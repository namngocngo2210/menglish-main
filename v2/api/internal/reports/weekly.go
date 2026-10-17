package reports

import (
	"context"
	"encoding/json"
	"fmt"
	"net/url"
	"slices"
	"strings"
	"time"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/reports/reportsdb"
)

// pickWeek: tuần hợp lệ và không phải tuần chưa tới, ngược lại tuần hiện tại.
func (s *Service) pickWeek(v string) string {
	if from, _, ok := weekRange(v); ok && !from.After(s.today()) {
		return v
	}
	return weekKey(s.now())
}

// validWeek: khóa tuần cho lưu báo cáo (không nộp cho tuần chưa tới).
func (s *Service) validWeek(v string) (time.Time, error) {
	from, _, ok := weekRange(v)
	if !ok {
		return time.Time{}, iam.Invalid("Tuần báo cáo không hợp lệ.")
	}
	if from.After(s.today()) {
		return time.Time{}, iam.Invalid("Không nộp báo cáo cho tuần chưa tới.")
	}
	return from, nil
}

// ═════════════ Báo cáo tuần Học vụ (KPI) ═════════════

// NoteGroup: ghi chú các báo cáo ngày trong tuần gộp theo khối.
type NoteGroup struct {
	Key   string   `json:"key"`
	Label string   `json:"label"`
	Lines []Detail `json:"lines"`
}

// DailySummary: báo cáo ngày của người viết trong tuần — đã nộp / phải nộp (đến hôm qua), ngày thiếu, ghi chú gộp.
type DailySummary struct {
	Submitted int         `json:"submitted"`
	Expected  int         `json:"expected"`
	Missing   []string    `json:"missing"`
	Notes     []NoteGroup `json:"notes"`
}

// weeklyOpsAuto là số liệu tuần Học vụ chụp lúc nộp.
type weeklyOpsAuto struct {
	Metrics    Section      `json:"metrics"`
	Groups     []KpiGroup   `json:"groups"`
	Daily      DailySummary `json:"daily"`
	ComputedAt time.Time    `json:"computed_at"`
}

type weeklyKpiCount struct {
	ID    string `json:"id"`
	Code  string `json:"code"`
	Name  string `json:"name"`
	Group string `json:"group"`
	Count int    `json:"count"`
}

type weeklyKpiData struct {
	Auto    *weeklyOpsAuto   `json:"auto,omitempty"`
	Counts  []weeklyKpiCount `json:"counts,omitempty"`
	Metrics map[string]any   `json:"metrics,omitempty"`
	Note    *string          `json:"note,omitempty"`
}

func (s *Service) dailySummary(ctx context.Context, w Writer, from, to time.Time) (DailySummary, error) {
	out := DailySummary{Missing: []string{}, Notes: []NoteGroup{}}
	due, err := s.requiredDays(ctx, w, from, to)
	if err != nil {
		return out, err
	}
	missing, err := s.missingDays(ctx, w, from, to)
	if err != nil {
		return out, err
	}
	for _, d := range missing {
		out.Missing = append(out.Missing, d.Format("02/01"))
	}
	out.Expected, out.Submitted = len(due), len(due)-len(missing)
	rows, err := s.q.DailyReportsBetween(ctx, reportsdb.DailyReportsBetweenParams{UserID: w.ID, FromKey: dateKey(from), ToKey: dateKey(to)})
	if err != nil {
		return out, err
	}
	for _, key := range dailyNoteOrder {
		g := NoteGroup{Key: key, Label: dailyNoteLabels[key], Lines: []Detail{}}
		for _, r := range rows {
			if n := parseDailyData(r.Data).Notes[key]; n != "" {
				d, _ := parseDate(*r.PeriodKey)
				g.Lines = append(g.Lines, Detail{Text: d.Format("02/01") + ": " + n})
			}
		}
		if len(g.Lines) > 0 {
			out.Notes = append(out.Notes, g)
		}
	}
	return out, nil
}

