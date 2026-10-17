package tuition

import (
	"encoding/json"
	"errors"
	"log/slog"
	"mime"
	"net/http"
	"strconv"

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

// Routes gắn API Học phí. Mỗi route gắn quyền như route Laravel tương ứng (nhóm tuition.* cần tuition.view);
// service kiểm thêm phạm vi chi nhánh (tuition.scope_branch / scope_all) và quyền theo từng loại hồ sơ.
func (h *Handler) Routes(r chi.Router) {
	r.Route("/tuition", func(r chi.Router) {
		view := iam.Require("tuition.view")
		create := iam.Require("tuition.create")
		r.With(view).Get("/students", h.students)
		r.With(view).Get("/students/{id}/payments", h.studentPayments)
		r.With(view).Get("/student-options", h.studentOptions)

		r.With(view).Get("/receipts/form", h.receiptForm)
		r.With(view, create).Post("/receipts", h.createReceipt)
		r.With(view).Get("/receipts/approve", h.approveScreen)
		r.With(view).Get("/receipts/{id}", h.receipt)
		r.With(view, create).Put("/receipts/{id}", h.updateReceipt)
		// Sửa phiếu kèm ảnh minh chứng (multipart) như form Laravel gửi POST.
		r.With(view, create).Post("/receipts/{id}", h.updateReceipt)
		r.With(view, iam.Require("tuition.approve")).Post("/receipts/{id}/approve", h.approveReceipt)
		r.With(view, iam.Require("tuition.reject")).Post("/receipts/{id}/reject", h.rejectReceipt)
		r.With(view, iam.Require("tuition.confirm_deposit")).Post("/receipts/{id}/confirm-deposit", h.confirmDeposit)

		r.With(view).Get("/history", h.history)
		r.With(view).Get("/history/export", h.exportHistory)

		contact := iam.Require("tuition.mark_contacted")
		r.With(view).Get("/overdue", h.overdue)
		r.With(view, contact).Post("/overdue/{id}/remind", h.remind)
		r.With(view, contact).Post("/overdue/{id}/upcoming-remind", h.remind)
		r.With(view, contact).Post("/overdue/{id}/contacted", h.markContacted)
		r.With(view, iam.Require("tuition.report_overdue")).Post("/overdue/{id}/report-admin", h.reportOverdue)

		// Hủy hóa đơn: duyệt / từ chối theo invoice.approve_cancel (mặc định chỉ Admin).
		approveCancel := iam.Require("invoice.approve_cancel")
		r.With(view).Get("/invoices/cancellations", h.invoiceCancellations)
		r.With(view).Get("/invoices/cancellations/export", h.exportInvoiceCancellations)
		r.With(view, iam.Require("invoice.request_cancel")).Post("/invoices/cancellations", h.createInvoiceCancellation)
		r.With(view, approveCancel).Post("/invoices/cancellations/{id}/approve", h.approveInvoiceCancellation)
		r.With(view, approveCancel).Post("/invoices/cancellations/{id}/reject", h.rejectInvoiceCancellation)

		// Cấu hình dải số hóa đơn: dải mặc định cần thêm invoice_range.manage_default (kiểm trong service).
		ranges := iam.Require("invoice_range.manage")
		r.With(view).Get("/config", h.invoiceConfig)
		r.With(view, ranges).Post("/config", h.updateInvoiceConfig)
		r.With(view, ranges).Post("/config/ranges", h.createInvoiceRange)
		r.With(view, ranges).Post("/config/ranges/{id}/toggle", h.toggleInvoiceRange)

		// Hoàn tiền & Khất nợ: quyền duyệt theo loại hồ sơ kiểm trong service (TuitionRefundRequest::approvePermission).
		r.With(view).Get("/refunds", h.refunds)
		r.With(view, iam.Require("refund_transfer.request")).Post("/refunds", h.createRefund)
		r.With(view, iam.RequireAny("refund_transfer.approve", "refund_transfer.approve_refund", "refund_transfer.approve_transfer")).
			Post("/refunds/{id}/approve", h.approveRefund)
		r.With(view, iam.Require("refund_transfer.reject")).Post("/refunds/{id}/reject", h.rejectRefund)

		// Nhập học phí từ Excel: xem trước → xác nhận (phiếu thu chờ duyệt).
		r.With(view).Get("/import", h.importForm)
		r.With(view).Get("/import/template", h.importTemplate)
		r.With(view, create).Post("/import", h.importPreview)
		r.With(view, create).Post("/import/confirm", h.importCommit)

		// SePay: cấu hình webhook + nhật ký giao dịch (tab SePay của màn Tài khoản ngân hàng), giao dịch gần đây.
		bank := iam.Require("bank_account.manage")
		r.With(bank).Get("/sepay", h.sepayPage)
		r.With(bank).Post("/sepay/config", h.updateSepayConfig)
		r.With(view).Get("/sepay/transactions", h.sepayTransactions)

		// Bill "Thông báo nộp học phí" (crm.tuition-bill): tư vấn viên (lead.view) hoặc người xem học phí.
		r.With(iam.RequireAny("lead.view", "tuition.view")).Get("/bill/{id}", h.bill)
	})
}

func actorOf(r *http.Request) *iam.Access { return iam.AccessFrom(r.Context()) }

func idParam(w http.ResponseWriter, r *http.Request) (uuid.UUID, bool) {
	id, err := uuid.Parse(chi.URLParam(r, "id"))
	if err != nil {
		httpx.Error(w, http.StatusNotFound, "not_found", "Không tìm thấy.")
		return uuid.Nil, false
	}
	return id, true
}

// queryUUID: tham số uuid tùy chọn trên URL; sai định dạng coi như không có.
func queryUUID(r *http.Request, key string) *uuid.UUID {
	id, err := uuid.Parse(r.URL.Query().Get(key))
	if err != nil {
		return nil
	}
	return &id
}

func queryInt(r *http.Request, key string) int {
	n, _ := strconv.Atoi(r.URL.Query().Get(key))
	return n
}

func (h *Handler) fail(w http.ResponseWriter, r *http.Request, err error) { iam.Fail(w, r, h.log, err) }

func (h *Handler) reply(w http.ResponseWriter, r *http.Request, v any, err error) {
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, v)
}

