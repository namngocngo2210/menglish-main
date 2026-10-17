package tuition

import (
	"context"
	"fmt"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/tuition/tuitiondb"
)

// Nhật ký (phân hệ "Học phí & Thu chi") và thông báo của Học phí (AdminNotification của Laravel). Thư điện tử gửi
// phụ huynh / kế toán chưa có ở v2: TODO(email).

func init() {
	audit.RegisterSubject("tuition_receipt", "Phiếu thu")
	audit.RegisterSubject("student_tuition", "Khoản học phí")
	audit.RegisterSubject("invoice_range", "Dải số hóa đơn")
	audit.RegisterSubject("invoice_cancellation", "Yêu cầu hủy hóa đơn")
	audit.RegisterSubject("tuition_refund_request", "Yêu cầu hoàn / chuyển phí")
	audit.RegisterSubject("sepay_config", "Cấu hình SePay")
	for _, p := range []string{"/tuition", "/sepay"} {
		audit.RegisterPath(p, audit.ModuleTuition)
	}
	for k, t := range map[string]notify.TypeInfo{
		"fee_payment":              {Label: "Đã nhận học phí", Icon: "paid", Tone: notify.ToneSuccess},
		"debt_reminder":            {Label: "Nhắc học phí", Icon: "payments", Tone: notify.ToneError},
		"invoice_range_changed":    {Label: "Thay đổi dải số hóa đơn", Icon: "pin", Tone: notify.ToneInfo},
		"invoice_range_low":        {Label: "Dải số hóa đơn sắp hết", Icon: "pin", Tone: notify.ToneWarning},
		"invoice_cancel_pending":   {Label: "Yêu cầu hủy hóa đơn", Icon: "receipt_long", Tone: notify.ToneWarning},
		"invoice_cancel_processed": {Label: "Kết quả hủy hóa đơn", Icon: "receipt_long", Tone: notify.ToneInfo},
		"refund_request_pending":   {Label: "Yêu cầu hoàn / chuyển phí", Icon: "currency_exchange", Tone: notify.ToneWarning},
		"refund_request_processed": {Label: "Kết quả hoàn / chuyển phí", Icon: "currency_exchange", Tone: notify.ToneInfo},
		"sepay_payment":            {Label: "SePay gạch nợ tự động", Icon: "account_balance", Tone: notify.ToneSuccess},
		"sepay_review":             {Label: "Giao dịch SePay cần đối soát", Icon: "account_balance", Tone: notify.ToneWarning},
	} {
		notify.RegisterType(k, t)
	}
}

func receiptLabel(code, student string) string {
	if student == "" {
		return code
	}
	return code + " (" + student + ")"
}

// recordReceipt ghi nhật ký thao tác trên phiếu thu.
func (s *Service) recordReceipt(ctx context.Context, tx pgx.Tx, actor *iam.Access, r tuitiondb.FinanceReceipt, student, action, desc string, branch *uuid.UUID) error {
	var by *uuid.UUID
	if actor != nil {
		by = &actor.UserID
	}
	return audit.Record(ctx, tx, audit.Entry{
		ActorID: by, Module: audit.ModuleTuition, Event: audit.EventUpdated, Action: action,
		SubjectType: "tuition_receipt", SubjectID: r.ID.String(), SubjectLabel: receiptLabel(r.Code, student), BranchID: branch,
		Description: desc + " " + r.Code + ".",
		Properties: map[string]any{
			"status": r.Status, "amount": r.Amount, "surcharge_amount": r.SurchargeAmount, "discount_amount": r.DiscountAmount,
			"payment_method": r.PaymentMethod, "invoice_number": r.InvoiceNumber, "tuition_id": r.TuitionID,
		},
	})
}

// approvers: người duyệt phiếu thu (quyền tuition.approve chỉ Admin dùng được), trừ người lập.
func approvers(ctx context.Context, db tuitiondb.DBTX) ([]uuid.UUID, error) {
	return iam.AdminIDs(ctx, db)
}

// notifyReceiptPending: phiếu mới / gửi lại chờ duyệt → người duyệt.
func (s *Service) notifyReceiptPending(ctx context.Context, tx pgx.Tx, r tuitiondb.FinanceReceipt, student string) error {
	ids, err := approvers(ctx, tx)
	if err != nil {
		return err
	}
	if student == "" {
		student = "Học viên"
	}
	_, err = notify.Send(ctx, tx, ids, notify.Notification{
		Type: "receipt_pending", Title: "Phiếu thu mới chờ bạn phê duyệt",
		Body:      fmt.Sprintf("Nhân viên vừa lập phiếu thu #%s (%s) cho học viên %s. Vui lòng đối chiếu chứng từ và phê duyệt.", r.Code, money(r.Amount), student),
		Link:      "/tuition/receipts/approve?selected_id=" + r.ID.String(),
		Data:      map[string]any{"receipt_id": r.ID, "receipt_number": r.Code, "amount": r.Amount, "student_id": r.StudentID},
		CreatedBy: r.CreatorID, Except: r.CreatorID,
	})
	return err
}

