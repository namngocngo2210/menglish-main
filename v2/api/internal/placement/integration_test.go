package placement

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
	"slices"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/go-chi/chi/v5"
	"github.com/google/uuid"
	"github.com/jackc/pgx/v5/pgxpool"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/secretbox"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/store"
)

// Test HTTP đầy đủ với Postgres và Redis thật (TEST_DATABASE_URL, TEST_REDIS_URL). CRM và lớp học là bản giả
// trong bộ nhớ (fakeLeads, fakeSessions) vì các module đó đang làm song song.

type fakeLeads struct {
	mu      sync.Mutex
	leads   map[uuid.UUID]Lead
	visible map[uuid.UUID][]uuid.UUID // người dùng → khách được xem
	events  []LeadEvent
}

func (f *fakeLeads) VisibleIDs(_ context.Context, a *iam.Access) ([]uuid.UUID, bool, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	return slices.Clone(f.visible[a.UserID]), false, nil
}

func (f *fakeLeads) Get(_ context.Context, id uuid.UUID) (*Lead, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	if l, ok := f.leads[id]; ok {
		return &l, nil
	}
	return nil, nil
}

func (f *fakeLeads) Lookup(_ context.Context, ids []uuid.UUID) (map[uuid.UUID]Lead, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	out := map[uuid.UUID]Lead{}
	for _, id := range ids {
		if l, ok := f.leads[id]; ok {
			out[id] = l
		}
	}
	return out, nil
}

func (f *fakeLeads) MatchByPhone(_ context.Context, phone string) (*Lead, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	for _, l := range f.leads {
		if NormalizePhone(l.Phone) == phone {
			return &l, nil
		}
	}
	return nil, nil
}

func (f *fakeLeads) Record(_ context.Context, ev LeadEvent) error {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.events = append(f.events, ev)
	return nil
}

func (f *fakeLeads) lastEvent(t *testing.T, lead uuid.UUID) LeadEvent {
	t.Helper()
	f.mu.Lock()
	defer f.mu.Unlock()
	for i := len(f.events) - 1; i >= 0; i-- {
		if f.events[i].LeadID == lead {
			return f.events[i]
		}
	}
	t.Fatalf("khách %s chưa có sự kiện nào", lead)
	return LeadEvent{}
}

type fakeSessions struct {
	mu       sync.Mutex
	sessions map[uuid.UUID]Session
	teaching map[uuid.UUID][]uuid.UUID // người dùng → buổi dạy
}

func (f *fakeSessions) Get(_ context.Context, id uuid.UUID) (*Session, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	if s, ok := f.sessions[id]; ok {
		return &s, nil
	}
	return nil, nil
}

func (f *fakeSessions) Lookup(_ context.Context, ids []uuid.UUID) (map[uuid.UUID]Session, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	out := map[uuid.UUID]Session{}
	for _, id := range ids {
		if s, ok := f.sessions[id]; ok {
			out[id] = s
		}
	}
	return out, nil
}

func (f *fakeSessions) TeachingScope(_ context.Context, uid uuid.UUID) ([]uuid.UUID, []uuid.UUID, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	return slices.Clone(f.teaching[uid]), nil, nil
}

type env struct {
	t        *testing.T
	pg       *pgxpool.Pool
	iam      *iam.Service
	svc      *Service
	srv      *httptest.Server
	leads    *fakeLeads
	sessions *fakeSessions
	mu       sync.Mutex
	clock    time.Time
	branch   uuid.UUID
}

func (e *env) now() time.Time {
	e.mu.Lock()
	defer e.mu.Unlock()
	return e.clock
}

