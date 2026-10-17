package tuition

import (
	"bytes"
	"context"
	"encoding/csv"
	"strings"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/tuition/tuitiondb"
)

// Màn lập phiếu thu (receiptForm), duyệt phiếu thu (approveReceipt) và lịch sử thu (history / exportHistory).

// Bank: tài khoản nhận tiền dùng để tạo VietQR.
type Bank struct {
	ID            uuid.UUID `json:"id"`
	BankCode      string    `json:"bank_code"`
	BankName      string    `json:"bank_name"`
	AccountNumber string    `json:"account_number"`
	AccountHolder string    `json:"account_holder"`
	Scope         string    `json:"scope"`
}

// resolveBank: tài khoản của hợp đồng → tài khoản chi nhánh (mặc định VietQR trước) → tài khoản mặc định hệ thống.
func resolveBank(accounts []tuitiondb.ActiveBankAccountsRow, own, branch *uuid.UUID) *Bank {
	conv := func(a tuitiondb.ActiveBankAccountsRow, scope string) *Bank {
		return &Bank{ID: a.ID, BankCode: a.BankCode, BankName: a.BankName, AccountNumber: a.AccountNumber, AccountHolder: a.AccountHolder, Scope: scope}
	}
	if own != nil {
		for _, a := range accounts {
			if a.ID == *own {
				return conv(a, "Tài khoản gắn với hợp đồng")
			}
		}
	}
	if branch != nil {
		for _, a := range accounts {
			if a.BranchID != nil && *a.BranchID == *branch {
				return conv(a, "Tài khoản chi nhánh "+textOf(a.BranchName))
			}
		}
	}
	if len(accounts) > 0 {
		a := accounts[0]
		if a.BranchID != nil {
			return conv(a, "Tài khoản chi nhánh "+textOf(a.BranchName))
		}
		return conv(a, "Tài khoản mặc định hệ thống")
	}
	return nil
}

// FormTuition: khoản học phí có thể thu tiếp.
type FormTuition struct {
	ID               uuid.UUID  `json:"id"`
	StudentID        uuid.UUID  `json:"student_id"`
	BranchID         *uuid.UUID `json:"branch_id"`
	FeeLabel         string     `json:"fee_label"`
	ClassName        *string    `json:"class_name"`
	CourseID         *uuid.UUID `json:"course_id"`
	TotalAmount      int64      `json:"total_amount"`
	DiscountAmount   int64      `json:"discount_amount"`
	OtherFees        int64      `json:"other_fees"`
	PaidAmount       int64      `json:"paid_amount"`
	DebtAmount       int64      `json:"debt_amount"`
	FinalAmount      int64      `json:"final_amount"`
	DueDate          *string    `json:"due_date"`
	FeeItems         []ItemLine `json:"fee_items"`
	ClassChanged     bool       `json:"class_changed"`
	CurrentClassName *string    `json:"current_class_name"`
	TransferMemo     string     `json:"transfer_memo"`
	Bank             *Bank      `json:"bank"`
	Status           string     `json:"status"`
	StatusLabel      string     `json:"status_label"`
	Quote            Quote      `json:"quote"`
}

type FormStudent struct {
	ID           uuid.UUID  `json:"id"`
	BranchID     *uuid.UUID `json:"branch_id"`
	Name         string     `json:"name"`
	Code         string     `json:"code"`
	Phone        string     `json:"phone"`
	ParentName   string     `json:"parent_name"`
	ParentPhone  string     `json:"parent_phone"`
	ClassName    string     `json:"class_name"`
	BranchName   string     `json:"branch_name"`
	Status       string     `json:"status"`
	IsDeferred   bool       `json:"is_deferred"`
	TransferMemo string     `json:"transfer_memo"`
	CourseID     *uuid.UUID `json:"course_id"`
	Quote        Quote      `json:"quote"`
}

type FormPromotion struct {
	ID                uuid.UUID  `json:"id"`
	Code              string     `json:"code"`
	Name              string     `json:"name"`
	Type              string     `json:"type"`
	Value             float64    `json:"value"`
	MaxDiscountAmount *int64     `json:"max_discount_amount"`
	BranchID          *uuid.UUID `json:"branch_id"`
	CourseID          *uuid.UUID `json:"course_id"`
	IsDefault         bool       `json:"is_default"`
}

type FormItem struct {
	ID    uuid.UUID `json:"id"`
	Code  string    `json:"code"`
	Name  string    `json:"name"`
	Unit  string    `json:"unit"`
	Price int64     `json:"price"`
	Stock int       `json:"stock"`
}

type Rejection struct {
	ID              uuid.UUID `json:"id"`
	Code            string    `json:"code"`
	RejectionReason *string   `json:"rejection_reason"`
	CanEdit         bool      `json:"can_edit"`
}

