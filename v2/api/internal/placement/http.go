package placement

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
	"github.com/redis/go-redis/v9"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/httpx"
)

// Đề có thể dài (tới 500 câu kèm đoạn đọc) nên nhận body lớn hơn mức 64 KB chung.
const (
	maxTestBody   = 2 << 20
	maxSubmitBody = 512 << 10
)

// Handler: API module test đầu vào & học thử.
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

func (h *Handler) rdb() *redis.Client {
	if h.svc == nil {
		return nil
	}
	return h.svc.rdb
}

// Routes: API nội bộ (cần đăng nhập + quyền).
func (h *Handler) Routes(r chi.Router) {
	r.Route("/placement-tests", func(r chi.Router) {
		r.With(iam.Require("placement_test.view")).Get("/", h.listTests)
		r.With(iam.RequireAny("placement_test.view", "placement_test.create", "placement_test.update")).Get("/options", h.options)
		r.With(iam.RequireAny("placement_test.view", "placement_test.grade", "entrance_test.grade")).Get("/rubric", h.rubric)
		r.With(iam.Require("placement_test.create")).Post("/", h.createTest)
		r.With(iam.Require("placement_test.view")).Get("/{id}", h.getTest)
		r.With(iam.Require("placement_test.update")).Put("/{id}", h.updateTest)
		r.With(iam.Require("placement_test.create")).Post("/{id}/duplicate", h.duplicateTest)
		r.With(iam.Require("placement_test.update")).Post("/{id}/toggle-active", h.toggleTest)
		r.With(iam.Require("placement_test.delete")).Delete("/{id}", h.deleteTest)
		r.With(iam.Require("placement_test.distribute")).Post("/{id}/distribute", h.distribute)
		r.With(iam.Require("placement_test.view")).Get("/{id}/invitations", h.listInvitations)
		r.With(iam.RequireAny("placement_test.distribute", "placement_test.send", "entrance_test.send")).Post("/{id}/invitations", h.createInvitation)
	})
	r.With(iam.RequireAny("placement_test.distribute", "placement_test.send", "entrance_test.send")).
		Post("/placement-invitations/{id}/revoke", h.revokeInvitation)
	r.Route("/placement-submissions", func(r chi.Router) {
		r.With(iam.Require("placement_test.view")).Get("/", h.listSubmissions)
		r.With(iam.Require("placement_test.grade")).Get("/{id}", h.getGrading)
		r.With(iam.Require("placement_test.grade")).Post("/{id}/grade", h.grade)
		r.With(iam.RequireAny("placement_test.view", "placement_test.grade", "entrance_test.view")).Get("/{id}/scorecard", h.scorecard)
	})
	r.Route("/trial-bookings", func(r chi.Router) {
		r.With(iam.Require("class.view")).Get("/", h.schedule)
		r.With(iam.Require("lead.stage_forward")).Post("/", h.bookTrial)
		r.With(iam.Require("lead.stage_forward")).Post("/{id}/cancel", h.cancelTrial)
		r.With(iam.Require("lead.view")).Get("/lead/{id}", h.leadTrials)
		r.With(iam.RequireAny("portal.teacher", "portal.assistant", "lead.trial_feedback")).Get("/teacher", h.teacherTrials)
		r.With(iam.RequireAny("portal.teacher", "portal.assistant", "lead.trial_feedback")).Post("/{id}/feedback", h.trialFeedback)
	})
}

// PublicPrefix: route làm bài công khai, chỉ vào được bằng token của link mời (không đăng nhập), có giới hạn tần suất.
const PublicPrefix = "/public/placement"

// PublicRoutes: GET mở đề, POST nộp bài.
func (h *Handler) PublicRoutes(r chi.Router) {
	r.Route(PublicPrefix, func(r chi.Router) {
		r.With(h.limit("open", openPerMinute)).Get("/{token}", h.publicOpen)
		r.With(h.limit("submit", submitPerMinut)).Post("/{token}/submit", h.publicSubmit)
	})
}

func (h *Handler) fail(w http.ResponseWriter, r *http.Request, err error) { iam.Fail(w, r, h.log, err) }

func pathID(w http.ResponseWriter, r *http.Request) (uuid.UUID, bool) {
	id, err := uuid.Parse(chi.URLParam(r, "id"))
	if err != nil {
		httpx.Error(w, http.StatusNotFound, "not_found", "Không tìm thấy.")
		return uuid.Nil, false
	}
	return id, true
}

