package tuition

import (
	"context"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/portal"
)

// PortalTuition: học phí cho Cổng học viên (TuitionPaymentHistory::forStudent không phải bản nhân sự): tên khoản
// "Học phí <khóa / lớp>", không có người lập / người duyệt, số hóa đơn, ghi chú nội bộ và phiếu chưa duyệt.
func (s *Service) PortalTuition() portal.Tuition { return portalTuition{s} }

type portalTuition struct{ s *Service }

func (p portalTuition) PortalPayments(ctx context.Context, studentID uuid.UUID) (portal.TuitionTotals, any, error) {
	st, err := p.s.q.GetStudent(ctx, studentID)
	if isNoRows(err) {
		return portal.TuitionTotals{}, nil, nil
	}
	if err != nil {
		return portal.TuitionTotals{}, nil, err
	}
	tuitions, err := p.s.q.StudentTuitions(ctx, studentID)
	if err != nil {
		return portal.TuitionTotals{}, nil, err
	}
	h, err := p.s.paymentHistory(ctx, st, tuitions)
	if err != nil {
		return portal.TuitionTotals{}, nil, err
	}
	for i, t := range tuitions {
		name := "khóa học"
		for _, n := range []*string{t.CourseName, t.ClassName} {
			if n != nil && *n != "" {
				name = *n
				break
			}
		}
		h.Tuitions[i].Label = "Học phí " + name
	}
	for i := range h.Payments {
		r := &h.Payments[i]
		r.InvoiceNumber, r.SurchargeDetail, r.CreatorName, r.ApproverName, r.Notes = nil, nil, nil, nil, nil
	}
	h.Others = []OtherReceipt{}
	totals := portal.TuitionTotals{HasTuition: len(tuitions) > 0, FinalAmount: h.Summary.FinalAmount,
		PaidAmount: h.Summary.PaidAmount, DebtAmount: h.Summary.DebtAmount, Installments: h.Summary.Installments}
	return totals, h, nil
}
