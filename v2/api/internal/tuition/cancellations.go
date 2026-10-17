package tuition

import (
	"bytes"
	"context"
	"encoding/csv"
	"errors"
	"fmt"
	"math"
	"strings"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/files"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/filestore"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/tuition/tuitiondb"
)

// Hủy hóa đơn (TuitionController::invoiceCancellations / exportInvoiceCancellations / storeInvoiceCancellation /
// approveInvoiceCancellation / rejectInvoiceCancellation): yêu cầu hủy số HĐĐT của phiếu đã duyệt (hoặc số hóa đơn giấy đã
// cấp cho phiếu chưa duyệt), Admin duyệt thì vô hiệu phiếu — giữ nguyên số trong dải — và hoàn tác công nợ.

const cancellationProofSubject = "invoice_cancellation_proof"

// CancellationProofPolicy: ảnh / PDF hóa đơn hỏng, gạch chéo (PROOF_EXTENSIONS, tối đa 5MB), không bắt buộc.
var CancellationProofPolicy = filestore.Policy{Types: []string{"jpg", "jpeg", "png", "webp", "pdf"}, MaxBytes: 5 << 20,
	TooLarge: "Dung lượng tập tin ảnh minh chứng không được vượt quá 5120 kB."}

var cancellationStatusLabels = map[string]string{"pending": "Chờ duyệt hủy", "approved": "Đã duyệt hủy", "rejected": "Đã từ chối hủy"}

type CancellationFilter struct {
	Status, BranchID, Q string
	SelectedID          *uuid.UUID
	CancelInvoice       string
}

type CancellationRow struct {
	ID            uuid.UUID `json:"id"`
	InvoiceNumber string    `json:"invoice_number"`
	ReceiptNumber *string   `json:"receipt_number"`
	StudentName   *string   `json:"student_name"`
	StudentCode   *string   `json:"student_code"`
	Amount        int64     `json:"amount"`
	Reason        string    `json:"reason"`
	RequesterName *string   `json:"requester_name"`
	CreatedAt     string    `json:"created_at"`
	CreatedAgo    string    `json:"created_ago"`
	Status        string    `json:"status"`
}

type CancellationReceipt struct {
	ReceiptNumber   string  `json:"receipt_number"`
	StatusColor     string  `json:"status_color"`
	StatusLabel     string  `json:"status_label"`
	PayerName       *string `json:"payer_name"`
	PayerPhone      *string `json:"payer_phone"`
	PaymentMethod   string  `json:"payment_method"`
	MethodLabel     string  `json:"method_label"`
	IsVatInvoice    bool    `json:"is_vat_invoice"`
	PaperInvoice    *string `json:"paper_invoice_number"`
	TuitionAmount   int64   `json:"tuition_amount"`
	DiscountAmount  int64   `json:"discount_amount"`
	SurchargeAmount int64   `json:"surcharge_amount"`
	SurchargeReason *string `json:"surcharge_reason"`
}

type CancellationDetail struct {
	ID              uuid.UUID            `json:"id"`
	InvoiceNumber   string               `json:"invoice_number"`
	Status          string               `json:"status"`
	Amount          int64                `json:"amount"`
	Reason          string               `json:"reason"`
	RejectionReason *string              `json:"rejection_reason"`
	RequesterName   *string              `json:"requester_name"`
	ApproverName    *string              `json:"approver_name"`
	CreatedDate     string               `json:"created_date"`
	CreatedAt       string               `json:"created_at"`
	UpdatedAt       string               `json:"updated_at"`
	ProofURL        *string              `json:"proof_url"`
	StudentName     *string              `json:"student_name"`
	StudentCode     *string              `json:"student_code"`
	ClassName       string               `json:"class_name"`
	BranchName      string               `json:"branch_name"`
	Receipt         *CancellationReceipt `json:"receipt"`
	PayerName       *string              `json:"payer_name"`
	PayerPhone      *string              `json:"payer_phone"`
}

