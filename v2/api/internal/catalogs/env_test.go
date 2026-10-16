package catalogs

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

// Test HTTP với Postgres và Redis thật (TEST_DATABASE_URL, TEST_REDIS_URL), giống iam/integration_test.go.

type env struct {
	t   *testing.T
	pg  *pgxpool.Pool
	iam *iam.Service
	box *secretbox.Box
	srv *httptest.Server
	n   int
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
	// TRUNCATE iam.users CASCADE dọn luôn mọi bảng có created_by (kể cả dữ liệu mẫu của migration).
	if _, err := pool.Exec(ctx, `TRUNCATE iam.users, iam.roles, iam.role_permissions, core.branches, core.settings, hr.sla_rules,
		academic.courses, academic.course_levels, core.rooms, core.room_types, core.holidays, core.system_categories,
		hr.teaching_shifts, finance.bank_accounts, finance.debt_reminder_rules CASCADE`); err != nil {
		t.Fatal(err)
	}
	if _, err := iam.SyncCatalog(ctx, pool); err != nil {
		t.Fatal(err)
	}
	if _, err := SyncSLA(ctx, pool); err != nil {
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
	auth := iam.NewHandler(isvc, log)
	h := NewHandler(NewService(pool, box, log), log)

	r := chi.NewRouter()
	r.Route("/api/v1", func(r chi.Router) {
		r.Use(iam.SameOrigin, auth.LoadSession)
		auth.Routes(r)
		h.Routes(r)
	})
	srv := httptest.NewServer(r)
	t.Cleanup(srv.Close)
	return &env{t: t, pg: pool, iam: isvc, box: box, srv: srv}
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

// as tạo tài khoản nhân sự có vai trò, chi nhánh, đã bật 2FA, rồi đăng nhập (mỗi người chỉ đăng nhập một lần / test).
func (e *env) as(branches []uuid.UUID, roles ...string) *client {
	e.t.Helper()
	ctx := context.Background()
	e.n++
	code := fmt.Sprintf("TST%03d", e.n)
	u, pw, err := e.iam.CreateUser(ctx, iam.NewUser{Kind: "staff", Code: code, FullName: "Người " + code})
	if err != nil {
		e.t.Fatal(err)
	}
	key, err := totp.Generate(totp.GenerateOpts{Issuer: "MEnglish", AccountName: code})
	if err != nil {
		e.t.Fatal(err)
	}
	e.exec("UPDATE iam.users SET must_change_password = false, totp_secret = $2, totp_enabled_at = now() WHERE id = $1",
		u.ID, e.box.Seal([]byte(key.Secret()), u.ID[:]))
	for _, r := range roles {
		e.exec("INSERT INTO iam.user_roles (user_id, role) VALUES ($1, $2)", u.ID, r)
	}
	for i, b := range branches {
		e.exec("INSERT INTO iam.user_branches (user_id, branch_id, is_primary) VALUES ($1, $2, $3)", u.ID, b, i == 0)
	}
	c := &client{e: e}
	res := c.do("POST", "/auth/login", map[string]any{"identifier": code, "password": pw})
	expect(e.t, res, 200, "")
	if res.body["status"] == "totp_required" {
		otp, _ := totp.GenerateCode(key.Secret(), time.Now())
		expect(e.t, c.do("POST", "/auth/login/totp", map[string]any{"challenge": res.body["challenge"], "code": otp}), 200, "")
	}
	return c
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

func expect(t *testing.T, r resp, status int, code string) {
	t.Helper()
	if r.status != status || (code != "" && r.body["error"] != code) {
		t.Fatalf("muốn %d %s, nhận %d %v", status, code, r.status, r.body)
	}
}

// expectMsg: lỗi / thông báo đúng nội dung tiếng Việt.
func expectMsg(t *testing.T, r resp, status int, msg string) {
	t.Helper()
	if r.status != status || r.body["message"] != msg {
		t.Fatalf("muốn %d %q, nhận %d %v", status, msg, r.status, r.body)
	}
}

func items(t *testing.T, r resp, key string) []map[string]any {
	t.Helper()
	raw, ok := r.body[key].([]any)
	if !ok {
		t.Fatalf("không có danh sách %s: %v", key, r.body)
	}
	out := make([]map[string]any, 0, len(raw))
	for _, it := range raw {
		out = append(out, it.(map[string]any))
	}
	return out
}
