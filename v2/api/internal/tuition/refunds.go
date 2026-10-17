package tuition

import (
	"context"
	"fmt"
	"math"
	"slices"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/files"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/filestore"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/students"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/tuition/tuitiondb"
)

// Hoàn tiền & Khất nợ (TuitionController::refunds / storeRefundRequest / approveRefundRequest / rejectRefundRequest):
// hoàn phí, chuyển nhượng số dư sang học viên khác, khất nợ (dời hạn đóng), bảo lưu (đóng băng số buổi / công nợ).

const (
	refundProofSubject = "refund_proof"
	// refundAdminFeePercent: phí quản trị khi hoàn phí (config tuition.refund_admin_fee_percent, mặc định 10%).
	refundAdminFeePercent = 10.0
	// clawbackSuggestMonths: học chưa tới 1 tháng (hoặc chưa học) → gợi ý thu hồi hoa hồng.
	clawbackSuggestMonths = 1
)

// RefundProofPolicy: ảnh bằng chứng chi tiền (REFUND_PROOF_EXTENSIONS, tối đa 10MB).
var RefundProofPolicy = filestore.Policy{Types: []string{"jpg", "jpeg", "png", "webp"}, MaxBytes: 10 << 20,
	TooLarge: "Ảnh bằng chứng tối đa 10MB.", Required: "Hoàn tiền bắt buộc đính kèm ảnh bằng chứng (ủy nhiệm chi / biên nhận)."}

var refundTypes = []Option{{"refund", "Hoàn phí"}, {"transfer", "Chuyển nhượng"}, {"extension", "Khất nợ"}, {"deferral", "Bảo lưu"}}

func validRefundType(t string) bool {
	return slices.ContainsFunc(refundTypes, func(o Option) bool { return o.Value == t })
}

func hasProcessingDeadline(t string) bool { return t == "refund" || t == "transfer" }

// refundRef: mã ngắn của hồ sơ trong ghi chú / thông báo (Laravel: "#<id>").
func refundRef(id uuid.UUID) string { return "#" + strings.ToUpper(id.String()[len(id.String())-6:]) }

type RefundPerson struct {
	Name      string  `json:"name"`
	Code      string  `json:"code"`
	ClassName *string `json:"class_name"`
}

type RefundRow struct {
	ID                 uuid.UUID     `json:"id"`
	Ref                string        `json:"ref"`
	Type               string        `json:"type"`
	TypeLabel          string        `json:"type_label"`
	Status             string        `json:"status"`
	Student            *RefundPerson `json:"student"`
	Target             *RefundPerson `json:"target"`
	RefundAmount       int64         `json:"refund_amount"`
	DeferFrom          *string       `json:"defer_from"`
	DeferTo            *string       `json:"defer_to"`
	ExtendedDueDate    *string       `json:"extended_due_date"`
	ProcessingDeadline *string       `json:"processing_deadline"`
	Overdue            bool          `json:"overdue"`
	Reason             string        `json:"reason"`
	NoTransferReason   *string       `json:"no_transfer_reason"`
	RejectionReason    *string       `json:"rejection_reason"`
	CreatedDate        string        `json:"created_date"`
	CreatedAt          string        `json:"created_at"`
	RequesterName      *string       `json:"requester_name"`
	ApproverName       *string       `json:"approver_name"`
	ClawbackCommission *bool         `json:"clawback_commission"`
	ClawbackAmount     int64         `json:"clawback_amount"`
	ClawbackUserName   *string       `json:"clawback_user_name"`
	HasProof           bool          `json:"has_proof"`
	// ProofURL: ảnh minh chứng ở kho file (/api/v1/files/<id>, kiểm quyền khi mở)
	ProofURL          *string `json:"proof_url"`
	OverdueDays       int     `json:"overdue_days,omitempty"`
	ApprovePermission string  `json:"approve_permission,omitempty"`
}

// RefundBasis: căn cứ tính hoàn phí từ hợp đồng thật (refundBasis).
type RefundBasis struct {
	HasTuition        bool    `json:"has_tuition"`
	Paid              int64   `json:"paid"`
	Contract          int64   `json:"contract"`
	Debt              int64   `json:"debt"`
	DueDate           *string `json:"due_date"`
	TotalSessions     *int    `json:"total_sessions"`
	AttendedSessions  int     `json:"attended_sessions"`
	RemainingSessions *int    `json:"remaining_sessions"`
	UnitPrice         float64 `json:"unit_price"`
	RemainingValue    int64   `json:"remaining_value"`
	AdminFee          int64   `json:"admin_fee"`
	SuggestedRefund   int64   `json:"suggested_refund"`
	StatusLabel       string  `json:"status_label"`
}

type ClawbackHint struct {
	Suggest bool    `json:"suggest"`
	Amount  int64   `json:"amount"`
	Owner   *string `json:"owner"`
	Start   *string `json:"start"`
}

type RefundTarget struct {
	ID    uuid.UUID `json:"id"`
	Name  string    `json:"name"`
	Code  string    `json:"code"`
	Class *string   `json:"class"`
	Debt  int64     `json:"debt"`
}

type RefundFilter struct{ Status, Type, Search string }

type RefundsPage struct {
	PendingRequests []RefundRow             `json:"pending_requests"`
	OverdueCount    int                     `json:"overdue_count"`
	HistoryRequests []RefundRow             `json:"history_requests"`
	TotalCount      int                     `json:"total_count"`
	Filters         map[string]string       `json:"filters"`
	TypeOptions     []Option                `json:"type_options"`
	ApprovableTypes []string                `json:"approvable_types"`
	CanRejectAny    bool                    `json:"can_reject_any"`
	ClawbackHints   map[string]ClawbackHint `json:"clawback_hints"`
	StudentFinance  map[string]RefundBasis  `json:"student_finance"`
	AdminFeePercent float64                 `json:"admin_fee_percent"`
	Targets         []RefundTarget          `json:"targets"`
	SourceOptions   []Option                `json:"source_options"`
	DebtorOptions   []Option                `json:"debtor_options"`
	Deadline        string                  `json:"deadline"`
	CurrentMonth    string                  `json:"current_month"`
	Today           string                  `json:"today"`
	Tomorrow        string                  `json:"tomorrow"`
}

