package iam

import (
	"context"
	"encoding/json"
	"errors"
	"slices"
	"time"

	"github.com/google/uuid"
	"github.com/redis/go-redis/v9"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam/iamdb"
)

const (
	authzVersionKey = "authz:version"
	authzTTL        = 10 * time.Minute
)

func authzKey(uid uuid.UUID) string { return "authz:" + uid.String() }

// ScopedGrant là một phân quyền cá nhân "cho phép" giới hạn trong một chi nhánh hoặc lớp.
type ScopedGrant struct {
	Type string    `json:"type"` // branch | class
	ID   uuid.UUID `json:"id"`
}

// UserBranch là chi nhánh của người dùng.
type UserBranch struct {
	ID      uuid.UUID `json:"id"`
	Code    string    `json:"code"`
	Name    string    `json:"name"`
	Primary bool      `json:"primary"`
}

// Access là tập quyền đã tính của một người dùng. Thứ tự quyết định của Can:
// Admin được tất cả (trừ quyền đối tượng) → quyền chỉ Admin → phân quyền cá nhân → quyền của vai trò.
type Access struct {
	UserID     uuid.UUID                `json:"user_id"`
	Roles      []string                 `json:"roles"`
	SuperAdmin bool                     `json:"super_admin"`
	Granted    map[string]bool          `json:"granted"`   // quyền của các vai trò
	Overrides  map[string]bool          `json:"overrides"` // phân quyền cá nhân phạm vi "all"
	Scoped     map[string][]ScopedGrant `json:"scoped,omitempty"`
	Branches   []UserBranch             `json:"branches"` // chi nhánh chính đứng đầu
}

// Can: người dùng có quyền perm không (không xét bản ghi cụ thể).
func (a *Access) Can(perm string) bool {
	c := GetCatalog()
	if a.SuperAdmin && c.Kind(perm) != KindAudience {
		return true
	}
	if c.IsAdminOnly(perm) {
		return false
	}
	if v, ok := a.Overrides[perm]; ok {
		return v
	}
	if overrideScopeEnforced[perm] && len(a.Scoped[perm]) > 0 {
		return true
	}
	return a.Granted[perm]
}

// CanAny: có ít nhất một quyền trong danh sách.
func (a *Access) CanAny(perms ...string) bool {
	return slices.ContainsFunc(perms, a.Can)
}

// Level trả mức phạm vi dữ liệu trên module: mức cao nhất được cấp, không có thì mức thấp nhất của module.
func (a *Access) Level(module string) string {
	levels := GetCatalog().LevelsOf(module)
	if len(levels) == 0 {
		panic("iam: module " + module + " không có phạm vi dữ liệu trong danh mục")
	}
	for i := len(levels) - 1; i > 0; i-- {
		if a.Can(ScopePermission(module, levels[i])) {
			return levels[i]
		}
	}
	return levels[0]
}

// BranchIDs: chi nhánh của người dùng (chính + cấp thêm).
func (a *Access) BranchIDs() []uuid.UUID {
	ids := make([]uuid.UUID, 0, len(a.Branches))
	for _, b := range a.Branches {
		ids = append(ids, b.ID)
	}
	return ids
}

// BranchLimit: nil = không giới hạn chi nhánh (mức all); ngược lại là chi nhánh của người dùng (có thể rỗng).
func (a *Access) BranchLimit(module string) []uuid.UUID {
	if a.Level(module) == ScopeAll {
		return nil
	}
	return a.BranchIDs()
}

// CoversBranch: người dùng thấy dữ liệu thuộc chi nhánh này trên module không (nil = chưa gắn chi nhánh, chỉ mức all).
func (a *Access) CoversBranch(module string, branch *uuid.UUID) bool {
	limit := a.BranchLimit(module)
	return limit == nil || (branch != nil && slices.Contains(limit, *branch))
}

// CanAssignRole: được tạo tài khoản / gán vai trò này cho người khác. Vai trò Admin chỉ Admin gán.
func (a *Access) CanAssignRole(role string) bool {
	if role == RoleAdmin {
		return a.SuperAdmin
	}
	return a.Can(AssignRolePrefix + role)
}

