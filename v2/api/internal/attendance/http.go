package attendance

import (
	"encoding/csv"
	"errors"
	"log/slog"
	"net/http"
	"strconv"
	"sync"
	"time"

	"github.com/go-chi/chi/v5"
	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/filestore"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/httpx"
)

type Handler struct {
	svc   *Service
	log   *slog.Logger
	punch *limiter
}

func NewHandler(svc *Service, log *slog.Logger) *Handler {
	return &Handler{svc: svc, log: log, punch: newLimiter(20, time.Minute)}
}

// Routes:
//   - /attendance/me/*: chấm công của tôi, lịch sử công, đơn chấm công & nghỉ (my-attendance.*, can:portal.staff).
//   - Duyệt đơn chấm công & nghỉ: ở hộp "Việc cần duyệt" chung (/approvals, nguồn ApprovalSource) như Laravel.
//   - /attendance/daily/*: chấm công hằng ngày (staff-attendance.*, can:staff_checkin.view).
//   - /timesheets/*: giờ dạy giáo viên, chấm tay, lịch sử đồng bộ (payroll.timesheets.*).
func (h *Handler) Routes(r chi.Router) {
	r.Route("/attendance", func(r chi.Router) {
		r.Route("/me", func(r chi.Router) {
			r.Use(iam.Require(PermPortalStaff))
			r.Get("/", h.home)
			r.With(h.punch.middleware).Post("/punch", h.doPunch)
			r.Get("/history", h.history)
			r.Get("/requests", h.myRequests)
			r.Post("/requests", h.submit)
			r.Post("/requests/{id}/cancel", h.cancel)
		})
		r.Route("/daily", func(r chi.Router) {
			r.Use(iam.Require(PermCheckinView))
			r.Get("/", h.daily)
			r.Get("/{id}", h.detail)
		})
	})
	r.Route("/timesheets", func(r chi.Router) {
		r.With(iam.RequireAny(PermTimesheetView, PermPayrollOwn)).Get("/teachers", h.teachers)
		r.With(iam.Require(PermTimesheetView)).Post("/teachers/bulk-review", h.bulkReview)
		r.With(iam.Require(PermTimesheetView)).Post("/teachers/{id}/review", h.review)
		r.With(iam.Require(PermManualRecord)).Put("/teachers/{id}/adjust", h.adjust)
		r.With(iam.Require(PermTimesheetView)).Post("/sessions/{id}/confirm", h.confirm)
		r.With(iam.Require(PermManualRecord)).Get("/manual", h.manualForm)
		r.With(iam.Require(PermManualRecord)).Post("/manual", h.storeManual)
		r.With(iam.Require(PermSync)).Get("/sync-history", h.syncHistory)
		r.With(iam.Require(PermSync)).Get("/sync-history/{id}/errors", h.syncErrors)
	})
}

func (h *Handler) fail(w http.ResponseWriter, r *http.Request, err error) { iam.Fail(w, r, h.log, err) }

func (h *Handler) reply(w http.ResponseWriter, r *http.Request, v any, err error) {
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, v)
}

func (h *Handler) message(w http.ResponseWriter, r *http.Request, msg string, err error) {
	h.reply(w, r, map[string]any{"message": msg}, err)
}

func idParam(w http.ResponseWriter, r *http.Request, h *Handler, missing error) (uuid.UUID, bool) {
	id, err := uuid.Parse(chi.URLParam(r, "id"))
	if err != nil {
		h.fail(w, r, missing)
		return uuid.Nil, false
	}
	return id, true
}

func qInt(r *http.Request, k string) int {
	n, _ := strconv.Atoi(r.URL.Query().Get(k))
	return n
}

func access(r *http.Request) *iam.Access { return iam.AccessFrom(r.Context()) }

// ───────────── Chấm công của tôi ─────────────

func (h *Handler) home(w http.ResponseWriter, r *http.Request) {
	out, err := h.svc.Home(r.Context(), access(r))
	h.reply(w, r, out, err)
}

func floatValue(v string) (*float64, bool) {
	if v == "" {
		return nil, true
	}
	f, err := strconv.ParseFloat(v, 64)
	if err != nil {
		return nil, false
	}
	return &f, true
}

// doPunch: multipart gồm kind (in / out), latitude, longitude, accuracy và ảnh khuôn mặt ở trường file (hoặc photo).
func (h *Handler) doPunch(w http.ResponseWriter, r *http.Request) {
	form, err := filestore.ReadMultipart(w, r, PhotoPolicy.MaxBytes, PhotoPolicy.TooLarge)
	if err != nil {
		var ve *filestore.ValidationError
		if errors.As(err, &ve) {
			err = iam.Invalid(ve.Message)
		}
		h.fail(w, r, err)
		return
	}
	defer form.Close()
	in := PunchInput{Kind: form.Value("kind"), Photo: form.File("file")}
	if in.Photo == nil {
		in.Photo = form.File("photo")
	}
	var ok1, ok2, ok3 bool
	in.Latitude, ok1 = floatValue(form.Value("latitude"))
	in.Longitude, ok2 = floatValue(form.Value("longitude"))
	in.Accuracy, ok3 = floatValue(form.Value("accuracy"))
	if !ok1 || !ok2 || !ok3 {
		h.fail(w, r, iam.Invalid("Vị trí GPS không hợp lệ."))
		return
	}
	out, err := h.svc.Punch(r.Context(), access(r), in)
	h.reply(w, r, out, err)
}

func (h *Handler) history(w http.ResponseWriter, r *http.Request) {
	out, err := h.svc.History(r.Context(), access(r), r.URL.Query().Get("month"))
	h.reply(w, r, out, err)
}

