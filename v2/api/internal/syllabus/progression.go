package syllabus

import (
	"context"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/syllabus/syllabusdb"
)

// Tiến trình chặng của lớp (SyllabusProgressionService, Q4 — BA chốt 25/09/2026):
//  1. Mỗi lớp chỉ có 1 chặng đang mở (DB chặn bằng chỉ mục duy nhất assignments_one_open_uq).
//  2. Chặng đóng khi Big Test của chặng đã được duyệt VÀ gửi phụ huynh: mọi kết quả "Đã gửi PH", học viên vắng thi chỉ
//     cần "Đã duyệt"; đủ cả lớp.
//  3. Đóng chặng → tự mở chặng kế tiếp của cùng giáo trình, cùng GV, và báo GV; hết chặng → lớp hoàn thành giáo trình.
//  4. Học thuật được mở / đóng tay (bắt buộc lý do) để xử lý ngoại lệ.

// openAttrs: thuộc tính tùy chọn khi mở chặng (form "Mở chặng").
type openAttrs struct {
	assignedChapters *string
	deadline         *time.Time
	openedAt         *time.Time
}

// outcome: kết quả đóng chặng.
type outcome struct {
	closedName string
	nextName   string
	hasNext    bool
	finished   bool
}

// describe: câu thông báo ngắn cho kết quả đóng chặng (nối sau thông báo chính).
func describe(o *outcome) string {
	switch {
	case o == nil:
		return ""
	case o.hasNext:
		return " Đã đóng " + o.closedName + " và tự mở " + o.nextName + " cho lớp."
	case o.finished:
		return " Đã đóng " + o.closedName + " — lớp đã hoàn thành giáo trình."
	}
	return " Đã đóng " + o.closedName + "."
}

func stageLink(classID uuid.UUID) string { return "/syllabus/teacher-view?class=" + classID.String() }

// notifyTeachers: báo GV phụ trách chặng + GV chính + GVNN của lớp (loại syllabus_stage).
func notifyTeachers(ctx context.Context, db notify.DB, c syllabusdb.ClassInfoRow, assigned *uuid.UUID, title, body string) error {
	return send(ctx, db, uniqueIDs(assigned, c.TeacherID, c.ForeignTeacherID), notify.Notification{
		Type: "syllabus_stage", Title: title, Body: body, Link: stageLink(c.ID),
	})
}

// nextStageFor: chặng lớp sẽ học tiếp: sau chặng đóng gần nhất; chặng đầu nếu chưa học chặng nào; nil khi đã học hết.
func nextStageFor(ctx context.Context, q *syllabusdb.Queries, classID, curriculumID uuid.UUID) (*uuid.UUID, error) {
	last, err := q.LastClosedStageID(ctx, syllabusdb.LastClosedStageIDParams{ClassID: &classID, CurriculumID: curriculumID})
	if err != nil && !isNoRows(err) {
		return nil, err
	}
	var id uuid.UUID
	if last == nil {
		id, err = q.FirstStageID(ctx, curriculumID)
	} else {
		id, err = q.NextStageID(ctx, *last)
	}
	if isNoRows(err) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	return &id, nil
}

// openStage mở chặng cho lớp (trong tx). Lớp đang có chặng mở thì từ chối.
func (s *Service) openStage(ctx context.Context, q *syllabusdb.Queries, c syllabusdb.ClassInfoRow, stage syllabusdb.GetStageRow,
	teacher *uuid.UUID, by *uuid.UUID, reason *string, at openAttrs) (uuid.UUID, string, error) {
	if teacher == nil {
		teacher = c.TeacherID
	}
	if teacher == nil {
		return uuid.Nil, "", iam.Invalid("Lớp chưa có giáo viên phụ trách; vui lòng chọn giáo viên.")
	}
	cur, err := q.OpenAssignmentOfClass(ctx, &c.ID)
	if err == nil {
		return uuid.Nil, "", iam.Invalid("Lớp đang học chặng \"" + deref(cur.StageName) + "\". Mỗi lớp chỉ mở 1 chặng — cần đóng chặng hiện tại trước.")
	}
	if !isNoRows(err) {
		return uuid.Nil, "", err
	}
	label := stageLabel(stage.Position, stage.Name)
	chapters := at.assignedChapters
	if chapters == nil {
		r, err := q.StageRange(ctx, &stage.ID)
		if err != nil {
			return uuid.Nil, "", err
		}
		chapters = ptr(rangeLabel(r.UnitMin, r.UnitMax, r.SessionMin, r.SessionMax, label))
	}
	openedAt := at.openedAt
	if openedAt == nil {
		openedAt = ptr(s.now())
	}
	id, err := q.InsertAssignment(ctx, syllabusdb.InsertAssignmentParams{
		UserID: *teacher, CurriculumID: stage.CurriculumID, StageID: &stage.ID, ClassID: &c.ID, AssignedChapters: cut(*chapters, 255),
		StageName: &label, Deadline: at.deadline, OpenedAt: openedAt, OpenedBy: by, OpenReason: reason,
	})
	if isUnique(err) {
		return uuid.Nil, "", iam.Invalid("Lớp vừa được mở một chặng khác. Vui lòng tải lại trang.")
	}
	return id, label, err
}

