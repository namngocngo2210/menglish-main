package tasks

import (
	"context"
	"slices"
	"testing"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/reports"
)

// Việc follow-up tạo từ báo cáo ngày đi qua phân hệ Công việc (reports.TaskService).
func TestReportTasks(t *testing.T) {
	e := setup(t)
	ctx := context.Background()
	a := e.branch("CN-A")
	manager := e.member("QL001", &a, iam.RoleManager)
	teacher := e.member("GV001", &a, iam.RoleTeacherFulltime)
	rt := e.tasks.ReportTasks()
	acc, err := e.iam.LoadAccess(ctx, manager.id)
	if err != nil {
		t.Fatal(err)
	}

	// Người nhận như màn Giao việc.
	opts, err := rt.Assignees(ctx, acc)
	if err != nil || !rt.Enabled() || !slices.ContainsFunc(opts, func(o reports.Option) bool { return o.Value == teacher.id.String() }) {
		t.Fatalf("người nhận: %v %v", opts, err)
	}

	due := time.Date(2030, 5, 6, 0, 0, 0, 0, vnZone)
	id, err := rt.Create(ctx, acc, reports.FollowUpTask{Title: "Gọi phụ huynh", Description: "Nội dung\n\nTạo từ báo cáo ngày 05/05/2030 của Người QL001.",
		AssigneeID: teacher.id, DueDate: due, BranchID: &a})
	if err != nil {
		t.Fatal(err)
	}
	var kind, status, slot, dueTime string
	if err := e.pg.QueryRow(ctx, `SELECT task_type, status, time_slot_category, to_char(due_time, 'HH24:MI') FROM ops.work_tasks WHERE id = $1`, id).
		Scan(&kind, &status, &slot, &dueTime); err != nil || kind != "one_time" || status != "new" || slot != "during" || dueTime != "18:00" {
		t.Fatalf("việc tạo ra: %s %s %s %s %v", kind, status, slot, dueTime, err)
	}
	// Thông báo "được giao việc" do phân hệ báo cáo gửi, phân hệ Công việc không gửi trùng.
	if got := e.inbox(teacher.id); got != "" {
		t.Fatalf("không gửi trùng thông báo: %q", got)
	}

	sums, err := rt.Summaries(ctx, []uuid.UUID{id, uuid.New()})
	if err != nil || len(sums) != 1 || sums[0].Title != "Gọi phụ huynh" || *sums[0].DueDate != "2030-05-06" ||
		*sums[0].Assignee != teacher.name || sums[0].StatusLabel != statusLabel("new") {
		t.Fatalf("tóm tắt: %+v %v", sums, err)
	}
}
