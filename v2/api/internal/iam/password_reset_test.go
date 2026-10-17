package iam

import (
	"context"
	"net/url"
	"strings"
	"testing"
)

// resetLink: link đặt lại mật khẩu trong thư mới nhất gửi tới email (đọc dữ liệu mẫu trong hàng đợi).
func (e *env) resetLink(t *testing.T, email string) (token string, count int) {
	t.Helper()
	ctx := context.Background()
	if err := e.pg.QueryRow(ctx, `SELECT count(*) FROM core.mail_outbox WHERE to_address = $1`, email).Scan(&count); err != nil {
		t.Fatal(err)
	}
	if count == 0 {
		return "", 0
	}
	var link, kind, subject, expire string
	var sensitive bool
	if err := e.pg.QueryRow(ctx, `SELECT data->>'url', kind, subject, sensitive, data->>'expire_minutes' FROM core.mail_outbox
		WHERE to_address = $1 ORDER BY created_at DESC, id DESC LIMIT 1`, email).Scan(&link, &kind, &subject, &sensitive, &expire); err != nil {
		t.Fatal(err)
	}
	if kind != "password_reset" || subject != "Đặt lại mật khẩu tài khoản MEnglish" || !sensitive || !strings.HasPrefix(link, "/reset-password?") ||
		expire != "30" {
		t.Fatalf("thư đặt lại mật khẩu: %s %s %v %s %s", kind, subject, sensitive, link, expire)
	}
	u, err := url.Parse(link)
	if err != nil {
		t.Fatal(err)
	}
	if u.Query().Get("email") != email {
		t.Fatalf("link thiếu email: %s", link)
	}
	return u.Query().Get("token"), count
}

