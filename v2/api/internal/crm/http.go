package crm

import (
	"errors"
	"io"
	"log/slog"
	"net/http"
	"strconv"
	"strings"
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

// Routes gắn /crm vào router /api/v1. Cả nhóm cần lead.view như Laravel (prefix crm, can:lead.view); thao tác ghi
// gắn thêm quyền riêng. Chuyển giai đoạn kiểm quyền theo hướng trong service (stage_forward / stage_back / mark_lost).
func (h *Handler) Routes(r chi.Router) {
	r.Route("/crm", func(r chi.Router) {
		r.Use(iam.Require("lead.view"))
		r.Get("/options", h.options)
		r.Get("/counts", h.counts)
		r.Get("/pipeline", h.pipeline)
		r.Get("/waiting", h.waiting)
		r.Get("/won", h.won)
		r.Get("/lost", h.lost)
		r.Get("/leads", h.listLeads)
		r.Get("/leads/export", h.exportLeads)
		r.With(iam.Require("lead.create")).Post("/leads", h.createLead)
		r.With(iam.Require("lead.assign")).Post("/leads/bulk-reassign", h.bulkReassign)
		r.With(iam.Require("lead.delete")).Get("/deleted", h.listDeleted)
		r.Route("/leads/{id}", func(r chi.Router) {
			r.Get("/", h.getLead)
			r.With(iam.Require("lead.update")).Put("/", h.updateLead)
			r.Post("/stage", h.moveStage)
			r.Post("/next-stage", h.nextStage)
			r.Post("/cancel-closing", h.cancelClosing)
			r.With(iam.Require("lead.update")).Post("/notes", h.addNote)
			r.With(iam.Require("lead.update")).Post("/care-checklist", h.updateCare)
			r.With(iam.Require("lead.assign")).Post("/reassign", h.reassign)
			r.With(iam.Require("lead.delete")).Post("/restore", h.restore)
			r.With(iam.Require("lead.convert")).Post("/close", h.closeLead)
			r.With(iam.Require("student.assign_class")).Post("/assign-class", h.assignClass)
			r.With(iam.Require("lead.convert")).Get("/closing-options", h.closingChoices)
			r.With(iam.Require("student.assign_class")).Get("/class-options", h.waitingChoices)
		})
		r.Group(func(r chi.Router) {
			r.Use(iam.Require("lead.approve_transfer"))
			r.Get("/transfers", h.listTransfers)
			r.Post("/transfers/{id}/approve", h.approveTransfer)
			r.Post("/transfers/{id}/reject", h.rejectTransfer)
		})
		r.Group(func(r chi.Router) {
			r.Use(iam.Require("student.assign_class"))
			r.Get("/confirmations", h.listConfirmations)
			r.Post("/confirmations/{id}", h.confirm)
		})
		r.Group(func(r chi.Router) {
			r.Use(iam.Require("promotion.manage"))
			r.Get("/promotions", h.listPromotions)
			r.Post("/promotions", h.createPromotion)
			r.Get("/promotions/{id}", h.getPromotion)
			r.Put("/promotions/{id}", h.updatePromotion)
			r.Post("/promotions/{id}/toggle", h.togglePromotion)
		})
		r.Group(func(r chi.Router) {
			r.Use(iam.Require("report.view"))
			r.Get("/reports", h.report)
			r.Get("/reports/export", h.exportReport)
		})
		r.Group(func(r chi.Router) {
			r.Use(iam.Require("lead.create"))
			r.Get("/import/options", h.importOptions)
			r.Get("/import/template", h.importTemplate)
			r.Post("/import/preview", h.importPreview)
			r.Get("/import/{id}", h.getImport)
			r.Get("/import/{id}/errors", h.importErrors)
			r.Post("/import/{id}/commit", h.commitImport)
			r.Post("/import/{id}/cancel", h.cancelImport)
		})
	})
}

func actor(r *http.Request) *iam.Access { return iam.AccessFrom(r.Context()) }

func (h *Handler) fail(w http.ResponseWriter, r *http.Request, err error) { iam.Fail(w, r, h.log, err) }

func (h *Handler) ok(w http.ResponseWriter, r *http.Request, v any, err error) {
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, v)
}

func idParam(w http.ResponseWriter, r *http.Request) (uuid.UUID, bool) {
	id, err := uuid.Parse(chi.URLParam(r, "id"))
	if err != nil {
		httpx.Error(w, http.StatusNotFound, "not_found", "Không tìm thấy dữ liệu.")
		return uuid.Nil, false
	}
	return id, true
}

