package kpi

import (
	"context"
	"time"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/reports"
)

// ReportMetrics: MetricsProvider của báo cáo + mục KPI (bảng KPI báo cáo tuần Học vụ, mã KPI gắn nhật ký) lấy từ bộ tiêu chí
// đang áp dụng; mục tự động đếm cùng cách phiếu KPI tháng (WeeklyReportMetrics::kpiGroups).
type ReportMetrics struct {
	reports.MetricsProvider
	svc *Service
}

func (s *Service) ReportMetrics(base reports.MetricsProvider) *ReportMetrics {
	return &ReportMetrics{MetricsProvider: base, svc: s}
}

var _ reports.Partial = (*ReportMetrics)(nil)

func (m *ReportMetrics) Pending(part string) bool {
	p, ok := m.MetricsProvider.(reports.Partial)
	return ok && p.Pending(part)
}

// KpiCriteria: tiêu chí đang áp dụng của vai trò. Auto = nguồn đếm số lần đã có ở v2; nguồn tỉ lệ / nguồn chưa chuyển
// sang thì báo cáo tuần đếm sự vụ trong Nhật ký Học vụ theo mã.
func (m *ReportMetrics) KpiCriteria(ctx context.Context, role string) ([]reports.KpiCriterion, error) {
	rows, err := m.svc.q.ActiveCriteria(ctx, role)
	if err != nil {
		return nil, err
	}
	out := make([]reports.KpiCriterion, 0, len(rows))
	for _, r := range rows {
		c := fromRow(r)
		lbl, _ := label(AutoSources, c.AutoSource)
		out = append(out, reports.KpiCriterion{ID: c.ID.String(), Code: c.Code, Name: c.Name, Group: c.Group,
			Unit: UnitLabel(c.Unit), Auto: m.svc.live(&c) && !c.IsRateSource(), AutoSource: c.AutoSource, AutoLabel: lbl})
	}
	return out, nil
}

// KpiEvidence: số lần + bản ghi của mục KPI tự động của người viết trong khoảng.
func (m *ReportMetrics) KpiEvidence(ctx context.Context, c reports.KpiCriterion, w reports.Writer, from, to time.Time) (int, []reports.Detail, error) {
	if c.AutoSource == "" {
		return 0, nil, nil
	}
	res, err := m.svc.measure(ctx, c.AutoSource, w.ID, from, to)
	if err != nil || !res.Live {
		return 0, nil, err
	}
	details := make([]reports.Detail, 0, len(res.Evidence))
	for _, e := range res.Evidence {
		text := e.Text
		if e.Date != "" {
			text = e.Date + " · " + text
		}
		if e.Note != nil {
			text += " (" + *e.Note + ")"
		}
		d := reports.Detail{Text: text}
		if !e.Counted {
			d.Tone = "muted"
		}
		details = append(details, d)
	}
	return countOf(res.Evidence), details, nil
}
