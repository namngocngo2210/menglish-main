package finance

import (
	"encoding/csv"
	"log/slog"
	"net/http"

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

// Routes: /finance/* (route finance.* của Laravel: nhóm can:finance.view, thêm / sửa / xóa khoản chi thêm can:tuition.create).
func (h *Handler) Routes(r chi.Router) {
	r.Route("/finance", func(r chi.Router) {
		r.Use(iam.Require("finance.view"))
		r.Get("/expenses", h.expenses)
		r.Get("/expenses/export", h.exportExpenses)
		r.With(iam.Require(PermManage)).Post("/expenses", h.createExpense)
		r.With(iam.Require(PermManage)).Put("/expenses/{id}", h.updateExpense)
		r.With(iam.Require(PermManage)).Delete("/expenses/{id}", h.deleteExpense)
		r.Get("/reports/provisional-revenue", h.revenue)
		r.Get("/reports/provisional-revenue/export", h.exportRevenue)
	})
}

func (h *Handler) fail(w http.ResponseWriter, r *http.Request, err error) { iam.Fail(w, r, h.log, err) }

func filterOf(r *http.Request) ExpenseFilter {
	q := r.URL.Query()
	return ExpenseFilter{Month: q.Get("month"), BranchID: q.Get("branch_id"), Search: q.Get("search")}
}

func (h *Handler) expenses(w http.ResponseWriter, r *http.Request) {
	out, err := h.svc.Expenses(r.Context(), iam.AccessFrom(r.Context()), filterOf(r))
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, out)
}

func (h *Handler) createExpense(w http.ResponseWriter, r *http.Request) {
	var in ExpenseInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	msg, err := h.svc.CreateExpense(r.Context(), iam.AccessFrom(r.Context()), in)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusCreated, map[string]any{"message": msg})
}

func (h *Handler) updateExpense(w http.ResponseWriter, r *http.Request) {
	id, err := uuid.Parse(chi.URLParam(r, "id"))
	if err != nil {
		h.fail(w, r, ErrNotFound)
		return
	}
	var in ExpenseInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	msg, err := h.svc.UpdateExpense(r.Context(), iam.AccessFrom(r.Context()), id, in)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, map[string]any{"message": msg})
}

func (h *Handler) deleteExpense(w http.ResponseWriter, r *http.Request) {
	id, err := uuid.Parse(chi.URLParam(r, "id"))
	if err != nil {
		h.fail(w, r, ErrNotFound)
		return
	}
	msg, err := h.svc.DeleteExpense(r.Context(), iam.AccessFrom(r.Context()), id)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, map[string]any{"message": msg})
}

func (h *Handler) exportExpenses(w http.ResponseWriter, r *http.Request) {
	sheet, err := h.svc.ExportExpenses(r.Context(), iam.AccessFrom(r.Context()), filterOf(r))
	if err != nil {
		h.fail(w, r, err)
		return
	}
	writeCSV(w, sheet)
}

func (h *Handler) revenue(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	out, err := h.svc.ProvisionalRevenue(r.Context(), iam.AccessFrom(r.Context()), q.Get("month"), q.Get("branch_id"))
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, out)
}

func (h *Handler) exportRevenue(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	sheet, err := h.svc.ExportRevenue(r.Context(), iam.AccessFrom(r.Context()), q.Get("month"), q.Get("branch_id"))
	if err != nil {
		h.fail(w, r, err)
		return
	}
	writeCSV(w, sheet)
}

// writeCSV: CSV UTF-8 có BOM (mở thẳng bằng Excel) như StreamedResponse của Laravel.
func writeCSV(w http.ResponseWriter, s *Sheet) {
	w.Header().Set("Content-Type", "text/csv; charset=UTF-8")
	w.Header().Set("Content-Disposition", `attachment; filename="`+s.Name+`"`)
	_, _ = w.Write([]byte("\xEF\xBB\xBF"))
	cw := csv.NewWriter(w)
	_ = cw.WriteAll(s.Rows)
}
