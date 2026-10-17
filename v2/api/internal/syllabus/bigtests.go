package syllabus

import (
	"context"
	"crypto/rand"
	"fmt"
	"math/big"
	"sort"
	"strings"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/syllabus/syllabusdb"
)

// Đợt Big Test: Duyệt & phân phối đề, Nhắc lịch (SyllabusController::bigTestDistribution / storeBigTest /
// approveAndDistributeBigTest / assignBigTestStage / bigTestSchedules / sendBigTestReminder).

const (
	testsPageSize     = 15
	ordersPageSize    = 10
	schedulesPageSize = 20

	distributionLink = "/syllabus/big-tests/distribution"
	schedulesLink    = "/syllabus/big-tests/schedules"
	maskedPasscode   = "••••••"
)

func resultsLink(id uuid.UUID) string { return "/syllabus/big-tests/results?test_id=" + id.String() }

func orderLink(id uuid.UUID, status string) string {
	l := distributionLink + "?order=" + id.String()
	if status != "" {
		l += "&order_status=" + status
	}
	return l
}

// newPasscode: passcode đề "MEN" + 4 chữ số 1000–9999 (random_int(1000, 9999)).
func newPasscode() string {
	n, _ := rand.Int(rand.Reader, big.NewInt(9000))
	return fmt.Sprintf("MEN%d", 1000+n.Int64())
}

// newBigTestCode: "BT-YYYY-NNNN" (DocumentCodeGenerator::bigTestCode), khóa theo năm trong tx.
func (s *Service) newBigTestCode(ctx context.Context, q *syllabusdb.Queries) (string, error) {
	year := s.now().In(vnZone).Year()
	if err := q.LockKey(ctx, fmt.Sprintf("syllabus.big_test_code:%d", year)); err != nil {
		return "", err
	}
	prefix := fmt.Sprintf("BT-%d-", year)
	n, err := q.NextBigTestNumber(ctx, prefix)
	if err != nil {
		return "", err
	}
	return fmt.Sprintf("%s%04d", prefix, n), nil
}

// PaperWarning: cảnh báo duyệt đề của đợt thi sắp tới.
type PaperWarning struct {
	Level string `json:"level"` // warn | overdue
	Label string `json:"label"`
}

// paperWindow: ngưỡng SLA duyệt đề đọc một lần cho cả trang.
type paperWindow struct{ warn, approve int }

func (s *Service) paperWindow(ctx context.Context) paperWindow {
	return paperWindow{warn: s.warnDays(ctx), approve: s.approveBeforeDays(ctx)}
}

// daysUntil: số ngày lịch từ hôm nay tới ngày thi.
func (s *Service) daysUntil(at time.Time) int { return daysBetween(s.today(), dateOf(at)) }

// paperWarning: đợt thi trong N ngày tới chưa phân phối đề ('warn'); còn dưới hạn duyệt đề ('overdue').
func (s *Service) paperWarning(w paperWindow, distributed bool, at *time.Time) *PaperWarning {
	if distributed || at == nil || !at.After(s.now()) {
		return nil
	}
	days := s.daysUntil(*at)
	if days > w.warn {
		return nil
	}
	if days < w.approve {
		return &PaperWarning{Level: "overdue", Label: fmt.Sprintf("Quá hạn duyệt đề (trước %d ngày) — còn %d ngày", w.approve, days)}
	}
	return &PaperWarning{Level: "warn", Label: fmt.Sprintf("Cần duyệt đề trước ngày thi %d ngày — còn %d ngày", w.approve, days)}
}

// mainTeacherOf: GV chính / GVNN của lớp thi (xem passcode, link Speaking sau khi phân phối).
func mainTeacherOf(actor *iam.Access, teacher, foreign *uuid.UUID) bool {
	return sameUser(teacher, actor.UserID) || sameUser(foreign, actor.UserID)
}

// passcodeFor: passcode chỉ người duyệt đề, hoặc GV chính / GVNN sau khi phân phối (BigTest::passcodeVisibleTo).
func passcodeFor(actor *iam.Access, passcode *string, distributed bool, teacher, foreign *uuid.UUID) string {
	if actor.Can("big_test.approve") || (distributed && mainTeacherOf(actor, teacher, foreign)) {
		return deref(passcode)
	}
	return maskedPasscode
}

// speakingFor: link phần Speaking (BigTest::speakingLinkVisibleTo).
func speakingFor(actor *iam.Access, url *string, distributed bool, teacher, foreign *uuid.UUID) *string {
	if url == nil || *url == "" {
		return nil
	}
	if actor.Can("big_test.approve") || (distributed && mainTeacherOf(actor, teacher, foreign)) {
		return url
	}
	return nil
}

