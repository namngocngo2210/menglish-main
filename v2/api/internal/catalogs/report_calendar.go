package catalogs

import (
	"context"
	"time"

	"github.com/google/uuid"
)

// ReportCalendar: ngày không phải nộp báo cáo ngày ngoài Chủ nhật (reports.Calendar), truyền vào reports.Deps ở
// cmd/menglish/main.go. Như Laravel DailyReportDue::requiredDays: ngày nghỉ toàn hệ thống + ngày nghỉ của chi nhánh chính
// người viết (không có chi nhánh: chỉ ngày nghỉ toàn hệ thống).
// TODO(hr): cộng ngày nghỉ phép đã duyệt của người viết khi phân hệ đơn nghỉ phép có ở v2.
func (s *Service) ReportCalendar() ReportCalendar { return ReportCalendar{s} }

type ReportCalendar struct{ s *Service }

func (c ReportCalendar) OffDays(ctx context.Context, _ uuid.UUID, branchID *uuid.UUID, from, to time.Time) (map[string]bool, error) {
	branch := uuid.Nil
	if branchID != nil {
		branch = *branchID
	}
	holidays, err := c.s.HolidaysBetween(ctx, branch, from, to)
	if err != nil {
		return nil, err
	}
	first, last := from.Format(time.DateOnly), to.Format(time.DateOnly)
	out := map[string]bool{}
	for _, h := range holidays {
		start, err1 := time.Parse(time.DateOnly, h.StartDate)
		end, err2 := time.Parse(time.DateOnly, h.EndDate)
		if err1 != nil || err2 != nil {
			continue
		}
		for d := start; !d.After(end); d = d.AddDate(0, 0, 1) {
			if k := d.Format(time.DateOnly); k >= first && k <= last {
				out[k] = true
			}
		}
	}
	return out, nil
}
