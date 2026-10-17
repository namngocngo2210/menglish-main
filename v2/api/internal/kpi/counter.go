package kpi

import (
	"context"
	"slices"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/kpi/kpisrc"
)

// Evidence: dòng bằng chứng lưu trên phiếu (KpiAutoCounter: date d/m, month Y-m, text, counted, note, cap).
type Evidence struct {
	Date    string  `json:"date"`
	Month   string  `json:"month"`
	Text    string  `json:"text"`
	Counted bool    `json:"counted"`
	Note    *string `json:"note"`
	Cap     bool    `json:"cap,omitempty"`
}

// measured: số liệu tự động của một tiêu chí trong kỳ.
type measured struct {
	Live     bool // nguồn đã có ở v2
	Value    *float64
	Evidence []Evidence
}

// MinUnits: tháng có ít hơn chừng này bản ghi tính theo hệ số thì gộp thêm tháng trước.
const MinUnits = 3

// Nguồn chưa có ở v2: tiêu chí dùng nguồn này do người chấm điền tay trên phiếu.
var pendingSources = map[string]string{
	"tuition_no_reminder":          "Học phí",
	"receipt_rejected":             "Học phí",
	"crm_sla_late":                 "SLA / phạt CRM",
	"teacher_absent_unexcused":     "Cổng giáo viên / giờ dạy",
	"teacher_leave":                "Cổng giáo viên / đơn nghỉ",
	"teacher_late":                 "Cổng giáo viên / giờ dạy",
	"staff_late":                   "Chấm công",
	"test_result":                  "Big Test / mini test",
	"test_progress":                "Big Test / mini test",
	"student_pass_rate":            "Big Test / mini test",
	"homework_rate":                "Bài tập",
	"academic_deliverable_on_time": "Dự án học thuật",
}

// PendingLabel: lý do nguồn chưa đếm được ở v2.
func PendingLabel(source string) string {
	mod, ok := pendingSources[source]
	if !ok {
		mod = "nguồn số liệu"
	}
	return "Chờ phân hệ " + mod + " chuyển sang hệ thống mới: người chấm điền tay số liệu."
}

// live: nguồn tự động của tiêu chí đã có ở v2.
func (s *Service) live(c *Criterion) bool {
	if !c.IsAuto() {
		return false
	}
	_, ok := s.sources[c.AutoSource]
	return ok
}

func endOfDay(t time.Time) time.Time { return dayOf(t).AddDate(0, 0, 1).Add(-time.Nanosecond) }

// measure: số liệu của nguồn cho nhân sự trong [from, to]. Đếm số lần → value = số bản ghi được tính; tỉ lệ → % (nil nếu
// chưa có dữ liệu); hệ số → trung bình hệ số × 100.
func (s *Service) measure(ctx context.Context, source string, userID uuid.UUID, from, to time.Time) (measured, error) {
	counter, ok := s.sources[source]
	if !ok {
		return measured{}, nil
	}
	from, to = dayOf(from), endOfDay(to)
	if slices.Contains(FactorSources, source) {
		v, ev, err := factorRate(func(f time.Time) ([]kpisrc.Record, error) {
			r, err := counter(ctx, userID, f, to)
			return r.Records, err
		}, from)
		return measured{Live: true, Value: v, Evidence: ev}, err
	}
	res, err := counter(ctx, userID, from, to)
	if err != nil {
		return measured{}, err
	}
	if slices.Contains(RateSources, source) {
		return measured{Live: true, Value: res.Value, Evidence: toEvidence(res.Records, false)}, nil
	}
	ev := toEvidence(res.Records, true)
	n := float64(countOf(ev))
	return measured{Live: true, Value: &n, Evidence: ev}, nil
}

// toEvidence: bản ghi → bằng chứng (sort = sắp theo thời điểm như KpiAutoCounter::evidence).
func toEvidence(rows []kpisrc.Record, sort bool) []Evidence {
	if sort {
		rows = slices.Clone(rows)
		slices.SortStableFunc(rows, func(a, b kpisrc.Record) int { return a.At.Compare(b.At) })
	}
	out := make([]Evidence, 0, len(rows))
	for _, r := range rows {
		out = append(out, recordEvidence(r, !r.Uncounted, ""))
	}
	return out
}

func recordEvidence(r kpisrc.Record, counted bool, suffix string) Evidence {
	e := Evidence{Date: r.Date, Month: r.Month, Text: r.Text + suffix, Counted: counted, Cap: r.Cap}
	if !r.At.IsZero() {
		at := r.At.In(vnZone)
		if e.Date == "" {
			e.Date = at.Format("02/01")
		}
		if e.Month == "" {
			e.Month = at.Format("2006-01")
		}
	}
	if r.Note != "" {
		e.Note = ptr(r.Note)
	}
	return e
}

func countOf(ev []Evidence) int {
	n := 0
	for _, e := range ev {
		if e.Counted {
			n++
		}
	}
	return n
}

// factorRate: % = trung bình hệ số từng bản ghi (đúng hạn 1, trễ nhẹ 0,5 / vừa 0,25 / nặng 0). Ít hơn MinUnits bản ghi trong kỳ
// thì gộp thêm tháng trước. Không có bản ghi → nil (Không phát sinh / điền tay). Dòng có cap là điều kiện chặn.
func factorRate(rows func(from time.Time) ([]kpisrc.Record, error), from time.Time) (*float64, []Evidence, error) {
	list, err := rows(from)
	if err != nil {
		return nil, nil, err
	}
	note := ""
	if counted(list) < MinUnits {
		start := time.Date(from.Year(), from.Month(), 1, 0, 0, 0, 0, vnZone).AddDate(0, -1, 0)
		if list, err = rows(start); err != nil {
			return nil, nil, err
		}
		note = "Tháng có ít hơn 3 bản ghi: gộp thêm tháng trước"
	}
	if counted(list) == 0 {
		return nil, []Evidence{}, nil
	}
	list = slices.Clone(list)
	slices.SortStableFunc(list, func(a, b kpisrc.Record) int { return a.At.Compare(b.At) })
	ev := make([]Evidence, 0, len(list)+1)
	if note != "" {
		ev = append(ev, Evidence{Text: note, Counted: true})
	}
	sum, n := 0.0, 0
	for _, r := range list {
		suffix := ""
		if r.Factor != nil {
			suffix = " · hệ số " + formatNum(*r.Factor, 2)
			sum += *r.Factor
			n++
		}
		ev = append(ev, recordEvidence(r, r.Factor != nil, suffix))
	}
	v := round1(sum / float64(n) * 100)
	return &v, ev, nil
}

func counted(rows []kpisrc.Record) int {
	n := 0
	for _, r := range rows {
		if r.Factor != nil {
			n++
		}
	}
	return n
}
