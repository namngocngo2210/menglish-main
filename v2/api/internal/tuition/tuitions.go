package tuition

import (
	"context"
	"slices"
	"sort"
	"strings"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/tuition/tuitiondb"
)

// Công nợ học viên (TuitionController::students), nhóm đến hạn / quá hạn (dueGroups) và lịch sử thu của một học viên
// (TuitionPaymentHistory::forStudent).

// StudentBrief: học viên gọn cho các bảng Học phí.
type StudentBrief struct {
	ID    uuid.UUID `json:"id"`
	Name  string    `json:"name"`
	Code  string    `json:"code"`
	Phone string    `json:"phone"`
}

type TuitionRow struct {
	ID          uuid.UUID    `json:"id"`
	Student     StudentBrief `json:"student"`
	ClassName   *string      `json:"class_name"`
	BranchName  *string      `json:"branch_name"`
	FeeLabel    string       `json:"fee_label"`
	FinalAmount int64        `json:"final_amount"`
	PaidAmount  int64        `json:"paid_amount"`
	DebtAmount  int64        `json:"debt_amount"`
	DueDate     *string      `json:"due_date"`
	// SLA học phí: quá hạn 1–6 ngày vàng, ≥ ngưỡng (mặc định 7) đỏ + bắt buộc liên hệ trực tiếp
	DaysOverdue int    `json:"days_overdue"`
	Status      string `json:"status"`
	StatusColor string `json:"status_color"`
	StatusLabel string `json:"status_label"`
}

type TuitionFilter struct {
	Search   string
	Status   string
	BranchID *uuid.UUID
	ClassID  *uuid.UUID
	Page     int
	// Nhóm đến hạn: all | overdue | upcoming, trang của nhóm sắp đến hạn
	Type         string
	UpcomingPage int
}

type TuitionStats struct {
	Final   int64 `json:"final"`
	Paid    int64 `json:"paid"`
	Debt    int64 `json:"debt"`
	Overdue int64 `json:"overdue"`
}

type StudentsPage struct {
	Tuitions []TuitionRow   `json:"tuitions"`
	Page     Page           `json:"page"`
	Stats    TuitionStats   `json:"stats"`
	Branches []Option       `json:"branches"`
	Classes  []Option       `json:"classes"`
	Statuses []Option       `json:"statuses"`
	Due      DueGroups      `json:"due"`
	Filters  map[string]any `json:"filters"`
}

const perPage = 15

func (s *Service) daysOverdue(due *time.Time) int {
	if due == nil {
		return 0
	}
	return daysBetween(*due, s.today())
}

// Students: màn Công nợ học viên.
func (s *Service) Students(ctx context.Context, actor *iam.Access, f TuitionFilter) (*StudentsPage, error) {
	limit := branchLimit(actor)
	var status *string
	if st := strings.TrimSpace(f.Status); st != "" {
		status = &st
	}
	today := s.today()
	n, off := pageOf(f.Page, perPage)
	rows, err := s.q.ListTuitions(ctx, tuitiondb.ListTuitionsParams{
		BranchIds: limit, Search: likePattern(f.Search), Status: status, Today: today, BranchID: f.BranchID, ClassID: f.ClassID,
		PageLimit: n, PageOffset: off,
	})
	if err != nil {
		return nil, err
	}
	total, err := s.q.CountTuitions(ctx, tuitiondb.CountTuitionsParams{
		BranchIds: limit, Search: likePattern(f.Search), Status: status, Today: today, BranchID: f.BranchID, ClassID: f.ClassID,
	})
	if err != nil {
		return nil, err
	}
	st, err := s.q.TuitionStats(ctx, tuitiondb.TuitionStatsParams{BranchIds: limit, Today: today, BranchID: f.BranchID, ClassID: f.ClassID})
	if err != nil {
		return nil, err
	}
	out := &StudentsPage{Tuitions: make([]TuitionRow, 0, len(rows)), Page: newPage(f.Page, perPage, total),
		Stats:    TuitionStats{Final: st.FinalTotal, Paid: st.PaidTotal, Debt: st.DebtTotal, Overdue: st.OverdueCount},
		Statuses: append(slices.Clone(tuitionStatuses), Option{"overdue", "Quá hạn (theo hạn đóng)"}),
		Filters:  map[string]any{"search": f.Search, "status": f.Status, "branch_id": f.BranchID, "class_id": f.ClassID},
	}
	for _, r := range rows {
		days := 0
		if r.DebtAmount > 0 {
			days = max(0, s.daysOverdue(r.DueDate))
		}
		out.Tuitions = append(out.Tuitions, TuitionRow{
			ID: r.ID, Student: StudentBrief{r.StudentID, r.StudentName, r.StudentCode, r.StudentPhone},
			ClassName: r.ClassName, BranchName: r.BranchName,
			FeeLabel: feeLabel(r.CourseName, r.ClassName, int32(len(decodeItems(r.FeeItems)))), FinalAmount: r.FinalAmount,
			PaidAmount: r.PaidAmount, DebtAmount: r.DebtAmount, DueDate: fmtDate(r.DueDate), DaysOverdue: days,
			Status: r.Status, StatusColor: tuitionStatusColor(r.Status), StatusLabel: tuitionStatusLabel(r.Status),
		})
	}
	if out.Branches, out.Classes, err = s.scopeOptions(ctx, actor, false); err != nil {
		return nil, err
	}
	if out.Due, err = s.dueGroups(ctx, actor, f); err != nil {
		return nil, err
	}
	return out, nil
}