// kpiGroups: mục KPI Học vụ đang áp dụng nhóm theo cấu hình; mục tự động đếm qua MetricsProvider, còn lại đếm sự vụ trong
// Nhật ký Học vụ của người viết có gắn mã KPI.
func (s *Service) kpiGroups(ctx context.Context, w Writer, from, to time.Time, daily DailySummary) ([]KpiGroup, error) {
	criteria, err := s.metrics.KpiCriteria(ctx, iam.RoleAcademicStaff)
	if err != nil {
		return nil, err
	}
	groups := []KpiGroup{}
	if len(criteria) == 0 {
		return groups, nil
	}
	journal, err := s.q.JournalWithKpiBetween(ctx, reportsdb.JournalWithKpiBetweenParams{UserID: w.ID, FromDate: from, ToDate: to})
	if err != nil {
		return nil, err
	}
	for _, c := range criteria {
		item := KpiItem{ID: c.ID, Code: c.Code, Name: c.Name, Unit: c.Unit, Details: []Detail{}}
		if c.Auto {
			item.Source = "auto"
			item.Info = "Hệ thống tự đếm: " + c.AutoLabel + " (cùng cách đếm phiếu KPI tháng)."
			n, details, err := s.metrics.KpiEvidence(ctx, c, w, from, to)
			if err != nil {
				return nil, err
			}
			item.Count = n
			item.Details = append(item.Details, details...)
		} else {
			item.Source = "journal"
			code := c.Code
			if code == "" {
				code = "—"
			}
			item.Info = "Số sự vụ trong Nhật ký Học vụ của bạn trong tuần có gắn mã KPI " + code + ". Ghi thêm sự vụ ở Nhật ký Học vụ nếu còn thiếu."
			for _, j := range journal {
				if c.Code != "" && j.KpiCode != nil && *j.KpiCode == c.Code {
					item.Count++
					item.Details = append(item.Details, Detail{
						Text: j.ReportDate.Format("02/01") + " · " + limitText(strings.TrimSpace(strOr(j.Content, j.Title)), 140),
						URL:  "/reports/journal"})
				}
			}
		}
		// Dòng tham khảo (không đếm): báo cáo ngày đã nộp; ticket CSVC (material_stock) và buổi chưa điểm danh (mục 5.1)
		// từ phân hệ Ticket / Lớp học.
		if c.AutoSource == "daily_report_late" {
			text := fmt.Sprintf("Báo cáo ngày đã nộp %d/%d ngày", daily.Submitted, daily.Expected)
			if len(daily.Missing) > 0 {
				text += " · còn thiếu " + strings.Join(daily.Missing, ", ")
			}
			item.Details = append(item.Details, Detail{Text: text, Tag: "Tham khảo", URL: "/reports/daily"})
		} else {
			hints, err := s.metrics.KpiHints(ctx, c, w, from, to)
			if err != nil {
				return nil, err
			}
			item.Details = append(item.Details, hints...)
		}
		i := slices.IndexFunc(groups, func(g KpiGroup) bool { return g.Name == c.Group })
		if i < 0 {
			groups = append(groups, KpiGroup{Name: c.Group})
			i = len(groups) - 1
		}
		groups[i].Items = append(groups[i].Items, item)
	}
	return groups, nil
}

func (s *Service) weeklyOps(ctx context.Context, w Writer, week string) (*weeklyOpsAuto, error) {
	from, to, _ := weekRange(week)
	scale, err := s.metrics.WeeklyScale(ctx, w, from, to)
	if err != nil {
		return nil, err
	}
	daily, err := s.dailySummary(ctx, w, from, to)
	if err != nil {
		return nil, err
	}
	groups, err := s.kpiGroups(ctx, w, from, to, daily)
	if err != nil {
		return nil, err
	}
	return &weeklyOpsAuto{Metrics: scale, Groups: groups, Daily: daily, ComputedAt: s.now()}, nil
}

// weeklyOpsText: bản chữ của báo cáo tuần Học vụ.
func weeklyOpsText(a *weeklyOpsAuto, note *string) string {
	var kpi []string
	for _, g := range a.Groups {
		for _, i := range g.Items {
			if i.Count > 0 {
				kpi = append(kpi, fmt.Sprintf("%s — %s: %d lần", g.Name, i.Name, i.Count))
			}
		}
	}
	var parts []string
	if len(kpi) == 0 {
		parts = append(parts, "Số lần phát sinh theo mục KPI: không có phát sinh.")
	} else {
		parts = append(parts, "Số lần phát sinh theo mục KPI:\n"+strings.Join(kpi, "\n"))
	}
	metrics := []string{"Chỉ số quy mô:"}
	for _, i := range a.Metrics.Items {
		metrics = append(metrics, i.Label+": "+strOr(i.Value, "—"))
	}
	parts = append(parts, strings.Join(metrics, "\n"))
	if len(a.Daily.Notes) > 0 {
		lines := []string{"Ghi chú báo cáo ngày:"}
		for _, n := range a.Daily.Notes {
			lines = append(lines, n.Label+":")
			for _, l := range n.Lines {
				lines = append(lines, "    · "+l.Text)
			}
		}
		parts = append(parts, strings.Join(lines, "\n"))
	}
	if note != nil {
		parts = append(parts, "Ghi chú tuần: "+*note)
	}
	return strings.Join(parts, "\n\n")
}

