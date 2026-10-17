package files

import (
	"log/slog"
	"net/http"

	"github.com/go-chi/chi/v5"
	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/filestore"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/httpx"
)

// Handler: tải file. Tải lên nằm ở API của từng phân hệ (mỗi chỗ một luật dung lượng / loại file và quyền riêng).
type Handler struct {
	svc *Service
	log *slog.Logger
}

func NewHandler(svc *Service, log *slog.Logger) *Handler {
	if log == nil {
		log = slog.Default()
	}
	return &Handler{svc: svc, log: log}
}

// DownloadRoute: route tải file. Gắn iam.RequireSignedIn (đăng nhập, CSRF, đổi mật khẩu, 2FA bắt buộc) chứ không
// gắn một quyền cố định: quyền do phân hệ sở hữu file quyết định theo từng file (RegisterReader), như
// users.contract.download của Laravel (chính chủ hoặc người quản lý tài khoản).
const DownloadRoute = "/files/{id}"

// Routes: GET /files/{id} → kiểm quyền rồi chuyển (302) sang URL ký sẵn; ?format=json trả {url, name, ...}.
func (h *Handler) Routes(r chi.Router) {
	r.With(iam.RequireSignedIn).Get(DownloadRoute, h.download)
}

// PublicRoutes: GET /files/blob/{token} — nội dung file của driver local, chỉ với token ký còn hạn.
func (h *Handler) PublicRoutes(r chi.Router) {
	r.Get(BlobPrefix+"/{token}", h.blob)
}

func (h *Handler) download(w http.ResponseWriter, r *http.Request) {
	id, err := uuid.Parse(chi.URLParam(r, "id"))
	if err != nil {
		iam.Fail(w, r, h.log, ErrNotFound)
		return
	}
	ctx := r.Context()
	f, err := h.svc.Get(ctx, id)
	if err != nil {
		iam.Fail(w, r, h.log, err)
		return
	}
	name, err := h.svc.Authorize(ctx, iam.AccessFrom(ctx), f)
	if err != nil {
		iam.Fail(w, r, h.log, err)
		return
	}
	u, err := h.svc.SignedURL(ctx, f, 0, name)
	if err != nil {
		iam.Fail(w, r, h.log, err)
		return
	}
	w.Header().Set("Cache-Control", "no-store")
	if r.URL.Query().Get("format") == "json" {
		httpx.JSON(w, http.StatusOK, map[string]any{
			"url": u, "name": name, "size": f.Size, "mime_type": f.MimeType, "expires_in": int(h.svc.ttl.Seconds()),
		})
		return
	}
	http.Redirect(w, r, u, http.StatusFound)
}

func (h *Handler) blob(w http.ResponseWriter, r *http.Request) {
	l, ok := h.svc.store.(*filestore.Local)
	if !ok {
		http.NotFound(w, r)
		return
	}
	l.ServeSigned(w, r, chi.URLParam(r, "token"))
}
