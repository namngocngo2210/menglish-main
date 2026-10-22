package payroll

import (
	"encoding/csv"
	"errors"
	"log/slog"
	"mime"
	"net/http"
	"strconv"
	"strings"

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

// Routes: /payroll/* (route payroll.* trừ màn chấm công), /portal/my-salary, /penalties/*.
// Đăng ký đường dẫn đầy đủ (không Route("/payroll")) để phân hệ Chấm công gắn /payroll/timesheets/* song song.
func (h *Handler) Routes(r chi.Router) {
	r.With(iam.Require(PermView)).Get("/payroll/periods", h.periods)
	r.With(iam.Require(PermCreate)).Post("/payroll/periods", h.createPeriod)
	r.With(iam.Require(PermView)).Get("/payroll/periods/{id}", h.period)
	r.With(iam.Require(PermApprove)).Post("/payroll/periods/{id}/approve", h.approve)
	r.With(iam.Require(PermMarkPaid)).Post("/payroll/periods/{id}/mark-paid", h.markPaid)
	r.With(iam.Require(PermCalculate)).Post("/payroll/periods/{id}/calculate", h.calculate)
	r.With(iam.Require(PermView)).Get("/payroll/periods/{id}/export", h.export)
	r.With(iam.Require(PermView)).Get("/payroll/periods/{id}/departments/{department}", h.department)
	r.With(iam.Require(PermView)).Get("/payroll/records/{id}", h.record)
	r.With(iam.Require(PermEdit)).Post("/payroll/records/{id}/adjust", h.adjust)
	r.With(iam.Require(PermEdit)).Post("/payroll/records/{id}/update", h.foreignPay)
	r.With(iam.Require(PermKpiView)).Get("/payroll/kpi-leaderboard", h.leaderboard)

	r.With(iam.Require(PermRates)).Get("/payroll/config/settings", h.settings)
	r.With(iam.Require(PermRates)).Post("/payroll/config/settings", h.saveSettings)
	r.With(iam.Require(PermRates)).Get("/payroll/config/teacher-rates", h.rates)
	r.With(iam.Require(PermRates)).Post("/payroll/config/teacher-rates", h.saveRankRates)
	r.With(iam.Require(PermRates)).Post("/payroll/config/teacher-rates/personal", h.addPersonalRate)
	r.With(iam.Require(PermTiers)).Get("/payroll/config/commission-tiers", h.tiers)
	r.With(iam.Require(PermTiers)).Post("/payroll/config/commission-tiers", h.createTier)
	r.With(iam.Require(PermTiers)).Put("/payroll/config/commission-tiers/{id}", h.updateTier)
	r.With(iam.Require(PermTiers)).Delete("/payroll/config/commission-tiers/{id}", h.retireTier)
	r.With(iam.Require(PermTiers)).Post("/payroll/config/renewal-bonus", h.saveRenewal)

	r.With(iam.RequireAny(PermViewOwn, PermPortalStaff, PermView)).Get("/portal/my-salary", h.mySalary)

	r.With(iam.RequireAny(PermViolationView, PermPortalStaff)).Get("/penalties", h.penalties)
	r.With(iam.Require(PermViolationCreate)).Post("/penalties", h.createPenalty)
	r.With(iam.RequireAny(PermViolationView, PermPortalStaff)).Post("/penalties/{id}/explain", h.explain)
	r.With(iam.RequireAny(PermConfirmFine, PermConfirmError)).Post("/penalties/{id}/confirm", h.decide)
	r.With(iam.Require(PermViolationMarkPaid)).Post("/penalties/{id}/mark-paid", h.penaltyPaid)
	r.With(iam.Require(PermViolationResolve)).Post("/penalties/{id}/resolve", h.resolve)
	r.With(iam.Require(PermViolationCancel)).Post("/penalties/{id}/cancel", h.cancel)
	r.With(iam.Require(PermViolationResolve)).Post("/penalties/{id}/remedy", h.remedy)
}

func (h *Handler) fail(w http.ResponseWriter, r *http.Request, err error) {
	var ve *filestore.ValidationError
	if errors.As(err, &ve) {
		err = iam.Invalid(ve.Message)
	}
	iam.Fail(w, r, h.log, err)
}

func (h *Handler) send(w http.ResponseWriter, r *http.Request, v any, err error) {
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, v)
}

