package tuition

import (
	"context"
	"fmt"
	"math"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/tuition/tuitiondb"
)

// Sổ buổi (SessionLedger của Laravel): số buổi đã đóng tiền − số buổi đã học = tồn buổi. Buổi đã học tính theo lịch
// lớp (chính khóa + học bù, không hủy, đã tới giờ) trong khoảng học viên thuộc lớp, trừ khoảng bảo lưu đã duyệt.

const defaultCourseSessions = 24

// classInfo: lớp kèm khóa (học phí niêm yết, số buổi).
type classInfo struct {
	ID            uuid.UUID
	Name, Code    string
	BranchID      uuid.UUID
	CourseID      *uuid.UUID
	TuitionFee    *int64
	CourseFee     *int64
	CourseLessons *int32
	CourseName    *string
}

func classFromRow(r tuitiondb.GetClassRow) *classInfo {
	return &classInfo{ID: r.ID, Name: r.Name, Code: r.Code, BranchID: r.BranchID, CourseID: r.CourseID, TuitionFee: r.TuitionFee,
		CourseFee: r.CourseTuitionFee, CourseLessons: r.CourseLessons, CourseName: r.CourseName}
}

// courseSessions: số buổi một khóa của lớp (khóa học), không có thì 24.
func courseSessions(c *classInfo) int {
	if c != nil && c.CourseLessons != nil && *c.CourseLessons > 0 {
		return int(*c.CourseLessons)
	}
	return defaultCourseSessions
}

// classFee: học phí của lớp (lớp đặt riêng), không có thì học phí niêm yết của khóa.
func classFee(c *classInfo) int64 {
	if c == nil {
		return 0
	}
	if c.TuitionFee != nil && *c.TuitionFee > 0 {
		return *c.TuitionFee
	}
	return val(c.CourseFee)
}

func classUnitPrice(c *classInfo) float64 {
	return round2(float64(classFee(c)) / float64(courseSessions(c)))
}

// JoinTuition: học phí khi vào lớp giữa khóa = số buổi còn lại × đơn giá (đủ khóa thì nguyên học phí).
type JoinTuition struct {
	Sessions       int   `json:"sessions"`
	CourseSessions int   `json:"course_sessions"`
	Fee            int64 `json:"fee"`
}

func joinTuition(c *classInfo, held int) JoinTuition {
	size := courseSessions(c)
	n := max(0, size-held)
	full := classFee(c)
	fee := full
	if n < size {
		fee = int64(math.Round(float64(full) * float64(n) / float64(size)))
	}
	return JoinTuition{Sessions: n, CourseSessions: size, Fee: fee}
}

func (s *Service) getClass(ctx context.Context, q *tuitiondb.Queries, id *uuid.UUID) (*classInfo, error) {
	if id == nil {
		return nil, nil
	}
	r, err := q.GetClass(ctx, *id)
	if isNoRows(err) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	return classFromRow(r), nil
}

// heldCounts: số buổi đã diễn ra của từng lớp.
func (s *Service) heldCounts(ctx context.Context, q *tuitiondb.Queries, classIDs []uuid.UUID) (map[uuid.UUID]int, error) {
	out := map[uuid.UUID]int{}
	if len(classIDs) == 0 {
		return out, nil
	}
	rows, err := q.CountedSessions(ctx, tuitiondb.CountedSessionsParams{ClassIds: classIDs, Today: s.today(), NowTime: pgTime(s.now())})
	if err != nil {
		return nil, err
	}
	for _, r := range rows {
		out[r.ClassID]++
	}
	return out, nil
}

// HeldSessions: số buổi lớp đã diễn ra (phân hệ khác dùng khi tính học phí vào lớp giữa khóa).
func (s *Service) HeldSessions(ctx context.Context, classID uuid.UUID) (int, error) {
	m, err := s.heldCounts(ctx, s.q, []uuid.UUID{classID})
	return m[classID], err
}

// Ledger: sổ buổi của một học viên.
type Ledger struct {
	Paid    int `json:"paid"`
	Used    int `json:"used"`
	Balance int `json:"balance"`
}