type CancellationsPage struct {
	Filters            map[string]string   `json:"filters"`
	Branches           []Option            `json:"branches"`
	PendingCount       int64               `json:"pending_count"`
	ApprovedMonthCount int64               `json:"approved_month_count"`
	RejectedCount      int64               `json:"rejected_count"`
	CanApproveCancel   bool                `json:"can_approve_cancel"`
	Prefill            *CancellationPrefil `json:"prefill"`
	Cancellations      []CancellationRow   `json:"cancellations"`
	Selected           *CancellationDetail `json:"selected"`
}

// CancellationPrefil: ?cancel_invoice=<số HĐ> (nút "Hủy số hóa đơn này" ở phiếu thu) điền sẵn số và số tiền.
type CancellationPrefil struct {
	InvoiceNumber string `json:"invoice_number"`
	Amount        int64  `json:"amount"`
}

func (s *Service) cancellationParams(actor *iam.Access, f *CancellationFilter) tuitiondb.ListInvoiceCancellationsParams {
	if f.Status == "" {
		f.Status = "pending"
	}
	if _, ok := cancellationStatusLabels[f.Status]; !ok {
		f.Status = "all"
	}
	p := tuitiondb.ListInvoiceCancellationsParams{BranchIds: branchLimit(actor)}
	if f.Status != "all" {
		p.Status = &f.Status
	}
	if id, err := uuid.Parse(f.BranchID); err == nil {
		p.BranchID = &id
	} else {
		f.BranchID = "all"
	}
	f.Q = strings.TrimSpace(f.Q)
	if l := likePattern(f.Q); l != nil {
		p.Search = ptr("%" + *l + "%")
	}
	return p
}

// humanAgo: "x phút / giờ / ngày trước" như Carbon::diffForHumans (tiếng Việt).
func humanAgo(t, now time.Time) string {
	d := now.Sub(t)
	switch {
	case d < time.Minute:
		return "vừa xong"
	case d < time.Hour:
		return fmt.Sprintf("%d phút trước", int(d.Minutes()))
	case d < 24*time.Hour:
		return fmt.Sprintf("%d giờ trước", int(d.Hours()))
	case d < 30*24*time.Hour:
		return fmt.Sprintf("%d ngày trước", int(d.Hours()/24))
	case d < 365*24*time.Hour:
		return fmt.Sprintf("%d tháng trước", int(d.Hours()/24/30))
	}
	return fmt.Sprintf("%d năm trước", int(d.Hours()/24/365))
}

