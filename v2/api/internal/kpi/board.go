package kpi

import (
	"context"
	"math"
	"strconv"
	"strings"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/kpi/kpidb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/kpi/kpisrc"
)

// ───────────── Bảng KPI tự động (KpiBoardService, WorkTaskController::kpiDashboard) ─────────────

// BoardRoles: giáo viên, trợ giảng và Học vụ.
var BoardRoles = []string{iam.RoleTeacherFulltime, iam.RoleTeacherParttime, iam.RoleAssistant, iam.RoleAcademicStaff}

const homeworkPending = "Chờ phân hệ Bài tập chuyển sang hệ thống mới: chưa có bài giao / bài nộp để tính tỷ lệ hoàn thành."

type BoardRow struct {
	ID               uuid.UUID `json:"id"`
	Name             string    `json:"name"`
	Code             string    `json:"code"`
	Classes          int       `json:"classes"`
	Retention        *float64  `json:"retention"`
	RetentionDetail  string    `json:"retention_detail"`
	Attendance       *float64  `json:"attendance"`
	AttendanceDetail string    `json:"attendance_detail"`
	Homework         *float64  `json:"homework"`
	HomeworkDetail   string    `json:"homework_detail"`
	Tasks            *float64  `json:"tasks"`
	TasksDetail      string    `json:"tasks_detail"`
}

type BoardPage struct {
	Staff           []BoardRow `json:"staff"`
	Total           int        `json:"total"`
	Page            int        `json:"page"`
	PerPage         int        `json:"per_page"`
	StaffOptions    []Option   `json:"staff_options"`
	UserID          *string    `json:"user_id"`
	Month           string     `json:"month"`
	MonthTo         string     `json:"month_to"`
	PeriodLabel     string     `json:"period_label"`
	CanSeeStaff     bool       `json:"can_see_staff"`
	HomeworkPending string     `json:"homework_pending"`
	Pending         *string    `json:"pending"`
}

type BoardFilter struct {
	Month   string
	MonthTo string
	UserID  string
	Page    int
}

func rate(part, total int) *float64 {
	if total <= 0 {
		return nil
	}
	return ptr(math.Round(float64(part)/float64(total)*1000) / 10)
}

// boardRange: kỳ báo cáo từ tháng → đến tháng (đảo nếu ngược), mặc định tháng hiện tại.
func (s *Service) boardRange(f BoardFilter) (string, string, time.Time, time.Time, error) {
	month, to := strings.TrimSpace(f.Month), strings.TrimSpace(f.MonthTo)
	if month == "" {
		month = s.now().In(vnZone).Format("2006-01")
	}
	if to == "" {
		to = month
	}
	a, err1 := time.ParseInLocation("2006-01", month, vnZone)
	b, err2 := time.ParseInLocation("2006-01", to, vnZone)
	if err1 != nil || err2 != nil {
		return "", "", time.Time{}, time.Time{}, iam.Invalid("Tháng không hợp lệ (định dạng YYYY-MM).")
	}
	if b.Before(a) {
		a, b = b, a
		month, to = to, month
	}
	return month, to, a, b.AddDate(0, 1, 0).Add(-time.Nanosecond), nil
}

// boardScope: phạm vi Công việc — Của tôi → chỉ KPI của mình; Chi nhánh → nhân sự chi nhánh mình; Toàn hệ thống → mọi người.
func boardScope(a *iam.Access) (canSee bool, branches []uuid.UUID) {
	level := a.Level("work_task")
	if level == iam.ScopeBranch {
		branches = a.BranchIDs()
		if branches == nil {
			branches = []uuid.UUID{}
		}
	}
	return level != iam.ScopeOwn, branches
}

