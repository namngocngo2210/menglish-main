package iam

import (
	"context"
	"testing"
	"time"

	"github.com/google/uuid"
	"github.com/pquerna/otp/totp"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam/iamdb"
)

// Test phân quyền qua HTTP: bắt buộc 2FA, kiểm quyền route, cây vai trò, phạm vi chi nhánh, đổi quyền có hiệu lực ngay.

type member struct {
	id     uuid.UUID
	code   string
	secret string // khóa TOTP, rỗng nếu chưa bật
}

func (e *env) branch(t *testing.T, code string) uuid.UUID {
	t.Helper()
	b, err := e.svc.q.CreateBranch(context.Background(), iamdb.CreateBranchParams{Code: code, Name: "Chi nhánh " + code})
	if err != nil {
		t.Fatal(err)
	}
	return b.ID
}

// member tạo tài khoản đã đổi mật khẩu (newPassword), có vai trò, chi nhánh và (tùy chọn) 2FA.
func (e *env) member(t *testing.T, code string, withTOTP bool, branches []uuid.UUID, roles ...string) member {
	t.Helper()
	ctx := context.Background()
	u, _, err := e.svc.CreateUser(ctx, NewUser{Kind: "staff", Code: code, FullName: "Người " + code})
	if err != nil {
		t.Fatal(err)
	}
	hash, _ := HashPassword(ctx, newPassword)
	if err := e.svc.q.SetPassword(ctx, iamdb.SetPasswordParams{ID: u.ID, PasswordHash: hash}); err != nil {
		t.Fatal(err)
	}
	if err := replaceRoles(ctx, e.svc.q, u.ID, roles); err != nil {
		t.Fatal(err)
	}
	if err := replaceBranches(ctx, e.svc.q, u.ID, branches); err != nil {
		t.Fatal(err)
	}
	m := member{id: u.ID, code: code}
	if withTOTP {
		key, _, err := newTOTPKey(code)
		if err != nil {
			t.Fatal(err)
		}
		m.secret = key
		if err := e.svc.q.EnableTOTP(ctx, iamdb.EnableTOTPParams{ID: u.ID, TotpSecret: e.svc.box.Seal([]byte(key), u.ID[:])}); err != nil {
			t.Fatal(err)
		}
	}
	return m
}

// as đăng nhập (kể cả bước 2FA); mỗi lần đăng nhập dời đồng hồ một bước TOTP để mã không bị coi là dùng lại.
func (e *env) as(t *testing.T, m member) *client {
	t.Helper()
	c := e.client()
	r := c.login(m.code, newPassword)
	expect(t, r, 200, "")
	if r.body["status"] == "totp_required" {
		at := e.svc.now().Add(30 * time.Second)
		e.svc.now = func() time.Time { return at }
		code, _ := totp.GenerateCode(m.secret, at)
		expect(t, c.do("POST", "/auth/login/totp", map[string]any{"challenge": r.body["challenge"], "code": code}), 200, "")
	}
	return c
}

func ids(t *testing.T, r resp) []string {
	t.Helper()
	var out []string
	for _, u := range r.body["users"].([]any) {
		out = append(out, u.(map[string]any)["id"].(string))
	}
	return out
}

func TestPrivilegedRolesMustUseTOTP(t *testing.T) {
	e := setup(t)
	admin := e.member(t, "MES001", false, nil, RoleAdmin)
	c := e.as(t, admin)
	expect(t, e.client().do("GET", "/users", nil), 401, "unauthenticated")

	me := c.do("GET", "/auth/me", nil)
	if me.body["totp_required"] != true {
		t.Fatalf("Admin phải bị bắt buộc 2FA: %v", me.body)
	}
	expect(t, c.do("GET", "/users", nil), 403, "totp_setup_required")

	r := c.do("POST", "/auth/totp/setup", nil)
	code, _ := totp.GenerateCode(r.body["secret"].(string), e.svc.now())
	expect(t, c.do("POST", "/auth/totp/enable", map[string]any{"code": code}), 200, "")
	expect(t, c.do("GET", "/users", nil), 200, "")
	expect(t, c.do("POST", "/auth/totp/disable", map[string]any{"password": newPassword}), 409, "totp_mandatory")

	// Giáo viên không bắt buộc 2FA nhưng không có quyền quản lý tài khoản
	teacher := e.as(t, e.member(t, "GV001", false, nil, RoleTeacherFulltime))
	expect(t, teacher.do("GET", "/users", nil), 403, "forbidden")
	me = teacher.do("GET", "/auth/me", nil)
	if me.body["totp_required"] != false || me.body["scopes"].(map[string]any)["class"] != ScopeOwn {
		t.Fatalf("/auth/me của giáo viên: %v", me.body)
	}
}

