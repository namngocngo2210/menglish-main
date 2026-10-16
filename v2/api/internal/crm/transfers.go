package crm

import (
	"context"
	"errors"
	"strconv"
	"strings"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/crm/crmdb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// Đổi người phụ trách (Laravel changeOwner + CrmBranchTransferService). Cùng cơ sở → đổi ngay. Khác cơ sở →
// người có lead.approve_transfer (Admin) đổi ngay kèm chuyển cơ sở, người khác gửi yêu cầu chờ Admin duyệt.

func (s *Service) branchName(ctx context.Context, q *crmdb.Queries, id *uuid.UUID) (*string, error) {
	if id == nil {
		return nil, nil
	}
	b, err := q.GetBranchBrief(ctx, *id)
	if errors.Is(err, pgx.ErrNoRows) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	return &b.Name, nil
}

func (s *Service) userName(ctx context.Context, q *crmdb.Queries, id *uuid.UUID) (*string, error) {
	if id == nil {
		return nil, nil
	}
	u, err := q.GetUserBrief(ctx, *id)
	if errors.Is(err, pgx.ErrNoRows) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	return &u.FullName, nil
}

// changeOwner đổi người phụ trách trong giao dịch riêng; trả thông báo khi đã gửi yêu cầu chờ duyệt.
func (s *Service) changeOwner(ctx context.Context, a *iam.Access, leadID uuid.UUID, o *owner, reason *string, title string, toBranch *uuid.UUID) (string, error) {
	var msg string
	err := s.tx(ctx, func(q *crmdb.Queries) error {
		var err error
		msg, err = s.changeOwnerTx(ctx, q, a, leadID, o, reason, title, toBranch)
		return err
	})
	return msg, err
}

func (s *Service) changeOwnerTx(ctx context.Context, q *crmdb.Queries, a *iam.Access, leadID uuid.UUID, o *owner, reason *string, title string, toBranch *uuid.UUID) (string, error) {
	if o == nil || !o.Candidate {
		return "", iam.Invalid(ownerInvalidMsg)
	}
	l, err := lockLead(ctx, q, leadID)
	if err != nil {
		return "", err
	}
	needsTransfer := !o.belongsToBranch(l.BranchID)
	if toBranch != nil {
		needsTransfer = l.BranchID == nil || *toBranch != *l.BranchID
	}
	if needsTransfer {
		return s.requestOrApply(ctx, q, a, l, o, reason, toBranch)
	}
	oldName, err := s.userName(ctx, q, l.AssignedUserID)
	if err != nil {
		return "", err
	}
	if err := q.SetLeadOwner(ctx, crmdb.SetLeadOwnerParams{ID: l.ID, AssignedUserID: &o.ID}); err != nil {
		return "", err
	}
	keys, changes := diffValues(map[string]*string{"assigned_user_id": oldName}, map[string]*string{"assigned_user_id": &o.Name})
	if len(keys) > 0 {
		if err := insertChanges(ctx, q, l.ID, a.UserID, "assign", title, reason, keys, changes); err != nil {
			return "", err
		}
	}
	// TODO(notify): báo người phụ trách mới "Bạn được giao phụ trách khách {tên}".
	return "", nil
}

// ownerPhrase: "chuyển người phụ trách sang X" hoặc "giữ người phụ trách X" (chỉ đổi cơ sở).
func ownerPhrase(fromUser *uuid.UUID, o *owner) string {
	if fromUser != nil && *fromUser == o.ID {
		return "giữ người phụ trách " + o.Name
	}
	return "chuyển người phụ trách sang " + o.Name
}

func (s *Service) requestOrApply(ctx context.Context, q *crmdb.Queries, a *iam.Access, l crmdb.CrmLead, o *owner, reason *string, toBranch *uuid.UUID) (string, error) {
	if toBranch == nil {
		if hb := o.homeBranch(); hb != nil {
			toBranch = &hb.ID
		}
	}
	if toBranch == nil {
		return "", iam.Invalid(o.Name + " chưa được gán cơ sở nên không chuyển khách sang được.")
	}
	if _, err := q.PendingTransferOfLead(ctx, l.ID); err == nil {
		return "", iam.Invalid("Khách đang có yêu cầu chuyển cơ sở chờ Admin duyệt. Chờ duyệt xong rồi đổi tiếp.")
	} else if !errors.Is(err, pgx.ErrNoRows) {
		return "", err
	}
	t, err := q.CreateTransfer(ctx, crmdb.CreateTransferParams{
		LeadID: l.ID, FromBranchID: l.BranchID, ToBranchID: *toBranch, FromUserID: l.AssignedUserID, ToUserID: o.ID,
		RequestedBy: &a.UserID, Reason: reason,
	})
	if isUniqueViolation(err) {
		return "", iam.Invalid("Khách đang có yêu cầu chuyển cơ sở chờ Admin duyệt. Chờ duyệt xong rồi đổi tiếp.")
	}
	if err != nil {
		return "", err
	}
	if a.Can("lead.approve_transfer") {
		return "", s.applyTransfer(ctx, q, t, a.UserID, nil)
	}
	fromName, err := s.branchName(ctx, q, l.BranchID)
	if err != nil {
		return "", err
	}
	toName, err := s.branchName(ctx, q, toBranch)
	if err != nil {
		return "", err
	}
	content := "Gửi yêu cầu " + ownerPhrase(l.AssignedUserID, o) + " và chuyển cơ sở " + orNone(fromName) + " → " +
		deref(toName) + ". Chờ Admin duyệt."
	if reason != nil && *reason != "" {
		content += " Lý do: " + *reason
	}
	if _, err := q.InsertEvent(ctx, crmdb.InsertEventParams{LeadID: l.ID, UserID: &a.UserID, Type: "assign", Reason: reason, Content: content}); err != nil {
		return "", err
	}
	// TODO(notify): báo Admin có yêu cầu chuyển cơ sở chờ duyệt (hộp Việc cần duyệt — "Chuyển cơ sở (CRM)").
	return "Đã gửi yêu cầu chuyển khách sang " + o.Name + " (" + deref(toName) + "). Chờ Admin duyệt chuyển cơ sở.", nil
}

func orNone(p *string) string {
	if p == nil || *p == "" {
		return "(chưa có)"
	}
	return *p
}

// applyTransfer: đổi người phụ trách + cơ sở của khách (và cơ sở chờ xếp lớp).
func (s *Service) applyTransfer(ctx context.Context, q *crmdb.Queries, t crmdb.CrmBranchTransfer, actor uuid.UUID, note *string) error {
	l, err := lockLead(ctx, q, t.LeadID)
	if err != nil {
		return err
	}
	o, err := s.loadOwner(ctx, q, t.ToUserID)
	if err != nil {
		return err
	}
	if o == nil || !o.Candidate {
		name := ""
		if o != nil {
			name = o.Name
		}
		return iam.Invalid(name + " không còn là người phụ trách khách được (tài khoản khóa hoặc đổi vai trò).")
	}
	if err := q.DecideTransfer(ctx, crmdb.DecideTransferParams{ID: t.ID, Status: "approved", DecidedBy: &actor, DecisionNote: note}); err != nil {
		return err
	}
	if err := q.MoveLeadBranch(ctx, crmdb.MoveLeadBranchParams{ID: l.ID, AssignedUserID: &o.ID, BranchID: t.ToBranchID}); err != nil {
		return err
	}
	// TODO(merge): khách đã chốt → học viên chuyển sang cơ sở mới (students.Service), thêm " Học viên {tên} chuyển sang cơ sở mới."
	fromUser, err := s.userName(ctx, q, t.FromUserID)
	if err != nil {
		return err
	}
	fromBranch, err := s.branchName(ctx, q, t.FromBranchID)
	if err != nil {
		return err
	}
	toBranch, err := s.branchName(ctx, q, &t.ToBranchID)
	if err != nil {
		return err
	}
	phrase := ownerPhrase(t.FromUserID, o)
	if t.RequestedBy != nil && *t.RequestedBy == actor {
		phrase = strings.ToUpper(phrase[:1]) + phrase[1:]
	} else {
		phrase = "Admin duyệt " + phrase
	}
	content := phrase + ", chuyển cơ sở " + orNone(fromBranch) + " → " + deref(toBranch) + "."
	if t.Reason != nil && *t.Reason != "" {
		content += " Lý do: " + *t.Reason
	}
	if note != nil && *note != "" {
		content += " Ghi chú duyệt: " + *note
	}
	changes, err := jsonBytes(map[string]Change{
		"assigned_user_id": {"Người phụ trách", fromUser, &o.Name},
		"branch_id":        {"Cơ sở", fromBranch, toBranch},
	})
	if err != nil {
		return err
	}
	_, err = q.InsertEvent(ctx, crmdb.InsertEventParams{
		LeadID: l.ID, UserID: &actor, Type: "assign", Reason: t.Reason, Changes: changes, Content: content,
	})
	return err
}

// ReassignInput: phân công lại người phụ trách (bắt buộc lý do), có thể chọn kèm cơ sở.
type ReassignInput struct {
	AssignedUserID *uuid.UUID `json:"assigned_user_id"`
	BranchID       *uuid.UUID `json:"branch_id"`
	Reason         string     `json:"reason"`
}

func (s *Service) Reassign(ctx context.Context, a *iam.Access, idOrCode string, in ReassignInput) (*Saved, error) {
	l, err := s.lead(ctx, s.q, a, idOrCode)
	if err != nil {
		return nil, err
	}
	reason := strings.TrimSpace(in.Reason)
	if in.AssignedUserID == nil {
		return nil, iam.Invalid("Vui lòng chọn người phụ trách.")
	}
	if reason == "" {
		return nil, iam.Invalid("Vui lòng nhập lý do phân công lại.")
	}
	if runeLen(reason) > 1000 {
		return nil, iam.Invalid("Lý do không được vượt quá 1000 ký tự.")
	}
	o, err := s.loadOwner(ctx, s.q, *in.AssignedUserID)
	if err != nil {
		return nil, err
	}
	if o == nil {
		return nil, iam.Invalid(ownerInvalidMsg)
	}
	if in.BranchID != nil && (l.BranchID == nil || *in.BranchID != *l.BranchID) {
		b, err := s.q.GetBranchBrief(ctx, *in.BranchID)
		if errors.Is(err, pgx.ErrNoRows) {
			return nil, iam.Invalid("Cơ sở không hợp lệ.")
		}
		if err != nil {
			return nil, err
		}
		if !b.IsActive {
			return nil, iam.Invalid("Cơ sở này đã ngừng hoạt động.")
		}
	}
	if in.BranchID != nil && o.Candidate && !o.belongsToBranch(in.BranchID) {
		return nil, iam.Invalid(o.Name + " không thuộc cơ sở đã chọn. Chọn Học vụ cùng cơ sở hoặc Admin.")
	}
	if l.AssignedUserID != nil && *l.AssignedUserID == o.ID && (in.BranchID == nil || (l.BranchID != nil && *in.BranchID == *l.BranchID)) {
		return nil, iam.Invalid("Khách đang do người này phụ trách.")
	}
	msg, err := s.changeOwner(ctx, a, l.ID, o, &reason, "Phân công lại người phụ trách", in.BranchID)
	if err != nil {
		return nil, err
	}
	if msg == "" {
		msg = "Đã phân công lại khách cho " + o.Name + "."
	}
	s.log.Info("crm: phân công lại", "lead", l.ID, "to", o.ID, "by", a.UserID)
	return &Saved{ID: l.ID, Message: msg}, nil
}

// BulkReassignInput: phân công lại hàng loạt (Học vụ nghỉ việc…): một người nhận, một lý do.
type BulkReassignInput struct {
	IDs            []uuid.UUID `json:"ids"`
	AssignedUserID *uuid.UUID  `json:"assigned_user_id"`
	Reason         string      `json:"reason"`
}

func (s *Service) BulkReassign(ctx context.Context, a *iam.Access, in BulkReassignInput) (*Saved, error) {
	if len(in.IDs) == 0 {
		return nil, iam.Invalid("Chọn ít nhất một khách.")
	}
	if len(in.IDs) > 500 {
		return nil, iam.Invalid("Mỗi lần phân công tối đa 500 khách.")
	}
	reason := strings.TrimSpace(in.Reason)
	if in.AssignedUserID == nil {
		return nil, iam.Invalid("Vui lòng chọn người phụ trách.")
	}
	if reason == "" {
		return nil, iam.Invalid("Vui lòng nhập lý do phân công lại.")
	}
	if runeLen(reason) > 1000 {
		return nil, iam.Invalid("Lý do không được vượt quá 1000 ký tự.")
	}
	o, err := s.loadOwner(ctx, s.q, *in.AssignedUserID)
	if err != nil {
		return nil, err
	}
	if o == nil || !o.Candidate {
		return nil, iam.Invalid(ownerInvalidMsg)
	}
	sc := scopeOf(a)
	leads, err := s.q.ListLeadsByIDs(ctx, crmdb.ListLeadsByIDsParams{Ids: in.IDs, ScopeOwner: sc.owner, ScopeBranches: sc.branches})
	if err != nil {
		return nil, err
	}
	var moved, pending, skipped int
	for _, l := range leads {
		if l.AssignedUserID != nil && *l.AssignedUserID == o.ID {
			skipped++
			continue
		}
		msg, err := s.changeOwner(ctx, a, l.ID, o, &reason, "Phân công lại hàng loạt", nil)
		if err != nil {
			return nil, err
		}
		if msg != "" {
			pending++
		} else {
			moved++
		}
	}
	msg := "Đã phân công " + strconv.Itoa(moved) + " khách cho " + o.Name + "."
	if pending > 0 {
		msg += " " + strconv.Itoa(pending) + " khách khác cơ sở đã gửi yêu cầu chuyển, chờ Admin duyệt."
	}
	if skipped > 0 {
		msg += " Bỏ qua " + strconv.Itoa(skipped) + " khách đã do người này phụ trách."
	}
	s.log.Info("crm: phân công lại hàng loạt", "count", moved, "pending", pending, "to", o.ID, "by", a.UserID)
	return &Saved{Message: msg}, nil
}

// ───────────────────────── Duyệt chuyển cơ sở ─────────────────────────

// TransferRow: một yêu cầu chuyển cơ sở.
type TransferRow struct {
	ID            uuid.UUID  `json:"id"`
	LeadID        uuid.UUID  `json:"lead_id"`
	LeadName      string     `json:"lead_name"`
	LeadCode      string     `json:"lead_code"`
	HasStudent    bool       `json:"has_student"`
	FromBranch    *string    `json:"from_branch"`
	ToBranch      *string    `json:"to_branch"`
	FromUser      *string    `json:"from_user"`
	ToUser        *string    `json:"to_user"`
	Requester     *string    `json:"requester"`
	Reason        *string    `json:"reason"`
	Status        string     `json:"status"`
	Decider       *string    `json:"decider"`
	DecidedAt     *time.Time `json:"decided_at"`
	DecisionNote  *string    `json:"decision_note"`
	CreatedAt     time.Time  `json:"created_at"`
	StatusLabel   string     `json:"status_label"`
	LeadShortCode string     `json:"lead_short_code"`
}

var transferStatusLabels = map[string]string{"pending": "Chờ duyệt", "approved": "Đã duyệt", "rejected": "Từ chối"}

func (s *Service) ListTransfers(ctx context.Context, status string, page, per int) (*Page[TransferRow], error) {
	var st *string
	if _, ok := transferStatusLabels[status]; ok {
		st = &status
	}
	page, per, lim, off := pageArgs(page, per, 20)
	rows, err := s.q.ListTransfers(ctx, crmdb.ListTransfersParams{Status: st, Lim: lim, Off: off})
	if err != nil {
		return nil, err
	}
	out := &Page[TransferRow]{Data: make([]TransferRow, 0, len(rows)), Meta: Meta{Page: page, PerPage: per}}
	for _, r := range rows {
		out.Meta.Total = r.Total
		out.Data = append(out.Data, TransferRow{
			ID: r.ID, LeadID: r.LeadID, LeadName: r.LeadName, LeadCode: r.LeadCode, LeadShortCode: ShortCode(r.LeadCode),
			HasStudent: r.ConvertedStudentID != nil, FromBranch: r.FromBranchName, ToBranch: r.ToBranchName,
			FromUser: r.FromUserName, ToUser: r.ToUserName, Requester: r.RequesterName, Reason: r.Reason, Status: r.Status,
			StatusLabel: transferStatusLabels[r.Status], Decider: r.DeciderName, DecidedAt: r.DecidedAt,
			DecisionNote: r.DecisionNote, CreatedAt: r.CreatedAt,
		})
	}
	return out, nil
}

func (s *Service) lockPendingTransfer(ctx context.Context, q *crmdb.Queries, id uuid.UUID) (crmdb.CrmBranchTransfer, error) {
	t, err := q.LockTransfer(ctx, id)
	if errors.Is(err, pgx.ErrNoRows) {
		return t, errNotFound
	}
	if err != nil {
		return t, err
	}
	if t.Status != "pending" {
		return t, iam.Invalid("Yêu cầu này đã được xử lý.")
	}
	return t, nil
}

func (s *Service) ApproveTransfer(ctx context.Context, a *iam.Access, id uuid.UUID, note *string) (*Saved, error) {
	note = strPtr(deref(note))
	if err := maxLen(note, 1000, "Ghi chú"); err != nil {
		return nil, err
	}
	err := s.tx(ctx, func(q *crmdb.Queries) error {
		t, err := s.lockPendingTransfer(ctx, q, id)
		if err != nil {
			return err
		}
		return s.applyTransfer(ctx, q, t, a.UserID, note)
	})
	if err != nil {
		return nil, err
	}
	s.log.Info("crm: duyệt chuyển cơ sở", "transfer", id, "by", a.UserID)
	return &Saved{ID: id, Message: "Đã chuyển cơ sở cho khách."}, nil
}

func (s *Service) RejectTransfer(ctx context.Context, a *iam.Access, id uuid.UUID, reason string) (*Saved, error) {
	reason = strings.TrimSpace(reason)
	if reason == "" {
		return nil, iam.Invalid("Vui lòng nhập lý do từ chối.")
	}
	if runeLen(reason) > 1000 {
		return nil, iam.Invalid("Lý do không được vượt quá 1000 ký tự.")
	}
	err := s.tx(ctx, func(q *crmdb.Queries) error {
		t, err := s.lockPendingTransfer(ctx, q, id)
		if err != nil {
			return err
		}
		if err := q.DecideTransfer(ctx, crmdb.DecideTransferParams{ID: t.ID, Status: "rejected", DecidedBy: &a.UserID, DecisionNote: &reason}); err != nil {
			return err
		}
		toUser, err := s.userName(ctx, q, &t.ToUserID)
		if err != nil {
			return err
		}
		toBranch, err := s.branchName(ctx, q, &t.ToBranchID)
		if err != nil {
			return err
		}
		_, err = q.InsertEvent(ctx, crmdb.InsertEventParams{
			LeadID: t.LeadID, UserID: &a.UserID, Type: "assign", Reason: &reason,
			Content: "Từ chối chuyển người phụ trách sang " + deref(toUser) + " (" + deref(toBranch) +
				"). Khách giữ cơ sở và người phụ trách cũ. Lý do: " + reason,
		})
		return err
	})
	if err != nil {
		return nil, err
	}
	s.log.Info("crm: từ chối chuyển cơ sở", "transfer", id, "by", a.UserID)
	return &Saved{ID: id, Message: "Đã từ chối chuyển cơ sở."}, nil
}