func tuitionFilter(r *http.Request) TuitionFilter {
	q := r.URL.Query()
	return TuitionFilter{Search: q.Get("search"), Status: q.Get("status"), BranchID: queryUUID(r, "branch_id"),
		ClassID: queryUUID(r, "class_id"), Page: queryInt(r, "page"), Type: q.Get("type"), UpcomingPage: queryInt(r, "upcoming_page")}
}

func (h *Handler) students(w http.ResponseWriter, r *http.Request) {
	out, err := h.svc.Students(r.Context(), actorOf(r), tuitionFilter(r))
	h.reply(w, r, out, err)
}

func (h *Handler) studentPayments(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	out, err := h.svc.StudentPayments(r.Context(), actorOf(r), id)
	h.reply(w, r, out, err)
}

func (h *Handler) studentOptions(w http.ResponseWriter, r *http.Request) {
	out, err := h.svc.SearchStudents(r.Context(), actorOf(r), r.URL.Query().Get("q"))
	h.reply(w, r, map[string]any{"students": out}, err)
}

func (h *Handler) receiptForm(w http.ResponseWriter, r *http.Request) {
	a := actorOf(r)
	rid := queryUUID(r, "receipt_id")
	if rid != nil && !a.Can("tuition.create") {
		httpx.Error(w, http.StatusForbidden, "forbidden", "Bạn không có quyền thực hiện thao tác này.")
		return
	}
	out, err := h.svc.ReceiptForm(r.Context(), a, queryUUID(r, "student_id"), queryUUID(r, "tuition_id"), rid)
	h.reply(w, r, out, err)
}

