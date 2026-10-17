// Package mailapi là màn "Hàng đợi email" (Cài đặt › Hệ thống): danh sách thư đã xếp, lọc thư lỗi, xem nội dung,
// gửi lại / hủy. Tách khỏi mail vì cần iam (đăng nhập, quyền). Quyền mail_config.manage (Cấu hình SMTP gửi email,
// mặc định Admin) như khối SMTP của màn "Email nhận Ticket".
package mailapi

import (
	"context"
	"errors"
	"log/slog"
	"net/http"
	"strconv"

	"github.com/go-chi/chi/v5"
	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify/mail"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/httpx"
)

func init() {
	audit.RegisterSubject("mail", "Email gửi đi")
	audit.RegisterPath("/mail-outbox", audit.ModuleSystem)
}

const perm = "mail_config.manage"

var (
	errNotFound = &iam.Error{Status: http.StatusNotFound, Code: "not_found", Message: "Không tìm thấy thư."}
	errRetry    = iam.Invalid("Chỉ gửi lại được thư lỗi, bị chặn hoặc đã hủy (thư nhạy cảm đã xóa nội dung thì không gửi lại được).")
	errCancel   = iam.Invalid("Chỉ hủy được thư đang chờ gửi hoặc bị lỗi.")
)

type Handler struct {
	svc *mail.Service
	log *slog.Logger
}

func NewHandler(svc *mail.Service, log *slog.Logger) *Handler { return &Handler{svc: svc, log: log} }

func (h *Handler) Routes(r chi.Router) {
	r.Route("/mail-outbox", func(r chi.Router) {
		r.With(iam.Require(perm)).Get("/", h.list)
		r.With(iam.Require(perm)).Get("/{id}", h.get)
		r.With(iam.Require(perm)).Post("/{id}/retry", h.retry)
		r.With(iam.Require(perm)).Post("/{id}/cancel", h.cancel)
	})
}

type listResponse struct {
	*mail.Page
	Kinds    any `json:"kinds"`
	Statuses any `json:"statuses"`
}

func (h *Handler) list(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	page, _ := strconv.Atoi(q.Get("page"))
	per, _ := strconv.Atoi(q.Get("per_page"))
	out, err := h.svc.List(r.Context(), mail.Filter{Status: q.Get("status"), Kind: q.Get("kind"), Q: q.Get("q"), Page: page, PerPage: per})
	if err != nil {
		iam.Fail(w, r, h.log, err)
		return
	}
	httpx.JSON(w, http.StatusOK, listResponse{Page: out, Kinds: mail.Kinds, Statuses: mail.Statuses})
}

func idParam(w http.ResponseWriter, r *http.Request) (uuid.UUID, bool) {
	id, err := uuid.Parse(chi.URLParam(r, "id"))
	if err != nil {
		httpx.Error(w, http.StatusNotFound, errNotFound.Code, errNotFound.Message)
		return uuid.Nil, false
	}
	return id, true
}

func (h *Handler) get(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	d, err := h.svc.Get(r.Context(), id)
	if errors.Is(err, mail.ErrNotFound) {
		err = errNotFound
	}
	if err != nil {
		iam.Fail(w, r, h.log, err)
		return
	}
	httpx.JSON(w, http.StatusOK, d)
}

func (h *Handler) retry(w http.ResponseWriter, r *http.Request) {
	h.change(w, r, "mail.retry", "Gửi lại email", errRetry, h.svc.Retry, "Đã xếp thư gửi lại.")
}

func (h *Handler) cancel(w http.ResponseWriter, r *http.Request) {
	h.change(w, r, "mail.cancel", "Hủy email trong hàng đợi", errCancel, h.svc.Cancel, "Đã hủy thư.")
}

type changeFn func(ctx context.Context, id uuid.UUID, after func(ctx context.Context, db mail.DB, subject, to string) error) error

func (h *Handler) change(w http.ResponseWriter, r *http.Request, action, desc string, statusErr *iam.Error, fn changeFn, msg string) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	a := iam.AccessFrom(r.Context())
	err := fn(r.Context(), id, func(ctx context.Context, db mail.DB, subject, to string) error {
		return audit.Record(ctx, db, audit.Entry{
			ActorID: &a.UserID, Module: audit.ModuleSystem, Event: action, Action: action,
			SubjectType: "mail", SubjectID: id.String(), SubjectLabel: subject + " → " + to, Description: desc + ": " + subject,
		})
	})
	switch {
	case errors.Is(err, mail.ErrNotFound):
		err = errNotFound
	case errors.Is(err, mail.ErrStatus):
		err = statusErr
	}
	if err != nil {
		iam.Fail(w, r, h.log, err)
		return
	}
	h.log.Info("mail outbox "+action, "id", id, "by", a.UserID)
	httpx.JSON(w, http.StatusOK, map[string]string{"message": msg})
}
