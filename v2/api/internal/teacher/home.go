package teacher

import (
	"context"
	"fmt"
	"math"
	"strconv"
	"strings"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/teacher/teacherdb"
)

// Lịch dạy của tôi (TeacherPortalController::home): ca hôm nay (kèm ca tối qua còn trong cửa sổ check-in) để check-in và
// điểm danh, buổi đã qua chưa điểm danh trong 30 ngày (điểm danh bù), lịch cả tuần, thẻ tổng quan.

// makeupLookbackDays: số ngày nhìn lại để nhắc buổi chưa điểm danh / chọn buổi điểm danh bù, nhận xét.
const makeupLookbackDays = 30

// checkinWarningMinutes: cảnh báo khi cửa sổ check-in còn dưới số phút này.
const checkinWarningMinutes = 15

type CheckinWindow struct {
	Deadline         string `json:"deadline"` // HH:MM dd/mm
	DeadlineISO      string `json:"deadline_iso"`
	RemainingSeconds int64  `json:"remaining_seconds"`
	Expired          bool   `json:"expired"`
	Warning          bool   `json:"warning"`
}

type Shift struct {
	SessionID      uuid.UUID     `json:"session_id"`
	ClassID        uuid.UUID     `json:"class_id"`
	ClassName      string        `json:"class_name"`
	ClassCode      string        `json:"class_code"`
	BranchName     string        `json:"branch_name"`
	RoomLabel      string        `json:"room_label"`
	TypeLabel      string        `json:"type_label"`
	IsSupport      bool          `json:"is_support"`
	ScheduledTime  string        `json:"scheduled_time"`
	ShiftName      string        `json:"shift_name"`
	CheckedIn      bool          `json:"checked_in"`
	CheckinTime    string        `json:"checkin_time"`
	Checkin        CheckinWindow `json:"checkin"`
	IsToday        bool          `json:"is_today"`
	DateLabel      string        `json:"date_label"`
	AttendanceDone bool          `json:"attendance_done"`
	StudentCount   int           `json:"student_count"`
	TrialCount     int           `json:"trial_count"`
}

type PendingSession struct {
	ID        uuid.UUID `json:"id"`
	ClassID   uuid.UUID `json:"class_id"`
	ClassName string    `json:"class_name"`
	Date      string    `json:"date"`
	Time      string    `json:"time"`
	TypeLabel string    `json:"type_label"`
}

type WeekSession struct {
	ID             uuid.UUID `json:"id"`
	ClassID        uuid.UUID `json:"class_id"`
	ClassName      string    `json:"class_name"`
	Time           string    `json:"time"`
	Room           string    `json:"room"`
	TypeLabel      string    `json:"type_label"`
	Cancelled      bool      `json:"cancelled"`
	Done           bool      `json:"done"`
	CanTake        bool      `json:"can_take"`
	CheckinOverdue bool      `json:"checkin_overdue"`
}

type WeekDay struct {
	Label    string        `json:"label"`
	Date     string        `json:"date"`
	IsToday  bool          `json:"is_today"`
	Sessions []WeekSession `json:"sessions"`
}

type Week struct {
	Label string `json:"label"`
	Start string `json:"start"`
	Prev  string `json:"prev"`
	Next  string `json:"next"`
}

type AttentionScore struct {
	ID          uuid.UUID `json:"id"`
	StudentName string    `json:"student_name"`
	ClassName   string    `json:"class_name"`
	Name        string    `json:"name"`
	Score       string    `json:"score"`
}

type Widgets struct {
	Attention []AttentionScore `json:"attention"`
	// WorkPending: chấm công / lương / vi phạm chờ phân hệ (các ô bên dưới bỏ trống)
	WorkPending       string          `json:"work_pending"`
	Estimate          *int64          `json:"estimate"`
	HoursLabel        string          `json:"hours_label"`
	Month             int             `json:"month"`
	TodayDM           string          `json:"today_dm"`
	Period            string          `json:"period"`
	MonthEndDM        string          `json:"month_end_dm"`
	TimesheetsTotal   int             `json:"timesheets_total"`
	TimesheetsPending int             `json:"timesheets_pending"`
	ViolationsCount   int             `json:"violations_count"`
	LatestViolation   *ViolationBrief `json:"latest_violation"`
}