// InvoiceCancellations: màn "Duyệt hủy hóa đơn" — số liệu, danh sách theo bộ lọc, yêu cầu đang xem, form điền sẵn.
func (s *Service) InvoiceCancellations(ctx context.Context, actor *iam.Access, f CancellationFilter) (*CancellationsPage, error) {
	limit := branchLimit(actor)
	p := s.cancellationParams(actor, &f)
	rows, err := s.q.ListInvoiceCancellations(ctx, p)
	if err != nil {
		return nil, err
	}
	nowVN := s.nowVN()
	monthStart := time.Date(nowVN.Year(), nowVN.Month(), 1, 0, 0, 0, 0, vnZone)
	counts, err := s.q.InvoiceCancellationCounts(ctx, tuitiondb.InvoiceCancellationCountsParams{
		MonthStart: monthStart, MonthEnd: monthStart.AddDate(0, 1, 0), BranchIds: limit})
	if err != nil {
		return nil, err
	}
	bs, err := s.q.ScopeBranches(ctx, limit)
	if err != nil {
		return nil, err
	}
	out := &CancellationsPage{
		Filters:  map[string]string{"status": f.Status, "branch_id": f.BranchID, "q": f.Q},
		Branches: []Option{}, PendingCount: counts.Pending, ApprovedMonthCount: counts.ApprovedMonth, RejectedCount: counts.Rejected,
		CanApproveCancel: actor.Can("invoice.approve_cancel"), Cancellations: make([]CancellationRow, 0, len(rows)),
	}
	for _, b := range bs {
		out.Branches = append(out.Branches, Option{b.ID.String(), b.Name})
	}
	for _, r := range rows {
		out.Cancellations = append(out.Cancellations, CancellationRow{
			ID: r.ID, InvoiceNumber: r.InvoiceNumber, ReceiptNumber: r.ReceiptCode, StudentName: r.StudentName, StudentCode: r.StudentCode,
			Amount: r.Amount, Reason: r.Reason, RequesterName: r.RequesterName, CreatedAt: r.CreatedAt.In(vnZone).Format("15:04 02/01/2006"),
			CreatedAgo: humanAgo(r.CreatedAt, s.now()), Status: r.Status,
		})
	}
	if f.SelectedID != nil {
		if c, err := s.cancellationScoped(ctx, actor, *f.SelectedID); err == nil {
			out.Selected = cancellationDetail(c)
		} else if !isNotFoundOrScope(err) {
			return nil, err
		}
	}
	if f.CancelInvoice != "" && actor.Can("invoice.request_cancel") {
		if id, err := s.q.ReceiptIDByInvoice(ctx, ptr(strings.TrimSpace(f.CancelInvoice))); err == nil {
			if r, err := s.loadReceiptScoped(ctx, actor, id); err == nil {
				out.Prefill = &CancellationPrefil{InvoiceNumber: textOf(r.InvoiceNumber), Amount: r.Amount}
			}
		} else if !isNoRows(err) {
			return nil, err
		}
	}
	return out, nil
}

func isNotFoundOrScope(err error) bool {
	var e *iam.Error
	return errors.As(err, &e) && (e.Status == 404 || e.Status == 403)
}

func cancellationDetail(c tuitiondb.GetInvoiceCancellationRow) *CancellationDetail {
	d := &CancellationDetail{
		ID: c.ID, InvoiceNumber: c.InvoiceNumber, Status: c.Status, Amount: c.Amount, Reason: c.Reason, RejectionReason: c.RejectionReason,
		RequesterName: c.RequesterName, ApproverName: c.ApproverName,
		CreatedDate: c.CreatedAt.In(vnZone).Format("02/01/2006"), CreatedAt: c.CreatedAt.In(vnZone).Format("15:04:05 - 02/01/2006"),
		UpdatedAt: c.UpdatedAt.In(vnZone).Format("02/01/2006 15:04"), ProofURL: c.ProofUrl,
		StudentName: c.StudentName, StudentCode: c.StudentCode, ClassName: c.ClassName, BranchName: textOf(c.BranchName),
		PayerName: firstText(c.ParentName, c.StudentName), PayerPhone: firstText(c.ParentPhone, c.StudentPhone),
	}
	if c.ProofFileID != nil {
		d.ProofURL = ptr(files.URL(*c.ProofFileID))
	}
	if d.ClassName == "" {
		d.ClassName = "Chưa gắn lớp"
	}
	if d.BranchName == "" {
		d.BranchName = "Chưa gán chi nhánh"
	}
	if c.ReceiptCode != nil {
		st := textOf(c.ReceiptStatus)
		d.Receipt = &CancellationReceipt{
			ReceiptNumber: *c.ReceiptCode, StatusColor: receiptStatusColor(st), StatusLabel: receiptStatusLabel(st),
			PayerName: firstText(c.PayerName, d.PayerName), PayerPhone: firstText(c.PayerPhone, d.PayerPhone),
			PaymentMethod: textOf(c.PaymentMethod), MethodLabel: methodLabel(textOf(c.PaymentMethod)), IsVatInvoice: val(c.IsVatInvoice),
			PaperInvoice: c.PaperInvoiceNumber, TuitionAmount: val(c.TuitionAmount), DiscountAmount: val(c.DiscountAmount),
			SurchargeAmount: val(c.SurchargeAmount), SurchargeReason: c.SurchargeReason,
		}
	}
	return d
}

