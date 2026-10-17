package kpi

import (
	"encoding/csv"
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

// Routes: /kpi/* (route kpi.* của Laravel) và bảng KPI tự động (tasks.kpi-dashboard, nhóm route Công việc).
func (h *Handler) Routes(r chi.Router) {
	r.Route("/kpi", func(r chi.Router) {
		r.With(iam.Require(PermView)).Get("/criteria", h.criteria)
		r.With(iam.Require(PermManage)).Post("/criteria", h.createCriterion)
		r.With(iam.Require(PermManage)).Post("/criteria/cycle", h.cycle)
		r.With(iam.Require(PermManage)).Put("/criteria/{id}", h.updateCriterion)
		r.With(iam.Require(PermManage)).Delete("/criteria/{id}", h.deleteCriterion)
		r.With(iam.Require(PermView)).Get("/monthly", h.monthly)
		// KPI của tôi: mọi nhân sự đăng nhập; vai trò chưa có tiêu chí KPI → 403 (Gate view-own-kpi).
		r.With(iam.RequireAny("notification.view", PermView)).Get("/mine", h.mine)
		r.With(iam.Require(PermView)).Get("/evaluate/{userId}", h.evaluate)
		r.With(iam.Require(PermConfirm)).Post("/evaluate/{userId}", h.decide)
		r.With(iam.Require(PermView)).Get("/attendance-review", h.attendance)
		r.With(iam.Require(PermConfirm)).Post("/attendance-review/{id}", h.review)
		r.With(iam.Require("work_task.view")).Get("/board", h.board)
		r.With(iam.Require("work_task.view")).Get("/board/export", h.boardExport)
	})
}

func (h *Handler) fail(w http.ResponseWriter, r *http.Request, err error) { iam.Fail(w, r, h.log, err) }

func (h *Handler) send(w http.ResponseWriter, r *http.Request, v any, err error) {
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, v)
}

func (h *Handler) pathID(w http.ResponseWriter, r *http.Request, key string, notFound error) (uuid.UUID, bool) {
	id, err := uuid.Parse(chi.URLParam(r, key))
	if err != nil {
		h.fail(w, r, notFound)
		return uuid.Nil, false
	}
	return id, true
}

func (h *Handler) criteria(w http.ResponseWriter, r *http.Request) {
	out, err := h.svc.Criteria(r.Context(), iam.AccessFrom(r.Context()), r.URL.Query().Get("role"))
	h.send(w, r, out, err)
}

func (h *Handler) createCriterion(w http.ResponseWriter, r *http.Request) {
	var in CriterionInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	msg, role, err := h.svc.CreateCriterion(r.Context(), iam.AccessFrom(r.Context()), in)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusCreated, map[string]any{"message": msg, "role": role})
}

func (h *Handler) updateCriterion(w http.ResponseWriter, r *http.Request) {
	id, ok := h.pathID(w, r, "id", ErrNotFound)
	if !ok {
		return
	}
	var in CriterionInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	msg, role, err := h.svc.UpdateCriterion(r.Context(), iam.AccessFrom(r.Context()), id, in)
	h.send(w, r, map[string]any{"message": msg, "role": role}, err)
}

func (h *Handler) deleteCriterion(w http.ResponseWriter, r *http.Request) {
	id, ok := h.pathID(w, r, "id", ErrNotFound)
	if !ok {
		return
	}
	msg, role, err := h.svc.DeleteCriterion(r.Context(), iam.AccessFrom(r.Context()), id)
	h.send(w, r, map[string]any{"message": msg, "role": role}, err)
}

func (h *Handler) cycle(w http.ResponseWriter, r *http.Request) {
	var in CycleInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	msg, err := h.svc.SetCycle(r.Context(), iam.AccessFrom(r.Context()), in)
	h.send(w, r, map[string]any{"message": msg}, err)
}

func (h *Handler) monthly(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	page, _ := strconv.Atoi(q.Get("page"))
	out, err := h.svc.Monthly(r.Context(), iam.AccessFrom(r.Context()),
		MonthlyFilter{Period: q.Get("period"), Role: q.Get("role"), BranchID: q.Get("branch_id"), Page: page})
	h.send(w, r, out, err)
}

func (h *Handler) evaluate(w http.ResponseWriter, r *http.Request) {
	id, ok := h.pathID(w, r, "userId", ErrStaffNotFound)
	if !ok {
		return
	}
	q := r.URL.Query()
	out, err := h.svc.Evaluate(r.Context(), iam.AccessFrom(r.Context()), id, q.Get("period"), q.Get("track"))
	h.send(w, r, out, err)
}

func (h *Handler) decide(w http.ResponseWriter, r *http.Request) {
	id, ok := h.pathID(w, r, "userId", ErrStaffNotFound)
	if !ok {
		return
	}
	var in DecideInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	msg, err := h.svc.Decide(r.Context(), iam.AccessFrom(r.Context()), id, in)
	h.send(w, r, map[string]any{"message": msg}, err)
}

func (h *Handler) mine(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	out, err := h.svc.Mine(r.Context(), iam.AccessFrom(r.Context()), q.Get("period"), q.Get("track"))
	h.send(w, r, out, err)
}

func (h *Handler) attendance(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	out, err := h.svc.Attendance(r.Context(), iam.AccessFrom(r.Context()), q.Get("date"), q.Get("class_id"))
	h.send(w, r, out, err)
}

func (h *Handler) review(w http.ResponseWriter, r *http.Request) {
	id, ok := h.pathID(w, r, "id", ErrAttendanceNotFound)
	if !ok {
		return
	}
	var in ReviewInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	msg, err := h.svc.ReviewAttendance(r.Context(), iam.AccessFrom(r.Context()), id, in)
	h.send(w, r, map[string]any{"message": msg}, err)
}

func boardFilter(r *http.Request) BoardFilter {
	q := r.URL.Query()
	page, _ := strconv.Atoi(q.Get("page"))
	return BoardFilter{Month: q.Get("month"), MonthTo: q.Get("month_to"), UserID: q.Get("user_id"), Page: page}
}

func (h *Handler) board(w http.ResponseWriter, r *http.Request) {
	out, err := h.svc.Board(r.Context(), iam.AccessFrom(r.Context()), boardFilter(r), false)
	h.send(w, r, out, err)
}

// boardExport: CSV UTF-8 có BOM (mở thẳng bằng Excel) thay cho file .xlsx của Laravel.
func (h *Handler) boardExport(w http.ResponseWriter, r *http.Request) {
	name, rows, err := h.svc.BoardExport(r.Context(), iam.AccessFrom(r.Context()), boardFilter(r))
	if err != nil {
		h.fail(w, r, err)
		return
	}
	w.Header().Set("Content-Type", "text/csv; charset=UTF-8")
	w.Header().Set("Content-Disposition", `attachment; filename="`+name+`"`)
	_, _ = w.Write([]byte("\xEF\xBB\xBF"))
	_ = csv.NewWriter(w).WriteAll(rows)
}