// stageOptionsFor: chặng có thể gắn cho đợt thi của từng lớp — giáo trình các lượt chặng của lớp + giáo trình theo trình
// độ lớp (stageOptionsForClasses).
func (s *Service) stageOptionsFor(ctx context.Context, q *syllabusdb.Queries, classIDs []uuid.UUID) (map[uuid.UUID][]Option, error) {
	out := map[uuid.UUID][]Option{}
	classIDs = dedupe(classIDs)
	if len(classIDs) == 0 {
		return out, nil
	}
	byClass := map[uuid.UUID][]uuid.UUID{}
	add := func(class, cur uuid.UUID) {
		for _, c := range byClass[class] {
			if c == cur {
				return
			}
		}
		byClass[class] = append(byClass[class], cur)
	}
	assigned, err := q.CurriculaOfClasses(ctx, classIDs)
	if err != nil {
		return nil, err
	}
	for _, a := range assigned {
		if a.ClassID != nil {
			add(*a.ClassID, a.CurriculumID)
		}
	}
	for _, id := range classIDs {
		c, err := q.ClassInfo(ctx, id)
		if isNoRows(err) {
			continue
		}
		if err != nil {
			return nil, err
		}
		cur, err := s.curriculumOfClass(ctx, q, c)
		if err != nil {
			return nil, err
		}
		if cur != nil {
			add(id, *cur)
		}
	}
	all := []uuid.UUID{}
	for _, ids := range byClass {
		all = append(all, ids...)
	}
	stages, err := q.ListStages(ctx, dedupe(all))
	if err != nil {
		return nil, err
	}
	for class, curs := range byClass {
		opts := []Option{}
		for _, st := range stages {
			for _, c := range curs {
				if st.CurriculumID == c {
					opts = append(opts, Option{Value: st.ID.String(), Label: stageLabel(st.Position, st.Name)})
				}
			}
		}
		out[class] = opts
	}
	return out, nil
}

// ───────────── Duyệt & phân phối đề ─────────────

type BigTestRow struct {
	ID            uuid.UUID     `json:"id"`
	Code          string        `json:"code"`
	Title         string        `json:"title"`
	ContentURL    *string       `json:"content_url"`
	SpeakingURL   *string       `json:"speaking_url"`
	ClassID       *uuid.UUID    `json:"class_id"`
	ClassName     *string       `json:"class_name"`
	StageLabel    *string       `json:"stage_label"`
	StageID       string        `json:"stage_id"`
	ScheduledAt   string        `json:"scheduled_at"`
	Room          *string       `json:"room"`
	Place         string        `json:"place,omitempty"`
	Proctor       *string       `json:"proctor"`
	Passcode      string        `json:"passcode"`
	IsDistributed bool          `json:"is_distributed"`
	PaperWarning  *PaperWarning `json:"paper_warning"`
	StageOptions  []Option      `json:"stage_options,omitempty"`
}

type OrderRow struct {
	ID          uuid.UUID `json:"id"`
	ClassLabel  string    `json:"class_label"`
	OrderedAt   time.Time `json:"ordered_at"`
	StageType   string    `json:"stage_type"`
	Teacher     *string   `json:"teacher"`
	ExamDate    string    `json:"exam_date"`
	DueDate     string    `json:"due_date"`
	Overdue     bool      `json:"overdue"`
	SlaWarning  bool      `json:"sla_warning"`
	Status      string    `json:"status"`
	StatusLabel string    `json:"status_label"`
	StatusColor string    `json:"status_color"`
}

type OrderBigTest struct {
	Code        string  `json:"code"`
	ScheduledAt string  `json:"scheduled_at"`
	Room        *string `json:"room"`
}

