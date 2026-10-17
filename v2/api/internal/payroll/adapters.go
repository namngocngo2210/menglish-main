package payroll

import (
	"context"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/finance"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/payroll/payrolldb"
)

// Adapter cho phân hệ khác (gắn ở cmd/menglish).

// FinancePayroll: dòng "chi lương tự động" của Thu chi — kỳ lương tháng đã duyệt / đã chi trả.
type FinancePayroll struct{ s *Service }

func (s *Service) Finance() FinancePayroll { return FinancePayroll{s} }

var _ finance.PayrollSource = FinancePayroll{}

func (f FinancePayroll) Salary(ctx context.Context, month time.Time, branch *uuid.UUID) (*finance.Salary, error) {
	m := month.In(vnZone)
	p, err := f.s.q.LockedPeriodOfMonth(ctx, payrolldb.LockedPeriodOfMonthParams{Year: int16(m.Year()), Month: int16(m.Month())})
	if err != nil {
		if isNoRows(err) {
			return nil, nil
		}
		return nil, err
	}
	sum, err := f.s.q.SalaryOfPeriod(ctx, payrolldb.SalaryOfPeriodParams{PeriodID: p.ID, Branch: branch})
	if err != nil {
		return nil, err
	}
	end := asDay(p.EndDate)
	return &finance.Salary{Code: p.Code, EndDate: &end, Amount: sum.Amount, StaffCount: int(sum.Staff)}, nil
}

func (FinancePayroll) Pending() bool { return false }

// Locked: kpi.PayrollLock — ngày thuộc kỳ lương đã duyệt / đã chi trả.
func (s *Service) Locked(ctx context.Context, day time.Time) (bool, error) {
	return s.LockedOn(ctx, day)
}

// OpenPeriods: dashboard.Payroll — số kỳ lương chưa chốt.
func (s *Service) OpenPeriods(ctx context.Context) (*int64, error) {
	n, err := s.q.OpenPeriodsCount(ctx)
	if err != nil {
		return nil, err
	}
	return &n, nil
}