func refundBasis(r tuitiondb.RefundStudentsRow) RefundBasis {
	b := RefundBasis{HasTuition: r.HasTuition, Paid: r.PaidAmount, Contract: r.FinalAmount, Debt: r.DebtAmount, StatusLabel: students.StatusLabel(r.Status)}
	if r.DueDate != nil {
		b.DueDate = ptr(r.DueDate.Format(time.DateOnly))
	}
	if !r.HasTuition {
		return b
	}
	if total, attended, ok := sessionStats(r.CourseLessons, r.ScheduledSessions, r.AttendedSessions, r.StatsClassID != nil); ok {
		remaining := max(0, total-attended)
		if r.FrozenRemainingSessions != nil {
			remaining = int(*r.FrozenRemainingSessions)
		}
		b.TotalSessions, b.AttendedSessions, b.RemainingSessions = &total, attended, &remaining
		b.UnitPrice = round2(float64(r.FinalAmount) / float64(total))
	}
	used := math.Min(float64(b.Paid), round2(b.UnitPrice*float64(b.AttendedSessions)))
	remainingValue := math.Max(0, round2(float64(b.Paid)-used))
	b.RemainingValue = int64(math.Round(remainingValue))
	b.AdminFee = int64(math.Round(remainingValue * refundAdminFeePercent / 100))
	b.SuggestedRefund = max(0, b.RemainingValue-b.AdminFee)
	return b
}

func person(name, code *string, class *string) *RefundPerson {
	if name == nil {
		return nil
	}
	return &RefundPerson{Name: *name, Code: textOf(code), ClassName: class}
}

func (s *Service) refundRow(r tuitiondb.ListRefundRequestsRow, days int, now time.Time) RefundRow {
	row := RefundRow{
		ID: r.ID, Ref: refundRef(r.ID), Type: r.Type, TypeLabel: refundTypeLabel(r.Type), Status: r.Status,
		Student: &RefundPerson{Name: r.StudentName, Code: r.StudentCode, ClassName: r.StudentClassName},
		Target:  person(r.TargetName, r.TargetCode, r.TargetClassName), RefundAmount: r.RefundAmount,
		DeferFrom: fmtDate(r.DeferFrom), DeferTo: fmtDate(r.DeferTo), ExtendedDueDate: fmtDate(r.ExtendedDueDate),
		Reason: r.Reason, NoTransferReason: r.NoTransferReason, RejectionReason: r.RejectionReason,
		CreatedDate: r.CreatedAt.In(vnZone).Format("02/01/2006"), CreatedAt: r.CreatedAt.In(vnZone).Format("02/01/2006 15:04"),
		RequesterName: r.RequesterName, ApproverName: r.ApproverName, ClawbackCommission: r.ClawbackCommission,
		ClawbackAmount: val(r.ClawbackAmount), ClawbackUserName: r.ClawbackUserName,
		HasProof: r.ProofFileID != nil || r.ProofUrl != nil,
	}
	if r.ProofFileID != nil {
		row.ProofURL = ptr(files.URL(*r.ProofFileID))
	} else {
		row.ProofURL = r.ProofUrl
	}
	if hasProcessingDeadline(r.Type) {
		d := refundDeadline(r.CreatedAt, days)
		row.ProcessingDeadline = ptr(d.Format("02/01/2006"))
		row.Overdue = r.Status == "pending" && now.After(d)
		if row.Overdue {
			row.OverdueDays = max(1, daysBetween(dayStart(d), dateOf(now)))
		}
	}
	return row
}

