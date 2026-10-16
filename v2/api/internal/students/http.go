package students

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

// Routes gắn /students. Như Laravel, cả nhóm cần student.view; thao tác ghi cần thêm quyền riêng.
func (h *Handler) Routes(r chi.Router) {
	r.Route("/students", func(r chi.Router) {
		r.Use(iam.Require("student.view"))
		r.Get("/", h.list)
		r.Get("/options", h.options)
		r.Get("/{id}", h.get)
		r.With(iam.Require("student.update")).Patch("/{id}", h.update)
		r.With(iam.Require("student.change_status")).Put("/{id}/status", h.changeStatus)
		r.With(iam.Require("student.change_status")).Post("/{id}/end-deferral", h.endDeferral)
		r.With(iam.Require("student.delete")).Delete("/{id}", h.delete)
		r.With(iam.Require("student.update")).Post("/{id}/guardians", h.addGuardian)
		r.With(iam.Require("student.update")).Delete("/{id}/guardians/{guardianID}", h.removeGuardian)
		r.With(iam.Require("student.assign_class")).Post("/{id}/reset-account", h.resetAccount)
	})
}

func actorOf(r *http.Request) *iam.Access { return iam.AccessFrom(r.Context()) }

func uuidParam(w http.ResponseWriter, r *http.Request, name string) (uuid.UUID, bool) {
	id, err := uuid.Parse(chi.URLParam(r, name))
	if err != nil {
		httpx.Error(w, http.StatusNotFound, ErrNotFound.Code, ErrNotFound.Message)
		return uuid.Nil, false
	}
	return id, true
}

func optUUID(s string) *uuid.UUID {
	if id, err := uuid.Parse(s); err == nil {
		return &id
	}
	return nil
}

func (h *Handler) list(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	page, _ := strconv.Atoi(q.Get("page"))
	perPage, _ := strconv.Atoi(q.Get("per_page"))
	tenure, _ := strconv.Atoi(q.Get("tenure"))
	statuses := q["statuses"]
	if s := q.Get("status"); s != "" {
		statuses = append(statuses, s)
	}
	res, err := h.svc.List(r.Context(), actorOf(r), ListFilter{
		Search: q.Get("q"), BranchID: optUUID(q.Get("branch_id")), ClassID: optUUID(q.Get("class_id")),
		Statuses: statuses, Tenure: tenure, Sort: q.Get("sort"), WithoutClass: q.Get("without_class") == "1",
		Page: page, PerPage: perPage,
	})
	if err != nil {
		iam.Fail(w, r, h.log, err)
		return
	}
	httpx.JSON(w, http.StatusOK, res)
}

func (h *Handler) options(w http.ResponseWriter, r *http.Request) {
	res, err := h.svc.Options(r.Context(), actorOf(r))
	if err != nil {
		iam.Fail(w, r, h.log, err)
		return
	}
	httpx.JSON(w, http.StatusOK, res)
}

// get nhận id hoặc mã học viên; ?view=scoped là "Xem theo phân quyền".
func (h *Handler) get(w http.ResponseWriter, r *http.Request) {
	p, err := h.svc.Get(r.Context(), actorOf(r), chi.URLParam(r, "id"), r.URL.Query().Get("view") == "scoped")
	if err != nil {
		iam.Fail(w, r, h.log, err)
		return
	}
	httpx.JSON(w, http.StatusOK, p)
}

func (h *Handler) update(w http.ResponseWriter, r *http.Request) {
	id, ok := uuidParam(w, r, "id")
	var in UpdateInput
	if !ok || !httpx.Decode(w, r, &in) {
		return
	}
	actor := actorOf(r)
	if err := h.svc.Update(r.Context(), actor, id, in); err != nil {
		iam.Fail(w, r, h.log, err)
		return
	}
	p, err := h.svc.Get(r.Context(), actor, id.String(), false)
	if err != nil {
		iam.Fail(w, r, h.log, err)
		return
	}
	httpx.JSON(w, http.StatusOK, map[string]any{
		"student": p, "message": "Cập nhật hồ sơ học viên " + p.FullName + " thành công!",
	})
}

func (h *Handler) changeStatus(w http.ResponseWriter, r *http.Request) {
	id, ok := uuidParam(w, r, "id")
	var req struct {
		Status string `json:"status"`
	}
	if !ok || !httpx.Decode(w, r, &req) {
		return
	}
	msg, err := h.svc.ChangeStatus(r.Context(), actorOf(r), id, req.Status)
	if err != nil {
		iam.Fail(w, r, h.log, err)
		return
	}
	httpx.JSON(w, http.StatusOK, map[string]string{"message": msg})
}

func (h *Handler) endDeferral(w http.ResponseWriter, r *http.Request) {
	id, ok := uuidParam(w, r, "id")
	if !ok {
		return
	}
	msg, err := h.svc.EndDeferral(r.Context(), actorOf(r), id)
	if err != nil {
		iam.Fail(w, r, h.log, err)
		return
	}
	httpx.JSON(w, http.StatusOK, map[string]string{"message": msg})
}

func (h *Handler) delete(w http.ResponseWriter, r *http.Request) {
	id, ok := uuidParam(w, r, "id")
	if !ok {
		return
	}
	msg, err := h.svc.Delete(r.Context(), actorOf(r), id)
	if err != nil {
		iam.Fail(w, r, h.log, err)
		return
	}
	httpx.JSON(w, http.StatusOK, map[string]string{"message": msg})
}

func (h *Handler) addGuardian(w http.ResponseWriter, r *http.Request) {
	id, ok := uuidParam(w, r, "id")
	var in GuardianInput
	if !ok || !httpx.Decode(w, r, &in) {
		return
	}
	res, err := h.svc.AddGuardian(r.Context(), actorOf(r), id, in)
	if err != nil {
		iam.Fail(w, r, h.log, err)
		return
	}
	httpx.JSON(w, http.StatusCreated, res)
}

func (h *Handler) removeGuardian(w http.ResponseWriter, r *http.Request) {
	id, ok := uuidParam(w, r, "id")
	if !ok {
		return
	}
	gid, err := uuid.Parse(chi.URLParam(r, "guardianID"))
	if err != nil {
		iam.Fail(w, r, h.log, ErrGuardianNotFound)
		return
	}
	if err := h.svc.RemoveGuardian(r.Context(), actorOf(r), id, gid); err != nil {
		iam.Fail(w, r, h.log, err)
		return
	}
	w.WriteHeader(http.StatusNoContent)
}

func (h *Handler) resetAccount(w http.ResponseWriter, r *http.Request) {
	id, ok := uuidParam(w, r, "id")
	if !ok {
		return
	}
	res, err := h.svc.ResetAccount(r.Context(), actorOf(r), id)
	if err != nil {
		iam.Fail(w, r, h.log, err)
		return
	}
	httpx.JSON(w, http.StatusOK, res)
}
