// Package iam lo đăng nhập, phiên, 2FA và (bước sau) vai trò, quyền, phạm vi dữ liệu.
package iam

import (
	"context"
	"encoding/json"
	"errors"
	"log/slog"
	"regexp"
	"strconv"
	"strings"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgxpool"
	"github.com/redis/go-redis/v9"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam/iamdb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/secretbox"
)

// Error là lỗi nghiệp vụ trả thẳng cho giao diện: Code cho máy, Message tiếng Việt cho người.
type Error struct {
	Status  int
	Code    string
	Message string
}

func (e *Error) Error() string { return e.Code }

var (
	ErrInvalidCredentials = &Error{401, "invalid_credentials", "Sai thông tin đăng nhập."}
	ErrTooManyAttempts    = &Error{429, "too_many_attempts", "Bạn đã thử quá nhiều lần. Vui lòng thử lại sau ít phút."}
	ErrInvalidCode        = &Error{401, "invalid_code", "Mã xác thực không đúng."}
	ErrChallengeExpired   = &Error{401, "challenge_expired", "Phiên xác thực đã hết hạn, vui lòng đăng nhập lại."}
	ErrWrongPassword      = &Error{422, "wrong_password", "Mật khẩu hiện tại không đúng."}
	ErrTOTPAlreadyEnabled = &Error{409, "totp_already_enabled", "Xác thực hai bước đang bật."}
	ErrTOTPNotEnabled     = &Error{409, "totp_not_enabled", "Xác thực hai bước chưa bật."}
	ErrTOTPSetupExpired   = &Error{409, "totp_setup_expired", "Mã QR đã hết hạn, hãy tạo lại."}
	ErrSessionNotFound    = &Error{404, "session_not_found", "Không tìm thấy phiên đăng nhập."}
	ErrUnauthenticated    = &Error{401, "unauthenticated", "Bạn cần đăng nhập."}
)

func weakPassword(msg string) *Error { return &Error{422, "weak_password", msg} }

type Service struct {
	pool     *pgxpool.Pool
	q        *iamdb.Queries
	rdb      *redis.Client
	sessions sessionStore
	limit    limiter
	box      *secretbox.Box
	log      *slog.Logger
	now      func() time.Time
}

func NewService(pool *pgxpool.Pool, rdb *redis.Client, box *secretbox.Box, log *slog.Logger) *Service {
	return &Service{
		pool: pool, q: iamdb.New(pool), rdb: rdb,
		sessions: sessionStore{rdb}, limit: limiter{rdb},
		box: box, log: log, now: time.Now,
	}
}

// User là thông tin tài khoản trả cho giao diện (không có băm mật khẩu, khóa TOTP).
type User struct {
	ID                 uuid.UUID `json:"id"`
	Kind               string    `json:"kind"`
	Code               *string   `json:"code"`
	Email              *string   `json:"email"`
	Phone              *string   `json:"phone"`
	FullName           string    `json:"full_name"`
	MustChangePassword bool      `json:"must_change_password"`
	TOTPEnabled        bool      `json:"totp_enabled"`
}

func toUser(u iamdb.IamUser) User {
	return User{
		ID: u.ID, Kind: u.Kind, Code: u.Code, Email: u.Email, Phone: u.Phone, FullName: u.FullName,
		MustChangePassword: u.MustChangePassword, TOTPEnabled: u.TotpEnabledAt != nil,
	}
}

func identifiersOf(u iamdb.IamUser) []string {
	var out []string
	for _, p := range []*string{u.Code, u.Email, u.Phone} {
		if p != nil {
			out = append(out, *p)
		}
	}
	if u.Email != nil {
		if local, _, ok := strings.Cut(*u.Email, "@"); ok && len(local) >= 4 {
			out = append(out, local)
		}
	}
	return out
}

var phoneRe = regexp.MustCompile(`^0[0-9]{8,10}$`)

// NormalizePhone đưa SĐT về dạng 0xxxxxxxxx; trả về "" nếu không phải SĐT.
func NormalizePhone(s string) string {
	s = strings.NewReplacer(" ", "", ".", "", "-", "", "(", "", ")", "").Replace(strings.TrimSpace(s))
	if rest, ok := strings.CutPrefix(s, "+84"); ok {
		s = "0" + rest
	} else if rest, ok := strings.CutPrefix(s, "84"); ok && len(s) == 11 {
		s = "0" + rest
	}
	if phoneRe.MatchString(s) {
		return s
	}
	return ""
}

