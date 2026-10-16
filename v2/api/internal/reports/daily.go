package reports

import (
	"context"
	"encoding/json"
	"fmt"
	"slices"
	"strings"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/reports/reportsdb"
)

// Báo cáo ngày Học vụ / Học thuật (mockup 02/06 + chủ dự án 15/10/2026): số liệu lấy từ hệ thống, người viết chỉ nhập
// ghi chú từng khối + tạo task follow-up. Ngày chưa nộp: số liệu tính trực tiếp. Đã nộp: hiện số đã chụp lúc nộp
// (refresh = tính lại; Lưu thì ghi số mới). Báo cáo nhập tay bản cũ (data.fields, chưa có data.auto) hiện lại các ô đã nhập.

// dailyAuto là số liệu hệ thống chụp lúc nộp.
type dailyAuto struct {
	Audience   string    `json:"audience"`
	Sections   []Section `json:"sections"`
	ComputedAt time.Time `json:"computed_at"`
}

// dailyData là nội dung data của báo cáo ngày.
type dailyData struct {
	Auto    *dailyAuto        `json:"auto,omitempty"`
	Fields  map[string]any    `json:"fields,omitempty"`
	Notes   map[string]string `json:"notes,omitempty"`
	TaskIDs []uuid.UUID       `json:"task_ids,omitempty"`
}

type LabeledValue struct {
	Label string `json:"label"`
	Value string `json:"value"`
}

type DayLink struct {
	Date  string `json:"date"`
	Label string `json:"label"`
}

type DailyForm struct {
	Audience         string            `json:"audience"`
	Tabs             []Tab             `json:"tabs"`
	Date             string            `json:"date"`
	DateLabel        string            `json:"date_label"`
	Today            string            `json:"today"`
	Deadline         string            `json:"deadline"`
	Sections         []Section         `json:"sections"`
	ComputedAt       *time.Time        `json:"computed_at"`
	Live             bool              `json:"live"`
	NoteLabels       map[string]string `json:"note_labels"`
	Notes            map[string]string `json:"notes"`
	LegacyFields     []LabeledValue    `json:"legacy_fields"`
	Tasks            []TaskSummary     `json:"tasks"`
	TasksEnabled     bool              `json:"tasks_enabled"`
	Assignees        []Option          `json:"assignees"`
	SubmittedAt      *time.Time        `json:"submitted_at"`
	FirstSubmittedAt *time.Time        `json:"first_submitted_at"`
	Late             bool              `json:"late"`
	Review           *Review           `json:"review"`
	MissingDays      []DayLink         `json:"missing_days"`
	History          []HistoryItem     `json:"history"`
	Legacy           []LegacyReport    `json:"legacy"`
}

func noteLabel(k string) string {
	if l, ok := dailyNoteLabels[k]; ok {
		return l
	}
	return "Ghi chú khác"
}

func parseDailyData(raw []byte) dailyData {
	var d dailyData
	_ = json.Unmarshal(raw, &d)
	return d
}

// pickDate: ngày hợp lệ (Y-m-d, không sau hôm nay), ngược lại hôm nay.
func (s *Service) pickDate(v string) time.Time {
	if d, ok := parseDate(v); ok && !d.After(s.today()) {
		return d
	}
	return s.today()
}

// dailyMetrics: số liệu từ MetricsProvider + sự việc trong Nhật ký Học vụ của người viết trong ngày (khối Tình hình lớp học).
func (s *Service) dailyMetrics(ctx context.Context, w Writer, date time.Time, audience string) ([]Section, map[string]any, error) {
	sections, fields, err := s.metrics.Daily(ctx, w, date, audience)
	if err != nil {
		return nil, nil, err
	}
	if fields == nil {
		fields = map[string]any{}
	}
	for si := range sections {
		for ii := range sections[si].Items {
			if sections[si].Items[ii].Key != "class_situation" {
				continue
			}
			rows, err := s.q.JournalOnDate(ctx, reportsdb.JournalOnDateParams{UserID: w.ID, ReportDate: date, ClassIds: []uuid.UUID{}})
			if err != nil {
				return nil, nil, err
			}
			for _, r := range rows {
				d := Detail{Text: limitText(strings.TrimSpace(strOr(r.Content, r.Title)), 160) + " — " + r.UserName, Tag: "Nhật ký", URL: "/reports/journal"}
				if r.SeriousConfirmedAt != nil {
					d.Tag, d.Tone = "Lỗi nghiêm trọng", "error"
				}
				sections[si].Items[ii].Details = append(sections[si].Items[ii].Details, d)
			}
		}
	}
	return sections, fields, nil
}

