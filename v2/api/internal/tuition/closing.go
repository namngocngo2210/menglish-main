package tuition

import (
	"context"
	"fmt"
	"slices"
	"strings"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/classes"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/crm"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/tasks"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/tuition/tuitiondb"
)

// Phần học phí của Chốt & Xếp lớp (CrmController::processClosingWizard): sau khi phân hệ Lớp học tạo hồ sơ học viên /
// ghi danh, tạo khoản học phí (vào lớp giữa khóa: số buổi còn lại × đơn giá), phiếu thu trước / phiếu thu lúc chốt (chờ
// duyệt) và task "Nhắc thu học phí" khi chưa đóng. Xếp lớp khách Chờ xếp lớp: khoản học phí chưa gắn lớp nhận lớp mới.

// closingInner: phần Chốt & Xếp lớp của phân hệ Lớp học (classes.CRMStudents).
type closingInner interface {
	crm.StudentCreator
	crm.ClassStarts
	crm.ClosingChoiceSource
}

// Closing bọc CRMStudents; cmd/menglish: crm.NewService(...).WithStudents(tuitionSvc.Closing(cls.CRMStudents(studentSvc))).
type Closing struct {
	closingInner
	s *Service
}

func (s *Service) Closing(inner closingInner) *Closing { return &Closing{closingInner: inner, s: s} }

var (
	_ crm.StudentCreator      = (*Closing)(nil)
	_ crm.ClassStarts         = (*Closing)(nil)
	_ crm.ClosingChoiceSource = (*Closing)(nil)
)

// closingBase: học phí niêm yết của hợp đồng và số buổi.
type closingBase struct {
	fee      int64
	sessions int
	courseID uuid.UUID
	class    *classInfo
}

func (c *Closing) base(ctx context.Context, q *tuitiondb.Queries, in crm.CloseInput) (closingBase, error) {
	s := c.s
	if in.ClassID != nil {
		cl, err := s.getClass(ctx, q, in.ClassID)
		if err != nil || cl == nil {
			return closingBase{}, err
		}
		held, err := s.heldCounts(ctx, q, []uuid.UUID{cl.ID})
		if err != nil {
			return closingBase{}, err
		}
		j := joinTuition(cl, held[cl.ID])
		if j.Sessions <= 0 {
			return closingBase{}, iam.Invalid("Lớp đã học hết số buổi của khóa, vui lòng chọn lớp khác.")
		}
		return closingBase{fee: j.Fee, sessions: j.Sessions, courseID: val(cl.CourseID), class: cl}, nil
	}
	co, err := q.GetCourse(ctx, *in.CourseID)
	if err != nil {
		return closingBase{}, err
	}
	n := int(co.TotalLessons)
	if n <= 0 {
		n = defaultCourseSessions
	}
	return closingBase{fee: co.TuitionFee, sessions: n, courseID: co.ID}, nil
}

func (c *Closing) feeItems(ctx context.Context, q *tuitiondb.Queries, refs []crm.FeeItemRef) ([]ItemLine, error) {
	ids := make([]uuid.UUID, 0, len(refs))
	for _, r := range refs {
		if r.ID == uuid.Nil || slices.Contains(ids, r.ID) {
			return nil, iam.Invalid("Khoản thu khác phải chọn từ danh mục hàng hóa.")
		}
		ids = append(ids, r.ID)
	}
	out := []ItemLine{}
	if len(ids) == 0 {
		return out, nil
	}
	rows, err := q.ActiveItemsByIDs(ctx, ids)
	if err != nil {
		return nil, err
	}
	if len(rows) != len(ids) {
		return nil, iam.Invalid("Có hàng hóa không tồn tại hoặc đã ngừng bán.")
	}
	by := map[uuid.UUID]tuitiondb.ActiveItemsByIDsRow{}
	for _, r := range rows {
		by[r.ID] = r
	}
	for _, id := range ids {
		r := by[id]
		out = append(out, ItemLine{ID: id, Code: r.Code, Name: r.Name, Unit: r.Unit, Quantity: 1, UnitPrice: r.Price, Amount: r.Price, StockTracked: true})
	}
	return out, nil
}