func (s *Service) cancellationScoped(ctx context.Context, actor *iam.Access, id uuid.UUID) (tuitiondb.GetInvoiceCancellationRow, error) {
	c, err := s.q.GetInvoiceCancellation(ctx, id)
	if isNoRows(err) {
		return c, &iam.Error{Status: 404, Code: "not_found", Message: "Không tìm thấy yêu cầu hủy."}
	}
	if err != nil {
		return c, err
	}
	// Yêu cầu không gắn phiếu lẫn học viên: ai xem được màn Học phí cũng thấy (như TuitionBranchScope::cancellations).
	if (c.ReceiptID != nil || c.StudentID != nil) && !covers(actor, nonZero(c.ScopeBranchID)) {
		return c, forbidden("Hóa đơn này thuộc chi nhánh ngoài phạm vi bạn được quản lý.")
	}
	return c, nil
}

// ExportInvoiceCancellations: "Xuất danh sách" (CSV UTF-8 có BOM) theo bộ lọc đang xem.
func (s *Service) ExportInvoiceCancellations(ctx context.Context, actor *iam.Access, f CancellationFilter) ([]byte, error) {
	rows, err := s.q.ListInvoiceCancellations(ctx, s.cancellationParams(actor, &f))
	if err != nil {
		return nil, err
	}
	var buf bytes.Buffer
	buf.WriteString("\uFEFF")
	w := csv.NewWriter(&buf)
	_ = w.Write([]string{"Số hóa đơn", "Mã phiếu thu", "Mã HV", "Học viên", "Số tiền (VNĐ)", "Lý do hủy", "Người yêu cầu", "Thời điểm gửi",
		"Trạng thái", "Người duyệt", "Lý do từ chối"})
	for _, r := range rows {
		label := cancellationStatusLabels[r.Status]
		if label == "" {
			label = r.Status
		}
		_ = w.Write([]string{r.InvoiceNumber, textOf(r.ReceiptCode), textOf(r.StudentCode), textOf(r.StudentName), itoa64(r.Amount), r.Reason,
			textOf(r.RequesterName), r.CreatedAt.In(vnZone).Format("02/01/2006 15:04"), label, textOf(r.ApproverName), textOf(r.RejectionReason)})
	}
	w.Flush()
	return buf.Bytes(), w.Error()
}

// CancellationInput: form "Yêu cầu hủy HĐ". Số tiền phải bằng đúng giá trị hóa đơn; phiếu xác định từ số hóa đơn phía
// server.
type CancellationInput struct {
	InvoiceNumber string `json:"invoice_number"`
	Amount        *int64 `json:"amount"`
	Reason        string `json:"reason"`
}

// cancellableReceipt: phiếu đã duyệt, hoặc phiếu tiền mặt đã cấp số hóa đơn giấy còn nháp / bị trả về / chờ duyệt.
func cancellableReceipt(r tuitiondb.FinanceReceipt) bool {
	return r.Status == StatusApproved || (hasIssuedPaper(r) && (editable(r.Status) || r.Status == StatusPending))
}

// systemReceipt: phiếu hệ thống sinh khi duyệt hoàn phí / chuyển phí — không hủy riêng được.
func systemReceipt(r tuitiondb.FinanceReceipt) bool {
	code := strings.ToUpper(textOf(r.TransactionCode))
	return r.Amount < 0 || strings.HasPrefix(code, "REFUND-") || strings.HasPrefix(code, "XFER-")
}

