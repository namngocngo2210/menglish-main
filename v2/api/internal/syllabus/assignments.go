package syllabus

import (
	"context"
	"fmt"
	"slices"
	"strings"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/files"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/syllabus/syllabusdb"
)

// Chặng của lớp: giao chặng, sửa / đóng tay, ngày dự kiến Big Test, Chặng đang dạy, Xem bài giảng
// (SyllabusController::assignments / storeAssignment / updateAssignment / closeAssignment / teachingStages /
// updateExpectedBigTestDate / teacherView).

const assignmentsPageSize = 20

type Assignment struct {
	ID                  uuid.UUID  `json:"id"`
	Code                string     `json:"code"`
	ClassID             *uuid.UUID `json:"class_id"`
	ClassName           string     `json:"class_name"`
	ClassPlace          string     `json:"class_place"`
	Curriculum          string     `json:"curriculum"`
	Teacher             string     `json:"teacher"`
	TeacherCode         string     `json:"teacher_code"`
	UserID              uuid.UUID  `json:"user_id"`
	StageLabel          string     `json:"stage_label"`
	AssignedChapters    string     `json:"assigned_chapters"`
	ExtraSessions       int32      `json:"extra_sessions"`
	StartDate           string     `json:"start_date"`
	ClosedAt            string     `json:"closed_at"`
	CloseReason         *string    `json:"close_reason"`
	Deadline            string     `json:"deadline"`
	IsOpen              bool       `json:"is_open"`
	Status              string     `json:"status"`
	StatusLabel         string     `json:"status_label"`
	CurriculumCompleted bool       `json:"curriculum_completed"`
	ClosingBigTest      *string    `json:"closing_big_test"`
}

type ClassRef struct {
	ID   uuid.UUID `json:"id"`
	Name string    `json:"name"`
	Code string    `json:"code"`
}

type AssignmentFilter struct {
	Search  string
	Status  string
	ClassID *uuid.UUID
	Page    int
}

type AssignmentsPage struct {
	Assignments     []Assignment          `json:"assignments"`
	Teachers        []Option              `json:"teachers"`
	Curriculums     []CurriculumRef       `json:"curriculums"`
	Classes         []ClassRef            `json:"classes"`
	ClassCurriculum map[string]*uuid.UUID `json:"class_curriculum"`
	ClassOpenStage  map[string]string     `json:"class_open_stage"`
	CanManage       bool                  `json:"can_manage"`
	CanOverride     bool                  `json:"can_override"`
	CanViewLog      bool                  `json:"can_view_log"`
	Page
}

func localDate(t *time.Time) *time.Time {
	if t == nil {
		return nil
	}
	return ptr(dateOf(*t))
}

// teacherOptions: giáo viên chọn khi giao chặng — chỉ nhân sự thuộc chi nhánh trong phạm vi Lớp học của người xem
// (User::scopePickableBy, SyllabusController::assignments).
func (s *Service) teacherOptions(ctx context.Context, actor *iam.Access) ([]Option, error) {
	rows, err := s.q.TeacherOptions(ctx)
	if err != nil {
		return nil, err
	}
	pickable, err := iam.PickableUsers(ctx, s.pool, actor, "class")
	if err != nil {
		return nil, err
	}
	rows = iam.FilterUsers(pickable, rows, func(r syllabusdb.TeacherOptionsRow) uuid.UUID { return r.ID })
	out := make([]Option, 0, len(rows))
	for _, t := range rows {
		label := t.FullName
		if t.Code != "" {
			label += " (ID: " + t.Code + ")"
		}
		out = append(out, Option{t.ID.String(), label})
	}
	return out, nil
}

