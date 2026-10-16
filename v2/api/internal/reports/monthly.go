package reports

import (
	"context"
	"encoding/json"
	"fmt"
	"math"
	"slices"
	"strconv"
	"strings"
	"time"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/reports/reportsdb"
)

// pickMonth / pickQuarter: khóa đúng dạng thì giữ (ReportPeriod::pick), không thì kỳ hiện tại.
func (s *Service) pickMonth(v string) string {
	if _, _, ok := monthRange(v); ok {
		return v
	}
	return monthKey(s.now())
}

func (s *Service) pickQuarter(v string) string {
	if _, _, ok := quarterRange(v); ok {
		return v
	}
	return quarterKey(s.now())
}

func validMonth(v string) (time.Time, error) {
	from, _, ok := monthRange(v)
	if !ok {
		return time.Time{}, iam.Invalid("Tháng báo cáo không hợp lệ.")
	}
	return from, nil
}

type narrativeData struct {
	Narrative map[string]*string `json:"narrative"`
}

func narrativeValues(r *reportsdb.OpsStaffReport) map[string]*string {
	out := map[string]*string{}
	if r != nil {
		var d narrativeData
		_ = json.Unmarshal(r.Data, &d)
		if d.Narrative != nil {
			out = d.Narrative
		}
	}
	return out
}

func submittedAt(r *reportsdb.OpsStaffReport) *time.Time {
	if r == nil {
		return nil
	}
	t := r.UpdatedAt
	return &t
}

// ═════════════ Báo cáo tháng Học thuật ═════════════

// WeekOfMonth là một tuần (ISO) chạm vào tháng kèm báo cáo tuần của chính người viết (có cấu trúc, hoặc chữ tự do cũ).
type WeekOfMonth struct {
	Week    string  `json:"week"`
	Label   string  `json:"label"`
	Range   string  `json:"range"`
	PIC     *string `json:"pic"`
	Title   *string `json:"title"`
	Content *string `json:"content"`
}

type AcademicMonthlyForm struct {
	Tabs           []Tab              `json:"tabs"`
	Month          string             `json:"month"`
	Months         []Option           `json:"months"`
	Fields         []Option           `json:"fields"`
	Values         map[string]*string `json:"values"`
	SubmittedAt    *time.Time         `json:"submitted_at"`
	Review         *Review            `json:"review"`
	WeeklyReports  []WeekOfMonth      `json:"weekly_reports"`
	Meetings       []Meeting          `json:"meetings"`
	MeetingsNote   string             `json:"meetings_note,omitempty"`
	History        []HistoryItem      `json:"history"`
	MaxFieldLength int                `json:"max_field_length"`
}

const narrativeMax = 10000

// weeksOfMonth: các tuần chạm vào tháng, mới nhất trước; tuần chưa có báo cáo có cấu trúc thì lấy báo cáo tuần chữ tự do
// cũ nộp trong tuần đó.
func (s *Service) weeksOfMonth(ctx context.Context, w Writer, from, to time.Time) ([]WeekOfMonth, error) {
	start, _, _ := weekRange(weekKey(from))
	var keys []string
	for d := start; !d.After(to); d = d.AddDate(0, 0, 7) {
		keys = append(keys, weekKey(d))
	}
	kind := KindAcademicWeekly
	rows, err := s.q.StructuredByPeriods(ctx, reportsdb.StructuredByPeriodsParams{UserID: w.ID, Kind: &kind, Periods: keys})
	if err != nil {
		return nil, err
	}
	byKey := map[string]reportsdb.OpsStaffReport{}
	for _, r := range rows {
		byKey[*r.PeriodKey] = r
	}
	old, err := s.q.LegacyWeeklyBetween(ctx, reportsdb.LegacyWeeklyBetweenParams{UserID: w.ID, FromDate: start, ToDate: to})
	if err != nil {
		return nil, err
	}
	legacyByKey := map[string]reportsdb.OpsStaffReport{}
	for _, r := range old { // keyBy giữ bản sau cùng của tuần
		legacyByKey[weekKey(r.ReportDate)] = r
	}
	out := make([]WeekOfMonth, 0, len(keys))
	for i, k := range keys {
		ws, we, _ := weekRange(k)
		if ws.Before(from) {
			ws = from
		}
		if we.After(to) {
			we = to
		}
		item := WeekOfMonth{Week: k, Label: fmt.Sprintf("Tuần %d", i+1), Range: ws.Format("02/01") + " - " + we.Format("02/01")}
		r, ok := byKey[k]
		if !ok {
			r, ok = legacyByKey[k]
		}
		if ok {
			item.PIC, item.Title, item.Content = ptr(w.Name), ptr(r.Title), r.Content
		}
		out = append(out, item)
	}
	slices.Reverse(out)
	return out, nil
}

