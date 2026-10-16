package crm

import (
	"context"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/crm/crmdb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// Xếp lớp khách Chờ xếp lớp từ màn Học viên / Tiếp nhận & Xếp lớp (WaitingLeadPlacement): phân hệ lớp học khóa khách,
// kiểm khóa / cơ sở đã chốt theo LeadSnapshot, ghi danh rồi gọi CompleteWaiting — để khách, lượt ghi danh luôn khớp nhau
// dù xếp lớp từ màn nào.

// LockWaitingLead: khách (khóa dòng) nếu vẫn đang Chờ xếp lớp; nil nếu không.
func (s *Service) LockWaitingLead(ctx context.Context, tx pgx.Tx, leadID uuid.UUID) (*LeadSnapshot, error) {
	l, err := lockLead(ctx, s.q.WithTx(tx), leadID)
	if err == errLeadNotFound {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	if l.Stage != StageWaitingClass || l.DeletedAt != nil {
		return nil, nil
	}
	snap := snapshot(l)
	return &snap, nil
}

// CompleteWaiting (WaitingLeadPlacement::complete): lượt ghi danh vào hàng "Xác nhận chính thức", bỏ Chờ xếp lớp,
// khách → Đã chốt. Cùng giao dịch với lượt ghi danh.
func (s *Service) CompleteWaiting(ctx context.Context, tx pgx.Tx, actor *iam.Access, leadID uuid.UUID, e Enrollment) error {
	q := s.q.WithTx(tx)
	l, err := lockLead(ctx, q, leadID)
	if err != nil {
		return err
	}
	e.LeadID = l.ID
	if _, err := s.RecordEnrollment(ctx, q, e); err != nil {
		return err
	}
	if err := q.SetLeadStage(ctx, crmdb.SetLeadStageParams{ID: l.ID, Stage: l.Stage, LostMode: "keep", ClearWaiting: true}); err != nil {
		return err
	}
	if _, err := s.AdvanceTo(ctx, q, l.ID, StageWon, &actor.UserID, "Học vụ xếp lớp "+e.ClassName+" cho học viên "+e.StudentCode+"."); err != nil {
		return err
	}
	s.log.Info("crm: hoàn tất khách chờ xếp lớp", "lead", l.ID, "class", e.ClassID, "by", actor.UserID)
	return nil
}

// WaitingCount: số khách Chờ xếp lớp người dùng thấy (màn Tiếp nhận & Xếp lớp); không có quyền xem khách → 0.
func (s *Service) WaitingCount(ctx context.Context, a *iam.Access) (int64, error) {
	if !a.Can("lead.view") {
		return 0, nil
	}
	sc := scopeOf(a)
	rows, err := s.q.WaitingLeads(ctx, crmdb.WaitingLeadsParams{ScopeOwner: sc.owner, ScopeBranches: sc.branches})
	return int64(len(rows)), err
}