func (s *Service) Assignments(ctx context.Context, actor *iam.Access, f AssignmentFilter) (*AssignmentsPage, error) {
	ids, err := s.visibleClassIDs(ctx, actor)
	if err != nil {
		return nil, err
	}
	var status *string
	if _, okc := assignmentStatusLabels[f.Status]; okc {
		status = &f.Status
	}
	search := likePattern(f.Search)
	lim, off, page := pageOf(f.Page, assignmentsPageSize)
	rows, err := s.q.ListAssignments(ctx, syllabusdb.ListAssignmentsParams{
		ClassIds: ids, ClassID: f.ClassID, Status: status, Search: search, Lim: lim, Off: off,
	})
	if err != nil {
		return nil, err
	}
	total, err := s.q.CountAssignments(ctx, syllabusdb.CountAssignmentsParams{ClassIds: ids, ClassID: f.ClassID, Status: status, Search: search})
	if err != nil {
		return nil, err
	}
	out := &AssignmentsPage{
		Assignments: make([]Assignment, 0, len(rows)), Classes: []ClassRef{}, ClassCurriculum: map[string]*uuid.UUID{},
		ClassOpenStage: map[string]string{}, CanManage: actor.Can("syllabus.manage"), CanOverride: actor.Can("syllabus.approve_adjustment"),
		CanViewLog: actor.Can("activity_log.view"), Page: Page{Total: total, Page: page, PageSize: assignmentsPageSize},
	}
	for _, r := range rows {
		place := []string{}
		for _, p := range []*string{r.BranchName, r.ClassRoom} {
			if v := trimmed(p); v != "" {
				place = append(place, v)
			}
		}
		out.Assignments = append(out.Assignments, Assignment{
			ID: r.ID, Code: assignmentCode(r.No, r.OpenedAt, r.CreatedAt), ClassID: r.ClassID, ClassName: deref(r.ClassName),
			ClassPlace: strings.Join(place, " - "), Curriculum: deref(r.CurriculumTitle), Teacher: deref(r.TeacherName), TeacherCode: r.TeacherCode,
			UserID: r.UserID, StageLabel: stageOr(r.StagePosition, r.StageTitle, deref(r.StageName)), AssignedChapters: r.AssignedChapters,
			ExtraSessions: r.ExtraSessions, StartDate: isoDate(localDate(firstTime(r.OpenedAt, &r.CreatedAt))),
			ClosedAt: isoDate(localDate(r.ClosedAt)), CloseReason: r.CloseReason, Deadline: isoDate(r.Deadline), IsOpen: r.Status == "in_progress",
			Status: r.Status, StatusLabel: assignmentStatusLabels[r.Status], CurriculumCompleted: r.CurriculumCompletedAt != nil,
			ClosingBigTest: r.ClosingBigTest,
		})
	}
	if out.Teachers, err = s.teacherOptions(ctx, actor); err != nil {
		return nil, err
	}
	if out.Curriculums, err = s.curriculumRefs(ctx); err != nil {
		return nil, err
	}
	classes, err := s.q.ClassesByIDs(ctx, syllabusdb.ClassesByIDsParams{Ids: ids, SkipCancelled: true})
	if err != nil {
		return nil, err
	}
	levelCur, err := s.q.LevelCurricula(ctx)
	if err != nil {
		return nil, err
	}
	byLevel := map[string]*uuid.UUID{}
	for _, l := range levelCur {
		if _, seen := byLevel[l.Code]; !seen {
			byLevel[l.Code] = l.SyllabusCurriculumID
		}
	}
	for _, c := range classes {
		out.Classes = append(out.Classes, ClassRef{c.ID, c.Name, c.Code})
		out.ClassCurriculum[c.ID.String()] = byLevel[deref(c.Level)]
	}
	open, err := s.q.OpenAssignments(ctx, syllabusdb.OpenAssignmentsParams{ClassIds: ids})
	if err != nil {
		return nil, err
	}
	for _, a := range open {
		if a.ClassID != nil {
			out.ClassOpenStage[a.ClassID.String()] = deref(a.StageName)
		}
	}
	return out, nil
}

func firstTime(ts ...*time.Time) *time.Time {
	for _, t := range ts {
		if t != nil {
			return t
		}
	}
	return nil
}

type AssignmentInput struct {
	ClassID          *uuid.UUID `json:"class_id"`
	CurriculumID     *uuid.UUID `json:"curriculum_id"`
	StageID          *uuid.UUID `json:"stage_id"`
	UserID           *uuid.UUID `json:"user_id"`
	AssignedChapters *string    `json:"assigned_chapters"`
	Deadline         *string    `json:"deadline"`
	StartDate        *string    `json:"start_date"`
	ReplaceCurrent   bool       `json:"replace_current"`
	Reason           *string    `json:"reason"`
}

// optDay: ngày tùy chọn "2006-01-02"; sai định dạng → lỗi với tên trường.
func optDay(s *string, field string) (*time.Time, error) {
	v := trimmed(s)
	if v == "" {
		return nil, nil
	}
	d, okd := parseDay(v)
	if !okd {
		return nil, iam.Invalid(field + " không phải ngày hợp lệ.")
	}
	return &d, nil
}

// startAt: "Ngày bắt đầu" — tối đa hôm nay + 60 ngày; lưu 00:00 giờ Việt Nam của ngày đó.
func (s *Service) startAt(v *string) (*time.Time, error) {
	d, err := optDay(v, "Ngày bắt đầu")
	if err != nil || d == nil {
		return nil, err
	}
	if d.After(s.today().AddDate(0, 0, 60)) {
		return nil, iam.Invalid("Ngày bắt đầu không được quá 60 ngày kể từ hôm nay.")
	}
	return ptr(startOfDayVN(*d)), nil
}

