package reports

import (
	"bytes"
	"context"
	"crypto/rand"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"os"
	"strings"
	"testing"
	"time"

	"github.com/go-chi/chi/v5"
	"github.com/google/uuid"
	"github.com/jackc/pgx/v5/pgxpool"
	"github.com/pquerna/otp/totp"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/secretbox"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/store"
)

// Test HTTP với Postgres và Redis thật (TEST_DATABASE_URL, TEST_REDIS_URL), như module iam.

type env struct {
	t   *testing.T
	iam *iam.Service
	svc *Service
	srv *httptest.Server
	pg  *pgxpool.Pool
	box *secretbox.Box
	n   int
}

const password = "Bao-cao-nhan-su-2026"

func setup(t *testing.T, deps Deps) *env {
	t.Helper()
	dbURL, redisURL := os.Getenv("TEST_DATABASE_URL"), os.Getenv("TEST_REDIS_URL")
	if dbURL == "" || redisURL == "" {
		t.Skip("cần TEST_DATABASE_URL và TEST_REDIS_URL")
	}
	ctx := context.Background()
	pool, err := store.OpenPostgres(ctx, dbURL)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(pool.Close)
	if err := store.Migrate(ctx, pool, "up"); err != nil {
		t.Fatal(err)
	}
	if _, err := pool.Exec(ctx, "TRUNCATE iam.users, iam.roles, iam.role_permissions, core.branches CASCADE"); err != nil {
		t.Fatal(err)
	}
	if _, err := iam.SyncCatalog(ctx, pool); err != nil {
		t.Fatal(err)
	}
	rdb, err := store.OpenRedis(redisURL)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { rdb.Close() })
	if err := rdb.FlushDB(ctx).Err(); err != nil {
		t.Fatal(err)
	}
	key := make([]byte, 32)
	_, _ = rand.Read(key)
	box, _ := secretbox.FromBase64(base64.StdEncoding.EncodeToString(key))
	log := slog.New(slog.NewTextHandler(io.Discard, nil))
	isvc := iam.NewService(pool, rdb, box, log)
	svc := NewService(pool, log, deps)
	auth := iam.NewHandler(isvc, log)
	r := chi.NewRouter()
	r.Route("/api/v1", func(r chi.Router) {
		r.Use(iam.SameOrigin, auth.LoadSession)
		auth.Routes(r)
		NewHandler(svc, log).Routes(r)
	})
	srv := httptest.NewServer(r)
	t.Cleanup(srv.Close)
	return &env{t: t, iam: isvc, svc: svc, srv: srv, pg: pool, box: box}
}

func (e *env) exec(sql string, args ...any) {
	e.t.Helper()
	if _, err := e.pg.Exec(context.Background(), sql, args...); err != nil {
		e.t.Fatal(err)
	}
}

func (e *env) branch(code string) uuid.UUID {
	e.t.Helper()
	var id uuid.UUID
	if err := e.pg.QueryRow(context.Background(), "INSERT INTO core.branches (code, name) VALUES ($1, $2) RETURNING id",
		code, "Chi nhánh "+code).Scan(&id); err != nil {
		e.t.Fatal(err)
	}
	return id
}

type member struct {
	id     uuid.UUID
	code   string
	name   string
	secret string
}

// member: tài khoản đã đổi mật khẩu, có vai trò, chi nhánh (đầu tiên là chính), tạo từ 30 ngày trước, đã bật 2FA.
func (e *env) member(name string, branches []uuid.UUID, roles ...string) member {
	e.t.Helper()
	ctx := context.Background()
	e.n++
	code := fmt.Sprintf("bc%03d", e.n)
	u, _, err := e.iam.CreateUser(ctx, iam.NewUser{Kind: "staff", Code: code, FullName: name})
	if err != nil {
		e.t.Fatal(err)
	}
	hash, _ := iam.HashPassword(ctx, password)
	e.exec("UPDATE iam.users SET password_hash = $2, must_change_password = false, created_at = now() - interval '30 days' WHERE id = $1", u.ID, hash)
	if err := e.iam.SetRolesUnchecked(ctx, u.ID, roles); err != nil {
		e.t.Fatal(err)
	}
	for i, b := range branches {
		e.exec("INSERT INTO iam.user_branches (user_id, branch_id, is_primary) VALUES ($1, $2, $3)", u.ID, b, i == 0)
	}
	m := member{id: u.ID, code: code, name: name}
	// Bật sẵn 2FA cho mọi người (Admin, Quản lý, Học vụ / Học thuật bắt buộc).
	key, err := totp.Generate(totp.GenerateOpts{Issuer: "MEnglish", AccountName: code})
	if err != nil {
		e.t.Fatal(err)
	}
	m.secret = key.Secret()
	e.exec("UPDATE iam.users SET totp_secret = $2, totp_enabled_at = now() WHERE id = $1", u.ID, e.box.Seal([]byte(m.secret), u.ID[:]))
	return m
}

type client struct {
	e      *env
	cookie string
	csrf   string
}

type resp struct {
	status int
	body   map[string]any
}

func (c *client) do(method, path string, body any) resp {
	c.e.t.Helper()
	var rd io.Reader
	if body != nil {
		b, _ := json.Marshal(body)
		rd = bytes.NewReader(b)
	}
	req, _ := http.NewRequest(method, c.e.srv.URL+"/api/v1"+path, rd)
	if body != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	req.Header.Set("Origin", c.e.srv.URL)
	if c.cookie != "" {
		req.AddCookie(&http.Cookie{Name: iam.CookieName, Value: c.cookie})
	}
	if c.csrf != "" {
		req.Header.Set("X-CSRF-Token", c.csrf)
	}
	res, err := http.DefaultClient.Do(req)
	if err != nil {
		c.e.t.Fatal(err)
	}
	defer res.Body.Close()
	for _, ck := range res.Cookies() {
		if ck.Name == iam.CookieName {
			c.cookie = ck.Value
		}
	}
	out := resp{status: res.StatusCode, body: map[string]any{}}
	_ = json.NewDecoder(res.Body).Decode(&out.body)
	if tok, ok := out.body["csrf_token"].(string); ok {
		c.csrf = tok
	}
	return out
}