// decode: như httpx.Decode nhưng cho giới hạn body riêng.
func decode(w http.ResponseWriter, r *http.Request, limit int64, dst any) bool {
	if limit <= 64<<10 {
		return httpx.Decode(w, r, dst)
	}
	if ct := r.Header.Get("Content-Type"); !strings.HasPrefix(ct, "application/json") {
		httpx.Error(w, http.StatusUnsupportedMediaType, "unsupported_media_type", "Cần gửi JSON.")
		return false
	}
	dec := json.NewDecoder(http.MaxBytesReader(w, r.Body, limit))
	dec.DisallowUnknownFields()
	if err := dec.Decode(dst); err != nil {
		var tooBig *http.MaxBytesError
		if errors.As(err, &tooBig) {
			httpx.Error(w, http.StatusRequestEntityTooLarge, "too_large", "Dữ liệu gửi lên quá lớn.")
			return false
		}
		httpx.Error(w, http.StatusBadRequest, "bad_request", "Dữ liệu gửi lên không hợp lệ.")
		return false
	}
	if err := dec.Decode(&struct{}{}); !errors.Is(err, io.EOF) {
		httpx.Error(w, http.StatusBadRequest, "bad_request", "Dữ liệu gửi lên không hợp lệ.")
		return false
	}
	return true
}

func qInt(r *http.Request, key string) int {
	n, _ := strconv.Atoi(r.URL.Query().Get(key))
	return n
}

type withMessage struct {
	Data    any    `json:"data"`
	Message string `json:"message"`
}

// ---- Ngân hàng đề ----

func (h *Handler) listTests(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	out, err := h.svc.ListTests(r.Context(), iam.AccessFrom(r.Context()), TestFilter{
		Search: q.Get("search"), GradeGroup: q.Get("grade_group"), Status: q.Get("status"),
		Page: qInt(r, "page"), PerPage: qInt(r, "per_page"),
	})
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, out)
}

func (h *Handler) options(w http.ResponseWriter, _ *http.Request) {
	httpx.JSON(w, http.StatusOK, h.svc.Options())
}

func (h *Handler) rubric(w http.ResponseWriter, _ *http.Request) {
	httpx.JSON(w, http.StatusOK, Config())
}

func (h *Handler) getTest(w http.ResponseWriter, r *http.Request) {
	id, ok := pathID(w, r)
	if !ok {
		return
	}
	t, err := h.svc.GetTest(r.Context(), id)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, t)
}

func (h *Handler) createTest(w http.ResponseWriter, r *http.Request) {
	var in TestInput
	if !decode(w, r, maxTestBody, &in) {
		return
	}
	t, msg, err := h.svc.CreateTest(r.Context(), iam.AccessFrom(r.Context()), in)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusCreated, withMessage{t, msg})
}

func (h *Handler) updateTest(w http.ResponseWriter, r *http.Request) {
	id, ok := pathID(w, r)
	if !ok {
		return
	}
	var in TestInput
	if !decode(w, r, maxTestBody, &in) {
		return
	}
	t, msg, err := h.svc.UpdateTest(r.Context(), iam.AccessFrom(r.Context()), id, in)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, withMessage{t, msg})
}

func (h *Handler) duplicateTest(w http.ResponseWriter, r *http.Request) {
	id, ok := pathID(w, r)
	if !ok {
		return
	}
	t, msg, err := h.svc.DuplicateTest(r.Context(), iam.AccessFrom(r.Context()), id)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusCreated, withMessage{t, msg})
}

func (h *Handler) toggleTest(w http.ResponseWriter, r *http.Request) {
	id, ok := pathID(w, r)
	if !ok {
		return
	}
	t, msg, err := h.svc.ToggleActive(r.Context(), iam.AccessFrom(r.Context()), id)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, withMessage{t, msg})
}

func (h *Handler) deleteTest(w http.ResponseWriter, r *http.Request) {
	id, ok := pathID(w, r)
	if !ok {
		return
	}
	msg, err := h.svc.DeleteTest(r.Context(), iam.AccessFrom(r.Context()), id)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, withMessage{nil, msg})
}

// ---- Link mời ----

func (h *Handler) distribute(w http.ResponseWriter, r *http.Request) {
	id, ok := pathID(w, r)
	if !ok {
		return
	}
	issued, msg, err := h.svc.Distribute(r.Context(), iam.AccessFrom(r.Context()), id)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusCreated, withMessage{issued, msg})
}

func (h *Handler) listInvitations(w http.ResponseWriter, r *http.Request) {
	id, ok := pathID(w, r)
	if !ok {
		return
	}
	out, err := h.svc.ListInvitations(r.Context(), id)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, map[string]any{"invitations": out})
}

