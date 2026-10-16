package tasks

import (
	"context"
	"strconv"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// Classes: phần việc phân hệ Lớp học làm cho công việc (classes.TaskClasses): lớp gắn vào việc, buổi học thật để giao việc
// trực ca cho trợ giảng, buổi học trong ngày cho portal trợ giảng. Chưa gắn (nil) → không có lớp để chọn, việc gắn lớp bị từ
// chối như lớp không tồn tại.
type Classes interface {
	// Options: lớp người dùng được xem (ClassModel::visibleTo), chưa xóa, ở một trong các trạng thái, xếp theo tên.
	Options(ctx context.Context, a *iam.Access, statuses []string) ([]ClassOption, error)
	// Exists: lớp có (chưa xóa).
	Exists(ctx context.Context, id uuid.UUID) (bool, error)
	// Visible: lớp người dùng được xem.
	Visible(ctx context.Context, a *iam.Access, id uuid.UUID) (bool, error)
	// Sessions: buổi không hủy, không phụ đạo của các lớp trong khoảng ngày, xếp theo ngày / giờ.
	Sessions(ctx context.Context, classIDs []uuid.UUID, from, to time.Time) ([]ClassSession, error)
	// Session: một buổi học (nil nếu không có / lớp đã xóa).
	Session(ctx context.Context, id uuid.UUID) (*ClassSession, error)
	// DaySessions: buổi không hủy trong ngày mà người dùng dạy / dạy GVNN / trợ giảng, hoặc thuộc các lớp classIDs.
	DaySessions(ctx context.Context, staffID uuid.UUID, classIDs []uuid.UUID, day time.Time) ([]ClassSession, error)
	// Refs: mã / tên lớp theo id (kể cả lớp đã xóa).
	Refs(ctx context.Context, ids []uuid.UUID) (map[uuid.UUID]ClassRef, error)
}

type ClassRef struct {
	Code string
	Name string
}

type ClassOption struct {
	ID           uuid.UUID
	Code         string
	Name         string
	ScheduleText *string
}

type ClassSession struct {
	ID        uuid.UUID
	ClassID   uuid.UUID
	ClassName string
	Date      time.Time
	Start     string // HH:MM
	End       string // HH:MM
	Room      *string
	BranchID  uuid.UUID
	Branch    string
	Makeup    bool
	// LessonNo: thứ tự buổi chính khóa của lớp (bỏ buổi hủy); 0 với buổi học bù / phụ đạo.
	LessonNo  int
	ShiftName *string
}

// WithClasses: gắn phân hệ Lớp học (cmd/menglish).
func (s *Service) WithClasses(c Classes) *Service {
	s.classes = c
	return s
}

// sessionLabel: tên buổi khi gắn việc vào buổi học (WorkTaskController::sessionLabel) — "Buổi N (dd/mm)"; buổi không
// đánh số thì tên ca hoặc "Buổi học". Tên bài theo giáo trình chờ phân hệ Giáo trình.
func sessionLabel(s ClassSession) string {
	label := "Buổi học"
	switch {
	case s.LessonNo > 0:
		label = "Buổi " + strconv.Itoa(s.LessonNo)
	case s.ShiftName != nil && *s.ShiftName != "":
		label = *s.ShiftName
	}
	return label + " (" + s.Date.Format("02/01") + ")"
}

// slotDueFor: giờ hạn ca trực khi gắn buổi học (slotDueTime) — Trước giờ học = giờ bắt đầu, Trong giờ học = giờ kết thúc,
// Sau giờ học = kết thúc + 60 phút (tối đa 23:59); không gắn buổi → mốc mặc định của ca.
func slotDueFor(category string, s *ClassSession) string {
	if s == nil || s.Start == "" || s.End == "" {
		return slotDue[category]
	}
	switch category {
	case "before":
		return s.Start
	case "during":
		return s.End
	}
	end, err := time.Parse("15:04", s.End)
	if err != nil {
		return slotDue[category]
	}
	after := end.Add(time.Hour)
	if after.Day() != end.Day() {
		return "23:59"
	}
	return after.Format("15:04")
}

// classLabels: "Tên lớp · Buổi …" cho danh sách / chi tiết công việc.
func (s *Service) classLabels(ctx context.Context, ids []uuid.UUID) (map[uuid.UUID]ClassRef, error) {
	if s.classes == nil || len(ids) == 0 {
		return map[uuid.UUID]ClassRef{}, nil
	}
	return s.classes.Refs(ctx, ids)
}

func withLesson(base string, lesson *string) *string {
	if lesson != nil && *lesson != "" {
		base += " · " + *lesson
	}
	return &base
}

// fillClassLabels: nhãn lớp (WorkTaskController index / show): "Tên lớp · Buổi …".
func (s *Service) fillClassLabels(ctx context.Context, list []Task) error {
	ids := []uuid.UUID{}
	for _, t := range list {
		if t.ClassID != nil {
			ids = append(ids, *t.ClassID)
		}
	}
	refs, err := s.classLabels(ctx, ids)
	if err != nil {
		return err
	}
	for i, t := range list {
		if t.ClassID == nil {
			continue
		}
		if r, ok := refs[*t.ClassID]; ok {
			list[i].ClassLabel = withLesson(r.Name, t.LessonSession)
		}
	}
	return nil
}

// checkClassExists: lớp gắn vào việc phải tồn tại ('class_id' => 'nullable|exists:classes,id').
func (s *Service) checkClassExists(ctx context.Context, id uuid.UUID) error {
	if s.classes == nil {
		return iam.Invalid("Lớp học đã chọn không tồn tại.")
	}
	ok, err := s.classes.Exists(ctx, id)
	if err != nil {
		return err
	}
	if !ok {
		return iam.Invalid("Lớp học đã chọn không tồn tại.")
	}
	return nil
}

// classOptions: lớp người dùng thấy — "Tên (Mã)", kèm " - lịch học" khi withSchedule.
func (s *Service) classOptions(ctx context.Context, a *iam.Access, statuses []string, withSchedule bool) ([]Option, error) {
	out := []Option{}
	if s.classes == nil {
		return out, nil
	}
	list, err := s.classes.Options(ctx, a, statuses)
	if err != nil {
		return out, err
	}
	for _, c := range list {
		label := c.Name + " (" + c.Code + ")"
		if withSchedule && c.ScheduleText != nil && *c.ScheduleText != "" {
			label += " - " + *c.ScheduleText
		}
		out = append(out, Option{c.ID, label})
	}
	return out, nil
}

// ───── Giao việc trợ giảng: gắn lớp / buổi học ─────

type SessionOption struct {
	ID    uuid.UUID `json:"id"`
	Date  string    `json:"date"`
	Label string    `json:"label"`
}

// taClassOptions: lớp đang học / sắp khai giảng mình thấy ("Tên (Mã) - lịch học") và buổi học thật của từng lớp
// (7 ngày trước → 30 ngày tới, không hủy, không phụ đạo) — "Buổi N (dd/mm) · HH:MM-HH:MM".
func (s *Service) taClassOptions(ctx context.Context, a *iam.Access, out *TAAssignOptions) error {
	if s.classes == nil {
		return nil
	}
	list, err := s.classes.Options(ctx, a, []string{"active", "upcoming"})
	if err != nil {
		return err
	}
	ids := make([]uuid.UUID, 0, len(list))
	for _, c := range list {
		label := c.Name + " (" + c.Code + ")"
		if c.ScheduleText != nil && *c.ScheduleText != "" {
			label += " - " + *c.ScheduleText
		}
		out.Classes = append(out.Classes, Option{c.ID, label})
		ids = append(ids, c.ID)
	}
	if len(ids) == 0 {
		return nil
	}
	today := dateOnly(s.now())
	sessions, err := s.classes.Sessions(ctx, ids, today.AddDate(0, 0, -7), today.AddDate(0, 0, 30))
	if err != nil {
		return err
	}
	for _, se := range sessions {
		k := se.ClassID.String()
		out.ClassSessions[k] = append(out.ClassSessions[k], SessionOption{
			ID: se.ID, Date: se.Date.Format(time.DateOnly), Label: sessionLabel(se) + " · " + se.Start + "-" + se.End,
		})
	}
	return nil
}

type taLink struct {
	classID *uuid.UUID
	lesson  *string
	session *ClassSession
}

// taClassLinks: kiểm tra "Gắn lớp" của từng đầu việc (WorkTaskController::taAssignStore): lớp trong phạm vi được xem,
// buổi học thuộc lớp và đúng ngày giao, hoặc gõ tên buổi khi không chọn buổi.
func (s *Service) taClassLinks(ctx context.Context, a *iam.Access, tasks []TATaskInput, day time.Time) ([]taLink, error) {
	out := make([]taLink, len(tasks))
	for i, t := range tasks {
		if !t.AttachClass {
			continue
		}
		no := "Đầu việc #" + strconv.Itoa(i+1) + ": "
		raw := strings.TrimSpace(t.ClassID)
		if raw == "" {
			return nil, iam.Invalid(no + `đã chọn "Gắn lớp" thì phải chọn lớp học.`)
		}
		classID, err := uuid.Parse(raw)
		if err != nil {
			return nil, iam.Invalid(no + "lớp nằm ngoài phạm vi bạn quản lý.")
		}
		ok := false
		if s.classes != nil {
			if ok, err = s.classes.Visible(ctx, a, classID); err != nil {
				return nil, err
			}
		}
		if !ok {
			return nil, iam.Invalid(no + "lớp nằm ngoài phạm vi bạn quản lý.")
		}
		l := taLink{classID: &classID}
		if sid := strings.TrimSpace(t.ClassSessionID); sid != "" {
			id, err := uuid.Parse(sid)
			var se *ClassSession
			if err == nil {
				if se, err = s.classes.Session(ctx, id); err != nil {
					return nil, err
				}
			}
			if se == nil || se.ClassID != classID || se.Date.Format(time.DateOnly) != day.Format(time.DateOnly) {
				return nil, iam.Invalid(no + "buổi học không thuộc lớp hoặc không đúng ngày giao việc.")
			}
			label := sessionLabel(*se)
			l.session, l.lesson = se, &label
		} else {
			name := strings.TrimSpace(t.Session)
			if name == "" {
				return nil, iam.Invalid(no + "vui lòng chọn buổi học.")
			}
			if utf8.RuneCountInString(name) > 255 {
				return nil, iam.Invalid(no + "tên buổi học không được vượt quá 255 ký tự.")
			}
			l.lesson = &name
		}
		out[i] = l
	}
	return out, nil
}

// ───── Portal trợ giảng: lớp trực trong ngày ─────

type PortalSession struct {
	ID        uuid.UUID `json:"id"`
	ClassName string    `json:"class_name"`
	Start     string    `json:"start"`
	End       string    `json:"end"`
	Room      *string   `json:"room"`
	Branch    string    `json:"branch"`
	Makeup    bool      `json:"makeup"`
}

// portalCards: lớp / buổi trong ngày để điền thẻ việc ("Trực lớp: mã · buổi", phòng, giờ ca học).
type portalCards struct {
	refs    map[uuid.UUID]ClassRef
	session map[uuid.UUID]ClassSession
}

// portalClasses: buổi trong ngày (taPortal: forStaff hoặc lớp có việc giao trong ngày, không hủy) và mã lớp của các việc.
func (s *Service) portalClasses(ctx context.Context, ta uuid.UUID, classIDs []uuid.UUID, day time.Time, out *TAPortal) (portalCards, error) {
	pc := portalCards{refs: map[uuid.UUID]ClassRef{}, session: map[uuid.UUID]ClassSession{}}
	if s.classes == nil {
		return pc, nil
	}
	sessions, err := s.classes.DaySessions(ctx, ta, classIDs, day)
	if err != nil {
		return pc, err
	}
	for _, se := range sessions {
		out.Sessions = append(out.Sessions, PortalSession{
			ID: se.ID, ClassName: se.ClassName, Start: se.Start, End: se.End, Room: se.Room, Branch: se.Branch, Makeup: se.Makeup,
		})
		// Nhiều buổi cùng lớp trong ngày: lấy buổi sau cùng (keyBy của Laravel).
		pc.session[se.ClassID] = se
	}
	if pc.refs, err = s.classLabels(ctx, classIDs); err != nil {
		return pc, err
	}
	return pc, nil
}

func (pc portalCards) fill(c *TACard, classID uuid.UUID, lesson *string) {
	if r, ok := pc.refs[classID]; ok {
		base := r.Code
		if base == "" {
			base = r.Name
		}
		c.ClassLabel = withLesson(base, lesson)
	}
	if se, ok := pc.session[classID]; ok {
		c.Room = se.Room
		if se.Start != "" && se.End != "" {
			t := se.Start + " - " + se.End
			c.SessionTime = &t
		}
	}
}
