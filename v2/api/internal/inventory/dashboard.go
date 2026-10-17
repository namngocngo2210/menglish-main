package inventory

import (
	"context"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/dashboard"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/inventory/inventorydb"
)

// DashboardOrders: order học thuật của giáo viên trong tháng cho khối chất lượng giảng dạy (dashboard.Orders,
// StaffReportController::academicOrders).
func (s *Service) DashboardOrders() dashboard.Orders { return dashboardOrders{s} }

type dashboardOrders struct{ s *Service }

func (d dashboardOrders) Academic(ctx context.Context, requesters []uuid.UUID, from, to time.Time, limit int) ([]dashboard.AcademicOrder, error) {
	if len(requesters) == 0 {
		return []dashboard.AcademicOrder{}, nil
	}
	rows, err := d.s.q.DashboardAcademicOrders(ctx, inventorydb.DashboardAcademicOrdersParams{
		Requesters: requesters, FromAt: from, ToAt: to, DateFrom: from, DateTo: to, Lim: int32(limit),
	})
	out := make([]dashboard.AcademicOrder, 0, len(rows))
	for _, r := range rows {
		name := r.RequesterName
		out = append(out, dashboard.AcademicOrder{ID: r.ID, Code: r.Code, Title: r.Title, Teacher: &name, Class: r.ClassName,
			UseDate: r.UseDate.Format("2006-01-02"), StatusLabel: labelOf(OrderStatuses, r.Status), Open: isOpen(r.Status)})
	}
	return out, err
}
