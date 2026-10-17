package syllabus

import (
	"context"
	"crypto/rand"
	"math/big"
	"strings"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/syllabus/syllabusdb"
)

// Order đề: GV order đề Mini / Big Test cho chặng đang mở (TeacherPortalController::orderTest / submitOrderTest),
// Admin duyệt & phân phối hoặc từ chối (SyllabusController::approveBigTestOrder / rejectBigTestOrder).

// newOrderCode: "ORDTEST-" + 6 ký tự in hoa / số ngẫu nhiên (Str::random(6)).
func newOrderCode() string {
	const alphabet = "ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789"
	b := make([]byte, 6)
	for i := range b {
		n, _ := rand.Int(rand.Reader, big.NewInt(int64(len(alphabet))))
		b[i] = alphabet[n.Int64()]
	}
	return "ORDTEST-" + string(b)
}

// approverIDs: người duyệt Big Test (chỉ Admin từ 06/10/2026 — BigTestSlaService::approverIdsOrAdmins).
func approverIDs(ctx context.Context, db notify.DB) ([]uuid.UUID, error) {
	return iam.AdminIDs(ctx, db)
}

type OrderApproval struct {
	TestLink     string `json:"test_link"`
	SpeakingLink string `json:"speaking_link"`
	BigTestID    string `json:"big_test_id"`
	ScheduledAt  string `json:"scheduled_at"`
	Room         string `json:"room"`
	Title        string `json:"title"`
}