type HomeStats struct {
	Total          int `json:"total"`
	CheckedIn      int `json:"checked_in"`
	AttendanceDone int `json:"attendance_done"`
	Pending        int `json:"pending"`
}

type NextShift struct {
	ClassID   uuid.UUID `json:"class_id"`
	SessionID uuid.UUID `json:"session_id"`
	StartTime string    `json:"start_time"`
	Upcoming  bool      `json:"upcoming"`
}

type Home struct {
	TeacherName     string           `json:"teacher_name"`
	TodayLabel      string           `json:"today_label"`
	Stats           HomeStats        `json:"stats"`
	NextShift       *NextShift       `json:"next_shift"`
	Shifts          []Shift          `json:"shifts"`
	PendingSessions []PendingSession `json:"pending_sessions"`
	Week            Week             `json:"week"`
	WeekDays        []WeekDay        `json:"week_days"`
	Widgets         Widgets          `json:"widgets"`
	WarningMinutes  int              `json:"warning_minutes"`
	// CanCheckin: phân hệ chấm công đã có (nút "Check-in các ca đã chọn")
	CanCheckin bool `json:"can_checkin"`
}

var weekdayLong = map[time.Weekday]string{time.Monday: "Thứ Hai", time.Tuesday: "Thứ Ba", time.Wednesday: "Thứ Tư",
	time.Thursday: "Thứ Năm", time.Friday: "Thứ Sáu", time.Saturday: "Thứ Bảy", time.Sunday: "Chủ Nhật"}

var weekdayShort = map[time.Weekday]string{time.Monday: "T2", time.Tuesday: "T3", time.Wednesday: "T4",
	time.Thursday: "T5", time.Friday: "T6", time.Saturday: "T7", time.Sunday: "CN"}

// mondayOf: thứ Hai của tuần chứa ngày d.
func mondayOf(d time.Time) time.Time {
	off := (int(d.Weekday()) + 6) % 7
	return d.AddDate(0, 0, -off)
}

// trimNumber: 8.50 → "8.5", 10.00 → "10".
func trimNumber(v float64) string {
	return strconv.FormatFloat(math.Round(v*100)/100, 'f', -1, 64)
}

// hoursLabel: 12.5 → "12,5"; 12 → "12".
func hoursLabel(v float64) string {
	return strings.ReplaceAll(strconv.FormatFloat(math.Round(v*10)/10, 'f', -1, 64), ".", ",")
}

func (s *Service) checkinWindow(se teacherdb.StaffSessionsRow, hours int, now time.Time) CheckinWindow {
	deadline := at(se.Date, se.StartTime).Add(time.Duration(hours) * time.Hour)
	remaining := int64(deadline.Sub(now) / time.Second)
	return CheckinWindow{
		Deadline: deadline.Format("15:04 02/01"), DeadlineISO: deadline.Format(time.RFC3339),
		RemainingSeconds: max(0, remaining), Expired: remaining < 0,
		Warning: remaining >= 0 && remaining < checkinWarningMinutes*60,
	}
}

