package reports

import (
	"encoding/json"
	"errors"
	"io"
	"log/slog"
	"net/http"
	"strconv"
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

// Routes gắn /reports vào router /api/v1. Nộp / xem báo cáo của mình và nhật ký: staff_report.submit; tổng hợp, chi tiết,
// kiểm tra: staff_report.view_all (kiểm tra / yêu cầu bổ sung / lỗi nghiêm trọng thêm điều kiện Admin trong service).
func (h *Handler) Routes(r chi.Router) {
	r.Route("/reports", func(r chi.Router) {
		r.Group(func(r chi.Router) {
			r.Use(iam.Require(PermSubmit))
			r.Get("/my", h.my)
			r.Post("/my", h.saveMy)
			r.Get("/daily", h.daily)
			r.Post("/daily", h.saveDaily)
			r.Get("/weekly-kpi", h.weeklyKpi)
			r.Post("/weekly-kpi", h.saveWeeklyKpi)
			r.Get("/academic-weekly", h.academicWeekly)
			r.Post("/academic-weekly", h.saveAcademicWeekly)
			r.Get("/academic-monthly", h.academicMonthly)
			r.Post("/academic-monthly", h.saveAcademicMonthly)
			r.Get("/academic-quarterly", h.academicQuarterly)
			r.Post("/academic-quarterly", h.saveAcademicQuarterly)
			r.Get("/teacher-monthly", h.teacherMonthly)
			r.Post("/teacher-monthly", h.saveTeacherMonthly)
			r.Post("/teacher-attendance", h.saveTeacherAttendance)

			r.Get("/journal", h.journal)
			r.Post("/journal", h.addJournal)
			r.Post("/journal/{id}/followup", h.addFollowup)
			r.Post("/journal/{id}/status", h.journalStatus)
			r.Post("/journal/{id}/serious", h.journalSerious)
		})
		r.Group(func(r chi.Router) {
			r.Use(iam.Require(PermViewAll))
			r.Get("/all", h.all)
			r.Get("/{id}/detail", h.detail)
			r.Post("/{id}/review", h.review)
			r.Post("/{id}/request-changes", h.requestChanges)
			r.Post("/review-bulk", h.reviewBulk)
		})
	})
}

type messageResponse struct {
	Message string `json:"message"`
}

func (h *Handler) ok(w http.ResponseWriter, r *http.Request, v any, err error) {
	if err != nil {
		iam.Fail(w, r, h.log, err)
		return
	}
	httpx.JSON(w, http.StatusOK, v)
}

func (h *Handler) done(w http.ResponseWriter, r *http.Request, msg string, err error) {
	h.ok(w, r, messageResponse{msg}, err)
}

func queryUUID(r *http.Request, key string) *uuid.UUID {
	id, err := uuid.Parse(r.URL.Query().Get(key))
	if err != nil || id == uuid.Nil {
		return nil
	}
	return &id
}

func (h *Handler) pathID(w http.ResponseWriter, r *http.Request) (uuid.UUID, bool) {
	id, err := uuid.Parse(chi.URLParam(r, "id"))
	if err != nil {
		iam.Fail(w, r, h.log, ErrNotFound)
		return uuid.Nil, false
	}
	return id, true
}

const maxReportBody = 2 << 20

// decode như httpx.Decode nhưng nhận body tới 2 MB: báo cáo tháng / quý có nhiều ô chữ tới 10.000 ký tự,
// báo cáo tuần Học thuật tới 150 dòng.
func decode(w http.ResponseWriter, r *http.Request, dst any) bool {
	if ct := r.Header.Get("Content-Type"); !strings.HasPrefix(ct, "application/json") {
		httpx.Error(w, http.StatusUnsupportedMediaType, "unsupported_media_type", "Cần gửi JSON.")
		return false
	}
	dec := json.NewDecoder(http.MaxBytesReader(w, r.Body, maxReportBody))
	dec.DisallowUnknownFields()
	if err := dec.Decode(dst); err != nil {
		httpx.Error(w, http.StatusBadRequest, "bad_request", "Dữ liệu gửi lên không hợp lệ.")
		return false
	}
	if err := dec.Decode(&struct{}{}); !errors.Is(err, io.EOF) {
		httpx.Error(w, http.StatusBadRequest, "bad_request", "Dữ liệu gửi lên không hợp lệ.")
		return false
	}
	return true
}

func queryInt(r *http.Request, key string) int {
	n, _ := strconv.Atoi(r.URL.Query().Get(key))
	return n
}

func queryBool(r *http.Request, key string) bool {
	v := r.URL.Query().Get(key)
	return v == "1" || v == "true"
}

// ───────────── Báo cáo của tôi ─────────────

func (h *Handler) my(w http.ResponseWriter, r *http.Request) {
	out, err := h.svc.My(r.Context(), iam.AccessFrom(r.Context()), queryInt(r, "page"))
	h.ok(w, r, out, err)
}

func (h *Handler) saveMy(w http.ResponseWriter, r *http.Request) {
	var in FreeReportInput
	if !decode(w, r, &in) {
		return
	}
	msg, err := h.svc.SaveMy(r.Context(), iam.AccessFrom(r.Context()), in)
	h.done(w, r, msg, err)
}

func (h *Handler) daily(w http.ResponseWriter, r *http.Request) {
	out, err := h.svc.Daily(r.Context(), iam.AccessFrom(r.Context()), r.URL.Query().Get("date"), queryBool(r, "refresh"))
	h.ok(w, r, out, err)
}

func (h *Handler) saveDaily(w http.ResponseWriter, r *http.Request) {
	var in DailyInput
	if !decode(w, r, &in) {
		return
	}
	msg, err := h.svc.SaveDaily(r.Context(), iam.AccessFrom(r.Context()), in)
	h.done(w, r, msg, err)
}

func (h *Handler) weeklyKpi(w http.ResponseWriter, r *http.Request) {
	out, err := h.svc.WeeklyKpi(r.Context(), iam.AccessFrom(r.Context()), r.URL.Query().Get("week"), queryBool(r, "refresh"))
	h.ok(w, r, out, err)
}

func (h *Handler) saveWeeklyKpi(w http.ResponseWriter, r *http.Request) {
	var in WeeklyKpiInput
	if !decode(w, r, &in) {
		return
	}
	msg, err := h.svc.SaveWeeklyKpi(r.Context(), iam.AccessFrom(r.Context()), in)
	h.done(w, r, msg, err)
}

func (h *Handler) academicWeekly(w http.ResponseWriter, r *http.Request) {
	out, err := h.svc.AcademicWeekly(r.Context(), iam.AccessFrom(r.Context()), r.URL.Query().Get("week"))
	h.ok(w, r, out, err)
}

func (h *Handler) saveAcademicWeekly(w http.ResponseWriter, r *http.Request) {
	var in AcademicWeeklyInput
	if !decode(w, r, &in) {
		return
	}
	msg, err := h.svc.SaveAcademicWeekly(r.Context(), iam.AccessFrom(r.Context()), in)
	h.done(w, r, msg, err)
}

func (h *Handler) academicMonthly(w http.ResponseWriter, r *http.Request) {
	out, err := h.svc.AcademicMonthly(r.Context(), iam.AccessFrom(r.Context()), r.URL.Query().Get("month"))
	h.ok(w, r, out, err)
}

func (h *Handler) saveAcademicMonthly(w http.ResponseWriter, r *http.Request) {
	var in struct {
		Month     string             `json:"month"`
		Narrative map[string]*string `json:"narrative"`
	}
	if !decode(w, r, &in) {
		return
	}
	msg, err := h.svc.SaveAcademicMonthly(r.Context(), iam.AccessFrom(r.Context()), NarrativeInput{in.Month, in.Narrative})
	h.done(w, r, msg, err)
}

func (h *Handler) academicQuarterly(w http.ResponseWriter, r *http.Request) {
	out, err := h.svc.AcademicQuarterly(r.Context(), iam.AccessFrom(r.Context()), r.URL.Query().Get("quarter"))
	h.ok(w, r, out, err)
}

func (h *Handler) saveAcademicQuarterly(w http.ResponseWriter, r *http.Request) {
	var in struct {
		Quarter   string             `json:"quarter"`
		Narrative map[string]*string `json:"narrative"`
	}
	if !decode(w, r, &in) {
		return
	}
	msg, err := h.svc.SaveAcademicQuarterly(r.Context(), iam.AccessFrom(r.Context()), NarrativeInput{in.Quarter, in.Narrative})
	h.done(w, r, msg, err)
}

func (h *Handler) teacherMonthly(w http.ResponseWriter, r *http.Request) {
	out, err := h.svc.TeacherMonthly(r.Context(), iam.AccessFrom(r.Context()), r.URL.Query().Get("month"))
	h.ok(w, r, out, err)
}

func (h *Handler) saveTeacherMonthly(w http.ResponseWriter, r *http.Request) {
	var in TeacherMonthlyInput
	if !decode(w, r, &in) {
		return
	}
	msg, err := h.svc.SaveTeacherMonthly(r.Context(), iam.AccessFrom(r.Context()), in)
	h.done(w, r, msg, err)
}

func (h *Handler) saveTeacherAttendance(w http.ResponseWriter, r *http.Request) {
	var in TeacherAttendanceInput
	if !decode(w, r, &in) {
		return
	}
	msg, err := h.svc.SaveTeacherAttendance(r.Context(), iam.AccessFrom(r.Context()), in)
	h.done(w, r, msg, err)
}

// ───────────── Nhật ký ─────────────

func (h *Handler) journal(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	out, err := h.svc.Journal(r.Context(), iam.AccessFrom(r.Context()), JournalFilter{
		Severity: q.Get("severity"), Status: q.Get("status"), ClassID: queryUUID(r, "class_id"), Page: queryInt(r, "page")})
	h.ok(w, r, out, err)
}

func (h *Handler) addJournal(w http.ResponseWriter, r *http.Request) {
	var in JournalInput
	if !decode(w, r, &in) {
		return
	}
	id, msg, err := h.svc.AddJournal(r.Context(), iam.AccessFrom(r.Context()), in)
	h.ok(w, r, struct {
		ID      uuid.UUID `json:"id"`
		Message string    `json:"message"`
	}{id, msg}, err)
}

func (h *Handler) addFollowup(w http.ResponseWriter, r *http.Request) {
	id, ok := h.pathID(w, r)
	if !ok {
		return
	}
	var in struct {
		Content string `json:"content"`
	}
	if !decode(w, r, &in) {
		return
	}
	msg, err := h.svc.AddFollowup(r.Context(), iam.AccessFrom(r.Context()), id, in.Content)
	h.done(w, r, msg, err)
}

func (h *Handler) journalStatus(w http.ResponseWriter, r *http.Request) {
	id, ok := h.pathID(w, r)
	if !ok {
		return
	}
	var in struct {
		Status string `json:"status"`
	}
	if !decode(w, r, &in) {
		return
	}
	msg, err := h.svc.SetJournalStatus(r.Context(), iam.AccessFrom(r.Context()), id, in.Status)
	h.done(w, r, msg, err)
}

func (h *Handler) journalSerious(w http.ResponseWriter, r *http.Request) {
	id, ok := h.pathID(w, r)
	if !ok {
		return
	}
	var in struct {
		Serious bool `json:"serious"`
	}
	if !decode(w, r, &in) {
		return
	}
	msg, err := h.svc.SetJournalSerious(r.Context(), iam.AccessFrom(r.Context()), id, in.Serious)
	h.done(w, r, msg, err)
}

// ───────────── Tổng hợp ─────────────

func (h *Handler) all(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	from, to := q.Get("from"), q.Get("to")
	if d := q.Get("date"); d != "" { // link cũ: lọc đúng một ngày
		if from == "" {
			from = d
		}
		if to == "" {
			to = d
		}
	}
	out, err := h.svc.All(r.Context(), iam.AccessFrom(r.Context()), AllFilter{View: q.Get("view"), Type: q.Get("type"),
		Review: q.Get("review"), UserID: queryUUID(r, "user_id"), BranchID: queryUUID(r, "branch_id"), From: from, To: to,
		Page: queryInt(r, "page"), Open: queryUUID(r, "open")})
	h.ok(w, r, out, err)
}

func (h *Handler) detail(w http.ResponseWriter, r *http.Request) {
	id, ok := h.pathID(w, r)
	if !ok {
		return
	}
	out, err := h.svc.Detail(r.Context(), iam.AccessFrom(r.Context()), id)
	h.ok(w, r, out, err)
}

func (h *Handler) review(w http.ResponseWriter, r *http.Request) {
	id, ok := h.pathID(w, r)
	if !ok {
		return
	}
	in := struct {
		Reviewed *bool `json:"reviewed"`
	}{}
	if !decode(w, r, &in) {
		return
	}
	reviewed := in.Reviewed == nil || *in.Reviewed
	msg, err := h.svc.Review(r.Context(), iam.AccessFrom(r.Context()), id, reviewed)
	h.done(w, r, msg, err)
}

func (h *Handler) requestChanges(w http.ResponseWriter, r *http.Request) {
	id, ok := h.pathID(w, r)
	if !ok {
		return
	}
	var in struct {
		Note string `json:"note"`
	}
	if !decode(w, r, &in) {
		return
	}
	msg, err := h.svc.RequestChanges(r.Context(), iam.AccessFrom(r.Context()), id, in.Note)
	h.done(w, r, msg, err)
}

func (h *Handler) reviewBulk(w http.ResponseWriter, r *http.Request) {
	var in struct {
		IDs []uuid.UUID `json:"ids"`
	}
	if !decode(w, r, &in) {
		return
	}
	msg, err := h.svc.ReviewBulk(r.Context(), iam.AccessFrom(r.Context()), in.IDs)
	h.done(w, r, msg, err)
}