// ReceiptForm: dữ liệu form lập / sửa phiếu cho một học viên (và khoản học phí đang chọn).
type ReceiptForm struct {
	Student          *FormStudent    `json:"student"`
	Tuitions         []FormTuition   `json:"tuitions"`
	SelectedTuition  *uuid.UUID      `json:"selected_tuition_id"`
	Promotions       []FormPromotion `json:"promotions"`
	Items            []FormItem      `json:"items"`
	PaperInvoiceNext *string         `json:"paper_invoice_next"`
	DefaultBank      *Bank           `json:"default_bank"`
	Editing          *ReceiptDetail  `json:"editing"`
	RecentRejection  *Rejection      `json:"recent_rejection"`
	CanRequestCancel bool            `json:"can_request_cancel"`
	Methods          []Option        `json:"methods"`
}

// StudentOption: học viên cho ô chọn ở form lập phiếu.
type StudentOption struct {
	ID         uuid.UUID `json:"id"`
	Code       string    `json:"code"`
	Name       string    `json:"name"`
	Phone      string    `json:"phone"`
	ClassName  *string   `json:"class_name"`
	BranchName *string   `json:"branch_name"`
}

// SearchStudents: học viên chưa thôi học trong phạm vi (tìm theo tên / SĐT / mã).
func (s *Service) SearchStudents(ctx context.Context, actor *iam.Access, q string) ([]StudentOption, error) {
	rows, err := s.q.SearchStudents(ctx, tuitiondb.SearchStudentsParams{BranchIds: branchLimit(actor), Search: likePattern(q)})
	if err != nil {
		return nil, err
	}
	out := make([]StudentOption, 0, len(rows))
	for _, r := range rows {
		out = append(out, StudentOption{r.ID, r.Code, r.FullName, r.Phone, r.ClassName, r.BranchName})
	}
	return out, nil
}

// ReceiptForm: form lập phiếu (studentID / tuitionID) hoặc sửa phiếu (receiptID).
func (s *Service) ReceiptForm(ctx context.Context, actor *iam.Access, studentID, tuitionID, receiptID *uuid.UUID) (*ReceiptForm, error) {
	out := &ReceiptForm{Tuitions: []FormTuition{}, Promotions: []FormPromotion{}, Items: []FormItem{},
		CanRequestCancel: actor.Can("invoice.request_cancel"),
		Methods:          []Option{{"transfer", "Chuyển khoản / VietQR"}, {"cash", "Tiền mặt"}}}
	var keepPromotion *uuid.UUID
	if receiptID != nil {
		info, err := s.loadReceiptScoped(ctx, actor, *receiptID)
		if err != nil {
			return nil, err
		}
		if !(info.CreatorID != nil && *info.CreatorID == actor.UserID) && !actor.SuperAdmin {
			return nil, ErrNotCreator
		}
		if !editable(info.Status) {
			return nil, iam.Invalid("Chỉ sửa được phiếu ở trạng thái Bản nháp hoặc Bị từ chối.")
		}
		d := s.receiptDetail(ctx, info, actor, s.depositCutoff(ctx))
		out.Editing = &d
		studentID, tuitionID, keepPromotion = info.OwnerStudentID, info.TuitionID, info.PromotionID
		if info.Status == StatusRejected {
			out.RecentRejection = &Rejection{ID: info.ID, Code: info.Code, RejectionReason: info.RejectionReason}
		}
	}
	if tuitionID != nil {
		t, err := s.q.GetTuition(ctx, *tuitionID)
		if isNoRows(err) {
			return nil, ErrTuitionNotFound
		}
		if err != nil {
			return nil, err
		}
		studentID = &t.StudentID
	}
	accounts, err := s.q.ActiveBankAccounts(ctx)
	if err != nil {
		return nil, err
	}
	out.DefaultBank = resolveBank(accounts, nil, nil)
	if err := s.formPromotions(ctx, out, keepPromotion); err != nil {
		return nil, err
	}
	if studentID == nil {
		return out, s.formItems(ctx, out, nil)
	}
	st, err := s.q.GetStudent(ctx, *studentID)
	if isNoRows(err) {
		return nil, ErrStudentNotFound
	}
	if err != nil {
		return nil, err
	}
	tuitions, err := s.q.StudentTuitions(ctx, st.ID)
	if err != nil {
		return nil, err
	}
	branch := nonZero(st.ScopeBranchID)
	if !covers(actor, branch) {
		ok := false
		for _, t := range tuitions {
			if t.BranchID != nil && covers(actor, t.BranchID) {
				ok = true
			}
		}
		if !ok {
			return nil, ErrOutOfScope
		}
	}
	sb := subject{student: st, branch: branch}
	class := sb.studentClass()
	var open *tuitiondb.FinanceTuition
	if o, err := s.q.OpenTuitionFor(ctx, st.ID); err == nil {
		open = &o
	} else if !isNoRows(err) {
		return nil, err
	}
	qs, err := s.quote(ctx, s.q, st.ID, class, open)
	if err != nil {
		return nil, err
	}
	memo := transferMemo(st.Code, st.FullName, st.ClassName)
	out.Student = &FormStudent{ID: st.ID, BranchID: branch, Name: st.FullName, Code: st.Code, Phone: st.Phone,
		ParentName: textOf(firstText(st.ParentName, &st.FullName)), ParentPhone: textOf(firstText(st.ParentPhone, &st.Phone)),
		ClassName: textOf(firstText(st.ClassName, ptr("Chưa xếp lớp"))), BranchName: textOf(firstText(st.BranchName, ptr("Trụ sở chính"))),
		Status: st.Status, IsDeferred: st.Status == "deferred", TransferMemo: memo, CourseID: st.ClassCourseID, Quote: qs}
	for _, t := range tuitions {
		if t.Status == "paid" && (tuitionID == nil || *tuitionID != t.ID) {
			continue
		}
		ft := t
		tb := t.BranchID
		if tb == nil {
			tb = branch
		}
		var o *tuitiondb.FinanceTuition
		if t.DebtAmount > 0 {
			o = tuitionOf(ft)
		} else {
			o = open
		}
		tq, err := s.quote(ctx, s.q, st.ID, class, o)
		if err != nil {
			return nil, err
		}
		course := st.ClassCourseID
		if c, err := s.getClass(ctx, s.q, t.ClassID); err != nil {
			return nil, err
		} else if c != nil && c.CourseID != nil {
			course = c.CourseID
		}
		tm := textOf(t.TransferMemo)
		if tm == "" {
			tm = memo
		}
		out.Tuitions = append(out.Tuitions, FormTuition{
			ID: t.ID, StudentID: t.StudentID, BranchID: tb, FeeLabel: feeLabel(t.CourseName, t.ClassName, int32(len(decodeItems(t.FeeItems)))),
			ClassName: firstText(t.ClassName, ptr("Chưa xếp lớp")), CourseID: course, TotalAmount: t.TotalAmount,
			DiscountAmount: t.DiscountAmount, OtherFees: t.OtherFees, PaidAmount: t.PaidAmount, DebtAmount: t.DebtAmount,
			FinalAmount: t.FinalAmount, DueDate: fmtDate(t.DueDate), FeeItems: decodeItems(t.FeeItems),
			ClassChanged:     t.ClassID != nil && st.CurrentClassID != nil && *t.ClassID != *st.CurrentClassID,
			CurrentClassName: st.ClassName, TransferMemo: tm, Bank: resolveBank(accounts, t.BankAccountID, tb),
			Status: t.Status, StatusLabel: tuitionStatusLabel(t.Status), Quote: tq,
		})
	}
	switch {
	case tuitionID != nil:
		out.SelectedTuition = tuitionID
	case len(out.Tuitions) > 0:
		out.SelectedTuition = &out.Tuitions[len(out.Tuitions)-1].ID
	}
	if out.DefaultBank = resolveBank(accounts, nil, branch); out.SelectedTuition != nil {
		for _, t := range out.Tuitions {
			if t.ID == *out.SelectedTuition && t.Bank != nil {
				out.DefaultBank = t.Bank
			}
		}
	}
	if receiptID == nil && out.SelectedTuition != nil {
		if r, err := s.q.LatestRejection(ctx, out.SelectedTuition); err == nil {
			out.RecentRejection = &Rejection{ID: r.ID, Code: r.Code, RejectionReason: r.RejectionReason,
				CanEdit: editable(r.Status) && ((r.CreatorID != nil && *r.CreatorID == actor.UserID) || actor.SuperAdmin)}
		} else if !isNoRows(err) {
			return nil, err
		}
	}
	if out.PaperInvoiceNext, err = s.peekPaperNumber(ctx, s.q, branch); err != nil {
		return nil, err
	}
	return out, s.formItems(ctx, out, branch)
}