func (s *Service) AcademicMonthly(ctx context.Context, a *iam.Access, monthParam string) (*AcademicMonthlyForm, error) {
	if err := guardView(a, KindAcademicMonthly); err != nil {
		return nil, err
	}
	w, err := s.writer(ctx, a)
	if err != nil {
		return nil, err
	}
	month := s.pickMonth(monthParam)
	from, to, _ := monthRange(month)
	report, err := s.structuredReport(ctx, a.UserID, KindAcademicMonthly, month)
	if err != nil {
		return nil, err
	}
	f := &AcademicMonthlyForm{Tabs: tabs(a.Roles), Month: month, Months: monthOptions(s.now()), Fields: monthlyFields,
		Values: narrativeValues(report), SubmittedAt: submittedAt(report), MaxFieldLength: narrativeMax}
	if f.Review, err = s.reviewState(ctx, report); err != nil {
		return nil, err
	}
	if f.WeeklyReports, err = s.weeksOfMonth(ctx, w, from, to); err != nil {
		return nil, err
	}
	weekStart, _, _ := weekRange(weekKey(from))
	if f.Meetings, err = s.metrics.TeacherMeetings(ctx, a.UserID, weekStart, to); err != nil {
		return nil, err
	}
	if f.Meetings == nil {
		f.Meetings = []Meeting{}
	}
	if pendingPart(s.metrics, "meetings") {
		f.MeetingsNote = srcQuality
	}
	if f.History, err = s.history(ctx, a.UserID, KindAcademicMonthly, monthLabel); err != nil {
		return nil, err
	}
	return f, nil
}

// NarrativeInput: báo cáo tháng / quý Học thuật — kỳ + các ô chữ.
type NarrativeInput struct {
	Period    string             `json:"period"`
	Narrative map[string]*string `json:"narrative"`
}

func (s *Service) SaveAcademicMonthly(ctx context.Context, a *iam.Access, in NarrativeInput) (string, error) {
	if err := guardWrite(a, KindAcademicMonthly); err != nil {
		return "", err
	}
	from, err := validMonth(in.Period)
	if err != nil {
		return "", err
	}
	values, err := validateNarrative(in.Narrative, monthlyFields, narrativeMax)
	if err != nil {
		return "", err
	}
	title := "Báo cáo tháng Học thuật — " + monthLabel(in.Period)
	if _, err := s.saveStructured(ctx, a, KindAcademicMonthly, in.Period, title, from, narrativeData{values},
		narrativeText(values, monthlyFields), "submitted"); err != nil {
		return "", err
	}
	return "Đã lưu báo cáo " + strings.ToLower(monthLabel(in.Period)) + ".", nil
}

// ═════════════ Báo cáo quý Học thuật ═════════════

type MonthOfQuarter struct {
	Month     string     `json:"month"`
	Label     string     `json:"label"`
	Submitted bool       `json:"submitted"`
	UpdatedAt *time.Time `json:"updated_at"`
}

type AcademicQuarterlyForm struct {
	Tabs           []Tab              `json:"tabs"`
	Quarter        string             `json:"quarter"`
	QuarterLabel   string             `json:"quarter_label"`
	Quarters       []Option           `json:"quarters"`
	Sections       []FieldGroup       `json:"sections"`
	Values         map[string]*string `json:"values"`
	SubmittedAt    *time.Time         `json:"submitted_at"`
	Review         *Review            `json:"review"`
	MonthlyReports []MonthOfQuarter   `json:"monthly_reports"`
	History        []HistoryItem      `json:"history"`
	MaxFieldLength int                `json:"max_field_length"`
}