func (s *Service) Board(ctx context.Context, a *iam.Access, f BoardFilter, all bool) (*BoardPage, error) {
	month, monthTo, from, to, err := s.boardRange(f)
	if err != nil {
		return nil, err
	}
	canSee, branches := boardScope(a)
	var user *uuid.UUID
	if id, err := uuid.Parse(f.UserID); err == nil {
		user = &id
	}
	if !canSee {
		user = &a.UserID
	}
	page := max(f.Page, 1)
	lim, off := int32(perPage), int32((page-1)*perPage)
	if all {
		lim, off = 100000, 0
	}
	label := "Tháng " + from.Format("01/2006")
	if monthTo != month {
		label += " - Tháng " + to.Format("01/2006")
	}
	label += " (" + from.Format("02/01/2006") + " - " + to.Format("02/01/2006") + ")"
	out := &BoardPage{Staff: []BoardRow{}, Page: page, PerPage: perPage, StaffOptions: []Option{}, Month: month, MonthTo: monthTo,
		PeriodLabel: label, CanSeeStaff: canSee, HomeworkPending: homeworkPending}
	if user != nil && canSee {
		out.UserID = ptr(user.String())
	}
	base := kpidb.BoardStaffParams{Roles: BoardRoles, OwnOnly: !canSee, ViewerID: a.UserID, ScopeBranches: branches}
	if canSee && !all {
		opts := base
		opts.Lim = 100000
		rows, err := s.q.BoardStaff(ctx, opts)
		if err != nil {
			return nil, err
		}
		for _, r := range rows {
			lbl := r.FullName
			if r.Code != "" {
				lbl += " (" + r.Code + ")"
			}
			out.StaffOptions = append(out.StaffOptions, Option{r.ID.String(), lbl})
		}
	}
	p := base
	p.UserID, p.Lim, p.Off = user, lim, off
	rows, err := s.q.BoardStaff(ctx, p)
	if err != nil {
		return nil, err
	}
	if len(rows) > 0 {
		out.Total = int(rows[0].Total)
	}
	ids := make([]uuid.UUID, 0, len(rows))
	for _, r := range rows {
		ids = append(ids, r.ID)
	}
	classes, tasks := map[uuid.UUID]kpisrc.ClassStats{}, map[uuid.UUID]kpisrc.TaskStats{}
	var pending []string
	if s.classes != nil && len(ids) > 0 {
		if classes, err = s.classes.KpiBoardClasses(ctx, ids, from, to); err != nil {
			return nil, err
		}
	} else if s.classes == nil {
		pending = append(pending, "Lớp học")
	}
	if s.tasks != nil && len(ids) > 0 {
		if tasks, err = s.tasks.KpiBoardTasks(ctx, ids, from, to); err != nil {
			return nil, err
		}
	} else if s.tasks == nil {
		pending = append(pending, "Công việc")
	}
	if len(pending) > 0 {
		out.Pending = ptr("Chờ phân hệ " + strings.Join(pending, " / ") + " chuyển sang hệ thống mới.")
	}
	for _, r := range rows {
		c, t := classes[r.ID], tasks[r.ID]
		out.Staff = append(out.Staff, BoardRow{ID: r.ID, Name: r.FullName, Code: r.Code, Classes: c.Classes,
			Retention: rate(c.Retained, c.Students), RetentionDetail: strconv.Itoa(c.Retained) + "/" + strconv.Itoa(c.Students) + " học viên",
			Attendance:       rate(c.AttendancePresent, c.AttendanceTotal),
			AttendanceDetail: strconv.Itoa(c.AttendancePresent) + "/" + strconv.Itoa(c.AttendanceTotal) + " lượt",
			HomeworkDetail:   "0/0 bài",
			Tasks:            rate(t.Done, t.Total), TasksDetail: strconv.Itoa(t.Done) + "/" + strconv.Itoa(t.Total) + " việc"})
	}
	return out, nil
}

// BoardExport: bảng KPI toàn bộ nhân sự theo bộ lọc (không phân trang) cho file xuất.
func (s *Service) BoardExport(ctx context.Context, a *iam.Access, f BoardFilter) (string, [][]string, error) {
	pg, err := s.Board(ctx, a, f, true)
	if err != nil {
		return "", nil, err
	}
	fmtRate := func(v *float64) string {
		if v == nil {
			return "Chưa có dữ liệu"
		}
		return strconv.FormatFloat(*v, 'f', -1, 64) + "%"
	}
	rows := [][]string{{"Mã NS", "Nhân sự", "Số lớp", "Chuyên cần", "Chi tiết chuyên cần", "Bài tập", "Chi tiết bài tập",
		"Công việc", "Chi tiết công việc", "Giữ chân", "Chi tiết giữ chân"}}
	for _, r := range pg.Staff {
		rows = append(rows, []string{r.Code, r.Name, strconv.Itoa(r.Classes), fmtRate(r.Attendance), r.AttendanceDetail,
			fmtRate(r.Homework), r.HomeworkDetail, fmtRate(r.Tasks), r.TasksDetail, fmtRate(r.Retention), r.RetentionDetail})
	}
	name := "kpi-" + pg.Month
	if pg.MonthTo != pg.Month {
		name += "-" + pg.MonthTo
	}
	return name + ".csv", rows, nil
}
