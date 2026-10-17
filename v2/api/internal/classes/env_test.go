package classes

import (
	"bytes"
	"context"
	"crypto/rand"
	"encoding/base64"
	"encoding/json"
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
	"github.com/redis/go-redis/v9"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/catalogs"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/secretbox"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/store"
)

// Test qua HTTP với Postgres và Redis thật (TEST_DATABASE_URL, TEST_REDIS_URL; Redis bị xóa sạch).
// "Hôm nay" cố định: Thứ 2 19/10/2026 10:00 giờ Việt Nam.
type env struct {
	t        *testing.T
	pg       *pgxpool.Pool
	iam      *iam.Service
	cat      *catalogs.Service
	svc      *Service
	srv      *httptest.Server
	box      *secretbox.Box
	rdb      *redis.Client
	roomType uuid.UUID
}

const password = "Trung-tam-tieng-anh-2026"

var fixedNow = time.Date(2026, 10, 19, 10, 0, 0, 0, vnZone)

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
	if _, err := pool.Exec(ctx, `TRUNCATE iam.users, iam.roles, iam.role_permissions, core.branches, core.room_types, core.rooms,
		core.holidays, hr.teaching_shifts, hr.sla_rules, academic.students, academic.courses, academic.course_levels,
		academic.classes CASCADE`); err != nil {
		t.Fatal(err)
	}
	// Khung giờ ca dạy Thứ 2–6 như dữ liệu mẫu (test danh mục có thể đã xóa).
	if _, err := pool.Exec(ctx, `INSERT INTO hr.teaching_shifts (day_type, name, start_time, end_time, alt_start_time, alt_end_time, sort_order) VALUES
		('weekday', 'Ca 1', '18:00', '19:30', '18:10', '19:40', 1),
		('weekday', 'Ca 2', '19:30', '21:00', '19:40', '21:10', 2)`); err != nil {
		t.Fatal(err)
	}
	if _, err := iam.SyncCatalog(ctx, pool); err != nil {
		t.Fatal(err)
	}
	if _, err := catalogs.SyncSLA(ctx, pool); err != nil {
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
	cat := catalogs.NewService(pool, box, log)
	svc := NewService(pool, cat, log)
	cat.Classes = svc
	svc.now = func() time.Time { return fixedNow }
	auth := iam.NewHandler(iamSvc, log)

	r := chi.NewRouter()
	r.Route("/api/v1", func(r chi.Router) {
		r.Use(iam.SameOrigin, auth.LoadSession)
		auth.Routes(r)
		NewHandler(svc, log).Routes(r)
	})
	srv := httptest.NewServer(r)
	t.Cleanup(srv.Close)
	e := &env{t: t, pg: pool, iam: iamSvc, cat: cat, svc: svc, srv: srv, box: box, rdb: rdb}
	if err := pool.QueryRow(ctx, "INSERT INTO core.room_types (name) VALUES ('Phòng học') RETURNING id").Scan(&e.roomType); err != nil {
		t.Fatal(err)
	}
	return e
}

func (e *env) exec(sql string, args ...any) {
	e.t.Helper()
	if _, err := e.pg.Exec(context.Background(), sql, args...); err != nil {
		e.t.Fatal(err)
	}
}

func (e *env) scalar(dst any, sql string, args ...any) {
	e.t.Helper()
	if err := e.pg.QueryRow(context.Background(), sql, args...).Scan(dst); err != nil {
		e.t.Fatal(err)
	}
}

func (e *env) branch(code string) uuid.UUID {
	e.t.Helper()
	var id uuid.UUID
	e.scalar(&id, "INSERT INTO core.branches (code, name) VALUES ($1, $2) RETURNING id", code, "Chi nhánh "+code)
	return id
}

func (e *env) room(branch uuid.UUID, name string) uuid.UUID {
	e.t.Helper()
	var id uuid.UUID
	e.scalar(&id, "INSERT INTO core.rooms (branch_id, room_type_id, name, capacity) VALUES ($1, $2, $3, 20) RETURNING id", branch, e.roomType, name)
	return id
}

// student: hồ sơ học viên (Chờ khai giảng) của chi nhánh (nil = chưa gán chi nhánh).
func (e *env) student(code, name string, branch *uuid.UUID) uuid.UUID {
	e.t.Helper()
	var id uuid.UUID
	e.scalar(&id, "INSERT INTO academic.students (code, full_name, phone, branch_id) VALUES ($1, $2, '0912345678', $3) RETURNING id", code, name, branch)
	return id
}

type member struct {
	id     uuid.UUID
	code   string
	secret string
}

