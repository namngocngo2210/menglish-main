package tickets

import (
	"context"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/dashboard"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/tickets/ticketsdb"
)

// DashboardTickets: ticket cho Tổng quan (hàng chờ "Ticket đang mở") và Nhật ký sự vụ (ticket khẩn / cao).
func (s *Service) DashboardTickets() dashboard.Tickets { return dashboardTickets{s} }

type dashboardTickets struct{ s *Service }

// Counts: DashboardController — ticket Mới + Đang xử lý, và ticket Mới chưa phân công, theo chi nhánh chính người tạo.
func (d dashboardTickets) Counts(ctx context.Context, branches []uuid.UUID) (*dashboard.TicketCounts, error) {
	n, err := d.s.q.DashboardTicketCounts(ctx, branches)
	if err != nil {
		return nil, err
	}
	return &dashboard.TicketCounts{Open: n.Open, Unassigned: n.Unassigned}, nil
}

// Incidents: AcademicDashboardController::incidents — ticket theo mức ưu tiên, chi nhánh chính người tạo, trạng thái
// (open = Mới / Đang xử lý, resolved = Đã giải quyết / Đã đóng). can_open: người xem mở được ticket.
func (d dashboardTickets) Incidents(ctx context.Context, a *iam.Access, f dashboard.TicketIncidentFilter, limit int) ([]dashboard.IncidentTicket, bool, error) {
	p := ticketsdb.IncidentTicketsParams{Priorities: f.Priorities, BranchID: f.BranchID, Lim: int32(limit)}
	switch f.Status {
	case "open":
		p.Statuses = []string{"open", "in_progress"}
	case "resolved":
		p.Statuses = []string{"resolved", "closed"}
	}
	rows, err := d.s.q.IncidentTickets(ctx, p)
	if err != nil {
		return nil, true, err
	}
	sc, err := d.s.scopeOf(ctx, a)
	if err != nil {
		return nil, true, err
	}
	out := make([]dashboard.IncidentTicket, 0, len(rows))
	for _, r := range rows {
		out = append(out, dashboard.IncidentTicket{ID: r.ID, Code: r.Code,
			CanOpen: a.Can(PermView) && sc.sees(ticketRef{r.CreatorID, r.AssigneeID}),
			Branch:  r.BranchName, CategoryLabel: CategoryLabel(r.Category), Title: r.Title, Excerpt: preview(r.Description, 140),
			Priority: r.Priority, Assignee: r.AssigneeName, Creator: r.CreatorName, Status: r.Status,
			StatusLabel: StatusLabel(r.Status)})
	}
	return out, true, nil
}
