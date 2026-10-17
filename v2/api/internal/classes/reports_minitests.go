package classes

import (
	"context"
	"fmt"
	"strconv"
	"time"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/classes/classesdb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/reports"
)

// Dòng tham khảo "Buổi chưa điểm danh" của mục KPI 5.1 (WeeklyReportMetrics::kpiHints) và mục "Kết quả mini test" của báo
// cáo ngày / tuần Học thuật (DailyReportMetrics::academic, WeeklyReportMetrics::
// academicSummary): bài mini test có ngày test trong kỳ của các lớp tính vào báo cáo; chi tiết là bài dưới 7 (thang 10).

func scoreText(v float64) string { return strconv.FormatFloat(v, 'f', -1, 64) }

func (m *ReportMetrics) fillMiniTests(ctx context.Context, sections []reports.Section, w reports.Writer, from, to time.Time) error {
	ids, err := m.classIDs(ctx, w)
	if err != nil {
		return err
	}
	var rows []classesdb.ReportMiniTestsRow
	if len(ids) > 0 {
		if rows, err = m.cls.q.ReportMiniTests(ctx, classesdb.ReportMiniTestsParams{ClassIds: ids, FromDate: dateOf(from), ToDate: dateOf(to)}); err != nil {
			return err
		}
	}
	low := []reports.Detail{}
	for _, r := range rows {
		maxScore := r.MaxScore
		if maxScore == 0 {
			maxScore = 10
		}
		if !r.HasScore || r.Score/max(maxScore, 1)*10 >= PassScore {
			continue
		}
		low = append(low, reports.Detail{Text: r.StudentName + " · " + r.ClassName + " · " + r.Name + " · " + scoreText(r.Score) + "/" +
			scoreText(maxScore), Tone: "warning"})
	}
	value, n := "0", len(rows)
	if n > 0 {
		value = fmt.Sprintf("%d bài · %d dưới 7", n, len(low))
	}
	for si := range sections {
		fill(&sections[si], "mini_tests", &value, &n, low)
	}
	return nil
}

// WeeklyAcademic: mục "Kết quả mini test" của tuần.
func (m *ReportMetrics) WeeklyAcademic(ctx context.Context, w reports.Writer, from, to time.Time) (reports.Section, map[string][]reports.WeeklyRow, error) {
	summary, suggestions, err := m.MetricsProvider.WeeklyAcademic(ctx, w, from, to)
	if err != nil {
		return summary, suggestions, err
	}
	sections := []reports.Section{summary}
	if err := m.fillMiniTests(ctx, sections, w, from, to); err != nil {
		return summary, nil, err
	}
	return sections[0], suggestions, nil
}

// KpiHints: buổi chưa điểm danh của tuần (tính đến hôm qua) — dòng tham khảo của mục KPI mã 5.1.
func (m *ReportMetrics) KpiHints(ctx context.Context, c reports.KpiCriterion, w reports.Writer, from, to time.Time) ([]reports.Detail, error) {
	out, err := m.MetricsProvider.KpiHints(ctx, c, w, from, to)
	if err != nil || c.Code != "5.1" {
		return out, err
	}
	ids, err := m.classIDs(ctx, w)
	if err != nil || len(ids) == 0 {
		return out, err
	}
	before := dateOf(to).AddDate(0, 0, 1)
	if today := dateOf(m.cls.now()); today.Before(before) {
		before = today
	}
	rows, err := m.cls.q.ReportUnmarkedSessions(ctx, classesdb.ReportUnmarkedSessionsParams{ClassIds: ids, FromDate: dateOf(from), BeforeDate: before})
	if err != nil {
		return nil, err
	}
	for _, r := range rows {
		out = append(out, reports.Detail{Text: "Buổi chưa điểm danh · " + r.ClassName + " · " + r.Date.Format("02/01"), Tag: "Tham khảo",
			URL: classLink(r.ClassID)})
	}
	return out, nil
}