func (s *Service) visibleClass(ctx context.Context, actor *iam.Access, classID uuid.UUID) (syllabusdb.ClassInfoRow, error) {
	visible, err := s.classVisible(ctx, actor, classID)
	if err != nil {
		return syllabusdb.ClassInfoRow{}, err
	}
	if !visible {
		return syllabusdb.ClassInfoRow{}, ErrClassNotFound
	}
	c, err := s.q.ClassInfo(ctx, classID)
	if isNoRows(err) {
		return c, ErrClassNotFound
	}
	return c, err
}

// curriculumOfClass: giáo trình theo Trình độ của lớp (classes.level là mã trình độ), không có thì theo trình độ của
// khóa học.
func (s *Service) curriculumOfClass(ctx context.Context, q *syllabusdb.Queries, c syllabusdb.ClassInfoRow) (*uuid.UUID, error) {
	rows, err := q.CurriculumOfLevel(ctx, syllabusdb.CurriculumOfLevelParams{Level: deref(c.Level), CourseID: c.CourseID})
	if err != nil {
		return nil, err
	}
	for _, r := range rows {
		if r.SyllabusCurriculumID != nil {
			return r.SyllabusCurriculumID, nil
		}
	}
	return nil, nil
}

// OpenAssignment: mở chặng cho lớp. Mặc định: giáo trình theo Trình độ của lớp, chặng = chặng đầu tiên lớp chưa học xong,
// GV = GV chính của lớp. Lớp đang mở chặng khác thì từ chối, trừ khi Học thuật chọn "chuyển chặng" kèm lý do.
func (s *Service) OpenAssignment(ctx context.Context, actor *iam.Access, in AssignmentInput) (*Message, error) {
	if in.ClassID == nil {
		return nil, iam.Invalid("Vui lòng chọn lớp.")
	}
	c, err := s.visibleClass(ctx, actor, *in.ClassID)
	if err != nil {
		return nil, err
	}
	var stage *syllabusdb.GetStageRow
	if in.StageID != nil {
		st, err := s.q.GetStage(ctx, *in.StageID)
		if isNoRows(err) {
			return nil, iam.Invalid("Chặng đã chọn không tồn tại.")
		}
		if err != nil {
			return nil, err
		}
		stage = &st
	}
	if in.CurriculumID != nil {
		if _, err := s.q.GetCurriculum(ctx, *in.CurriculumID); isNoRows(err) {
			return nil, iam.Invalid("Giáo trình đã chọn không tồn tại.")
		} else if err != nil {
			return nil, err
		}
	}
	if in.UserID != nil {
		okc, err := s.q.UserExists(ctx, *in.UserID)
		if err != nil {
			return nil, err
		}
		if !okc {
			return nil, iam.Invalid("Giáo viên đã chọn không tồn tại.")
		}
	}
	in.AssignedChapters = optText(in.AssignedChapters)
	if tooLong(in.AssignedChapters, 255) {
		return nil, iam.Invalid("Nội dung được giao tối đa 255 ký tự.")
	}
	deadline, err := optDay(in.Deadline, "Dự kiến hoàn thành")
	if err != nil {
		return nil, err
	}
	openedAt, err := s.startAt(in.StartDate)
	if err != nil {
		return nil, err
	}
	reason := optText(in.Reason)
	if tooLong(reason, 2000) {
		return nil, iam.Invalid("Lý do tối đa 2000 ký tự.")
	}
	curID := in.CurriculumID
	if curID == nil && stage != nil {
		curID = &stage.CurriculumID
	}
	if curID == nil {
		if curID, err = s.curriculumOfClass(ctx, s.q, c); err != nil {
			return nil, err
		}
	}
	if curID == nil {
		return nil, iam.Invalid("Chọn giáo trình (trình độ của lớp chưa gắn giáo trình).")
	}
	if stage != nil && stage.CurriculumID != *curID {
		return nil, iam.Invalid("Chặng không thuộc giáo trình đã chọn.")
	}
	var id uuid.UUID
	var label string
	err = s.inTx(ctx, func(tx pgx.Tx, q *syllabusdb.Queries) error {
		if err := q.LockKey(ctx, "syllabus.class:"+c.ID.String()); err != nil {
			return err
		}
		if stage == nil {
			next, err := nextStageFor(ctx, q, c.ID, *curID)
			if err != nil {
				return err
			}
			if next == nil {
				return iam.Invalid("Lớp đã học hết các chặng của giáo trình này.")
			}
			st, err := q.GetStage(ctx, *next)
			if err != nil {
				return err
			}
			stage = &st
		}
		at := openAttrs{assignedChapters: in.AssignedChapters, deadline: deadline, openedAt: openedAt}
		cur, err := q.OpenAssignmentOfClass(ctx, &c.ID)
		if err != nil && !isNoRows(err) {
			return err
		}
		if in.ReplaceCurrent && err == nil {
			if !actor.Can("syllabus.approve_adjustment") {
				return forbidden("Chỉ Học thuật được chuyển chặng đang mở.")
			}
			if reason == nil {
				return iam.Invalid("Vui lòng nhập lý do chuyển chặng.")
			}
			if _, err := s.closeStage(ctx, tx, q, cur.ID, &actor.UserID, "Học thuật chuyển chặng: "+*reason, nil, false); err != nil {
				return err
			}
		}
		if id, label, err = s.openStage(ctx, q, c, *stage, in.UserID, &actor.UserID, reason, at); err != nil {
			return err
		}
		a, err := q.GetAssignment(ctx, id)
		if err != nil {
			return err
		}
		if err := send(ctx, tx, []uuid.UUID{a.UserID}, notify.Notification{
			Type: "syllabus_stage", Title: "Lớp " + c.Name + " mở " + label, Body: "Nội dung chặng đã mở trong màn Xem giáo trình.",
			Link: stageLink(c.ID), CreatedBy: &actor.UserID,
		}); err != nil {
			return err
		}
		return record(ctx, tx, &actor.UserID, audit.EventCreated, "syllabus_assignment.open", "syllabus_assignment", id,
			c.Name+" - "+label, "Mở "+label+" cho lớp "+c.Name, &c.BranchID)
	})
	if err != nil {
		return nil, err
	}
	return okID("Đã mở "+label+" cho lớp "+c.Name+".", id), nil
}

