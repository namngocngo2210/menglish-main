package payroll

import (
	"context"
	"fmt"
	"strings"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/payroll/payrolldb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/tuition"
)

// Hoa hồng tuyển sinh (SalesCommissionService): sổ hr.commission_items, gate kép, thu hồi khi hoàn phí / hủy hóa đơn.

// commissionReceipt: phiếu thu căn cứ hoa hồng kèm % theo mốc của thứ tự chốt.
type commissionReceipt struct {
	payrolldb.CommissionableReceiptsRow
	Base     int64
	Percent  float64
	TierName string
	Amount   int64
}

// commissionableReceipts: phiếu căn cứ hoa hồng duyệt trong [start, end] (ngày), % theo bảng mốc hiệu lực tại cuối kỳ.
func (s *Service) commissionableReceipts(ctx context.Context, q *payrolldb.Queries, start, end time.Time, owner *uuid.UUID) ([]commissionReceipt, error) {
	from, to := dayOf(start), endOfDay(end)
	rows, err := q.CommissionableReceipts(ctx, payrolldb.CommissionableReceiptsParams{StartAt: &from, EndAt: &to, Owner: owner})
	if err != nil {
		return nil, err
	}
	tiers, err := q.TiersAt(ctx, ptr(dbDay(end)))
	if err != nil {
		return nil, err
	}
	out := make([]commissionReceipt, 0, len(rows))
	for _, r := range rows {
		c := commissionReceipt{CommissionableReceiptsRow: r, TierName: "Chưa cấu hình mốc"}
		c.Base = tuitionBase(r.Amount, r.SurchargeAmount, r.TuitionFinal, r.TuitionOtherFees)
		if t := tierFor(tiers, max(1, int(r.ClosingRank))); t != nil {
			c.Percent, c.TierName = t.NewSalePercent, t.TierName
		}
		c.Amount = roundVND(float64(c.Base) * c.Percent / 100)
		out = append(out, c)
	}
	return out, nil
}

// closedCounts: số HS chốt trong [start, end] theo người nhận hoa hồng.
func closedCounts(ctx context.Context, q *payrolldb.Queries, start, end time.Time) (map[uuid.UUID]int, error) {
	from, to := dayOf(start), endOfDay(end)
	rows, err := q.ClosedCountsBySales(ctx, payrolldb.ClosedCountsBySalesParams{StartAt: &from, EndAt: &to})
	if err != nil {
		return nil, err
	}
	out := map[uuid.UUID]int{}
	for _, r := range rows {
		out[r.OwnerID] = int(r.Closed)
	}
	return out, nil
}

// paidOut: khoản đã chi cho sale (đã chốt sổ, hoặc nằm trên phiếu lương của kỳ đã khóa).
func paidOut(settled *time.Time, periodStatus string) bool {
	return settled != nil || periodStatus == "approved" || periodStatus == "paid"
}