func (s *Service) ledgers(ctx context.Context, q *tuitiondb.Queries, ids []uuid.UUID) (map[uuid.UUID]Ledger, error) {
	out := map[uuid.UUID]Ledger{}
	if len(ids) == 0 {
		return out, nil
	}
	tuitions, err := q.LedgerTuitions(ctx, ids)
	if err != nil {
		return nil, err
	}
	paid := map[uuid.UUID]int{}
	for _, t := range tuitions {
		c := contract{Total: t.TotalAmount, Discount: t.DiscountAmount, OtherFees: t.OtherFees, Final: t.FinalAmount,
			Debt: t.DebtAmount, Sessions: int(val(t.SessionCount))}
		paid[t.StudentID] += c.paidSessions()
	}
	used, err := s.usedSessions(ctx, q, ids)
	if err != nil {
		return nil, err
	}
	for _, id := range ids {
		out[id] = Ledger{Paid: paid[id], Used: used[id], Balance: paid[id] - used[id]}
	}
	return out, nil
}

func (s *Service) usedSessions(ctx context.Context, q *tuitiondb.Queries, ids []uuid.UUID) (map[uuid.UUID]int, error) {
	periods, err := q.LedgerPeriods(ctx, ids)
	if err != nil {
		return nil, err
	}
	out := map[uuid.UUID]int{}
	if len(periods) == 0 {
		return out, nil
	}
	classSet := map[uuid.UUID]bool{}
	var classIDs []uuid.UUID
	for _, p := range periods {
		if !classSet[p.ClassID] {
			classSet[p.ClassID] = true
			classIDs = append(classIDs, p.ClassID)
		}
	}
	sessions, err := q.CountedSessions(ctx, tuitiondb.CountedSessionsParams{ClassIds: classIDs, Today: s.today(), NowTime: pgTime(s.now())})
	if err != nil {
		return nil, err
	}
	byClass := map[uuid.UUID][]tuitiondb.CountedSessionsRow{}
	for _, x := range sessions {
		byClass[x.ClassID] = append(byClass[x.ClassID], x)
	}
	defs, err := q.ApprovedDeferrals(ctx, ids)
	if err != nil {
		return nil, err
	}
	paused := map[uuid.UUID][][2]time.Time{}
	for _, d := range defs {
		paused[d.StudentID] = append(paused[d.StudentID], [2]time.Time{*d.DeferFrom, *d.DeferTo})
	}
	counted := map[uuid.UUID]map[uuid.UUID]bool{}
	for _, p := range periods {
		for _, x := range byClass[p.ClassID] {
			day := x.Date
			if day.Before(p.FromDay) || (p.UntilDay != nil && !day.Before(*p.UntilDay)) {
				continue
			}
			skip := false
			for _, r := range paused[p.StudentID] {
				if !day.Before(r[0]) && !day.After(r[1]) {
					skip = true
					break
				}
			}
			if skip {
				continue
			}
			if counted[p.StudentID] == nil {
				counted[p.StudentID] = map[uuid.UUID]bool{}
			}
			counted[p.StudentID][x.ID] = true
		}
	}
	for id, m := range counted {
		out[id] = len(m)
	}
	return out, nil
}

// Quote: sổ buổi + số buổi cần thu cho form lập phiếu (SessionLedger::quote).
type Quote struct {
	Paid           int     `json:"paid"`
	Used           int     `json:"used"`
	Balance        int     `json:"balance"`
	ClassName      *string `json:"class_name"`
	CourseSessions int     `json:"course_sessions"`
	Held           int     `json:"held"`
	Remaining      int     `json:"remaining"`
	Needed         int     `json:"needed"`
	CarryOver      int     `json:"carry_over"`
	// contract: thu tiếp khoản còn nợ | new: khoản khóa kế tiếp (tạo khi duyệt) | none: chưa xếp lớp, chưa có khoản
	Mode                      string     `json:"mode"`
	TuitionID                 *uuid.UUID `json:"tuition_id"`
	UnitPrice                 float64    `json:"unit_price"`
	MaxSessions               int        `json:"max_sessions"`
	ContractRemainingSessions *int       `json:"contract_remaining_sessions"`
	TuitionRemaining          *int64     `json:"tuition_remaining"`
	FeeDue                    int64      `json:"fee_due"`
	Suggested                 int        `json:"suggested"`
}

