package projects

import (
	"context"
	"time"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/dashboard"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/projects/projectsdb"
)

// DashboardMilestones: hạn mốc dự án học thuật trên lịch "Việc của bạn" (MyWorkBoard::agenda, kind "project").
type DashboardMilestones struct{ s *Service }

func (s *Service) DashboardMilestones() DashboardMilestones { return DashboardMilestones{s} }

var _ dashboard.Projects = DashboardMilestones{}

func (d DashboardMilestones) Sees(ctx context.Context, a *iam.Access) (bool, error) {
	if a.Can(PermManage) {
		return true, nil
	}
	return d.s.q.HasOpenAssignment(ctx, a.UserID)
}

func (d DashboardMilestones) Agenda(ctx context.Context, a *iam.Access, from, to time.Time) ([]dashboard.AgendaItem, error) {
	rows, err := d.s.q.AgendaMilestones(ctx, projectsdb.AgendaMilestonesParams{
		FromDate: dateOf(from), ToDate: dateOf(to), Manage: a.Can(PermManage), UserID: a.UserID, SeeAll: seeAll(a),
	})
	if err != nil {
		return nil, err
	}
	out := make([]dashboard.AgendaItem, 0, len(rows))
	for _, r := range rows {
		href := projectLink(r.ProjectID)
		out = append(out, dashboard.AgendaItem{
			At: time.Date(r.DueDate.Year(), r.DueDate.Month(), r.DueDate.Day(), 0, 0, 0, 0, vnZone), DateOnly: true,
			Kind: "project", KindLabel: "Hạn mốc dự án", Title: r.Title, Subtitle: r.ProjectName, Href: &href,
		})
	}
	return out, nil
}
