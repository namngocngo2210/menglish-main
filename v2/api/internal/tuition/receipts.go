package tuition

import (
	"context"
	"errors"
	"fmt"
	"math"
	"slices"
	"strings"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/crm"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/crm/crmdb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/files"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/filestore"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/tuition/tuitiondb"
)

// Phiếu thu (TuitionController::storeReceipt / updateReceipt / approveReceiptAction / rejectReceiptAction /
// confirmDeposit). Phiếu không bao giờ tự duyệt khi lập; chỉ thao tác duyệt (tuition.approve) mới duyệt và cấp số
// hóa đơn điện tử. Phiếu tiền mặt ở chi nhánh có cuốn hóa đơn giấy được cấp số giấy ngay lúc lập.

// ItemQty: hàng hóa chọn ở phần phụ thu.
type ItemQty struct {
	ID       uuid.UUID `json:"id"`
	Quantity *int      `json:"quantity"`
}

// ReceiptInput: form lập / sửa phiếu thu. SessionCount có giá trị = phiếu theo sổ buổi (server tự tính tiền).
type ReceiptInput struct {
	StudentID                  *uuid.UUID `json:"student_id"`
	TuitionID                  *uuid.UUID `json:"student_tuition_id"`
	TuitionAmount              *int64     `json:"tuition_amount"`
	SessionCount               *int       `json:"session_count"`
	ExamFee                    *int64     `json:"exam_fee"`
	OtherFee                   *int64     `json:"other_fee"`
	OtherFeeReason             *string    `json:"other_fee_reason"`
	DiscountAmount             *int64     `json:"discount_amount"`
	PromotionID                *uuid.UUID `json:"promotion_id"`
	DiscountReason             *string    `json:"discount_reason"`
	SurchargeAmount            *int64     `json:"surcharge_amount"`
	SurchargeReason            *string    `json:"surcharge_reason"`
	Amount                     *int64     `json:"amount"`
	PaymentMethod              string     `json:"payment_method"`
	TransactionCode            *string    `json:"transaction_code"`
	PaperInvoiceNumber         *string    `json:"paper_invoice_number"`
	ExpectedPaperInvoiceNumber *string    `json:"expected_paper_invoice_number"`
	PayerName                  *string    `json:"payer_name"`
	PayerPhone                 *string    `json:"payer_phone"`
	IsVatInvoice               *bool      `json:"is_vat_invoice"`
	Notes                      *string    `json:"notes"`
	// nil = giữ hàng hóa đã chọn của phiếu (khi sửa)
	CollectedItems *[]ItemQty `json:"collected_items"`
	// ProofURL: đường dẫn minh chứng nhập tay (dữ liệu cũ); ProofFile: ảnh / PDF tải lên kho file (proof_image).
	ProofURL     *string `json:"proof_url"`
	RemoveProof  bool    `json:"remove_proof"`
	SubmitAction string  `json:"submit_action"`
	// ProofFile: file multipart proof_image (handler gắn; đã qua ReceiptProofPolicy trong service)
	ProofFile *filestore.Upload `json:"-"`
}

const receiptProofSubject = "tuition_receipt_proof"

// ReceiptProofPolicy: proof_image của Laravel (nullable|file|mimes:jpeg,png,jpg,pdf,webp|max:5120).
var ReceiptProofPolicy = filestore.Policy{Types: []string{"jpg", "jpeg", "png", "webp", "pdf"}, MaxBytes: 5 << 20,
	TooLarge: "Dung lượng tập tin ảnh minh chứng không được vượt quá 5120 kB."}

// saveReceiptProof lưu ảnh minh chứng vào kho file, gắn với phiếu (proof_url = link mở file, kiểm quyền khi mở).
func (s *Service) saveReceiptProof(ctx context.Context, tx pgx.Tx, q *tuitiondb.Queries, actor *iam.Access, r tuitiondb.FinanceReceipt,
	student string, branch *uuid.UUID, proof *filestore.Upload) error {
	if s.files == nil {
		return iam.Invalid("Kho file chưa sẵn sàng: chưa tải được ảnh minh chứng.")
	}
	f, err := s.files.Save(ctx, tx, proof, files.SaveInput{
		Owner: files.Owner{Module: "tuition", SubjectType: receiptProofSubject, SubjectID: &r.ID, BranchID: branch},
		By:    &actor.UserID,
		Audit: audit.Entry{Module: audit.ModuleTuition, SubjectType: "tuition_receipt", SubjectID: r.ID.String(),
			SubjectLabel: receiptLabel(r.Code, student), Description: "Tải ảnh minh chứng phiếu thu"},
	})
	if err != nil {
		return err
	}
	return q.SetReceiptProofFile(ctx, tuitiondb.SetReceiptProofFileParams{ID: r.ID, ProofFileID: &f.ID, ProofUrl: ptr(files.URL(f.ID))})
}

// readReceiptProof: quyền mở ảnh minh chứng — người xem được phiếu thu (tuition.view + phạm vi chi nhánh).
func (s *Service) readReceiptProof(ctx context.Context, a *iam.Access, f files.File) (string, error) {
	if f.SubjectID == nil || !a.Can("tuition.view") {
		return "", forbidden("Bạn không có quyền xem ảnh minh chứng này.")
	}
	if _, err := s.loadReceiptScoped(ctx, a, *f.SubjectID); err != nil {
		return "", err
	}
	return "", nil
}

func (in *ReceiptInput) clean() error {
	for _, p := range []**string{&in.OtherFeeReason, &in.DiscountReason, &in.SurchargeReason, &in.PayerName, &in.PayerPhone,
		&in.Notes, &in.ProofURL, &in.ExpectedPaperInvoiceNumber} {
		*p = optText(*p)
	}
	in.PaymentMethod = strings.TrimSpace(in.PaymentMethod)
	switch {
	case in.Amount == nil:
		return iam.Invalid("Vui lòng nhập số tiền của phiếu.")
	case *in.Amount < 1000:
		return iam.Invalid("Số tiền của phiếu tối thiểu 1.000 đ.")
	case in.PaymentMethod == "":
		return iam.Invalid("Vui lòng chọn hình thức thu.")
	case !slices.Contains(inputMethods, in.PaymentMethod):
		return iam.Invalid("Hình thức thu không hợp lệ.")
	case in.SessionCount != nil && (*in.SessionCount < 0 || *in.SessionCount > 500):
		return iam.Invalid("Số buổi thu phải từ 0 đến 500.")
	case tooLong(in.OtherFeeReason, 255):
		return iam.Invalid("Nội dung khoản thu khác tối đa 255 ký tự.")
	case tooLong(in.DiscountReason, 500):
		return iam.Invalid("Lý do giảm trừ tối đa 500 ký tự.")
	case tooLong(in.SurchargeReason, 500):
		return iam.Invalid("Lý do phụ thu tối đa 500 ký tự.")
	case in.TransactionCode != nil && len([]rune(strings.TrimSpace(*in.TransactionCode))) > 100:
		return iam.Invalid("Mã giao dịch tối đa 100 ký tự.")
	case in.PaperInvoiceNumber != nil && len([]rune(strings.TrimSpace(*in.PaperInvoiceNumber))) > 100:
		return iam.Invalid("Số hóa đơn giấy tối đa 100 ký tự.")
	case tooLong(in.PayerName, 255):
		return iam.Invalid("Tên người nộp tối đa 255 ký tự.")
	case tooLong(in.PayerPhone, 50):
		return iam.Invalid("Số điện thoại người nộp tối đa 50 ký tự.")
	case tooLong(in.Notes, 1000):
		return iam.Invalid("Ghi chú tối đa 1000 ký tự.")
	case tooLong(in.ProofURL, 1000):
		return iam.Invalid("Đường dẫn minh chứng tối đa 1000 ký tự.")
	case in.SubmitAction != "" && in.SubmitAction != "draft" && in.SubmitAction != "submit":
		return iam.Invalid("Thao tác không hợp lệ.")
	}
	for _, v := range []*int64{in.TuitionAmount, in.ExamFee, in.OtherFee, in.DiscountAmount, in.SurchargeAmount} {
		if v != nil && *v < 0 {
			return iam.Invalid("Số tiền không được âm.")
		}
	}
	return nil
}

