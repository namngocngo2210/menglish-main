package teacher

import (
	"errors"
	"log/slog"
	"net/http"
	"strings"
	"time"

	"github.com/go-chi/chi/v5"
	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/filestore"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/httpx"
)

// Handler: API cổng giáo viên (/teacher/*).
type Handler struct {
	svc *Service
	log *slog.Logger
}

func NewHandler(svc *Service, log *slog.Logger) *Handler {
	if log == nil {
		log = slog.Default()
	}
	return &Handler{svc: svc, log: log}
}

// Routes: cổng giáo viên mở cho người điểm danh lớp mình (attendance_student.record) hoặc làm thay giáo viên
// (attendance_student.record_any, như guardTeacher của Laravel); chấm bài nộp cần homework.grade.
func (h *Handler) Routes(r chi.Router) {
	teacher := iam.RequireAny("attendance_student.record", "attendance_student.record_any")
	r.Route("/teacher", func(r chi.Router) {
		r.With(teacher).Get("/home", h.home)
		r.With(teacher).Post("/checkin", h.checkin)
		r.With(teacher).Get("/general-report", h.generalReport)
		r.Route("/classes/{id}", func(r chi.Router) {
			r.With(teacher).Get("/homework", h.homeworkPage)
			r.With(teacher).Post("/homework", h.createHomework)
			r.With(teacher).Post("/homework/audio", h.uploadAudio)
			r.With(teacher).Put("/homework/{hid}", h.updateHomework)
			r.With(teacher).Delete("/homework/{hid}", h.deleteHomework)
			r.With(teacher).Get("/scores", h.scoresPage)
			r.With(teacher).Post("/scores", h.saveScore)
			r.With(teacher).Get("/remarks", h.remarksPage)
			r.With(teacher).Post("/remarks", h.saveRemarks)
		})
		r.With(iam.Require("homework.grade")).Get("/submissions", h.submissions)
		r.With(iam.Require("homework.grade")).Post("/submissions/{id}/mark", h.mark)
	})
}

func (h *Handler) fail(w http.ResponseWriter, r *http.Request, err error) { iam.Fail(w, r, h.log, err) }

func param(w http.ResponseWriter, r *http.Request, name string) (uuid.UUID, bool) {
	id, err := uuid.Parse(chi.URLParam(r, name))
	if err != nil {
		httpx.Error(w, http.StatusNotFound, "not_found", "Không tìm thấy.")
		return uuid.Nil, false
	}
	return id, true
}

// queryID: tham số id tùy chọn (sai định dạng = không gửi).
func queryID(r *http.Request, name string) *uuid.UUID {
	id, err := uuid.Parse(strings.TrimSpace(r.URL.Query().Get(name)))
	if err != nil {
		return nil
	}
	return &id
}

func queryDay(r *http.Request, name string) *time.Time {
	if d, ok := parseDay(r.URL.Query().Get(name)); ok {
		return &d
	}
	return nil
}

type message struct {
	Message string `json:"message"`
}

func (h *Handler) home(w http.ResponseWriter, r *http.Request) {
	out, err := h.svc.Home(r.Context(), iam.AccessFrom(r.Context()), r.URL.Query().Get("week"))
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, out)
}

func (h *Handler) checkin(w http.ResponseWriter, r *http.Request) {
	var in CheckinInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	out, err := h.svc.CheckIn(r.Context(), iam.AccessFrom(r.Context()), in)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, out)
}

func (h *Handler) generalReport(w http.ResponseWriter, r *http.Request) {
	out, err := h.svc.GeneralReport(r.Context(), iam.AccessFrom(r.Context()), r.URL.Query().Get("period"))
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, out)
}

// ───────────── Bài tập về nhà ─────────────

func (h *Handler) homeworkPage(w http.ResponseWriter, r *http.Request) {
	id, ok := param(w, r, "id")
	if !ok {
		return
	}
	out, err := h.svc.HomeworkPage(r.Context(), iam.AccessFrom(r.Context()), id, queryID(r, "edit"), queryID(r, "session"))
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, out)
}

type savedHomework struct {
	ID      uuid.UUID `json:"id"`
	Message string    `json:"message"`
}

func (h *Handler) saveHomework(w http.ResponseWriter, r *http.Request, hid *uuid.UUID) {
	id, ok := param(w, r, "id")
	if !ok {
		return
	}
	var in HomeworkInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	saved, msg, err := h.svc.SaveHomework(r.Context(), iam.AccessFrom(r.Context()), id, hid, in)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	status := http.StatusOK
	if hid == nil {
		status = http.StatusCreated
	}
	httpx.JSON(w, status, savedHomework{saved, msg})
}

