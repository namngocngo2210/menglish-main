package attendance

import (
	"context"
	"fmt"
	"math"
	"slices"
	"strconv"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/attendance/attendancedb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// Giờ dạy giáo viên (PayrollController::teacherTimesheets / manualTimesheet / storeTimesheet / confirmScheduledSession /
// reviewTimesheet / bulkReviewTimesheets / adjustTimesheet; TeacherTimesheet::matchSession / findDuplicate).

const (
	TsPending = "pending_review"
	TsValid   = "valid"
	TsInvalid = "invalid"

	SourceCheckin  = "checkin"
	SourceManual   = "manual"
	SourceSchedule = "schedule"

	timesheetPerPage = 20
	bulkMax          = 500
)

// TimesheetTypes: loại ca (TeacherTimesheet::type_label).
var TimesheetTypes = []Option{
	{"regular", "Ca dạy chính khóa"},
	{"sub", "Dạy thay (Sub)"},
	{"1on1", "Kèm phụ đạo 1-1"},
	{"grading", "Chấm bài thi Test"},
	{"workshop", "Workshop / Sự kiện"},
}

// TimesheetStatuses: trạng thái đối soát.
var TimesheetStatuses = []Option{
	{TsPending, "Chờ duyệt"},
	{TsValid, "Hợp lệ"},
	{TsInvalid, "Từ chối"},
}

var sourceLabels = map[string]string{SourceManual: "Chấm tay", SourceCheckin: "Check-in", SourceSchedule: "Xác nhận theo lịch"}

// teachingRoles: nhân sự giảng dạy + Học thuật + Quản lý cơ sở (PayrollController::teachingStaff).
var teachingRoles = []string{"teacher_fulltime", "teacher_parttime", "assistant", "academic_lead", "manager"}

// manualClassStatuses: lớp chấm tay được (đang học / sắp khai giảng / chờ xếp lịch).
var manualClassStatuses = []string{"active", "upcoming", "pending_schedule"}

var weekdays = []string{"Chủ nhật", "Thứ Hai", "Thứ Ba", "Thứ Tư", "Thứ Năm", "Thứ Sáu", "Thứ Bảy"}

// fmtHours: 1.50 → "1.5", 2.00 → "2".
func fmtHours(h float64) string {
	return strings.TrimSuffix(strings.TrimRight(strconv.FormatFloat(h, 'f', 2, 64), "0"), ".")
}

// hoursBetween: số giờ giữa hai mốc "HH:MM" (làm tròn 2 số).
func hoursBetween(in, out string) float64 {
	a, _ := parseHM(in)
	b, _ := parseHM(out)
	return math.Round(math.Abs(float64(b-a))/60*100) / 100
}

func limit(s *string, n int) *string {
	if s == nil || *s == "" {
		return nil
	}
	r := []rune(*s)
	if len(r) <= n {
		return s
	}
	return ptr(string(r[:n]) + "...")
}

// ───────────── Phạm vi lớp ─────────────

// classSet: lớp trong phạm vi chấm công (all = mọi lớp).
type classSet struct {
	all bool
	ids []uuid.UUID
}

func (c classSet) has(id *uuid.UUID) bool {
	return id != nil && (c.all || slices.Contains(c.ids, *id))
}

func (c classSet) list() []uuid.UUID {
	if c.ids == nil {
		return []uuid.UUID{}
	}
	return c.ids
}

// timesheetClasses: all → mọi lớp; branch → lớp thuộc chi nhánh của tôi + lớp của tôi; own → lớp của tôi.
func (s *Service) timesheetClasses(ctx context.Context, a *iam.Access) (classSet, error) {
	level := a.Level(scopeTimesheet)
	if level == iam.ScopeAll {
		return classSet{all: true}, nil
	}
	var ids []uuid.UUID
	if s.classes != nil {
		v, err := s.classes.VisibleClassIDs(ctx, a)
		if err != nil {
			return classSet{}, err
		}
		ids = append(ids, v...)
	}
	if level == iam.ScopeBranch {
		b, err := s.q.BranchClassIDs(ctx, a.BranchIDs())
		if err != nil {
			return classSet{}, err
		}
		for _, id := range b {
			if !slices.Contains(ids, id) {
				ids = append(ids, id)
			}
		}
	}
	if ids == nil {
		ids = []uuid.UUID{}
	}
	return classSet{ids: ids}, nil
}

// canReach: ca có lớp → lớp trong phạm vi; ca không gắn lớp → chỉ mức all.
func (s *Service) canReach(ctx context.Context, a *iam.Access, classID *uuid.UUID) (bool, error) {
	if classID == nil {
		return a.Level(scopeTimesheet) == iam.ScopeAll, nil
	}
	c, err := s.timesheetClasses(ctx, a)
	return c.has(classID), err
}

// ───────────── Màn Giờ dạy giáo viên ─────────────

type TimesheetFilter struct {
	Month    string
	Status   string
	Type     string
	Search   string
	BranchID string
	ClassID  string
	UserID   string
	Day      string
	Page     int
	PerPage  int
}

// TimesheetRow: một ca dạy.
type TimesheetRow struct {
	ID                uuid.UUID  `json:"id"`
	UserID            uuid.UUID  `json:"user_id"`
	TeachingDate      string     `json:"teaching_date"`
	ScheduledTime     *string    `json:"scheduled_time"`
	ShiftName         *string    `json:"shift_name"`
	Type              string     `json:"type"`
	TypeLabel         string     `json:"type_label"`
	Source            string     `json:"source"`
	SourceLabel       string     `json:"source_label"`
	ClassID           *uuid.UUID `json:"class_id"`
	ClassLabel        string     `json:"class_label"`
	TeacherName       string     `json:"teacher_name"`
	CheckinTime       *string    `json:"checkin_time"`
	CheckoutTime      *string    `json:"checkout_time"`
	DisplayCheckout   *string    `json:"display_checkout"`
	Adjusted          bool       `json:"adjusted"`
	Hours             string     `json:"hours"`
	PunchState        string     `json:"punch_state"`
	PunchStateLabel   string     `json:"punch_state_label"`
	AdjustmentReason  *string    `json:"adjustment_reason"`
	AdjustmentShort   *string    `json:"adjustment_short"`
	AdjusterName      *string    `json:"adjuster_name"`
	NotesShort        *string    `json:"notes_short"`
	Status            string     `json:"status"`
	StatusLabel       string     `json:"status_label"`
	RejectionShort    *string    `json:"rejection_short"`
	ReviewerName      *string    `json:"reviewer_name"`
	Locked            bool       `json:"locked"`
	RejectLabel       string     `json:"reject_label"`
	LateMinutes       int        `json:"late_minutes"`
	EarlyLeaveMinutes int        `json:"early_leave_minutes"`
	LateNotified      bool       `json:"late_notified"`
	LateLabel         *string    `json:"late_label"`
	EditLabel         string     `json:"edit_label"`
}

// punchState: TeacherTimesheet::punch_state.
func punchState(t attendancedb.HrTeacherTimesheet) (string, string) {
	switch {
	case t.AdjustedAt != nil:
		return "adjusted", "Chỉnh tay"
	case !t.CheckinTime.Valid:
		return "missing_in", "Thiếu vào"
	case !t.CheckoutTime.Valid && t.Source != SourceCheckin && t.Source != SourceSchedule:
		return "missing_out", "Thiếu ra"
	}
	return "full", "Đầy đủ"
}

// displayCheckout: ca check-in / theo lịch chưa có giờ ra → giờ kết thúc theo lịch.
func displayCheckout(t attendancedb.HrTeacherTimesheet) *string {
	if v := hm(t.CheckoutTime); v != nil {
		return v
	}
	if (t.Source == SourceCheckin || t.Source == SourceSchedule) && t.ScheduledTime != nil && strings.Contains(*t.ScheduledTime, "-") {
		return ptr(strings.TrimSpace(strings.SplitN(*t.ScheduledTime, "-", 2)[1]))
	}
	return nil
}

func lateLabel(late, early int32, notified bool) *string {
	if late+early <= 0 {
		return nil
	}
	var parts []string
	if late > 0 {
		parts = append(parts, fmt.Sprintf("Muộn %dp", late))
	}
	if early > 0 {
		parts = append(parts, fmt.Sprintf("Về sớm %dp", early))
	}
	suffix := " (không báo trước)"
	if notified {
		suffix = " (có báo trước)"
	}
	return ptr(strings.Join(parts, " · ") + suffix)
}

func timesheetOf(r attendancedb.TimesheetPageRow) attendancedb.HrTeacherTimesheet {
	return attendancedb.HrTeacherTimesheet{ID: r.ID, UserID: r.UserID, ClassID: r.ClassID, ClassSessionID: r.ClassSessionID,
		TeachingDate: r.TeachingDate, ScheduledTime: r.ScheduledTime, CheckinTime: r.CheckinTime, CheckoutTime: r.CheckoutTime,
		Hours: r.Hours, LateMinutes: r.LateMinutes, EarlyLeaveMinutes: r.EarlyLeaveMinutes, LateNotified: r.LateNotified,
		HourlyRate: r.HourlyRate, Type: r.Type, Source: r.Source, Status: r.Status, AdjustedAt: r.AdjustedAt,
		AdjustmentReason: r.AdjustmentReason, Notes: r.Notes, RejectionReason: r.RejectionReason}
}

// TeacherCard: thẻ giáo viên khi lọc theo một người.
type TeacherCard struct {
	ID         uuid.UUID `json:"id"`
	Name       string    `json:"name"`
	Code       string    `json:"employee_code"`
	BranchName *string   `json:"branch_name"`
}

// MissingSession: buổi được phân công đã diễn ra mà chưa có ca chấm công.
type MissingSession struct {
	ID         uuid.UUID `json:"id"`
	Date       string    `json:"date"`
	StartTime  string    `json:"start_time"`
	EndTime    string    `json:"end_time"`
	ClassLabel string    `json:"class_label"`
	// Mở form chấm tay điền sẵn
	ClassID  uuid.UUID `json:"class_id"`
	BranchID uuid.UUID `json:"branch_id"`
}

// ScheduleDay: ngày của bảng "Chấm công theo lịch".
type ScheduleDay struct {
	Date    string `json:"date"`
	Weekday string `json:"weekday"`
}

// ScheduleSession: một buổi học của ngày + trạng thái chấm công của GV dự kiến.
type ScheduleSession struct {
	ID          uuid.UUID `json:"id"`
	ClassName   string    `json:"class_name"`
	ClassCode   string    `json:"class_code"`
	StartTime   string    `json:"start_time"`
	EndTime     string    `json:"end_time"`
	TeacherName *string   `json:"teacher_name"`
	State       string    `json:"state"`
	StateNote   *string   `json:"state_note"`
	SourceLabel *string   `json:"source_label"`
	CanConfirm  bool      `json:"can_confirm"`
}

type TimesheetSummaryCounts struct {
	Pending int `json:"pending_review"`
	Valid   int `json:"valid"`
	Invalid int `json:"invalid"`
}

type TeacherTimesheetsPage struct {
	CanViewAll       bool                   `json:"can_view_all"`
	CanReview        bool                   `json:"can_review"`
	CanAdjust        bool                   `json:"can_adjust"`
	Month            string                 `json:"month"`
	MonthLabel       string                 `json:"month_label"`
	Period           *PayrollPeriod         `json:"period"`
	PeriodLocked     bool                   `json:"period_locked"`
	Summary          TimesheetSummaryCounts `json:"summary"`
	Timesheets       []TimesheetRow         `json:"timesheets"`
	Meta             Meta                   `json:"meta"`
	Teacher          *TeacherCard           `json:"teacher"`
	MissingSessions  []MissingSession       `json:"missing_sessions"`
	ScheduleDay      *ScheduleDay           `json:"schedule_day"`
	ScheduleSessions []ScheduleSession      `json:"schedule_sessions"`
	SubPendingCount  int                    `json:"sub_pending_count"`
	FilterBranches   []Option               `json:"filter_branches"`
	FilterClasses    []Option               `json:"filter_classes"`
	FilterTeachers   []Option               `json:"filter_teachers"`
	Types            []Option               `json:"types"`
	Statuses         []Option               `json:"statuses"`
}

func optionOf(opts []Option, v string) *string {
	v = strings.TrimSpace(v)
	if typeLabelOf(opts, v) == "" {
		return nil
	}
	return &v
}

// TeacherTimesheets: ca dạy trong tháng. Người có attendance_staff.view xem ca của lớp trong phạm vi; người chỉ có
// payroll.view_own xem ca của chính mình.
func (s *Service) TeacherTimesheets(ctx context.Context, a *iam.Access, f TimesheetFilter) (*TeacherTimesheetsPage, error) {
	canViewAll := a.Can(PermTimesheetView)
	if !canViewAll && !a.Can(PermPayrollOwn) {
		return nil, iam.ErrForbidden
	}
	now := s.now()
	today := dayOf(now)
	start := parseMonth(f.Month, now)
	end := monthEnd(start)
	status, typ := optionOf(TimesheetStatuses, f.Status), optionOf(TimesheetTypes, f.Type)
	classID, _ := optUUID(f.ClassID)
	var branchID, teacherID *uuid.UUID
	if canViewAll {
		branchID, _ = optUUID(f.BranchID)
		teacherID, _ = optUUID(f.UserID)
	} else {
		teacherID = &a.UserID
	}
	scope := classSet{all: true}
	if canViewAll {
		var err error
		if scope, err = s.timesheetClasses(ctx, a); err != nil {
			return nil, err
		}
	}
	page, per, lim, off := pageArgs(f.Page, f.PerPage, timesheetPerPage)
	q := likePattern(f.Search)
	rows, err := s.q.TimesheetPage(ctx, attendancedb.TimesheetPageParams{FromDate: sqlDate(start), ToDate: sqlDate(end),
		AllClasses: scope.all, ClassIds: scope.list(), UserID: teacherID, BranchID: branchID, ClassID: classID, Status: status,
		Type: typ, Q: q, Lim: lim, Off: off})
	if err != nil {
		return nil, err
	}
	sum, err := s.q.TimesheetSummary(ctx, attendancedb.TimesheetSummaryParams{FromDate: sqlDate(start), ToDate: sqlDate(end),
		AllClasses: scope.all, ClassIds: scope.list(), UserID: teacherID, BranchID: branchID, ClassID: classID, Status: status,
		Type: typ, Q: q})
	if err != nil {
		return nil, err
	}
	l, err := s.locks(ctx)
	if err != nil {
		return nil, err
	}
	period, err := s.lock.Period(ctx, start)
	if err != nil {
		return nil, err
	}
	periodLocked := (period != nil && period.Locked) || l.has(start)
	out := &TeacherTimesheetsPage{CanViewAll: canViewAll, CanReview: canViewAll, CanAdjust: a.Can(PermManualRecord),
		Month: monthKey(start), MonthLabel: start.Format("01/2006"), Period: period, PeriodLocked: periodLocked,
		Timesheets: []TimesheetRow{}, Meta: Meta{Page: page, PerPage: per}, MissingSessions: []MissingSession{},
		ScheduleSessions: []ScheduleSession{}, FilterBranches: []Option{}, FilterClasses: []Option{}, FilterTeachers: []Option{},
		Types: TimesheetTypes, Statuses: TimesheetStatuses}
	for _, r := range sum {
		switch r.Status {
		case TsPending:
			out.Summary.Pending = int(r.Total)
		case TsValid:
			out.Summary.Valid = int(r.Total)
		case TsInvalid:
			out.Summary.Invalid = int(r.Total)
		}
	}
	for _, r := range rows {
		out.Meta.Total = r.Total
		t := timesheetOf(r)
		day := dateOnly(r.TeachingDate)
		state, stateLabel := punchState(t)
		classLabel := r.ClassCode
		if classLabel == "" {
			classLabel = r.ClassName
		}
		if classLabel == "" {
			classLabel = "—"
		}
		row := TimesheetRow{ID: r.ID, UserID: r.UserID, TeachingDate: isoDate(day), ScheduledTime: r.ScheduledTime,
			ShiftName: shiftLabel(r.ShiftName), Type: r.Type, TypeLabel: typeLabelOf(TimesheetTypes, r.Type), Source: r.Source,
			SourceLabel: sourceLabels[r.Source], ClassID: r.ClassID, ClassLabel: classLabel, TeacherName: r.TeacherName,
			CheckinTime: hm(r.CheckinTime), CheckoutTime: hm(r.CheckoutTime), DisplayCheckout: displayCheckout(t),
			Adjusted: r.AdjustedAt != nil, Hours: fmtHours(r.Hours), PunchState: state, PunchStateLabel: stateLabel,
			AdjustmentReason: r.AdjustmentReason, AdjusterName: r.AdjusterName, Status: r.Status,
			StatusLabel: typeLabelOf(TimesheetStatuses, r.Status), ReviewerName: r.ReviewerName,
			Locked: periodLocked || l.has(day), RejectLabel: r.TeacherName + " — " + fmtDate(day),
			LateMinutes: int(r.LateMinutes), EarlyLeaveMinutes: int(r.EarlyLeaveMinutes), LateNotified: r.LateNotified,
			LateLabel: lateLabel(r.LateMinutes, r.EarlyLeaveMinutes, r.LateNotified),
			EditLabel: r.TeacherName + " — " + r.ClassCode + " — " + fmtDate(day)}
		if r.AdjustedAt != nil {
			row.AdjustmentShort = limit(r.AdjustmentReason, 50)
		}
		if r.Source == SourceManual {
			row.NotesShort = limit(r.Notes, 50)
		}
		if r.Status == TsInvalid {
			row.RejectionShort = limit(r.RejectionReason, 60)
		}
		out.Timesheets = append(out.Timesheets, row)
	}
	// Thẻ giáo viên chỉ hiện nhân sự trong phạm vi (Quản lý cơ sở: nhân sự chi nhánh mình) — ?user_id= của chi nhánh
	// khác không lộ tên / mã; buổi thiếu chấm công chỉ tính lớp trong phạm vi.
	staffScope, err := iam.StaffInScope(ctx, s.pool, a, scopeTimesheet)
	if err != nil {
		return nil, err
	}
	if teacherID != nil && staffScope.Has(*teacherID) {
		c, err := s.q.StaffContext(ctx, *teacherID)
		if err != nil && !isNoRows(err) {
			return nil, err
		}
		if err == nil {
			out.Teacher = &TeacherCard{ID: c.ID, Name: c.FullName, Code: c.Code, BranchName: c.BranchName}
			upTo := end
			if today.Before(upTo) {
				upTo = today
			}
			ms, err := s.q.MissingSessions(ctx, attendancedb.MissingSessionsParams{UserID: teacherID, FromDate: sqlDate(start),
				ToDate: sqlDate(upTo), ClassID: classID, AllClasses: scope.all, ClassIds: scope.list()})
			if err != nil {
				return nil, err
			}
			for _, m := range ms {
				label := m.ClassCode
				if label == "" {
					label = m.ClassName
				}
				out.MissingSessions = append(out.MissingSessions, MissingSession{ID: m.ID, Date: isoDate(m.Date),
					StartTime: hmText(m.StartTime), EndTime: hmText(m.EndTime), ClassLabel: label, ClassID: m.ClassID, BranchID: m.BranchID})
			}
		}
	}
	if !canViewAll {
		return out, nil
	}
	day := today
	if d, ok := parseDate(f.Day); ok {
		day = d
	}
	out.ScheduleDay = &ScheduleDay{Date: isoDate(day), Weekday: weekdays[day.Weekday()]}
	ss, err := s.q.ScheduleSessions(ctx, attendancedb.ScheduleSessionsParams{Day: sqlDate(day), AllClasses: scope.all,
		ClassIds: scope.list(), BranchID: branchID})
	if err != nil {
		return nil, err
	}
	for _, r := range ss {
		item := ScheduleSession{ID: r.ID, ClassName: r.ClassName, ClassCode: r.ClassCode, StartTime: hmText(r.StartTime),
			EndTime: hmText(r.EndTime), TeacherName: r.TeacherName, State: "none"}
		valid := false
		if r.TsID != nil {
			src := sourceLabels[deref(r.TsSource)]
			item.SourceLabel = &src
			switch {
			case deref(r.TsStatus) == TsValid:
				item.State, valid = "valid", true
			case deref(r.TsSource) == SourceCheckin:
				item.State = "checkin"
			default:
				item.State = "pending"
			}
			if r.TsReviewer != nil {
				item.StateNote = ptr("Đã duyệt bởi " + *r.TsReviewer)
			} else {
				item.StateNote = &src
			}
		}
		sday := dateOnly(r.Date)
		item.CanConfirm = !valid && !sday.After(today) && !l.has(sday)
		out.ScheduleSessions = append(out.ScheduleSessions, item)
	}
	n, err := s.q.SubPendingCount(ctx, attendancedb.SubPendingCountParams{AllClasses: scope.all, ClassIds: scope.list()})
	if err != nil {
		return nil, err
	}
	out.SubPendingCount = int(n)
	classes, err := s.q.ScopeClasses(ctx, attendancedb.ScopeClassesParams{AllClasses: scope.all, ClassIds: scope.list(), Statuses: []string{}})
	if err != nil {
		return nil, err
	}
	seen := map[uuid.UUID]bool{}
	for _, c := range classes {
		out.FilterClasses = append(out.FilterClasses, Option{c.ID.String(), c.Name + " (" + c.Code + ")"})
		if !seen[c.BranchID] {
			seen[c.BranchID] = true
			out.FilterBranches = append(out.FilterBranches, Option{c.BranchID.String(), c.BranchName})
		}
	}
	slices.SortStableFunc(out.FilterBranches, func(x, y Option) int { return strings.Compare(x.Label, y.Label) })
	staff, err := s.teachingStaff(ctx, a)
	if err != nil {
		return nil, err
	}
	for _, t := range staff {
		out.FilterTeachers = append(out.FilterTeachers, Option{t.ID.String(), t.FullName})
	}
	return out, nil
}

// teachingStaff: nhân sự giảng dạy đang hoạt động trong phạm vi người xem (PayrollController::teachingStaff → staffInScope):
// ô chọn chấm tay, bộ lọc Giáo viên.
func (s *Service) teachingStaff(ctx context.Context, a *iam.Access) ([]attendancedb.TeachingStaffRow, error) {
	staff, err := s.q.TeachingStaff(ctx, teachingRoles)
	if err != nil {
		return nil, err
	}
	set, err := iam.StaffInScope(ctx, s.pool, a, scopeTimesheet)
	if err != nil {
		return nil, err
	}
	return iam.FilterUsers(set, staff, func(r attendancedb.TeachingStaffRow) uuid.UUID { return r.ID }), nil
}

// ───────────── Chấm công tay ─────────────

// ManualTeacher: nhân sự trong ô chọn của form chấm tay.
type ManualTeacher struct {
	ID     uuid.UUID `json:"id"`
	Label  string    `json:"label"`
	Search string    `json:"search"`
}

// ManualClass: lớp trong ô chọn (lọc theo chi nhánh).
type ManualClass struct {
	ID     uuid.UUID `json:"id"`
	Label  string    `json:"label"`
	Branch uuid.UUID `json:"branch"`
}

type LockedRangeOut struct {
	From  string `json:"from"`
	To    string `json:"to"`
	Title string `json:"title"`
}

type ManualForm struct {
	Teachers      []ManualTeacher  `json:"teachers"`
	Classes       []ManualClass    `json:"classes"`
	Branches      []Option         `json:"branches"`
	LockedRanges  []LockedRangeOut `json:"locked_ranges"`
	DefaultBranch string           `json:"default_branch"`
	Today         string           `json:"today"`
	Types         []Option         `json:"types"`
}

// Manual: dữ liệu form chấm tay — lớp đang học / sắp mở trong phạm vi, nhân sự giảng dạy, kỳ lương đã khóa.
func (s *Service) Manual(ctx context.Context, a *iam.Access) (*ManualForm, error) {
	scope, err := s.timesheetClasses(ctx, a)
	if err != nil {
		return nil, err
	}
	classes, err := s.q.ScopeClasses(ctx, attendancedb.ScopeClassesParams{AllClasses: scope.all, ClassIds: scope.list(),
		Statuses: manualClassStatuses})
	if err != nil {
		return nil, err
	}
	out := &ManualForm{Teachers: []ManualTeacher{}, Classes: []ManualClass{}, Branches: []Option{}, LockedRanges: []LockedRangeOut{},
		Today: isoDate(dayOf(s.now())), Types: TimesheetTypes}
	seen := map[uuid.UUID]bool{}
	for _, c := range classes {
		out.Classes = append(out.Classes, ManualClass{ID: c.ID, Label: c.Name + " (" + c.Code + ")", Branch: c.BranchID})
		if !seen[c.BranchID] {
			seen[c.BranchID] = true
			out.Branches = append(out.Branches, Option{c.BranchID.String(), c.BranchName})
		}
	}
	slices.SortStableFunc(out.Branches, func(x, y Option) int { return strings.Compare(x.Label, y.Label) })
	staff, err := s.teachingStaff(ctx, a)
	if err != nil {
		return nil, err
	}
	for _, t := range staff {
		label := t.FullName
		if t.Code != "" {
			label += " — " + t.Code
		}
		roles := t.RoleLabels
		if roles == "" {
			roles = "GV/TA"
		}
		out.Teachers = append(out.Teachers, ManualTeacher{ID: t.ID, Label: label + " (" + roles + ")",
			Search: strings.ToLower(t.FullName + " " + t.Code + " " + t.Email)})
	}
	l, err := s.locks(ctx)
	if err != nil {
		return nil, err
	}
	for _, r := range l {
		out.LockedRanges = append(out.LockedRanges, LockedRangeOut{From: isoDate(r.From), To: isoDate(r.To), Title: r.Title})
	}
	if len(out.Branches) == 1 {
		out.DefaultBranch = out.Branches[0].Value
	} else if c, err := s.q.StaffContext(ctx, a.UserID); err == nil && c.BranchID != nil {
		if typeLabelOf(out.Branches, c.BranchID.String()) != "" {
			out.DefaultBranch = c.BranchID.String()
		}
	}
	return out, nil
}

// ManualInput: một ca chấm tay.
type ManualInput struct {
	UserID            string   `json:"user_id"`
	BranchID          string   `json:"branch_id"`
	ClassID           string   `json:"class_id"`
	TeachingDate      string   `json:"teaching_date"`
	TimeIn            string   `json:"time_in"`
	TimeOut           string   `json:"time_out"`
	HourlyRate        *float64 `json:"hourly_rate"`
	LateMinutes       *int     `json:"late_minutes"`
	EarlyLeaveMinutes *int     `json:"early_leave_minutes"`
	LateNotified      bool     `json:"late_notified"`
	Type              string   `json:"type"`
	Notes             string   `json:"notes"`
}

func checkMinutes(v *int, label string) error {
	if v != nil && (*v < 0 || *v > 600) {
		return iam.Invalid(label + " phải từ 0 đến 600 phút.")
	}
	return nil
}

// checkTimes: giờ vào / ra bắt buộc, đúng định dạng, ra sau vào, ca ít nhất 30 phút. Trả số giờ.
func checkTimes(in, out string) (float64, error) {
	in, out = strings.TrimSpace(in), strings.TrimSpace(out)
	if in == "" {
		return 0, iam.Invalid("Vui lòng nhập giờ vào.")
	}
	if out == "" {
		return 0, iam.Invalid("Vui lòng nhập giờ ra.")
	}
	a, ok1 := parseHM(in)
	b, ok2 := parseHM(out)
	if !ok1 {
		return 0, iam.Invalid("Giờ vào không hợp lệ (định dạng HH:MM).")
	}
	if !ok2 {
		return 0, iam.Invalid("Giờ ra không hợp lệ (định dạng HH:MM).")
	}
	if b <= a {
		return 0, iam.Invalid("Giờ ra phải sau giờ vào.")
	}
	h := hoursBetween(in, out)
	if h < 0.5 {
		return 0, iam.Invalid("Ca dạy phải kéo dài ít nhất 30 phút.")
	}
	return h, nil
}

// matchSession: gắn ca chấm tay vào buổi học thật của lớp trong ngày — buổi người đó được phân công, không thì buổi trùng
// giờ, không thì buổi duy nhất của ngày.
func matchSession(sessions []attendancedb.ClassSessionsOnRow, user uuid.UUID, in, out string) *attendancedb.ClassSessionsOnRow {
	if len(sessions) == 0 {
		return nil
	}
	for i, s := range sessions {
		if (s.TeacherID != nil && *s.TeacherID == user) || (s.AssistantID != nil && *s.AssistantID == user) {
			return &sessions[i]
		}
	}
	if in != "" && out != "" {
		for i, s := range sessions {
			if hmText(s.StartTime) < out && hmText(s.EndTime) > in {
				return &sessions[i]
			}
		}
	}
	if len(sessions) == 1 {
		return &sessions[0]
	}
	return nil
}

func duplicateMessage(d attendancedb.HrTeacherTimesheet, day time.Time) string {
	how := "check-in"
	if d.Source == SourceManual {
		how = "chấm tay"
	}
	return "Nhân sự đã được chấm công (" + how + ") cho lớp này ngày " + fmtDate(day) +
		" — không thể tính công 2 lần. Nếu bản ghi cũ sai, hãy từ chối bản ghi đó trước."
}

// StoreManual: chấm công tay (bắt buộc lý do), tự gắn buổi học thật, chặn chấm trùng; ca chờ duyệt.
func (s *Service) StoreManual(ctx context.Context, a *iam.Access, in ManualInput) (string, error) {
	userID, err := uuid.Parse(strings.TrimSpace(in.UserID))
	if err != nil {
		return "", iam.Invalid("Chọn nhân sự được chấm công.")
	}
	classID, err := uuid.Parse(strings.TrimSpace(in.ClassID))
	if err != nil {
		return "", iam.Invalid("Chọn lớp.")
	}
	branchID, err := optUUID(in.BranchID)
	if err != nil {
		return "", iam.Invalid("Chi nhánh không hợp lệ.")
	}
	if strings.TrimSpace(in.TeachingDate) == "" {
		return "", iam.Invalid("Chọn ngày dạy.")
	}
	day, ok := parseDate(in.TeachingDate)
	if !ok {
		return "", iam.Invalid("Ngày dạy không hợp lệ.")
	}
	if day.After(dayOf(s.now())) {
		return "", iam.Invalid("Không chấm công tay trước cho ngày chưa diễn ra.")
	}
	hours, err := checkTimes(in.TimeIn, in.TimeOut)
	if err != nil {
		return "", err
	}
	var rate *int64
	if in.HourlyRate != nil {
		if *in.HourlyRate < 1000 || math.IsNaN(*in.HourlyRate) || *in.HourlyRate > 1e12 {
			return "", iam.Invalid("Đơn giá giờ tối thiểu 1.000đ.")
		}
		rate = ptr(int64(math.Round(*in.HourlyRate)))
	}
	if err := checkMinutes(in.LateMinutes, "Số phút đi muộn"); err != nil {
		return "", err
	}
	if err := checkMinutes(in.EarlyLeaveMinutes, "Số phút về sớm"); err != nil {
		return "", err
	}
	if typeLabelOf(TimesheetTypes, in.Type) == "" {
		return "", iam.Invalid("Chọn loại ca.")
	}
	notes := strings.TrimSpace(in.Notes)
	if notes == "" {
		return "", iam.Invalid("Chấm công tay bắt buộc ghi lý do.")
	}
	if utf8.RuneCountInString(notes) < 5 {
		return "", iam.Invalid("Lý do chấm công tay cần ít nhất 5 ký tự.")
	}
	if utf8.RuneCountInString(notes) > 500 {
		return "", iam.Invalid("Lý do chấm công tay tối đa 500 ký tự.")
	}
	teacher, err := s.q.StaffContext(ctx, userID)
	if isNoRows(err) {
		return "", iam.Invalid("Nhân sự không tồn tại.")
	}
	if err != nil {
		return "", err
	}
	class, err := s.q.GetClass(ctx, classID)
	if isNoRows(err) {
		return "", iam.Invalid("Lớp không tồn tại.")
	}
	if err != nil {
		return "", err
	}
	scope, err := s.timesheetClasses(ctx, a)
	if err != nil {
		return "", err
	}
	if !scope.has(&classID) {
		return "", ErrOutOfScope
	}
	if branchID != nil && *branchID != class.BranchID {
		return "", iam.Invalid("Lớp đã chọn không thuộc chi nhánh đã chọn.")
	}
	if err := s.ensureOpen(ctx, day); err != nil {
		return "", err
	}
	timeIn, timeOut := strings.TrimSpace(in.TimeIn), strings.TrimSpace(in.TimeOut)
	late, early := 0, 0
	if in.LateMinutes != nil {
		late = *in.LateMinutes
	}
	if in.EarlyLeaveMinutes != nil {
		early = *in.EarlyLeaveMinutes
	}
	var session *attendancedb.ClassSessionsOnRow
	err = s.inTx(ctx, func(tx pgx.Tx, q *attendancedb.Queries) error {
		sessions, err := q.ClassSessionsOn(ctx, attendancedb.ClassSessionsOnParams{ClassID: classID, Day: sqlDate(day)})
		if err != nil {
			return err
		}
		session = matchSession(sessions, userID, timeIn, timeOut)
		var sessionID *uuid.UUID
		var scheduled *string
		if session != nil {
			sessionID = &session.ID
			scheduled = ptr(hmText(session.StartTime) + "-" + hmText(session.EndTime))
		}
		dup, err := q.FindDuplicate(ctx, attendancedb.FindDuplicateParams{UserID: userID, SessionID: sessionID, ClassID: &classID, Day: sqlDate(day)})
		if err == nil {
			return iam.Invalid(duplicateMessage(dup, day))
		}
		if !isNoRows(err) {
			return err
		}
		var ts attendancedb.HrTeacherTimesheet
		rejected := false
		if sessionID != nil {
			old, err := q.TimesheetBySession(ctx, attendancedb.TimesheetBySessionParams{UserID: userID, SessionID: sessionID})
			if err != nil && !isNoRows(err) {
				return err
			}
			if err == nil {
				rejected = true
				ts, err = q.OverwriteTimesheet(ctx, attendancedb.OverwriteTimesheetParams{ID: old.ID, ClassID: &classID,
					ClassSessionID: sessionID, TeachingDate: sqlDate(day), ScheduledTime: scheduled, CheckinTime: &timeIn,
					CheckoutTime: &timeOut, Hours: hours, LateMinutes: int32(late), EarlyLeaveMinutes: int32(early),
					LateNotified: in.LateNotified, HourlyRate: rate, Type: in.Type, Source: SourceManual, Status: TsPending, Notes: &notes})
				if err != nil {
					return err
				}
			}
		}
		if !rejected {
			ts, err = q.InsertTimesheet(ctx, attendancedb.InsertTimesheetParams{UserID: userID, ClassID: &classID,
				ClassSessionID: sessionID, TeachingDate: sqlDate(day), ScheduledTime: scheduled, CheckinTime: &timeIn,
				CheckoutTime: &timeOut, Hours: hours, LateMinutes: int32(late), EarlyLeaveMinutes: int32(early),
				LateNotified: in.LateNotified, HourlyRate: rate, Type: in.Type, Source: SourceManual, Status: TsPending,
				Notes: &notes, CreatedBy: &a.UserID})
			if err != nil {
				return err
			}
		}
		return s.record(ctx, tx, audit.Entry{ActorID: &a.UserID, Event: audit.EventCreated, Action: "teacher_timesheet.manual",
			SubjectType: "teacher_timesheet", SubjectID: ts.ID.String(), SubjectLabel: teacher.FullName + " · " + class.Code + " · " + fmtDate(day),
			BranchID: &class.BranchID,
			Description: "Chấm công tay " + teacher.FullName + " lớp " + class.Code + " ngày " + fmtDate(day) +
				" (" + timeIn + "–" + timeOut + ")",
			Properties: map[string]any{"hours": hours, "type": in.Type, "notes": notes, "overwrite_rejected": rejected}})
	})
	if err != nil {
		return "", err
	}
	s.log.Info("manual timesheet", "user", userID, "class", classID, "by", a.UserID)
	where := ", không có buổi học trên lịch"
	if session != nil {
		where = ", gắn buổi học ngày " + fmtDate(day)
	}
	return "Đã ghi nhận chấm công tay (" + fmtHours(hours) + "h" + where + ") — chờ duyệt.", nil
}

// ───────────── Xác nhận theo lịch, duyệt, chốt, chỉnh tay ─────────────

// ConfirmSession: "Xác nhận" buổi học trên lịch — duyệt ca đã có của GV dự kiến, chưa có thì tạo ca "Xác nhận theo lịch"
// hợp lệ với giờ theo lịch.
func (s *Service) ConfirmSession(ctx context.Context, a *iam.Access, sessionID uuid.UUID) (string, error) {
	sess, err := s.q.GetSession(ctx, sessionID)
	if isNoRows(err) {
		return "", ErrSessionMissing
	}
	if err != nil {
		return "", err
	}
	scope, err := s.timesheetClasses(ctx, a)
	if err != nil {
		return "", err
	}
	if !scope.has(&sess.ClassID) {
		return "", iam.ErrForbidden
	}
	if sess.Status == "cancelled" || sess.TeacherID == nil {
		return "", iam.Invalid("Buổi học đã hủy hoặc chưa phân công giáo viên.")
	}
	day := dateOnly(sess.Date)
	if day.After(dayOf(s.now())) {
		return "", iam.Invalid("Chưa tới ngày học — không thể xác nhận chấm công trước.")
	}
	if err := s.ensureOpen(ctx, day); err != nil {
		return "", err
	}
	teacher := *sess.TeacherID
	err = s.inTx(ctx, func(tx pgx.Tx, q *attendancedb.Queries) error {
		existing, err := q.TimesheetBySession(ctx, attendancedb.TimesheetBySessionParams{UserID: teacher, SessionID: &sessionID})
		found := err == nil
		if err != nil && !isNoRows(err) {
			return err
		}
		if !found {
			existing, err = q.FindDuplicate(ctx, attendancedb.FindDuplicateParams{UserID: teacher, SessionID: &sessionID,
				ClassID: &sess.ClassID, Day: sqlDate(day)})
			found = err == nil
			if err != nil && !isNoRows(err) {
				return err
			}
		}
		var id uuid.UUID
		if found && existing.Status != TsInvalid {
			id = existing.ID
			if err := q.ReviewTimesheet(ctx, attendancedb.ReviewTimesheetParams{ID: existing.ID, Status: TsValid, ReviewedBy: &a.UserID}); err != nil {
				return err
			}
		} else {
			start, end := hmText(sess.StartTime), hmText(sess.EndTime)
			hours := math.Max(0.5, hoursBetween(start, end))
			typ := "regular"
			if sess.Type == "support" {
				typ = "1on1"
			}
			now := s.now()
			note := "Học vụ xác nhận theo lịch buổi học"
			scheduled := start + "-" + end
			var ts attendancedb.HrTeacherTimesheet
			if found {
				ts, err = q.OverwriteTimesheet(ctx, attendancedb.OverwriteTimesheetParams{ID: existing.ID, ClassID: &sess.ClassID,
					ClassSessionID: &sessionID, TeachingDate: sqlDate(day), ScheduledTime: &scheduled, CheckinTime: &start,
					CheckoutTime: &end, Hours: hours, Type: typ, Source: SourceSchedule, Status: TsValid, ReviewedBy: &a.UserID,
					ReviewedAt: &now, Notes: &note})
			} else {
				ts, err = q.InsertTimesheet(ctx, attendancedb.InsertTimesheetParams{UserID: teacher, ClassID: &sess.ClassID,
					ClassSessionID: &sessionID, TeachingDate: sqlDate(day), ScheduledTime: &scheduled, CheckinTime: &start,
					CheckoutTime: &end, Hours: hours, Type: typ, Source: SourceSchedule, Status: TsValid, ReviewedBy: &a.UserID,
					ReviewedAt: &now, Notes: &note, CreatedBy: &a.UserID})
			}
			if err != nil {
				return err
			}
			id = ts.ID
		}
		return s.record(ctx, tx, audit.Entry{ActorID: &a.UserID, Event: "confirmed", Action: "teacher_timesheet.confirm",
			SubjectType: "teacher_timesheet", SubjectID: id.String(), SubjectLabel: sess.ClassCode + " · " + fmtDate(day),
			BranchID: &sess.BranchID, Description: "Xác nhận chấm công theo lịch buổi " + sess.ClassCode + " ngày " + fmtDate(day)})
	})
	if err != nil {
		return "", err
	}
	return "Đã xác nhận chấm công buổi " + sess.ClassCode + " ngày " + fmtDate(day) + ".", nil
}

// ReviewInput: xác nhận hợp lệ / từ chối một ca.
type ReviewInput struct {
	Decision        string `json:"decision"`
	RejectionReason string `json:"rejection_reason"`
}

// Review: đối soát một ca (hợp lệ / từ chối có lý do).
func (s *Service) Review(ctx context.Context, a *iam.Access, id uuid.UUID, in ReviewInput) (string, error) {
	if in.Decision != TsValid && in.Decision != TsInvalid {
		return "", iam.Invalid("Chọn kết quả đối soát.")
	}
	reason := strings.TrimSpace(in.RejectionReason)
	if in.Decision == TsInvalid && reason == "" {
		return "", iam.Invalid("Vui lòng nhập lý do từ chối ca dạy.")
	}
	if utf8.RuneCountInString(reason) > 1000 {
		return "", iam.Invalid("Lý do từ chối tối đa 1000 ký tự.")
	}
	err := s.inTx(ctx, func(tx pgx.Tx, q *attendancedb.Queries) error {
		t, err := q.GetTimesheetLock(ctx, id)
		if isNoRows(err) {
			return ErrTimesheetMissing
		}
		if err != nil {
			return err
		}
		ok, err := s.canReach(ctx, a, t.ClassID)
		if err != nil {
			return err
		}
		if !ok {
			return iam.ErrForbidden
		}
		day := dateOnly(t.TeachingDate)
		if err := s.ensureOpen(ctx, day); err != nil {
			return err
		}
		var rr *string
		if in.Decision == TsInvalid {
			rr = &reason
		}
		if err := q.ReviewTimesheet(ctx, attendancedb.ReviewTimesheetParams{ID: id, Status: in.Decision, ReviewedBy: &a.UserID, RejectionReason: rr}); err != nil {
			return err
		}
		verb := "Xác nhận hợp lệ"
		if in.Decision == TsInvalid {
			verb = "Từ chối"
		}
		return s.record(ctx, tx, audit.Entry{ActorID: &a.UserID, Event: "reviewed", Action: "teacher_timesheet.review",
			SubjectType: "teacher_timesheet", SubjectID: id.String(), SubjectLabel: t.TeacherName + " · " + fmtDate(day),
			Description: verb + " ca dạy của " + t.TeacherName + " lớp " + t.ClassCode + " ngày " + fmtDate(day),
			Changes:     audit.Changes{"status": {Old: t.Status, New: in.Decision}}, Properties: map[string]any{"reason": reason}})
	})
	if err != nil {
		return "", err
	}
	if in.Decision == TsValid {
		return "Đã xác nhận ca dạy hợp lệ.", nil
	}
	return "Đã từ chối ca dạy.", nil
}

// BulkReview: "Chốt bảng công" — duyệt hàng loạt ca đang chờ đã chọn; bỏ qua ca không còn chờ duyệt / thuộc kỳ đã khóa.
func (s *Service) BulkReview(ctx context.Context, a *iam.Access, ids []uuid.UUID) (string, error) {
	if len(ids) == 0 {
		return "", iam.Invalid("Chọn ít nhất một ca dạy để chốt.")
	}
	if len(ids) > bulkMax {
		return "", iam.Invalid("Chốt tối đa 500 ca dạy mỗi lần.")
	}
	scope, err := s.timesheetClasses(ctx, a)
	if err != nil {
		return "", err
	}
	l, err := s.locks(ctx)
	if err != nil {
		return "", err
	}
	all := a.Level(scopeTimesheet) == iam.ScopeAll
	approved, skipped := 0, 0
	err = s.inTx(ctx, func(tx pgx.Tx, q *attendancedb.Queries) error {
		rows, err := q.TimesheetsByIDs(ctx, ids)
		if err != nil {
			return err
		}
		var done []string
		for _, t := range rows {
			if (t.ClassID == nil && !all) || (t.ClassID != nil && !scope.has(t.ClassID)) {
				continue
			}
			if t.Status != TsPending || l.has(dateOnly(t.TeachingDate)) {
				skipped++
				continue
			}
			if err := q.ReviewTimesheet(ctx, attendancedb.ReviewTimesheetParams{ID: t.ID, Status: TsValid, ReviewedBy: &a.UserID}); err != nil {
				return err
			}
			approved++
			done = append(done, t.ID.String())
		}
		if approved == 0 {
			return nil
		}
		return s.record(ctx, tx, audit.Entry{ActorID: &a.UserID, Event: "reviewed", Action: "teacher_timesheet.bulk_review",
			SubjectType: "teacher_timesheet", Description: fmt.Sprintf("Chốt %d ca dạy vào bảng công", approved),
			Properties: map[string]any{"ids": done, "skipped": skipped}})
	})
	if err != nil {
		return "", err
	}
	msg := fmt.Sprintf("Đã chốt %d ca dạy vào bảng công", approved)
	if skipped > 0 {
		return msg + fmt.Sprintf(" — bỏ qua %d ca (không còn chờ duyệt hoặc thuộc kỳ lương đã khóa).", skipped), nil
	}
	return msg + ".", nil
}

// AdjustInput: chỉnh tay giờ vào / ra của một ca.
type AdjustInput struct {
	TimeIn            string `json:"time_in"`
	TimeOut           string `json:"time_out"`
	AdjustmentReason  string `json:"adjustment_reason"`
	LateMinutes       *int   `json:"late_minutes"`
	EarlyLeaveMinutes *int   `json:"early_leave_minutes"`
	LateNotified      *bool  `json:"late_notified"`
}

// Adjust: "Chỉnh tay bổ sung" — sửa giờ vào / ra (bắt buộc lý do), ca quay về Chờ duyệt để đối soát lại.
func (s *Service) Adjust(ctx context.Context, a *iam.Access, id uuid.UUID, in AdjustInput) (string, error) {
	var hours float64
	err := s.inTx(ctx, func(tx pgx.Tx, q *attendancedb.Queries) error {
		t, err := q.GetTimesheetLock(ctx, id)
		if isNoRows(err) {
			return ErrTimesheetMissing
		}
		if err != nil {
			return err
		}
		scope, err := s.timesheetClasses(ctx, a)
		if err != nil {
			return err
		}
		if !scope.has(t.ClassID) {
			return iam.ErrForbidden
		}
		day := dateOnly(t.TeachingDate)
		if err := s.ensureOpen(ctx, day); err != nil {
			return err
		}
		if hours, err = checkTimes(in.TimeIn, in.TimeOut); err != nil {
			return err
		}
		reason := strings.TrimSpace(in.AdjustmentReason)
		switch n := utf8.RuneCountInString(reason); {
		case n == 0:
			return iam.Invalid("Chỉnh tay bắt buộc ghi lý do.")
		case n < 5:
			return iam.Invalid("Lý do điều chỉnh cần ít nhất 5 ký tự.")
		case n > 500:
			return iam.Invalid("Lý do điều chỉnh tối đa 500 ký tự.")
		}
		if err := checkMinutes(in.LateMinutes, "Số phút đi muộn"); err != nil {
			return err
		}
		if err := checkMinutes(in.EarlyLeaveMinutes, "Số phút về sớm"); err != nil {
			return err
		}
		late, early, notified := t.LateMinutes, t.EarlyLeaveMinutes, t.LateNotified
		if in.LateMinutes != nil {
			late = int32(*in.LateMinutes)
			// Form có trường phút muộn: không tick = chưa báo trước.
			notified = in.LateNotified != nil && *in.LateNotified
		}
		if in.EarlyLeaveMinutes != nil {
			early = int32(*in.EarlyLeaveMinutes)
		}
		timeIn, timeOut := strings.TrimSpace(in.TimeIn), strings.TrimSpace(in.TimeOut)
		after, err := q.AdjustTimesheet(ctx, attendancedb.AdjustTimesheetParams{ID: id, CheckinTime: timeIn, CheckoutTime: timeOut,
			Hours: hours, LateMinutes: late, EarlyLeaveMinutes: early, LateNotified: notified, AdjustedBy: &a.UserID,
			AdjustmentReason: &reason})
		if err != nil {
			return err
		}
		snap := func(x attendancedb.HrTeacherTimesheet) map[string]any {
			return map[string]any{"checkin_time": hm(x.CheckinTime), "checkout_time": hm(x.CheckoutTime), "hours": x.Hours,
				"late_minutes": x.LateMinutes, "early_leave_minutes": x.EarlyLeaveMinutes, "late_notified": x.LateNotified, "status": x.Status}
		}
		before := attendancedb.HrTeacherTimesheet{CheckinTime: t.CheckinTime, CheckoutTime: t.CheckoutTime, Hours: t.Hours,
			LateMinutes: t.LateMinutes, EarlyLeaveMinutes: t.EarlyLeaveMinutes, LateNotified: t.LateNotified, Status: t.Status}
		return s.record(ctx, tx, audit.Entry{ActorID: &a.UserID, Event: audit.EventUpdated, Action: "teacher_timesheet.adjust",
			SubjectType: "teacher_timesheet", SubjectID: id.String(), SubjectLabel: t.TeacherName + " · " + fmtDate(day),
			Description: "Chỉnh tay giờ chấm công của " + t.TeacherName + " ngày " + fmtDate(day),
			Properties:  map[string]any{"before": snap(before), "after": snap(after), "reason": reason}})
	})
	if err != nil {
		return "", err
	}
	return "Đã chỉnh tay giờ vào/ra (" + fmtHours(hours) + "h) — ca chuyển về Chờ duyệt để đối soát lại.", nil
}