func TestManagerSeesOnlySubordinatesInOwnBranch(t *testing.T) {
	e := setup(t)
	a, b := e.branch(t, "CN-A"), e.branch(t, "CN-B")
	e.member(t, "MES001", true, nil, RoleAdmin)
	manager := e.member(t, "QL001", true, []uuid.UUID{a}, RoleManager)
	otherManager := e.member(t, "QL002", true, []uuid.UUID{a}, RoleManager)
	ta := e.member(t, "GV-A", false, []uuid.UUID{a}, RoleTeacherFulltime)
	tb := e.member(t, "GV-B", false, []uuid.UUID{b}, RoleTeacherFulltime)
	c := e.as(t, manager)

	got := ids(t, c.do("GET", "/users", nil))
	if len(got) != 1 || got[0] != ta.id.String() {
		t.Fatalf("Quản lý cơ sở chỉ thấy GV chi nhánh mình, nhận %v", got)
	}
	expect(t, c.do("GET", "/users/"+tb.id.String(), nil), 403, "outside_branch")
	expect(t, c.do("GET", "/users/"+otherManager.id.String(), nil), 403, "not_subordinate")
	expect(t, c.do("PUT", "/users/"+ta.id.String()+"/roles", map[string]any{"roles": []string{RoleManager}}), 403, "role_not_assignable")

	// Quản lý cơ sở không có quyền tạo tài khoản (như bản Laravel); Học vụ thì có, trong chi nhánh mình
	newUser := map[string]any{"code": "TG001", "full_name": "Trợ giảng", "roles": []string{RoleManager}, "primary_branch_id": a}
	expect(t, c.do("POST", "/users", newUser), 403, "forbidden")
	staff := e.as(t, e.member(t, "HV001", true, []uuid.UUID{a}, RoleAcademicStaff))
	expect(t, staff.do("POST", "/users", newUser), 403, "role_not_assignable")
	newUser["roles"] = []string{RoleAssistant}
	newUser["primary_branch_id"] = b
	expect(t, staff.do("POST", "/users", newUser), 422, "invalid")
	newUser["primary_branch_id"] = a
	r := staff.do("POST", "/users", newUser)
	expect(t, r, 201, "")
	temp := r.body["temporary_password"].(string)
	expect(t, e.client().login("TG001", temp), 200, "")
	expect(t, staff.do("POST", "/users", newUser), 409, "code_taken")
	opts := staff.do("GET", "/users/options", nil)
	if n := len(opts.body["branches"].([]any)); n != 1 {
		t.Fatalf("Học vụ chỉ chọn được chi nhánh mình, nhận %d chi nhánh", n)
	}

	// Khóa tài khoản: phiên đang mở bị đăng xuất ngay
	teacher := e.as(t, ta)
	expect(t, c.do("POST", "/users/"+ta.id.String()+"/lock", nil), 204, "")
	expect(t, teacher.do("GET", "/auth/me", nil), 401, "unauthenticated")
	expect(t, c.do("POST", "/users/"+ta.id.String()+"/unlock", nil), 204, "")
	r = c.do("POST", "/users/"+ta.id.String()+"/reset-password", nil)
	expect(t, r, 200, "")
	expect(t, e.client().login("GV-A", r.body["temporary_password"].(string)), 200, "")
	expect(t, c.do("POST", "/users/"+manager.id.String()+"/lock", nil), 403, "not_subordinate")

	// Quản lý cơ sở không xem được danh mục chi nhánh, vai trò
	expect(t, c.do("GET", "/branches", nil), 403, "forbidden")
	expect(t, c.do("PUT", "/roles/"+RoleTeacherFulltime+"/permissions", map[string]any{"permissions": []string{}}), 403, "forbidden")
}

