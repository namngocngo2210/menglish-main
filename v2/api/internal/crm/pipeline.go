package crm

import (
	"context"
	"errors"
	"strings"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/crm/crmdb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// ───────────────────────── Kanban ─────────────────────────

// Card: thẻ khách trên Kanban.
type Card struct {
	ID            uuid.UUID  `json:"id"`
	Code          string     `json:"code"`
	ShortCode     string     `json:"short_code"`
	Name          string     `json:"name"`
	ParentName    *string    `json:"parent_name"`
	Phone         string     `json:"phone"`
	Source        string     `json:"source"`
	Course        string     `json:"course"`
	DealValue     int64      `json:"deal_value"`
	Agent         string     `json:"agent"`
	CreatedAt     time.Time  `json:"created_at"`
	Score         string     `json:"score"`
	HasTestResult bool       `json:"has_test_result"`
	Confirmed     bool       `json:"confirmed"`
	NextBlocked   *string    `json:"next_blocked"`
	HasStudent    bool       `json:"has_student"`
	SLA           *SLA       `json:"sla"`
	ConvertedAt   *time.Time `json:"converted_at"`
}

// Column: một cột giai đoạn (tối đa 30 thẻ; hidden = số thẻ còn lại xem ở danh sách).
type Column struct {
	ID     string  `json:"id"`
	Name   string  `json:"name"`
	Next   *string `json:"next"`
	Count  int64   `json:"count"`
	Hidden int64   `json:"hidden"`
	Amount int64   `json:"amount"`
	Leads  []Card  `json:"leads"`
}

// Pipeline: Kanban 8 giai đoạn + quyền chuyển giai đoạn.
type Pipeline struct {
	Stages      []Column   `json:"stages"`
	Permissions StagePerms `json:"permissions"`
	Order       []string   `json:"order"`
	Closed      []string   `json:"closed"`
	Closable    []string   `json:"closable"`
	Counts      ChipCounts `json:"counts"`
}

func (s *Service) Pipeline(ctx context.Context, a *iam.Access, f ListFilter) (*Pipeline, error) {
	now := s.now()
	sc := scopeOf(a)
	p := f.prepare(a)
	rows, err := s.q.PipelineLeads(ctx, crmdb.PipelineLeadsParams{
		ColLimit: kanbanColumnLimit, ScopeOwner: sc.owner, ScopeBranches: sc.branches, Q: p.q, QDigits: p.qDigits,
		BranchID: p.branchID, AssignedUserID: p.assignedUserID, Source: p.source, DateFrom: p.from, DateTo: p.to,
		WonCutoff: now.AddDate(0, 0, -kanbanWonRecentDays),
	})
	if err != nil {
		return nil, err
	}
	cols := map[string]*Column{}
	out := &Pipeline{Permissions: stagePerms(a), Order: PipelineStages, Closed: ClosedStages, Closable: ClosableStages}
	for _, st := range PipelineStages {
		c := Column{ID: st, Name: StageLabel(st), Leads: []Card{}}
		if n := nextStage(st); n != "" {
			c.Next = &n
		}
		out.Stages = append(out.Stages, c)
	}
	for i := range out.Stages {
		cols[out.Stages[i].ID] = &out.Stages[i]
	}
	for _, r := range rows {
		c := cols[r.Stage]
		if c == nil {
			continue
		}
		c.Count, c.Amount = r.StageCount, r.StageAmount
		card := Card{
			ID: r.ID, Code: r.Code, ShortCode: ShortCode(r.Code), Name: r.Name, ParentName: r.ParentName, Phone: r.Phone,
			Source: orDefault(r.Source, "Trực tiếp"), Course: orDefault(r.CourseInterest, "Chưa chọn khóa"),
			DealValue: r.DealValue, Agent: orDefault(r.AssignedUserName, "Chưa phân công"), CreatedAt: r.CreatedAt,
			Score: orDefault(r.TestScore, "Chưa test"), HasTestResult: strings.TrimSpace(deref(r.TestScore)) != "",
			Confirmed: r.Confirmed, HasStudent: r.ConvertedStudentID != nil, ConvertedAt: r.ConvertedAt,
			SLA: contactSLA(r.Stage, r.CreatedAt, r.LastCareAt, r.NextFollowUpAt, now),
		}
		if n := nextStage(r.Stage); n != "" {
			if b := forwardBlockReason(n, r.AppointmentAt, r.TestScore); b != "" {
				card.NextBlocked = &b
			}
		}
		c.Leads = append(c.Leads, card)
	}
	for i := range out.Stages {
		out.Stages[i].Hidden = out.Stages[i].Count - int64(len(out.Stages[i].Leads))
	}
	if out.Counts, err = s.chipCounts(ctx, a); err != nil {
		return nil, err
	}
	return out, nil
}

func orDefault(p *string, def string) string {
	if p == nil || strings.TrimSpace(*p) == "" {
		return def
	}
	return *p
}

// ───────────────────────── Chờ xếp lớp ─────────────────────────

// WaitingRow: khách đã chốt chưa có lớp.
type WaitingRow struct {
	ID          uuid.UUID  `json:"id"`
	ShortCode   string     `json:"short_code"`
	Name        string     `json:"name"`
	Phone       string     `json:"phone"`
	StudentID   *uuid.UUID `json:"student_id"`
	Course      *string    `json:"course"`
	Branch      string     `json:"branch"`
	Assigned    *string    `json:"assigned_user"`
	ConvertedAt *time.Time `json:"converted_at"`
	WaitDays    *int       `json:"wait_days"`
	FeePaid     bool       `json:"fee_paid_at_closing"`
}

// Waiting: danh sách Chờ xếp lớp (cũ nhất trước). Gợi ý lớp phù hợp cần phân hệ lớp học (TODO(merge)).
func (s *Service) Waiting(ctx context.Context, a *iam.Access) ([]WaitingRow, ChipCounts, error) {
	sc := scopeOf(a)
	rows, err := s.q.WaitingLeads(ctx, crmdb.WaitingLeadsParams{ScopeOwner: sc.owner, ScopeBranches: sc.branches})
	if err != nil {
		return nil, ChipCounts{}, err
	}
	now := s.now()
	out := make([]WaitingRow, 0, len(rows))
	for _, r := range rows {
		w := WaitingRow{
			ID: r.ID, ShortCode: ShortCode(r.Code), Name: r.Name, Phone: r.Phone, StudentID: r.ConvertedStudentID,
			Course: r.CourseInterest, Branch: r.BranchName, Assigned: r.AssignedUserName, ConvertedAt: r.ConvertedAt,
			FeePaid: r.FeePaidAtClosing,
		}
		if r.ConvertedAt != nil {
			w.WaitDays = ptr(int(now.Sub(*r.ConvertedAt).Hours() / 24))
		}
		out = append(out, w)
	}
	counts, err := s.chipCounts(ctx, a)
	return out, counts, err
}

// ───────────────────────── Khách đã xóa ─────────────────────────

// DeletedRow: khách đã xóa (trước khi Laravel bỏ chức năng xóa).
type DeletedRow struct {
	ID           uuid.UUID  `json:"id"`
	ShortCode    string     `json:"short_code"`
	Name         string     `json:"name"`
	Phone        string     `json:"phone"`
	Branch       *string    `json:"branch"`
	Stage        string     `json:"stage"`
	StageLabel   string     `json:"stage_label"`
	AssignedUser *string    `json:"assigned_user"`
	DeletedAt    *time.Time `json:"deleted_at"`
}

func (s *Service) ListDeleted(ctx context.Context, a *iam.Access, search string, page, per int) (*Page[DeletedRow], error) {
	sc := scopeOf(a)
	var q *string
	if t := strings.TrimSpace(search); t != "" {
		q = ptr("%" + escapeLike(t) + "%")
	}
	page, per, lim, off := pageArgs(page, per, 20)
	rows, err := s.q.ListDeletedLeads(ctx, crmdb.ListDeletedLeadsParams{ScopeOwner: sc.owner, ScopeBranches: sc.branches, Q: q, Lim: lim, Off: off})
	if err != nil {
		return nil, err
	}
	out := &Page[DeletedRow]{Data: make([]DeletedRow, 0, len(rows)), Meta: Meta{Page: page, PerPage: per}}
	for _, r := range rows {
		out.Meta.Total = r.Total
		out.Data = append(out.Data, DeletedRow{
			ID: r.ID, ShortCode: ShortCode(r.Code), Name: r.Name, Phone: r.Phone, Branch: r.BranchName, Stage: r.Stage,
			StageLabel: StageLabel(r.Stage), AssignedUser: r.AssignedUserName, DeletedAt: r.DeletedAt,
		})
	}
	return out, nil
}

// Restore: khôi phục khách đã xóa; SĐT đang thuộc khách khác thì chặn, email đã có người dùng thì bỏ email.
func (s *Service) Restore(ctx context.Context, a *iam.Access, id uuid.UUID) (*Saved, error) {
	var out *Saved
	err := s.tx(ctx, func(q *crmdb.Queries) error {
		sc := scopeOf(a)
		l, err := q.GetDeletedLead(ctx, crmdb.GetDeletedLeadParams{ID: id, ScopeOwner: sc.owner, ScopeBranches: sc.branches})
		if errors.Is(err, pgx.ErrNoRows) {
			return errLeadNotFound
		}
		if err != nil {
			return err
		}
		norm := NormalizePhone(l.Phone)
		conflict, err := q.FindLeadByPhone(ctx, crmdb.FindLeadByPhoneParams{Phone: norm})
		if err == nil {
			return iam.Invalid("Không thể khôi phục " + l.Name + ": SĐT đang thuộc khách " + ShortCode(conflict.Code) +
				" (" + conflict.Name + "). Hãy xử lý trùng trước.")
		} else if !errors.Is(err, pgx.ErrNoRows) {
			return err
		}
		email := l.DeletedEmail
		if email != nil {
			taken, err := q.EmailTaken(ctx, crmdb.EmailTakenParams{Email: *email})
			if err != nil {
				return err
			}
			if taken {
				email = nil // email đã được khách khác dùng: khôi phục không kèm email.
			}
		}
		if err := q.RestoreLead(ctx, crmdb.RestoreLeadParams{ID: l.ID, PhoneNormalized: &norm, Email: email}); err != nil {
			return err
		}
		content := "Khôi phục khách hàng đã xóa."
		if l.DeletedEmail != nil && email == nil {
			content += " Email cũ đã thuộc khách khác nên không khôi phục email."
		}
		if _, err := q.InsertEvent(ctx, crmdb.InsertEventParams{LeadID: l.ID, UserID: &a.UserID, Type: "system", Content: content}); err != nil {
			return err
		}
		out = &Saved{ID: l.ID, Message: "Đã khôi phục khách hàng " + l.Name + "."}
		return nil
	})
	if err != nil {
		return nil, err
	}
	s.log.Info("crm: khôi phục khách", "lead", id, "by", a.UserID)
	return out, nil
}
