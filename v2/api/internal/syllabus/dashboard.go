package syllabus

import (
	"context"
	"time"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/dashboard"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/syllabus/syllabusdb"
)

// Dashboard: số liệu Giáo trình & Big Test cho Tổng quan (dashboard.Syllabus): hàng chờ chuyên môn của bảng Học thuật,
// số liệu tuần của màn Báo cáo đào tạo, đợt Big Test trên lịch "Việc của bạn".
func (s *Service) Dashboard() dashboard.Syllabus { return dashboardSource{s} }

type dashboardSource struct{ s *Service }

const schedulesPath = "/syllabus/big-tests/schedules"

func startOfDay(t time.Time) time.Time {
	y, m, d := t.In(vnZone).Date()
	return time.Date(y, m, d, 0, 0, 0, 0, vnZone)
}

func (d dashboardSource) Board(ctx context.Context, now time.Time) (dashboard.SyllabusBoard, error) {
	n, err := d.s.q.DashboardCounts(ctx)
	if err != nil {
		return dashboard.SyllabusBoard{}, err
	}
	out := dashboard.SyllabusBoard{PendingProposals: n.Proposals, PendingOrders: n.Orders, PendingResults: n.Results,
		PendingAdjustments: n.Adjustments, Adjustments: []dashboard.PendingAdjustment{}, UpcomingBigTests: []dashboard.UpcomingBigTest{}}
	adj, err := d.s.q.DashboardAdjustments(ctx, 5)
	if err != nil {
		return out, err
	}
	for _, r := range adj {
		out.Adjustments = append(out.Adjustments, dashboard.PendingAdjustment{ID: r.ID, Class: r.ClassName, Teacher: r.TeacherName, Reason: r.Reason})
	}
	tests, err := d.s.q.DashboardBigTests(ctx, syllabusdb.DashboardBigTestsParams{FromAt: startOfDay(now),
		ToAt: startOfDay(now).AddDate(0, 0, 15).Add(-time.Nanosecond), Lim: 8})
	if err != nil {
		return out, err
	}
	for _, t := range tests {
		out.UpcomingBigTests = append(out.UpcomingBigTests, dashboard.UpcomingBigTest{ID: t.ID, Title: t.Title, Class: t.ClassName,
			ScheduledAt: t.ScheduledAt})
	}
	return out, nil
}

func (d dashboardSource) Week(ctx context.Context, now time.Time) (dashboard.SyllabusWeek, error) {
	today := startOfDay(now)
	weekStart := today.AddDate(0, 0, -(int(today.Weekday())+6)%7) // Carbon startOfWeek: thứ Hai
	r, err := d.s.q.DashboardWeek(ctx, syllabusdb.DashboardWeekParams{WeekStart: weekStart, NowAt: now, WeekAhead: now.AddDate(0, 0, 7)})
	return dashboard.SyllabusWeek{AdjustmentsPending: r.AdjustmentsPending, AdjustmentsWeek: r.AdjustmentsWeek,
		BigTestsUpcoming: r.BigTestsUpcoming, BigTestsDistributed: r.BigTestsDistributed}, err
}

func (d dashboardSource) Agenda(ctx context.Context, from, to time.Time) ([]dashboard.AgendaItem, error) {
	tests, err := d.s.q.DashboardBigTests(ctx, syllabusdb.DashboardBigTestsParams{FromAt: from, ToAt: to, Lim: 500})
	if err != nil {
		return nil, err
	}
	href := schedulesPath
	out := make([]dashboard.AgendaItem, 0, len(tests))
	for _, t := range tests {
		title := t.Title
		if t.ClassName != nil && *t.ClassName != "" {
			title = *t.ClassName
		}
		out = append(out, dashboard.AgendaItem{At: t.ScheduledAt.In(vnZone), Kind: "bigtest", KindLabel: "Big Test", Title: title,
			Subtitle: t.Title, Href: &href})
	}
	return out, nil
}