// Refunds: màn Hoàn tiền & Khất nợ.
func (s *Service) Refunds(ctx context.Context, actor *iam.Access, f RefundFilter) (*RefundsPage, error) {
	limit := branchLimit(actor)
	rows, err := s.q.ListRefundRequests(ctx, limit)
	if err != nil {
		return nil, err
	}
	days, now, today := s.refundProcessingDays(ctx), s.now(), s.today()
	f.Search = strings.TrimSpace(f.Search)
	if !slices.Contains([]string{"pending", "approved", "rejected", "overdue"}, f.Status) {
		f.Status = ""
	}
	if !validRefundType(f.Type) {
		f.Type = ""
	}
	out := &RefundsPage{PendingRequests: []RefundRow{}, HistoryRequests: []RefundRow{}, TotalCount: len(rows),
		Filters: map[string]string{"status": f.Status, "type": f.Type, "search": f.Search}, TypeOptions: refundTypes,
		ApprovableTypes: []string{}, CanRejectAny: actor.Can("refund_transfer.reject"), ClawbackHints: map[string]ClawbackHint{},
		StudentFinance: map[string]RefundBasis{}, AdminFeePercent: refundAdminFeePercent, Targets: []RefundTarget{},
		SourceOptions: []Option{}, DebtorOptions: []Option{},
		Deadline: refundDeadline(now, days).Format("02/01/2006"), CurrentMonth: s.nowVN().Format("01/2006"),
		Today: today.Format(time.DateOnly), Tomorrow: today.AddDate(0, 0, 1).Format(time.DateOnly),
	}
	for _, o := range refundTypes {
		if actor.Can(refundApprovePermission(o.Value)) {
			out.ApprovableTypes = append(out.ApprovableTypes, o.Value)
		}
	}
	needle := strings.ToLower(f.Search)
	var pendingRefunds []tuitiondb.ListRefundRequestsRow
	for _, r := range rows {
		row := s.refundRow(r, days, now)
		if r.Status == "pending" {
			p := row
			p.ApprovePermission = refundApprovePermission(r.Type)
			out.PendingRequests = append(out.PendingRequests, p)
			if row.Overdue {
				out.OverdueCount++
			}
			if r.Type == "refund" {
				pendingRefunds = append(pendingRefunds, r)
			}
		}
		row.OverdueDays = 0
		switch {
		case f.Status == "overdue" && !row.Overdue:
			continue
		case f.Status != "" && f.Status != "overdue" && r.Status != f.Status:
			continue
		case f.Type != "" && r.Type != f.Type:
			continue
		}
		if needle != "" {
			hay := strings.ToLower(r.StudentName + " " + r.StudentCode + " " + textOf(r.TargetName) + " " + textOf(r.TargetCode))
			if !strings.Contains(hay, needle) {
				continue
			}
		}
		out.HistoryRequests = append(out.HistoryRequests, row)
	}
	// Chờ duyệt: quá hạn xử lý lên đầu, rồi theo hạn gần nhất.
	slices.SortStableFunc(out.PendingRequests, func(a, b RefundRow) int {
		if a.Overdue != b.Overdue {
			if a.Overdue {
				return -1
			}
			return 1
		}
		da, db := deadlineKey(a.ProcessingDeadline), deadlineKey(b.ProcessingDeadline)
		return strings.Compare(da, db)
	})
	if err := s.clawbackHints(ctx, pendingRefunds, out.ClawbackHints); err != nil {
		return nil, err
	}
	sts, err := s.q.RefundStudents(ctx, tuitiondb.RefundStudentsParams{BranchIds: limit})
	if err != nil {
		return nil, err
	}
	for _, st := range sts {
		b := refundBasis(st)
		out.StudentFinance[st.ID.String()] = b
		out.Targets = append(out.Targets, RefundTarget{ID: st.ID, Name: st.FullName, Code: st.Code, Class: st.ClassName, Debt: st.DebtAmount})
		label := st.FullName + " · " + st.Code
		if b.HasTuition {
			class := "Chưa gán lớp"
			if st.ClassName != nil {
				class = *st.ClassName
			}
			out.SourceOptions = append(out.SourceOptions, Option{st.ID.String(), label + " (" + class + ")"})
		}
		if st.DebtAmount > 0 {
			due := "chưa đặt"
			if st.DueDate != nil {
				due = st.DueDate.Format("02/01/2006")
			}
			out.DebtorOptions = append(out.DebtorOptions, Option{st.ID.String(), label + " · Còn nợ " + money(st.DebtAmount) + " · Hạn " + due})
		}
	}
	return out, nil
}

// deadlineKey: hạn dd/mm/yyyy → yyyymmdd để so sánh (không có hạn xếp cuối).
func deadlineKey(d *string) string {
	if d == nil || len(*d) != 10 {
		return "99999999"
	}
	v := *d
	return v[6:] + v[3:5] + v[:2]
}

// clawbackHints: gợi ý thu hồi hoa hồng cho hồ sơ hoàn phí đang chờ duyệt.
func (s *Service) clawbackHints(ctx context.Context, refunds []tuitiondb.ListRefundRequestsRow, out map[string]ClawbackHint) error {
	if len(refunds) == 0 {
		return nil
	}
	ids := make([]uuid.UUID, 0, len(refunds))
	for _, r := range refunds {
		ids = append(ids, r.StudentID)
	}
	starts, err := s.studyStarts(ctx, s.q, ids)
	if err != nil {
		return err
	}
	for _, r := range refunds {
		h := ClawbackHint{Suggest: s.suggestClawback(starts[r.StudentID])}
		if d := starts[r.StudentID]; d != nil {
			h.Start = ptr(d.Format("02/01/2006"))
		}
		owner, err := s.q.CommissionOwner(ctx, &r.StudentID)
		if err != nil && !isNoRows(err) {
			return err
		}
		if err == nil {
			if n, err := s.q.UserFullName(ctx, owner); err == nil {
				h.Owner = &n
			}
			if h.Amount, err = s.suggestedClawback(ctx, r.StudentID, owner, r.RefundAmount); err != nil {
				return err
			}
		}
		out[r.ID.String()] = h
	}
	return nil
}

func (s *Service) studyStarts(ctx context.Context, q *tuitiondb.Queries, ids []uuid.UUID) (map[uuid.UUID]*time.Time, error) {
	rows, err := q.StudyStartDates(ctx, ids)
	if err != nil {
		return nil, err
	}
	out := map[uuid.UUID]*time.Time{}
	for _, r := range rows {
		d := r.StartDate
		out[r.StudentID] = &d
	}
	return out, nil
}

// suggestClawback: chưa học hoặc bắt đầu học chưa tới 1 tháng → gợi ý thu hồi.
func (s *Service) suggestClawback(start *time.Time) bool {
	return start == nil || start.After(s.today().AddDate(0, -clawbackSuggestMonths, 0))
}

func (s *Service) suggestedClawback(ctx context.Context, student, owner uuid.UUID, amount int64) (int64, error) {
	if s.commissions == nil {
		return 0, nil
	}
	return s.commissions.SuggestedClawback(ctx, student, owner, amount)
}

// ───────────── Lập hồ sơ ─────────────

type RefundInput struct {
	StudentID        *uuid.UUID `json:"student_id"`
	Type             string     `json:"type"`
	TotalPaid        *int64     `json:"total_paid"`
	AttendedLessons  *int32     `json:"attended_lessons"`
	AdminFee         *int64     `json:"admin_fee"`
	RefundAmount     *int64     `json:"refund_amount"`
	TargetStudentID  *uuid.UUID `json:"target_student_id"`
	ExtendedDueDate  *string    `json:"extended_due_date"`
	DeferFrom        *string    `json:"defer_from"`
	DeferTo          *string    `json:"defer_to"`
	Reason           *string    `json:"reason"`
	NoTransferReason *string    `json:"no_transfer_reason"`
}

func optDate(v *string, invalid string) (*time.Time, error) {
	t := optText(v)
	if t == nil {
		return nil, nil
	}
	d, ok := parseDate(*t)
	if !ok {
		return nil, iam.Invalid(invalid)
	}
	return &d, nil
}

