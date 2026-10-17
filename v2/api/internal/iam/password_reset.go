package iam

import (
	"context"
	"errors"
	"net/http"
	"net/url"
	"strings"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"
	"github.com/redis/go-redis/v9"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam/iamdb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify/mail"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/httpx"
)

// Quên mật khẩu qua email (Laravel PasswordResetLinkController / NewPasswordController, broker "users": 60 giây mới
// gửi lại). Link hết hạn sau 30 phút theo tài liệu thiết kế (Laravel: 60 phút). Luôn trả cùng một thông báo dù email có tài khoản hay không. Token chỉ lưu dạng băm
// trong Redis; thư đi qua hàng đợi email, đánh dấu nhạy cảm (không hiện ở màn quản trị, gửi xong xóa dữ liệu).

const (
	ResetTokenTTL   = 30 * time.Minute
	resetThrottle   = 60 * time.Second
	resetIPLimit    = 5 // yêu cầu / phút / IP (throttle:5,1)
	resetPathPrefix = "/reset-password"
)

// ResetLinkSentMessage: thông báo sau khi yêu cầu link, giống nhau với mọi email.
const ResetLinkSentMessage = "Nếu email này có tài khoản, hệ thống đã gửi link đặt lại mật khẩu. Vui lòng kiểm tra hộp thư (kể cả Spam)."

var (
	errResetToken   = &Error{422, "invalid_token", "Link đặt lại mật khẩu không hợp lệ hoặc đã hết hạn."}
	errResetLimited = &Error{429, "too_many_attempts", "Vui lòng đợi một lát rồi thử lại."}
)

func resetTokenKey(token string) string     { return "pwreset:" + hashToken(token) }
func resetUserKey(uid uuid.UUID) string     { return "pwreset_user:" + uid.String() }
func resetThrottleKey(uid uuid.UUID) string { return "pwreset_throttle:" + uid.String() }
func resetIPKey(ip string) string           { return "pwreset_ip:" + ip }

// hit đếm số lần trong cửa sổ window; trả true khi vượt limit.
func (s *Service) hit(ctx context.Context, key string, limit int, window time.Duration) (bool, error) {
	n, err := s.rdb.Incr(ctx, key).Result()
	if err != nil {
		return false, err
	}
	if n == 1 {
		s.rdb.Expire(ctx, key, window)
	}
	return n > int64(limit), nil
}

func checkEmail(email string) (string, error) {
	email = strings.TrimSpace(email)
	if email == "" {
		return "", invalid("Vui lòng nhập Địa chỉ Email.")
	}
	if a := mail.NormalizeAddress(email); a != "" {
		return a, nil
	}
	return "", invalid("Trường Địa chỉ Email phải là một địa chỉ email hợp lệ.")
}

// RequestPasswordReset xếp email chứa link đặt lại mật khẩu nếu email thuộc một tài khoản đang hoạt động.
func (s *Service) RequestPasswordReset(ctx context.Context, email, ip string) error {
	email, err := checkEmail(email)
	if err != nil {
		return err
	}
	if over, err := s.hit(ctx, resetIPKey(ip), resetIPLimit, time.Minute); err != nil {
		return err
	} else if over {
		return errResetLimited
	}
	u, err := s.q.GetUserByEmail(ctx, &email)
	if errors.Is(err, pgx.ErrNoRows) {
		s.log.Info("password reset requested", "result", "unknown_email", "ip", ip)
		return nil
	}
	if err != nil {
		return err
	}
	if u.Status != "active" {
		s.log.Info("password reset requested", "result", "disabled", "user_id", u.ID, "ip", ip)
		return nil
	}
	// Vừa gửi trong 60 giây thì không gửi thêm (RESET_THROTTLED), vẫn trả cùng thông báo.
	if ok, err := s.rdb.SetNX(ctx, resetThrottleKey(u.ID), "1", resetThrottle).Result(); err != nil {
		return err
	} else if !ok {
		s.log.Info("password reset requested", "result", "throttled", "user_id", u.ID, "ip", ip)
		return nil
	}
	token := randomToken()
	// Chỉ link mới nhất còn dùng được (như bảng password_reset_tokens một dòng mỗi email).
	if old, err := s.rdb.Get(ctx, resetUserKey(u.ID)).Result(); err == nil {
		s.rdb.Del(ctx, "pwreset:"+old)
	} else if !errors.Is(err, redis.Nil) {
		return err
	}
	pipe := s.rdb.TxPipeline()
	pipe.Set(ctx, resetTokenKey(token), u.ID.String(), ResetTokenTTL)
	pipe.Set(ctx, resetUserKey(u.ID), hashToken(token), ResetTokenTTL)
	if _, err := pipe.Exec(ctx); err != nil {
		return err
	}
	link := resetPathPrefix + "?token=" + url.QueryEscape(token) + "&email=" + url.QueryEscape(email)
	if _, err := mail.Enqueue(ctx, s.pool, mail.Message{
		To: email, ToName: u.FullName, Subject: "Đặt lại mật khẩu tài khoản MEnglish", Template: mail.TemplatePasswordReset,
		Kind: mail.KindPasswordReset, Sensitive: true, MaxAttempts: 3, RefType: "user", RefID: u.ID.String(),
		Data: mail.PasswordReset{Name: u.FullName, URL: link, ExpireMinutes: int(ResetTokenTTL / time.Minute)},
	}); err != nil {
		return err
	}
	s.log.Info("password reset requested", "result", "sent", "user_id", u.ID, "ip", ip)
	return nil
}

