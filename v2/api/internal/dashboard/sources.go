package dashboard

import (
	"context"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// Nguồn số liệu của Tổng quan: mỗi phân hệ làm một adapter (crm.Service.DashboardLeads, tasks.Service.DashboardTasks…)
// truyền vào Deps ở cmd/menglish/main.go. Phạm vi dữ liệu do adapter áp đúng như màn gốc của phân hệ đó.
// branches nil = mọi chi nhánh (phạm vi "Toàn hệ thống" của dashboard.scope_all); slice rỗng = không có chi nhánh nào.

// Clock: các mốc thời gian (giờ Việt Nam) dùng chung cho một lần dựng Tổng quan.
type Clock struct {
	Now        time.Time
	Today      time.Time // 00:00 hôm nay
	TodayEnd   time.Time // 23:59:59.999 hôm nay
	WeekEnd    time.Time // Chủ nhật tuần này (00:00), như Carbon endOfWeek
	MonthStart time.Time
	MonthEnd   time.Time // 23:59:59.999 ngày cuối tháng
}

// AgendaItem: một lịch hẹn. DateOnly = mục chỉ có ngày (hạn mốc, support sau chốt dự kiến), còn hạn tới hết ngày.
type AgendaItem struct {
	At        time.Time
	DateOnly  bool
	Kind      string
	KindLabel string
	Title     string
	Subtitle  string
	Href      *string
}

// LeadSummary: số đếm CRM theo phạm vi khách của người xem (CrmCustomer::visibleTo).
type LeadSummary struct {
	TestToday        int64 // hẹn test hôm nay (bỏ khách thất bại / đã chốt)
	TestTodayPending int64 // trong đó chưa làm bài (giai đoạn Đã hẹn test)
	FollowUp         int64 // cần gọi lại tới hết hôm nay (gồm quá hạn)
	FollowUpOverdue  int64 // trong đó đã quá giờ hẹn
	Stale            int64 // khách Mới chưa liên hệ quá 24 giờ
	WaitingClass     int64 // khách Chờ xếp lớp
	ClosedMonth      int64 // khách chốt (converted_at) trong tháng
}

type Leads interface {
	Summary(ctx context.Context, a *iam.Access, c Clock) (LeadSummary, error)
	// NewInPeriod: khách tạo trong khoảng, theo chi nhánh của bảng điều hành (không theo phạm vi lead).
	NewInPeriod(ctx context.Context, branches []uuid.UUID, from, to time.Time) (int64, error)
	// Agenda: hẹn test đầu vào; withCare thêm học thử, hẹn gọi lại, support sau chốt (việc chăm sóc khách).
	Agenda(ctx context.Context, a *iam.Access, from, to time.Time, withCare bool) ([]AgendaItem, error)
	// TrialsCount: học thử đã hẹn (chưa diễn ra) trong khoảng ngày, theo phạm vi khách.
	TrialsCount(ctx context.Context, a *iam.Access, from, to time.Time) (int64, error)
}

// TaskItem: việc của tôi trên bảng "Việc của tôi trong tuần".
type TaskItem struct {
	ID          uuid.UUID  `json:"id"`
	Title       string     `json:"title"`
	Status      string     `json:"status"`
	StatusLabel string     `json:"status_label"`
	DueDate     *time.Time `json:"-"`
	DueTime     *string    `json:"-"` // "HH:MM"
	Due         *string    `json:"due"`
	Overdue     bool       `json:"overdue"`
}

// MyTasksData: việc được giao cho tôi còn mở (Mới, Đang làm, Quá hạn, Bị chặn).
type MyTasksData struct {
	Items       []TaskItem // tối đa 6, hạn trong tuần hoặc không hạn, sớm nhất trước
	Total       int64      // số việc của danh sách trên
	DueWeek     int64      // có hạn tới hết tuần (gồm quá hạn)
	OverdueWeek int64      // trong đó hạn trước hôm nay
}

type OverdueTask struct {
	ID       uuid.UUID `json:"id"`
	Title    string    `json:"title"`
	Assignee *string   `json:"assignee"`
	DueDate  *string   `json:"due_date"`
}

// OpsTasks: việc quá hạn và chờ xác nhận trong chi nhánh của bảng điều hành (việc gắn chi nhánh hoặc người nhận có chi
// nhánh chính thuộc đó). Nhiệm vụ hằng ngày của TA không có hạn nên không tính quá hạn.
type OpsTasks struct {
	Overdue             int64
	OverdueList         []OverdueTask // 5 việc hạn sớm nhất
	PendingConfirmation int64
}

type Tasks interface {
	Mine(ctx context.Context, uid uuid.UUID, c Clock) (MyTasksData, error)
	Operations(ctx context.Context, branches []uuid.UUID, c Clock) (OpsTasks, error)
}

// ClassStats: số liệu thô của một lớp trong tháng (sĩ số hiện tại, học sinh mới / nghỉ, điểm danh).
type ClassStats struct {
	ID              uuid.UUID
	Code            string
	Name            string
	TeacherID       *uuid.UUID
	Students        int
	New             int
	Dropped         int
	AttendanceTotal int
	Attended        int // có mặt + đi muộn
}

type ClassOverview struct {
	Total           int64
	Active          int64
	AttendanceTotal int64 // lượt điểm danh hôm nay
	Attended        int64
}

type Option struct {
	Value string `json:"value"`
	Label string `json:"label"`
}

type Classes interface {
	// RunningInBranches: lớp đang chạy (active) thuộc chi nhánh.
	RunningInBranches(ctx context.Context, branches []uuid.UUID) (int64, error)
	// RunningVisible: lớp đang chạy người xem thấy được (ClassModel::visibleTo).
	RunningVisible(ctx context.Context, a *iam.Access) (int64, error)
	// TeachingOf: lớp đang chạy người này là GV / trợ giảng / GVNN, cộng lớp có buổi người này dạy trong khoảng.
	TeachingOf(ctx context.Context, uid uuid.UUID, from, to time.Time) ([]ClassStats, error)
	// TeamClasses: lớp đang chạy người xem thấy được có GV chính là giáo viên (vai trò GV Full-time / Part-time),
	// kèm tên giáo viên.
	TeamClasses(ctx context.Context, a *iam.Access, from, to time.Time) ([]ClassStats, map[uuid.UUID]string, error)
	Names(ctx context.Context, ids []uuid.UUID) (map[uuid.UUID]string, error)
	Overview(ctx context.Context, day time.Time) (ClassOverview, error)
	// FilterOptions: lớp chưa hủy người xem thấy được (ô lọc nhật ký sự vụ), nhãn = mã lớp.
	FilterOptions(ctx context.Context, a *iam.Access) ([]Option, error)
}

type Students interface {
	// StudyingInBranches: học viên đang học thuộc chi nhánh.
	StudyingInBranches(ctx context.Context, branches []uuid.UUID) (int64, error)
}

// DayLink: một ngày báo cáo ngày (còn thiếu / cần bổ sung) và link màn nhập.
type DayLink struct {
	Date time.Time
	Href string
	Note *string
}

// DailyDue: báo cáo ngày của người viết (DailyReportDue): ngày còn thiếu trong khoảng, báo cáo Admin yêu cầu bổ sung,
// hôm nay đã nộp chưa.
type DailyDue struct {
	Missing        []DayLink // cũ trước
	Returned       []DayLink // mới trước
	TodaySubmitted bool
	TodayHref      string
}

// TeacherClassNote: phần một lớp trong báo cáo tháng của giáo viên.
type TeacherClassNote struct {
	ClassID     string
	Situation   *string
	Attention   *string
	Solution    *string
	NeedSupport bool
	SupportNote *string
}

type TeacherMonthly struct {
	UpdatedAt    time.Time
	Progress     *string
	Difficulties *string
	Proposals    *string
	Classes      []TeacherClassNote
}

// StaffReportRow: một báo cáo nhân sự đã nộp (màn Báo cáo đào tạo).
type StaffReportRow struct {
	ID         uuid.UUID
	Kind       *string
	Type       string
	PeriodKey  *string
	Title      string
	User       *string
	KindLabel  string
	Content    *string
	ReportDate time.Time
	UpdatedAt  time.Time
	Fields     map[string]any // báo cáo ngày: số liệu chụp lúc nộp (classes_planned, classes_actual, teachers…)
	HasTasks   bool
}

// JournalFilter: lọc nhật ký sự vụ (severity: urgent | important | normal; status: open | resolved).
type JournalFilter struct {
	Severity string
	Status   string
	BranchID *uuid.UUID
	ClassID  *uuid.UUID
}

type JournalRow struct {
	ID            uuid.UUID
	Branch        *string
	ClassID       *uuid.UUID
	ClassCode     *string
	Title         string
	Content       *string
	Severity      *string
	SeverityLabel string
	User          *string
	Followup      *string
	Status        string
}

type Reports interface {
	// DailyDue: nil nếu người này không phải nộp báo cáo ngày (không có daily_ops).
	DailyDue(ctx context.Context, a *iam.Access, c Clock) (*DailyDue, error)
	// TeacherMonthlyHref: link màn báo cáo tháng giáo viên nếu người này viết báo cáo đó, ngược lại nil.
	TeacherMonthlyHref(a *iam.Access, month string) *string
	// TeacherMonthly: báo cáo tháng (đã nộp) của các giáo viên trong tháng.
	TeacherMonthly(ctx context.Context, ids []uuid.UUID, month string) (map[uuid.UUID]TeacherMonthly, error)
	// Submitted: báo cáo đã nộp (không phải bản nháp) theo nhóm: daily | weekly | weekly_kpi | monthly.
	Submitted(ctx context.Context, group string, limit int) ([]StaffReportRow, error)
	// DailyOn: số báo cáo ngày của ngày này.
	DailyOn(ctx context.Context, day time.Time) (int64, error)
	Journals(ctx context.Context, f JournalFilter, limit int) ([]JournalRow, error)
}

type AcademicOrder struct {
	ID          uuid.UUID `json:"id"`
	Code        string    `json:"code"`
	Title       string    `json:"title"`
	Teacher     *string   `json:"teacher"`
	Class       *string   `json:"class"`
	UseDate     string    `json:"use_date"`
	StatusLabel string    `json:"status_label"`
	Open        bool      `json:"open"`
}

type Orders interface {
	// Academic: order học thuật của các người yêu cầu, tạo hoặc dùng trong khoảng, mới trước.
	Academic(ctx context.Context, requesters []uuid.UUID, from, to time.Time, limit int) ([]AcademicOrder, error)
}

// TicketCounts: ticket đang mở (Mới + Đang xử lý) và ticket Mới chưa có người xử lý.
type TicketCounts struct {
	Open       int64
	Unassigned int64
}

// IncidentTicket: ticket hỗ trợ nổi cộm trên màn Nhật ký sự vụ.
type IncidentTicket struct {
	ID            uuid.UUID `json:"id"`
	Code          string    `json:"code"`
	CanOpen       bool      `json:"can_open"`
	Branch        *string   `json:"branch"`
	CategoryLabel string    `json:"category_label"`
	Title         string    `json:"title"`
	Excerpt       string    `json:"excerpt"`
	Priority      string    `json:"priority"`
	Assignee      *string   `json:"assignee"`
	Creator       string    `json:"creator"`
	Status        string    `json:"status"`
	StatusLabel   string    `json:"status_label"`
}

// TicketIncidentFilter: mức ưu tiên cần lấy, chi nhánh chính của người tạo, trạng thái (open | resolved | rỗng = mọi).
type TicketIncidentFilter struct {
	Priorities []string
	BranchID   *uuid.UUID
	Status     string
}

// Tickets: ticket hỗ trợ (adapter tickets.Service.DashboardTickets). Counts trả nil = phân hệ chưa có.
type Tickets interface {
	// Counts: lọc theo chi nhánh chính của người tạo ticket.
	Counts(ctx context.Context, branches []uuid.UUID) (*TicketCounts, error)
	// Incidents: mới trước, tối đa limit; ok = false khi phân hệ chưa có.
	Incidents(ctx context.Context, a *iam.Access, f TicketIncidentFilter, limit int) (rows []IncidentTicket, ok bool, err error)
}

type noTickets struct{}

func (noTickets) Counts(context.Context, []uuid.UUID) (*TicketCounts, error) { return nil, nil }
func (noTickets) Incidents(context.Context, *iam.Access, TicketIncidentFilter, int) ([]IncidentTicket, bool, error) {
	return nil, false, nil
}

// ───────────── Phân hệ đang làm song song: mặc định "chưa có" ─────────────

// Finance: số liệu học phí. Trả nil = phân hệ chưa có (màn hiện "Chờ phân hệ Học phí").
// Adapter: tuition.Service.DashboardFinance (phiếu thu đã duyệt / chờ duyệt, học phí quá hạn theo TuitionBranchScope).
type Finance interface {
	// Revenue: tổng phiếu thu đã duyệt có ngày thu trong khoảng, của học viên thuộc chi nhánh.
	Revenue(ctx context.Context, branches []uuid.UUID, from, to time.Time) (*int64, error)
	PendingReceipts(ctx context.Context, branches []uuid.UUID) (*int64, error)
	// OverdueTuitions: học phí quá hạn trong phạm vi học phí của người xem.
	OverdueTuitions(ctx context.Context, a *iam.Access) (*int64, error)
}

type noFinance struct{}

func (noFinance) Revenue(context.Context, []uuid.UUID, time.Time, time.Time) (*int64, error) {
	return nil, nil
}
func (noFinance) PendingReceipts(context.Context, []uuid.UUID) (*int64, error) { return nil, nil }
func (noFinance) OverdueTuitions(context.Context, *iam.Access) (*int64, error) { return nil, nil }

// WorkStats: ngày công, buổi đi muộn, ngày phép, lỗi vi phạm trong tháng của một người.
type WorkStats struct {
	WorkDays   int `json:"work_days"`
	Late       int `json:"late"`
	LeaveDays  int `json:"leave_days"`
	Violations int `json:"violations"`
}

// StaffWork: chấm công / nghỉ phép / biên bản lỗi. ok = false khi phân hệ chưa có.
// TODO(gộp): adapter của phân hệ chấm công & KPI (teacher_timesheets, staff_attendances, đơn nghỉ phép, penalties).
type StaffWork interface {
	Stats(ctx context.Context, ids []uuid.UUID, from, to time.Time) (stats map[uuid.UUID]WorkStats, ok bool, err error)
}

type noStaffWork struct{}

func (noStaffWork) Stats(context.Context, []uuid.UUID, time.Time, time.Time) (map[uuid.UUID]WorkStats, bool, error) {
	return nil, false, nil
}