type OrderDetail struct {
	ID                 uuid.UUID     `json:"id"`
	Code               string        `json:"code"`
	ClassCode          string        `json:"class_code"`
	ClassLabel         string        `json:"class_label"`
	Summary            string        `json:"summary"`
	Status             string        `json:"status"`
	StatusLabel        string        `json:"status_label"`
	StatusColor        string        `json:"status_color"`
	ExamDate           string        `json:"exam_date"`
	Teacher            *string       `json:"teacher"`
	DueDate            string        `json:"due_date"`
	Overdue            bool          `json:"overdue"`
	SlaWarning         bool          `json:"sla_warning"`
	Note               *string       `json:"note"`
	Reviewer           *string       `json:"reviewer"`
	ReviewedAt         string        `json:"reviewed_at"`
	RejectionReason    *string       `json:"rejection_reason"`
	TestLink           *string       `json:"test_link"`
	SpeakingLink       *string       `json:"speaking_link"`
	BigTest            *OrderBigTest `json:"big_test"`
	Reviewing          bool          `json:"reviewing"`
	IsBig              bool          `json:"is_big"`
	DefaultScheduledAt string        `json:"default_scheduled_at"`
	DefaultRoom        *string       `json:"default_room"`
	ClassTests         []Option      `json:"class_tests"`
}

type DistributionFilter struct {
	OrderStatus string // "" = mọi trạng thái
	OrderSearch string
	OrdersPage  int
	TestsPage   int
	OrderID     *uuid.UUID
}

type DistributionPage struct {
	Classes            []Option     `json:"classes"`
	DefaultScheduledAt string       `json:"default_scheduled_at"`
	Orders             []OrderRow   `json:"orders"`
	OrdersPage         Page         `json:"orders_page"`
	OrderStatus        string       `json:"order_status"`
	OrderSearch        string       `json:"order_search"`
	OrderStatusOptions []Option     `json:"order_status_options"`
	PendingOrders      int64        `json:"pending_orders"`
	BigTests           []BigTestRow `json:"big_tests"`
	TestsPage          Page         `json:"tests_page"`
	SelectedOrder      *OrderDetail `json:"selected_order"`
	LeadDays           int          `json:"lead_days"`
	CanReview          bool         `json:"can_review"`
	CanApprove         bool         `json:"can_approve"`
	CanManage          bool         `json:"can_manage"`
}

func classLabel(name *string, code string) string {
	l := deref(name)
	if code != "" {
		l += " - " + code
	}
	return l
}

// orderFlags: quá hạn xử lý / sắp tới hạn (hôm nay, ngày mai) của order còn chờ duyệt.
func (s *Service) orderFlags(status string, due *time.Time) (overdue, warning bool) {
	if status != "pending" || due == nil {
		return false, false
	}
	today := s.today()
	overdue = due.Before(today)
	return overdue, !overdue && !due.After(today.AddDate(0, 0, 1))
}

func orderStatusOptions() []Option {
	out := []Option{}
	for _, k := range []string{"pending", "approved", "rejected"} {
		out = append(out, Option{Value: k, Label: orderStatusLabels[k]})
	}
	return out
}

func orderStatusColor(s string) string { return statusColor(s) }

