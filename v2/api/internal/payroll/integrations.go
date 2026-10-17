package payroll

import (
	"context"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/payroll/payrolldb"
)

// Hàm cho phân hệ khác (adapter ở cmd/menglish): kỳ lương khóa chấm công, biên bản đi muộn tự động của Chấm công
// (StaffAttendanceService::syncLatePenalty), biên bản trễ mốc của Dự án học thuật (SlaBreachService::breach).

// LockedPeriod: khoảng ngày của một kỳ lương đã duyệt / đã chi trả.
type LockedPeriod struct {
	From, To time.Time // 0 giờ VN
	Code     string
	Title    string
}

// LockedPeriods: các kỳ lương đã duyệt / đã chi trả (PayrollPeriod::isLockedFor).
func (s *Service) LockedPeriods(ctx context.Context) ([]LockedPeriod, error) {
	rows, err := s.q.AllPeriods(ctx)
	if err != nil {
		return nil, err
	}
	out := []LockedPeriod{}
	for _, p := range rows {
		if locked(p.Status) {
			out = append(out, LockedPeriod{From: asDay(p.StartDate), To: asDay(p.EndDate), Code: p.Code, Title: p.Title})
		}
	}
	return out, nil
}

// PeriodBrief: trạng thái kỳ lương của một tháng.
type PeriodBrief struct {
	StatusLabel string
	Locked      bool
}

// MonthPeriod: kỳ lương của tháng chứa month; nil = chưa khởi tạo bảng lương.
func (s *Service) MonthPeriod(ctx context.Context, month time.Time) (*PeriodBrief, error) {
	m := month.In(vnZone)
	p, err := s.q.PeriodByMonth(ctx, payrolldb.PeriodByMonthParams{Year: int16(m.Year()), Month: int16(m.Month())})
	if isNoRows(err) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	return &PeriodBrief{StatusLabel: periodStatusLabels[p.Status], Locked: locked(p.Status)}, nil
}

// PenaltyRef: mã + trạng thái của một biên bản.
type PenaltyRef struct {
	ID     uuid.UUID
	Code   string
	Status string
}

// LatePenalty: dòng chấm công đi muộn tính lỗi.
type LatePenalty struct {
	UserID    uuid.UUID
	WorkDate  time.Time
	Violation string // "Đi muộn 12 phút (chấm công ngày 17/10/2026)"
	Notes     string
}

// SyncLatePenalty: biên bản đang gắn (current) chưa hủy → còn chờ giải trình thì cập nhật nội dung vi phạm; chưa có /
// đã hủy → lập biên bản mới (chờ giải trình, 0đ, nhóm Vận hành, người báo "Tự động"). created = vừa lập mới (phân hệ
// gọi tự gửi thông báo cho nhân sự). Chạy trong tx của Chấm công.
func (s *Service) SyncLatePenalty(ctx context.Context, tx pgxTx, current *uuid.UUID, in LatePenalty) (ref *PenaltyRef, created bool, err error) {
	q := s.q.WithTx(tx)
	if current != nil {
		p, err := q.LockPenalty(ctx, *current)
		if err != nil && !isNoRows(err) {
			return nil, false, err
		}
		if err == nil && p.Status != "cancelled" {
			if p.Status == "pending" && p.ViolationType != in.Violation {
				if err := q.SetPendingViolation(ctx, payrolldb.SetPendingViolationParams{ViolationType: in.Violation, ID: p.ID}); err != nil {
					return nil, false, err
				}
			}
			return &PenaltyRef{ID: p.ID, Code: p.Code, Status: p.Status}, false, nil
		}
	}
	cat := "operations"
	p, err := s.insertPenalty(ctx, q, payrolldb.InsertPenaltyParams{UserID: in.UserID, ViolationType: in.Violation,
		ErrorCategory: &cat, ViolationDate: dbDay(in.WorkDate), Amount: 0, Notes: trimPtr(&in.Notes)})
	if err != nil {
		return nil, false, err
	}
	if err := audit.Record(ctx, tx, audit.Entry{Module: audit.ModulePayroll, Event: audit.EventCreated, Action: "penalty.auto",
		SubjectType: "penalty", SubjectID: p.ID.String(), SubjectLabel: p.Code,
		Description: "Hệ thống tự lập biên bản " + p.Code + ": " + in.Violation,
		Properties:  map[string]any{"user_id": in.UserID, "source": "staff_attendance"}}); err != nil {
		return nil, false, err
	}
	return &PenaltyRef{ID: p.ID, Code: p.Code, Status: p.Status}, true, nil
}

// AutoCancelPenalty: biên bản còn chờ / đã giải trình tự hủy với ghi chú note (trạng thái khác giữ nguyên).
func (s *Service) AutoCancelPenalty(ctx context.Context, tx pgxTx, id uuid.UUID, note string) error {
	return s.q.WithTx(tx).AutoCancelPenalty(ctx, payrolldb.AutoCancelPenaltyParams{Note: &note, ID: id})
}

// PenaltyRefs: mã + trạng thái các biên bản theo id.
func (s *Service) PenaltyRefs(ctx context.Context, ids []uuid.UUID) (map[uuid.UUID]PenaltyRef, error) {
	out := map[uuid.UUID]PenaltyRef{}
	if len(ids) == 0 {
		return out, nil
	}
	rows, err := s.q.PenaltyRefs(ctx, ids)
	if err != nil {
		return nil, err
	}
	for _, r := range rows {
		out[r.ID] = PenaltyRef{ID: r.ID, Code: r.Code, Status: r.Status}
	}
	return out, nil
}

// ViolationCounts: số biên bản chưa hủy có ngày vi phạm trong [from, to] của từng người.
func (s *Service) ViolationCounts(ctx context.Context, userIDs []uuid.UUID, from, to time.Time) (map[uuid.UUID]int, error) {
	out := map[uuid.UUID]int{}
	if len(userIDs) == 0 {
		return out, nil
	}
	rows, err := s.q.ViolationCountsByUser(ctx, payrolldb.ViolationCountsByUserParams{UserIds: userIDs, FromDay: dbDay(from), ToDay: dbDay(to)})
	if err != nil {
		return nil, err
	}
	for _, r := range rows {
		out[r.UserID] = int(r.N)
	}
	return out, nil
}

// AutoPenaltyRow: biên bản tự động của một nguồn theo khóa.
type AutoPenaltyRow struct {
	ID          uuid.UUID
	Code        string
	UserID      uuid.UUID
	UserName    string
	Key         string
	Status      string
	StatusLabel string
	Amount      int64
	CreatedAt   time.Time
}

// AutoPenalties: biên bản tự động của nguồn source có khóa trong keys, mới nhất trước.
func (s *Service) AutoPenalties(ctx context.Context, source string, keys []string) ([]AutoPenaltyRow, error) {
	if len(keys) == 0 {
		return nil, nil
	}
	rows, err := s.q.AutoPenaltiesByKeys(ctx, payrolldb.AutoPenaltiesByKeysParams{AutoSource: &source, Keys: keys})
	if err != nil {
		return nil, err
	}
	labels := penaltyStatusLabels(s.paymentDueDays(ctx, s.q))
	out := make([]AutoPenaltyRow, 0, len(rows))
	for _, r := range rows {
		out = append(out, AutoPenaltyRow{ID: r.ID, Code: r.Code, UserID: r.UserID, UserName: r.UserName, Key: r.AutoKey,
			Status: r.Status, StatusLabel: labelOf(labels, r.Status), Amount: r.Amount, CreatedAt: r.CreatedAt})
	}
	return out, nil
}
