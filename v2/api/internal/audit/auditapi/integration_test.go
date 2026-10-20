package auditapi

import (
	"context"
	"io"
	"net/http"
	"strings"
	"testing"

	"github.com/go-chi/chi/v5"
	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam/iamtest"
)

func setup(t *testing.T) *iamtest.Env {
	e := iamtest.Setup(t, "core.audit_log")
	h := NewHandler(NewService(e.Pool, e.Log), e.Log)
	e.Serve(h.Routes, audit.Middleware(e.Pool, e.Log, "/api/v1", iam.CurrentUserID))
	return e
}

func logs(t *testing.T, c *iamtest.Client, query string) []map[string]any {
	t.Helper()
	r := c.Do("GET", "/activity-logs?"+query, nil)
	iamtest.Expect(t, r, 200, "")
	return iamtest.List(t, r, "logs")
}

func descriptions(list []map[string]any) []string {
	var out []string
	for _, l := range list {
		out = append(out, l["description"].(string))
	}
	return out
}

// Thao tác quản trị iam ghi nhật ký đúng mô tả, trước / sau, người làm; lọc theo nhóm, tìm kiếm, ngày.
func TestAdminActionsAreLogged(t *testing.T) {
	e := setup(t)
	_, admin := e.Member("MES001", "Quản trị", nil, iam.RoleAdmin)

	r := admin.Do("POST", "/branches", map[string]any{"code": "HN01", "name": "Hà Nội 1"})
	iamtest.Expect(t, r, 201, "")
	branch := r.Body["id"].(string)
	iamtest.Expect(t, admin.Do("PATCH", "/branches/"+branch, map[string]any{"code": "HN01", "name": "Hà Nội Một", "phone": "0912345678"}), 200, "")
	iamtest.Expect(t, admin.Do("POST", "/branches/"+branch+"/deactivate", nil), 200, "")
	iamtest.Expect(t, admin.Do("POST", "/branches/"+branch+"/activate", nil), 200, "")
	r = admin.Do("POST", "/users", map[string]any{"code": "GV001", "full_name": "Giáo viên A", "roles": []string{iam.RoleTeacherFulltime}, "primary_branch_id": branch})
	iamtest.Expect(t, r, 201, "")
	uid := r.Body["user"].(map[string]any)["id"].(string)
	iamtest.Expect(t, admin.Do("PUT", "/users/"+uid+"/roles", map[string]any{"roles": []string{iam.RoleTeacherFulltime, iam.RoleAssistant}}), 200, "")
	iamtest.Expect(t, admin.Do("POST", "/users/"+uid+"/lock", nil), 204, "")
	iamtest.Expect(t, admin.Do("POST", "/users/"+uid+"/reset-password", nil), 200, "")
	iamtest.Expect(t, admin.Do("PUT", "/users/"+uid+"/permissions", map[string]any{"overrides": []map[string]any{{"permission": "user.view", "allow": true}}}), 200, "")
	iamtest.Expect(t, admin.Do("PUT", "/roles/"+iam.RoleAssistant+"/permissions", map[string]any{"permissions": []string{"notification.view", "user.view"}}), 200, "")
	// Thao tác lỗi không ghi nhật ký
	iamtest.Expect(t, admin.Do("POST", "/branches", map[string]any{"code": "HN01", "name": "Trùng"}), 409, "code_taken")

	all := logs(t, admin, "")
	got := descriptions(all)
	if !strings.HasPrefix(got[0], `Cập nhật vai trò "assistant" (+1 / −`) {
		t.Fatalf("đổi quyền vai trò: %s", got[0])
	}
	got = got[1:]
	all = all[1:]
	want := []string{
		"Cập nhật phân quyền chi tiết cá nhân",
		"Đặt lại mật khẩu (bắt buộc đổi ở lần đăng nhập tới)",
		"Vô hiệu hóa tài khoản",
		"Cập nhật vai trò nhân viên",
		"Tạo tài khoản nhân viên mới",
		"Chuyển trạng thái chi nhánh [Hà Nội Một] sang Đang hoạt động",
		"Chuyển trạng thái chi nhánh [Hà Nội Một] sang Tạm dừng",
		"Cập nhật thông tin cơ sở chi nhánh: Hà Nội Một (HN01)",
		"Thêm mới cơ sở chi nhánh: Hà Nội 1 (HN01)",
	}
	// 2FA của Admin bật trong phiên này cũng được ghi (dòng chung "Tài khoản & Hồ sơ") ở cuối danh sách.
	if len(got) < len(want) || strings.Join(got[:len(want)], "|") != strings.Join(want, "|") {
		t.Fatalf("nhật ký:\n%s", strings.Join(got, "\n"))
	}

	update := all[7]
	if update["actor_name"] != "Quản trị" || update["module"] != audit.ModuleSystem || update["event_label"] != "Cập nhật" ||
		update["ip"] == nil || update["url"] != "PATCH /api/v1/branches/"+branch || update["subject"] != "Chi nhánh Hà Nội Một (HN01)" {
		t.Fatalf("dòng cập nhật chi nhánh: %v", update)
	}
	diff := update["diff"].([]any)
	if len(diff) != 2 || diff[0].(map[string]any)["field"] != "name" || diff[0].(map[string]any)["old"] != "Hà Nội 1" ||
		diff[1].(map[string]any)["field"] != "phone" || diff[1].(map[string]any)["old"] != "—" {
		t.Fatalf("trước / sau: %v", diff)
	}
	roles := all[3]["diff"].([]any)[0].(map[string]any)
	if roles["old"] != `["teacher_fulltime"]` || roles["new"] != `["assistant","teacher_fulltime"]` {
		t.Fatalf("đổi vai trò: %v", roles)
	}

	// Nhóm "Phân quyền" chỉ có thao tác người dùng & vai trò; tìm theo mô tả, theo mã người làm
	perm := logs(t, admin, "group=permission")
	for _, l := range perm {
		if l["module"] != audit.ModuleUsers && l["module"] != audit.ModuleAccount {
			t.Fatalf("nhóm phân quyền lẫn %v", l["module"])
		}
	}
	if n := len(logs(t, admin, "module="+strings.ReplaceAll(audit.ModuleSystem, " ", "+"))); n != 4 {
		t.Fatalf("phân hệ Cấu hình hệ thống có %d dòng", n)
	}
	if got := descriptions(logs(t, admin, "q=t%E1%BA%A1m+d%E1%BB%ABng")); len(got) != 1 {
		t.Fatalf("tìm 'tạm dừng': %v", got)
	}
	if n := len(logs(t, admin, "q=MES001")); n != len(all)+1 {
		t.Fatalf("tìm theo mã người làm: %d/%d", n, len(all))
	}
	if n := len(logs(t, admin, "q=%23"+uid)); n < 5 {
		t.Fatalf("tìm theo mã bản ghi: %d", n)
	}
	r = admin.Do("GET", "/activity-logs?date_from=2026-10-10&date_to=2026-10-01", nil)
	iamtest.Expect(t, r, 422, "invalid")
	if r.Body["message"] != "Ngày kết thúc phải sau hoặc bằng ngày bắt đầu." {
		t.Fatalf("thông báo: %v", r.Body["message"])
	}
	iamtest.Expect(t, admin.Do("GET", "/activity-logs?date_from=10/10/2026", nil), 422, "invalid")
	if n := len(logs(t, admin, "date_to=2020-01-01")); n != 0 {
		t.Fatalf("lọc tới năm 2020 còn %d dòng", n)
	}
	r = admin.Do("GET", "/activity-logs?per_page=3&page=2", nil)
	if len(iamtest.List(t, r, "logs")) != 3 || r.Body["total"].(float64) != float64(len(all)+1) {
		t.Fatalf("phân trang: %s", r.Raw)
	}

	opts := admin.Do("GET", "/activity-logs/options", nil)
	iamtest.Expect(t, opts, 200, "")
	st := opts.Body["stats"].(map[string]any)
	if st["total"].(float64) != float64(len(all)+1) || st["today"] != st["total"] || st["active_users"] != float64(1) {
		t.Fatalf("thống kê: %v", st)
	}
	if actors := iamtest.List(t, opts, "actors"); len(actors) != 1 || actors[0]["label"] != "Quản trị (MES001)" {
		t.Fatalf("người thực hiện: %v", actors)
	}

	csv := admin.Do("GET", "/activity-logs/export?group=permission", nil)
	iamtest.Expect(t, csv, 200, "")
	text := string(csv.Raw)
	if !strings.HasPrefix(text, "\xEF\xBB\xBFID,Thời điểm,Người thực hiện") || !strings.Contains(text, "Vô hiệu hóa tài khoản") ||
		strings.Contains(text, "Thêm mới cơ sở chi nhánh") || !strings.Contains(csv.Header.Get("Content-Disposition"), "nhat-ky-van-hanh-") {
		t.Fatalf("CSV:\n%s", text)
	}
}