// ApproveOrder: duyệt & phân phối order đề. Order Big Test gắn đợt thi có sẵn của lớp, hoặc tự tạo đợt thi từ ngày giờ +
// phòng thi nhập ở form duyệt (gắn chặng đang mở của lớp) và phân phối luôn.
func (s *Service) ApproveOrder(ctx context.Context, actor *iam.Access, id uuid.UUID, in OrderApproval) (*Message, error) {
	if err := requireAdmin(actor); err != nil {
		return nil, err
	}
	o, err := s.q.GetOrder(ctx, id)
	if isNoRows(err) {
		return nil, ErrNotFound
	}
	if err != nil {
		return nil, err
	}
	if o.Status != "pending" {
		return nil, errAlreadyHandled
	}
	isBig := o.TestType == "big"
	link, speaking := strings.TrimSpace(in.TestLink), strings.TrimSpace(in.SpeakingLink)
	switch {
	case link == "":
		return nil, iam.Invalid("Vui lòng nhập link đề trước khi phê duyệt.")
	case !validURL(link):
		return nil, iam.Invalid("Link đề không hợp lệ.")
	case runeLen(link) > 500:
		return nil, iam.Invalid("Link đề tối đa 500 ký tự.")
	case speaking != "" && !validURL(speaking):
		return nil, iam.Invalid("Link phần Speaking không hợp lệ.")
	case runeLen(speaking) > 500:
		return nil, iam.Invalid("Link phần Speaking tối đa 500 ký tự.")
	case runeLen(strings.TrimSpace(in.Title)) > 255:
		return nil, iam.Invalid("Tên đợt thi tối đa 255 ký tự.")
	}
	testID, valid := optUUID(in.BigTestID)
	if !valid {
		return nil, iam.Invalid("Đợt Big Test không thuộc lớp của order.")
	}
	if testID != nil {
		inClass, err := s.q.BigTestInClass(ctx, syllabusdb.BigTestInClassParams{ID: *testID, ClassID: &o.ClassID})
		if err != nil {
			return nil, err
		}
		if !inClass {
			return nil, iam.Invalid("Đợt Big Test không thuộc lớp của order.")
		}
	}
	var at time.Time
	room := strings.TrimSpace(in.Room)
	create := isBig && testID == nil
	if create {
		if strings.TrimSpace(in.ScheduledAt) == "" {
			return nil, iam.Invalid("Vui lòng chọn ngày giờ thi để tạo đợt Big Test (hoặc gắn đợt thi có sẵn).")
		}
		if at, valid = parseLocalDateTime(in.ScheduledAt); !valid {
			return nil, iam.Invalid("Ngày giờ thi không hợp lệ.")
		}
		if room == "" {
			return nil, iam.Invalid("Vui lòng nhập phòng thi để tạo đợt Big Test (hoặc gắn đợt thi có sẵn).")
		}
		if runeLen(room) > 255 {
			return nil, iam.Invalid("Phòng thi tối đa 255 ký tự.")
		}
	}
	var speakingPtr *string
	if speaking != "" {
		speakingPtr = &speaking
	}
	orderStage := stageOr(o.StagePosition, o.StageTitle, o.StageName)
	createdNote := ""
	err = s.inTx(ctx, func(tx pgx.Tx, q *syllabusdb.Queries) error {
		if create {
			bt, err := s.createBigTestForOrder(ctx, q, actor, o, orderStage, strings.TrimSpace(in.Title), at, room, link, speakingPtr)
			if err != nil {
				return err
			}
			testID = &bt.ID
			createdNote = " Đã tạo đợt thi " + bt.Code + " lúc " + fmtDateTime(bt.ScheduledAt) + " tại " + room + "."
			if err := record(ctx, tx, &actor.UserID, audit.EventCreated, "big_test.create", "big_test", bt.ID, bt.Code,
				"Tạo đợt Big Test "+bt.Code+" khi duyệt order "+o.Code+".", o.BranchID); err != nil {
				return err
			}
		}
		n, err := q.ApproveOrder(ctx, syllabusdb.ApproveOrderParams{TestLink: &link, SpeakingLink: speakingPtr, BigTestID: testID,
			ReviewedBy: &actor.UserID, ID: id})
		if err != nil {
			return err
		}
		if n == 0 {
			return errAlreadyHandled
		}
		if testID != nil {
			if err := q.ApplyOrderToBigTest(ctx, syllabusdb.ApplyOrderToBigTestParams{ContentUrl: &link, SpeakingUrl: speakingPtr,
				StageID: o.StageID, ID: *testID}); err != nil {
				return err
			}
			if _, err := q.DistributeBigTest(ctx, syllabusdb.DistributeBigTestParams{By: &actor.UserID, ID: *testID}); err != nil {
				return err
			}
			if err := s.closeTasks(ctx, tx, testID); err != nil {
				return err
			}
		}
		if err := record(ctx, tx, &actor.UserID, audit.EventUpdated, "big_test_order.approve", "big_test_order", o.ID, o.Code,
			"Duyệt & phân phối order đề "+o.Code+" lớp "+deref(o.ClassName)+"."+createdNote, o.BranchID); err != nil {
			return err
		}
		return notifyReview(ctx, tx, o.TeacherID, "Order đề đã được duyệt",
			"Đề "+orderTypeLabel(o.TestType)+" chặng \""+o.StageName+"\" lớp "+deref(o.ClassName)+" đã được phân phối."+createdNote,
			orderLink(o.ID, "approved"), actor.UserID)
	})
	if err != nil {
		return nil, err
	}
	return okID("Đã duyệt và phân phối đề cho order "+o.Code+"."+createdNote, o.ID), nil
}

// createBigTestForOrder: đợt thi cho order Big Test vừa duyệt — chặng đang mở của lớp (không có thì chặng của order),
// giám thị = GV chính của lớp (không có thì GV order); phân phối ở bước duyệt.
func (s *Service) createBigTestForOrder(ctx context.Context, q *syllabusdb.Queries, actor *iam.Access, o syllabusdb.GetOrderRow,
	orderStage, title string, at time.Time, room, link string, speaking *string) (syllabusdb.InsertBigTestRow, error) {
	stageID, label := o.StageID, orderStage
	var bigTitle *string
	open, err := q.OpenAssignmentOfClass(ctx, &o.ClassID)
	if err != nil && !isNoRows(err) {
		return syllabusdb.InsertBigTestRow{}, err
	}
	if err == nil {
		stageID, label, bigTitle = open.StageID, stageOr(open.StagePosition, open.StageTitle, deref(open.StageName)), open.BigTestTitle
	}
	if title == "" {
		base := trimmed(bigTitle)
		if base == "" {
			base = "Big Test " + label
		}
		title = strings.Trim(base+" · "+deref(o.ClassName), " ·")
	}
	proctor := o.ClassTeacherID
	if proctor == nil {
		proctor = o.TeacherID
	}
	code, err := s.newBigTestCode(ctx, q)
	if err != nil {
		return syllabusdb.InsertBigTestRow{}, err
	}
	return q.InsertBigTest(ctx, syllabusdb.InsertBigTestParams{
		Code: code, Title: cut(title, 255), ClassID: &o.ClassID, StageID: stageID, TestType: "stage_end", ScheduledAt: &at,
		Room: &room, ProctorID: proctor, Passcode: ptr(newPasscode()), ContentUrl: &link, SpeakingUrl: speaking,
		CreatedBy: &actor.UserID,
	})
}