func tuitionOf(t tuitiondb.StudentTuitionsRow) *tuitiondb.FinanceTuition {
	return &tuitiondb.FinanceTuition{ID: t.ID, StudentID: t.StudentID, ClassID: t.ClassID, BranchID: t.BranchID,
		BankAccountID: t.BankAccountID, PromotionID: t.PromotionID, TotalAmount: t.TotalAmount, SessionCount: t.SessionCount,
		DiscountAmount: t.DiscountAmount, OtherFees: t.OtherFees, FeeItems: t.FeeItems, PrepaidAmount: t.PrepaidAmount,
		FinalAmount: t.FinalAmount, PaidAmount: t.PaidAmount, DebtAmount: t.DebtAmount, DueDate: t.DueDate, Status: t.Status}
}

func (s *Service) formPromotions(ctx context.Context, out *ReceiptForm, keep *uuid.UUID) error {
	ps, err := s.q.AvailablePromotions(ctx, keep)
	if err != nil {
		return err
	}
	for _, p := range ps {
		out.Promotions = append(out.Promotions, FormPromotion{ID: p.ID, Code: p.Code, Name: p.Name, Type: p.Type, Value: p.Value,
			MaxDiscountAmount: p.MaxDiscountAmount, BranchID: p.BranchID, CourseID: p.CourseID, IsDefault: p.IsDefault})
	}
	return nil
}

