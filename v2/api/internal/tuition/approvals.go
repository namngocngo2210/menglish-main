package tuition

import (
	"context"
	"strings"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/approvals"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/tuition/tuitiondb"
)

// ApprovalSources: nguồn Học phí của hộp "Việc cần duyệt" — phiếu thu, hủy hóa đơn, hoàn tiền & khất nợ
// (ReceiptApprovalSource / InvoiceCancellationApprovalSource / RefundApprovalSource của Laravel).
func (s *Service) ApprovalSources() []approvals.Source {
	return []approvals.Source{receiptSource{s}, cancellationSource{s}, refundSource{s}}
}

func firstOf[T any](rows []T, err error) (*T, error) {
	if err != nil || len(rows) == 0 {
		return nil, err
	}
	return &rows[0], nil
}

func studentLabel(name *string, code string) string {
	if name == nil {
		return ""
	}
	if code == "" {
		return *name
	}
	return *name + " (" + code + ")"
}

func joinDot(parts ...string) *string {
	out := []string{}
	for _, p := range parts {
		if p = strings.TrimSpace(p); p != "" {
			out = append(out, p)
		}
	}
	if len(out) == 0 {
		return nil
	}
	v := strings.Join(out, " · ")
	return &v
}

// ───── Phiếu thu chờ duyệt (duyệt = phát hành hóa đơn, như màn Duyệt phiếu thu) ─────

type receiptSource struct{ s *Service }

func (receiptSource) Key() string      { return "receipt" }
func (receiptSource) Label() string    { return "Phiếu thu chờ duyệt" }
func (receiptSource) Group() string    { return approvals.GroupTuition }
func (receiptSource) IndexURL() string { return "/tuition/receipts/approve?status=pending" }
func (receiptSource) CanView(a *iam.Access) bool {
	return a.Can("tuition.view") && a.Can("tuition.approve")
}

func (x receiptSource) Count(ctx context.Context, _ *iam.Access) (int64, error) {
	return x.s.q.ApprovalReceiptCount(ctx)
}

func (x receiptSource) item(r tuitiondb.ApprovalReceiptsRow) approvals.Item {
	method := methodLabel(r.PaymentMethod)
	who := textOf(r.StudentName)
	if who == "" {
		who = textOf(r.PayerName)
	}
	amount := r.Amount
	student := studentLabel(r.StudentName, r.StudentCode)
	if student == "" {
		student = textOf(r.PayerName)
	}
	return approvals.Item{Source: x.Key(), ID: r.ID, Title: "Phiếu thu " + r.Code, Subtitle: joinDot(who, method),
		URL: "/tuition/receipts/approve?status=pending&selected_id=" + r.ID.String(), CreatedAt: &r.CreatedAt, Amount: &amount,
		Meta: approvals.MetaOf("Học viên", student, "Hình thức", method, "Mã giao dịch", textOf(r.TransactionCode),
			"Người lập", textOf(r.CreatorName), "Ghi chú", approvals.Limit(r.Notes, 200))}
}

func (x receiptSource) list(ctx context.Context, id *uuid.UUID, limit int) ([]approvals.Item, error) {
	rows, err := x.s.q.ApprovalReceipts(ctx, tuitiondb.ApprovalReceiptsParams{ID: id, Lim: int32(limit)})
	out := make([]approvals.Item, 0, len(rows))
	for _, r := range rows {
		out = append(out, x.item(r))
	}
	return out, err
}

func (x receiptSource) Pending(ctx context.Context, _ *iam.Access, limit int) ([]approvals.Item, error) {
	return x.list(ctx, nil, limit)
}

func (x receiptSource) Find(ctx context.Context, _ *iam.Access, id uuid.UUID) (*approvals.Item, error) {
	return firstOf(x.list(ctx, &id, 1))
}

func (receiptSource) Supports(a *iam.Access, action string) bool {
	if action == approvals.Approve {
		return a.Can("tuition.approve")
	}
	return a.Can("tuition.reject")
}

func (x receiptSource) Approve(ctx context.Context, a *iam.Access, id uuid.UUID) (string, error) {
	res, err := x.s.ApproveReceipt(ctx, a, id, false)
	if err != nil {
		return "", err
	}
	return res.Message, nil
}

func (x receiptSource) Reject(ctx context.Context, a *iam.Access, id uuid.UUID, reason string) (string, error) {
	res, err := x.s.RejectReceipt(ctx, a, id, &reason)
	if err != nil {
		return "", err
	}
	return res.Message, nil
}

// ───── Yêu cầu hủy hóa đơn ─────

type cancellationSource struct{ s *Service }

func (cancellationSource) Key() string      { return "invoice_cancellation" }
func (cancellationSource) Label() string    { return "Yêu cầu hủy hóa đơn" }
func (cancellationSource) Group() string    { return approvals.GroupTuition }
func (cancellationSource) IndexURL() string { return "/tuition/invoices/cancellations?status=pending" }
func (cancellationSource) CanView(a *iam.Access) bool {
	return a.Can("tuition.view") && a.Can("invoice.approve_cancel")
}

func (x cancellationSource) Count(ctx context.Context, _ *iam.Access) (int64, error) {
	return x.s.q.ApprovalCancellationCount(ctx)
}

