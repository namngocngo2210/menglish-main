package tasks

import (
	"context"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/dashboard"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/tasks/tasksdb"
)

// DashboardTasks: công việc cho Tổng quan (dashboard.Tasks) — "Việc của tôi trong tuần" và việc quá hạn / chờ xác nhận
// của bảng điều hành.
func (s *Service) DashboardTasks() dashboard.Tasks { return dashboardTasks{s} }

type dashboardTasks struct{ s *Service }

func (d dashboardTasks) Mine(ctx context.Context, uid uuid.UUID, c dashboard.Clock) (dashboard.MyTasksData, error) {
	var out dashboard.MyTasksData
	rows, err := d.s.q.DashboardMyTasks(ctx, tasksdb.DashboardMyTasksParams{Me: uid, WeekEnd: c.WeekEnd})
	if err != nil {
		return out, err
	}
	for _, t := range rows {
		it := dashboard.TaskItem{ID: t.ID, Title: t.Title, Status: t.Status, StatusLabel: statusLabel(t.Status),
			DueDate: t.DueDate, DueTime: formatClock(t.DueTime), Overdue: t.Status == StatusOverdue}
		if t.DueDate != nil {
			due := t.DueDate.Format("02/01")
			if it.DueTime != nil {
				due += " " + *it.DueTime
			}
			it.Due = &due
			// Ngày của cột date so theo chuỗi Y-m-d (không lệch múi giờ).
			it.Overdue = it.Overdue || t.DueDate.Format("2006-01-02") < c.Today.Format("2006-01-02")
		}
		out.Items = append(out.Items, it)
	}
	n, err := d.s.q.DashboardMyTaskCounts(ctx, tasksdb.DashboardMyTaskCountsParams{Me: uid, WeekEnd: c.WeekEnd, Today: c.Today})
	out.Total, out.DueWeek, out.OverdueWeek = n.Total, n.DueWeek, n.OverdueWeek
	return out, err
}

func (d dashboardTasks) Operations(ctx context.Context, branches []uuid.UUID, c dashboard.Clock) (dashboard.OpsTasks, error) {
	var out dashboard.OpsTasks
	n, err := d.s.q.DashboardOpsTaskCounts(ctx, tasksdb.DashboardOpsTaskCountsParams{Today: c.Today, Branches: branches})
	if err != nil {
		return out, err
	}
	out.Overdue, out.PendingConfirmation = n.Overdue, n.PendingConfirmation
	rows, err := d.s.q.DashboardOverdueTasks(ctx, tasksdb.DashboardOverdueTasksParams{Today: c.Today, Branches: branches})
	out.OverdueList = make([]dashboard.OverdueTask, 0, len(rows))
	for _, t := range rows {
		ot := dashboard.OverdueTask{ID: t.ID, Title: t.Title, Assignee: t.AssigneeName}
		if t.DueDate != nil {
			d := t.DueDate.Format("2006-01-02")
			ot.DueDate = &d
		}
		out.OverdueList = append(out.OverdueList, ot)
	}
	return out, err
}
