package dashboard

import (
	"context"
	"math"
	"regexp"
	"sort"
	"strings"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// Chất lượng giảng dạy theo tháng (DashboardController::teaching + TeachingQuality):
//   - Giáo viên / trợ giảng: số liệu của mình + từng lớp đang giữ.
//   - Người theo dõi giáo viên (kpi.view, bảng điều hành, Học thuật): bảng theo giáo viên (GV chính) trong phạm vi lớp.
//   - Học thuật / Admin / Quản lý: thêm báo cáo tháng của giáo viên và order học thuật trong tháng.
// Chuyên cần = (Có mặt + Đi muộn) / lượt điểm danh trong tháng. Bài về nhà, điểm mini test, ngày công / đi muộn / phép /
// vi phạm chờ các phân hệ nguồn (để trống).

var monthPattern = regexp.MustCompile(`^\d{4}-(0[1-9]|1[0-2])$`)

type ClassMetric struct {
	ID         uuid.UUID  `json:"id"`
	Code       string     `json:"code"`
	Name       string     `json:"name"`
	TeacherID  *uuid.UUID `json:"teacher_id"`
	Students   int        `json:"students"`
	New        int        `json:"new"`
	Dropped    int        `json:"dropped"`
	Attendance *float64   `json:"attendance"`
	Homework   *float64   `json:"homework"`
	Score      *float64   `json:"score"`
}

type Totals struct {
	Classes    int      `json:"classes"`
	Students   int      `json:"students"`
	New        int      `json:"new"`
	Dropped    int      `json:"dropped"`
	Attendance *float64 `json:"attendance"`
	Homework   *float64 `json:"homework"`
	Score      *float64 `json:"score"`
}

type TeachingMine struct {
	Work    *WorkStats    `json:"work"` // nil = chờ phân hệ chấm công
	Totals  Totals        `json:"totals"`
	Classes []ClassMetric `json:"classes"`
}

// TeamRow: một dòng bảng theo giáo viên (tổng các lớp + chấm công; chấm công nil khi chưa có phân hệ).
type TeamRow struct {
	ID   uuid.UUID `json:"id"`
	Name string    `json:"name"`
	Totals
	Work *WorkStats `json:"work"`
}

type TeachingTeam struct {
	Rows []TeamRow `json:"rows"`
}

type TeacherReportClass struct {
	Class       string  `json:"class"`
	Situation   *string `json:"situation"`
	Attention   *string `json:"attention"`
	Solution    *string `json:"solution"`
	NeedSupport bool    `json:"need_support"`
	SupportNote *string `json:"support_note"`
}

type TeacherReport struct {
	ID           uuid.UUID            `json:"id"`
	Teacher      string               `json:"teacher"`
	Submitted    bool                 `json:"submitted"`
	UpdatedAt    *time.Time           `json:"updated_at"`
	Progress     *string              `json:"progress"`
	Difficulties *string              `json:"difficulties"`
	Proposals    *string              `json:"proposals"`
	Classes      []TeacherReportClass `json:"classes"`
}

type Teaching struct {
	Month          string          `json:"month"`
	MonthLabel     string          `json:"month_label"`
	Months         []Option        `json:"months"`
	Mine           *TeachingMine   `json:"mine"`
	ReportURL      *string         `json:"report_url"`
	Team           *TeachingTeam   `json:"team"`
	TeacherReports []TeacherReport `json:"teacher_reports"`
	AcademicOrders []AcademicOrder `json:"academic_orders"`
	OrdersURL      *string         `json:"orders_url"`
	// Pending: phần số liệu chờ phân hệ nguồn (hiện ghi chú dưới tiêu đề).
	Pending []string `json:"pending"`
}

var teachingRoles = []string{iam.RoleTeacherFulltime, iam.RoleTeacherParttime, iam.RoleAssistant}

func monthLabel(from time.Time) string { return "Tháng " + from.Format("01/2006") }

// monthOptions: tháng hiện tại và 6 tháng trước (ReportPeriod::monthOptions).
func monthOptions(c Clock) []Option {
	out := make([]Option, 0, 7)
	for i := 0; i <= 6; i++ {
		m := c.MonthStart.AddDate(0, -i, 0)
		out = append(out, Option{m.Format("2006-01"), monthLabel(m)})
	}
	return out
}

// pickMonth: tháng ?month=YYYY-MM, sai định dạng thì tháng hiện tại; trả ngày đầu và cuối (23:59:59) của tháng.
func pickMonth(v string, c Clock) (time.Time, time.Time) {
	from := c.MonthStart
	if monthPattern.MatchString(v) {
		if t, err := time.ParseInLocation("2006-01", v, vnZone); err == nil {
			from = t
		}
	}
	return from, from.AddDate(0, 1, 0).Add(-time.Nanosecond)
}

func (s *Service) teaching(ctx context.Context, a *iam.Access, c Clock, month string) (*Teaching, error) {
	isTeaching := hasRole(a, teachingRoles...)
	watches := a.Can("kpi.view") || a.Can(PermOperations) || a.Can(PermAcademic)
	if !isTeaching && !watches {
		return nil, nil
	}
	from, to := pickMonth(month, c)
	key := from.Format("2006-01")
	out := &Teaching{Month: key, MonthLabel: monthLabel(from), Months: monthOptions(c), Pending: []string{}}
	out.Pending = append(out.Pending, "Bài về nhà, điểm trung bình: "+pendingHomework)
	workPending := false

	if isTeaching {
		stats, err := s.d.Classes.TeachingOf(ctx, a.UserID, from, to)
		if err != nil {
			return nil, err
		}
		classes := classMetrics(stats)
		work, ok, err := s.d.Work.Stats(ctx, []uuid.UUID{a.UserID}, from, to)
		if err != nil {
			return nil, err
		}
		mine := &TeachingMine{Totals: totals(classes), Classes: classes}
		if ok {
			w := work[a.UserID]
			mine.Work = &w
		} else {
			workPending = true
		}
		out.Mine = mine
		out.ReportURL = s.d.Reports.TeacherMonthlyHref(a, key)
	}

	if watches {
		stats, names, err := s.d.Classes.TeamClasses(ctx, a, from, to)
		if err != nil {
			return nil, err
		}
		rows, ids := teamRows(classMetrics(stats), names)
		work, ok, err := s.d.Work.Stats(ctx, ids, from, to)
		if err != nil {
			return nil, err
		}
		if ok {
			for i := range rows {
				w := work[rows[i].ID]
				rows[i].Work = &w
			}
		} else {
			workPending = true
		}
		out.Team = &TeachingTeam{Rows: rows}

		if a.Can(PermAcademic) || a.Can(PermOperations) {
			if out.TeacherReports, err = s.teacherReports(ctx, rows, key); err != nil {
				return nil, err
			}
			if out.AcademicOrders, err = s.d.Orders.Academic(ctx, ids, from, to, 10); err != nil {
				return nil, err
			}
			if out.AcademicOrders == nil {
				out.AcademicOrders = []AcademicOrder{}
			}
			if a.CanAny("material_order.create", "material_order.view_all", "material_order.process_ops", "material_order.process_academic") {
				out.OrdersURL = ptr("/material-orders")
			}
		}
	}
	if workPending {
		out.Pending = append(out.Pending, "Ngày công, đi muộn, phép, vi phạm: "+pendingWork)
	}
	return out, nil
}

// classMetrics: số liệu từng lớp (tỷ lệ chuyên cần làm tròn 1 chữ số).
func classMetrics(stats []ClassStats) []ClassMetric {
	out := make([]ClassMetric, 0, len(stats))
	for _, st := range stats {
		m := ClassMetric{ID: st.ID, Code: st.Code, Name: st.Name, TeacherID: st.TeacherID, Students: st.Students, New: st.New, Dropped: st.Dropped}
		if st.AttendanceTotal > 0 {
			m.Attendance = ptr(round1(float64(st.Attended) / float64(st.AttendanceTotal) * 100))
		}
		out = append(out, m)
	}
	return out
}

func round1(v float64) float64 { return math.Round(v*10) / 10 }

// totals: cộng dồn nhiều lớp; tỷ lệ là trung bình có trọng số theo sĩ số (tối thiểu 1), lớp chưa có dữ liệu không tính.
func totals(classes []ClassMetric) Totals {
	t := Totals{Classes: len(classes)}
	for _, c := range classes {
		t.Students += c.Students
		t.New += c.New
		t.Dropped += c.Dropped
	}
	weighted := func(get func(ClassMetric) *float64) *float64 {
		var sum, weight float64
		for _, c := range classes {
			if v := get(c); v != nil {
				w := float64(max(1, c.Students))
				sum += *v * w
				weight += w
			}
		}
		if weight == 0 {
			return nil
		}
		return ptr(round1(sum / weight))
	}
	t.Attendance = weighted(func(c ClassMetric) *float64 { return c.Attendance })
	t.Homework = weighted(func(c ClassMetric) *float64 { return c.Homework })
	t.Score = weighted(func(c ClassMetric) *float64 { return c.Score })
	return t
}

// teamRows: một dòng mỗi giáo viên chính (theo tên, không phân biệt hoa thường), kèm danh sách id.
func teamRows(classes []ClassMetric, names map[uuid.UUID]string) ([]TeamRow, []uuid.UUID) {
	byTeacher := map[uuid.UUID][]ClassMetric{}
	var ids []uuid.UUID
	for _, c := range classes {
		if c.TeacherID == nil {
			continue
		}
		if _, ok := byTeacher[*c.TeacherID]; !ok {
			ids = append(ids, *c.TeacherID)
		}
		byTeacher[*c.TeacherID] = append(byTeacher[*c.TeacherID], c)
	}
	rows := make([]TeamRow, 0, len(ids))
	for _, id := range ids {
		name := names[id]
		if name == "" {
			name = "—"
		}
		rows = append(rows, TeamRow{ID: id, Name: name, Totals: totals(byTeacher[id])})
	}
	sort.SliceStable(rows, func(i, j int) bool { return strings.ToLower(rows[i].Name) < strings.ToLower(rows[j].Name) })
	ids = make([]uuid.UUID, 0, len(rows))
	for _, r := range rows {
		ids = append(ids, r.ID)
	}
	return rows, ids
}

// teacherReports: báo cáo tháng của từng giáo viên trong bảng (chưa nộp vẫn liệt kê); đã nộp lên trước, rồi theo tên.
func (s *Service) teacherReports(ctx context.Context, rows []TeamRow, month string) ([]TeacherReport, error) {
	ids := make([]uuid.UUID, 0, len(rows))
	for _, r := range rows {
		ids = append(ids, r.ID)
	}
	out := make([]TeacherReport, 0, len(rows))
	if len(ids) == 0 {
		return out, nil
	}
	reports, err := s.d.Reports.TeacherMonthly(ctx, ids, month)
	if err != nil {
		return nil, err
	}
	var classIDs []uuid.UUID
	for _, r := range reports {
		for _, c := range r.Classes {
			if id, err := uuid.Parse(c.ClassID); err == nil {
				classIDs = append(classIDs, id)
			}
		}
	}
	names, err := s.d.Classes.Names(ctx, classIDs)
	if err != nil {
		return nil, err
	}
	for _, row := range rows {
		tr := TeacherReport{ID: row.ID, Teacher: row.Name, Classes: []TeacherReportClass{}}
		if r, ok := reports[row.ID]; ok {
			tr.Submitted, tr.UpdatedAt = true, ptr(r.UpdatedAt)
			tr.Progress, tr.Difficulties, tr.Proposals = r.Progress, r.Difficulties, r.Proposals
			for _, c := range r.Classes {
				name := "Lớp"
				if id, err := uuid.Parse(c.ClassID); err == nil && names[id] != "" {
					name = names[id]
				}
				tr.Classes = append(tr.Classes, TeacherReportClass{Class: name, Situation: c.Situation, Attention: c.Attention,
					Solution: c.Solution, NeedSupport: c.NeedSupport, SupportNote: c.SupportNote})
			}
		}
		out = append(out, tr)
	}
	sort.SliceStable(out, func(i, j int) bool {
		if out[i].Submitted != out[j].Submitted {
			return out[i].Submitted
		}
		return strings.ToLower(out[i].Teacher) < strings.ToLower(out[j].Teacher)
	})
	return out, nil
}
