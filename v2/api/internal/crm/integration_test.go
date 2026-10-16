package crm

import (
	"bytes"
	"context"
	"crypto/rand"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"io"
	"log/slog"
	"mime/multipart"
	"net/http"
	"net/http/httptest"
	"os"
	"strings"
	"testing"
	"time"

	"github.com/go-chi/chi/v5"
	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgxpool"
	"github.com/pquerna/otp/totp"
	"github.com/xuri/excelize/v2"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/secretbox"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/store"
)

// Test HTTP đầy đủ với Postgres và Redis thật (TEST_DATABASE_URL, TEST_REDIS_URL): đăng nhập thật (kể cả 2FA),
// quyền route, phạm vi own / branch / all, thông báo kiểm tra dữ liệu và các luồng chính của CRM.

const testPassword = "Trung-tam-tieng-anh-2026"

type env struct {
	t    *testing.T
	iam  *iam.Service
	svc  *Service
	srv  *httptest.Server
	pg   *pgxpool.Pool
	box  *secretbox.Box
	hash string
}

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
	if _, err := pool.Exec(ctx, `TRUNCATE crm.import_batches, crm.enrollment_confirmations, crm.promotions, crm.branch_transfers,
		crm.lead_events, crm.leads, iam.users, iam.roles, iam.role_permissions, core.branches CASCADE`); err != nil {
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
	// Lỗi nội bộ in ra log của test để dễ tìm nguyên nhân.
	log := slog.New(slog.NewTextHandler(testLog{t}, &slog.HandlerOptions{Level: slog.LevelError}))
	isvc := iam.NewService(pool, rdb, box, log)
	svc := NewService(pool, isvc, log)
	auth := iam.NewHandler(isvc, log)
	r := chi.NewRouter()
	r.Route("/api/v1", func(r chi.Router) {
		r.Use(iam.SameOrigin, auth.LoadSession)
		auth.Routes(r)
		NewHandler(svc, log).Routes(r)
	})
	srv := httptest.NewServer(r)
	t.Cleanup(srv.Close)
	hash, err := iam.HashPassword(ctx, testPassword)
	if err != nil {
		t.Fatal(err)
	}
	return &env{t: t, iam: isvc, svc: svc, srv: srv, pg: pool, box: box, hash: hash}
}

type testLog struct{ t *testing.T }

func (l testLog) Write(p []byte) (int, error) {
	l.t.Log(strings.TrimSpace(string(p)))
	return len(p), nil
}

