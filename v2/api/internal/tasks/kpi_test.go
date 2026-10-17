package tasks

import (
	"context"
	"testing"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// Nguồn KPI task_on_time (hệ số trễ theo giờ) và số việc của bảng KPI tự động.
func TestKpiTaskSources(t *testing.T) {
	e := setup(t)
	a := e.branch("CN-A")
	admin := e.member("AD001", &a, iam.RoleAdmin)
	ac := e.as(admin)
	e.tasks.now = func() time.Time { return time.Date(2026, 10, 17, 9, 0, 0, 0, vnZone) }
	set := func(id, sql string, args ...any) {
		if _, err := e.pg.Exec(context.Background(), "UPDATE ops.work_tasks SET "+sql+" WHERE id = '"+id+"'", args...); err != nil {
			t.Fatal(err)
		}
	}
	onTime := ac.create(newTask("Việc đúng hạn", admin.id))
	set(onTime, "due_date = '2026-10-05', due_time = '17:00', status = 'completed', completed_at = '2026-10-05 16:00+07'")
	late := ac.create(newTask("Việc trễ", admin.id))
	set(late, "due_date = '2026-10-06', due_time = NULL, status = 'completed', completed_at = '2026-10-07 01:29+07'")
	open := ac.create(newTask("Việc chưa xong", admin.id))
	set(open, "due_date = '2026-10-10', due_time = '08:00', status = 'in_progress'")
	future := ac.create(newTask("Việc chưa tới hạn", admin.id))
	set(future, "due_date = '2026-10-20', status = 'new'")

	ctx := context.Background()
	from, to := time.Date(2026, 10, 1, 0, 0, 0, 0, vnZone), time.Date(2026, 10, 31, 23, 59, 0, 0, vnZone)
	res, err := e.tasks.KpiSources()["task_on_time"](ctx, admin.id, from, to)
	if err != nil || len(res.Records) != 3 {
		t.Fatalf("việc có hạn: %+v %v", res, err)
	}
	want := []struct {
		text   string
		factor float64
	}{
		{`"Việc đúng hạn": hạn 17:00 05/10, xong đúng hạn`, 1},
		{`"Việc trễ": hạn 23:59 06/10, xong trễ 1,5 giờ`, 0.5},
		{`"Việc chưa xong": hạn 08:00 10/10, chưa xong, trễ 169 giờ`, 0},
	}
	for i, w := range want {
		if r := res.Records[i]; r.Text != w.text || r.Factor == nil || *r.Factor != w.factor {
			t.Errorf("bản ghi %d: %q %v, muốn %q %v", i, r.Text, r.Factor, w.text, w.factor)
		}
	}

	st, err := e.tasks.KpiBoard().KpiBoardTasks(ctx, []uuid.UUID{admin.id}, from, to)
	if err != nil || st[admin.id].Total != 4 || st[admin.id].Done != 2 {
		t.Fatalf("bảng KPI: %+v %v", st, err)
	}
}