func (in *ReceiptInput) draft() bool { return in.SubmitAction == "draft" }

// receiptAmountError: phiếu thu theo số tiền (form cũ).
func receiptAmountError(in *ReceiptInput) error {
	amount, surcharge := *in.Amount, val(in.SurchargeAmount)
	if surcharge > amount {
		return iam.Invalid("Tiền phụ thu không được lớn hơn tổng tiền của phiếu.")
	}
	if in.TuitionAmount != nil && abs(*in.TuitionAmount+surcharge-amount) > 0 {
		return iam.Invalid("Tổng thu phải bằng tiền học phí + tiền phụ thu.")
	}
	return nil
}

func abs(v int64) int64 {
	if v < 0 {
		return -v
	}
	return v
}

// surchargeError: phụ thu = tiền hàng hóa đã chọn + phụ thu khác (phụ thu khác bắt buộc lý do).
func surchargeError(in *ReceiptInput, items []ItemLine) error {
	surcharge, total := val(in.SurchargeAmount), itemsTotal(items)
	if total > surcharge {
		return invalidf("Tiền phụ thu (%s) ít hơn tiền hàng hóa đã chọn (%s).", money(surcharge), money(total))
	}
	if surcharge > total && in.SurchargeReason == nil {
		return iam.Invalid("Bắt buộc nhập lý do khi có số tiền phụ thu.")
	}
	return nil
}

// surchargeReason: lý do người lập nhập, thiếu thì ghi danh sách hàng hóa đã chọn.
func surchargeReason(reason *string, items []ItemLine) *string {
	if reason != nil || len(items) == 0 {
		return reason
	}
	parts := make([]string, 0, len(items))
	for _, l := range items {
		parts = append(parts, fmt.Sprintf("%s x%d", l.Name, l.Quantity))
	}
	return ptr(limitText(strings.Join(parts, ", "), 490))
}

// surchargeItems: hàng hóa ở phần phụ thu, chỉ nhận mặt hàng đang bán trong danh mục, giá theo danh mục.
func (s *Service) surchargeItems(ctx context.Context, q *tuitiondb.Queries, raw []ItemQty) ([]ItemLine, error) {
	qty := map[uuid.UUID]int{}
	var order []uuid.UUID
	for _, l := range raw {
		if l.ID == uuid.Nil {
			continue
		}
		n := 1
		if l.Quantity != nil {
			n = *l.Quantity
		}
		if n < 1 || n > 1000 {
			return nil, iam.Invalid("Số lượng hàng hóa phải từ 1 đến 1000.")
		}
		if _, ok := qty[l.ID]; !ok {
			order = append(order, l.ID)
		}
		qty[l.ID] += n
	}
	if len(order) == 0 {
		return []ItemLine{}, nil
	}
	rows, err := q.ActiveItemsByIDs(ctx, order)
	if err != nil {
		return nil, err
	}
	if len(rows) != len(order) {
		return nil, iam.Invalid("Có hàng hóa không tồn tại hoặc đã ngừng bán.")
	}
	byID := map[uuid.UUID]tuitiondb.ActiveItemsByIDsRow{}
	for _, r := range rows {
		byID[r.ID] = r
	}
	out := make([]ItemLine, 0, len(order))
	for _, id := range order {
		r := byID[id]
		out = append(out, ItemLine{ID: id, Code: r.Code, Name: r.Name, Unit: r.Unit, Quantity: qty[id], UnitPrice: r.Price,
			Amount: r.Price * int64(qty[id]), Source: "surcharge"})
	}
	return out, nil
}

// subject: học viên / khoản học phí của phiếu.
type subject struct {
	tuition *tuitiondb.FinanceTuition
	student tuitiondb.GetStudentRow
	// Chi nhánh ghi nhận phiếu (khoản học phí → học viên → lớp đang học)
	branch *uuid.UUID
}

func (sb subject) studentClass() *classInfo {
	st := sb.student
	if st.CurrentClassID == nil {
		return nil
	}
	return &classInfo{ID: *st.CurrentClassID, Name: textOf(st.ClassName), Code: textOf(st.ClassCode), BranchID: val(st.ClassBranchID),
		CourseID: st.ClassCourseID, TuitionFee: st.ClassTuitionFee, CourseFee: st.CourseTuitionFee, CourseLessons: st.CourseLessons,
		CourseName: st.CourseName}
}

// resolveSubject đọc (và khóa) khoản học phí, đọc học viên, kiểm phạm vi chi nhánh.
func (s *Service) resolveSubject(ctx context.Context, q *tuitiondb.Queries, actor *iam.Access, studentID, tuitionID *uuid.UUID) (subject, error) {
	var sb subject
	if tuitionID == nil && studentID == nil {
		return sb, iam.Invalid("Vui lòng chọn học viên hoặc khoản học phí.")
	}
	if tuitionID != nil {
		t, err := q.LockTuition(ctx, *tuitionID)
		if isNoRows(err) {
			return sb, iam.Invalid("Khoản học phí đã chọn không tồn tại.")
		}
		if err != nil {
			return sb, err
		}
		if studentID != nil && *studentID != t.StudentID {
			return sb, iam.Invalid("Học viên không khớp với khoản học phí đã chọn.")
		}
		sb.tuition = &t
		studentID = &t.StudentID
	}
	st, err := q.GetStudent(ctx, *studentID)
	if isNoRows(err) || (err == nil && st.DeletedAt != nil && sb.tuition == nil) {
		return sb, iam.Invalid("Học viên đã chọn không tồn tại.")
	}
	if err != nil {
		return sb, err
	}
	sb.student = st
	sb.branch = nonZero(st.ScopeBranchID)
	if sb.tuition != nil && sb.tuition.BranchID != nil {
		sb.branch = sb.tuition.BranchID
	}
	if !covers(actor, sb.branch) {
		return sb, ErrOutOfScope
	}
	return sb, nil
}

