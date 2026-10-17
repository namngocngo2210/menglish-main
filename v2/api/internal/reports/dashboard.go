package reports

import (
	"context"
	"encoding/json"
	"slices"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/dashboard"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/reports/reportsdb"
)

// DashboardReports: báo cáo nhân sự cho Tổng quan (băng "Báo cáo ngày", báo cáo tháng giáo viên) và màn Báo cáo & sự vụ
// lớp (dashboard.Reports).
func (s *Service) DashboardReports() dashboard.Reports { return dashboardReports{s} }

type dashboardReports struct{ s *Service }

// dailyDueDays: số ngày gần nhất xét báo cáo ngày còn thiếu (DashboardController::dailyReport).
const dailyDueDays = 14

func dailyPath(day time.Time) string { return WriterPath(ptr(KindDailyOps), ptr(dateKey(day))) }

func (d dashboardReports) DailyDue(ctx context.Context, a *iam.Access, c dashboard.Clock) (*dashboard.DailyDue, error) {
	if !slices.Contains(structuredKinds(a.Roles), KindDailyOps) {
		return nil, nil
	}
	w, err := d.s.writer(ctx, a)
	if err != nil {
		return nil, err
	}
	today := dayOf(c.Today)
	missing, err := d.s.missingDays(ctx, w, today.AddDate(0, 0, -dailyDueDays), today.AddDate(0, 0, -1))
	if err != nil {
		return nil, err
	}
	out := &dashboard.DailyDue{Missing: []dashboard.DayLink{}, Returned: []dashboard.DayLink{}, TodayHref: dailyPath(today)}
	for _, day := range missing {
		out.Missing = append(out.Missing, dashboard.DayLink{Date: day, Href: dailyPath(day)})
	}
	returned, err := d.s.q.DashboardReturnedDaily(ctx, a.UserID)
	if err != nil {
		return nil, err
	}
	for _, r := range returned {
		out.Returned = append(out.Returned, dashboard.DayLink{Date: r.ReportDate, Href: dailyPath(r.ReportDate), Note: r.ReviewNote})
	}
	dates, err := d.s.q.SubmittedDailyDates(ctx, reportsdb.SubmittedDailyDatesParams{UserID: a.UserID, FromDate: today, ToDate: today})
	out.TodaySubmitted = len(dates) > 0
	return out, err
}

func (d dashboardReports) TeacherMonthlyHref(a *iam.Access, month string) *string {
	if !slices.Contains(structuredKinds(a.Roles), KindTeacherMonthly) {
		return nil
	}
	return ptr(WriterPath(ptr(KindTeacherMonthly), &month))
}

func (d dashboardReports) TeacherMonthly(ctx context.Context, ids []uuid.UUID, month string) (map[uuid.UUID]dashboard.TeacherMonthly, error) {
	out := map[uuid.UUID]dashboard.TeacherMonthly{}
	if len(ids) == 0 {
		return out, nil
	}
	rows, err := d.s.q.DashboardTeacherMonthly(ctx, reportsdb.DashboardTeacherMonthlyParams{UserIds: ids, Period: month})
	if err != nil {
		return nil, err
	}
	text := func(row map[string]any, key string) *string {
		if v, ok := row[key].(string); ok && v != "" {
			return &v
		}
		return nil
	}
	for _, r := range rows {
		var data teacherMonthlyData
		_ = json.Unmarshal(r.Data, &data)
		tm := dashboard.TeacherMonthly{UpdatedAt: r.UpdatedAt, Classes: []dashboard.TeacherClassNote{}}
		tm.Progress, tm.Difficulties, tm.Proposals = trimPtr(data.General["progress"]), trimPtr(data.General["difficulties"]), trimPtr(data.General["proposals"])
		// Thứ tự lớp ổn định theo id lớp.
		keys := make([]string, 0, len(data.Classes))
		for k := range data.Classes {
			keys = append(keys, k)
		}
		slices.Sort(keys)
		for _, k := range keys {
			row := data.Classes[k]
			need, _ := row["need_support"].(bool)
			tm.Classes = append(tm.Classes, dashboard.TeacherClassNote{ClassID: k, Situation: text(row, "situation"),
				Attention: text(row, "attention"), Solution: text(row, "solution"), NeedSupport: need, SupportNote: text(row, "support_note")})
		}
		out[r.UserID] = tm
	}
	return out, nil
}

func (d dashboardReports) Submitted(ctx context.Context, group string, limit int) ([]dashboard.StaffReportRow, error) {
	rows, err := d.s.q.DashboardSubmitted(ctx, reportsdb.DashboardSubmittedParams{ReportGroup: group, Lim: int32(limit)})
	out := make([]dashboard.StaffReportRow, 0, len(rows))
	for _, r := range rows {
		name := r.UserName
		row := dashboard.StaffReportRow{ID: r.ID, Kind: r.Kind, Type: r.Type, PeriodKey: r.PeriodKey, Title: r.Title, User: &name,
			KindLabel: kindLabel(r.Type, r.Kind), Content: r.Content, ReportDate: r.ReportDate, UpdatedAt: r.UpdatedAt}
		if r.Type == "daily" {
			data := parseDailyData(r.Data)
			row.Fields, row.HasTasks = data.Fields, len(data.TaskIDs) > 0
		}
		out = append(out, row)
	}
	return out, err
}

func (d dashboardReports) DailyOn(ctx context.Context, day time.Time) (int64, error) {
	return d.s.q.DashboardDailyOn(ctx, day)
}

func (d dashboardReports) Journals(ctx context.Context, f dashboard.JournalFilter, limit int) ([]dashboard.JournalRow, error) {
	p := reportsdb.DashboardJournalsParams{BranchID: f.BranchID, ClassID: f.ClassID, Lim: int32(limit)}
	if f.Severity != "" {
		p.Severity = &f.Severity
	}
	if f.Status != "" {
		p.Status = &f.Status
	}
	rows, err := d.s.q.DashboardJournals(ctx, p)
	out := make([]dashboard.JournalRow, 0, len(rows))
	for _, r := range rows {
		branch := r.ClassBranchName
		if branch == nil {
			branch = r.UserBranchName
		}
		name := r.UserName
		j := dashboard.JournalRow{ID: r.ID, Branch: branch, ClassCode: r.ClassCode, Title: r.Title, Content: r.Content,
			Severity: r.Severity, SeverityLabel: severityLabel(r.Severity), User: &name, Followup: r.Followup, Status: r.Status}
		if r.ClassCode != nil {
			j.ClassID = r.ClassID
		}
		out = append(out, j)
	}
	return out, err
}
