package attendance

import (
	"context"
	"net/url"
	"slices"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/approvals"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// ApprovalSource: "Đơn chấm công & nghỉ" của hộp "Việc cần duyệt" (StaffAttendanceRequestApprovalSource): đơn chờ duyệt
// trong phạm vi chi nhánh, không tự duyệt đơn của mình; duyệt / từ chối ngay trong hộp bằng Approve / Reject của phân hệ.
func (s *Service) ApprovalSource() approvals.Source { return requestSource{s} }

type requestSource struct{ s *Service }

func (requestSource) Key() string   { return "staff_attendance_request" }
func (requestSource) Label() string { return "Đơn chấm công & nghỉ" }
func (requestSource) Group() string { return approvals.GroupHR }
func (requestSource) IndexURL() string {
	return "/approvals?group=" + approvals.GroupSlug(approvals.GroupHR)
}
func (requestSource) CanView(a *iam.Access) bool { return a.Can(PermCheckinApprove) }

func (x requestSource) items(ctx context.Context, a *iam.Access) ([]approvals.Item, error) {
	rows, err := x.s.Pending(ctx, a)
	if err != nil {
		return nil, err
	}
	// Mới nhất trước như QueryApprovalSource (màn gốc xếp cũ trước).
	slices.Reverse(rows)
	out := make([]approvals.Item, 0, len(rows))
	for _, r := range rows {
		who := r.UserName
		if r.UserCode != "" {
			who += " (" + r.UserCode + ")"
		}
		search := r.UserCode
		if search == "" {
			search = r.UserName
		}
		created := r.CreatedAt
		item := approvals.Item{Source: requestSource{}.Key(), ID: r.ID, Title: r.UserName + ": " + r.TypeLabel + " " + r.Period,
			URL:       "/staff-attendance?" + url.Values{"date": {r.Date}, "search": {search}}.Encode(),
			CreatedAt: &created,
			Meta: approvals.MetaOf("Nhân sự", who, "Cơ sở", deref(r.Branch), "Loại đơn", r.TypeLabel, "Ngày", r.Period,
				"Chấm công ngày này", deref(r.CurrentPunch), "Lý do", r.Reason)}
		if sub := approvals.Limit(&r.Reason, 90); sub != "" {
			item.Subtitle = &sub
		}
		out = append(out, item)
	}
	return out, nil
}

func (x requestSource) Count(ctx context.Context, a *iam.Access) (int64, error) {
	items, err := x.items(ctx, a)
	return int64(len(items)), err
}

func (x requestSource) Pending(ctx context.Context, a *iam.Access, limit int) ([]approvals.Item, error) {
	items, err := x.items(ctx, a)
	if len(items) > limit {
		items = items[:limit]
	}
	return items, err
}

func (x requestSource) Find(ctx context.Context, a *iam.Access, id uuid.UUID) (*approvals.Item, error) {
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

func (x requestSource) Supports(a *iam.Access, _ string) bool { return x.CanView(a) }

func (x requestSource) Approve(ctx context.Context, a *iam.Access, id uuid.UUID) (string, error) {
	return x.s.Approve(ctx, a, id)
}

func (x requestSource) Reject(ctx context.Context, a *iam.Access, id uuid.UUID, reason string) (string, error) {
	return x.s.Reject(ctx, a, id, reason)
}
