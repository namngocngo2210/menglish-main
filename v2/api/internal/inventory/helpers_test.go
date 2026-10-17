package inventory

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

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/secretbox"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/store"
)

// Test HTTP với Postgres và Redis thật (TEST_DATABASE_URL, TEST_REDIS_URL; test xóa sạch DB Redis đó).
// Người dùng tạo bằng iam.Service.CreateUser, vai trò / chi nhánh / 2FA gắn bằng SQL.

const testPassword = "Kho-vat-pham-2026!"

type env struct {
	t    *testing.T
	pool *pgxpool.Pool
	iam  *iam.Service
	box  *secretbox.Box
	svc  *Service
	srv  *httptest.Server
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
	if _, err := pool.Exec(ctx, `TRUNCATE iam.users, iam.roles, iam.role_permissions, core.branches,
		ops.material_order_counters CASCADE`); err != nil {
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
	svc := NewService(pool, log, DefaultConfig())

	r := chi.NewRouter()
	r.Route("/api/v1", func(r chi.Router) {
		auth := iam.NewHandler(isvc, log)
		r.Use(iam.SameOrigin, auth.LoadSession)
		auth.Routes(r)
		NewHandler(svc, log).Routes(r)
	})
	srv := httptest.NewServer(r)
	t.Cleanup(srv.Close)
	return &env{t: t, pool: pool, iam: isvc, box: box, svc: svc, srv: srv}
}

func (e *env) exec(sql string, args ...any) {
	e.t.Helper()
	if _, err := e.pool.Exec(context.Background(), sql, args...); err != nil {
		e.t.Fatal(err)
	}
}

func (e *env) branch(code, name string) uuid.UUID {
	e.t.Helper()
	var id uuid.UUID
	if err := e.pool.QueryRow(context.Background(),
		`INSERT INTO core.branches (code, name) VALUES ($1, $2) RETURNING id`, code, name).Scan(&id); err != nil {
		e.t.Fatal(err)
	}
	return id
}

type member struct {
	id     uuid.UUID
	code   string
	secret string // khóa TOTP (vai trò bắt buộc 2FA)
}

// member tạo tài khoản đã đổi mật khẩu, có vai trò và chi nhánh (chi nhánh đầu là chi nhánh chính).
// Vai trò bắt buộc 2FA (Admin, Quản lý, Học vụ, Trưởng Học thuật) được bật sẵn TOTP.
func (e *env) member(code string, branches []uuid.UUID, roles ...string) member {
	e.t.Helper()
	ctx := context.Background()
	u, _, err := e.iam.CreateUser(ctx, iam.NewUser{Kind: "staff", Code: code, FullName: "Người " + code})
	if err != nil {
		e.t.Fatal(err)
	}
	hash, err := iam.HashPassword(ctx, testPassword)
	if err != nil {
		e.t.Fatal(err)
	}
	e.exec(`UPDATE iam.users SET password_hash = $2, must_change_password = false WHERE id = $1`, u.ID, hash)
	for _, role := range roles {
		e.exec(`INSERT INTO iam.user_roles (user_id, role) VALUES ($1, $2)`, u.ID, role)
	}
	for i, b := range branches {
		e.exec(`INSERT INTO iam.user_branches (user_id, branch_id, is_primary) VALUES ($1, $2, $3)`, u.ID, b, i == 0)
	}
	m := member{id: u.ID, code: code}
	needTOTP := false
	for _, role := range roles {
		needTOTP = needTOTP || role == iam.RoleAdmin || role == iam.RoleManager || role == iam.RoleAcademicStaff || role == iam.RoleAcademicLead
	}
	if needTOTP {
		key, err := totp.Generate(totp.GenerateOpts{Issuer: "MEnglish", AccountName: code})
		if err != nil {
			e.t.Fatal(err)
		}
		m.secret = key.Secret()
		e.exec(`UPDATE iam.users SET totp_secret = $2, totp_enabled_at = now() WHERE id = $1`, u.ID, e.box.Seal([]byte(m.secret), u.ID[:]))
	}
	return m
}

// access: tập quyền của người dùng (gọi service trực tiếp, không qua HTTP).
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

// as đăng nhập (kể cả bước 2FA). Mỗi người chỉ đăng nhập một lần trong test (mã TOTP không dùng lại được).
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
		t.Fatalf("muốn %d %q, nhận %d %v", status, code, r.status, r.body)
	}
}

// expectMsg: lỗi 422 có thông báo chứa đoạn chữ.
func expectMsg(t *testing.T, r resp, status int, part string) {
	t.Helper()
	msg, _ := r.body["message"].(string)
	if r.status != status || !strings.Contains(msg, part) {
		t.Fatalf("muốn %d với thông báo chứa %q, nhận %d %v", status, part, r.status, r.body)
	}
}

func (e *env) stockAt(item, branch uuid.UUID) int {
	e.t.Helper()
	var n int
	if err := e.pool.QueryRow(context.Background(),
		`SELECT COALESCE((SELECT quantity FROM ops.stock WHERE item_id = $1 AND branch_id = $2), 0)`, item, branch).Scan(&n); err != nil {
		e.t.Fatal(err)
	}
	return n
}

func str(v any) string { s, _ := v.(string); return s }

func num(v any) int { f, _ := v.(float64); return int(f) }

func list(v any) []map[string]any {
	var out []map[string]any
	for _, x := range v.([]any) {
		out = append(out, x.(map[string]any))
	}
	return out
}

// notified: mã người nhận (sắp xếp, nối bằng dấu phẩy) của thông báo loại typ có link.
func (e *env) notified(typ, link string) string {
	e.t.Helper()
	var codes string
	if err := e.pool.QueryRow(context.Background(), `SELECT coalesce(string_agg(u.code, ',' ORDER BY u.code), '')
		FROM core.notifications n JOIN iam.users u ON u.id = n.user_id WHERE n.type = $1 AND n.link = $2`, typ, link).Scan(&codes); err != nil {
		e.t.Fatal(err)
	}
	return codes
}

// audited: số dòng nhật ký vận hành theo mã thao tác và đối tượng.
func (e *env) audited(action, subjectID string) int {
	e.t.Helper()
	var n int
	if err := e.pool.QueryRow(context.Background(), `SELECT count(*) FROM core.audit_log WHERE action = $1 AND subject_id = $2`,
		action, subjectID).Scan(&n); err != nil {
		e.t.Fatal(err)
	}
	return n
}