// inbox: tiêu đề thông báo loại typ của một người (theo thứ tự gửi), nối bằng "|".
func (e *env) inbox(user uuid.UUID, typ string) string {
	e.t.Helper()
	var s string
	if err := e.pg.QueryRow(context.Background(), `SELECT coalesce(string_agg(title, '|' ORDER BY id), '') FROM core.notifications
		WHERE user_id = $1 AND type = $2`, user, typ).Scan(&s); err != nil {
		e.t.Fatal(err)
	}
	return s
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
		code, "Cơ sở "+code).Scan(&id); err != nil {
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

// member tạo tài khoản đã đổi mật khẩu, có vai trò, chi nhánh (chi nhánh đầu là chính) và 2FA nếu cần.
func (e *env) member(code string, withTOTP bool, branches []uuid.UUID, roles ...string) member {
	e.t.Helper()
	ctx := context.Background()
	name := "Người " + code
	u, _, err := e.iam.CreateUser(ctx, iam.NewUser{Kind: "staff", Code: code, FullName: name})
	if err != nil {
		e.t.Fatal(err)
	}
	e.exec("UPDATE iam.users SET password_hash = $2, must_change_password = false WHERE id = $1", u.ID, e.hash)
	if err := e.iam.SetRolesUnchecked(ctx, u.ID, roles); err != nil {
		e.t.Fatal(err)
	}
	for i, b := range branches {
		e.exec("INSERT INTO iam.user_branches (user_id, branch_id, is_primary) VALUES ($1, $2, $3)", u.ID, b, i == 0)
	}
	m := member{id: u.ID, code: code, name: name}
	if withTOTP {
		key, err := totp.Generate(totp.GenerateOpts{Issuer: "MEnglish", AccountName: code})
		if err != nil {
			e.t.Fatal(err)
		}
		m.secret = key.Secret()
		e.exec("UPDATE iam.users SET totp_secret = $2, totp_enabled_at = now() WHERE id = $1", u.ID, e.box.Seal([]byte(m.secret), u.ID[:]))
	}
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
	header http.Header
	raw    []byte
}

func (c *client) send(method, path, ctype string, body io.Reader) resp {
	c.e.t.Helper()
	req, _ := http.NewRequest(method, c.e.srv.URL+"/api/v1"+path, body)
	if ctype != "" {
		req.Header.Set("Content-Type", ctype)
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
	raw, _ := io.ReadAll(res.Body)
	out := resp{status: res.StatusCode, body: map[string]any{}, header: res.Header, raw: raw}
	_ = json.Unmarshal(raw, &out.body)
	if tok, ok := out.body["csrf_token"].(string); ok {
		c.csrf = tok
	}
	return out
}

func (c *client) do(method, path string, body any) resp {
	c.e.t.Helper()
	if body == nil {
		return c.send(method, path, "", nil)
	}
	b, _ := json.Marshal(body)
	return c.send(method, path, "application/json", bytes.NewReader(b))
}

// upload gửi multipart (file + các trường form).
func (c *client) upload(path, fileName string, data []byte, fields map[string]string) resp {
	c.e.t.Helper()
	var buf bytes.Buffer
	w := multipart.NewWriter(&buf)
	for k, v := range fields {
		_ = w.WriteField(k, v)
	}
	if fileName != "" {
		fw, _ := w.CreateFormFile("file", fileName)
		_, _ = fw.Write(data)
	}
	_ = w.Close()
	return c.send("POST", path, w.FormDataContentType(), &buf)
}

// as đăng nhập (kể cả bước 2FA). Mỗi người chỉ đăng nhập một lần trong một test để mã TOTP không bị coi là dùng lại.
func (e *env) as(m member) *client {
	e.t.Helper()
	c := &client{e: e}
	r := c.do("POST", "/auth/login", map[string]any{"identifier": m.code, "password": testPassword})
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
		t.Fatalf("muốn %d %s, nhận %d %s", status, code, r.status, string(r.raw))
	}
}

// invalid: lỗi 422 với thông báo chứa msg.
func invalid(t *testing.T, r resp, msg string) {
	t.Helper()
	expect(t, r, 422, "invalid")
	if m, _ := r.body["message"].(string); !strings.Contains(m, msg) {
		t.Fatalf("thông báo %q, muốn chứa %q", m, msg)
	}
}

func message(r resp) string { m, _ := r.body["message"].(string); return m }

func data(r resp) []any { d, _ := r.body["data"].([]any); return d }

func field(v any, path ...string) any {
	for _, p := range path {
		m, ok := v.(map[string]any)
		if !ok {
			return nil
		}
		v = m[p]
	}
	return v
}

func newLead(name, phone string, branch uuid.UUID) map[string]any {
	return map[string]any{"name": name, "phone": phone, "source": "Facebook", "branch_id": branch}
}

func (c *client) create(t *testing.T, body map[string]any) string {
	t.Helper()
	r := c.do("POST", "/crm/leads", body)
	expect(t, r, 201, "")
	return r.body["id"].(string)
}

// team: hai cơ sở với đủ vai trò dùng CRM.
type team struct {
	a, b                               uuid.UUID
	admin, manager, staffA, staffA2    member
	staffB, salesA, salesA2, teacher   member
	adminC, managerC, staffAC, staffBC *client
}

func newTeam(e *env) *team {
	tm := &team{a: e.branch("CN-A"), b: e.branch("CN-B")}
	tm.admin = e.member("MES001", true, nil, iam.RoleAdmin)
	tm.manager = e.member("QL-A", true, []uuid.UUID{tm.a}, iam.RoleManager)
	tm.staffA = e.member("HV-A", true, []uuid.UUID{tm.a}, iam.RoleAcademicStaff)
	tm.staffA2 = e.member("HV-A2", true, []uuid.UUID{tm.a}, iam.RoleAcademicStaff)
	tm.staffB = e.member("HV-B", true, []uuid.UUID{tm.b}, iam.RoleAcademicStaff)
	tm.salesA = e.member("TV-A", false, []uuid.UUID{tm.a}, "sales_consultant")
	tm.salesA2 = e.member("TV-A2", false, []uuid.UUID{tm.a}, "sales_consultant")
	tm.teacher = e.member("GV-A", false, []uuid.UUID{tm.a}, iam.RoleTeacherFulltime)
	tm.adminC, tm.managerC, tm.staffAC, tm.staffBC = e.as(tm.admin), e.as(tm.manager), e.as(tm.staffA), e.as(tm.staffB)
	return tm
}

func ids(r resp) []string {
	var out []string
	for _, row := range data(r) {
		out = append(out, row.(map[string]any)["id"].(string))
	}
	return out
}

func TestLeadScopeAndPermissions(t *testing.T) {
	e := setup(t)
	tm := newTeam(e)
	sales, sales2 := e.as(tm.salesA), e.as(tm.salesA2)

	expect(t, e.as(tm.teacher).do("GET", "/crm/leads", nil), 403, "forbidden")
	expect(t, (&client{e: e}).do("GET", "/crm/leads", nil), 401, "unauthenticated")

	// Kiểm tra dữ liệu (thông báo như Laravel)
	invalid(t, tm.staffAC.do("POST", "/crm/leads", map[string]any{"phone": "0912345678", "source": "Facebook", "branch_id": tm.a}),
		"Vui lòng nhập họ và tên khách hàng.")
	invalid(t, tm.staffAC.do("POST", "/crm/leads", newLead("An", "12345", tm.a)), "Số điện thoại không hợp lệ.")
	invalid(t, tm.staffAC.do("POST", "/crm/leads", map[string]any{"name": "An", "phone": "0912345678", "branch_id": tm.a}),
		"Vui lòng chọn nguồn khách.")
	invalid(t, tm.staffAC.do("POST", "/crm/leads", newLead("An", "0912345678", tm.b)), "Vui lòng chọn cơ sở trong phạm vi của bạn.")
	bad := newLead("An", "0912345678", tm.a)
	bad["email"] = "khong-phai-email"
	invalid(t, tm.staffAC.do("POST", "/crm/leads", bad), "Địa chỉ email không đúng định dạng.")

	r := tm.staffAC.do("POST", "/crm/leads", newLead("Nguyễn Văn An", "0912 345 678", tm.a))
	expect(t, r, 201, "")
	if !strings.Contains(message(r), "Đã thêm khách hàng Nguyễn Văn An (KH-") {
		t.Fatalf("thông báo thêm khách: %s", message(r))
	}
	leadA := r.body["id"].(string)
	var desc string
	if err := e.pg.QueryRow(context.Background(), `SELECT description FROM core.audit_log
		WHERE action = 'lead.create' AND subject_id = $1 AND module = 'CRM & Leads'`, leadA).Scan(&desc); err != nil || desc != `Tạo mới khách hàng "Nguyễn Văn An"` {
		t.Fatalf("nhật ký thêm khách: %q %v", desc, err)
	}
	invalid(t, tm.staffAC.do("POST", "/crm/leads", newLead("Trùng", "+84912345678", tm.a)), "Số điện thoại này đã tồn tại trong CRM (khách KH-")

	// Tư vấn viên (phạm vi own): khách tự giao cho chính mình; không gán được người khác
	own := newLead("Khách tư vấn", "0987654321", tm.a)
	own["assigned_user_id"] = tm.staffA.id
	leadSales := sales.create(t, own)
	d := sales.do("GET", "/crm/leads/"+leadSales, nil)
	expect(t, d, 200, "")
	if d.body["assigned_user_id"] != tm.salesA.id.String() {
		t.Fatalf("khách của tư vấn viên phải do chính họ phụ trách: %v", d.body["assigned_user_id"])
	}
	leadB := tm.staffBC.create(t, newLead("Khách cơ sở B", "0901234567", tm.b))

	// Phạm vi xem
	if got := ids(sales.do("GET", "/crm/leads", nil)); len(got) != 1 || got[0] != leadSales {
		t.Fatalf("tư vấn viên chỉ thấy khách mình: %v", got)
	}
	if got := ids(sales2.do("GET", "/crm/leads", nil)); len(got) != 0 {
		t.Fatalf("tư vấn viên khác không thấy: %v", got)
	}
	expect(t, sales2.do("GET", "/crm/leads/"+leadSales, nil), 404, "lead_not_found")
	if got := ids(tm.managerC.do("GET", "/crm/leads", nil)); len(got) != 2 {
		t.Fatalf("quản lý cơ sở A thấy 2 khách cơ sở A: %v", got)
	}
	expect(t, tm.staffBC.do("GET", "/crm/leads/"+leadA, nil), 404, "lead_not_found")
	expect(t, tm.staffBC.do("PUT", "/crm/leads/"+leadA, newLead("Sửa", "0912345678", tm.a)), 404, "lead_not_found")
	expect(t, tm.staffBC.do("POST", "/crm/leads/"+leadA+"/notes", map[string]any{"type": "note", "content": "x"}), 404, "lead_not_found")
	if got := ids(tm.adminC.do("GET", "/crm/leads", nil)); len(got) != 3 {
		t.Fatalf("Admin thấy toàn hệ thống: %v", got)
	}
	// Lọc cơ sở ngoài phạm vi bị bỏ qua (không lộ dữ liệu)
	if got := ids(tm.staffAC.do("GET", "/crm/leads?branch_id="+tm.b.String(), nil)); len(got) != 2 {
		t.Fatalf("lọc cơ sở ngoài phạm vi: %v", got)
	}
	if got := ids(tm.adminC.do("GET", "/crm/leads?branch_id="+tm.b.String(), nil)); len(got) != 1 || got[0] != leadB {
		t.Fatalf("Admin lọc cơ sở B: %v", got)
	}
	// Tìm theo SĐT (bỏ ký tự phân cách), theo tên
	if got := ids(tm.adminC.do("GET", "/crm/leads?search=0912.345", nil)); len(got) != 1 || got[0] != leadA {
		t.Fatalf("tìm theo SĐT: %v", got)
	}
	if got := ids(tm.adminC.do("GET", "/crm/leads?search=văn+an", nil)); len(got) != 1 {
		t.Fatalf("tìm theo tên: %v", got)
	}
	// Mở bằng mã khách
	code := d.body["code"].(string)
	expect(t, sales.do("GET", "/crm/leads/"+code, nil), 200, "")

	// Chip "Chưa liên hệ >24h": khách tạo quá 24 giờ chưa chăm sóc
	e.exec("UPDATE crm.leads SET created_at = now() - interval '25 hours' WHERE id = $1", leadA)
	list := tm.managerC.do("GET", "/crm/leads?sla=1", nil)
	if got := ids(list); len(got) != 1 || got[0] != leadA || field(list.body, "counts", "sla") != float64(1) {
		t.Fatalf("chip chưa liên hệ: %v %v", got, list.body["counts"])
	}

	// Quyền thao tác
	expect(t, sales.do("POST", "/crm/promotions", map[string]any{"name": "x"}), 403, "forbidden")
	expect(t, sales.do("GET", "/crm/reports", nil), 403, "forbidden")
	expect(t, sales.do("GET", "/crm/deleted", nil), 403, "forbidden")
	expect(t, sales.do("POST", "/crm/leads/"+leadSales+"/reassign", map[string]any{}), 403, "forbidden")
	expect(t, sales.do("GET", "/crm/transfers", nil), 403, "forbidden")
	expect(t, tm.managerC.do("GET", "/crm/transfers", nil), 403, "forbidden")
	expect(t, sales.do("GET", "/crm/confirmations", nil), 403, "forbidden")
	expect(t, e.as(tm.teacher).do("POST", "/crm/leads", newLead("x", "0911111111", tm.a)), 403, "forbidden")

	// Tùy chọn form theo phạm vi
	o := sales.do("GET", "/crm/options", nil)
	expect(t, o, 200, "")
	if field(o.body, "scope") != iam.ScopeOwn || len(field(o.body, "form_branches").([]any)) != 1 {
		t.Fatalf("tùy chọn của tư vấn viên: scope=%v branches=%v", field(o.body, "scope"), field(o.body, "form_branches"))
	}
}

// Chưa có khách nào: danh sách nguồn để lọc là [] (null làm màn Kanban / Danh sách lỗi).
func TestOptionsWithoutLeads(t *testing.T) {
	e := setup(t)
	tm := newTeam(e)
	o := e.as(tm.salesA).do("GET", "/crm/options", nil)
	expect(t, o, 200, "")
	if v, ok := field(o.body, "filter_sources").([]any); !ok || len(v) != 0 {
		t.Fatalf("filter_sources khi chưa có khách: %#v", field(o.body, "filter_sources"))
	}
}

func TestLeadLifecycle(t *testing.T) {
	e := setup(t)
	tm := newTeam(e)
	sales := e.as(tm.salesA)
	c := tm.staffAC
	id := c.create(t, newLead("Trần Bình", "0912000111", tm.a))

	d := c.do("GET", "/crm/leads/"+id, nil)
	if d.body["stage"] != StageNew || len(d.body["events"].([]any)) != 1 || field(d.body, "status_card", "sla", "kind") != "first" {
		t.Fatalf("hồ sơ khách mới: %v %v", d.body["stage"], d.body["events"])
	}

	// Sửa thông tin: lịch sử ghi trước / sau
	upd := newLead("Trần Văn Bình", "0912000111", tm.a)
	upd["notes"] = "Quan tâm IELTS"
	r := c.do("PUT", "/crm/leads/"+id, upd)
	expect(t, r, 200, "")
	d = c.do("GET", "/crm/leads/"+id, nil)
	ev := d.body["events"].([]any)[0].(map[string]any)
	if ev["type"] != "update" || field(ev, "changes", "name", "new") != "Trần Văn Bình" || field(ev, "changes", "name", "old") != "Trần Bình" {
		t.Fatalf("lịch sử sửa: %v", ev)
	}

	// Nhật ký: kiểm tra dữ liệu, liên hệ thất bại không đổi giai đoạn, liên hệ được thì sang Đang tư vấn
	invalid(t, c.do("POST", "/crm/leads/"+id+"/notes", map[string]any{"type": "call"}), "Vui lòng nhập nội dung ghi chú.")
	invalid(t, c.do("POST", "/crm/leads/"+id+"/notes", map[string]any{"type": "fax", "content": "x"}), "Loại nhật ký không hợp lệ.")
	invalid(t, c.do("POST", "/crm/leads/"+id+"/notes", map[string]any{"type": "call", "content": "x", "next_follow_up_at": "2020-01-01T10:00"}),
		"Hẹn liên hệ tiếp phải sau thời điểm hiện tại.")
	r = c.do("POST", "/crm/leads/"+id+"/notes", map[string]any{"type": "call", "content": "Không nghe máy", "outcome": "failed"})
	if message(r) != "Đã lưu nhật ký chăm sóc thành công!" {
		t.Fatalf("liên hệ thất bại: %s", r.raw)
	}
	r = c.do("POST", "/crm/leads/"+id+"/notes", map[string]any{"type": "call", "content": "Đã tư vấn lộ trình"})
	if message(r) != "Đã lưu nhật ký. Khách chuyển sang Đang tư vấn." {
		t.Fatalf("liên hệ được lần đầu: %s", r.raw)
	}
	d = c.do("GET", "/crm/leads/"+id, nil)
	if d.body["stage"] != StageConsulting || field(d.body, "status_card", "sla", "kind") != "follow_up" {
		t.Fatalf("sau khi liên hệ được: %v %v", d.body["stage"], field(d.body, "status_card", "sla"))
	}
	if field(d.body, "stage_controls", "next_blocked") == nil {
		t.Fatalf("Hẹn test cần lịch hẹn: %v", d.body["stage_controls"])
	}

	// Chuyển giai đoạn
	stage := func(cl *client, body map[string]any) resp { return cl.do("POST", "/crm/leads/"+id+"/stage", body) }
	expect(t, sales.do("POST", "/crm/leads/"+id+"/stage", map[string]any{"stage": StageTestScheduled}), 404, "lead_not_found")
	invalid(t, c.do("POST", "/crm/leads/"+id+"/next-stage", map[string]any{}), "Chưa có lịch hẹn test.")
	appt := time.Now().Add(48 * time.Hour)
	if err := e.svc.SetLeadTest(context.Background(), nil, uuid.MustParse(id), TestUpdate{AppointmentAt: &appt}); err != nil {
		t.Fatal(err)
	}
	r = c.do("POST", "/crm/leads/"+id+"/next-stage", map[string]any{})
	expect(t, r, 200, "")
	if r.body["stage"] != StageTestScheduled || message(r) != "Đã chuyển khách hàng Trần Văn Bình sang giai đoạn Hẹn test!" {
		t.Fatalf("tiếp theo: %s", r.raw)
	}
	invalid(t, stage(c, map[string]any{"stage": StageTested}), "Chỉ được chuyển tiến 1 bước: Hẹn test → Test.")
	invalid(t, stage(c, map[string]any{"stage": StageWon}), "Hãy dùng Chốt & Xếp lớp")
	invalid(t, stage(c, map[string]any{"stage": "bay"}), "Giai đoạn không hợp lệ.")
	expect(t, stage(c, map[string]any{"stage": StageConsulting, "reason": "Nhầm"}), 403, "forbidden")
	invalid(t, stage(tm.adminC, map[string]any{"stage": StageConsulting}), "Lùi giai đoạn bắt buộc nhập lý do.")
	expect(t, stage(tm.adminC, map[string]any{"stage": StageConsulting, "reason": "Khách dời lịch test"}), 200, "")

	// Kanban
	p := c.do("GET", "/crm/pipeline", nil)
	expect(t, p, 200, "")
	cols := p.body["stages"].([]any)
	if len(cols) != len(PipelineStages) {
		t.Fatalf("Kanban có %d cột", len(cols))
	}
	consulting := cols[1].(map[string]any)
	if consulting["id"] != StageConsulting || consulting["count"] != float64(1) || len(consulting["leads"].([]any)) != 1 {
		t.Fatalf("cột Đang tư vấn: %v", consulting)
	}

	// Thất bại → danh sách thất bại → mở lại
	invalid(t, stage(c, map[string]any{"stage": StageLost}), "Vui lòng nhập lý do thất bại.")
	expect(t, stage(c, map[string]any{"stage": StageLost, "lost_reason": "Học phí cao"}), 200, "")
	lost := c.do("GET", "/crm/lost", nil)
	if got := ids(lost); len(got) != 1 || data(lost)[0].(map[string]any)["lost_reason"] != "Học phí cao" {
		t.Fatalf("danh sách thất bại: %s", lost.raw)
	}
	if got := ids(c.do("GET", "/crm/lost?search=hoc+phi", nil)); len(got) != 0 {
		// Tìm theo lý do cần đúng dấu như Laravel LIKE
		t.Fatalf("tìm lý do không dấu: %v", got)
	}
	if got := ids(c.do("GET", "/crm/lost?search=Học+phí", nil)); len(got) != 1 {
		t.Fatalf("tìm theo lý do thất bại: %v", got)
	}
	invalid(t, stage(c, map[string]any{"stage": StageNew}), "Khách Thất bại chỉ mở lại được về bước Đang tư vấn.")
	invalid(t, stage(c, map[string]any{"stage": StageConsulting}), "Vui lòng nhập lý do mở lại khách.")
	expect(t, stage(c, map[string]any{"stage": StageConsulting, "reason": "Khách quay lại"}), 200, "")
	d = c.do("GET", "/crm/leads/"+id, nil)
	if d.body["stage"] != StageConsulting || d.body["lost_reason"] != nil {
		t.Fatalf("mở lại: %v %v", d.body["stage"], d.body["lost_reason"])
	}
	changes := 0
	for _, ev := range d.body["events"].([]any) {
		if ev.(map[string]any)["type"] == "stage_change" {
			changes++
		}
	}
	if changes != 5 {
		t.Fatalf("mỗi lần đổi giai đoạn ghi một dòng lịch sử, có %d", changes)
	}

	// Chốt khi chưa gộp phân hệ học viên
	expect(t, c.do("POST", "/crm/leads/"+id+"/close", map[string]any{"course_id": uuid.New()}), 501, "students_not_ready")
	invalid(t, c.do("POST", "/crm/leads/"+id+"/close", map[string]any{}), "Chọn khóa học khi xếp lớp sau để tính học phí.")

	// Xuất Excel / CSV
	x := c.send("GET", "/crm/leads/export", "", nil)
	if x.status != 200 || !strings.Contains(x.header.Get("Content-Disposition"), "danh-sach-khach-") {
		t.Fatalf("xuất Excel: %d %v", x.status, x.header)
	}
	f, err := excelize.OpenReader(bytes.NewReader(x.raw))
	if err != nil {
		t.Fatal(err)
	}
	rows, _ := f.GetRows(f.GetSheetName(0))
	if len(rows) != 2 || rows[0][0] != "Mã KH" || rows[1][1] != "Trần Văn Bình" {
		t.Fatalf("nội dung file Excel: %v", rows)
	}
	csv := c.send("GET", "/crm/lost?export=csv", "", nil)
	if csv.status != 200 || !bytes.HasPrefix(csv.raw, []byte("\xEF\xBB\xBFMã KH")) {
		t.Fatalf("xuất CSV: %d %q", csv.status, csv.raw)
	}
}

// fakeStudents: phân hệ học viên giả cho luồng Chốt & Xếp lớp.
type fakeStudents struct{ n int }

func (f *fakeStudents) CreateFromLead(ctx context.Context, tx pgx.Tx, _ *iam.Access, l LeadSnapshot, in CloseInput) (*ClosedStudent, error) {
	f.n++
	st := &ClosedStudent{StudentID: uuid.New(), StudentCode: fmt.Sprintf("HS%03d", f.n), StudentName: l.Name, ContractTotal: 6_000_000,
		CourseName: "IELTS Foundation", BranchID: l.BranchID}
	// Hồ sơ học viên thật (crm.leads.converted_student_id / enrollment_confirmations.student_id có khoá ngoại).
	if _, err := tx.Exec(ctx, `INSERT INTO academic.students (id, code, full_name, phone, branch_id, lead_id) VALUES ($1, $2, $3, $4, $5, $6)`,
		st.StudentID, st.StudentCode, l.Name, l.Phone, l.BranchID, l.ID); err != nil {
		return nil, err
	}
	if in.ClassID != nil {
		st.ClassName = ptr("IELTS-01")
		st.Enrollment = &Enrollment{StudentID: st.StudentID, StudentCode: st.StudentCode, StudentName: l.Name, ClassID: *in.ClassID,
			ClassName: "IELTS-01", ClassBranchID: l.BranchID}
	} else {
		st.CourseID = in.CourseID
	}
	return st, nil
}

func (f *fakeStudents) EnrollWaiting(_ context.Context, _ pgx.Tx, _ *iam.Access, l LeadSnapshot, studentID, classID uuid.UUID) (*Enrollment, error) {
	return &Enrollment{StudentID: studentID, StudentCode: "HS-W", StudentName: l.Name, ClassID: classID, ClassName: "IELTS-02",
		ClassBranchID: l.BranchID}, nil
}

func TestCloseConfirmAndCancel(t *testing.T) {
	e := setup(t)
	e.svc.WithStudents(&fakeStudents{})
	tm := newTeam(e)
	c := tm.staffAC
	consulting := func(name, phone string) string {
		id := c.create(t, newLead(name, phone, tm.a))
		expect(t, c.do("POST", "/crm/leads/"+id+"/notes", map[string]any{"type": "call", "content": "Đã gọi"}), 200, "")
		return id
	}

	// Chốt có lớp → Đã chốt + chờ xác nhận chính thức
	won := consulting("Lê Chốt", "0912000222")
	invalid(t, c.do("POST", "/crm/leads/"+won+"/close", map[string]any{"class_id": uuid.New(), "fee_paid_at_closing": true}),
		`Đã tích "Đã đóng học phí đăng ký" thì phải nhập số tiền đã thu.`)
	r := c.do("POST", "/crm/leads/"+won+"/close", map[string]any{"class_id": uuid.New(), "fee_paid_at_closing": true, "paid_amount": 3000000,
		"payment_method": "cash"})
	expect(t, r, 200, "")
	if message(r) != "Đã chốt deal và tạo hồ sơ HS001." {
		t.Fatalf("chốt: %s", r.raw)
	}
	r = c.do("POST", "/crm/leads/"+won+"/close", map[string]any{"class_id": uuid.New()})
	if !strings.Contains(message(r), "đã được chốt trước đó") {
		t.Fatalf("chốt lại không tạo trùng: %s", r.raw)
	}
	d := c.do("GET", "/crm/leads/"+won, nil)
	if d.body["stage"] != StageWon || d.body["deal_value"] != float64(6_000_000) || d.body["contract_locked"] != true {
		t.Fatalf("sau chốt: %v %v %v", d.body["stage"], d.body["deal_value"], d.body["contract_locked"])
	}
	// Trường hợp đồng bị khóa khi đã chốt
	upd := newLead("Lê Chốt", "0912000222", tm.a)
	upd["deal_value"] = 1
	invalid(t, c.do("PUT", "/crm/leads/"+won, upd), "Giá trị hợp đồng")
	expect(t, c.do("POST", "/crm/leads/"+won+"/stage", map[string]any{"stage": StageLost, "lost_reason": "x"}), 422, "invalid")

	// Chăm sóc tháng đầu
	r = c.do("POST", "/crm/leads/"+won+"/care-checklist", map[string]any{"items": []string{"session_1"}})
	expect(t, r, 200, "")
	d = c.do("GET", "/crm/leads/"+won, nil)
	if field(d.body["care_checklist"].([]any)[0], "done") != true {
		t.Fatalf("mốc chăm sóc: %v", d.body["care_checklist"])
	}

	list := c.do("GET", "/crm/confirmations", nil)
	expect(t, list, 200, "")
	if list.body["pending_count"] != float64(1) {
		t.Fatalf("chờ xác nhận: %s", list.raw)
	}
	cid := data(list)[0].(map[string]any)["id"].(string)
	expect(t, tm.staffBC.do("POST", "/crm/confirmations/"+cid, map[string]any{"action": "save"}), 404, "")
	invalid(t, c.do("POST", "/crm/confirmations/"+cid, map[string]any{"action": "confirm", "account_sent": true}),
		"Chưa đủ hồ sơ để xác nhận chính thức: Đã vào nhóm Zalo, Đã nhận giáo trình.")
	expect(t, c.do("POST", "/crm/confirmations/"+cid, map[string]any{"action": "save", "account_sent": true}), 200, "")
	r = c.do("POST", "/crm/confirmations/"+cid, map[string]any{"action": "confirm", "account_sent": true, "zalo_group_added": true,
		"curriculum_delivered": true})
	if message(r) != "Đã xác nhận chính thức học viên Lê Chốt." {
		t.Fatalf("xác nhận: %s", r.raw)
	}
	invalid(t, c.do("POST", "/crm/confirmations/"+cid, map[string]any{"action": "save"}), "Học viên này đã được xác nhận chính thức.")
	wonList := c.do("GET", "/crm/won", nil)
	if got := data(wonList); len(got) != 1 || got[0].(map[string]any)["enrollment_confirmed"] != true {
		t.Fatalf("khách chốt thành công: %s", wonList.raw)
	}

	// Chốt chưa có lớp → Chờ xếp lớp → xếp lớp
	wait := consulting("Phạm Chờ", "0912000333")
	r = c.do("POST", "/crm/leads/"+wait+"/close", map[string]any{"course_id": uuid.New()})
	if !strings.Contains(message(r), "Học viên đang ở danh sách Chờ xếp lớp.") {
		t.Fatalf("chốt chờ xếp lớp: %s", r.raw)
	}
	w := c.do("GET", "/crm/waiting", nil)
	if got := data(w); len(got) != 1 {
		t.Fatalf("danh sách chờ xếp lớp: %s", w.raw)
	}
	invalid(t, c.do("POST", "/crm/leads/"+wait+"/stage", map[string]any{"stage": StageWon}), "Hãy gán lớp cho học viên để chuyển sang Đã chốt.")
	r = c.do("POST", "/crm/leads/"+wait+"/assign-class", map[string]any{"class_id": uuid.New()})
	expect(t, r, 200, "")
	if d = c.do("GET", "/crm/leads/"+wait, nil); d.body["stage"] != StageWon {
		t.Fatalf("sau xếp lớp: %v", d.body["stage"])
	}

	// Huỷ chốt: chỉ Admin, khách Chờ xếp lớp
	cancel := consulting("Võ Huỷ", "0912000444")
	expect(t, c.do("POST", "/crm/leads/"+cancel+"/close", map[string]any{"course_id": uuid.New()}), 200, "")
	expect(t, c.do("POST", "/crm/leads/"+cancel+"/cancel-closing", map[string]any{"reason": "Gia đình chuyển nhà"}), 403, "forbidden")
	invalid(t, tm.adminC.do("POST", "/crm/leads/"+cancel+"/cancel-closing", map[string]any{"reason": "abc"}),
		"Lý do huỷ chốt phải có ít nhất 5 ký tự.")
	r = tm.adminC.do("POST", "/crm/leads/"+cancel+"/cancel-closing", map[string]any{"reason": "Gia đình chuyển nhà"})
	if message(r) != "Đã huỷ chốt Võ Huỷ: khách chuyển Thất bại, học viên chuyển Thôi học." {
		t.Fatalf("huỷ chốt: %s", r.raw)
	}
	if d = c.do("GET", "/crm/leads/"+cancel, nil); d.body["stage"] != StageLost || d.body["lost_reason"] != "Huỷ chốt: Gia đình chuyển nhà" {
		t.Fatalf("sau huỷ chốt: %v %v", d.body["stage"], d.body["lost_reason"])
	}

	// Báo cáo (chỉ Admin có report.view theo danh mục quyền)
	rep := tm.adminC.do("GET", "/crm/reports?preset=today", nil)
	expect(t, rep, 200, "")
	if rep.body["metric_total_leads"] != float64(3) || rep.body["metric_won_deals"] != float64(2) || rep.body["metric_lost_deals"] != float64(1) {
		t.Fatalf("báo cáo: tổng %v chốt %v thất bại %v", rep.body["metric_total_leads"], rep.body["metric_won_deals"], rep.body["metric_lost_deals"])
	}
	expect(t, tm.managerC.do("GET", "/crm/reports", nil), 403, "forbidden")
	if x := tm.adminC.send("GET", "/crm/reports/export", "", nil); x.status != 200 || !strings.Contains(x.header.Get("Content-Disposition"), "bao-cao-doanh-so-") {
		t.Fatalf("xuất báo cáo: %d %v", x.status, x.header)
	}
}

func TestReassignAndTransfers(t *testing.T) {
	e := setup(t)
	tm := newTeam(e)
	c := tm.staffAC
	id := c.create(t, newLead("Đỗ Chuyển", "0912000555", tm.a))
	path := "/crm/leads/" + id + "/reassign"

	invalid(t, c.do("POST", path, map[string]any{"assigned_user_id": tm.staffA2.id}), "Vui lòng nhập lý do phân công lại.")
	invalid(t, c.do("POST", path, map[string]any{"assigned_user_id": tm.staffA.id, "reason": "x"}), "Khách đang do người này phụ trách.")
	invalid(t, c.do("POST", path, map[string]any{"assigned_user_id": tm.salesA.id, "reason": "x"}), "")
	r := c.do("POST", path, map[string]any{"assigned_user_id": tm.staffA2.id, "reason": "Chia việc"})
	if message(r) != "Đã phân công lại khách cho "+tm.staffA2.name+"." {
		t.Fatalf("phân công cùng cơ sở: %s", r.raw)
	}
	if got := e.inbox(tm.staffA2.id, "lead_assigned"); got != "Bạn được giao phụ trách khách Đỗ Chuyển" {
		t.Fatalf("thông báo người phụ trách mới: %q", got)
	}

	// Khác cơ sở → yêu cầu chờ Admin duyệt
	r = c.do("POST", path, map[string]any{"assigned_user_id": tm.staffB.id, "reason": "Khách chuyển nhà"})
	if !strings.Contains(message(r), "Chờ Admin duyệt chuyển cơ sở.") {
		t.Fatalf("yêu cầu chuyển cơ sở: %s", r.raw)
	}
	invalid(t, c.do("POST", path, map[string]any{"assigned_user_id": tm.staffB.id, "reason": "lần nữa"}), "Khách đang có yêu cầu chuyển cơ sở chờ Admin duyệt.")
	if got := e.inbox(tm.admin.id, "crm_transfer_pending"); !strings.HasPrefix(got, "Chuyển cơ sở chờ duyệt: Đỗ Chuyển (KH-") {
		t.Fatalf("thông báo Admin duyệt chuyển cơ sở: %q", got)
	}
	d := c.do("GET", "/crm/leads/"+id, nil)
	if d.body["pending_transfer"] == nil {
		t.Fatal("hồ sơ phải hiện yêu cầu chuyển đang chờ")
	}
	tr := tm.adminC.do("GET", "/crm/transfers", nil)
	if len(data(tr)) != 1 {
		t.Fatalf("danh sách chờ duyệt: %s", tr.raw)
	}
	tid := data(tr)[0].(map[string]any)["id"].(string)
	invalid(t, tm.adminC.do("POST", "/crm/transfers/"+tid+"/reject", map[string]any{}), "Vui lòng nhập lý do từ chối.")
	expect(t, tm.adminC.do("POST", "/crm/transfers/"+tid+"/reject", map[string]any{"reason": "Chưa đủ thông tin"}), 200, "")
	invalid(t, tm.adminC.do("POST", "/crm/transfers/"+tid+"/approve", map[string]any{}), "")

	expect(t, c.do("POST", path, map[string]any{"assigned_user_id": tm.staffB.id, "reason": "Khách chuyển nhà"}), 200, "")
	tid = data(tm.adminC.do("GET", "/crm/transfers", nil))[0].(map[string]any)["id"].(string)
	expect(t, tm.adminC.do("POST", "/crm/transfers/"+tid+"/approve", map[string]any{"note": "Đồng ý"}), 200, "")
	if got := e.inbox(tm.staffB.id, "lead_assigned"); got != "Bạn được giao phụ trách khách Đỗ Chuyển" {
		t.Fatalf("thông báo người phụ trách sau duyệt: %q", got)
	}
	expect(t, c.do("GET", "/crm/leads/"+id, nil), 404, "lead_not_found")
	d = tm.staffBC.do("GET", "/crm/leads/"+id, nil)
	if d.body["branch_id"] != tm.b.String() || d.body["assigned_user_id"] != tm.staffB.id.String() {
		t.Fatalf("sau duyệt: %v %v", d.body["branch_id"], d.body["assigned_user_id"])
	}
	if hist := tm.adminC.do("GET", "/crm/transfers?status=approved", nil); len(data(hist)) != 1 {
		t.Fatalf("lịch sử đã duyệt: %s", hist.raw)
	}

	// Admin đổi cơ sở thì áp dụng ngay
	r = tm.adminC.do("POST", path, map[string]any{"assigned_user_id": tm.staffA.id, "reason": "Về lại cơ sở A"})
	expect(t, r, 200, "")
	if d = c.do("GET", "/crm/leads/"+id, nil); d.status != 200 {
		t.Fatalf("Admin chuyển ngay: %s", d.raw)
	}

	// Phân công hàng loạt: chỉ khách trong phạm vi
	id2 := c.create(t, newLead("Hàng loạt 1", "0912000666", tm.a))
	idB := tm.staffBC.create(t, newLead("Cơ sở B", "0912000777", tm.b))
	r = tm.managerC.do("POST", "/crm/leads/bulk-reassign", map[string]any{"ids": []string{id, id2, idB}, "assigned_user_id": tm.staffA2.id,
		"reason": "Học vụ nghỉ phép"})
	if message(r) != "Đã phân công 2 khách cho "+tm.staffA2.name+"." {
		t.Fatalf("phân công hàng loạt: %s", r.raw)
	}
	invalid(t, tm.managerC.do("POST", "/crm/leads/bulk-reassign", map[string]any{"ids": []string{}, "assigned_user_id": tm.staffA2.id, "reason": "x"}),
		"Chọn ít nhất một khách.")
}

func TestDeletedAndRestore(t *testing.T) {
	e := setup(t)
	tm := newTeam(e)
	id := tm.staffAC.create(t, newLead("Khách cũ", "0912000888", tm.a))
	e.exec("UPDATE crm.leads SET deleted_at = now(), deleted_email = email, email = NULL, phone_normalized = NULL WHERE id = $1", id)

	expect(t, tm.staffAC.do("GET", "/crm/deleted", nil), 403, "forbidden")
	del := tm.managerC.do("GET", "/crm/deleted", nil)
	if len(data(del)) != 1 || field(tm.managerC.do("GET", "/crm/counts", nil).body, "deleted") != float64(1) {
		t.Fatalf("khách đã xóa: %s", del.raw)
	}
	expect(t, tm.staffAC.do("GET", "/crm/leads/"+id, nil), 404, "lead_not_found")

	// SĐT đã thuộc khách khác thì không khôi phục được
	other := tm.staffAC.create(t, newLead("Khách mới", "0912000888", tm.a))
	invalid(t, tm.managerC.do("POST", "/crm/leads/"+id+"/restore", nil), "Không thể khôi phục Khách cũ: SĐT đang thuộc khách KH-")
	e.exec("UPDATE crm.leads SET deleted_at = now(), phone_normalized = NULL WHERE id = $1", other)
	r := tm.managerC.do("POST", "/crm/leads/"+id+"/restore", nil)
	if message(r) != "Đã khôi phục khách hàng Khách cũ." {
		t.Fatalf("khôi phục: %s", r.raw)
	}
	expect(t, tm.staffAC.do("GET", "/crm/leads/"+id, nil), 200, "")
	expect(t, tm.staffBC.do("GET", "/crm/deleted", nil), 403, "forbidden")
}

func TestPromotions(t *testing.T) {
	e := setup(t)
	tm := newTeam(e)
	c := tm.managerC
	invalid(t, c.do("POST", "/crm/promotions", map[string]any{"name": "", "type": "percent", "value": 10}), "Vui lòng nhập tên ưu đãi.")
	invalid(t, c.do("POST", "/crm/promotions", map[string]any{"name": "Hè", "type": "percent", "value": 120}), "Ưu đãi phần trăm không được vượt quá 100%.")
	invalid(t, c.do("POST", "/crm/promotions", map[string]any{"name": "Hè", "type": "fixed", "value": 0}), "Giá trị ưu đãi phải lớn hơn 0.")
	invalid(t, c.do("POST", "/crm/promotions", map[string]any{"name": "Hè", "type": "fixed", "value": 100000, "starts_at": "2026-10-10",
		"ends_at": "2026-10-01"}), "Ngày kết thúc ưu đãi phải sau ngày bắt đầu.")
	invalid(t, c.do("POST", "/crm/promotions", map[string]any{"name": "Ca riêng", "type": "fixed", "value": 100000, "is_special": true}),
		"Ưu đãi riêng cho ca đặc biệt bắt buộc ghi lý do.")

	r := c.do("POST", "/crm/promotions", map[string]any{"name": "Ưu đãi hè", "type": "percent", "value": 10, "max_discount_amount": 500000})
	expect(t, r, 201, "")
	pid := r.body["id"].(string)
	p := c.do("GET", "/crm/promotions/"+pid, nil)
	if code, _ := p.body["code"].(string); !strings.HasPrefix(code, "UDUUDA") || len(code) != 9 {
		t.Fatalf("mã ưu đãi: %s", p.raw)
	}
	list := c.do("GET", "/crm/promotions", nil)
	if len(data(list)) != 1 {
		t.Fatalf("danh sách ưu đãi: %s", list.raw)
	}
	r = c.do("PUT", "/crm/promotions/"+pid, map[string]any{"name": "Ưu đãi hè 2026", "type": "fixed", "value": 300000})
	if message(r) != "Đã cập nhật ưu đãi 'Ưu đãi hè 2026'." {
		t.Fatalf("sửa ưu đãi: %s", r.raw)
	}
	r = c.do("POST", "/crm/promotions/"+pid+"/toggle", nil)
	if !strings.HasPrefix(message(r), "Đã ngừng áp dụng ưu đãi") {
		t.Fatalf("ngừng ưu đãi: %s", r.raw)
	}
	r = c.do("POST", "/crm/promotions/"+pid+"/toggle", nil)
	if !strings.HasPrefix(message(r), "Đã bật lại ưu đãi") {
		t.Fatalf("bật lại ưu đãi: %s", r.raw)
	}
	expect(t, c.do("GET", "/crm/promotions/"+uuid.NewString(), nil), 404, "")

	// Ưu đãi theo khóa học: khóa phải có trong danh mục, phạm vi hiện tên khóa.
	invalid(t, c.do("POST", "/crm/promotions", map[string]any{"name": "Khóa lạ", "type": "fixed", "value": 100000, "course_id": uuid.New()}),
		"Khóa học không hợp lệ.")
	var course uuid.UUID
	if err := e.pg.QueryRow(context.Background(), `INSERT INTO academic.courses (code, name, tuition_fee, total_lessons)
		VALUES ('CS-CRM-1', 'IELTS Foundation', 6000000, 48) RETURNING id`).Scan(&course); err != nil {
		t.Fatal(err)
	}
	r = c.do("POST", "/crm/promotions", map[string]any{"name": "Ưu đãi IELTS", "type": "fixed", "value": 200000, "course_id": course, "branch_id": tm.a})
	expect(t, r, 201, "")
	if p := c.do("GET", "/crm/promotions/"+r.body["id"].(string), nil); p.body["scope_label"] != "Cơ sở CN-A · IELTS Foundation" {
		t.Fatalf("phạm vi ưu đãi theo khóa: %s", p.raw)
	}
}

// Nguồn khách theo danh mục chung (core.system_categories, type lead_source).
func TestLeadSources(t *testing.T) {
	e := setup(t)
	tm := newTeam(e)
	// Nguồn khách: mặc định khi danh mục trống, có danh mục "lead_source" thì theo danh mục (thứ tự sort_order).
	sources := func() []any {
		t.Helper()
		r := tm.staffAC.do("GET", "/crm/options", nil)
		expect(t, r, 200, "")
		return r.body["sources"].([]any)
	}
	if got := sources(); len(got) != len(DefaultSources) || got[0] != DefaultSources[0] {
		t.Fatalf("nguồn mặc định: %v", got)
	}
	e.exec(`INSERT INTO core.system_categories (type, code, name, sort_order) VALUES ('lead_source', 'S2', 'Zalo OA', 2),
		('lead_source', 'S1', 'Hội thảo', 1), ('lost_reason', 'L1', 'Học phí', 1)`)
	if got := sources(); len(got) != 2 || got[0] != "Hội thảo" || got[1] != "Zalo OA" {
		t.Fatalf("nguồn theo danh mục: %v", got)
	}
}

func TestImport(t *testing.T) {
	e := setup(t)
	tm := newTeam(e)
	c := tm.staffAC
	c.create(t, newLead("Đã có", "0912000999", tm.a))

	csv := "\xEF\xBB\xBFHọ tên,Số điện thoại,Email,Ngày sinh,Người phụ trách\n" +
		"Nguyễn Một,912111111,mot@example.com,15/08/2015,\n" +
		"Nguyễn Hai,0912 111 111,,,\n" +
		",0912111222,,,\n" +
		"Nguyễn Ba,0912000999,,31/02/2015,\n" +
		"Nguyễn Bốn,0912111333,,," + tm.staffA2.name + "\n"
	fields := map[string]string{"branch_id": tm.a.String(), "default_source": "Hội thảo"}

	invalid(t, c.upload("/crm/import/preview", "", nil, fields), "Vui lòng chọn file Excel / CSV.")
	invalid(t, c.upload("/crm/import/preview", "khach.xls", []byte("x"), fields), "Chỉ hỗ trợ file .xlsx hoặc .csv")
	invalid(t, c.upload("/crm/import/preview", "khach.csv", []byte(csv), map[string]string{"branch_id": tm.b.String()}),
		"Chi nhánh không hợp lệ hoặc ngoài phạm vi của bạn.")
	invalid(t, c.upload("/crm/import/preview", "khach.csv", []byte("Tên lớp,Ghi chú\nA,B\n"), fields), `File phải có cột "Họ tên" và "Số điện thoại"`)

	p := c.upload("/crm/import/preview", "khach.csv", []byte(csv), fields)
	expect(t, p, 200, "")
	if p.body["valid_count"] != float64(2) || p.body["error_count"] != float64(3) {
		t.Fatalf("xem trước: %s", p.raw)
	}
	rows := p.body["rows"].([]any)
	wantErr := map[int]string{1: "Trùng SĐT với dòng 2 trong file", 2: "Thiếu họ tên", 3: "SĐT đã có trong CRM (KH-"}
	for i, want := range wantErr {
		errs := field(rows[i], "errors").([]any)
		if len(errs) == 0 || !strings.Contains(errs[0].(string), want) {
			t.Fatalf("lỗi dòng %d: %v, muốn %q", i+2, errs, want)
		}
	}
	if errs := field(rows[3], "errors").([]any); len(errs) != 2 || !strings.HasPrefix(errs[1].(string), "Ngày sinh không hợp lệ") {
		t.Fatalf("lỗi ngày sinh: %v", errs)
	}
	bid := p.body["id"].(string)
	expect(t, tm.staffBC.do("POST", "/crm/import/"+bid+"/commit", nil), 404, "")
	if x := c.send("GET", "/crm/import/"+bid+"/errors", "", nil); x.status != 200 {
		t.Fatalf("tải file lỗi: %d", x.status)
	}

	r := c.do("POST", "/crm/import/"+bid+"/commit", nil)
	expect(t, r, 200, "")
	if r.body["created"] != float64(2) || message(r) != "Đã nhập 2 khách hàng mới vào Cơ sở CN-A. Bỏ qua 3 dòng lỗi." {
		t.Fatalf("nhập khách: %s", r.raw)
	}
	invalid(t, c.do("POST", "/crm/import/"+bid+"/commit", nil), "Chưa có dữ liệu xem trước.")
	list := c.do("GET", "/crm/leads?search=Nguyễn", nil)
	if len(data(list)) != 2 {
		t.Fatalf("khách đã nhập: %s", list.raw)
	}
	for _, row := range data(list) {
		m := row.(map[string]any)
		want := tm.staffA.name
		if m["name"] == "Nguyễn Bốn" {
			want = tm.staffA2.name // cột "Người phụ trách"
		}
		if m["assigned_user"] != want || m["source"] != "Hội thảo" {
			t.Fatalf("khách nhập %v: phụ trách %v nguồn %v", m["name"], m["assigned_user"], m["source"])
		}
	}

	// File .xlsx (số điện thoại kiểu số mất số 0 đầu)
	f := excelize.NewFile()
	sh := f.GetSheetName(0)
	_ = f.SetSheetRow(sh, "A1", &[]any{"Họ tên", "SĐT", "Nguồn"})
	_ = f.SetSheetRow(sh, "A2", &[]any{"Excel Một", 912222333, "Zalo"})
	var buf bytes.Buffer
	_ = f.Write(&buf)
	p = c.upload("/crm/import/preview", "khach.xlsx", buf.Bytes(), map[string]string{"branch_id": tm.a.String()})
	if p.body["valid_count"] != float64(1) || field(p.body["rows"].([]any)[0], "data", "phone") != "0912222333" {
		t.Fatalf("xem trước xlsx: %s", p.raw)
	}
	r = c.do("POST", "/crm/import/"+p.body["id"].(string)+"/cancel", nil)
	if message(r) != "Đã hủy phiên nhập khách." {
		t.Fatalf("hủy nhập: %s", r.raw)
	}
	expect(t, e.as(tm.teacher).do("GET", "/crm/import/options", nil), 403, "forbidden")
}
