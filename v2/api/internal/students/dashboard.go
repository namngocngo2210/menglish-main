package students

import (
	"context"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/dashboard"
)

// DashboardStudents: số học viên đang học cho bảng điều hành của Tổng quan (dashboard.Students).
func (s *Service) DashboardStudents() dashboard.Students { return dashboardStudents{s} }

type dashboardStudents struct{ s *Service }

func (d dashboardStudents) StudyingInBranches(ctx context.Context, branches []uuid.UUID) (int64, error) {
	return d.s.q.DashboardStudying(ctx, branches)
}