// scopeOptions: chi nhánh và lớp trong phạm vi cho ô lọc.
func (s *Service) scopeOptions(ctx context.Context, actor *iam.Access, activeOnly bool) ([]Option, []Option, error) {
	limit := branchLimit(actor)
	bs, err := s.q.ScopeBranches(ctx, limit)
	if err != nil {
		return nil, nil, err
	}
	branches := make([]Option, 0, len(bs))
	for _, b := range bs {
		if activeOnly && !b.IsActive {
			continue
		}
		branches = append(branches, Option{b.ID.String(), b.Name})
	}
	cs, err := s.q.ScopeClasses(ctx, limit)
	if err != nil {
		return nil, nil, err
	}
	classes := make([]Option, 0, len(cs))
	for _, c := range cs {
		classes = append(classes, Option{c.ID.String(), c.Name + " (" + c.Code + ")"})
	}
	return branches, classes, nil
}

// ───────────── Nhóm đến hạn / quá hạn ─────────────

type Line struct {
	Label string `json:"label"`
	Value int    `json:"value"`
}

type Contact struct {
	At        *string `json:"at"`
	UserName  *string `json:"user_name"`
	Note      *string `json:"note"`
	NoteShort *string `json:"note_short"`
}

type DueRow struct {
	ID                  uuid.UUID    `json:"id"`
	Student             StudentBrief `json:"student"`
	ClassLabel          string       `json:"class_label"`
	ClassName           *string      `json:"class_name"`
	BranchName          *string      `json:"branch_name"`
	FeeLabel            string       `json:"fee_label"`
	DebtAmount          int64        `json:"debt_amount"`
	DueDate             *string      `json:"due_date"`
	DaysOverdue         int          `json:"days_overdue"`
	SessionLines        []Line       `json:"session_lines"`
	LastContact         *Contact     `json:"last_contact"`
	LastReportDate      *string      `json:"last_report_date"`
	ReminderPausedUntil *string      `json:"reminder_paused_until"`
	DeferredUntil       *string      `json:"deferred_until"`
	paused              bool
}

type DueGroups struct {
	SeriousOverdue []DueRow `json:"serious_overdue"`
	NewOverdue     []DueRow `json:"new_overdue"`
	Upcoming       []DueRow `json:"upcoming"`
	UpcomingPage   Page     `json:"upcoming_page"`
	Paused         []DueRow `json:"paused"`
	OverdueCount   int      `json:"overdue_count"`
	Type           string   `json:"type"`
	SeriousDays    int      `json:"serious_days"`
	UpcomingDays   int      `json:"upcoming_days"`
}

const upcomingDays = 14

// seriousDays: "Mốc quá hạn bắt buộc liên hệ" (debt_reminder.must_contact_days, mặc định 7).
func (s *Service) seriousDays(ctx context.Context) int {
	if s.cat != nil {
		if c, err := s.cat.DebtReminders(ctx); err == nil {
			return max(1, c.MustContactDays)
		}
	}
	return 7
}