func (h *Handler) myRequests(w http.ResponseWriter, r *http.Request) {
	out, err := h.svc.MyRequests(r.Context(), access(r))
	h.reply(w, r, out, err)
}

func (h *Handler) submit(w http.ResponseWriter, r *http.Request) {
	var in RequestInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	msg, err := h.svc.Submit(r.Context(), access(r), in)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusCreated, map[string]any{"message": msg})
}

func (h *Handler) cancel(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r, h, ErrRequestNotFound)
	if !ok {
		return
	}
	msg, err := h.svc.Cancel(r.Context(), access(r), id)
	h.message(w, r, msg, err)
}

// ───────────── Duyệt đơn ─────────────

// ───────────── Chấm công hằng ngày ─────────────

func (h *Handler) daily(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	out, err := h.svc.Daily(r.Context(), access(r), DailyFilter{View: q.Get("view"), Date: q.Get("date"), Month: q.Get("month"),
		BranchID: q.Get("branch_id"), Status: q.Get("status"), Search: q.Get("search"), UserID: q.Get("user_id"),
		Page: qInt(r, "page"), PerPage: qInt(r, "per_page")})
	h.reply(w, r, out, err)
}

func (h *Handler) detail(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r, h, ErrNotFound)
	if !ok {
		return
	}
	out, err := h.svc.Detail(r.Context(), access(r), id)
	h.reply(w, r, out, err)
}

// ───────────── Giờ dạy giáo viên ─────────────

func (h *Handler) teachers(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	out, err := h.svc.TeacherTimesheets(r.Context(), access(r), TimesheetFilter{Month: q.Get("month"), Status: q.Get("status"),
		Type: q.Get("type"), Search: q.Get("search"), BranchID: q.Get("branch_id"), ClassID: q.Get("class_id"),
		UserID: q.Get("user_id"), Day: q.Get("day"), Page: qInt(r, "page"), PerPage: qInt(r, "per_page")})
	h.reply(w, r, out, err)
}

func (h *Handler) review(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r, h, ErrTimesheetMissing)
	if !ok {
		return
	}
	var in ReviewInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	msg, err := h.svc.Review(r.Context(), access(r), id, in)
	h.message(w, r, msg, err)
}

func (h *Handler) bulkReview(w http.ResponseWriter, r *http.Request) {
	var in struct {
		IDs []uuid.UUID `json:"ids"`
	}
	if !httpx.Decode(w, r, &in) {
		return
	}
	msg, err := h.svc.BulkReview(r.Context(), access(r), in.IDs)
	h.message(w, r, msg, err)
}

func (h *Handler) adjust(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r, h, ErrTimesheetMissing)
	if !ok {
		return
	}
	var in AdjustInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	msg, err := h.svc.Adjust(r.Context(), access(r), id, in)
	h.message(w, r, msg, err)
}

func (h *Handler) confirm(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r, h, ErrSessionMissing)
	if !ok {
		return
	}
	msg, err := h.svc.ConfirmSession(r.Context(), access(r), id)
	h.message(w, r, msg, err)
}

func (h *Handler) manualForm(w http.ResponseWriter, r *http.Request) {
	out, err := h.svc.Manual(r.Context(), access(r))
	h.reply(w, r, out, err)
}

func (h *Handler) storeManual(w http.ResponseWriter, r *http.Request) {
	var in ManualInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	msg, err := h.svc.StoreManual(r.Context(), access(r), in)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusCreated, map[string]any{"message": msg})
}

func (h *Handler) syncHistory(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	out, err := h.svc.SyncHistory(r.Context(), SyncFilter{From: q.Get("from"), To: q.Get("to"), Status: q.Get("status"),
		Page: qInt(r, "page"), PerPage: qInt(r, "per_page")})
	h.reply(w, r, out, err)
}

func (h *Handler) syncErrors(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r, h, ErrSyncLogMissing)
	if !ok {
		return
	}
	sheet, err := h.svc.SyncErrors(r.Context(), id)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	// CSV UTF-8 có BOM (mở thẳng bằng Excel).
	w.Header().Set("Content-Type", "text/csv; charset=UTF-8")
	w.Header().Set("Content-Disposition", `attachment; filename="`+sheet.Name+`"`)
	_, _ = w.Write([]byte("\xEF\xBB\xBF"))
	_ = csv.NewWriter(w).WriteAll(sheet.Rows)
}

// ───────────── Giới hạn lượt chấm ─────────────

// limiter: tối đa limit lượt chấm công mỗi người trong một cửa sổ (throttle:20,1 của Laravel), giữ trong bộ nhớ tiến trình.
type limiter struct {
	mu     sync.Mutex
	limit  int
	window time.Duration
	start  time.Time
	hits   map[string]int
}

func newLimiter(limit int, window time.Duration) *limiter {
	return &limiter{limit: limit, window: window, hits: map[string]int{}}
}

func (l *limiter) allow(key string, now time.Time) bool {
	l.mu.Lock()
	defer l.mu.Unlock()
	if now.Sub(l.start) >= l.window {
		l.start, l.hits = now, map[string]int{}
	}
	l.hits[key]++
	return l.hits[key] <= l.limit
}

func (l *limiter) middleware(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		key := httpx.ClientIP(r)
		if a := iam.AccessFrom(r.Context()); a != nil {
			key = a.UserID.String()
		}
		if !l.allow(key, time.Now()) {
			w.Header().Set("Retry-After", "60")
			httpx.Error(w, http.StatusTooManyRequests, "too_many_requests", "Bạn thao tác quá nhanh, hãy thử lại sau ít phút.")
			return
		}
		next.ServeHTTP(w, r)
	})
}