// priced: kết quả tính tiền của phiếu.
type priced struct {
	tuition         *tuitiondb.FinanceTuition
	amount          int64
	surcharge       int64
	surchargeReason *string
	promotionID     *uuid.UUID
	discount        int64
	discountReason  *string
	sessionCount    *int32
	unitPrice       *int64
	materialFee     int64
	examFee         int64
	otherFee        int64
	otherFeeReason  *string
}

func (s *Service) promotion(ctx context.Context, q *tuitiondb.Queries, id uuid.UUID) (*crmdb.CrmPromotion, error) {
	p, err := q.GetPromotion(ctx, id)
	if isNoRows(err) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	c := crmdb.CrmPromotion(p)
	return &c, nil
}

func promotionValue(p *crmdb.CrmPromotion) float64 { return p.Value }

// sessionPricing: phiếu theo sổ buổi (sessionReceipt): server tự tính số tiền, không tin số tiền của form.
func (s *Service) sessionPricing(ctx context.Context, q *tuitiondb.Queries, in *ReceiptInput, sb subject, items []ItemLine, existing *tuitiondb.FinanceReceipt) (priced, error) {
	var out priced
	open := sb.tuition
	if open == nil || open.DebtAmount <= 0 {
		o, err := q.OpenTuitionFor(ctx, sb.student.ID)
		if err != nil && !isNoRows(err) {
			return out, err
		}
		open = nil
		if err == nil {
			open = &o
		}
	}
	class := sb.studentClass()
	qt, err := s.quote(ctx, q, sb.student.ID, class, open)
	if err != nil {
		return out, err
	}
	if class == nil && open != nil {
		if class, err = s.getClass(ctx, q, open.ClassID); err != nil {
			return out, err
		}
	}
	sessions := val(in.SessionCount)
	if sessions > 0 && qt.Mode == "none" {
		return out, iam.Invalid("Học viên chưa xếp lớp và chưa có khoản học phí nên chưa thu theo buổi được.")
	}
	if sessions > qt.MaxSessions {
		if qt.Mode == "contract" {
			return out, invalidf("Khoản học phí đang thu chỉ còn %d buổi chưa đóng. Thu hết khoản này rồi lập phiếu mới cho khóa kế tiếp.", qt.MaxSessions)
		}
		return out, invalidf("Theo sổ buổi chỉ cần thu tối đa %d buổi (còn %d buổi của khóa, tồn %d buổi).", qt.Needed, qt.Remaining, qt.Balance)
	}
	exam, other, extra := val(in.ExamFee), val(in.OtherFee), val(in.SurchargeAmount)
	if !in.draft() && other > 0 && in.OtherFeeReason == nil {
		return out, iam.Invalid("Nhập nội dung khoản thu khác.")
	}
	if !in.draft() && extra > 0 && in.SurchargeReason == nil {
		return out, iam.Invalid("Bắt buộc nhập lý do khi có số tiền phụ thu.")
	}
	// Giảm trừ chỉ tính trên tiền buổi: ưu đãi trong danh mục (server tính lại) hoặc nhập tay kèm lý do.
	value := calculate(qt, sessions, 0, 0, 0, 0, 0).SessionValue
	var discount int64
	if in.PromotionID != nil {
		p, err := s.promotion(ctx, q, *in.PromotionID)
		if err != nil {
			return out, err
		}
		keepsOwn := existing != nil && existing.PromotionID != nil && *existing.PromotionID == *in.PromotionID
		branch, course := sb.student.BranchID, (*uuid.UUID)(nil)
		if class != nil {
			branch, course = &class.BranchID, class.CourseID
		}
		if p == nil || p.IsSpecial || (!keepsOwn && !crm.PromotionApplicable(*p, branch, course, s.now())) {
			return out, iam.Invalid("Ưu đãi không còn hiệu lực hoặc không áp dụng cho khóa học này.")
		}
		out.promotionID = &p.ID
		discount = int64(crm.CalculateDiscount(p.Type, promotionValue(p), p.MaxDiscountAmount, float64(value)))
	} else {
		discount = val(in.DiscountAmount)
		if discount > value {
			return out, invalidf("Giảm trừ không vượt tiền học phí theo buổi (%s).", money(value))
		}
		if discount > 0 && in.DiscountReason == nil && !in.draft() {
			return out, iam.Invalid("Giảm trừ không theo ưu đãi có sẵn: nhập lý do giảm.")
		}
	}
	c := calculate(qt, sessions, itemsTotal(items), exam, other, extra, discount)
	if abs(c.Amount-*in.Amount) > 1 {
		return out, invalidf("Số tiền của phiếu (%s) không khớp cách tính theo sổ buổi (%s). Sổ buổi vừa thay đổi, vui lòng mở lại form lập phiếu.",
			money(*in.Amount), money(c.Amount))
	}
	if qt.Mode == "contract" {
		out.tuition = open
	}
	out.amount, out.surcharge = c.Amount, c.SurchargeAmount
	if extra > 0 {
		out.surchargeReason = in.SurchargeReason
	}
	out.discount = c.Discount
	if c.Discount > 0 {
		out.discountReason = in.DiscountReason
	} else {
		out.promotionID = nil
	}
	out.sessionCount = ptr(int32(sessions))
	out.unitPrice = ptr(int64(math.Round(qt.UnitPrice)))
	out.materialFee, out.examFee, out.otherFee = c.MaterialFee, c.ExamFee, c.OtherFee
	if c.OtherFee > 0 {
		out.otherFeeReason = in.OtherFeeReason
	}
	return out, nil
}

// amountPricing: phiếu theo số tiền (receiptDiscount): giảm trừ theo ưu đãi tính lại trên phần còn phải thu.
func (s *Service) amountPricing(ctx context.Context, q *tuitiondb.Queries, in *ReceiptInput, sb subject, existing *tuitiondb.FinanceReceipt) (priced, error) {
	out := priced{tuition: sb.tuition, amount: *in.Amount, surcharge: val(in.SurchargeAmount), surchargeReason: in.SurchargeReason}
	t := sb.tuition
	if t == nil {
		return out, nil
	}
	if in.PromotionID != nil {
		p, err := s.promotion(ctx, q, *in.PromotionID)
		if err != nil {
			return out, err
		}
		course := sb.student.ClassCourseID
		if t.ClassID != nil {
			if c, err := s.getClass(ctx, q, t.ClassID); err != nil {
				return out, err
			} else if c != nil && c.CourseID != nil {
				course = c.CourseID
			}
		}
		branch := t.BranchID
		if branch == nil {
			branch = sb.student.BranchID
		}
		keepsOwn := existing != nil && existing.PromotionID != nil && *existing.PromotionID == *in.PromotionID
		if p == nil || p.IsSpecial || (!keepsOwn && !crm.PromotionApplicable(*p, branch, course, s.now())) {
			return out, iam.Invalid("Ưu đãi không còn hiệu lực hoặc không áp dụng cho khoản học phí này.")
		}
		base := t.DebtAmount
		if base <= 0 {
			base = t.TotalAmount + t.OtherFees
		}
		out.promotionID = &p.ID
		out.discount = int64(crm.CalculateDiscount(p.Type, promotionValue(p), p.MaxDiscountAmount, float64(base)))
		out.discountReason = in.DiscountReason
		return out, nil
	}
	out.discount = val(in.DiscountAmount)
	if out.discount > 0 && in.DiscountReason == nil && !in.draft() {
		return out, iam.Invalid("Giảm trừ không theo ưu đãi có sẵn: nhập lý do giảm.")
	}
	if out.discount > 0 {
		out.discountReason = in.DiscountReason
	}
	return out, nil
}