// as đăng nhập (kể cả bước 2FA). Mỗi người chỉ đăng nhập một lần trong một test (mã TOTP không dùng lại được).
func (e *env) as(m member) *client {
	e.t.Helper()
	c := &client{e: e}
	r := c.do("POST", "/auth/login", map[string]any{"identifier": m.code, "password": password})
	expect(e.t, r, 200, "")
	if r.body["status"] == "totp_required" {
		code, _ := totp.GenerateCode(m.secret, time.Now())
		expect(e.t, c.do("POST", "/auth/login/totp", map[string]any{"challenge": r.body["challenge"], "code": code}), 200, "")
	}
	return c
}

func expect(t *testing.T, r resp, status int, code string) {
	t.Helper()
	if r.status != status || (code != "" && r.body["error"] != code) {
		t.Fatalf("muốn %d %s, nhận %d %v", status, code, r.status, r.body)
	}
}

// invalid: 422 kèm đúng thông báo tiếng Việt.
func invalidMsg(t *testing.T, r resp, msg string) {
	t.Helper()
	if r.status != 422 || r.body["message"] != msg {
		t.Fatalf("muốn 422 %q, nhận %d %v", msg, r.status, r.body)
	}
}

func okMsg(t *testing.T, r resp, msg string) {
	t.Helper()
	if r.status != 200 || r.body["message"] != msg {
		t.Fatalf("muốn 200 %q, nhận %d %v", msg, r.status, r.body)
	}
}

// list đọc mảng key từ resp hoặc object.
func list(src any, key string) []any {
	var m map[string]any
	switch x := src.(type) {
	case resp:
		m = x.body
	case map[string]any:
		m = x
	}
	v, _ := m[key].([]any)
	return v
}

func obj(v any) map[string]any {
	m, _ := v.(map[string]any)
	return m
}

func (e *env) count(sql string, args ...any) int {
	e.t.Helper()
	var n int
	if err := e.pg.QueryRow(context.Background(), sql, args...).Scan(&n); err != nil {
		e.t.Fatal(err)
	}
	return n
}

// lastWeekday: ngày thứ Hai → thứ Bảy gần nhất trước hôm nay (giờ Việt Nam), cách ít nhất skip ngày.
func lastWeekday(skip int) time.Time {
	d := dayOf(time.Now()).AddDate(0, 0, -skip)
	for d.Weekday() == time.Sunday {
		d = d.AddDate(0, 0, -1)
	}
	return d
}

// ───────────── Quyền ─────────────

func TestPermissions(t *testing.T) {
	e := setup(t, Deps{})
	b := e.branch("HN1")
	sales := e.as(e.member("Tư vấn", []uuid.UUID{b}, iam.RoleSalesConsultant))
	teacher := e.as(e.member("Giáo viên", []uuid.UUID{b}, iam.RoleTeacherFulltime))
	manager := e.as(e.member("Quản lý", []uuid.UUID{b}, iam.RoleManager))

	expect(t, (&client{e: e}).do("GET", "/reports/my", nil), 401, "")
	expect(t, sales.do("GET", "/reports/my", nil), 403, "forbidden")
	expect(t, sales.do("GET", "/reports/journal", nil), 403, "forbidden")
	expect(t, teacher.do("GET", "/reports/all", nil), 403, "forbidden")

	// Báo cáo không thuộc vai trò.
	r := teacher.do("GET", "/reports/daily", nil)
	expect(t, r, 403, "report_not_for_role")
	if r.body["message"] != "Báo cáo ngày chỉ dành cho Học vụ / Học thuật." {
		t.Fatalf("thông báo: %v", r.body)
	}
	expect(t, teacher.do("POST", "/reports/academic-monthly", map[string]any{"month": "2026-10", "narrative": map[string]any{"overall": "x"}}), 403, "report_not_for_role")
	expect(t, teacher.do("GET", "/reports/teacher-monthly", nil), 200, "")

	// Báo cáo định kỳ chính của giáo viên là màn có cấu trúc.
	my := teacher.do("GET", "/reports/my", nil)
	if my.body["primary_kind"] != KindTeacherMonthly {
		t.Fatalf("primary_kind: %v", my.body)
	}

	// Quản lý xem tổng hợp nhưng không kiểm tra được (chỉ Admin).
	expect(t, manager.do("GET", "/reports/all", nil), 200, "")
	expect(t, manager.do("POST", "/reports/review-bulk", map[string]any{"ids": []string{uuid.NewString()}}), 403, "admin_only")
	expect(t, manager.do("GET", "/reports/not-a-uuid/detail", nil), 404, "not_found")
}

// ───────────── Báo cáo ngày ─────────────