// receiptInput: JSON, hoặc multipart gồm trường payload (JSON như trên) + file proof_image (ảnh / PDF minh chứng).
// done() xóa file tạm sau khi xử lý.
func (h *Handler) receiptInput(w http.ResponseWriter, r *http.Request) (ReceiptInput, func(), bool) {
	var in ReceiptInput
	if mt, _, _ := mime.ParseMediaType(r.Header.Get("Content-Type")); mt != "multipart/form-data" {
		return in, func() {}, httpx.Decode(w, r, &in)
	}
	form, err := filestore.ReadMultipart(w, r, ReceiptProofPolicy.MaxBytes, ReceiptProofPolicy.TooLarge)
	if err != nil {
		h.fail(w, r, uploadErr(err))
		return in, nil, false
	}
	if err := json.Unmarshal([]byte(form.Value("payload")), &in); err != nil {
		form.Close()
		h.fail(w, r, iam.Invalid("Dữ liệu phiếu thu không hợp lệ."))
		return in, nil, false
	}
	if in.ProofFile = form.File("proof_image"); in.ProofFile == nil {
		in.ProofFile = form.File("file")
	}
	return in, form.Close, true
}

func (h *Handler) createReceipt(w http.ResponseWriter, r *http.Request) {
	in, done, ok := h.receiptInput(w, r)
	if !ok {
		return
	}
	defer done()
	out, err := h.svc.CreateReceipt(r.Context(), actorOf(r), in)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusCreated, out)
}

func (h *Handler) updateReceipt(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	in, done, ok := h.receiptInput(w, r)
	if !ok {
		return
	}
	defer done()
	out, err := h.svc.UpdateReceipt(r.Context(), actorOf(r), id, in)
	h.reply(w, r, out, err)
}

func (h *Handler) approveScreen(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	out, err := h.svc.ApproveScreen(r.Context(), actorOf(r), ApproveFilter{Status: q.Get("status"), BranchID: queryUUID(r, "branch_id"),
		PaymentMethod: q.Get("payment_method"), Search: q.Get("q"), SelectedID: queryUUID(r, "selected_id")})
	h.reply(w, r, out, err)
}

func (h *Handler) receipt(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	out, err := h.svc.Receipt(r.Context(), actorOf(r), id)
	h.reply(w, r, out, err)
}

func (h *Handler) approveReceipt(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	var in struct {
		ConfirmNotDuplicate bool `json:"confirm_not_duplicate"`
	}
	if r.ContentLength > 0 && !httpx.Decode(w, r, &in) {
		return
	}
	out, err := h.svc.ApproveReceipt(r.Context(), actorOf(r), id, in.ConfirmNotDuplicate)
	h.reply(w, r, out, err)
}

func (h *Handler) rejectReceipt(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	var in struct {
		RejectionReason *string `json:"rejection_reason"`
	}
	if r.ContentLength > 0 && !httpx.Decode(w, r, &in) {
		return
	}
	out, err := h.svc.RejectReceipt(r.Context(), actorOf(r), id, in.RejectionReason)
	h.reply(w, r, out, err)
}

func (h *Handler) confirmDeposit(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	out, err := h.svc.ConfirmDeposit(r.Context(), actorOf(r), id)
	h.reply(w, r, out, err)
}

func historyFilter(r *http.Request) HistoryFilter {
	q := r.URL.Query()
	return HistoryFilter{Search: q.Get("search"), StudentID: queryUUID(r, "student_id"), Status: q.Get("status"), Method: q.Get("method"),
		From: q.Get("from"), To: q.Get("to"), Kind: q.Get("kind"), Page: queryInt(r, "page")}
}

func (h *Handler) history(w http.ResponseWriter, r *http.Request) {
	out, err := h.svc.History(r.Context(), actorOf(r), historyFilter(r))
	h.reply(w, r, out, err)
}

func (h *Handler) exportHistory(w http.ResponseWriter, r *http.Request) {
	b, err := h.svc.ExportHistory(r.Context(), actorOf(r), historyFilter(r))
	if err != nil {
		h.fail(w, r, err)
		return
	}
	csvFile(w, "lich-su-thu-hoc-phi.csv", b)
}