// overpaymentError: phần học phí + chiết khấu của phiếu không vượt công nợ còn lại.
func overpaymentError(t tuitiondb.FinanceTuition, portion, discount int64) string {
	if portion+discount > t.DebtAmount {
		return fmt.Sprintf("Phần học phí (%s) + chiết khấu (%s) vượt quá công nợ còn lại %s.", money(portion), money(discount), money(t.DebtAmount))
	}
	return ""
}

// receiptSubmitError: điều kiện gửi duyệt (không áp dụng khi lưu nháp).
func (s *Service) receiptSubmitError(ctx context.Context, q *tuitiondb.Queries, method string, code *string, hasProof bool, ignore *uuid.UUID, paper *string, paperRange bool) error {
	if method == "cash" && paperRange && !hasProof {
		return iam.Invalid("Thu tiền mặt cần tải ảnh chụp hóa đơn giấy mang đúng số hệ thống cấp, đã ghi đúng nội dung thu.")
	}
	if method == "cash" && !paperRange && optText(paper) == nil {
		return iam.Invalid("Thu tiền mặt cần nhập số hóa đơn giấy đã xuất cho khách.")
	}
	if proofRequired(method) && !hasProof {
		return iam.Invalid("Bắt buộc đính kèm minh chứng (ủy nhiệm chi / ảnh chuyển khoản) khi gửi duyệt phiếu thu chuyển khoản.")
	}
	ref := normalizeReference(code)
	if isTransfer(method) && ref != "" {
		dup, err := q.ReceiptByTransferReference(ctx, tuitiondb.ReceiptByTransferReferenceParams{Reference: ref, IgnoreID: ignore})
		if err == nil {
			return invalidf("Mã giao dịch %s đã được ghi nhận ở phiếu %s (%s). Không ghi nhận một khoản chuyển khoản 2 lần.",
				strings.TrimSpace(*code), dup.Code, receiptStatusLabel(dup.Status))
		}
		if !isNoRows(err) {
			return err
		}
		tx, err := q.AppliedBankTransaction(ctx, tuitiondb.AppliedBankTransactionParams{Reference: ref, IgnoreID: ignore})
		if err == nil {
			via := ""
			if tx.ReceiptCode != nil {
				via = " (phiếu " + *tx.ReceiptCode + ")"
			}
			return invalidf("Mã giao dịch %s đã được SePay tự động gạch nợ%s. Không lập thêm phiếu thu tay cho giao dịch này.", strings.TrimSpace(*code), via)
		}
		if !isNoRows(err) {
			return err
		}
	}
	return nil
}

// Saved: kết quả thao tác (phiếu + thông báo cho người dùng).
type Saved struct {
	ID      uuid.UUID `json:"id"`
	Code    string    `json:"code"`
	Status  string    `json:"status"`
	Message string    `json:"message"`
}

func (s *Service) newReceiptCode(ctx context.Context, q *tuitiondb.Queries) (string, error) {
	n, err := q.NextReceiptSeq(ctx)
	if err != nil {
		return "", err
	}
	return fmt.Sprintf("PT-%d-%06d", s.nowVN().Year(), n), nil
}

func uniqueTransfer(code *string) error {
	return invalidf("Mã giao dịch %s vừa được ghi nhận ở một phiếu thu khác.", strings.TrimSpace(textOf(code)))
}

// CreateReceipt: lập phiếu thu (nháp hoặc gửi duyệt).
func (s *Service) CreateReceipt(ctx context.Context, actor *iam.Access, in ReceiptInput) (*Saved, error) {
	if err := in.clean(); err != nil {
		return nil, err
	}
	sessionMode := in.SessionCount != nil
	if !sessionMode {
		if err := receiptAmountError(&in); err != nil {
			return nil, err
		}
	}
	if in.ProofFile != nil {
		if err := uploadErr(in.ProofFile.Check(ReceiptProofPolicy)); err != nil {
			return nil, err
		}
	}
	var out *Saved
	var studentName string
	err := s.inTx(ctx, func(tx pgx.Tx, q *tuitiondb.Queries) error {
		var raw []ItemQty
		if in.CollectedItems != nil {
			raw = *in.CollectedItems
		}
		items, err := s.surchargeItems(ctx, q, raw)
		if err != nil {
			return err
		}
		if !sessionMode {
			if err := surchargeError(&in, items); err != nil {
				return err
			}
		}
		sb, err := s.resolveSubject(ctx, q, actor, in.StudentID, in.TuitionID)
		if err != nil {
			return err
		}
		studentName = sb.student.FullName
		isDraft := in.draft()
		paperRange := false
		if in.PaymentMethod == "cash" {
			if paperRange, err = s.branchUsesPaperRange(ctx, q, sb.branch); err != nil {
				return err
			}
		}
		hasProof := in.ProofURL != nil || in.ProofFile != nil
		if !isDraft {
			if err := s.receiptSubmitError(ctx, q, in.PaymentMethod, in.TransactionCode, hasProof, nil, in.PaperInvoiceNumber, paperRange); err != nil {
				return err
			}
		}
		var p priced
		if sessionMode {
			p, err = s.sessionPricing(ctx, q, &in, sb, items, nil)
		} else {
			p, err = s.amountPricing(ctx, q, &in, sb, nil)
		}
		if err != nil {
			return err
		}
		if !isDraft && p.tuition != nil {
			if msg := overpaymentError(*p.tuition, p.amount-p.surcharge, p.discount); msg != "" {
				return iam.Invalid(msg)
			}
		}
		// Số hóa đơn giấy cấp ngay khi lập (kể cả nháp) để ghi lên hóa đơn; gửi duyệt thẳng thì phải đúng số đã thấy.
		var paper *string
		if paperRange {
			var expected *string
			if !isDraft {
				expected = in.ExpectedPaperInvoiceNumber
			}
			n, err := s.consumePaperNumber(ctx, q, *sb.branch, expected)
			if err != nil {
				return err
			}
			paper = &n
		}
		code, err := s.newReceiptCode(ctx, q)
		if err != nil {
			return err
		}
		status := StatusPending
		if isDraft {
			status = StatusDraft
		}
		notes := in.Notes
		if notes == nil {
			notes = ptr("Lập phiếu thu học phí & phụ thu")
		}
		payerName := firstText(in.PayerName)
		payerPhone := firstText(in.PayerPhone)
		if p.tuition != nil {
			payerName = firstText(in.PayerName, sb.student.ParentName, &sb.student.FullName)
			payerPhone = firstText(in.PayerPhone, &sb.student.Phone)
		}
		paperNo := paper
		if paperNo == nil {
			paperNo = optText(in.PaperInvoiceNumber)
		}
		var tuitionID *uuid.UUID
		if p.tuition != nil {
			tuitionID = &p.tuition.ID
		}
		r, err := q.CreateReceipt(ctx, tuitiondb.CreateReceiptParams{
			Code: code, Source: "manual", InvoiceNumber: paper, TuitionID: tuitionID, StudentID: &sb.student.ID,
			Amount: p.amount, TuitionAmount: p.amount - p.surcharge, SessionCount: p.sessionCount, SessionUnitPrice: p.unitPrice,
			MaterialFee: p.materialFee, ExamFee: p.examFee, OtherFee: p.otherFee, OtherFeeReason: p.otherFeeReason,
			SurchargeAmount: p.surcharge, SurchargeReason: surchargeReason(p.surchargeReason, items),
			DiscountAmount: p.discount, PromotionID: p.promotionID, DiscountReason: p.discountReason,
			PaymentMethod: in.PaymentMethod, TransactionCode: optText(in.TransactionCode), PayerName: payerName, PayerPhone: payerPhone,
			IsVatInvoice: val(in.IsVatInvoice), PaperInvoiceNumber: paperNo, ProofUrl: in.ProofURL, PaymentDate: ptr(s.today()),
			CollectedItems: encodeItems(items), Status: status, Notes: notes, CreatorID: &actor.UserID,
		})
		if isUnique(err) {
			return uniqueTransfer(in.TransactionCode)
		}
		if err != nil {
			return err
		}
		if in.ProofFile != nil {
			if err := s.saveReceiptProof(ctx, tx, q, actor, r, studentName, sb.branch, in.ProofFile); err != nil {
				return err
			}
		}
		if err := s.recordReceipt(ctx, tx, actor, r, studentName, "tuition.receipt.create", "Lập phiếu thu", sb.branch); err != nil {
			return err
		}
		if !isDraft {
			if err := s.notifyReceiptPending(ctx, tx, r, studentName); err != nil {
				return err
			}
		}
		paperNote := ""
		if hasIssuedPaper(r) {
			paperNote = " Số hóa đơn giấy: " + *r.InvoiceNumber + "."
		}
		var msg string
		if isDraft {
			msg = "Đã lưu nháp phiếu thu " + r.Code + " thành công!" + paperNote
			if paperNote != "" {
				msg += " Ghi số này lên hóa đơn giấy, chụp ảnh tải lên phiếu rồi gửi duyệt."
			} else {
				msg += " Bạn có thể tiếp tục sửa và gửi duyệt."
			}
		} else {
			msg = fmt.Sprintf("Đã gửi duyệt phiếu thu %s (Số tiền: %s) lên cấp Quản lý / Kế toán!%s", r.Code, money(r.Amount), paperNote)
		}
		out = &Saved{ID: r.ID, Code: r.Code, Status: r.Status, Message: msg}
		return nil
	})
	if err != nil {
		return nil, err
	}
	s.log.Info("tuition receipt created", "receipt_id", out.ID, "status", out.Status, "by", actor.UserID)
	return out, nil
}