// Home: trang "Check-in & Điểm danh hôm nay"; week = ngày bất kỳ trong tuần cần xem (YYYY-MM-DD, trống = tuần này).
func (s *Service) Home(ctx context.Context, a *iam.Access, week string) (*Home, error) {
	if err := guardTeacher(a); err != nil {
		return nil, err
	}
	now, today := s.now(), s.today()
	anchor := today
	if d, ok := parseDay(week); ok {
		anchor = d
	}
	weekStart := mondayOf(anchor)
	weekEnd := weekStart.AddDate(0, 0, 6)
	lookFrom := today.AddDate(0, 0, -makeupLookbackDays)
	from, to := lookFrom, today
	if weekStart.Before(from) {
		from = weekStart
	}
	if weekEnd.After(to) {
		to = weekEnd
	}
	rows, err := s.q.StaffSessions(ctx, teacherdb.StaffSessionsParams{FromDate: from, ToDate: to, UserID: &a.UserID})
	if err != nil {
		return nil, err
	}
	hours := s.checkinWindowHours(ctx)
	yesterday := today.AddDate(0, 0, -1)

	var todayRows, windowRows, weekRows, pendingRows []teacherdb.StaffSessionsRow
	for _, r := range rows {
		cancelled := r.Status == "cancelled"
		switch {
		case r.Date.Equal(today) && !cancelled:
			todayRows = append(todayRows, r)
		case r.Date.Equal(yesterday) && !cancelled && !now.After(at(r.Date, r.StartTime).Add(time.Duration(hours)*time.Hour)):
			// Ca tối qua còn trong cửa sổ check-in (giờ bắt đầu + N giờ).
			windowRows = append(windowRows, r)
		}
		if !r.Date.Before(weekStart) && !r.Date.After(weekEnd) {
			weekRows = append(weekRows, r)
		}
		// Buổi đã qua chưa điểm danh (buổi phụ đạo đã hoàn thành không cần điểm danh lớp).
		if r.Date.Before(today) && !r.Date.Before(lookFrom) && !cancelled && !r.Attended && !(r.Type == "support" && r.Status == "completed") {
			pendingRows = append(pendingRows, r)
		}
	}
	shiftRows := append(windowRows, todayRows...)

	ids := make([]uuid.UUID, 0, len(shiftRows)+len(weekRows))
	shiftIDs := make([]uuid.UUID, 0, len(shiftRows))
	for _, r := range shiftRows {
		ids = append(ids, r.ID)
		shiftIDs = append(shiftIDs, r.ID)
	}
	for _, r := range weekRows {
		ids = append(ids, r.ID)
	}
	checkins, err := s.work.Checkins(ctx, a.UserID, ids)
	if err != nil {
		return nil, err
	}
	guests, err := s.trials.SessionGuests(ctx, shiftIDs)
	if err != nil {
		return nil, err
	}

	name, err := s.q.UserName(ctx, a.UserID)
	if err != nil && !isNoRows(err) {
		return nil, err
	}
	out := &Home{
		TeacherName: name, TodayLabel: weekdayLong[today.Weekday()] + ", " + today.Format("02/01"),
		Shifts: []Shift{}, PendingSessions: []PendingSession{}, WeekDays: []WeekDay{}, WarningMinutes: checkinWarningMinutes,
		CanCheckin: s.work.Available(),
		Week: Week{
			Label: weekStart.Format("02/01") + " – " + weekEnd.Format("02/01/2006"), Start: weekStart.Format(time.DateOnly),
			Prev: weekStart.AddDate(0, 0, -7).Format(time.DateOnly), Next: weekStart.AddDate(0, 0, 7).Format(time.DateOnly),
		},
	}
	for _, r := range shiftRows {
		room := deref(r.Room)
		if room == "" {
			room = deref(r.ClassRoom)
		}
		ck, has := checkins[r.ID]
		sh := Shift{
			SessionID: r.ID, ClassID: r.ClassID, ClassName: r.ClassName, ClassCode: r.ClassCode, BranchName: r.BranchName,
			RoomLabel: roomLabel(room), TypeLabel: typeLabel(r.Type), IsSupport: r.Type == "support",
			ScheduledTime: r.StartTime + " - " + r.EndTime, ShiftName: shiftLabel(r.ShiftName),
			CheckedIn: has && ck.Time != "", CheckinTime: ck.Time, Checkin: s.checkinWindow(r, hours, now),
			IsToday: r.Date.Equal(today), DateLabel: r.Date.Format("02/01"), AttendanceDone: r.Attended,
			StudentCount: int(r.RosterCount), TrialCount: len(guests[r.ID]),
		}
		// TODO(gộp): buổi phụ đạo → 1 học viên được xếp (phân hệ Công việc / buổi bổ trợ).
		if sh.IsSupport {
			sh.StudentCount = 1
		}
		out.Shifts = append(out.Shifts, sh)
		if sh.CheckedIn {
			out.Stats.CheckedIn++
		}
		if sh.AttendanceDone {
			out.Stats.AttendanceDone++
		}
		if out.NextShift == nil && !sh.AttendanceDone && !now.After(at(r.Date, r.EndTime)) {
			out.NextShift = &NextShift{ClassID: r.ClassID, SessionID: r.ID, StartTime: r.StartTime, Upcoming: now.Before(at(r.Date, r.StartTime))}
		}
	}
	out.Stats.Total = len(out.Shifts)
	// Mới nhất trước (ngày giảm dần, giờ tăng dần như Laravel).
	for i := len(pendingRows) - 1; i >= 0; {
		j := i
		for j > 0 && pendingRows[j-1].Date.Equal(pendingRows[i].Date) {
			j--
		}
		for _, r := range pendingRows[j : i+1] {
			out.PendingSessions = append(out.PendingSessions, PendingSession{ID: r.ID, ClassID: r.ClassID, ClassName: r.ClassName,
				Date: r.Date.Format("02/01/2006"), Time: r.StartTime + "-" + r.EndTime, TypeLabel: typeLabel(r.Type)})
		}
		i = j - 1
	}
	out.Stats.Pending = len(out.PendingSessions)

	for i := 0; i < 7; i++ {
		day := weekStart.AddDate(0, 0, i)
		wd := WeekDay{Label: weekdayShort[day.Weekday()] + " · " + day.Format("02/01"), Date: day.Format(time.DateOnly),
			IsToday: day.Equal(today), Sessions: []WeekSession{}}
		for _, r := range weekRows {
			if !r.Date.Equal(day) {
				continue
			}
			cancelled := r.Status == "cancelled"
			ck, has := checkins[r.ID]
			checked := has && ck.Status != "invalid"
			deadline := at(r.Date, r.StartTime).Add(time.Duration(hours) * time.Hour)
			wd.Sessions = append(wd.Sessions, WeekSession{ID: r.ID, ClassID: r.ClassID, ClassName: r.ClassName,
				Time: r.StartTime + "-" + r.EndTime, Room: roomLabel(deref(r.Room)), TypeLabel: typeLabel(r.Type), Cancelled: cancelled,
				Done: r.Attended, CanTake: !cancelled && !r.Date.After(today), CheckinOverdue: !cancelled && now.After(deadline) && !checked})
		}
		out.WeekDays = append(out.WeekDays, wd)
	}

	if out.Widgets, err = s.widgets(ctx, a.UserID, today, now); err != nil {
		return nil, err
	}
	return out, nil
}