func (in *RefundInput) validate(today time.Time) (ext, from, to *time.Time, err error) {
	in.Reason, in.NoTransferReason = optText(in.Reason), optText(in.NoTransferReason)
	switch {
	case in.StudentID == nil:
		return nil, nil, nil, iam.Invalid("Vui lòng chọn học viên.")
	case !validRefundType(in.Type):
		return nil, nil, nil, iam.Invalid("Loại yêu cầu không hợp lệ.")
	case in.TotalPaid != nil && *in.TotalPaid < 0, in.AdminFee != nil && *in.AdminFee < 0, in.AttendedLessons != nil && *in.AttendedLessons < 0:
		return nil, nil, nil, iam.Invalid("Số liệu không được âm.")
	case hasProcessingDeadline(in.Type) && in.RefundAmount == nil:
		return nil, nil, nil, iam.Invalid("Vui lòng nhập số tiền hoàn / chuyển nhượng.")
	case in.RefundAmount != nil && *in.RefundAmount < 0:
		return nil, nil, nil, iam.Invalid("Số tiền hoàn / chuyển nhượng không được âm.")
	case in.Type == "transfer" && in.TargetStudentID == nil:
		return nil, nil, nil, iam.Invalid("Vui lòng chọn học viên nhận chuyển nhượng.")
	case in.TargetStudentID != nil && *in.TargetStudentID == *in.StudentID:
		return nil, nil, nil, iam.Invalid("Học viên nhận phải khác học viên chuyển nhượng.")
	}
	if ext, err = optDate(in.ExtendedDueDate, "Hạn đóng mới không hợp lệ."); err != nil {
		return
	}
	if from, err = optDate(in.DeferFrom, "Ngày bắt đầu bảo lưu không hợp lệ."); err != nil {
		return
	}
	if to, err = optDate(in.DeferTo, "Ngày kết thúc bảo lưu không hợp lệ."); err != nil {
		return
	}
	switch {
	case in.Type == "extension" && ext == nil:
		err = iam.Invalid("Khất nợ cần chọn hạn đóng mới.")
	case ext != nil && !ext.After(today):
		err = iam.Invalid("Hạn đóng mới phải sau hôm nay.")
	case in.Type == "deferral" && from == nil:
		err = iam.Invalid("Bảo lưu cần chọn ngày bắt đầu.")
	case in.Type == "deferral" && to == nil:
		err = iam.Invalid("Bảo lưu cần chọn ngày kết thúc.")
	case from != nil && to != nil && !to.After(*from):
		err = iam.Invalid("Ngày kết thúc bảo lưu phải sau ngày bắt đầu.")
	case in.Reason == nil:
		err = iam.Invalid("Vui lòng nhập lý do.")
	case tooLong(in.Reason, 1000):
		err = iam.Invalid("Lý do tối đa 1000 ký tự.")
	case in.Type == "refund" && in.NoTransferReason == nil:
		err = iam.Invalid("Hoàn tiền là phương án cuối: vui lòng ghi rõ lý do không chuyển nhượng buổi dư cho học viên khác.")
	case in.NoTransferReason != nil && utf8.RuneCountInString(*in.NoTransferReason) < 5:
		err = iam.Invalid("Lý do không chuyển nhượng quá ngắn.")
	case tooLong(in.NoTransferReason, 1000):
		err = iam.Invalid("Lý do không chuyển nhượng tối đa 1000 ký tự.")
	}
	return
}

// CreateRefund: lập hồ sơ (refund_transfer.request); hồ sơ chờ người có quyền duyệt theo loại.
func (s *Service) CreateRefund(ctx context.Context, actor *iam.Access, in RefundInput) (*Saved, error) {
	ext, from, to, err := in.validate(s.today())
	if err != nil {
		return nil, err
	}
	st, err := s.q.StudentScope(ctx, *in.StudentID)
	if isNoRows(err) {
		return nil, iam.Invalid("Học viên đã chọn không tồn tại.")
	}
	if err != nil {
		return nil, err
	}
	if in.TargetStudentID != nil {
		if _, err := s.q.StudentScope(ctx, *in.TargetStudentID); isNoRows(err) {
			return nil, iam.Invalid("Học viên nhận đã chọn không tồn tại.")
		} else if err != nil {
			return nil, err
		}
	}
	if !covers(actor, nonZero(st.ScopeBranchID)) {
		return nil, forbidden(outOfScope)
	}
	basis := RefundBasis{}
	var tuitionID *uuid.UUID
	if bs, err := s.q.RefundStudents(ctx, tuitiondb.RefundStudentsParams{StudentID: &st.ID}); err != nil {
		return nil, err
	} else if len(bs) == 1 {
		basis = refundBasis(bs[0])
	}
	latest, err := s.q.LatestStudentTuition(ctx, st.ID)
	switch {
	case err == nil:
		tuitionID = &latest.ID
	case !isNoRows(err):
		return nil, err
	}
	if (in.Type == "extension" || in.Type == "deferral") && tuitionID == nil {
		return nil, iam.Invalid("Học viên chưa có hồ sơ học phí để khất nợ / bảo lưu.")
	}
	if in.Type == "extension" && latest.DueDate != nil && !ext.After(*latest.DueDate) {
		return nil, iam.Invalid("Hạn mới phải sau hạn đóng hiện tại (" + latest.DueDate.Format("02/01/2006") + ").")
	}
	p := tuitiondb.CreateRefundRequestParams{StudentID: st.ID, TuitionID: tuitionID, Type: in.Type, Reason: *in.Reason, RequesterID: &actor.UserID}
	switch {
	case in.TotalPaid != nil:
		p.TotalPaid = *in.TotalPaid
	case tuitionID != nil:
		p.TotalPaid = latest.PaidAmount
	case in.RefundAmount != nil:
		p.TotalPaid = *in.RefundAmount
	}
	p.AttendedLessons = int32(basis.AttendedSessions)
	if in.AttendedLessons != nil {
		p.AttendedLessons = *in.AttendedLessons
	}
	p.AdminFee = val(in.AdminFee)
	if hasProcessingDeadline(in.Type) {
		p.RefundAmount = *in.RefundAmount
	}
	switch in.Type {
	case "extension":
		p.ExtendedDueDate = ext
	case "deferral":
		p.DeferFrom, p.DeferTo = from, to
	case "transfer":
		p.TargetStudentID = in.TargetStudentID
	case "refund":
		p.NoTransferReason = in.NoTransferReason
	}
	var saved tuitiondb.FinanceRefundRequest
	err = s.inTx(ctx, func(tx pgx.Tx, q *tuitiondb.Queries) error {
		if saved, err = q.CreateRefundRequest(ctx, p); err != nil {
			return err
		}
		return s.recordRefund(ctx, tx, actor, saved, st.FullName, nonZero(st.ScopeBranchID), audit.EventCreated, "tuition.refund_request.create",
			"Lập hồ sơ "+strings.ToLower(refundTypeLabel(in.Type))+" cho học viên "+st.FullName)
	})
	if err != nil {
		return nil, err
	}
	label := map[string]string{"transfer": "chuyển nhượng số dư học phí sang học viên khác", "extension": "khất nợ (dời hạn đóng)",
		"deferral": "bảo lưu học phí"}[in.Type]
	if label == "" {
		label = "hoàn trả học phí"
	}
	approver := "cấp Quản lý/Kế toán"
	if in.Type == "refund" {
		approver = "Admin"
	}
	note := ""
	if hasProcessingDeadline(in.Type) {
		note = " Hạn xử lý: " + refundDeadline(s.now(), s.refundProcessingDays(ctx)).Format("02/01/2006") + "."
	}
	s.log.Info("refund request created", "refund_id", saved.ID, "type", in.Type, "by", actor.UserID)
	return &Saved{ID: saved.ID, Status: saved.Status, Message: "Đã lập hồ sơ " + label + " và gửi lên " + approver + " phê duyệt!" + note}, nil
}