// Không có activity_log.view thì không xem được; phạm vi own / branch / all như ActivityLogController::scoped.
func TestPermissionAndScope(t *testing.T) {
	e := setup(t)
	a, b := e.Branch("CN-A"), e.Branch("CN-B")
	ctx := context.Background()
	record := func(u iamtest.User, desc string) {
		if err := audit.Record(ctx, e.Pool, audit.Entry{ActorID: &u.ID, Module: audit.ModuleCRM, Event: audit.EventCreated, Description: desc}); err != nil {
			t.Fatal(err)
		}
	}
	manager := e.User("QL001", "Quản lý", []uuid.UUID{a}, iam.RoleManager)
	staffA := e.User("HV-A", "Học vụ A", []uuid.UUID{a}, iam.RoleAcademicStaff)
	staffB := e.User("HV-B", "Học vụ B", []uuid.UUID{b}, iam.RoleAcademicStaff)
	teacher := e.User("GV-A", "Giáo viên A", []uuid.UUID{a}, iam.RoleTeacherFulltime)
	record(manager, "của quản lý")
	record(staffA, "của học vụ A")
	record(staffB, "của học vụ B")
	record(teacher, "của giáo viên A")
	if err := audit.Record(ctx, e.Pool, audit.Entry{Module: audit.ModuleCRM, Description: "của hệ thống"}); err != nil {
		t.Fatal(err)
	}
	if err := audit.Record(ctx, e.Pool, audit.Entry{Description: " "}); err == nil {
		t.Fatal("thiếu mô tả phải lỗi")
	}

	iamtest.Expect(t, e.Login(teacher).Do("GET", "/activity-logs", nil), 403, "forbidden")

	// Quản lý cơ sở: activity_log.scope_branch → dòng của mình + nhân sự chi nhánh mình, không thấy chi nhánh khác / hệ thống
	mc := e.Login(manager)
	if got := descriptions(logs(t, mc, "group=crm")); strings.Join(got, "|") != "của giáo viên A|của học vụ A|của quản lý" {
		t.Fatalf("quản lý cơ sở theo chi nhánh: %v", got)
	}
	// Admin: toàn hệ thống → mọi dòng kể cả hệ thống
	admin := e.User("AD001", "Quản trị", nil, iam.RoleAdmin)
	if n := len(logs(t, e.Login(admin), "group=crm")); n != 5 {
		t.Fatalf("toàn hệ thống thấy %d/5 dòng", n)
	}
	// Học vụ được cấp quyền xem phạm vi chi nhánh: dòng của mình + nhân sự chi nhánh mình
	e.Grant(iam.RoleAcademicStaff, "activity_log.view", "activity_log.scope_branch")
	got := descriptions(logs(t, e.Login(staffA), "group=crm"))
	if strings.Join(got, "|") != "của giáo viên A|của học vụ A|của quản lý" {
		t.Fatalf("phạm vi chi nhánh: %v", got)
	}
	opts := e.Login(staffA).Do("GET", "/activity-logs/options", nil)
	// 3 dòng CRM + 2 dòng bật 2FA (tạo mã, xác nhận) của Quản lý cơ sở cùng chi nhánh + 2 dòng của chính mình
	if st := opts.Body["stats"].(map[string]any); st["total"] != float64(7) || st["active_users"] != float64(3) {
		t.Fatalf("thống kê theo phạm vi: %v", st)
	}
	// Chỉ phạm vi "của tôi"
	e.Revoke(iam.RoleAcademicStaff, "activity_log.scope_branch")
	got = descriptions(logs(t, e.Login(staffB), "group=crm"))
	if strings.Join(got, "|") != "của học vụ B" {
		t.Fatalf("phạm vi của tôi: %v", got)
	}
}

