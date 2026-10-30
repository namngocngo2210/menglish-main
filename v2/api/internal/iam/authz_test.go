package iam

import (
	"slices"
	"testing"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam/iamdb"
)

// accessFor dựng Access cho người giữ các vai trò với quyền mặc định của danh mục.
func accessFor(t *testing.T, branches []uuid.UUID, roles ...string) *Access {
	t.Helper()
	c := GetCatalog()
	a := &Access{
		UserID: uuid.New(), Roles: roles, SuperAdmin: slices.Contains(roles, RoleAdmin),
		Granted: map[string]bool{}, Overrides: map[string]bool{}, Scoped: map[string][]ScopedGrant{},
	}
	for _, r := range c.Roles {
		if !slices.Contains(roles, r.Name) {
			continue
		}
		perms, err := c.Expand(r.Name, r.Defaults)
		if err != nil {
			t.Fatal(err)
		}
		for _, p := range perms {
			a.Granted[p] = true
		}
	}
	for i, b := range branches {
		a.Branches = append(a.Branches, UserBranch{ID: b, Primary: i == 0})
	}
	return a
}

func TestCatalogDefaultsExpand(t *testing.T) {
	c := GetCatalog()
	if len(c.Roles) != 10 {
		t.Fatalf("có %d vai trò, muốn 10", len(c.Roles))
	}
	for _, r := range c.Roles {
		if _, err := c.Expand(r.Name, r.Defaults); err != nil {
			t.Error(err)
		}
	}
	admin, _ := c.Expand(RoleAdmin, []string{"*"})
	for _, p := range admin {
		if c.Kind(p) == KindAudience {
			t.Errorf("\"*\" không được gồm quyền đối tượng %s", p)
		}
	}
	if slices.Contains(admin, AssignRolePrefix+RoleAdmin) {
		t.Error("không có quyền gán vai trò Admin")
	}
	if _, err := c.Expand(RoleManager, []string{"*"}); err == nil {
		t.Error("vai trò khác Admin không được dùng \"*\"")
	}
	if _, err := c.Expand(RoleManager, []string{"lead.khong_co"}); err == nil {
		t.Error("quyền lạ phải báo lỗi")
	}
}

func TestManagerAssignsEveryRoleButAdminAndManager(t *testing.T) {
	m := accessFor(t, nil, RoleManager)
	got := m.AssignableRoles()
	want := []string{RoleAcademicLead, RoleAcademicStaff, RoleSalesConsultant, RoleTeacherFulltime, RoleTeacherParttime, "foreign_teacher", RoleAssistant, RoleStudent}
	if !slices.Equal(got, want) {
		t.Fatalf("Quản lý cơ sở gán được %v, muốn %v", got, want)
	}
	staff := accessFor(t, nil, RoleAcademicStaff)
	if staff.CanAssignRole(RoleManager) || !staff.CanAssignRole(RoleAcademicLead) || staff.CanAssignRole(RoleAcademicStaff) {
		t.Fatalf("Học vụ gán được %v", staff.AssignableRoles())
	}
}

func TestCanDecisionOrder(t *testing.T) {
	admin := accessFor(t, nil, RoleAdmin)
	if !admin.Can("payroll.approve") || !admin.Can("tuition.approve") || !admin.Can("lead.scope_all") {
		t.Error("Admin phải có mọi quyền thao tác và phạm vi")
	}
	if admin.Can("class.teach") || admin.Can("portal.student") {
		t.Error("Admin không tự có quyền đối tượng")
	}

	m := accessFor(t, nil, RoleManager)
	m.Granted["tuition.approve"] = true
	m.Overrides["lead.approve_transfer"] = true
	if m.Can("tuition.approve") || m.Can("lead.approve_transfer") {
		t.Error("quyền duyệt chỉ Admin dùng được, kể cả khi vai trò hay phân quyền cá nhân cấp")
	}
	m.Overrides["lead.delete"] = false
	if m.Can("lead.delete") {
		t.Error("phân quyền cá nhân chặn phải thắng quyền vai trò")
	}
	m.Overrides["payroll.view"] = true
	if !m.Can("payroll.view") {
		t.Error("phân quyền cá nhân cho phép phải mở quyền vai trò không có")
	}

	teacher := accessFor(t, nil, RoleTeacherFulltime)
	if teacher.Can("class.update") {
		t.Fatal("giáo viên không sửa lớp")
	}
	teacher.Scoped["class.update"] = []ScopedGrant{{Type: "class", ID: uuid.New()}}
	teacher.Scoped["lead.view"] = []ScopedGrant{{Type: "branch", ID: uuid.New()}}
	if !teacher.Can("class.update") || teacher.Can("lead.view") {
		t.Error("phân quyền theo lớp chỉ mở các quyền lớp học có lọc theo phạm vi")
	}
}