func (s *Service) dueGroups(ctx context.Context, actor *iam.Access, f TuitionFilter) (DueGroups, error) {
	today := s.today()
	g := DueGroups{SeriousDays: s.seriousDays(ctx), UpcomingDays: upcomingDays, Type: "all",
		SeriousOverdue: []DueRow{}, NewOverdue: []DueRow{}, Upcoming: []DueRow{}, Paused: []DueRow{}}
	if f.Type == "overdue" || f.Type == "upcoming" {
		g.Type = f.Type
	}
	rows, err := s.q.DueTuitions(ctx, tuitiondb.DueTuitionsParams{
		Until: today.AddDate(0, 0, upcomingDays), Today: today, BranchIds: branchLimit(actor), Search: likePattern(f.Search),
		BranchID: f.BranchID, ClassID: f.ClassID,
	})
	if err != nil {
		return g, err
	}
	ids := make([]uuid.UUID, 0, len(rows))
	for _, r := range rows {
		ids = append(ids, r.ID)
	}
	logs, err := s.q.LastContactLogs(ctx, ids)
	if err != nil {
		return g, err
	}
	contacts := map[uuid.UUID]tuitiondb.LastContactLogsRow{}
	reports := map[uuid.UUID]time.Time{}
	for _, l := range logs {
		if l.Action == "contacted" {
			contacts[l.TuitionID] = l
		} else {
			reports[l.TuitionID] = l.ContactedAt
		}
	}
	var upcoming []DueRow
	for _, r := range rows {
		row := s.dueRow(r, today)
		if l, ok := contacts[r.ID]; ok {
			c := &Contact{At: fmtDateTime(&l.ContactedAt, "02/01 15:04"), UserName: l.UserName, Note: l.Note}
			if l.Note != nil {
				c.NoteShort = ptr(limitText(*l.Note, 40))
			}
			row.LastContact = c
		}
		if at, ok := reports[r.ID]; ok {
			row.LastReportDate = fmtDateTime(&at, "02/01/2006")
		}
		switch {
		case row.paused:
			g.Paused = append(g.Paused, row)
		case row.DaysOverdue >= g.SeriousDays:
			g.SeriousOverdue = append(g.SeriousOverdue, row)
		case row.DaysOverdue >= 1:
			g.NewOverdue = append(g.NewOverdue, row)
		default:
			upcoming = append(upcoming, row)
		}
	}
	byDays := func(list []DueRow) {
		sort.SliceStable(list, func(i, j int) bool { return list[i].DaysOverdue > list[j].DaysOverdue })
	}
	byDays(g.SeriousOverdue)
	byDays(g.NewOverdue)
	byDays(upcoming)
	switch g.Type {
	case "overdue":
		upcoming = nil
	case "upcoming":
		g.SeriousOverdue, g.NewOverdue = []DueRow{}, []DueRow{}
	}
	g.OverdueCount = len(g.SeriousOverdue) + len(g.NewOverdue)
	const per = 10
	page := max(1, f.UpcomingPage)
	g.UpcomingPage = newPage(page, per, int64(len(upcoming)))
	from := min(len(upcoming), (page-1)*per)
	g.Upcoming = append(g.Upcoming, upcoming[from:min(len(upcoming), from+per)]...)
	return g, nil
}

