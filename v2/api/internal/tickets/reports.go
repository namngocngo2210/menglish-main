package tickets

import (
	"context"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/reports"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/tickets/ticketsdb"
)

// Phần việc Ticket hỗ trợ làm cho báo cáo: ticket "Cơ sở vật chất" trong mục "Cơ sở vật chất" của báo cáo ngày Học vụ
// (DailyReportMetrics::facilityDetails) và dòng tham khảo của mục KPI material_stock ở báo cáo tuần Học vụ
// (WeeklyReportMetrics::kpiHints). Ticket tính theo chi nhánh của người tạo (lọc theo chi nhánh của người viết).

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

func dayStart(t time.Time) time.Time {
	y, mo, d := t.In(vnZone).Date()
	return time.Date(y, mo, d, 0, 0, 0, 0, vnZone)
}

func branchesOf(w reports.Writer) []uuid.UUID {
	if w.BranchIDs == nil {
		return []uuid.UUID{}
	}
	return w.BranchIDs
}

func (m *ReportMetrics) Daily(ctx context.Context, w reports.Writer, date time.Time, audience string) ([]reports.Section, map[string]any, error) {
	sections, fields, err := m.MetricsProvider.Daily(ctx, w, date, audience)
	if err != nil || audience == reports.AudienceAcademic {
		return sections, fields, err
	}
	start := dayStart(date)
	rows, err := m.svc.q.ReportDayFacilityTickets(ctx, ticketsdb.ReportDayFacilityTicketsParams{DayStart: start,
		DayEnd: start.AddDate(0, 0, 1).Add(-time.Nanosecond), OpenOnly: start.Equal(dayStart(m.svc.now())), BranchIds: branchesOf(w)})
	if err != nil {
		return nil, nil, err
	}
	details := make([]reports.Detail, 0, len(rows))
	for _, t := range rows {
		text := t.Code + " · " + t.Title
		if dayStart(t.CreatedAt).Equal(start) {
			text += " (mới)"
		}
		tone := "warning"
		if t.Status == "resolved" || t.Status == "closed" {
			tone = "success"
		}
		details = append(details, reports.Detail{Text: text, Tag: "Ticket", Tone: tone, URL: "/tickets/" + t.ID.String()})
	}
	for si := range sections {
		for ii := range sections[si].Items {
			if it := &sections[si].Items[ii]; it.Key == "facilities" {
				// Ticket đứng trước order đạo cụ / in ấn (phân hệ Order bọc ngoài nối thêm).
				it.Details, it.Unavailable = append(details, nonEmpty(it)...), ""
			}
		}
	}
	return sections, fields, nil
}

// nonEmpty: chi tiết sẵn có của mục khi mục đã có nguồn (mục còn "chờ phân hệ" thì bỏ).
func nonEmpty(it *reports.Item) []reports.Detail {
	if it.Unavailable != "" {
		return nil
	}
	return it.Details
}

// KpiHints: ticket CSVC tạo trong tuần — dòng tham khảo của mục KPI material_stock.
func (m *ReportMetrics) KpiHints(ctx context.Context, c reports.KpiCriterion, w reports.Writer, from, to time.Time) ([]reports.Detail, error) {
	out, err := m.MetricsProvider.KpiHints(ctx, c, w, from, to)
	if err != nil || c.AutoSource != "material_stock" {
		return out, err
	}
	rows, err := m.svc.q.ReportWeekFacilityTickets(ctx, ticketsdb.ReportWeekFacilityTicketsParams{FromAt: dayStart(from),
		ToAt: dayStart(to).AddDate(0, 0, 1).Add(-time.Nanosecond), BranchIds: branchesOf(w)})
	if err != nil {
		return nil, err
	}
	for _, t := range rows {
		out = append(out, reports.Detail{Text: "Ticket CSVC " + t.Code + " · " + t.Title, Tag: "Tham khảo", URL: "/tickets/" + t.ID.String()})
	}
	return out, nil
}
