package tasks

import (
	"context"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/reports"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/tasks/tasksdb"
)

// ReportTasks: việc follow-up tạo từ báo cáo ngày (reports.TaskService), truyền vào reports.Deps ở cmd/menglish/main.go.
// Như Laravel StaffReportController::createFollowUpTask: việc một lần, hạn 18:00, ca "trong ca", trạng thái Mới. Thông báo
// "Bạn được giao việc" do phân hệ báo cáo gửi (nội dung riêng "đã giao việc từ báo cáo ngày …") nên ở đây không gửi.
func (s *Service) ReportTasks() reports.TaskService { return reportTasks{s} }

type reportTasks struct{ s *Service }

func (reportTasks) Enabled() bool { return true }

// Assignees: người nhận việc như màn Giao việc (assignable).
func (r reportTasks) Assignees(ctx context.Context, a *iam.Access) ([]reports.Option, error) {
	users, err := r.s.assignable(ctx, a)
	out := make([]reports.Option, 0, len(users))
	for _, u := range users {
		out = append(out, reports.Option{Value: u.ID.String(), Label: userLabel(u.FullName, u.RoleLabels)})
	}
	return out, err
}

func (r reportTasks) Create(ctx context.Context, a *iam.Access, t reports.FollowUpTask) (uuid.UUID, error) {
	due := t.DueDate
	clock, _ := parseClock("18:00")
	var desc *string
	if t.Description != "" {
		desc = &t.Description
	}
	row, err := r.s.q.InsertTask(ctx, tasksdb.InsertTaskParams{
		Title: t.Title, Description: desc, CreatorID: a.UserID, AssigneeID: &t.AssigneeID, BranchID: t.BranchID,
		TimeSlotCategory: "during", TaskType: "one_time", DueDate: &due, DueTime: clock,
	})
	if err != nil {
		return uuid.Nil, err
	}
	r.s.log.Info("work task created from daily report", "task_id", row.ID, "assignee", t.AssigneeID, "by", a.UserID)
	return row.ID, nil
}

func (r reportTasks) Summaries(ctx context.Context, ids []uuid.UUID) ([]reports.TaskSummary, error) {
	if len(ids) == 0 {
		return []reports.TaskSummary{}, nil
	}
	rows, err := r.s.q.TaskSummaries(ctx, ids)
	out := make([]reports.TaskSummary, 0, len(rows))
	for _, t := range rows {
		out = append(out, reports.TaskSummary{ID: t.ID, Title: t.Title, DueDate: formatDate(t.DueDate), Assignee: t.AssigneeName,
			Status: t.Status, StatusLabel: statusLabel(t.Status)})
	}
	return out, err
}
