package crm

import (
	"context"
	"errors"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/crm/crmdb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/placement"
)

// PlacementLeads: cổng khách CRM cho phân hệ test đầu vào & học thử (placement.Leads), truyền vào
// placement.Options{Leads: …} ở cmd/menglish/main.go.
func (s *Service) PlacementLeads() placement.Leads { return placementLeads{s} }

type placementLeads struct{ s *Service }

// VisibleIDs: cùng phạm vi với danh sách khách CRM (scopeOf: phụ trách / chi nhánh / toàn hệ thống).
func (p placementLeads) VisibleIDs(ctx context.Context, a *iam.Access) ([]uuid.UUID, bool, error) {
	sc := scopeOf(a)
	if sc.owner == nil && sc.branches == nil {
		return nil, true, nil
	}
	ids, err := p.s.q.ListLeadIDsInScope(ctx, crmdb.ListLeadIDsInScopeParams{ScopeOwner: sc.owner, ScopeBranches: sc.branches})
	return ids, false, err
}

func toPlacementLead(r crmdb.PlacementLeadsRow) placement.Lead {
	return placement.Lead{
		ID: r.ID, Code: r.Code, Name: r.Name, ParentName: deref(r.ParentName), Phone: r.Phone, Email: deref(r.Email),
		Stage: r.Stage, StageLabel: StageLabel(r.Stage), BranchID: r.BranchID, AssignedUserID: r.AssignedUserID,
		AssignedName: r.AssignedUserName, TestScore: deref(r.TestScore),
	}
}

func (p placementLeads) Get(ctx context.Context, id uuid.UUID) (*placement.Lead, error) {
	rows, err := p.s.q.PlacementLeads(ctx, []uuid.UUID{id})
	if err != nil || len(rows) == 0 {
		return nil, err
	}
	l := toPlacementLead(rows[0])
	return &l, nil
}

func (p placementLeads) Lookup(ctx context.Context, ids []uuid.UUID) (map[uuid.UUID]placement.Lead, error) {
	out := map[uuid.UUID]placement.Lead{}
	if len(ids) == 0 {
		return out, nil
	}
	rows, err := p.s.q.PlacementLeads(ctx, ids)
	for _, r := range rows {
		out[r.ID] = toPlacementLead(r)
	}
	return out, err
}

// MatchByPhone: khách chưa chốt có SĐT khớp; không có thì khớp SĐT phụ huynh của đúng một khách chưa chốt (thí sinh
// nhỏ tuổi hay nhập SĐT phụ huynh, anh chị em dùng chung SĐT phụ huynh thì không đoán) — như Laravel
// resolveSubmissionLead.
func (p placementLeads) MatchByPhone(ctx context.Context, phone string) (*placement.Lead, error) {
	phone = NormalizePhone(phone)
	if len(phone) < 9 {
		return nil, nil
	}
	id, err := p.s.q.MatchLeadByPhone(ctx, phone)
	if errors.Is(err, pgx.ErrNoRows) {
		cands, err := p.s.q.MatchLeadsByParentPhone(ctx, phone[len(phone)-9:])
		if err != nil {
			return nil, err
		}
		var hits []uuid.UUID
		for _, c := range cands {
			if NormalizePhone(c.ParentPhone) == phone {
				hits = append(hits, c.ID)
			}
		}
		if len(hits) != 1 {
			return nil, nil
		}
		id = hits[0]
	} else if err != nil {
		return nil, err
	}
	return p.Get(ctx, id)
}

// Record: ghi điểm test (khách Thất bại giữ nguyên), chuyển giai đoạn chỉ đi tiến rồi ghi nhật ký khách, cùng một
// giao dịch. Không chuyển được thì nhật ký ghi thêm "Giữ nguyên giai đoạn hiện tại" như Laravel. Khách đã xóa: bỏ qua.
func (p placementLeads) Record(ctx context.Context, ev placement.LeadEvent) error {
	return p.s.tx(ctx, func(q *crmdb.Queries) error {
		l, err := lockLead(ctx, q, ev.LeadID)
		if errors.Is(err, errLeadNotFound) {
			return nil
		}
		if err != nil {
			return err
		}
		if ev.TestScore != nil && l.Stage != StageLost {
			if err := p.s.SetLeadTest(ctx, q, l.ID, TestUpdate{
				AppointmentAt: l.AppointmentAt, AppointmentType: l.AppointmentType, AssignedTestID: l.AssignedTestID,
				ExaminerID: l.ExaminerID, TestScore: ev.TestScore, TestDecision: l.TestDecision,
			}); err != nil {
				return err
			}
		}
		content := ev.Content
		if ev.AdvanceTo != "" {
			advanced, err := p.s.AdvanceTo(ctx, q, l.ID, ev.AdvanceTo, ev.ActorID, ev.Content)
			if err != nil {
				return err
			}
			already := l.Stage == ev.AdvanceTo || (ev.AdvanceTo == StageTested && l.Stage == StageResultSent)
			if !advanced && !already {
				content += " Giữ nguyên giai đoạn hiện tại (" + StageLabel(l.Stage) + ")."
			}
		}
		user := ev.ActorID
		if user == nil {
			user = l.AssignedUserID
		}
		_, err = q.InsertEvent(ctx, crmdb.InsertEventParams{LeadID: l.ID, UserID: user, Type: ev.Type, Content: content})
		return err
	})
}