func (h *Handler) message(w http.ResponseWriter, r *http.Request, msg string, err error) {
	h.send(w, r, map[string]any{"message": msg}, err)
}

func (h *Handler) id(w http.ResponseWriter, r *http.Request, notFound error) (uuid.UUID, bool) {
	id, err := uuid.Parse(chi.URLParam(r, "id"))
	if err != nil {
		h.fail(w, r, notFound)
		return uuid.Nil, false
	}
	return id, true
}

func intParam(r *http.Request, key string) int {
	n, _ := strconv.Atoi(r.URL.Query().Get(key))
	return n
}

func uuidParam(r *http.Request, key string) *uuid.UUID {
	if id, err := uuid.Parse(strings.TrimSpace(r.URL.Query().Get(key))); err == nil {
		return &id
	}
	return nil
}

func access(r *http.Request) *iam.Access { return iam.AccessFrom(r.Context()) }

// ───────────── Kỳ lương ─────────────

func (h *Handler) periods(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	out, err := h.svc.Periods(r.Context(), access(r), q.Get("search"), q.Get("status"), intParam(r, "page"))
	h.send(w, r, out, err)
}

func (h *Handler) createPeriod(w http.ResponseWriter, r *http.Request) {
	var in PeriodInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	id, msg, err := h.svc.CreatePeriod(r.Context(), access(r), in)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusCreated, map[string]any{"id": id, "message": msg})
}

func (h *Handler) period(w http.ResponseWriter, r *http.Request) {
	id, ok := h.id(w, r, errPeriodNotFound)
	if !ok {
		return
	}
	q := r.URL.Query()
	out, err := h.svc.Period(r.Context(), access(r), id, PeriodFilter{Search: q.Get("search"), Type: q.Get("type"), Kpi: q.Get("kpi"),
		Page: intParam(r, "page")})
	h.send(w, r, out, err)
}

func (h *Handler) approve(w http.ResponseWriter, r *http.Request) {
	if id, ok := h.id(w, r, errPeriodNotFound); ok {
		msg, err := h.svc.ApprovePeriod(r.Context(), access(r), id)
		h.message(w, r, msg, err)
	}
}

func (h *Handler) markPaid(w http.ResponseWriter, r *http.Request) {
	id, ok := h.id(w, r, errPeriodNotFound)
	if !ok {
		return
	}
	msg, warn, err := h.svc.MarkPaid(r.Context(), access(r), id)
	h.send(w, r, map[string]any{"message": msg, "warning": warn}, err)
}

func (h *Handler) calculate(w http.ResponseWriter, r *http.Request) {
	if id, ok := h.id(w, r, errPeriodNotFound); ok {
		msg, err := h.svc.CalculatePeriod(r.Context(), access(r), id)
		h.message(w, r, msg, err)
	}
}

// export: CSV UTF-8 có BOM (mở thẳng bằng Excel) thay cho file .xlsx của Laravel.
func (h *Handler) export(w http.ResponseWriter, r *http.Request) {
	id, ok := h.id(w, r, errPeriodNotFound)
	if !ok {
		return
	}
	name, rows, err := h.svc.Export(r.Context(), access(r), id, r.URL.Query().Get("department"))
	if err != nil {
		h.fail(w, r, err)
		return
	}
	w.Header().Set("Content-Type", "text/csv; charset=UTF-8")
	w.Header().Set("Content-Disposition", `attachment; filename="`+name+`"`)
	_, _ = w.Write([]byte("\xEF\xBB\xBF"))
	_ = csv.NewWriter(w).WriteAll(rows)
}

func (h *Handler) department(w http.ResponseWriter, r *http.Request) {
	if id, ok := h.id(w, r, errPeriodNotFound); ok {
		out, err := h.svc.Department(r.Context(), access(r), id, chi.URLParam(r, "department"))
		h.send(w, r, out, err)
	}
}

func (h *Handler) record(w http.ResponseWriter, r *http.Request) {
	if id, ok := h.id(w, r, errRecordNotFound); ok {
		out, err := h.svc.Record(r.Context(), access(r), id)
		h.send(w, r, out, err)
	}
}

