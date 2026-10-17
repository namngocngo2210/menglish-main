package projects

import (
	"context"
	"fmt"
	"math"
	"slices"
	"strings"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/projects/projectsdb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/reports"
)

// Phần việc Dự án học thuật làm cho báo cáo (DailyReportMetrics::academic, WeeklyReportMetrics::academicSummary /
// academicSuggestions): mục "Dự án học thuật" của báo cáo ngày Học thuật, 3 mục mốc của báo cáo tuần và các dòng gợi ý
// "đã làm / kế hoạch" từ mốc. Bọc một MetricsProvider khác, chỉ điền các mục này. Mốc tính theo dự án người viết được
// xem (AcademicProject::visibleTo).

// AccessLoader nạp quyền của người viết báo cáo (iam.Service.LoadAccess).
type AccessLoader func(ctx context.Context, userID uuid.UUID) (*iam.Access, error)

type ReportMetrics struct {
	reports.MetricsProvider
	svc    *Service
	access AccessLoader
}

func (s *Service) ReportMetrics(base reports.MetricsProvider, access AccessLoader) *ReportMetrics {
	return &ReportMetrics{MetricsProvider: base, svc: s, access: access}
}

var _ reports.Partial = (*ReportMetrics)(nil)

// Pending: phân hệ này không thêm phần nào của báo cáo tháng; theo provider gốc.
func (m *ReportMetrics) Pending(part string) bool {
	p, ok := m.MetricsProvider.(reports.Partial)
	return ok && p.Pending(part)
}

// maxSuggestions: số dòng gợi ý tối đa mỗi bảng (WeeklyReportMetrics::MAX_SUGGESTIONS).
const maxSuggestions = 30

func endOfDay(day time.Time) time.Time {
	d := day.In(vnZone)
	return time.Date(d.Year(), d.Month(), d.Day(), 0, 0, 0, 0, vnZone).AddDate(0, 0, 1).Add(-time.Nanosecond)
}

// milestones: mốc của dự án người viết thấy — hạn trong [from, dueTo], hoặc chưa xong hạn ≤ dueTo, hoặc hoàn thành
// trong [from, to] (ngày giờ Việt Nam).
func (m *ReportMetrics) milestones(ctx context.Context, w reports.Writer, from, dueTo, to time.Time) ([]projectsdb.VisibleMilestonesRow, error) {
	a, err := m.access(ctx, w.ID)
	if err != nil {
		return nil, err
	}
	all := a != nil && seeAll(a)
	start := from.In(vnZone)
	return m.svc.q.VisibleMilestones(ctx, projectsdb.VisibleMilestonesParams{
		SeeAll: all, UserID: w.ID, FromDate: dateOf(from), ToDate: dateOf(dueTo),
		FromAt: time.Date(start.Year(), start.Month(), start.Day(), 0, 0, 0, 0, vnZone), ToAt: endOfDay(to),
	})
}

func setItem(sections []reports.Section, key string, n int, details []reports.Detail) {
	for si := range sections {
		for ii := range sections[si].Items {
			if it := &sections[si].Items[ii]; it.Key == key {
				v := fmt.Sprint(n)
				it.Value, it.Count, it.Details, it.Unavailable = &v, &n, nonNil(details), ""
			}
		}
	}
}

func sameDay(t *time.Time, day time.Time) bool {
	return t != nil && dateOf(*t).Equal(dateOf(day))
}

func (m *ReportMetrics) Daily(ctx context.Context, w reports.Writer, date time.Time, audience string) ([]reports.Section, map[string]any, error) {
	sections, fields, err := m.MetricsProvider.Daily(ctx, w, date, audience)
	if err != nil || audience != reports.AudienceAcademic {
		return sections, fields, err
	}
	rows, err := m.milestones(ctx, w, date, date, date)
	if err != nil {
		return nil, nil, err
	}
	day := dateOf(date)
	var due, overdue, done []reports.Detail
	for _, r := range rows {
		t := r.ProjectName + " · " + r.Title
		if r.AssigneeName != nil {
			t += " · " + *r.AssigneeName
		}
		t += " · hạn " + r.DueDate.Format("02/01")
		d := reports.Detail{Text: t, URL: projectLink(r.ProjectID)}
		if r.Status != "done" && r.DueDate.Equal(day) {
			due = append(due, d)
		}
		if r.Status != "done" && r.DueDate.Before(day) {
			d.Tone = "error"
			overdue = append(overdue, d)
		}
		if sameDay(r.CompletedAt, date) {
			d.Tone = "success"
			done = append(done, d)
		}
	}
	setItem(sections, "milestones_due", len(due), due)
	setItem(sections, "milestones_overdue", len(overdue), overdue)
	setItem(sections, "milestones_done", len(done), done)
	return sections, fields, nil
}

// progressOf: tiến độ mốc làm tròn xuống 0 / 25 / 50 / 75 / 100 (WeeklyReportMetrics::progressOf).
func progressOf(r projectsdb.VisibleMilestonesRow) int {
	if r.Status == "done" {
		return 100
	}
	if r.TargetQuantity == 0 {
		if r.Status == "in_progress" {
			return 50
		}
		return 0
	}
	return int(math.Min(75, math.Floor(r.DoneQuantity/r.TargetQuantity*4)*25))
}

