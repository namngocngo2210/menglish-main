package dashboard_test

import (
	"context"
	"slices"
	"strings"
	"testing"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/dashboard"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

type fakeSyllabus struct{ agendaFrom, agendaTo *time.Time }

func (fakeSyllabus) Board(context.Context, time.Time) (dashboard.SyllabusBoard, error) {
	cls, gv := "Lớp A1", "Cô Lan"
	at := time.Now().Add(48 * time.Hour)
	return dashboard.SyllabusBoard{PendingProposals: 2, PendingOrders: 1, PendingResults: 3, PendingAdjustments: 1,
		Adjustments:      []dashboard.PendingAdjustment{{ID: uuid.New(), Class: &cls, Teacher: &gv, Reason: "Lớp học chậm"}},
		UpcomingBigTests: []dashboard.UpcomingBigTest{{ID: uuid.New(), Title: "Thi chặng 1", Class: &cls, ScheduledAt: &at}}}, nil
}

func (fakeSyllabus) Week(context.Context, time.Time) (dashboard.SyllabusWeek, error) {
	return dashboard.SyllabusWeek{AdjustmentsPending: 1, AdjustmentsWeek: 4, BigTestsUpcoming: 2, BigTestsDistributed: 3}, nil
}

func (f fakeSyllabus) Agenda(_ context.Context, from, to time.Time) ([]dashboard.AgendaItem, error) {
	*f.agendaFrom, *f.agendaTo = from, to
	href := "/syllabus/big-tests/schedules"
	return []dashboard.AgendaItem{{At: from.Add(26 * time.Hour), Kind: "bigtest", KindLabel: "Big Test", Title: "Lớp A1",
		Subtitle: "Thi chặng 1", Href: &href}}, nil
}

// Bảng Học thuật / Báo cáo đào tạo / lịch "Việc của bạn" lấy số liệu Giáo trình & Big Test khi đã nối phân hệ
// (DashboardController::academicDashboard, AcademicDashboardController::reports, MyWorkBoard::agenda).
func TestSyllabusSource(t *testing.T) {
	var from, to time.Time
	e := setup(t, func(d *dashboard.Deps) { d.Syllabus = fakeSyllabus{&from, &to} })
	a := e.Branch("A")
	_, leadC := e.Member("HT001", "Học thuật", []uuid.UUID{a}, iam.RoleAcademicLead)

	d := get[dashboard.Overview](t, leadC, "/dashboard").RoleDashboard
	if d.Type != "academic" || d.AcademicPending != nil {
		t.Fatalf("bảng học thuật: %+v", d)
	}
	if s := statByLabel(t, d, "Đề xuất giáo trình / giãn tiến độ chờ duyệt"); val(s) != "3" || s.Tone != "warning" ||
		*s.Hint != "2 đề xuất sửa giáo trình · 1 giãn tiến độ" || s.Pending != nil {
		t.Errorf("đề xuất chờ duyệt: %+v", s)
	}
	if s := statByLabel(t, d, "Big Test trong 14 ngày tới"); val(s) != "1" {
		t.Errorf("Big Test sắp tới: %+v", s)
	}
	if q := queueByLabel(t, d, "Đợt Big Test có kết quả chờ duyệt"); *q.Value != 3 || *q.Href != "/syllabus/big-tests/results" {
		t.Errorf("kết quả chờ duyệt: %+v", q)
	}
	if q := queueByLabel(t, d, "Đề xuất sửa giáo trình"); *q.Value != 2 || *q.Href != "/syllabus/versions?status=pending" {
		t.Errorf("đề xuất sửa giáo trình: %+v", q)
	}
	if len(d.PendingAdjustments) != 1 || d.PendingAdjustments[0].Reason != "Lớp học chậm" || len(d.UpcomingBigTests) != 1 {
		t.Errorf("danh sách: %+v %+v", d.PendingAdjustments, d.UpcomingBigTests)
	}
	if slices.ContainsFunc(d.Agenda.Pending, func(p string) bool { return strings.HasPrefix(p, "Big Test") }) || from.IsZero() {
		t.Errorf("lịch: %+v", d.Agenda)
	}
	found := false
	for _, day := range d.Agenda.Days {
		for _, it := range day.Items {
			found = found || (it.Kind == "bigtest" && it.Title == "Lớp A1" && it.Subtitle == "Thi chặng 1")
		}
	}
	if !found {
		t.Errorf("Big Test trên lịch: %+v", d.Agenda.Days)
	}

	r := get[dashboard.AcademicReports](t, leadC, "/academic/dashboards/reports?tab=weekly")
	if w := r.WeeklyStats; w.Pending != "" || *w.AdjustmentsPending != 1 || *w.AdjustmentsWeek != 4 || *w.BigTestsUpcoming != 2 ||
		*w.BigTestsDistributed != 3 {
		t.Errorf("số liệu tuần: %+v", w)
	}
}
