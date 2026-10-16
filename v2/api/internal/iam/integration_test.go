package iam

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
	"testing"
	"time"

	"github.com/go-chi/chi/v5"
	"github.com/jackc/pgx/v5/pgxpool"
	"github.com/pquerna/otp/totp"
	"github.com/redis/go-redis/v9"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/secretbox"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/store"
)

// Test đầy đủ luồng HTTP với Postgres và Redis thật. Cần TEST_DATABASE_URL và TEST_REDIS_URL (CI đặt sẵn);
// Redis dùng DB riêng vì test xóa sạch nó.
type env struct {
	t   *testing.T
	svc *Service
	srv *httptest.Server
	rdb *redis.Client
	pg  *pgxpool.Pool
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
	if _, err := pool.Exec(ctx, "TRUNCATE iam.users CASCADE"); err != nil {
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
	svc := NewService(pool, rdb, box, log)
	h := NewHandler(svc, log)

	r := chi.NewRouter()
	r.Route("/api/v1", func(r chi.Router) {
		r.Use(SameOrigin, h.LoadSession)
		h.Routes(r)
	})
	srv := httptest.NewServer(r)
	t.Cleanup(srv.Close)
	return &env{t: t, svc: svc, srv: srv, rdb: rdb, pg: pool}
}

// client giữ cookie phiên và CSRF như trình duyệt (cookie Secure không đi qua http của httptest nên tự gắn).
type client struct {
	e      *env
	cookie string
	csrf   string
	ip     string
	origin string
}

func (e *env) client() *client { return &client{e: e, ip: "203.0.113.10", origin: "self"} }

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
	switch c.origin {
	case "self":
		req.Header.Set("Origin", c.e.srv.URL)
	case "":
	default:
		req.Header.Set("Origin", c.origin)
	}
	req.Header.Set("X-Forwarded-For", c.ip)
	if c.cookie != "" {
		req.AddCookie(&http.Cookie{Name: CookieName, Value: c.cookie})
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
		if ck.Name == CookieName {
			if !ck.HttpOnly || !ck.Secure || ck.SameSite != http.SameSiteLaxMode || ck.Path != "/" {
				c.e.t.Fatalf("cookie phiên thiếu thuộc tính bảo mật: %+v", ck)
			}
			c.cookie = ck.Value
			if ck.MaxAge < 0 {
				c.cookie = ""
			}
		}
	}
	out := resp{status: res.StatusCode, body: map[string]any{}}
	_ = json.NewDecoder(res.Body).Decode(&out.body)
	if tok, ok := out.body["csrf_token"].(string); ok {
		c.csrf = tok
	}
	return out
}

func (c *client) login(id, pw string) resp {
	return c.do("POST", "/auth/login", map[string]any{"identifier": id, "password": pw})
}

func expect(t *testing.T, r resp, status int, code string) {
	t.Helper()
	if r.status != status || (code != "" && r.body["error"] != code) {
		t.Fatalf("muốn %d %s, nhận %d %v", status, code, r.status, r.body)
	}
}

const newPassword = "Trung-tam-tieng-anh-2026"

func (e *env) staff(t *testing.T) (User, string) {
	u, pw, err := e.svc.CreateUser(context.Background(), NewUser{Kind: "staff", Code: "mes001", Email: "Admin@Meducation.vn", FullName: "Quản trị"})
	if err != nil {
		t.Fatal(err)
	}
	return u, pw
}