// BigTestDistribution: màn "Duyệt & phân phối đề Big Test".
func (s *Service) BigTestDistribution(ctx context.Context, actor *iam.Access, f DistributionFilter) (*DistributionPage, error) {
	sc := scopeOf(actor)
	visible, err := s.visibleClassIDs(ctx, actor)
	if err != nil {
		return nil, err
	}
	classes, err := s.q.ClassesByIDs(ctx, syllabusdb.ClassesByIDsParams{Ids: visible, SkipCancelled: true})
	if err != nil {
		return nil, err
	}
	out := &DistributionPage{
		Classes: []Option{}, Orders: []OrderRow{}, BigTests: []BigTestRow{}, OrderStatusOptions: orderStatusOptions(),
		DefaultScheduledAt: s.now().In(vnZone).AddDate(0, 0, 7).Format("2006-01-02T15:04"),
		OrderSearch:        strings.TrimSpace(f.OrderSearch), OrderStatus: f.OrderStatus,
		LeadDays: s.approveBeforeDays(ctx), CanReview: actor.Can("big_test.approve"), CanApprove: actor.SuperAdmin,
		CanManage: actor.Can("syllabus.manage"),
	}
	for _, c := range classes {
		out.Classes = append(out.Classes, Option{Value: c.ID.String(), Label: c.Name})
	}

	var status *string
	if _, ok := orderStatusLabels[f.OrderStatus]; ok {
		status = &f.OrderStatus
	}
	lim, off, page := pageOf(f.OrdersPage, ordersPageSize)
	orders, err := s.q.ListOrders(ctx, syllabusdb.ListOrdersParams{AllRows: sc.all, UserID: &sc.userID, BranchIds: sc.branches,
		Status: status, Search: likePattern(f.OrderSearch), Lim: lim, Off: off})
	if err != nil {
		return nil, err
	}
	total, err := s.q.CountOrders(ctx, syllabusdb.CountOrdersParams{AllRows: sc.all, UserID: &sc.userID, BranchIds: sc.branches,
		Status: status, Search: likePattern(f.OrderSearch)})
	if err != nil {
		return nil, err
	}
	out.OrdersPage = Page{Total: total, Page: page, PageSize: ordersPageSize}
	for _, o := range orders {
		overdue, warn := s.orderFlags(o.Status, o.DueDate)
		out.Orders = append(out.Orders, OrderRow{
			ID: o.ID, ClassLabel: classLabel(&o.ClassName, o.ClassCode), OrderedAt: o.CreatedAt,
			StageType: stageOr(o.StagePosition, o.StageTitle, o.StageName) + " · " + orderTypeLabel(o.TestType),
			Teacher:   o.TeacherName, ExamDate: fmtDate(o.ExamDate), DueDate: fmtDate(o.DueDate), Overdue: overdue, SlaWarning: warn,
			Status: o.Status, StatusLabel: orderStatusLabel(o.Status), StatusColor: orderStatusColor(o.Status),
		})
	}
	pending := "pending"
	if out.PendingOrders, err = s.q.CountOrders(ctx, syllabusdb.CountOrdersParams{AllRows: sc.all, UserID: &sc.userID,
		BranchIds: sc.branches, Status: &pending}); err != nil {
		return nil, err
	}

	lim, off, page = pageOf(f.TestsPage, testsPageSize)
	tests, err := s.q.ListBigTests(ctx, syllabusdb.ListBigTestsParams{AllRows: sc.all, UserID: &sc.userID, BranchIds: sc.branches,
		Lim: lim, Off: off})
	if err != nil {
		return nil, err
	}
	if total, err = s.q.CountBigTests(ctx, syllabusdb.CountBigTestsParams{AllRows: sc.all, UserID: &sc.userID,
		BranchIds: sc.branches}); err != nil {
		return nil, err
	}
	out.TestsPage = Page{Total: total, Page: page, PageSize: testsPageSize}
	ids := []uuid.UUID{}
	for _, t := range tests {
		if t.ClassID != nil {
			ids = append(ids, *t.ClassID)
		}
	}
	options, err := s.stageOptionsFor(ctx, s.q, ids)
	if err != nil {
		return nil, err
	}
	w := s.paperWindow(ctx)
	for _, t := range tests {
		row := s.bigTestRow(actor, w, t)
		row.StageOptions = []Option{}
		if t.ClassID != nil && options[*t.ClassID] != nil {
			row.StageOptions = options[*t.ClassID]
		}
		out.BigTests = append(out.BigTests, row)
	}

	if f.OrderID != nil {
		d, err := s.orderDetail(ctx, actor, *f.OrderID)
		if err != nil {
			return nil, err
		}
		out.SelectedOrder = d
	}
	return out, nil
}

func (s *Service) bigTestRow(actor *iam.Access, w paperWindow, t syllabusdb.ListBigTestsRow) BigTestRow {
	row := BigTestRow{
		ID: t.ID, Code: t.Code, Title: t.Title, ClassID: t.ClassID, ClassName: t.ClassName, ScheduledAt: fmtLocal(t.ScheduledAt),
		Room: t.Room, Proctor: t.ProctorName, IsDistributed: t.IsDistributed,
		Passcode:     passcodeFor(actor, t.Passcode, t.IsDistributed, t.TeacherID, t.ForeignTeacherID),
		SpeakingURL:  speakingFor(actor, t.SpeakingUrl, t.IsDistributed, t.TeacherID, t.ForeignTeacherID),
		PaperWarning: s.paperWarning(w, t.IsDistributed, t.ScheduledAt),
	}
	if t.ContentUrl != nil && *t.ContentUrl != "" && actor.Can("big_test.approve") {
		row.ContentURL = t.ContentUrl
	}
	if t.StageID != nil {
		row.StageID = t.StageID.String()
		if t.StagePosition != nil {
			row.StageLabel = ptr(stageLabel(*t.StagePosition, deref(t.StageTitle)))
		}
	}
	return row
}

// fmtLocal: "02/01/2006 15:04" theo giờ Việt Nam.
func fmtLocal(t *time.Time) string {
	if t == nil {
		return ""
	}
	return t.In(vnZone).Format("02/01/2006 15:04")
}

// orderInScope: order trong phạm vi big_test (BigTestOrder::visibleTo).
func orderInScope(actor *iam.Access, o syllabusdb.GetOrderRow) bool {
	return scopeOf(actor).covers(o.BranchID, o.TeacherID, o.ClassTeacherID, o.ForeignTeacherID, o.AssistantID)
}

