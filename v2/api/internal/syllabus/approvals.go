package syllabus

import (
	"context"
	"strconv"
	"strings"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/approvals"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/syllabus/syllabusdb"
)

// Nguồn của hộp "Việc cần duyệt" (ProposalApprovalSource, AdjustmentApprovalSource, BigTestOrderApprovalSource): duyệt /
// từ chối gọi đúng nghiệp vụ của màn gốc. Hộp chỉ dành cho Admin nên người duyệt thấy mọi mục như màn gốc.
func (s *Service) ApprovalSources() []approvals.Source {
	return []approvals.Source{proposalSource{s}, pacingSource{s}, orderSource{s}}
}

// inboxLimit: số mục tối đa đọc để đếm / tìm (hộp hiện tối đa approvals.PerSource mục mỗi nguồn).
const inboxLimit = 500

func subtitle(parts ...string) *string {
	out := []string{}
	for _, p := range parts {
		if p = strings.TrimSpace(p); p != "" {
			out = append(out, p)
		}
	}
	if len(out) == 0 {
		return nil
	}
	v := strings.Join(out, " · ")
	return &v
}

func pick(items []approvals.Item, id uuid.UUID) *approvals.Item {
	for i := range items {
		if items[i].ID == id {
			return &items[i]
		}
	}
	return nil
}

func firstN(items []approvals.Item, n int) []approvals.Item {
	if len(items) > n {
		return items[:n]
	}
	return items
}

func message(m *Message, err error) (string, error) {
	if err != nil {
		return "", err
	}
	return m.Message, nil
}

// ───── Đề xuất sửa giáo trình ─────

type proposalSource struct{ s *Service }

func (proposalSource) Key() string      { return "syllabus_proposal" }
func (proposalSource) Label() string    { return "Đề xuất sửa giáo trình" }
func (proposalSource) Group() string    { return approvals.GroupAcademic }
func (proposalSource) IndexURL() string { return "/syllabus/versions?status=pending" }
func (proposalSource) CanView(a *iam.Access) bool {
	return a.Can("syllabus.view") && a.Can("syllabus.approve_adjustment")
}

func (x proposalSource) Count(ctx context.Context, a *iam.Access) (int64, error) {
	return x.s.q.CountProposals(ctx, syllabusdb.CountProposalsParams{AllRows: true, UserID: a.UserID, Status: ptr("pending")})
}

func (x proposalSource) list(ctx context.Context, a *iam.Access, limit int) ([]approvals.Item, error) {
	rows, err := x.s.q.ListProposals(ctx, syllabusdb.ListProposalsParams{AllRows: true, UserID: a.UserID, Status: ptr("pending"),
		Lim: int32(limit), Off: 0})
	if err != nil {
		return nil, err
	}
	out := make([]approvals.Item, 0, len(rows))
	for _, p := range rows {
		target := proposalTarget(p.SessionNo, p.LessonTitle, p.UnitNumber, p.UnitTitle)
		created := p.CreatedAt
		out = append(out, approvals.Item{Source: x.Key(), ID: p.ID, CreatedAt: &created,
			Title:    strings.Trim(p.CurriculumTitle+" — "+target, " —"),
			Subtitle: subtitle(deref(p.ProposerName), approvals.Limit(&p.NewContent, 70)),
			URL:      "/syllabus/versions?proposal=" + p.ID.String()})
	}
	return out, nil
}

func (x proposalSource) Pending(ctx context.Context, a *iam.Access, limit int) ([]approvals.Item, error) {
	return x.list(ctx, a, limit)
}