// syncItems: ghi sổ khoản hoa hồng phát sinh trong kỳ (syncItemsForPeriod). Khoản đã trả không sửa; khoản đã hủy không mở lại.
func (s *Service) syncItems(ctx context.Context, q *payrolldb.Queries, p payrolldb.HrPayrollPeriod) error {
	receipts, err := s.commissionableReceipts(ctx, q, asDay(p.StartDate), asDay(p.EndDate), nil)
	if err != nil {
		return err
	}
	closed, err := closedCounts(ctx, q, asDay(p.StartDate), asDay(p.EndDate))
	if err != nil {
		return err
	}
	keep := []uuid.UUID{}
	for _, r := range receipts {
		// Phiếu chỉ thu sách / Thu khác (học phí = 0) không có khoản hoa hồng.
		if r.Base > 0 {
			keep = append(keep, r.ID)
		}
	}
	existing, err := q.CommissionItemsByReceipts(ctx, keep)
	if err != nil {
		return err
	}
	byReceipt := map[uuid.UUID]payrolldb.CommissionItemsByReceiptsRow{}
	for _, it := range existing {
		byReceipt[it.ReceiptID] = it
	}
	for _, r := range receipts {
		if r.Base <= 0 {
			continue
		}
		student, lead := r.StudentID, r.LeadID
		attrs := payrolldb.UpdateCommissionItemParams{
			UserID: r.OwnerID, StudentID: &student, LeadID: &lead, BaseAmount: r.Base, Percent: r.Percent, Amount: r.Amount,
			ClosedCount: int32(closed[r.OwnerID]), ClosingRank: r.ClosingRank,
			EarnedPeriodStart: p.StartDate, EarnedPeriodEnd: p.EndDate, ClosedAt: r.ConvertedAt,
		}
		if it, ok := byReceipt[r.ID]; ok {
			if it.Status == "void" || paidOut(it.SettledAt, it.PeriodStatus) {
				continue
			}
			attrs.ID = it.ID
			if err := q.UpdateCommissionItem(ctx, attrs); err != nil {
				return err
			}
			continue
		}
		if err := q.InsertCommissionItem(ctx, payrolldb.InsertCommissionItemParams{
			UserID: attrs.UserID, ReceiptID: r.ID, StudentID: attrs.StudentID, LeadID: attrs.LeadID, BaseAmount: attrs.BaseAmount,
			Percent: attrs.Percent, Amount: attrs.Amount, ClosedCount: attrs.ClosedCount, ClosingRank: attrs.ClosingRank,
			EarnedPeriodStart: attrs.EarnedPeriodStart, EarnedPeriodEnd: attrs.EarnedPeriodEnd, ClosedAt: attrs.ClosedAt,
		}); err != nil {
			return err
		}
	}
	if err := q.VoidStaleItems(ctx, payrolldb.VoidStaleItemsParams{StartDay: p.StartDate, Keep: keep}); err != nil {
		return err
	}
	return q.VoidCancelledReceiptItems(ctx, p.StartDate)
}

// gate: kết quả gate kép của một khoản tại cuối kỳ (gateFor).
type gate struct {
	Passes     bool
	Reason     *string
	DaysOK     bool
	Milestones int
}

func gateOf(closedAt *time.Time, milestones int, end time.Time) gate {
	g := gate{Milestones: milestones}
	var reasons []string
	if closedAt != nil {
		due := dayOf(*closedAt).AddDate(0, 0, gateDays)
		g.DaysOK = !due.After(endOfDay(end))
		if !g.DaysOK {
			reasons = append(reasons, fmt.Sprintf("chưa đủ %d ngày từ ngày chốt %s (đủ ngày %s)", gateDays,
				dayOf(*closedAt).Format("02/01/2006"), due.Format("02/01/2006")))
		}
	} else {
		reasons = append(reasons, "chưa xác định ngày chốt")
	}
	if milestones < gateMilestones {
		reasons = append(reasons, fmt.Sprintf("chăm sóc tháng đầu mới %d/%d mốc", milestones, gateMilestones))
	}
	g.Passes = len(reasons) == 0
	if !g.Passes {
		g.Reason = ptr("Hoãn: " + strings.Join(reasons, "; "))
	}
	return g
}

// commissionSplit: khoản trả trong kỳ / khoản hoãn của một người.
type commissionSplit struct {
	Payable  []payrolldb.HrCommissionItem
	Deferred []payrolldb.HrCommissionItem
}

func sumItems(items []payrolldb.HrCommissionItem, base bool) int64 {
	var t int64
	for _, it := range items {
		if base {
			t += it.BaseAmount
		} else {
			t += it.Amount
		}
	}
	return t
}