func (s *Service) formItems(ctx context.Context, out *ReceiptForm, branch *uuid.UUID) error {
	items, err := s.q.ActiveItems(ctx)
	if err != nil {
		return err
	}
	stock := map[uuid.UUID]int{}
	if branch != nil {
		rows, err := s.q.BranchStock(ctx, *branch)
		if err != nil {
			return err
		}
		for _, r := range rows {
			stock[r.ItemID] = int(r.Quantity)
		}
	}
	for _, i := range items {
		out.Items = append(out.Items, FormItem{ID: i.ID, Code: i.Code, Name: i.Name, Unit: i.Unit, Price: i.Price, Stock: stock[i.ID]})
	}
	return nil
}

// ───────────── Chi tiết phiếu ─────────────

// SessionBreakdown: bảng kê phiếu thu theo buổi (phiếu cũ theo số tiền → nil).
type SessionBreakdown struct {
	SessionCount int     `json:"session_count"`
	UnitPrice    int64   `json:"unit_price"`
	SessionValue int64   `json:"session_value"`
	FeeDue       int64   `json:"fee_due"`
	MaterialFee  int64   `json:"material_fee"`
	ExamFee      int64   `json:"exam_fee"`
	OtherFee     int64   `json:"other_fee"`
	OtherReason  *string `json:"other_fee_reason"`
	Subtotal     int64   `json:"subtotal"`
	Discount     int64   `json:"discount"`
	TotalDue     int64   `json:"total_due"`
	Extra        int64   `json:"extra"`
	Amount       int64   `json:"amount"`
}

func sessionBreakdown(r tuitiondb.FinanceReceipt) *SessionBreakdown {
	if r.SessionCount == nil {
		return nil
	}
	items := itemsTotal(decodeItems(r.CollectedItems))
	extra := max(0, r.SurchargeAmount-items-r.ExamFee-r.OtherFee)
	feeDue := max(0, r.MaterialFee-items)
	value := r.Amount - r.SurchargeAmount + r.DiscountAmount - feeDue
	subtotal := value + r.MaterialFee + r.ExamFee + r.OtherFee
	return &SessionBreakdown{SessionCount: int(*r.SessionCount), UnitPrice: val(r.SessionUnitPrice), SessionValue: value,
		FeeDue: feeDue, MaterialFee: r.MaterialFee, ExamFee: r.ExamFee, OtherFee: r.OtherFee, OtherReason: r.OtherFeeReason,
		Subtotal: subtotal, Discount: r.DiscountAmount, TotalDue: subtotal - r.DiscountAmount, Extra: extra, Amount: r.Amount}
}

type Reconciliation struct {
	Tone   string `json:"tone"`
	Label  string `json:"label"`
	Detail string `json:"detail"`
}

type SepayWarning struct {
	SepayID     string  `json:"sepay_id"`
	Amount      int64   `json:"amount"`
	Date        *string `json:"date"`
	ReceiptCode *string `json:"receipt_number"`
}

// ReceiptDetail: chi tiết phiếu ở màn duyệt / form sửa.
type ReceiptDetail struct {
	ID                 uuid.UUID         `json:"id"`
	Code               string            `json:"code"`
	Source             string            `json:"source"`
	Status             string            `json:"status"`
	StatusLabel        string            `json:"status_label"`
	StatusColor        string            `json:"status_color"`
	InvoiceNumber      *string           `json:"invoice_number"`
	IssuedPaperInvoice *string           `json:"issued_paper_invoice"`
	CreatedAt          string            `json:"created_at"`
	CreatorName        *string           `json:"creator_name"`
	ApproverName       *string           `json:"approver_name"`
	BranchName         string            `json:"branch_name"`
	StudentID          *uuid.UUID        `json:"student_id"`
	StudentName        *string           `json:"student_name"`
	StudentCode        *string           `json:"student_code"`
	ClassName          string            `json:"class_name"`
	PayerName          *string           `json:"payer_name"`
	PayerPhone         *string           `json:"payer_phone"`
	TuitionID          *uuid.UUID        `json:"student_tuition_id"`
	TuitionDueDate     *string           `json:"tuition_due_date"`
	FeeLabel           *string           `json:"fee_label"`
	PaymentMethod      string            `json:"payment_method"`
	MethodLabel        string            `json:"method_label"`
	TransactionCode    *string           `json:"transaction_code"`
	PaperInvoiceNumber *string           `json:"paper_invoice_number"`
	Amount             int64             `json:"amount"`
	TuitionPortion     int64             `json:"tuition_portion"`
	DiscountAmount     int64             `json:"discount_amount"`
	PromotionID        *uuid.UUID        `json:"promotion_id"`
	PromotionName      *string           `json:"promotion_name"`
	ContractPromotion  *string           `json:"contract_promotion_name"`
	ContractPromoNote  *string           `json:"contract_promotion_reason"`
	DiscountReason     *string           `json:"discount_reason"`
	SurchargeAmount    int64             `json:"surcharge_amount"`
	SurchargeReason    *string           `json:"surcharge_reason"`
	SurchargeItems     []ItemLine        `json:"surcharge_items"`
	Session            *SessionBreakdown `json:"session"`
	IsVatInvoice       bool              `json:"is_vat_invoice"`
	Notes              *string           `json:"notes"`
	RejectionReason    *string           `json:"rejection_reason"`
	ProofURL           *string           `json:"proof_url"`
	Reference          string            `json:"reference"`
	PaymentDate        *string           `json:"payment_date"`
	DepositState       *string           `json:"deposit_state"`
	DepositedAt        *string           `json:"deposited_at"`
	DepositedByName    *string           `json:"deposited_by_name"`
	CanConfirmDeposit  bool              `json:"can_confirm_deposit"`
	CanResubmit        bool              `json:"can_resubmit"`
	Beneficiary        *string           `json:"beneficiary"`
	StockOut           []StockLine       `json:"stock_out"`
	Reconciliation     *Reconciliation   `json:"reconciliation"`
	SepayWarnings      []SepayWarning    `json:"sepay_warnings"`
}