type WeeklyKpiForm struct {
	Tabs        []Tab          `json:"tabs"`
	Week        string         `json:"week"`
	Weeks       []Option       `json:"weeks"`
	Groups      []KpiGroup     `json:"groups"`
	Metrics     Section        `json:"metrics"`
	Daily       DailySummary   `json:"daily"`
	ComputedAt  *time.Time     `json:"computed_at"`
	Live        bool           `json:"live"`
	Note        *string        `json:"note"`
	Legacy      []LabeledValue `json:"legacy"`
	SubmittedAt *time.Time     `json:"submitted_at"`
	Review      *Review        `json:"review"`
	History     []HistoryItem  `json:"history"`
}

func legacyWeeklyKpi(d weeklyKpiData) []LabeledValue {
	out := []LabeledValue{}
	for _, c := range d.Counts {
		if c.Count > 0 {
			out = append(out, LabeledValue{c.Name, fmt.Sprintf("%d lần", c.Count)})
		}
	}
	for _, m := range weeklyMetricLabels {
		if v, ok := d.Metrics[m.Value]; ok && v != nil {
			out = append(out, LabeledValue{m.Label, fmt.Sprint(v)})
		}
	}
	return out
}

func (s *Service) WeeklyKpi(ctx context.Context, a *iam.Access, weekParam string, refresh bool) (*WeeklyKpiForm, error) {
	if err := guardView(a, KindWeeklyKpi); err != nil {
		return nil, err
	}
	w, err := s.writer(ctx, a)
	if err != nil {
		return nil, err
	}
	week := s.pickWeek(weekParam)
	report, err := s.structuredReport(ctx, a.UserID, KindWeeklyKpi, week)
	if err != nil {
		return nil, err
	}
	var data weeklyKpiData
	if report != nil {
		_ = json.Unmarshal(report.Data, &data)
	}
	f := &WeeklyKpiForm{Tabs: tabs(a.Roles), Week: week, Weeks: weekOptions(s.now()), Note: data.Note, Legacy: []LabeledValue{}}
	f.Live = data.Auto == nil || refresh
	snap := data.Auto
	if f.Live {
		if snap, err = s.weeklyOps(ctx, w, week); err != nil {
			return nil, err
		}
	}
	f.Groups, f.Metrics, f.Daily, f.ComputedAt = snap.Groups, snap.Metrics, snap.Daily, &snap.ComputedAt
	if report != nil {
		f.SubmittedAt = &report.UpdatedAt
		if data.Auto == nil {
			f.Legacy = legacyWeeklyKpi(data)
		}
	}
	if f.Review, err = s.reviewState(ctx, report); err != nil {
		return nil, err
	}
	if f.History, err = s.history(ctx, a.UserID, KindWeeklyKpi, weekLabel); err != nil {
		return nil, err
	}
	return f, nil
}

type WeeklyKpiInput struct {
	Week    string  `json:"week"`
	Note    *string `json:"note"`
	Refresh bool    `json:"refresh"`
}

func (s *Service) SaveWeeklyKpi(ctx context.Context, a *iam.Access, in WeeklyKpiInput) (string, error) {
	if err := guardWrite(a, KindWeeklyKpi); err != nil {
		return "", err
	}
	from, err := s.validWeek(in.Week)
	if err != nil {
		return "", err
	}
	if in.Note != nil && runeLen(*in.Note) > 5000 {
		return "", iam.Invalid("Ghi chú tuần tối đa 5000 ký tự.")
	}
	w, err := s.writer(ctx, a)
	if err != nil {
		return "", err
	}
	existing, err := s.structuredReport(ctx, a.UserID, KindWeeklyKpi, in.Week)
	if err != nil {
		return "", err
	}
	var old weeklyKpiData
	if existing != nil {
		_ = json.Unmarshal(existing.Data, &old)
	}
	// Số liệu tính ở máy chủ và lưu kèm tên / nhóm mục KPI để báo cáo cũ vẫn đọc được khi Admin đổi cấu hình KPI.
	snap := old.Auto
	if snap == nil || in.Refresh {
		if snap, err = s.weeklyOps(ctx, w, in.Week); err != nil {
			return "", err
		}
	}
	data := weeklyKpiData{Auto: snap, Counts: []weeklyKpiCount{}, Metrics: map[string]any{}, Note: trimPtr(in.Note)}
	for _, g := range snap.Groups {
		for _, i := range g.Items {
			data.Counts = append(data.Counts, weeklyKpiCount{i.ID, i.Code, i.Name, g.Name, i.Count})
		}
	}
	for _, i := range snap.Metrics.Items {
		data.Metrics[i.Key] = i.Count
	}
	title := "Báo cáo tuần " + weekLabel(in.Week)
	if _, err := s.saveStructured(ctx, a, KindWeeklyKpi, in.Week, title, from, data, weeklyOpsText(snap, data.Note), "submitted"); err != nil {
		return "", err
	}
	return "Đã lưu báo cáo " + lcfirst(weekLabel(in.Week)) + ".", nil
}

