package crm

import (
	"context"
	"time"

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

// ClassStart: lớp đã khai giảng chưa (ClassStartActivation::classHasStarted, hoặc lớp đã kết thúc) và ngày khai giảng.
type ClassStart struct {
	Started   bool
	StartDate *time.Time
}

// ClassStarts: StudentCreator cài thêm interface này (classes.CRMStudents) thì màn Xác nhận nhập học lấy trạng thái
// khai giảng thật của lớp thay cho ngày khai giảng chép lúc ghi danh.
type ClassStarts interface {
	ClassStarts(ctx context.Context, classIDs []uuid.UUID) (map[uuid.UUID]ClassStart, error)
}

func (s *Service) classStarts(ctx context.Context, rows []crmdb.ListConfirmationsRow) (map[uuid.UUID]ClassStart, error) {
	cs, ok := s.students.(ClassStarts)
	if !ok || len(rows) == 0 {
		return map[uuid.UUID]ClassStart{}, nil
	}
	ids := make([]uuid.UUID, 0, len(rows))
	for _, r := range rows {
		ids = append(ids, r.ClassID)
	}
	return cs.ClassStarts(ctx, ids)
}

// label: "Đã khai giảng" | "Sắp khai giảng dd/mm/yyyy".
func (c ClassStart) label() string {
	if c.Started {
		return "Đã khai giảng"
	}
	if c.StartDate != nil {
		return "Sắp khai giảng " + c.StartDate.Format("02/01/2006")
	}
	return "Sắp khai giảng"
}
