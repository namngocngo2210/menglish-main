package crm

import (
	"context"
	"strings"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/approvals"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/crm/crmdb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// ApprovalSources: nguồn CRM của hộp "Việc cần duyệt" — Xác nhận chính thức (chỉ link màn gốc) và Chuyển cơ sở
// (duyệt / từ chối ngay trong hộp), như EnrollmentConfirmationApprovalSource / BranchTransferApprovalSource.
func (s *Service) ApprovalSources() []approvals.Source {
	return []approvals.Source{confirmationSource{s: s}, transferSource{s}}
}

// ───── Xác nhận chính thức (cần tick checklist thật → màn gốc) ─────

type confirmationSource struct {
	approvals.LinkOnly
	s *Service
}

func (confirmationSource) Key() string      { return "crm_confirmation" }
func (confirmationSource) Label() string    { return "Xác nhận chính thức (CRM)" }
func (confirmationSource) Group() string    { return approvals.GroupAcademic }
func (confirmationSource) IndexURL() string { return "/crm/confirmations" }
func (confirmationSource) CanView(a *iam.Access) bool {
	return a.Can("lead.view") && a.Can("student.assign_class")
}

func (x confirmationSource) Count(ctx context.Context, _ *iam.Access) (int64, error) {
	return x.s.q.ApprovalConfirmationCount(ctx)
}

func (x confirmationSource) list(ctx context.Context, id *uuid.UUID, limit int) ([]approvals.Item, error) {
	rows, err := x.s.q.ApprovalConfirmations(ctx, crmdb.ApprovalConfirmationsParams{ID: id, Lim: int32(limit)})
	out := make([]approvals.Item, 0, len(rows))
	for _, r := range rows {
		done := map[string]bool{"account_sent": r.AccountSent, "zalo_group_added": r.ZaloGroupAdded, "curriculum_delivered": r.CurriculumDelivered}
		missing := []string{}
		meta := []string{"Học viên", r.StudentName + " (" + r.StudentCode + ")", "Lớp", r.ClassName,
			"Tư vấn phụ trách", deref(r.ConsultantName), "Ngày ghi danh", r.EnrolledAt.In(vnZone).Format("02/01/2006")}
		for _, c := range confirmationChecklist {
			if !done[c.Key] {
				missing = append(missing, c.Label)
			}
			meta = append(meta, c.Label, map[bool]string{true: "Rồi", false: "Chưa"}[done[c.Key]])
		}
		sub := "Đủ hồ sơ, chờ xác nhận"
		if len(missing) > 0 {
			sub = "Còn thiếu: " + strings.Join(missing, ", ")
		}
		out = append(out, approvals.Item{Source: x.Key(), ID: r.ID, Title: r.StudentName + " → " + r.ClassName, Subtitle: &sub,
			URL: "/crm/confirmations?search=" + r.StudentCode, CreatedAt: &r.CreatedAt, Meta: approvals.MetaOf(meta...)})
	}
	return out, err
}

func (x confirmationSource) Pending(ctx context.Context, _ *iam.Access, limit int) ([]approvals.Item, error) {
	return x.list(ctx, nil, limit)
}

func (x confirmationSource) Find(ctx context.Context, _ *iam.Access, id uuid.UUID) (*approvals.Item, error) {
	items, err := x.list(ctx, &id, 1)
	if err != nil || len(items) == 0 {
		return nil, err
	}
	return &items[0], nil
}

// ───── Chuyển cơ sở ─────

type transferSource struct{ s *Service }

func (transferSource) Key() string   { return "crm_branch_transfer" }
func (transferSource) Label() string { return "Chuyển cơ sở (CRM)" }
func (transferSource) Group() string { return approvals.GroupAcademic }
func (transferSource) IndexURL() string {
	return "/approvals?group=" + approvals.GroupSlug(approvals.GroupAcademic)
}
func (transferSource) CanView(a *iam.Access) bool { return a.Can("lead.approve_transfer") }

func (x transferSource) Count(ctx context.Context, _ *iam.Access) (int64, error) {
	return x.s.q.ApprovalTransferCount(ctx)
}

func (x transferSource) list(ctx context.Context, id *uuid.UUID, limit int) ([]approvals.Item, error) {
	rows, err := x.s.q.ApprovalTransfers(ctx, crmdb.ApprovalTransfersParams{ID: id, Lim: int32(limit)})
	out := make([]approvals.Item, 0, len(rows))
	for _, r := range rows {
		from := deref(r.FromBranchName)
		if from == "" {
			from = "(chưa có)"
		}
		fromUser := deref(r.FromUserName)
		if fromUser == "" {
			fromUser = "Chưa phân công"
		}
		student := ""
		if r.ConvertedStudentID != nil {
			student = "Đã chốt, học viên chuyển cơ sở theo"
		}
		sub := "Người phụ trách mới: " + deref(r.ToUserName) + " · Người gửi: " + deref(r.RequesterName)
		out = append(out, approvals.Item{Source: x.Key(), ID: r.ID, Title: r.LeadName + ": " + from + " → " + deref(r.ToBranchName),
			Subtitle: &sub, URL: "/crm/customers/" + r.LeadID.String(), CreatedAt: &r.CreatedAt,
			Meta: approvals.MetaOf("Khách", r.LeadName+" ("+ShortCode(r.LeadCode)+")", "Cơ sở hiện tại", from,
				"Chuyển sang cơ sở", deref(r.ToBranchName), "Người phụ trách hiện tại", fromUser,
				"Người phụ trách mới", deref(r.ToUserName), "Học viên", student, "Người gửi", deref(r.RequesterName),
				"Lý do", deref(r.Reason))})
	}
	return out, err
}

func (x transferSource) Pending(ctx context.Context, _ *iam.Access, limit int) ([]approvals.Item, error) {
	return x.list(ctx, nil, limit)
}

func (x transferSource) Find(ctx context.Context, _ *iam.Access, id uuid.UUID) (*approvals.Item, error) {
	items, err := x.list(ctx, &id, 1)
	if err != nil || len(items) == 0 {
		return nil, err
	}
	return &items[0], nil
}

func (x transferSource) Supports(a *iam.Access, _ string) bool { return x.CanView(a) }

func (x transferSource) Approve(ctx context.Context, a *iam.Access, id uuid.UUID) (string, error) {
	res, err := x.s.ApproveTransfer(ctx, a, id, nil)
	if err != nil {
		return "", err
	}
	return res.Message, nil
}

func (x transferSource) Reject(ctx context.Context, a *iam.Access, id uuid.UUID, reason string) (string, error) {
	res, err := x.s.RejectTransfer(ctx, a, id, reason)
	if err != nil {
		return "", err
	}
	return res.Message, nil
}