// CreateInvoiceCancellation gửi yêu cầu hủy hóa đơn lên người duyệt hủy (invoice.approve_cancel).
func (s *Service) CreateInvoiceCancellation(ctx context.Context, actor *iam.Access, in CancellationInput, proof *filestore.Upload) (*Saved, error) {
	in.InvoiceNumber = strings.TrimSpace(in.InvoiceNumber)
	in.Reason = strings.TrimSpace(in.Reason)
	switch {
	case in.InvoiceNumber == "":
		return nil, iam.Invalid("Vui lòng nhập số hóa đơn.")
	case in.Amount == nil:
		return nil, iam.Invalid("Vui lòng nhập số tiền.")
	case in.Reason == "":
		return nil, iam.Invalid("Vui lòng nhập lý do.")
	case tooLong(&in.Reason, 1000):
		return nil, iam.Invalid("Trường Lý do không được vượt quá 1000 ký tự.")
	}
	if proof != nil {
		if err := proof.Check(CancellationProofPolicy); err != nil {
			return nil, iam.Invalid(err.Error())
		}
		if s.files == nil {
			return nil, iam.Invalid("Chưa cấu hình kho file để lưu ảnh minh chứng.")
		}
	}
	notFound := iam.Invalid("Không tìm thấy hóa đơn đã duyệt (hoặc hóa đơn giấy đã cấp số) với số " + in.InvoiceNumber + ".")
	id, err := s.q.ReceiptIDByInvoice(ctx, &in.InvoiceNumber)
	if isNoRows(err) {
		return nil, notFound
	}
	if err != nil {
		return nil, err
	}
	info, err := s.q.GetReceipt(ctx, id)
	if err != nil {
		return nil, err
	}
	r := receiptOf(info)
	if !cancellableReceipt(r) {
		return nil, notFound
	}
	if !covers(actor, nonZero(info.ScopeBranchID)) {
		return nil, forbidden("Hóa đơn này thuộc chi nhánh ngoài phạm vi bạn được quản lý.")
	}
	if systemReceipt(r) {
		return nil, invalidf("Hóa đơn %s là phiếu hoàn phí / chuyển phí do hệ thống tạo — không hủy riêng được.", in.InvoiceNumber)
	}
	if *in.Amount != r.Amount {
		return nil, invalidf("Số tiền hủy phải bằng đúng giá trị hóa đơn (%s).", money(r.Amount))
	}
	branch := nonZero(info.ScopeBranchID)
	var out *Saved
	err = s.inTx(ctx, func(tx pgx.Tx, q *tuitiondb.Queries) error {
		pending, err := q.PendingCancellationExists(ctx, &r.ID)
		if err != nil {
			return err
		}
		if pending {
			return iam.Invalid("Hóa đơn này đang có yêu cầu hủy chờ duyệt.")
		}
		c, err := q.CreateInvoiceCancellation(ctx, tuitiondb.CreateInvoiceCancellationParams{
			InvoiceNumber: *r.InvoiceNumber, ReceiptID: &r.ID, StudentID: info.OwnerStudentID, Amount: r.Amount, Reason: in.Reason,
			RequesterID: &actor.UserID,
		})
		if isUnique(err) {
			return iam.Invalid("Hóa đơn này đang có yêu cầu hủy chờ duyệt.")
		}
		if err != nil {
			return err
		}
		student := textOf(info.StudentName)
		if proof != nil {
			f, err := s.files.Save(ctx, tx, proof, files.SaveInput{
				Owner: files.Owner{Module: "tuition", SubjectType: cancellationProofSubject, SubjectID: &c.ID, BranchID: branch},
				By:    &actor.UserID,
				Audit: audit.Entry{Module: audit.ModuleTuition, SubjectType: "invoice_cancellation", SubjectID: c.ID.String(),
					SubjectLabel: cancellationLabel(c.InvoiceNumber, student), Description: "Tải ảnh minh chứng hủy hóa đơn"},
			})
			if err != nil {
				return err
			}
			if err := q.SetCancellationProof(ctx, tuitiondb.SetCancellationProofParams{ID: c.ID, ProofFileID: &f.ID}); err != nil {
				return err
			}
		}
		if err := s.recordCancellation(ctx, tx, actor, c, student, branch, audit.EventCreated, "tuition.invoice_cancellation.create",
			"Gửi yêu cầu hủy hóa đơn "+c.InvoiceNumber+": "+in.Reason); err != nil {
			return err
		}
		ids, err := s.holders(ctx, tx, "invoice.approve_cancel", branch)
		if err != nil {
			return err
		}
		if _, err := notify.Send(ctx, tx, ids, notify.Notification{
			Type: "invoice_cancel_pending", Title: "Yêu cầu hủy hóa đơn " + c.InvoiceNumber,
			Body: fmt.Sprintf("%s · %s — %s", nonEmpty(student, "Không rõ học viên"), money(c.Amount), limitText(in.Reason, 120)),
			Link: "/tuition/invoices/cancellations?status=pending&selected_id=" + c.ID.String(),
			Data: map[string]any{"cancellation_id": c.ID}, CreatedBy: &actor.UserID, Except: &actor.UserID,
		}); err != nil {
			return err
		}
		msg := "Đã gửi yêu cầu hủy hóa đơn GTGT lên cấp quản lý phê duyệt!"
		if hasIssuedPaper(r) {
			msg = fmt.Sprintf("Đã gửi yêu cầu hủy hóa đơn giấy %s lên cấp quản lý phê duyệt! Phiếu lập mới sẽ nhận số hóa đơn kế tiếp.", c.InvoiceNumber)
		}
		out = &Saved{ID: c.ID, Code: c.InvoiceNumber, Status: c.Status, Message: msg}
		return nil
	})
	if err != nil {
		return nil, err
	}
	s.log.Info("invoice cancellation requested", "cancellation_id", out.ID, "invoice", out.Code, "by", actor.UserID)
	return out, nil
}