func (h *Handler) createHomework(w http.ResponseWriter, r *http.Request) { h.saveHomework(w, r, nil) }

func (h *Handler) updateHomework(w http.ResponseWriter, r *http.Request) {
	hid, ok := param(w, r, "hid")
	if !ok {
		return
	}
	h.saveHomework(w, r, &hid)
}

func (h *Handler) deleteHomework(w http.ResponseWriter, r *http.Request) {
	id, ok := param(w, r, "id")
	if !ok {
		return
	}
	hid, ok := param(w, r, "hid")
	if !ok {
		return
	}
	if err := h.svc.DeleteHomework(r.Context(), iam.AccessFrom(r.Context()), id, hid); err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, message{"Đã xóa bài tập về nhà."})
}

// uploadErr: lỗi kiểm file (dung lượng, loại) → 422 với thông báo tiếng Việt.
func uploadErr(err error) error {
	var ve *filestore.ValidationError
	if errors.As(err, &ve) {
		return iam.Invalid(ve.Message)
	}
	return err
}

// uploadAudio: multipart file → {id, name, url}; gửi id vào audio_file_id khi lưu bài.
func (h *Handler) uploadAudio(w http.ResponseWriter, r *http.Request) {
	id, ok := param(w, r, "id")
	if !ok {
		return
	}
	form, err := filestore.ReadMultipart(w, r, AudioPolicy.MaxBytes, AudioPolicy.TooLarge)
	if err != nil {
		h.fail(w, r, uploadErr(err))
		return
	}
	defer form.Close()
	up := form.File("file")
	if err := up.Check(AudioPolicy); err != nil {
		h.fail(w, r, uploadErr(err))
		return
	}
	out, err := h.svc.UploadAudio(r.Context(), iam.AccessFrom(r.Context()), id, up)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusCreated, out)
}

// ───────────── Điểm mini test ─────────────

func (h *Handler) scoresPage(w http.ResponseWriter, r *http.Request) {
	id, ok := param(w, r, "id")
	if !ok {
		return
	}
	q := r.URL.Query()
	p := ScoresQuery{UnitID: queryID(r, "unit_id"), StudentID: queryID(r, "student_id"), TestDate: q.Get("test_date")}
	if q.Has("name") {
		p.Name = ptr(q.Get("name"))
	}
	out, err := h.svc.ScoresPage(r.Context(), iam.AccessFrom(r.Context()), id, p)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, out)
}

func (h *Handler) saveScore(w http.ResponseWriter, r *http.Request) {
	id, ok := param(w, r, "id")
	if !ok {
		return
	}
	var in ScoreInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	out, err := h.svc.SaveScore(r.Context(), iam.AccessFrom(r.Context()), id, in)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, out)
}

// ───────────── Nhận xét buổi học ─────────────

func (h *Handler) remarksPage(w http.ResponseWriter, r *http.Request) {
	id, ok := param(w, r, "id")
	if !ok {
		return
	}
	out, err := h.svc.RemarksPage(r.Context(), iam.AccessFrom(r.Context()), id, queryID(r, "session"), queryDay(r, "date"))
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, out)
}

type savedRemarks struct {
	SessionID uuid.UUID `json:"session_id"`
	Message   string    `json:"message"`
}

func (h *Handler) saveRemarks(w http.ResponseWriter, r *http.Request) {
	id, ok := param(w, r, "id")
	if !ok {
		return
	}
	var in RemarksInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	sid, msg, err := h.svc.SaveRemarks(r.Context(), iam.AccessFrom(r.Context()), id, in)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, savedRemarks{sid, msg})
}

// ───────────── Chấm bài nộp ─────────────

func (h *Handler) submissions(w http.ResponseWriter, r *http.Request) {
	out, err := h.svc.Submissions(r.Context(), iam.AccessFrom(r.Context()), queryID(r, "class_id"), r.URL.Query().Get("type"))
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, out)
}

func (h *Handler) mark(w http.ResponseWriter, r *http.Request) {
	id, ok := param(w, r, "id")
	if !ok {
		return
	}
	var in MarkInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	msg, err := h.svc.Mark(r.Context(), iam.AccessFrom(r.Context()), id, in)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, message{msg})
}
