package inventory

import (
	"context"
	"fmt"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/inventory/inventorydb"
)

// Hết sách vẫn cho thu tiền (kho được âm). Tồn một mặt hàng ở chi nhánh về 0 hoặc âm sau một lần xuất → giao Admin việc
// "Nhập bù sách" (Laravel RestockTaskService); nhập kho / kiểm kê đưa tồn lên trên 0 → việc tự hoàn thành. Việc do phân hệ
// Công việc tạo (tasks.Service.Restocker), cùng giao dịch với lần xuất / nhập.

// Restock: việc nhập bù cần mở / cập nhật (Open) hoặc tự hoàn thành (tồn Balance > 0).
type Restock struct {
	ItemID, BranchID uuid.UUID
	Balance          int32
	Open             bool   // tồn <= 0 sau một lần xuất: mở việc (đã mở thì cập nhật mô tả)
	Title            string // "Nhập bù sách: <mặt hàng> — <chi nhánh>"
	Description      string
	Summary          string // "Kho âm 2 Cuốn tại Cầu Giấy" — đầu nội dung thông báo cho Admin
	Link             string // màn Tồn kho của chi nhánh
}

// Restocker: phân hệ Công việc giao / cập nhật / tự hoàn thành việc nhập bù trong giao dịch db.
type Restocker interface {
	SyncRestock(ctx context.Context, db inventorydb.DBTX, r Restock) error
}

// WithRestocker nối phân hệ Công việc (cmd/menglish/main.go); chưa nối thì không giao việc nhập bù.
func (s *Service) WithRestocker(r Restocker) *Service { s.restock = r; return s }

// syncRestock: gọi sau mỗi lần ghi tồn (apply), như RestockTaskService::sync.
func (s *Service) syncRestock(ctx context.Context, q *inventorydb.Queries, m move, mv inventorydb.OpsStockMovement) error {
	if s.restock == nil || (mv.BalanceAfter <= 0 && mv.QuantityChange >= 0) {
		return nil
	}
	r := Restock{ItemID: m.itemID, BranchID: m.branchID, Balance: mv.BalanceAfter}
	if mv.BalanceAfter <= 0 {
		item, err := q.GetItemAny(ctx, m.itemID)
		if err != nil {
			return err
		}
		branch, err := q.GetBranch(ctx, m.branchID)
		if err != nil {
			return err
		}
		state, short := "đã hết (còn 0)", "Kho hết"
		if mv.BalanceAfter < 0 {
			state = fmt.Sprintf("đang âm %d %s (đã giao cho học viên nhiều hơn số có trong kho)", -mv.BalanceAfter, item.Unit)
			short = fmt.Sprintf("Kho âm %d", -mv.BalanceAfter)
		}
		source := ""
		if m.receiptID != nil && m.document != nil && *m.document != "" {
			source = " sau khi duyệt phiếu thu " + *m.document
		}
		r.Open = true
		r.Title = "Nhập bù sách: " + item.Name + " — " + branch.Name
		r.Description = fmt.Sprintf("Tồn kho [%s] %s tại %s %s%s. Nhập sách bù cho chi nhánh ở Tồn kho theo chi nhánh → Nhập kho; "+
			"việc tự hoàn thành khi tồn lên trên 0.", item.Code, item.Name, branch.Name, state, source)
		r.Summary = short + " " + item.Unit + " tại " + branch.Name
		r.Link = "/inventory/stock?branch_id=" + branch.ID.String()
	}
	return s.restock.SyncRestock(ctx, q.DB(), r)
}
