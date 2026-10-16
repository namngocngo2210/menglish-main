// Package iamtest dựng môi trường test HTTP với Postgres / Redis thật cho các module ngoài iam: tạo chi nhánh, người
// dùng có vai trò (tự bật 2FA nếu vai trò bắt buộc), client giữ cookie phiên + CSRF như trình duyệt.
// Chỉ dùng trong test; cần TEST_DATABASE_URL và TEST_REDIS_URL (không có thì bỏ qua test).
package iamtest

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
	"sync/atomic"
	"testing"
	"time"

	"github.com/go-chi/chi/v5"
	"github.com/google/uuid"
	"github.com/jackc/pgx/v5/pgxpool"
	"github.com/pquerna/otp/totp"
	"github.com/redis/go-redis/v9"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/secretbox"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/store"
)

// Password là mật khẩu của mọi tài khoản tạo bằng Member.
const Password = "Trung-tam-tieng-anh-2026"

type Env struct {
	T    *testing.T
	Pool *pgxpool.Pool
	RDB  *redis.Client
	IAM  *iam.Service
	Auth *iam.Handler
	Log  *slog.Logger
	srv  *httptest.Server
	ips  atomic.Int32
	// Mã dự phòng 2FA của từng tài khoản (để đăng nhập lại)
	recovery map[uuid.UUID][]string
}

