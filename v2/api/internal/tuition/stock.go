package tuition

import (
	"context"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/inventory"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/tuition/tuitiondb"
)

// Xuất kho sách / hàng hóa khi phiếu được duyệt, hoàn kho khi hủy hóa đơn (StockService::syncReceipt). Hàng ở phần
// phụ thu của phiếu xuất theo phiếu; sách trong hợp đồng xuất một lần cho khoản học phí (phiếu đầu tiên được duyệt).

// StockLine: một dòng xuất kho dự kiến (cảnh báo ở màn duyệt).
type StockLine struct {
	ItemID     uuid.UUID `json:"item_id"`
	Name       string    `json:"name"`
	Unit       string    `json:"unit"`
	Quantity   int       `json:"quantity"`
	Source     string    `json:"source"`
	Stock      int       `json:"stock"`
	StockAfter int       `json:"stock_after"`
}

type stockLine struct {
	itemID   uuid.UUID
	name     string
	quantity int
	source   string
}

// pendingStockLines: hàng chưa xuất của phiếu.
func (s *Service) pendingStockLines(ctx context.Context, q *tuitiondb.Queries, r tuitiondb.FinanceReceipt) ([]stockLine, error) {
	var out []stockLine
	issued, err := q.IssuedByReceipt(ctx, &r.ID)
	if err != nil {
		return nil, err
	}
	surcharge := map[uuid.UUID]int{}
	for _, x := range issued {
		if x.Source != nil && *x.Source == "surcharge" {
			surcharge[x.ItemID] += -int(x.Net)
		}
	}
	for _, l := range surchargeLines(decodeItems(r.CollectedItems)) {
		if rest := l.Quantity - surcharge[l.ID]; rest > 0 {
			out = append(out, stockLine{l.ID, l.Name, rest, "surcharge"})
		}
	}
	if r.TuitionID == nil {
		return out, nil
	}
	fee, err := q.TuitionFeeItems(ctx, *r.TuitionID)
	if err != nil {
		return nil, err
	}
	contractIssued, err := q.IssuedByTuitionContract(ctx, r.TuitionID)
	if err != nil {
		return nil, err
	}
	done := map[uuid.UUID]int{}
	for _, x := range contractIssued {
		done[x.ItemID] += -int(x.Net)
	}
	for _, l := range contractLines(decodeItems(fee)) {
		if rest := l.Quantity - done[l.ID]; rest > 0 {
			out = append(out, stockLine{l.ID, l.Name, rest, "contract"})
		}
	}
	return out, nil
}

// syncStock: gọi sau khi phiếu được duyệt / hủy, trong cùng giao dịch.
func (s *Service) syncStock(ctx context.Context, tx pgx.Tx, q *tuitiondb.Queries, r tuitiondb.FinanceReceipt, branch *uuid.UUID, by *uuid.UUID) error {
	if s.stock == nil {
		return nil
	}
	doc := r.Code
	if r.InvoiceNumber != nil {
		doc = *r.InvoiceNumber
	}
	switch {
	case r.Status == StatusApproved && r.Amount > 0:
		if branch == nil {
			return nil
		}
		lines, err := s.pendingStockLines(ctx, q, r)
		if err != nil {
			return err
		}
		for _, l := range lines {
			note := "Phụ thu — phiếu " + r.Code
			if l.source == "contract" {
				note = "Sách / hàng hóa trong hợp đồng — phiếu " + r.Code
			}
			if _, err := s.stock.Record(ctx, tx, inventory.RecordInput{
				ItemID: l.itemID, BranchID: *branch, Type: inventory.TypeSale, Quantity: int32(l.quantity), Source: l.source,
				ReceiptID: &r.ID, TuitionID: r.TuitionID, StudentID: r.StudentID, DocumentNumber: doc, Note: note, By: by,
			}); err != nil {
				return err
			}
		}
	case r.Status == StatusCancelled:
		issued, err := q.IssuedByReceipt(ctx, &r.ID)
		if err != nil {
			return err
		}
		for _, x := range issued {
			if x.Net >= 0 {
				continue
			}
			if _, err := s.stock.Record(ctx, tx, inventory.RecordInput{
				ItemID: x.ItemID, BranchID: x.BranchID, Type: inventory.TypeReturn, Quantity: -x.Net, Source: textOf(x.Source),
				ReceiptID: &r.ID, TuitionID: r.TuitionID, StudentID: r.StudentID, DocumentNumber: doc,
				Note: "Hủy hóa đơn " + doc, By: by,
			}); err != nil {
				return err
			}
		}
	}
	return nil
}

// previewStock: hàng sẽ xuất kho khi duyệt (cảnh báo hết / âm kho ở màn duyệt).
func (s *Service) previewStock(ctx context.Context, r tuitiondb.FinanceReceipt, branch *uuid.UUID) ([]StockLine, error) {
	out := []StockLine{}
	if branch == nil {
		return out, nil
	}
	lines, err := s.pendingStockLines(ctx, s.q, r)
	if err != nil || len(lines) == 0 {
		return out, err
	}
	ids := make([]uuid.UUID, 0, len(lines))
	for _, l := range lines {
		ids = append(ids, l.itemID)
	}
	items, err := s.q.ItemsByIDs(ctx, ids)
	if err != nil {
		return nil, err
	}
	info := map[uuid.UUID]tuitiondb.ItemsByIDsRow{}
	for _, i := range items {
		info[i.ID] = i
	}
	stock, err := s.q.BranchStock(ctx, *branch)
	if err != nil {
		return nil, err
	}
	qty := map[uuid.UUID]int{}
	for _, x := range stock {
		qty[x.ItemID] = int(x.Quantity)
	}
	for _, l := range lines {
		before := qty[l.itemID]
		qty[l.itemID] = before - l.quantity
		name := l.name
		if i, ok := info[l.itemID]; ok {
			name = i.Name
		}
		out = append(out, StockLine{ItemID: l.itemID, Name: name, Unit: info[l.itemID].Unit, Quantity: l.quantity, Source: l.source,
			Stock: before, StockAfter: qty[l.itemID]})
	}
	return out, nil
}