func (s *Service) recordRefund(ctx context.Context, tx pgx.Tx, actor *iam.Access, r tuitiondb.FinanceRefundRequest, student string, branch *uuid.UUID,
	event, action, desc string) error {
	return audit.Record(ctx, tx, audit.Entry{
		ActorID: &actor.UserID, Module: audit.ModuleTuition, Event: event, Action: action,
		SubjectType: "tuition_refund_request", SubjectID: r.ID.String(), SubjectLabel: refundTypeLabel(r.Type) + " " + refundRef(r.ID) + " (" + student + ")",
		BranchID: branch, Description: desc + ".",
		Properties: map[string]any{"type": r.Type, "status": r.Status, "refund_amount": r.RefundAmount, "student_id": r.StudentID,
			"target_student_id": r.TargetStudentID},
	})
}

// ───────────── Duyệt / từ chối ─────────────

type RefundDecision struct {
	ClawbackCommission *bool  `json:"clawback_commission"`
	ClawbackAmount     *int64 `json:"clawback_amount"`
}

func (s *Service) refundScoped(ctx context.Context, actor *iam.Access, id uuid.UUID) (tuitiondb.RefundScopeRow, error) {
	r, err := s.q.RefundScope(ctx, id)
	if isNoRows(err) {
		return r, &iam.Error{Status: 404, Code: "not_found", Message: "Không tìm thấy hồ sơ."}
	}
	if err != nil {
		return r, err
	}
	if !covers(actor, nonZero(r.ScopeBranchID)) {
		return r, forbidden(outOfScope)
	}
	return r, nil
}

var errRefundProcessed = iam.Invalid("Yêu cầu này đã được xử lý trước đó.")

// ApproveRefund: duyệt theo quyền của loại hồ sơ. Hoàn tiền bắt buộc ảnh bằng chứng (proof đã qua RefundProofPolicy);
// quá hạn xử lý chỉ gắn cờ, không chặn.
func (s *Service) ApproveRefund(ctx context.Context, actor *iam.Access, id uuid.UUID, in RefundDecision, proof *filestore.Upload) (*Saved, error) {
	pending, err := s.refundScoped(ctx, actor, id)
	if err != nil {
		return nil, err
	}
	if !actor.Can(refundApprovePermission(pending.Type)) {
		if pending.Type == "refund" {
			return nil, forbidden("Bạn chưa được cấp quyền duyệt hoàn tiền học phí.")
		}
		return nil, forbidden("Bạn chưa được cấp quyền duyệt loại yêu cầu này.")
	}
	if in.ClawbackAmount != nil && *in.ClawbackAmount < 0 {
		return nil, iam.Invalid("Số hoa hồng thu hồi không được âm.")
	}
	needProof := pending.Type == "refund" && pending.Status == "pending"
	if needProof {
		if err := proof.Check(RefundProofPolicy); err != nil {
			return nil, iam.Invalid(err.Error())
		}
		if s.files == nil {
			return nil, iam.Invalid("Chưa cấu hình kho file để lưu ảnh bằng chứng.")
		}
	}
	var msg string
	err = s.inTx(ctx, func(tx pgx.Tx, q *tuitiondb.Queries) error {
		r, err := q.LockRefundRequest(ctx, id)
		if err != nil {
			return err
		}
		if r.Status != "pending" {
			return errRefundProcessed
		}
		if r.Type == "extension" || r.Type == "deferral" {
			if msg, err = s.applyExtensionOrDeferral(ctx, tx, q, actor, r, pending.StudentName); err != nil {
				return err
			}
		} else {
			if msg, err = s.applyRefund(ctx, tx, q, actor, r, pending, in, proof); err != nil {
				return err
			}
		}
		r.Status = "approved"
		return s.recordRefund(ctx, tx, actor, r, pending.StudentName, nonZero(pending.ScopeBranchID), audit.EventUpdated, "tuition.refund_request.approve",
			"Duyệt hồ sơ "+strings.ToLower(refundTypeLabel(r.Type))+" của học viên "+pending.StudentName)
	})
	if err != nil {
		return nil, err
	}
	if hasProcessingDeadline(pending.Type) {
		if d := refundDeadline(pending.CreatedAt, s.refundProcessingDays(ctx)); s.now().After(d) {
			msg += " Lưu ý: hồ sơ được xử lý sau hạn " + d.Format("02/01/2006") + " (Quá hạn xử lý)."
		}
	}
	s.log.Info("refund request approved", "refund_id", id, "type", pending.Type, "by", actor.UserID)
	return &Saved{ID: id, Status: "approved", Message: msg}, nil
}