func TestDailyReport(t *testing.T) {
	e := setup(t, Deps{})
	b := e.branch("HN1")
	staff := e.as(e.member("Học vụ A", []uuid.UUID{b}, iam.RoleAcademicStaff))

	day := lastWeekday(2)
	key := dateKey(day)
	r := staff.do("GET", "/reports/daily?date="+key, nil)
	expect(t, r, 200, "")
	if r.body["audience"] != AudienceOperations || r.body["date"] != key || r.body["live"] != true {
		t.Fatalf("form: %v", r.body)
	}
	secs := list(r, "sections")
	if len(secs) != 3 || obj(secs[0])["title"] != "Vận hành lớp học" {
		t.Fatalf("khối số liệu: %v", secs)
	}
	item := obj(list(obj(secs[0]), "items")[0])
	if item["label"] != "Số lớp kế hoạch" || item["unavailable"] == "" {
		t.Fatalf("mục số liệu: %v", item)
	}
	// Ngày còn thiếu (trước ngày đang xem, 14 ngày gần nhất) có trong danh sách.
	if len(list(r, "missing_days")) == 0 {
		t.Fatalf("missing_days rỗng: %v", r.body["missing_days"])
	}

	invalidMsg(t, staff.do("POST", "/reports/daily", map[string]any{"date": dateKey(dayOf(time.Now()).AddDate(0, 0, 2))}), "Không nộp báo cáo cho ngày chưa tới.")
	invalidMsg(t, staff.do("POST", "/reports/daily", map[string]any{"date": "2026-13-01"}), "Ngày báo cáo không hợp lệ.")
	expect(t, staff.do("POST", "/reports/daily", map[string]any{"date": key,
		"tasks": []map[string]any{{"title": "Gọi phụ huynh"}}}), 409, "tasks_unavailable")

	okMsg(t, staff.do("POST", "/reports/daily", map[string]any{"date": key, "notes": map[string]any{"operations": "Lớp A1 vắng 2 bạn", "bogus": "bỏ"}}),
		"Đã lưu báo cáo ngày "+day.Format("02/01/2006")+".")
	okMsg(t, staff.do("POST", "/reports/daily", map[string]any{"date": key, "notes": map[string]any{"operations": "Lớp A1 vắng 3 bạn"}}),
		"Đã lưu báo cáo ngày "+day.Format("02/01/2006")+".")
	if n := e.count("SELECT count(*) FROM ops.staff_reports WHERE kind = 'daily_ops'"); n != 1 {
		t.Fatalf("lưu lại phải cập nhật, có %d bản", n)
	}
	r = staff.do("GET", "/reports/daily?date="+key, nil)
	notes := obj(r.body["notes"])
	if notes["operations"] != "Lớp A1 vắng 3 bạn" || notes["bogus"] != nil || r.body["live"] != false || r.body["late"] != true {
		t.Fatalf("sau khi lưu: notes=%v live=%v late=%v", notes, r.body["live"], r.body["late"])
	}
	if obj(r.body["review"])["status"] != "pending" {
		t.Fatalf("review: %v", r.body["review"])
	}
	// Ngày đã nộp không còn trong danh sách thiếu.
	r = staff.do("GET", "/reports/daily", nil)
	for _, d := range list(r, "missing_days") {
		if obj(d)["date"] == key {
			t.Fatalf("ngày đã nộp vẫn bị tính thiếu: %v", d)
		}
	}
}

// ───────────── Báo cáo tuần ─────────────

func TestWeeklyReports(t *testing.T) {
	e := setup(t, Deps{})
	b := e.branch("HN1")
	lead := e.as(e.member("Học thuật", []uuid.UUID{b}, iam.RoleAcademicLead))
	staff := e.as(e.member("Học vụ", []uuid.UUID{b}, iam.RoleAcademicStaff))
	week := weekKey(time.Now())

	r := lead.do("GET", "/reports/academic-weekly", nil)
	expect(t, r, 200, "")
	if r.body["week"] != week || len(list(r, "weeks")) != 9 || len(list(r, "tabs")) < 2 {
		t.Fatalf("form tuần: week=%v weeks=%d tabs=%v", r.body["week"], len(list(r, "weeks")), r.body["tabs"])
	}
	invalidMsg(t, lead.do("POST", "/reports/academic-weekly", map[string]any{"week": week, "rows": map[string]any{}}), "Nhập ít nhất một công việc.")
	invalidMsg(t, lead.do("POST", "/reports/academic-weekly", map[string]any{"week": week,
		"rows": map[string]any{"done": []map[string]any{{"detail": "chỉ có chi tiết"}}}}), "Công việc đã làm trong tuần, dòng 1: Nhập nội dung công việc.")
	invalidMsg(t, lead.do("POST", "/reports/academic-weekly", map[string]any{"week": week,
		"rows": map[string]any{"planned": []map[string]any{{}, {"content": "Soạn đề", "progress": 30}}}}), "Công việc dự kiến tuần tới, dòng 2: Tiến độ chỉ nhận 0, 25, 50, 75 hoặc 100%.")
	invalidMsg(t, lead.do("POST", "/reports/academic-weekly", map[string]any{"week": week,
		"rows": map[string]any{"done": []map[string]any{{"content": "Soạn đề", "link": "drive"}}}}), "Công việc đã làm trong tuần, dòng 1: Link sản phẩm phải là đường dẫn (https://...).")
	invalidMsg(t, lead.do("POST", "/reports/academic-weekly", map[string]any{"week": "2099-W01",
		"rows": map[string]any{"done": []map[string]any{{"content": "x"}}}}), "Không nộp báo cáo cho tuần chưa tới.")
	r = lead.do("POST", "/reports/academic-weekly", map[string]any{"week": week, "rows": map[string]any{
		"done": []map[string]any{{"content": "Soạn đề Big Test", "progress": 100, "deadline": dateKey(time.Now()), "link": "https://drive.example/x"}}}})
	expect(t, r, 200, "")
	if !strings.HasPrefix(r.body["message"].(string), "Đã lưu báo cáo tuần ") {
		t.Fatalf("thông báo: %v", r.body)
	}
	r = lead.do("GET", "/reports/academic-weekly?week="+week, nil)
	if done := obj(r.body["rows"])["done"].([]any); len(done) != 1 || obj(done[0])["content"] != "Soạn đề Big Test" {
		t.Fatalf("rows: %v", r.body["rows"])
	}
	var content string
	_ = e.pg.QueryRow(context.Background(), "SELECT content FROM ops.staff_reports WHERE kind = 'academic_weekly'").Scan(&content)
	if !strings.Contains(content, "1. Soạn đề Big Test (deadline ") || !strings.Contains(content, "tiến độ 100%") {
		t.Fatalf("nội dung chữ: %q", content)
	}

	// Báo cáo tuần Học vụ: bảng KPI + ghi chú tuần.
	r = staff.do("GET", "/reports/weekly-kpi", nil)
	expect(t, r, 200, "")
	okMsg(t, staff.do("POST", "/reports/weekly-kpi", map[string]any{"week": week, "note": "Tuần ổn định"}),
		"Đã lưu báo cáo "+lcfirst(weekLabel(week))+".")
	expect(t, staff.do("GET", "/reports/academic-weekly", nil), 403, "report_not_for_role")
}

