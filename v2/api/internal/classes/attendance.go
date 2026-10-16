package classes

import (
	"context"
	"fmt"
	"slices"
	"sort"
	"strings"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/classes/classesdb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// Điểm danh học viên theo buổi (TeacherPortalController::attendance / attendanceStore): GV / GVNN / trợ giảng của lớp
// hoặc của buổi tự điểm danh trong khung ±N giờ quanh giờ bắt đầu (SLA gv.attendance_window); Học vụ / Quản lý
// (attendance_student.record_any) điểm danh thay mọi lúc cho lớp trong phạm vi, trừ buổi chưa tới ngày.

// makeupLookbackDays: số ngày gần đây hiện trong ô chọn buổi (điểm danh bù).
const makeupLookbackDays = 30

const defaultWindowHours = 24

var AttendanceStatuses = []Option{{"present", "Có mặt"}, {"absent", "Vắng"}, {"late", "Đi muộn"}, {"excused", "Vắng có phép"}}

func validAttendance(st string) bool {
	return slices.ContainsFunc(AttendanceStatuses, func(o Option) bool { return o.Value == st })
}

var errNotTeacher = forbidden("Chỉ giáo viên / trợ giảng mới truy cập được cổng này.")
var errNotAssigned = forbidden("Bạn không được phân công phụ trách lớp này.")

type sessionInfo struct {
	ID        uuid.UUID
	Date      time.Time
	Start     string
	End       string
	Type      string
	Status    string
	Room      *string
	Teacher   *uuid.UUID
	Foreign   *uuid.UUID
	Assistant *uuid.UUID
	HolidayID *uuid.UUID
	TeacherNm *string
}

func fromGetSession(r classesdb.GetSessionRow) *sessionInfo {
	return &sessionInfo{ID: r.ID, Date: r.Date, Start: r.StartTime, End: r.EndTime, Type: r.Type, Status: r.Status, Room: r.Room,
		Teacher: r.TeacherID, Foreign: r.ForeignTeacherID, Assistant: r.AssistantID, HolidayID: r.HolidayID, TeacherNm: r.TeacherName}
}

// isAssigned: người dùng là GV / GVNN / trợ giảng của lớp hoặc của buổi.
func isAssigned(uid uuid.UUID, c classesdb.GetClassRow, se *sessionInfo) bool {
	ids := []*uuid.UUID{c.TeacherID, c.AssistantID, c.ForeignTeacherID}
	if se != nil {
		ids = append(ids, se.Teacher, se.Assistant, se.Foreign)
	}
	return slices.ContainsFunc(ids, func(id *uuid.UUID) bool { return id != nil && *id == uid })
}

func (s *Service) authorizeAttendance(ctx context.Context, actor *iam.Access, c classesdb.GetClassRow, se *sessionInfo) error {
	if isAssigned(actor.UserID, c, se) {
		return nil
	}
	if actor.Can("attendance_student.record_any") {
		sc, err := s.visible(ctx, actor)
		if err != nil {
			return err
		}
		if sc.has(c.ID) {
			return nil
		}
	}
	return errNotAssigned
}

// resolveSession: theo id, hoặc theo ngày (mặc định hôm nay) — ưu tiên buổi người dùng được phân công (không phải phụ
// đạo), rồi buổi không phải phụ đạo.
func (s *Service) resolveSession(ctx context.Context, actor *iam.Access, classID uuid.UUID, sessionID *uuid.UUID, date *time.Time) (*sessionInfo, error) {
	if sessionID != nil {
		r, err := s.q.GetSession(ctx, *sessionID)
		if isNoRows(err) || (err == nil && r.ClassID != classID) {
			return nil, ErrSessionNotFound
		}
		if err != nil {
			return nil, err
		}
		return fromGetSession(r), nil
	}
	day := s.today()
	if date != nil {
		day = *date
	}
	rows, err := s.q.SessionsOnDate(ctx, classesdb.SessionsOnDateParams{ClassID: classID, Date: day})
	if err != nil || len(rows) == 0 {
		return nil, err
	}
	pick := rows[0].ID
	found := false
	for _, r := range rows {
		mine := slices.ContainsFunc([]*uuid.UUID{r.TeacherID, r.AssistantID, r.ForeignTeacherID}, func(id *uuid.UUID) bool { return id != nil && *id == actor.UserID })
		if mine && r.Type != "support" {
			pick, found = r.ID, true
			break
		}
	}
	if !found {
		for _, r := range rows {
			if r.Type != "support" {
				pick = r.ID
				break
			}
		}
	}
	r, err := s.q.GetSession(ctx, pick)
	if err != nil {
		return nil, err
	}
	return fromGetSession(r), nil
}

func (s *Service) windowHours(ctx context.Context) int {
	if s.cat == nil {
		return defaultWindowHours
	}
	r, err := s.cat.SlaRule(ctx, "gv.attendance_window")
	if err != nil || r.Value <= 0 {
		return defaultWindowHours
	}
	return int(r.Value)
}

func sessionStart(se *sessionInfo) time.Time {
	m, _ := hm(se.Start)
	return time.Date(se.Date.Year(), se.Date.Month(), se.Date.Day(), m/60, m%60, 0, 0, vnZone)
}

// blockReason: buổi đã hủy; Học vụ điểm danh thay buổi chưa tới ngày. GV tự điểm danh dùng khung ±N giờ (windowReason).
func (s *Service) blockReason(actor *iam.Access, se *sessionInfo) string {
	if se.Status == "cancelled" {
		if se.HolidayID != nil {
			return "Buổi học này đã hủy (nghỉ lễ) — không điểm danh."
		}
		return "Buổi học này đã hủy — không điểm danh."
	}
	if !actor.Can("attendance_student.record_any") {
		return ""
	}
	if se.Date.After(s.today()) {
		return fmt.Sprintf("Buổi học ngày %s chưa diễn ra — chưa điểm danh được.", se.Date.Format("02/01/2006"))
	}
	return ""
}

func (s *Service) windowReason(ctx context.Context, actor *iam.Access, se *sessionInfo) string {
	if se.Status == "cancelled" || actor.Can("attendance_student.record_any") {
		return ""
	}
	hours := s.windowHours(ctx)
	start, now := sessionStart(se), s.now()
	if now.Before(start.Add(-time.Duration(hours)*time.Hour)) || now.After(start.Add(time.Duration(hours)*time.Hour)) {
		return fmt.Sprintf("Ngoài khung ±%dh so với giờ bắt đầu buổi học — liên hệ Học vụ để điểm danh.", hours)
	}
	return ""
}

type rosterEntry struct {
	ID     uuid.UUID
	Name   string
	Code   string
	Linked bool
}

// sessionRoster: danh sách lớp thật (lớp chính + lớp liên kết, còn giữ chỗ), luôn giữ học viên đã có điểm danh của buổi.
// TODO(tasks): buổi phụ đạo → học viên được xếp phụ đạo khi có bảng phụ đạo.
func (s *Service) sessionRoster(ctx context.Context, q *classesdb.Queries, classID uuid.UUID, existing []classesdb.SessionAttendanceRow) ([]rosterEntry, error) {
	rows, err := q.RosterStudents(ctx, &classID)
	if err != nil {
		return nil, err
	}
	out := make([]rosterEntry, 0, len(rows))
	seen := map[uuid.UUID]bool{}
	for _, r := range rows {
		out = append(out, rosterEntry{ID: r.ID, Name: r.FullName, Code: r.Code, Linked: !r.IsMain})
		seen[r.ID] = true
	}
	var missing []uuid.UUID
	for _, e := range existing {
		if !seen[e.StudentID] {
			missing = append(missing, e.StudentID)
		}
	}
	if len(missing) > 0 {
		extra, err := q.StudentsByIDs(ctx, missing)
		if err != nil {
			return nil, err
		}
		for _, r := range extra {
			out = append(out, rosterEntry{ID: r.ID, Name: r.FullName, Code: r.Code, Linked: !sameID(r.CurrentClassID, &classID)})
		}
	}
	sort.SliceStable(out, func(i, j int) bool { return strings.ToLower(out[i].Name) < strings.ToLower(out[j].Name) })
	return out, nil
}

type SheetSession struct {
	ID        uuid.UUID `json:"id"`
	Date      string    `json:"date"` // dd/mm/YYYY
	Time      string    `json:"time"`
	RoomLabel string    `json:"room_label"`
	Type      string    `json:"type"`
	IsPast    bool      `json:"is_past"`
}

type SheetStudent struct {
	ID           uuid.UUID `json:"id"`
	Name         string    `json:"name"`
	Code         string    `json:"code"`
	Linked       bool      `json:"linked"` // học viên liên kết (lớp chính là lớp khác)
	RecorderName *string   `json:"recorder_name"`
	Status       string    `json:"status"`
	Note         *string   `json:"note"`
}

type SessionChoice struct {
	ID       uuid.UUID `json:"id"`
	Label    string    `json:"label"`
	Disabled bool      `json:"disabled"`
}

type AttendanceSheet struct {
	Class          ClassRef        `json:"classroom"`
	WindowHours    int             `json:"window_hours"`
	Session        *SheetSession   `json:"session"`
	Students       []SheetStudent  `json:"students"`
	BlockReason    string          `json:"block_reason"`
	WindowReason   string          `json:"window_reason"`
	OnBehalf       bool            `json:"on_behalf"`
	OnBehalfName   *string         `json:"on_behalf_name"`
	RecentSessions []SessionChoice `json:"recent_sessions"`
	RosterSize     int64           `json:"roster_size"`
	Statuses       []Option        `json:"statuses"`
	// TODO(crm): khách học thử của buổi.
}

type ClassRef struct {
	ID         uuid.UUID `json:"id"`
	Name       string    `json:"name"`
	BranchName string    `json:"branch_name"`
}

func sessionLabel(date time.Time, start, end, typ, status string, count int64) string {
	label := date.Format("02/01/2006") + " · " + start + "-" + end
	switch typ {
	case "makeup":
		label += " · Học bù"
	case "support":
		label += " · Phụ đạo"
	}
	switch {
	case status == "cancelled":
		label += " · Đã hủy"
	case count > 0:
		label += fmt.Sprintf(" · Đã điểm danh (%d)", count)
	default:
		label += " · Chưa điểm danh"
	}
	return label
}

func guardTeacher(actor *iam.Access) error {
	if !actor.CanAny("attendance_student.record", "attendance_student.record_any") {
		return errNotTeacher
	}
	return nil
}

// AttendanceSheet: trang điểm danh một buổi; không chọn buổi / ngày và hôm nay không có buổi thì mở buổi gần nhất
// chưa điểm danh trong 30 ngày.
func (s *Service) AttendanceSheet(ctx context.Context, actor *iam.Access, classID uuid.UUID, sessionID *uuid.UUID, date *time.Time) (*AttendanceSheet, error) {
	if err := guardTeacher(actor); err != nil {
		return nil, err
	}
	c, err := s.q.GetClass(ctx, classID)
	if isNoRows(err) {
		return nil, ErrNotFound
	}
	if err != nil {
		return nil, err
	}
	today := s.today()
	recent, err := s.q.RecentSessions(ctx, classesdb.RecentSessionsParams{ClassID: classID, FromDate: today.AddDate(0, 0, -makeupLookbackDays), ToDate: today})
	if err != nil {
		return nil, err
	}
	se, err := s.resolveSession(ctx, actor, classID, sessionID, date)
	if err != nil {
		return nil, err
	}
	if se == nil && sessionID == nil && date == nil {
		var pick *uuid.UUID
		for _, r := range recent {
			if r.Status != "cancelled" && r.Type != "support" && r.AttendanceCount == 0 {
				pick = &r.ID
				break
			}
		}
		if pick == nil {
			for _, r := range recent {
				if r.Status != "cancelled" && r.Type != "support" {
					pick = &r.ID
					break
				}
			}
		}
		if pick != nil {
			if se, err = s.resolveSession(ctx, actor, classID, pick, nil); err != nil {
				return nil, err
			}
		}
	}
	if err := s.authorizeAttendance(ctx, actor, c, se); err != nil {
		return nil, err
	}
	out := &AttendanceSheet{
		Class: ClassRef{c.ID, c.Name, c.BranchName}, WindowHours: s.windowHours(ctx), Students: []SheetStudent{},
		RecentSessions: []SessionChoice{}, RosterSize: c.RosterCount, Statuses: AttendanceStatuses,
	}
	inRecent := false
	for _, r := range recent {
		out.RecentSessions = append(out.RecentSessions, SessionChoice{r.ID, sessionLabel(r.Date, r.StartTime, r.EndTime, r.Type, r.Status, r.AttendanceCount), r.Status == "cancelled"})
		if se != nil && r.ID == se.ID {
			inRecent = true
		}
	}
	if se == nil {
		return out, nil
	}
	existing, err := s.q.SessionAttendance(ctx, se.ID)
	if err != nil {
		return nil, err
	}
	if !inRecent {
		out.RecentSessions = append(out.RecentSessions, SessionChoice{se.ID, sessionLabel(se.Date, se.Start, se.End, se.Type, se.Status, int64(len(existing))), se.Status == "cancelled"})
	}
	room := deref(se.Room)
	if room == "" {
		room = deref(c.Room)
	}
	if room == "" {
		room = "Chưa có phòng"
	}
	out.Session = &SheetSession{ID: se.ID, Date: se.Date.Format("02/01/2006"), Time: se.Start + " – " + se.End, RoomLabel: room, Type: se.Type, IsPast: se.Date.Before(today)}
	out.BlockReason, out.WindowReason = s.blockReason(actor, se), s.windowReason(ctx, actor, se)
	out.OnBehalf = !isAssigned(actor.UserID, c, se)
	if out.OnBehalf {
		name := "giáo viên của lớp"
		if se.TeacherNm != nil {
			name = *se.TeacherNm
		} else if c.TeacherName != nil {
			name = *c.TeacherName
		}
		out.OnBehalfName = &name
	}
	roster, err := s.sessionRoster(ctx, s.q, classID, existing)
	if err != nil {
		return nil, err
	}
	byStudent := map[uuid.UUID]classesdb.SessionAttendanceRow{}
	for _, e := range existing {
		byStudent[e.StudentID] = e
	}
	for _, st := range roster {
		row := SheetStudent{ID: st.ID, Name: st.Name, Code: st.Code, Linked: st.Linked, Status: "present"}
		if e, ok := byStudent[st.ID]; ok {
			row.Status, row.Note = e.Status, e.Note
			if e.RecordedBy != nil && !sameID(e.RecordedBy, e.TeacherID) {
				row.RecorderName = e.RecorderName
			}
		}
		out.Students = append(out.Students, row)
	}
	return out, nil
}

type AttendanceRecord struct {
	StudentID uuid.UUID `json:"student_id"`
	Status    string    `json:"status"`
	Note      string    `json:"note"`
}

type AttendanceInput struct {
	SessionID *uuid.UUID         `json:"session_id"`
	Date      string             `json:"date"`
	Records   []AttendanceRecord `json:"records"`
}

// SaveAttendance: lưu điểm danh một buổi. Học vụ / Quản lý lưu thay thì teacher_id vẫn là GV của buổi, recorded_by là
// người lưu; lưu lại thì về chờ rà soát; buổi chuyển "đã điểm danh".
func (s *Service) SaveAttendance(ctx context.Context, actor *iam.Access, classID uuid.UUID, in AttendanceInput) (string, error) {
	if err := guardTeacher(actor); err != nil {
		return "", err
	}
	c, err := s.q.GetClass(ctx, classID)
	if isNoRows(err) {
		return "", ErrNotFound
	}
	if err != nil {
		return "", err
	}
	if len(in.Records) == 0 {
		return "", iam.Invalid("Chưa có học viên nào để điểm danh.")
	}
	for _, r := range in.Records {
		if !validAttendance(r.Status) {
			return "", iam.Invalid("Trạng thái điểm danh không hợp lệ.")
		}
		if tooLong(strings.TrimSpace(r.Note), 500) {
			return "", iam.Invalid("Ghi chú điểm danh không được vượt quá 500 ký tự.")
		}
	}
	var date *time.Time
	if d, ok, err := parseOptDate(&in.Date, "Ngày không hợp lệ."); err != nil {
		return "", err
	} else if ok {
		date = d
	}
	se, err := s.resolveSession(ctx, actor, classID, in.SessionID, date)
	if err != nil {
		return "", err
	}
	if err := s.authorizeAttendance(ctx, actor, c, se); err != nil {
		return "", err
	}
	if se == nil {
		return "", iam.Invalid("Lớp không có buổi học trong ngày này. Hãy chọn buổi cần điểm danh.")
	}
	if reason := s.blockReason(actor, se); reason != "" {
		return "", iam.Invalid(reason)
	}
	if reason := s.windowReason(ctx, actor, se); reason != "" {
		return "", iam.Invalid(reason)
	}
	missing := 0
	for _, r := range in.Records {
		if (r.Status == "absent" || r.Status == "excused") && strings.TrimSpace(r.Note) == "" {
			missing++
		}
	}
	if missing > 0 {
		return "", iam.Invalid(fmt.Sprintf(`Vui lòng điền rõ lý do cho %d học sinh được đánh dấu "Nghỉ".`, missing))
	}
	teacher := &actor.UserID
	if !isAssigned(actor.UserID, c, se) {
		teacher = coalesceID(se.Teacher, c.TeacherID, &actor.UserID)
	}
	count := 0
	err = s.tx(ctx, func(_ pgx.Tx, q *classesdb.Queries) error {
		existing, err := q.SessionAttendance(ctx, se.ID)
		if err != nil {
			return err
		}
		roster, err := s.sessionRoster(ctx, q, classID, existing)
		if err != nil {
			return err
		}
		allowed := map[uuid.UUID]bool{}
		for _, r := range roster {
			allowed[r.ID] = true
		}
		var started []uuid.UUID
		for _, r := range in.Records {
			if !allowed[r.StudentID] {
				return iam.Invalid("Học viên không thuộc lớp/buổi học này.")
			}
			if err := q.UpsertAttendance(ctx, classesdb.UpsertAttendanceParams{
				SessionID: se.ID, ClassID: classID, StudentID: r.StudentID, SessionDate: se.Date, Status: r.Status,
				Note: optText(r.Note), TeacherID: teacher, RecordedBy: &actor.UserID,
			}); err != nil {
				return err
			}
			if r.Status == "present" || r.Status == "late" {
				started = append(started, r.StudentID)
			}
			count++
		}
		// Có mặt / đi muộn lần đầu = ngày bắt đầu vào học.
		if len(started) > 0 {
			if err := q.RecordStudyStart(ctx, classesdb.RecordStudyStartParams{Day: se.Date, Ids: started}); err != nil {
				return err
			}
		}
		// Đã điểm danh nghĩa là buổi đã diễn ra: chốt buổi để không bị xếp lại / ghi đè về sau.
		return q.SetSessionCompleted(ctx, se.ID)
	})
	if err != nil {
		return "", err
	}
	s.log.Info("attendance saved", "class_id", classID, "session_id", se.ID, "count", count, "by", actor.UserID)
	when := "hôm nay"
	if !se.Date.Equal(s.today()) {
		when = "ngày " + se.Date.Format("02/01/2006") + " (điểm danh bù)"
	}
	return fmt.Sprintf("Đã lưu điểm danh %d học viên lớp %s buổi %s.", count, c.Name, when), nil
}