// AssignableRoles: các vai trò người dùng gán được, theo thứ tự danh mục.
func (a *Access) AssignableRoles() []string {
	var out []string
	for _, r := range GetCatalog().Roles {
		if a.CanAssignRole(r.Name) {
			out = append(out, r.Name)
		}
	}
	return out
}

// RequiresTOTP: Admin và người có quyền phân quyền / duyệt tiền / chạy lương bắt buộc bật 2FA.
func (a *Access) RequiresTOTP() bool {
	return a.SuperAdmin || a.CanAny(totpRequiredPermissions...)
}

// Permissions: mọi quyền trong danh mục người dùng đang có (gửi cho giao diện để ẩn / hiện menu, nút).
func (a *Access) Permissions() []string {
	var out []string
	for _, p := range GetCatalog().All() {
		if a.Can(p.Name) {
			out = append(out, p.Name)
		}
	}
	return out
}

// Scopes: mức phạm vi dữ liệu trên từng module có phạm vi.
func (a *Access) Scopes() map[string]string {
	out := map[string]string{}
	for _, m := range GetCatalog().ScopedModules() {
		out[m] = a.Level(m)
	}
	return out
}

type cachedAccess struct {
	Version int64   `json:"v"`
	Access  *Access `json:"a"`
}

// LoadAccess tính tập quyền của người dùng; cache trong Redis 10 phút, bỏ khi vai trò / phân quyền / chi nhánh đổi.
func (s *Service) LoadAccess(ctx context.Context, uid uuid.UUID) (*Access, error) {
	ver, err := s.rdb.Get(ctx, authzVersionKey).Int64()
	if err != nil && !errors.Is(err, redis.Nil) {
		return nil, err
	}
	if data, err := s.rdb.Get(ctx, authzKey(uid)).Bytes(); err == nil {
		var c cachedAccess
		if json.Unmarshal(data, &c) == nil && c.Version == ver && c.Access != nil {
			return c.Access, nil
		}
	} else if !errors.Is(err, redis.Nil) {
		return nil, err
	}
	a, err := s.computeAccess(ctx, s.q, uid)
	if err != nil {
		return nil, err
	}
	data, _ := json.Marshal(cachedAccess{ver, a})
	if err := s.rdb.Set(ctx, authzKey(uid), data, authzTTL).Err(); err != nil {
		return nil, err
	}
	return a, nil
}

func (s *Service) computeAccess(ctx context.Context, q *iamdb.Queries, uid uuid.UUID) (*Access, error) {
	roles, err := q.ListUserRoles(ctx, uid)
	if err != nil {
		return nil, err
	}
	perms, err := q.ListUserRolePermissions(ctx, uid)
	if err != nil {
		return nil, err
	}
	overrides, err := q.ListUserOverrides(ctx, uid)
	if err != nil {
		return nil, err
	}
	branches, err := q.ListUserBranches(ctx, uid)
	if err != nil {
		return nil, err
	}
	a := &Access{
		UserID: uid, Roles: roles, SuperAdmin: slices.Contains(roles, RoleAdmin),
		Granted: map[string]bool{}, Overrides: map[string]bool{}, Scoped: map[string][]ScopedGrant{},
		Branches: make([]UserBranch, 0, len(branches)),
	}
	for _, p := range perms {
		a.Granted[p] = true
	}
	for _, o := range overrides {
		switch {
		case o.ScopeType == "all":
			a.Overrides[o.Permission] = o.Allow
		case o.Allow && o.ScopeID != nil:
			a.Scoped[o.Permission] = append(a.Scoped[o.Permission], ScopedGrant{o.ScopeType, *o.ScopeID})
		}
	}
	for _, b := range branches {
		a.Branches = append(a.Branches, UserBranch{ID: b.BranchID, Code: b.Code, Name: b.Name, Primary: b.IsPrimary})
	}
	return a, nil
}

// invalidateAccess bỏ cache quyền của một người (sau khi đổi vai trò, phân quyền cá nhân, chi nhánh).
func (s *Service) invalidateAccess(ctx context.Context, uid uuid.UUID) error {
	return s.rdb.Del(ctx, authzKey(uid)).Err()
}