func nonEmpty(s, fallback string) string {
	if s == "" {
		return fallback
	}
	return s
}

func cancellationLabel(invoice, student string) string {
	if student == "" {
		return "Hủy HĐ " + invoice
	}
	return "Hủy HĐ " + invoice + " (" + student + ")"
}

func (s *Service) recordCancellation(ctx context.Context, tx pgx.Tx, actor *iam.Access, c tuitiondb.FinanceInvoiceCancellation, student string,
	branch *uuid.UUID, event, action, desc string) error {
	return audit.Record(ctx, tx, audit.Entry{
		ActorID: &actor.UserID, Module: audit.ModuleTuition, Event: event, Action: action,
		SubjectType: "invoice_cancellation", SubjectID: c.ID.String(), SubjectLabel: cancellationLabel(c.InvoiceNumber, student),
		BranchID: branch, Description: desc + ".",
		Properties: map[string]any{"invoice_number": c.InvoiceNumber, "receipt_id": c.ReceiptID, "amount": c.Amount, "status": c.Status},
	})
}

var errCancellationProcessed = iam.Invalid("Yêu cầu hủy này đã được xử lý trước đó.")

// ApproveInvoiceCancellation: duyệt hủy — phiếu đã duyệt thì vô hiệu phiếu, thu hồi hoa hồng (nếu có), hoàn kho, tính lại
// công nợ; hóa đơn giấy của phiếu chưa duyệt thì chỉ vô hiệu phiếu + số hóa đơn.
func (s *Service) ApproveInvoiceCancellation(ctx context.Context, actor *iam.Access, id uuid.UUID) (*Saved, error) {
	info, err := s.cancellationScoped(ctx, actor, id)
	if err != nil {
		return nil, err
	}
	student := textOf(info.StudentName)
	branch := nonZero(info.ScopeBranchID)
	var msg string
	err = s.inTx(ctx, func(tx pgx.Tx, q *tuitiondb.Queries) error {
		// Khóa công nợ trước (cùng thứ tự với duyệt phiếu / SePay / hoàn phí), rồi yêu cầu hủy, rồi phiếu.
		var tid *uuid.UUID
		if info.ReceiptID != nil {
			if tid, err = q.ReceiptTuitionID(ctx, *info.ReceiptID); err != nil && !isNoRows(err) {
				return err
			}
		}
		if tid != nil {
			if _, err := q.LockTuition(ctx, *tid); err != nil {
				return err
			}
		}
		c, err := q.LockInvoiceCancellation(ctx, id)
		if err != nil {
			return err
		}
		if c.Status != "pending" {
			return errCancellationProcessed
		}
		var r tuitiondb.FinanceReceipt
		if c.ReceiptID != nil {
			if r, err = q.LockReceipt(ctx, *c.ReceiptID); err != nil && !isNoRows(err) {
				return err
			}
		}
		unapprovedPaper := r.ID != uuid.Nil && hasIssuedPaper(r) && (editable(r.Status) || r.Status == StatusPending)
		if r.ID == uuid.Nil || (r.Status != StatusApproved && !unapprovedPaper) {
			return iam.Invalid("Không xác định được phiếu thu đã duyệt gắn với hóa đơn này, không thể hoàn tác công nợ.")
		}
		if err := q.ApproveInvoiceCancellation(ctx, tuitiondb.ApproveInvoiceCancellationParams{ID: c.ID, ApproverID: &actor.UserID}); err != nil {
			return err
		}
		c.Status = "approved"
		if unapprovedPaper {
			if r, err = q.CancelReceiptForInvoice(ctx, tuitiondb.CancelReceiptForInvoiceParams{ID: r.ID,
				RejectionReason: ptr(limitText("Hủy số hóa đơn giấy: "+c.Reason, 1000))}); err != nil {
				return err
			}
			msg = fmt.Sprintf("Đã duyệt hủy hóa đơn giấy %s. Phiếu thu lập mới sẽ nhận số hóa đơn kế tiếp của chi nhánh.", c.InvoiceNumber)
		} else {
			// Hoa hồng của phiếu đã chi trong kỳ lương đã duyệt → thu hồi ở kỳ kế tiếp.
			var claw *Clawback
			if s.commissions != nil {
				if claw, err = s.commissions.CancellationClawback(ctx, tx, r.ID, c.InvoiceNumber, actor.UserID); err != nil {
					return err
				}
			}
			if r, err = q.CancelReceiptForInvoice(ctx, tuitiondb.CancelReceiptForInvoiceParams{ID: r.ID,
				RejectionReason: ptr(limitText("Hóa đơn/phiếu thu đã được duyệt hủy: "+c.Reason, 1000))}); err != nil {
				return err
			}
			if r.TuitionID != nil {
				if _, err := s.recalculateDebt(ctx, q, *r.TuitionID); err != nil {
					return err
				}
			}
			msg = fmt.Sprintf("Đã duyệt hủy hóa đơn %s và hoàn tác công nợ học viên!", c.InvoiceNumber)
			if claw != nil && claw.Amount != 0 {
				msg += fmt.Sprintf(" Kỳ lương chứa phiếu đã duyệt: thu hồi %s hoa hồng của %s ở lần tính lương kế tiếp.",
					money(int64(math.Abs(float64(claw.Amount)))), nonEmpty(claw.UserName, "sale"))
			}
		}
		if err := s.syncStock(ctx, tx, q, r, branch, &actor.UserID); err != nil {
			return err
		}
		if err := s.recordReceipt(ctx, tx, actor, r, student, "tuition.receipt.cancel", "Hủy hóa đơn "+c.InvoiceNumber+" của phiếu thu", branch); err != nil {
			return err
		}
		if err := s.recordCancellation(ctx, tx, actor, c, student, branch, audit.EventUpdated, "tuition.invoice_cancellation.approve",
			"Duyệt hủy hóa đơn "+c.InvoiceNumber); err != nil {
			return err
		}
		return s.notifyCancellationProcessed(ctx, tx, actor, c, msg)
	})
	if err != nil {
		return nil, err
	}
	s.log.Info("invoice cancellation approved", "cancellation_id", id, "by", actor.UserID)
	return &Saved{ID: id, Code: info.InvoiceNumber, Status: "approved", Message: msg}, nil
}