func TestScopeLevels(t *testing.T) {
	b1, b2 := uuid.New(), uuid.New()
	m := accessFor(t, []uuid.UUID{b1}, RoleManager)
	// Quản lý cơ sở chỉ thấy dữ liệu chi nhánh mình (cả KPI, Big Test, ticket, nhật ký); lương chỉ của mình.
	if m.Level("lead") != ScopeBranch || m.Level("kpi") != ScopeBranch || m.Level("payroll") != ScopeOwn {
		t.Errorf("Quản lý cơ sở: lead=%s kpi=%s payroll=%s", m.Level("lead"), m.Level("kpi"), m.Level("payroll"))
	}
	if !m.CoversBranch("lead", &b1) || m.CoversBranch("lead", &b2) || m.CoversBranch("lead", nil) {
		t.Error("mức chi nhánh chỉ thấy chi nhánh của mình")
	}
	if limit := m.BranchLimit("kpi"); len(limit) != 1 || limit[0] != b1 {
		t.Errorf("mức chi nhánh giới hạn theo chi nhánh của mình: %v", limit)
	}
	if accessFor(t, []uuid.UUID{b1}, RoleAcademicLead).BranchLimit("kpi") != nil {
		t.Error("mức toàn hệ thống không giới hạn chi nhánh")
	}
	sales := accessFor(t, nil, RoleSalesConsultant)
	if sales.Level("lead") != ScopeOwn {
		t.Error("Sales chỉ thấy khách của mình")
	}
	if accessFor(t, nil, RoleTeacherFulltime).Level("dashboard") != ScopeBranch {
		t.Error("không được cấp mức nào thì dùng mức thấp nhất của module")
	}
	m.Overrides["lead.scope_all"] = true
	if m.Level("lead") != ScopeAll {
		t.Error("phân quyền cá nhân nâng được phạm vi")
	}
}

func TestRequiresTOTP(t *testing.T) {
	cases := map[string]bool{
		RoleAdmin: true, RoleManager: true, RoleAcademicStaff: true, RoleAcademicLead: true,
		RoleSalesConsultant: false, RoleTeacherFulltime: false, RoleAssistant: false, RoleStudent: false,
	}
	for role, want := range cases {
		if got := accessFor(t, nil, role).RequiresTOTP(); got != want {
			t.Errorf("%s: bắt buộc 2FA = %v, muốn %v", role, got, want)
		}
	}
}

func TestNormalizePermissions(t *testing.T) {
	got, err := normalizePermissions([]string{"lead.view", "lead.scope_own", "lead.scope_branch", "class.scope_own", "lead.view"})
	if err != nil {
		t.Fatal(err)
	}
	if want := []string{"lead.scope_branch", "lead.view"}; !slices.Equal(got, want) {
		t.Fatalf("được %v, muốn %v", got, want)
	}
	if _, err := normalizePermissions([]string{"lead.bay"}); err == nil {
		t.Fatal("quyền lạ phải bị từ chối")
	}
}

func TestCanManage(t *testing.T) {
	b1, b2 := uuid.New(), uuid.New()
	manager := accessFor(t, []uuid.UUID{b1}, RoleManager)
	tgt := func(created *uuid.UUID, branch uuid.UUID, roles ...string) *target {
		return &target{
			user:     iamdb.IamUser{ID: uuid.New(), CreatedBy: created},
			roles:    roles,
			branches: []iamdb.ListUserBranchesRow{{BranchID: branch, IsPrimary: true}},
		}
	}
	if err := canManage(manager, tgt(nil, b1, RoleTeacherFulltime, RoleAssistant)); err != nil {
		t.Errorf("Quản lý cơ sở quản lý GV cùng chi nhánh: %v", err)
	}
	if err := canManage(manager, tgt(nil, b2, RoleTeacherFulltime)); err != ErrOutsideBranch {
		t.Errorf("GV chi nhánh khác: %v", err)
	}
	if err := canManage(manager, tgt(nil, b1, RoleManager)); err != ErrNotSubordinate {
		t.Errorf("Quản lý cơ sở khác: %v", err)
	}
	if err := canManage(manager, tgt(nil, b1, RoleTeacherFulltime, RoleAdmin)); err != ErrNotSubordinate {
		t.Errorf("tài khoản có vai trò Admin: %v", err)
	}
	if err := canManage(manager, tgt(nil, b1)); err != ErrNotSubordinate {
		t.Errorf("tài khoản chưa có vai trò: %v", err)
	}
	if err := canManage(accessFor(t, nil, RoleAdmin), tgt(nil, b2, RoleAdmin)); err != nil {
		t.Errorf("Admin quản lý mọi tài khoản: %v", err)
	}
	manager.Overrides["user.scope_branch"] = false
	if err := canManage(manager, tgt(nil, b1, RoleTeacherFulltime)); err != ErrNotOwnAccount {
		t.Errorf("mức của tôi chỉ quản lý tài khoản do mình tạo: %v", err)
	}
	if err := canManage(manager, tgt(&manager.UserID, b2, RoleTeacherFulltime)); err != nil {
		t.Errorf("tài khoản do mình tạo: %v", err)
	}
}

// TestTruthTable là bảng chân lý quyền của các thao tác quản trị cho 10 vai trò mặc định (theo config/access.php).
// Thêm thao tác mới thì thêm cột ở đây.
func TestTruthTable(t *testing.T) {
	perms := []string{"user.view", "user.create", "user.update", "user.assign_role", "user.lock", "user.reset_password",
		"role.view", "role.assign_permission", "permission.override", "branch.view", "branch.create"}
	table := map[string]string{ // 1 = có quyền, theo thứ tự perms
		RoleAdmin:           "11111111111",
		RoleManager:         "10011110000",
		RoleAcademicLead:    "11110000000",
		RoleAcademicStaff:   "11110000000",
		RoleSalesConsultant: "00000000000",
		RoleTeacherFulltime: "00000000000",
		RoleTeacherParttime: "00000000000",
		"foreign_teacher":   "00000000000",
		RoleAssistant:       "00000000000",
		RoleStudent:         "00000000000",
	}
	for role, row := range table {
		a := accessFor(t, nil, role)
		for i, p := range perms {
			if want := row[i] == '1'; a.Can(p) != want {
				t.Errorf("%s / %s: được %v, muốn %v", role, p, a.Can(p), want)
			}
		}
	}
}