type AssignmentEdit struct {
	UserID    *uuid.UUID `json:"user_id"`
	StartDate *string    `json:"start_date"`
	Deadline  *string    `json:"deadline"`
}

// assignmentInScope: chặng của lớp người dùng được xem.
func (s *Service) assignmentInScope(ctx context.Context, actor *iam.Access, id uuid.UUID) (syllabusdb.GetAssignmentRow, error) {
	a, err := s.q.GetAssignment(ctx, id)
	if isNoRows(err) {
		return a, ErrNotFound
	}
	if err != nil {
		return a, err
	}
	if a.ClassID == nil {
		return a, ErrNotFound
	}
	visible, err := s.classVisible(ctx, actor, *a.ClassID)
	if err != nil {
		return a, err
	}
	if !visible {
		return a, ErrNotFound
	}
	return a, nil
}

// UpdateAssignment: đổi GV phụ trách / ngày bắt đầu / dự kiến hoàn thành của chặng đang mở (không đổi chặng ở đây).
func (s *Service) UpdateAssignment(ctx context.Context, actor *iam.Access, id uuid.UUID, in AssignmentEdit) (*Message, error) {
	a, err := s.assignmentInScope(ctx, actor, id)
	if err != nil {
		return nil, err
	}
	if a.Status != "in_progress" {
		return nil, iam.Invalid("Chặng đã đóng, không chỉnh sửa được.")
	}
	// Lỗi báo bằng một thông báo chung kèm tiền tố như Laravel.
	fail := func(msg string) error { return iam.Invalid("Chưa lưu chỉnh sửa chặng: " + msg) }
	if in.UserID == nil {
		return nil, fail("Vui lòng chọn giáo viên phụ trách.")
	}
	okc, err := s.q.UserExists(ctx, *in.UserID)
	if err != nil {
		return nil, err
	}
	if !okc {
		return nil, fail("Giáo viên phụ trách không tồn tại.")
	}
	openedAt, err := s.startAt(in.StartDate)
	if err != nil {
		return nil, fail(err.(*iam.Error).Message)
	}
	deadline, err := optDay(in.Deadline, "Dự kiến hoàn thành")
	if err != nil {
		return nil, fail(err.(*iam.Error).Message)
	}
	stage := deref(a.StageName)
	className := deref(a.ClassName)
	err = s.inTx(ctx, func(tx pgx.Tx, q *syllabusdb.Queries) error {
		if err := q.EditAssignment(ctx, syllabusdb.EditAssignmentParams{ID: id, UserID: *in.UserID, Deadline: deadline, OpenedAt: openedAt}); err != nil {
			return err
		}
		if *in.UserID != a.UserID {
			if err := notifyReview(ctx, tx, in.UserID, "Bạn được giao "+stage,
				"Lớp "+className+": nội dung chặng đã mở trong màn Xem giáo trình.", stageLink(*a.ClassID), actor.UserID); err != nil {
				return err
			}
		}
		return record(ctx, tx, &actor.UserID, audit.EventUpdated, "syllabus_assignment.update", "syllabus_assignment", id,
			className+" - "+stage, "Cập nhật "+stage+" của lớp "+className, nil)
	})
	if err != nil {
		return nil, err
	}
	return okID("Đã cập nhật "+stage+" của lớp "+className+".", id), nil
}

