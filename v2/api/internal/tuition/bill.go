package tuition

import (
	"context"
	"net/url"
	"strings"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/tuition/tuitiondb"
)

// Bill "Thông báo nộp học phí" (CrmController::tuitionBill, route crm.tuition-bill): mở được bằng id khoản học phí hoặc
// id học viên (lấy khoản mới nhất). Người có tuition.view: theo phạm vi chi nhánh học phí; chỉ có lead.view: khách CRM
// đã chốt ra học viên phải nằm trong phạm vi khách của mình.

// LeadVisibility: CRM kiểm người dùng có xem được khách (crm.Service.LeadVisible).
type LeadVisibility interface {
	LeadVisible(ctx context.Context, a *iam.Access, leadID uuid.UUID) (bool, error)
}

func (s *Service) WithLeads(x LeadVisibility) *Service { s.leads = x; return s }

type BillLead struct {
	ID   uuid.UUID `json:"id"`
	Code string    `json:"code"`
	Name string    `json:"name"`
}

type Bill struct {
	Tuition struct {
		ID             uuid.UUID  `json:"id"`
		TotalAmount    int64      `json:"total_amount"`
		DiscountAmount int64      `json:"discount_amount"`
		OtherFees      int64      `json:"other_fees"`
		FeeItems       []ItemLine `json:"fee_items"`
		PrepaidAmount  int64      `json:"prepaid_amount"`
		FinalAmount    int64      `json:"final_amount"`
		PaidAmount     int64      `json:"paid_amount"`
		DebtAmount     int64      `json:"debt_amount"`
		SessionCount   *int32     `json:"session_count"`
		DueDate        *string    `json:"due_date"`
		Status         string     `json:"status"`
		StatusLabel    string     `json:"status_label"`
		Notes          *string    `json:"notes"`
		CreatedAt      string     `json:"created_at"`
	} `json:"tuition"`
	Student struct {
		ID          uuid.UUID `json:"id"`
		Code        string    `json:"code"`
		Name        string    `json:"name"`
		Phone       string    `json:"phone"`
		ParentName  *string   `json:"parent_name"`
		ParentPhone *string   `json:"parent_phone"`
	} `json:"student"`
	ClassName     *string   `json:"class_name"`
	CourseName    *string   `json:"course_name"`
	BranchName    *string   `json:"branch_name"`
	Lead          *BillLead `json:"customer"`
	PromotionName *string   `json:"promotion_name"`
	Bank          *Bank     `json:"bank_account"`
	TransferMemo  string    `json:"transfer_memo"`
	AmountToPay   int64     `json:"amount_to_pay"`
	PendingAmount int64     `json:"pending_amount"`
	VietQRURL     *string   `json:"vietqr_url"`
	QRWarning     *string   `json:"qr_warning"`
	TotalSessions int       `json:"total_sessions"`
	// Lịch lớp (null = chưa xếp lớp: "Sẽ thông báo khi Học vụ xếp lớp").
	Schedule *BillSchedule `json:"schedule"`
	Receipts []BillReceipt `json:"receipts"`
	Center   BillCenter    `json:"center"`
	Today    string        `json:"today"`
}

type BillSchedule struct {
	Text      *string `json:"text"`
	StartDate *string `json:"start_date"`
	EndDate   *string `json:"end_date"`
}

// BillReceipt: một đợt thu của khoản học phí.
type BillReceipt struct {
	Installment        int     `json:"installment"`
	Code               string  `json:"code"`
	InvoiceNumber      *string `json:"invoice_number"`
	PaperInvoiceNumber *string `json:"paper_invoice_number"`
	Status             string  `json:"status"`
	Method             string  `json:"method"`
	MethodLabel        string  `json:"method_label"`
	Date               string  `json:"date"`
	Content            string  `json:"content"`
	Amount             int64   `json:"amount"`
}

// BillCenter: tên, địa chỉ các cơ sở và điện thoại trung tâm (CenterInfo).
type BillCenter struct {
	Name     string   `json:"name"`
	Branches []string `json:"branches"`
	Phone    *string  `json:"phone"`
}

// billMethodLabel: hình thức thu in trên bill.
func billMethodLabel(m string) string {
	switch m {
	case "transfer":
		return "Chuyển khoản VietQR"
	case "cash":
		return "Tiền mặt tại quầy"
	case "pos":
		return "Quẹt thẻ POS"
	case "split":
		return "Kết hợp (TM + CK)"
	}
	return methodLabel(m)
}