func (s *Service) AcademicQuarterly(ctx context.Context, a *iam.Access, quarterParam string) (*AcademicQuarterlyForm, error) {
	if err := guardView(a, KindAcademicQuarterly); err != nil {
		return nil, err
	}
	quarter := s.pickQuarter(quarterParam)
	report, err := s.structuredReport(ctx, a.UserID, KindAcademicQuarterly, quarter)
	if err != nil {
		return nil, err
	}
	months := quarterMonths(quarter)
	kind := KindAcademicMonthly
	rows, err := s.q.StructuredByPeriods(ctx, reportsdb.StructuredByPeriodsParams{UserID: a.UserID, Kind: &kind, Periods: months})
	if err != nil {
		return nil, err
	}
	byMonth := map[string]reportsdb.OpsStaffReport{}
	for _, r := range rows {
		byMonth[*r.PeriodKey] = r
	}
	f := &AcademicQuarterlyForm{Tabs: tabs(a.Roles), Quarter: quarter, QuarterLabel: quarterLabel(quarter),
		Quarters: quarterOptions(s.now()), Sections: quarterlySections, Values: narrativeValues(report),
		SubmittedAt: submittedAt(report), MonthlyReports: []MonthOfQuarter{}, MaxFieldLength: narrativeMax}
	for _, m := range months {
		item := MonthOfQuarter{Month: m, Label: "Báo cáo " + monthLabel(m)}
		if r, ok := byMonth[m]; ok {
			item.Submitted, item.UpdatedAt = true, submittedAt(&r)
		}
		f.MonthlyReports = append(f.MonthlyReports, item)
	}
	if f.Review, err = s.reviewState(ctx, report); err != nil {
		return nil, err
	}
	if f.History, err = s.history(ctx, a.UserID, KindAcademicQuarterly, quarterLabel); err != nil {
		return nil, err
	}
	return f, nil
}

func (s *Service) SaveAcademicQuarterly(ctx context.Context, a *iam.Access, in NarrativeInput) (string, error) {
	if err := guardWrite(a, KindAcademicQuarterly); err != nil {
		return "", err
	}
	from, _, ok := quarterRange(in.Period)
	if !ok {
		return "", iam.Invalid("Quý báo cáo không hợp lệ.")
	}
	fields := quarterlyFields()
	values, err := validateNarrative(in.Narrative, fields, narrativeMax)
	if err != nil {
		return "", err
	}
	title := "Báo cáo quý Học thuật — " + quarterLabel(in.Period)
	if _, err := s.saveStructured(ctx, a, KindAcademicQuarterly, in.Period, title, from, narrativeData{values},
		narrativeText(values, fields), "submitted"); err != nil {
		return "", err
	}
	return "Đã lưu báo cáo quý " + in.Period + ".", nil
}

// ═════════════ Báo cáo tháng giáo viên (giảng dạy theo lớp + chấm công) ═════════════

// teacherMonthlyData: data của teacher_monthly — nhận xét chung + từng lớp (khóa phẳng như Laravel:
// sessions, các lựa chọn, checks, notes, các ô chữ, need_support).
type teacherMonthlyData struct {
	General map[string]*string        `json:"general"`
	Classes map[string]map[string]any `json:"classes"`
}

type attendanceData struct {
	Note *string `json:"note"`
}

type TeacherAttendance struct {
	Note        *string    `json:"note"`
	SubmittedAt *time.Time `json:"submitted_at"`
}

type TeacherMonthlyForm struct {
	Tabs            []Tab                     `json:"tabs"`
	Month           string                    `json:"month"`
	MonthLabel      string                    `json:"month_label"`
	Months          []Option                  `json:"months"`
	DueLabel        string                    `json:"due_label"`
	GeneralFields   []Option                  `json:"general_fields"`
	AcademicChecks  []Check                   `json:"academic_checks"`
	OperationChecks []Check                   `json:"operation_checks"`
	QualityChecks   []Check                   `json:"quality_checks"`
	ClassTexts      []Option                  `json:"class_texts"`
	Choices         []Choice                  `json:"choices"`
	General         map[string]*string        `json:"general"`
	ClassValues     map[string]map[string]any `json:"class_values"`
	Classes         []TeacherClass            `json:"classes"`
	ClassesNote     string                    `json:"classes_note,omitempty"`
	Status          *string                   `json:"status"`
	SubmittedAt     *time.Time                `json:"submitted_at"`
	Review          *Review                   `json:"review"`
	Attendance      TeacherAttendance         `json:"attendance"`
	ProposeURL      *string                   `json:"propose_url"`
	History         []HistoryItem             `json:"history"`
	Legacy          []LegacyReport            `json:"legacy"`
}

