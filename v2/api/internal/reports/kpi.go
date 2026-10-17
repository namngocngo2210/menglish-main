package reports

import (
	"context"
	"math"
	"slices"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/kpi/kpisrc"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/reports/reportsdb"
)

// Phần việc phân hệ báo cáo làm cho KPI (KpiAutoCounter của Laravel): báo cáo ngày nộp trễ / không nộp, tỉ lệ tháng nộp báo
// cáo tháng đúng hạn, và sự vụ Nhật ký Học vụ gắn mã KPI (gợi ý số lần trên phiếu KPI).

// Báo cáo tháng nộp trước 12:00 ngày mùng 2 tháng sau là đúng hạn (file KPI GV part-time).
const (
	monthlyReportDay  = 2
	monthlyReportHour = 12
)

// KpiSources: nguồn daily_report_late, monthly_report_on_time.
func (s *Service) KpiSources() kpisrc.Sources {
	return kpisrc.Sources{
		"daily_report_late":      s.kpiDailyLate,
		"monthly_report_on_time": s.kpiMonthlyOnTime,
	}
}

// kpiDailyLate: báo cáo ngày nộp sau 09:00 hôm sau + ngày phải nộp mà không nộp (thứ Hai → thứ Bảy, trừ ngày lễ / nghỉ phép).
func (s *Service) kpiDailyLate(ctx context.Context, userID uuid.UUID, from, to time.Time) (kpisrc.Result, error) {
	rows, err := s.q.KpiDailyReports(ctx, reportsdb.KpiDailyReportsParams{UserID: userID, FromDate: dayOf(from), ToDate: dayOf(to)})
	if err != nil {
		return kpisrc.Result{}, err
	}
	var out []kpisrc.Record
	for _, r := range rows {
		if !isLate("daily", r.SubmittedAt, r.CreatedAt, r.ReportDate) {
			continue
		}
		first := r.CreatedAt
		if r.SubmittedAt != nil {
			first = *r.SubmittedAt
		}
		day := time.Date(r.ReportDate.Year(), r.ReportDate.Month(), r.ReportDate.Day(), 0, 0, 0, 0, vnZone)
		out = append(out, kpisrc.Record{At: day,
			Text: "Báo cáo ngày " + day.Format("02/01") + " nộp lúc " + first.In(vnZone).Format("15:04 02/01")})
	}
	w, err := s.writerByID(ctx, userID)
	if err != nil {
		return kpisrc.Result{}, err
	}
	if slices.Contains(structuredKinds(w.Roles), KindDailyOps) {
		missing, err := s.missingDays(ctx, w, from, to)
		if err != nil {
			return kpisrc.Result{}, err
		}
		for _, d := range missing {
			out = append(out, kpisrc.Record{At: d, Text: "Không nộp báo cáo ngày " + d.Format("02/01")})
		}
	}
	return kpisrc.Result{Records: out}, nil
}

// kpiMonthlyOnTime: tỉ lệ tháng trong kỳ nộp báo cáo tháng trước 12h ngày mùng 2 tháng sau (tháng chưa tới hạn mà chưa nộp
// thì chưa tính).
func (s *Service) kpiMonthlyOnTime(ctx context.Context, userID uuid.UUID, from, to time.Time) (kpisrc.Result, error) {
	from = dayOf(from)
	var months []time.Time
	for m := time.Date(from.Year(), from.Month(), 1, 0, 0, 0, 0, vnZone); !m.After(to); m = m.AddDate(0, 1, 0) {
		months = append(months, m)
	}
	keys := make([]string, 0, len(months))
	for _, m := range months {
		keys = append(keys, m.Format("2006-01"))
	}
	rows, err := s.q.KpiMonthlyReports(ctx, reportsdb.KpiMonthlyReportsParams{UserID: userID, Months: keys})
	if err != nil {
		return kpisrc.Result{}, err
	}
	submitted := map[string]time.Time{}
	for _, r := range rows {
		submitted[r.Month] = r.Submitted
	}
	now := s.now()
	var out []kpisrc.Record
	onTime, counted := 0, 0
	for _, m := range months {
		key := m.Format("2006-01")
		deadline := time.Date(m.Year(), m.Month()+1, monthlyReportDay, monthlyReportHour, 0, 0, 0, vnZone)
		at, ok := submitted[key]
		if !ok && now.Before(deadline) {
			continue
		}
		counted++
		good := ok && !at.After(deadline)
		if good {
			onTime++
		}
		text := "Chưa nộp báo cáo tháng"
		if ok {
			text = "Báo cáo tháng nộp lúc " + at.In(vnZone).Format("15:04 02/01")
			if !good {
				text += " (trễ hạn)"
			}
		}
		out = append(out, kpisrc.Record{Date: m.Format("01/2006"), Month: key, Text: text, Uncounted: good})
	}
	res := kpisrc.Result{Records: out}
	if counted > 0 {
		res.Value = kpisrc.F(math.Round(float64(onTime)/float64(counted)*1000) / 10)
	}
	return res, nil
}

// KpiJournal: sự vụ trong Nhật ký Học vụ của nhân sự có gắn mã KPI trong khoảng.
func (s *Service) KpiJournal(ctx context.Context, userID uuid.UUID, from, to time.Time) ([]kpisrc.JournalEntry, error) {
	rows, err := s.q.JournalWithKpiBetween(ctx, reportsdb.JournalWithKpiBetweenParams{UserID: userID, FromDate: dayOf(from), ToDate: dayOf(to)})
	if err != nil {
		return nil, err
	}
	out := make([]kpisrc.JournalEntry, 0, len(rows))
	for _, r := range rows {
		if r.KpiCode != nil {
			out = append(out, kpisrc.JournalEntry{Code: *r.KpiCode, Date: r.ReportDate, Title: r.Title})
		}
	}
	return out, nil
}
