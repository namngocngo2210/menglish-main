package inventory

import (
	"context"
	"fmt"
	"time"
	"unicode/utf8"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/inventory/inventorydb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/reports"
)

// Phần việc Order học liệu làm cho báo cáo (DailyReportMetrics::facilityDetails / academic, WeeklyReportMetrics::
// academicSummary / academicSuggestions): order đạo cụ / in ấn trong mục "Cơ sở vật chất" của báo cáo ngày Học vụ, mục
// "Order học liệu" của báo cáo ngày / tuần Học thuật và dòng gợi ý order học liệu của báo cáo tuần. Order tính theo phạm
// vi xem của người viết (MaterialOrder::visibleTo). Bọc một MetricsProvider khác, chỉ điền các mục này.

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

func orderLink(id uuid.UUID) string { return "/material-orders/" + id.String() }

func dayBounds(day time.Time) (time.Time, time.Time, time.Time) {
	y, mo, d := day.In(vnZone).Date()
	start := time.Date(y, mo, d, 0, 0, 0, 0, vnZone)
	return time.Date(y, mo, d, 0, 0, 0, 0, time.UTC), start, start.AddDate(0, 0, 1).Add(-time.Nanosecond)
}

func statusTone(status string) string {
	switch status {
	case OrderOverdue:
		return "error"
	case OrderDone:
		return "success"
	}
	return ""
}

func (m *ReportMetrics) scope(ctx context.Context, w reports.Writer) (*iam.Access, bool, []uuid.UUID, error) {
	a, err := m.access(ctx, w.ID)
	if err != nil {
		return nil, false, nil, err
	}
	all, branches := orderScope(a)
	return a, all, branches, nil
}

func (m *ReportMetrics) dayOrders(ctx context.Context, w reports.Writer, date time.Time, byUse bool, categories ...string) ([]inventorydb.ReportDayOrdersRow, error) {
	a, all, branches, err := m.scope(ctx, w)
	if err != nil {
		return nil, err
	}
	day, start, end := dayBounds(date)
	return m.svc.q.ReportDayOrders(ctx, inventorydb.ReportDayOrdersParams{AllScope: all, UserID: a.UserID, BranchIds: branches,
		Categories: categories, ByUse: byUse, DayEnd: end, DayStart: start, Day: day})
}

func classSuffix(name *string) string {
	if name == nil || *name == "" {
		return ""
	}
	return " · " + *name
}

// fill: thay số / chi tiết của mục key (count nil: mục chỉ có chi tiết).
func fill(sections []reports.Section, key string, count *int, details []reports.Detail) {
	for si := range sections {
		for ii := range sections[si].Items {
			if it := &sections[si].Items[ii]; it.Key == key {
				if count != nil {
					v := fmt.Sprint(*count)
					it.Value, it.Count = &v, count
				}
				if it.Unavailable != "" || it.Details == nil {
					it.Details = []reports.Detail{}
				}
				it.Details = append(it.Details, details...)
				it.Unavailable = ""
			}
		}
	}
}

func (m *ReportMetrics) Daily(ctx context.Context, w reports.Writer, date time.Time, audience string) ([]reports.Section, map[string]any, error) {
	sections, fields, err := m.MetricsProvider.Daily(ctx, w, date, audience)
	if err != nil {
		return sections, fields, err
	}
	if audience == reports.AudienceAcademic {
		rows, err := m.dayOrders(ctx, w, date, false, "academic")
		if err != nil {
			return nil, nil, err
		}
		details := make([]reports.Detail, 0, len(rows))
		for _, o := range rows {
			details = append(details, reports.Detail{Text: o.Code + " · " + o.Title + classSuffix(o.ClassName),
				Tag: labelOf(OrderStatuses, o.Status), Tone: statusTone(o.Status), URL: orderLink(o.ID)})
		}
		n := len(rows)
		fill(sections, "academic_orders", &n, details)
		return sections, fields, nil
	}
	// "Cơ sở vật chất": order đạo cụ / in ấn dùng hôm nay hoặc đang quá hạn, sau các ticket CSVC.
	rows, err := m.dayOrders(ctx, w, date, true, "props", "printing")
	if err != nil {
		return nil, nil, err
	}
	details := make([]reports.Detail, 0, len(rows))
	for _, o := range rows {
		details = append(details, reports.Detail{Text: o.Code + " · " + o.Title + classSuffix(o.ClassName),
			Tag: labelOf(OrderCategories, o.Category) + " · " + labelOf(OrderStatuses, o.Status), Tone: statusTone(o.Status), URL: orderLink(o.ID)})
	}
	fill(sections, "facilities", nil, details)
	return sections, fields, nil
}