func (s *Service) TeacherMonthly(ctx context.Context, a *iam.Access, monthParam string) (*TeacherMonthlyForm, error) {
	if err := guardView(a, KindTeacherMonthly); err != nil {
		return nil, err
	}
	w, err := s.writer(ctx, a)
	if err != nil {
		return nil, err
	}
	month := s.pickMonth(monthParam)
	from, _, _ := monthRange(month)
	report, err := s.structuredReport(ctx, a.UserID, KindTeacherMonthly, month)
	if err != nil {
		return nil, err
	}
	attendance, err := s.structuredReport(ctx, a.UserID, KindTeacherAttendance, month)
	if err != nil {
		return nil, err
	}
	f := &TeacherMonthlyForm{Tabs: tabs(a.Roles), Month: month, MonthLabel: monthLabel(month), Months: monthOptions(s.now()),
		DueLabel: "Chủ nhật " + monthlyDueDate(from).Format("02/01"), GeneralFields: teacherGeneralFields,
		AcademicChecks: teacherAcademicChecks, OperationChecks: teacherOperationChecks, QualityChecks: teacherQualityChecks,
		ClassTexts: teacherClassTexts, Choices: teacherChoices, General: map[string]*string{},
		ClassValues: map[string]map[string]any{}, SubmittedAt: submittedAt(report)}
	// TODO(gộp): link "Đề xuất điều chỉnh giáo trình" (syllabus.teacher-propose) khi phân hệ Giáo trình có ở v2.
	if report != nil {
		var d teacherMonthlyData
		_ = json.Unmarshal(report.Data, &d)
		if d.General != nil {
			f.General = d.General
		}
		if d.Classes != nil {
			f.ClassValues = d.Classes
		}
		f.Status = &report.Status
	}
	if attendance != nil {
		var d attendanceData
		_ = json.Unmarshal(attendance.Data, &d)
		f.Attendance = TeacherAttendance{Note: d.Note, SubmittedAt: submittedAt(attendance)}
	}
	if f.Classes, err = s.metrics.TeacherClasses(ctx, w, month); err != nil {
		return nil, err
	}
	if f.Classes == nil {
		f.Classes = []TeacherClass{}
	}
	if pendingPart(s.metrics, "classes") {
		f.ClassesNote = srcClasses
	}
	if f.Review, err = s.reviewState(ctx, report); err != nil {
		return nil, err
	}
	if f.History, err = s.history(ctx, a.UserID, KindTeacherMonthly, monthLabel); err != nil {
		return nil, err
	}
	if f.Legacy, err = s.legacy(ctx, a.UserID, "monthly"); err != nil {
		return nil, err
	}
	return f, nil
}

type TeacherMonthlyInput struct {
	Month   string                                `json:"month"`
	Intent  string                                `json:"intent"` // draft | submit
	General map[string]*string                    `json:"general"`
	Classes map[string]map[string]json.RawMessage `json:"classes"`
}

// rawText đọc ô chữ (null / chuỗi); chuỗi trắng = nil.
func rawText(raw json.RawMessage) (*string, bool) {
	if len(raw) == 0 || string(raw) == "null" {
		return nil, true
	}
	var v string
	if json.Unmarshal(raw, &v) != nil {
		return nil, false
	}
	if strings.TrimSpace(v) == "" {
		return nil, true
	}
	return &v, true
}

// rawSessions đọc "Số buổi đã dạy": số nguyên 0–500, nhận cả chuỗi số; trống = nil.
func rawSessions(raw json.RawMessage) (*int, error) {
	if len(raw) == 0 || string(raw) == "null" {
		return nil, nil
	}
	var v any
	if json.Unmarshal(raw, &v) != nil {
		return nil, iam.Invalid("Số buổi phải là số nguyên.")
	}
	var n float64
	switch x := v.(type) {
	case float64:
		n = x
	case string:
		if strings.TrimSpace(x) == "" {
			return nil, nil
		}
		f, err := strconv.ParseFloat(strings.TrimSpace(x), 64)
		if err != nil {
			return nil, iam.Invalid("Số buổi phải là số nguyên.")
		}
		n = f
	default:
		return nil, iam.Invalid("Số buổi phải là số nguyên.")
	}
	if n != math.Trunc(n) {
		return nil, iam.Invalid("Số buổi phải là số nguyên.")
	}
	if n < 0 {
		return nil, iam.Invalid("Số buổi không âm.")
	}
	if n > 500 {
		return nil, iam.Invalid("Số buổi tối đa 500.")
	}
	i := int(n)
	return &i, nil
}