func (s *Service) actorName(ctx context.Context, q *tuitiondb.Queries, actor *iam.Access) string {
	if n, err := q.UserFullName(ctx, actor.UserID); err == nil && n != "" {
		return n
	}
	return "hệ thống"
}

func (s *Service) stamp() string { return "[" + s.nowVN().Format("02/01/2006 15:04") + "] " }

func appendNote(notes *string, line string) *string {
	v := strings.TrimSpace(textOf(notes) + "\n" + line)
	return &v
}

// applyRefund: hoàn tiền / chuyển nhượng — phiếu âm bên nguồn + giảm giá trị hợp đồng; chuyển nhượng thêm phiếu dương
// cấn trừ công nợ bên nhận.
func (s *Service) applyRefund(ctx context.Context, tx pgx.Tx, q *tuitiondb.Queries, actor *iam.Access, r tuitiondb.FinanceRefundRequest,
	info tuitiondb.RefundScopeRow, in RefundDecision, proof *filestore.Upload) (string, error) {
	amount := r.RefundAmount
	if amount <= 0 {
		return "", iam.Invalid("Số tiền hoàn/chuyển nhượng phải lớn hơn 0.")
	}
	src, err := q.LatestStudentTuition(ctx, r.StudentID)
	if isNoRows(err) {
		return "", iam.Invalid("Học viên chưa có khoản học phí để hoàn/chuyển nhượng.")
	}
	if err != nil {
		return "", err
	}
	isTransfer := r.Type == "transfer"
	var dst *tuitiondb.FinanceTuition
	if isTransfer && r.TargetStudentID != nil {
		t, err := q.LatestStudentTuition(ctx, *r.TargetStudentID)
		if err != nil && !isNoRows(err) {
			return "", err
		}
		if err == nil {
			dst = &t
		}
	}
	// Khóa hai khoản theo thứ tự id để tránh deadlock khi chuyển nhượng chéo đồng thời.
	ids := []uuid.UUID{src.ID}
	if dst != nil {
		ids = append(ids, dst.ID)
	}
	slices.SortFunc(ids, func(a, b uuid.UUID) int { return strings.Compare(a.String(), b.String()) })
	for _, id := range ids {
		if _, err := q.LockTuition(ctx, id); err != nil {
			return "", err
		}
	}
	if src, err = s.recalculateDebt(ctx, q, src.ID); err != nil {
		return "", err
	}
	if amount > src.PaidAmount {
		return "", iam.Invalid("Số tiền xử lý vượt quá số tiền học viên đã nộp (" + money(src.PaidAmount) + ").")
	}
	if isTransfer {
		if dst == nil {
			return "", iam.Invalid("Học viên nhận chưa có khoản học phí để cấn trừ.")
		}
		t, err := s.recalculateDebt(ctx, q, dst.ID)
		if err != nil {
			return "", err
		}
		dst = &t
		if amount > dst.DebtAmount {
			return "", iam.Invalid("Số tiền chuyển nhượng vượt quá công nợ còn lại của học viên nhận (" + money(dst.DebtAmount) + "). Vui lòng điều chỉnh số tiền.")
		}
	}

	// Ảnh bằng chứng (hoàn tiền) lưu vào kho file, gắn với hồ sơ.
	var proofID *uuid.UUID
	if r.Type == "refund" && proof != nil {
		f, err := s.files.Save(ctx, tx, proof, files.SaveInput{
			Owner: files.Owner{Module: "tuition", SubjectType: refundProofSubject, SubjectID: &r.ID, BranchID: nonZero(info.ScopeBranchID)},
			By:    &actor.UserID,
			Audit: audit.Entry{Module: audit.ModuleTuition, SubjectType: "tuition_refund_request", SubjectID: r.ID.String(),
				SubjectLabel: "Hoàn phí " + refundRef(r.ID) + " (" + info.StudentName + ")", Description: "Tải ảnh bằng chứng hoàn tiền"},
		})
		if err != nil {
			return "", err
		}
		proofID = &f.ID
	}

	// Quyết định thu hồi hoa hồng: hoàn phí theo lựa chọn (mặc định theo gợi ý), chuyển nhượng không bao giờ thu hồi.
	clawback := false
	var owner *uuid.UUID
	if o, err := q.CommissionOwner(ctx, &r.StudentID); err == nil {
		owner = &o
	} else if !isNoRows(err) {
		return "", err
	}
	if r.Type == "refund" {
		if in.ClawbackCommission != nil {
			clawback = *in.ClawbackCommission
		} else {
			starts, err := s.studyStarts(ctx, q, []uuid.UUID{r.StudentID})
			if err != nil {
				return "", err
			}
			clawback = s.suggestClawback(starts[r.StudentID])
		}
	}
	var clawAmount int64
	var clawUser *uuid.UUID
	if clawback {
		if in.ClawbackAmount != nil {
			clawAmount = *in.ClawbackAmount
		} else if owner != nil {
			if clawAmount, err = s.suggestedClawback(ctx, r.StudentID, *owner, amount); err != nil {
				return "", err
			}
		}
		clawUser = owner
	}
	if err := q.ApproveRefundRequest(ctx, tuitiondb.ApproveRefundRequestParams{ID: r.ID, ApproverID: &actor.UserID, ProofFileID: proofID,
		ClawbackCommission: &clawback, ClawbackAmount: &clawAmount, ClawbackUserID: clawUser}); err != nil {
		return "", err
	}
	if clawback && owner != nil && s.commissions != nil {
		if err := s.commissions.RecordClawback(ctx, tx, r.ID, r.StudentID, *owner, clawAmount, actor.UserID); err != nil {
			return "", err
		}
	}

	amountLabel := money(amount)
	creator := r.RequesterID
	if creator == nil {
		creator = &actor.UserID
	}
	now := s.now()
	today := s.today()
	receipt := func(t tuitiondb.FinanceTuition, amt int64, source, ref, notes string) error {
		code, err := s.newReceiptCode(ctx, q)
		if err != nil {
			return err
		}
		ti, err := q.GetTuition(ctx, t.ID)
		if err != nil {
			return err
		}
		inv, err := s.consumeInvoiceNumber(ctx, q, nonZero(ti.ScopeBranchID))
		if err != nil {
			return err
		}
		_, err = q.CreateReceipt(ctx, tuitiondb.CreateReceiptParams{
			Code: code, Source: source, InvoiceNumber: &inv, TuitionID: &t.ID, StudentID: &t.StudentID, Amount: amt, TuitionAmount: amt,
			PaymentMethod: "transfer", TransactionCode: &ref, PaymentDate: &today, CollectedItems: []byte("[]"), Status: StatusApproved,
			Notes: &notes, CreatorID: creator, ApproverID: &actor.UserID, ApprovedAt: &now,
		})
		return err
	}
	target := textOf(info.TargetName) + " (" + textOf(info.TargetCode) + ")"
	srcNote := "Hoàn trả rút tiền học phí (-" + amountLabel + "). Lý do: " + r.Reason
	source, ref := "refund", "REFUND-"+r.ID.String()
	if isTransfer {
		srcNote = "Trừ chuyển nhượng học phí (-" + amountLabel + ") sang cho học viên " + target + ". Lý do: " + r.Reason
		source, ref = "transfer_out", "XFER-OUT-"+r.ID.String()
	}
	if err := receipt(src, -amount, source, ref, srcNote); err != nil {
		return "", err
	}
	newFinal := max(0, src.FinalAmount-amount)
	kind := "Hoàn phí"
	if isTransfer {
		kind = "Chuyển nhượng"
	}
	line := s.stamp() + kind + " " + refundRef(r.ID) + ": giảm giá trị hợp đồng " + groupThousands(src.FinalAmount) + " → " + money(newFinal) +
		" (-" + amountLabel + "), duyệt bởi " + s.actorName(ctx, q, actor) + "."
	if err := q.SetTuitionFinalAndNotes(ctx, tuitiondb.SetTuitionFinalAndNotesParams{ID: src.ID, FinalAmount: newFinal, Notes: appendNote(src.Notes, line)}); err != nil {
		return "", err
	}
	if _, err := s.recalculateDebt(ctx, q, src.ID); err != nil {
		return "", err
	}
	if !isTransfer {
		return "Đã duyệt hoàn trả " + amountLabel + " cho học viên " + info.StudentName + " và cập nhật lại sổ nợ học phí!", nil
	}
	if err := receipt(*dst, amount, "transfer_in", "XFER-IN-"+r.ID.String(),
		"Nhận chuyển nhượng học phí (+"+amountLabel+") từ học viên "+info.StudentName+" ("+info.StudentCode+"). Lý do: "+r.Reason); err != nil {
		return "", err
	}
	if _, err := s.recalculateDebt(ctx, q, dst.ID); err != nil {
		return "", err
	}
	return "Đã duyệt chuyển nhượng " + amountLabel + " từ học viên " + info.StudentName + " sang " + textOf(info.TargetName) +
		", tự động cấn trừ công nợ & xuất biên lai đối soát!", nil
}

