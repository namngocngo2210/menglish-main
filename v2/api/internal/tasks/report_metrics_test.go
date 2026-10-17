package tasks

import (
	"context"
	"strings"
	"testing"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/reports"
)

// Dòng gợi ý việc được giao của báo cáo tuần Học thuật (WeeklyReportMetrics::academicSuggestions).
func TestReportMetricsSuggestions(t *testing.T) {
	e := setup(t)
	ctx := context.Background()
	a := e.branch("CN-A")
	lead := e.member("HT001", &a, iam.RoleAcademicLead)
	other := e.member("HT002", &a, iam.RoleAcademicLead)
	task := func(title string, assignee uuid.UUID, status string, due *string, completed *string, care bool) {
		var milestone *int
		if care {
			v := 1
			milestone = &v
		}
		if _, err := e.pg.Exec(ctx, `INSERT INTO ops.work_tasks (title, description, creator_id, assignee_id, status, due_date,
			completed_at, completion_note, care_milestone) VALUES ($1, 'Mô tả '||$1, $2, $2, $3, $4::date, $5::timestamptz,
			CASE WHEN $5::timestamptz IS NULL THEN NULL ELSE 'Đã xong '||$1 END, $6)`,
			title, assignee, status, due, completed, milestone); err != nil {
			t.Fatal(err)
		}
	}
	s := func(v string) *string { return &v }
	task("Soạn đề", lead.id, "completed", s("2026-10-14"), s("2026-10-15 10:00+07"), false)
	task("Rà giáo án", lead.id, "in_progress", s("2026-10-22"), nil, false)
	task("Họp tổ", lead.id, "new", s("2026-10-16"), nil, false)
	task("Xa quá", lead.id, "new", s("2026-10-30"), nil, false)
	task("Chăm sóc", lead.id, "new", s("2026-10-16"), nil, true)
	task("Người khác", other.id, "new", s("2026-10-16"), nil, false)
	task("Xong tuần trước", lead.id, "completed", s("2026-10-05"), s("2026-10-06 10:00+07"), false)

	m := e.tasks.ReportMetrics(reports.Skeleton())
	from := time.Date(2026, 10, 12, 0, 0, 0, 0, vnZone)
	_, rows, err := m.WeeklyAcademic(ctx, reports.Writer{ID: lead.id}, from, from.AddDate(0, 0, 6))
	if err != nil {
		t.Fatal(err)
	}
	text := func(rs []reports.WeeklyRow) string {
		var out []string
		for _, r := range rs {
			d := ""
			if r.Detail != nil {
				d = *r.Detail
			}
			out = append(out, *r.Content+"|"+d+"|"+*r.Deadline+"|"+string(rune('0'+*r.Progress/50)))
		}
		return strings.Join(out, ";")
	}
	if got := text(rows["done"]); got != "Soạn đề|Đã xong Soạn đề|2026-10-14|2" {
		t.Fatalf("đã làm: %s", got)
	}
	if got := text(rows["planned"]); got != "Họp tổ|Mô tả Họp tổ|2026-10-16|0;Rà giáo án|Mô tả Rà giáo án|2026-10-22|1" {
		t.Fatalf("kế hoạch: %s", got)
	}
	if !strings.HasPrefix(*rows["done"][0].Source, "task:") || !strings.HasPrefix(*rows["done"][0].Link, "/tasks/") {
		t.Fatalf("nguồn: %+v", rows["done"][0])
	}
}
