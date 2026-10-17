package tuition

import (
	"context"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/crm"
)

// StudentTotals: tổng đã thu / còn nợ của các học viên (crm.TuitionLookup — màn Khách chốt thành công).
func (s *Service) StudentTotals(ctx context.Context, studentIDs []uuid.UUID) (int64, int64, error) {
	if len(studentIDs) == 0 {
		return 0, 0, nil
	}
	t, err := s.q.StudentsTuitionTotals(ctx, studentIDs)
	return t.Paid, t.Debt, err
}

// StudentSummaries: học phí mới nhất của từng học viên với nhãn như Laravel wonCustomers: có phiếu chờ duyệt →
// "Chờ đối soát X", không thì nhãn trạng thái; còn nợ → " · Còn Y" (đã trừ phần chờ duyệt).
func (s *Service) StudentSummaries(ctx context.Context, studentIDs []uuid.UUID) (map[uuid.UUID]crm.TuitionSummary, error) {
	out := map[uuid.UUID]crm.TuitionSummary{}
	if len(studentIDs) == 0 {
		return out, nil
	}
	rows, err := s.q.StudentsLatestTuition(ctx, studentIDs)
	if err != nil {
		return nil, err
	}
	for _, r := range rows {
		out[r.StudentID] = tuitionSummary(r.Status, r.PaidAmount, r.DebtAmount, r.Pending)
	}
	return out, nil
}

func tuitionSummary(status string, paid, debt, pending int64) crm.TuitionSummary {
	t := crm.TuitionSummary{Paid: paid, Debt: debt, Label: tuitionStatusLabel(status), Tone: tuitionStatusColor(status)}
	if pending > 0 {
		t.Label, t.Tone = "Chờ đối soát "+money(pending), "warning"
	}
	if debt > 0 {
		t.Label += " · Còn " + money(max(0, debt-pending))
	}
	return t
}