// applyExtensionOrDeferral: khất nợ dời hạn đóng + tạm dừng nhắc nợ tới hạn mới; bảo lưu ghi khoảng bảo lưu, đóng băng số
// buổi / công nợ nếu bắt đầu từ hôm nay trở về trước (bắt đầu ở tương lai: lệnh hằng ngày đóng băng vào ngày đó).
func (s *Service) applyExtensionOrDeferral(ctx context.Context, tx pgx.Tx, q *tuitiondb.Queries, actor *iam.Access, r tuitiondb.FinanceRefundRequest,
	student string) (string, error) {
	t, err := q.LatestStudentTuition(ctx, r.StudentID)
	if isNoRows(err) {
		return "", iam.Invalid("Học viên chưa có hồ sơ học phí để khất nợ / bảo lưu.")
	}
	if err != nil {
		return "", err
	}
	if t, err = q.LockTuition(ctx, t.ID); err != nil {
		return "", err
	}
	approver := s.actorName(ctx, q, actor)
	approve := func() error {
		f := false
		return q.ApproveRefundRequest(ctx, tuitiondb.ApproveRefundRequestParams{ID: r.ID, ApproverID: &actor.UserID, ClawbackCommission: &f})
	}
	if r.Type == "extension" {
		if r.ExtendedDueDate == nil {
			return "", iam.Invalid("Hồ sơ khất nợ chưa có hạn đóng mới. Vui lòng từ chối và lập lại hồ sơ kèm hạn mới.")
		}
		old := "chưa có"
		if t.DueDate != nil {
			old = t.DueDate.Format("02/01/2006")
		}
		nd := r.ExtendedDueDate.Format("02/01/2006")
		line := s.stamp() + "Khất nợ " + refundRef(r.ID) + ": hạn đóng " + old + " → " + nd + ", tạm dừng nhắc nợ tới hạn mới. Duyệt bởi " + approver + "."
		if err := q.ApplyTuitionExtension(ctx, tuitiondb.ApplyTuitionExtensionParams{ID: t.ID, DueDate: r.ExtendedDueDate, Notes: appendNote(t.Notes, line)}); err != nil {
			return "", err
		}
		if _, err := s.recalculateDebt(ctx, q, t.ID); err != nil {
			return "", err
		}
		if err := approve(); err != nil {
			return "", err
		}
		return "Đã duyệt khất nợ cho học viên " + student + ": hạn đóng mới " + nd + ", tạm dừng nhắc nợ tới ngày này.", nil
	}
	if r.DeferFrom == nil || r.DeferTo == nil {
		return "", iam.Invalid("Hồ sơ bảo lưu chưa có thời gian từ ngày / đến ngày.")
	}
	if t, err = s.recalculateDebt(ctx, q, t.ID); err != nil {
		return "", err
	}
	stats, err := q.TuitionSessionStats(ctx, t.ID)
	if err != nil {
		return "", err
	}
	total, attended, hasStats := sessionStats(stats.CourseLessons, stats.ScheduledSessions, stats.AttendedSessions, stats.StatsClassID != nil)
	remaining := max(0, total-attended)
	if t.FrozenRemainingSessions != nil {
		remaining = int(*t.FrozenRemainingSessions)
	}
	today := s.today()
	resume := r.DeferTo.AddDate(0, 0, 1)
	startsNow := !r.DeferFrom.After(today)
	p := tuitiondb.ApplyTuitionDeferralParams{ID: t.ID, DeferredFrom: r.DeferFrom, DeferredUntil: r.DeferTo,
		FrozenRemainingSessions: t.FrozenRemainingSessions, FrozenDebtAmount: t.FrozenDebtAmount, DueDate: t.DueDate}
	if startsNow {
		p.FrozenRemainingSessions, p.FrozenDebtAmount = nil, &t.DebtAmount
		if hasStats {
			p.FrozenRemainingSessions = ptr(int32(remaining))
		}
	}
	p.ReminderPausedUntil = &resume
	if t.ReminderPausedUntil != nil && t.ReminderPausedUntil.After(resume) {
		p.ReminderPausedUntil = t.ReminderPausedUntil
	}
	// Hạn đóng còn nằm trong thời gian bảo lưu → dời sang ngày học lại để không bị tính quá hạn.
	if t.DebtAmount > 0 && (t.DueDate == nil || t.DueDate.Before(resume)) {
		p.DueDate = &resume
	}
	rng := r.DeferFrom.Format("02/01/2006") + " – " + r.DeferTo.Format("02/01/2006")
	what := "đóng băng số buổi và công nợ vào ngày bắt đầu"
	if startsNow {
		sessions := "số buổi còn lại"
		if hasStats {
			sessions = fmt.Sprintf("%d buổi còn lại", remaining)
		}
		what = "đóng băng " + sessions + " và công nợ " + money(t.DebtAmount)
	}
	line := s.stamp() + "Bảo lưu " + refundRef(r.ID) + ": " + rng + ", " + what + ". Duyệt bởi " + approver + "."
	p.Notes = appendNote(t.Notes, line)
	if err := q.ApplyTuitionDeferral(ctx, p); err != nil {
		return "", err
	}
	if _, err := s.recalculateDebt(ctx, q, t.ID); err != nil {
		return "", err
	}
	if s.students != nil {
		note := "Bảo lưu " + rng + " (hồ sơ " + refundRef(r.ID) + "). Lý do: " + r.Reason
		if !startsNow {
			note += " Học viên vẫn học tới ngày bắt đầu bảo lưu."
		}
		if err := s.students.ApproveDeferral(ctx, tx, r.StudentID, startsNow, note, actor.UserID); err != nil {
			return "", err
		}
	}
	if err := approve(); err != nil {
		return "", err
	}
	msg := "Đã duyệt bảo lưu cho học viên " + student + " từ " + r.DeferFrom.Format("02/01/2006") + " đến " + r.DeferTo.Format("02/01/2006") + "; "
	if startsNow {
		return msg + "đã đóng băng số buổi còn lại và công nợ.", nil
	}
	return msg + "học viên vẫn học bình thường tới ngày bắt đầu, hệ thống tự chuyển Bảo lưu vào ngày đó.", nil
}