func (s *Service) receiptDetail(ctx context.Context, info tuitiondb.GetReceiptRow, actor *iam.Access, cutoff int) ReceiptDetail {
	r := receiptOf(info)
	d := ReceiptDetail{
		ID: r.ID, Code: r.Code, Source: r.Source, Status: r.Status, StatusLabel: receiptStatusLabel(r.Status),
		StatusColor: receiptStatusColor(r.Status), InvoiceNumber: r.InvoiceNumber,
		CreatedAt: r.CreatedAt.In(vnZone).Format("15:04 - 02/01/2006"), CreatorName: info.CreatorName, ApproverName: info.ApproverName,
		BranchName: textOf(firstText(info.BranchName, ptr("Chưa gán chi nhánh"))), StudentID: info.OwnerStudentID,
		StudentName: info.StudentName, StudentCode: info.StudentCode,
		ClassName:  textOf(firstText(info.TuitionClassName, info.CurrentClassName, ptr("Chưa gắn lớp"))),
		PayerName:  firstText(r.PayerName, info.StudentParentName, info.StudentName),
		PayerPhone: firstText(r.PayerPhone, info.StudentParentPhone, info.StudentPhone),
		TuitionID:  r.TuitionID, TuitionDueDate: fmtDate(info.TuitionDueDate), PaymentMethod: r.PaymentMethod,
		MethodLabel: methodLabel(r.PaymentMethod), TransactionCode: r.TransactionCode, PaperInvoiceNumber: r.PaperInvoiceNumber,
		Amount: r.Amount, TuitionPortion: r.Amount - r.SurchargeAmount, DiscountAmount: r.DiscountAmount, PromotionID: r.PromotionID,
		PromotionName: info.PromotionName, ContractPromotion: info.ContractPromotionName, ContractPromoNote: info.ContractPromotionReason,
		DiscountReason: r.DiscountReason, SurchargeAmount: r.SurchargeAmount, SurchargeReason: r.SurchargeReason,
		SurchargeItems: surchargeLinesFull(decodeItems(r.CollectedItems)), Session: sessionBreakdown(r),
		IsVatInvoice: r.IsVatInvoice, Notes: r.Notes, RejectionReason: r.RejectionReason, ProofURL: r.ProofUrl,
		PaymentDate: fmtDate(r.PaymentDate), DepositState: depositState(r, cutoff, s.now()),
		DepositedAt: fmtDateTime(r.DepositedAt, "15:04 02/01/2006"), DepositedByName: info.DepositorName,
		StockOut: []StockLine{}, SepayWarnings: []SepayWarning{},
	}
	if hasIssuedPaper(r) {
		d.IssuedPaperInvoice = r.InvoiceNumber
	}
	if r.TuitionID != nil {
		d.FeeLabel = ptr(feeLabel(info.CourseName, info.TuitionClassName, info.TuitionFeeItemCount))
	}
	d.Reference = textOf(firstText(r.TransactionCode, r.PaperInvoiceNumber, ptr("—")))
	d.CanConfirmDeposit = d.DepositState != nil && *d.DepositState == "pending" && actor.Can("tuition.confirm_deposit")
	d.CanResubmit = editable(r.Status) && ((r.CreatorID != nil && *r.CreatorID == actor.UserID) || actor.SuperAdmin)
	return d
}

// surchargeLinesFull: hàng hóa ở phần phụ thu (đủ thông tin cho form sửa).
func surchargeLinesFull(items []ItemLine) []ItemLine {
	out := []ItemLine{}
	for _, l := range items {
		if l.Source == "surcharge" {
			out = append(out, l)
		}
	}
	return out
}