func TestPasswordResetByEmail(t *testing.T) {
	e := setup(t)
	ctx := context.Background()
	if _, err := e.pg.Exec(ctx, `TRUNCATE core.mail_outbox`); err != nil {
		t.Fatal(err)
	}
	e.staff(t) // MES001, admin@meducation.vn
	other := e.client()
	expect(t, other.login("admin@meducation.vn", newPassword), 401, "invalid_credentials")
	// Phiên cũ đang đăng nhập (đăng nhập bằng mật khẩu tạm thì phải đổi trước): đặt lại xong bị đăng xuất.
	if _, err := e.pg.Exec(ctx, `UPDATE iam.users SET must_change_password = false`); err != nil {
		t.Fatal(err)
	}

	c := e.client()
	msg := func(r resp) string { s, _ := r.body["message"].(string); return s }
	r := c.do("POST", "/auth/forgot-password", map[string]any{"email": ""})
	if r.status != 422 || msg(r) != "Vui lòng nhập Địa chỉ Email." {
		t.Fatalf("thiếu email: %v", r.body)
	}
	r = c.do("POST", "/auth/forgot-password", map[string]any{"email": "sai"})
	if r.status != 422 || msg(r) != "Trường Địa chỉ Email phải là một địa chỉ email hợp lệ." {
		t.Fatalf("email sai: %v", r.body)
	}
	// Email không có tài khoản: cùng thông báo, không xếp thư.
	r = c.do("POST", "/auth/forgot-password", map[string]any{"email": "khongco@meducation.vn"})
	if r.status != 200 || msg(r) != ResetLinkSentMessage {
		t.Fatalf("email không có tài khoản: %v", r.body)
	}
	if _, n := e.resetLink(t, "khongco@meducation.vn"); n != 0 {
		t.Fatal("không được gửi thư cho email không có tài khoản")
	}
	r = c.do("POST", "/auth/forgot-password", map[string]any{"email": " ADMIN@meducation.vn "})
	if r.status != 200 || msg(r) != ResetLinkSentMessage {
		t.Fatalf("yêu cầu link: %v", r.body)
	}
	token, n := e.resetLink(t, "admin@meducation.vn")
	if n != 1 || token == "" {
		t.Fatalf("phải xếp 1 thư, có %d", n)
	}
	// Gửi lại trong 60 giây: cùng thông báo, không thêm thư.
	expect(t, c.do("POST", "/auth/forgot-password", map[string]any{"email": "admin@meducation.vn"}), 200, "")
	if _, n := e.resetLink(t, "admin@meducation.vn"); n != 1 {
		t.Fatalf("gửi lại trong 60 giây: %d thư", n)
	}

	session := e.client()
	expect(t, session.login("MES001", e.currentPassword(t)), 200, "")

	reset := func(tok, email, pw, confirm string) resp {
		return e.client().do("POST", "/auth/reset-password", map[string]any{"token": tok, "email": email, "password": pw, "password_confirmation": confirm})
	}
	if r := reset(token, "admin@meducation.vn", newPassword, "khac"); r.status != 422 || msg(r) != "Xác nhận Mật khẩu không khớp." {
		t.Fatalf("nhập lại sai: %v", r.body)
	}
	expect(t, reset(token, "admin@meducation.vn", "password123", "password123"), 422, "weak_password")
	expect(t, reset("sai-token", "admin@meducation.vn", newPassword, newPassword), 422, "invalid_token")
	expect(t, reset(token, "nguoi-khac@meducation.vn", newPassword, newPassword), 422, "invalid_token")
	r = reset(token, "admin@meducation.vn", newPassword, newPassword)
	if r.status != 200 || msg(r) != "Mật khẩu của bạn đã được đặt lại." {
		t.Fatalf("đặt lại: %v", r.body)
	}
	// Link chỉ dùng một lần; phiên cũ bị đăng xuất; đăng nhập bằng mật khẩu mới.
	expect(t, reset(token, "admin@meducation.vn", newPassword+"x", newPassword+"x"), 422, "invalid_token")
	expect(t, session.do("GET", "/auth/me", nil), 401, "unauthenticated")
	expect(t, e.client().login("MES001", newPassword), 200, "")

	// Link mới thay link cũ (IP khác để không chạm giới hạn 5 lần / phút).
	c = e.client()
	c.ip = "198.51.100.9"
	e.rdb.Del(ctx, "pwreset_throttle:"+e.userID(t, "MES001"))
	expect(t, c.do("POST", "/auth/forgot-password", map[string]any{"email": "admin@meducation.vn"}), 200, "")
	first, _ := e.resetLink(t, "admin@meducation.vn")
	e.rdb.Del(ctx, "pwreset_throttle:"+e.userID(t, "MES001"))
	expect(t, c.do("POST", "/auth/forgot-password", map[string]any{"email": "admin@meducation.vn"}), 200, "")
	second, _ := e.resetLink(t, "admin@meducation.vn")
	if first == second {
		t.Fatal("link mới phải khác")
	}
	expect(t, reset(first, "admin@meducation.vn", newPassword+"1", newPassword+"1"), 422, "invalid_token")

	// Tài khoản bị khóa: không gửi.
	if _, err := e.pg.Exec(ctx, `UPDATE iam.users SET status = 'disabled'`); err != nil {
		t.Fatal(err)
	}
	e.rdb.Del(ctx, "pwreset_throttle:"+e.userID(t, "MES001"))
	expect(t, c.do("POST", "/auth/forgot-password", map[string]any{"email": "admin@meducation.vn"}), 200, "")
	if _, n := e.resetLink(t, "admin@meducation.vn"); n != 3 {
		t.Fatalf("tài khoản khóa vẫn gửi: %d", n)
	}
	expect(t, reset(second, "admin@meducation.vn", newPassword+"2", newPassword+"2"), 422, "invalid_token")

	// Giới hạn 5 yêu cầu / phút / IP.
	ip := e.client()
	ip.ip = "198.51.100.77"
	for range 5 {
		expect(t, ip.do("POST", "/auth/forgot-password", map[string]any{"email": "x@meducation.vn"}), 200, "")
	}
	expect(t, ip.do("POST", "/auth/forgot-password", map[string]any{"email": "x@meducation.vn"}), 429, "too_many_attempts")
}

func (e *env) userID(t *testing.T, code string) string {
	t.Helper()
	var id string
	if err := e.pg.QueryRow(context.Background(), `SELECT id::text FROM iam.users WHERE code = $1`, code).Scan(&id); err != nil {
		t.Fatal(err)
	}
	return id
}

// currentPassword đặt mật khẩu đã biết cho MES001 (mật khẩu tạm in ra lúc tạo không giữ lại ở đây).
func (e *env) currentPassword(t *testing.T) string {
	t.Helper()
	const pw = "Mat-khau-cu-truoc-khi-dat-lai"
	hash, _ := HashPassword(context.Background(), pw)
	if _, err := e.pg.Exec(context.Background(), `UPDATE iam.users SET password_hash = $1 WHERE code = 'MES001'`, hash); err != nil {
		t.Fatal(err)
	}
	return pw
}
