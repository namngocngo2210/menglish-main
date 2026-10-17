package tasks

import (
	"context"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/approvals"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// ApprovalSource: "Xác nhận hoàn thành việc" của hộp "Việc cần duyệt" (WorkTaskApprovalSource): việc Chờ xác nhận,
// Admin không tự duyệt việc của mình. Duyệt / trả về gọi đúng Approve / Reject của màn Hoàn thành công việc.
// Lớp của việc chưa hiện (tasks.Classes chưa có tra tên lớp). TODO(gộp): báo cáo trực lớp chờ xác nhận
// (ClassReportApprovalSource) khi phân hệ Chất lượng lớp có ở v2.
func (s *Service) ApprovalSource() approvals.Source { return taskSource{s} }

type taskSource struct{ s *Service }

func (taskSource) Key() string      { return "work_task" }
func (taskSource) Label() string    { return "Xác nhận hoàn thành việc" }
func (taskSource) Group() string    { return approvals.GroupWork }
func (taskSource) IndexURL() string { return "/tasks/approvals" }
func (taskSource) CanView(a *iam.Access) bool {
	return a.Can("work_task.view") && a.CanAny("work_task.approve", "work_task.create", "work_task.request")
}

func (x taskSource) Count(ctx context.Context, a *iam.Access) (int64, error) {
	if !isAdmin(a) {
		return 0, nil
	}
	return x.s.q.CountAwaitingConfirmation(ctx, a.UserID)
}

func (x taskSource) items(ctx context.Context, a *iam.Access) ([]approvals.Item, error) {
	res, err := x.s.Approvals(ctx, a, "", "")
	if err != nil {
		return nil, err
	}
	out := make([]approvals.Item, 0, len(res.Items))
	for _, it := range res.Items {
		person := ""
		if it.Person != nil {
			person = "Người làm: " + *it.Person
		}
		updated := it.UpdatedAt
		item := approvals.Item{Source: x.Key(), ID: it.ID, Title: it.Title, URL: "/tasks/approvals", CreatedAt: &updated,
			Meta: approvals.MetaOf("Người làm", textOr(it.Person), "Người giao", it.Creator, "Buổi / ca", textOr(it.LessonSession),
				"Hạn", textOr(it.Due), "Báo cáo hoàn thành", approvals.Limit(it.Note, 400))}
		if person != "" {
			item.Subtitle = &person
		}
		out = append(out, item)
	}
	return out, nil
}

func (x taskSource) Pending(ctx context.Context, a *iam.Access, limit int) ([]approvals.Item, error) {
	items, err := x.items(ctx, a)
	if len(items) > limit {
		items = items[:limit]
	}
	return items, err
}

func (x taskSource) Find(ctx context.Context, a *iam.Access, id uuid.UUID) (*approvals.Item, error) {
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

// Supports: quyền theo từng việc do Approve / Reject của màn gốc kiểm.
func (taskSource) Supports(*iam.Access, string) bool { return true }

func (x taskSource) Approve(ctx context.Context, a *iam.Access, id uuid.UUID) (string, error) {
	return x.s.Approve(ctx, a, id, "")
}

func (x taskSource) Reject(ctx context.Context, a *iam.Access, id uuid.UUID, reason string) (string, error) {
	return x.s.Reject(ctx, a, id, reason)
}

func textOr(p *string) string {
	if p == nil {
		return ""
	}
	return *p
}