func qUUID(r *http.Request, key string) *uuid.UUID {
	if id, err := uuid.Parse(r.URL.Query().Get(key)); err == nil {
		return &id
	}
	return nil
}

func qInt(r *http.Request, key string) int {
	n, _ := strconv.Atoi(r.URL.Query().Get(key))
	return n
}

func qBool(r *http.Request, key string) bool {
	v := r.URL.Query().Get(key)
	return v == "1" || v == "true"
}

func qDate(r *http.Request, key string) *time.Time {
	v := strings.TrimSpace(r.URL.Query().Get(key))
	if v == "" {
		return nil
	}
	if t, err := time.ParseInLocation("2006-01-02", v, vnZone); err == nil {
		return &t
	}
	return nil
}

func listFilter(r *http.Request) ListFilter {
	q := r.URL.Query()
	return ListFilter{
		Search: q.Get("search"), BranchID: qUUID(r, "branch_id"), AssignedUserID: qUUID(r, "assigned_user_id"),
		Source: q.Get("source"), From: qDate(r, "from"), To: qDate(r, "to"), Stage: q.Get("stage"),
		Stale: qBool(r, "sla"), FollowUp: qBool(r, "follow_up"), TestToday: qBool(r, "test_today"),
		Page: qInt(r, "page"), PerPage: qInt(r, "per_page"),
	}
}

func (h *Handler) options(w http.ResponseWriter, r *http.Request) {
	o, err := h.svc.Options(r.Context(), actor(r))
	h.ok(w, r, o, err)
}

func (h *Handler) counts(w http.ResponseWriter, r *http.Request) {
	c, err := h.svc.ChipCounts(r.Context(), actor(r))
	h.ok(w, r, c, err)
}

func (h *Handler) pipeline(w http.ResponseWriter, r *http.Request) {
	p, err := h.svc.Pipeline(r.Context(), actor(r), listFilter(r))
	h.ok(w, r, p, err)
}

func (h *Handler) waiting(w http.ResponseWriter, r *http.Request) {
	rows, counts, err := h.svc.Waiting(r.Context(), actor(r))
	h.ok(w, r, map[string]any{"data": rows, "counts": counts}, err)
}

func (h *Handler) listLeads(w http.ResponseWriter, r *http.Request) {
	l, err := h.svc.ListLeads(r.Context(), actor(r), listFilter(r))
	h.ok(w, r, l, err)
}

func (h *Handler) won(w http.ResponseWriter, r *http.Request) {
	if f := r.URL.Query().Get("export"); f != "" {
		h.export(w, r, "won", f)
		return
	}
	l, err := h.svc.ListWon(r.Context(), actor(r), listFilter(r))
	h.ok(w, r, l, err)
}

func (h *Handler) lost(w http.ResponseWriter, r *http.Request) {
	if f := r.URL.Query().Get("export"); f != "" {
		h.export(w, r, "lost", f)
		return
	}
	l, err := h.svc.ListLost(r.Context(), actor(r), listFilter(r))
	h.ok(w, r, l, err)
}

func (h *Handler) exportLeads(w http.ResponseWriter, r *http.Request) {
	h.export(w, r, "all", r.URL.Query().Get("format"))
}

func (h *Handler) export(w http.ResponseWriter, r *http.Request, kind, format string) {
	t, err := h.svc.ExportLeads(r.Context(), actor(r), listFilter(r), kind)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	h.writeFile(w, r, t, format)
}

func (h *Handler) writeFile(w http.ResponseWriter, r *http.Request, t Table, format string) {
	if err := writeTable(w, t, format, true, time.Now()); err != nil {
		h.log.Error("crm: xuất file", "err", err, "path", r.URL.Path)
	}
}

func (h *Handler) createLead(w http.ResponseWriter, r *http.Request) {
	var in LeadInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	res, err := h.svc.CreateLead(r.Context(), actor(r), in)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusCreated, res)
}

func (h *Handler) getLead(w http.ResponseWriter, r *http.Request) {
	d, err := h.svc.Detail(r.Context(), actor(r), chi.URLParam(r, "id"))
	h.ok(w, r, d, err)
}

func (h *Handler) updateLead(w http.ResponseWriter, r *http.Request) {
	var in LeadInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	res, err := h.svc.UpdateLead(r.Context(), actor(r), chi.URLParam(r, "id"), in)
	h.ok(w, r, res, err)
}