func (s *Service) orderDetail(ctx context.Context, actor *iam.Access, id uuid.UUID) (*OrderDetail, error) {
	o, err := s.q.GetOrder(ctx, id)
	if isNoRows(err) || (err == nil && !orderInScope(actor, o)) {
		return nil, ErrNotFound
	}
	if err != nil {
		return nil, err
	}
	label := stageOr(o.StagePosition, o.StageTitle, o.StageName)
	parts := []string{}
	for _, p := range []*string{o.CourseName, o.BranchName} {
		if p != nil && *p != "" {
			parts = append(parts, *p)
		}
	}
	summary := strings.Join(parts, " · ")
	if summary == "" {
		summary = "—"
	}
	overdue, warn := s.orderFlags(o.Status, o.DueDate)
	d := &OrderDetail{
		ID: o.ID, Code: o.Code, ClassCode: o.ClassCode, ClassLabel: classLabel(o.ClassName, o.ClassCode),
		Summary: summary + ". Đề " + orderTypeLabel(o.TestType) + " cho " + label + ".",
		Status:  o.Status, StatusLabel: orderStatusLabel(o.Status), StatusColor: orderStatusColor(o.Status),
		ExamDate: fmtDate(o.ExamDate), Teacher: o.TeacherName, DueDate: fmtDate(o.DueDate), Overdue: overdue, SlaWarning: warn,
		Note: o.Note, Reviewer: o.ReviewerName, ReviewedAt: fmtDateTime(o.ReviewedAt), RejectionReason: o.RejectionReason,
		SpeakingLink: o.SpeakingLink, Reviewing: actor.SuperAdmin && o.Status == "pending", IsBig: o.TestType == "big",
		DefaultRoom: o.ClassRoom, ClassTests: []Option{},
	}
	if actor.Can("big_test.approve") {
		d.TestLink = o.TestLink
	}
	if o.BigTestCode != nil {
		d.BigTest = &OrderBigTest{Code: *o.BigTestCode, ScheduledAt: fmtDateTime(o.BigTestScheduledAt), Room: o.BigTestRoom}
	}
	if o.ExamDate != nil {
		d.DefaultScheduledAt = o.ExamDate.Format(time.DateOnly) + "T08:00"
	}
	if d.Reviewing && d.IsBig {
		tests, err := s.q.OpenClassTests(ctx, &o.ClassID)
		if err != nil {
			return nil, err
		}
		for _, t := range tests {
			l := t.Code + " · " + t.Title
			if t.ScheduledAt != nil {
				l += " · " + fmtLocal(t.ScheduledAt)
			}
			d.ClassTests = append(d.ClassTests, Option{Value: t.ID.String(), Label: l})
		}
	}
	return d, nil
}

// ───────────── Tạo / duyệt / gắn chặng đợt thi ─────────────

type BigTestInput struct {
	Title       string  `json:"title"`
	ClassID     string  `json:"class_id"`
	TestType    string  `json:"test_type"`
	ScheduledAt string  `json:"scheduled_at"`
	Room        string  `json:"room"`
	StageID     *string `json:"syllabus_stage_id"`
}