// ───────────── Báo cáo tháng / quý Học thuật ─────────────

func TestAcademicMonthlyQuarterly(t *testing.T) {
	e := setup(t, Deps{})
	b := e.branch("HN1")
	lead := e.as(e.member("Học thuật", []uuid.UUID{b}, iam.RoleAcademicLead))
	month := monthKey(time.Now())

	invalidMsg(t, lead.do("POST", "/reports/academic-monthly", map[string]any{"month": month, "narrative": map[string]any{"overall": "  "}}), "Nhập ít nhất một mục của báo cáo.")
	invalidMsg(t, lead.do("POST", "/reports/academic-monthly", map[string]any{"month": "2026-13", "narrative": map[string]any{"overall": "x"}}), "Tháng báo cáo không hợp lệ.")
	invalidMsg(t, lead.do("POST", "/reports/academic-monthly", map[string]any{"month": month, "narrative": map[string]any{"overall": strings.Repeat("a", 10001)}}), "Đánh giá chung tháng tối đa 10000 ký tự.")
	okMsg(t, lead.do("POST", "/reports/academic-monthly", map[string]any{"month": month, "narrative": map[string]any{"overall": "Tháng tốt", "bogus": "x"}}),
		"Đã lưu báo cáo "+strings.ToLower(monthLabel(month))+".")
	r := lead.do("GET", "/reports/academic-monthly?month="+month, nil)
	if obj(r.body["values"])["overall"] != "Tháng tốt" || len(list(r, "weekly_reports")) < 4 || r.body["submitted_at"] == nil {
		t.Fatalf("tháng: %v", r.body)
	}

	q := quarterKey(time.Now())
	r = lead.do("GET", "/reports/academic-quarterly", nil)
	found := false
	for _, m := range list(r, "monthly_reports") {
		if obj(m)["month"] == month {
			found = obj(m)["submitted"] == true
		}
	}
	if r.body["quarter"] != q || !found {
		t.Fatalf("quý: %v", r.body)
	}
	okMsg(t, lead.do("POST", "/reports/academic-quarterly", map[string]any{"quarter": q, "narrative": map[string]any{"next_plan": "Kế hoạch"}}),
		"Đã lưu báo cáo quý "+q+".")
}

// ───────────── Báo cáo tháng giáo viên ─────────────

type fakeMetrics struct {
	skeletonMetrics
	classes []TeacherClass
	kpis    []KpiCriterion
}

func (f fakeMetrics) TeacherClasses(context.Context, Writer, string) ([]TeacherClass, error) {
	return f.classes, nil
}

// Pending: bản giả có đủ nguồn (không hiện ghi chú "Chờ phân hệ …").
func (fakeMetrics) Pending(string) bool { return false }

func (f fakeMetrics) KpiCriteria(context.Context, string) ([]KpiCriterion, error) { return f.kpis, nil }

