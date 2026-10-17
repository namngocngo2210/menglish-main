package crm

import (
	"context"
	"encoding/json"
	"fmt"
	"strings"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5/pgtype"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/crm/crmdb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/dashboard"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// DashboardLeads: số liệu CRM cho Tổng quan (dashboard.Leads), cùng phạm vi khách với danh sách khách (scopeOf).
func (s *Service) DashboardLeads() dashboard.Leads { return dashboardLeads{s} }

type dashboardLeads struct{ s *Service }

func (d dashboardLeads) Summary(ctx context.Context, a *iam.Access, c dashboard.Clock) (dashboard.LeadSummary, error) {
	sc := scopeOf(a)
	r, err := d.s.q.DashboardLeadCounts(ctx, crmdb.DashboardLeadCountsParams{
		TodayStart: c.Today, TodayEnd: c.TodayEnd, Now: c.Now, FirstContactCutoff: c.Now.Add(-firstContactSLAHours * time.Hour),
		MonthStart: c.MonthStart, MonthEnd: c.MonthEnd, ScopeOwner: sc.owner, ScopeBranches: sc.branches,
	})
	return dashboard.LeadSummary{TestToday: r.TestToday, TestTodayPending: r.TestTodayPending, FollowUp: r.FollowUp,
		FollowUpOverdue: r.FollowUpOverdue, Stale: r.Stale, WaitingClass: r.WaitingClass, ClosedMonth: r.ClosedMonth}, err
}

func (d dashboardLeads) NewInPeriod(ctx context.Context, branches []uuid.UUID, from, to time.Time) (int64, error) {
	return d.s.q.DashboardNewLeads(ctx, crmdb.DashboardNewLeadsParams{DateFrom: from, DateTo: to, Branches: branches})
}

func (d dashboardLeads) TrialsCount(ctx context.Context, a *iam.Access, from, to time.Time) (int64, error) {
	sc := scopeOf(a)
	return d.s.q.DashboardTrialsCount(ctx, crmdb.DashboardTrialsCountParams{DateFrom: from, DateTo: to, ScopeOwner: sc.owner, ScopeBranches: sc.branches})
}

func customerHref(id uuid.UUID) *string {
	h := "/crm/customers/" + id.String()
	return &h
}

func joinNonEmpty(parts ...string) string {
	out := parts[:0]
	for _, p := range parts {
		if p != "" {
			out = append(out, p)
		}
	}
	return strings.Join(out, " · ")
}

func clockText(t pgtype.Time) string {
	if !t.Valid {
		return ""
	}
	m := t.Microseconds / 60_000_000
	return fmt.Sprintf("%02d:%02d", m/60, m%60)
}

// atClock: ngày (giờ Việt Nam) + giờ "HH:MM" ("" = 00:00).
func atClock(day time.Time, hhmm string) time.Time {
	day = day.In(vnZone)
	t := time.Date(day.Year(), day.Month(), day.Day(), 0, 0, 0, 0, vnZone)
	if hhmm != "" {
		var h, m int
		_, _ = fmt.Sscanf(hhmm, "%d:%d", &h, &m)
		t = t.Add(time.Duration(h)*time.Hour + time.Duration(m)*time.Minute)
	}
	return t
}

func ptrOr(p *string) string {
	if p == nil {
		return ""
	}
	return *p
}

// Agenda: hẹn test (bỏ khách đã chốt — thay bằng support sau chốt); withCare thêm học thử, hẹn gọi lại, support sau chốt.
func (d dashboardLeads) Agenda(ctx context.Context, a *iam.Access, from, to time.Time, withCare bool) ([]dashboard.AgendaItem, error) {
	sc := scopeOf(a)
	var items []dashboard.AgendaItem
	tests, err := d.s.q.DashboardAppointments(ctx, crmdb.DashboardAppointmentsParams{DateFrom: from, DateTo: to, ScopeOwner: sc.owner, ScopeBranches: sc.branches})
	if err != nil {
		return nil, err
	}
	for _, t := range tests {
		place := "Tại cơ sở"
		if t.AppointmentType != nil && *t.AppointmentType == "online" {
			place = "Online"
		}
		stage := StageLabel(t.Stage)
		if t.Stage == StageTestScheduled {
			stage = "Chưa làm bài"
		}
		items = append(items, dashboard.AgendaItem{At: t.AppointmentAt, Kind: "test", KindLabel: "Test đầu vào", Title: t.Name,
			Subtitle: joinNonEmpty(place, stage, ptrOr(t.AssignedName)), Href: customerHref(t.ID)})
	}
	if !withCare {
		return items, nil
	}

	trials, err := d.s.q.DashboardTrials(ctx, crmdb.DashboardTrialsParams{DateFrom: from, DateTo: to, ScopeOwner: sc.owner, ScopeBranches: sc.branches})
	if err != nil {
		return nil, err
	}
	for _, t := range trials {
		start, end := clockText(t.StartTime), clockText(t.EndTime)
		span := ""
		if start != "" && end != "" {
			span = start + "–" + end
		}
		title := t.LeadName
		if title == "" {
			title = "Khách học thử"
		}
		items = append(items, dashboard.AgendaItem{At: atClock(t.SessionDate, start), Kind: "trial", KindLabel: "Học thử", Title: title,
			Subtitle: joinNonEmpty(ptrOr(t.ClassName), span), Href: customerHref(t.LeadID)})
	}

	calls, err := d.s.q.DashboardFollowUps(ctx, crmdb.DashboardFollowUpsParams{DateFrom: from, DateTo: to, ScopeOwner: sc.owner, ScopeBranches: sc.branches})
	if err != nil {
		return nil, err
	}
	for _, c := range calls {
		items = append(items, dashboard.AgendaItem{At: c.NextFollowUpAt, Kind: "call", KindLabel: "Gọi lại", Title: c.Name,
			Subtitle: StageLabel(c.Stage), Href: customerHref(c.ID)})
	}

	care, err := d.postCloseCare(ctx, sc, from, to)
	if err != nil {
		return nil, err
	}
	return append(items, care...), nil
}

// Chăm sóc tháng đầu (FirstMonthCareService): mốc → khóa checklist CRM và nhãn.
var careMilestones = []struct {
	milestone int16
	key       string
	label     string
}{
	{1, "session_1", "Buổi 1 — Hỏi phản hồi sau buổi học đầu tiên"},
	{4, "session_4_5", "Buổi 4–5 — Trao đổi tiến độ với phụ huynh"},
	{30, "day_30", "Đủ 30 ngày — Đánh giá cuối tháng đầu, mức độ hài lòng"},
}

const (
	careDaysAfterClosing = 30
	careCatchUpDays      = 30
)

var openTaskStatuses = map[string]bool{"new": true, "in_progress": true, "overdue": true, "blocked": true}

// checklistDone: mốc đã tick trên checklist chăm sóc của CRM.
func checklistDone(raw []byte) map[string]bool {
	done := map[string]bool{}
	var m map[string]json.RawMessage
	if json.Unmarshal(raw, &m) != nil {
		return done
	}
	for k, v := range m {
		s := strings.TrimSpace(string(v))
		done[k] = s != "" && s != "null" && s != "false" && s != `""` && s != "{}" && s != "[]" && s != "0"
	}
	return done
}

// postCloseCare: mốc chăm sóc tháng đầu chưa xong của khách đã chốt có hạn trong khoảng. Mốc chưa có việc / hạn thì dự
// kiến: Buổi 1 vào buổi học đầu tiên sắp tới của lớp (học viên chưa đi buổi nào), Đủ 30 ngày vào ngày chốt + 30.
func (d dashboardLeads) postCloseCare(ctx context.Context, sc scope, from, to time.Time) ([]dashboard.AgendaItem, error) {
	since := from.AddDate(0, 0, -(careDaysAfterClosing + careCatchUpDays))
	leads, err := d.s.q.DashboardCareLeads(ctx, crmdb.DashboardCareLeadsParams{DateFrom: from, DateTo: to, ConvertedSince: since,
		ScopeOwner: sc.owner, ScopeBranches: sc.branches})
	if err != nil || len(leads) == 0 {
		return nil, err
	}
	ids := make([]uuid.UUID, 0, len(leads))
	for _, l := range leads {
		ids = append(ids, l.StudentID)
	}
	rows, err := d.s.q.DashboardCareTasks(ctx, ids)
	if err != nil {
		return nil, err
	}
	type key struct {
		student   uuid.UUID
		milestone int16
	}
	tasks := map[key]crmdb.DashboardCareTasksRow{}
	for _, t := range rows {
		tasks[key{t.StudentID, t.CareMilestone}] = t
	}
	var items []dashboard.AgendaItem
	for _, l := range leads {
		done := checklistDone(l.CareChecklist)
		for _, m := range careMilestones {
			task, hasTask := tasks[key{l.StudentID, m.milestone}]
			if done[m.key] || (hasTask && !openTaskStatuses[task.Status]) {
				continue // đã chăm sóc (tick CRM / việc xong) hoặc việc đã hủy
			}
			note := m.label
			var at *time.Time
			dateOnly := false
			switch {
			case hasTask && task.DueDate != nil:
				t := atClock(*task.DueDate, clockText(task.DueTime))
				at, dateOnly = &t, !task.DueTime.Valid
			case m.milestone == 30:
				t := atClock(l.ConvertedAt, "").AddDate(0, 0, careDaysAfterClosing)
				at, dateOnly = &t, true
			case m.milestone == 1 && !l.Attended && l.FirstSessionDate != nil:
				t := atClock(*l.FirstSessionDate, clockText(l.FirstSessionStart))
				at, note = &t, "Buổi học đầu tiên, hỏi phản hồi sau buổi"
			}
			if at == nil || at.Before(from) || at.After(to) {
				continue
			}
			assignee := ""
			if hasTask {
				assignee = ptrOr(task.AssigneeName)
			}
			items = append(items, dashboard.AgendaItem{At: *at, DateOnly: dateOnly, Kind: "care", KindLabel: "Support sau chốt",
				Title: l.Name, Subtitle: joinNonEmpty(note, ptrOr(l.ClassName), assignee), Href: customerHref(l.ID)})
		}
	}
	return items, nil
}