// ResetPasswordWithToken đặt mật khẩu mới bằng link trong email; xong thì đăng xuất mọi thiết bị, mở khóa tạm.
func (s *Service) ResetPasswordWithToken(ctx context.Context, token, email, password, confirmation string) error {
	email, err := checkEmail(email)
	if err != nil {
		return err
	}
	if strings.TrimSpace(token) == "" {
		return errResetToken
	}
	if password == "" {
		return invalid("Vui lòng nhập Mật khẩu.")
	}
	if password != confirmation {
		return invalid("Xác nhận Mật khẩu không khớp.")
	}
	raw, err := s.rdb.Get(ctx, resetTokenKey(token)).Result()
	if errors.Is(err, redis.Nil) {
		return errResetToken
	}
	if err != nil {
		return err
	}
	uid, err := uuid.Parse(raw)
	if err != nil {
		return errResetToken
	}
	u, err := s.q.GetUser(ctx, uid)
	if errors.Is(err, pgx.ErrNoRows) {
		return errResetToken
	}
	if err != nil {
		return err
	}
	if u.Email == nil || !strings.EqualFold(*u.Email, email) || u.Status != "active" {
		return errResetToken
	}
	if msg := CheckPasswordPolicy(password, identifiersOf(u)...); msg != "" {
		return weakPassword(msg)
	}
	hash, err := HashPassword(ctx, password)
	if err != nil {
		return err
	}
	// Xóa token trước: một link chỉ dùng một lần kể cả khi hai request tới cùng lúc.
	if n, err := s.rdb.Del(ctx, resetTokenKey(token)).Result(); err != nil {
		return err
	} else if n == 0 {
		return errResetToken
	}
	s.rdb.Del(ctx, resetUserKey(u.ID))
	if err := s.q.SetPassword(ctx, iamdb.SetPasswordParams{ID: u.ID, PasswordHash: hash, MustChangePassword: false}); err != nil {
		return err
	}
	if err := s.q.UnlockUser(ctx, u.ID); err != nil {
		return err
	}
	s.log.Info("password reset by email", "user_id", u.ID)
	return s.sessions.revokeAll(ctx, u.ID, "")
}

func (h *Handler) forgotPassword(w http.ResponseWriter, r *http.Request) {
	var req struct {
		Email string `json:"email"`
	}
	if !httpx.Decode(w, r, &req) {
		return
	}
	if err := h.svc.RequestPasswordReset(r.Context(), req.Email, httpx.ClientIP(r)); err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, map[string]string{"message": ResetLinkSentMessage})
}

func (h *Handler) resetPassword(w http.ResponseWriter, r *http.Request) {
	var req struct {
		Token                string `json:"token"`
		Email                string `json:"email"`
		Password             string `json:"password"`
		PasswordConfirmation string `json:"password_confirmation"`
	}
	if !httpx.Decode(w, r, &req) {
		return
	}
	if err := h.svc.ResetPasswordWithToken(r.Context(), req.Token, req.Email, req.Password, req.PasswordConfirmation); err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, map[string]string{"message": "Mật khẩu của bạn đã được đặt lại."})
}
