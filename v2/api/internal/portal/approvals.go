package portal

import (
	"context"
	"slices"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/approvals"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// ApprovalSource: "Học viên báo đã đóng học phí" của hộp "Việc cần duyệt" (PaymentReportApprovalSource). Không có màn
// gốc riêng: người duyệt phiếu thu (tuition.view + tuition.approve, phạm vi chi nhánh Học phí) xác nhận / từ chối ngay
// trong hộp, rồi lập phiếu thu từ link của mục.
func (s *Service) ApprovalSource() approvals.Source { return reportSource{s} }

type reportSource struct{ s *Service }

func (reportSource) Key() string      { return "payment_report" }
func (reportSource) Label() string    { return "Học viên báo đã đóng học phí" }
func (reportSource) Group() string    { return approvals.GroupTuition }
func (reportSource) IndexURL() string { return "/approvals?group=" + approvals.GroupSlug(approvals.GroupTuition) }
func (reportSource) CanView(a *iam.Access) bool {
	return a.Can("tuition.view") && a.Can("tuition.approve")
}

func (x reportSource) Count(ctx context.Context, a *iam.Access) (int64, error) {
	n, err := x.s.PendingReports(ctx, a)
	return int64(n), err
}

func (x reportSource) items(ctx context.Context, a *iam.Access) ([]approvals.Item, error) {
	rows, err := x.s.PaymentReports(ctx, a, "pending")
	if err != nil {
		return nil, err
	}
	slices.Reverse(rows) // mới nhất trước
	out := make([]approvals.Item, 0, len(rows))
	for _, r := range rows {
		// Mở form lập phiếu thu cho khoản học phí của học viên.
		link := "/tuition/receipts/new"
		if r.TuitionID != nil {
			link += "?tuition_id=" + r.TuitionID.String()
		}
		created, amount := r.CreatedAt, r.Amount
		item := approvals.Item{Source: reportSource{}.Key(), ID: r.ID, Title: "Báo đóng học phí · " + r.StudentName, URL: link,
			CreatedAt: &created, Amount: &amount,
			Meta: approvals.MetaOf("Học viên", r.StudentName+" ("+r.StudentCode+")", "Nội dung", approvals.Limit(&r.Content, 300),
				"Gửi lúc", r.CreatedAt.In(vnZone).Format("02/01/2006 15:04"))}
		if sub := approvals.Limit(&r.Content, 60); sub != "" {
			item.Subtitle = &sub
		}
		out = append(out, item)
	}
	return out, nil
}

func (x reportSource) Pending(ctx context.Context, a *iam.Access, limit int) ([]approvals.Item, error) {
	items, err := x.items(ctx, a)
	if len(items) > limit {
		items = items[:limit]
	}
	return items, err
}

func (x reportSource) Find(ctx context.Context, a *iam.Access, id uuid.UUID) (*approvals.Item, error) {
	items, err := x.items(ctx, a)
	if err != nil {
		return nil, err
	}
	for i := range items {
		if items[i].ID == id {
			return &items[i], nil
		}
	}
	return nil, nil
}

func (x reportSource) Supports(a *iam.Access, _ string) bool { return x.CanView(a) }

func (x reportSource) Approve(ctx context.Context, a *iam.Access, id uuid.UUID) (string, error) {
	if err := x.s.ConfirmReport(ctx, a, id); err != nil {
		return "", err
	}
	return "Đã xác nhận, học viên được báo qua hộp thư.", nil
}

func (x reportSource) Reject(ctx context.Context, a *iam.Access, id uuid.UUID, reason string) (string, error) {
	if err := x.s.RejectReport(ctx, a, id, reason); err != nil {
		return "", err
	}
	return "Đã từ chối, học viên được báo qua hộp thư.", nil
}
