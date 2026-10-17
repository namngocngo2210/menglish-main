package teacher

import (
	"context"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/reports"
)

// ReportMetrics: phần của Cổng giáo viên trong báo cáo định kỳ — tỷ lệ làm bài về nhà (%) của từng lớp trong báo cáo tháng
// giáo viên (TeachingQuality::classMetrics → homework, gợi ý mức "Bài tập"). Bọc provider có danh sách lớp (classes).
type ReportMetrics struct {
	reports.MetricsProvider
	svc *Service
}

func (s *Service) ReportMetrics(base reports.MetricsProvider) *ReportMetrics {
	return &ReportMetrics{MetricsProvider: base, svc: s}
}

var _ reports.Partial = (*ReportMetrics)(nil)

// Pending: theo provider gốc (phân hệ này không thêm phần nào riêng).
func (m *ReportMetrics) Pending(part string) bool {
	p, ok := m.MetricsProvider.(reports.Partial)
	return ok && p.Pending(part)
}

// TeacherClasses: lớp của provider gốc + tỷ lệ bài về nhà của tháng (lớp không giao bài trong tháng để trống).
func (m *ReportMetrics) TeacherClasses(ctx context.Context, w reports.Writer, month string) ([]reports.TeacherClass, error) {
	classes, err := m.MetricsProvider.TeacherClasses(ctx, w, month)
	if err != nil || len(classes) == 0 {
		return classes, err
	}
	start, err := time.ParseInLocation("2006-01", month, vnZone)
	if err != nil {
		return classes, nil
	}
	ids := make([]uuid.UUID, len(classes))
	for i, c := range classes {
		ids[i] = c.ID
	}
	rates, err := m.svc.homeworkRates(ctx, ids, start, start.AddDate(0, 1, 0).Add(-time.Nanosecond))
	if err != nil {
		return nil, err
	}
	for i := range classes {
		if r, ok := rates[classes[i].ID]; ok {
			v := r
			classes[i].Homework = &v
		}
	}
	return classes, nil
}