// hasIssuedPaper: phiếu tiền mặt đã được cấp số hóa đơn giấy từ dải của chi nhánh (số giữ nguyên khi sửa / duyệt).
func hasIssuedPaper(r tuitiondb.FinanceReceipt) bool {
	return r.InvoiceNumber != nil && r.PaperInvoiceNumber != nil && *r.InvoiceNumber == *r.PaperInvoiceNumber
}

// UpdateReceipt: người lập (hoặc Admin) sửa phiếu nháp / bị trả về rồi lưu nháp hoặc gửi duyệt lại.
func (s *Service) UpdateReceipt(ctx context.Context, actor *iam.Access, id uuid.UUID, in ReceiptInput) (*Saved, error) {
	if err := in.clean(); err != nil {
		return nil, err
	}
	if in.ProofFile != nil {
		if err := uploadErr(in.ProofFile.Check(ReceiptProofPolicy)); err != nil {
			return nil, err
		}
	}
	var out *Saved
	err := s.inTx(ctx, func(tx pgx.Tx, q *tuitiondb.Queries) error {
		tid, err := q.ReceiptTuitionID(ctx, id)
		if isNoRows(err) {
			return ErrReceiptNotFound
		}
		if err != nil {
			return err
		}
		if tid != nil {
			if _, err := q.LockTuition(ctx, *tid); err != nil {
				return err
			}
		}
		cur, err := q.LockReceipt(ctx, id)
		if err != nil {
			return err
		}
		if !(cur.CreatorID != nil && *cur.CreatorID == actor.UserID) && !actor.SuperAdmin {
			return ErrNotCreator
		}
		sb, err := s.resolveSubject(ctx, q, actor, cur.StudentID, cur.TuitionID)
		if err != nil {
			return err
		}
		sessionMode := in.SessionCount != nil
		if !sessionMode {
			if err := receiptAmountError(&in); err != nil {
				return err
			}
		}
		var items []ItemLine
		if in.CollectedItems != nil {
			if items, err = s.surchargeItems(ctx, q, *in.CollectedItems); err != nil {
				return err
			}
		} else {
			for _, l := range decodeItems(cur.CollectedItems) {
				if l.Source == "surcharge" {
					items = append(items, l)
				}
			}
		}
		if !sessionMode {
			if err := surchargeError(&in, items); err != nil {
				return err
			}
		}
		if hasIssuedPaper(cur) && in.PaymentMethod != "cash" {
			return invalidf("Phiếu đã được cấp số hóa đơn giấy %s nên phải giữ hình thức tiền mặt. Muốn đổi hình thức thu, hãy tạo yêu cầu hủy hóa đơn số này rồi lập phiếu mới.", *cur.InvoiceNumber)
		}
		if !editable(cur.Status) {
			return iam.Invalid("Chỉ sửa được phiếu ở trạng thái Bản nháp hoặc Bị từ chối.")
		}
		isDraft := in.draft()
		var p priced
		if sessionMode {
			p, err = s.sessionPricing(ctx, q, &in, sb, items, &cur)
		} else {
			p, err = s.amountPricing(ctx, q, &in, sb, &cur)
		}
		if err != nil {
			return err
		}
		code := cur.TransactionCode
		if in.TransactionCode != nil {
			code = optText(in.TransactionCode)
		}
		paperRange := false
		if in.PaymentMethod == "cash" {
			if paperRange = hasIssuedPaper(cur); !paperRange {
				if paperRange, err = s.branchUsesPaperRange(ctx, q, sb.branch); err != nil {
					return err
				}
			}
		}
		keepsProof := cur.ProofUrl != nil && !in.RemoveProof
		proof := in.ProofURL
		if proof == nil && keepsProof {
			proof = cur.ProofUrl
		}
		paperNo := cur.PaperInvoiceNumber
		if in.PaperInvoiceNumber != nil {
			paperNo = optText(in.PaperInvoiceNumber)
		}
		if !isDraft {
			if err := s.receiptSubmitError(ctx, q, in.PaymentMethod, code, proof != nil || in.ProofFile != nil, &cur.ID, paperNo, paperRange); err != nil {
				return err
			}
			if p.tuition != nil {
				if msg := overpaymentError(*p.tuition, p.amount-p.surcharge, p.discount); msg != "" {
					return iam.Invalid(msg)
				}
			}
		}
		// Phiếu tiền mặt chưa có số hóa đơn giấy (lập trước khi chi nhánh có dải giấy) → cấp số lúc này.
		invoice := cur.InvoiceNumber
		if paperRange && !hasIssuedPaper(cur) && cur.InvoiceNumber == nil {
			var expected *string
			if !isDraft {
				expected = in.ExpectedPaperInvoiceNumber
			}
			n, err := s.consumePaperNumber(ctx, q, *sb.branch, expected)
			if err != nil {
				return err
			}
			invoice, paperNo = &n, &n
		} else if hasIssuedPaper(cur) {
			paperNo = cur.InvoiceNumber
		}
		status, rejection := StatusPending, (*string)(nil)
		if isDraft {
			status, rejection = StatusDraft, cur.RejectionReason
		}
		tuitionID := cur.TuitionID
		if sessionMode {
			tuitionID = nil
			if p.tuition != nil {
				tuitionID = &p.tuition.ID
			}
		}
		vat := cur.IsVatInvoice
		if in.IsVatInvoice != nil {
			vat = *in.IsVatInvoice
		}
		notes := in.Notes
		if notes == nil {
			notes = cur.Notes
		}
		r, err := q.UpdateReceipt(ctx, tuitiondb.UpdateReceiptParams{
			ID: cur.ID, TuitionID: tuitionID, Amount: p.amount, TuitionAmount: p.amount - p.surcharge,
			SessionCount: p.sessionCount, SessionUnitPrice: p.unitPrice, MaterialFee: p.materialFee, ExamFee: p.examFee,
			OtherFee: p.otherFee, OtherFeeReason: p.otherFeeReason, SurchargeAmount: p.surcharge,
			SurchargeReason: surchargeReason(p.surchargeReason, items), DiscountAmount: p.discount, PromotionID: p.promotionID,
			DiscountReason: p.discountReason, PaymentMethod: in.PaymentMethod, TransactionCode: code, InvoiceNumber: invoice,
			PaperInvoiceNumber: paperNo, PayerName: firstText(in.PayerName, cur.PayerName), PayerPhone: firstText(in.PayerPhone, cur.PayerPhone),
			IsVatInvoice: vat, ProofUrl: proof, CollectedItems: encodeItems(items), Notes: notes, Status: status, RejectionReason: rejection,
		})
		if isUnique(err) {
			return uniqueTransfer(code)
		}
		if err != nil {
			return err
		}
		switch {
		case in.ProofFile != nil:
			if err := s.saveReceiptProof(ctx, tx, q, actor, r, sb.student.FullName, sb.branch, in.ProofFile); err != nil {
				return err
			}
		case cur.ProofFileID != nil && (proof == nil || *proof != files.URL(*cur.ProofFileID)):
			// Gỡ / thay minh chứng: bỏ liên kết file cũ (file còn trong kho, theo quy tắc lưu trữ của phân hệ tệp).
			if err := q.SetReceiptProofFile(ctx, tuitiondb.SetReceiptProofFileParams{ID: r.ID, ProofUrl: proof}); err != nil {
				return err
			}
		}
		if err := s.recordReceipt(ctx, tx, actor, r, sb.student.FullName, "tuition.receipt.update", "Sửa phiếu thu", sb.branch); err != nil {
			return err
		}
		paperNote := ""
		if hasIssuedPaper(r) {
			paperNote = " Số hóa đơn giấy: " + *r.InvoiceNumber + "."
		}
		msg := "Đã lưu nháp phiếu thu " + r.Code + "." + paperNote
		if !isDraft {
			if err := s.notifyReceiptPending(ctx, tx, r, sb.student.FullName); err != nil {
				return err
			}
			msg = "Đã gửi duyệt lại phiếu thu " + r.Code + "." + paperNote
		}
		out = &Saved{ID: r.ID, Code: r.Code, Status: r.Status, Message: msg}
		return nil
	})
	if err != nil {
		return nil, err
	}
	s.log.Info("tuition receipt updated", "receipt_id", id, "status", out.Status, "by", actor.UserID)
	return out, nil
}

