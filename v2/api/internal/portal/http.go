package portal

import (
	"errors"
	"log/slog"
	"net/http"

	"github.com/go-chi/chi/v5"
	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/filestore"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/httpx"
)

type Handler struct {
	svc *Service
	log *slog.Logger
}

func NewHandler(svc *Service, log *slog.Logger) *Handler { return &Handler{svc: svc, log: log} }

// Routes gắn /portal (cổng học viên: tài khoản cổng portal.student hoặc nhân sự student.view xem hộ; màn chấm bài nộp
// của lớp ở phân hệ Cổng giáo viên /teacher/submissions) và /payment-reports (kế toán xác nhận "Học viên báo đã đóng học phí": tuition.view + tuition.approve).
func (h *Handler) Routes(r chi.Router) {
	r.Route("/portal", func(r chi.Router) {
		r.Group(func(r chi.Router) {
			r.Use(iam.RequireAny("portal.student", "student.view"))
			r.Get("/summary", h.summary)
			r.Get("/home", h.home)
			r.Get("/tuition", h.tuitionHistory)
			r.Get("/app-shell", h.appShell)
			r.Patch("/students/{id}/contact", h.updateContact)
			r.Post("/payment-reports", h.submitPaymentReport)
			r.Get("/homework", h.homework)
			r.Post("/homework", h.submitHomework)
			r.Post("/homework/{id}", h.updateHomework)
			r.Delete("/homework/{id}", h.deleteHomework)
			r.Get("/pronunciation", h.pronunciation)
			r.Post("/pronunciation", h.submitPronunciation)
			r.Delete("/pronunciation/{id}", h.deletePronunciation)
			r.Get("/notifications", h.notifications)
			r.Post("/notifications/read-all", h.markAllRead)
			r.Post("/notifications/{id}/read", h.markRead)
			r.Delete("/notifications/{id}", h.deleteNotification)
			r.Get("/surveys", h.surveys)
			r.Post("/surveys", h.submitSurvey)
			r.Delete("/surveys/{id}", h.deleteSurvey)
			r.Get("/feedback", h.feedback)
			r.Post("/feedback", h.submitFeedback)
			r.Delete("/feedback/{id}", h.deleteFeedback)
		})
	})
	r.Route("/payment-reports", func(r chi.Router) {
		r.Use(iam.Require("tuition.view"), iam.Require("tuition.approve"))
		r.Get("/", h.paymentReports)
		r.Get("/pending-count", h.pendingReports)
		r.Post("/{id}/confirm", h.confirmReport)
		r.Post("/{id}/reject", h.rejectReport)
	})
}

func actorOf(r *http.Request) *iam.Access { return iam.AccessFrom(r.Context()) }

