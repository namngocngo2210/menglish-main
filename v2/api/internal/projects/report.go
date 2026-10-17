package projects

import (
	"cmp"
	"context"
	"slices"
	"strconv"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/projects/projectsdb"
)

// Báo cáo dự án học thuật (khu Báo cáo, AcademicProjectController::report): tiến độ từng dự án, theo thành viên, khó
// khăn chưa phản hồi, biên bản trễ deadline. Quyền academic_project.view_all.

type LastUpdate struct {
	At   time.Time `json:"at"`
	User *string   `json:"user"`
}

type ReportProject struct {
	Summary
	LastUpdate *LastUpdate `json:"last_update"`
	OpenIssues int         `json:"open_issues"`
}

type ReportMember struct {
	ID             *uuid.UUID `json:"id"`
	Name           string     `json:"name"`
	Assigned       int        `json:"assigned"`
	DoneOnTime     int        `json:"done_on_time"`
	DoneLate       int        `json:"done_late"`
	Overdue        int        `json:"overdue"`
	InProgress     int        `json:"in_progress"`
	UpdatesInMonth int        `json:"updates_in_month"`
	Penalties      int        `json:"penalties"`
}

type ReportIssue struct {
	ID           uuid.UUID `json:"id"`
	ProjectID    uuid.UUID `json:"project_id"`
	Project      *string   `json:"project"`
	User         *string   `json:"user"`
	CreatedAt    time.Time `json:"created_at"`
	Difficulties string    `json:"difficulties"`
	Responded    bool      `json:"responded"`
}

type ReportStats struct {
	Active           int `json:"active"`
	Overdue          int `json:"overdue"`
	DoneInMonth      int `json:"done_in_month"`
	DueInMonth       int `json:"due_in_month"`
	PenaltiesInMonth int `json:"penalties_in_month"`
	OpenIssues       int `json:"open_issues"`
}

type Report struct {
	Month            string          `json:"month"`
	Months           []Option        `json:"months"`
	Status           string          `json:"status"`
	Statuses         []Option        `json:"statuses"`
	Stats            ReportStats     `json:"stats"`
	Projects         []ReportProject `json:"projects"`
	Members          []ReportMember  `json:"members"`
	Issues           []ReportIssue   `json:"issues"`
	Penalties        []Penalty       `json:"penalties"`
	PenaltiesPending bool            `json:"penalties_pending"`
}

// ───────────────────────── Kỳ tháng (ReportPeriod) ─────────────────────────

func validMonth(key string) bool {
	if len(key) != 7 || key[4] != '-' {
		return false
	}
	y, err1 := strconv.Atoi(key[:4])
	m, err2 := strconv.Atoi(key[5:])
	return err1 == nil && err2 == nil && y > 0 && m >= 1 && m <= 12
}

func monthLabel(key string) string {
	t, _ := time.Parse("2006-01", key)
	return "Tháng " + t.Format("01/2006")
}

// monthOptions: tháng hiện tại và 6 tháng trước (ReportPeriod::monthOptions()).
func monthOptions(now time.Time) []Option {
	n := now.In(vnZone)
	cur := time.Date(n.Year(), n.Month(), 1, 0, 0, 0, 0, time.UTC)
	out := []Option{}
	for i := 0; i >= -6; i-- {
		key := cur.AddDate(0, i, 0).Format("2006-01")
		out = append(out, Option{Value: key, Label: monthLabel(key)})
	}
	return out
}