// loadReceiptScoped: phiếu kèm dữ liệu hiển thị, kiểm phạm vi chi nhánh.
func (s *Service) loadReceiptScoped(ctx context.Context, actor *iam.Access, id uuid.UUID) (tuitiondb.GetReceiptRow, error) {
	r, err := s.q.GetReceipt(ctx, id)
	if isNoRows(err) {
		return r, ErrReceiptNotFound
	}
	if err != nil {
		return r, err
	}
	if !covers(actor, nonZero(r.ScopeBranchID)) {
		return r, ErrOutOfScope
	}
	return r, nil
}

// ApproveReceipt duyệt phiếu chờ duyệt: cấp số hóa đơn, tính lại công nợ, đối soát SePay, tính lượt ưu đãi, xuất kho.
func (s *Service) ApproveReceipt(ctx context.Context, actor *iam.Access, id uuid.UUID, confirmNotDuplicate bool) (*Saved, error) {
	info, err := s.loadReceiptScoped(ctx, actor, id)
	if err != nil {
		return nil, err
	}
	var out *Saved
	err = s.inTx(ctx, func(tx pgx.Tx, q *tuitiondb.Queries) error {
		// Khóa công nợ trước (cùng thứ tự với SePay / hoàn phí), rồi khóa phiếu.
		tid, err := q.ReceiptTuitionID(ctx, id)
		if err != nil {
			return err
		}
		var t *tuitiondb.FinanceTuition
		if tid != nil {
			x, err := q.LockTuition(ctx, *tid)
			if err != nil {
				return err
			}
			t = &x
		}
		r, err := q.LockReceipt(ctx, id)
		if err != nil {
			return err
		}
		if r.Status != StatusPending {
			return ErrAlreadyProcessed
		}
		if r.CreatorID != nil && *r.CreatorID == actor.UserID && !actor.SuperAdmin {
			return iam.Invalid("Người lập phiếu không được tự duyệt phiếu của mình. Vui lòng chuyển Kế toán/Quản lý khác duyệt.")
		}
		if msg, err := s.sepayDuplicateError(ctx, q, r, info.OwnerStudentID, confirmNotDuplicate); err != nil || msg != "" {
			if err != nil {
				return err
			}
			return iam.Invalid(msg)
		}
		if r.InvoiceNumber != nil {
			pending, err := q.PendingCancellationExists(ctx, &r.ID)
			if err != nil {
				return err
			}
			if pending {
				return invalidf("Hóa đơn %s của phiếu này đang có yêu cầu hủy chờ duyệt — xử lý yêu cầu hủy trước.", *r.InvoiceNumber)
			}
		}
		branch := nonZero(info.ScopeBranchID)
		// Phiếu theo buổi cho khóa kế tiếp (chưa có khoản học phí): duyệt thì tạo khoản học phí đúng số buổi của phiếu.
		if t == nil && val(r.SessionCount) > 0 {
			if t, err = s.createCourseTuition(ctx, q, r); err != nil {
				return err
			}
			if t != nil && t.BranchID != nil {
				branch = t.BranchID
			}
		}
		if t != nil {
			x, err := s.recalculateDebt(ctx, q, t.ID)
			if err != nil {
				return err
			}
			if msg := overpaymentError(x, r.Amount-r.SurchargeAmount, r.DiscountAmount); msg != "" {
				return iam.Invalid("Không thể duyệt: " + msg)
			}
		}
		invoice := textOf(r.InvoiceNumber)
		if invoice == "" {
			if invoice, err = s.consumeInvoiceNumber(ctx, q, branch); err != nil {
				var e *iam.Error
				if errors.As(err, &e) {
					return iam.Invalid("Không thể duyệt: " + e.Message)
				}
				return err
			}
		}
		var tuitionID *uuid.UUID
		if t != nil {
			tuitionID = &t.ID
		}
		r, err = q.ApproveReceipt(ctx, tuitiondb.ApproveReceiptParams{ID: r.ID, InvoiceNumber: &invoice, ApproverID: &actor.UserID, TuitionID: tuitionID})
		if err != nil {
			return err
		}
		if t != nil {
			if _, err := s.recalculateDebt(ctx, q, t.ID); err != nil {
				return err
			}
		}
		if err := s.linkUnappliedTransactions(ctx, q, r, info.OwnerStudentID); err != nil {
			return err
		}
		// Ưu đãi chọn khi lập phiếu tính lượt dùng lúc phiếu được duyệt.
		if r.PromotionID != nil && r.DiscountAmount > 0 {
			if err := q.IncrementPromotionUse(ctx, *r.PromotionID); err != nil {
				return err
			}
		}
		if err := s.syncStock(ctx, tx, q, r, branch, &actor.UserID); err != nil {
			return err
		}
		name := textOf(info.StudentName)
		if err := s.recordReceipt(ctx, tx, actor, r, name, "tuition.receipt.approve", "Duyệt phiếu thu, cấp hóa đơn "+invoice, branch); err != nil {
			return err
		}
		if err := s.notifyReceiptApproved(ctx, tx, actor, r, name); err != nil {
			return err
		}
		if err := s.confirmPayment(ctx, tx, r); err != nil {
			return err
		}
		msg := fmt.Sprintf("Đã phê duyệt phiếu thu %s và phát hành HĐĐT số %s!", r.Code, invoice)
		if hasIssuedPaper(r) {
			msg = fmt.Sprintf("Đã phê duyệt phiếu thu %s (hóa đơn giấy số %s)!", r.Code, invoice)
		}
		out = &Saved{ID: r.ID, Code: r.Code, Status: r.Status, Message: msg}
		return nil
	})
	if err != nil {
		return nil, err
	}
	s.log.Info("tuition receipt approved", "receipt_id", id, "by", actor.UserID)
	return out, nil
}