// dailyText: bản chữ của số liệu + ghi chú (cột content).
func dailyText(sections []Section, notes map[string]string) string {
	var blocks []string
	for _, sec := range sections {
		lines := []string{sec.Title + ":"}
		for _, it := range sec.Items {
			line := "- " + it.Label
			if it.Value != nil {
				line += ": " + *it.Value
			}
			lines = append(lines, line)
			for i, d := range it.Details {
				if i == 15 {
					break
				}
				tag := ""
				if d.Tag != "" {
					tag = "[" + d.Tag + "] "
				}
				lines = append(lines, "    · "+tag+d.Text)
			}
		}
		if n := notes[sec.Key]; n != "" {
			lines = append(lines, "- Ghi chú: "+n)
		}
		blocks = append(blocks, strings.Join(lines, "\n"))
	}
	return strings.Join(blocks, "\n\n")
}

func (s *Service) assignees(ctx context.Context, a *iam.Access, w Writer) ([]Option, error) {
	out := []Option{{w.ID.String(), w.Name + " (Tôi)"}}
	if !s.tasks.Enabled() || !a.CanAny("work_task.create", "work_task.request") {
		return out, nil
	}
	others, err := s.tasks.Assignees(ctx, a)
	if err != nil {
		return nil, err
	}
	for _, o := range others {
		if o.Value != w.ID.String() {
			out = append(out, o)
		}
	}
	return out, nil
}