func (e *env) advance(d time.Duration) {
	e.mu.Lock()
	defer e.mu.Unlock()
	e.clock = e.clock.Add(d)
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
	if _, err := pool.Exec(ctx, `TRUNCATE placement.submissions, placement.invitations, placement.tests, crm.trial_bookings,
		iam.users, iam.roles, iam.role_permissions, core.branches CASCADE`); err != nil {
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

	e := &env{t: t, pg: pool, leads: &fakeLeads{leads: map[uuid.UUID]Lead{}, visible: map[uuid.UUID][]uuid.UUID{}},
		sessions: &fakeSessions{sessions: map[uuid.UUID]Session{}, teaching: map[uuid.UUID][]uuid.UUID{}},
		clock:    time.Now().Truncate(time.Second)}
	e.iam = iam.NewService(pool, rdb, box, log)
	e.svc = NewService(pool, rdb, log, Options{Leads: e.leads, Sessions: e.sessions, Now: e.now})
	auth := iam.NewHandler(e.iam, log)
	h := NewHandler(e.svc, log)
	r := chi.NewRouter()
	r.Route("/api/v1", func(r chi.Router) {
		r.Use(iam.SameOrigin, auth.LoadSession)
		auth.Routes(r)
		h.Routes(r)
		h.PublicRoutes(r)
	})
	e.srv = httptest.NewServer(r)
	t.Cleanup(e.srv.Close)
	if err := pool.QueryRow(ctx, `INSERT INTO core.branches (code, name) VALUES ('CS1', 'Cơ sở 1') RETURNING id`).Scan(&e.branch); err != nil {
		t.Fatal(err)
	}
	return e
}

const testPassword = "Trung-tam-tieng-anh-2026"

type member struct {
	id   uuid.UUID
	code string
}

// member tạo nhân sự chỉ có đúng các quyền perms (cấp bằng phân quyền cá nhân, không vai trò).
func (e *env) member(t *testing.T, code string, perms ...string) member {
	t.Helper()
	ctx := context.Background()
	u, _, err := e.iam.CreateUser(ctx, iam.NewUser{Kind: "staff", Code: code, FullName: "Người " + code})
	if err != nil {
		t.Fatal(err)
	}
	hash, _ := iam.HashPassword(ctx, testPassword)
	if _, err := e.pg.Exec(ctx, `UPDATE iam.users SET password_hash = $2, must_change_password = false WHERE id = $1`, u.ID, hash); err != nil {
		t.Fatal(err)
	}
	if _, err := e.pg.Exec(ctx, `INSERT INTO iam.user_branches (user_id, branch_id, is_primary) VALUES ($1, $2, true)`, u.ID, e.branch); err != nil {
		t.Fatal(err)
	}
	for _, p := range perms {
		if _, err := e.pg.Exec(ctx, `INSERT INTO iam.permission_overrides (user_id, permission, allow) VALUES ($1, $2, true)`, u.ID, p); err != nil {
			t.Fatalf("cấp quyền %s: %v", p, err)
		}
	}
	return member{id: u.ID, code: code}
}

type client struct {
	e      *env
	cookie string
	csrf   string
	ip     string
}

type resp struct {
	status int
	body   map[string]any
	raw    string
}

func (e *env) anon(ip string) *client { return &client{e: e, ip: ip} }

func (e *env) as(t *testing.T, m member) *client {
	t.Helper()
	c := &client{e: e, ip: "203.0.113.10"}
	r := c.do("POST", "/auth/login", map[string]any{"identifier": m.code, "password": testPassword})
	expect(t, r, 200, "")
	return c
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
	req.Header.Set("X-Forwarded-For", c.ip)
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
	out := resp{status: res.StatusCode, body: map[string]any{}, raw: string(raw)}
	_ = json.Unmarshal(raw, &out.body)
	if tok, ok := out.body["csrf_token"].(string); ok {
		c.csrf = tok
	}
	return out
}

func expect(t *testing.T, r resp, status int, code string) {
	t.Helper()
	if r.status != status || (code != "" && r.body["error"] != code) {
		t.Fatalf("muốn %d %s, nhận %d %s", status, code, r.status, r.raw)
	}
}

// expectMsg: lỗi 422 với đúng thông báo tiếng Việt.
func expectMsg(t *testing.T, r resp, msg string) {
	t.Helper()
	if r.status != 422 || r.body["message"] != msg {
		t.Fatalf("muốn 422 %q, nhận %d %s", msg, r.status, r.raw)
	}
}

func obj(v any) map[string]any {
	m, _ := v.(map[string]any)
	return m
}

func list(v any) []any {
	l, _ := v.([]any)
	return l
}

var sampleQuestions = []map[string]any{
	{"id": "l1", "skill": "listening", "title": "Nghe 1", "correct_answer": "A", "options": []map[string]any{{"key": "A", "text": "cat"}, {"key": "B", "text": "dog"}}},
	{"id": "l2", "skill": "listening", "title": "Nghe 2", "correct_answer": "B", "options": []map[string]any{{"key": "A", "text": "cat"}, {"key": "B", "text": "dog"}}},
	{"id": "r1", "skill": "reading", "title": "Đọc 1", "type": "fill_blank", "correct_answer": "seven|7", "explanation": "GIẢI_THÍCH"},
	{"id": "w1", "skill": "writing", "type": "essay", "title": "Viết về gia đình em"},
	{"id": "s1", "skill": "speaking", "type": "speaking_prompt", "title": "Giới thiệu bản thân", "cue_points": "tên, tuổi"},
}

func (e *env) createTest(t *testing.T, c *client, code string) string {
	t.Helper()
	r := c.do("POST", "/placement-tests", map[string]any{
		"code": code, "title": "Đề " + code, "grade_level": "lop_3", "duration_minutes": 45, "questions": sampleQuestions,
	})
	expect(t, r, 201, "")
	return obj(r.body["data"])["id"].(string)
}

func TestTestBank(t *testing.T) {
	e := setup(t)
	author := e.as(t, e.member(t, "gv01", "placement_test.view", "placement_test.create", "placement_test.update", "placement_test.delete"))
	viewer := e.as(t, e.member(t, "xem01", "placement_test.view"))
	nobody := e.as(t, e.member(t, "kho01", "lead.view"))

	expect(t, nobody.do("GET", "/placement-tests", nil), 403, "forbidden")
	expect(t, viewer.do("POST", "/placement-tests", map[string]any{"code": "X"}), 403, "forbidden")

	base := map[string]any{"code": "TEST-01", "title": "Đề lớp 3", "grade_level": "lop_3", "duration_minutes": 45}
	with := func(k string, v any) map[string]any {
		m := map[string]any{}
		for kk, vv := range base {
			m[kk] = vv
		}
		m[k] = v
		return m
	}
	expectMsg(t, author.do("POST", "/placement-tests", with("code", " ")), "Vui lòng nhập mã đề.")
	expectMsg(t, author.do("POST", "/placement-tests", with("title", "")), "Vui lòng nhập tên đề thi.")
	expectMsg(t, author.do("POST", "/placement-tests", with("duration_minutes", 5)), "Thời gian làm bài tối thiểu 10 phút.")
	expectMsg(t, author.do("POST", "/placement-tests", base), `Mã đề phải chứa "G3-G4" để hệ thống chấm theo thang điểm Khối 3 lên 4.`)
	expectMsg(t, author.do("POST", "/placement-tests", map[string]any{"code": "TEST-G3-G4-PDF", "title": "PDF", "grade_level": "lop_3", "duration_minutes": 45, "mode": "pdf"}),
		`Hệ thống mới chưa hỗ trợ tải đề PDF — hãy chọn cách "Soạn từng câu".`)
	expectMsg(t, author.do("POST", "/placement-tests", map[string]any{"code": "TEST-G3-G4-Q", "title": "Q", "grade_level": "lop_3", "duration_minutes": 45,
		"questions": []map[string]any{{"skill": "math"}}}), "Câu 1: kỹ năng không hợp lệ.")

	r := author.do("POST", "/placement-tests", map[string]any{"code": "TEST-G3-G4-01", "title": "Đề lớp 3", "grade_level": "lop_3",
		"duration_minutes": 45, "questions": sampleQuestions, "save_mode": "draft"})
	expect(t, r, 201, "")
	if !strings.HasPrefix(r.body["message"].(string), "Đã lưu nháp đề Đề lớp 3") {
		t.Fatalf("thông báo lưu nháp: %v", r.body["message"])
	}
	d := obj(r.body["data"])
	id := d["id"].(string)
	if d["is_active"] != false || d["target_level"] != "Lớp 3" || d["questions_count"] != float64(5) {
		t.Fatalf("đề vừa tạo: %v", d)
	}
	expectMsg(t, author.do("POST", "/placement-tests", map[string]any{"code": "test-g3-g4-01", "title": "Trùng", "grade_level": "lop_3", "duration_minutes": 45}),
		"Mã đề đã tồn tại, vui lòng chọn mã khác.")

	r = viewer.do("GET", "/placement-tests?search=lớp%203", nil)
	expect(t, r, 200, "")
	if r.body["total"] != float64(1) || obj(r.body["stats"])["hidden_tests"] != float64(1) {
		t.Fatalf("danh sách: %s", r.raw)
	}
	r = viewer.do("GET", "/placement-tests?status=active", nil)
	if r.body["total"] != float64(0) {
		t.Fatalf("lọc đang hoạt động: %s", r.raw)
	}
	r = viewer.do("GET", "/placement-tests/"+id, nil)
	expect(t, r, 200, "")
	if len(list(r.body["questions"])) != 5 {
		t.Fatalf("chi tiết đề: %s", r.raw)
	}
	expect(t, viewer.do("GET", "/placement-tests/"+uuid.NewString(), nil), 404, "not_found")
	expect(t, viewer.do("GET", "/placement-tests/rubric", nil), 200, "")
	expect(t, viewer.do("GET", "/placement-tests/options", nil), 200, "")

	expect(t, viewer.do("PUT", "/placement-tests/"+id, map[string]any{"title": "x"}), 403, "forbidden")
	r = author.do("PUT", "/placement-tests/"+id, map[string]any{"title": "Đề lớp 3 (sửa)", "target_level": "Lớp 3", "grade_level": "lop_3",
		"duration_minutes": 60, "questions": sampleQuestions, "is_active": true})
	expect(t, r, 200, "")
	if obj(r.body["data"])["title"] != "Đề lớp 3 (sửa)" || obj(r.body["data"])["is_active"] != true {
		t.Fatalf("sửa đề: %s", r.raw)
	}
	r = author.do("POST", "/placement-tests/"+id+"/toggle-active", nil)
	expect(t, r, 200, "")
	if obj(r.body["data"])["is_active"] != false {
		t.Fatalf("ẩn đề: %s", r.raw)
	}
	r = author.do("POST", "/placement-tests/"+id+"/duplicate", nil)
	expect(t, r, 201, "")
	dup := obj(r.body["data"])
	if !strings.HasPrefix(dup["code"].(string), "TEST-CUSTOM-") || len(list(dup["questions"])) != 5 {
		t.Fatalf("nhân bản: %s", r.raw)
	}
	expect(t, author.do("DELETE", "/placement-tests/"+dup["id"].(string), nil), 200, "")
	expect(t, viewer.do("GET", "/placement-tests/"+dup["id"].(string), nil), 404, "not_found")

	// Đề mẫu hệ thống khóa sửa / xóa.
	if _, err := e.pg.Exec(context.Background(), `UPDATE placement.tests SET is_preset = true WHERE id = $1`, id); err != nil {
		t.Fatal(err)
	}
	expect(t, author.do("PUT", "/placement-tests/"+id, map[string]any{"title": "x", "target_level": "x", "duration_minutes": 45}), 409, "")
	expect(t, author.do("DELETE", "/placement-tests/"+id, nil), 409, "")
}

func TestInvitationAndPublicTest(t *testing.T) {
	e := setup(t)
	author := e.as(t, e.member(t, "gv01", "placement_test.view", "placement_test.create", "placement_test.distribute"))
	saleM := e.member(t, "sale01", "placement_test.view", "entrance_test.send", "lead.view")
	sale := e.as(t, saleM)
	viewer := e.as(t, e.member(t, "xem01", "placement_test.view"))
	grader := e.as(t, e.member(t, "hv01", "placement_test.view", "placement_test.grade"))

	lead := Lead{ID: uuid.New(), Name: "Bé Na", Phone: "0912345678", Email: "na@example.com", Stage: "test_scheduled", BranchID: &e.branch}
	e.leads.leads[lead.ID] = lead
	e.leads.visible[saleM.id] = []uuid.UUID{lead.ID}

	testID := e.createTest(t, author, "TEST-G3-G4-01")

	expect(t, viewer.do("POST", "/placement-tests/"+testID+"/invitations", map[string]any{}), 403, "forbidden")
	// Người chỉ gửi link cho khách không tạo được link phát hành chung.
	expect(t, sale.do("POST", "/placement-tests/"+testID+"/invitations", map[string]any{"kind": "public"}), 403, "forbidden")
	expectMsg(t, sale.do("POST", "/placement-tests/"+testID+"/invitations", map[string]any{"kind": "lead"}), "Vui lòng chọn khách hàng.")
	expectMsg(t, sale.do("POST", "/placement-tests/"+testID+"/invitations", map[string]any{"kind": "lead", "lead_id": uuid.NewString()}),
		"Không tìm thấy khách hàng trong phạm vi bạn phụ trách.")
	expectMsg(t, author.do("POST", "/placement-tests/"+testID+"/invitations", map[string]any{"expires_in_days": 365}), "Hạn dùng link từ 1 đến 90 ngày.")

	r := author.do("POST", "/placement-tests/"+testID+"/invitations", map[string]any{"max_submissions": 1, "note": "Hội thảo"})
	expect(t, r, 201, "")
	pubTok := r.body["token"].(string)
	if r.body["path"] != "/lam-bai/"+pubTok || obj(r.body["invitation"])["status"] != "active" {
		t.Fatalf("link chung: %s", r.raw)
	}
	// DB chỉ giữ băm SHA-256 của token.
	var n int
	_ = e.pg.QueryRow(context.Background(), `SELECT count(*) FROM placement.invitations WHERE token_hash = $1`, []byte(pubTok)).Scan(&n)
	if n != 0 {
		t.Fatal("token lưu dạng rõ trong DB")
	}

	r = sale.do("POST", "/placement-tests/"+testID+"/invitations", map[string]any{"kind": "lead", "lead_id": lead.ID})
	expect(t, r, 201, "")
	leadTok := r.body["token"].(string)
	inv := obj(r.body["invitation"])
	if inv["candidate_name"] != "Bé Na" || inv["expires_at"] == nil {
		t.Fatalf("link riêng: %s", r.raw)
	}

	anon := e.anon("198.51.100.1")
	expect(t, anon.do("GET", "/public/placement/khong-co-token", nil), 404, "link_not_found")
	r = anon.do("GET", "/public/placement/"+pubTok, nil)
	expect(t, r, 200, "")
	for _, secret := range []string{"correct_answer", "GIẢI_THÍCH", "seven"} {
		if strings.Contains(r.raw, secret) {
			t.Fatalf("trang làm bài lộ %q: %s", secret, r.raw)
		}
	}
	if len(list(r.body["listening"])) != 2 || r.body["max_violations"] != float64(MaxViolations) || r.body["lead"] != nil {
		t.Fatalf("trang làm bài: %s", r.raw)
	}
	r = anon.do("GET", "/public/placement/"+leadTok, nil)
	expect(t, r, 200, "")
	if obj(r.body["lead"])["name"] != "Bé Na" {
		t.Fatalf("điền sẵn thông tin khách: %s", r.raw)
	}
	if ev := e.leads.lastEvent(t, lead.ID); ev.AdvanceTo != "testing" || ev.ActorID != nil {
		t.Fatalf("mở link riêng phải chuyển khách sang Test: %+v", ev)
	}

	// Nộp bài
	expectMsg(t, anon.do("POST", "/public/placement/"+pubTok+"/submit", map[string]any{"candidate_phone": "0900000000"}), "Vui lòng nhập họ và tên thí sinh.")
	expectMsg(t, anon.do("POST", "/public/placement/"+pubTok+"/submit", map[string]any{"candidate_name": "An", "candidate_phone": "0900000000", "candidate_email": "sai"}),
		"Email nhận bảng điểm không hợp lệ.")
	answers := map[string]any{"l1": "A", "l2": "A", "r1": "Seven", "khong_co": "x"}
	r = anon.do("POST", "/public/placement/"+pubTok+"/submit", map[string]any{
		"candidate_name": "Trần An", "candidate_phone": "0900000000", "answers": answers, "writing_content": "My family has four people.",
		"speaking_self_rate": "beginner", "violation_count": 3, "auto_submitted": true,
		"violation_log": []map[string]any{{"type": "hidden", "at": "10:00"}, {"type": "hack", "at": "x"}, {"type": "blur", "at": "10:01"}},
	})
	expect(t, r, 201, "")
	if r.body["test_title"] != "Đề TEST-G3-G4-01" {
		t.Fatalf("nộp bài: %s", r.raw)
	}
	// Link chung giới hạn 1 lượt nộp.
	expect(t, anon.do("POST", "/public/placement/"+pubTok+"/submit", map[string]any{"candidate_name": "B", "candidate_phone": "0900000001"}), 410, "link_used")
	expect(t, anon.do("GET", "/public/placement/"+pubTok, nil), 410, "link_used")

	// Bài nộp qua link riêng gắn khách; khách CRM khớp SĐT.
	r = anon.do("POST", "/public/placement/"+leadTok+"/submit", map[string]any{"candidate_name": "Bé Na", "candidate_phone": "0912345678", "answers": map[string]any{"l1": "A"}})
	expect(t, r, 201, "")
	if ev := e.leads.lastEvent(t, lead.ID); !strings.Contains(ev.Content, "Nộp qua link test riêng của lead.") {
		t.Fatalf("nhật ký khách khi nộp: %+v", ev)
	}

	// Danh sách bài: người không thấy khách chỉ thấy bài không gắn khách.
	r = viewer.do("GET", "/placement-submissions?test_id="+testID, nil)
	expect(t, r, 200, "")
	subs := list(r.body["submissions"])
	if len(subs) != 1 || obj(subs[0])["candidate_name"] != "Trần An" {
		t.Fatalf("phạm vi khách khi xem bài: %s", r.raw)
	}
	r = sale.do("GET", "/placement-submissions", nil)
	if len(list(r.body["submissions"])) != 2 {
		t.Fatalf("người phụ trách khách thấy cả bài của khách: %s", r.raw)
	}
	pubSub := obj(subs[0])
	if pubSub["listening"] != "7.5" || pubSub["reading_writing"] != "20" || pubSub["is_pending"] != true {
		t.Fatalf("chấm tự động (Nghe 1/2 × 15 = 7,5; Đọc 1/1 × 20): %v", pubSub)
	}
	r = grader.do("GET", "/placement-submissions/"+pubSub["id"].(string), nil)
	expect(t, r, 200, "")
	sub := obj(r.body["submission"])
	if sub["violation_count"] != float64(3) || sub["auto_submitted"] != true || len(list(sub["violations"])) != 2 {
		t.Fatalf("nhật ký rời bài: %s", r.raw)
	}
	if obj(r.body["rubric"])["group"] != "khoi_3_4" {
		t.Fatalf("khối theo mã đề: %s", r.raw)
	}
	// Bài của khách ngoài phạm vi: 404.
	r = sale.do("GET", "/placement-submissions", nil)
	var leadSub string
	for _, s := range list(r.body["submissions"]) {
		if obj(s)["lead_id"] != nil {
			leadSub = obj(s)["id"].(string)
		}
	}
	expect(t, grader.do("GET", "/placement-submissions/"+leadSub, nil), 404, "not_found")
	expect(t, viewer.do("GET", "/placement-submissions/"+leadSub+"/scorecard", nil), 404, "not_found")
	expect(t, viewer.do("GET", "/placement-submissions/"+pubSub["id"].(string), nil), 403, "forbidden")

	// Đề đang ẩn → link ngừng; thu hồi / hết hạn → 410.
	r = sale.do("GET", "/placement-tests/"+testID+"/invitations", nil)
	expect(t, r, 200, "")
	if len(list(r.body["invitations"])) != 2 {
		t.Fatalf("danh sách link: %s", r.raw)
	}
	invID := inv["id"].(string)
	expect(t, viewer.do("POST", "/placement-invitations/"+invID+"/revoke", nil), 403, "forbidden")
	expect(t, sale.do("POST", "/placement-invitations/"+invID+"/revoke", nil), 200, "")
	expect(t, sale.do("POST", "/placement-invitations/"+invID+"/revoke", nil), 409, "already_revoked")
	expect(t, anon.do("GET", "/public/placement/"+leadTok, nil), 410, "link_expired")

	r = author.do("POST", "/placement-tests/"+testID+"/invitations", map[string]any{"expires_in_days": 1})
	tok := r.body["token"].(string)
	expect(t, anon.do("GET", "/public/placement/"+tok, nil), 200, "")
	e.advance(25 * time.Hour)
	expect(t, anon.do("GET", "/public/placement/"+tok, nil), 410, "link_expired")
	// Đang làm dở vẫn nộp được trong thời gian làm bài + 60 phút sau hạn.
	expect(t, anon.do("POST", "/public/placement/"+tok+"/submit", map[string]any{"candidate_name": "C", "candidate_phone": "0900000002"}), 201, "")
	e.advance(2 * time.Hour)
	expect(t, anon.do("POST", "/public/placement/"+tok+"/submit", map[string]any{"candidate_name": "C", "candidate_phone": "0900000002"}), 410, "link_expired")
}

func TestPublicRateLimit(t *testing.T) {
	e := setup(t)
	author := e.as(t, e.member(t, "gv01", "placement_test.view", "placement_test.create", "placement_test.distribute"))
	testID := e.createTest(t, author, "TEST-G3-G4-01")
	r := author.do("POST", "/placement-tests/"+testID+"/distribute", nil)
	expect(t, r, 201, "")
	tok := obj(r.body["data"])["token"].(string)
	anon := e.anon("198.51.100.9")
	for i := range openPerMinute {
		if r := anon.do("GET", "/public/placement/"+tok, nil); r.status != 200 {
			t.Fatalf("lần %d: %d", i+1, r.status)
		}
	}
	expect(t, anon.do("GET", "/public/placement/"+tok, nil), 429, "too_many_requests")
	// IP khác không bị ảnh hưởng.
	expect(t, e.anon("198.51.100.10").do("GET", "/public/placement/"+tok, nil), 200, "")
	other := e.anon("198.51.100.11")
	for range submitPerMinut {
		other.do("POST", "/public/placement/"+tok+"/submit", map[string]any{})
	}
	expect(t, other.do("POST", "/public/placement/"+tok+"/submit", map[string]any{}), 429, "too_many_requests")
}

func TestGrading(t *testing.T) {
	e := setup(t)
	author := e.as(t, e.member(t, "gv01", "placement_test.view", "placement_test.create", "placement_test.distribute"))
	graderM := e.member(t, "hv01", "placement_test.view", "placement_test.grade", "lead.scope_all")
	grader := e.as(t, graderM)
	lead := Lead{ID: uuid.New(), Name: "Bé Bin", Phone: "0987654321", Stage: "testing"}
	e.leads.leads[lead.ID] = lead

	testID := e.createTest(t, author, "TEST-G3-G4-01")
	r := author.do("POST", "/placement-tests/"+testID+"/invitations", map[string]any{})
	tok := r.body["token"].(string)
	// Link chung nhưng SĐT khớp khách CRM → gắn khách.
	expect(t, e.anon("198.51.100.2").do("POST", "/public/placement/"+tok+"/submit", map[string]any{"candidate_name": "Bin", "candidate_phone": "0987 654 321"}), 201, "")
	if ev := e.leads.lastEvent(t, lead.ID); !strings.Contains(ev.Content, "Khớp lead theo số điện thoại.") {
		t.Fatalf("khớp khách theo SĐT: %+v", ev)
	}
	r = grader.do("GET", "/placement-submissions", nil)
	subID := obj(list(r.body["submissions"])[0])["id"].(string)
	path := "/placement-submissions/" + subID + "/grade"

	expectMsg(t, grader.do("POST", path, map[string]any{"grade_group": "khoi_9"}), "Vui lòng chọn khối lớp để chấm theo thang điểm.")
	expectMsg(t, grader.do("POST", path, map[string]any{"grade_group": "khoi_3_4", "listening_score": "16", "reading_writing_score": 1, "speaking_score": 1}),
		"Điểm Nghe tối đa 15 (Khối 3 lên 4).")
	expectMsg(t, grader.do("POST", path, map[string]any{"grade_group": "khoi_3_4", "listening_score": "abc"}), "Điểm Nghe phải là số.")
	expectMsg(t, grader.do("POST", path, map[string]any{"grade_group": "khoi_3_4", "listening_score": 10, "reading_writing_score": 10}),
		"Điểm Nói (Speaking) luôn do người chấm nhập tay.")
	expectMsg(t, grader.do("POST", path, map[string]any{"grade_group": "khac", "listening_score": 50, "reading_writing_score": 50}),
		NoRubricNotice+": vui lòng nhập lớp xếp cho học viên.")

	r = grader.do("POST", path, map[string]any{"grade_group": "khoi_3_4", "listening_score": 10, "reading_writing_score": "12.5", "speaking_score": 5, "action": "draft"})
	expect(t, r, 200, "")
	sub := obj(r.body["submission"])
	if sub["status"] != "pending" || sub["total_score"] != 27.5 || sub["suggested_class"] != "FAM 2 (NỬA SAU)" {
		t.Fatalf("lưu nháp: %s", r.raw)
	}
	if ev := e.leads.lastEvent(t, lead.ID); ev.AdvanceTo == "tested" {
		t.Fatal("lưu nháp không được đồng bộ điểm sang khách")
	}
	r = grader.do("POST", path, map[string]any{"grade_group": "khoi_3_4", "listening_score": 15, "reading_writing_score": 20, "speaking_score": 5,
		"chosen_class": "FAM 2 (NỬA SAU)", "teacher_comments": "Con cần luyện nói thêm", "action": "confirm"})
	expect(t, r, 200, "")
	sub = obj(r.body["submission"])
	if sub["status"] != "graded" || sub["total_score"] != float64(40) || sub["suggested_class"] != "Luyện MOVERS" || sub["final_class"] != "FAM 2 (NỬA SAU)" || sub["overridden"] != true {
		t.Fatalf("xác nhận kết quả: %s", r.raw)
	}
	ev := e.leads.lastEvent(t, lead.ID)
	if ev.AdvanceTo != "tested" || ev.TestScore == nil || ev.ActorID == nil || *ev.ActorID != graderM.id ||
		!strings.Contains(ev.Content, "(lớp đề xuất theo thang điểm: Luyện MOVERS)") {
		t.Fatalf("đồng bộ điểm sang khách: %+v", ev)
	}
	// Đã chấm thì lưu nháp vẫn giữ "Đã chấm".
	r = grader.do("POST", path, map[string]any{"grade_group": "khoi_3_4", "listening_score": 15, "reading_writing_score": 20, "speaking_score": 6,
		"chosen_class": "FAM 2 (NỬA SAU)", "teacher_comments": "Con cần luyện nói thêm", "action": "draft"})
	expect(t, r, 200, "")
	if obj(r.body["submission"])["status"] != "graded" {
		t.Fatalf("bài đã chấm không lùi về nháp: %s", r.raw)
	}
	r = grader.do("GET", "/placement-submissions/"+subID+"/scorecard", nil)
	expect(t, r, 200, "")
	if r.body["teacher_comments"] != "Con cần luyện nói thêm" {
		t.Fatalf("bảng điểm: %s", r.raw)
	}
	// Đề đã có bài làm không xóa được.
	deleter := e.as(t, e.member(t, "ql01", "placement_test.view", "placement_test.delete"))
	expect(t, deleter.do("DELETE", "/placement-tests/"+testID, nil), 409, "")
}

func TestTrialBooking(t *testing.T) {
	e := setup(t)
	// 09:00 giờ Việt Nam để buổi học trong ngày chưa kết thúc.
	vn := e.now().In(vnZone)
	e.clock = time.Date(vn.Year(), vn.Month(), vn.Day(), 9, 0, 0, 0, vnZone)
	day := func(add int) time.Time {
		d := e.clock.AddDate(0, 0, add)
		return time.Date(d.Year(), d.Month(), d.Day(), 0, 0, 0, 0, time.UTC)
	}

	staffM := e.member(t, "hv01", "lead.view", "lead.stage_forward", "class.view")
	staff := e.as(t, staffM)
	teacherM := e.member(t, "gv01", "portal.teacher")
	teacher := e.as(t, teacherM)
	otherTeacher := e.as(t, e.member(t, "gv02", "portal.teacher"))
	nobody := e.as(t, e.member(t, "kho01", "lead.view"))

	lead := Lead{ID: uuid.New(), Name: "Bé Mai", Phone: "0911111111", Stage: "consulting", BranchID: &e.branch}
	closed := Lead{ID: uuid.New(), Name: "Đã chốt", Stage: "won", BranchID: &e.branch}
	hidden := Lead{ID: uuid.New(), Name: "Không thấy", Stage: "consulting"}
	for _, l := range []Lead{lead, closed, hidden} {
		e.leads.leads[l.ID] = l
	}
	e.leads.visible[staffM.id] = []uuid.UUID{lead.ID, closed.ID}
	classID := uuid.New()
	s1 := Session{ID: uuid.New(), ClassID: classID, ClassName: "Movers A", ClassStatus: "active", Status: "scheduled", BranchID: &e.branch,
		Date: day(1), Start: "17:30", End: "19:00", TeacherName: "Cô Lan"}
	s2 := s1
	s2.ID, s2.Date = uuid.New(), day(3)
	far := s1
	far.ID, far.Date = uuid.New(), day(20)
	otherBranch := s1
	otherBranch.ID, otherBranch.BranchID = uuid.New(), ptr(uuid.New())
	for _, s := range []Session{s1, s2, far, otherBranch} {
		e.sessions.sessions[s.ID] = s
	}
	e.sessions.teaching[teacherM.id] = []uuid.UUID{s1.ID, s2.ID}
	e.realSessions(t, s1, s2, far)

	expect(t, nobody.do("POST", "/trial-bookings", map[string]any{"lead_id": lead.ID}), 403, "forbidden")
	expect(t, staff.do("POST", "/trial-bookings", map[string]any{"lead_id": hidden.ID, "class_session_id": s1.ID}), 404, "not_found")
	expectMsg(t, staff.do("POST", "/trial-bookings", map[string]any{"lead_id": lead.ID}), "Vui lòng chọn một buổi học thử.")
	expectMsg(t, staff.do("POST", "/trial-bookings", map[string]any{"lead_id": closed.ID, "class_session_id": s1.ID}),
		"Chỉ đặt học thử cho khách đang tư vấn (chưa chốt, chưa thất bại).")
	expectMsg(t, staff.do("POST", "/trial-bookings", map[string]any{"lead_id": lead.ID, "class_session_id": far.ID}), "Buổi học đã chọn không còn khả dụng.")
	expectMsg(t, staff.do("POST", "/trial-bookings", map[string]any{"lead_id": lead.ID, "class_session_id": otherBranch.ID}), "Buổi học thử phải thuộc chi nhánh của khách.")

	r := staff.do("POST", "/trial-bookings", map[string]any{"lead_id": lead.ID, "class_session_id": s1.ID, "notes": "Bé nhút nhát"})
	expect(t, r, 201, "")
	b1 := r.body["id"].(string)
	if r.body["class_name"] != "Movers A" || r.body["customer_name"] != "Bé Mai" || r.body["start"] != "17:30" {
		t.Fatalf("xếp học thử: %s", r.raw)
	}
	if ev := e.leads.lastEvent(t, lead.ID); !strings.Contains(ev.Content, "Đặt lịch học thử: Movers A") || !strings.Contains(ev.Content, "Ghi chú cho giáo viên: Bé nhút nhát") {
		t.Fatalf("nhật ký xếp học thử: %+v", ev)
	}
	expectMsg(t, staff.do("POST", "/trial-bookings", map[string]any{"lead_id": lead.ID, "class_session_id": s2.ID}),
		fmt.Sprintf("Khách đang có buổi học thử chưa diễn ra (%s). Xếp buổi tiếp theo sau khi buổi này kết thúc.", day(1).Format("02/01/2006")))

	r = staff.do("GET", "/trial-bookings/lead/"+lead.ID.String(), nil)
	expect(t, r, 200, "")
	if st := obj(r.body["state"]); st["used"] != float64(1) || st["pending_id"] != b1 {
		t.Fatalf("tình trạng học thử: %s", r.raw)
	}
	r = staff.do("GET", "/trial-bookings", nil)
	expect(t, r, 200, "")
	if r.body["total"] != float64(1) {
		t.Fatalf("lịch học thử: %s", r.raw)
	}
	expect(t, nobody.do("GET", "/trial-bookings", nil), 403, "forbidden")

	// Giáo viên: chỉ buổi mình dạy; nhận xét từ ngày học.
	r = teacher.do("GET", "/trial-bookings/teacher", nil)
	expect(t, r, 200, "")
	if r.body["total"] != float64(1) {
		t.Fatalf("khách học thử của giáo viên: %s", r.raw)
	}
	r = otherTeacher.do("GET", "/trial-bookings/teacher", nil)
	if r.body["total"] != float64(0) {
		t.Fatalf("giáo viên khác không thấy: %s", r.raw)
	}
	fb := "/trial-bookings/" + b1 + "/feedback"
	expectMsg(t, teacher.do("POST", fb, map[string]any{"status": "attended", "rating": 4, "feedback": "Tốt"}),
		"Buổi học thử chưa diễn ra — chỉ điểm danh / nhận xét từ ngày học.")
	e.advance(24 * time.Hour)
	expect(t, otherTeacher.do("POST", fb, map[string]any{"status": "attended", "rating": 4, "feedback": "Tốt"}), 403, "forbidden")
	expectMsg(t, teacher.do("POST", fb, map[string]any{"status": "present"}), "Trạng thái điểm danh không hợp lệ.")
	expectMsg(t, teacher.do("POST", fb, map[string]any{"status": "attended", "feedback": "Tốt"}), "Vui lòng chọn mức đánh giá.")
	expectMsg(t, teacher.do("POST", fb, map[string]any{"status": "attended", "rating": 4}), "Vui lòng nhập nhận xét chi tiết cho khách học thử.")
	r = teacher.do("POST", fb, map[string]any{"status": "attended", "rating": 4, "feedback": "Con hăng hái phát biểu",
		"remarks": map[string]any{"grammar": "Khá", "attitude": "Tích cực", "khac": "bỏ"}})
	expect(t, r, 200, "")
	if r.body["message"] != "Đã lưu nhận xét học thử vào hồ sơ khách — Học vụ đã được báo để chăm sóc khách." ||
		obj(r.body["data"])["status"] != "attended" || obj(r.body["data"])["remarks_summary"] != "Thực hành ngữ pháp: Khá · Tinh thần học tập: Tích cực" {
		t.Fatalf("nhận xét: %s", r.raw)
	}
	if ev := e.leads.lastEvent(t, lead.ID); !strings.HasPrefix(ev.Content, "Nhận xét học thử (4/5) buổi Movers A") {
		t.Fatalf("nhật ký nhận xét: %+v", ev)
	}
	expectMsg(t, staff.do("POST", "/trial-bookings/"+b1+"/cancel", map[string]any{"reason": "bận"}), "Chỉ hủy được buổi học thử đang chờ.")

	// Buổi 2 xếp được sau khi buổi 1 kết thúc; hủy cần lý do.
	r = staff.do("POST", "/trial-bookings", map[string]any{"lead_id": lead.ID, "class_session_id": s2.ID})
	expect(t, r, 201, "")
	b2 := r.body["id"].(string)
	expectMsg(t, staff.do("POST", "/trial-bookings/"+b2+"/cancel", map[string]any{"reason": " "}), "Vui lòng nhập lý do hủy.")
	r = staff.do("POST", "/trial-bookings/"+b2+"/cancel", map[string]any{"reason": "Khách bận"})
	expect(t, r, 200, "")
	if r.body["status"] != "cancelled" || !strings.Contains(r.body["notes"].(string), "Hủy: Khách bận") {
		t.Fatalf("hủy học thử: %s", r.raw)
	}
	// Hủy trả lại lượt; đã học 1 buổi → còn 1 lượt.
	r = staff.do("GET", "/trial-bookings/lead/"+lead.ID.String(), nil)
	if st := obj(r.body["state"]); st["used"] != float64(1) || st["remaining"] != float64(1) {
		t.Fatalf("lượt học thử sau khi hủy: %s", r.raw)
	}
	r = staff.do("GET", "/trial-bookings?scope=past", nil)
	expect(t, r, 200, "")
}

// realSessions ghi lớp / buổi học thật cùng id với buổi giả (crm.trial_bookings có khóa ngoại tới academic.*).
func (e *env) realSessions(t *testing.T, list ...Session) {
	t.Helper()
	ctx := context.Background()
	for _, s := range list {
		if _, err := e.pg.Exec(ctx, `INSERT INTO academic.classes (id, code, name, branch_id, status) VALUES ($1, $2, $3, $4, 'active')
			ON CONFLICT (id) DO NOTHING`, s.ClassID, "L-"+s.ClassID.String()[:8], s.ClassName, s.BranchID); err != nil {
			t.Fatal(err)
		}
		if _, err := e.pg.Exec(ctx, `INSERT INTO academic.class_sessions (id, class_id, branch_id, date, start_time, end_time)
			VALUES ($1, $2, $3, $4, $5::time, $6::time)`, s.ID, s.ClassID, s.BranchID, s.Date, s.Start, s.End); err != nil {
			t.Fatal(err)
		}
	}
}