// RejectInvoiceCancellation: từ chối hủy (lý do không bắt buộc, mặc định "Yêu cầu hủy không hợp lệ").
func (s *Service) RejectInvoiceCancellation(ctx context.Context, actor *iam.Access, id uuid.UUID, reason *string) (*Saved, error) {
	info, err := s.cancellationScoped(ctx, actor, id)
	if err != nil {
		return nil, err
	}
	reason = optText(reason)
	if tooLong(reason, 1000) {
		return nil, iam.Invalid("Trường lý do từ chối không được vượt quá 1000 ký tự.")
	}
	if reason == nil {
		reason = ptr("Yêu cầu hủy không hợp lệ")
	}
	var msg string
	err = s.inTx(ctx, func(tx pgx.Tx, q *tuitiondb.Queries) error {
		c, err := q.LockInvoiceCancellation(ctx, id)
		if err != nil {
			return err
		}
		if c.Status != "pending" {
			return errCancellationProcessed
		}
		if err := q.RejectInvoiceCancellation(ctx, tuitiondb.RejectInvoiceCancellationParams{ID: id, ApproverID: &actor.UserID, RejectionReason: reason}); err != nil {
			return err
		}
		c.Status = "rejected"
		if err := s.recordCancellation(ctx, tx, actor, c, textOf(info.StudentName), nonZero(info.ScopeBranchID), audit.EventUpdated,
			"tuition.invoice_cancellation.reject", "Từ chối yêu cầu hủy hóa đơn "+c.InvoiceNumber+": "+*reason); err != nil {
			return err
		}
		msg = "Đã từ chối yêu cầu hủy hóa đơn " + c.InvoiceNumber + "!"
		return s.notifyCancellationProcessed(ctx, tx, actor, c, msg+" Lý do: "+*reason)
	})
	if err != nil {
		return nil, err
	}
	s.log.Info("invoice cancellation rejected", "cancellation_id", id, "by", actor.UserID)
	return &Saved{ID: id, Code: info.InvoiceNumber, Status: "rejected", Message: msg}, nil
}