// Setup migrate DB, xóa dữ liệu iam + các bảng truncate (của module đang test), đồng bộ danh mục quyền, xóa Redis.
func Setup(t *testing.T, truncate ...string) *Env {
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
	tables := append([]string{"iam.users", "iam.roles", "iam.role_permissions", "core.branches"}, truncate...)
	if _, err := pool.Exec(ctx, "TRUNCATE "+strings.Join(tables, ", ")+" CASCADE"); err != nil {
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
	// IAMTEST_LOG=1 in log ra stderr để xem lỗi 500 khi viết test.
	var out io.Writer = io.Discard
	if os.Getenv("IAMTEST_LOG") != "" {
		out = os.Stderr
	}
	log := slog.New(slog.NewTextHandler(out, nil))
	svc := iam.NewService(pool, rdb, box, log)
	return &Env{T: t, Pool: pool, RDB: rdb, IAM: svc, Auth: iam.NewHandler(svc, log), Log: log, recovery: map[uuid.UUID][]string{}}
}

// Serve chạy API /api/v1 gồm đăng nhập, quản trị iam và các route mount (thêm middleware riêng qua mws).
func (e *Env) Serve(mount func(r chi.Router), mws ...func(http.Handler) http.Handler) {
	r := chi.NewRouter()
	r.Route("/api/v1", func(r chi.Router) {
		r.Use(iam.SameOrigin, e.Auth.LoadSession)
		for _, mw := range mws {
			r.Use(mw)
		}
		e.Auth.Routes(r)
		e.Auth.AdminRoutes(r)
		mount(r)
	})
	e.srv = httptest.NewServer(r)
	e.T.Cleanup(e.srv.Close)
}

// Branch tạo chi nhánh đang hoạt động.
func (e *Env) Branch(code string) uuid.UUID {
	e.T.Helper()
	var id uuid.UUID
	err := e.Pool.QueryRow(context.Background(),
		"INSERT INTO core.branches (code, name) VALUES ($1, $2) RETURNING id", code, "Chi nhánh "+code).Scan(&id)
	if err != nil {
		e.T.Fatal(err)
	}
	return id
}

// Grant thêm quyền cho vai trò (vd. phạm vi dữ liệu) và bỏ cache quyền.
func (e *Env) Grant(role string, perms ...string) {
	e.T.Helper()
	ctx := context.Background()
	for _, p := range perms {
		if _, err := e.Pool.Exec(ctx, "INSERT INTO iam.role_permissions (role, permission) VALUES ($1, $2) ON CONFLICT DO NOTHING", role, p); err != nil {
			e.T.Fatal(err)
		}
	}
	if err := iam.InvalidateAllAccess(ctx, e.RDB); err != nil {
		e.T.Fatal(err)
	}
}

// Revoke bỏ quyền khỏi vai trò.
func (e *Env) Revoke(role string, perms ...string) {
	e.T.Helper()
	ctx := context.Background()
	if _, err := e.Pool.Exec(ctx, "DELETE FROM iam.role_permissions WHERE role = $1 AND permission = ANY ($2)", role, perms); err != nil {
		e.T.Fatal(err)
	}
	if err := iam.InvalidateAllAccess(ctx, e.RDB); err != nil {
		e.T.Fatal(err)
	}
}

type User struct {
	ID   uuid.UUID
	Code string
}

// User tạo tài khoản nhân viên đã đổi mật khẩu (Password), có vai trò và chi nhánh (chi nhánh đầu là chính).
func (e *Env) User(code, name string, branches []uuid.UUID, roles ...string) User {
	e.T.Helper()
	ctx := context.Background()
	u, _, err := e.IAM.CreateUser(ctx, iam.NewUser{Kind: "staff", Code: code, FullName: name})
	if err != nil {
		e.T.Fatal(err)
	}
	hash, _ := iam.HashPassword(ctx, Password)
	if _, err := e.Pool.Exec(ctx, "UPDATE iam.users SET password_hash = $2, must_change_password = false WHERE id = $1", u.ID, hash); err != nil {
		e.T.Fatal(err)
	}
	if err := e.IAM.SetRolesUnchecked(ctx, u.ID, roles); err != nil {
		e.T.Fatal(err)
	}
	for i, b := range branches {
		if _, err := e.Pool.Exec(ctx, "INSERT INTO iam.user_branches (user_id, branch_id, is_primary) VALUES ($1, $2, $3)", u.ID, b, i == 0); err != nil {
			e.T.Fatal(err)
		}
	}
	return User{u.ID, code}
}

// Login đăng nhập; vai trò bắt buộc 2FA thì bật 2FA ngay trong phiên này.
func (e *Env) Login(u User) *Client {
	e.T.Helper()
	c := &Client{e: e, ip: fmt.Sprintf("203.0.113.%d", e.ips.Add(1)%250+1)}
	r := c.Do("POST", "/auth/login", map[string]any{"identifier": u.Code, "password": Password})
	if r.Status == 200 && r.Body["status"] == "totp_required" && len(e.recovery[u.ID]) > 0 {
		// Đăng nhập lại tài khoản đã bật 2FA: dùng mã dự phòng (mã TOTP cùng bước thời gian bị coi là dùng lại).
		code := e.recovery[u.ID][0]
		e.recovery[u.ID] = e.recovery[u.ID][1:]
		r = c.Do("POST", "/auth/login/totp", map[string]any{"challenge": r.Body["challenge"], "code": code})
	}
	if r.Status != 200 || r.Body["status"] != "ok" {
		e.T.Fatalf("đăng nhập %s: %d %v", u.Code, r.Status, r.Body)
	}
	me := c.Do("GET", "/auth/me", nil)
	if me.Body["totp_required"] == true && me.Body["user"].(map[string]any)["totp_enabled"] != true {
		s := c.Do("POST", "/auth/totp/setup", nil)
		code, _ := totp.GenerateCode(s.Body["secret"].(string), time.Now())
		r := c.Do("POST", "/auth/totp/enable", map[string]any{"code": code})
		if r.Status != 200 {
			e.T.Fatalf("bật 2FA %s: %d %v", u.Code, r.Status, r.Body)
		}
		for _, rc := range r.Body["recovery_codes"].([]any) {
			e.recovery[u.ID] = append(e.recovery[u.ID], rc.(string))
		}
	}
	return c
}

// Member = User + Login.
func (e *Env) Member(code, name string, branches []uuid.UUID, roles ...string) (User, *Client) {
	e.T.Helper()
	u := e.User(code, name, branches, roles...)
	return u, e.Login(u)
}

// Client giữ cookie phiên và CSRF như trình duyệt (cookie Secure không đi qua http của httptest nên tự gắn).
type Client struct {
	e      *Env
	cookie string
	csrf   string
	ip     string
}

type Resp struct {
	Status int
	Body   map[string]any
	Raw    []byte
	Header http.Header
}

// Do gửi request JSON tới /api/v1 + path.
func (c *Client) Do(method, path string, body any) Resp {
	c.e.T.Helper()
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
	req.Header.Set("User-Agent", "iamtest")
	if c.cookie != "" {
		req.AddCookie(&http.Cookie{Name: iam.CookieName, Value: c.cookie})
	}
	if c.csrf != "" {
		req.Header.Set("X-CSRF-Token", c.csrf)
	}
	res, err := http.DefaultClient.Do(req)
	if err != nil {
		c.e.T.Fatal(err)
	}
	defer res.Body.Close()
	for _, ck := range res.Cookies() {
		if ck.Name == iam.CookieName {
			c.cookie = ck.Value
			if ck.MaxAge < 0 {
				c.cookie = ""
			}
		}
	}
	out := Resp{Status: res.StatusCode, Body: map[string]any{}, Header: res.Header}
	out.Raw, _ = io.ReadAll(res.Body)
	_ = json.Unmarshal(out.Raw, &out.Body)
	if tok, ok := out.Body["csrf_token"].(string); ok {
		c.csrf = tok
	}
	return out
}

// Expect dừng test nếu mã HTTP / mã lỗi không như mong đợi (code rỗng = không kiểm mã lỗi).
func Expect(t *testing.T, r Resp, status int, code string) {
	t.Helper()
	if r.Status != status || (code != "" && r.Body["error"] != code) {
		t.Fatalf("muốn %d %s, nhận %d %s", status, code, r.Status, r.Raw)
	}
}

// List lấy mảng key trong body JSON.
func List(t *testing.T, r Resp, key string) []map[string]any {
	t.Helper()
	raw, ok := r.Body[key].([]any)
	if !ok {
		t.Fatalf("thiếu mảng %q: %s", key, r.Raw)
	}
	out := make([]map[string]any, 0, len(raw))
	for _, x := range raw {
		out = append(out, x.(map[string]any))
	}
	return out
}