// quote: class = lớp đang học, không có thì lớp của khoản còn nợ.
func (s *Service) quote(ctx context.Context, q *tuitiondb.Queries, studentID uuid.UUID, class *classInfo, open *tuitiondb.FinanceTuition) (Quote, error) {
	ls, err := s.ledgers(ctx, q, []uuid.UUID{studentID})
	if err != nil {
		return Quote{}, err
	}
	l := ls[studentID]
	if class == nil && open != nil {
		if class, err = s.getClass(ctx, q, open.ClassID); err != nil {
			return Quote{}, err
		}
	}
	var size, held int
	if class != nil {
		size = courseSessions(class)
		h, err := s.heldCounts(ctx, q, []uuid.UUID{class.ID})
		if err != nil {
			return Quote{}, err
		}
		held = h[class.ID]
	} else {
		// Chưa xếp lớp: chưa có buổi nào diễn ra, khóa = số buổi của khoản học phí.
		size = defaultCourseSessions
		if open != nil && val(open.SessionCount) > 0 {
			size = int(*open.SessionCount)
		}
	}
	remaining := max(0, size-held)
	out := Quote{Paid: l.Paid, Used: l.Used, Balance: l.Balance, CourseSessions: size, Held: held, Remaining: remaining,
		Needed: max(0, remaining-l.Balance), CarryOver: max(0, l.Balance-remaining)}
	if class != nil {
		out.ClassName = &class.Name
	}
	switch {
	case open != nil && open.DebtAmount > 0:
		c := contractOf(*open)
		out.Mode = "contract"
		out.TuitionID = &open.ID
		out.UnitPrice = c.unitPrice()
		out.MaxSessions = c.remainingSessions()
		out.ContractRemainingSessions = ptr(out.MaxSessions)
		out.FeeDue = c.feeRemaining()
		out.TuitionRemaining = ptr(c.tuitionRemaining())
	case class != nil:
		out.Mode = "new"
		out.UnitPrice = classUnitPrice(class)
		out.MaxSessions = out.Needed
	default:
		out.Mode = "none"
	}
	out.Suggested = min(out.Needed, out.MaxSessions)
	return out, nil
}

// Calc: bảng tính phiếu thu theo buổi (SessionLedger::calculate).
type Calc struct {
	SessionCount    int   `json:"session_count"`
	SessionValue    int64 `json:"session_value"`
	FeeDue          int64 `json:"fee_due"`
	MaterialFee     int64 `json:"material_fee"`
	ExamFee         int64 `json:"exam_fee"`
	OtherFee        int64 `json:"other_fee"`
	Subtotal        int64 `json:"subtotal"`
	Discount        int64 `json:"discount"`
	TotalDue        int64 `json:"total_due"`
	Extra           int64 `json:"extra"`
	Amount          int64 `json:"amount"`
	TuitionAmount   int64 `json:"tuition_amount"`
	SurchargeAmount int64 `json:"surcharge_amount"`
}

// calculate: tiền buổi (thu hết số buổi còn của hợp đồng thì lấy đúng phần học phí còn lại), học liệu còn nợ của
// hợp đồng, phí thi, khoản khác, giảm trừ (không vượt tiền buổi) và phụ thu.
func calculate(qt Quote, sessions int, items, exam, other, extra, discount int64) Calc {
	sessions = max(0, sessions)
	isContract := qt.Mode == "contract"
	var value int64
	if sessions > 0 && isContract && qt.ContractRemainingSessions != nil && sessions >= *qt.ContractRemainingSessions {
		value = val(qt.TuitionRemaining)
	} else {
		value = int64(math.Round(float64(sessions) * qt.UnitPrice))
	}
	var feeDue int64
	if sessions > 0 && isContract {
		feeDue = qt.FeeDue
	}
	exam, other, items, extra = max(0, exam), max(0, other), max(0, items), max(0, extra)
	discount = min(max(0, discount), value)
	material := items + feeDue
	subtotal := value + material + exam + other
	total := subtotal - discount
	return Calc{SessionCount: sessions, SessionValue: value, FeeDue: feeDue, MaterialFee: material, ExamFee: exam,
		OtherFee: other, Subtotal: subtotal, Discount: discount, TotalDue: total, Extra: extra, Amount: total + extra,
		TuitionAmount: value + feeDue - discount, SurchargeAmount: items + exam + other + extra}
}

