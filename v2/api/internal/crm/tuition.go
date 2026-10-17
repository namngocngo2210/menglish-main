package crm

import (
	"context"

	"github.com/google/uuid"
)

// TuitionSummary: học phí của học viên đã chốt hiện ở màn Khách chốt thành công (nhãn như Laravel wonCustomers).
type TuitionSummary struct {
	Paid  int64  `json:"paid_amount"`
	Debt  int64  `json:"debt_amount"`
	Label string `json:"label"`
	Tone  string `json:"tone"` // success | warning | error | neutral
}

// TuitionLookup: phân hệ Học phí cho tổng Đã thu (duyệt) / Còn nợ và học phí từng học viên (tuition.Service).
type TuitionLookup interface {
	StudentTotals(ctx context.Context, studentIDs []uuid.UUID) (paid, debt int64, err error)
	StudentSummaries(ctx context.Context, studentIDs []uuid.UUID) (map[uuid.UUID]TuitionSummary, error)
}

// WithTuition gắn phân hệ Học phí (nil = chưa nối: tổng bằng 0, không có nhãn học phí).
func (s *Service) WithTuition(t TuitionLookup) *Service { s.tuition = t; return s }

// fillTuition gắn học phí vào từng dòng khách đã có học viên.
func (s *Service) fillTuition(ctx context.Context, rows []LeadRow) error {
	if s.tuition == nil {
		return nil
	}
	ids := make([]uuid.UUID, 0, len(rows))
	for _, r := range rows {
		if r.ConvertedStudentID != nil {
			ids = append(ids, *r.ConvertedStudentID)
		}
	}
	if len(ids) == 0 {
		return nil
	}
	m, err := s.tuition.StudentSummaries(ctx, ids)
	if err != nil {
		return err
	}
	for i := range rows {
		if id := rows[i].ConvertedStudentID; id != nil {
			if t, ok := m[*id]; ok {
				rows[i].Tuition = &t
			} else {
				rows[i].Tuition = &TuitionSummary{Label: "Chưa có học phí", Tone: "neutral"}
			}
		}
	}
	return nil
}
