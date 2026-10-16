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

// Xác nhận chính thức nhập học (Laravel confirmations / confirmEnrollment): học viên chốt từ CRM đã vào lớp,
// Học vụ tick đủ 3 mục hồ sơ rồi bấm Xác nhận.

var confirmationChecklist = []struct{ Key, Label string }{
	{"account_sent", "Đã gửi tài khoản"},
	{"zalo_group_added", "Đã vào nhóm Zalo"},
	{"curriculum_delivered", "Đã nhận giáo trình"},
}

// ConfirmationRow: một lượt ghi danh chờ / đã xác nhận.
type ConfirmationRow struct {
	ID          uuid.UUID       `json:"id"`
	LeadID      uuid.UUID       `json:"lead_id"`
	StudentID   uuid.UUID       `json:"student_id"`
	StudentCode string          `json:"student_code"`
	StudentName string          `json:"student_name"`
	Phone       string          `json:"phone"`
	ClassID     uuid.UUID       `json:"class_id"`
	ClassName   string          `json:"class_name"`
	ClassCode   *string         `json:"class_code"`
	Branch      *string         `json:"branch"`
	StartLabel  string          `json:"start_label"`
	ClosedAt    time.Time       `json:"closed_at"`
	ConfirmedAt *time.Time      `json:"confirmed_at"`
	ConfirmedBy *string         `json:"confirmed_by"`
	Checklist   map[string]bool `json:"checklist"`
}

// ConfirmationList: màn Xác nhận chính thức.
type ConfirmationList struct {
	Page[ConfirmationRow]
	Status         string   `json:"status"`
	PendingCount   int64    `json:"pending_count"`
	TotalCount     int64    `json:"total_count"`
	FilterClasses  []Option `json:"filter_classes"`
	FilterBranches []Option `json:"filter_branches"`
	ChecklistItems []Option `json:"checklist_items"`
}

// ConfirmationFilter: bộ lọc màn Xác nhận chính thức.
type ConfirmationFilter struct {
	Status   string // pending | confirmed
	Search   string
	BranchID *uuid.UUID
	ClassID  *uuid.UUID
	Page     int
	PerPage  int
}

func (s *Service) ListConfirmations(ctx context.Context, a *iam.Access, f ConfirmationFilter) (*ConfirmationList, error) {
	sc := scopeOf(a)
	status := "pending"
	if f.Status == "confirmed" {
		status = "confirmed"
	}
	var q *string
	if t := strings.TrimSpace(f.Search); t != "" {
		q = ptr("%" + escapeLike(t) + "%")
	}
	page, per, lim, off := pageArgs(f.Page, f.PerPage, 20)
	rows, err := s.q.ListConfirmations(ctx, crmdb.ListConfirmationsParams{
		ScopeOwner: sc.owner, ScopeBranches: sc.branches, Confirmed: status == "confirmed", Q: q, BranchID: f.BranchID,
		ClassID: f.ClassID, Lim: lim, Off: off,
	})
	if err != nil {
		return nil, err
	}
	now := s.now()
	out := &ConfirmationList{
		Page: Page[ConfirmationRow]{Data: make([]ConfirmationRow, 0, len(rows)), Meta: Meta{Page: page, PerPage: per}}, Status: status,
		FilterClasses: []Option{}, FilterBranches: []Option{},
	}
	for _, c := range confirmationChecklist {
		out.ChecklistItems = append(out.ChecklistItems, Option{c.Key, c.Label})
	}
	for _, r := range rows {
		out.Meta.Total = r.Total
		row := ConfirmationRow{
			ID: r.ID, LeadID: r.LeadID, StudentID: r.StudentID, StudentCode: r.StudentCode, StudentName: r.StudentName,
			Phone: r.LeadPhone, ClassID: r.ClassID, ClassName: r.ClassName, ClassCode: r.ClassCode, Branch: r.BranchName,
			ClosedAt: r.EnrolledAt, ConfirmedAt: r.ConfirmedAt, ConfirmedBy: r.ConfirmedByName,
			Checklist: map[string]bool{"account_sent": r.AccountSent, "zalo_group_added": r.ZaloGroupAdded, "curriculum_delivered": r.CurriculumDelivered},
		}
		if r.LeadConvertedAt != nil {
			row.ClosedAt = *r.LeadConvertedAt
		}
		// TODO(merge): trạng thái khai giảng lấy từ phân hệ lớp học; tạm theo ngày khai giảng chép lúc ghi danh.
		switch {
		case r.ClassStartDate != nil && r.ClassStartDate.After(now):
			row.StartLabel = "Sắp khai giảng " + r.ClassStartDate.Format("02/01/2006")
		case r.ClassStartDate != nil:
			row.StartLabel = "Đã khai giảng"
		default:
			row.StartLabel = "Sắp khai giảng"
		}
		out.Data = append(out.Data, row)
	}
	counts, err := s.q.ConfirmationCounts(ctx, crmdb.ConfirmationCountsParams{ScopeOwner: sc.owner, ScopeBranches: sc.branches})
	if err != nil {
		return nil, err
	}
	out.PendingCount, out.TotalCount = counts.Pending, counts.Total
	opts, err := s.q.ConfirmationFilterOptions(ctx, crmdb.ConfirmationFilterOptionsParams{ScopeOwner: sc.owner, ScopeBranches: sc.branches})
	if err != nil {
		return nil, err
	}
	seenClass, seenBranch := map[uuid.UUID]bool{}, map[uuid.UUID]bool{}
	for _, o := range opts {
		if !seenClass[o.ClassID] {
			seenClass[o.ClassID] = true
			out.FilterClasses = append(out.FilterClasses, Option{o.ClassID.String(), o.ClassName})
		}
		if o.ClassBranchID != nil && !seenBranch[*o.ClassBranchID] {
			seenBranch[*o.ClassBranchID] = true
			out.FilterBranches = append(out.FilterBranches, Option{o.ClassBranchID.String(), deref(o.BranchName)})
		}
	}
	return out, nil
}