func TestLoginFirstTimeAndChangePassword(t *testing.T) {
	e := setup(t)
	_, temp := e.staff(t)

	c := e.client()
	expect(t, c.login("MES001", "sai-mat-khau-roi"), 401, "invalid_credentials")
	expect(t, c.login("khong-ton-tai", "sai-mat-khau-roi"), 401, "invalid_credentials")

	r := c.login("mes001", temp) // mã không phân biệt hoa thường
	expect(t, r, 200, "")
	if r.body["status"] != "ok" || r.body["user"].(map[string]any)["must_change_password"] != true {
		t.Fatalf("đăng nhập: %v", r.body)
	}
	expect(t, c.do("GET", "/auth/me", nil), 200, "")
	// Chưa đổi mật khẩu thì không làm được việc khác
	expect(t, c.do("GET", "/auth/sessions", nil), 403, "password_change_required")

	// Thiết bị thứ hai đăng nhập bằng email
	other := e.client()
	expect(t, other.login("admin@meducation.vn", temp), 200, "")

	// Thiếu CSRF
	csrf := c.csrf
	c.csrf = ""
	expect(t, c.do("POST", "/auth/password", map[string]any{"current_password": temp, "new_password": newPassword}), 403, "csrf")
	c.csrf = csrf
	expect(t, c.do("POST", "/auth/password", map[string]any{"current_password": "sai", "new_password": newPassword}), 422, "wrong_password")
	expect(t, c.do("POST", "/auth/password", map[string]any{"current_password": temp, "new_password": "password123"}), 422, "weak_password")
	expect(t, c.do("POST", "/auth/password", map[string]any{"current_password": temp, "new_password": newPassword}), 204, "")

	// Phiên hiện tại còn, thiết bị kia bị đăng xuất
	expect(t, c.do("GET", "/auth/sessions", nil), 200, "")
	expect(t, other.do("GET", "/auth/me", nil), 401, "unauthenticated")
	expect(t, e.client().login("MES001", temp), 401, "invalid_credentials")
	expect(t, e.client().login("MES001", newPassword), 200, "")

	// Đăng xuất
	expect(t, c.do("POST", "/auth/logout", nil), 204, "")
	expect(t, c.do("GET", "/auth/me", nil), 401, "unauthenticated")
}

func TestGuardianLoginByPhone(t *testing.T) {
	e := setup(t)
	_, pw, err := e.svc.CreateUser(context.Background(), NewUser{Kind: "guardian", Phone: "+84 912 345 678", FullName: "Phụ huynh"})
	if err != nil {
		t.Fatal(err)
	}
	expect(t, e.client().login("0912.345.678", pw), 200, "")
	// Nhân sự trùng SĐT không đăng nhập được bằng SĐT
	_, pw2, _ := e.svc.CreateUser(context.Background(), NewUser{Kind: "staff", Code: "MES002", Phone: "0987654321", FullName: "GV"})
	expect(t, e.client().login("0987654321", pw2), 401, "invalid_credentials")
}

func TestRateLimitAndLockout(t *testing.T) {
	e := setup(t)
	_, temp := e.staff(t)

	c := e.client()
	for range maxFailsPerAcct {
		expect(t, c.login("MES001", "sai-mat-khau-roi"), 401, "invalid_credentials")
	}
	// Đủ 5 lần sai từ IP này: mật khẩu đúng cũng bị chặn
	expect(t, c.login("MES001", temp), 429, "too_many_attempts")
	// IP khác vẫn đăng nhập được
	c2 := e.client()
	c2.ip = "198.51.100.7"
	expect(t, c2.login("MES001", temp), 200, "")

	// 10 lần sai liên tiếp (từ nhiều IP) thì khóa tạm tài khoản
	for i := range 10 {
		ci := e.client()
		ci.ip = "192.0.2." + string(rune('a'+i))
		ci.login("MES001", "sai-mat-khau-roi")
	}
	c3 := e.client()
	c3.ip = "192.0.2.200"
	expect(t, c3.login("MES001", temp), 429, "too_many_attempts")
	// Cấp lại mật khẩu thì mở khóa
	_, temp2, err := e.svc.ResetPassword(context.Background(), "MES001")
	if err != nil {
		t.Fatal(err)
	}
	expect(t, c3.login("MES001", temp2), 200, "")
}

func TestOriginCheck(t *testing.T) {
	e := setup(t)
	_, temp := e.staff(t)
	c := e.client()
	c.origin = ""
	expect(t, c.login("MES001", temp), 403, "bad_origin")
	c.origin = "https://evil.example"
	expect(t, c.login("MES001", temp), 403, "bad_origin")
}

func TestDisabledUserLosesSession(t *testing.T) {
	e := setup(t)
	u, temp := e.staff(t)
	c := e.client()
	expect(t, c.login("MES001", temp), 200, "")
	if _, err := e.pg.Exec(context.Background(), "UPDATE iam.users SET status = 'disabled' WHERE id = $1", u.ID); err != nil {
		t.Fatal(err)
	}
	expect(t, c.do("GET", "/auth/me", nil), 401, "unauthenticated")
	expect(t, e.client().login("MES001", temp), 401, "invalid_credentials")
}