func (h *Handler) createInvitation(w http.ResponseWriter, r *http.Request) {
	id, ok := pathID(w, r)
	if !ok {
		return
	}
	var in InvitationInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	issued, err := h.svc.CreateInvitation(r.Context(), iam.AccessFrom(r.Context()), id, in)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusCreated, issued)
}

func (h *Handler) revokeInvitation(w http.ResponseWriter, r *http.Request) {
	id, ok := pathID(w, r)
	if !ok {
		return
	}
	v, err := h.svc.RevokeInvitation(r.Context(), iam.AccessFrom(r.Context()), id)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, v)
}

// ---- Bài làm & chấm ----

func (h *Handler) listSubmissions(w http.ResponseWriter, r *http.Request) {
	var testID *uuid.UUID
	if v := r.URL.Query().Get("test_id"); v != "" {
		id, err := uuid.Parse(v)
		if err != nil {
			h.fail(w, r, errTestNotFound)
			return
		}
		testID = &id
	}
	out, err := h.svc.ListSubmissions(r.Context(), iam.AccessFrom(r.Context()), testID, int32(qInt(r, "limit")))
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, out)
}

func (h *Handler) getGrading(w http.ResponseWriter, r *http.Request) {
	id, ok := pathID(w, r)
	if !ok {
		return
	}
	out, err := h.svc.GetGrading(r.Context(), iam.AccessFrom(r.Context()), id)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, out)
}

func (h *Handler) grade(w http.ResponseWriter, r *http.Request) {
	id, ok := pathID(w, r)
	if !ok {
		return
	}
	var in GradeInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	out, err := h.svc.Grade(r.Context(), iam.AccessFrom(r.Context()), id, in)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, out)
}

func (h *Handler) scorecard(w http.ResponseWriter, r *http.Request) {
	id, ok := pathID(w, r)
	if !ok {
		return
	}
	out, err := h.svc.GetScorecard(r.Context(), iam.AccessFrom(r.Context()), id)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, out)
}

// ---- Học thử ----

func trialFilter(r *http.Request) TrialFilter {
	q := r.URL.Query()
	return TrialFilter{Past: q.Get("scope") == "past", Status: q.Get("status"), Page: qInt(r, "page"), PerPage: qInt(r, "per_page")}
}

func (h *Handler) schedule(w http.ResponseWriter, r *http.Request) {
	out, err := h.svc.Schedule(r.Context(), iam.AccessFrom(r.Context()), trialFilter(r))
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, out)
}

func (h *Handler) teacherTrials(w http.ResponseWriter, r *http.Request) {
	out, err := h.svc.TeacherTrials(r.Context(), iam.AccessFrom(r.Context()), trialFilter(r))
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, out)
}

func (h *Handler) leadTrials(w http.ResponseWriter, r *http.Request) {
	id, ok := pathID(w, r)
	if !ok {
		return
	}
	out, err := h.svc.GetLeadTrials(r.Context(), iam.AccessFrom(r.Context()), id)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, out)
}

func (h *Handler) bookTrial(w http.ResponseWriter, r *http.Request) {
	var in BookTrialInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	out, err := h.svc.BookTrial(r.Context(), iam.AccessFrom(r.Context()), in)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusCreated, out)
}

func (h *Handler) cancelTrial(w http.ResponseWriter, r *http.Request) {
	id, ok := pathID(w, r)
	if !ok {
		return
	}
	var in struct {
		Reason string `json:"reason"`
	}
	if !httpx.Decode(w, r, &in) {
		return
	}
	out, err := h.svc.CancelTrial(r.Context(), iam.AccessFrom(r.Context()), id, in.Reason)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, out)
}

func (h *Handler) trialFeedback(w http.ResponseWriter, r *http.Request) {
	id, ok := pathID(w, r)
	if !ok {
		return
	}
	var in FeedbackInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	out, msg, err := h.svc.TrialFeedback(r.Context(), iam.AccessFrom(r.Context()), id, in)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, withMessage{out, msg})
}

// ---- Làm bài công khai ----

func (h *Handler) publicOpen(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Cache-Control", "no-store")
	out, err := h.svc.OpenInvitation(r.Context(), chi.URLParam(r, "token"))
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, out)
}

func (h *Handler) publicSubmit(w http.ResponseWriter, r *http.Request) {
	var in SubmitInput
	if !decode(w, r, maxSubmitBody, &in) {
		return
	}
	out, err := h.svc.Submit(r.Context(), chi.URLParam(r, "token"), in)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusCreated, out)
}
