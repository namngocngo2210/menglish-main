package teacher

import (
	"context"
	"fmt"
	"strings"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/teacher/teacherdb"
)

// Báo cáo giảng dạy tháng của chính giáo viên (TeacherPortalController::generalReport): giờ dạy / phiên chấm công
// (phân hệ Lương & chấm công), lượt điểm danh đã ghi, điểm mini test đã nhập, lớp GV chính / GVNN.

type ReportStats struct {
	Hours            string `json:"hours"`
	Sessions         int    `json:"sessions"`
	ValidHours       string `json:"valid_hours"`
	ValidSessions    int    `json:"valid_sessions"`
	AttendanceMarked int    `json:"attendance_marked"`
	ScoresEntered    int    `json:"scores_entered"`
}

type ReportClass struct {
	ID        uuid.UUID `json:"id"`
	Name      string    `json:"name"`
	Code      string    `json:"code"`
	Course    string    `json:"course"`
	IsMain    bool      `json:"is_main"`
	IsForeign bool      `json:"is_foreign"`
	Status    string    `json:"status"`
}

type ReportTimesheet struct {
	ID        uuid.UUID `json:"id"`
	Date      string    `json:"date"`
	ClassName string    `json:"class_name"`
	Type      string    `json:"type"`
	Hours     string    `json:"hours"`
	Status    string    `json:"status"`
}

type GeneralReport struct {
	Month      int               `json:"month"`
	Year       int               `json:"year"`
	Period     string            `json:"period"` // YYYY-MM
	Stats      ReportStats       `json:"stats"`
	MyClasses  []ReportClass     `json:"my_classes"`
	Timesheets []ReportTimesheet `json:"timesheets"`
	// WorkPending: chấm công giờ dạy chờ phân hệ Lương & chấm công (giờ dạy / phiên để trống).
	WorkPending string `json:"work_pending"`
}

func hoursText(v float64) string { return fmt.Sprintf("%.1fh", v) }

// parsePeriod: "YYYY-MM" → ngày 1 của tháng; trống / sai → tháng hiện tại.
func (s *Service) parsePeriod(period string) time.Time {
	if t, err := time.Parse("2006-01", strings.TrimSpace(period)); err == nil && t.Year() >= 2000 && t.Year() <= 2100 {
		return t
	}
	now := s.today()
	return time.Date(now.Year(), now.Month(), 1, 0, 0, 0, 0, time.UTC)
}

func (s *Service) GeneralReport(ctx context.Context, a *iam.Access, period string) (*GeneralReport, error) {
	if err := guardTeacher(a); err != nil {
		return nil, err
	}
	from := s.parsePeriod(period)
	to := from.AddDate(0, 1, -1)
	out := &GeneralReport{Month: int(from.Month()), Year: from.Year(), Period: from.Format("2006-01"),
		MyClasses: []ReportClass{}, Timesheets: []ReportTimesheet{}}
	var err error
	if out.Stats.AttendanceMarked, err = s.count(s.q.AttendanceMarkedCount(ctx, teacherdb.AttendanceMarkedCountParams{UserID: &a.UserID, FromDate: from, ToDate: to})); err != nil {
		return nil, err
	}
	if out.Stats.ScoresEntered, err = s.count(s.q.ScoresEnteredCount(ctx, teacherdb.ScoresEnteredCountParams{UserID: &a.UserID, FromDate: from, ToDate: to})); err != nil {
		return nil, err
	}
	classes, err := s.q.MyClasses(ctx, &a.UserID)
	if err != nil {
		return nil, err
	}
	for _, c := range classes {
		course := deref(c.CourseName)
		if course == "" {
			course = deref(c.Program)
		}
		out.MyClasses = append(out.MyClasses, ReportClass{ID: c.ID, Name: c.Name, Code: c.Code, Course: course, Status: c.Status,
			IsMain: c.TeacherID != nil && *c.TeacherID == a.UserID, IsForeign: c.ForeignTeacherID != nil && *c.ForeignTeacherID == a.UserID})
	}
	if !s.work.Available() {
		out.WorkPending = workPending
		out.Stats.Hours, out.Stats.ValidHours = "—", "—"
		return out, nil
	}
	m, err := s.work.Month(ctx, a.UserID, from, to)
	if err != nil {
		return nil, err
	}
	if m == nil {
		m = &WorkMonth{}
	}
	out.Stats.Hours, out.Stats.Sessions = hoursText(m.Hours), m.Sessions
	out.Stats.ValidHours, out.Stats.ValidSessions = hoursText(m.ValidHours), m.ValidSessions
	for _, r := range m.Rows {
		out.Timesheets = append(out.Timesheets, ReportTimesheet{ID: r.ID, Date: r.Date, ClassName: r.ClassName, Type: r.Type,
			Hours: hoursText(r.Hours), Status: r.Status})
	}
	return out, nil
}

func (s *Service) count(n int32, err error) (int, error) { return int(n), err }