// insertTuition tạo khoản học phí: nội dung chuyển khoản và số buổi mặc định như StudentTuition::booted.
func (s *Service) insertTuition(ctx context.Context, q *tuitiondb.Queries, p tuitiondb.CreateTuitionParams) (tuitiondb.FinanceTuition, error) {
	st, err := q.GetStudent(ctx, p.StudentID)
	if err != nil {
		return tuitiondb.FinanceTuition{}, err
	}
	classID := p.ClassID
	if classID == nil {
		classID = st.CurrentClassID
	}
	class, err := s.getClass(ctx, q, classID)
	if err != nil {
		return tuitiondb.FinanceTuition{}, err
	}
	if p.TransferMemo == nil {
		var className *string
		if class != nil {
			className = &class.Name
		}
		if m := transferMemo(st.Code, st.FullName, className); m != "" {
			p.TransferMemo = &m
		}
	}
	if val(p.SessionCount) == 0 {
		p.SessionCount = ptr(int32(courseSessions(class)))
	}
	if p.FeeItems == nil {
		p.FeeItems = []byte("[]")
	}
	if p.Status == "" {
		p.Status = "unpaid"
	}
	return q.CreateTuition(ctx, p)
}

// createCourseTuition: phiếu thu theo buổi cho khóa kế tiếp được duyệt → tạo khoản học phí đúng số buổi của phiếu.
func (s *Service) createCourseTuition(ctx context.Context, q *tuitiondb.Queries, r tuitiondb.FinanceReceipt) (*tuitiondb.FinanceTuition, error) {
	if r.StudentID == nil || val(r.SessionCount) <= 0 {
		return nil, nil
	}
	st, err := q.GetStudent(ctx, *r.StudentID)
	if err != nil {
		return nil, err
	}
	value := r.Amount - r.SurchargeAmount + r.DiscountAmount
	branch := st.BranchID
	if st.ClassBranchID != nil {
		branch = st.ClassBranchID
	}
	notes := fmt.Sprintf("Khoản học phí khóa kế tiếp (%d buổi) tạo từ phiếu thu %s.", *r.SessionCount, r.Code)
	t, err := s.insertTuition(ctx, q, tuitiondb.CreateTuitionParams{
		StudentID: st.ID, ClassID: st.CurrentClassID, BranchID: branch, TotalAmount: value, SessionCount: r.SessionCount,
		FinalAmount: value, DebtAmount: value, DueDate: ptr(s.today()), Status: "unpaid", Notes: &notes,
	})
	if err != nil {
		return nil, err
	}
	return &t, nil
}

// recalculateDebt: đã nộp / còn nợ / trạng thái từ các phiếu đã duyệt của khoản.
func (s *Service) recalculateDebt(ctx context.Context, q *tuitiondb.Queries, id uuid.UUID) (tuitiondb.FinanceTuition, error) {
	t, err := q.LockTuition(ctx, id)
	if err != nil {
		return t, err
	}
	tot, err := q.ApprovedReceiptTotals(ctx, &id)
	if err != nil {
		return t, err
	}
	paid := tot.PaidTotal
	debt := max(0, t.FinalAmount-tot.DiscountTotal-paid)
	today := s.today()
	// Như Laravel (due_date < now()): tới ngày hạn mà chưa nộp đồng nào là quá hạn, trừ khi đang khất nợ / bảo lưu.
	overdue := t.DueDate != nil && !t.DueDate.After(today) && !pausedOn(t.ReminderPausedUntil, today)
	status := debtStatus(debt, paid, overdue)
	if err := q.SetTuitionDebt(ctx, tuitiondb.SetTuitionDebtParams{ID: id, PaidAmount: paid, DebtAmount: debt, Status: status}); err != nil {
		return t, err
	}
	t.PaidAmount, t.DebtAmount, t.Status = paid, debt, status
	return t, nil
}

// pausedOn: đang khất nợ / bảo lưu (tạm dừng nhắc nợ) vào ngày day.
func pausedOn(until *time.Time, day time.Time) bool { return until != nil && until.After(day) }
