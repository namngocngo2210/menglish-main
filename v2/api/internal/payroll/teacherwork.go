package payroll

import (
	"context"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/payroll/payrolldb"
)

// Phần Lương / Phạt cho teacher.Work (cổng giáo viên). TODO(gộp): adapter teacher.Work ghép các hàm này với phân hệ
// Chấm công (Checkins / CheckIn / giờ dạy): Month.Estimate = Σ giờ × RateOn, Month.Violations / Latest = Violations,
// CheckIn chặn ngày thuộc kỳ đã khóa bằng LockedOn + LockedMessage.

// RateOn: đơn giá riêng hiệu lực của GV tại ngày (TeacherHourlyRate::rateFor); nil = chưa có đơn giá riêng.
func (s *Service) RateOn(ctx context.Context, userID uuid.UUID, day time.Time) (*float64, string, error) {
	rows, err := s.q.HourlyRatesFor(ctx, []uuid.UUID{userID})
	if err != nil {
		return nil, "", err
	}
	r := newRateBook(rows).effective(userID, day)
	if r == nil {
		return nil, "", nil
	}
	rate := r.Rate
	return &rate, r.RateUnit, nil
}

// ViolationBrief: vi phạm gần nhất cho thẻ "Vi phạm tháng này".
type ViolationBrief struct {
	Type        string
	Date        time.Time
	StatusLabel string
}

// Violations: số biên bản của người dùng có ngày vi phạm trong [from, to] và biên bản gần nhất.
func (s *Service) Violations(ctx context.Context, userID uuid.UUID, from, to time.Time) (int, *ViolationBrief, error) {
	f, t, today := dbDay(from), dbDay(to), dbDay(s.today())
	n, err := s.q.CountPenalties(ctx, payrolldb.CountPenaltiesParams{Owner: &userID, Today: &today, FromDay: &f, ToDay: &t})
	if err != nil || n == 0 {
		return int(n), nil, err
	}
	rows, err := s.q.ListPenalties(ctx, payrolldb.ListPenaltiesParams{Owner: &userID, Today: &today, FromDay: &f, ToDay: &t, Lim: 1})
	if err != nil || len(rows) == 0 {
		return int(n), nil, err
	}
	p := rows[0]
	return int(n), &ViolationBrief{Type: p.ViolationType, Date: asDay(p.ViolationDate),
		StatusLabel: labelOf(penaltyStatusLabels(s.paymentDueDays(ctx, s.q)), p.Status)}, nil
}
