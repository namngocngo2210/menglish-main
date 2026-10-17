package reports

import (
	"context"
	"testing"
	"time"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// Nguồn KPI của phân hệ báo cáo: báo cáo ngày nộp sau 09:00 hôm sau, tỉ lệ tháng nộp báo cáo tháng trước 12h mùng 2.
func TestKpiSources(t *testing.T) {
	e := setup(t, Deps{})
	e.svc.SetClock(func() time.Time { return time.Date(2026, 10, 17, 9, 0, 0, 0, vnZone) })
	gv := e.member("Giáo viên", nil, iam.RoleTeacherParttime)
	add := func(typ, date, status string, submitted *string) {
		e.exec("INSERT INTO ops.staff_reports (user_id, type, title, report_date, status, submitted_at) VALUES ($1, $2, 'Báo cáo', $3::date, $4, $5::timestamptz)",
			gv.id, typ, date, status, submitted)
	}
	s := func(v string) *string { return &v }
	add("daily", "2026-09-07", "submitted", s("2026-09-08 08:30+07"))
	add("daily", "2026-09-09", "submitted", s("2026-09-10 10:15+07"))
	add("daily", "2026-09-11", "draft", nil)
	add("monthly", "2026-08-31", "submitted", s("2026-09-02 11:00+07"))
	add("monthly", "2026-09-30", "submitted", s("2026-10-03 08:00+07"))

	ctx := context.Background()
	src := e.svc.KpiSources()
	from, to := time.Date(2026, 9, 1, 0, 0, 0, 0, vnZone), time.Date(2026, 9, 30, 23, 59, 0, 0, vnZone)
	res, err := src["daily_report_late"](ctx, gv.id, from, to)
	if err != nil || len(res.Records) != 1 || res.Records[0].Text != "Báo cáo ngày 09/09 nộp lúc 10:15 10/09" {
		t.Fatalf("báo cáo ngày trễ: %+v %v", res, err)
	}

	// Tháng 8 đúng hạn, tháng 9 trễ, tháng 10 chưa tới hạn → 50%
	res, err = src["monthly_report_on_time"](ctx, gv.id, time.Date(2026, 8, 1, 0, 0, 0, 0, vnZone), time.Date(2026, 10, 31, 23, 59, 0, 0, vnZone))
	if err != nil || res.Value == nil || *res.Value != 50 || len(res.Records) != 2 {
		t.Fatalf("báo cáo tháng: %+v %v", res, err)
	}
	if r := res.Records; !r[0].Uncounted || r[1].Uncounted || r[1].Text != "Báo cáo tháng nộp lúc 08:00 03/10 (trễ hạn)" || r[1].Month != "2026-09" {
		t.Errorf("bản ghi tháng: %+v", r)
	}
}
