package tasks

import (
	"context"
	"slices"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/reports"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/tasks/tasksdb"
)

// Phần việc Công việc làm cho báo cáo tuần Học thuật (WeeklyReportMetrics::academicSuggestions): dòng gợi ý "đã làm" (việc
// được giao hoàn thành trong tuần) và "kế hoạch" (việc còn mở có hạn tới hết tuần sau), sau các dòng mốc dự án. Bọc một
// MetricsProvider khác, chỉ thêm các dòng này.

type ReportMetrics struct {
	reports.MetricsProvider
	svc *Service
}

func (s *Service) ReportMetrics(base reports.MetricsProvider) *ReportMetrics {
	return &ReportMetrics{MetricsProvider: base, svc: s}
}

var _ reports.Partial = (*ReportMetrics)(nil)

// Pending: phân hệ này không thêm phần nào của báo cáo tháng; theo provider gốc.
func (m *ReportMetrics) Pending(part string) bool {
	p, ok := m.MetricsProvider.(reports.Partial)
	return ok && p.Pending(part)
}

// maxSuggestions: số dòng gợi ý tối đa mỗi bảng (WeeklyReportMetrics::MAX_SUGGESTIONS).
const maxSuggestions = 30

func strLimit(s string, n int) string {
	if utf8.RuneCountInString(s) <= n {
		return s
	}
	return strings.TrimRight(string([]rune(s)[:n]), " ") + "..."
}

func suggestion(t tasksdb.ReportWeekTasksRow, detail *string, progress int) reports.WeeklyRow {
	source, content, link := "task:"+t.ID.String(), strLimit(t.Title, 480), taskLink(t.ID)
	r := reports.WeeklyRow{Source: &source, Content: &content, Detail: detail, Progress: &progress, Link: &link}
	if t.DueDate != nil {
		d := t.DueDate.Format(time.DateOnly)
		r.Deadline = &d
	}
	return r
}

func appendRows(m map[string][]reports.WeeklyRow, key string, rows []reports.WeeklyRow) map[string][]reports.WeeklyRow {
	if m == nil {
		m = map[string][]reports.WeeklyRow{}
	}
	if len(rows) == 0 {
		return m
	}
	seen := map[string]bool{}
	out := make([]reports.WeeklyRow, 0, len(m[key])+len(rows))
	for _, r := range append(append([]reports.WeeklyRow{}, m[key]...), rows...) {
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

func (m *ReportMetrics) WeeklyAcademic(ctx context.Context, w reports.Writer, from, to time.Time) (reports.Section, map[string][]reports.WeeklyRow, error) {
	summary, suggestions, err := m.MetricsProvider.WeeklyAcademic(ctx, w, from, to)
	if err != nil {
		return summary, suggestions, err
	}
	fy, fm, fd := from.In(vnZone).Date()
	ty, tm, td := to.In(vnZone).Date()
	fromAt := time.Date(fy, fm, fd, 0, 0, 0, 0, vnZone)
	toAt := time.Date(ty, tm, td, 0, 0, 0, 0, vnZone).AddDate(0, 0, 1).Add(-time.Nanosecond)
	rows, err := m.svc.q.ReportWeekTasks(ctx, tasksdb.ReportWeekTasksParams{UserID: &w.ID, FromAt: fromAt, ToAt: toAt,
		PlannedTo: time.Date(ty, tm, td+7, 0, 0, 0, 0, time.UTC)})
	if err != nil {
		return summary, nil, err
	}
	var done, planned []tasksdb.ReportWeekTasksRow
	for _, t := range rows {
		if t.Status == "completed" {
			done = append(done, t)
		} else {
			planned = append(planned, t)
		}
	}
	slices.SortStableFunc(planned, func(a, b tasksdb.ReportWeekTasksRow) int { return a.DueDate.Compare(*b.DueDate) })
	doneRows := make([]reports.WeeklyRow, 0, len(done))
	for _, t := range done {
		doneRows = append(doneRows, suggestion(t, t.CompletionNote, 100))
	}
	plannedRows := make([]reports.WeeklyRow, 0, len(planned))
	for _, t := range planned {
		var detail *string
		if t.Description != nil && *t.Description != "" {
			d := strLimit(*t.Description, 300)
			detail = &d
		}
		progress := 0
		if t.Status == "in_progress" {
			progress = 50
		}
		plannedRows = append(plannedRows, suggestion(t, detail, progress))
	}
	suggestions = appendRows(suggestions, "done", doneRows)
	return summary, appendRows(suggestions, "planned", plannedRows), nil
}