func (s *Service) billCenter(ctx context.Context) (BillCenter, error) {
	c := BillCenter{Name: "MENGLISH", Branches: []string{}}
	set, err := s.q.CenterSettings(ctx)
	if err != nil {
		return c, err
	}
	for _, r := range set {
		v := strings.TrimSpace(r.Value)
		switch {
		case v == "":
		case r.Key == "center_name":
			c.Name = v
		case r.Key == "center_phone":
			c.Phone = &v
		}
	}
	brs, err := s.q.CenterBranches(ctx)
	if err != nil {
		return c, err
	}
	for _, b := range brs {
		c.Branches = append(c.Branches, b.Name+": "+b.Address)
	}
	return c, nil
}

var errBillNotFound = &iam.Error{Status: 404, Code: "not_found", Message: "Không tìm thấy thông tin công nợ / học phí của học viên."}

// billBank: tài khoản của hợp đồng (đúng chi nhánh), rồi tài khoản VietQR mặc định của chi nhánh, rồi của hệ thống.
func billBank(rows []tuitiondb.BillBankAccountsRow, own, branch *uuid.UUID) *Bank {
	conv := func(a tuitiondb.BillBankAccountsRow) *Bank {
		return &Bank{ID: a.ID, BankCode: a.BankCode, BankName: a.BankName, AccountNumber: a.AccountNumber, AccountHolder: a.AccountHolder}
	}
	if own != nil {
		for _, a := range rows {
			if a.ID == *own && (branch == nil || a.BranchID == nil || *a.BranchID == *branch) {
				return conv(a)
			}
		}
	}
	if branch != nil {
		for _, a := range rows {
			if a.IsDefaultVietqr && a.BranchID != nil && *a.BranchID == *branch {
				return conv(a)
			}
		}
	}
	for _, a := range rows {
		if a.IsDefaultVietqr && a.BranchID == nil {
			return conv(a)
		}
	}
	return nil
}