// RejectRefund: từ chối hồ sơ (refund_transfer.reject).
func (s *Service) RejectRefund(ctx context.Context, actor *iam.Access, id uuid.UUID, reason *string) (*Saved, error) {
	reason = optText(reason)
	if tooLong(reason, 1000) {
		return nil, iam.Invalid("Lý do từ chối tối đa 1000 ký tự.")
	}
	info, err := s.refundScoped(ctx, actor, id)
	if err != nil {
		return nil, err
	}
	err = s.inTx(ctx, func(tx pgx.Tx, q *tuitiondb.Queries) error {
		r, err := q.LockRefundRequest(ctx, id)
		if err != nil {
			return err
		}
		if r.Status != "pending" {
			return errRefundProcessed
		}
		if err := q.RejectRefundRequest(ctx, tuitiondb.RejectRefundRequestParams{ID: id, ApproverID: &actor.UserID, RejectionReason: reason}); err != nil {
			return err
		}
		r.Status = "rejected"
		desc := "Từ chối hồ sơ " + strings.ToLower(refundTypeLabel(r.Type)) + " của học viên " + info.StudentName
		if reason != nil {
			desc += ": " + *reason
		}
		return s.recordRefund(ctx, tx, actor, r, info.StudentName, nonZero(info.ScopeBranchID), audit.EventUpdated, "tuition.refund_request.reject", desc)
	})
	if err != nil {
		return nil, err
	}
	s.log.Info("refund request rejected", "refund_id", id, "by", actor.UserID)
	return &Saved{ID: id, Status: "rejected", Message: "Đã từ chối hồ sơ xử lý học phí của học viên " + info.StudentName + "!"}, nil
}

// readRefundProof: quyền mở ảnh bằng chứng — người xem được màn Học phí và hồ sơ trong phạm vi chi nhánh.
func (s *Service) readRefundProof(ctx context.Context, a *iam.Access, f files.File) (string, error) {
	if f.SubjectID == nil || !a.Can("tuition.view") {
		return "", forbidden("Bạn không có quyền xem ảnh bằng chứng này.")
	}
	if _, err := s.refundScoped(ctx, a, *f.SubjectID); err != nil {
		return "", err
	}
	return "", nil
}