func (s *Service) Report(ctx context.Context, actor *iam.Access, month, status string) (*Report, error) {
	now := s.now()
	if !validMonth(month) {
		month = now.In(vnZone).Format("2006-01")
	}
	m, _ := time.Parse("2006-01", month)
	from := time.Date(m.Year(), m.Month(), 1, 0, 0, 0, 0, vnZone)
	to := from.AddDate(0, 1, 0).Add(-time.Nanosecond)
	fromDay, toDay := dateOf(from), dateOf(to)
	var st *string
	if hasKey(Statuses, status) {
		st = &status
	} else {
		status = ""
	}
	rows, err := s.q.ReportProjects(ctx, projectsdb.ReportProjectsParams{Status: st, Since: from})
	if err != nil {
		return nil, err
	}
	ids := make([]uuid.UUID, 0, len(rows))
	names := map[uuid.UUID]string{}
	statusOf := map[uuid.UUID]string{}
	for _, r := range rows {
		ids = append(ids, r.ID)
		names[r.ID], statusOf[r.ID] = r.Name, r.Status
	}
	ms, err := s.milestonesByProject(ctx, ids)
	if err != nil {
		return nil, err
	}
	ups, err := s.q.ProjectUpdates(ctx, projectsdb.ProjectUpdatesParams{ProjectIds: ids, Lim: 100000})
	if err != nil {
		return nil, err
	}
	var all []projectsdb.MilestonesOfRow
	titles := map[uuid.UUID]string{}
	for _, id := range ids {
		for _, x := range ms[id] {
			all = append(all, x)
			titles[x.ID] = x.Title
		}
	}
	mids := make([]uuid.UUID, 0, len(all))
	for _, x := range all {
		mids = append(mids, x.ID)
	}
	penalties, err := s.penalties.ForMilestones(ctx, mids)
	if err != nil {
		return nil, err
	}
	penalties = withTitles(penalties, titles)

	out := &Report{Month: month, Months: monthOptions(now), Status: status, Statuses: optionsOf(Statuses),
		Projects: make([]ReportProject, 0, len(rows)), Issues: []ReportIssue{}, PenaltiesPending: !s.penalties.Enabled()}
	// Cập nhật mới nhất trước: dòng đầu mỗi dự án là lần cập nhật gần nhất.
	last := map[uuid.UUID]*LastUpdate{}
	issues := map[uuid.UUID]int{}
	for _, u := range ups {
		if last[u.ProjectID] == nil {
			last[u.ProjectID] = &LastUpdate{At: u.CreatedAt, User: u.UserName}
		}
		if deref(u.Difficulties) != "" && deref(u.Response) == "" {
			issues[u.ProjectID]++
		}
	}
	for _, r := range rows {
		sum := summarize(projectRow{ID: r.ID, Code: r.Code, Name: r.Name, Type: r.Type, Status: r.Status, OwnerName: r.OwnerName,
			StartDate: r.StartDate, Deadline: r.Deadline, MembersCount: r.MembersCount}, ms[r.ID], now)
		out.Projects = append(out.Projects, ReportProject{Summary: sum, LastUpdate: last[r.ID], OpenIssues: issues[r.ID]})
		out.Stats.OpenIssues += issues[r.ID]
		if r.Status == "active" {
			out.Stats.Active++
		}
	}

	// Theo thành viên (người nhận mốc), nhiều mốc quá hạn trước.
	byUser := map[uuid.UUID]*ReportMember{}
	var order []uuid.UUID
	for _, x := range all {
		mm := toMile(x)
		if !mm.done() && mm.overdue(now) && statusOf[x.ProjectID] == "active" {
			out.Stats.Overdue++
		}
		if mm.done() && x.CompletedAt != nil && inRange(*x.CompletedAt, from, to) {
			out.Stats.DoneInMonth++
		}
		if inRange(x.DueDate, fromDay, toDay) {
			out.Stats.DueInMonth++
		}
		if x.AssigneeID == nil {
			continue
		}
		mb := byUser[*x.AssigneeID]
		if mb == nil {
			id := *x.AssigneeID
			mb = &ReportMember{ID: &id, Name: deref(x.AssigneeName)}
			if mb.Name == "" {
				mb.Name = "—"
			}
			byUser[id] = mb
			order = append(order, id)
		}
		mb.Assigned++
		switch over := mm.overdue(now); {
		case mm.done() && !over:
			mb.DoneOnTime++
		case mm.done():
			mb.DoneLate++
		case over:
			mb.Overdue++
		default:
			mb.InProgress++
		}
	}
	for _, u := range ups {
		if u.UserID != nil && byUser[*u.UserID] != nil && inRange(u.CreatedAt, from, to) {
			byUser[*u.UserID].UpdatesInMonth++
		}
	}
	out.Penalties = []Penalty{}
	for _, p := range penalties {
		if p.UserID != nil && byUser[*p.UserID] != nil {
			byUser[*p.UserID].Penalties++
		}
		if p.CreatedAt != nil && inRange(*p.CreatedAt, from, to) {
			out.Penalties = append(out.Penalties, p)
		}
	}
	out.Stats.PenaltiesInMonth = len(out.Penalties)
	out.Members = make([]ReportMember, 0, len(order))
	for _, id := range order {
		out.Members = append(out.Members, *byUser[id])
	}
	sortedDesc(out.Members, func(m ReportMember) int { return m.Overdue })

	// Khó khăn: chưa phản hồi trước, mới trước; tối đa 15.
	var withIssue []projectsdb.ProjectUpdatesRow
	for _, u := range ups {
		if deref(u.Difficulties) != "" {
			withIssue = append(withIssue, u)
		}
	}
	slices.SortStableFunc(withIssue, func(a, b projectsdb.ProjectUpdatesRow) int {
		ra, rb := deref(a.Response) != "", deref(b.Response) != ""
		if ra != rb {
			if ra {
				return 1
			}
			return -1
		}
		return cmp.Compare(b.CreatedAt.Unix(), a.CreatedAt.Unix())
	})
	for _, u := range withIssue[:min(15, len(withIssue))] {
		name := names[u.ProjectID]
		out.Issues = append(out.Issues, ReportIssue{ID: u.ID, ProjectID: u.ProjectID, Project: &name, User: u.UserName,
			CreatedAt: u.CreatedAt, Difficulties: *u.Difficulties, Responded: deref(u.Response) != ""})
	}
	return out, nil
}
