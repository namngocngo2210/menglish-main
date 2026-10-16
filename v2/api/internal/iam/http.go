package iam

import (
	"context"
	"crypto/subtle"
	"errors"
	"log/slog"
	"net/http"
	"net/url"
	"strings"

	"github.com/go-chi/chi/v5"
	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/geo"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam/iamdb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/httpx"
)

// CookieName dùng tiền tố __Host-: trình duyệt chỉ nhận khi Secure, Path=/ và không có Domain.
const CookieName = "__Host-session"

type ctxKey struct{}

type authInfo struct {
	session *Session
	user    iamdb.IamUser
	svc     *Service
	access  *Access // tính khi cần lần đầu trong request
}

func (a *authInfo) loadAccess(ctx context.Context) (*Access, error) {
	if a.access == nil {
		acc, err := a.svc.LoadAccess(ctx, a.user.ID)
		if err != nil {
			return nil, err
		}
		a.access = acc
	}
	return a.access, nil
}

func fromContext(ctx context.Context) *authInfo {
	a, _ := ctx.Value(ctxKey{}).(*authInfo)
	return a
}

// CurrentUserID trả về id người dùng đang đăng nhập (module khác dùng).
func CurrentUserID(ctx context.Context) (uuid.UUID, bool) {
	if a := fromContext(ctx); a != nil {
		return a.user.ID, true
	}
	return uuid.Nil, false
}

// AccessFrom trả tập quyền của người đang đăng nhập; chỉ gọi sau Require (luôn có người dùng).
func AccessFrom(ctx context.Context) *Access {
	if a := fromContext(ctx); a != nil {
		return a.access
	}
	return nil
}

type Handler struct {
	svc *Service
	log *slog.Logger
	geo *geo.Searcher
}

func NewHandler(svc *Service, log *slog.Logger) *Handler { return &Handler{svc: svc, log: log} }

// WithAddressSearch bật tìm địa chỉ cho bản đồ chấm công chi nhánh (không bật → API trả 502).
func (h *Handler) WithAddressSearch(s *geo.Searcher) *Handler {
	h.geo = s
	return h
}

// LoadSession đọc cookie phiên (nếu có) và gắn người dùng vào context. Không chặn request.
func (h *Handler) LoadSession(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		c, err := r.Cookie(CookieName)
		if err != nil || c.Value == "" {
			next.ServeHTTP(w, r)
			return
		}
		sess, u, err := h.svc.Authenticate(r.Context(), c.Value)
		switch {
		case errors.Is(err, errNoSession):
			clearCookie(w)
			next.ServeHTTP(w, r)
		case err != nil:
			httpx.InternalError(w, r, h.log, err)
		default:
			next.ServeHTTP(w, r.WithContext(context.WithValue(r.Context(), ctxKey{}, &authInfo{session: sess, user: u, svc: h.svc})))
		}
	})
}

// RequireAuth chặn request chưa đăng nhập; với phương thức ghi thì kiểm thêm header X-CSRF-Token.
func RequireAuth(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if checkAuth(w, r) != nil {
			next.ServeHTTP(w, r)
		}
	})
}

func checkAuth(w http.ResponseWriter, r *http.Request) *authInfo {
	a := fromContext(r.Context())
	if a == nil {
		writeErr(w, ErrUnauthenticated)
		return nil
	}
	if !isSafeMethod(r.Method) {
		got := r.Header.Get("X-CSRF-Token")
		if subtle.ConstantTimeCompare([]byte(got), []byte(a.session.CSRF)) != 1 {
			httpx.Error(w, http.StatusForbidden, "csrf", "Phiên làm việc không hợp lệ, hãy tải lại trang.")
			return nil
		}
	}
	return a
}

// Require chặn request nếu người dùng không có quyền perm. Một route gắn Require là đủ: middleware này kiểm luôn
// đăng nhập, CSRF, đổi mật khẩu lần đầu và 2FA bắt buộc. Mọi route nghiệp vụ phải gắn Require hoặc RequireAny
// (test TestEveryRouteHasPermission trong cmd/menglish kiểm điều này).
//
// go:noinline giữ một bản duy nhất của hàm trả về để test nhận ra middleware này theo con trỏ hàm.
//
//go:noinline
func Require(perm string) func(http.Handler) http.Handler {
	mustExist(perm)
	return func(next http.Handler) http.Handler {
		return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			if authorize(w, r, perm) {
				next.ServeHTTP(w, r)
			}
		})
	}
}

// RequireAny: như Require nhưng chỉ cần một trong các quyền.
//
//go:noinline
func RequireAny(perms ...string) func(http.Handler) http.Handler {
	mustExist(perms...)
	return func(next http.Handler) http.Handler {
		return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			if authorize(w, r, perms...) {
				next.ServeHTTP(w, r)
			}
		})
	}
}

// mustExist dừng chương trình ngay lúc gắn route nếu tên quyền gõ sai (không ai vào được route đó).
func mustExist(perms ...string) {
	if len(perms) == 0 {
		panic("iam: Require cần ít nhất một quyền")
	}
	for _, p := range perms {
		if GetCatalog().Kind(p) == "" {
			panic("iam: quyền " + p + " không có trong danh mục")
		}
	}
}