func idParam(w http.ResponseWriter, r *http.Request) (uuid.UUID, bool) {
	id, err := uuid.Parse(chi.URLParam(r, "id"))
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

// studentQuery: ?student_id= của học viên đang xem (đổi học viên).
func studentQuery(r *http.Request) *uuid.UUID { return optUUID(r.URL.Query().Get("student_id")) }

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

func uploadErr(err error) error {
	var ve *filestore.ValidationError
	if errors.As(err, &ve) {
		return iam.Invalid(ve.Message)
	}
	return err
}

// form: đọc multipart (trường văn bản + một file); done() xóa file tạm.
func (h *Handler) form(w http.ResponseWriter, r *http.Request, p filestore.Policy) (*filestore.Form, bool) {
	f, err := filestore.ReadMultipart(w, r, p.MaxBytes, p.TooLarge)
	if err != nil {
		iam.Fail(w, r, h.log, uploadErr(err))
		return nil, false
	}
	return f, true
}

func firstFile(f *filestore.Form, names ...string) *filestore.Upload {
	for _, n := range names {
		if u := f.File(n); u != nil {
			return u
		}
	}
	return nil
}

// ───── Màn của học viên ─────

func (h *Handler) summary(w http.ResponseWriter, r *http.Request) {
	n, err := h.svc.Summary(r.Context(), actorOf(r))
	h.reply(w, r, map[string]int{"unread_count": n}, err)
}

func (h *Handler) home(w http.ResponseWriter, r *http.Request) {
	out, err := h.svc.Home(r.Context(), actorOf(r), studentQuery(r))
	h.reply(w, r, out, err)
}

func (h *Handler) tuitionHistory(w http.ResponseWriter, r *http.Request) {
	out, err := h.svc.TuitionHistory(r.Context(), actorOf(r), studentQuery(r))
	h.reply(w, r, map[string]any{"history": out}, err)
}

func (h *Handler) appShell(w http.ResponseWriter, r *http.Request) {
	out, err := h.svc.AppShell(r.Context(), actorOf(r), studentQuery(r))
	h.reply(w, r, out, err)
}

func (h *Handler) updateContact(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	var in ContactInput
	if !ok || !httpx.Decode(w, r, &in) {
		return
	}
	h.message(w, r, "Đã cập nhật thông tin học viên thành công!", h.svc.UpdateContact(r.Context(), actorOf(r), id, in))
}

func (h *Handler) submitPaymentReport(w http.ResponseWriter, r *http.Request) {
	var in PaymentReportInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	out, err := h.svc.SubmitPaymentReport(r.Context(), actorOf(r), in)
	h.reply(w, r, map[string]any{"report": out, "message": "Đã gửi thông báo đóng học phí tới bộ phận Kế toán thành công!"}, err)
}

func (h *Handler) homework(w http.ResponseWriter, r *http.Request) {
	out, err := h.svc.Homework(r.Context(), actorOf(r), studentQuery(r))
	h.reply(w, r, out, err)
}

// submitHomework: multipart student_id, homework_type, notes, file attachment (tùy chọn).
func (h *Handler) submitHomework(w http.ResponseWriter, r *http.Request) {
	f, ok := h.form(w, r, HomeworkPolicy)
	if !ok {
		return
	}
	defer f.Close()
	in := HomeworkInput{StudentID: optUUID(f.Value("student_id")), HomeworkType: f.Value("homework_type")}
	if v := f.Value("notes"); v != "" {
		in.Notes = &v
	}
	id, err := h.svc.SubmitHomework(r.Context(), actorOf(r), in, firstFile(f, "attachment", "file"))
	h.reply(w, r, map[string]any{"id": id,
		"message": "Đã nộp bài tập thành công! Giáo viên sẽ nhận được thông báo để kiểm tra và chấm điểm."}, err)
}

// updateHomework: multipart notes (gửi rỗng = xóa ghi chú), file attachment (nộp lại, tùy chọn).
func (h *Handler) updateHomework(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	f, ok := h.form(w, r, HomeworkPolicy)
	if !ok {
		return
	}
	defer f.Close()
	_, setNotes := f.Values["notes"]
	notes := f.Value("notes")
	h.message(w, r, "Đã cập nhật bài nộp thành công!",
		h.svc.UpdateHomework(r.Context(), actorOf(r), id, &notes, setNotes, firstFile(f, "attachment", "file")))
}

func (h *Handler) deleteHomework(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	h.message(w, r, "Đã xóa bài nộp thành công! Bạn có thể nộp lại bài mới.", h.svc.DeleteHomework(r.Context(), actorOf(r), id))
}

func (h *Handler) pronunciation(w http.ResponseWriter, r *http.Request) {
	out, err := h.svc.Pronunciation(r.Context(), actorOf(r), studentQuery(r))
	h.reply(w, r, out, err)
}

// submitPronunciation: multipart student_id, unit_title, duration, file audio.
func (h *Handler) submitPronunciation(w http.ResponseWriter, r *http.Request) {
	f, ok := h.form(w, r, PronunciationPolicy)
	if !ok {
		return
	}
	defer f.Close()
	in := PronunciationInput{StudentID: optUUID(f.Value("student_id")), UnitTitle: f.Value("unit_title")}
	if v := f.Value("duration"); v != "" {
		in.Duration = &v
	}
	out, err := h.svc.SubmitPronunciation(r.Context(), actorOf(r), in, firstFile(f, "audio", "file"))
	h.reply(w, r, map[string]any{"record": out, "message": "Đã nộp bài ghi âm! Bài đang chờ giáo viên chấm."}, err)
}

func (h *Handler) deletePronunciation(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	h.message(w, r, "Đã xóa bản ghi âm thành công!", h.svc.DeletePronunciation(r.Context(), actorOf(r), id))
}

func (h *Handler) notifications(w http.ResponseWriter, r *http.Request) {
	out, err := h.svc.Notifications(r.Context(), actorOf(r), studentQuery(r))
	h.reply(w, r, out, err)
}

type studentBody struct {
	StudentID *uuid.UUID `json:"student_id"`
}

func (h *Handler) markAllRead(w http.ResponseWriter, r *http.Request) {
	var in studentBody
	if !httpx.Decode(w, r, &in) {
		return
	}
	if in.StudentID == nil {
		iam.Fail(w, r, h.log, errNoStudent)
		return
	}
	_, err := h.svc.MarkAllRead(r.Context(), actorOf(r), *in.StudentID)
	h.message(w, r, "Đã đánh dấu tất cả thông báo là đã đọc!", err)
}

func (h *Handler) markRead(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	var in studentBody
	if !ok || !httpx.Decode(w, r, &in) {
		return
	}
	if in.StudentID == nil {
		iam.Fail(w, r, h.log, errNoStudent)
		return
	}
	h.message(w, r, "Đã đánh dấu thông báo là đã đọc.", h.svc.MarkRead(r.Context(), actorOf(r), *in.StudentID, id))
}

func (h *Handler) deleteNotification(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	sid := studentQuery(r)
	if sid == nil {
		iam.Fail(w, r, h.log, errNoStudent)
		return
	}
	h.message(w, r, "Đã xóa thông báo thành công.", h.svc.DeleteNotification(r.Context(), actorOf(r), *sid, id))
}

func (h *Handler) surveys(w http.ResponseWriter, r *http.Request) {
	out, err := h.svc.Surveys(r.Context(), actorOf(r), studentQuery(r))
	h.reply(w, r, out, err)
}

func (h *Handler) submitSurvey(w http.ResponseWriter, r *http.Request) {
	var in SurveyAnswer
	if !httpx.Decode(w, r, &in) {
		return
	}
	h.message(w, r, "Cảm ơn bạn đã gửi khảo sát! Trung tâm sẽ tiếp nhận và cải thiện chất lượng dịch vụ.",
		h.svc.SubmitSurvey(r.Context(), actorOf(r), in))
}

func (h *Handler) deleteSurvey(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	h.message(w, r, "Đã xóa bài khảo sát thành công! Bạn có thể thực hiện lại.", h.svc.DeleteSurvey(r.Context(), actorOf(r), id))
}

func (h *Handler) feedback(w http.ResponseWriter, r *http.Request) {
	out, err := h.svc.Feedback(r.Context(), actorOf(r), studentQuery(r))
	h.reply(w, r, out, err)
}

func (h *Handler) submitFeedback(w http.ResponseWriter, r *http.Request) {
	var in FeedbackInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	out, created, err := h.svc.SubmitFeedback(r.Context(), actorOf(r), in)
	h.reply(w, r, map[string]any{"feedback": out, "created": created,
		"message": "Đã ghi nhận feedback thành công! Bạn có thể điều chỉnh bất cứ lúc nào trong thời gian đợt mở."}, err)
}

func (h *Handler) deleteFeedback(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	h.message(w, r, "Đã xóa feedback thành công! Trạng thái đã được đặt lại ban đầu.", h.svc.DeleteFeedback(r.Context(), actorOf(r), id))
}

// ───── Kế toán: Học viên báo đã đóng học phí ─────

func (h *Handler) paymentReports(w http.ResponseWriter, r *http.Request) {
	out, err := h.svc.PaymentReports(r.Context(), actorOf(r), r.URL.Query().Get("status"))
	h.reply(w, r, map[string]any{"data": out}, err)
}

func (h *Handler) pendingReports(w http.ResponseWriter, r *http.Request) {
	n, err := h.svc.PendingReports(r.Context(), actorOf(r))
	h.reply(w, r, map[string]int{"count": n}, err)
}

func (h *Handler) confirmReport(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	h.message(w, r, "Đã xác nhận, học viên được báo qua hộp thư.", h.svc.ConfirmReport(r.Context(), actorOf(r), id))
}

func (h *Handler) rejectReport(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	var in struct {
		Reason string `json:"reason"`
	}
	if !ok || !httpx.Decode(w, r, &in) {
		return
	}
	h.message(w, r, "Đã từ chối, học viên được báo qua hộp thư.", h.svc.RejectReport(r.Context(), actorOf(r), id, in.Reason))
}
