package classes

import (
	"log/slog"
	"net/http"
	"strconv"
	"time"

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

// Routes: /classes (Laravel classes.* — cả nhóm cần class.view, thao tác ghi cần quyền riêng), /class-attendance
// (cổng giáo viên điểm danh), /schedule-config (Lịch & TKB lớp — tasks.schedule-config), /class-enrollments
// (Tiếp nhận & Xếp lớp — students.enrollments, link-class).
func (h *Handler) Routes(r chi.Router) {
	r.Route("/classes", func(r chi.Router) {
		r.With(iam.Require("class.view")).Get("/", h.list)
		r.With(iam.RequireAny("class.create", "class.update")).Get("/form-options", h.formOptions)
		r.With(iam.Require("class.view"), iam.Require("class.create")).Post("/", h.create)
		r.With(iam.Require("class.view")).Post("/check-availability", h.checkAvailability)
		r.With(iam.Require("class.view")).Get("/{id}", h.show)
		r.With(iam.Require("class.view"), iam.Require("class.update")).Put("/{id}", h.update)
		r.With(iam.Require("class.view"), iam.Require("class.update")).Post("/{id}/foreign-teacher", h.foreignTeacher)
		r.With(iam.Require("class.view"), iam.Require("class.delete")).Delete("/{id}", h.delete)
	})
	r.Route("/class-attendance/{id}", func(r chi.Router) {
		r.Use(iam.RequireAny("attendance_student.record", "attendance_student.record_any"))
		r.Get("/", h.attendanceSheet)
		r.Post("/", h.saveAttendance)
	})
	r.Route("/schedule-config", func(r chi.Router) {
		r.Use(iam.Require("work_task.view"))
		r.Get("/", h.scheduleConfig)
		r.With(iam.Require("work_task.assign")).Post("/", h.saveSchedule)
		r.With(iam.Require("work_task.assign")).Post("/toggle", h.toggleStatus)
	})
	r.Route("/class-enrollments", func(r chi.Router) {
		r.Use(iam.Require("student.view"))
		r.Get("/", h.enrollments)
		r.With(iam.Require("student.assign_class")).Post("/", h.enroll)
		r.With(iam.Require("student.assign_class")).Post("/link", h.link)
		r.With(iam.Require("student.assign_class")).Put("/{id}", h.handoff)
	})
}

func actorOf(r *http.Request) *iam.Access { return iam.AccessFrom(r.Context()) }

func optUUID(s string) *uuid.UUID {
	if id, err := uuid.Parse(s); err == nil {
		return &id
	}
	return nil
}

func idParam(w http.ResponseWriter, r *http.Request) (uuid.UUID, bool) {
	id, err := uuid.Parse(chi.URLParam(r, "id"))
	if err != nil {
		httpx.Error(w, http.StatusNotFound, ErrNotFound.Code, ErrNotFound.Message)
		return uuid.Nil, false
	}
	return id, true
}

func (h *Handler) reply(w http.ResponseWriter, r *http.Request, v any, err error) {
	if err != nil {
		iam.Fail(w, r, h.log, err)
		return
	}
	httpx.JSON(w, http.StatusOK, v)
}

func (h *Handler) message(w http.ResponseWriter, r *http.Request, msg string, err error) {
	h.reply(w, r, map[string]string{"message": msg}, err)
}

func (h *Handler) list(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	page, _ := strconv.Atoi(q.Get("page"))
	res, err := h.svc.List(r.Context(), actorOf(r), ListFilter{
		Search: q.Get("q"), BranchID: optUUID(q.Get("branch_id")), Status: q.Get("status"),
		Program: q.Get("program"), Level: q.Get("level"), Page: page,
	})
	h.reply(w, r, res, err)
}

func (h *Handler) formOptions(w http.ResponseWriter, r *http.Request) {
	res, err := h.svc.FormOptions(r.Context(), actorOf(r), optUUID(r.URL.Query().Get("class_id")))
	h.reply(w, r, res, err)
}

func (h *Handler) create(w http.ResponseWriter, r *http.Request) {
	var in ClassInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	res, err := h.svc.Create(r.Context(), actorOf(r), in)
	if err != nil {
		iam.Fail(w, r, h.log, err)
		return
	}
	httpx.JSON(w, http.StatusCreated, res)
}

func (h *Handler) checkAvailability(w http.ResponseWriter, r *http.Request) {
	var in AvailabilityInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	res, err := h.svc.CheckAvailability(r.Context(), in)
	h.reply(w, r, res, err)
}

func (h *Handler) show(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	res, err := h.svc.Show(r.Context(), actorOf(r), id, r.URL.Query().Get("tab"))
	h.reply(w, r, res, err)
}

func (h *Handler) update(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	var in ClassInput
	if !ok || !httpx.Decode(w, r, &in) {
		return
	}
	res, err := h.svc.Update(r.Context(), actorOf(r), id, in)
	h.reply(w, r, res, err)
}

func (h *Handler) foreignTeacher(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	var in ForeignInput
	if !ok || !httpx.Decode(w, r, &in) {
		return
	}
	msg, err := h.svc.AssignForeignTeacher(r.Context(), actorOf(r), id, in)
	h.message(w, r, msg, err)
}

func (h *Handler) delete(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	msg, err := h.svc.Delete(r.Context(), actorOf(r), id)
	h.message(w, r, msg, err)
}

func optDate(s string) *time.Time {
	if t, err := time.Parse(time.DateOnly, s); err == nil {
		return &t
	}
	return nil
}

func (h *Handler) attendanceSheet(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	q := r.URL.Query()
	res, err := h.svc.AttendanceSheet(r.Context(), actorOf(r), id, optUUID(q.Get("session")), optDate(q.Get("date")))
	h.reply(w, r, res, err)
}

func (h *Handler) saveAttendance(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	var in AttendanceInput
	if !ok || !httpx.Decode(w, r, &in) {
		return
	}
	msg, err := h.svc.SaveAttendance(r.Context(), actorOf(r), id, in)
	h.message(w, r, msg, err)
}

func (h *Handler) scheduleConfig(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	res, err := h.svc.ScheduleConfig(r.Context(), actorOf(r), optUUID(q.Get("class_id")), q.Get("class_q"))
	h.reply(w, r, res, err)
}

func (h *Handler) saveSchedule(w http.ResponseWriter, r *http.Request) {
	var in ScheduleInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	msg, err := h.svc.SaveSchedule(r.Context(), actorOf(r), in)
	h.message(w, r, msg, err)
}

func (h *Handler) toggleStatus(w http.ResponseWriter, r *http.Request) {
	var in struct {
		ClassID uuid.UUID `json:"class_id"`
	}
	if !httpx.Decode(w, r, &in) {
		return
	}
	res, err := h.svc.ToggleStatus(r.Context(), actorOf(r), in.ClassID)
	h.reply(w, r, res, err)
}

func (h *Handler) enrollments(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	page, _ := strconv.Atoi(q.Get("page"))
	res, err := h.svc.Enrollments(r.Context(), actorOf(r), EnrollmentFilter{
		StudentID: optUUID(q.Get("student_id")), ClassID: optUUID(q.Get("class_id")), Page: page,
	})
	h.reply(w, r, res, err)
}

func (h *Handler) enroll(w http.ResponseWriter, r *http.Request) {
	var in EnrollInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	msg, err := h.svc.Enroll(r.Context(), actorOf(r), in, true)
	h.message(w, r, msg, err)
}

func (h *Handler) link(w http.ResponseWriter, r *http.Request) {
	var in EnrollInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	msg, err := h.svc.Enroll(r.Context(), actorOf(r), in, false)
	h.message(w, r, msg, err)
}

func (h *Handler) handoff(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	var in HandoffInput
	if !ok || !httpx.Decode(w, r, &in) {
		return
	}
	msg, err := h.svc.UpdateHandoff(r.Context(), actorOf(r), id, in)
	h.message(w, r, msg, err)
}