// widgets: học sinh cần chú ý (mini test dưới 7/10 trong 30 ngày), lương tạm tính, chấm công, vi phạm trong tháng.
func (s *Service) widgets(ctx context.Context, uid uuid.UUID, today, now time.Time) (Widgets, error) {
	monthStart := time.Date(today.Year(), today.Month(), 1, 0, 0, 0, 0, time.UTC)
	monthEnd := monthStart.AddDate(0, 1, -1)
	w := Widgets{Attention: []AttentionScore{}, Month: int(today.Month()), TodayDM: today.Format("02/01"),
		Period: today.Format("01/2006"), MonthEndDM: monthEnd.Format("02/01"), HoursLabel: "0"}
	rows, err := s.q.AttentionScores(ctx, teacherdb.AttentionScoresParams{UserID: &uid, FromDate: today.AddDate(0, 0, -30)})
	if err != nil {
		return w, err
	}
	for _, r := range rows {
		w.Attention = append(w.Attention, AttentionScore{ID: r.ID, StudentName: r.StudentName, ClassName: r.ClassName, Name: r.Name,
			Score: trimNumber(deref(r.Score)) + "/" + trimNumber(r.MaxScore)})
	}
	m, err := s.work.Month(ctx, uid, monthStart, today)
	if err != nil {
		return w, err
	}
	if m == nil {
		w.WorkPending = workPending
		return w, nil
	}
	w.Estimate, w.HoursLabel = m.Estimate, hoursLabel(m.Hours)
	w.TimesheetsTotal, w.TimesheetsPending = m.Sessions, m.Pending
	w.ViolationsCount, w.LatestViolation = m.Violations, m.Latest
	return w, nil
}

// ───────────── Check-in nhiều ca ─────────────