// resolveItems: xét gate cho khoản chưa trả, chưa gắn phiếu lương, phát sinh tới cuối kỳ (resolveForPeriod).
func (s *Service) resolveItems(ctx context.Context, q *payrolldb.Queries, p payrolldb.HrPayrollPeriod) (map[uuid.UUID]*commissionSplit, error) {
	items, err := q.OpenUnlinkedItems(ctx, p.EndDate)
	if err != nil {
		return nil, err
	}
	students := []uuid.UUID{}
	seen := map[uuid.UUID]bool{}
	for _, it := range items {
		if it.StudentID != nil && !seen[*it.StudentID] {
			seen[*it.StudentID] = true
			students = append(students, *it.StudentID)
		}
	}
	done := map[uuid.UUID]int{}
	if len(students) > 0 {
		rows, err := q.CareMilestones(ctx, students)
		if err != nil {
			return nil, err
		}
		for _, r := range rows {
			done[r.StudentID] = int(r.Done)
		}
	}
	out := map[uuid.UUID]*commissionSplit{}
	for _, it := range items {
		m := 0
		if it.StudentID != nil {
			m = done[*it.StudentID]
		}
		g := gateOf(it.ClosedAt, m, asDay(p.EndDate))
		it.DeferredReason = g.Reason
		sp := out[it.UserID]
		if sp == nil {
			sp = &commissionSplit{}
			out[it.UserID] = sp
		}
		if g.Passes {
			sp.Payable = append(sp.Payable, it)
		} else {
			sp.Deferred = append(sp.Deferred, it)
		}
	}
	return out, nil
}

// ownerSummary: hoa hồng phát sinh trong kỳ theo người phụ trách (summaryByOwner) — trước gate kép.
type ownerSummary struct {
	Collected int64 `json:"collected"`
	Base      int64 `json:"base"`
	Amount    int64 `json:"amount"`
	Students  int   `json:"students"`
	Closed    int   `json:"closed"`
	milestone
}

func (s *Service) summaryByOwner(ctx context.Context, q *payrolldb.Queries, start, end time.Time, owner *uuid.UUID) (map[uuid.UUID]ownerSummary, []payrolldb.HrCommissionTier, error) {
	receipts, err := s.commissionableReceipts(ctx, q, start, end, owner)
	if err != nil {
		return nil, nil, err
	}
	closed, err := closedCounts(ctx, q, start, end)
	if err != nil {
		return nil, nil, err
	}
	tiers, err := q.TiersAt(ctx, ptr(dbDay(end)))
	if err != nil {
		return nil, nil, err
	}
	out := map[uuid.UUID]ownerSummary{}
	students := map[uuid.UUID]map[uuid.UUID]bool{}
	for _, r := range receipts {
		row := out[r.OwnerID]
		row.Collected += r.CommissionableReceiptsRow.Amount
		row.Base += r.Base
		row.Amount += r.Amount
		if students[r.OwnerID] == nil {
			students[r.OwnerID] = map[uuid.UUID]bool{}
		}
		students[r.OwnerID][r.StudentID] = true
		out[r.OwnerID] = row
	}
	for id := range closed {
		if _, ok := out[id]; !ok && (owner == nil || id == *owner) {
			out[id] = ownerSummary{}
		}
	}
	for id, row := range out {
		row.Students = len(students[id])
		row.Closed = closed[id]
		row.milestone = milestoneFor(tiers, row.Closed)
		out[id] = row
	}
	return out, tiers, nil
}

// ───────────── Thu hồi (tuition.Commissions) ─────────────

// Commissions: adapter cho phân hệ Học phí (duyệt hoàn phí / hủy hóa đơn).
type Commissions struct{ s *Service }

// Commissions trả adapter tuition.Commissions.
func (s *Service) Commissions() Commissions { return Commissions{s} }

var _ tuition.Commissions = Commissions{}

// SuggestedClawback: min(tiền hoàn, căn cứ các khoản ĐÃ TRẢ) × % của khoản đã trả gần nhất (suggestedClawbackAmount).
func (c Commissions) SuggestedClawback(ctx context.Context, studentID, ownerID uuid.UUID, refundAmount int64) (int64, error) {
	items, err := c.s.q.StudentItems(ctx, payrolldb.StudentItemsParams{StudentID: &studentID, UserID: ownerID})
	if err != nil {
		return 0, err
	}
	var base int64
	var last *payrolldb.StudentItemsRow
	for i, it := range items {
		if paidOut(it.SettledAt, it.PeriodStatus) {
			base += it.BaseAmount
			last = &items[i]
		}
	}
	if last == nil {
		return 0, nil
	}
	return roundVND(float64(min(max(0, refundAmount), base)) * last.Percent / 100), nil
}

