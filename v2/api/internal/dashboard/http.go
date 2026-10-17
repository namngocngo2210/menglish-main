package dashboard

import (
	"log/slog"
	"net/http"

	"github.com/go-chi/chi/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/httpx"
)

type Handler struct {
	svc *Service
	log *slog.Logger
}

func NewHandler(svc *Service, log *slog.Logger) *Handler { return &Handler{svc: svc, log: log} }

// Routes: GET /dashboard (Tổng quan — mọi nhân sự; Laravel chỉ cần đăng nhập, học viên / phụ huynh chuyển sang cổng học viên),
// GET /academic/dashboards/reports, /academic/dashboards/incidents (Báo cáo & sự vụ lớp — class.update).
func (h *Handler) Routes(r chi.Router) {
	r.With(iam.RequireAny(PermStaff, PermOperations, PermAcademic)).Get("/dashboard", h.overview)
	// Không mount nhóm /academic: tránh trùng tiền tố với phân hệ Đào tạo khác (giáo trình, dự án học thuật).
	r.With(iam.Require(PermClassManager)).Get("/academic/dashboards/reports", h.reports)
	r.With(iam.Require(PermClassManager)).Get("/academic/dashboards/incidents", h.incidents)
}

func (h *Handler) ok(w http.ResponseWriter, r *http.Request, v any, err error) {
	if err != nil {
		iam.Fail(w, r, h.log, err)
		return
	}
	httpx.JSON(w, http.StatusOK, v)
}

func (h *Handler) overview(w http.ResponseWriter, r *http.Request) {
	out, err := h.svc.Overview(r.Context(), iam.AccessFrom(r.Context()), r.URL.Query().Get("month"))
	h.ok(w, r, out, err)
}

func (h *Handler) reports(w http.ResponseWriter, r *http.Request) {
	out, err := h.svc.AcademicReports(r.Context(), iam.AccessFrom(r.Context()), r.URL.Query().Get("tab"))
	h.ok(w, r, out, err)
}

func (h *Handler) incidents(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	out, err := h.svc.Incidents(r.Context(), iam.AccessFrom(r.Context()), IncidentFilter{
		Severity: q.Get("severity"), Status: q.Get("status"), BranchID: q.Get("branch_id"), ClassID: q.Get("class_id"),
	})
	h.ok(w, r, out, err)
}