func (s *Service) Daily(ctx context.Context, a *iam.Access, dateParam string, refresh bool) (*DailyForm, error) {
	if err := guardView(a, KindDailyOps); err != nil {
		return nil, err
	}
	w, err := s.writer(ctx, a)
	if err != nil {
		return nil, err
	}
	today := s.today()
	date := s.pickDate(dateParam)
	missing, err := s.missingDays(ctx, w, today.AddDate(0, 0, -14), today.AddDate(0, 0, -1))
	if err != nil {
		return nil, err
	}
	// Trước 10:00 mà hôm qua chưa nộp thì mở sẵn hôm qua.
	yesterday := today.AddDate(0, 0, -1)
	if dateParam == "" && s.now().In(vnZone).Hour() < openYesterdayBefore &&
		slices.ContainsFunc(missing, func(d time.Time) bool { return d.Equal(yesterday) }) {
		date = yesterday
	}
	key := dateKey(date)
	audience := dailyAudience(a.Roles)
	report, err := s.structuredReport(ctx, a.UserID, KindDailyOps, key)
	if err != nil {
		return nil, err
	}
	var data dailyData
	if report != nil {
		data = parseDailyData(report.Data)
	}
	f := &DailyForm{
		Audience: audience, Tabs: tabs(a.Roles), Date: key, DateLabel: dateLabelVI(date), Today: dateKey(today),
		Deadline: dailyDeadline(date).Format("15:04 02/01"), NoteLabels: dailyNoteLabels, Notes: map[string]string{},
		LegacyFields: []LabeledValue{}, Tasks: []TaskSummary{}, TasksEnabled: s.tasks.Enabled(), MissingDays: []DayLink{},
	}
	f.Live = data.Auto == nil || refresh
	if f.Live {
		sections, _, err := s.dailyMetrics(ctx, w, date, audience)
		if err != nil {
			return nil, err
		}
		now := s.now()
		f.Sections, f.ComputedAt = sections, &now
	} else {
		f.Sections, f.ComputedAt = data.Auto.Sections, &data.Auto.ComputedAt
	}
	if data.Notes != nil {
		f.Notes = data.Notes
	}
	if report != nil && data.Auto == nil {
		for _, lf := range legacyDailyFields {
			if v, ok := data.Fields[lf.Value]; ok && v != nil && fmt.Sprint(v) != "" {
				f.LegacyFields = append(f.LegacyFields, LabeledValue{lf.Label, fmt.Sprint(v)})
			}
		}
	}
	if len(data.TaskIDs) > 0 {
		if f.Tasks, err = s.tasks.Summaries(ctx, data.TaskIDs); err != nil {
			return nil, err
		}
	}
	if f.Tasks == nil {
		f.Tasks = []TaskSummary{}
	}
	if f.Assignees, err = s.assignees(ctx, a, w); err != nil {
		return nil, err
	}
	if report != nil {
		f.SubmittedAt = &report.UpdatedAt
		f.FirstSubmittedAt = report.SubmittedAt
		if f.FirstSubmittedAt == nil {
			f.FirstSubmittedAt = &report.CreatedAt
		}
		f.Late = isLate(report.Type, report.SubmittedAt, report.CreatedAt, report.ReportDate)
	}
	if f.Review, err = s.reviewState(ctx, report); err != nil {
		return nil, err
	}
	for i := len(missing) - 1; i >= 0; i-- {
		f.MissingDays = append(f.MissingDays, DayLink{dateKey(missing[i]), missing[i].Format("02/01")})
	}
	label := func(k string) string {
		d, _ := parseDate(k)
		return "Ngày " + d.Format("02/01/2006")
	}
	hist, err := s.history(ctx, a.UserID, KindDailyOps, label)
	if err != nil {
		return nil, err
	}
	for _, d := range missing {
		hist = append(hist, HistoryItem{Period: dateKey(d), Label: label(dateKey(d)), Missing: true})
	}
	sortHistory(hist)
	if len(hist) > 20 {
		hist = hist[:20]
	}
	f.History = hist
	if f.Legacy, err = s.legacy(ctx, a.UserID, "daily"); err != nil {
		return nil, err
	}
	return f, nil
}

type TaskInput struct {
	Title      string  `json:"title"`
	Content    *string `json:"content"`
	DueDate    string  `json:"due_date"`
	AssigneeID *string `json:"assignee_id"`
}

type DailyInput struct {
	Date    string            `json:"date"`
	Refresh bool              `json:"refresh"`
	Notes   map[string]string `json:"notes"`
	Tasks   []TaskInput       `json:"tasks"`
}