// maxSuggestions: số dòng gợi ý tối đa mỗi bảng (WeeklyReportMetrics::MAX_SUGGESTIONS).
const maxSuggestions = 30

func limitText(s string, n int) string {
	if utf8.RuneCountInString(s) <= n {
		return s
	}
	return string([]rune(s)[:n]) + "..."
}

func weeklyRow(source, content string, detail *string, deadline time.Time, progress int, link string) reports.WeeklyRow {
	c, d := limitText(content, 480), deadline.In(vnZone).Format(time.DateOnly)
	return reports.WeeklyRow{Source: &source, Content: &c, Detail: detail, Deadline: &d, Progress: &progress, Link: &link}
}

// appendRows: thêm dòng vào cuối bảng key, bỏ dòng trùng source, tối đa maxSuggestions dòng.
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

// WeeklyAcademic: mục "Order học liệu" (tạo trong tuần) và dòng gợi ý order hoàn thành trong tuần / đang mở.
func (m *ReportMetrics) WeeklyAcademic(ctx context.Context, w reports.Writer, from, to time.Time) (reports.Section, map[string][]reports.WeeklyRow, error) {
	summary, suggestions, err := m.MetricsProvider.WeeklyAcademic(ctx, w, from, to)
	if err != nil {
		return summary, suggestions, err
	}
	a, all, branches, err := m.scope(ctx, w)
	if err != nil {
		return summary, nil, err
	}
	_, fromAt, _ := dayBounds(from)
	_, _, toAt := dayBounds(to)
	rows, err := m.svc.q.ReportWeekOrders(ctx, inventorydb.ReportWeekOrdersParams{AllScope: all, UserID: a.UserID, BranchIds: branches,
		FromAt: fromAt, ToAt: toAt})
	if err != nil {
		return summary, nil, err
	}
	created := []reports.Detail{}
	var done, requests []reports.WeeklyRow
	for _, o := range rows {
		if !o.CreatedAt.Before(fromAt) && !o.CreatedAt.After(toAt) {
			tone := ""
			if o.Status == OrderDone {
				tone = "success"
			}
			created = append(created, reports.Detail{Text: o.Code + " · " + o.Title, Tag: labelOf(OrderStatuses, o.Status), Tone: tone,
				URL: orderLink(o.ID)})
		}
		content := "Order học liệu " + o.Code + ": " + o.Title
		if o.Status == OrderDone && o.ProcessedAt != nil && !o.ProcessedAt.Before(fromAt) && !o.ProcessedAt.After(toAt) {
			done = append(done, weeklyRow("order:"+o.ID.String(), content, nil, o.DueAt, 100, orderLink(o.ID)))
		}
		if isOpen(o.Status) {
			label := labelOf(OrderStatuses, o.Status)
			requests = append(requests, weeklyRow("order:"+o.ID.String(), content, &label, o.DueAt, 0, orderLink(o.ID)))
		}
	}
	sections := []reports.Section{summary}
	n := len(created)
	fill(sections, "academic_orders", &n, created)
	suggestions = appendRows(suggestions, "done", done)
	return sections[0], appendRows(suggestions, "requests", requests), nil
}