// CloseAssignment: Học thuật đóng tay chặng đang mở (bắt buộc lý do); openNext thì tự mở chặng kế tiếp.
func (s *Service) CloseAssignment(ctx context.Context, actor *iam.Access, id uuid.UUID, reason string, openNext bool) (*Message, error) {
	if _, err := s.assignmentInScope(ctx, actor, id); err != nil {
		return nil, err
	}
	reason = strings.TrimSpace(reason)
	if reason == "" {
		return nil, iam.Invalid("Vui lòng nhập lý do đóng chặng.")
	}
	if runeLen(reason) > 2000 {
		return nil, iam.Invalid("Lý do tối đa 2000 ký tự.")
	}
	var o *outcome
	err := s.inTx(ctx, func(tx pgx.Tx, q *syllabusdb.Queries) error {
		var err error
		o, err = s.closeStage(ctx, tx, q, id, &actor.UserID, "Học thuật đóng tay: "+reason, nil, openNext)
		return err
	})
	if err != nil {
		return nil, err
	}
	return okID(strings.TrimSpace(describe(o)), id), nil
}

// SetExpectedBigTestDate: GV chính (hoặc GVNN) đặt / sửa ngày dự kiến Big Test cuối chặng đang mở; Học thuật cũng sửa được.
func (s *Service) SetExpectedBigTestDate(ctx context.Context, actor *iam.Access, id uuid.UUID, day string) (*Message, error) {
	a, err := s.q.GetAssignment(ctx, id)
	if isNoRows(err) {
		return nil, ErrNotFound
	}
	if err != nil {
		return nil, err
	}
	main := false
	if a.ClassID != nil {
		c, err := s.q.ClassInfo(ctx, *a.ClassID)
		if err != nil && !isNoRows(err) {
			return nil, err
		}
		main = err == nil && (sameUser(c.TeacherID, actor.UserID) || sameUser(c.ForeignTeacherID, actor.UserID))
	}
	if !main && !actor.Can("syllabus.approve_adjustment") {
		return nil, forbidden("Chỉ giáo viên chính của lớp được đặt lịch dự kiến Big Test.")
	}
	if a.Status != "in_progress" {
		return nil, iam.Invalid("Chặng đã đóng.")
	}
	if strings.TrimSpace(day) == "" {
		return nil, iam.Invalid("Vui lòng chọn ngày dự kiến Big Test.")
	}
	d, okd := parseDay(day)
	if !okd {
		return nil, iam.Invalid("Ngày dự kiến Big Test không hợp lệ.")
	}
	if d.Before(s.today()) {
		return nil, iam.Invalid("Ngày dự kiến Big Test phải từ hôm nay trở đi.")
	}
	if err := s.q.SetExpectedBigTestDate(ctx, syllabusdb.SetExpectedBigTestDateParams{ID: id, Day: &d}); err != nil {
		return nil, err
	}
	return okID(fmt.Sprintf("Đã lưu ngày dự kiến Big Test %s lớp %s: %s.", deref(a.StageName), deref(a.ClassName), fmtDay(d)), id), nil
}

// ───────────── Kế hoạch Big Test của chặng đang mở ─────────────

type examPlan struct {
	date    *time.Time // ngày thi (đợt thi của chặng → ngày dự kiến GV đặt → ngày thi trong order)
	order   *syllabusdb.ClassBigOrdersRow
	bigTest *syllabusdb.ClassStageTestsRow
	exam    string // approved | pending | none
}

