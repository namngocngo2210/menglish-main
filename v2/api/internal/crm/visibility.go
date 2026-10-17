package crm

import (
	"context"
	"errors"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// LeadVisible: người dùng xem được khách này theo phạm vi khách của mình (phân hệ Học phí dùng cho bill
// "Thông báo nộp học phí" khi chỉ có lead.view — scopeCustomerQuery của Laravel).
func (s *Service) LeadVisible(ctx context.Context, a *iam.Access, leadID uuid.UUID) (bool, error) {
	if !a.Can("lead.view") {
		return false, nil
	}
	_, err := s.lead(ctx, s.q, a, leadID.String())
	if errors.Is(err, errLeadNotFound) {
		return false, nil
	}
	return err == nil, err
}