func (s *Service) dueRow(r tuitiondb.DueTuitionsRow, today time.Time) DueRow {
	row := DueRow{
		ID: r.ID, Student: StudentBrief{r.StudentID, r.StudentName, r.StudentCode, r.StudentPhone},
		ClassLabel: "—", ClassName: r.ClassName, BranchName: r.BranchName,
		FeeLabel:   feeLabel(r.CourseName, r.ClassName, int32(len(decodeItems(r.FeeItems)))),
		DebtAmount: r.DebtAmount, DueDate: fmtDate(r.DueDate), DaysOverdue: s.daysOverdue(r.DueDate),
		SessionLines:        []Line{},
		ReminderPausedUntil: fmtDate(r.ReminderPausedUntil), DeferredUntil: fmtDate(r.DeferredUntil),
		paused: pausedOn(r.ReminderPausedUntil, today),
	}
	if r.ClassCode != nil {
		row.ClassLabel = *r.ClassCode
	} else if r.ClassName != nil {
		row.ClassLabel = *r.ClassName
	}
	if total, attended, ok := sessionStats(r.CourseLessons, r.ScheduledSessions, r.AttendedSessions, r.StatsClassID != nil); ok {
		remaining := max(0, total-attended)
		if r.FrozenRemainingSessions != nil {
			remaining = int(*r.FrozenRemainingSessions)
		}
		row.SessionLines = []Line{{"Số buổi còn tồn", remaining}, {"Số buổi đã học", attended}, {"Tổng số buổi", total}}
	}
	return row
}

// sessionStats: tổng buổi theo khóa học (chưa có khóa thì theo lịch lớp), số buổi đã học (có mặt / đi muộn).
func sessionStats(courseLessons, scheduled, attended int32, hasClass bool) (int, int, bool) {
	total := int(courseLessons)
	if total <= 0 && hasClass {
		total = int(scheduled)
	}
	if total <= 0 {
		return 0, 0, false
	}
	return total, int(attended), true
}

// ───────────── Lịch sử thu của một học viên ─────────────

type PaymentRow struct {
	ID              uuid.UUID `json:"id"`
	Kind            string    `json:"kind"`
	Title           string    `json:"title"`
	Installment     *int      `json:"installment"`
	Number          string    `json:"number"`
	Date            *string   `json:"date"`
	Method          string    `json:"method"`
	MethodIcon      string    `json:"method_icon"`
	Amount          int64     `json:"amount"`
	Surcharge       int64     `json:"surcharge"`
	Discount        int64     `json:"discount"`
	DebtAfter       *int64    `json:"debt_after"`
	InvoiceNumber   *string   `json:"invoice_number"`
	SurchargeDetail *string   `json:"surcharge_detail"`
	CreatorName     *string   `json:"creator_name"`
	ApproverName    *string   `json:"approver_name"`
	Notes           *string   `json:"notes"`
	TuitionLabel    *string   `json:"tuition_label"`
}

type PaymentSummary struct {
	FinalAmount     int64   `json:"final_amount"`
	PaidAmount      int64   `json:"paid_amount"`
	DiscountAmount  int64   `json:"discount_amount"`
	DebtAmount      int64   `json:"debt_amount"`
	OtherFees       int64   `json:"other_fees"`
	SurchargeAmount int64   `json:"surcharge_amount"`
	Installments    int     `json:"installments"`
	NextDueDate     *string `json:"next_due_date"`
	IsSettled       bool    `json:"is_settled"`
}

type PaymentTuition struct {
	ID          uuid.UUID `json:"id"`
	Label       string    `json:"label"`
	CourseLabel string    `json:"course_label"`
	ClassName   *string   `json:"class_name"`
	FinalAmount int64     `json:"final_amount"`
	OtherFees   int64     `json:"other_fees"`
	PaidAmount  int64     `json:"paid_amount"`
	DebtAmount  int64     `json:"debt_amount"`
	DueDate     *string   `json:"due_date"`
	StatusLabel string    `json:"status_label"`
	StatusColor string    `json:"status_color"`
}

type OtherReceipt struct {
	ID              uuid.UUID `json:"id"`
	Number          string    `json:"number"`
	Date            *string   `json:"date"`
	Amount          int64     `json:"amount"`
	Method          string    `json:"method"`
	StatusLabel     string    `json:"status_label"`
	StatusColor     string    `json:"status_color"`
	RejectionReason *string   `json:"rejection_reason"`
}

type StudentPayments struct {
	Student  StudentBrief     `json:"student"`
	Summary  PaymentSummary   `json:"summary"`
	Tuitions []PaymentTuition `json:"tuitions"`
	Payments []PaymentRow     `json:"payments"`
	Others   []OtherReceipt   `json:"others"`
}

// receiptKind: phiếu hệ thống của hoàn / chuyển phí, còn lại là đợt đóng.
func receiptKind(source string, amount int64) string {
	switch {
	case source == "transfer_out":
		return "transfer_out"
	case source == "transfer_in":
		return "transfer_in"
	case source == "refund" || amount < 0:
		return "refund"
	}
	return "payment"
}