// findUser tìm theo email, SĐT phụ huynh hoặc mã (nhân sự MES001, học viên HV-00001).
func (s *Service) findUser(ctx context.Context, identifier string) (iamdb.IamUser, string, error) {
	id := strings.TrimSpace(identifier)
	switch {
	case strings.Contains(id, "@"):
		key := strings.ToLower(id)
		u, err := s.q.GetUserByEmail(ctx, &key)
		return u, key, err
	case NormalizePhone(id) != "":
		key := NormalizePhone(id)
		u, err := s.q.GetGuardianByPhone(ctx, &key)
		return u, key, err
	default:
		key := strings.ToUpper(id)
		u, err := s.q.GetUserByCode(ctx, &key)
		return u, key, err
	}
}

type LoginInput struct {
	Identifier string
	Password   string
	Remember   bool
	IP         string
	UserAgent  string
}

// LoginResult: hoặc có phiên (Token, Session), hoặc cần bước 2FA (Challenge).
type LoginResult struct {
	User      User
	Token     string
	Session   *Session
	Challenge string
}

func (s *Service) Login(ctx context.Context, in LoginInput) (*LoginResult, error) {
	id := strings.TrimSpace(in.Identifier)
	if id == "" || in.Password == "" || len(id) > 200 || len(in.Password) > MaxPasswordLen*4 {
		return nil, ErrInvalidCredentials
	}
	u, acct, err := s.findUser(ctx, id)
	if blocked, berr := s.limit.blocked(ctx, acct, in.IP); berr != nil {
		return nil, berr
	} else if blocked {
		return nil, ErrTooManyAttempts
	}
	if errors.Is(err, pgx.ErrNoRows) {
		// Vẫn băm để thời gian trả lời giống tài khoản có thật.
		_, _ = VerifyPassword(ctx, in.Password, dummyHash())
		_ = s.limit.fail(ctx, acct, in.IP)
		s.log.Info("login failed", "reason", "unknown_account", "ip", in.IP)
		return nil, ErrInvalidCredentials
	}
	if err != nil {
		return nil, err
	}
	if u.LockedUntil != nil && u.LockedUntil.After(s.now()) {
		return nil, ErrTooManyAttempts
	}

	ok, err := VerifyPassword(ctx, in.Password, u.PasswordHash)
	if err != nil {
		return nil, err
	}
	if !ok {
		_ = s.limit.fail(ctx, acct, in.IP)
		locked, err := s.q.RecordLoginFailure(ctx, u.ID)
		if err != nil {
			return nil, err
		}
		if locked != nil {
			// TODO(giai đoạn 0, bước 5): ghi audit_log và báo Admin qua email/Zalo.
			s.log.Warn("account locked", "user_id", u.ID, "until", locked, "ip", in.IP)
		}
		s.log.Info("login failed", "reason", "wrong_password", "user_id", u.ID, "ip", in.IP)
		return nil, ErrInvalidCredentials
	}
	if u.Status != "active" {
		s.log.Info("login failed", "reason", "disabled", "user_id", u.ID, "ip", in.IP)
		return nil, ErrInvalidCredentials
	}
	if err := s.q.RecordLoginSuccess(ctx, u.ID); err != nil {
		return nil, err
	}
	_ = s.limit.reset(ctx, acct, in.IP)

	if u.TotpEnabledAt != nil {
		challenge, err := s.newChallenge(ctx, u.ID, in.Remember)
		if err != nil {
			return nil, err
		}
		return &LoginResult{User: toUser(u), Challenge: challenge}, nil
	}
	return s.startSession(ctx, u, in.Remember, in.IP, in.UserAgent)
}

func (s *Service) startSession(ctx context.Context, u iamdb.IamUser, remember bool, ip, ua string) (*LoginResult, error) {
	sess := &Session{UserID: u.ID, Remember: remember, IP: ip, UserAgent: truncate(ua, 300)}
	token, err := s.sessions.create(ctx, sess)
	if err != nil {
		return nil, err
	}
	s.log.Info("login ok", "user_id", u.ID, "ip", ip)
	return &LoginResult{User: toUser(u), Token: token, Session: sess}, nil
}

type challenge struct {
	UserID   uuid.UUID `json:"uid"`
	Remember bool      `json:"remember"`
}

