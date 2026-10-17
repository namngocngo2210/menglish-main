package dashboard

import (
	"context"
	"fmt"
	"strings"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// Báo cáo & sự vụ lớp (AcademicDashboardController): Báo cáo đào tạo (báo cáo ngày Học vụ, tuần Học thuật + tuần KPI,
// tháng / quý giáo viên và Học thuật) và Nhật ký sự vụ. Quyền route: class.update; Laravel không lọc thêm phạm vi.

// Branches: danh sách chi nhánh (iam.Service.ListBranches).
type Branches interface {
	ListBranches(ctx context.Context) ([]iam.Branch, error)
}

type RecordRow struct {
	ID          uuid.UUID `json:"id"`
	Title       string    `json:"title"`
	RecordCode  *string   `json:"record_code"`
	User        *string   `json:"user"`
	StatusLabel string    `json:"status_label"`
	Content     *string   `json:"content"`
	CreatedAt   string    `json:"created_at"`
}

type DailyRow struct {
	ID         uuid.UUID `json:"id"`
	Date       string    `json:"date"`
	User       *string   `json:"user"`
	Title      string    `json:"title"`
	Content    *string   `json:"content"`
	Structured bool      `json:"structured"`
	Classes    *string   `json:"classes"`
	Staff      *string   `json:"staff"`
	Enrollment *string   `json:"enrollment"`
	HasTask    bool      `json:"has_task"`
	UpdatedAt  string    `json:"updated_at"`
}

type WeeklyStats struct {
	Pending string `json:"pending"` // đề xuất giãn tiến độ, Big Test: chờ phân hệ Giáo trình
}

type AcademicReports struct {
	Tab                    string      `json:"tab"`
	Today                  string      `json:"today"`
	ClassReports           []any       `json:"class_reports"`
	ClassReportsPending    string      `json:"class_reports_pending"`
	StaffDailyReports      []DailyRow  `json:"staff_daily_reports"`
	WeeklyReports          []RecordRow `json:"weekly_reports"`
	WeeklyKpiReports       []RecordRow `json:"weekly_kpi_reports"`
	MonthlyReports         []RecordRow `json:"monthly_reports"`
	TotalClasses           int64       `json:"total_classes"`
	ActiveClasses          int64       `json:"active_classes"`
	TotalDailyReportsToday int64       `json:"total_daily_reports_today"`
	AttendanceRateToday    *float64    `json:"attendance_rate_today"`
	WeeklyStats            WeeklyStats `json:"weekly_stats"`
}

func pickTab(tab string) string {
	switch tab {
	case "weekly", "monthly":
		return tab
	}
	return "daily"
}

func recordRows(rows []StaffReportRow) []RecordRow {
	out := make([]RecordRow, 0, len(rows))
	for _, r := range rows {
		title := r.Title
		if title == "" {
			title = "Báo cáo"
		}
		out = append(out, RecordRow{ID: r.ID, Title: title, RecordCode: r.PeriodKey, User: r.User, StatusLabel: r.KindLabel,
			Content: r.Content, CreatedAt: r.UpdatedAt.In(vnZone).Format("15:04 02/01/2006")})
	}
	return out
}

func fieldText(f map[string]any, key string) (string, bool) {
	v, ok := f[key]
	if !ok || v == nil {
		return "", false
	}
	switch n := v.(type) {
	case float64:
		return fmt.Sprintf("%g", n), true
	}
	s := fmt.Sprint(v)
	return s, s != ""
}

func orDash(f map[string]any, key string) string {
	if s, ok := fieldText(f, key); ok {
		return s
	}
	return "—"
}

func orZero(f map[string]any, key string) string {
	if s, ok := fieldText(f, key); ok {
		return s
	}
	return "0"
}

// dailyRow: dòng báo cáo ngày (bản có cấu trúc thì tóm tắt số liệu chụp lúc nộp: lớp thực tế / kế hoạch, GV / TG, tuyển sinh).
func dailyRow(r StaffReportRow) DailyRow {
	out := DailyRow{ID: r.ID, Date: r.ReportDate.Format("2006-01-02"), User: r.User, Title: r.Title, Content: r.Content,
		Structured: r.Kind != nil && *r.Kind == "daily_ops", HasTask: r.HasTasks,
		UpdatedAt: r.UpdatedAt.In(vnZone).Format("15:04 02/01/2006")}
	f := r.Fields
	_, planned := fieldText(f, "classes_planned")
	_, actual := fieldText(f, "classes_actual")
	if planned || actual {
		out.Classes = ptr(orDash(f, "classes_actual") + " / " + orDash(f, "classes_planned"))
	}
	_, teachers := fieldText(f, "teachers")
	_, assistants := fieldText(f, "assistants")
	if teachers || assistants {
		out.Staff = ptr(orZero(f, "teachers") + " GV · " + orZero(f, "assistants") + " TG")
	}
	var parts []string
	for _, p := range [][2]string{{"Data", "new_data"}, {"Test", "test_students"}, {"Thu phí", "fees"}} {
		if v, ok := fieldText(f, p[1]); ok {
			parts = append(parts, p[0]+" "+v)
		}
	}
	if len(parts) > 0 {
		out.Enrollment = ptr(strings.Join(parts, " · "))
	}
	return out
}

// AcademicReports: màn Báo cáo đào tạo (academic.dashboards.reports), tab daily | weekly | monthly.
func (s *Service) AcademicReports(ctx context.Context, a *iam.Access, tab string) (*AcademicReports, error) {
	c := clockAt(s.now())
	tab = pickTab(tab)
	out := &AcademicReports{Tab: tab, Today: c.Now.Format("02/01/2006"), ClassReports: []any{}, ClassReportsPending: pendingClassReports,
		StaffDailyReports: []DailyRow{}, WeeklyReports: []RecordRow{}, WeeklyKpiReports: []RecordRow{}, MonthlyReports: []RecordRow{},
		WeeklyStats: WeeklyStats{Pending: pendingSyllabus}}
	switch tab {
	case "daily":
		rows, err := s.d.Reports.Submitted(ctx, "daily", 15)
		if err != nil {
			return nil, err
		}
		for _, r := range rows {
			out.StaffDailyReports = append(out.StaffDailyReports, dailyRow(r))
		}
	case "weekly":
		rows, err := s.d.Reports.Submitted(ctx, "weekly", 10)
		if err != nil {
			return nil, err
		}
		out.WeeklyReports = recordRows(rows)
		if rows, err = s.d.Reports.Submitted(ctx, "weekly_kpi", 10); err != nil {
			return nil, err
		}
		out.WeeklyKpiReports = recordRows(rows)
	case "monthly":
		rows, err := s.d.Reports.Submitted(ctx, "monthly", 15)
		if err != nil {
			return nil, err
		}
		out.MonthlyReports = recordRows(rows)
	}
	ov, err := s.d.Classes.Overview(ctx, c.Today)
	if err != nil {
		return nil, err
	}
	out.TotalClasses, out.ActiveClasses = ov.Total, ov.Active
	if ov.AttendanceTotal > 0 {
		out.AttendanceRateToday = ptr(round1(float64(ov.Attended) * 100 / float64(ov.AttendanceTotal)))
	}
	// Laravel cộng thêm báo cáo trực lớp trong ngày (chờ phân hệ Báo cáo trực lớp).
	if out.TotalDailyReportsToday, err = s.d.Reports.DailyOn(ctx, c.Today); err != nil {
		return nil, err
	}
	return out, nil
}

type JournalOut struct {
	ID            uuid.UUID  `json:"id"`
	Branch        *string    `json:"branch"`
	ClassID       *uuid.UUID `json:"class_id"`
	ClassCode     *string    `json:"class_code"`
	Title         string     `json:"title"`
	Content       *string    `json:"content"`
	Severity      *string    `json:"severity"`
	Priority      *string    `json:"priority"`
	SeverityLabel string     `json:"severity_label"`
	User          *string    `json:"user"`
	Followup      *string    `json:"followup"`
	Status        string     `json:"status"`
}

type Incidents struct {
	Severity         string           `json:"severity"`
	Status           string           `json:"status"`
	BranchID         string           `json:"branch_id"`
	ClassID          string           `json:"class_id"`
	Branches         []Option         `json:"branches"`
	Classes          []Option         `json:"classes"`
	Journals         []JournalOut     `json:"journals"`
	Tickets          []IncidentTicket `json:"tickets"`
	TicketsPending   *string          `json:"tickets_pending"`
	TotalIncidents   int              `json:"total_incidents"`
	ResolvedCount    int              `json:"resolved_count"`
	OpenCount        int              `json:"open_count"`
	UrgentCount      int              `json:"urgent_count"`
	CanSubmitJournal bool             `json:"can_submit_journal"`
}

type IncidentFilter struct {
	Severity string // all | urgent | high | medium | low
	Status   string // all | open | resolved
	BranchID string
	ClassID  string
}

// mức sự vụ (ticket) ↔ mức nhật ký
var journalSeverityOf = map[string]string{"urgent": "urgent", "high": "important", "medium": "normal"}
var priorityOfJournal = map[string]string{"urgent": "urgent", "important": "high", "normal": "medium"}

// Incidents: màn Nhật ký sự vụ (academic.dashboards.incidents): sự việc trong Nhật ký Học vụ (gắn lớp thì lọc cơ sở theo
// lớp, không thì theo người ghi) và ticket hỗ trợ khẩn / cao (hoặc đúng mức đang lọc; ticket không gắn lớp nên lọc theo lớp
// thì không có). Nhật ký cũ academic_records không chuyển.
func (s *Service) Incidents(ctx context.Context, a *iam.Access, f IncidentFilter) (*Incidents, error) {
	severity := "all"
	switch f.Severity {
	case "urgent", "high", "medium", "low":
		severity = f.Severity
	}
	status := "all"
	if f.Status == "open" || f.Status == "resolved" {
		status = f.Status
	}
	out := &Incidents{Severity: severity, Status: status, Branches: []Option{}, Journals: []JournalOut{}, Tickets: []IncidentTicket{},
		CanSubmitJournal: a.Can("staff_report.submit")}
	jf := JournalFilter{}
	if id, err := uuid.Parse(f.BranchID); err == nil {
		jf.BranchID, out.BranchID = &id, id.String()
	}
	if id, err := uuid.Parse(f.ClassID); err == nil {
		jf.ClassID, out.ClassID = &id, id.String()
	}
	if status != "all" {
		jf.Status = status
	}
	bs, err := s.d.Branches.ListBranches(ctx)
	if err != nil {
		return nil, err
	}
	for _, b := range bs {
		if b.IsActive {
			out.Branches = append(out.Branches, Option{b.ID.String(), b.Name})
		}
	}
	if out.Classes, err = s.d.Classes.FilterOptions(ctx, a); err != nil {
		return nil, err
	}
	if out.Classes == nil {
		out.Classes = []Option{}
	}
	js, mapped := journalSeverityOf[severity]
	if severity == "all" || mapped {
		jf.Severity = js
		rows, err := s.d.Reports.Journals(ctx, jf, 30)
		if err != nil {
			return nil, err
		}
		for _, r := range rows {
			j := JournalOut{ID: r.ID, Branch: r.Branch, ClassID: r.ClassID, ClassCode: r.ClassCode, Title: r.Title, Content: r.Content,
				Severity: r.Severity, SeverityLabel: r.SeverityLabel, User: r.User, Followup: r.Followup, Status: r.Status}
			if r.Severity != nil {
				if p, ok := priorityOfJournal[*r.Severity]; ok {
					j.Priority = &p
				}
			}
			out.Journals = append(out.Journals, j)
			if r.Status == "resolved" {
				out.ResolvedCount++
			} else if r.Severity != nil && *r.Severity == "urgent" {
				out.UrgentCount++
			}
		}
	}
	if jf.ClassID == nil {
		tf := TicketIncidentFilter{Priorities: []string{"urgent", "high"}, BranchID: jf.BranchID, Status: jf.Status}
		if severity != "all" {
			tf.Priorities = []string{severity}
		}
		rows, ok, err := s.d.Tickets.Incidents(ctx, a, tf, 20)
		if err != nil {
			return nil, err
		}
		if !ok {
			out.TicketsPending = ptr(pendingTickets)
		}
		for _, t := range rows {
			out.Tickets = append(out.Tickets, t)
			switch {
			case t.Status == "resolved" || t.Status == "closed":
				out.ResolvedCount++
			case t.Priority == "urgent":
				out.UrgentCount++
			}
		}
	}
	out.TotalIncidents = len(out.Journals) + len(out.Tickets)
	out.OpenCount = max(0, out.TotalIncidents-out.ResolvedCount)
	return out, nil
}