// bank: tài khoản nhận chuyển khoản ghi trên hợp đồng. Form chốt của v2 chưa chọn tài khoản thì lấy tài khoản mặc định
// của chi nhánh / hệ thống (Laravel bắt chọn).
func (c *Closing) bank(ctx context.Context, q *tuitiondb.Queries, id, branch *uuid.UUID, required bool) (*uuid.UUID, error) {
	accounts, err := q.ActiveBankAccounts(ctx)
	if err != nil {
		return nil, err
	}
	if id != nil {
		for _, a := range accounts {
			if a.ID == *id {
				if a.BranchID != nil && branch != nil && *a.BranchID != *branch {
					return nil, iam.Invalid("Tài khoản thu tiền không dùng cho cơ sở của lớp.")
				}
				return &a.ID, nil
			}
		}
		if required {
			return nil, iam.Invalid("Chuyển khoản cần một tài khoản ngân hàng đang hoạt động.")
		}
		return nil, nil
	}
	if !required {
		return nil, nil
	}
	if b := resolveBank(accounts, nil, branch); b != nil {
		return &b.ID, nil
	}
	return nil, iam.Invalid("Chuyển khoản cần một tài khoản ngân hàng đang hoạt động.")
}

func closingReference(prefix string) string {
	return prefix + "-" + strings.ToUpper(strings.ReplaceAll(uuid.NewString(), "-", ""))[:26]
}