// enrichDetail: phần chỉ màn duyệt cần (đối soát SePay, cảnh báo trùng, xuất kho, tài khoản nhận).
func (s *Service) enrichDetail(ctx context.Context, d *ReceiptDetail, info tuitiondb.GetReceiptRow) error {
	r := receiptOf(info)
	d.Reconciliation = &Reconciliation{Tone: "neutral", Label: "Tiền mặt — đối chiếu quỹ",
		Detail: "Đối chiếu số tiền với quỹ tiền mặt / biên lai giấy trước khi duyệt."}
	if isTransfer(r.PaymentMethod) {
		ref := normalizeReference(r.TransactionCode)
		var tx *tuitiondb.StatementForReferenceRow
		if ref != "" {
			x, err := s.q.StatementForReference(ctx, ref)
			if err == nil {
				tx = &x
			} else if !isNoRows(err) {
				return err
			}
		}
		switch {
		case tx == nil:
			d.Reconciliation = &Reconciliation{"warning", "Cần đối chiếu thủ công",
				"Chưa tìm thấy giao dịch khớp mã tham chiếu trong sao kê SePay. Đối chiếu minh chứng với sao kê ngân hàng trước khi duyệt."}
		case abs(tx.TransferAmount-r.Amount) < 1:
			d.Reconciliation = &Reconciliation{"success", "Khớp số tiền & mã giao dịch",
				"Sao kê SePay ghi nhận " + money(tx.TransferAmount) + " ngày " + textOf(firstText(fmtDateTime(tx.TransactionDate, "02/01/2006 15:04"), ptr("—"))) +
					" vào TK " + textOf(firstText(tx.AccountNumber, ptr("—"))) + "."}
		default:
			d.Reconciliation = &Reconciliation{"error", "Lệch số tiền",
				"Sao kê SePay ghi nhận " + money(tx.TransferAmount) + ", phiếu ghi " + money(r.Amount) + "."}
		}
	}
	if r.Status == StatusPending && isTransfer(r.PaymentMethod) {
		if ref := normalizeReference(r.TransactionCode); ref != "" {
			x, err := s.q.AppliedBankTransaction(ctx, tuitiondb.AppliedBankTransactionParams{Reference: ref, IgnoreID: &r.ID})
			if err == nil {
				d.SepayWarnings = append(d.SepayWarnings, SepayWarning{x.ProviderTxnID, x.TransferAmount, fmtDateTime(x.TransactionDate, "02/01/2006 15:04"), x.ReceiptCode})
			} else if !isNoRows(err) {
				return err
			}
		}
		if len(d.SepayWarnings) == 0 {
			sim, err := s.similarTransactions(ctx, s.q, r, info.OwnerStudentID)
			if err != nil {
				return err
			}
			for _, x := range sim {
				d.SepayWarnings = append(d.SepayWarnings, SepayWarning{x.ProviderTxnID, x.TransferAmount, fmtDateTime(x.TransactionDate, "02/01/2006 15:04"), x.ReceiptCode})
			}
		}
	}
	if r.Status == StatusPending {
		lines, err := s.previewStock(ctx, r, nonZero(info.ScopeBranchID))
		if err != nil {
			return err
		}
		d.StockOut = lines
	}
	if r.TuitionID != nil {
		accounts, err := s.q.ActiveBankAccounts(ctx)
		if err != nil {
			return err
		}
		branch := info.TuitionBranchID
		if branch == nil {
			branch = nonZero(info.ScopeBranchID)
		}
		if b := resolveBank(accounts, info.TuitionBankAccountID, branch); b != nil {
			d.Beneficiary = ptr(b.AccountNumber + " (" + b.BankName + ")")
		}
	}
	return nil
}

// ReceiptRow: một dòng ở danh sách duyệt / lịch sử thu.
type ReceiptRow struct {
	ID                 uuid.UUID  `json:"id"`
	Code               string     `json:"code"`
	InvoiceNumber      *string    `json:"invoice_number"`
	HasProof           bool       `json:"has_proof"`
	PaperInvoiceNumber *string    `json:"paper_invoice_number"`
	StudentID          *uuid.UUID `json:"student_id"`
	StudentName        string     `json:"student_name"`
	StudentCode        *string    `json:"student_code"`
	ClassName          string     `json:"class_name"`
	BranchName         string     `json:"branch_name"`
	FeeLabel           *string    `json:"fee_label"`
	PaymentMethod      string     `json:"payment_method"`
	MethodLabel        string     `json:"method_label"`
	TransactionCode    *string    `json:"transaction_code"`
	Amount             int64      `json:"amount"`
	SurchargeAmount    int64      `json:"surcharge_amount"`
	HasSurcharge       bool       `json:"has_surcharge"`
	CreatorName        *string    `json:"creator_name"`
	ApproverName       *string    `json:"approver_name"`
	CreatedAt          string     `json:"created_at"`
	PaymentDate        *string    `json:"payment_date"`
	Status             string     `json:"status"`
	StatusLabel        string     `json:"status_label"`
	StatusColor        string     `json:"status_color"`
	DepositState       *string    `json:"deposit_state"`
	DepositedAt        *string    `json:"deposited_at"`
	DepositedByName    *string    `json:"deposited_by_name"`
	CanConfirmDeposit  bool       `json:"can_confirm_deposit"`
}

