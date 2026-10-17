package projects

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

// Routes: /academic-projects/* (route academic-projects.* của Laravel). Cả nhóm cần academic_project.view; báo cáo cần
// view_all; tạo / sửa / xóa / chốt / trạng thái / mốc cần manage. Cập nhật tiến độ & phản hồi kiểm tra trong service
// (người tham gia dự án / người phụ trách).
func (h *Handler) Routes(r chi.Router) {
	r.Route("/academic-projects", func(r chi.Router) {
		r.Use(iam.Require(PermView))
		r.Get("/", h.list)
		r.With(iam.Require(PermViewAll)).Get("/report", h.report)
		r.With(iam.Require(PermManage)).Post("/", h.create)
		r.Get("/{id}", h.get)
		r.With(iam.Require(PermManage)).Put("/{id}", h.update)
		r.With(iam.Require(PermManage)).Delete("/{id}", h.delete)
		r.With(iam.Require(PermManage)).Post("/{id}/lock", h.lock)
		r.With(iam.Require(PermManage)).Post("/{id}/status", h.status)
		r.With(iam.Require(PermManage)).Post("/{id}/milestones", h.createMilestone)
		r.With(iam.Require(PermManage)).Put("/milestones/{id}", h.updateMilestone)
		r.With(iam.Require(PermManage)).Delete("/milestones/{id}", h.deleteMilestone)
		r.Post("/{id}/updates", h.createUpdate)
		r.Post("/updates/{id}/respond", h.respond)
	})
}

func (h *Handler) fail(w http.ResponseWriter, r *http.Request, err error) { iam.Fail(w, r, h.log, err) }

func actor(r *http.Request) *iam.Access { return iam.AccessFrom(r.Context()) }

func qInt(r *http.Request, key string) int {
	n, _ := strconv.Atoi(r.URL.Query().Get(key))
	return n
}

func (h *Handler) message(w http.ResponseWriter, r *http.Request, status int, msg string, err error) {
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, status, map[string]any{"message": msg})
}

// pathID: id trên đường dẫn; sai định dạng thì trả lỗi notFound.
func (h *Handler) pathID(w http.ResponseWriter, r *http.Request, notFound error) (uuid.UUID, bool) {
	id, err := uuid.Parse(chi.URLParam(r, "id"))
	if err != nil {
		h.fail(w, r, notFound)
		return uuid.Nil, false
	}
	return id, true
}

func (h *Handler) list(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	out, err := h.svc.List(r.Context(), actor(r), ProjectsFilter{
		Search: q.Get("search"), Type: q.Get("type"), Status: q.Get("status"), OwnerID: q.Get("owner_id"),
		Page: qInt(r, "page"), PerPage: qInt(r, "per_page"),
	})
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, out)
}

func (h *Handler) report(w http.ResponseWriter, r *http.Request) {
	out, err := h.svc.Report(r.Context(), actor(r), r.URL.Query().Get("month"), r.URL.Query().Get("status"))
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, out)
}

func (h *Handler) get(w http.ResponseWriter, r *http.Request) {
	id, ok := h.pathID(w, r, errProjectNotFound)
	if !ok {
		return
	}
	out, err := h.svc.Get(r.Context(), actor(r), id)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, out)
}

func (h *Handler) create(w http.ResponseWriter, r *http.Request) {
	var in ProjectInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	id, msg, err := h.svc.Create(r.Context(), actor(r), in)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusCreated, map[string]any{"id": id, "message": msg})
}

func (h *Handler) update(w http.ResponseWriter, r *http.Request) {
	id, ok := h.pathID(w, r, errProjectNotFound)
	if !ok {
		return
	}
	var in ProjectInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	msg, err := h.svc.Update(r.Context(), actor(r), id, in)
	h.message(w, r, http.StatusOK, msg, err)
}

func (h *Handler) delete(w http.ResponseWriter, r *http.Request) {
	id, ok := h.pathID(w, r, errProjectNotFound)
	if !ok {
		return
	}
	msg, err := h.svc.Delete(r.Context(), actor(r), id)
	h.message(w, r, http.StatusOK, msg, err)
}

func (h *Handler) lock(w http.ResponseWriter, r *http.Request) {
	id, ok := h.pathID(w, r, errProjectNotFound)
	if !ok {
		return
	}
	msg, err := h.svc.Lock(r.Context(), actor(r), id)
	h.message(w, r, http.StatusOK, msg, err)
}

func (h *Handler) status(w http.ResponseWriter, r *http.Request) {
	id, ok := h.pathID(w, r, errProjectNotFound)
	if !ok {
		return
	}
	var in struct {
		Action string `json:"action"`
	}
	if !httpx.Decode(w, r, &in) {
		return
	}
	msg, err := h.svc.ChangeStatus(r.Context(), actor(r), id, in.Action)
	h.message(w, r, http.StatusOK, msg, err)
}

func (h *Handler) createMilestone(w http.ResponseWriter, r *http.Request) {
	id, ok := h.pathID(w, r, errProjectNotFound)
	if !ok {
		return
	}
	var in MilestoneInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	msg, err := h.svc.CreateMilestone(r.Context(), actor(r), id, in)
	h.message(w, r, http.StatusCreated, msg, err)
}

func (h *Handler) updateMilestone(w http.ResponseWriter, r *http.Request) {
	id, ok := h.pathID(w, r, errMilestoneNotFound)
	if !ok {
		return
	}
	var in MilestoneInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	msg, err := h.svc.UpdateMilestone(r.Context(), actor(r), id, in)
	h.message(w, r, http.StatusOK, msg, err)
}

func (h *Handler) deleteMilestone(w http.ResponseWriter, r *http.Request) {
	id, ok := h.pathID(w, r, errMilestoneNotFound)
	if !ok {
		return
	}
	msg, err := h.svc.DeleteMilestone(r.Context(), actor(r), id)
	h.message(w, r, http.StatusOK, msg, err)
}

func (h *Handler) createUpdate(w http.ResponseWriter, r *http.Request) {
	id, ok := h.pathID(w, r, errProjectNotFound)
	if !ok {
		return
	}
	var in UpdateInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	msg, err := h.svc.CreateUpdate(r.Context(), actor(r), id, in)
	h.message(w, r, http.StatusCreated, msg, err)
}

func (h *Handler) respond(w http.ResponseWriter, r *http.Request) {
	id, ok := h.pathID(w, r, errUpdateNotFound)
	if !ok {
		return
	}
	var in struct {
		Response *string `json:"response"`
	}
	if !httpx.Decode(w, r, &in) {
		return
	}
	msg, err := h.svc.Respond(r.Context(), actor(r), id, in.Response)
	h.message(w, r, http.StatusOK, msg, err)
}
