package tickets

import (
	"context"
	"slices"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// Phạm vi ticket "support_ticket.scope_*" (Laravel SupportTicket::scopeVisibleTo, rà soát 19/10/2026 b73d3ae6):
// Của tôi → ticket mình tạo hoặc được giao xử lý; Chi nhánh → thêm ticket do nhân sự thuộc chi nhánh mình tạo (chi nhánh
// chính hoặc được cấp thêm của người tạo); Toàn hệ thống → mọi ticket. Ticket ngoài phạm vi → 403 như Laravel.
const scopeModule = "support_ticket"

type ticketScope struct {
	all      bool
	me       uuid.UUID
	staff    iam.UserSet // nhân sự thuộc chi nhánh của người xem (chỉ ở mức Chi nhánh)
	branches []uuid.UUID // chi nhánh của người xem (mức Chi nhánh), cho truy vấn danh sách
}

func (s *Service) scopeOf(ctx context.Context, a *iam.Access) (ticketScope, error) {
	sc := ticketScope{me: a.UserID, branches: []uuid.UUID{}}
	switch a.Level(scopeModule) {
	case iam.ScopeAll:
		sc.all = true
	case iam.ScopeBranch:
		set, err := iam.StaffInBranches(ctx, s.pool, a, scopeModule)
		if err != nil {
			return sc, err
		}
		sc.staff, sc.branches = set, a.BranchIDs()
		if sc.staff == nil {
			sc.staff = iam.UserSet{}
		}
	}
	return sc, nil
}

func (sc ticketScope) sees(t ticketRef) bool {
	return sc.all || t.CreatorID == sc.me || t.assignedTo(sc.me) || (sc.staff != nil && sc.staff.Has(t.CreatorID))
}

// visible: người xem thấy ticket này theo phạm vi support_ticket.
func (s *Service) visible(ctx context.Context, a *iam.Access, t ticketRef) (bool, error) {
	sc, err := s.scopeOf(ctx, a)
	if err != nil {
		return false, err
	}
	return sc.sees(t), nil
}

// handlerCandidates: tài khoản có support_ticket.update nhận được phân công. Người phân công chưa ở mức Toàn hệ thống chỉ
// chọn được nhân sự thuộc chi nhánh của mình; Admin luôn có trong danh sách (người xử lý cuối) — handlerCandidates().
func (s *Service) handlerCandidates(ctx context.Context, a *iam.Access) ([]uuid.UUID, error) {
	ids, err := iam.UserIDsWithPermission(ctx, s.pool, PermUpdate, nil)
	if err != nil {
		return nil, err
	}
	if a == nil {
		return ids, nil
	}
	inScope, err := iam.StaffInBranches(ctx, s.pool, a, scopeModule)
	if err != nil || inScope == nil {
		return ids, err
	}
	admins, err := iam.UserIDsWithRole(ctx, s.pool, iam.RoleAdmin, nil)
	if err != nil {
		return nil, err
	}
	return slices.DeleteFunc(ids, func(id uuid.UUID) bool { return !inScope.Has(id) && !slices.Contains(admins, id) }), nil
}