// Request ghi không gọi audit.Record vẫn có một dòng chung (như AuditOperationMiddleware), khóa nhạy cảm bị che,
// đường dẫn /auth/ không lưu dữ liệu gửi lên.
func TestFallbackEntry(t *testing.T) {
	e := iamtest.Setup(t, "core.audit_log")
	h := NewHandler(NewService(e.Pool, e.Log), e.Log)
	e.Serve(func(r chi.Router) {
		h.Routes(r)
		// Route giả của một module chưa gọi audit.Record
		r.With(iam.Require("branch.view")).Post("/students/import", func(w http.ResponseWriter, r *http.Request) {
			_, _ = io.Copy(io.Discard, r.Body)
			w.WriteHeader(http.StatusOK)
		})
		r.With(iam.Require("branch.view")).Post("/students/fail", func(w http.ResponseWriter, r *http.Request) {
			w.WriteHeader(http.StatusUnprocessableEntity)
		})
	}, audit.Middleware(e.Pool, e.Log, "/api/v1", iam.CurrentUserID))
	_, admin := e.Member("MES001", "Quản trị", nil, iam.RoleAdmin)
	before := len(logs(t, admin, ""))

	iamtest.Expect(t, admin.Do("POST", "/auth/password", map[string]any{"current_password": iamtest.Password, "new_password": "Mat-khau-moi-2026-rat-dai"}), 204, "")
	iamtest.Expect(t, admin.Do("POST", "/students/import", map[string]any{"name": "Lớp A", "secret_key": "abc", "rows": []any{map[string]any{"password": "x"}}}), 200, "")
	iamtest.Expect(t, admin.Do("POST", "/students/fail", map[string]any{"name": "Lỗi"}), 422, "")
	all := logs(t, admin, "")
	if len(all) != before+2 {
		t.Fatalf("muốn thêm 2 dòng, có %d → %d", before, len(all))
	}
	imp, pw := all[0], all[1]
	if pw["module"] != audit.ModuleAccount || pw["properties"].(map[string]any)["payload"] != nil ||
		pw["description"] != "Tạo mới / Thao tác dữ liệu thuộc phân hệ Tài khoản & Hồ sơ" {
		t.Fatalf("dòng đổi mật khẩu: %v", pw)
	}
	payload := imp["properties"].(map[string]any)["payload"].(map[string]any)
	if imp["module"] != audit.ModuleStudents || imp["description"] != "Tạo mới / Thao tác Học viên & Lớp học (Lớp A)" ||
		payload["secret_key"] != audit.MaskValue || payload["rows"].([]any)[0].(map[string]any)["password"] != audit.MaskValue ||
		imp["url"] != "POST /api/v1/students/import" || imp["request_id"] == nil {
		t.Fatalf("dòng chung: %v", imp)
	}
}