func authorize(w http.ResponseWriter, r *http.Request, perms ...string) bool {
	a := checkAuth(w, r)
	if a == nil {
		return false
	}
	if a.user.MustChangePassword {
		writeErr(w, ErrPasswordChangeRequired)
		return false
	}
	acc, err := a.loadAccess(r.Context())
	if err != nil {
		httpx.InternalError(w, r, a.svc.log, err)
		return false
	}
	if a.user.TotpEnabledAt == nil && acc.RequiresTOTP() {
		writeErr(w, ErrTOTPSetupRequired)
		return false
	}
	if !acc.CanAny(perms...) {
		writeErr(w, ErrForbidden)
		return false
	}
	return true
}

// RequirePasswordChanged chặn mọi thao tác khi tài khoản còn phải đổi mật khẩu lần đầu.
func RequirePasswordChanged(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if a := fromContext(r.Context()); a != nil && a.user.MustChangePassword {
			writeErr(w, ErrPasswordChangeRequired)
			return
		}
		next.ServeHTTP(w, r)
	})
}

// SameOrigin từ chối request ghi đến từ trang khác (lớp chống CSRF thứ hai, cùng SameSite=Lax).
func SameOrigin(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if !isSafeMethod(r.Method) {
			origin := r.Header.Get("Origin")
			u, err := url.Parse(origin)
			if origin == "" || err != nil || !strings.EqualFold(u.Host, r.Host) {
				httpx.Error(w, http.StatusForbidden, "bad_origin", "Yêu cầu không hợp lệ.")
				return
			}
		}
		next.ServeHTTP(w, r)
	})
}

func isSafeMethod(m string) bool {
	return m == http.MethodGet || m == http.MethodHead || m == http.MethodOptions
}

// Routes gắn các route /auth vào router /api/v1.
func (h *Handler) Routes(r chi.Router) {
	r.Route("/auth", func(r chi.Router) {
		r.Post("/login", h.login)
		r.Post("/login/totp", h.loginTOTP)

		r.Group(func(r chi.Router) {
			r.Use(RequireAuth)
			r.Get("/me", h.me)
			r.Post("/logout", h.logout)
			r.Post("/password", h.changePassword)

			r.Group(func(r chi.Router) {
				r.Use(RequirePasswordChanged)
				r.Post("/totp/setup", h.totpSetup)
				r.Post("/totp/enable", h.totpEnable)
				r.Post("/totp/disable", h.totpDisable)
				r.Get("/sessions", h.listSessions)
				r.Delete("/sessions/{id}", h.revokeSession)
			})
		})
	})
}

type loginResponse struct {
	Status    string `json:"status"` // "ok" | "totp_required"
	User      *User  `json:"user,omitempty"`
	CSRFToken string `json:"csrf_token,omitempty"`
	Challenge string `json:"challenge,omitempty"`
}

func (h *Handler) login(w http.ResponseWriter, r *http.Request) {
	var req struct {
		Identifier string `json:"identifier"`
		Password   string `json:"password"`
		Remember   bool   `json:"remember"`
	}
	if !httpx.Decode(w, r, &req) {
		return
	}
	res, err := h.svc.Login(r.Context(), LoginInput{
		Identifier: req.Identifier, Password: req.Password, Remember: req.Remember,
		IP: httpx.ClientIP(r), UserAgent: r.UserAgent(),
	})
	if err != nil {
		h.fail(w, r, err)
		return
	}
	h.respondLogin(w, res)
}

func (h *Handler) loginTOTP(w http.ResponseWriter, r *http.Request) {
	var req struct {
		Challenge string `json:"challenge"`
		Code      string `json:"code"`
	}
	if !httpx.Decode(w, r, &req) {
		return
	}
	res, err := h.svc.VerifyLoginTOTP(r.Context(), req.Challenge, req.Code, httpx.ClientIP(r), r.UserAgent())
	if err != nil {
		h.fail(w, r, err)
		return
	}
	h.respondLogin(w, res)
}

func (h *Handler) respondLogin(w http.ResponseWriter, res *LoginResult) {
	if res.Challenge != "" {
		httpx.JSON(w, http.StatusOK, loginResponse{Status: "totp_required", Challenge: res.Challenge})
		return
	}
	c := &http.Cookie{
		Name: CookieName, Value: res.Token, Path: "/",
		HttpOnly: true, Secure: true, SameSite: http.SameSiteLaxMode,
	}
	if res.Session.Remember {
		c.MaxAge = int(RememberSessionTTL.Seconds())
	}
	http.SetCookie(w, c)
	httpx.JSON(w, http.StatusOK, loginResponse{Status: "ok", User: &res.User, CSRFToken: res.Session.CSRF})
}

func clearCookie(w http.ResponseWriter) {
	http.SetCookie(w, &http.Cookie{
		Name: CookieName, Value: "", Path: "/", MaxAge: -1,
		HttpOnly: true, Secure: true, SameSite: http.SameSiteLaxMode,
	})
}