// CreateBigTest: tạo bản nháp đợt Big Test (cần duyệt trước khi phân phối). Mặc định gắn chặng đang mở của lớp.
func (s *Service) CreateBigTest(ctx context.Context, actor *iam.Access, in BigTestInput) (*Message, error) {
	title, room, testType := strings.TrimSpace(in.Title), strings.TrimSpace(in.Room), strings.TrimSpace(in.TestType)
	switch {
	case title == "":
		return nil, iam.Invalid("Vui lòng nhập tên đợt thi.")
	case runeLen(title) > 255:
		return nil, iam.Invalid("Tên đợt thi tối đa 255 ký tự.")
	case strings.TrimSpace(in.ClassID) == "":
		return nil, iam.Invalid("Vui lòng chọn lớp.")
	case testType == "":
		return nil, iam.Invalid("Vui lòng chọn loại bài thi.")
	case runeLen(testType) > 50:
		return nil, iam.Invalid("Loại bài thi tối đa 50 ký tự.")
	case strings.TrimSpace(in.ScheduledAt) == "":
		return nil, iam.Invalid("Vui lòng chọn ngày giờ thi.")
	case room == "":
		return nil, iam.Invalid("Vui lòng nhập phòng thi.")
	case runeLen(room) > 255:
		return nil, iam.Invalid("Phòng thi tối đa 255 ký tự.")
	}
	at, valid := parseLocalDateTime(in.ScheduledAt)
	if !valid {
		return nil, iam.Invalid("Ngày giờ thi không hợp lệ.")
	}
	classID, err := uuid.Parse(strings.TrimSpace(in.ClassID))
	if err != nil {
		return nil, iam.Invalid("Lớp đã chọn không hợp lệ.")
	}
	visible, err := s.classVisible(ctx, actor, classID)
	if err != nil {
		return nil, err
	}
	if !visible {
		return nil, iam.Invalid("Lớp đã chọn không hợp lệ.")
	}
	c, err := s.q.ClassInfo(ctx, classID)
	if isNoRows(err) {
		return nil, iam.Invalid("Lớp đã chọn không hợp lệ.")
	}
	if err != nil {
		return nil, err
	}
	var stageID *uuid.UUID
	if in.StageID != nil && strings.TrimSpace(*in.StageID) != "" {
		id, valid := optUUID(*in.StageID)
		if !valid || id == nil {
			return nil, iam.Invalid("Chặng đã chọn không tồn tại.")
		}
		if _, err := s.q.GetStage(ctx, *id); isNoRows(err) {
			return nil, iam.Invalid("Chặng đã chọn không tồn tại.")
		} else if err != nil {
			return nil, err
		}
		stageID = id
	} else {
		a, err := s.q.OpenAssignmentOfClass(ctx, &classID)
		if err != nil && !isNoRows(err) {
			return nil, err
		}
		if err == nil {
			stageID = a.StageID
		}
	}
	var msg string
	err = s.inTx(ctx, func(tx pgx.Tx, q *syllabusdb.Queries) error {
		code, err := s.newBigTestCode(ctx, q)
		if err != nil {
			return err
		}
		bt, err := q.InsertBigTest(ctx, syllabusdb.InsertBigTestParams{
			Code: code, Title: title, ClassID: &classID, StageID: stageID, TestType: testType, ScheduledAt: &at, Room: &room,
			ProctorID: &actor.UserID, Passcode: ptr(newPasscode()), CreatedBy: &actor.UserID,
		})
		if err != nil {
			return err
		}
		msg = fmt.Sprintf("Đã tạo bản nháp Big Test %s (%s); cần duyệt trước khi phân phối.", title, code)
		return record(ctx, tx, &actor.UserID, audit.EventCreated, "big_test.create", "big_test", bt.ID, code+" · "+title,
			"Tạo đợt Big Test "+code+" lớp "+c.Name+" lúc "+fmtDateTime(&at)+".", &c.BranchID)
	})
	if err != nil {
		return nil, err
	}
	return ok(msg), nil
}

// getBigTest: đợt thi trong phạm vi big_test của người dùng (BigTest::isAccessibleBy); ngoài phạm vi → 404.
func (s *Service) getBigTest(ctx context.Context, actor *iam.Access, id uuid.UUID) (syllabusdb.GetBigTestRow, error) {
	t, err := s.q.GetBigTest(ctx, id)
	if isNoRows(err) {
		return t, ErrTestNotFound
	}
	if err != nil {
		return t, err
	}
	sc := scopeOf(actor)
	if !sc.all && (t.ClassID == nil || !sc.covers(t.BranchID, t.TeacherID, t.ForeignTeacherID, t.AssistantID)) {
		return t, ErrTestNotFound
	}
	return t, nil
}

// closeTasks: đề đã phân phối → đóng việc "Duyệt & phân phối đề" (nil = chưa gắn phân hệ Công việc).
func (s *Service) closeTasks(ctx context.Context, tx pgx.Tx, id *uuid.UUID) error {
	if s.tasks == nil {
		return nil
	}
	_, err := s.tasks.CloseBigTestTasks(ctx, tx, id)
	return err
}

// ApproveBigTest: Admin duyệt & phân phối đề (GV nhập được kết quả), tự đóng việc duyệt đề.
func (s *Service) ApproveBigTest(ctx context.Context, actor *iam.Access, id uuid.UUID) (*Message, error) {
	if err := requireAdmin(actor); err != nil {
		return nil, err
	}
	t, err := s.getBigTest(ctx, actor, id)
	if err != nil {
		return nil, err
	}
	err = s.inTx(ctx, func(tx pgx.Tx, q *syllabusdb.Queries) error {
		n, err := q.DistributeBigTest(ctx, syllabusdb.DistributeBigTestParams{By: &actor.UserID, ID: id})
		if err != nil {
			return err
		}
		if err := s.closeTasks(ctx, tx, &id); err != nil {
			return err
		}
		if n == 0 {
			return nil
		}
		return record(ctx, tx, &actor.UserID, audit.EventUpdated, "big_test.distribute", "big_test", id, t.Code+" · "+t.Title,
			"Duyệt & phân phối đề "+t.Code+".", t.BranchID)
	})
	if err != nil {
		return nil, err
	}
	return ok("Đã duyệt và phân phối đề " + t.Code + "."), nil
}