// cleanTeacherClass kiểm và chuẩn hóa một lớp; empty = lớp không có gì (bỏ khỏi báo cáo).
func cleanTeacherClass(name string, in map[string]json.RawMessage) (row map[string]any, empty bool, err error) {
	at := "Lớp " + name + ": "
	wrap := func(e error) error { return iam.Invalid(at + e.(*iam.Error).Message) }
	sessions, err := rawSessions(in["sessions"])
	if err != nil {
		return nil, false, wrap(err)
	}
	row = map[string]any{"sessions": sessions}
	filled := sessions != nil
	for _, c := range teacherChoices {
		v, ok := rawText(in[c.Key])
		if !ok || (v != nil && !slices.ContainsFunc(c.Options, func(o Option) bool { return o.Value == *v })) {
			return nil, false, iam.Invalid(at + c.Label + " không hợp lệ.")
		}
		row[c.Key] = v
		filled = filled || v != nil
	}
	var checksIn map[string]any
	if raw := in["checks"]; len(raw) > 0 && string(raw) != "null" {
		if json.Unmarshal(raw, &checksIn) != nil {
			return nil, false, iam.Invalid(at + "Mục tích không hợp lệ.")
		}
	}
	var notesIn map[string]*string
	if raw := in["notes"]; len(raw) > 0 && string(raw) != "null" {
		if json.Unmarshal(raw, &notesIn) != nil {
			return nil, false, iam.Invalid(at + "Ghi chú không hợp lệ.")
		}
	}
	checks, notes := map[string]bool{}, map[string]*string{}
	for _, c := range teacherAllChecks() {
		on := false
		switch v := checksIn[c.Key].(type) {
		case bool:
			on = v
		case float64:
			on = v != 0
		case string:
			on = v == "1" || v == "true"
		}
		checks[c.Key] = on
		filled = filled || on
		if c.Note == "" {
			continue
		}
		n := trimPtr(notesIn[c.Key])
		if n != nil && runeLen(*n) > 1000 {
			return nil, false, iam.Invalid(at + "Ghi chú \"" + c.Label + "\" tối đa 1000 ký tự.")
		}
		notes[c.Key] = n
		filled = filled || n != nil
	}
	row["checks"], row["notes"] = checks, notes
	for _, t := range teacherClassTexts {
		v, ok := rawText(in[t.Value])
		if !ok {
			return nil, false, iam.Invalid(at + t.Label + " không hợp lệ.")
		}
		if v != nil && runeLen(*v) > 5000 {
			return nil, false, iam.Invalid(fmt.Sprintf("%s%s tối đa 5000 ký tự.", at, t.Label))
		}
		row[t.Value] = v
		filled = filled || v != nil
	}
	row["need_support"] = row["support_note"].(*string) != nil
	return row, !filled, nil
}

// teacherClassText: dòng "- ..." của một lớp (số buổi, lựa chọn, mục tích kèm ghi chú, ô chữ).
func teacherClassText(row map[string]any) string {
	var lines []string
	if v, ok := row["sessions"].(*int); ok && v != nil {
		lines = append(lines, fmt.Sprintf("- Số buổi đã dạy: %d", *v))
	}
	for _, c := range teacherChoices {
		if v, ok := row[c.Key].(*string); ok && v != nil {
			lines = append(lines, "- "+c.Label+": "+optionLabel(c.Options, *v))
		}
	}
	checks, _ := row["checks"].(map[string]bool)
	notes, _ := row["notes"].(map[string]*string)
	for _, c := range teacherAllChecks() {
		note := notes[c.Key]
		if !checks[c.Key] && note == nil {
			continue
		}
		mark := "[ ] "
		if checks[c.Key] {
			mark = "[x] "
		}
		line := "- " + mark + c.Label
		if note != nil {
			line += " — " + *note
		}
		lines = append(lines, line)
	}
	for _, t := range teacherClassTexts {
		if v, ok := row[t.Value].(*string); ok && v != nil {
			lines = append(lines, "- "+t.Label+": "+*v)
		}
	}
	return strings.Join(lines, "\n")
}