func (x cancellationSource) list(ctx context.Context, id *uuid.UUID, limit int) ([]approvals.Item, error) {
	rows, err := x.s.q.ApprovalCancellations(ctx, tuitiondb.ApprovalCancellationsParams{ID: id, Lim: int32(limit)})
	out := make([]approvals.Item, 0, len(rows))
	for _, r := range rows {
		amount := r.Amount
		out = append(out, approvals.Item{Source: x.Key(), ID: r.ID, Title: "Hủy HĐ " + r.InvoiceNumber,
			Subtitle: joinDot(textOf(r.StudentName), approvals.Limit(&r.Reason, 60)),
			URL:      "/tuition/invoices/cancellations?status=pending&selected_id=" + r.ID.String(), CreatedAt: &r.CreatedAt, Amount: &amount,
			Meta: approvals.MetaOf("Học viên", studentLabel(r.StudentName, r.StudentCode), "Phiếu thu", textOf(r.ReceiptCode),
				"Lý do hủy", approvals.Limit(&r.Reason, 300), "Người yêu cầu", textOf(r.RequesterName))})
	}
	return out, err
}

func (x cancellationSource) Pending(ctx context.Context, _ *iam.Access, limit int) ([]approvals.Item, error) {
	return x.list(ctx, nil, limit)
}

func (x cancellationSource) Find(ctx context.Context, _ *iam.Access, id uuid.UUID) (*approvals.Item, error) {
	return firstOf(x.list(ctx, &id, 1))
}

func (cancellationSource) Supports(a *iam.Access, _ string) bool {
	return a.Can("invoice.approve_cancel")
}

func (x cancellationSource) Approve(ctx context.Context, a *iam.Access, id uuid.UUID) (string, error) {
	res, err := x.s.ApproveInvoiceCancellation(ctx, a, id)
	if err != nil {
		return "", err
	}
	return res.Message, nil
}

func (x cancellationSource) Reject(ctx context.Context, a *iam.Access, id uuid.UUID, reason string) (string, error) {
	res, err := x.s.RejectInvoiceCancellation(ctx, a, id, &reason)
	if err != nil {
		return "", err
	}
	return res.Message, nil
}

// ───── Hoàn tiền / chuyển nhượng / khất nợ / bảo lưu: duyệt cần ảnh chứng từ → ở màn gốc; hộp chỉ từ chối ─────

type refundSource struct{ s *Service }

func (refundSource) Key() string      { return "refund" }
func (refundSource) Label() string    { return "Hoàn tiền & khất nợ" }
func (refundSource) Group() string    { return approvals.GroupTuition }
func (refundSource) IndexURL() string { return "/tuition/refunds?status=pending" }

// types: loại hồ sơ người này được duyệt (TuitionRefundRequest::approvePermission).
func (refundSource) types(a *iam.Access) []string {
	out := []string{}
	for _, o := range refundTypes {
		if a.Can(refundApprovePermission(o.Value)) {
			out = append(out, o.Value)
		}
	}
	return out
}

func (x refundSource) CanView(a *iam.Access) bool {
	return a.Can("tuition.view") && len(x.types(a)) > 0
}

func (x refundSource) Count(ctx context.Context, a *iam.Access) (int64, error) {
	return x.s.q.ApprovalRefundCount(ctx, x.types(a))
}

func (x refundSource) list(ctx context.Context, a *iam.Access, id *uuid.UUID, limit int) ([]approvals.Item, error) {
	rows, err := x.s.q.ApprovalRefunds(ctx, tuitiondb.ApprovalRefundsParams{Types: x.types(a), ID: id, Lim: int32(limit)})
	if err != nil {
		return nil, err
	}
	days, now := x.s.refundProcessingDays(ctx), x.s.now()
	out := make([]approvals.Item, 0, len(rows))
	for _, r := range rows {
		label := refundTypeLabel(r.Type)
		it := approvals.Item{Source: x.Key(), ID: r.ID, Title: label + " · " + r.StudentName, URL: "/tuition/refunds?status=pending",
			CreatedAt: &r.CreatedAt}
		if r.TargetName != nil {
			it.Subtitle = ptr("Chuyển sang " + *r.TargetName)
		} else if v := approvals.Limit(&r.Reason, 60); v != "" {
			it.Subtitle = &v
		}
		if r.RefundAmount > 0 {
			amount := r.RefundAmount
			it.Amount = &amount
		}
		deadline := ""
		if hasProcessingDeadline(r.Type) {
			d := refundDeadline(r.CreatedAt, days)
			deadline = d.Format("02/01/2006")
			if now.After(d) {
				it.Flag = ptr("Quá hạn xử lý")
			}
		}
		it.Meta = approvals.MetaOf("Loại hồ sơ", label, "Học viên", r.StudentName+" ("+r.StudentCode+")",
			"Người nhận chuyển nhượng", textOf(r.TargetName), "Hạn xử lý", deadline, "Người đề nghị", textOf(r.RequesterName))
		out = append(out, it)
	}
	return out, nil
}

func (x refundSource) Pending(ctx context.Context, a *iam.Access, limit int) ([]approvals.Item, error) {
	return x.list(ctx, a, nil, limit)
}

func (x refundSource) Find(ctx context.Context, a *iam.Access, id uuid.UUID) (*approvals.Item, error) {
	return firstOf(x.list(ctx, a, &id, 1))
}

func (refundSource) Supports(a *iam.Access, action string) bool {
	return action == approvals.Reject && a.Can("refund_transfer.reject")
}

func (refundSource) Approve(context.Context, *iam.Access, uuid.UUID) (string, error) {
	return "", iam.Invalid("Duyệt hồ sơ cần ảnh chứng từ, vui lòng duyệt ở màn Hoàn tiền & Khất nợ.")
}

func (x refundSource) Reject(ctx context.Context, a *iam.Access, id uuid.UUID, reason string) (string, error) {
	res, err := x.s.RejectRefund(ctx, a, id, &reason)
	if err != nil {
		return "", err
	}
	return res.Message, nil
}