// sepayDuplicateError: chống ghi nhận chuyển khoản 2 lần (phiếu tay + SePay tự động).
func (s *Service) sepayDuplicateError(ctx context.Context, q *tuitiondb.Queries, r tuitiondb.FinanceReceipt, student *uuid.UUID, confirmed bool) (string, error) {
	if !isTransfer(r.PaymentMethod) {
		return "", nil
	}
	if ref := normalizeReference(r.TransactionCode); ref != "" {
		tx, err := q.AppliedBankTransaction(ctx, tuitiondb.AppliedBankTransactionParams{Reference: ref, IgnoreID: &r.ID})
		if err == nil {
			via := ""
			if tx.ReceiptCode != nil {
				via = " (phiếu " + *tx.ReceiptCode + ")"
			}
			return "Không thể duyệt: mã giao dịch " + textOf(r.TransactionCode) + " đã được SePay tự động gạch nợ" + via + ". Vui lòng từ chối phiếu thu tay này.", nil
		}
		if !isNoRows(err) {
			return "", err
		}
	}
	if confirmed {
		return "", nil
	}
	similar, err := s.similarTransactions(ctx, q, r, student)
	if err != nil || len(similar) == 0 {
		return "", err
	}
	x := similar[0]
	via := ""
	if x.ReceiptCode != nil {
		via = " (phiếu " + *x.ReceiptCode + ")"
	}
	return fmt.Sprintf("Cảnh báo trùng giao dịch: SePay đã tự động ghi nhận %s cho học viên này ngày %s%s. Nếu chắc chắn đây là khoản chuyển khác, hãy tick \"Xác nhận không trùng giao dịch SePay\" rồi duyệt lại.",
		money(x.TransferAmount), textOf(fmtDateTime(x.TransactionDate, "02/01/2006 15:04")), via), nil
}

// similarTransactions: giao dịch SePay đã gạch nợ cho cùng học viên / khoản, cùng số tiền, trong ±3 ngày quanh ngày nộp.
func (s *Service) similarTransactions(ctx context.Context, q *tuitiondb.Queries, r tuitiondb.FinanceReceipt, student *uuid.UUID) ([]tuitiondb.SimilarBankTransactionsRow, error) {
	if !isTransfer(r.PaymentMethod) || (r.TuitionID == nil && student == nil) {
		return nil, nil
	}
	const window = 3
	day := dateOf(r.CreatedAt)
	if r.PaymentDate != nil {
		day = *r.PaymentDate
	}
	from := dayStart(day).AddDate(0, 0, -window)
	to := dayStart(day).AddDate(0, 0, window+1).Add(-time.Second)
	rows, err := q.SimilarBankTransactions(ctx, tuitiondb.SimilarBankTransactionsParams{
		ReceiptID: r.ID, TuitionID: r.TuitionID, StudentID: student, FromTime: from, ToTime: to,
	})
	if err != nil {
		return nil, err
	}
	out := rows[:0]
	for _, x := range rows {
		if abs(x.TransferAmount-r.Amount) < 1 || (x.ReceiptAmount != nil && abs(*x.ReceiptAmount-r.Amount) < 1) {
			out = append(out, x)
		}
	}
	return out, nil
}

// linkUnappliedTransactions: giao dịch SePay cùng mã chưa gạch nợ → đã đối soát bằng phiếu tay này.
func (s *Service) linkUnappliedTransactions(ctx context.Context, q *tuitiondb.Queries, r tuitiondb.FinanceReceipt, student *uuid.UUID) error {
	ref := normalizeReference(r.TransactionCode)
	if ref == "" || !isTransfer(r.PaymentMethod) {
		return nil
	}
	if student == nil {
		student = r.StudentID
	}
	return q.LinkUnappliedBankTransactions(ctx, tuitiondb.LinkUnappliedBankTransactionsParams{
		ReceiptID: &r.ID, TuitionID: r.TuitionID, StudentID: student, Reference: ref,
		Message: ptr("Đã đối soát thủ công bằng phiếu thu " + r.Code + "."),
	})
}