func TestTeacherMonthly(t *testing.T) {
	class := TeacherClass{ID: uuid.New(), Name: "IELTS 6.5 K12"}
	e := setup(t, Deps{Metrics: fakeMetrics{classes: []TeacherClass{class}}})
	b := e.branch("HN1")
	teacher := e.as(e.member("Giáo viên", []uuid.UUID{b}, iam.RoleTeacherFulltime))
	month := monthKey(time.Now())
	cid := class.ID.String()

	r := teacher.do("GET", "/reports/teacher-monthly", nil)
	expect(t, r, 200, "")
	if len(list(r, "classes")) != 1 || !strings.HasPrefix(r.body["due_label"].(string), "Chủ nhật ") || r.body["classes_note"] != nil {
		t.Fatalf("form: %v", r.body)
	}
	invalidMsg(t, teacher.do("POST", "/reports/teacher-monthly", map[string]any{"month": month}), "Nhập ít nhất một mục của báo cáo.")
	invalidMsg(t, teacher.do("POST", "/reports/teacher-monthly", map[string]any{"month": month,
		"classes": map[string]any{cid: map[string]any{"sessions": -1}}}), "Lớp IELTS 6.5 K12: Số buổi không âm.")
	invalidMsg(t, teacher.do("POST", "/reports/teacher-monthly", map[string]any{"month": month,
		"classes": map[string]any{cid: map[string]any{"program_progress": "fast"}}}), "Lớp IELTS 6.5 K12: Tiến độ chương trình không hợp lệ.")
	// Lớp không giữ trong tháng bị bỏ qua.
	invalidMsg(t, teacher.do("POST", "/reports/teacher-monthly", map[string]any{"month": month,
		"classes": map[string]any{uuid.NewString(): map[string]any{"sessions": 4}}}), "Nhập ít nhất một mục của báo cáo.")

	payload := map[string]any{"month": month, "intent": "draft", "general": map[string]any{"progress": "Đúng kế hoạch"},
		"classes": map[string]any{cid: map[string]any{"sessions": "8", "program_progress": "on_track",
			"checks": map[string]any{"syllabus_provided": true, "materials_order": false},
			"notes":  map[string]any{"materials_order": "Thiếu sách bài tập"}, "support_note": "Cần thêm trợ giảng"}}}
	okMsg(t, teacher.do("POST", "/reports/teacher-monthly", payload), "Đã lưu nháp báo cáo "+strings.ToLower(monthLabel(month))+".")
	r = teacher.do("GET", "/reports/teacher-monthly", nil)
	cv := obj(obj(r.body["class_values"])[cid])
	if r.body["status"] != "draft" || cv["sessions"] != float64(8) || cv["need_support"] != true || r.body["review"] != nil {
		t.Fatalf("nháp: status=%v class=%v", r.body["status"], cv)
	}
	payload["intent"] = "submit"
	okMsg(t, teacher.do("POST", "/reports/teacher-monthly", payload), "Đã nộp báo cáo giảng dạy "+strings.ToLower(monthLabel(month))+".")
	// Đã nộp thì "Lưu nháp" không kéo về nháp.
	payload["intent"] = "draft"
	okMsg(t, teacher.do("POST", "/reports/teacher-monthly", payload), "Đã nộp báo cáo giảng dạy "+strings.ToLower(monthLabel(month))+".")
	var content string
	_ = e.pg.QueryRow(context.Background(), "SELECT content FROM ops.staff_reports WHERE kind = 'teacher_monthly'").Scan(&content)
	for _, want := range []string{"Tiến độ giảng dạy:\nĐúng kế hoạch", "Lớp IELTS 6.5 K12:", "- Số buổi đã dạy: 8",
		"- Tiến độ chương trình: Đúng tiến độ", "- [x] Syllabus đã cung cấp", "- [ ] Tài liệu cần order thêm — Thiếu sách bài tập",
		"- Đề xuất hỗ trợ từ quản lý: Cần thêm trợ giảng"} {
		if !strings.Contains(content, want) {
			t.Fatalf("thiếu %q trong:\n%s", want, content)
		}
	}

	okMsg(t, teacher.do("POST", "/reports/teacher-attendance", map[string]any{"month": month, "note": ""}),
		"Đã nộp báo cáo chấm công "+strings.ToLower(monthLabel(month))+".")
	r = teacher.do("GET", "/reports/teacher-monthly", nil)
	if obj(r.body["attendance"])["submitted_at"] == nil {
		t.Fatalf("chấm công: %v", r.body["attendance"])
	}
}

// ───────────── Báo cáo chữ tự do ─────────────

func TestFreeReport(t *testing.T) {
	e := setup(t, Deps{})
	b := e.branch("HN1")
	ta := e.as(e.member("Trợ giảng", []uuid.UUID{b}, iam.RoleAssistant))

	r := ta.do("GET", "/reports/my", nil)
	expect(t, r, 200, "")
	if r.body["type"] != "monthly" || r.body["label"] != "Báo cáo tháng" || obj(r.body["monthly_due"])["submitted"] != false {
		t.Fatalf("my: %v", r.body)
	}
	invalidMsg(t, ta.do("POST", "/reports/my", map[string]any{"title": "", "content": "x"}), "Nhập tiêu đề báo cáo.")
	invalidMsg(t, ta.do("POST", "/reports/my", map[string]any{"title": "Tháng 10", "content": " "}), "Nhập nội dung báo cáo.")
	expect(t, ta.do("POST", "/reports/my", map[string]any{"title": "Tháng 10", "content": "x", "class_id": uuid.NewString()}), 403, "class_outside_scope")
	okMsg(t, ta.do("POST", "/reports/my", map[string]any{"title": "Tháng 10", "content": "Hỗ trợ 3 lớp"}), "Đã nộp Báo cáo tháng thành công!")
	r = ta.do("GET", "/reports/my", nil)
	if len(list(r, "reports")) != 1 || obj(r.body["monthly_due"])["submitted"] != true || obj(r.body["page"])["total"] != float64(1) {
		t.Fatalf("sau khi nộp: %v", r.body)
	}
}

// ───────────── Nhật ký Học vụ ─────────────