// stageExamPlans: kế hoạch Big Test cuối chặng của từng chặng đang mở (SyllabusController::stageExamPlans).
func (s *Service) stageExamPlans(ctx context.Context, as []syllabusdb.OpenAssignmentsRow) (map[uuid.UUID]examPlan, error) {
	classIDs := []uuid.UUID{}
	for _, a := range as {
		if a.ClassID != nil {
			classIDs = append(classIDs, *a.ClassID)
		}
	}
	orders, err := s.q.ClassBigOrders(ctx, classIDs)
	if err != nil {
		return nil, err
	}
	tests, err := s.q.ClassStageTests(ctx, classIDs)
	if err != nil {
		return nil, err
	}
	out := map[uuid.UUID]examPlan{}
	for _, a := range as {
		label := stageOr(a.StagePosition, a.StageTitle, deref(a.StageName))
		var p examPlan
		for i := range orders {
			o := orders[i]
			if a.ClassID == nil || o.ClassID != *a.ClassID {
				continue
			}
			match := o.StageName == label
			if a.StageID != nil && o.StageID != nil {
				match = *o.StageID == *a.StageID
			}
			if match {
				p.order = &orders[i]
				break
			}
		}
		for i := range tests {
			t := tests[i]
			if a.ClassID != nil && t.ClassID != nil && *t.ClassID == *a.ClassID && a.StageID != nil && t.StageID != nil && *t.StageID == *a.StageID {
				p.bigTest = &tests[i]
				break
			}
		}
		switch {
		case p.bigTest != nil && p.bigTest.ScheduledAt != nil:
			p.date = ptr(dateOf(*p.bigTest.ScheduledAt))
		case a.ExpectedBigTestDate != nil:
			p.date = a.ExpectedBigTestDate
		case p.order != nil:
			p.date = p.order.ExamDate
		}
		switch {
		case (p.bigTest != nil && p.bigTest.IsDistributed) || (p.order != nil && p.order.Status == "approved"):
			p.exam = "approved"
		case p.order != nil && p.order.Status == "pending":
			p.exam = "pending"
		default:
			p.exam = "none"
		}
		out[a.ID] = p
	}
	return out, nil
}

// openOfVisible: chặng đang mở của các lớp người dùng được xem (bỏ lớp đã hủy khi skipCancelled).
func (s *Service) openOfVisible(ctx context.Context, actor *iam.Access, skipCancelled bool) ([]syllabusdb.OpenAssignmentsRow, error) {
	ids, err := s.visibleClassIDs(ctx, actor)
	if err != nil {
		return nil, err
	}
	rows, err := s.q.OpenAssignments(ctx, syllabusdb.OpenAssignmentsParams{ClassIds: ids})
	if err != nil {
		return nil, err
	}
	if skipCancelled {
		rows = slices.DeleteFunc(rows, func(r syllabusdb.OpenAssignmentsRow) bool { return r.ClassStatus == "cancelled" })
	}
	return rows, nil
}

type TeachingStage struct {
	ID             uuid.UUID  `json:"id"`
	ClassID        *uuid.UUID `json:"class_id"`
	StageLabel     string     `json:"stage_label"`
	ClassLabel     string     `json:"class_label"`
	StartDate      string     `json:"start_date"`
	IsAssistant    bool       `json:"is_assistant"`
	RoleLabel      string     `json:"role_label"`
	Exam           string     `json:"exam"`
	Date           string     `json:"date"`
	BigTestCode    *string    `json:"big_test_code"`
	ExpectedDate   string     `json:"expected_date"`
	CanSetDate     bool       `json:"can_set_date"`
	CanOrder       bool       `json:"can_order"`
	OrderStatus    *string    `json:"order_status"`
	OrderRejection *string    `json:"order_rejection"`
}

type TeachingStagesPage struct {
	Assignments []TeachingStage `json:"assignments"`
	Today       string          `json:"today"`
	Month       string          `json:"month"`
}

// TeachingStages: "Chặng đang dạy & Order Test" — mỗi lớp mình được xem đang mở chặng là một thẻ.
func (s *Service) TeachingStages(ctx context.Context, actor *iam.Access) (*TeachingStagesPage, error) {
	as, err := s.openOfVisible(ctx, actor, true)
	if err != nil {
		return nil, err
	}
	plans, err := s.stageExamPlans(ctx, as)
	if err != nil {
		return nil, err
	}
	academic := actor.Can("syllabus.approve_adjustment")
	recordAny := actor.Can("attendance_student.record_any")
	now := s.now().In(vnZone)
	out := &TeachingStagesPage{Assignments: []TeachingStage{}, Today: s.today().Format(time.DateOnly),
		Month: fmt.Sprintf("Tháng %d, %d", int(now.Month()), now.Year())}
	for _, a := range as {
		p := plans[a.ID]
		main := sameUser(a.TeacherID, actor.UserID) || sameUser(a.ForeignTeacherID, actor.UserID)
		assistant := sameUser(a.AssistantID, actor.UserID) && !main
		role := "Học thuật / Quản lý"
		switch {
		case assistant:
			role = "Trợ giảng (Chỉ xem)"
		case main:
			role = "Giáo viên chính"
		}
		classLabel := a.ClassName
		if a.ClassCode != "" {
			classLabel += " - " + a.ClassCode
		}
		row := TeachingStage{
			ID: a.ID, ClassID: a.ClassID, StageLabel: stageOr(a.StagePosition, a.StageTitle, deref(a.StageName)), ClassLabel: classLabel,
			StartDate: fmtDate(localDate(firstTime(a.OpenedAt, &a.CreatedAt))), IsAssistant: assistant, RoleLabel: role, Exam: p.exam,
			Date: fmtDate(p.date), ExpectedDate: isoDate(a.ExpectedBigTestDate), CanSetDate: (main || academic) && p.bigTest == nil,
			CanOrder: main || recordAny,
		}
		if p.bigTest != nil {
			row.BigTestCode = &p.bigTest.Code
		}
		if p.order != nil {
			row.OrderStatus, row.OrderRejection = &p.order.Status, p.order.RejectionReason
		}
		out.Assignments = append(out.Assignments, row)
	}
	return out, nil
}