// RejectReceipt trả phiếu chờ duyệt về người lập kèm lý do.
func (s *Service) RejectReceipt(ctx context.Context, actor *iam.Access, id uuid.UUID, reason *string) (*Saved, error) {
	info, err := s.loadReceiptScoped(ctx, actor, id)
	if err != nil {
		return nil, err
	}
	reason = optText(reason)
	if tooLong(reason, 1000) {
		return nil, iam.Invalid("Lý do từ chối tối đa 1000 ký tự.")
	}
	if reason == nil {
		reason = ptr("Từ chối bởi cấp quản lý / kiểm soát")
	}
	var out *Saved
	err = s.inTx(ctx, func(tx pgx.Tx, q *tuitiondb.Queries) error {
		r, err := q.LockReceipt(ctx, id)
		if err != nil {
			return err
		}
		if r.Status != StatusPending {
			return ErrAlreadyProcessed
		}
		r, err = q.RejectReceipt(ctx, tuitiondb.RejectReceiptParams{ID: id, ApproverID: &actor.UserID, RejectionReason: reason})
		if err != nil {
			return err
		}
		if err := s.recordReceipt(ctx, tx, actor, r, textOf(info.StudentName), "tuition.receipt.reject", "Từ chối phiếu thu: "+*reason, nonZero(info.ScopeBranchID)); err != nil {
			return err
		}
		if err := s.notifyReceiptRejected(ctx, tx, actor, r); err != nil {
			return err
		}
		out = &Saved{ID: r.ID, Code: r.Code, Status: r.Status,
			Message: "Đã từ chối phiếu thu " + r.Code + " và trả về người lập để chỉnh sửa, gửi duyệt lại!"}
		return nil
	})
	if err != nil {
		return nil, err
	}
	s.log.Info("tuition receipt rejected", "receipt_id", id, "by", actor.UserID)
	return out, nil
}

// depositCutoff: hạn nộp tiền mặt về tài khoản công ty (SLA tuition.cash_deposit, mặc định 19:00), phút trong ngày.
func (s *Service) depositCutoff(ctx context.Context) int {
	if s.cat != nil {
		if r, err := s.cat.SlaRule(ctx, "tuition.cash_deposit"); err == nil && r.Value > 0 {
			return int(r.Value)
		}
	}
	return 19 * 60
}

func requiresDeposit(r tuitiondb.FinanceReceipt) bool {
	return r.PaymentMethod == "cash" && holdsReference(r.Status) && r.Amount > 0
}

func depositDeadline(r tuitiondb.FinanceReceipt, cutoff int) *time.Time {
	if r.PaymentDate == nil {
		return nil
	}
	d := dayStart(*r.PaymentDate).Add(time.Duration(cutoff) * time.Minute)
	return &d
}

// depositState: null (không áp dụng) | pending | on_time | late.
func depositState(r tuitiondb.FinanceReceipt, cutoff int, now time.Time) *string {
	if !requiresDeposit(r) {
		return nil
	}
	if r.DepositedAt == nil {
		return ptr("pending")
	}
	if d := depositDeadline(r, cutoff); d != nil && r.DepositedAt.After(*d) {
		return ptr("late")
	}
	return ptr("on_time")
}

func cutoffLabel(m int) string { return fmt.Sprintf("%02d:%02d", m/60, m%60) }

// ConfirmDeposit: kế toán xác nhận tiền mặt của phiếu đã nộp về TK công ty.
func (s *Service) ConfirmDeposit(ctx context.Context, actor *iam.Access, id uuid.UUID) (*Saved, error) {
	info, err := s.loadReceiptScoped(ctx, actor, id)
	if err != nil {
		return nil, err
	}
	r := receiptOf(info)
	if !requiresDeposit(r) {
		return nil, iam.Invalid("Phiếu này không thuộc diện phải nộp tiền mặt về tài khoản công ty.")
	}
	if r.DepositedAt != nil {
		return nil, iam.Invalid("Phiếu này đã được xác nhận nộp trước đó.")
	}
	cutoff := s.depositCutoff(ctx)
	var out *Saved
	err = s.inTx(ctx, func(tx pgx.Tx, q *tuitiondb.Queries) error {
		r, err := q.ConfirmDeposit(ctx, tuitiondb.ConfirmDepositParams{ID: id, DepositedBy: &actor.UserID})
		if isNoRows(err) {
			return iam.Invalid("Phiếu này đã được xác nhận nộp trước đó.")
		}
		if err != nil {
			return err
		}
		if err := s.recordReceipt(ctx, tx, actor, r, textOf(info.StudentName), "tuition.receipt.confirm_deposit", "Xác nhận nộp tiền mặt về TK công ty", nonZero(info.ScopeBranchID)); err != nil {
			return err
		}
		msg := "Đã xác nhận nộp về TK công ty."
		if st := depositState(r, cutoff, s.now()); st != nil && *st == "late" {
			msg = "Đã xác nhận nộp về TK công ty (nộp trễ sau " + cutoffLabel(cutoff) + ")."
		}
		out = &Saved{ID: r.ID, Code: r.Code, Status: r.Status, Message: msg}
		return nil
	})
	if err != nil {
		return nil, err
	}
	s.log.Info("tuition cash deposit confirmed", "receipt_id", id, "by", actor.UserID)
	return out, nil
}

// receiptOf: phần bảng finance.receipts của dòng GetReceipt.
func receiptOf(r tuitiondb.GetReceiptRow) tuitiondb.FinanceReceipt {
	return tuitiondb.FinanceReceipt{
		ID: r.ID, Code: r.Code, Source: r.Source, InvoiceNumber: r.InvoiceNumber, TuitionID: r.TuitionID, StudentID: r.StudentID,
		Amount: r.Amount, TuitionAmount: r.TuitionAmount, SessionCount: r.SessionCount, SessionUnitPrice: r.SessionUnitPrice,
		MaterialFee: r.MaterialFee, ExamFee: r.ExamFee, OtherFee: r.OtherFee, OtherFeeReason: r.OtherFeeReason,
		SurchargeAmount: r.SurchargeAmount, SurchargeReason: r.SurchargeReason, DiscountAmount: r.DiscountAmount,
		PromotionID: r.PromotionID, DiscountReason: r.DiscountReason, PaymentMethod: r.PaymentMethod,
		TransactionCode: r.TransactionCode, TransferReference: r.TransferReference, PayerName: r.PayerName,
		PayerPhone: r.PayerPhone, IsVatInvoice: r.IsVatInvoice, PaperInvoiceNumber: r.PaperInvoiceNumber, ProofUrl: r.ProofUrl,
		ProofFileID: r.ProofFileID, PaymentDate: r.PaymentDate, CollectedItems: r.CollectedItems, Status: r.Status,
		Notes: r.Notes, RejectionReason: r.RejectionReason, CreatorID: r.CreatorID, ApproverID: r.ApproverID,
		ApprovedAt: r.ApprovedAt, DepositedAt: r.DepositedAt, DepositedBy: r.DepositedBy, LegacyID: r.LegacyID,
		CreatedAt: r.CreatedAt, UpdatedAt: r.UpdatedAt,
	}
}