type CheckinInput struct {
	SessionIDs []uuid.UUID `json:"session_ids"`
}

type CheckinOutcome struct {
	Message string `json:"message"`
	// Warning: các ca bị bỏ qua khi vẫn check-in được ít nhất một ca
	Warning string `json:"warning,omitempty"`
	Count   int    `json:"count"`
}

// checkinReason: ngoài cửa sổ check-in [đầu ngày học, giờ bắt đầu + N giờ].
func checkinReason(date time.Time, start string, hours int, now, today time.Time) string {
	deadline := at(date, start).Add(time.Duration(hours) * time.Hour)
	if now.After(deadline) {
		return "Quá hạn chấm công (hạn " + deadline.Format("15:04 02/01/2006") + ") — liên hệ Học vụ để được xác nhận công"
	}
	if date.After(today) {
		return "buổi học ngày " + date.Format("02/01/2006") + " chưa tới ngày — chưa check-in được"
	}
	return ""
}

// CheckIn: check-in nhiều ca cùng lúc. Chỉ tính công cho buổi có thật trên lịch được phân công cho người này, trong cửa
// sổ check-in; phần ghi chấm công (trùng / chấm tay / kỳ lương khóa) do phân hệ chấm công làm.
func (s *Service) CheckIn(ctx context.Context, a *iam.Access, in CheckinInput) (*CheckinOutcome, error) {
	if err := guardTeacher(a); err != nil {
		return nil, err
	}
	if len(in.SessionIDs) == 0 {
		return nil, iam.Invalid("Vui lòng chọn ít nhất 1 ca dạy để check-in.")
	}
	now, today := s.now(), s.today()
	hours := s.checkinWindowHours(ctx)
	var targets []CheckinSession
	var skipped []string
	seen := map[uuid.UUID]bool{}
	for _, id := range in.SessionIDs {
		if seen[id] {
			continue
		}
		seen[id] = true
		r, err := s.q.StaffSessionByID(ctx, teacherdb.StaffSessionByIDParams{ID: id, UserID: &a.UserID})
		if isNoRows(err) || (err == nil && r.Status == "cancelled") {
			name, nerr := s.q.SessionClassName(ctx, id)
			if nerr != nil {
				name = "Buổi " + id.String()[:8]
			}
			skipped = append(skipped, name+": không phải buổi được phân công cho bạn")
			continue
		}
		if err != nil {
			return nil, err
		}
		if reason := checkinReason(r.Date, r.StartTime, hours, now, today); reason != "" {
			skipped = append(skipped, r.ClassName+": "+reason)
			continue
		}
		late := int(now.Sub(at(r.Date, r.StartTime)) / time.Minute)
		targets = append(targets, CheckinSession{SessionID: r.ID, ClassID: r.ClassID, ClassName: r.ClassName, Date: r.Date,
			Start: r.StartTime, End: r.EndTime, Type: r.Type, LateMinutes: min(1440, max(0, late))})
	}
	var res CheckinResult
	if len(targets) > 0 {
		var err error
		if res, err = s.work.CheckIn(ctx, a.UserID, now, targets); err != nil {
			return nil, err
		}
	}
	skipped = append(skipped, res.Skipped...)
	if res.Count == 0 {
		if res.Locked != "" && len(skipped) == 0 {
			return nil, iam.Invalid(res.Locked)
		}
		parts := skipped
		if res.Locked != "" {
			parts = append(parts, res.Locked)
		}
		return nil, iam.Invalid("Không check-in được ca nào — " + strings.Join(parts, "; ") + ".")
	}
	s.log.Info("teacher checkin", "count", res.Count, "skipped", len(skipped), "by", a.UserID)
	if len(skipped) > 0 {
		return &CheckinOutcome{Count: res.Count, Message: fmt.Sprintf("Đã check-in %d ca dạy hôm nay.", res.Count),
			Warning: "Bỏ qua: " + strings.Join(skipped, "; ") + "."}, nil
	}
	return &CheckinOutcome{Count: res.Count, Message: fmt.Sprintf("Đã check-in thành công %d ca dạy hôm nay!", res.Count)}, nil
}
