package dashboard

import (
	"context"
	"sort"
	"time"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// Bảng "Việc của bạn" (MyWorkBoard), bảng điều hành Admin / Quản lý và bảng Học thuật (DashboardController).

const (
	agendaDays  = 7
	agendaLimit = 40 // số mục lịch hẹn tối đa; phần dư hiện "+N lịch nữa"
)

// Đường dẫn màn đích ở v2.
const (
	pathTestToday = "/crm/customers?chip=test_today"
	pathFollowUp  = "/crm/customers?chip=follow_up"
	pathStale     = "/crm/customers?chip=sla"
	pathWaiting   = "/crm/waiting"
	pathMyTasks   = "/tasks?tab=mine"
	pathApprovals = "/tasks/approvals"
)

// seesLeads: có quyền xem khách CRM (route crm.customers.index).
func seesLeads(a *iam.Access) bool { return a.Can("lead.view") }

// seesTasks: mở được Danh sách đầu việc (tasks.index).
func seesTasks(a *iam.Access) bool { return a.Can("work_task.view") }

// seesBigTests: Học thuật xem lịch Big Test (phân hệ Giáo trình chưa có ở v2 → chỉ ghi chú trên lịch hẹn).
func seesBigTests(a *iam.Access) bool { return a.Can(PermAcademic) }

func agendaEnd(c Clock) time.Time { return endOfDay(c.Today.AddDate(0, 0, agendaDays-1)) }

// personal: "Việc của bạn" cho vai trò không có bảng điều hành / Học thuật.
func (s *Service) personal(ctx context.Context, a *iam.Access, c Clock) (*RoleDashboard, error) {
	out := &RoleDashboard{Type: "personal", Title: "Việc của bạn", Scope: personalScope(a), UpdatedAt: c.Now.Format("15:04 02/01/2006")}
	var leads LeadSummary
	var err error
	if seesLeads(a) {
		if leads, err = s.d.Leads.Summary(ctx, a, c); err != nil {
			return nil, err
		}
	}
	var mine MyTasksData
	if seesTasks(a) {
		if mine, err = s.d.Tasks.Mine(ctx, a.UserID, c); err != nil {
			return nil, err
		}
	}
	if out.Stats, err = s.personalStats(ctx, a, c, leads, mine); err != nil {
		return nil, err
	}
	// Vai trò không có nguồn lịch hẹn nào (vd. Kế toán) thì không có khối lịch. Mốc dự án học thuật: người quản lý dự án
	// (người nhận mốc chưa xét được vì phân hệ Dự án học thuật chưa có ở v2).
	if seesLeads(a) || seesBigTests(a) || a.Can("academic_project.manage") {
		if out.Agenda, err = s.agenda(ctx, a, c, true); err != nil {
			return nil, err
		}
	}
	if out.Queues, err = s.personalQueues(ctx, a, leads); err != nil {
		return nil, err
	}
	out.MyTasks = myTasks(a, mine)
	return out, nil
}

// personalScope: nhãn phạm vi của "Việc của bạn".
func personalScope(a *iam.Access) string {
	switch {
	case seesLeads(a) && a.Level("lead") == iam.ScopeOwn:
		return "Khách và việc bạn phụ trách"
	case a.Level("lead") == iam.ScopeAll:
		return "Toàn hệ thống"
	}
	if n := primaryBranchName(a); n != "" {
		return n
	}
	return "Chi nhánh của bạn"
}

func (s *Service) personalStats(ctx context.Context, a *iam.Access, c Clock, leads LeadSummary, mine MyTasksData) ([]Stat, error) {
	var stats []Stat
	if seesLeads(a) {
		stats = append(stats, numStat("Hẹn test hôm nay", leads.TestToday, "event", toneIf(leads.TestToday > 0, "primary", "default"),
			hintIf(leads.TestToday > 0, "%d chưa làm bài", leads.TestTodayPending), ptr(pathTestToday)))
		trials, err := s.d.Leads.TrialsCount(ctx, a, c.Today, agendaEnd(c))
		if err != nil {
			return nil, err
		}
		stats = append(stats, numStat("Học thử 7 ngày tới", trials, "co_present", toneIf(trials > 0, "secondary", "default"), nil, nil))
		tone := "default"
		if leads.FollowUpOverdue > 0 {
			tone = "error"
		} else if leads.FollowUp > 0 {
			tone = "warning"
		}
		stats = append(stats, numStat("Cần gọi lại hôm nay", leads.FollowUp, "call", tone,
			hintIf(leads.FollowUpOverdue > 0, "%d khách đã quá hạn", leads.FollowUpOverdue), ptr(pathFollowUp)))
	}
	if seesTasks(a) {
		stats = append(stats, numStat("Việc của tôi đến hạn tuần này", mine.DueWeek, "task_alt", toneIf(mine.OverdueWeek > 0, "error", "default"),
			hintIf(mine.OverdueWeek > 0, "%d việc đã quá hạn", mine.OverdueWeek), ptr(pathMyTasks)))
	}
	// "Chờ bạn duyệt": hộp duyệt chung chỉ Admin mở (AdminOnlyApprovals), Admin luôn xem bảng điều hành nên không tới đây.
	if seesLeads(a) {
		stats = append(stats, numStat("Khách chốt tháng "+c.Now.Format("01/2006"), leads.ClosedMonth, "how_to_reg", "success", nil, nil))
	}
	if a.Can("tuition.view") {
		st, err := s.overdueTuitionStat(ctx, a)
		if err != nil {
			return nil, err
		}
		stats = append(stats, st)
	}
	if len(stats) > 5 {
		stats = stats[:5]
	}
	if stats == nil {
		stats = []Stat{}
	}
	return stats, nil
}

func (s *Service) overdueTuitionStat(ctx context.Context, a *iam.Access) (Stat, error) {
	n, err := s.d.Finance.OverdueTuitions(ctx, a)
	if err != nil || n == nil {
		return pendingStat("Học phí quá hạn", "receipt_long", pendingTuition), err
	}
	// TODO(gộp): href màn Học phí quá hạn khi phân hệ Học phí có ở v2.
	return numStat("Học phí quá hạn", *n, "receipt_long", toneIf(*n > 0, "error", "default"), nil, nil), nil
}

func (s *Service) overdueTuitionQueue(ctx context.Context, a *iam.Access) (Queue, error) {
	n, err := s.d.Finance.OverdueTuitions(ctx, a)
	if err != nil || n == nil {
		return pendingQueue("Học phí quá hạn", "receipt_long", pendingTuition), err
	}
	return Queue{Label: "Học phí quá hạn", Value: n, Icon: "receipt_long"}, nil
}

func (s *Service) personalQueues(ctx context.Context, a *iam.Access, leads LeadSummary) ([]Queue, error) {
	var qs []Queue
	if seesLeads(a) {
		qs = append(qs, queue("Khách chưa liên hệ >24h", leads.Stale, "person_alert", pathStale, nil))
		if a.Can("class.update") {
			qs = append(qs, queue("Khách chờ xếp lớp", leads.WaitingClass, "event_seat", pathWaiting, nil))
		}
	}
	if a.Can("tuition.view") {
		q, err := s.overdueTuitionQueue(ctx, a)
		if err != nil {
			return nil, err
		}
		qs = append(qs, q)
	}
	if a.Can("payroll.calculate") || a.Can("payroll.approve") {
		qs = append(qs, pendingQueue("Kỳ lương chưa chốt", "payments", pendingPayroll))
	}
	// Mục còn việc lên trước (giữ thứ tự trong từng nhóm).
	sort.SliceStable(qs, func(i, j int) bool { return hasWork(qs[i]) && !hasWork(qs[j]) })
	if qs == nil {
		qs = []Queue{}
	}
	return qs, nil
}

func hasWork(q Queue) bool { return q.Value != nil && *q.Value > 0 }

func myTasks(a *iam.Access, mine MyTasksData) *MyTasks {
	if !seesTasks(a) {
		return &MyTasks{Items: []TaskItem{}}
	}
	items := mine.Items
	if items == nil {
		items = []TaskItem{}
	}
	return &MyTasks{Items: items, Total: mine.Total, URL: ptr(pathMyTasks)}
}

// agenda: lịch hẹn từ hôm nay đến hết ngày thứ 7, nhóm theo ngày, xếp theo giờ.
func (s *Service) agenda(ctx context.Context, a *iam.Access, c Clock, withCrmCare bool) (*Agenda, error) {
	end := agendaEnd(c)
	var items []AgendaItem
	if seesLeads(a) {
		got, err := s.d.Leads.Agenda(ctx, a, c.Today, end, withCrmCare)
		if err != nil {
			return nil, err
		}
		items = append(items, got...)
	}
	out := buildAgenda(items, c, end)
	if seesBigTests(a) {
		out.Pending = append(out.Pending, "Big Test: "+pendingSyllabus)
	}
	if a.Can("academic_project.manage") {
		out.Pending = append(out.Pending, "Hạn mốc dự án học thuật: "+pendingProjects)
	}
	return out, nil
}

// buildAgenda nhóm lịch theo ngày (tối đa agendaLimit mục, phần dư đếm vào More).
func buildAgenda(items []AgendaItem, c Clock, end time.Time) *Agenda {
	sort.SliceStable(items, func(i, j int) bool { return items[i].At.Before(items[j].At) })
	out := &Agenda{Days: []AgendaDay{}, Total: len(items), Pending: []string{},
		Range: c.Today.Format("02/01") + " – " + end.In(vnZone).Format("02/01")}
	shown := items
	if len(shown) > agendaLimit {
		shown = shown[:agendaLimit]
	}
	out.More = len(items) - len(shown)
	for _, it := range shown {
		at := it.At.In(vnZone)
		key := at.Format("2006-01-02")
		if len(out.Days) == 0 || out.Days[len(out.Days)-1].Date != key {
			out.Days = append(out.Days, AgendaDay{Date: key, Label: dayLabel(at, c.Today), Items: []AgendaEntry{}})
		}
		e := AgendaEntry{Kind: it.Kind, KindLabel: it.KindLabel, Title: it.Title, Subtitle: it.Subtitle, Href: it.Href}
		// Mục chỉ có ngày (00:00) còn hạn tới hết ngày.
		if it.DateOnly || at.Format("15:04") == "00:00" {
			e.Past = dayOf(at).Before(c.Today)
		} else {
			e.Time = ptr(at.Format("15:04"))
			e.Past = at.Before(c.Now)
		}
		day := &out.Days[len(out.Days)-1]
		day.Items = append(day.Items, e)
	}
	return out
}

// operations: bảng điều hành Admin (toàn hệ thống) / Quản lý cơ sở (chi nhánh của mình).
func (s *Service) operations(ctx context.Context, a *iam.Access, c Clock) (*RoleDashboard, error) {
	branches := a.BranchLimit("dashboard")
	out := &RoleDashboard{Type: "admin", Title: "Tổng quan toàn hệ thống", Scope: "Toàn hệ thống", UpdatedAt: c.Now.Format("15:04 02/01/2006")}
	if branches != nil {
		out.Type, out.Title, out.Scope = "manager", "Tổng quan chi nhánh", primaryBranchName(a)
		if out.Scope == "" {
			out.Scope = "Chi nhánh của bạn"
		}
	}

	revenue, err := s.d.Finance.Revenue(ctx, branches, c.MonthStart, c.MonthEnd)
	if err != nil {
		return nil, err
	}
	studying, err := s.d.Students.StudyingInBranches(ctx, branches)
	if err != nil {
		return nil, err
	}
	newLeads, err := s.d.Leads.NewInPeriod(ctx, branches, c.MonthStart, c.MonthEnd)
	if err != nil {
		return nil, err
	}
	running, err := s.d.Classes.RunningInBranches(ctx, branches)
	if err != nil {
		return nil, err
	}
	tasks, err := s.d.Tasks.Operations(ctx, branches, c)
	if err != nil {
		return nil, err
	}
	revenueLabel := "Doanh thu tháng " + c.Now.Format("01/2006")
	revenueStat := pendingStat(revenueLabel, "payments", pendingTuition)
	if revenue != nil {
		revenueStat = Stat{Label: revenueLabel, Value: ptr(formatMoney(*revenue)), Icon: "payments", Tone: "success", Hint: ptr("Tổng phiếu thu đã duyệt")}
	}
	out.Stats = []Stat{
		revenueStat,
		numStat("Học viên đang học", studying, "school", "primary", nil, nil),
		numStat("Lead mới trong tháng", newLeads, "person_add", "secondary", nil, nil),
		numStat("Lớp đang chạy", running, "co_present", "default", nil, nil),
		numStat("Việc quá hạn", tasks.Overdue, "alarm", toneIf(tasks.Overdue > 0, "error", "default"), nil, nil),
	}

	receipts, err := s.d.Finance.PendingReceipts(ctx, branches)
	if err != nil {
		return nil, err
	}
	receiptQueue := pendingQueue("Phiếu thu chờ duyệt", "receipt_long", pendingTuition)
	if receipts != nil {
		// TODO(gộp): href màn duyệt phiếu thu của phân hệ Học phí.
		receiptQueue = Queue{Label: "Phiếu thu chờ duyệt", Value: receipts, Icon: "receipt_long"}
	}
	reports := pendingQueue("Báo cáo trực lớp chờ xác nhận", "fact_check", pendingClassReports)
	reports.Hint = ptr("Admin xác nhận")
	out.Queues = []Queue{
		queue("Việc chờ xác nhận", tasks.PendingConfirmation, "pending_actions", pathApprovals, nil),
		reports,
		receiptQueue,
		pendingQueue("Ticket đang mở", "support_agent", pendingTickets),
	}
	// Lịch hẹn test hôm nay: cùng phạm vi khách CRM với danh sách mà link mở ra.
	if seesLeads(a) {
		leads, err := s.d.Leads.Summary(ctx, a, c)
		if err != nil {
			return nil, err
		}
		out.Queues = append(out.Queues, queue("Lịch hẹn test hôm nay", leads.TestToday, "event", pathTestToday,
			hintIf(leads.TestToday > 0, "%d chưa làm bài", leads.TestTodayPending)))
	}
	out.OverdueTasks = tasks.OverdueList
	if out.OverdueTasks == nil {
		out.OverdueTasks = []OverdueTask{}
	}
	return out, nil
}

// academic: bảng Học thuật — lớp đang chạy, hàng chờ chuyên môn (chờ phân hệ Giáo trình), lịch hẹn + việc của tôi.
func (s *Service) academic(ctx context.Context, a *iam.Access, c Clock) (*RoleDashboard, error) {
	running, err := s.d.Classes.RunningVisible(ctx, a)
	if err != nil {
		return nil, err
	}
	out := &RoleDashboard{Type: "academic", Title: "Tổng quan học thuật", Scope: "Học thuật", UpdatedAt: c.Now.Format("15:04 02/01/2006"),
		Stats: []Stat{
			numStat("Lớp đang chạy", running, "co_present", "primary", nil, nil),
			pendingStat("Đề xuất giáo trình / giãn tiến độ chờ duyệt", "pending_actions", pendingSyllabus),
			pendingStat("Big Test trong 14 ngày tới", "quiz", pendingSyllabus),
		},
		Queues: []Queue{
			pendingQueue("Đề xuất sửa giáo trình", "edit_note", pendingSyllabus),
			pendingQueue("Order đề Big Test chờ duyệt", "assignment", pendingSyllabus),
			pendingQueue("Đợt Big Test có kết quả chờ duyệt", "grading", pendingSyllabus),
		},
		PendingAdjustments: []any{}, UpcomingBigTests: []any{}, AcademicPending: ptr(pendingSyllabus),
	}
	// Lịch hẹn 7 ngày tới (Big Test + hẹn test, không gồm gọi lại / học thử của CRM) + việc của tôi.
	if out.Agenda, err = s.agenda(ctx, a, c, false); err != nil {
		return nil, err
	}
	var mine MyTasksData
	if seesTasks(a) {
		if mine, err = s.d.Tasks.Mine(ctx, a.UserID, c); err != nil {
			return nil, err
		}
	}
	out.MyTasks = myTasks(a, mine)
	return out, nil
}