func courseLabel(t tuitiondb.StudentTuitionsRow) string {
	var parts []string
	for _, p := range []*string{t.CourseName, t.ClassName} {
		if p != nil && *p != "" && !slices.Contains(parts, *p) {
			parts = append(parts, *p)
		}
	}
	if len(parts) == 0 {
		return "Khoản học phí " + t.CreatedAt.In(vnZone).Format("02/01/2006")
	}
	return strings.Join(parts, " · ")
}

// surchargeDetail: lý do phụ thu · hàng hóa giao kèm.
func surchargeDetail(reason *string, items []ItemLine) *string {
	var names []string
	for _, l := range items {
		if l.Name == "" {
			continue
		}
		n := l.Name
		if l.Quantity > 1 {
			n += " ×" + itoaPlain(l.Quantity)
		}
		names = append(names, n)
	}
	var parts []string
	if reason != nil && *reason != "" {
		parts = append(parts, *reason)
	}
	if len(names) > 0 {
		parts = append(parts, strings.Join(names, ", "))
	}
	if len(parts) == 0 {
		return nil
	}
	return ptr(strings.Join(parts, " · "))
}

// paymentDay: ngày của phiếu (ngày thu, rồi ngày duyệt, rồi ngày lập).
func paymentDay(r tuitiondb.StudentReceiptsRow) time.Time {
	switch {
	case r.PaymentDate != nil:
		return *r.PaymentDate
	case r.ApprovedAt != nil:
		return dateOf(*r.ApprovedAt)
	}
	return dateOf(r.CreatedAt)
}

// StudentPayments: toàn bộ lịch sử thu học phí của một học viên (học viên trong phạm vi, hoặc có khoản học phí trong
// phạm vi).
func (s *Service) StudentPayments(ctx context.Context, actor *iam.Access, studentID uuid.UUID) (*StudentPayments, error) {
	st, err := s.q.GetStudent(ctx, studentID)
	if isNoRows(err) {
		return nil, ErrStudentNotFound
	}
	if err != nil {
		return nil, err
	}
	tuitions, err := s.q.StudentTuitions(ctx, studentID)
	if err != nil {
		return nil, err
	}
	if !covers(actor, nonZero(st.ScopeBranchID)) {
		ok := false
		for _, t := range tuitions {
			b := t.BranchID
			if b == nil {
				b = nonZero(st.ScopeBranchID)
			}
			ok = ok || covers(actor, b)
		}
		if !ok {
			return nil, ErrStudentNotFound
		}
	}
	return s.paymentHistory(ctx, st, tuitions)
}