func (h *Handler) moveStage(w http.ResponseWriter, r *http.Request) {
	var in StageInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	res, err := h.svc.MoveStage(r.Context(), actor(r), chi.URLParam(r, "id"), in)
	h.ok(w, r, res, err)
}

type reasonBody struct {
	Reason *string `json:"reason"`
}

func (h *Handler) nextStage(w http.ResponseWriter, r *http.Request) {
	var in reasonBody
	if !httpx.Decode(w, r, &in) {
		return
	}
	res, err := h.svc.NextStage(r.Context(), actor(r), chi.URLParam(r, "id"), in.Reason)
	h.ok(w, r, res, err)
}

func (h *Handler) cancelClosing(w http.ResponseWriter, r *http.Request) {
	var in reasonBody
	if !httpx.Decode(w, r, &in) {
		return
	}
	res, err := h.svc.CancelClosing(r.Context(), actor(r), chi.URLParam(r, "id"), deref(in.Reason))
	h.ok(w, r, res, err)
}

func (h *Handler) addNote(w http.ResponseWriter, r *http.Request) {
	var in NoteInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	res, err := h.svc.AddNote(r.Context(), actor(r), chi.URLParam(r, "id"), in)
	h.ok(w, r, res, err)
}

func (h *Handler) updateCare(w http.ResponseWriter, r *http.Request) {
	var in CareInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	res, err := h.svc.UpdateCare(r.Context(), actor(r), chi.URLParam(r, "id"), in)
	h.ok(w, r, res, err)
}

func (h *Handler) reassign(w http.ResponseWriter, r *http.Request) {
	var in ReassignInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	res, err := h.svc.Reassign(r.Context(), actor(r), chi.URLParam(r, "id"), in)
	h.ok(w, r, res, err)
}

func (h *Handler) bulkReassign(w http.ResponseWriter, r *http.Request) {
	var in BulkReassignInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	res, err := h.svc.BulkReassign(r.Context(), actor(r), in)
	h.ok(w, r, res, err)
}

func (h *Handler) listDeleted(w http.ResponseWriter, r *http.Request) {
	p, err := h.svc.ListDeleted(r.Context(), actor(r), r.URL.Query().Get("search"), qInt(r, "page"), qInt(r, "per_page"))
	h.ok(w, r, p, err)
}

func (h *Handler) restore(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	res, err := h.svc.Restore(r.Context(), actor(r), id)
	h.ok(w, r, res, err)
}

func (h *Handler) closeLead(w http.ResponseWriter, r *http.Request) {
	var in CloseInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	res, err := h.svc.Close(r.Context(), actor(r), chi.URLParam(r, "id"), in)
	h.ok(w, r, res, err)
}

func (h *Handler) assignClass(w http.ResponseWriter, r *http.Request) {
	var in struct {
		ClassID *uuid.UUID `json:"class_id"`
	}
	if !httpx.Decode(w, r, &in) {
		return
	}
	res, err := h.svc.AssignClass(r.Context(), actor(r), chi.URLParam(r, "id"), in.ClassID)
	h.ok(w, r, res, err)
}

func (h *Handler) listTransfers(w http.ResponseWriter, r *http.Request) {
	status := r.URL.Query().Get("status")
	if status == "" {
		status = "pending"
	}
	p, err := h.svc.ListTransfers(r.Context(), status, qInt(r, "page"), qInt(r, "per_page"))
	h.ok(w, r, p, err)
}

type noteBody struct {
	Note   *string `json:"note"`
	Reason *string `json:"reason"`
}

func (h *Handler) approveTransfer(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	var in noteBody
	if !httpx.Decode(w, r, &in) {
		return
	}
	res, err := h.svc.ApproveTransfer(r.Context(), actor(r), id, in.Note)
	h.ok(w, r, res, err)
}

func (h *Handler) rejectTransfer(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	var in noteBody
	if !httpx.Decode(w, r, &in) {
		return
	}
	res, err := h.svc.RejectTransfer(r.Context(), actor(r), id, deref(in.Reason))
	h.ok(w, r, res, err)
}

func (h *Handler) listConfirmations(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	l, err := h.svc.ListConfirmations(r.Context(), actor(r), ConfirmationFilter{
		Status: q.Get("status"), Search: q.Get("search"), BranchID: qUUID(r, "branch_id"), ClassID: qUUID(r, "class_id"),
		Page: qInt(r, "page"), PerPage: qInt(r, "per_page"),
	})
	h.ok(w, r, l, err)
}

