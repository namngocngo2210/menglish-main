package tasks

import (
	"log/slog"
	"net/http"
	"strconv"

	"github.com/go-chi/chi/v5"
	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/httpx"
)

type Handler struct {
	svc *Service
	log *slog.Logger
}

func NewHandler(svc *Service, log *slog.Logger) *Handler { return &Handler{svc: svc, log: log} }

// Routes gắn API công việc vào /tasks. Như Laravel: cả nhóm cần work_task.view; giao việc cần thêm work_task.create
// hoặc work_task.request; giao việc cho trợ giảng cần work_task.assign. Xác nhận / trả lại chỉ Admin (service kiểm).
func (h *Handler) Routes(r chi.Router) {
	view := iam.Require("work_task.view")
	creator := iam.RequireAny("work_task.create", "work_task.request")
	assign := iam.Require("work_task.assign")
	r.Route("/tasks", func(r chi.Router) {
		r.With(view).Get("/", h.list)
		r.With(view, creator).Get("/options", h.createOptions)
		r.With(view, creator).Post("/", h.create)
		r.With(view).Get("/approvals", h.approvals)
		r.With(view, assign).Get("/ta-assign/options", h.taAssignOptions)
		r.With(view, assign).Post("/ta-assign", h.taAssign)
		r.With(view).Get("/ta-portal", h.taPortal)
		r.With(view).Get("/{id}", h.get)
		r.With(view).Post("/{id}/status", h.changeStatus)
		r.With(view).Post("/{id}/complete", h.complete)
		r.With(view).Post("/{id}/approve", h.approve)
		r.With(view).Post("/{id}/reject", h.reject)
	})
}

func (h *Handler) fail(w http.ResponseWriter, r *http.Request, err error) { iam.Fail(w, r, h.log, err) }

func actor(r *http.Request) *iam.Access { return iam.AccessFrom(r.Context()) }

func idParam(w http.ResponseWriter, r *http.Request) (uuid.UUID, bool) {
	id, err := uuid.Parse(chi.URLParam(r, "id"))
	if err != nil {
		httpx.Error(w, http.StatusNotFound, ErrNotFound.Code, ErrNotFound.Message)
		return uuid.Nil, false
	}
	return id, true
}

func (h *Handler) list(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	page, _ := strconv.Atoi(q.Get("page"))
	per, _ := strconv.Atoi(q.Get("per_page"))
	res, err := h.svc.List(r.Context(), actor(r), ListFilter{
		Tab: q.Get("tab"), Status: q.Get("status"), TaskType: q.Get("task_type"), Search: q.Get("q"),
		DateFrom: q.Get("date_from"), DateTo: q.Get("date_to"), AssigneeID: q.Get("assignee_id"),
		Page: page, PerPage: per,
	})
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, res)
}

func (h *Handler) createOptions(w http.ResponseWriter, r *http.Request) {
	res, err := h.svc.CreateOptions(r.Context(), actor(r))
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, res)
}

func (h *Handler) create(w http.ResponseWriter, r *http.Request) {
	var in CreateInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	t, err := h.svc.Create(r.Context(), actor(r), in)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusCreated, map[string]any{
		"task": t, "message": "Đã giao việc '" + t.Title + "' thành công cho nhân sự!",
	})
}

func (h *Handler) get(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	t, err := h.svc.Get(r.Context(), actor(r), id)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, t)
}

func (h *Handler) changeStatus(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	var in StatusInput
	if !ok || !httpx.Decode(w, r, &in) {
		return
	}
	t, msg, err := h.svc.ChangeStatus(r.Context(), actor(r), id, in)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, map[string]any{"task": t, "message": msg})
}

func (h *Handler) approvals(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	res, err := h.svc.Approvals(r.Context(), actor(r), q.Get("q"), q.Get("assignee_id"))
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, res)
}

type noteInput struct {
	AdminNote string `json:"admin_note"`
}

func (h *Handler) approve(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	var in noteInput
	if !ok || !httpx.Decode(w, r, &in) {
		return
	}
	msg, err := h.svc.Approve(r.Context(), actor(r), id, in.AdminNote)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, map[string]any{"message": msg})
}

func (h *Handler) reject(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	var in noteInput
	if !ok || !httpx.Decode(w, r, &in) {
		return
	}
	msg, err := h.svc.Reject(r.Context(), actor(r), id, in.AdminNote)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, map[string]any{"message": msg})
}

func (h *Handler) taAssignOptions(w http.ResponseWriter, r *http.Request) {
	res, err := h.svc.TAAssignOptions(r.Context(), actor(r))
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, res)
}

func (h *Handler) taAssign(w http.ResponseWriter, r *http.Request) {
	var in TAAssignInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	res, err := h.svc.TAAssign(r.Context(), actor(r), in)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusCreated, res)
}

func (h *Handler) taPortal(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	res, err := h.svc.TAPortal(r.Context(), actor(r), q.Get("date"), q.Get("ta_id"))
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, res)
}

func (h *Handler) complete(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	var in CompleteInput
	if !ok || !httpx.Decode(w, r, &in) {
		return
	}
	msg, err := h.svc.Complete(r.Context(), actor(r), id, in)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, map[string]any{"message": msg})
}