// RecordClawback: duyệt hoàn phí có thu hồi → hủy khoản còn hoãn của học viên, tạo khoản điều chỉnh âm (recordRefundDecision).
func (c Commissions) RecordClawback(ctx context.Context, tx pgx.Tx, refundID, studentID, ownerID uuid.UUID, amount int64, approver uuid.UUID) error {
	q := c.s.q.WithTx(tx)
	items, err := q.StudentItems(ctx, payrolldb.StudentItemsParams{StudentID: &studentID, UserID: ownerID})
	if err != nil {
		return err
	}
	ref := refundRef(refundID)
	for _, it := range items {
		if it.SettledAt != nil || paidOut(it.SettledAt, it.PeriodStatus) {
			continue
		}
		reason := "Hủy do duyệt hoàn phí " + ref + " có thu hồi hoa hồng"
		if err := q.VoidItem(ctx, payrolldb.VoidItemParams{Reason: &reason, ID: it.ID}); err != nil {
			return err
		}
	}
	if amount <= 0 {
		return nil
	}
	name, err := q.StudentName(ctx, studentID)
	if err != nil && !isNoRows(err) {
		return err
	}
	return q.InsertAdjustment(ctx, payrolldb.InsertAdjustmentParams{
		UserID: ownerID, RefundRequestID: &refundID, StudentID: &studentID, Amount: -amount,
		Reason:    fmt.Sprintf("Thu hồi hoa hồng do hoàn phí %s (học viên %s)", ref, name),
		CreatedBy: &approver,
	})
}

// refundRef: mã ngắn của yêu cầu hoàn phí (Laravel "#id").
func refundRef(id uuid.UUID) string { v := id.String(); return "#" + strings.ToUpper(v[len(v)-6:]) }

// CancellationClawback: hủy hóa đơn — khoản chưa trả thì hủy trong sổ; khoản đã trả thì tạo khoản thu hồi ở kỳ kế tiếp.
func (c Commissions) CancellationClawback(ctx context.Context, tx pgx.Tx, receiptID uuid.UUID, invoice string, approver uuid.UUID) (*tuition.Clawback, error) {
	q := c.s.q.WithTx(tx)
	r, err := q.ReceiptBrief(ctx, receiptID)
	if err != nil {
		if isNoRows(err) {
			return nil, nil
		}
		return nil, err
	}
	if r.Status != "approved" || r.Amount <= 0 || r.ApprovedAt == nil {
		return nil, nil
	}
	it, err := q.ItemByReceipt(ctx, receiptID)
	if err != nil {
		if isNoRows(err) {
			return nil, nil
		}
		return nil, err
	}
	if it.Status == "void" {
		return nil, nil
	}
	if !paidOut(it.SettledAt, it.PeriodStatus) {
		reason := "Hủy do hủy hóa đơn " + invoice
		return nil, q.VoidItem(ctx, payrolldb.VoidItemParams{Reason: &reason, ID: it.ID})
	}
	if it.Amount <= 0 {
		return nil, nil
	}
	period := it.PeriodTitle
	if period == "" {
		period = "trả hoa hồng"
	}
	if err := q.InsertAdjustment(ctx, payrolldb.InsertAdjustmentParams{
		UserID: it.UserID, StudentID: it.StudentID, Amount: -it.Amount,
		Reason:    fmt.Sprintf("Thu hồi hoa hồng do hủy hóa đơn %s (phiếu %s) sau khi kỳ lương %s đã duyệt", invoice, r.Code, period),
		CreatedBy: &approver,
	}); err != nil {
		return nil, err
	}
	return &tuition.Clawback{Amount: -it.Amount, UserName: it.UserName}, nil
}