func (x proposalSource) Find(ctx context.Context, a *iam.Access, id uuid.UUID) (*approvals.Item, error) {
	p, err := x.s.q.GetProposal(ctx, id)
	if isNoRows(err) || (err == nil && p.Status != "pending") {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	target := proposalTarget(p.SessionNo, p.LessonTitle, p.UnitNumber, p.UnitTitle)
	created := p.CreatedAt
	return &approvals.Item{Source: x.Key(), ID: p.ID, CreatedAt: &created,
		Title:    strings.Trim(p.CurriculumTitle+" — "+target, " —"),
		Subtitle: subtitle(deref(p.ProposerName), approvals.Limit(&p.NewContent, 70)),
		URL:      "/syllabus/versions?proposal=" + p.ID.String(),
		Meta: approvals.MetaOf("Giáo trình", p.CurriculumTitle, "Vị trí", target, "Loại đề xuất", deref(p.ProposalType),
			"Nội dung đề xuất", approvals.Limit(&p.NewContent, 400), "Lý do", approvals.Limit(p.Reason, 300),
			"Người đề xuất", deref(p.ProposerName))}, nil
}

func (proposalSource) Supports(a *iam.Access, _ string) bool {
	return a.Can("syllabus.approve_adjustment")
}

func (x proposalSource) Approve(ctx context.Context, a *iam.Access, id uuid.UUID) (string, error) {
	return message(x.s.ReviewProposal(ctx, a, id, true, ""))
}

func (x proposalSource) Reject(ctx context.Context, a *iam.Access, id uuid.UUID, reason string) (string, error) {
	return message(x.s.ReviewProposal(ctx, a, id, false, reason))
}

// ───── Điều chỉnh tiến độ ─────

type pacingSource struct{ s *Service }

func (pacingSource) Key() string      { return "syllabus_adjustment" }
func (pacingSource) Label() string    { return "Điều chỉnh tiến độ" }
func (pacingSource) Group() string    { return approvals.GroupAcademic }
func (pacingSource) IndexURL() string { return "/syllabus/adjustment-requests?status=pending" }
func (pacingSource) CanView(a *iam.Access) bool {
	return a.Can("syllabus.view") && a.Can("syllabus.approve_adjustment")
}

func (x pacingSource) items(ctx context.Context) ([]approvals.Item, error) {
	rows, err := x.s.q.InboxPacing(ctx)
	if err != nil {
		return nil, err
	}
	days, _ := x.s.adjustmentDays(ctx)
	out := make([]approvals.Item, 0, len(rows))
	for _, r := range rows {
		label := classStageLabel(&r.ClassName, r.ClassID, r.AssignmentStage)
		created := r.CreatedAt
		extra := ""
		if r.ExtraSessions != nil && *r.ExtraSessions > 0 {
			extra = strconv.Itoa(int(*r.ExtraSessions))
		}
		due := r.CreatedAt.Add(time.Duration(days) * 24 * time.Hour)
		it := approvals.Item{Source: x.Key(), ID: r.ID, CreatedAt: &created, Title: label + ": " + r.RequestType,
			Subtitle: subtitle(deref(r.TeacherName), approvals.Limit(&r.Reason, 70)), URL: pacingLink(r.ID),
			Meta: approvals.MetaOf("Lớp / chặng", label, "Yêu cầu", r.RequestType, "Số buổi cần thêm", extra,
				"Lý do", approvals.Limit(&r.Reason, 400), "Giáo viên", deref(r.TeacherName), "Hạn SLA", fmtDateTime(&due))}
		if x.s.slaOverdue("pending", r.CreatedAt, days) {
			it.Flag = ptr("Quá SLA")
		}
		out = append(out, it)
	}
	return out, nil
}

func (x pacingSource) Count(ctx context.Context, _ *iam.Access) (int64, error) {
	items, err := x.items(ctx)
	return int64(len(items)), err
}

func (x pacingSource) Pending(ctx context.Context, _ *iam.Access, limit int) ([]approvals.Item, error) {
	items, err := x.items(ctx)
	return firstN(items, limit), err
}

func (x pacingSource) Find(ctx context.Context, _ *iam.Access, id uuid.UUID) (*approvals.Item, error) {
	items, err := x.items(ctx)
	if err != nil {
		return nil, err
	}
	return pick(items, id), nil
}

func (pacingSource) Supports(a *iam.Access, _ string) bool {
	return a.Can("syllabus.approve_adjustment")
}

func (x pacingSource) Approve(ctx context.Context, a *iam.Access, id uuid.UUID) (string, error) {
	return message(x.s.ApprovePacing(ctx, a, id, nil))
}

func (x pacingSource) Reject(ctx context.Context, a *iam.Access, id uuid.UUID, reason string) (string, error) {
	return message(x.s.RejectPacing(ctx, a, id, reason))
}

// ───── Order đề Big Test: duyệt cần link đề + lịch / phòng thi → làm ở màn gốc; hộp chỉ từ chối ─────

type orderSource struct{ s *Service }

func (orderSource) Key() string      { return "big_test_order" }
func (orderSource) Label() string    { return "Phân phối đề Big Test" }
func (orderSource) Group() string    { return approvals.GroupAcademic }
func (orderSource) IndexURL() string { return "/syllabus/big-tests/distribution?order_status=pending" }
func (orderSource) CanView(a *iam.Access) bool {
	return a.Can("syllabus.view") && a.Can("big_test.approve")
}

func (x orderSource) items(ctx context.Context, a *iam.Access) ([]approvals.Item, error) {
	rows, err := x.s.q.ListOrders(ctx, syllabusdb.ListOrdersParams{AllRows: true, UserID: &a.UserID, BranchIds: []uuid.UUID{},
		Status: ptr("pending"), Lim: inboxLimit, Off: 0})
	if err != nil {
		return nil, err
	}
	out := make([]approvals.Item, 0, len(rows))
	for _, o := range rows {
		stage := stageOr(o.StagePosition, o.StageTitle, o.StageName)
		typ := orderTypeLabel(o.TestType)
		due := ""
		if o.DueDate != nil {
			due = "hạn " + fmtDate(o.DueDate)
		}
		created := o.CreatedAt
		it := approvals.Item{Source: x.Key(), ID: o.ID, CreatedAt: &created, Title: "Order " + o.Code + " · " + o.ClassName,
			Subtitle: subtitle(strings.TrimSpace(typ+" "+stage), deref(o.TeacherName), due),
			URL:      "/syllabus/big-tests/distribution?order=" + o.ID.String() + "&order_status=pending",
			Meta: approvals.MetaOf("Lớp", o.ClassName, "Loại đề", typ, "Chặng", stage, "Giáo viên", deref(o.TeacherName),
				"Hạn duyệt", fmtDate(o.DueDate))}
		if overdue, _ := x.s.orderFlags(o.Status, o.DueDate); overdue {
			it.Flag = ptr("Quá hạn")
		}
		out = append(out, it)
	}
	// Mới nhất trước như QueryApprovalSource (màn gốc xếp theo hạn).
	sortNewest(out)
	return out, nil
}

func sortNewest(items []approvals.Item) {
	for i := 1; i < len(items); i++ {
		for j := i; j > 0 && items[j].CreatedAt.After(*items[j-1].CreatedAt); j-- {
			items[j], items[j-1] = items[j-1], items[j]
		}
	}
}

func (x orderSource) Count(ctx context.Context, a *iam.Access) (int64, error) {
	pending := "pending"
	return x.s.q.CountOrders(ctx, syllabusdb.CountOrdersParams{AllRows: true, UserID: &a.UserID, BranchIds: []uuid.UUID{}, Status: &pending})
}

func (x orderSource) Pending(ctx context.Context, a *iam.Access, limit int) ([]approvals.Item, error) {
	items, err := x.items(ctx, a)
	return firstN(items, limit), err
}

func (x orderSource) Find(ctx context.Context, a *iam.Access, id uuid.UUID) (*approvals.Item, error) {
	items, err := x.items(ctx, a)
	if err != nil {
		return nil, err
	}
	return pick(items, id), nil
}

func (orderSource) Supports(a *iam.Access, action string) bool {
	return action == approvals.Reject && a.Can("big_test.approve")
}

func (orderSource) Approve(context.Context, *iam.Access, uuid.UUID) (string, error) {
	return "", iam.Invalid("Mục này cần duyệt ở màn gốc (cần nhập thêm thông tin).")
}

func (x orderSource) Reject(ctx context.Context, a *iam.Access, id uuid.UUID, reason string) (string, error) {
	return message(x.s.RejectOrder(ctx, a, id, reason))
}