// notifyCancellationProcessed: báo kết quả cho người gửi yêu cầu (thay hộp "Việc cần duyệt" của Laravel).
func (s *Service) notifyCancellationProcessed(ctx context.Context, tx pgx.Tx, actor *iam.Access, c tuitiondb.FinanceInvoiceCancellation, body string) error {
	if c.RequesterID == nil {
		return nil
	}
	title := "Yêu cầu hủy hóa đơn " + c.InvoiceNumber + " đã được duyệt"
	if c.Status == "rejected" {
		title = "Yêu cầu hủy hóa đơn " + c.InvoiceNumber + " bị từ chối"
	}
	_, err := notify.Send(ctx, tx, []uuid.UUID{*c.RequesterID}, notify.Notification{
		Type: "invoice_cancel_processed", Title: title, Body: body,
		Link: "/tuition/invoices/cancellations?status=all&selected_id=" + c.ID.String(),
		Data: map[string]any{"cancellation_id": c.ID, "status": c.Status}, CreatedBy: &actor.UserID, Except: &actor.UserID,
	})
	return err
}

// readCancellationProof: quyền mở ảnh minh chứng — người xem được màn Học phí và yêu cầu trong phạm vi chi nhánh.
func (s *Service) readCancellationProof(ctx context.Context, a *iam.Access, f files.File) (string, error) {
	if f.SubjectID == nil || !a.Can("tuition.view") {
		return "", forbidden("Bạn không có quyền xem ảnh minh chứng này.")
	}
	if _, err := s.cancellationScoped(ctx, a, *f.SubjectID); err != nil {
		return "", err
	}
	return "", nil
}