func TestTOTPFlow(t *testing.T) {
	e := setup(t)
	_, temp := e.staff(t)
	c := e.client()
	c.login("MES001", temp)
	expect(t, c.do("POST", "/auth/password", map[string]any{"current_password": temp, "new_password": newPassword}), 204, "")

	r := c.do("POST", "/auth/totp/setup", nil)
	expect(t, r, 200, "")
	secret := r.body["secret"].(string)
	if url := r.body["otpauth_url"].(string); len(url) < 20 {
		t.Fatalf("thiếu URL QR: %v", r.body)
	}
	expect(t, c.do("POST", "/auth/totp/enable", map[string]any{"code": "000000"}), 401, "invalid_code")
	now := time.Now()
	code, _ := totp.GenerateCode(secret, now)
	r = c.do("POST", "/auth/totp/enable", map[string]any{"code": code})
	expect(t, r, 200, "")
	recovery := r.body["recovery_codes"].([]any)
	if len(recovery) != 10 {
		t.Fatalf("có %d mã dự phòng", len(recovery))
	}
	expect(t, c.do("POST", "/auth/totp/setup", nil), 409, "totp_already_enabled")

	// Đăng nhập lại cần bước 2
	d := e.client()
	r = d.login("MES001", newPassword)
	if r.status != 200 || r.body["status"] != "totp_required" || d.cookie != "" {
		t.Fatalf("muốn totp_required, nhận %d %v", r.status, r.body)
	}
	challenge := r.body["challenge"].(string)
	// Mã vừa dùng để bật 2FA không dùng lại được
	expect(t, d.do("POST", "/auth/login/totp", map[string]any{"challenge": challenge, "code": code}), 401, "invalid_code")
	// Mã của bước sau thì được
	e.svc.now = func() time.Time { return now.Add(30 * time.Second) }
	next, _ := totp.GenerateCode(secret, now.Add(30*time.Second))
	expect(t, d.do("POST", "/auth/login/totp", map[string]any{"challenge": challenge, "code": next}), 200, "")
	expect(t, d.do("GET", "/auth/me", nil), 200, "")
	// Challenge chỉ dùng một lần
	expect(t, e.client().do("POST", "/auth/login/totp", map[string]any{"challenge": challenge, "code": next}), 401, "challenge_expired")

	// Đăng nhập bằng mã dự phòng, mã đó chỉ dùng một lần
	f := e.client()
	ch := f.login("MES001", newPassword).body["challenge"].(string)
	expect(t, f.do("POST", "/auth/login/totp", map[string]any{"challenge": ch, "code": recovery[0]}), 200, "")
	if left := f.do("GET", "/auth/me", nil).body["recovery_codes_left"]; left != float64(9) {
		t.Fatalf("còn %v mã dự phòng", left)
	}
	g := e.client()
	ch = g.login("MES001", newPassword).body["challenge"].(string)
	expect(t, g.do("POST", "/auth/login/totp", map[string]any{"challenge": ch, "code": recovery[0]}), 401, "invalid_code")
	// Sai quá 5 lần thì challenge bị hủy
	for range 4 {
		g.do("POST", "/auth/login/totp", map[string]any{"challenge": ch, "code": "111111"})
	}
	expect(t, g.do("POST", "/auth/login/totp", map[string]any{"challenge": ch, "code": recovery[1]}), 429, "too_many_attempts")

	// Tắt 2FA cần mật khẩu
	expect(t, c.do("POST", "/auth/totp/disable", map[string]any{"password": "sai"}), 422, "wrong_password")
	expect(t, c.do("POST", "/auth/totp/disable", map[string]any{"password": newPassword}), 204, "")
	expect(t, e.client().login("MES001", newPassword), 200, "")
}

func TestSessionsListAndRevoke(t *testing.T) {
	e := setup(t)
	_, temp := e.staff(t)
	c := e.client()
	c.login("MES001", temp)
	c.do("POST", "/auth/password", map[string]any{"current_password": temp, "new_password": newPassword})
	other := e.client()
	other.login("MES001", newPassword)

	list := c.do("GET", "/auth/sessions", nil).body["sessions"].([]any)
	if len(list) != 2 {
		t.Fatalf("có %d phiên", len(list))
	}
	var otherID string
	for _, s := range list {
		m := s.(map[string]any)
		if m["current"] != true {
			otherID = m["id"].(string)
		}
	}
	expect(t, c.do("DELETE", "/auth/sessions/khong-co", nil), 404, "session_not_found")
	expect(t, c.do("DELETE", "/auth/sessions/"+otherID, nil), 204, "")
	expect(t, other.do("GET", "/auth/me", nil), 401, "unauthenticated")
	if n := len(c.do("GET", "/auth/sessions", nil).body["sessions"].([]any)); n != 1 {
		t.Fatalf("còn %d phiên", n)
	}
}
