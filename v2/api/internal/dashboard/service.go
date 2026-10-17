// Package dashboard: Tổng quan (DashboardController) và Báo cáo & sự vụ lớp (AcademicDashboardController) của bản Laravel.
// Không có bảng riêng: đọc số liệu của các phân hệ qua interface trong sources.go.
package dashboard

import (
	"context"
	"fmt"
	"log/slog"
	"slices"
	"strconv"
	"strings"
	"time"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// Quyền dùng ở Tổng quan (tên trong danh mục quyền của Laravel).
const (
	PermOperations = "dashboard.operations"
	PermAcademic   = "dashboard.academic"
	// PermStaff: quyền đối tượng mọi nhân sự đều có (cổng nhân sự); học viên / phụ huynh không có (isPortalStudentOnly).
	PermStaff = "portal.staff"
	// PermClassManager: Báo cáo & sự vụ lớp (route academic.dashboards.* gắn can:class.update).
	PermClassManager = "class.update"
)

// Ghi chú cho số liệu mà phân hệ nguồn chưa có ở v2.
const (
	pendingTuition      = "Chờ phân hệ Học phí"
	pendingPayroll      = "Chờ phân hệ Lương"
	pendingSyllabus     = "Chờ phân hệ Giáo trình & Big Test"
	pendingTickets      = "Chờ phân hệ Ticket hỗ trợ"
	pendingClassReports = "Chờ phân hệ Báo cáo trực lớp"
	pendingProjects     = "Chờ phân hệ Dự án học thuật"
	pendingWork         = "Chờ phân hệ Chấm công & KPI"
	pendingHomework     = "Chờ cổng Giáo viên (bài tập, mini test)"
)

// vnZone: giờ Việt Nam (+7, không đổi giờ mùa hè) như các phân hệ khác.
var vnZone = time.FixedZone("ICT", 7*3600)

type Deps struct {
	Leads    Leads
	Tasks    Tasks
	Classes  Classes
	Students Students
	Reports  Reports
	Orders   Orders
	Branches Branches
	// Finance, Work: phân hệ đang làm song song; nil = chưa có.
	Finance Finance
	Work    StaffWork
	// Projects: hạn mốc dự án học thuật; nil = lịch ghi "Chờ phân hệ Dự án học thuật".
	Projects Projects
}

type Service struct {
	d   Deps
	log *slog.Logger
	now func() time.Time
}

func NewService(d Deps, log *slog.Logger) *Service {
	if d.Finance == nil {
		d.Finance = noFinance{}
	}
	if d.Work == nil {
		d.Work = noStaffWork{}
	}
	return &Service{d: d, log: log, now: time.Now}
}

// SetClock: đặt giờ hiện tại (test).
func (s *Service) SetClock(now func() time.Time) { s.now = now }

func dayOf(t time.Time) time.Time {
	t = t.In(vnZone)
	return time.Date(t.Year(), t.Month(), t.Day(), 0, 0, 0, 0, vnZone)
}

func endOfDay(t time.Time) time.Time { return dayOf(t).AddDate(0, 0, 1).Add(-time.Nanosecond) }

func clockAt(now time.Time) Clock {
	today := dayOf(now)
	monthStart := time.Date(today.Year(), today.Month(), 1, 0, 0, 0, 0, vnZone)
	return Clock{
		Now: now.In(vnZone), Today: today, TodayEnd: endOfDay(today),
		// Carbon endOfWeek: Chủ nhật.
		WeekEnd:    today.AddDate(0, 0, (7-int(today.Weekday()))%7),
		MonthStart: monthStart, MonthEnd: monthStart.AddDate(0, 1, 0).Add(-time.Nanosecond),
	}
}

// ───────────── Kiểu trả về (giữ tên trường như props của Dashboard.vue, viết snake_case) ─────────────

// Stat: thẻ số liệu. Value nil = chưa có nguồn (Pending ghi lý do).
type Stat struct {
	Label   string  `json:"label"`
	Value   *string `json:"value"`
	Icon    string  `json:"icon"`
	Tone    string  `json:"tone"`
	Hint    *string `json:"hint"`
	Href    *string `json:"href"`
	Pending *string `json:"pending,omitempty"`
}

// Queue: hàng chờ cần xử lý (số + link).
type Queue struct {
	Label   string  `json:"label"`
	Value   *int64  `json:"value"`
	Icon    string  `json:"icon"`
	Href    *string `json:"href"`
	Hint    *string `json:"hint"`
	Pending *string `json:"pending,omitempty"`
}

type AgendaEntry struct {
	Time      *string `json:"time"`
	Past      bool    `json:"past"`
	Kind      string  `json:"kind"`
	KindLabel string  `json:"kind_label"`
	Title     string  `json:"title"`
	Subtitle  string  `json:"subtitle"`
	Href      *string `json:"href"`
}

type AgendaDay struct {
	Date  string        `json:"date"`
	Label string        `json:"label"`
	Items []AgendaEntry `json:"items"`
}

type Agenda struct {
	Days  []AgendaDay `json:"days"`
	Total int         `json:"total"`
	More  int         `json:"more"`
	Range string      `json:"range"`
	// Pending: nguồn lịch chưa có ở v2 (vd. Big Test).
	Pending []string `json:"pending"`
}

type MyTasks struct {
	Items []TaskItem `json:"items"`
	Total int64      `json:"total"`
	URL   *string    `json:"url"`
}

type RoleDashboard struct {
	Type      string  `json:"type"` // admin | manager | academic | personal
	Title     string  `json:"title"`
	Scope     string  `json:"scope"`
	UpdatedAt string  `json:"updated_at"`
	Stats     []Stat  `json:"stats"`
	Queues    []Queue `json:"queues"`
	// Bảng điều hành
	OverdueTasks []OverdueTask `json:"overdue_tasks,omitempty"`
	// Học thuật: đề xuất giãn tiến độ / Big Test chờ phân hệ Giáo trình
	PendingAdjustments []any    `json:"pending_adjustments,omitempty"`
	UpcomingBigTests   []any    `json:"upcoming_big_tests,omitempty"`
	AcademicPending    *string  `json:"academic_pending,omitempty"`
	Agenda             *Agenda  `json:"agenda,omitempty"`
	MyTasks            *MyTasks `json:"my_tasks,omitempty"`
}

type MissingDay struct {
	Label    string `json:"label"`
	Href     string `json:"href"`
	Deadline string `json:"deadline"`
	Overdue  bool   `json:"overdue"`
}

type ReturnedDay struct {
	Label string  `json:"label"`
	Href  string  `json:"href"`
	Note  *string `json:"note"`
}

type DailyReport struct {
	Missing        []MissingDay  `json:"missing"`
	Returned       []ReturnedDay `json:"returned"`
	TodaySubmitted bool          `json:"today_submitted"`
	TodayHref      string        `json:"today_href"`
}

type Overview struct {
	IsOperations  bool           `json:"is_operations"`
	RoleDashboard *RoleDashboard `json:"role_dashboard"`
	DailyReport   *DailyReport   `json:"daily_report"`
	Teaching      *Teaching      `json:"teaching"`
}

// ───────────── Dựng Tổng quan ─────────────

// Overview: Tổng quan theo vai trò (DashboardController::__invoke). month = tháng của khối chất lượng giảng dạy.
func (s *Service) Overview(ctx context.Context, a *iam.Access, month string) (*Overview, error) {
	c := clockAt(s.now())
	out := &Overview{IsOperations: a.Can(PermOperations)}
	var err error
	switch {
	case out.IsOperations:
		out.RoleDashboard, err = s.operations(ctx, a, c)
	case a.Can(PermAcademic):
		out.RoleDashboard, err = s.academic(ctx, a, c)
	default:
		out.RoleDashboard, err = s.personal(ctx, a, c)
	}
	if err != nil {
		return nil, err
	}
	if out.DailyReport, err = s.dailyReport(ctx, a, c); err != nil {
		return nil, err
	}
	if out.Teaching, err = s.teaching(ctx, a, c, month); err != nil {
		return nil, err
	}
	return out, nil
}

// dailyReport: băng "Báo cáo ngày" cho Học vụ / Học thuật (ngày còn thiếu trong 14 ngày qua, báo cáo cần bổ sung, hôm nay).
func (s *Service) dailyReport(ctx context.Context, a *iam.Access, c Clock) (*DailyReport, error) {
	if !a.Can("staff_report.submit") {
		return nil, nil
	}
	due, err := s.d.Reports.DailyDue(ctx, a, c)
	if err != nil || due == nil {
		return nil, err
	}
	out := &DailyReport{Missing: []MissingDay{}, Returned: []ReturnedDay{}, TodaySubmitted: due.TodaySubmitted, TodayHref: due.TodayHref}
	// Mới nhất trước như Laravel (->reverse()).
	for i := len(due.Missing) - 1; i >= 0; i-- {
		d := due.Missing[i]
		deadline := dailyDeadline(d.Date)
		out.Missing = append(out.Missing, MissingDay{
			Label: d.Date.In(vnZone).Format("02/01"), Href: d.Href, Deadline: deadline.Format("15:04 02/01"),
			Overdue: deadline.Before(c.Now),
		})
	}
	for _, d := range due.Returned {
		out.Returned = append(out.Returned, ReturnedDay{Label: d.Date.In(vnZone).Format("02/01"), Href: d.Href, Note: d.Note})
	}
	return out, nil
}

// dailyDeadline: hạn nộp báo cáo của ngày: 09:00 sáng hôm sau.
func dailyDeadline(day time.Time) time.Time { return dayOf(day).AddDate(0, 0, 1).Add(9 * time.Hour) }

// ───────────── Định dạng ─────────────

// formatNumber như number_format() của PHP: phân tách hàng nghìn bằng dấu phẩy.
func formatNumber(n int64) string {
	neg := n < 0
	if neg {
		n = -n
	}
	digits := strconv.FormatInt(n, 10)
	var b strings.Builder
	for i, ch := range digits {
		if i > 0 && (len(digits)-i)%3 == 0 {
			b.WriteByte(',')
		}
		b.WriteRune(ch)
	}
	if neg {
		return "-" + b.String()
	}
	return b.String()
}

// formatMoney như Money::format: 1.234.567 đ.
func formatMoney(n int64) string { return strings.ReplaceAll(formatNumber(n), ",", ".") + " đ" }

func ptr[T any](v T) *T { return &v }

func numStat(label string, n int64, icon, tone string, hint *string, href *string) Stat {
	return Stat{Label: label, Value: ptr(formatNumber(n)), Icon: icon, Tone: tone, Hint: hint, Href: href}
}

func pendingStat(label, icon, why string) Stat {
	return Stat{Label: label, Icon: icon, Tone: "default", Pending: ptr(why)}
}

func queue(label string, n int64, icon string, href string, hint *string) Queue {
	return Queue{Label: label, Value: &n, Icon: icon, Href: &href, Hint: hint}
}

func pendingQueue(label, icon, why string) Queue {
	return Queue{Label: label, Icon: icon, Pending: ptr(why)}
}

func hintIf(cond bool, format string, args ...any) *string {
	if !cond {
		return nil
	}
	return ptr(fmt.Sprintf(format, args...))
}

func toneIf(cond bool, yes, no string) string {
	if cond {
		return yes
	}
	return no
}

func hasRole(a *iam.Access, roles ...string) bool {
	return slices.ContainsFunc(a.Roles, func(r string) bool { return slices.Contains(roles, r) })
}

// primaryBranchName: tên chi nhánh chính ("" nếu chưa gán).
func primaryBranchName(a *iam.Access) string {
	for _, b := range a.Branches {
		if b.Primary {
			return b.Name
		}
	}
	if len(a.Branches) > 0 {
		return a.Branches[0].Name
	}
	return ""
}

// dayLabel: "Hôm nay · 17/10", "Ngày mai · 18/10", "Thứ 2 · 19/10".
func dayLabel(day, today time.Time) string {
	d := dayOf(day)
	switch {
	case d.Equal(today):
		return "Hôm nay · " + d.Format("02/01")
	case d.Equal(today.AddDate(0, 0, 1)):
		return "Ngày mai · " + d.Format("02/01")
	}
	weekday := [...]string{"Chủ nhật", "Thứ 2", "Thứ 3", "Thứ 4", "Thứ 5", "Thứ 6", "Thứ 7"}[d.Weekday()]
	return weekday + " · " + d.Format("02/01")
}