func (h *Handler) adjust(w http.ResponseWriter, r *http.Request) {
	id, ok := h.id(w, r, errRecordNotFound)
	if !ok {
		return
	}
	var in AdjustInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	msg, err := h.svc.AdjustRecord(r.Context(), access(r), id, in)
	h.message(w, r, msg, err)
}

func (h *Handler) foreignPay(w http.ResponseWriter, r *http.Request) {
	id, ok := h.id(w, r, errRecordNotFound)
	if !ok {
		return
	}
	var in ForeignPayInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	msg, err := h.svc.UpdateForeignPay(r.Context(), access(r), id, in)
	h.message(w, r, msg, err)
}

func (h *Handler) leaderboard(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	out, err := h.svc.Leaderboard(r.Context(), access(r), LeaderboardFilter{Period: q.Get("period"), Month: intParam(r, "month"),
		Year: intParam(r, "year"), BranchID: uuidParam(r, "branch_id"), KpiPage: intParam(r, "kpi_page"), SalesPage: intParam(r, "sales_page")})
	h.send(w, r, out, err)
}

func (h *Handler) mySalary(w http.ResponseWriter, r *http.Request) {
	var pid *uuid.UUID
	if v := r.URL.Query().Get("period_id"); v != "" {
		id, err := uuid.Parse(v)
		if err != nil {
			h.fail(w, r, errRecordNotFound)
			return
		}
		pid = &id
	}
	out, err := h.svc.MySalary(r.Context(), access(r), pid)
	h.send(w, r, out, err)
}

// ───────────── Thiết lập ─────────────

func (h *Handler) settings(w http.ResponseWriter, r *http.Request) {
	out, err := h.svc.SettingsPage(r.Context())
	h.send(w, r, out, err)
}

func (h *Handler) saveSettings(w http.ResponseWriter, r *http.Request) {
	var in SettingsInput
	if httpx.Decode(w, r, &in) {
		msg, err := h.svc.SaveSettings(r.Context(), access(r), in)
		h.message(w, r, msg, err)
	}
}

func (h *Handler) saveRenewal(w http.ResponseWriter, r *http.Request) {
	var in RenewalInput
	if httpx.Decode(w, r, &in) {
		msg, err := h.svc.SaveRenewal(r.Context(), access(r), in)
		h.message(w, r, msg, err)
	}
}

func (h *Handler) rates(w http.ResponseWriter, r *http.Request) {
	out, err := h.svc.RatesPage(r.Context(), access(r), uuidParam(r, "teacher_id"), intParam(r, "page"))
	h.send(w, r, out, err)
}

func (h *Handler) saveRankRates(w http.ResponseWriter, r *http.Request) {
	var in RankRatesInput
	if httpx.Decode(w, r, &in) {
		msg, err := h.svc.SaveRankRates(r.Context(), access(r), in)
		h.message(w, r, msg, err)
	}
}

func (h *Handler) addPersonalRate(w http.ResponseWriter, r *http.Request) {
	var in PersonalRateInput
	if httpx.Decode(w, r, &in) {
		msg, err := h.svc.AddPersonalRate(r.Context(), access(r), in)
		h.message(w, r, msg, err)
	}
}

func (h *Handler) tiers(w http.ResponseWriter, r *http.Request) {
	out, err := h.svc.TiersPage(r.Context(), r.URL.Query().Get("as_of"), intParam(r, "page"))
	h.send(w, r, out, err)
}

func (h *Handler) createTier(w http.ResponseWriter, r *http.Request) {
	var in TierInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	msg, err := h.svc.CreateTier(r.Context(), access(r), in)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusCreated, map[string]any{"message": msg})
}

func (h *Handler) updateTier(w http.ResponseWriter, r *http.Request) {
	id, ok := h.id(w, r, errTierNotFound)
	if !ok {
		return
	}
	var in TierInput
	if httpx.Decode(w, r, &in) {
		msg, err := h.svc.UpdateTier(r.Context(), access(r), id, in)
		h.message(w, r, msg, err)
	}
}