// bigTestRef: đợt thi đóng chặng.
type bigTestRef struct {
	id   uuid.UUID
	code string
}

// closeStage đóng chặng đang mở (trong tx); openNext thì tự mở chặng kế tiếp và báo GV.
func (s *Service) closeStage(ctx context.Context, tx pgx.Tx, q *syllabusdb.Queries, assignmentID uuid.UUID, by *uuid.UUID, reason string,
	bt *bigTestRef, openNext bool) (*outcome, error) {
	a, err := q.LockAssignment(ctx, assignmentID)
	if isNoRows(err) {
		return nil, ErrNotFound
	}
	if err != nil {
		return nil, err
	}
	if a.Status != "in_progress" {
		return nil, iam.Invalid("Chặng này đã đóng trước đó.")
	}
	var next *uuid.UUID
	if openNext && a.StageID != nil {
		id, err := q.NextStageID(ctx, *a.StageID)
		if err != nil && !isNoRows(err) {
			return nil, err
		}
		if err == nil {
			next = &id
		}
	}
	finished := openNext && a.StageID != nil && next == nil
	var btID *uuid.UUID
	if bt != nil {
		btID = &bt.id
	}
	if err := q.CloseAssignment(ctx, syllabusdb.CloseAssignmentParams{
		ID: a.ID, ClosedBy: by, CloseReason: ptr(cut(reason, 2500)), BigTestID: btID, Finished: finished,
	}); err != nil {
		return nil, err
	}
	o := &outcome{closedName: deref(a.StageName), finished: finished}
	if a.ClassID == nil {
		return o, nil
	}
	c, err := q.ClassInfo(ctx, *a.ClassID)
	if isNoRows(err) {
		return o, nil
	}
	if err != nil {
		return nil, err
	}
	if err := record(ctx, tx, by, audit.EventUpdated, "syllabus_assignment.close", "syllabus_assignment", a.ID,
		c.Name+" - "+o.closedName, "Đóng "+o.closedName+" của lớp "+c.Name+": "+reason, &c.BranchID); err != nil {
		return nil, err
	}
	switch {
	case next != nil:
		st, err := q.GetStage(ctx, *next)
		if err != nil {
			return nil, err
		}
		openReason := "Mở tiếp sau khi đóng " + o.closedName + ": " + reason
		if bt != nil {
			openReason = "Tự mở sau khi Big Test " + bt.code + " của " + o.closedName + " được duyệt và gửi phụ huynh."
		}
		nextID, label, err := s.openStage(ctx, q, c, st, &a.UserID, by, ptr(cut(openReason, 2000)), openAttrs{})
		if err != nil {
			return nil, err
		}
		o.hasNext, o.nextName = true, label
		body := o.closedName + " đã đóng"
		if bt != nil {
			body += " (Big Test " + bt.code + " đã duyệt và gửi PH)"
		}
		if err := notifyTeachers(ctx, tx, c, &a.UserID, "Lớp "+c.Name+" đã chuyển sang "+label,
			body+". Nội dung chặng mới đã mở trong màn Xem giáo trình."); err != nil {
			return nil, err
		}
		if err := record(ctx, tx, by, audit.EventCreated, "syllabus_assignment.open", "syllabus_assignment", nextID,
			c.Name+" - "+label, "Tự mở "+label+" cho lớp "+c.Name, &c.BranchID); err != nil {
			return nil, err
		}
	case finished:
		cur, err := q.GetCurriculum(ctx, a.CurriculumID)
		if err != nil && !isNoRows(err) {
			return nil, err
		}
		if err := notifyTeachers(ctx, tx, c, &a.UserID, "Lớp "+c.Name+" đã hoàn thành giáo trình",
			o.closedName+" là chặng cuối của giáo trình "+cur.Title+"."); err != nil {
			return nil, err
		}
	}
	return o, nil
}