func TestJournal(t *testing.T) {
	e := setup(t, Deps{Metrics: fakeMetrics{kpis: []KpiCriterion{{ID: "1", Code: "HV01", Name: "Học sinh vắng không phép", Group: "Chuyên cần"}}}})
	b1, b2 := e.branch("HN1"), e.branch("HN2")
	staffM := e.member("Học vụ HN1", []uuid.UUID{b1}, iam.RoleAcademicStaff)
	otherM := e.member("Học vụ HN2", []uuid.UUID{b2}, iam.RoleAcademicStaff)
	staff, other := e.as(staffM), e.as(otherM)
	manager := e.as(e.member("Quản lý HN1", []uuid.UUID{b1}, iam.RoleManager))
	admin := e.as(e.member("Admin", nil, iam.RoleAdmin))

	invalidMsg(t, staff.do("POST", "/reports/journal", map[string]any{"content": " "}), "Vui lòng nhập nội dung sự việc.")
	invalidMsg(t, staff.do("POST", "/reports/journal", map[string]any{"content": "x", "kpi_code": "ZZ"}), "Mã KPI không có trong bộ KPI Học vụ đang áp dụng.")
	r := staff.do("POST", "/reports/journal", map[string]any{"content": "Lớp A1   vắng 2 bạn\nđã gọi phụ huynh", "kpi_code": "HV01"})
	okMsg(t, r, "Đã thêm nhật ký!")
	id := r.body["id"].(string)
	var title string
	_ = e.pg.QueryRow(context.Background(), "SELECT title FROM ops.staff_reports WHERE id = $1", id).Scan(&title)
	if title != "Lớp A1 vắng 2 bạn" {
		t.Fatalf("tiêu đề lấy từ dòng đầu: %q", title)
	}
	expect(t, other.do("POST", "/reports/journal", map[string]any{"content": "Sự việc HN2", "severity": "urgent"}), 200, "")

	// Học vụ chỉ thấy của mình; Quản lý HN1 thấy nhân sự HN1; Admin thấy hết.
	if n := len(list(staff.do("GET", "/reports/journal", nil), "journals")); n != 1 {
		t.Fatalf("học vụ thấy %d", n)
	}
	r = manager.do("GET", "/reports/journal", nil)
	if js := list(r, "journals"); len(js) != 1 || obj(js[0])["id"] != id || obj(js[0])["user_role"] != "Học vụ" || r.body["is_priv"] != true {
		t.Fatalf("quản lý thấy: %v", r.body["journals"])
	}
	if subs := list(r, "subjects"); len(subs) != 1 || obj(subs[0])["value"] != staffM.id.String() {
		t.Fatalf("subjects: %v", subs)
	}
	if kpis := list(r, "kpi_options"); len(kpis) != 1 || obj(kpis[0])["label"] != "KPI HV01 · Học sinh vắng không phép" {
		t.Fatalf("kpi_options: %v", kpis)
	}
	if n := len(list(admin.do("GET", "/reports/journal", nil), "journals")); n != 2 {
		t.Fatalf("admin thấy %d", n)
	}
	if n := len(list(admin.do("GET", "/reports/journal?severity=urgent", nil), "journals")); n != 1 {
		t.Fatalf("lọc mức độ: %d", n)
	}

	// Ghi thay: Quản lý ghi cho Học vụ chi nhánh mình được, chi nhánh khác thì không.
	expect(t, manager.do("POST", "/reports/journal", map[string]any{"content": "Ghi thay", "subject_user_id": staffM.id}), 200, "")
	r = manager.do("POST", "/reports/journal", map[string]any{"content": "Ghi thay", "subject_user_id": otherM.id})
	expect(t, r, 403, "forbidden")
	expect(t, staff.do("POST", "/reports/journal", map[string]any{"content": "Ghi thay", "subject_user_id": otherM.id}), 403, "forbidden")
	r = staff.do("GET", "/reports/journal", nil)
	if js := list(r, "journals"); len(js) != 2 || obj(js[0])["logged_by"] != "Quản lý HN1" {
		t.Fatalf("logged_by: %v", js)
	}

	// Follow-up: mới → đang theo dõi; người khác chi nhánh không thấy sự vụ.
	invalidMsg(t, staff.do("POST", "/reports/journal/"+id+"/followup", map[string]any{"content": ""}), "Vui lòng nhập nội dung tác vụ / follow-up.")
	expect(t, other.do("POST", "/reports/journal/"+id+"/followup", map[string]any{"content": "x"}), 404, "not_found")
	okMsg(t, manager.do("POST", "/reports/journal/"+id+"/followup", map[string]any{"content": "Đã nhắc GV"}), "Đã thêm follow-up cho sự vụ!")
	if n := e.count("SELECT count(*) FROM ops.staff_reports WHERE id = $1 AND status = 'following'", id); n != 1 {
		t.Fatal("follow-up phải chuyển sự vụ sang Đang theo dõi")
	}
	invalidMsg(t, staff.do("POST", "/reports/journal/"+id+"/status", map[string]any{"status": "done"}), "Trạng thái sự vụ không hợp lệ.")
	okMsg(t, staff.do("POST", "/reports/journal/"+id+"/status", map[string]any{"status": "resolved"}), "Đã cập nhật trạng thái sự vụ!")

	// Lỗi nghiêm trọng: chỉ Admin.
	expect(t, manager.do("POST", "/reports/journal/"+id+"/serious", map[string]any{"serious": true}), 403, "admin_only")
	okMsg(t, admin.do("POST", "/reports/journal/"+id+"/serious", map[string]any{"serious": true}), "Đã xác nhận lỗi nghiêm trọng.")
	r = staff.do("GET", "/reports/journal", nil)
	for _, j := range list(r, "journals") {
		if obj(j)["id"] == id && (obj(j)["serious"] != true || obj(j)["serious_by"] != "Admin" || len(list(obj(j), "followups")) != 1) {
			t.Fatalf("sự vụ: %v", j)
		}
	}
	okMsg(t, admin.do("POST", "/reports/journal/"+id+"/serious", map[string]any{"serious": false}), "Đã bỏ đánh dấu lỗi nghiêm trọng.")
}

// ───────────── Tổng hợp, phạm vi chi nhánh, kiểm tra ─────────────