// AssignBigTestStage: gắn lại chặng cho đợt thi; đợt thi đã trả đủ kết quả và là chặng đang mở → đóng chặng, mở chặng kế.
func (s *Service) AssignBigTestStage(ctx context.Context, actor *iam.Access, id uuid.UUID, stage string) (*Message, error) {
	t, err := s.getBigTest(ctx, actor, id)
	if err != nil {
		return nil, err
	}
	stageID, okID := optUUID(stage)
	if !okID {
		return nil, iam.Invalid("Chặng không thuộc giáo trình của lớp thi.")
	}
	var label string
	if stageID != nil {
		valid := false
		if t.ClassID != nil {
			opts, err := s.stageOptionsFor(ctx, s.q, []uuid.UUID{*t.ClassID})
			if err != nil {
				return nil, err
			}
			for _, o := range opts[*t.ClassID] {
				if o.Value == stageID.String() {
					valid, label = true, o.Label
				}
			}
		}
		if !valid {
			return nil, iam.Invalid("Chặng không thuộc giáo trình của lớp thi.")
		}
	}
	var o *outcome
	err = s.inTx(ctx, func(tx pgx.Tx, q *syllabusdb.Queries) error {
		if err := q.SetBigTestStage(ctx, syllabusdb.SetBigTestStageParams{StageID: stageID, ID: id}); err != nil {
			return err
		}
		desc := "Bỏ gắn chặng của đợt thi " + t.Code + "."
		if stageID != nil {
			desc = "Gắn đợt thi " + t.Code + " vào " + label + "."
		}
		if err := record(ctx, tx, &actor.UserID, audit.EventUpdated, "big_test.assign_stage", "big_test", id, t.Code+" · "+t.Title, desc,
			t.BranchID); err != nil {
			return err
		}
		if stageID == nil {
			return nil
		}
		var err error
		o, err = s.syncBigTest(ctx, tx, q, id, &actor.UserID)
		return err
	})
	if err != nil {
		return nil, err
	}
	if stageID == nil {
		return ok("Đã bỏ gắn chặng của đợt thi " + t.Code + "."), nil
	}
	return ok("Đã gắn đợt thi " + t.Code + " vào " + label + "." + describe(o)), nil
}

// ───────────── Nhắc lịch Big Test ─────────────

type UpcomingStage struct {
	ID         uuid.UUID `json:"id"`
	Code       string    `json:"code"`
	ClassName  string    `json:"class_name"`
	StageLabel string    `json:"stage_label"`
	Date       string    `json:"date"`
	Exam       string    `json:"exam"`
	DaysLeft   int       `json:"days_left"`
}

type SchedulesPage struct {
	Upcoming  []UpcomingStage `json:"upcoming"`
	Urgent    int             `json:"urgent"`
	BigTests  []BigTestRow    `json:"big_tests"`
	Page      Page            `json:"page"`
	CanManage bool            `json:"can_manage"`
}