// ───────────── Xem bài giảng (màn GV) ─────────────

type ViewStage struct {
	ID           uuid.UUID `json:"id"`
	Label        string    `json:"label"`
	State        string    `json:"state"` // open | done | todo
	Summary      string    `json:"summary,omitempty"`
	OverviewLink *string   `json:"overview_link,omitempty"`
}

type ViewAssignment struct {
	StageLabel    string `json:"stage_label"`
	Curriculum    string `json:"curriculum"`
	OpenedAt      string `json:"opened_at"`
	ExtraSessions int32  `json:"extra_sessions"`
	BigTestTitle  string `json:"big_test_title"`
	HasStage      bool   `json:"has_stage"`
}

type ViewPosition struct {
	Taught  int          `json:"taught"`
	Lessons int          `json:"lessons"`
	Over    int          `json:"over"`
	Current *LessonBrief `json:"current"`
}

type LessonBrief struct {
	ID         uuid.UUID `json:"id"`
	SessionNo  int32     `json:"session_no"`
	UnitNumber int32     `json:"unit_number"`
	Title      string    `json:"title"`
}

type SelectedDocument struct {
	ID          uuid.UUID `json:"id"`
	Title       string    `json:"title"`
	Icon        string    `json:"icon"`
	Kind        string    `json:"kind"`
	Extension   string    `json:"extension"`
	Meta        string    `json:"meta"`
	CanDownload bool      `json:"can_download"`
	Viewed      bool      `json:"viewed"`
	URL         string    `json:"url"`
}

type TeacherViewPage struct {
	Documents          []Document        `json:"documents"`
	Selected           *SelectedDocument `json:"selected"`
	Search             string            `json:"search"`
	InitialTab         string            `json:"initial_tab"`
	Classes            []Option          `json:"classes"`
	ClassID            *uuid.UUID        `json:"class_id"`
	OverviewCurriculum string            `json:"overview_curriculum"`
	OverviewStages     []ViewStage       `json:"overview_stages"`
	Stages             []ViewStage       `json:"stages"`
	Assignment         *ViewAssignment   `json:"assignment"`
	Position           *ViewPosition     `json:"position"`
	StageUnits         []Unit            `json:"stage_units"`
}

type TeacherViewFilter struct {
	ClassID    *uuid.UUID
	Search     string
	DocumentID *uuid.UUID
	Tab        string
}