// CreateFromLead: hồ sơ học viên / ghi danh (phân hệ Lớp học) rồi khoản học phí, phiếu thu, task nhắc thu.
func (c *Closing) CreateFromLead(ctx context.Context, tx pgx.Tx, actor *iam.Access, lead crm.LeadSnapshot, in crm.CloseInput) (*crm.ClosedStudent, error) {
	s := c.s
	q := s.q.WithTx(tx)
	method := "cash"
	if in.PaymentMethod != nil {
		method = *in.PaymentMethod
	}
	paid, prepaid := in.PaidAmount, in.PrepaidAmount
	out, err := c.closingInner.CreateFromLead(ctx, tx, actor, lead, in)
	if err != nil {
		return nil, err
	}
	b, err := c.base(ctx, q, in)
	if err != nil {
		return nil, err
	}
	if b.fee <= 0 {
		return nil, iam.Invalid("Lớp / khóa học chưa được cấu hình học phí.")
	}
	branch := out.BranchID
	needsBank := paid > 0 && method == "transfer"
	bankID, err := c.bank(ctx, q, in.BankAccountID, branch, needsBank)
	if err != nil {
		return nil, err
	}
	var discount int64
	var promoID *uuid.UUID
	if in.PromotionID != nil {
		p, err := s.promotion(ctx, q, *in.PromotionID)
		if err != nil {
			return nil, err
		}
		if p != nil {
			promoID = &p.ID
			discount = int64(crm.CalculateDiscount(p.Type, p.Value, p.MaxDiscountAmount, float64(b.fee)))
		}
	}
	items, err := c.feeItems(ctx, q, in.FeeItems)
	if err != nil {
		return nil, err
	}
	others := itemsTotal(items)
	total := max(0, b.fee-discount+others)
	// Khoản thu trước (đã thu ngoài phiếu) cần người có quyền duyệt phiếu thu xác nhận.
	if prepaid > 0 && !actor.Can("tuition.approve") {
		return nil, iam.Invalid("Khoản thu trước phải được quản lý xác nhận.")
	}
	if prepaid > total {
		return nil, iam.Invalid("Khoản thu trước vượt quá giá trị hợp đồng.")
	}
	if paid > total-prepaid {
		return nil, iam.Invalid("Số tiền thu vượt quá số tiền còn phải nộp.")
	}
	paperRange := false
	if paid > 0 && method == "cash" {
		if paperRange, err = s.branchUsesPaperRange(ctx, q, branch); err != nil {
			return nil, err
		}
	}
	proof := optText(in.PaperInvoiceProof)
	paperNo := optText(in.PaperInvoiceNumber)
	if paperRange && proof == nil {
		return nil, iam.Invalid("Thu tiền mặt cần tải ảnh chụp hóa đơn giấy mang đúng số hệ thống cấp, đã ghi đúng nội dung thu.")
	}
	if !paperRange && paid > 0 && method == "cash" && paperNo == nil {
		return nil, iam.Invalid("Thu tiền mặt cần nhập số hóa đơn giấy đã xuất cho khách.")
	}

	var className *string
	var classID *uuid.UUID
	if b.class != nil {
		className, classID = &b.class.Name, &b.class.ID
	}
	memo := transferMemo(out.StudentCode, out.StudentName, className)
	notes := optText(in.BillNotes)
	if notes == nil {
		notes = ptr("Thu qua Closing Wizard (Khách hàng " + lead.Name + ")")
	}
	t, err := q.CreateTuition(ctx, tuitiondb.CreateTuitionParams{
		StudentID: out.StudentID, ClassID: classID, BranchID: branch, BankAccountID: bankID, PromotionID: promoID,
		TotalAmount: b.fee, SessionCount: ptr(int32(max(1, b.sessions))), DiscountAmount: discount, OtherFees: others,
		FeeItems: encodeItems(items), PrepaidAmount: prepaid, FinalAmount: total, DebtAmount: total,
		DueDate: ptr(s.today().AddDate(0, 0, 7)), Status: "unpaid", Notes: notes, TransferMemo: ptr(memo), CreatedBy: &actor.UserID,
	})
	if err != nil {
		return nil, err
	}
	if err := audit.Record(ctx, tx, audit.Entry{
		ActorID: &actor.UserID, Module: audit.ModuleTuition, Event: audit.EventCreated, Action: "tuition.create",
		SubjectType: "student_tuition", SubjectID: t.ID.String(), SubjectLabel: out.StudentName + " (" + out.StudentCode + ")", BranchID: branch,
		Description: "Tạo khoản học phí " + money(total) + " khi chốt khách " + lead.Name + ".",
		Properties:  map[string]any{"final_amount": total, "discount_amount": discount, "other_fees": others, "prepaid_amount": prepaid, "lead_id": lead.ID},
	}); err != nil {
		return nil, err
	}
	receipts := 0
	newReceipt := func(p tuitiondb.CreateReceiptParams) error {
		code, err := s.newReceiptCode(ctx, q)
		if err != nil {
			return err
		}
		p.Code, p.TuitionID, p.StudentID, p.PaymentMethod = code, &t.ID, &out.StudentID, method
		p.PaymentDate, p.Status, p.CreatorID = ptr(s.today()), StatusPending, &actor.UserID
		if p.CollectedItems == nil {
			p.CollectedItems = []byte("[]")
		}
		r, err := q.CreateReceipt(ctx, p)
		if err != nil {
			return err
		}
		receipts++
		if err := s.recordReceipt(ctx, tx, actor, r, out.StudentName, "tuition.receipt.create", "Lập phiếu thu khi chốt khách", branch); err != nil {
			return err
		}
		return s.notifyReceiptPending(ctx, tx, r, out.StudentName)
	}
	if prepaid > 0 {
		if err := newReceipt(tuitiondb.CreateReceiptParams{
			Source: "closing_prepaid", Amount: prepaid, TuitionAmount: prepaid, TransactionCode: ptr(closingReference("CWD")),
			Notes: ptr("Khoản đặt cọc/thu trước từ Closing Wizard, chờ Kế toán/Admin đối soát."),
		}); err != nil {
			return nil, err
		}
	}
	if paid > 0 {
		var issued *string
		if paperRange && branch != nil {
			n, err := s.consumePaperNumber(ctx, q, *branch, in.ExpectedPaperInvoiceNumber)
			if err != nil {
				return nil, err
			}
			issued = &n
		}
		p := tuitiondb.CreateReceiptParams{
			Source: "closing", InvoiceNumber: issued, Amount: paid, TuitionAmount: min(paid, max(0, b.fee-discount)),
			CollectedItems: encodeItems(items), TransactionCode: ptr(closingReference("CW")),
			Notes: ptr("Khoản thu từ Closing Wizard, chờ Kế toán/Admin đối soát. ND: " + memo),
		}
		if method == "cash" {
			p.PaperInvoiceNumber = paperNo
			if issued != nil {
				p.PaperInvoiceNumber, p.ProofUrl = issued, proof
			}
		}
		if err := newReceipt(p); err != nil {
			return nil, err
		}
	}
	tail := "Khoản thu đang chờ Kế toán/Admin duyệt."
	if receipts == 0 {
		tail = "Đã tạo task nhắc thu học phí cho người phụ trách."
	}
	if !in.FeePaidAtClosing && s.tasks != nil {
		assignee := actor.UserID
		if lead.AssignedTo != nil {
			assignee = *lead.AssignedTo
		}
		if _, err := s.tasks.CreateFeeReminder(ctx, tx, tasks.FeeReminder{
			Title: fmt.Sprintf("Nhắc thu học phí: %s (%s)", out.StudentName, out.StudentCode),
			Description: fmt.Sprintf("Học viên %s (%s) đã chốt từ Lead %s nhưng chưa đóng học phí đăng ký. Số tiền cần thu: %s. Hồ sơ Lead: /crm/customers/%s · Phiếu học phí: /tuition/bill/%s",
				out.StudentName, out.StudentCode, lead.Code, money(total), lead.ID, t.ID),
			CreatorID: actor.UserID, AssigneeID: assignee, BranchID: branch, StudentID: &out.StudentID,
			DueDate: s.today().AddDate(0, 0, 3),
		}); err != nil {
			return nil, err
		}
	}
	out.Message = strings.TrimSpace(strings.Replace(out.Message, classes.MsgTuitionPending, tail, 1))
	out.ContractTotal = total
	out.BillURL = "/tuition/bill/" + t.ID.String()
	s.log.Info("tuition created at closing", "tuition_id", t.ID, "student_id", out.StudentID, "receipts", receipts, "by", actor.UserID)
	return out, nil
}