func TestAllScopeAndReview(t *testing.T) {
	e := setup(t, Deps{})
	b1, b2 := e.branch("HN1"), e.branch("HN2")
	aM := e.member("Học vụ HN1", []uuid.UUID{b1}, iam.RoleAcademicStaff)
	bM := e.member("Học vụ HN2", []uuid.UUID{b2}, iam.RoleAcademicStaff)
	a, bc := e.as(aM), e.as(bM)
	manager := e.as(e.member("Quản lý HN1", []uuid.UUID{b1}, iam.RoleManager))
	admin := e.as(e.member("Admin", nil, iam.RoleAdmin))

	day := lastWeekday(1)
	key := dateKey(day)
	expect(t, a.do("POST", "/reports/daily", map[string]any{"date": key, "notes": map[string]any{"operations": "Ổn định"}}), 200, "")
	expect(t, bc.do("POST", "/reports/daily", map[string]any{"date": key}), 200, "")
	var aID, bID string
	_ = e.pg.QueryRow(context.Background(), "SELECT id FROM ops.staff_reports WHERE user_id = $1", aM.id).Scan(&aID)
	_ = e.pg.QueryRow(context.Background(), "SELECT id FROM ops.staff_reports WHERE user_id = $1", bM.id).Scan(&bID)

	// Quản lý HN1 chỉ thấy nhân sự HN1.
	r := manager.do("GET", "/reports/all", nil)
	rows := list(r, "reports")
	if len(rows) != 1 || obj(rows[0])["id"] != aID || obj(rows[0])["preview"] != "Vận hành lớp học: Ổn định" || obj(rows[0])["for"] != AudienceOperations {
		t.Fatalf("quản lý thấy: %v", rows)
	}
	if r.body["can_review"] != false || len(list(r, "branches")) != 1 || len(list(r, "users")) != 1 {
		t.Fatalf("tùy chọn lọc: can_review=%v branches=%v users=%v", r.body["can_review"], r.body["branches"], r.body["users"])
	}
	expect(t, manager.do("GET", "/reports/"+bID+"/detail", nil), 404, "not_found")
	r = manager.do("GET", "/reports/"+aID+"/detail", nil)
	expect(t, r, 200, "")
	if r.body["view"] != "daily" || obj(r.body["report"])["deadline"] == nil || obj(r.body["notes"])["operations"] != "Ổn định" {
		t.Fatalf("chi tiết: %v", r.body)
	}
	if r := manager.do("GET", "/reports/all?open="+bID, nil); r.body["open_id"] != nil {
		t.Fatalf("open ngoài phạm vi: %v", r.body["open_id"])
	}

	// Admin thấy cả hai; lọc chi nhánh / người.
	r = admin.do("GET", "/reports/all?review=pending", nil)
	if len(list(r, "reports")) != 2 || obj(r.body["stats"])["pending_review"] != float64(2) {
		t.Fatalf("admin: %v / %v", r.body["reports"], r.body["stats"])
	}
	if n := len(list(admin.do("GET", "/reports/all?branch_id="+b2.String(), nil), "reports")); n != 1 {
		t.Fatalf("lọc chi nhánh: %d", n)
	}
	if n := len(list(admin.do("GET", "/reports/all?type=weekly", nil), "reports")); n != 0 {
		t.Fatalf("lọc loại: %d", n)
	}
	if n := len(list(admin.do("GET", fmt.Sprintf("/reports/all?from=%s&to=%s", key, key), nil), "reports")); n != 2 {
		t.Fatalf("lọc ngày: %d", n)
	}

	// Yêu cầu bổ sung → người viết thấy trạng thái + nhận xét → nộp lại về chờ kiểm tra.
	invalidMsg(t, admin.do("POST", "/reports/"+aID+"/request-changes", map[string]any{"note": " "}), "Nhập nội dung cần bổ sung.")
	okMsg(t, admin.do("POST", "/reports/"+aID+"/request-changes", map[string]any{"note": "Bổ sung tình hình lớp"}), "Đã gửi yêu cầu bổ sung cho Học vụ HN1.")
	r = a.do("GET", "/reports/daily?date="+key, nil)
	if rv := obj(r.body["review"]); rv["status"] != "returned" || rv["note"] != "Bổ sung tình hình lớp" || rv["by"] != "Admin" {
		t.Fatalf("returned: %v", r.body["review"])
	}
	if n := len(list(admin.do("GET", "/reports/all?review=returned", nil), "reports")); n != 1 {
		t.Fatalf("lọc trả về: %d", n)
	}
	expect(t, a.do("POST", "/reports/daily", map[string]any{"date": key, "notes": map[string]any{"operations": "Ổn định, A1 vắng 1"}}), 200, "")
	if n := e.count("SELECT count(*) FROM ops.staff_reports WHERE id = $1 AND status = 'submitted' AND review_note IS NULL AND reviewed_by IS NULL", aID); n != 1 {
		t.Fatal("nộp lại phải về chờ kiểm tra")
	}

	// Kiểm tra từng bản và hàng loạt.
	okMsg(t, admin.do("POST", "/reports/"+aID+"/review", map[string]any{}), "Đã đánh dấu đã kiểm tra.")
	okMsg(t, admin.do("POST", "/reports/"+aID+"/review", map[string]any{"reviewed": false}), "Đã chuyển về chờ kiểm tra.")
	invalidMsg(t, admin.do("POST", "/reports/review-bulk", map[string]any{"ids": []string{}}), "Chọn ít nhất một báo cáo.")
	okMsg(t, admin.do("POST", "/reports/review-bulk", map[string]any{"ids": []string{aID, bID, uuid.NewString()}}), "Đã đánh dấu 2 báo cáo đã kiểm tra.")
	r = admin.do("GET", "/reports/"+aID+"/detail", nil)
	if obj(r.body["report"])["reviewed_by"] != "Admin" || r.body["next_id"] != nil {
		t.Fatalf("đã kiểm tra: %v next=%v", r.body["report"], r.body["next_id"])
	}
	if r := a.do("GET", "/reports/daily?date="+key, nil); obj(r.body["review"])["status"] != "reviewed" {
		t.Fatalf("người viết thấy: %v", r.body["review"])
	}
	// Thông báo: người viết nhận "Cần bổ sung", rồi "Đã kiểm tra" mỗi lần được kiểm tra (bỏ đánh dấu rồi kiểm tra hàng
	// loạt là lần thứ hai); Admin nhận "Đã bổ sung" khi nộp lại.
	if n := e.count("SELECT count(*) FROM core.notifications WHERE user_id = $1 AND type = 'staff_report_returned' AND title LIKE 'Cần bổ sung: Báo cáo ngày %'", aM.id); n != 1 {
		t.Fatalf("thông báo cần bổ sung: %d", n)
	}
	if n := e.count("SELECT count(*) FROM core.notifications WHERE user_id = $1 AND type = 'staff_report_reviewed'", aM.id); n != 2 {
		t.Fatalf("thông báo đã kiểm tra: %d", n)
	}
	if n := e.count("SELECT count(*) FROM core.notifications WHERE type = 'staff_report_resubmitted' AND body LIKE 'Học vụ HN1 đã nộp lại%'"); n != 1 {
		t.Fatalf("thông báo đã bổ sung: %d", n)
	}

	// Tab "Chưa nộp": ngày phải nộp còn thiếu của Học vụ trong phạm vi.
	r = manager.do("GET", "/reports/all?view=missing", nil)
	miss := obj(r.body["missing"])
	mrows := list(miss, "rows")
	if r.body["view"] != "missing" || len(mrows) != 1 || obj(mrows[0])["user"] != "Học vụ HN1" {
		t.Fatalf("chưa nộp: %v", miss)
	}
	for _, d := range list(obj(mrows[0]), "days") {
		if obj(d)["date"] == key {
			t.Fatalf("ngày đã nộp bị tính thiếu: %v", d)
		}
	}
}