func (m *ReportMetrics) WeeklyAcademic(ctx context.Context, w reports.Writer, from, to time.Time) (reports.Section, map[string][]reports.WeeklyRow, error) {
	summary, suggestions, err := m.MetricsProvider.WeeklyAcademic(ctx, w, from, to)
	if err != nil {
		return summary, suggestions, err
	}
	nextEnd := to.AddDate(0, 0, 7)
	rows, err := m.milestones(ctx, w, from, nextEnd, to)
	if err != nil {
		return summary, nil, err
	}
	// Mốc "đang mở" so với mốc thời gian: tuần đã qua thì cuối tuần, tuần hiện tại thì hôm nay.
	ref := dateOf(m.svc.now())
	if endOfDay(to).Before(m.svc.now()) {
		ref = dateOf(to)
	}
	fromDay, toDay, nextDay := dateOf(from), dateOf(to), dateOf(nextEnd)
	fromAt, toAt := time.Date(fromDay.Year(), fromDay.Month(), fromDay.Day(), 0, 0, 0, 0, vnZone), endOfDay(to)
	row := func(r projectsdb.VisibleMilestonesRow) reports.Detail {
		return reports.Detail{Text: r.ProjectName + " · " + r.Title + " · hạn " + r.DueDate.Format("02/01"), URL: projectLink(r.ProjectID)}
	}
	var due, overdue []reports.Detail
	var done []projectsdb.VisibleMilestonesRow
	var planned []reports.WeeklyRow
	for _, r := range rows {
		if !r.DueDate.Before(fromDay) && !r.DueDate.After(toDay) {
			d := row(r)
			d.Tag = labelOf(MilestoneStatuses, r.Status)
			if r.Status == "done" {
				d.Tone = "success"
			}
			due = append(due, d)
		}
		if r.Status != "done" && r.DueDate.Before(ref) {
			d := row(r)
			d.Tone = "error"
			overdue = append(overdue, d)
		}
		if r.CompletedAt != nil && !r.CompletedAt.Before(fromAt) && !r.CompletedAt.After(toAt) {
			done = append(done, r)
		}
		if r.Status != "done" && !r.DueDate.After(nextDay) {
			planned = append(planned, suggestion(r, progressOf(r), ref))
		}
	}
	slices.SortStableFunc(done, func(a, b projectsdb.VisibleMilestonesRow) int { return a.CompletedAt.Compare(*b.CompletedAt) })
	doneDetails := make([]reports.Detail, 0, len(done))
	doneRows := make([]reports.WeeklyRow, 0, len(done))
	for _, r := range done {
		d := row(r)
		d.Tone = "success"
		doneDetails = append(doneDetails, d)
		doneRows = append(doneRows, suggestion(r, 100, ref))
	}
	sections := []reports.Section{summary}
	setItem(sections, "milestones_due", len(due), due)
	setItem(sections, "milestones_done", len(done), doneDetails)
	setItem(sections, "milestones_overdue", len(overdue), overdue)
	// Mốc đứng đầu các dòng gợi ý như Laravel.
	suggestions = prependRows(suggestions, "done", doneRows)
	suggestions = prependRows(suggestions, "planned", planned)
	return sections[0], suggestions, nil
}

// suggestion: dòng gợi ý của một mốc (WeeklyReportMetrics::milestoneRow).
func suggestion(r projectsdb.VisibleMilestonesRow, progress int, ref time.Time) reports.WeeklyRow {
	var parts []string
	if r.TargetQuantity != 0 {
		parts = append(parts, "Đã làm "+FormatQuantity(r.DoneQuantity)+"/"+FormatQuantity(r.TargetQuantity)+" "+r.Unit)
	}
	if r.AssigneeName != nil {
		parts = append(parts, "Phụ trách: "+*r.AssigneeName)
	}
	if r.Status != "done" && r.DueDate.Before(ref) {
		parts = append(parts, "Quá hạn")
	}
	source, content := "milestone:"+r.ID.String(), limit(r.ProjectName+": "+r.Title, 480)
	deadline, link := r.DueDate.Format("2006-01-02"), projectLink(r.ProjectID)
	out := reports.WeeklyRow{Source: &source, Content: &content, Deadline: &deadline, Progress: &progress, Link: &link}
	if len(parts) > 0 {
		d := strings.Join(parts, " · ")
		out.Detail = &d
	}
	return out
}

// prependRows: đặt rows lên đầu bảng key, bỏ dòng trùng source, tối đa maxSuggestions dòng.
func prependRows(m map[string][]reports.WeeklyRow, key string, rows []reports.WeeklyRow) map[string][]reports.WeeklyRow {
	if m == nil {
		m = map[string][]reports.WeeklyRow{}
	}
	if len(rows) == 0 {
		return m
	}
	seen := map[string]bool{}
	out := make([]reports.WeeklyRow, 0, len(rows)+len(m[key]))
	for _, r := range append(append([]reports.WeeklyRow{}, rows...), m[key]...) {
		if r.Source != nil {
			if seen[*r.Source] {
				continue
			}
			seen[*r.Source] = true
		}
		if len(out) == maxSuggestions {
			break
		}
		out = append(out, r)
	}
	m[key] = out
	return m
}
