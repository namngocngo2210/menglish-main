package quality

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

// Routes: /class-quality/* (route class-quality.* của Laravel: nhóm can:class_quality.view, ghi thêm quyền từng màn).
func (h *Handler) Routes(r chi.Router) {
	r.Route("/class-quality", func(r chi.Router) {
		r.Use(iam.Require(PermView))
		r.Get("/operations", h.operations)
		r.With(iam.Require(PermObserveOperations)).Post("/operations", h.createOperation)
		r.With(iam.Require(PermObserveOperations)).Put("/operations/{id}", h.updateOperation)
		r.With(iam.Require(PermObserveOperations)).Delete("/operations/{id}", h.deleteOperation)
		r.Get("/academic", h.academic)
		r.With(iam.Require(PermObserveAcademic)).Put("/academic", h.saveAcademic)
		r.Get("/checklist", h.checklist)
		r.With(iam.Require(PermChecklist)).Put("/checklist", h.saveChecklist)
		r.Get("/teacher-meetings", h.meetings)
		r.With(iam.Require(PermTeacherMeeting)).Post("/teacher-meetings", h.createMeeting)
		r.With(iam.Require(PermTeacherMeeting)).Put("/teacher-meetings/{id}", h.updateMeeting)
		r.With(iam.Require(PermTeacherMeeting)).Delete("/teacher-meetings/{id}", h.deleteMeeting)
	})
}

func (h *Handler) fail(w http.ResponseWriter, r *http.Request, err error) { iam.Fail(w, r, h.log, err) }

func actor(r *http.Request) *iam.Access { return iam.AccessFrom(r.Context()) }

func qInt(r *http.Request, key string) int {
	n, _ := strconv.Atoi(r.URL.Query().Get(key))
	return n
}

func respond(h *Handler, w http.ResponseWriter, r *http.Request, v any, err error) {
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, v)
}

func message(h *Handler, w http.ResponseWriter, r *http.Request, status int, msg string, err error) {
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, status, map[string]any{"message": msg})
}

func pathID(r *http.Request) (uuid.UUID, bool) {
	id, err := uuid.Parse(chi.URLParam(r, "id"))
	return id, err == nil
}

func (h *Handler) operations(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	out, err := h.svc.Operations(r.Context(), actor(r), OperationsFilter{TeacherID: q.Get("teacher_id"), Rating: q.Get("rating"), Page: qInt(r, "page")})
	respond(h, w, r, out, err)
}

func (h *Handler) createOperation(w http.ResponseWriter, r *http.Request) {
	var in OperationInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	msg, err := h.svc.CreateOperation(r.Context(), actor(r), in)
	message(h, w, r, http.StatusCreated, msg, err)
}

func (h *Handler) updateOperation(w http.ResponseWriter, r *http.Request) {
	id, ok := pathID(r)
	if !ok {
		h.fail(w, r, errOperationNotFound)
		return
	}
	var in OperationInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	msg, err := h.svc.UpdateOperation(r.Context(), actor(r), id, in)
	message(h, w, r, http.StatusOK, msg, err)
}

func (h *Handler) deleteOperation(w http.ResponseWriter, r *http.Request) {
	id, ok := pathID(r)
	if !ok {
		h.fail(w, r, errOperationNotFound)
		return
	}
	msg, err := h.svc.DeleteOperation(r.Context(), actor(r), id)
	message(h, w, r, http.StatusOK, msg, err)
}

func (h *Handler) academic(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	out, err := h.svc.Academic(r.Context(), actor(r), AcademicFilter{Month: q.Get("month"), Status: q.Get("status"), Search: q.Get("search"), Page: qInt(r, "page")})
	respond(h, w, r, out, err)
}

func (h *Handler) saveAcademic(w http.ResponseWriter, r *http.Request) {
	var in AcademicInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	msg, err := h.svc.SaveAcademic(r.Context(), actor(r), in)
	message(h, w, r, http.StatusOK, msg, err)
}

func (h *Handler) checklist(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	out, err := h.svc.Checklist(r.Context(), actor(r), ChecklistFilter{Month: q.Get("month"), Search: q.Get("search"), Page: qInt(r, "page")})
	respond(h, w, r, out, err)
}

func (h *Handler) saveChecklist(w http.ResponseWriter, r *http.Request) {
	var in ChecklistInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	msg, err := h.svc.SaveChecklist(r.Context(), actor(r), in)
	message(h, w, r, http.StatusOK, msg, err)
}

func (h *Handler) meetings(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	out, err := h.svc.Meetings(r.Context(), actor(r), MeetingsFilter{Week: q.Get("week"), TeacherID: q.Get("teacher_id"), Status: q.Get("status"), Page: qInt(r, "page")})
	respond(h, w, r, out, err)
}

func (h *Handler) createMeeting(w http.ResponseWriter, r *http.Request) {
	var in MeetingInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	msg, err := h.svc.CreateMeeting(r.Context(), actor(r), in)
	message(h, w, r, http.StatusCreated, msg, err)
}

func (h *Handler) updateMeeting(w http.ResponseWriter, r *http.Request) {
	id, ok := pathID(r)
	if !ok {
		h.fail(w, r, errMeetingNotFound)
		return
	}
	var in MeetingInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	msg, err := h.svc.UpdateMeeting(r.Context(), actor(r), id, in)
	message(h, w, r, http.StatusOK, msg, err)
}

func (h *Handler) deleteMeeting(w http.ResponseWriter, r *http.Request) {
	id, ok := pathID(r)
	if !ok {
		h.fail(w, r, errMeetingNotFound)
		return
	}
	msg, err := h.svc.DeleteMeeting(r.Context(), actor(r), id)
	message(h, w, r, http.StatusOK, msg, err)
}
