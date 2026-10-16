package classes

import (
	"context"
	"fmt"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/classes/classesdb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// Tiếp nhận & Xếp lớp (StudentProfileController enrollments / storeEnrollment / linkClass / updateEnrollmentHandoff):
// xếp học viên vào lớp chính (chuyển lớp: lượt ở lớp cũ hết hiệu lực từ hôm nay), liên kết thêm lớp học song song,
// checklist bàn giao (giáo trình, nhóm Zalo lớp). Phạm vi theo student.scope_*.

const enrollmentsPageSize = 15

// studentScope: phạm vi Học viên (Student::visibleTo) và lớp thao tác được khi xếp lớp.
type studentScope struct {
	limitBranches bool
	branches      []uuid.UUID
	limitClasses  bool
	classes       []uuid.UUID
	// lớp chọn được: Toàn hệ thống → mọi lớp; Chi nhánh → lớp thuộc chi nhánh mình; Của tôi → lớp mình được xem
	allClasses bool
}

func (s *Service) studentScope(ctx context.Context, actor *iam.Access) (studentScope, error) {
	switch actor.Level("student") {
	case iam.ScopeAll:
		return studentScope{allClasses: true, branches: []uuid.UUID{}, classes: []uuid.UUID{}}, nil
	case iam.ScopeBranch:
		return studentScope{limitBranches: true, branches: nonNil(actor.BranchIDs()), classes: []uuid.UUID{}, allClasses: true}, nil
	}
	sc, err := s.visible(ctx, actor)
	if err != nil {
		return studentScope{}, err
	}
	ids, err := s.VisibleClassIDs(ctx, actor)
	if err != nil {
		return studentScope{}, err
	}
	return studentScope{limitClasses: true, classes: nonNil(ids), branches: []uuid.UUID{}, allClasses: sc.all}, nil
}

func (ss studentScope) studentVisible(ctx context.Context, q *classesdb.Queries, id uuid.UUID) (bool, error) {
	return q.StudentInScope(ctx, classesdb.StudentInScopeParams{
		ID: id, LimitBranches: ss.limitBranches, BranchIds: ss.branches, LimitClasses: ss.limitClasses, ClassIds: ss.classes,
	})
}

func (ss studentScope) classesParams() classesdb.EnrollableClassesParams {
	return classesdb.EnrollableClassesParams{AllClasses: ss.allClasses, Ids: ss.classes, LimitBranches: ss.limitBranches, BranchIds: ss.branches}
}

type Enrollment struct {
	ID                  uuid.UUID `json:"id"`
	StudentID           uuid.UUID `json:"student_id"`
	StudentName         string    `json:"student_name"`
	StudentCode         string    `json:"student_code"`
	ClassID             uuid.UUID `json:"class_id"`
	ClassName           string    `json:"class_name"`
	ClassCode           string    `json:"class_code"`
	Teacher             *string   `json:"teacher"`
	Branch              string    `json:"branch"`
	CurriculumDelivered bool      `json:"curriculum_delivered"`
	ZaloGroupAdded      bool      `json:"zalo_group_added"`
	EnrolledAt          string    `json:"enrolled_at"`
	LeftAt              *string   `json:"left_at"`
	Status              string    `json:"status"`
	Dropped             bool      `json:"dropped"`
	FromCRM             bool      `json:"from_crm"`
	Confirmed           bool      `json:"confirmed"`
}

type EnrollClassOption struct {
	ID       uuid.UUID  `json:"id"`
	Label    string     `json:"label"`
	BranchID uuid.UUID  `json:"branch_id"`
	CourseID *uuid.UUID `json:"course_id"`
	Seat     string     `json:"seat"` // "12/20" hoặc "12" khi không giới hạn
	Full     bool       `json:"full"`
}

type StudentOption struct {
	Value    uuid.UUID  `json:"value"`
	Label    string     `json:"label"`
	BranchID *uuid.UUID `json:"branch_id"`
	Branch   *string    `json:"branch"`
}

type EnrollmentList struct {
	Enrollments []Enrollment        `json:"enrollments"`
	Total       int64               `json:"total"`
	Page        int                 `json:"page"`
	PerPage     int                 `json:"per_page"`
	Classes     []EnrollClassOption `json:"classes"`
	Students    []StudentOption     `json:"students"`
	CanAssign   bool                `json:"can_assign"`
	// TODO(crm): luật xếp lớp theo khóa / chi nhánh đã chốt của khách Chờ xếp lớp (WaitingLeadPlacement,
	// PlacementLevelMatcher) và số khách Chờ xếp lớp bên CRM.
	CrmWaitingCount int64 `json:"crm_waiting_count"`
}

type EnrollmentFilter struct {
	StudentID *uuid.UUID
	ClassID   *uuid.UUID
	Page      int
}

func toEnrollment(r classesdb.ListEnrollmentsRow) Enrollment {
	return Enrollment{
		ID: r.ID, StudentID: r.StudentID, StudentName: r.StudentName, StudentCode: r.StudentCode, ClassID: r.ClassID,
		ClassName: r.ClassName, ClassCode: r.ClassCode, Teacher: r.TeacherName, Branch: r.BranchName,
		CurriculumDelivered: r.CurriculumDelivered, ZaloGroupAdded: r.ZaloGroupAdded, EnrolledAt: r.EnrolledAt.Format(time.DateOnly),
		LeftAt: fmtDate(r.LeftAt), Status: r.Status, Dropped: r.Status == "dropped", FromCRM: r.LeadID != nil, Confirmed: r.ConfirmedAt != nil,
	}
}

// Enrollments: lượt xếp lớp của học viên trong phạm vi, kèm lựa chọn học viên / lớp cho form xếp lớp.
func (s *Service) Enrollments(ctx context.Context, actor *iam.Access, f EnrollmentFilter) (*EnrollmentList, error) {
	ss, err := s.studentScope(ctx, actor)
	if err != nil {
		return nil, err
	}
	if f.Page < 1 {
		f.Page = 1
	}
	rows, err := s.q.ListEnrollments(ctx, classesdb.ListEnrollmentsParams{
		StudentID: f.StudentID, ClassID: f.ClassID, LimitBranches: ss.limitBranches, BranchIds: ss.branches,
		LimitClasses: ss.limitClasses, ClassIds: ss.classes, Lim: enrollmentsPageSize, Off: int32((f.Page - 1) * enrollmentsPageSize),
	})
	if err != nil {
		return nil, err
	}
	out := &EnrollmentList{Enrollments: []Enrollment{}, Page: f.Page, PerPage: enrollmentsPageSize, CanAssign: actor.Can("student.assign_class")}
	for _, r := range rows {
		out.Total = r.Total
		out.Enrollments = append(out.Enrollments, toEnrollment(r))
	}
	classes, err := s.q.EnrollableClasses(ctx, ss.classesParams())
	if err != nil {
		return nil, err
	}
	out.Classes = make([]EnrollClassOption, 0, len(classes))
	for _, c := range classes {
		seat := fmt.Sprint(c.RosterCount)
		if c.MaxCapacity > 0 {
			seat = fmt.Sprintf("%d/%d", c.RosterCount, c.MaxCapacity)
		}
		out.Classes = append(out.Classes, EnrollClassOption{
			ID: c.ID, Label: fmt.Sprintf("%s (%s)", c.Name, c.Code), BranchID: c.BranchID, CourseID: c.CourseID, Seat: seat,
			Full: c.MaxCapacity > 0 && c.RosterCount >= int64(c.MaxCapacity),
		})
	}
	sts, err := s.q.EnrollableStudents(ctx, classesdb.EnrollableStudentsParams{
		LimitBranches: ss.limitBranches, BranchIds: ss.branches, LimitClasses: ss.limitClasses, ClassIds: ss.classes,
	})
	if err != nil {
		return nil, err
	}
	out.Students = make([]StudentOption, 0, len(sts))
	for _, st := range sts {
		out.Students = append(out.Students, StudentOption{Value: st.ID, Label: fmt.Sprintf("%s (%s)", st.FullName, st.Code), BranchID: st.BranchID, Branch: st.BranchName})
	}
	return out, nil
}

type EnrollInput struct {
	StudentID *uuid.UUID `json:"student_id"`
	ClassID   *uuid.UUID `json:"class_id"`
}

// enrollableClass: lớp (chưa xóa) trong phạm vi xếp lớp.
func (s *Service) enrollableClass(ctx context.Context, ss studentScope, id uuid.UUID) (*classesdb.EnrollableClassesRow, error) {
	classes, err := s.q.EnrollableClasses(ctx, ss.classesParams())
	if err != nil {
		return nil, err
	}
	for _, c := range classes {
		if c.ID == id {
			return &c, nil
		}
	}
	return nil, nil
}

// joinCheck: điều kiện vào lớp (assertCanJoinClass).
func joinCheck(ctx context.Context, q *classesdb.Queries, st classesdb.LockStudentRow, classID, classBranch uuid.UUID) error {
	if st.Status == "dropped" {
		return iam.Invalid("Học viên đã thôi học, không thể xếp vào lớp.")
	}
	if st.BranchID != nil && *st.BranchID != classBranch {
		return iam.Invalid("Học viên và lớp phải thuộc cùng chi nhánh.")
	}
	has, err := q.HasActiveEnrollment(ctx, classesdb.HasActiveEnrollmentParams{StudentID: st.ID, ClassID: classID})
	if err != nil {
		return err
	}
	if has {
		return iam.Invalid("Học viên đã có trong danh sách của lớp này.")
	}
	return nil
}

// seatCheck: còn chỗ (max_capacity = 0 là không giới hạn); gọi trong transaction, khóa dòng lớp.
func seatCheck(ctx context.Context, q *classesdb.Queries, classID uuid.UUID) error {
	c, err := q.LockClass(ctx, classID)
	if isNoRows(err) {
		return ErrNotFound
	}
	if err != nil || c.MaxCapacity <= 0 {
		return err
	}
	cur, err := q.GetClass(ctx, classID)
	if err != nil {
		return err
	}
	if cur.RosterCount >= int64(c.MaxCapacity) {
		return iam.Invalid(fmt.Sprintf("Lớp %s đã đủ sĩ số (%d học viên).", c.Name, c.MaxCapacity))
	}
	return nil
}

// Enroll: xếp lớp chính (storeEnrollment). main = false: "Liên kết lớp khác" (học song song, không đổi lớp chính;
// học viên chưa có lớp chính thì lớp vừa liên kết thành lớp chính).
func (s *Service) Enroll(ctx context.Context, actor *iam.Access, in EnrollInput, main bool) (string, error) {
	if in.StudentID == nil {
		return "", iam.Invalid("Vui lòng chọn học viên.")
	}
	if in.ClassID == nil {
		return "", iam.Invalid("Vui lòng chọn lớp.")
	}
	ss, err := s.studentScope(ctx, actor)
	if err != nil {
		return "", err
	}
	if ok, err := ss.studentVisible(ctx, s.q, *in.StudentID); err != nil {
		return "", err
	} else if !ok {
		return "", &iam.Error{Status: 404, Code: "student_not_found", Message: "Không tìm thấy học viên."}
	}
	class, err := s.enrollableClass(ctx, ss, *in.ClassID)
	if err != nil {
		return "", err
	}
	if class == nil {
		if main {
			return "", ErrNotFound
		}
		return "", iam.Invalid("Lớp không thuộc phạm vi bạn quản lý.")
	}
	today := s.today()
	var studentName string
	err = s.tx(ctx, func(_ pgx.Tx, q *classesdb.Queries) error {
		st, err := q.LockStudent(ctx, *in.StudentID)
		if isNoRows(err) {
			return &iam.Error{Status: 404, Code: "student_not_found", Message: "Không tìm thấy học viên."}
		}
		if err != nil {
			return err
		}
		studentName = st.FullName
		if err := joinCheck(ctx, q, st, class.ID, class.BranchID); err != nil {
			return err
		}
		// TODO(crm): học viên chốt từ CRM đang Chờ xếp lớp — kiểm khóa / chi nhánh đã chốt và hoàn tất lead
		// (WaitingLeadPlacement::complete: lead → Đã chốt, học phí gắn lớp).
		if err := seatCheck(ctx, q, class.ID); err != nil {
			return err
		}
		if _, err := q.CreateEnrollment(ctx, classesdb.CreateEnrollmentParams{
			StudentID: st.ID, ClassID: class.ID, EnrolledAt: today, CreatedBy: &actor.UserID,
		}); err != nil {
			return err
		}
		if main {
			// Đổi lớp chính (chuyển lớp): lượt ở lớp chính cũ hết hiệu lực từ hôm nay.
			if st.CurrentClassID != nil && *st.CurrentClassID != class.ID {
				if err := q.CloseEnrollments(ctx, classesdb.CloseEnrollmentsParams{Today: today, StudentID: st.ID, ClassID: *st.CurrentClassID}); err != nil {
					return err
				}
			}
			return q.SetStudentClass(ctx, classesdb.SetStudentClassParams{ID: st.ID, ClassID: &class.ID})
		}
		if st.CurrentClassID == nil {
			return q.SetStudentClass(ctx, classesdb.SetStudentClassParams{ID: st.ID, ClassID: &class.ID})
		}
		return nil
	})
	if isUnique(err, "enrollments_active_uq") {
		return "", iam.Invalid("Học viên đã có trong danh sách của lớp này.")
	}
	if err != nil {
		return "", err
	}
	s.log.Info("student enrolled", "student_id", *in.StudentID, "class_id", class.ID, "main", main, "by", actor.UserID)
	if main {
		return "Đã xếp lớp. Học vụ cần hoàn tất checklist giáo trình và nhóm lớp.", nil
	}
	return fmt.Sprintf("Đã liên kết học viên %s với lớp %s.", studentName, class.Name), nil
}

type HandoffInput struct {
	CurriculumDelivered bool `json:"curriculum_delivered"`
	ZaloGroupAdded      bool `json:"zalo_group_added"`
}

// UpdateHandoff: checklist bàn giao; đủ hai mục thì lượt xếp lớp hoàn tất, vào lớp đã khai giảng thì học viên
// Chờ khai giảng chuyển Đang học (ClassStartActivation).
func (s *Service) UpdateHandoff(ctx context.Context, actor *iam.Access, id uuid.UUID, in HandoffInput) (string, error) {
	notFound := &iam.Error{Status: 404, Code: "enrollment_not_found", Message: "Không tìm thấy lượt xếp lớp."}
	e, err := s.q.GetEnrollment(ctx, id)
	if isNoRows(err) {
		return "", notFound
	}
	if err != nil {
		return "", err
	}
	ss, err := s.studentScope(ctx, actor)
	if err != nil {
		return "", err
	}
	if ok, err := ss.studentVisible(ctx, s.q, e.StudentID); err != nil {
		return "", err
	} else if !ok {
		return "", notFound
	}
	if e.Status == "dropped" {
		return "", iam.Invalid("Học viên đã thôi học, không cập nhật bàn giao cho lượt xếp lớp này.")
	}
	status := "pending"
	if in.CurriculumDelivered && in.ZaloGroupAdded {
		status = "completed"
	}
	started, err := s.ClassStarted(ctx, e.ClassID)
	if err != nil {
		return "", err
	}
	err = s.tx(ctx, func(_ pgx.Tx, q *classesdb.Queries) error {
		if err := q.UpdateHandoff(ctx, classesdb.UpdateHandoffParams{
			ID: id, CurriculumDelivered: in.CurriculumDelivered, ZaloGroupAdded: in.ZaloGroupAdded, Status: status,
		}); err != nil {
			return err
		}
		if status == "completed" && started {
			return q.StartStudying(ctx, e.StudentID)
		}
		return nil
	})
	if err != nil {
		return "", err
	}
	s.log.Info("enrollment handoff updated", "enrollment_id", id, "status", status, "by", actor.UserID)
	if status == "completed" {
		return "Đã hoàn tất bàn giao học viên vào lớp.", nil
	}
	return "Đã cập nhật checklist; bàn giao vẫn đang chờ hoàn tất.", nil
}

// StartStudying: học viên Chờ khai giảng đã bàn giao xong vào lớp đã khai giảng → Đang học (lệnh hằng ngày
// students:start-studying); trả số học viên đã chuyển.
func (s *Service) StartStudying(ctx context.Context) (int64, error) {
	return s.q.StartStudyingDue(ctx, s.today())
}

// RunStartStudyingLoop chạy StartStudying định kỳ đến khi ctx kết thúc.
func (s *Service) RunStartStudyingLoop(ctx context.Context, every time.Duration) {
	t := time.NewTicker(every)
	defer t.Stop()
	for {
		if n, err := s.StartStudying(ctx); err != nil && ctx.Err() == nil {
			s.log.Error("start studying failed", "err", err)
		} else if n > 0 {
			s.log.Info("students started studying", "count", n)
		}
		select {
		case <-ctx.Done():
			return
		case <-t.C:
		}
	}
}
