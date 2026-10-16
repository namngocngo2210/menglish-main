package inventory

import (
	"context"
	"fmt"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/inventory/inventorydb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify"
)

// Nhật ký vận hành (activity('merchandise_item') của Laravel) và thông báo order học liệu (MaterialOrderService).

func init() {
	audit.RegisterSubject("merchandise_item", "Hàng hóa")
	audit.RegisterSubject("material_order", "Order học liệu")
}

// inTx chạy fn trong một giao dịch, có cả tx (để ghi nhật ký / thông báo cùng giao dịch).
func (s *Service) inTx(ctx context.Context, fn func(tx pgx.Tx, q *inventorydb.Queries) error) error {
	return pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error { return fn(tx, s.q.WithTx(tx)) })
}

func itemSnapshot(i inventorydb.OpsInventoryItem) map[string]any {
	return map[string]any{
		"code": i.Code, "name": i.Name, "category": i.Category, "unit": i.Unit, "price": i.Price,
		"cost_price": i.CostPrice, "is_active": i.IsActive, "description": i.Description,
	}
}

func itemEntry(actor *iam.Access, i inventorydb.OpsInventoryItem, event, action, desc string, changes audit.Changes) audit.Entry {
	return audit.Entry{
		ActorID: &actor.UserID, Module: audit.ModuleGeneral, Event: event, Action: action,
		SubjectType: "merchandise_item", SubjectID: i.ID.String(), SubjectLabel: fmt.Sprintf("%s (%s)", i.Name, i.Code),
		Description: desc, Changes: changes,
	}
}

// orderProcessors: người xử lý order (MaterialOrderService::processors): có quyền xử lý theo loại; loại vận hành (đạo
// cụ, in ấn, GVNN) chỉ người thuộc chi nhánh của order, cộng Admin (mọi quyền, mọi chi nhánh).
func orderProcessors(ctx context.Context, db inventorydb.DBTX, category string, branch uuid.UUID) ([]uuid.UUID, error) {
	if !isOpsCategory(category) {
		return iam.UserIDsWithPermission(ctx, db, processPermission(category), nil)
	}
	ids, err := iam.UserIDsWithPermission(ctx, db, processPermission(category), &branch)
	if err != nil {
		return nil, err
	}
	admins, err := iam.AdminIDs(ctx, db)
	return append(ids, admins...), err
}

// notifyProcessors báo người xử lý order (loại material_order_new / material_order_overdue).
func notifyProcessors(ctx context.Context, db inventorydb.DBTX, o Order, typ, title, body string, by *uuid.UUID) error {
	ids, err := orderProcessors(ctx, db, o.Category, o.BranchID)
	if err != nil {
		return err
	}
	_, err = notify.Send(ctx, db, ids, notify.Notification{
		Type: typ, Title: title, Body: body, Link: "/material-orders/" + o.ID.String(),
		Data: map[string]any{"material_order_id": o.ID}, CreatedBy: by,
	})
	return err
}
