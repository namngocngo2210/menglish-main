package iam

import (
	"context"
	"database/sql"
	"io"
	"log/slog"
	"os"
	"testing"

	_ "github.com/go-sql-driver/mysql"
	"golang.org/x/crypto/bcrypt"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/legacy"
)

// Nhập từ MySQL đã chạy migration của Laravel (CI: php artisan migrate trên MariaDB). Bảng cũ đổi cột thì test này đỏ,
// nhắc sửa import-legacy trước khi deploy. Cần TEST_LEGACY_MYSQL_DSN.
func legacySource(t *testing.T) *sql.DB {
	t.Helper()
	dsn := os.Getenv("TEST_LEGACY_MYSQL_DSN")
	if dsn == "" {
		t.Skip("cần TEST_LEGACY_MYSQL_DSN (MySQL đã chạy migration Laravel)")
	}
	db, err := sql.Open("mysql", dsn+"?parseTime=true&multiStatements=true")
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { db.Close() })
	for _, tbl := range []string{"user_permission_overrides", "user_branches", "model_has_roles", "role_has_permissions", "users", "branches"} {
		if _, err := db.Exec("DELETE FROM " + tbl); err != nil {
			t.Fatal(err)
		}
	}
	return db
}

func TestImportLegacyIAM(t *testing.T) {
	src := legacySource(t)
	e := setup(t)
	ctx := context.Background()
	hash, _ := bcrypt.GenerateFromPassword([]byte(newPassword), 4)
	legacyHash := "$2y$" + string(hash)[4:]

	exec := func(q string, args ...any) {
		t.Helper()
		if _, err := src.Exec(q, args...); err != nil {
			t.Fatalf("%s: %v", q, err)
		}
	}
	exec(`INSERT INTO branches (id, code, name, address, phone, is_active, latitude, longitude, checkin_radius, work_start_time, work_end_time, late_grace_minutes, created_at, updated_at)
		VALUES (1, 'HN01', 'Hà Nội 1', '25 Trần Duy Hưng', '0243 555 0101', 1, 21.0285000, 105.8542000, 80, '08:00:00', '17:30:00', 10, NOW(), NOW()),
		       (2, 'HCM 01', 'Hồ Chí Minh', NULL, NULL, 1, NULL, NULL, 100, NULL, NULL, 0, NOW(), NOW())`)
	exec(`INSERT INTO users (id, employee_code, name, email, phone, password, is_active, must_change_password, branch_id, created_at, updated_at)
		VALUES (1, 'MES001', 'Quản trị', 'admin@meducation.vn', NULL, ?, 1, 0, NULL, NOW(), NOW()),
		       (2, 'MES002', 'Quản lý HN', 'ql@meducation.vn', '0912.345.678', ?, 1, 0, 1, NOW(), NOW()),
		       (3, 'MES003', 'Nghỉ việc', 'old@meducation.vn', NULL, ?, 0, 0, 2, NOW(), NOW())`, legacyHash, legacyHash, legacyHash)
	exec(`UPDATE users SET created_by = 1 WHERE id = 2`)
	exec(`INSERT INTO user_branches (user_id, branch_id, created_at, updated_at) VALUES (2, 1, NOW(), NOW()), (2, 2, NOW(), NOW()), (3, 2, NOW(), NOW())`)
	roleID := func(name string) int64 {
		var id int64
		if err := src.QueryRow("SELECT id FROM roles WHERE name = ? AND guard_name = 'web'", name).Scan(&id); err != nil {
			res, err := src.Exec("INSERT INTO roles (name, guard_name, created_at, updated_at) VALUES (?, 'web', NOW(), NOW())", name)
			if err != nil {
				t.Fatal(err)
			}
			id, _ = res.LastInsertId()
		}
		return id
	}
	exec(`INSERT INTO model_has_roles (role_id, model_type, model_id) VALUES (?, 'App\\Models\\User', 1), (?, 'App\\Models\\User', 2), (?, 'App\\Models\\User', 3)`,
		roleID("admin"), roleID("manager"), roleID("teacher"))
	permID := func(name string) int64 {
		var id int64
		if err := src.QueryRow("SELECT id FROM permissions WHERE name = ? AND guard_name = 'web'", name).Scan(&id); err != nil {
			res, err := src.Exec("INSERT INTO permissions (name, guard_name, created_at, updated_at) VALUES (?, 'web', NOW(), NOW())", name)
			if err != nil {
				t.Fatal(err)
			}
			id, _ = res.LastInsertId()
		}
		return id
	}
	for _, p := range []string{"user.view", "user.scope_branch", "branch.view"} {
		exec("INSERT INTO role_has_permissions (permission_id, role_id) VALUES (?, ?)", permID(p), roleID("manager"))
	}
	exec(`INSERT INTO user_permission_overrides (user_id, module, action, allow, scope_type, scope_id, created_at, updated_at)
		VALUES (2, 'branch', 'update', 1, 'all', NULL, NOW(), NOW()), (2, 'class', 'view', 1, 'class', 99, NOW(), NOW())`)

	log := slog.New(slog.NewTextHandler(io.Discard, nil))
	run := func(dry bool) map[string]*legacy.Count {
		t.Helper()
		rep, err := legacy.Import(ctx, src, e.pg, log, LegacySteps(), legacy.Options{DryRun: dry})
		if err != nil {
			t.Fatal(err)
		}
		return rep
	}
	if rep := run(true); rep["users"].Inserted != 3 {
		t.Fatalf("chạy thử: %+v", rep["users"])
	}
	var n int
	_ = e.pg.QueryRow(ctx, "SELECT count(*) FROM iam.users").Scan(&n)
	if n != 0 {
		t.Fatalf("chạy thử không được ghi, có %d tài khoản", n)
	}

	rep := run(false)
	if rep["branches"].Inserted != 2 || rep["users"].Inserted != 3 || rep["permission_overrides"].Inserted != 1 || rep["permission_overrides"].Skipped != 1 {
		t.Fatalf("báo cáo: branches %+v users %+v overrides %+v", rep["branches"], rep["users"], rep["permission_overrides"])
	}
	// Chạy lại: cập nhật, không tạo trùng
	rep = run(false)
	if rep["users"].Inserted != 0 || rep["users"].Updated != 3 || rep["branches"].Updated != 2 {
		t.Fatalf("chạy lại: %+v %+v", rep["users"], rep["branches"])
	}

	var code string
	var radius int
	var start string
	_ = e.pg.QueryRow(ctx, "SELECT code, checkin_radius, to_char(work_start_time, 'HH24:MI') FROM core.branches WHERE name = 'Hà Nội 1'").Scan(&code, &radius, &start)
	if code != "HN01" || radius != 80 || start != "08:00" {
		t.Fatalf("chi nhánh: %s %d %s", code, radius, start)
	}
	_ = e.pg.QueryRow(ctx, "SELECT code FROM core.branches WHERE name = 'Hồ Chí Minh'").Scan(&code)
	if code != "HCM-01" {
		t.Fatalf("mã chi nhánh phải được sửa cho hợp lệ, nhận %q", code)
	}

	// Đăng nhập bằng mật khẩu Laravel; vai trò, chi nhánh chính, phân quyền cá nhân đúng như bên cũ
	c := e.client()
	expect(t, c.login("mes002", newPassword), 200, "")
	me := c.do("GET", "/auth/me", nil).body
	roles := me["roles"].([]any)
	branches := me["branches"].([]any)
	if len(roles) != 1 || roles[0].(map[string]any)["name"] != RoleManager || len(branches) != 2 ||
		branches[0].(map[string]any)["code"] != "HN01" || branches[0].(map[string]any)["primary"] != true {
		t.Fatalf("/auth/me sau khi nhập: roles %v branches %v", roles, branches)
	}
	perms := me["permissions"].([]any)
	has := func(p string) bool {
		for _, x := range perms {
			if x == p {
				return true
			}
		}
		return false
	}
	if !has("branch.update") || !has("user.view") || has("user.create") {
		t.Fatalf("quyền sau khi nhập: %v", perms)
	}
	// Tài khoản nghỉ việc: khóa; vai trò cũ "teacher" → GV Full-time
	expect(t, e.client().login("MES003", newPassword), 401, "invalid_credentials")
	var role string
	_ = e.pg.QueryRow(ctx, "SELECT ur.role FROM iam.user_roles ur JOIN iam.users u ON u.id = ur.user_id WHERE u.code = 'MES003'").Scan(&role)
	if role != RoleTeacherFulltime {
		t.Fatalf("vai trò đã bỏ phải đổi sang GV Full-time, nhận %q", role)
	}
}