// BigTestSchedules: chặng đang mở sắp tới hạn thi (trong N ngày) mà đề chưa duyệt + danh sách đợt thi.
func (s *Service) BigTestSchedules(ctx context.Context, actor *iam.Access, page int) (*SchedulesPage, error) {
	out := &SchedulesPage{Upcoming: []UpcomingStage{}, BigTests: []BigTestRow{}, CanManage: actor.Can("syllabus.manage")}
	as, err := s.openOfVisible(ctx, actor, false)
	if err != nil {
		return nil, err
	}
	plans, err := s.stageExamPlans(ctx, as)
	if err != nil {
		return nil, err
	}
	today := s.today()
	until := today.AddDate(0, 0, s.warnDays(ctx))
	for _, a := range as {
		p := plans[a.ID]
		if p.date == nil || p.exam == "approved" || p.date.Before(today) || p.date.After(until) {
			continue
		}
		left := daysBetween(today, *p.date)
		out.Upcoming = append(out.Upcoming, UpcomingStage{
			ID: a.ID, Code: assignmentCode(a.No, a.OpenedAt, a.CreatedAt), ClassName: a.ClassName,
			StageLabel: stageOr(a.StagePosition, a.StageTitle, deref(a.StageName)), Date: fmtDate(p.date), Exam: p.exam, DaysLeft: left,
		})
		if left <= 2 {
			out.Urgent++
		}
	}
	sort.SliceStable(out.Upcoming, func(i, j int) bool { return out.Upcoming[i].DaysLeft < out.Upcoming[j].DaysLeft })

	sc := scopeOf(actor)
	lim, off, pg := pageOf(page, schedulesPageSize)
	tests, err := s.q.ListBigTests(ctx, syllabusdb.ListBigTestsParams{AllRows: sc.all, UserID: &sc.userID, BranchIds: sc.branches,
		Lim: lim, Off: off})
	if err != nil {
		return nil, err
	}
	total, err := s.q.CountBigTests(ctx, syllabusdb.CountBigTestsParams{AllRows: sc.all, UserID: &sc.userID, BranchIds: sc.branches})
	if err != nil {
		return nil, err
	}
	out.Page = Page{Total: total, Page: pg, PageSize: schedulesPageSize}
	w := s.paperWindow(ctx)
	for _, t := range tests {
		row := s.bigTestRow(actor, w, t)
		row.ContentURL, row.SpeakingURL = nil, nil
		branch := deref(t.BranchName)
		if branch == "" {
			branch = "—"
		}
		row.Place = deref(t.Room) + " · " + branch
		out.BigTests = append(out.BigTests, row)
	}
	return out, nil
}

// SendBigTestReminder: nhắc lịch thi tới học viên đang học / bảo lưu của lớp (Cổng PH/HS) và báo lại người gửi.
func (s *Service) SendBigTestReminder(ctx context.Context, actor *iam.Access, id uuid.UUID) (*Message, error) {
	t, err := s.getBigTest(ctx, actor, id)
	if err != nil {
		return nil, err
	}
	if t.ClassID == nil || t.ClassDeletedAt != nil {
		return nil, iam.Invalid("Đợt thi chưa gắn lớp học.")
	}
	if t.ScheduledAt == nil {
		return nil, iam.Invalid("Đợt thi chưa có lịch giờ thi.")
	}
	students, err := s.q.ReminderStudents(ctx, t.ClassID)
	if err != nil {
		return nil, err
	}
	when := fmtDateTime(t.ScheduledAt)
	className := deref(t.ClassName)
	err = s.inTx(ctx, func(tx pgx.Tx, _ *syllabusdb.Queries) error {
		for _, st := range students {
			if st.UserID == nil {
				continue
			}
			// Mỗi học viên chỉ nhận 1 lần cho mỗi đợt thi (firstOrCreate theo BIGTEST-REMIND-{test}-{student}).
			if _, err := notify.SendOnce(ctx, tx, []uuid.UUID{*st.UserID}, "BIGTEST-REMIND-"+t.ID.String()+"-"+st.ID.String(),
				notify.Notification{
					Type: "big_test_reminder", Title: "Nhắc lịch thi: " + t.Title,
					Body: "Học viên " + st.FullName + " có bài thi \"" + t.Title + "\" lúc " + when + " tại phòng " + deref(t.Room) +
						". Vui lòng có mặt sớm 15 phút.",
					Data: map[string]any{"big_test_id": t.ID.String(), "student_id": st.ID.String()}, CreatedBy: &actor.UserID,
				}); err != nil {
				return err
			}
		}
		if err := send(ctx, tx, []uuid.UUID{actor.UserID}, notify.Notification{
			Type: "info", Title: "Đã gửi nhắc lịch Big Test: " + t.Title,
			Body: fmt.Sprintf("Đợt thi [%s] %s lúc %s — đã gửi nhắc tới %d học viên của lớp %s.", t.Code, t.Title, when, len(students), className),
			Link: schedulesLink,
		}); err != nil {
			return err
		}
		return record(ctx, tx, &actor.UserID, audit.EventUpdated, "big_test.remind", "big_test", t.ID, t.Code+" · "+t.Title,
			fmt.Sprintf("Gửi nhắc lịch thi tới %d học viên lớp %s.", len(students), className), t.BranchID)
	})
	if err != nil {
		return nil, err
	}
	return ok(fmt.Sprintf("Đã gửi nhắc lịch %s tới %d học viên của lớp %s.", t.Title, len(students), className)), nil
}