func lcfirst(s string) string {
	for i := range s {
		if i > 0 {
			return strings.ToLower(s[:i]) + s[i:]
		}
	}
	return strings.ToLower(s)
}

// ═════════════ Báo cáo tuần Học thuật ═════════════

type academicWeeklyData struct {
	Rows map[string][]WeeklyRow `json:"rows"`
}

type AcademicWeeklyForm struct {
	Tabs            []Tab                  `json:"tabs"`
	Week            string                 `json:"week"`
	Weeks           []Option               `json:"weeks"`
	Sections        []Option               `json:"sections"`
	Columns         []Option               `json:"columns"`
	ProgressOptions []int                  `json:"progress_options"`
	Rows            map[string][]WeeklyRow `json:"rows"`
	Summary         Section                `json:"summary"`
	Suggestions     map[string][]WeeklyRow `json:"suggestions"`
	ComputedAt      time.Time              `json:"computed_at"`
	SubmittedAt     *time.Time             `json:"submitted_at"`
	Review          *Review                `json:"review"`
	History         []HistoryItem          `json:"history"`
	Legacy          []LegacyReport         `json:"legacy"`
}

func (s *Service) AcademicWeekly(ctx context.Context, a *iam.Access, weekParam string) (*AcademicWeeklyForm, error) {
	if err := guardView(a, KindAcademicWeekly); err != nil {
		return nil, err
	}
	w, err := s.writer(ctx, a)
	if err != nil {
		return nil, err
	}
	week := s.pickWeek(weekParam)
	report, err := s.structuredReport(ctx, a.UserID, KindAcademicWeekly, week)
	if err != nil {
		return nil, err
	}
	from, to, _ := weekRange(week)
	summary, suggestions, err := s.metrics.WeeklyAcademic(ctx, w, from, to)
	if err != nil {
		return nil, err
	}
	if suggestions == nil {
		suggestions = map[string][]WeeklyRow{}
	}
	f := &AcademicWeeklyForm{Tabs: tabs(a.Roles), Week: week, Weeks: weekOptions(s.now()), Sections: weeklySections,
		Columns: weeklyColumns, ProgressOptions: weeklyProgress, Rows: map[string][]WeeklyRow{}, Summary: summary,
		Suggestions: suggestions, ComputedAt: s.now()}
	if report != nil {
		var d academicWeeklyData
		_ = json.Unmarshal(report.Data, &d)
		if d.Rows != nil {
			f.Rows = d.Rows
		}
		f.SubmittedAt = &report.UpdatedAt
	}
	if f.Review, err = s.reviewState(ctx, report); err != nil {
		return nil, err
	}
	if f.History, err = s.history(ctx, a.UserID, KindAcademicWeekly, weekLabel); err != nil {
		return nil, err
	}
	if f.Legacy, err = s.legacy(ctx, a.UserID, "weekly"); err != nil {
		return nil, err
	}
	return f, nil
}

type AcademicWeeklyInput struct {
	Week string                 `json:"week"`
	Rows map[string][]WeeklyRow `json:"rows"`
}

func validURL(s string) bool {
	u, err := url.ParseRequestURI(s)
	return err == nil && (u.Scheme == "http" || u.Scheme == "https") && u.Host != ""
}