func (h *Handler) confirm(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	var in ConfirmInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	res, err := h.svc.Confirm(r.Context(), actor(r), id, in)
	h.ok(w, r, res, err)
}

func (h *Handler) listPromotions(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	l, err := h.svc.ListPromotions(r.Context(), q.Get("tab"), q.Get("status"), q.Get("q"), qInt(r, "page"))
	h.ok(w, r, l, err)
}

func (h *Handler) createPromotion(w http.ResponseWriter, r *http.Request) {
	var in PromotionInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	res, err := h.svc.CreatePromotion(r.Context(), actor(r), in)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusCreated, res)
}

func (h *Handler) getPromotion(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	p, err := h.svc.GetPromotion(r.Context(), id)
	h.ok(w, r, p, err)
}

func (h *Handler) updatePromotion(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	var in PromotionInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	res, err := h.svc.UpdatePromotion(r.Context(), actor(r), id, in)
	h.ok(w, r, res, err)
}

func (h *Handler) togglePromotion(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	res, err := h.svc.TogglePromotion(r.Context(), actor(r), id)
	h.ok(w, r, res, err)
}

func reportFilter(r *http.Request) ReportFilter {
	q := r.URL.Query()
	return ReportFilter{Preset: q.Get("preset"), From: qDate(r, "start_date"), To: qDate(r, "end_date"), BranchID: qUUID(r, "branch_id")}
}

func (h *Handler) report(w http.ResponseWriter, r *http.Request) {
	rep, err := h.svc.Report(r.Context(), actor(r), reportFilter(r))
	h.ok(w, r, rep, err)
}

func (h *Handler) exportReport(w http.ResponseWriter, r *http.Request) {
	t, err := h.svc.ExportReps(r.Context(), actor(r), reportFilter(r))
	if err != nil {
		h.fail(w, r, err)
		return
	}
	h.writeFile(w, r, t, r.URL.Query().Get("format"))
}

func (h *Handler) importOptions(w http.ResponseWriter, r *http.Request) {
	o, err := h.svc.ImportOptions(r.Context(), actor(r))
	h.ok(w, r, o, err)
}

func (h *Handler) importTemplate(w http.ResponseWriter, r *http.Request) {
	if err := writeTable(w, ImportTemplate(), "xlsx", false, time.Now()); err != nil {
		h.log.Error("crm: file mẫu nhập khách", "err", err)
	}
}

func (h *Handler) importPreview(w http.ResponseWriter, r *http.Request) {
	r.Body = http.MaxBytesReader(w, r.Body, ImportMaxBytes+(1<<20))
	if err := r.ParseMultipartForm(ImportMaxBytes + (1 << 20)); err != nil {
		var mbe *http.MaxBytesError
		if errors.As(err, &mbe) {
			h.fail(w, r, iam.Invalid("File không được lớn hơn 5 MB."))
			return
		}
		h.fail(w, r, iam.Invalid("Vui lòng chọn file Excel / CSV."))
		return
	}
	defer r.MultipartForm.RemoveAll()
	in := ImportPreviewInput{DefaultSource: r.FormValue("default_source")}
	if id, err := uuid.Parse(r.FormValue("branch_id")); err == nil {
		in.BranchID = &id
	}
	if id, err := uuid.Parse(r.FormValue("assigned_user_id")); err == nil {
		in.AssignedUserID = &id
	}
	if f, fh, err := r.FormFile("file"); err == nil {
		defer f.Close()
		in.FileName = fh.Filename
		data, err := io.ReadAll(io.LimitReader(f, ImportMaxBytes+1))
		if err != nil {
			h.fail(w, r, err)
			return
		}
		in.Data = data
	}
	p, err := h.svc.PreviewImport(r.Context(), actor(r), in)
	h.ok(w, r, p, err)
}

func (h *Handler) getImport(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	p, err := h.svc.GetImport(r.Context(), actor(r), id)
	h.ok(w, r, p, err)
}

func (h *Handler) importErrors(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	t, err := h.svc.ImportErrors(r.Context(), actor(r), id)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	h.writeFile(w, r, t, "xlsx")
}

func (h *Handler) commitImport(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	res, err := h.svc.CommitImport(r.Context(), actor(r), id)
	h.ok(w, r, res, err)
}

func (h *Handler) cancelImport(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	res, err := h.svc.CancelImport(r.Context(), actor(r), id)
	h.ok(w, r, res, err)
}
