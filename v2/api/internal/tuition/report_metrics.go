package tuition

import (
	"context"
	"fmt"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/reports"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/tuition/tuitiondb"
)

// ReportMetrics bọc provider số liệu báo cáo, điền mục "Số lượng thu phí" của báo cáo ngày Học vụ
// (DailyReportMetrics: phiếu thu ngày báo cáo, chờ duyệt / đã duyệt). cmd/menglish:
// tuitionSvc.ReportMetrics(cls.ReportMetrics(...)).
type ReportMetrics struct {
	reports.MetricsProvider
	s *Service
}

func (s *Service) ReportMetrics(base reports.MetricsProvider) *ReportMetrics {
	return &ReportMetrics{MetricsProvider: base, s: s}
}

var _ reports.Partial = (*ReportMetrics)(nil)

// Pending: theo provider gốc (Học phí không thêm phần nào khác).
func (m *ReportMetrics) Pending(part string) bool {
	p, ok := m.MetricsProvider.(reports.Partial)
	return ok && p.Pending(part)
}

func (m *ReportMetrics) Daily(ctx context.Context, w reports.Writer, date time.Time, audience string) ([]reports.Section, map[string]any, error) {
	sections, fields, err := m.MetricsProvider.Daily(ctx, w, date, audience)
	if err != nil || audience == reports.AudienceAcademic {
		return sections, fields, err
	}
	var it *reports.Item
	for i := range sections {
		for j := range sections[i].Items {
			if sections[i].Items[j].Key == "fees" {
				it = &sections[i].Items[j]
			}
		}
	}
	if it == nil {
		return sections, fields, nil
	}
	var branches []uuid.UUID
	if len(w.BranchIDs) > 0 {
		branches = w.BranchIDs
	}
	day := time.Date(date.Year(), date.Month(), date.Day(), 0, 0, 0, 0, time.UTC)
	rows, err := m.s.q.ReportDayReceipts(ctx, tuitiondb.ReportDayReceiptsParams{Day: day, BranchIds: branches})
	if err != nil {
		return nil, nil, err
	}
	n := len(rows)
	v := fmt.Sprint(n)
	it.Value, it.Count, it.Unavailable, it.Details = &v, &n, "", []reports.Detail{}
	if fields == nil {
		fields = map[string]any{}
	}
	fields["fees"] = n
	if n == 0 {
		return sections, fields, nil
	}
	var total int64
	count := map[uuid.UUID]int{}
	for _, r := range rows {
		d := reports.Detail{Text: r.StudentName + " · " + groupThousands(r.Amount) + "đ · " + methodLabel(r.PaymentMethod), Tag: "Chờ duyệt"}
		if r.Status == StatusApproved {
			d.Tag, d.Tone = "Đã duyệt", "success"
		}
		it.Details = append(it.Details, d)
		total += r.Amount
		if r.StudentBranchID != nil {
			count[*r.StudentBranchID]++
		}
	}
	it.Details = append(it.Details, reports.Detail{Text: "Tổng tiền: " + groupThousands(total) + "đ", Tone: "strong"})
	// Người viết phụ trách từ 2 chi nhánh: thêm dòng số phiếu theo từng chi nhánh.
	if len(w.BranchIDs) >= 2 {
		names, err := m.s.q.BranchNames(ctx, w.BranchIDs)
		if err != nil {
			return nil, nil, err
		}
		byID := map[uuid.UUID]string{}
		for _, b := range names {
			byID[b.ID] = b.Name
		}
		for _, b := range w.BranchIDs {
			if name, ok := byID[b]; ok {
				it.Details = append(it.Details, reports.Detail{Text: fmt.Sprintf("%s: %d phiếu", name, count[b]), Tag: "Chi nhánh"})
			}
		}
	}
	return sections, fields, nil
}