// RejectOrder: từ chối order đề (bắt buộc lý do), báo GV.
func (s *Service) RejectOrder(ctx context.Context, actor *iam.Access, id uuid.UUID, reason string) (*Message, error) {
	if err := requireAdmin(actor); err != nil {
		return nil, err
	}
	o, err := s.q.GetOrder(ctx, id)
	if isNoRows(err) {
		return nil, ErrNotFound
	}
	if err != nil {
		return nil, err
	}
	if o.Status != "pending" {
		return nil, errAlreadyHandled
	}
	reason = strings.TrimSpace(reason)
	if reason == "" {
		return nil, iam.Invalid("Vui lòng nhập lý do từ chối.")
	}
	if runeLen(reason) > 2000 {
		return nil, iam.Invalid("Lý do từ chối tối đa 2000 ký tự.")
	}
	err = s.inTx(ctx, func(tx pgx.Tx, q *syllabusdb.Queries) error {
		n, err := q.RejectOrder(ctx, syllabusdb.RejectOrderParams{RejectionReason: &reason, ReviewedBy: &actor.UserID, ID: id})
		if err != nil {
			return err
		}
		if n == 0 {
			return errAlreadyHandled
		}
		if err := record(ctx, tx, &actor.UserID, audit.EventUpdated, "big_test_order.reject", "big_test_order", o.ID, o.Code,
			"Từ chối order đề "+o.Code+": "+reason, o.BranchID); err != nil {
			return err
		}
		return notifyReview(ctx, tx, o.TeacherID, "Order đề bị từ chối",
			"Order "+o.Code+" lớp "+deref(o.ClassName)+" — Lý do: "+reason, orderLink(o.ID, "rejected"), actor.UserID)
	})
	if err != nil {
		return nil, err
	}
	return okID("Đã từ chối order "+o.Code+".", o.ID), nil
}

// ───────────── Cổng GV: Order đề test ─────────────

// orderClass: lớp GV được order đề — GV / GVNN / TA của lớp, hoặc làm thay GV (attendance_student.record_any + lớp
// trong phạm vi Lớp học).
func (s *Service) orderClass(ctx context.Context, actor *iam.Access, classID uuid.UUID) (syllabusdb.ClassInfoRow, error) {
	c, err := s.q.ClassInfo(ctx, classID)
	if isNoRows(err) {
		return c, ErrClassNotFound
	}
	if err != nil {
		return c, err
	}
	if sameUser(c.TeacherID, actor.UserID) || sameUser(c.AssistantID, actor.UserID) || sameUser(c.ForeignTeacherID, actor.UserID) {
		return c, nil
	}
	if actor.Can("attendance_student.record_any") {
		visible, err := s.classVisible(ctx, actor, classID)
		if err != nil {
			return c, err
		}
		if visible {
			return c, nil
		}
	}
	return c, forbidden("Bạn không được phân công phụ trách lớp này.")
}

type OrderClassroom struct {
	ID      uuid.UUID `json:"id"`
	Name    string    `json:"name"`
	Program *string   `json:"program"`
}

type OrderOpenStage struct {
	Label               string `json:"label"`
	StartedAt           string `json:"started_at"`
	AssignedChapters    string `json:"assigned_chapters"`
	ExpectedBigTestDate string `json:"expected_big_test_date"`
}