func (h *Handler) retireTier(w http.ResponseWriter, r *http.Request) {
	if id, ok := h.id(w, r, errTierNotFound); ok {
		msg, err := h.svc.RetireTier(r.Context(), access(r), id)
		h.message(w, r, msg, err)
	}
}

// ───────────── Biên bản vi phạm ─────────────

func (h *Handler) penalties(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	out, err := h.svc.Penalties(r.Context(), access(r), PenaltyFilter{Search: q.Get("search"), Status: q.Get("status"),
		Category: q.Get("error_category"), Step: q.Get("step"), From: q.Get("from"), To: q.Get("to"), Page: intParam(r, "page")})
	h.send(w, r, out, err)
}

// createPenalty: JSON, hoặc multipart (các trường + file "evidence").
func (h *Handler) createPenalty(w http.ResponseWriter, r *http.Request) {
	var in PenaltyInput
	var evidence *filestore.Upload
	if mt, _, _ := mime.ParseMediaType(r.Header.Get("Content-Type")); mt == "multipart/form-data" {
		form, err := filestore.ReadMultipart(w, r, EvidencePolicy.MaxBytes, EvidencePolicy.TooLarge)
		if err != nil {
			h.fail(w, r, err)
			return
		}
		defer form.Close()
		if evidence = form.File("evidence"); evidence == nil {
			evidence = form.File("file")
		}
		in.UserID, in.ErrorCategory, in.ViolationType = form.Value("user_id"), form.Value("error_category"), form.Value("violation_type")
		in.ViolationAt, in.ClassID = form.Value("violation_at"), form.Value("class_id")
		if v := strings.TrimSpace(form.Value("amount")); v != "" {
			n, err := strconv.ParseInt(v, 10, 64)
			if err != nil {
				h.fail(w, r, iam.Invalid("Số tiền phạt phải là số."))
				return
			}
			in.Amount = &n
		}
		if v := form.Value("notes"); v != "" {
			in.Notes = &v
		}
	} else if !httpx.Decode(w, r, &in) {
		return
	}
	msg, err := h.svc.CreatePenalty(r.Context(), access(r), in, evidence)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusCreated, map[string]any{"message": msg})
}

func (h *Handler) explain(w http.ResponseWriter, r *http.Request) {
	id, ok := h.id(w, r, errPenaltyNotFound)
	if !ok {
		return
	}
	var in struct {
		Explanation string `json:"explanation"`
	}
	if httpx.Decode(w, r, &in) {
		msg, err := h.svc.ExplainPenalty(r.Context(), access(r), id, in.Explanation)
		h.message(w, r, msg, err)
	}
}

func (h *Handler) decide(w http.ResponseWriter, r *http.Request) {
	id, ok := h.id(w, r, errPenaltyNotFound)
	if !ok {
		return
	}
	var in DecisionInput
	if httpx.Decode(w, r, &in) {
		msg, err := h.svc.DecidePenalty(r.Context(), access(r), id, in)
		h.message(w, r, msg, err)
	}
}

func (h *Handler) penaltyPaid(w http.ResponseWriter, r *http.Request) {
	if id, ok := h.id(w, r, errPenaltyNotFound); ok {
		msg, err := h.svc.MarkPenaltyPaid(r.Context(), access(r), id)
		h.message(w, r, msg, err)
	}
}

func (h *Handler) resolve(w http.ResponseWriter, r *http.Request) {
	if id, ok := h.id(w, r, errPenaltyNotFound); ok {
		msg, err := h.svc.ResolvePenalty(r.Context(), access(r), id)
		h.message(w, r, msg, err)
	}
}

func (h *Handler) cancel(w http.ResponseWriter, r *http.Request) {
	if id, ok := h.id(w, r, errPenaltyNotFound); ok {
		msg, err := h.svc.CancelPenalty(r.Context(), access(r), id)
		h.message(w, r, msg, err)
	}
}

func (h *Handler) remedy(w http.ResponseWriter, r *http.Request) {
	id, ok := h.id(w, r, errPenaltyNotFound)
	if !ok {
		return
	}
	var in struct {
		Note *string `json:"remedy_note"`
	}
	if httpx.Decode(w, r, &in) {
		msg, err := h.svc.RemedyPenalty(r.Context(), access(r), id, in.Note)
		h.message(w, r, msg, err)
	}
}
