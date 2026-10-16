package inventory

import (
	"log/slog"
	"net/http"
	"strconv"

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

// Quyền xem / tạo / xử lý order học liệu (MaterialOrderController::authorizeAccess).
var orderAccess = []string{"material_order.create", "material_order.view_all", "material_order.process_ops", "material_order.process_academic"}

// Routes gắn API kho vật phẩm và order học liệu. Mỗi route gắn quyền như route Laravel tương ứng; service kiểm thêm
// phạm vi chi nhánh và quyền theo từng order.
func (h *Handler) Routes(r chi.Router) {
	// Danh mục hàng hóa & vật phẩm (merchandise.*: can:system_category.manage)
	r.Route("/inventory/items", func(r chi.Router) {
		r.Use(iam.Require("system_category.manage"))
		r.Get("/", h.listItems)
		r.Post("/", h.createItem)
		r.Get("/active", h.activeItems)
		r.Get("/{id}", h.getItem)
		r.Put("/{id}", h.updateItem)
		r.Delete("/{id}", h.deleteItem)
		r.Post("/{id}/toggle", h.toggleItem)
	})

	// Tồn kho theo chi nhánh (merchandise.stock.*)
	r.Route("/inventory/stock", func(r chi.Router) {
		r.With(iam.Require("merchandise_stock.view")).Get("/", h.stockOverview)
		r.With(iam.Require("merchandise_stock.view")).Get("/items/{id}/history", h.stockHistory)
		r.With(iam.Require("merchandise_stock.manage")).Get("/form-options", h.stockFormOptions)
		r.With(iam.Require("merchandise_stock.manage")).Post("/movements", h.stockMove)
	})

	// Order học liệu (material-orders.*; quyền kiểm theo loại order + chi nhánh trong service)
	r.Route("/material-orders", func(r chi.Router) {
		r.With(iam.RequireAny(orderAccess...)).Get("/", h.listOrders)
		r.With(iam.Require("material_order.create")).Get("/form-options", h.orderFormOptions)
		r.With(iam.Require("material_order.create")).Post("/", h.createOrder)
		r.With(iam.RequireAny(orderAccess...)).Get("/{id}", h.getOrder)
		r.With(iam.RequireAny("material_order.process_ops", "material_order.process_academic")).Post("/{id}/claim", h.claimOrder)
		r.With(iam.RequireAny("material_order.process_ops", "material_order.process_academic")).Post("/{id}/complete", h.completeOrder)
		r.With(iam.RequireAny("material_order.process_ops", "material_order.process_academic")).Post("/{id}/reject", h.rejectOrder)
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

func queryPage(r *http.Request) int {
	n, _ := strconv.Atoi(r.URL.Query().Get("page"))
	return max(1, n)
}

func (h *Handler) fail(w http.ResponseWriter, r *http.Request, err error) { iam.Fail(w, r, h.log, err) }

// ───────────── Hàng hóa ─────────────

func (h *Handler) listItems(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	out, err := h.svc.ListItems(r.Context(), ItemFilter{
		Search: q.Get("q"), Category: q.Get("category"), Status: q.Get("status"), Page: queryPage(r),
	})
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, out)
}

func (h *Handler) activeItems(w http.ResponseWriter, r *http.Request) {
	items, err := h.svc.ActiveItems(r.Context())
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, map[string]any{"items": items})
}

func (h *Handler) getItem(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	it, err := h.svc.GetItem(r.Context(), id)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, it)
}

func (h *Handler) createItem(w http.ResponseWriter, r *http.Request) {
	var in ItemInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	it, msg, err := h.svc.CreateItem(r.Context(), actorOf(r), in)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusCreated, map[string]any{"item": it, "message": msg})
}

func (h *Handler) updateItem(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	var in ItemInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	it, msg, err := h.svc.UpdateItem(r.Context(), actorOf(r), id, in)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, map[string]any{"item": it, "message": msg})
}

func (h *Handler) toggleItem(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	it, msg, err := h.svc.ToggleItem(r.Context(), actorOf(r), id)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, map[string]any{"item": it, "message": msg})
}

func (h *Handler) deleteItem(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	msg, err := h.svc.DeleteItem(r.Context(), actorOf(r), id)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, map[string]any{"message": msg})
}

// ───────────── Tồn kho ─────────────

func (h *Handler) stockOverview(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	out, err := h.svc.Overview(r.Context(), actorOf(r), StockFilter{
		BranchID: queryUUID(r, "branch_id"), Level: q.Get("level"), Search: q.Get("q"), Category: q.Get("category"),
	})
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, out)
}

func (h *Handler) stockHistory(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	out, err := h.svc.History(r.Context(), actorOf(r), id, queryUUID(r, "branch_id"))
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, out)
}

func (h *Handler) stockFormOptions(w http.ResponseWriter, r *http.Request) {
	out, err := h.svc.FormOptions(r.Context(), actorOf(r))
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, out)
}

func (h *Handler) stockMove(w http.ResponseWriter, r *http.Request) {
	var in MoveInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	out, err := h.svc.Move(r.Context(), actorOf(r), in)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusCreated, out)
}

// ───────────── Order học liệu ─────────────

func (h *Handler) listOrders(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	out, err := h.svc.ListOrders(r.Context(), actorOf(r), OrderFilter{
		Search: q.Get("search"), Status: q.Get("status"), Category: q.Get("category"),
		BranchID: queryUUID(r, "branch_id"), Page: queryPage(r),
	})
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, out)
}

func (h *Handler) orderFormOptions(w http.ResponseWriter, r *http.Request) {
	out, err := h.svc.OrderFormOptions(r.Context(), actorOf(r))
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, out)
}

func (h *Handler) createOrder(w http.ResponseWriter, r *http.Request) {
	var in OrderInput
	if !httpx.Decode(w, r, &in) {
		return
	}
	out, err := h.svc.CreateOrder(r.Context(), actorOf(r), in)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusCreated, out)
}

func (h *Handler) getOrder(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	out, err := h.svc.GetOrder(r.Context(), actorOf(r), id)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, out)
}

func (h *Handler) claimOrder(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	msg, err := h.svc.ClaimOrder(r.Context(), actorOf(r), id)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, map[string]any{"message": msg})
}

func (h *Handler) completeOrder(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	var in struct {
		ProcessorNote *string `json:"processor_note"`
	}
	if !httpx.Decode(w, r, &in) {
		return
	}
	msg, err := h.svc.CompleteOrder(r.Context(), actorOf(r), id, in.ProcessorNote)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, map[string]any{"message": msg})
}

func (h *Handler) rejectOrder(w http.ResponseWriter, r *http.Request) {
	id, ok := idParam(w, r)
	if !ok {
		return
	}
	var in struct {
		RejectReason *string `json:"reject_reason"`
	}
	if !httpx.Decode(w, r, &in) {
		return
	}
	msg, err := h.svc.RejectOrder(r.Context(), actorOf(r), id, in.RejectReason)
	if err != nil {
		h.fail(w, r, err)
		return
	}
	httpx.JSON(w, http.StatusOK, map[string]any{"message": msg})
}
