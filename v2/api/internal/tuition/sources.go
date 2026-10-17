package tuition

import (
	"context"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/dashboard"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/finance"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/tuition/tuitiondb"
)

// Nguồn số liệu Học phí cho phân hệ khác (gắn ở cmd/menglish): doanh thu của Thu chi, ô / hàng chờ của Tổng quan.

// RevenueSource: phiếu thu đã duyệt cho báo cáo Thu chi (finSvc.WithRevenue).
func (s *Service) RevenueSource() finance.RevenueSource { return revenueSource{s} }

type revenueSource struct{ s *Service }

func (r revenueSource) Pending() bool { return false }

func (r revenueSource) Revenue(ctx context.Context, from, to time.Time) ([]finance.RevenueRow, error) {
	rows, err := r.s.q.RevenueByBranchMethod(ctx, tuitiondb.RevenueByBranchMethodParams{FromDay: dateOf(from), ToDay: dateOf(to)})
	if err != nil {
		return nil, err
	}
	out := make([]finance.RevenueRow, 0, len(rows))
	for _, x := range rows {
		var branch *uuid.UUID
		if x.BranchID != uuid.Nil {
			branch = &x.BranchID
		}
		out = append(out, finance.RevenueRow{BranchID: branch, PaymentMethod: x.PaymentMethod, Receipts: int(x.Receipts),
			Amount: x.Amount, Surcharge: x.Surcharge})
	}
	return out, nil
}

// DashboardFinance: doanh thu tháng, phiếu thu chờ duyệt, học phí quá hạn cho màn Tổng quan (dashboard.Deps.Finance).
func (s *Service) DashboardFinance() dashboard.Finance { return dashboardFinance{s} }

type dashboardFinance struct{ s *Service }

func (d dashboardFinance) Revenue(ctx context.Context, branches []uuid.UUID, from, to time.Time) (*int64, error) {
	n, err := d.s.q.DashboardRevenue(ctx, tuitiondb.DashboardRevenueParams{FromDay: dateOf(from), ToDay: dateOf(to), BranchIds: branches})
	if err != nil {
		return nil, err
	}
	return &n, nil
}

func (d dashboardFinance) PendingReceipts(ctx context.Context, branches []uuid.UUID) (*int64, error) {
	n, err := d.s.q.DashboardPendingReceipts(ctx, branches)
	if err != nil {
		return nil, err
	}
	return &n, nil
}

// OverdueTuitions: khoản còn nợ đã quá hạn, không trong thời gian tạm dừng nhắc (StudentTuition::overdueNow), trong
// phạm vi học phí của người xem.
func (d dashboardFinance) OverdueTuitions(ctx context.Context, a *iam.Access) (*int64, error) {
	rows, err := d.s.q.DebtSummaryRows(ctx, branchLimit(a))
	if err != nil {
		return nil, err
	}
	today := d.s.today()
	var n int64
	for _, r := range rows {
		if r.DueDate != nil && r.DueDate.Before(today) && !pausedOn(r.ReminderPausedUntil, today) {
			n++
		}
	}
	return &n, nil
}