func (s *Service) receiptRow(r tuitiondb.ListReceiptsRow, actor *iam.Access, cutoff int) ReceiptRow {
	fr := tuitiondb.FinanceReceipt{PaymentMethod: r.PaymentMethod, Status: r.Status, Amount: r.Amount, PaymentDate: r.PaymentDate, DepositedAt: r.DepositedAt}
	row := ReceiptRow{ID: r.ID, Code: r.Code, InvoiceNumber: r.InvoiceNumber, HasProof: r.ProofUrl != nil, PaperInvoiceNumber: r.PaperInvoiceNumber,
		StudentID: r.StudentID, StudentName: textOf(firstText(r.StudentName, ptr("Học viên"))), StudentCode: r.StudentCode,
		ClassName:  textOf(firstText(r.TuitionClassName, r.CurrentClassName, ptr("Chưa gắn lớp"))),
		BranchName: textOf(firstText(r.BranchName, ptr("Trụ sở chính"))), PaymentMethod: r.PaymentMethod,
		MethodLabel: methodLabel(r.PaymentMethod), TransactionCode: r.TransactionCode, Amount: r.Amount, SurchargeAmount: r.SurchargeAmount,
		HasSurcharge: r.SurchargeAmount > 0, CreatorName: r.CreatorName, ApproverName: r.ApproverName,
		CreatedAt: r.CreatedAt.In(vnZone).Format("15:04 02/01/2006"), PaymentDate: fmtDate(r.PaymentDate), Status: r.Status,
		StatusLabel: receiptStatusLabel(r.Status), StatusColor: receiptStatusColor(r.Status),
		DepositState: depositState(fr, cutoff, s.now()), DepositedAt: fmtDateTime(r.DepositedAt, "15:04 02/01/2006"),
		DepositedByName: r.DepositorName}
	if r.TuitionID != nil {
		row.FeeLabel = ptr(feeLabel(r.CourseName, r.TuitionClassName, r.TuitionFeeItemCount))
	}
	row.CanConfirmDeposit = row.DepositState != nil && *row.DepositState == "pending" && actor.Can("tuition.confirm_deposit")
	return row
}

// ───────────── Màn duyệt phiếu thu ─────────────

type ApproveFilter struct {
	Status        string
	BranchID      *uuid.UUID
	PaymentMethod string
	Search        string
	SelectedID    *uuid.UUID
}

type ApproveScreen struct {
	Filters       map[string]any `json:"filters"`
	Branches      []Option       `json:"branches"`
	Statuses      []Option       `json:"statuses"`
	PendingCount  int64          `json:"pending_count"`
	PendingTotal  int64          `json:"pending_total"`
	ApprovedToday int64          `json:"approved_today_count"`
	RejectedToday int64          `json:"rejected_today_count"`
	Receipts      []ReceiptRow   `json:"receipts"`
	Selected      *ReceiptDetail `json:"selected"`
	CanApprove    bool           `json:"can_approve"`
	CanReject     bool           `json:"can_reject"`
}

const approveListLimit = 200

func (s *Service) ApproveScreen(ctx context.Context, actor *iam.Access, f ApproveFilter) (*ApproveScreen, error) {
	limit := branchLimit(actor)
	today := s.today()
	m, err := s.q.ReceiptMetrics(ctx, tuitiondb.ReceiptMetricsParams{BranchIds: limit, DayStart: dayStart(today), DayEnd: dayStart(today).AddDate(0, 0, 1)})
	if err != nil {
		return nil, err
	}
	status := strings.TrimSpace(f.Status)
	if status == "" {
		status = "all"
		if m.PendingCount > 0 {
			status = StatusPending
		}
	}
	method := strings.TrimSpace(f.PaymentMethod)
	if method == "ck" {
		method = "transfer"
	}
	out := &ApproveScreen{Statuses: receiptStatuses, PendingCount: m.PendingCount, PendingTotal: m.PendingTotal,
		ApprovedToday: m.ApprovedToday, RejectedToday: m.RejectedToday, Receipts: []ReceiptRow{},
		CanApprove: actor.Can("tuition.approve"), CanReject: actor.Can("tuition.reject"),
		Filters: map[string]any{"status": status, "branch_id": f.BranchID, "payment_method": textOf(firstText(&method, ptr("all"))), "q": f.Search}}
	p := tuitiondb.ListReceiptsParams{BranchIds: limit, BranchID: f.BranchID, Search: likePattern(f.Search), PageLimit: approveListLimit}
	if status != "all" {
		p.Status = &status
	}
	if method != "" && method != "all" {
		p.Method = &method
	}
	rows, err := s.q.ListReceipts(ctx, p)
	if err != nil {
		return nil, err
	}
	cutoff := s.depositCutoff(ctx)
	for _, r := range rows {
		out.Receipts = append(out.Receipts, s.receiptRow(r, actor, cutoff))
	}
	if out.Branches, _, err = s.scopeOptions(ctx, actor, false); err != nil {
		return nil, err
	}
	if f.SelectedID != nil {
		info, err := s.loadReceiptScoped(ctx, actor, *f.SelectedID)
		if err == nil {
			d := s.receiptDetail(ctx, info, actor, cutoff)
			if err := s.enrichDetail(ctx, &d, info); err != nil {
				return nil, err
			}
			out.Selected = &d
		} else if _, ok := err.(*iam.Error); !ok {
			return nil, err
		}
	}
	return out, nil
}

