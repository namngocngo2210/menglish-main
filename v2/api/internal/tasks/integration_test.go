package tasks

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
	"net/url"
	"os"
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

// Test HTTP với Postgres và Redis thật (TEST_DATABASE_URL, TEST_REDIS_URL; Redis DB riêng vì test xóa sạch nó).

type env struct {
	t     *testing.T
	pg    *pgxpool.Pool
	iam   *iam.Service
	tasks *Service
	box   *secretbox.Box
	srv   *httptest.Server
}

const password = "Trung-tam-tieng-anh-2026"

func setup(t *testing.T) *env {
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
	if _, err := pool.Exec(ctx, "TRUNCATE ops.work_tasks, iam.users, iam.roles, iam.role_permissions, core.branches CASCADE"); err != nil {
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
	iamSvc := iam.NewService(pool, rdb, box, log)
	taskSvc := NewService(pool, log)
	auth := iam.NewHandler(iamSvc, log)

	r := chi.NewRouter()
	r.Route("/api/v1", func(r chi.Router) {
		r.Use(iam.SameOrigin, auth.LoadSession)
		auth.Routes(r)
		NewHandler(taskSvc, log).Routes(r)
	})
	srv := httptest.NewServer(r)
	t.Cleanup(srv.Close)
	return &env{t: t, pg: pool, iam: iamSvc, tasks: taskSvc, box: box, srv: srv}
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

// member: nhân sự đã đổi mật khẩu, bật 2FA, có vai trò và chi nhánh chính (nil = chưa gán).
func (e *env) member(code string, branch *uuid.UUID, roles ...string) member {
	e.t.Helper()
	ctx := context.Background()
	name := "Người " + code
	u, _, err := e.iam.CreateUser(ctx, iam.NewUser{Kind: "staff", Code: code, FullName: name})
	if err != nil {
		e.t.Fatal(err)
	}
	hash, _ := iam.HashPassword(ctx, password)
	key, err := totp.Generate(totp.GenerateOpts{Issuer: "MEnglish", AccountName: code})
	if err != nil {
		e.t.Fatal(err)
	}
	sealed := e.box.Seal([]byte(key.Secret()), u.ID[:])
	if _, err := e.pg.Exec(ctx, `UPDATE iam.users SET password_hash = $2, must_change_password = false,
		totp_secret = $3, totp_enabled_at = now() WHERE id = $1`, u.ID, hash, sealed); err != nil {
		e.t.Fatal(err)
	}
	for _, r := range roles {
		if _, err := e.pg.Exec(ctx, "INSERT INTO iam.user_roles (user_id, role) VALUES ($1, $2)", u.ID, r); err != nil {
			e.t.Fatal(err)
		}
	}
	if branch != nil {
		if _, err := e.pg.Exec(ctx, "INSERT INTO iam.user_branches (user_id, branch_id, is_primary) VALUES ($1, $2, true)", u.ID, *branch); err != nil {
			e.t.Fatal(err)
		}
	}
	return member{id: u.ID, code: code, name: name, secret: key.Secret()}
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

// as đăng nhập kèm bước 2FA (mỗi người chỉ đăng nhập một lần mỗi test vì mã TOTP không dùng lại được).
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

func expectMsg(t *testing.T, r resp, status int, msg string) {
	t.Helper()
	if r.status != status || r.body["message"] != msg {
		t.Fatalf("muốn %d %q, nhận %d %v", status, msg, r.status, r.body)
	}
}

func optionIDs(list any) map[string]bool {
	out := map[string]bool{}
	for _, o := range list.([]any) {
		out[o.(map[string]any)["id"].(string)] = true
	}
	return out
}

func titles(r resp) []string {
	var out []string
	for _, t := range r.body["tasks"].([]any) {
		out = append(out, t.(map[string]any)["title"].(string))
	}
	return out
}

func allowedValues(task map[string]any) []string {
	var out []string
	for _, a := range task["allowed"].([]any) {
		out = append(out, a.(map[string]any)["value"].(string))
	}
	return out
}

func tomorrow() string { return time.Now().In(vnZone).AddDate(0, 0, 1).Format(time.DateOnly) }

func newTask(title string, assignee uuid.UUID) map[string]any {
	return map[string]any{"title": title, "assignee_id": assignee, "due_date": tomorrow(), "task_type": "one_time"}
}

// create giao việc và trả id việc vừa tạo.
func (c *client) create(body map[string]any) string {
	c.e.t.Helper()
	r := c.do("POST", "/tasks", body)
	expect(c.e.t, r, 201, "")
	return r.body["task"].(map[string]any)["id"].(string)
}

func (e *env) status(id string) string {
	e.t.Helper()
	var s string
	if err := e.pg.QueryRow(context.Background(), "SELECT status FROM ops.work_tasks WHERE id = $1", id).Scan(&s); err != nil {
		e.t.Fatal(err)
	}
	return s
}

func TestCreatePermissionsAndAssignees(t *testing.T) {
	e := setup(t)
	a, b := e.branch("CN-A"), e.branch("CN-B")
	admin := e.member("AD001", &b, iam.RoleAdmin)
	manager := e.member("QL001", &a, iam.RoleManager)
	teacher := e.member("GV001", &a, iam.RoleTeacherFulltime)
	teacherB := e.member("GV002", &b, iam.RoleTeacherFulltime)
	student := e.member("HV001", &a, iam.RoleStudent)
	noRequest := e.member("GV003", &a, iam.RoleTeacherFulltime)
	if _, err := e.pg.Exec(context.Background(), "INSERT INTO iam.permission_overrides (user_id, permission, allow) VALUES ($1, 'work_task.request', false)", noRequest.id); err != nil {
		t.Fatal(err)
	}

	// Không có work_task.view: không vào được danh sách
	expect(t, e.as(student).do("GET", "/tasks", nil), 403, "forbidden")
	// Có xem nhưng không có quyền giao / đề xuất
	nr := e.as(noRequest)
	expect(t, nr.do("GET", "/tasks", nil), 200, "")
	expect(t, nr.do("POST", "/tasks", newTask("Không được", admin.id)), 403, "forbidden")

	// Giáo viên chỉ đề xuất: giao cho Admin và người có quyền duyệt (Quản lý cơ sở), không giao cho GV khác
	tc := e.as(teacher)
	opts := tc.do("GET", "/tasks/options", nil)
	expect(t, opts, 200, "")
	ids := optionIDs(opts.body["users"])
	if !ids[admin.id.String()] || !ids[manager.id.String()] || ids[teacherB.id.String()] || ids[teacher.id.String()] {
		t.Fatalf("người nhận của GV: %v", opts.body["users"])
	}
	if opts.body["title"] != "Đề xuất việc cho Admin / Học vụ" || opts.body["can_ta_assign"] != false {
		t.Fatalf("form đề xuất: %v", opts.body)
	}
	expectMsg(t, tc.do("POST", "/tasks", newTask("Việc cho GV khác", teacherB.id)), 422,
		"Bạn chỉ được giao việc cho Admin, Quản lý cơ sở, Học vụ hoặc Học thuật.")
	r := tc.do("POST", "/tasks", newTask("Việc cho Admin từ GV", admin.id))
	expect(t, r, 201, "")
	task := r.body["task"].(map[string]any)
	if task["status"] != StatusNew || task["due_time"] != "18:00" || task["creator"] != teacher.name || task["type_label"] != "Phát sinh" {
		t.Fatalf("việc vừa tạo: %v", task)
	}
	if r.body["message"] != "Đã giao việc 'Việc cho Admin từ GV' thành công cho nhân sự!" {
		t.Fatalf("thông báo: %v", r.body["message"])
	}

	// Quản lý cơ sở (phạm vi chi nhánh): nhân sự chi nhánh mình + chính mình + Admin
	mc := e.as(manager)
	opts = mc.do("GET", "/tasks/options", nil)
	ids = optionIDs(opts.body["users"])
	if !ids[admin.id.String()] || !ids[teacher.id.String()] || !ids[manager.id.String()] || ids[teacherB.id.String()] {
		t.Fatalf("người nhận của Quản lý cơ sở: %v", opts.body["users"])
	}
	if opts.body["title"] != "Giao việc mới" || opts.body["can_ta_assign"] != true {
		t.Fatalf("form giao việc: %v", opts.body)
	}
	expect(t, mc.do("POST", "/tasks", newTask("Ngoài chi nhánh", teacherB.id)), 422, "invalid")

	// Kiểm dữ liệu
	bad := newTask("", teacher.id)
	expectMsg(t, mc.do("POST", "/tasks", bad), 422, "Vui lòng nhập tiêu đề công việc.")
	bad = newTask("Việc", teacher.id)
	bad["due_date"] = "16/10/2026"
	expectMsg(t, mc.do("POST", "/tasks", bad), 422, "Hạn hoàn thành không phải là ngày hợp lệ.")
	bad = newTask("Việc", teacher.id)
	delete(bad, "assignee_id")
	expectMsg(t, mc.do("POST", "/tasks", bad), 422, "Vui lòng chọn người nhận.")
	bad = newTask("Việc", teacher.id)
	bad["task_type"] = "recurring"
	bad["frequency"] = "yearly"
	expectMsg(t, mc.do("POST", "/tasks", bad), 422, "Tần suất không hợp lệ.")
	bad["frequency"] = ""
	bad["branch_id"] = b
	r = mc.do("POST", "/tasks", bad)
	expect(t, r, 201, "")
	if got := r.body["task"].(map[string]any); got["type_label"] != "Lặp đi lặp lại (Hàng tuần)" || got["branch"] != "Chi nhánh CN-B" {
		t.Fatalf("việc lặp mặc định hàng tuần: %v", got)
	}
}

func TestListTabsCountsAndFilters(t *testing.T) {
	e := setup(t)
	a := e.branch("CN-A")
	admin := e.member("AD001", &a, iam.RoleAdmin)
	academic := e.member("HV001", &a, iam.RoleAcademicStaff)
	teacher := e.member("GV001", &a, iam.RoleTeacherFulltime)
	ta := e.member("TG001", &a, iam.RoleAssistant)

	ac := e.as(admin)
	ac.create(newTask("Việc Admin giao", ta.id))
	tc := e.as(teacher)
	tc.create(newTask("Việc giao cho Admin", admin.id))
	hc := e.as(academic)
	hc.create(newTask("Việc người khác", teacher.id))
	other := hc.create(newTask("Việc người khác chờ duyệt", ta.id))
	if _, err := e.pg.Exec(context.Background(), "UPDATE ops.work_tasks SET status = 'pending_confirmation' WHERE id = $1", other); err != nil {
		t.Fatal(err)
	}
	if _, err := e.pg.Exec(context.Background(), "UPDATE ops.work_tasks SET status = 'overdue' WHERE title = 'Việc Admin giao'"); err != nil {
		t.Fatal(err)
	}

	// Kể cả Admin chỉ có "Của tôi" và "Tôi giao"
	r := ac.do("GET", "/tasks", nil)
	expect(t, r, 200, "")
	if got := titles(r); len(got) != 1 || got[0] != "Việc giao cho Admin" || r.body["tab"] != "mine" {
		t.Fatalf("tab Của tôi: %v", r.body)
	}
	if got := titles(ac.do("GET", "/tasks?tab=assigned", nil)); len(got) != 1 || got[0] != "Việc Admin giao" {
		t.Fatalf("tab Tôi giao: %v", got)
	}
	if got := titles(ac.do("GET", "/tasks?tab=all", nil)); len(got) != 1 || got[0] != "Việc giao cho Admin" {
		t.Fatalf("tab lạ quay về Của tôi: %v", got)
	}
	counts := r.body["counts"].(map[string]any)
	if counts["mine"] != 1.0 || counts["assigned"] != 1.0 || counts["overdue"] != 1.0 || counts["pending"] != 0.0 || counts["approvals"] != 1.0 {
		t.Fatalf("số đếm: %v", counts)
	}
	if ids := optionIDs(r.body["assignees"]); len(ids) != 2 || !ids[admin.id.String()] || !ids[ta.id.String()] {
		t.Fatalf("ô Nhân viên: %v", r.body["assignees"])
	}

	// Lọc: trạng thái, hạn, người nhận, từ khóa
	hcList := func(q string) []string { return titles(hc.do("GET", "/tasks?tab=assigned&"+q, nil)) }
	if got := hcList("status=pending_confirmation"); len(got) != 1 || got[0] != "Việc người khác chờ duyệt" {
		t.Fatalf("lọc trạng thái: %v", got)
	}
	if got := hcList("assignee_id=" + teacher.id.String()); len(got) != 1 || got[0] != "Việc người khác" {
		t.Fatalf("lọc người nhận: %v", got)
	}
	if got := hcList("q=" + url.QueryEscape("chờ DUYỆT")); len(got) != 1 {
		t.Fatalf("tìm kiếm: %v", got)
	}
	if got := hcList("q=" + url.QueryEscape(ta.name)); len(got) != 1 {
		t.Fatalf("tìm theo tên người nhận: %v", got)
	}
	day := tomorrow()
	if got := hcList("date_from=" + day + "&date_to=" + day); len(got) != 2 {
		t.Fatalf("lọc hạn: %v", got)
	}
	if got := hcList("date_to=2000-01-01"); len(got) != 0 {
		t.Fatalf("lọc hạn quá khứ: %v", got)
	}
	expectMsg(t, hc.do("GET", "/tasks?date_from=2026-10-10&date_to=2026-10-01", nil), 422, "Hạn đến ngày phải sau hoặc bằng hạn từ ngày.")
	// Phân trang
	p := hc.do("GET", "/tasks?tab=assigned&per_page=10&page=2", nil)
	if p.body["total"] != 2.0 || len(p.body["tasks"].([]any)) != 0 {
		t.Fatalf("trang vượt quá: %v", p.body)
	}
	// Thứ tự: Chờ xác nhận trước Mới
	if got := hcList(""); got[0] != "Việc người khác chờ duyệt" {
		t.Fatalf("thứ tự trạng thái: %v", got)
	}
}

func TestStatusFlowApprovalAndRecurring(t *testing.T) {
	e := setup(t)
	a := e.branch("CN-A")
	admin := e.member("AD001", &a, iam.RoleAdmin)
	manager := e.member("QL001", &a, iam.RoleManager)
	teacher := e.member("GV001", &a, iam.RoleTeacherFulltime)

	mc := e.as(manager)
	body := newTask("Báo cáo tuần", teacher.id)
	body["task_type"], body["frequency"] = "recurring", "daily"
	id := mc.create(body)
	tc := e.as(teacher)
	ac := e.as(admin)

	d := tc.do("GET", "/tasks/"+id, nil)
	expect(t, d, 200, "")
	if got := allowedValues(d.body); fmt.Sprint(got) != "[in_progress blocked pending_confirmation]" {
		t.Fatalf("bước của người làm: %v", got)
	}
	st := func(c *client, status, reason string) resp {
		return c.do("POST", "/tasks/"+id+"/status", map[string]any{"status": status, "reason": reason})
	}
	expectMsg(t, st(tc, StatusCanceled, "x"), 422, `Không thể chuyển công việc từ "Mới" sang trạng thái này.`)
	expectMsg(t, st(tc, StatusBlocked, ""), 422, "Vui lòng nhập lý do khiến công việc bị chặn.")
	expectMsg(t, st(tc, StatusInProgress, ""), 200, "Đã cập nhật trạng thái công việc thành công!")
	expectMsg(t, st(tc, StatusCompleted, ""), 422, `Người thực hiện không tự xác nhận hoàn thành: hãy gửi "Chờ xác nhận" để người giao việc duyệt.`)
	expect(t, st(tc, "done", ""), 422, "invalid")
	expect(t, st(tc, StatusPendingConfirmation, "Đã xong, xem https://drive.example/bao-cao"), 200, "")

	// Người giao (Quản lý cơ sở) không duyệt; Admin thấy ở màn xác nhận
	expect(t, mc.do("POST", "/tasks/"+id+"/approve", map[string]any{}), 403, "admin_only")
	if items := mc.do("GET", "/tasks/approvals", nil).body["items"].([]any); len(items) != 0 {
		t.Fatalf("Quản lý cơ sở không có việc chờ mình xác nhận: %v", items)
	}
	ap := ac.do("GET", "/tasks/approvals", nil)
	items := ap.body["items"].([]any)
	if len(items) != 1 {
		t.Fatalf("màn xác nhận: %v", ap.body)
	}
	item := items[0].(map[string]any)
	if item["person"] != teacher.name || item["creator"] != manager.name || fmt.Sprint(item["links"]) != "[https://drive.example/bao-cao]" {
		t.Fatalf("mục chờ xác nhận: %v", item)
	}
	expectMsg(t, ac.do("POST", "/tasks/"+id+"/reject", map[string]any{"admin_note": " "}), 422, "Vui lòng nhập lý do từ chối / yêu cầu bổ sung.")
	expectMsg(t, ac.do("POST", "/tasks/"+id+"/reject", map[string]any{"admin_note": "Thiếu số liệu"}), 200, "Đã từ chối/yêu cầu bổ sung cho công việc 'Báo cáo tuần'!")
	if e.status(id) != StatusInProgress {
		t.Fatal("trả về → Đang thực hiện")
	}
	expect(t, ac.do("POST", "/tasks/"+id+"/approve", map[string]any{}), 422, "not_pending")
	expect(t, st(tc, StatusPendingConfirmation, ""), 200, "")
	expectMsg(t, ac.do("POST", "/tasks/"+id+"/approve", map[string]any{"admin_note": "Tốt"}), 200, "Đã xác nhận hoàn thành công việc 'Báo cáo tuần'!")

	var note string
	var confirmedBy uuid.UUID
	if err := e.pg.QueryRow(context.Background(), "SELECT completion_note, confirmed_by FROM ops.work_tasks WHERE id = $1", id).Scan(&note, &confirmedBy); err != nil {
		t.Fatal(err)
	}
	if note != "Đã xong, xem https://drive.example/bao-cao\n[Ghi chú duyệt]: Tốt" || confirmedBy != admin.id {
		t.Fatalf("ghi chú duyệt: %q %v", note, confirmedBy)
	}
	// Việc lặp hàng ngày: tự tạo lượt kế tiếp (hạn + 1 ngày)
	var n int
	next := time.Now().In(vnZone).AddDate(0, 0, 2).Format(time.DateOnly)
	if err := e.pg.QueryRow(context.Background(), "SELECT count(*) FROM ops.work_tasks WHERE title = 'Báo cáo tuần' AND status = 'new' AND due_date = $1", next).Scan(&n); err != nil || n != 1 {
		t.Fatalf("lượt kế tiếp của việc lặp: %d %v", n, err)
	}

	// Admin không tự duyệt việc của mình
	own := ac.create(newTask("Việc của Admin", admin.id))
	expect(t, ac.do("POST", "/tasks/"+own+"/status", map[string]any{"status": StatusPendingConfirmation}), 200, "")
	expect(t, ac.do("POST", "/tasks/"+own+"/approve", map[string]any{}), 403, "self_approval")
	// Người ngoài không đổi được trạng thái
	expect(t, tc.do("POST", "/tasks/"+own+"/status", map[string]any{"status": StatusInProgress}), 403, "forbidden")

	// Thông báo như WorkTaskController: người làm nhận giao việc / trả về / xác nhận; Admin nhận "chờ xác nhận"
	// (tự giao cho mình, tự gửi chờ xác nhận thì không ai nhận)
	if got := e.inbox(teacher.id); got != "Bạn được giao việc: Báo cáo tuần|Công việc bị trả về: Báo cáo tuần|Việc đã được xác nhận hoàn thành: Báo cáo tuần" {
		t.Fatalf("thông báo người làm: %s", got)
	}
	if got := e.inbox(admin.id); got != "Việc chờ xác nhận: Báo cáo tuần|Việc chờ xác nhận: Báo cáo tuần" {
		t.Fatalf("thông báo Admin: %s", got)
	}
	if got := e.inbox(manager.id); got != "" {
		t.Fatalf("người giao không nhận gì: %s", got)
	}
}

// inbox: tiêu đề thông báo của một người (theo thứ tự gửi), nối bằng "|".
func (e *env) inbox(user uuid.UUID) string {
	e.t.Helper()
	var s string
	if err := e.pg.QueryRow(context.Background(), `SELECT coalesce(string_agg(title, '|' ORDER BY id), '') FROM core.notifications
		WHERE user_id = $1`, user).Scan(&s); err != nil {
		e.t.Fatal(err)
	}
	return s
}

func TestHandBackAndCancel(t *testing.T) {
	e := setup(t)
	a := e.branch("CN-A")
	admin := e.member("AD001", &a, iam.RoleAdmin)
	manager := e.member("QL001", &a, iam.RoleManager)
	teacher := e.member("GV001", &a, iam.RoleTeacherFulltime)
	mc, tc, ac := e.as(manager), e.as(teacher), e.as(admin)

	id := mc.create(newTask("Chuẩn bị phòng", teacher.id))
	st := func(c *client, body map[string]any) resp { return c.do("POST", "/tasks/"+id+"/status", body) }
	expectMsg(t, st(tc, map[string]any{"status": HandBack}), 422, `Không thể chuyển công việc từ "Mới" sang trạng thái này.`)
	expect(t, st(tc, map[string]any{"status": StatusBlocked, "reason": "Thiếu chìa khóa"}), 200, "")

	d := tc.do("GET", "/tasks/"+id, nil)
	if got := allowedValues(d.body); fmt.Sprint(got) != "[in_progress hand_back]" {
		t.Fatalf("việc bị chặn, người làm: %v", got)
	}
	if got := allowedValues(mc.do("GET", "/tasks/"+id, nil).body); fmt.Sprint(got) != "[hand_back canceled]" {
		t.Fatalf("việc bị chặn, người giao: %v", got)
	}
	expectMsg(t, st(tc, map[string]any{"status": HandBack, "note": "Nhờ anh xử lý"}), 200, "Đã chuyển công việc lại cho người giao.")
	if got := e.inbox(manager.id); got != "SOS – Việc bị chặn: Chuẩn bị phòng|Việc bị chặn chuyển lại cho bạn: Chuẩn bị phòng" {
		t.Fatalf("thông báo người giao: %s", got)
	}
	if got := e.inbox(admin.id); got != "SOS – Việc bị chặn: Chuẩn bị phòng" {
		t.Fatalf("SOS cho Admin: %s", got)
	}

	var assignee, from uuid.UUID
	var status, reason string
	if err := e.pg.QueryRow(context.Background(), "SELECT assignee_id, handed_back_from_id, status, blocked_reason FROM ops.work_tasks WHERE id = $1", id).
		Scan(&assignee, &from, &status, &reason); err != nil {
		t.Fatal(err)
	}
	if assignee != manager.id || from != teacher.id || status != StatusNew || reason != "Thiếu chìa khóa\n[Ghi chú khi chuyển lại – "+teacher.name+"]: Nhờ anh xử lý" {
		t.Fatalf("chuyển lại: %v %v %s %q", assignee, from, status, reason)
	}
	// Người làm cũ vẫn mở được chi tiết nhưng không còn thao tác
	d = tc.do("GET", "/tasks/"+id, nil)
	expect(t, d, 200, "")
	if len(d.body["allowed"].([]any)) != 0 || d.body["handed_back_from"] != teacher.name {
		t.Fatalf("người làm cũ xem chi tiết: %v", d.body)
	}
	// Danh sách "Tôi giao" của người giao hiện "Chuyển lại từ"
	list := mc.do("GET", "/tasks", nil)
	if row := list.body["tasks"].([]any)[0].(map[string]any); row["handed_back_from"] != teacher.name {
		t.Fatalf("dòng danh sách: %v", row)
	}

	// Hủy: chỉ người giao và Admin, bắt buộc lý do
	id2 := mc.create(newTask("Việc sẽ hủy", teacher.id))
	expect(t, tc.do("POST", "/tasks/"+id2+"/status", map[string]any{"status": StatusCanceled, "reason": "x"}), 422, "invalid")
	expectMsg(t, ac.do("POST", "/tasks/"+id2+"/status", map[string]any{"status": StatusCanceled}), 422, "Vui lòng nhập lý do hủy công việc.")
	expect(t, ac.do("POST", "/tasks/"+id2+"/status", map[string]any{"status": StatusCanceled, "reason": "Không cần nữa"}), 200, "")
	if e.status(id2) != StatusCanceled {
		t.Fatal("Admin hủy được việc")
	}
}

func TestBranchScope(t *testing.T) {
	e := setup(t)
	a, b := e.branch("CN-A"), e.branch("CN-B")
	admin := e.member("AD001", nil, iam.RoleAdmin)
	manager := e.member("QL001", &a, iam.RoleManager)
	teacherA := e.member("GV001", &a, iam.RoleTeacherFulltime)
	teacherB := e.member("GV002", &b, iam.RoleTeacherFulltime)
	ac := e.as(admin)
	inA := ac.create(newTask("Việc của GV chi nhánh A", teacherA.id))
	inB := ac.create(newTask("Việc của GV chi nhánh B", teacherB.id))
	branchA := newTask("Việc gắn chi nhánh A", teacherB.id)
	branchA["branch_id"] = a
	taggedA := ac.create(branchA)

	mc := e.as(manager)
	expect(t, mc.do("GET", "/tasks/"+inA, nil), 200, "")
	expect(t, mc.do("GET", "/tasks/"+taggedA, nil), 200, "")
	expect(t, mc.do("GET", "/tasks/"+inB, nil), 404, "not_found")
	expect(t, mc.do("GET", "/tasks/"+uuid.NewString(), nil), 404, "not_found")
	// Quản lý cơ sở có quyền duyệt công việc: thao tác được với việc trong phạm vi (không phải người giao / người làm)
	expect(t, mc.do("POST", "/tasks/"+inA+"/status", map[string]any{"status": StatusCanceled, "reason": "x"}), 422, "invalid")
	expect(t, mc.do("POST", "/tasks/"+inB+"/status", map[string]any{"status": StatusCanceled, "reason": "x"}), 403, "forbidden")

	// Giáo viên (phạm vi của tôi) chỉ thấy việc mình
	tb := e.as(teacherB)
	expect(t, tb.do("GET", "/tasks/"+inB, nil), 200, "")
	expect(t, tb.do("GET", "/tasks/"+inA, nil), 404, "not_found")
}

func TestTAAssignPortalAndComplete(t *testing.T) {
	e := setup(t)
	a, b := e.branch("CN-A"), e.branch("CN-B")
	e.member("AD001", nil, iam.RoleAdmin)
	manager := e.member("QL001", &a, iam.RoleManager)
	teacher := e.member("GV001", &a, iam.RoleTeacherFulltime)
	ta := e.member("TG001", &a, iam.RoleAssistant)
	taB := e.member("TG002", &b, iam.RoleAssistant)

	// Cố định giờ: 16:00 hôm nay (sau 15:30 → gửi trễ)
	n := time.Now().In(vnZone)
	fixed := time.Date(n.Year(), n.Month(), n.Day(), 16, 0, 0, 0, vnZone)
	e.tasks.now = func() time.Time { return fixed }
	today := fixed.Format(time.DateOnly)

	gv := e.as(teacher)
	expect(t, gv.do("GET", "/tasks/ta-assign/options", nil), 403, "forbidden")
	mc := e.as(manager)
	opts := mc.do("GET", "/tasks/ta-assign/options", nil)
	expect(t, opts, 200, "")
	if ids := optionIDs(opts.body["assistants"]); len(ids) != 1 || !ids[ta.id.String()] {
		t.Fatalf("trợ giảng trong phạm vi: %v", opts.body["assistants"])
	}
	if ids := optionIDs(opts.body["branches"]); len(ids) != 1 || !ids[a.String()] || opts.body["today"] != today {
		t.Fatalf("chi nhánh trong phạm vi: %v", opts.body)
	}

	body := map[string]any{"assistant_id": taB.id, "assign_date": today, "tasks": []map[string]any{{"category": "before", "content": "Mở phòng"}}}
	expectMsg(t, mc.do("POST", "/tasks/ta-assign", body), 422, "Chỉ giao cho trợ giảng đang hoạt động trong phạm vi bạn quản lý.")
	body["assistant_id"], body["branch_id"] = ta.id, b
	expectMsg(t, mc.do("POST", "/tasks/ta-assign", body), 422, "Bạn chỉ giao việc trong chi nhánh mình quản lý.")
	body["branch_id"] = a
	body["tasks"] = []map[string]any{{"category": "before", "content": " "}}
	expectMsg(t, mc.do("POST", "/tasks/ta-assign", body), 422, "Nội dung đầu việc không được để trống.")
	body["tasks"] = []map[string]any{}
	expectMsg(t, mc.do("POST", "/tasks/ta-assign", body), 422, "Cần ít nhất một đầu việc.")
	body["tasks"] = []map[string]any{
		{"category": "before", "content": "Mở phòng, bật máy chiếu"},
		{"category": "after", "content": "Dọn phòng"},
	}
	r := mc.do("POST", "/tasks/ta-assign", body)
	expect(t, r, 201, "")
	if r.body["created"] != 2.0 || r.body["late"] != true ||
		r.body["message"] != "Đã tạo thành công 2 nhiệm vụ cho Trợ giảng! (Gửi sau 15:30 — đã báo Admin và người giao.)" {
		t.Fatalf("giao việc trợ giảng: %v", r.body)
	}

	// Portal của trợ giảng
	tc := e.as(ta)
	p := tc.do("GET", "/tasks/ta-portal", nil)
	expect(t, p, 200, "")
	if p.body["can_pick_ta"] != false || p.body["can_complete"] != true || p.body["total"] != 2.0 || p.body["first_open"] != "before" {
		t.Fatalf("portal trợ giảng: %v", p.body)
	}
	groups := p.body["groups"].([]any)
	before := groups[0].(map[string]any)["tasks"].([]any)
	after := groups[2].(map[string]any)["tasks"].([]any)
	if len(before) != 1 || len(after) != 1 {
		t.Fatalf("3 ca: %v", groups)
	}
	card := before[0].(map[string]any)
	if card["due_label"] != "14:00, Hôm nay" || card["creator"] != manager.name {
		t.Fatalf("thẻ nhiệm vụ: %v", card)
	}
	beforeID, afterID := card["id"].(string), after[0].(map[string]any)["id"].(string)

	// Hoàn thành: không ảnh → chờ xác nhận; có ảnh → hoàn thành ngay
	expectMsg(t, tc.do("POST", "/tasks/"+beforeID+"/complete", map[string]any{"proof_image_url": "ftp://x"}), 422, "Đường dẫn ảnh minh chứng phải là URL http/https hợp lệ.")
	expectMsg(t, tc.do("POST", "/tasks/"+beforeID+"/complete", map[string]any{"note": "Xong"}), 200,
		"Đã gửi báo cáo tiến độ. Do không đính kèm ảnh, nhiệm vụ chuyển sang 'Chờ Admin xác nhận'!")
	expect(t, tc.do("POST", "/tasks/"+beforeID+"/complete", map[string]any{}), 422, "closed")
	expectMsg(t, tc.do("POST", "/tasks/"+afterID+"/complete", map[string]any{"proof_image_url": "https://anh.example/phong.jpg"}), 200,
		"Đã hoàn thành nhiệm vụ 'Dọn phòng' thành công!")
	expect(t, gv.do("POST", "/tasks/"+afterID+"/complete", map[string]any{}), 403, "forbidden")

	p = tc.do("GET", "/tasks/ta-portal", nil)
	before = p.body["groups"].([]any)[0].(map[string]any)["tasks"].([]any)
	after = p.body["groups"].([]any)[2].(map[string]any)["tasks"].([]any)
	if before[0].(map[string]any)["pending_label"] != "Đang chờ Admin xác nhận" || after[0].(map[string]any)["has_proof"] != true || p.body["done"] != 1.0 {
		t.Fatalf("portal sau khi báo xong: %v", p.body)
	}

	// Quản lý chọn trợ giảng trong phạm vi; ngoài phạm vi bị chặn
	mp := mc.do("GET", "/tasks/ta-portal", nil)
	if mp.body["can_pick_ta"] != true || mp.body["ta_user"].(map[string]any)["id"] != ta.id.String() {
		t.Fatalf("portal của quản lý: %v", mp.body)
	}
	expect(t, mc.do("GET", "/tasks/ta-portal?ta_id="+taB.id.String(), nil), 403, "outside_scope")
	// Ngày khác hôm nay
	if got := mc.do("GET", "/tasks/ta-portal?date=2026-01-02", nil); got.body["title"] != "Nhiệm vụ ngày 02/01/2026" || got.body["has_tasks"] != false {
		t.Fatalf("portal ngày khác: %v", got.body)
	}
}

func TestMarkOverdue(t *testing.T) {
	e := setup(t)
	a := e.branch("CN-A")
	admin := e.member("AD001", &a, iam.RoleAdmin)
	ac := e.as(admin)
	yesterday := time.Now().In(vnZone).AddDate(0, 0, -1).Format(time.DateOnly)
	late := newTask("Việc quá hạn", admin.id)
	late["due_date"] = yesterday
	lateID := ac.create(late)
	onTime := ac.create(newTask("Việc còn hạn", admin.id))
	daily := ac.create(late)
	if _, err := e.pg.Exec(context.Background(), "UPDATE ops.work_tasks SET kind = 'ta_daily' WHERE id = $1", daily); err != nil {
		t.Fatal(err)
	}
	blocked := ac.create(late)
	if _, err := e.pg.Exec(context.Background(), "UPDATE ops.work_tasks SET status = 'blocked' WHERE id = $1", blocked); err != nil {
		t.Fatal(err)
	}
	n, err := e.tasks.MarkOverdue(context.Background())
	if err != nil || n != 1 {
		t.Fatalf("quét quá hạn: %d %v", n, err)
	}
	if e.status(lateID) != StatusOverdue || e.status(onTime) != StatusNew || e.status(daily) != StatusNew || e.status(blocked) != StatusBlocked {
		t.Fatal("chỉ việc Mới / Đang làm có hạn thật mới thành Quá hạn")
	}
	if n, _ := e.tasks.MarkOverdue(context.Background()); n != 0 {
		t.Fatal("chạy lại không đổi gì")
	}
}
