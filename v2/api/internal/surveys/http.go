package surveys

import (
	"log/slog"
	"net/http"
	"strings"

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

// Routes:
//   - Quản lý (survey.manage, như middleware can:survey.manage): GET/POST /surveys, PUT/DELETE /surveys/{id},
//     GET /surveys/{id}/responses.
//   - Cổng PH/HS (portal.student): GET /surveys/portal?student_id=, POST /surveys/portal,
//     DELETE /surveys/portal/responses/{id}. TODO(gộp): Cổng PH/HS gọi các API này cho màn "Khảo sát 5 sao".
func (h *Handler) Routes(r chi.Router) {
	manage := iam.Require(PermManage)
	portal := iam.Require(PermPortal)
	r.Route("/surveys", func(r chi.Router) {
		r.With(portal).Get("/portal", h.portal)
		r.With(portal).Post("/portal", h.submit)
		r.With(portal).Delete("/portal/responses/{id}", h.deleteResponse)
		r.With(manage).Get("/", h.list)
		r.With(manage).Post("/", h.create)
		r.With(manage).Put("/{id}", h.update)
		r.With(manage).Delete("/{id}", h.delete)
		r.With(manage).Get("/{id}/responses", h.responses)
	})
}

func (h *Handler) fail(w http.ResponseWriter, r *http.Request, err error) { iam.Fail(w, r, h.log, err) }

func actor(r *http.Request) *iam.Access { return iam.AccessFrom(r.Context()) }

func (h *Handler) id(w http.ResponseWriter, r *http.Request, notFound error) (uuid.UUID, bool) {
	id, err := uuid.Parse(chi.URLParam(r, "id"))
	if err != nil {
		h.fail(w, r, notFound)
		return id, false
	}
	return id, true
}

func (h *Handler) reply(w http.ResponseWriter, r *http.Request, status int, res any, err error) {
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, status, res)
}

func (h *Handler) list(w http.ResponseWriter, r *http.Request) {
	res, err := h.svc.List(r.Context(), actor(r))
	h.reply(w, r, http.StatusOK, res, err)
}

func (h *Handler) create(w http.ResponseWriter, r *http.Request) {
	var in Input
	if !httpx.Decode(w, r, &in) {
		return
	}
	res, err := h.svc.Create(r.Context(), actor(r), in)
	h.reply(w, r, http.StatusCreated, res, err)
}

func (h *Handler) update(w http.ResponseWriter, r *http.Request) {
	id, ok := h.id(w, r, ErrNotFound)
	if !ok {
		return
	}
	var in Input
	if !httpx.Decode(w, r, &in) {
		return
	}
	res, err := h.svc.Update(r.Context(), actor(r), id, in)
	h.reply(w, r, http.StatusOK, res, err)
}

func (h *Handler) delete(w http.ResponseWriter, r *http.Request) {
	id, ok := h.id(w, r, ErrNotFound)
	if !ok {
		return
	}
	res, err := h.svc.Delete(r.Context(), actor(r), id)
	h.reply(w, r, http.StatusOK, res, err)
}

func (h *Handler) responses(w http.ResponseWriter, r *http.Request) {
	id, ok := h.id(w, r, ErrNotFound)
	if !ok {
		return
	}
	res, err := h.svc.Responses(r.Context(), actor(r), id)
	h.reply(w, r, http.StatusOK, res, err)
}

func (h *Handler) portal(w http.ResponseWriter, r *http.Request) {
	var sid *uuid.UUID
	if v := strings.TrimSpace(r.URL.Query().Get("student_id")); v != "" {
		id, err := uuid.Parse(v)
		if err != nil {
			h.fail(w, r, ErrStudentForbidden)
			return
		}
		sid = &id
	}
	res, err := h.svc.Portal(r.Context(), actor(r), sid)
	h.reply(w, r, http.StatusOK, res, err)
}

func (h *Handler) submit(w http.ResponseWriter, r *http.Request) {
	var in SubmitInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	res, err := h.svc.Submit(r.Context(), actor(r), in)
	h.reply(w, r, http.StatusCreated, res, err)
}

func (h *Handler) deleteResponse(w http.ResponseWriter, r *http.Request) {
	id, ok := h.id(w, r, ErrResponseNotFound)
	if !ok {
		return
	}
	res, err := h.svc.DeleteResponse(r.Context(), actor(r), id)
	h.reply(w, r, http.StatusOK, res, err)
}