// TeacherView: tài liệu được chia sẻ + chặng đang mở của lớp mình (các chặng của giáo trình, Unit / Buổi của chặng
// hiện tại, buổi đang dạy).
func (s *Service) TeacherView(ctx context.Context, actor *iam.Access, f TeacherViewFilter) (*TeacherViewPage, error) {
	au := audienceOf(actor)
	docs, err := s.q.ListDocuments(ctx, syllabusdb.ListDocumentsParams{
		Manage: au.manage, Teacher: au.teacher, Assistant: au.assistant, Search: likePattern(f.Search), Lim: 1000,
	})
	if err != nil {
		return nil, err
	}
	viewedIDs, err := s.q.ViewedDocumentIDs(ctx, actor.UserID)
	if err != nil {
		return nil, err
	}
	viewed := map[uuid.UUID]bool{}
	for _, id := range viewedIDs {
		viewed[id] = true
	}
	out := &TeacherViewPage{Documents: []Document{}, Search: strings.TrimSpace(f.Search), Classes: []Option{}, OverviewStages: []ViewStage{},
		Stages: []ViewStage{}, StageUnits: []Unit{}}
	for _, d := range docs {
		out.Documents = append(out.Documents, toDocument(d, au, viewed))
	}
	var overviewCur *uuid.UUID
	if f.DocumentID != nil {
		d, err := s.q.GetDocument(ctx, *f.DocumentID)
		if isNoRows(err) || (err == nil && !au.sees(d.VisibleToTeachers, d.VisibleToAssistants)) {
			return nil, ErrNotFound
		}
		if err != nil {
			return nil, err
		}
		kind := docKind(d.Extension)
		stage := stageOr(d.StagePosition, d.StageTitle, deref(d.StageName))
		if stage == "" {
			stage = "Chưa gắn chặng"
		}
		out.Selected = &SelectedDocument{
			ID: d.ID, Title: d.Title, Icon: docIcon(kind), Kind: kind, Extension: d.Extension,
			Meta:        stage + " • " + d.CurriculumTitle + " • " + sizeHuman(d.SizeBytes),
			CanDownload: au.canDownload(d.VisibleToTeachers, d.VisibleToAssistants, d.Downloadable), Viewed: viewed[d.ID], URL: files.URL(d.FileID),
		}
		overviewCur = &d.CurriculumID
	} else if len(docs) > 0 {
		overviewCur = &docs[0].CurriculumID
	}

	as, err := s.openOfVisible(ctx, actor, false)
	if err != nil {
		return nil, err
	}
	var open *syllabusdb.OpenAssignmentsRow
	for i, a := range as {
		if a.ClassID == nil {
			continue
		}
		out.Classes = append(out.Classes, Option{a.ClassID.String(), a.ClassName})
		if (f.ClassID == nil && open == nil) || (f.ClassID != nil && *a.ClassID == *f.ClassID) {
			open = &as[i]
		}
	}
	if f.ClassID != nil && open == nil {
		return nil, ErrClassNotFound
	}
	closed := map[uuid.UUID]bool{}
	if open != nil {
		out.ClassID = open.ClassID
		ids, err := s.q.ClosedStageIDs(ctx, open.ClassID)
		if err != nil {
			return nil, err
		}
		for _, id := range ids {
			if id != nil {
				closed[*id] = true
			}
		}
		full, err := s.q.OpenAssignmentOfClass(ctx, open.ClassID)
		if err != nil {
			return nil, err
		}
		btTitle := trimmed(full.BigTestTitle)
		if btTitle == "" {
			btTitle = "Big Test cuối chặng"
		}
		out.Assignment = &ViewAssignment{
			StageLabel: stageOr(full.StagePosition, full.StageTitle, deref(full.StageName)), Curriculum: deref(full.CurriculumTitle),
			OpenedAt: fmtDate(localDate(firstTime(full.OpenedAt, &full.CreatedAt))), ExtraSessions: full.ExtraSessions,
			BigTestTitle: btTitle, HasStage: full.StagePosition != nil,
		}
		stageID := full.StageID
		if full.StagePosition == nil {
			stageID = nil // chặng đã bị xóa
		}
		pos, err := s.position(ctx, stageID, *open.ClassID, full.OpenedAt)
		if err != nil {
			return nil, err
		}
		out.Position = &ViewPosition{Taught: pos.taught, Lessons: len(pos.lessons), Over: pos.over}
		if c := pos.current; c != nil {
			out.Position.Current = &LessonBrief{ID: c.ID, SessionNo: c.SessionNo, UnitNumber: c.UnitNumber, Title: c.Title}
		}
		overviewCur = &full.CurriculumID
	}
	state := func(id uuid.UUID) string {
		switch {
		case open != nil && open.StageID != nil && *open.StageID == id:
			return "open"
		case closed[id]:
			return "done"
		}
		return "todo"
	}
	if overviewCur != nil {
		tree, err := s.loadTree(ctx, *overviewCur)
		if err != nil {
			return nil, err
		}
		if c, err := s.q.GetCurriculum(ctx, *overviewCur); err == nil {
			out.OverviewCurriculum = c.Title
		}
		for _, st := range tree.stages {
			units := tree.unitsOf(st.ID)
			lessons := 0
			for _, u := range units {
				lessons += len(u.Lessons)
			}
			summary := trimmed(st.Description)
			if summary == "" {
				summary = fmt.Sprintf("%d unit · %d buổi", len(units), lessons)
			}
			label := stageLabel(st.Position, st.Name)
			out.OverviewStages = append(out.OverviewStages, ViewStage{ID: st.ID, Label: label, State: state(st.ID), Summary: summary, OverviewLink: st.OverviewLink})
			if open != nil {
				out.Stages = append(out.Stages, ViewStage{ID: st.ID, Label: label, State: state(st.ID)})
				if open.StageID != nil && *open.StageID == st.ID {
					out.StageUnits = units
				}
			}
		}
	}
	switch f.Tab {
	case "docs", "overview", "lessons":
		out.InitialTab = f.Tab
	default:
		out.InitialTab = "docs"
		if open != nil && len(out.Documents) == 0 {
			out.InitialTab = "lessons"
		}
	}
	return out, nil
}
