package approvals

import (
	"log/slog"
	"net/http"

	"github.com/go-chi/chi/v5"
	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/httpx"
)

// Anchors: quyền neo của các nguồn duyệt (người không có quyền nào bị chặn ngay ở route). Hộp còn kiểm Admin trong
// service (ApprovalInboxService::allowsModule).
var Anchors = []string{"tuition.approve", "tuition.reject", "invoice.approve_cancel", "refund_transfer.reject",
	"lead.approve_transfer", "student.assign_class", "work_task.create", "work_task.approve", "syllabus.approve_adjustment",
	"big_test.approve", "staff_checkin.approve"}

type Handler struct {
	svc *Service
	log *slog.Logger
}

func NewHandler(svc *Service, log *slog.Logger) *Handler { return &Handler{svc: svc, log: log} }

// Routes: GET /approvals (?group=), GET /approvals/badge, GET /approvals/{source}/{id}, POST /approvals/bulk.
func (h *Handler) Routes(r chi.Router) {
	guard := iam.RequireAny(Anchors...)
	r.Route("/approvals", func(r chi.Router) {
		r.With(guard).Get("/", h.index)
		r.With(guard).Get("/badge", h.badge)
		r.With(guard).Post("/bulk", h.bulk)
		r.With(guard).Get("/{source}/{id}", h.show)
	})
}

func (h *Handler) fail(w http.ResponseWriter, r *http.Request, err error) { iam.Fail(w, r, h.log, err) }

func (h *Handler) index(w http.ResponseWriter, r *http.Request) {
	res, err := h.svc.Index(r.Context(), iam.AccessFrom(r.Context()), r.URL.Query().Get("group"))
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, res)
}

func (h *Handler) badge(w http.ResponseWriter, r *http.Request) {
	n, err := h.svc.Badge(r.Context(), iam.AccessFrom(r.Context()))
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, map[string]any{"count": n})
}

func (h *Handler) show(w http.ResponseWriter, r *http.Request) {
	id, err := uuid.Parse(chi.URLParam(r, "id"))
	if err != nil {
		h.fail(w, r, ErrNotFound)
		return
	}
	res, err := h.svc.Show(r.Context(), iam.AccessFrom(r.Context()), chi.URLParam(r, "source"), id)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, res)
}

func (h *Handler) bulk(w http.ResponseWriter, r *http.Request) {
	var in BulkInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	res, err := h.svc.Bulk(r.Context(), iam.AccessFrom(r.Context()), in)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, res)
}
