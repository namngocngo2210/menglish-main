package portal

import (
	"context"
	"errors"
	"slices"
	"strings"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/portal/portaldb"
)

// "Học viên báo đã đóng học phí" (PaymentReportApprovalSource + PaymentReportService): người duyệt phiếu thu
// (tuition.view + tuition.approve, phạm vi chi nhánh Học phí) xác nhận / từ chối, rồi lập phiếu thu. Học viên nhận kết
// quả ở hộp thư cổng.

const tuitionScope = "tuition"

var errReportGone = &iam.Error{Status: 409, Code: "conflict", Message: "Yêu cầu không còn chờ xác nhận hoặc ngoài phạm vi của bạn."}

// ReportRow: một yêu cầu trên màn duyệt.
type ReportRow struct {
	PaymentReport
	StudentID    uuid.UUID  `json:"student_id"`
	StudentCode  string     `json:"student_code"`
	StudentName  string     `json:"student_name"`
	ClassName    *string    `json:"class_name"`
	ResolverName *string    `json:"resolver_name"`
	CreatorName  *string    `json:"creator_name"`
	TuitionID    *uuid.UUID `json:"tuition_id"`
}

var reportStatuses = []string{"pending", "confirmed", "rejected"}

func scopeParams(actor *iam.Access) (bool, []uuid.UUID) {
	limit := actor.BranchLimit(tuitionScope)
	return limit != nil, nonNil(limit)
}

// PaymentReports: yêu cầu theo trạng thái (mặc định chờ xác nhận) trong phạm vi chi nhánh Học phí.
func (s *Service) PaymentReports(ctx context.Context, actor *iam.Access, status string) ([]ReportRow, error) {
	if !slices.Contains(reportStatuses, status) {
		status = "pending"
	}
	limited, branches := scopeParams(actor)
	rows, err := s.q.ListPaymentReports(ctx, portaldb.ListPaymentReportsParams{Status: status, LimitBranches: limited, BranchIds: branches})
	if err != nil {
		return nil, err
	}
	out := make([]ReportRow, 0, len(rows))
	for _, r := range rows {
		out = append(out, ReportRow{
			PaymentReport: PaymentReport{ID: r.ID, Code: r.Code, Amount: r.Amount, Content: r.Content, Status: r.Status,
				StatusLabel: reportStatusLabels[r.Status], RejectionReason: r.RejectionReason, CreatedAt: r.CreatedAt, ResolvedAt: r.ResolvedAt},
			StudentID: r.StudentID, StudentCode: r.StudentCode, StudentName: r.StudentName, ClassName: r.ClassName,
			ResolverName: r.ResolverName, CreatorName: r.CreatorName, TuitionID: r.TuitionID})
	}
	return out, nil
}

// PendingReports: số yêu cầu chờ xác nhận trong phạm vi (đếm trên tab Cần duyệt).
func (s *Service) PendingReports(ctx context.Context, actor *iam.Access) (int, error) {
	limited, branches := scopeParams(actor)
	n, err := s.q.PendingPaymentReportCount(ctx, portaldb.PendingPaymentReportCountParams{LimitBranches: limited, BranchIds: branches})
	return int(n), err
}

// ConfirmReport: kế toán xác nhận đã nhận tiền (sẽ lập phiếu thu).
func (s *Service) ConfirmReport(ctx context.Context, actor *iam.Access, id uuid.UUID) error {
	return s.resolveReport(ctx, actor, id, "confirmed", nil)
}

// RejectReport: kế toán chưa xác nhận khoản đóng (bắt buộc lý do).
func (s *Service) RejectReport(ctx context.Context, actor *iam.Access, id uuid.UUID, reason string) error {
	reason = strings.TrimSpace(reason)
	switch {
	case reason == "":
		return iam.Invalid("Vui lòng nhập lý do từ chối.")
	case runes(reason) > 1000:
		return iam.Invalid("Trường lý do không được vượt quá 1000 ký tự.")
	}
	return s.resolveReport(ctx, actor, id, "rejected", &reason)
}

func (s *Service) resolveReport(ctx context.Context, actor *iam.Access, id uuid.UUID, status string, reason *string) error {
	uid := actor.UserID
	err := pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
		q := s.q.WithTx(tx)
		r, err := q.LockPaymentReport(ctx, id)
		if errors.Is(err, pgx.ErrNoRows) {
			return errReportGone
		}
		if err != nil {
			return err
		}
		branch := &r.ScopeBranchID
		if r.ScopeBranchID == uuid.Nil {
			branch = nil
		}
		if r.Status != "pending" || !actor.CoversBranch(tuitionScope, branch) {
			return errReportGone
		}
		if err := q.ResolvePaymentReport(ctx, portaldb.ResolvePaymentReportParams{Status: status, Reason: reason, ResolvedBy: &uid, ID: id}); err != nil {
			return err
		}
		amount := money(r.Amount)
		n := notify.Notification{Type: "tuition_payment_report", Title: "Kế toán đã xác nhận khoản đóng " + amount,
			Body: "Kế toán đã xác nhận khoản đóng " + amount + " của học viên " + r.StudentName +
				". Biên lai sẽ hiện trong lịch sử thu học phí sau khi phiếu thu được duyệt.",
			Link: "/portal/home", CreatedBy: &uid, Data: map[string]any{"report_id": id.String()}}
		event, desc := audit.EventUpdated, "Xác nhận báo đóng học phí "+amount+" - "+r.StudentName
		if status == "rejected" {
			n.Type, n.Title = "tuition_payment_report_rejected", "Kế toán chưa xác nhận khoản đóng "+amount
			n.Body = "Kế toán chưa xác nhận khoản đóng " + amount + " của học viên " + r.StudentName + ". Lý do: " + *reason +
				". Vui lòng liên hệ trung tâm để được hỗ trợ."
			event, desc = audit.EventUpdated, "Từ chối báo đóng học phí "+amount+" - "+r.StudentName+". Lý do: "+*reason
		}
		if err := s.sendPortal(ctx, tx, r.StudentID, n); err != nil {
			return err
		}
		return audit.Record(ctx, tx, audit.Entry{ActorID: &uid, Module: audit.ModuleTuition, Event: event,
			Action: "tuition.payment_report_" + status, SubjectType: "payment_report", SubjectID: id.String(), SubjectLabel: r.Code,
			BranchID: branch, Description: desc,
			Changes: audit.Diff(map[string]any{"status": r.Status}, map[string]any{"status": status, "rejection_reason": reason})})
	})
	if err != nil {
		return err
	}
	s.log.Info("payment report resolved", "report", id, "status", status, "by", uid, "at", time.Now().UTC())
	return nil
}