func TestRoleAndOverrideChangesApplyImmediately(t *testing.T) {
	e := setup(t)
	admin := e.as(t, e.member(t, "MES001", true, nil, RoleAdmin))
	teacherM := e.member(t, "GV001", false, nil, RoleTeacherFulltime)
	teacher := e.as(t, teacherM)
	expect(t, teacher.do("GET", "/users", nil), 403, "forbidden")

	roles := admin.do("GET", "/roles", nil).body["roles"].([]any)
	var perms []any
	for _, r := range roles {
		if m := r.(map[string]any); m["name"] == RoleTeacherFulltime {
			perms = m["permissions"].([]any)
		}
	}
	perms = append(perms, "user.view")
	expect(t, admin.do("PUT", "/roles/"+RoleTeacherFulltime+"/permissions", map[string]any{"permissions": perms}), 200, "")
	expect(t, teacher.do("GET", "/users", nil), 200, "")
	expect(t, admin.do("PUT", "/roles/"+RoleAdmin+"/permissions", map[string]any{"permissions": []string{}}), 409, "role_immutable")
	expect(t, admin.do("PUT", "/roles/"+RoleTeacherFulltime+"/permissions", map[string]any{"permissions": []string{"lead.bay"}}), 422, "invalid")

	// Phân quyền cá nhân: chặn thắng quyền vai trò
	path := "/users/" + teacherM.id.String() + "/permissions"
	r := admin.do("PUT", path, map[string]any{"overrides": []map[string]any{{"permission": "user.view", "allow": false}}})
	expect(t, r, 200, "")
	expect(t, teacher.do("GET", "/users", nil), 403, "forbidden")

	// Người giữ vai trò không tự bỏ được quyền quản trị phân quyền của mình
	manager := e.member(t, "QL001", true, nil, RoleManager)
	expect(t, admin.do("PUT", "/users/"+manager.id.String()+"/permissions",
		map[string]any{"overrides": []map[string]any{{"permission": "role.assign_permission", "allow": true}}}), 200, "")
	mc := e.as(t, manager)
	expect(t, mc.do("PUT", "/roles/"+RoleManager+"/permissions", map[string]any{"permissions": []string{"user.view"}}), 422, "keeps_access_management")
	expect(t, mc.do("GET", "/users/"+manager.id.String()+"/permissions", nil), 403, "forbidden")
}

func TestBranchAdmin(t *testing.T) {
	e := setup(t)
	admin := e.as(t, e.member(t, "MES001", true, nil, RoleAdmin))
	r := admin.do("POST", "/branches", map[string]any{"code": "hn01", "name": "Hà Nội 1", "phone": "0912 345 678"})
	expect(t, r, 201, "")
	if r.body["code"] != "HN01" || r.body["phone"] != "0912345678" {
		t.Fatalf("chi nhánh: %v", r.body)
	}
	id := r.body["id"].(string)
	expect(t, admin.do("POST", "/branches", map[string]any{"code": "HN01", "name": "Trùng"}), 409, "code_taken")
	expect(t, admin.do("POST", "/branches", map[string]any{"code": "có dấu", "name": "Sai"}), 422, "invalid")
	expect(t, admin.do("PATCH", "/branches/"+id, map[string]any{"code": "HN01", "name": "Hà Nội Một"}), 200, "")
	r = admin.do("POST", "/branches/"+id+"/deactivate", nil)
	if r.status != 200 || r.body["is_active"] != false {
		t.Fatalf("ngừng hoạt động: %d %v", r.status, r.body)
	}
	// Chi nhánh đã ngừng không chọn được cho tài khoản mới
	expect(t, admin.do("POST", "/users", map[string]any{"code": "GV009", "full_name": "GV", "roles": []string{RoleTeacherFulltime}, "primary_branch_id": id}), 422, "invalid")
}