// Receipt: chi tiết một phiếu (kèm đối soát).
func (s *Service) Receipt(ctx context.Context, actor *iam.Access, id uuid.UUID) (*ReceiptDetail, error) {
	info, err := s.loadReceiptScoped(ctx, actor, id)
	if err != nil {
		return nil, err
	}
	d := s.receiptDetail(ctx, info, actor, s.depositCutoff(ctx))
	if err := s.enrichDetail(ctx, &d, info); err != nil {
		return nil, err
	}
	return &d, nil
}

// ───────────── Lịch sử thu ─────────────

type HistoryFilter struct {
	Search    string
	StudentID *uuid.UUID
	Status    string
	Method    string
	From, To  string
	Kind      string
	Page      int
}

type HistoryPage struct {
	Receipts    []ReceiptRow   `json:"receipts"`
	Page        Page           `json:"page"`
	AmountTotal int64          `json:"amount_total"`
	Filters     map[string]any `json:"filters"`
	Statuses    []Option       `json:"statuses"`
	Methods     []Option       `json:"methods"`
}

func (s *Service) historyParams(actor *iam.Access, f HistoryFilter) tuitiondb.ListReceiptsParams {
	p := tuitiondb.ListReceiptsParams{BranchIds: branchLimit(actor), StudentID: f.StudentID, Search: likePattern(f.Search),
		RenewalOnly: f.Kind == "renewal"}
	if st := strings.TrimSpace(f.Status); st != "" && st != "all" {
		p.Status = &st
	}
	if m := strings.TrimSpace(f.Method); m != "" && m != "all" {
		p.Method = &m
	}
	if d, ok := parseDate(f.From); ok {
		p.FromDay = &d
	}
	if d, ok := parseDate(f.To); ok {
		p.ToDay = &d
	}
	return p
}

func (s *Service) History(ctx context.Context, actor *iam.Access, f HistoryFilter) (*HistoryPage, error) {
	p := s.historyParams(actor, f)
	p.PageLimit, p.PageOffset = pageOf(f.Page, perPage)
	rows, err := s.q.ListReceipts(ctx, p)
	if err != nil {
		return nil, err
	}
	cnt, err := s.q.CountReceipts(ctx, tuitiondb.CountReceiptsParams{BranchIds: p.BranchIds, Status: p.Status, Method: p.Method,
		BranchID: p.BranchID, StudentID: p.StudentID, FromDay: p.FromDay, ToDay: p.ToDay, RenewalOnly: p.RenewalOnly, Search: p.Search})
	if err != nil {
		return nil, err
	}
	cutoff := s.depositCutoff(ctx)
	out := &HistoryPage{Receipts: make([]ReceiptRow, 0, len(rows)), Page: newPage(f.Page, perPage, cnt.Count), AmountTotal: cnt.AmountTotal,
		Statuses: receiptStatuses, Methods: []Option{{"transfer", "Chuyển khoản"}, {"vietqr", "Chuyển khoản VietQR"}, {"cash", "Tiền mặt"}, {"pos", "Quẹt thẻ POS"}},
		Filters: map[string]any{"search": f.Search, "student_id": f.StudentID, "status": f.Status, "method": f.Method, "from": f.From, "to": f.To, "kind": f.Kind}}
	for _, r := range rows {
		out.Receipts = append(out.Receipts, s.receiptRow(r, actor, cutoff))
	}
	return out, nil
}

// ExportHistory: CSV (có BOM để Excel đọc đúng tiếng Việt) theo bộ lọc lịch sử thu.
func (s *Service) ExportHistory(ctx context.Context, actor *iam.Access, f HistoryFilter) ([]byte, error) {
	p := s.historyParams(actor, f)
	p.PageLimit = 50000
	rows, err := s.q.ListReceipts(ctx, p)
	if err != nil {
		return nil, err
	}
	var buf bytes.Buffer
	buf.WriteString("\uFEFF")
	w := csv.NewWriter(&buf)
	_ = w.Write([]string{"Mã phiếu", "Số HĐĐT", "Mã HV", "Học viên", "Khoản thu", "Số tiền (VNĐ)", "Phụ thu (VNĐ)", "Hình thức",
		"Mã giao dịch", "Trạng thái", "Ngày thu", "Người lập", "Người duyệt", "Chi nhánh"})
	for _, r := range rows {
		fee := ""
		if r.TuitionID != nil {
			fee = feeLabel(r.CourseName, r.TuitionClassName, r.TuitionFeeItemCount)
		}
		day := dateOf(r.CreatedAt)
		if r.PaymentDate != nil {
			day = *r.PaymentDate
		}
		_ = w.Write([]string{r.Code, textOf(r.InvoiceNumber), textOf(r.StudentCode), textOf(r.StudentName), fee,
			itoa64(r.Amount), itoa64(r.SurchargeAmount), methodLabel(r.PaymentMethod), textOf(r.TransactionCode),
			receiptStatusLabel(r.Status), day.Format("02/01/2006"), textOf(r.CreatorName), textOf(r.ApproverName), textOf(r.BranchName)})
	}
	w.Flush()
	return buf.Bytes(), w.Error()
}

func itoa64(v int64) string { return strings.ReplaceAll(groupThousands(v), ".", "") }
