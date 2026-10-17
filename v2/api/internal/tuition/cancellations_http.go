package tuition

import (
	"mime"
	"net/http"
	"strconv"
	"strings"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/filestore"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/httpx"
)

func cancellationFilter(r *http.Request) CancellationFilter {
	q := r.URL.Query()
	return CancellationFilter{Status: q.Get("status"), BranchID: q.Get("branch_id"), Q: q.Get("q"), SelectedID: queryUUID(r, "selected_id"),
		CancelInvoice: q.Get("cancel_invoice")}
}

func (h *Handler) invoiceCancellations(w http.ResponseWriter, r *http.Request) {
	out, err := h.svc.InvoiceCancellations(r.Context(), actorOf(r), cancellationFilter(r))
	h.reply(w, r, out, err)
}

func (h *Handler) exportInvoiceCancellations(w http.ResponseWriter, r *http.Request) {
	b, err := h.svc.ExportInvoiceCancellations(r.Context(), actorOf(r), cancellationFilter(r))
	if err != nil {
		h.fail(w, r, err)
		return
	}
	csvFile(w, "yeu-cau-huy-hoa-don-"+h.svc.nowVN().Format("20060102-150405")+".csv", b)
}

// createInvoiceCancellation: JSON {invoice_number, amount, reason} hoặc multipart (cùng các trường + proof_image).
func (h *Handler) createInvoiceCancellation(w http.ResponseWriter, r *http.Request) {
	var in CancellationInput
	var proof *filestore.Upload
	if mt, _, _ := mime.ParseMediaType(r.Header.Get("Content-Type")); mt == "multipart/form-data" {
		form, err := filestore.ReadMultipart(w, r, CancellationProofPolicy.MaxBytes, CancellationProofPolicy.TooLarge)
		if err != nil {
			h.fail(w, r, uploadErr(err))
			return
		}
		defer form.Close()
		if proof = form.File("proof_image"); proof == nil {
			proof = form.File("file")
		}
		in.InvoiceNumber, in.Reason = form.Value("invoice_number"), form.Value("reason")
		if v := strings.TrimSpace(form.Value("amount")); v != "" {
			n, err := strconv.ParseInt(v, 10, 64)
			if err != nil {
				h.fail(w, r, iam.Invalid("Trường Số tiền phải là một số hợp lệ."))
				return
			}
			in.Amount = &n
		}
	} else if !httpx.Decode(w, r, &in) {
		return
	}
	out, err := h.svc.CreateInvoiceCancellation(r.Context(), actorOf(r), in, proof)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusCreated, out)
}

func (h *Handler) approveInvoiceCancellation(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	out, err := h.svc.ApproveInvoiceCancellation(r.Context(), actorOf(r), id)
	h.reply(w, r, out, err)
}

func (h *Handler) rejectInvoiceCancellation(w http.ResponseWriter, r *http.Request) {
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
	out, err := h.svc.RejectInvoiceCancellation(r.Context(), actorOf(r), id, in.RejectionReason)
	h.reply(w, r, out, err)
}