type meResponse struct {
	User              User              `json:"user"`
	CSRFToken         string            `json:"csrf_token"`
	RecoveryCodesLeft *int64            `json:"recovery_codes_left,omitempty"`
	TOTPRequired      bool              `json:"totp_required"`
	Roles             []RoleRef         `json:"roles"`
	Permissions       []string          `json:"permissions"`
	Scopes            map[string]string `json:"scopes"`
	Branches          []UserBranch      `json:"branches"`
}

// RoleRef là vai trò kèm nhãn hiển thị.
type RoleRef struct {
	Name       string `json:"name"`
	Label      string `json:"label"`
	ShortLabel string `json:"short_label"`
}

func (h *Handler) me(w http.ResponseWriter, r *http.Request) {
	a := fromContext(r.Context())
	resp := meResponse{User: toUser(a.user), CSRFToken: a.session.CSRF}
	if a.user.TotpEnabledAt != nil {
		n, err := h.svc.RecoveryCodesLeft(r.Context(), a.user.ID)
		if err != nil {
			httpx.InternalError(w, r, h.log, err)
			return
		}
		resp.RecoveryCodesLeft = &n
	}
	acc, err := a.loadAccess(r.Context())
	if err == nil {
		resp.Roles, err = h.svc.roleRefs(r.Context(), acc.Roles)
	}
	if err != nil {
		httpx.InternalError(w, r, h.log, err)
		return
	}
	resp.TOTPRequired = acc.RequiresTOTP()
	resp.Permissions = acc.Permissions()
	resp.Scopes = acc.Scopes()
	resp.Branches = acc.Branches
	httpx.JSON(w, http.StatusOK, resp)
}

func (h *Handler) logout(w http.ResponseWriter, r *http.Request) {
	if err := h.svc.Logout(r.Context(), fromContext(r.Context()).session); err != nil {
		httpx.InternalError(w, r, h.log, err)
		return
	}
	clearCookie(w)
	w.WriteHeader(http.StatusNoContent)
}

func (h *Handler) changePassword(w http.ResponseWriter, r *http.Request) {
	var req struct {
		Current string `json:"current_password"`
		New     string `json:"new_password"`
	}
	if !httpx.Decode(w, r, &req) {
		return
	}
	if err := h.svc.ChangePassword(r.Context(), fromContext(r.Context()).session, req.Current, req.New); err != nil {
		h.fail(w, r, err)
		return
	}
	w.WriteHeader(http.StatusNoContent)
}

func (h *Handler) totpSetup(w http.ResponseWriter, r *http.Request) {
	setup, err := h.svc.SetupTOTP(r.Context(), fromContext(r.Context()).session)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, setup)
}

func (h *Handler) totpEnable(w http.ResponseWriter, r *http.Request) {
	var req struct {
		Code string `json:"code"`
	}
	if !httpx.Decode(w, r, &req) {
		return
	}
	codes, err := h.svc.EnableTOTP(r.Context(), fromContext(r.Context()).session, req.Code)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, map[string][]string{"recovery_codes": codes})
}

func (h *Handler) totpDisable(w http.ResponseWriter, r *http.Request) {
	var req struct {
		Password string `json:"password"`
	}
	if !httpx.Decode(w, r, &req) {
		return
	}
	if err := h.svc.DisableTOTP(r.Context(), fromContext(r.Context()).session, req.Password); err != nil {
		h.fail(w, r, err)
		return
	}
	w.WriteHeader(http.StatusNoContent)
}

func (h *Handler) listSessions(w http.ResponseWriter, r *http.Request) {
	list, err := h.svc.ListSessions(r.Context(), fromContext(r.Context()).session)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, map[string]any{"sessions": list})
}

func (h *Handler) revokeSession(w http.ResponseWriter, r *http.Request) {
	if err := h.svc.RevokeSession(r.Context(), fromContext(r.Context()).session, chi.URLParam(r, "id")); err != nil {
		h.fail(w, r, err)
		return
	}
	w.WriteHeader(http.StatusNoContent)
}

func (h *Handler) fail(w http.ResponseWriter, r *http.Request, err error) {
	var e *Error
	if errors.As(err, &e) {
		writeErr(w, e)
		return
	}
	httpx.InternalError(w, r, h.log, err)
}

// Fail trả lỗi cho module khác: *Error (kể cả bọc) → đúng mã và thông báo; lỗi khác → 500 và ghi log.
func Fail(w http.ResponseWriter, r *http.Request, log *slog.Logger, err error) {
	var e *Error
	if errors.As(err, &e) {
		writeErr(w, e)
		return
	}
	httpx.InternalError(w, r, log, err)
}

// Invalid: lỗi 422 "invalid" với thông báo tiếng Việt cho người dùng.
func Invalid(msg string) *Error { return invalid(msg) }

func writeErr(w http.ResponseWriter, e *Error) {
	httpx.Error(w, e.Status, e.Code, e.Message)
}