func (h *Handler) bill(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	out, err := h.svc.Bill(r.Context(), actorOf(r), id)
	h.reply(w, r, out, err)
}

func (h *Handler) overdue(w http.ResponseWriter, r *http.Request) {
	out, err := h.svc.Overdue(r.Context(), actorOf(r), tuitionFilter(r))
	h.reply(w, r, out, err)
}

type messageReply struct {
	Message string `json:"message"`
}

func (h *Handler) remind(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	res, msg, err := h.svc.SendDebtReminder(r.Context(), actorOf(r), id)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, map[string]any{"message": msg, "result": res})
}

func (h *Handler) markContacted(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	var in ContactInput
	if r.ContentLength > 0 && !httpx.Decode(w, r, &in) {
		return
	}
	msg, err := h.svc.MarkContacted(r.Context(), actorOf(r), id, in)
	h.reply(w, r, messageReply{msg}, err)
}

func (h *Handler) reportOverdue(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	var in struct {
		Note *string `json:"note"`
	}
	if r.ContentLength > 0 && !httpx.Decode(w, r, &in) {
		return
	}
	msg, err := h.svc.ReportOverdue(r.Context(), actorOf(r), id, in.Note)
	h.reply(w, r, messageReply{msg}, err)
}

func (h *Handler) refunds(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	out, err := h.svc.Refunds(r.Context(), actorOf(r), RefundFilter{Status: q.Get("status"), Type: q.Get("type"), Search: q.Get("search")})
	h.reply(w, r, out, err)
}

func (h *Handler) createRefund(w http.ResponseWriter, r *http.Request) {
	var in RefundInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	out, err := h.svc.CreateRefund(r.Context(), actorOf(r), in)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusCreated, out)
}

// approveRefund: JSON {clawback_commission, clawback_amount} hoặc multipart (cùng các trường + proof_image) — hoàn tiền
// bắt buộc ảnh bằng chứng.
func (h *Handler) approveRefund(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	var in RefundDecision
	var proof *filestore.Upload
	if mt, _, _ := mime.ParseMediaType(r.Header.Get("Content-Type")); mt == "multipart/form-data" {
		form, err := filestore.ReadMultipart(w, r, RefundProofPolicy.MaxBytes, RefundProofPolicy.TooLarge)
		if err != nil {
			h.fail(w, r, uploadErr(err))
			return
		}
		defer form.Close()
		if proof = form.File("proof_image"); proof == nil {
			proof = form.File("file")
		}
		if v := form.Value("clawback_commission"); v != "" {
			in.ClawbackCommission = ptr(v == "1" || v == "true" || v == "on")
		}
		if v := form.Value("clawback_amount"); v != "" {
			n, err := strconv.ParseInt(v, 10, 64)
			if err != nil {
				h.fail(w, r, iam.Invalid("Số hoa hồng thu hồi không hợp lệ."))
				return
			}
			in.ClawbackAmount = &n
		}
	} else if r.ContentLength > 0 && !httpx.Decode(w, r, &in) {
		return
	}
	out, err := h.svc.ApproveRefund(r.Context(), actorOf(r), id, in, proof)
	h.reply(w, r, out, err)
}

func uploadErr(err error) error {
	var ve *filestore.ValidationError
	if errors.As(err, &ve) {
		return iam.Invalid(ve.Message)
	}
	return err
}

func (h *Handler) rejectRefund(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	var in struct {
		RejectionReason *string `json:"rejection_reason"`
	}
	if r.ContentLength > 0 && !httpx.Decode(w, r, &in) {
		return
	}
	out, err := h.svc.RejectRefund(r.Context(), actorOf(r), id, in.RejectionReason)
	h.reply(w, r, out, err)
}

func csvFile(w http.ResponseWriter, name string, b []byte) {
	w.Header().Set("Content-Type", "text/csv; charset=utf-8")
	w.Header().Set("Content-Disposition", `attachment; filename="`+name+`"`)
	w.WriteHeader(http.StatusOK)
	_, _ = w.Write(b)
}