// ConfirmInput: lưu tiến độ (save) hoặc xác nhận chính thức (confirm).
type ConfirmInput struct {
	AccountSent         bool   `json:"account_sent"`
	ZaloGroupAdded      bool   `json:"zalo_group_added"`
	CurriculumDelivered bool   `json:"curriculum_delivered"`
	Action              string `json:"action"`
}

func (s *Service) Confirm(ctx context.Context, a *iam.Access, id uuid.UUID, in ConfirmInput) (*Saved, error) {
	if in.Action != "save" && in.Action != "confirm" {
		return nil, iam.Invalid("Thao tác không hợp lệ.")
	}
	confirming := in.Action == "confirm"
	var out *Saved
	err := s.tx(ctx, func(q *crmdb.Queries) error {
		sc := scopeOf(a)
		c, err := q.LockConfirmation(ctx, crmdb.LockConfirmationParams{ID: id, ScopeOwner: sc.owner, ScopeBranches: sc.branches})
		if errors.Is(err, pgx.ErrNoRows) {
			return errNotFound
		}
		if err != nil {
			return err
		}
		if c.ConfirmedAt != nil {
			return iam.Invalid("Học viên này đã được xác nhận chính thức.")
		}
		if confirming {
			var missing []string
			for i, v := range []bool{in.AccountSent, in.ZaloGroupAdded, in.CurriculumDelivered} {
				if !v {
					missing = append(missing, confirmationChecklist[i].Label)
				}
			}
			if len(missing) > 0 {
				return iam.Invalid("Chưa đủ hồ sơ để xác nhận chính thức: " + strings.Join(missing, ", ") + ".")
			}
		}
		if err := q.SaveConfirmation(ctx, crmdb.SaveConfirmationParams{
			ID: c.ID, AccountSent: in.AccountSent, ZaloGroupAdded: in.ZaloGroupAdded, CurriculumDelivered: in.CurriculumDelivered,
			Confirm: confirming, ConfirmedBy: &a.UserID,
		}); err != nil {
			return err
		}
		if !confirming {
			out = &Saved{ID: c.ID, Message: "Đã lưu tiến độ hồ sơ nhập học."}
			return nil
		}
		// TODO(merge): ClassStartActivation — lớp đã khai giảng thì học viên chính thức "Đang học" (phân hệ học viên).
		if _, err := q.InsertEvent(ctx, crmdb.InsertEventParams{
			LeadID: c.LeadID, UserID: &a.UserID, Type: "system",
			Content: "Xác nhận chính thức nhập học lớp " + c.ClassName + ": đã gửi tài khoản, đã vào nhóm Zalo, đã nhận giáo trình.",
		}); err != nil {
			return err
		}
		out = &Saved{ID: c.ID, Message: "Đã xác nhận chính thức học viên " + c.StudentName + "."}
		return nil
	})
	if err != nil {
		return nil, err
	}
	s.log.Info("crm: xác nhận nhập học", "confirmation", id, "action", in.Action, "by", a.UserID)
	return out, nil
}

// Enrollment: lượt ghi danh tạo từ CRM (phân hệ lớp học / học viên gọi khi Chốt & Xếp lớp hoặc Gán lớp).
type Enrollment struct {
	LeadID         uuid.UUID
	EnrollmentID   *uuid.UUID
	StudentID      uuid.UUID
	StudentCode    string
	StudentName    string
	ClassID        uuid.UUID
	ClassName      string
	ClassCode      *string
	ClassBranchID  *uuid.UUID
	ClassStartDate *time.Time
}

// RecordEnrollment ghi một dòng chờ xác nhận chính thức. q nil = ngoài giao dịch.
func (s *Service) RecordEnrollment(ctx context.Context, q *crmdb.Queries, e Enrollment) (uuid.UUID, error) {
	if q == nil {
		q = s.q
	}
	c, err := q.CreateConfirmation(ctx, crmdb.CreateConfirmationParams{
		LeadID: e.LeadID, EnrollmentID: e.EnrollmentID, StudentID: e.StudentID, ClassID: e.ClassID, StudentCode: e.StudentCode,
		StudentName: e.StudentName, ClassName: e.ClassName, ClassCode: e.ClassCode, ClassBranchID: e.ClassBranchID,
		ClassStartDate: e.ClassStartDate,
	})
	return c.ID, err
}