func (s *Service) SaveTeacherMonthly(ctx context.Context, a *iam.Access, in TeacherMonthlyInput) (string, error) {
	if err := guardWrite(a, KindTeacherMonthly); err != nil {
		return "", err
	}
	from, err := validMonth(in.Month)
	if err != nil {
		return "", err
	}
	if in.Intent != "" && in.Intent != "draft" && in.Intent != "submit" {
		return "", iam.Invalid("Thao tác lưu không hợp lệ.")
	}
	general := map[string]*string{}
	filled := false
	for _, f := range teacherGeneralFields {
		v := in.General[f.Value]
		if v != nil && strings.TrimSpace(*v) == "" {
			v = nil
		}
		if v != nil && runeLen(*v) > narrativeMax {
			return "", iam.Invalid(fmt.Sprintf("%s tối đa %d ký tự.", f.Label, narrativeMax))
		}
		general[f.Value] = v
		filled = filled || v != nil
	}
	// Chỉ nhận lớp giáo viên đang giữ trong tháng báo cáo.
	w, err := s.writer(ctx, a)
	if err != nil {
		return "", err
	}
	held, err := s.metrics.TeacherClasses(ctx, w, in.Month)
	if err != nil {
		return "", err
	}
	classes := map[string]map[string]any{}
	var blocks []string
	for _, c := range held {
		raw, ok := in.Classes[c.ID.String()]
		if !ok {
			continue
		}
		row, empty, err := cleanTeacherClass(c.Name, raw)
		if err != nil {
			return "", err
		}
		if empty {
			continue
		}
		classes[c.ID.String()] = row
		blocks = append(blocks, "Lớp "+c.Name+":\n"+teacherClassText(row))
	}
	if !filled && len(classes) == 0 {
		return "", iam.Invalid("Nhập ít nhất một mục của báo cáo.")
	}
	text := narrativeText(general, teacherGeneralFields)
	if text != "" {
		blocks = append([]string{text}, blocks...)
	}
	existing, err := s.structuredReport(ctx, a.UserID, KindTeacherMonthly, in.Month)
	if err != nil {
		return "", err
	}
	// Đã nộp thì "Lưu nháp" không kéo báo cáo về nháp.
	status := "submitted"
	if in.Intent == "draft" && (existing == nil || existing.Status != "submitted") {
		status = "draft"
	}
	title := "Báo cáo giảng dạy tháng — " + monthLabel(in.Month)
	if _, err := s.saveStructured(ctx, a, KindTeacherMonthly, in.Month, title, from,
		teacherMonthlyData{general, classes}, strings.TrimSpace(strings.Join(blocks, "\n\n")), status); err != nil {
		return "", err
	}
	if status == "draft" {
		return "Đã lưu nháp báo cáo " + strings.ToLower(monthLabel(in.Month)) + ".", nil
	}
	return "Đã nộp báo cáo giảng dạy " + strings.ToLower(monthLabel(in.Month)) + ".", nil
}

type TeacherAttendanceInput struct {
	Month string  `json:"month"`
	Note  *string `json:"note"`
}

// SaveTeacherAttendance: báo cáo chấm công tháng (ghi chú tùy chọn), mỗi tháng một bản.
func (s *Service) SaveTeacherAttendance(ctx context.Context, a *iam.Access, in TeacherAttendanceInput) (string, error) {
	if err := guardWrite(a, KindTeacherMonthly); err != nil {
		return "", err
	}
	from, err := validMonth(in.Month)
	if err != nil {
		return "", err
	}
	note := trimPtr(in.Note)
	if note != nil && runeLen(*note) > 5000 {
		return "", iam.Invalid("Ghi chú chấm công tối đa 5000 ký tự.")
	}
	title := "Báo cáo chấm công — " + monthLabel(in.Month)
	if _, err := s.saveStructured(ctx, a, KindTeacherAttendance, in.Month, title, from, attendanceData{note},
		strOr(note, "Không có ghi chú thêm."), "submitted"); err != nil {
		return "", err
	}
	return "Đã nộp báo cáo chấm công " + strings.ToLower(monthLabel(in.Month)) + ".", nil
}