// EnrollWaiting: xếp lớp (phân hệ Lớp học) rồi gắn lớp cho khoản học phí chưa có lớp.
func (c *Closing) EnrollWaiting(ctx context.Context, tx pgx.Tx, actor *iam.Access, lead crm.LeadSnapshot, studentID, classID uuid.UUID) (*crm.Enrollment, error) {
	e, err := c.closingInner.EnrollWaiting(ctx, tx, actor, lead, studentID, classID)
	if err != nil {
		return nil, err
	}
	if err := c.s.AttachWaitingClass(ctx, tx, studentID, classID); err != nil {
		return nil, err
	}
	return e, nil
}

var _ crm.ClosingCanceller = (*Closing)(nil)

// CancelClosing: hủy chốt khách Chờ xếp lớp (CrmController::cancelClosing). Phân hệ Lớp học chuyển học viên Thôi học;
// khoản học phí: phiếu nháp / chờ duyệt bị hủy, học phí = phần đã thu (bỏ phần còn nợ), đã thu > 0 thì lập hồ sơ hoàn
// phí chờ duyệt.
func (c *Closing) CancelClosing(ctx context.Context, tx pgx.Tx, actor *iam.Access, studentID uuid.UUID, reason string) (int64, error) {
	if inner, ok := c.closingInner.(crm.ClosingCanceller); ok {
		if _, err := inner.CancelClosing(ctx, tx, actor, studentID, reason); err != nil {
			return 0, err
		}
	}
	s := c.s
	q := s.q.WithTx(tx)
	lt, err := q.LatestStudentTuition(ctx, studentID)
	if isNoRows(err) {
		return 0, nil
	}
	if err != nil {
		return 0, err
	}
	if _, err := q.CancelOpenTuitionReceipts(ctx, &lt.ID); err != nil {
		return 0, err
	}
	t, err := s.recalculateDebt(ctx, q, lt.ID)
	if err != nil {
		return 0, err
	}
	note := strings.TrimSpace(textOf(t.Notes) + "\nHuỷ chốt " + s.today().Format("02/01/2006") + ": học phí gốc " + money(t.FinalAmount) + ", bỏ phần còn nợ.")
	if err := q.SetTuitionFinalAndNotes(ctx, tuitiondb.SetTuitionFinalAndNotesParams{ID: t.ID, FinalAmount: t.PaidAmount, Notes: &note}); err != nil {
		return 0, err
	}
	if t, err = s.recalculateDebt(ctx, q, t.ID); err != nil {
		return 0, err
	}
	if t.PaidAmount <= 0 {
		return 0, nil
	}
	rr, err := q.CreateRefundRequest(ctx, tuitiondb.CreateRefundRequestParams{
		StudentID: studentID, TuitionID: &t.ID, Type: "refund", TotalPaid: t.PaidAmount, RefundAmount: t.PaidAmount,
		Reason: limitText("Huỷ chốt trước khi xếp lớp: "+reason, 1990), NoTransferReason: ptr("Khách huỷ đăng ký trước khi có lớp."),
		RequesterID: &actor.UserID,
	})
	if err != nil {
		return 0, err
	}
	if err := audit.Record(ctx, tx, audit.Entry{
		ActorID: &actor.UserID, Module: audit.ModuleTuition, Event: audit.EventCreated, Action: "tuition.refund_request.create",
		SubjectType: "tuition_refund_request", SubjectID: rr.ID.String(), BranchID: t.BranchID,
		Description: "Lập hồ sơ hoàn " + money(rr.RefundAmount) + " khi huỷ chốt khách.",
		Properties:  map[string]any{"type": rr.Type, "refund_amount": rr.RefundAmount, "student_id": studentID},
	}); err != nil {
		return 0, err
	}
	s.log.Info("refund request created at closing cancel", "refund_request_id", rr.ID, "student_id", studentID, "by", actor.UserID)
	return rr.RefundAmount, nil
}

// AttachWaitingClass:khoản học phí chưa gắn lớp của học viên nhận lớp vừa xếp (classes.WaitingTuition — xếp lớp
// khách Chờ xếp lớp từ màn Học viên).
func (s *Service) AttachWaitingClass(ctx context.Context, tx pgx.Tx, studentID, classID uuid.UUID) error {
	return s.q.WithTx(tx).SetWaitingTuitionClass(ctx, tuitiondb.SetWaitingTuitionClassParams{ClassID: &classID, StudentID: studentID})
}