func challengeKey(token string) string { return "totp_challenge:" + hashToken(token) }

func (s *Service) newChallenge(ctx context.Context, uid uuid.UUID, remember bool) (string, error) {
	token := randomToken()
	data, _ := json.Marshal(challenge{uid, remember})
	return token, s.rdb.Set(ctx, challengeKey(token), data, totpChallengeTTL).Err()
}

// VerifyLoginTOTP là bước 2 khi tài khoản bật 2FA: nhận mã 6 số hoặc mã dự phòng.
func (s *Service) VerifyLoginTOTP(ctx context.Context, token, code, ip, ua string) (*LoginResult, error) {
	key := challengeKey(token)
	data, err := s.rdb.Get(ctx, key).Bytes()
	if errors.Is(err, redis.Nil) || token == "" {
		return nil, ErrChallengeExpired
	}
	if err != nil {
		return nil, err
	}
	attempts, err := s.rdb.Incr(ctx, key+":attempts").Result()
	if err != nil {
		return nil, err
	}
	s.rdb.Expire(ctx, key+":attempts", totpChallengeTTL)
	if attempts > maxTOTPAttempts {
		s.rdb.Del(ctx, key, key+":attempts")
		return nil, ErrTooManyAttempts
	}
	var ch challenge
	if err := json.Unmarshal(data, &ch); err != nil {
		return nil, err
	}
	u, err := s.q.GetUser(ctx, ch.UserID)
	if err != nil {
		return nil, err
	}
	if u.Status != "active" || u.TotpEnabledAt == nil {
		s.rdb.Del(ctx, key, key+":attempts")
		return nil, ErrChallengeExpired
	}
	if ok, err := s.checkSecondFactor(ctx, u, code); err != nil {
		return nil, err
	} else if !ok {
		s.log.Info("totp failed", "user_id", u.ID, "ip", ip)
		return nil, ErrInvalidCode
	}
	s.rdb.Del(ctx, key, key+":attempts")
	return s.startSession(ctx, u, ch.Remember, ip, ua)
}

func (s *Service) checkSecondFactor(ctx context.Context, u iamdb.IamUser, code string) (bool, error) {
	if looksLikeRecoveryCode(code) {
		n, err := s.q.UseRecoveryCode(ctx, iamdb.UseRecoveryCodeParams{UserID: u.ID, CodeHash: hashRecoveryCode(code)})
		if err == nil && n == 1 {
			s.log.Info("recovery code used", "user_id", u.ID)
		}
		return n == 1, err
	}
	secret, err := s.box.Open(u.TotpSecret, u.ID[:])
	if err != nil {
		return false, err
	}
	return s.consumeTOTP(ctx, u.ID, string(secret), code)
}

// consumeTOTP kiểm mã và đánh dấu bước thời gian đã dùng: cùng một mã không dùng được hai lần.
func (s *Service) consumeTOTP(ctx context.Context, uid uuid.UUID, secret, code string) (bool, error) {
	step, ok := matchTOTP(secret, code, s.now())
	if !ok {
		return false, nil
	}
	fresh, err := s.rdb.SetNX(ctx, "totp_used:"+uid.String()+":"+strconv.FormatInt(step, 10), 1, 3*totpPeriod*time.Second).Result()
	return fresh, err
}

// Authenticate đọc phiên từ token cookie và kiểm tài khoản còn hoạt động (mỗi request).
func (s *Service) Authenticate(ctx context.Context, token string) (*Session, iamdb.IamUser, error) {
	sess, err := s.sessions.get(ctx, token)
	if err != nil {
		return nil, iamdb.IamUser{}, err
	}
	u, err := s.q.GetUser(ctx, sess.UserID)
	if errors.Is(err, pgx.ErrNoRows) || (err == nil && u.Status != "active") {
		_ = s.sessions.delete(ctx, sess.UserID, sess.ID)
		return nil, iamdb.IamUser{}, errNoSession
	}
	if err != nil {
		return nil, iamdb.IamUser{}, err
	}
	return sess, u, nil
}

func (s *Service) Logout(ctx context.Context, sess *Session) error {
	return s.sessions.delete(ctx, sess.UserID, sess.ID)
}

