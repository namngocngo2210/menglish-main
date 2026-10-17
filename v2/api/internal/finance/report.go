package finance

import (
	"context"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// Báo cáo doanh thu tạm tính (FinanceController::provisionalRevenue / exportRevenueReport).

// RevenuePage: màn Báo cáo doanh thu tạm tính (props của Finance/Reports/ProvisionalRevenue.vue).
type RevenuePage struct {
	Report
	BranchScoped   bool        `json:"branch_scoped"`
	Month          string      `json:"month"`
	BranchID       string      `json:"branch_id"`
	Branches       []BranchRef `json:"branches"`
	PrevMonthLabel string      `json:"prev_month_label"`
	MonthOptions   []Option    `json:"month_options"`
	RevenuePending string      `json:"revenue_pending,omitempty"`
	PayrollPending string      `json:"payroll_pending,omitempty"`
}

// salaryAmount: tổng thực nhận kỳ lương đã chốt / đã trả của tháng (0 khi chưa có).
func (s *Service) salaryAmount(ctx context.Context, start time.Time, branch *uuid.UUID) (int64, error) {
	sal, err := s.payroll.Salary(ctx, start, branch)
	if err != nil || sal == nil {
		return 0, err
	}
	return sal.Amount, nil
}

// loadReport nạp số liệu một tháng cho buildReport; branches = chi nhánh của ma trận.
func (s *Service) loadReport(ctx context.Context, start time.Time, filter *uuid.UUID, branches []BranchRef) (reportInput, error) {
	in := reportInput{Branches: branches, Filter: filter, BranchSalary: map[uuid.UUID]int64{}}
	var err error
	if in.Revenue, err = s.revenue.Revenue(ctx, start, monthEnd(start)); err != nil {
		return in, err
	}
	prev := start.AddDate(0, -1, 0)
	if in.PrevRevenue, err = s.revenue.Revenue(ctx, prev, monthEnd(prev)); err != nil {
		return in, err
	}
	if in.Expenses, err = s.expenseSums(ctx, start); err != nil {
		return in, err
	}
	if in.Salary, err = s.salaryAmount(ctx, start, filter); err != nil {
		return in, err
	}
	for _, b := range branches {
		id := b.ID
		v, err := s.salaryAmount(ctx, start, &id)
		if err != nil {
			return in, err
		}
		in.BranchSalary[id] = v
	}
	return in, nil
}

// ProvisionalRevenue: Doanh thu tạm tính = Tổng thu (phiếu đã duyệt) − Chi vận hành, theo tháng và chi nhánh.
func (s *Service) ProvisionalRevenue(ctx context.Context, a *iam.Access, month, branchID string) (*RevenuePage, error) {
	sc, err := scope(a)
	if err != nil {
		return nil, err
	}
	now := s.now()
	start := parseMonth(month, now)
	filter := resolveBranch(sc, branchID)
	branches, err := s.visibleBranches(ctx, sc)
	if err != nil {
		return nil, err
	}
	in, err := s.loadReport(ctx, start, filter, branches)
	if err != nil {
		return nil, err
	}
	p := &RevenuePage{
		Report: buildReport(in), BranchScoped: sc != nil, Month: start.Format("2006-01"), BranchID: branchKey(filter),
		Branches: branches, PrevMonthLabel: start.AddDate(0, -1, 0).Format("01/2006"), MonthOptions: monthOptions(now),
	}
	if s.revenue.Pending() {
		p.RevenuePending = msgRevenuePending
	}
	if s.payroll.Pending() {
		p.PayrollPending = msgPayrollPending
	}
	return p, nil
}

// ExportRevenue: ma trận chi nhánh theo đúng bộ lọc đang xem + dòng tổng cộng (CSV).
func (s *Service) ExportRevenue(ctx context.Context, a *iam.Access, month, branchID string) (*Sheet, error) {
	sc, err := scope(a)
	if err != nil {
		return nil, err
	}
	start := parseMonth(month, s.now())
	filter := resolveBranch(sc, branchID)
	all, err := s.visibleBranches(ctx, sc)
	if err != nil {
		return nil, err
	}
	branches := all
	if filter != nil {
		branches = nil
		for _, b := range all {
			if b.ID == *filter {
				branches = append(branches, b)
			}
		}
	}
	in, err := s.loadReport(ctx, start, filter, branches)
	if err != nil {
		return nil, err
	}
	return revenueSheet(start, sc != nil || filter != nil, buildReport(in)), nil
}

// revenueSheet dựng file xuất từ ma trận đã tính.
func revenueSheet(start time.Time, scoped bool, r Report) *Sheet {
	out := &Sheet{Name: "Bao_Cao_Doanh_Thu_Tam_Tinh_" + start.Format("2006-01") + ".csv", Rows: [][]string{
		{"BÁO CÁO DOANH THU TẠM TÍNH - MENGLISH ADMIN"},
		{"Kỳ tháng: " + start.Format("01/2006")},
		{},
		{"Chi nhánh / Cơ sở", "Tổng thu (VNĐ)", "Chi vận hành (VNĐ)", "Doanh thu tạm tính (VNĐ)", "Tỷ suất gộp (%)", "Tình trạng"},
	}}
	for _, m := range r.BranchMatrix {
		out.Rows = append(out.Rows, safeRow([]string{
			m.Branch.Name, vnd(m.Revenue), vnd(m.Expense), vnd(m.Profit), pctLabel(m.Margin), m.Status.Label,
		}))
	}
	label := "TỔNG CỘNG TOÀN HỆ THỐNG"
	if scoped {
		label = "TỔNG CỘNG"
	}
	out.Rows = append(out.Rows, []string{
		label, vnd(r.TotalMatrixRevenue), vnd(r.TotalMatrixExpense), vnd(r.TotalMatrixProfit),
		pctLabel(r.TotalMatrixMargin), r.TotalMatrixStatus.Label,
	})
	return out
}