type OrderClosedStage struct {
	ID       uuid.UUID `json:"id"`
	Label    string    `json:"label"`
	ClosedAt string    `json:"closed_at"`
}

type OrderRequest struct {
	ID              uuid.UUID `json:"id"`
	CreatedAt       string    `json:"created_at"`
	TypeLabel       string    `json:"type_label"`
	StageLabel      string    `json:"stage_label"`
	ExamDate        string    `json:"exam_date"`
	DueDate         string    `json:"due_date"`
	Note            *string   `json:"note"`
	Status          string    `json:"status"`
	StatusLabel     string    `json:"status_label"`
	StatusColor     string    `json:"status_color"`
	TestLink        *string   `json:"test_link"`
	SpeakingLink    *string   `json:"speaking_link"`
	RejectionReason *string   `json:"rejection_reason"`
}

type OrderTestPage struct {
	Classroom         OrderClassroom     `json:"classroom"`
	OpenAssignment    *OrderOpenStage    `json:"open_assignment"`
	ClosedAssignments []OrderClosedStage `json:"closed_assignments"`
	LeadDays          int                `json:"lead_days"`
	Today             string             `json:"today"`
	Requests          []OrderRequest     `json:"requests"`
}

// OrderTest: màn "Order đề test" của một lớp — chặng đang mở, chặng đã đóng, lịch sử order (10 gần nhất).
func (s *Service) OrderTest(ctx context.Context, actor *iam.Access, classID uuid.UUID) (*OrderTestPage, error) {
	c, err := s.orderClass(ctx, actor, classID)
	if err != nil {
		return nil, err
	}
	as, err := s.q.ClassAssignmentHistory(ctx, &classID)
	if err != nil {
		return nil, err
	}
	out := &OrderTestPage{
		Classroom:         OrderClassroom{ID: c.ID, Name: c.Name, Program: c.CourseName},
		ClosedAssignments: []OrderClosedStage{}, Requests: []OrderRequest{},
		LeadDays: s.approveBeforeDays(ctx), Today: s.today().Format(time.DateOnly),
	}
	for _, a := range as {
		label := stageOr(a.StagePosition, a.StageTitle, deref(a.StageName))
		if a.Status == "in_progress" {
			if out.OpenAssignment == nil {
				out.OpenAssignment = &OrderOpenStage{Label: label, StartedAt: fmtDate(localDate(firstTime(a.OpenedAt, &a.CreatedAt))),
					AssignedChapters: a.AssignedChapters, ExpectedBigTestDate: isoDate(a.ExpectedBigTestDate)}
			}
			continue
		}
		out.ClosedAssignments = append(out.ClosedAssignments, OrderClosedStage{ID: a.ID, Label: label, ClosedAt: fmtDate(localDate(a.ClosedAt))})
	}
	orders, err := s.q.ClassOrderHistory(ctx, classID)
	if err != nil {
		return nil, err
	}
	canApprove := actor.Can("big_test.approve")
	for _, o := range orders {
		r := OrderRequest{
			ID: o.ID, CreatedAt: fmtLocal(&o.CreatedAt), TypeLabel: orderTypeLabel(o.TestType),
			StageLabel: stageOr(o.StagePosition, o.StageTitle, o.StageName), ExamDate: fmtDate(o.ExamDate), DueDate: fmtDate(o.DueDate),
			Note: o.Note, Status: o.Status, StatusLabel: orderStatusLabel(o.Status), StatusColor: orderStatusColor(o.Status),
		}
		switch o.Status {
		case "pending":
			r.StatusLabel = "Đã order - Chờ HT duyệt"
		case "approved":
			// GV chỉ xem phần Speaking sau khi phân phối; link đề đầy đủ chỉ người duyệt đề xem.
			r.SpeakingLink = o.SpeakingLink
			if canApprove {
				r.TestLink = o.TestLink
			}
		case "rejected":
			r.RejectionReason = o.RejectionReason
		}
		out.Requests = append(out.Requests, r)
	}
	return out, nil
}

type OrderTestInput struct {
	TestType string `json:"test_type"`
	ExamDate string `json:"exam_date"`
	Note     string `json:"note"`
}