// ChangePassword đổi mật khẩu và đăng xuất mọi thiết bị khác.
func (s *Service) ChangePassword(ctx context.Context, sess *Session, current, next string) error {
	u, err := s.q.GetUser(ctx, sess.UserID)
	if err != nil {
		return err
	}
	ok, err := VerifyPassword(ctx, current, u.PasswordHash)
	if err != nil {
		return err
	}
	if !ok {
		return ErrWrongPassword
	}
	if current == next {
		return weakPassword("Mật khẩu mới phải khác mật khẩu hiện tại.")
	}
	if msg := CheckPasswordPolicy(next, identifiersOf(u)...); msg != "" {
		return weakPassword(msg)
	}
	hash, err := HashPassword(ctx, next)
	if err != nil {
		return err
	}
	if err := s.q.SetPassword(ctx, iamdb.SetPasswordParams{ID: u.ID, PasswordHash: hash, MustChangePassword: false}); err != nil {
		return err
	}
	s.log.Info("password changed", "user_id", u.ID)
	return s.sessions.revokeAll(ctx, u.ID, sess.ID)
}

type TOTPSetup struct {
	Secret string `json:"secret"`
	URL    string `json:"otpauth_url"`
}

func totpSetupKey(uid uuid.UUID) string { return "totp_setup:" + uid.String() }

// SetupTOTP tạo khóa mới chờ xác nhận (10 phút); chỉ lưu vào DB khi người dùng nhập đúng mã đầu tiên.
func (s *Service) SetupTOTP(ctx context.Context, sess *Session) (*TOTPSetup, error) {
	u, err := s.q.GetUser(ctx, sess.UserID)
	if err != nil {
		return nil, err
	}
	if u.TotpEnabledAt != nil {
		return nil, ErrTOTPAlreadyEnabled
	}
	account := u.FullName
	if ids := identifiersOf(u); len(ids) > 0 {
		account = ids[0]
	}
	secret, url, err := newTOTPKey(account)
	if err != nil {
		return nil, err
	}
	sealed := s.box.Seal([]byte(secret), u.ID[:])
	if err := s.rdb.Set(ctx, totpSetupKey(u.ID), sealed, 10*time.Minute).Err(); err != nil {
		return nil, err
	}
	return &TOTPSetup{Secret: secret, URL: url}, nil
}

// EnableTOTP xác nhận mã đầu tiên, lưu khóa và trả về 10 mã dự phòng (chỉ hiện một lần).
func (s *Service) EnableTOTP(ctx context.Context, sess *Session, code string) ([]string, error) {
	sealed, err := s.rdb.Get(ctx, totpSetupKey(sess.UserID)).Bytes()
	if errors.Is(err, redis.Nil) {
		return nil, ErrTOTPSetupExpired
	}
	if err != nil {
		return nil, err
	}
	secret, err := s.box.Open(sealed, sess.UserID[:])
	if err != nil {
		return nil, err
	}
	if ok, err := s.consumeTOTP(ctx, sess.UserID, string(secret), code); err != nil {
		return nil, err
	} else if !ok {
		return nil, ErrInvalidCode
	}
	codes, hashes := newRecoveryCodes()
	err = pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
		q := s.q.WithTx(tx)
		u, err := q.GetUser(ctx, sess.UserID)
		if err != nil {
			return err
		}
		if u.TotpEnabledAt != nil {
			return ErrTOTPAlreadyEnabled
		}
		if err := q.EnableTOTP(ctx, iamdb.EnableTOTPParams{ID: u.ID, TotpSecret: sealed}); err != nil {
			return err
		}
		if err := q.DeleteRecoveryCodes(ctx, u.ID); err != nil {
			return err
		}
		for _, h := range hashes {
			if err := q.InsertRecoveryCode(ctx, iamdb.InsertRecoveryCodeParams{UserID: u.ID, CodeHash: h}); err != nil {
				return err
			}
		}
		return nil
	})
	if err != nil {
		return nil, err
	}
	s.rdb.Del(ctx, totpSetupKey(sess.UserID))
	s.log.Info("totp enabled", "user_id", sess.UserID)
	return codes, nil
}

// DisableTOTP tắt 2FA sau khi nhập lại mật khẩu.
// TODO(giai đoạn 0, bước 2): chặn tắt với Admin và nhóm quyền cao (bắt buộc 2FA).
func (s *Service) DisableTOTP(ctx context.Context, sess *Session, password string) error {
	u, err := s.q.GetUser(ctx, sess.UserID)
	if err != nil {
		return err
	}
	if u.TotpEnabledAt == nil {
		return ErrTOTPNotEnabled
	}
	ok, err := VerifyPassword(ctx, password, u.PasswordHash)
	if err != nil {
		return err
	}
	if !ok {
		return ErrWrongPassword
	}
	err = pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
		q := s.q.WithTx(tx)
		if err := q.DisableTOTP(ctx, u.ID); err != nil {
			return err
		}
		return q.DeleteRecoveryCodes(ctx, u.ID)
	})
	if err == nil {
		s.log.Info("totp disabled", "user_id", u.ID)
	}
	return err
}