// SaveDaily lưu (nộp / cập nhật) báo cáo ngày. Số liệu luôn tính ở máy chủ; sửa ghi chú báo cáo đã nộp giữ nguyên số
// đã chụp, chỉ tính lại khi refresh.
func (s *Service) SaveDaily(ctx context.Context, a *iam.Access, in DailyInput) (string, error) {
	if err := guardWrite(a, KindDailyOps); err != nil {
		return "", err
	}
	date, ok := parseDate(in.Date)
	if !ok {
		return "", iam.Invalid("Ngày báo cáo không hợp lệ.")
	}
	today := s.today()
	if date.After(today) {
		return "", iam.Invalid("Không nộp báo cáo cho ngày chưa tới.")
	}
	for k, v := range in.Notes {
		if runeLen(v) > 5000 {
			return "", iam.Invalid(noteLabel(k) + " tối đa 5000 ký tự.")
		}
	}
	if len(in.Tasks) > 10 {
		return "", iam.Invalid("Mỗi lần lưu tạo tối đa 10 task.")
	}
	w, err := s.writer(ctx, a)
	if err != nil {
		return "", err
	}
	tasks := make([]FollowUpTask, 0, len(in.Tasks))
	if len(in.Tasks) > 0 {
		if !s.tasks.Enabled() {
			_, err := s.tasks.Create(ctx, a, FollowUpTask{})
			return "", err
		}
		allowed, err := s.assignees(ctx, a, w)
		if err != nil {
			return "", err
		}
		for i, t := range in.Tasks {
			n := i + 1
			title := strings.TrimSpace(t.Title)
			if title == "" {
				return "", iam.Invalid(fmt.Sprintf("Task %d: nhập tiêu đề cho task.", n))
			}
			if runeLen(title) > 255 {
				return "", iam.Invalid(fmt.Sprintf("Task %d: tiêu đề tối đa 255 ký tự.", n))
			}
			if t.Content != nil && runeLen(*t.Content) > 5000 {
				return "", iam.Invalid(fmt.Sprintf("Task %d: nội dung tối đa 5000 ký tự.", n))
			}
			due, ok := parseDate(t.DueDate)
			if !ok {
				return "", iam.Invalid(fmt.Sprintf("Task %d: nhập hạn xử lý cho task.", n))
			}
			if due.Before(today) {
				return "", iam.Invalid("Hạn xử lý phải từ hôm nay trở đi.")
			}
			assignee := w.ID
			if t.AssigneeID != nil && *t.AssigneeID != "" {
				id, err := uuid.Parse(*t.AssigneeID)
				if err != nil || !slices.ContainsFunc(allowed, func(o Option) bool { return o.Value == id.String() }) {
					return "", iam.Invalid("Bạn không giao việc được cho người này.")
				}
				assignee = id
			}
			var branch *uuid.UUID
			if len(w.BranchIDs) > 0 {
				branch = &w.BranchIDs[0]
			}
			desc := strings.TrimSpace(strOr(t.Content, "") + "\n\nTạo từ báo cáo ngày " + date.Format("02/01/2006") + " của " + w.Name + ".")
			tasks = append(tasks, FollowUpTask{Title: title, Description: desc, AssigneeID: assignee, DueDate: due, BranchID: branch})
		}
	}

	key := dateKey(date)
	existing, err := s.structuredReport(ctx, a.UserID, KindDailyOps, key)
	if err != nil {
		return "", err
	}
	var old dailyData
	if existing != nil {
		old = parseDailyData(existing.Data)
	}
	data := dailyData{Fields: old.Fields}
	if old.Auto != nil && !in.Refresh {
		data.Auto = old.Auto
	} else {
		sections, fields, err := s.dailyMetrics(ctx, w, date, dailyAudience(a.Roles))
		if err != nil {
			return "", err
		}
		data.Auto = &dailyAuto{Audience: dailyAudience(a.Roles), Sections: sections, ComputedAt: s.now()}
		data.Fields = fields
	}
	data.Notes = map[string]string{}
	for _, sec := range data.Auto.Sections {
		if v := strings.TrimSpace(in.Notes[sec.Key]); v != "" {
			data.Notes[sec.Key] = v
		}
	}
	data.TaskIDs = old.TaskIDs
	var titles []string
	// Task tạo qua phân hệ Công việc (giao dịch riêng của phân hệ đó).
	for _, t := range tasks {
		id, err := s.tasks.Create(ctx, a, t)
		if err != nil {
			return "", err
		}
		data.TaskIDs = append(data.TaskIDs, id)
		titles = append(titles, t.Title)
		if t.AssigneeID != w.ID {
			// TODO(notify): báo người được giao (t.AssigneeID), loại task_assigned, "Bạn được giao việc: <tiêu đề>" —
			// "<tên> đã giao việc từ báo cáo ngày <d/m/Y>, hạn <d/m/Y>.", link Công việc tab "của tôi".
		}
	}
	text := dailyText(data.Auto.Sections, data.Notes)
	if len(titles) > 0 {
		text += "\n\nTask follow-up: " + strings.Join(titles, "; ")
	}
	if _, err := s.saveStructured(ctx, a, KindDailyOps, key, "Báo cáo ngày "+date.Format("02/01/2006"), date, data, text, "submitted"); err != nil {
		return "", err
	}
	return "Đã lưu báo cáo ngày " + date.Format("02/01/2006") + ".", nil
}