// member tạo nhân sự đã đổi mật khẩu, bật 2FA, có vai trò và chi nhánh (chi nhánh đầu là chi nhánh chính).
func (e *env) member(code string, branches []uuid.UUID, roles ...string) member {
	e.t.Helper()
	ctx := context.Background()
	u, _, err := e.iam.CreateUser(ctx, iam.NewUser{Kind: "staff", Code: code, FullName: "Người " + code})
	if err != nil {
		e.t.Fatal(err)
	}
	hash, _ := iam.HashPassword(ctx, password)
	key, err := totp.Generate(totp.GenerateOpts{Issuer: "MEnglish", AccountName: code})
	if err != nil {
		e.t.Fatal(err)
	}
	e.exec("UPDATE iam.users SET password_hash = $2, must_change_password = false, totp_secret = $3, totp_enabled_at = now() WHERE id = $1",
		u.ID, hash, e.box.Seal([]byte(key.Secret()), u.ID[:]))
	for _, r := range roles {
		e.exec("INSERT INTO iam.user_roles (user_id, role) VALUES ($1, $2)", u.ID, r)
	}
	for i, b := range branches {
		e.exec("INSERT INTO iam.user_branches (user_id, branch_id, is_primary) VALUES ($1, $2, $3)", u.ID, b, i == 0)
	}
	return member{id: u.ID, code: code, secret: key.Secret()}
}

func (e *env) access(m member) *iam.Access {
	e.t.Helper()
	a, err := e.iam.LoadAccess(context.Background(), m.id)
	if err != nil {
		e.t.Fatal(err)
	}
	return a
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

// as đăng nhập (kể cả bước 2FA).
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

func expectMessage(t *testing.T, r resp, status int, msg string) {
	t.Helper()
	if r.status != status || r.body["message"] != msg {
		t.Fatalf("muốn %d %q, nhận %d %v", status, msg, r.status, r.body)
	}
}

func expectPrefix(t *testing.T, r resp, status int, prefix string) string {
	t.Helper()
	msg, _ := r.body["message"].(string)
	if r.status != status || !strings.HasPrefix(msg, prefix) {
		t.Fatalf("muốn %d %q…, nhận %d %v", status, prefix, r.status, r.body)
	}
	return msg
}

// newClass tạo lớp qua API; trả id.
func (e *env) newClass(c *client, body map[string]any) uuid.UUID {
	e.t.Helper()
	in := map[string]any{"program": "IELTS Foundation", "level": "B1", "max_capacity": 10}
	for k, v := range body {
		in[k] = v
	}
	r := c.do("POST", "/classes", in)
	expect(e.t, r, 201, "")
	id, err := uuid.Parse(r.body["id"].(string))
	if err != nil {
		e.t.Fatal(err)
	}
	return id
}

// weekly: TKB Thứ 2 + Thứ 4 Ca 1 (18:00–19:30) từ 19/10 đến 13/11/2026 = 8 buổi.
func weekly(classID uuid.UUID, extra map[string]any) map[string]any {
	in := map[string]any{
		"class_id": classID, "start_date": "2026-10-19", "end_date": "2026-11-13",
		"slot1_day": "Thứ 2", "slot1_start": "18:00", "slot1_end": "19:30",
		"slot2_day": "Thứ 4", "slot2_start": "18:00", "slot2_end": "19:30",
	}
	for k, v := range extra {
		in[k] = v
	}
	return in
}

func (e *env) classField(id uuid.UUID, col string) *string {
	e.t.Helper()
	var v *string
	e.scalar(&v, "SELECT "+col+"::text FROM academic.classes WHERE id = $1", id)
	return v
}

func (e *env) sessionDates(id uuid.UUID, status string) []string {
	e.t.Helper()
	rows, err := e.pg.Query(context.Background(),
		"SELECT to_char(date, 'YYYY-MM-DD') || ':' || type FROM academic.class_sessions WHERE class_id = $1 AND status = $2 ORDER BY date, start_time", id, status)
	if err != nil {
		e.t.Fatal(err)
	}
	defer rows.Close()
	out := []string{}
	for rows.Next() {
		var s string
		if err := rows.Scan(&s); err != nil {
			e.t.Fatal(err)
		}
		out = append(out, s)
	}
	return out
}

func (e *env) sessionOn(classID uuid.UUID, date string) uuid.UUID {
	e.t.Helper()
	var id uuid.UUID
	e.scalar(&id, "SELECT id FROM academic.class_sessions WHERE class_id = $1 AND date = $2::date ORDER BY start_time LIMIT 1", classID, date)
	return id
}

func list(r resp, key string) []map[string]any {
	out := []map[string]any{}
	items, _ := r.body[key].([]any)
	for _, it := range items {
		out = append(out, it.(map[string]any))
	}
	return out
}

func strp(s *string) string {
	if s == nil {
		return "<nil>"
	}
	return *s
}