func (s *Service) Bill(ctx context.Context, actor *iam.Access, id uuid.UUID) (*Bill, error) {
	t, err := s.q.GetTuition(ctx, id)
	if isNoRows(err) {
		// Không phải khoản học phí: thử id học viên (khoản mới nhất).
		lt, err2 := s.q.LatestStudentTuition(ctx, id)
		if isNoRows(err2) {
			return nil, errBillNotFound
		}
		if err2 != nil {
			return nil, err2
		}
		t, err = s.q.GetTuition(ctx, lt.ID)
	}
	if err != nil {
		return nil, err
	}
	var lead *BillLead
	leadID, err := s.q.LeadOfStudent(ctx, &t.StudentID)
	if err != nil && !isNoRows(err) {
		return nil, err
	}
	hasLead := err == nil
	if actor.Can("tuition.view") {
		if !covers(actor, nonZero(t.ScopeBranchID)) {
			return nil, errBillNotFound
		}
	} else {
		ok := false
		if hasLead && s.leads != nil {
			if ok, err = s.leads.LeadVisible(ctx, actor, leadID); err != nil {
				return nil, err
			}
		}
		if !ok {
			return nil, errBillNotFound
		}
	}
	if hasLead {
		l, err := s.q.BillLead(ctx, leadID)
		if err != nil {
			return nil, err
		}
		lead = &BillLead{ID: l.ID, Code: l.Code, Name: l.Name}
	}
	out := &Bill{Lead: lead, ClassName: t.ClassName, CourseName: t.CourseName, BranchName: t.BranchName}
	bt := &out.Tuition
	bt.ID, bt.TotalAmount, bt.DiscountAmount, bt.OtherFees, bt.FeeItems = t.ID, t.TotalAmount, t.DiscountAmount, t.OtherFees, decodeItems(t.FeeItems)
	bt.PrepaidAmount, bt.FinalAmount, bt.PaidAmount, bt.DebtAmount = t.PrepaidAmount, t.FinalAmount, t.PaidAmount, t.DebtAmount
	bt.SessionCount, bt.DueDate, bt.Status, bt.StatusLabel, bt.Notes = t.SessionCount, fmtDate(t.DueDate), t.Status, tuitionStatusLabel(t.Status), t.Notes
	bt.CreatedAt = t.CreatedAt.In(vnZone).Format("02/01/2006")
	st := &out.Student
	st.ID, st.Code, st.Name, st.Phone, st.ParentName, st.ParentPhone = t.StudentID, t.StudentCode, t.StudentName, t.StudentPhone, t.StudentParentName, t.StudentParentPhone
	if t.PromotionID != nil {
		if p, err := s.q.GetPromotion(ctx, *t.PromotionID); err == nil {
			out.PromotionName = &p.Name
		} else if !isNoRows(err) {
			return nil, err
		}
	}
	// Nội dung CK luôn theo mẫu hiện hành (tên + mã + lớp của khoản, không có thì lớp đang học).
	memo := transferMemo(t.StudentCode, t.StudentName, firstText(t.ClassName, t.CurrentClassName))
	if memo != "" && textOf(t.TransferMemo) != memo {
		if err := s.q.SetTransferMemo(ctx, tuitiondb.SetTransferMemoParams{ID: t.ID, TransferMemo: &memo}); err != nil {
			return nil, err
		}
	}
	out.TransferMemo = memo
	pending, err := s.q.PendingReceiptTotal(ctx, &t.ID)
	if err != nil {
		return nil, err
	}
	debt := max(0, t.DebtAmount)
	out.PendingAmount = min(debt, pending)
	out.AmountToPay = max(0, debt-out.PendingAmount)
	banks, err := s.q.BillBankAccounts(ctx)
	if err != nil {
		return nil, err
	}
	out.Bank = billBank(banks, t.BankAccountID, t.BranchID)
	if out.Bank == nil && out.AmountToPay > 0 {
		out.QRWarning = ptr("Chưa cấu hình tài khoản ngân hàng hoạt động để tạo mã VietQR. Vui lòng liên hệ Kế toán/Admin bổ sung trước khi thu tiền.")
	}
	if out.Bank != nil && out.AmountToPay > 0 {
		b := out.Bank
		out.VietQRURL = ptr("https://img.vietqr.io/image/" + url.QueryEscape(b.BankCode) + "-" + url.QueryEscape(strings.Join(strings.Fields(b.AccountNumber), "")) +
			"-compact2.png?amount=" + itoa64(out.AmountToPay) + "&addInfo=" + url.QueryEscape(memo) + "&accountName=" + url.QueryEscape(b.AccountHolder))
	}
	cls, err := s.getClass(ctx, s.q, t.ClassID)
	if err != nil {
		return nil, err
	}
	if t.ClassID != nil {
		c, err := s.q.BillClass(ctx, *t.ClassID)
		if err != nil && !isNoRows(err) {
			return nil, err
		}
		if err == nil {
			// Lớp chưa có ngày: như Laravel in ngày 01 tháng này → 3 tháng sau.
			now := s.nowVN()
			start, end := fmtDate(c.StartDate), fmtDate(c.EndDate)
			if start == nil {
				start = ptr(time.Date(now.Year(), now.Month(), 1, 0, 0, 0, 0, vnZone).Format("02/01/2006"))
			}
			if end == nil {
				end = ptr(now.AddDate(0, 3, 0).Format("02/01/2006"))
			}
			out.Schedule = &BillSchedule{Text: optText(c.ScheduleText), StartDate: start, EndDate: end}
		}
	}
	recs, err := s.q.BillReceipts(ctx, &t.ID)
	if err != nil {
		return nil, err
	}
	out.Receipts = []BillReceipt{}
	for i, r := range recs {
		d := r.CreatedAt.In(vnZone)
		if r.PaymentDate != nil {
			d = *r.PaymentDate
		}
		content := textOf(optText(r.Notes))
		if content == "" {
			content = "Thanh toán học phí đợt " + itoaPlain(i+1)
		}
		out.Receipts = append(out.Receipts, BillReceipt{Installment: i + 1, Code: r.Code, InvoiceNumber: r.InvoiceNumber,
			PaperInvoiceNumber: r.PaperInvoiceNumber, Status: r.Status, Method: r.PaymentMethod, MethodLabel: billMethodLabel(r.PaymentMethod),
			Date: d.Format("02/01/2006"), Content: content, Amount: r.Amount})
	}
	if out.Center, err = s.billCenter(ctx); err != nil {
		return nil, err
	}
	out.Today = s.nowVN().Format("02/01/2006")
	if cls != nil && cls.CourseLessons != nil {
		out.TotalSessions = int(*cls.CourseLessons)
	} else if n, err := s.q.StudentTotalLessons(ctx, t.StudentID); err == nil && n != nil {
		out.TotalSessions = int(*n)
	} else if err != nil && !isNoRows(err) {
		return nil, err
	}
	return out, nil
}
