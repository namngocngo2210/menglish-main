package iam

import (
	_ "embed"
	"encoding/json"
	"fmt"
	"slices"
	"strings"
	"sync"
)

// catalog/catalog.json sinh từ config Laravel bằng catalog/export.php (xem file đó). Không sửa tay.
//
//go:embed catalog/catalog.json
var catalogJSON []byte

// Vai trò cố định (App\Support\Roles).
const (
	RoleAdmin           = "admin"
	RoleManager         = "manager"
	RoleAcademicLead    = "academic_lead"
	RoleAcademicStaff   = "academic_staff"
	RoleSalesConsultant = "sales_consultant"
	RoleTeacherFulltime = "teacher_fulltime"
	RoleTeacherParttime = "teacher_parttime"
	RoleAssistant       = "assistant"
	RoleStudent         = "student"
)

// Nhóm vai trò dùng trong nghiệp vụ (KPI, lương, người nhận việc).
var (
	RolesTeachers        = []string{RoleTeacherFulltime, RoleTeacherParttime}
	RolesTeaching        = []string{RoleTeacherFulltime, RoleTeacherParttime, RoleAssistant}
	RolesAcademic        = []string{RoleTeacherFulltime, RoleTeacherParttime, RoleAssistant, RoleAcademicStaff, RoleAcademicLead}
	RolesKPI             = []string{RoleAcademicStaff, RoleAcademicLead, RoleManager, RoleSalesConsultant, RoleTeacherFulltime, RoleTeacherParttime, RoleAssistant}
	RolesBranchOperators = []string{RoleAcademicStaff, RoleManager}
)

// Mức phạm vi dữ liệu, thấp → cao.
const (
	ScopeOwn    = "own"
	ScopeBranch = "branch"
	ScopeAll    = "all"
)

// Loại quyền.
const (
	KindAction   = "action"
	KindScope    = "scope"
	KindAudience = "audience"
	KindDynamic  = "dynamic"
)

// AssignRolePrefix: quyền động "user.assign_role.<vai trò>" (được tạo / gán vai trò đó cho người khác).
const AssignRolePrefix = "user.assign_role."

// AccessManagement: quyền quản trị phân quyền, người đang có không được tự làm mất.
var AccessManagement = []string{"role.assign_permission", "permission.override", "user.assign_role"}

// totpRequiredPermissions: ai có một trong các quyền này (hoặc là Admin) phải bật 2FA mới thao tác được.
// Gồm quyền phân quyền, duyệt tiền (chỉ Admin dùng được) và chạy lương.
var totpRequiredPermissions = []string{
	"role.assign_permission", "permission.override", "user.assign_role",
	"payroll.calculate", "payroll.approve", "payroll.mark_paid",
}

// overrideScopeEnforced: quyền mà phân quyền cá nhân theo chi nhánh / lớp có tác dụng (module tự lọc theo scope_id).
var overrideScopeEnforced = map[string]bool{"class.view": true, "class.update": true, "class.delete": true}

type Labeled struct {
	Key   string `json:"key"`
	Label string `json:"label"`
}

type CatalogAction struct {
	Key         string `json:"key"`
	Label       string `json:"label"`
	Description string `json:"description"`
}

type CatalogModule struct {
	Key      string          `json:"key"`
	Label    string          `json:"label"`
	Group    string          `json:"group"`
	Icon     string          `json:"icon"`
	Actions  []CatalogAction `json:"actions"`
	Audience []CatalogAction `json:"audience"`
	Scope    *struct {
		Levels       []string          `json:"levels"`
		Descriptions map[string]string `json:"descriptions"`
	} `json:"scope,omitempty"`
	Dynamic []struct {
		Prefix      string `json:"prefix"`
		Label       string `json:"label"`
		Description string `json:"description"`
	} `json:"dynamic,omitempty"`
}

type RoleDef struct {
	Name       string   `json:"name"`
	Label      string   `json:"label"`
	ShortLabel string   `json:"short_label"`
	Defaults   []string `json:"defaults"`
}

// Catalog là danh mục quyền và vai trò mặc định, nạp một lần từ catalog.json.
type Catalog struct {
	Groups        []Labeled       `json:"groups"`
	ScopeLevels   []Labeled       `json:"scope_levels"`
	MatrixColumns []Labeled       `json:"matrix_columns"`
	Modules       []CatalogModule `json:"modules"`
	Roles         []RoleDef       `json:"roles"`
	AdminOnly     []string        `json:"admin_only"`

	kinds     map[string]string // quyền tĩnh → loại
	modules   map[string]*CatalogModule
	adminOnly map[string]bool
}

// PermissionInfo là một quyền trong danh mục kèm module và loại.
type PermissionInfo struct {
	Name   string
	Module string
	Kind   string
}

var loadCatalog = sync.OnceValue(func() *Catalog {
	c, err := parseCatalog(catalogJSON)
	if err != nil {
		panic(err)
	}
	return c
})

// GetCatalog trả danh mục quyền dùng chung.
func GetCatalog() *Catalog { return loadCatalog() }

