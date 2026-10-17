package tuition

import (
	"net/http"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/httpx"
)

func (h *Handler) invoiceConfig(w http.ResponseWriter, r *http.Request) {
	out, err := h.svc.InvoiceConfig(r.Context(), actorOf(r))
	h.reply(w, r, out, err)
}

func (h *Handler) updateInvoiceConfig(w http.ResponseWriter, r *http.Request) {
	var in RangeInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	out, err := h.svc.UpdateInvoiceRange(r.Context(), actorOf(r), in)
	h.reply(w, r, out, err)
}

func (h *Handler) createInvoiceRange(w http.ResponseWriter, r *http.Request) {
	var in RangeInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	out, err := h.svc.CreateInvoiceRange(r.Context(), actorOf(r), in)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusCreated, out)
}

func (h *Handler) toggleInvoiceRange(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	out, err := h.svc.ToggleInvoiceRange(r.Context(), actorOf(r), id)
	h.reply(w, r, out, err)
}