// ───────────── Nhắc hạn ─────────────

func TestReminders(t *testing.T) {
	e := setup(t, Deps{})
	b := e.branch("HN1")
	aM := e.member("Học vụ A", []uuid.UUID{b}, iam.RoleAcademicStaff)
	bM := e.member("Học vụ B", []uuid.UUID{b}, iam.RoleAcademicStaff)
	teacherM := e.member("Giáo viên", []uuid.UUID{b}, iam.RoleTeacherParttime)
	taM := e.member("Trợ giảng", []uuid.UUID{b}, iam.RoleAssistant)
	// Thứ Năm 22/10/2026 08:00: hôm qua thứ Tư phải nộp; hạn báo cáo tháng Chủ nhật 25/10 (còn 3 ngày).
	e.svc.SetClock(func() time.Time { return vn(2026, 10, 22, 8, 0) })
	a, ta := e.as(aM), e.as(taM)
	expect(t, a.do("POST", "/reports/daily", map[string]any{"date": "2026-10-21"}), 200, "")
	expect(t, ta.do("POST", "/reports/my", map[string]any{"title": "Tháng 10", "content": "Đã hỗ trợ lớp", "report_date": "2026-10-20"}), 200, "")

	ctx := context.Background()
	daily, err := e.svc.DailyReminders(ctx)
	if err != nil {
		t.Fatal(err)
	}
	if len(daily) != 1 || daily[0].UserID != bM.id || daily[0].Title != "Chưa nộp báo cáo ngày 21/10" ||
		daily[0].Message != "Hạn nộp 09:00 22/10. Nộp sau hạn tính là nộp trễ." || daily[0].Link != "/reports/daily?date=2026-10-21" {
		t.Fatalf("nhắc ngày: %+v", daily)
	}
	monthly, err := e.svc.MonthlyReminders(ctx)
	if err != nil {
		t.Fatal(err)
	}
	if len(monthly) != 1 || monthly[0].UserID != teacherM.id || monthly[0].Link != "/reports/teacher-monthly?month=2026-10" ||
		monthly[0].Message != "Hạn nộp: Chủ nhật 25/10 (còn 3 ngày). Bạn chưa nộp báo cáo tháng này." {
		t.Fatalf("nhắc tháng: %+v", monthly)
	}
	d, err := e.svc.DailyDigest(ctx)
	if err != nil {
		t.Fatal(err)
	}
	if d == nil || d.Title != "Báo cáo ngày 21/10: đã nộp 1/2" || d.Message != "Chưa nộp: Học vụ B." {
		t.Fatalf("tổng hợp: %+v", d)
	}
	// Chủ nhật không ai phải nộp; ngày không phải ngày nhắc tháng.
	e.svc.SetClock(func() time.Time { return vn(2026, 10, 19, 8, 0) })
	if d, _ := e.svc.DailyDigest(ctx); d != nil {
		t.Fatalf("Chủ nhật vẫn có tổng hợp: %+v", d)
	}
	if m, _ := e.svc.MonthlyReminders(ctx); len(m) != 0 {
		t.Fatalf("19/10 không phải ngày nhắc: %+v", m)
	}
	// Vòng nhắc chạy mỗi việc một lần mỗi ngày.
	last := map[string]string{}
	e.svc.SetClock(func() time.Time { return vn(2026, 10, 22, 9, 10) })
	adminM := e.member("Admin", nil, iam.RoleAdmin)
	e.svc.runDueReminders(ctx, last)
	if last["remind-daily"] != "2026-10-22" || last["daily-digest"] != "2026-10-22" {
		t.Fatalf("vòng nhắc: %v", last)
	}
	// Đã gửi thật qua notify; chạy lại (vd. tiến trình khởi động lại) không gửi trùng.
	e.svc.runDueReminders(ctx, map[string]string{})
	for _, c := range []struct {
		user uuid.UUID
		typ  string
	}{{bM.id, "daily_report_due"}, {teacherM.id, "monthly_report_due"}, {adminM.id, "staff_report_digest"}} {
		if n := e.count("SELECT count(*) FROM core.notifications WHERE user_id = $1 AND type = $2", c.user, c.typ); n != 1 {
			t.Fatalf("thông báo %s: %d", c.typ, n)
		}
	}
	if n := e.count("SELECT count(*) FROM core.notifications WHERE user_id = $1", aM.id); n != 0 {
		t.Fatalf("người đã nộp vẫn bị nhắc: %d", n)
	}
}