// SubmitOrderTest: GV gửi order đề cho chặng đang mở của lớp; báo người duyệt Big Test.
func (s *Service) SubmitOrderTest(ctx context.Context, actor *iam.Access, classID uuid.UUID, in OrderTestInput) (*Message, error) {
	c, err := s.orderClass(ctx, actor, classID)
	if err != nil {
		return nil, err
	}
	testType := strings.TrimSpace(in.TestType)
	if testType == "" {
		return nil, iam.Invalid("Vui lòng chọn loại đề.")
	}
	if _, known := orderTypeLabels[testType]; !known {
		return nil, iam.Invalid("Loại đề không hợp lệ.")
	}
	var exam *time.Time
	if strings.TrimSpace(in.ExamDate) != "" {
		d, valid := parseDay(in.ExamDate)
		if !valid {
			return nil, iam.Invalid("Ngày thi không hợp lệ.")
		}
		if d.Before(s.today()) {
			return nil, iam.Invalid("Ngày thi phải từ hôm nay trở đi.")
		}
		exam = &d
	}
	note := optText(&in.Note)
	if tooLong(note, 1000) {
		return nil, iam.Invalid("Ghi chú tối đa 1000 ký tự.")
	}
	a, err := s.q.OpenAssignmentOfClass(ctx, &classID)
	if isNoRows(err) {
		return nil, iam.Invalid("Lớp chưa được giao chặng nào đang mở — liên hệ Học thuật mở chặng trước khi order đề.")
	}
	if err != nil {
		return nil, err
	}
	label := stageOr(a.StagePosition, a.StageTitle, deref(a.StageName))
	lead := s.approveBeforeDays(ctx)
	due := s.today().AddDate(0, 0, lead)
	if exam != nil {
		// Đề phải phân phối trước ngày thi N ngày, không sớm hơn hôm nay.
		due = exam.AddDate(0, 0, -lead)
		if due.Before(s.today()) {
			due = s.today()
		}
	}
	var created syllabusdb.InsertOrderRow
	err = s.inTx(ctx, func(tx pgx.Tx, q *syllabusdb.Queries) error {
		if err := q.LockKey(ctx, "syllabus.class:"+classID.String()); err != nil {
			return err
		}
		dup, err := q.DuplicatePendingOrder(ctx, syllabusdb.DuplicatePendingOrderParams{ClassID: classID, TestType: testType,
			StageID: a.StageID, StageName: label})
		if err != nil {
			return err
		}
		if dup {
			return iam.Invalid("Đã có order đề cho " + label + " đang chờ Học thuật duyệt.")
		}
		created, err = q.InsertOrder(ctx, syllabusdb.InsertOrderParams{Code: newOrderCode(), ClassID: classID, StageID: a.StageID,
			TeacherID: &actor.UserID, StageName: label, TestType: testType, ExamDate: exam, DueDate: &due, Note: note})
		if err != nil {
			return err
		}
		if err := record(ctx, tx, &actor.UserID, audit.EventCreated, "big_test_order.create", "big_test_order", created.ID, created.Code,
			"Order đề "+orderTypeLabel(testType)+" cho "+label+" lớp "+c.Name+".", &c.BranchID); err != nil {
			return err
		}
		ids, err := approverIDs(ctx, tx)
		if err != nil {
			return err
		}
		name, err := q.UserName(ctx, actor.UserID)
		if err != nil {
			return err
		}
		body := "Giáo viên " + name + " yêu cầu đề " + orderTypeLabel(testType) + " cho chặng \"" + label + "\" của lớp " + c.Name
		if note != nil {
			body += " — Ghi chú: " + *note
		}
		return send(ctx, tx, ids, notify.Notification{
			Type: "big_test_order", Title: "GV yêu cầu đề test: " + name, Body: body, Link: orderLink(created.ID, ""),
			Data: map[string]any{"big_test_order_id": created.ID.String()}, CreatedBy: &actor.UserID, Except: &actor.UserID,
		})
	})
	if err != nil {
		return nil, err
	}
	return okID("Đã gửi yêu cầu đề test tới Ban Học thuật.", created.ID), nil
}