func (s *Service) notifyReceiptApproved(ctx context.Context, tx pgx.Tx, actor *iam.Access, r tuitiondb.FinanceReceipt, student string) error {
	if r.CreatorID == nil {
		return nil
	}
	if student == "" {
		student = "Học viên"
	}
	_, err := notify.Send(ctx, tx, []uuid.UUID{*r.CreatorID}, notify.Notification{
		Type: "receipt_approved", Title: "Xác nhận thu học phí thành công",
		Body:      fmt.Sprintf("Phiếu thu #%s (%s) của học viên %s đã được phê duyệt thành công. Hóa đơn số: %s.", r.Code, money(r.Amount), student, textOf(r.InvoiceNumber)),
		Link:      "/tuition/history?search=" + r.Code,
		Data:      map[string]any{"receipt_id": r.ID, "receipt_number": r.Code, "invoice_number": r.InvoiceNumber, "amount": r.Amount, "student_id": r.StudentID},
		CreatedBy: &actor.UserID, Except: &actor.UserID,
	})
	// TODO(email): thư "Giao dịch mới" cho kế toán (NotificationService::notifyTransactionReceipt).
	return err
}

func (s *Service) notifyReceiptRejected(ctx context.Context, tx pgx.Tx, actor *iam.Access, r tuitiondb.FinanceReceipt) error {
	if r.CreatorID == nil {
		return nil
	}
	_, err := notify.Send(ctx, tx, []uuid.UUID{*r.CreatorID}, notify.Notification{
		Type: "receipt_rejected", Title: "Phiếu thu bị trả về cần sửa",
		Body: fmt.Sprintf("Phiếu thu #%s bị từ chối: %s. Vui lòng sửa và gửi duyệt lại.", r.Code, textOf(r.RejectionReason)),
		Link: "/tuition/receipts/" + r.ID.String() + "/edit",
		Data: map[string]any{"receipt_id": r.ID}, CreatedBy: &actor.UserID, Except: &actor.UserID,
	})
	return err
}

// confirmPayment: báo phụ huynh đã nhận học phí (PaymentConfirmation), một lần cho mỗi phiếu. Cổng học viên nhận
// thông báo trong ứng dụng; chưa gửi được thư nên báo người phụ trách khách (CRM) kèm nội dung để xác nhận qua Zalo.
func (s *Service) confirmPayment(ctx context.Context, tx pgx.Tx, r tuitiondb.FinanceReceipt) error {
	if r.Amount <= 0 {
		return nil
	}
	q := s.q.WithTx(tx)
	info, err := q.GetReceipt(ctx, r.ID)
	if err != nil {
		return err
	}
	if info.OwnerStudentID == nil {
		return nil
	}
	st, err := q.PaymentConfirmTarget(ctx, *info.OwnerStudentID)
	if err != nil {
		return err
	}
	text := s.paymentText(info, st.FullName)
	key := "PAYCONFIRM-" + r.ID.String()
	if st.UserID != nil {
		if _, err := notify.SendOnce(ctx, tx, []uuid.UUID{*st.UserID}, key, notify.Notification{
			Type: "fee_payment", Title: "Đã nhận học phí " + money(r.Amount), Body: text,
			Data: map[string]any{"receipt_id": r.ID, "student_id": st.ID},
		}); err != nil {
			return err
		}
	}
	// TODO(email): gửi thư xác nhận cho phụ huynh (ParentMessenger); gửi được thì không cần báo người phụ trách.
	if st.OwnerID == nil {
		return nil
	}
	phone := firstText(st.ParentPhone, &st.Phone)
	reason := "Học viên chưa có email"
	if st.Email != nil || st.LeadEmail != nil {
		reason = "Chưa gửi được email xác nhận"
	}
	suffix := ""
	if phone != nil {
		suffix = " (" + *phone + ")"
	}
	_, err = notify.SendOnce(ctx, tx, []uuid.UUID{*st.OwnerID}, key, notify.Notification{
		Type:  "payment_confirm_manual",
		Title: fmt.Sprintf("Đã nhận học phí của %s (%s)", st.FullName, money(r.Amount)),
		Body:  reason + "; đã báo ở Cổng Học viên. Nếu cần, sao chép nội dung và xác nhận với phụ huynh qua Zalo" + suffix + ".",
		Link:  "/tuition/history?search=" + r.Code,
		Data:  map[string]any{"copy_text": text, "student_id": st.ID, "receipt_id": r.ID},
	})
	return err
}

// paymentText: nội dung xác nhận đã nhận học phí gửi phụ huynh.
func (s *Service) paymentText(r tuitiondb.GetReceiptRow, student string) string {
	day := s.nowVN().Format("02/01/2006")
	if r.PaymentDate != nil {
		day = r.PaymentDate.Format("02/01/2006")
	}
	code := ""
	if r.StudentCode != nil {
		code = " (" + *r.StudentCode + ")"
	}
	class := ""
	if c := firstText(r.TuitionClassName, r.CurrentClassName); c != nil {
		class = " — lớp " + *c
	}
	debt := "• Học viên đã hoàn tất học phí của hợp đồng này."
	if r.TuitionDebt != nil && *r.TuitionDebt > 0 {
		debt = "• Học phí còn phải đóng: " + money(*r.TuitionDebt)
	}
	return "Kính gửi Quý phụ huynh,\n" +
		"MEnglish xác nhận đã nhận " + money(r.Amount) + " học phí của học viên " + student + code + class + " ngày " + day + ".\n" +
		"• Mã phiếu thu: " + r.Code + "\n" + debt + "\nCảm ơn Quý phụ huynh đã tin tưởng MEnglish!"
}