func (s *Service) paymentHistory(ctx context.Context, st tuitiondb.GetStudentRow, tuitions []tuitiondb.StudentTuitionsRow) (*StudentPayments, error) {
	receipts, err := s.q.StudentReceipts(ctx, st.ID)
	if err != nil {
		return nil, err
	}
	sort.SliceStable(receipts, func(i, j int) bool {
		di, dj := paymentDay(receipts[i]), paymentDay(receipts[j])
		if !di.Equal(dj) {
			return di.Before(dj)
		}
		return receipts[i].CreatedAt.Before(receipts[j].CreatedAt)
	})
	out := &StudentPayments{Student: StudentBrief{st.ID, st.FullName, st.Code, st.Phone}, Tuitions: []PaymentTuition{},
		Payments: []PaymentRow{}, Others: []OtherReceipt{}}
	remaining := map[uuid.UUID]int64{}
	labels := map[uuid.UUID]string{}
	var nextDue *time.Time
	for _, t := range tuitions {
		remaining[t.ID] = t.FinalAmount
		labels[t.ID] = courseLabel(t)
		out.Summary.FinalAmount += t.FinalAmount
		out.Summary.PaidAmount += t.PaidAmount
		out.Summary.DebtAmount += t.DebtAmount
		out.Summary.OtherFees += t.OtherFees
		if t.DebtAmount > 0 && t.DueDate != nil && (nextDue == nil || t.DueDate.Before(*nextDue)) {
			nextDue = t.DueDate
		}
		status := "Chưa đóng"
		switch {
		case t.DebtAmount <= 0:
			status = "Đã đóng đủ"
		case t.PaidAmount > 0:
			status = "Đang đóng dần"
		}
		pt := PaymentTuition{ID: t.ID, Label: feeLabel(t.CourseName, t.ClassName, int32(len(decodeItems(t.FeeItems)))),
			CourseLabel: labels[t.ID], ClassName: t.ClassName, FinalAmount: t.FinalAmount, OtherFees: t.OtherFees,
			PaidAmount: t.PaidAmount, DebtAmount: t.DebtAmount, StatusLabel: status, StatusColor: tuitionStatusColor(t.Status)}
		if t.DebtAmount > 0 {
			pt.DueDate = fmtDate(t.DueDate)
		}
		out.Tuitions = append(out.Tuitions, pt)
	}
	out.Summary.NextDueDate = fmtDate(nextDue)
	out.Summary.IsSettled = len(tuitions) > 0 && out.Summary.DebtAmount <= 0
	perTuition := map[uuid.UUID]int{}
	var others []OtherReceipt
	for _, r := range receipts {
		if r.Status != StatusApproved {
			o := OtherReceipt{ID: r.ID, Number: r.Code, Amount: r.Amount, Method: methodLabel(r.PaymentMethod),
				StatusLabel: receiptStatusLabel(r.Status), StatusColor: receiptStatusColor(r.Status)}
			d := dateOf(r.CreatedAt)
			if r.PaymentDate != nil {
				d = *r.PaymentDate
			}
			o.Date = fmtDate(&d)
			if r.Status == StatusRejected {
				o.RejectionReason = r.RejectionReason
			}
			others = append(others, o)
			continue
		}
		out.Summary.DiscountAmount += r.DiscountAmount
		out.Summary.SurchargeAmount += r.SurchargeAmount
		var debtAfter *int64
		if r.TuitionID != nil {
			if rem, ok := remaining[*r.TuitionID]; ok {
				rem -= r.Amount - r.SurchargeAmount + r.DiscountAmount
				remaining[*r.TuitionID] = rem
				debtAfter = ptr(max(0, rem))
			}
		}
		kind := receiptKind(r.Source, r.Amount)
		var number *int
		if kind == "payment" && r.TuitionID != nil {
			out.Summary.Installments++
			perTuition[*r.TuitionID]++
			number = ptr(perTuition[*r.TuitionID])
		}
		title := "Thu khác"
		switch {
		case kind == "transfer_out":
			title = "Chuyển phí sang học viên khác"
		case kind == "transfer_in":
			title = "Nhận chuyển phí"
		case kind == "refund":
			title = "Hoàn học phí"
		case number != nil:
			title = "Đợt " + itoaPlain(*number)
		}
		icon := methodIcons[r.PaymentMethod]
		if icon == "" {
			icon = "payments"
		}
		d := paymentDay(r)
		row := PaymentRow{ID: r.ID, Kind: kind, Title: title, Installment: number, Number: r.Code, Date: fmtDate(&d),
			Method: methodLabel(r.PaymentMethod), MethodIcon: icon, Amount: r.Amount, Surcharge: r.SurchargeAmount,
			Discount: r.DiscountAmount, DebtAfter: debtAfter, InvoiceNumber: r.InvoiceNumber,
			SurchargeDetail: surchargeDetail(r.SurchargeReason, decodeItems(r.CollectedItems)), CreatorName: r.CreatorName,
			ApproverName: r.ApproverName, Notes: r.Notes}
		if r.TuitionID != nil {
			if l, ok := labels[*r.TuitionID]; ok {
				row.TuitionLabel = &l
			}
		}
		out.Payments = append(out.Payments, row)
	}
	// Mới nhất lên đầu; số "Đợt" vẫn đánh theo thứ tự thời gian.
	slices.Reverse(out.Payments)
	slices.Reverse(others)
	out.Others = append(out.Others, others...)
	return out, nil
}

func itoaPlain(n int) string {
	return strings.TrimSpace(strings.ReplaceAll(groupThousands(int64(n)), ".", ""))
}