func parseCatalog(data []byte) (*Catalog, error) {
	var c Catalog
	if err := json.Unmarshal(data, &c); err != nil {
		return nil, fmt.Errorf("catalog.json: %w", err)
	}
	c.kinds = map[string]string{}
	c.modules = map[string]*CatalogModule{}
	c.adminOnly = map[string]bool{}
	for i := range c.Modules {
		m := &c.Modules[i]
		c.modules[m.Key] = m
		for _, a := range m.Actions {
			c.kinds[m.Key+"."+a.Key] = KindAction
		}
		for _, a := range m.Audience {
			c.kinds[m.Key+"."+a.Key] = KindAudience
		}
		if m.Scope != nil {
			for _, l := range m.Scope.Levels {
				c.kinds[ScopePermission(m.Key, l)] = KindScope
			}
		}
	}
	for _, p := range c.AdminOnly {
		c.adminOnly[p] = true
	}
	return &c, nil
}

// ScopePermission: "module.scope_<mức>".
func ScopePermission(module, level string) string { return module + ".scope_" + level }

func moduleOf(perm string) string {
	m, _, _ := strings.Cut(perm, ".")
	return m
}

// Kind trả loại quyền, "" nếu không có trong danh mục.
func (c *Catalog) Kind(perm string) string {
	if k, ok := c.kinds[perm]; ok {
		return k
	}
	if role, ok := strings.CutPrefix(perm, AssignRolePrefix); ok && role != RoleAdmin && c.HasRole(role) {
		return KindDynamic
	}
	return ""
}

func (c *Catalog) HasRole(name string) bool {
	return slices.ContainsFunc(c.Roles, func(r RoleDef) bool { return r.Name == name })
}

// IsAdminOnly: quyền duyệt chỉ Admin dùng được (AdminOnlyApprovals); cấp cho vai trò khác không có tác dụng.
func (c *Catalog) IsAdminOnly(perm string) bool { return c.adminOnly[perm] }

// LevelsOf trả các mức phạm vi của module (thấp → cao); nil nếu module không có phạm vi dữ liệu.
func (c *Catalog) LevelsOf(module string) []string {
	if m := c.modules[module]; m != nil && m.Scope != nil {
		return m.Scope.Levels
	}
	return nil
}

// ScopedModules: các module có phạm vi dữ liệu, theo thứ tự danh mục.
func (c *Catalog) ScopedModules() []string {
	var out []string
	for _, m := range c.Modules {
		if m.Scope != nil {
			out = append(out, m.Key)
		}
	}
	return out
}

// All trả mọi quyền: tĩnh theo thứ tự danh mục, rồi quyền gán vai trò cho từng vai trò (trừ Admin).
func (c *Catalog) All() []PermissionInfo {
	var out []PermissionInfo
	for _, m := range c.Modules {
		for _, a := range m.Actions {
			out = append(out, PermissionInfo{m.Key + "." + a.Key, m.Key, KindAction})
		}
		if m.Scope != nil {
			for _, l := range m.Scope.Levels {
				out = append(out, PermissionInfo{ScopePermission(m.Key, l), m.Key, KindScope})
			}
		}
		for _, a := range m.Audience {
			out = append(out, PermissionInfo{m.Key + "." + a.Key, m.Key, KindAudience})
		}
	}
	for _, r := range c.Roles {
		if r.Name != RoleAdmin {
			out = append(out, PermissionInfo{AssignRolePrefix + r.Name, "user", KindDynamic})
		}
	}
	return out
}

// Expand đổi tập quyền mặc định của một vai trò (cú pháp config/access.php) thành danh sách quyền cụ thể:
//   - "*" (chỉ Admin): mọi quyền thao tác, phạm vi và gán vai trò;
//   - "module.*": mọi quyền thao tác của module (không gồm đối tượng, phạm vi);
//   - "user.assign_role.*": gán mọi vai trò trừ Admin và chính vai trò này.
func (c *Catalog) Expand(role string, defaults []string) ([]string, error) {
	seen := map[string]bool{}
	var out []string
	add := func(p string) {
		if !seen[p] {
			seen[p] = true
			out = append(out, p)
		}
	}
	for _, d := range defaults {
		switch {
		case d == "*":
			if role != RoleAdmin {
				return nil, fmt.Errorf("vai trò %s: chỉ Admin được dùng \"*\"", role)
			}
			for _, p := range c.All() {
				if p.Kind != KindAudience {
					add(p.Name)
				}
			}
		case d == AssignRolePrefix+"*":
			for _, r := range c.Roles {
				if r.Name != RoleAdmin && r.Name != role {
					add(AssignRolePrefix + r.Name)
				}
			}
		case strings.HasSuffix(d, ".*"):
			m := c.modules[strings.TrimSuffix(d, ".*")]
			if m == nil {
				return nil, fmt.Errorf("vai trò %s: không có module %q", role, d)
			}
			for _, a := range m.Actions {
				add(m.Key + "." + a.Key)
			}
		default:
			if c.Kind(d) == "" {
				return nil, fmt.Errorf("vai trò %s: quyền %q không có trong danh mục", role, d)
			}
			add(d)
		}
	}
	return out, nil
}