// cleanWeeklyRows: bỏ dòng trống, kiểm từng ô; dòng có dữ liệu phải có nội dung.
func cleanWeeklyRows(in map[string][]WeeklyRow) (map[string][]WeeklyRow, error) {
	out := map[string][]WeeklyRow{}
	total := 0
	for _, sec := range weeklySections {
		rows := in[sec.Value]
		if len(rows) > 50 {
			return nil, iam.Invalid(sec.Label + ": tối đa 50 dòng.")
		}
		clean := []WeeklyRow{}
		for i, r := range rows {
			at := fmt.Sprintf("%s, dòng %d: ", sec.Label, i+1)
			c := WeeklyRow{Content: trimPtr(r.Content), Detail: trimPtr(r.Detail), Deadline: trimPtr(r.Deadline), Progress: r.Progress,
				Link: trimPtr(r.Link), Report: trimPtr(r.Report), Support: trimPtr(r.Support), Note: trimPtr(r.Note)}
			if c.Content == nil && c.Detail == nil && c.Deadline == nil && c.Progress == nil && c.Link == nil &&
				c.Report == nil && c.Support == nil && c.Note == nil {
				continue
			}
			limits := []struct {
				v     *string
				label string
				max   int
			}{
				{c.Content, "Nội dung", 500}, {c.Detail, "Chi tiết", 5000}, {c.Link, "Link sản phẩm", 500},
				{c.Report, "Báo cáo", 1000}, {c.Support, "Đề xuất HT", 1000}, {c.Note, "Ghi chú", 1000},
			}
			for _, l := range limits {
				if l.v != nil && runeLen(*l.v) > l.max {
					return nil, iam.Invalid(fmt.Sprintf("%s%s tối đa %d ký tự.", at, l.label, l.max))
				}
			}
			if c.Content == nil {
				return nil, iam.Invalid(at + "Nhập nội dung công việc.")
			}
			if c.Deadline != nil {
				if _, ok := parseDate(*c.Deadline); !ok {
					return nil, iam.Invalid(at + "Deadline không phải ngày hợp lệ.")
				}
			}
			if c.Progress != nil && !slices.Contains(weeklyProgress, *c.Progress) {
				return nil, iam.Invalid(at + "Tiến độ chỉ nhận 0, 25, 50, 75 hoặc 100%.")
			}
			if c.Link != nil && !validURL(*c.Link) {
				return nil, iam.Invalid(at + "Link sản phẩm phải là đường dẫn (https://...).")
			}
			// Dòng điền sẵn từ hệ thống giữ mã nguồn (milestone:12, task:5…) để lần sau không gợi ý trùng.
			if src := trimPtr(r.Source); src != nil && runeLen(*src) <= 100 {
				c.Source = src
			}
			clean = append(clean, c)
		}
		out[sec.Value] = clean
		total += len(clean)
	}
	if total == 0 {
		return nil, iam.Invalid("Nhập ít nhất một công việc.")
	}
	return out, nil
}

func academicWeeklyText(rows map[string][]WeeklyRow) string {
	extra := []struct {
		label string
		get   func(WeeklyRow) *string
	}{
		{"Chi tiết", func(r WeeklyRow) *string { return r.Detail }},
		{"Link sản phẩm", func(r WeeklyRow) *string { return r.Link }},
		{"Báo cáo", func(r WeeklyRow) *string { return r.Report }},
		{"Đề xuất HT", func(r WeeklyRow) *string { return r.Support }},
		{"Ghi chú", func(r WeeklyRow) *string { return r.Note }},
	}
	var blocks []string
	for _, sec := range weeklySections {
		items := rows[sec.Value]
		if len(items) == 0 {
			continue
		}
		lines := []string{sec.Label + ":"}
		for i, r := range items {
			var meta []string
			if r.Deadline != nil {
				d, _ := parseDate(*r.Deadline)
				meta = append(meta, "deadline "+d.Format("02/01/2006"))
			}
			if r.Progress != nil {
				meta = append(meta, fmt.Sprintf("tiến độ %d%%", *r.Progress))
			}
			line := fmt.Sprintf("%d. %s", i+1, strOr(r.Content, ""))
			if len(meta) > 0 {
				line += " (" + strings.Join(meta, ", ") + ")"
			}
			for _, e := range extra {
				if v := e.get(r); v != nil {
					line += "\n   " + e.label + ": " + *v
				}
			}
			lines = append(lines, line)
		}
		blocks = append(blocks, strings.Join(lines, "\n"))
	}
	return strings.Join(blocks, "\n\n")
}

func (s *Service) SaveAcademicWeekly(ctx context.Context, a *iam.Access, in AcademicWeeklyInput) (string, error) {
	if err := guardWrite(a, KindAcademicWeekly); err != nil {
		return "", err
	}
	from, err := s.validWeek(in.Week)
	if err != nil {
		return "", err
	}
	rows, err := cleanWeeklyRows(in.Rows)
	if err != nil {
		return "", err
	}
	title := "Báo cáo tuần " + weekLabel(in.Week)
	if _, err := s.saveStructured(ctx, a, KindAcademicWeekly, in.Week, title, from, academicWeeklyData{rows}, academicWeeklyText(rows), "submitted"); err != nil {
		return "", err
	}
	return "Đã lưu báo cáo " + lcfirst(weekLabel(in.Week)) + ".", nil
}