// bigTestCompleted: đã duyệt và gửi đủ: có kết quả, mọi kết quả đã gửi PH (vắng thi: đã duyệt) và đủ cả lớp.
func bigTestCompleted(ctx context.Context, q *syllabusdb.Queries, testID uuid.UUID, classID uuid.UUID) (bool, error) {
	rs, err := q.ResultStatuses(ctx, testID)
	if err != nil || len(rs) == 0 {
		return false, err
	}
	have := map[uuid.UUID]bool{}
	for _, r := range rs {
		if !(r.Status == "sent" || (r.IsAbsent && r.Status == "approved")) {
			return false, nil
		}
		have[r.StudentID] = true
	}
	roster, err := q.RosterIDs(ctx, &classID)
	if err != nil {
		return false, err
	}
	for _, id := range roster {
		if !have[id] {
			return false, nil
		}
	}
	return true, nil
}

// syncBigTest: gọi sau mỗi lần duyệt kết quả / gửi PH. Big Test của chặng đang mở đã duyệt & gửi đủ → đóng chặng, mở
// chặng kế. Mốc "đã trả đủ kết quả" (dừng tính trễ hạn) đặt cả khi đợt thi không gắn chặng.
func (s *Service) syncBigTest(ctx context.Context, tx pgx.Tx, q *syllabusdb.Queries, testID uuid.UUID, by *uuid.UUID) (*outcome, error) {
	t, err := q.GetBigTest(ctx, testID)
	if err != nil {
		return nil, err
	}
	if t.ClassID == nil {
		return nil, nil
	}
	done, err := bigTestCompleted(ctx, q, t.ID, *t.ClassID)
	if err != nil || !done {
		return nil, err
	}
	if err := q.MarkResultsCompleted(ctx, t.ID); err != nil {
		return nil, err
	}
	if t.StageID == nil {
		return nil, nil
	}
	a, err := q.OpenAssignmentOfClass(ctx, t.ClassID)
	if isNoRows(err) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	if a.StageID == nil || *a.StageID != *t.StageID {
		return nil, nil
	}
	return s.closeStage(ctx, tx, q, a.ID, by, "Big Test "+t.Code+" đã được duyệt và gửi kết quả cho phụ huynh.",
		&bigTestRef{id: t.ID, code: t.Code}, true)
}

// stagePosition: vị trí dạy của lớp trong chặng đang mở — số buổi đã dạy (chính khóa / học bù không hủy, trước hôm nay,
// từ ngày mở chặng) → buổi tiếp theo trong danh sách buổi của chặng; vượt quá = buổi giãn tiến độ / ôn trước Big Test.
type stagePosition struct {
	lessons []syllabusdb.StageLessonsRow
	taught  int
	current *syllabusdb.StageLessonsRow
	over    int
}

func (s *Service) position(ctx context.Context, stageID *uuid.UUID, classID uuid.UUID, openedAt *time.Time) (*stagePosition, error) {
	p := &stagePosition{lessons: []syllabusdb.StageLessonsRow{}}
	if stageID != nil {
		ls, err := s.q.StageLessons(ctx, stageID)
		if err != nil {
			return nil, err
		}
		p.lessons = ls
	}
	var since *time.Time
	if openedAt != nil {
		since = ptr(dateOf(*openedAt))
	}
	n, err := s.q.TaughtSessions(ctx, syllabusdb.TaughtSessionsParams{ClassID: classID, Today: s.today(), Since: since})
	if err != nil {
		return nil, err
	}
	p.taught = int(n)
	if p.taught < len(p.lessons) {
		p.current = &p.lessons[p.taught]
	}
	p.over = max(0, p.taught-len(p.lessons))
	return p, nil
}