func (s *Service) RecoveryCodesLeft(ctx context.Context, uid uuid.UUID) (int64, error) {
	return s.q.CountUnusedRecoveryCodes(ctx, uid)
}

type SessionInfo struct {
	ID        string    `json:"id"`
	Current   bool      `json:"current"`
	CreatedAt time.Time `json:"created_at"`
	LastSeen  time.Time `json:"last_seen"`
	IP        string    `json:"ip"`
	UserAgent string    `json:"user_agent"`
}

func (s *Service) ListSessions(ctx context.Context, sess *Session) ([]SessionInfo, error) {
	all, err := s.sessions.list(ctx, sess.UserID)
	if err != nil {
		return nil, err
	}
	out := make([]SessionInfo, 0, len(all))
	for _, x := range all {
		out = append(out, SessionInfo{
			ID: x.ID, Current: x.ID == sess.ID, CreatedAt: x.CreatedAt, LastSeen: x.LastSeen, IP: x.IP, UserAgent: x.UserAgent,
		})
	}
	return out, nil
}

// RevokeSession đăng xuất một thiết bị của chính người dùng.
func (s *Service) RevokeSession(ctx context.Context, sess *Session, id string) error {
	member, err := s.rdb.SIsMember(ctx, userSessionsKey(sess.UserID), id).Result()
	if err != nil {
		return err
	}
	if !member {
		return ErrSessionNotFound
	}
	return s.sessions.delete(ctx, sess.UserID, id)
}

type NewUser struct {
	Kind     string
	Code     string
	Email    string
	Phone    string
	FullName string
}

// CreateUser tạo tài khoản với mật khẩu tạm (bắt đổi ở lần đăng nhập đầu); trả về mật khẩu tạm để giao cho người dùng.
func (s *Service) CreateUser(ctx context.Context, in NewUser) (User, string, error) {
	password := RandomPassword()
	hash, err := HashPassword(ctx, password)
	if err != nil {
		return User{}, "", err
	}
	u, err := s.q.CreateUser(ctx, iamdb.CreateUserParams{
		Kind: in.Kind, Code: upperOrNil(in.Code), Email: lowerOrNil(in.Email),
		Phone: phoneOrNil(in.Phone), FullName: strings.TrimSpace(in.FullName), PasswordHash: hash,
	})
	if err != nil {
		return User{}, "", err
	}
	return toUser(u), password, nil
}

// ResetPassword cấp mật khẩu tạm mới, bắt đổi khi đăng nhập, mở khóa và đăng xuất mọi thiết bị.
func (s *Service) ResetPassword(ctx context.Context, identifier string) (User, string, error) {
	u, _, err := s.findUser(ctx, identifier)
	if err != nil {
		return User{}, "", err
	}
	password := RandomPassword()
	hash, err := HashPassword(ctx, password)
	if err != nil {
		return User{}, "", err
	}
	if err := s.q.SetPassword(ctx, iamdb.SetPasswordParams{ID: u.ID, PasswordHash: hash, MustChangePassword: true}); err != nil {
		return User{}, "", err
	}
	if err := s.q.UnlockUser(ctx, u.ID); err != nil {
		return User{}, "", err
	}
	if err := s.sessions.revokeAll(ctx, u.ID, ""); err != nil {
		return User{}, "", err
	}
	u.MustChangePassword = true
	return toUser(u), password, nil
}

func upperOrNil(s string) *string {
	if s = strings.ToUpper(strings.TrimSpace(s)); s == "" {
		return nil
	}
	return &s
}

func lowerOrNil(s string) *string {
	if s = strings.ToLower(strings.TrimSpace(s)); s == "" {
		return nil
	}
	return &s
}

func phoneOrNil(s string) *string {
	if s = NormalizePhone(s); s == "" {
		return nil
	}
	return &s
}

func truncate(s string, n int) string {
	if len(s) <= n {
		return s
	}
	return s[:n]
}
