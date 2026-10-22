package classes

import (
	"context"
	"slices"
	"strings"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/classes/classesdb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// Trang lớp: một trang cho một lớp, tab con theo việc. Dữ liệu riêng của từng tab chỉ nạp khi mở tab đó.

type ClassInfo struct {
	ID               uuid.UUID  `json:"id"`
	Name             string     `json:"name"`
	Code             string     `json:"code"`
	Status           string     `json:"status"`
	StatusMeta       StatusMeta `json:"status_meta"`
	BranchID         uuid.UUID  `json:"branch_id"`
	Branch           string     `json:"branch"`
	Program          *string    `json:"program"`
	Level            *string    `json:"level"`
	Room             *string    `json:"room"`
	Teacher          *string    `json:"teacher"`
	ForeignTeacher   *string    `json:"foreign_teacher"`
	ScheduleText     *string    `json:"schedule_text"`
	StartDate        *string    `json:"start_date"`
	EndDate          *string    `json:"end_date"`
	HasScheduleSlots bool       `json:"has_schedule_config"`
}

type Progress struct {
	Total int64 `json:"total"`
	Done  int64 `json:"done"`
}

type NextSessionInfo struct {
	Date    string  `json:"date"`
	Start   string  `json:"start"`
	End     string  `json:"end"`
	Room    *string `json:"room"`
	Teacher *string `json:"teacher"`
}

type RosterStudent struct {
	ID          uuid.UUID `json:"id"`
	Name        string    `json:"name"`
	Code        string    `json:"code"`
	DOB         *string   `json:"dob"`
	Target      *string   `json:"target"`
	Address     *string   `json:"address"`
	ParentName  *string   `json:"parent_name"`
	Phone       *string   `json:"phone"` // chỉ gửi cho người quản lý lớp
	Notes       *string   `json:"notes"`
	Status      string    `json:"status"`
	StatusLabel string    `json:"status_label"`
	IsMain      bool      `json:"is_main"` // lớp chính (false = liên kết lớp khác)
}

type SessionRow struct {
	ID               uuid.UUID  `json:"id"`
	Date             string     `json:"date"`
	Weekday          int        `json:"weekday"` // 0 = Chủ nhật (như dayOfWeek)
	Start            string     `json:"start"`
	End              string     `json:"end"`
	Type             string     `json:"type"`
	Room             string     `json:"room"`
	Teacher          string     `json:"teacher"`
	ForeignTeacher   *string    `json:"foreign_teacher"`
	ForeignTeacherID *uuid.UUID `json:"foreign_teacher_id"`
	Assistants       string     `json:"assistants"`
	Cancelled        bool       `json:"cancelled"`
	Holiday          *string    `json:"holiday"`
	IsToday          bool       `json:"is_today"`
	Past             bool       `json:"past"`
	Editable         bool       `json:"editable"` // đổi GVNN được (buổi sắp tới, chưa điểm danh)
}

type AttendanceState struct {
	Key   string `json:"key"` // cancelled | done | upcoming | missing
	Color string `json:"color"`
	Label string `json:"label"`
}

func attendanceState(status, typ string, holiday bool, count int64, date, today time.Time) AttendanceState {
	switch {
	case status == "cancelled":
		if holiday {
			return AttendanceState{"cancelled", "neutral", "Nghỉ lễ"}
		}
		return AttendanceState{"cancelled", "neutral", "Đã hủy"}
	case count > 0 || (typ == "support" && status == "completed"):
		return AttendanceState{"done", "success", "Đã điểm danh"}
	case date.After(today):
		return AttendanceState{"upcoming", "info", "Chưa diễn ra"}
	}
	return AttendanceState{"missing", "error", "Chưa điểm danh"}
}

type AttendanceRow struct {
	ID               uuid.UUID       `json:"id"`
	Date             string          `json:"date"`
	Start            string          `json:"start"`
	End              string          `json:"end"`
	State            AttendanceState `json:"state"`
	AttendancesCount int64           `json:"attendances_count"`
	Cancelled        bool            `json:"cancelled"`
	// TODO(reports): báo cáo buổi (ClassReport) mới nhất của buổi khi có phân hệ báo cáo lớp.
	Report any `json:"report"`
}

type AttendanceStats struct {
	Past    int64 `json:"past"`
	Done    int64 `json:"done"`
	Missing int64 `json:"missing"`
	Reports int64 `json:"reports"`
}

type ClassPage struct {
	Class           ClassInfo        `json:"klass"`
	Tab             string           `json:"tab"`
	Tabs            []Tab            `json:"tabs"`
	Seat            Seat             `json:"seat"`
	Steps           []Step           `json:"steps"`
	CanManage       bool             `json:"can_manage"`
	CanDelete       bool             `json:"can_delete"`
	SessionProgress Progress         `json:"session_progress"`
	NextSession     *NextSessionInfo `json:"next_session"`
	NextAction      *NextAction      `json:"next_action"`
	// TODO(academic): Big Test, giai đoạn giáo trình hiện tại; TODO(incidents): số sự vụ chưa xử lý.
	OpenIncidents int64 `json:"open_incidents"`

	// Tab Tổng quan
	UpcomingForeignTeachers []string `json:"upcoming_foreign_teachers,omitempty"`
	// Trợ giảng 7 ngày tới = người được giao việc (ops.work_tasks) gắn lớp có hạn trong 7 ngày.
	UpcomingAssistants []string `json:"upcoming_assistants,omitempty"`
	// Tab Học viên
	Students []RosterStudent `json:"students,omitempty"`
	// Tab Lịch & buổi học
	Sessions              []SessionRow `json:"sessions,omitempty"`
	UpcomingCount         int          `json:"upcoming_count"`
	ForeignTeacherOptions []Option     `json:"foreign_teacher_options,omitempty"`
	// Tab Điểm danh & báo cáo buổi
	AttendanceRows      []AttendanceRow  `json:"attendance_rows,omitempty"`
	AttendanceStats     *AttendanceStats `json:"attendance_stats,omitempty"`
	CanRecordAttendance bool             `json:"can_record_attendance"`
}

func validTab(t string) bool {
	return slices.ContainsFunc(Tabs, func(x Tab) bool { return x.Key == t })
}

// Show: Trang lớp; tab không hợp lệ thì về Tổng quan.
func (s *Service) Show(ctx context.Context, actor *iam.Access, id uuid.UUID, tab string) (*ClassPage, error) {
	if err := ensureBrowse(actor); err != nil {
		return nil, err
	}
	c, err := s.classFor(ctx, actor, id, "")
	if err != nil {
		return nil, err
	}
	if !validTab(tab) {
		tab = "overview"
	}
	today := s.today()
	program := c.Program
	if program == nil {
		program = c.CourseName
	}
	seat := seatSummary(c.Status, c.StartDate, c.RosterCount, c.MaxCapacity, c.MinStudents, today)
	page := &ClassPage{
		Class: ClassInfo{
			ID: c.ID, Name: c.Name, Code: c.Code, Status: c.Status, StatusMeta: statusMeta(c.Status), BranchID: c.BranchID,
			Branch: c.BranchName, Program: program, Level: c.Level, Room: c.Room, Teacher: c.TeacherName,
			ForeignTeacher: c.ForeignTeacherName, ScheduleText: c.ScheduleText, StartDate: fmtDate(c.StartDate),
			EndDate: fmtDate(c.EndDate), HasScheduleSlots: c.HasSlots,
		},
		Tab: tab, Tabs: Tabs, Seat: seat, Steps: lifecycleSteps(c.Status, c.StartDate, c.EndDate, seat),
		CanManage: actor.Can("class.update") && canOn(actor, "class.update", c.ID, c.BranchID),
		CanDelete: actor.Can("class.delete") && canOn(actor, "class.delete", c.ID, c.BranchID),
	}
	prog, err := s.q.SessionProgress(ctx, classesdb.SessionProgressParams{ClassID: id, Today: today})
	if err != nil {
		return nil, err
	}
	page.SessionProgress = Progress{prog.Total, prog.Done}
	var nextDate *time.Time
	if ns, err := s.q.NextSession(ctx, classesdb.NextSessionParams{ClassID: id, Today: today}); err == nil {
		page.NextSession = &NextSessionInfo{Date: ns.Date.Format(time.DateOnly), Start: ns.StartTime, End: ns.EndTime, Room: ns.Room, Teacher: ns.TeacherName}
		nextDate = &ns.Date
	} else if !isNoRows(err) {
		return nil, err
	}
	page.NextAction = nextAction(c.Status, seat, nextDate)

	switch tab {
	case "overview":
		names, err := s.q.UpcomingForeignTeachers(ctx, classesdb.UpcomingForeignTeachersParams{ClassID: id, Today: today})
		if err != nil {
			return nil, err
		}
		assistants, err := s.q.UpcomingTaskAssistants(ctx, classesdb.UpcomingTaskAssistantsParams{ClassID: &id, Today: today})
		if err != nil {
			return nil, err
		}
		page.UpcomingForeignTeachers, page.UpcomingAssistants = nonNil(names), nonNil(assistants)
	case "students":
		rows, err := s.q.RosterStudents(ctx, &id)
		if err != nil {
			return nil, err
		}
		page.Students = make([]RosterStudent, 0, len(rows))
		for _, r := range rows {
			st := RosterStudent{
				ID: r.ID, Name: r.FullName, Code: r.Code, Target: r.Target, Address: r.Address, ParentName: r.ParentName,
				Notes: r.Notes, Status: r.Status, StatusLabel: studentStatusLabel(r.Status), IsMain: r.IsMain,
			}
			if r.Dob != nil {
				st.DOB = ptr(r.Dob.Format("02/01/2006"))
			}
			if page.CanManage {
				st.Phone = &r.Phone
			}
			page.Students = append(page.Students, st)
		}
	case "schedule", "attendance":
		rows, err := s.q.ClassSessions(ctx, classesdb.ClassSessionsParams{ClassID: id, Today: today})
		if err != nil {
			return nil, err
		}
		if tab == "schedule" {
			byDay, err := s.q.ClassTaskAssistants(ctx, &id)
			if err != nil {
				return nil, err
			}
			assistants := map[string][]string{}
			for _, r := range byDay {
				k := r.Day.Format(time.DateOnly)
				assistants[k] = append(assistants[k], r.FullName)
			}
			s.fillSchedule(page, c, rows, today, assistants)
			if page.CanManage && slices.ContainsFunc(page.Sessions, func(r SessionRow) bool { return r.Editable }) {
				var keep []uuid.UUID
				for _, r := range rows {
					if r.ForeignTeacherID != nil {
						keep = append(keep, *r.ForeignTeacherID)
					}
				}
				if page.ForeignTeacherOptions, err = s.staffOptions(ctx, actor, "class.teach", false, keep...); err != nil {
					return nil, err
				}
			}
		} else {
			fillAttendance(page, rows, today)
			page.CanRecordAttendance = actor.CanAny("attendance_student.record", "attendance_student.record_any")
		}
	}
	// TODO(academic): tab Học thuật (Big Test, giáo trình) và TODO(incidents): tab Sự vụ khi có các phân hệ đó.
	return page, nil
}

// taskAssistants: người được giao việc gắn lớp theo ngày hạn (trợ giảng không cố định theo lớp).
func (s *Service) fillSchedule(page *ClassPage, c classesdb.GetClassRow, rows []classesdb.ClassSessionsRow, today time.Time, taskAssistants map[string][]string) {
	page.Sessions = make([]SessionRow, 0, len(rows))
	for _, r := range rows {
		room := deref(r.Room)
		if room == "" {
			room = deref(c.Room)
		}
		if room == "" {
			room = "—"
		}
		// Lớp không có GV chính: teacher_id của buổi chính là GVNN → không lặp tên ở cột Giáo viên.
		teacher := "—"
		if r.TeacherID != nil && !sameID(r.TeacherID, r.ForeignTeacherID) && r.TeacherName != nil {
			teacher = *r.TeacherName
		}
		// Trợ giảng của buổi = người được giao việc gắn lớp trong ngày; dữ liệu cũ vẫn đọc trợ giảng của buổi.
		names := slices.Clone(taskAssistants[r.Date.Format(time.DateOnly)])
		if r.AssistantName != nil && !slices.Contains(names, *r.AssistantName) {
			names = append(names, *r.AssistantName)
		}
		assistants := "—"
		if len(names) > 0 {
			assistants = strings.Join(names, ", ")
		}
		page.Sessions = append(page.Sessions, SessionRow{
			ID: r.ID, Date: r.Date.Format(time.DateOnly), Weekday: int(r.Date.Weekday()), Start: r.StartTime, End: r.EndTime,
			Type: r.Type, Room: room, Teacher: teacher, ForeignTeacher: r.ForeignTeacherName, ForeignTeacherID: r.ForeignTeacherID,
			Assistants: assistants, Cancelled: r.Status == "cancelled", Holiday: r.HolidayName,
			IsToday: r.Date.Equal(today), Past: r.Date.Before(today), Editable: page.CanManage && r.StaffSyncable,
		})
		if !r.Date.Before(today) && r.Status != "cancelled" {
			page.UpcomingCount++
		}
	}
}

func fillAttendance(page *ClassPage, rows []classesdb.ClassSessionsRow, today time.Time) {
	stats := AttendanceStats{}
	page.AttendanceRows = []AttendanceRow{}
	for i := len(rows) - 1; i >= 0; i-- { // mới nhất trước
		r := rows[i]
		if r.Date.After(today) {
			continue
		}
		st := attendanceState(r.Status, r.Type, r.HolidayID != nil, r.AttendanceCount, r.Date, today)
		page.AttendanceRows = append(page.AttendanceRows, AttendanceRow{
			ID: r.ID, Date: r.Date.Format(time.DateOnly), Start: r.StartTime, End: r.EndTime, State: st,
			AttendancesCount: r.AttendanceCount, Cancelled: r.Status == "cancelled",
		})
		if r.Status != "cancelled" {
			stats.Past++
		}
		switch st.Key {
		case "done":
			stats.Done++
		case "missing":
			stats.Missing++
		}
	}
	page.AttendanceStats = &stats
}
