package classes

import (
	"context"
	"fmt"
	"strings"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/classes/classesdb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/crm"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/crm/crmdb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/students"
)

// CRMStudents: phần Chốt & Xếp lớp của CRM (crm.StudentCreator) — CrmController::processClosingWizard (tạo hồ sơ học viên,
// tài khoản cổng học viên, ghi danh lớp) và assignClass (xếp lớp khách Chờ xếp lớp). Chạy trong giao dịch của CRM.
// Học phí / phiếu thu / task nhắc thu chưa có ở v2 (phân hệ Học phí): giá trị hợp đồng = học phí niêm yết của khóa trừ ưu đãi.
type CRMStudents struct {
	cls *Service
	stu *students.Service
}

// CRMStudents gắn phân hệ học viên cho luồng chốt khách (cmd/menglish: crm.Service.WithStudents).
func (s *Service) CRMStudents(stu *students.Service) *CRMStudents {
	return &CRMStudents{cls: s, stu: stu}
}

var _ crm.StudentCreator = (*CRMStudents)(nil)

const msgTuitionPending = "Học phí, phiếu thu và task nhắc thu sẽ lập khi phân hệ Học phí chuyển sang hệ thống mới."

// enrollableForClosing: lớp nhận ghi danh khi chốt — đang học, hoặc sắp khai giảng chưa tới ngày bắt đầu.
func enrollableForClosing(status string, start *time.Time, today time.Time) bool {
	return status == StatusActive || (status == StatusUpcoming && (start == nil || !start.Before(today)))
}

func hasSeat(c classesdb.LockClassForClosingRow) bool {
	return c.MaxCapacity <= 0 || c.RosterCount < int64(c.MaxCapacity)
}

func toCRMEnrollment(id uuid.UUID, st students.Created, name string, c classesdb.LockClassForClosingRow) *crm.Enrollment {
	code := c.Code
	return &crm.Enrollment{
		EnrollmentID: &id, StudentID: st.ID, StudentCode: st.Code, StudentName: name, ClassID: c.ID, ClassName: c.Name,
		ClassCode: &code, ClassBranchID: &c.BranchID, ClassStartDate: c.StartDate,
	}
}

// lockClass: lớp chọn khi chốt / xếp lớp, còn nhận học viên và khóa học còn hoạt động.
func (b *CRMStudents) lockClass(ctx context.Context, q *classesdb.Queries, id uuid.UUID) (classesdb.LockClassForClosingRow, error) {
	c, err := q.LockClassForClosing(ctx, id)
	if isNoRows(err) {
		return c, iam.Invalid("Lớp học đã chọn không tồn tại.")
	}
	if err != nil {
		return c, err
	}
	if !enrollableForClosing(c.Status, c.StartDate, b.cls.today()) || c.CourseActive == nil || !*c.CourseActive {
		return c, iam.Invalid("Lớp hoặc khóa học không còn hoạt động.")
	}
	return c, nil
}

// discount: ưu đãi đã chọn (CRM đã khóa dòng) áp vào học phí; chọn lớp thì kiểm cơ sở / khóa theo lớp ở đây.
func (b *CRMStudents) discount(ctx context.Context, tx pgx.Tx, id *uuid.UUID, branch, course *uuid.UUID, base int64, checkScope bool) (int64, error) {
	if id == nil {
		return 0, nil
	}
	p, err := crmdb.New(tx).GetPromotion(ctx, *id)
	if isNoRows(err) {
		return 0, iam.Invalid("Ưu đãi không hợp lệ.")
	}
	if err != nil {
		return 0, err
	}
	if checkScope && !crm.PromotionApplicable(p, branch, course, b.cls.now()) && !(p.IsSpecial && p.UsedCount == 0) {
		return 0, iam.Invalid("Ưu đãi đã hết hiệu lực hoặc không áp dụng cho cơ sở / khóa này.")
	}
	return int64(crm.CalculateDiscount(p.Type, p.Value, p.MaxDiscountAmount, float64(base))), nil
}

func firstText(vals ...*string) string {
	for _, v := range vals {
		if v != nil && strings.TrimSpace(*v) != "" {
			return strings.TrimSpace(*v)
		}
	}
	return ""
}

func text(v *string) string {
	if v == nil {
		return ""
	}
	return *v
}

// CreateFromLead: tạo hồ sơ học viên (students.Service.Create, kèm tài khoản cổng học viên) và ghi danh lớp nếu chọn lớp.
func (b *CRMStudents) CreateFromLead(ctx context.Context, tx pgx.Tx, actor *iam.Access, lead crm.LeadSnapshot, in crm.CloseInput) (*crm.ClosedStudent, error) {
	q := b.cls.q.WithTx(tx)
	var class *classesdb.LockClassForClosingRow
	var courseID uuid.UUID
	var courseName string
	var fee int64
	var lessons int32
	if in.ClassID != nil {
		c, err := b.lockClass(ctx, q, *in.ClassID)
		if err != nil {
			return nil, err
		}
		if lead.BranchID != nil && *lead.BranchID != c.BranchID {
			return nil, iam.Invalid("Lớp được chọn phải thuộc cùng chi nhánh với Lead.")
		}
		if !hasSeat(c) {
			return nil, iam.Invalid("Lớp đã đủ sĩ số, vui lòng chọn lớp khác.")
		}
		class, courseID, courseName, fee, lessons = &c, *c.CourseID, deref(c.CourseName), deref(c.CourseFee), deref(c.CourseLessons)
	} else {
		co, err := q.CourseForClosing(ctx, *in.CourseID)
		if (err != nil && isNoRows(err)) || (err == nil && !co.IsActive) {
			return nil, iam.Invalid("Khóa học không còn hoạt động.")
		}
		if err != nil {
			return nil, err
		}
		courseID, courseName, fee, lessons = co.ID, co.Name, co.TuitionFee, co.TotalLessons
	}
	if fee <= 0 {
		return nil, iam.Invalid("Lớp / khóa học chưa được cấu hình học phí.")
	}
	branch := lead.BranchID
	if class != nil {
		branch = &class.BranchID
	}
	off, err := b.discount(ctx, tx, in.PromotionID, branch, &courseID, fee, class != nil)
	if err != nil {
		return nil, err
	}
	total := max(0, fee-off)

	in2 := students.CreateInput{
		Code: text(in.StudentCode), LeadID: &lead.ID, FullName: lead.Name, Phone: lead.Phone, Email: text(lead.Email),
		ParentName: text(lead.ParentName), ParentPhone: text(lead.ParentPhone), DOB: lead.Dob, Gender: text(lead.Gender),
		Address: text(lead.Address), School: text(lead.School), BranchID: branch,
		Target: firstText(lead.CourseInterest, &courseName), EntranceScore: firstText(lead.TestScore, ptr("Chưa test")),
		TotalLessons: &lessons,
	}
	if class != nil {
		in2.CurrentClassID = &class.ID
	}
	st, err := b.stu.Create(ctx, tx, actor, in2)
	if err != nil {
		return nil, err
	}
	out := &crm.ClosedStudent{
		StudentID: st.ID, StudentCode: st.Code, StudentName: lead.Name, ContractTotal: total, CourseName: courseName, BranchID: branch,
		Message: msgTuitionPending,
	}
	if st.TemporaryPassword != "" {
		out.Message += fmt.Sprintf(" Tài khoản cổng học viên: %s, mật khẩu tạm %s (đổi khi đăng nhập lần đầu).", st.Login, st.TemporaryPassword)
	}
	if class == nil {
		out.CourseID = &courseID
		return out, nil
	}
	id, err := q.CreateLeadEnrollment(ctx, classesdb.CreateLeadEnrollmentParams{
		StudentID: st.ID, ClassID: class.ID, LeadID: &lead.ID, EnrolledAt: b.cls.today(), CreatedBy: &actor.UserID,
	})
	if err != nil {
		return nil, err
	}
	out.ClassName = &class.Name
	out.Enrollment = toCRMEnrollment(id, *st, lead.Name, *class)
	b.cls.log.Info("crm lead enrolled", "lead_id", lead.ID, "student_id", st.ID, "class_id", class.ID, "by", actor.UserID)
	return out, nil
}

// EnrollWaiting: Học vụ gán lớp cho học viên Chờ xếp lớp (assignClass + WaitingLeadPlacement): lớp đúng chi nhánh của
// học viên / chi nhánh đã chốt, đúng khóa đã chốt hoặc cùng trình độ, chưa có trong lớp, còn chỗ.
func (b *CRMStudents) EnrollWaiting(ctx context.Context, tx pgx.Tx, actor *iam.Access, lead crm.LeadSnapshot, studentID, classID uuid.UUID) (*crm.Enrollment, error) {
	q := b.cls.q.WithTx(tx)
	st, err := q.LockStudent(ctx, studentID)
	if isNoRows(err) {
		return nil, iam.Invalid("Chỉ xếp lớp cho học viên đang Chờ xếp lớp.")
	}
	if err != nil {
		return nil, err
	}
	c, err := b.lockClass(ctx, q, classID)
	if err != nil {
		return nil, err
	}
	branch := st.BranchID
	if branch == nil {
		branch = lead.BranchID
	}
	if branch != nil && c.BranchID != *branch {
		return nil, iam.Invalid("Lớp phải thuộc chi nhánh của học viên.")
	}
	if err := checkWaitingPlacement(ctx, q, lead, c); err != nil {
		return nil, err
	}
	if in, err := q.HasActiveEnrollment(ctx, classesdb.HasActiveEnrollmentParams{StudentID: st.ID, ClassID: c.ID}); err != nil {
		return nil, err
	} else if in || (st.CurrentClassID != nil && *st.CurrentClassID == c.ID) {
		return nil, iam.Invalid("Học viên đã có trong danh sách của lớp này.")
	}
	if !hasSeat(c) {
		return nil, iam.Invalid("Lớp đã đủ sĩ số, vui lòng chọn lớp khác.")
	}
	id, err := q.CreateLeadEnrollment(ctx, classesdb.CreateLeadEnrollmentParams{
		StudentID: st.ID, ClassID: c.ID, LeadID: &lead.ID, EnrolledAt: b.cls.today(), CreatedBy: &actor.UserID,
	})
	if isUnique(err, "enrollments_active_uq") {
		return nil, iam.Invalid("Học viên đã có trong danh sách của lớp này.")
	}
	if err != nil {
		return nil, err
	}
	if err := q.SetStudentClass(ctx, classesdb.SetStudentClassParams{ID: st.ID, ClassID: &c.ID}); err != nil {
		return nil, err
	}
	b.cls.log.Info("crm waiting student enrolled", "lead_id", lead.ID, "student_id", st.ID, "class_id", c.ID, "by", actor.UserID)
	return toCRMEnrollment(id, students.Created{ID: st.ID, Code: st.Code}, st.FullName, c), nil
}

// matchesClosedCourse (PlacementLevelMatcher::matchesClosed): đúng khóa đã chốt, hoặc lớp cùng trình độ với khóa đã chốt.
// Khách không có khóa chốt → không giới hạn. Cấp độ test đầu vào chưa ghép (phân hệ test chưa lưu cấp độ đề).
func matchesClosedCourse(ctx context.Context, q *classesdb.Queries, lead crm.LeadSnapshot, c classesdb.LockClassForClosingRow) error {
	if lead.WaitingCourseID == nil || (c.CourseID != nil && *c.CourseID == *lead.WaitingCourseID) {
		return nil
	}
	co, err := q.CourseForClosing(ctx, *lead.WaitingCourseID)
	if err != nil && !isNoRows(err) {
		return err
	}
	level := c.ClassLevelID
	if level == nil {
		level = c.CourseLevelID
	}
	if err == nil && co.CourseLevelID != nil && level != nil && *level == *co.CourseLevelID {
		return nil
	}
	msg := "Lớp phải thuộc khóa học đã chốt (khóa đã chọn)"
	if err == nil {
		msg = "Lớp phải thuộc khóa học đã chốt (" + co.Name + ")"
		if co.LevelName != nil {
			msg += " hoặc cùng cấp độ (" + *co.LevelName + ")"
		}
	}
	return iam.Invalid(msg + ".")
}

// checkWaitingPlacement: lớp đúng khóa (hoặc cấp độ) và chi nhánh đã chốt của khách Chờ xếp lớp.
func checkWaitingPlacement(ctx context.Context, q *classesdb.Queries, lead crm.LeadSnapshot, c classesdb.LockClassForClosingRow) error {
	if err := matchesClosedCourse(ctx, q, lead, c); err != nil {
		return err
	}
	if lead.WaitingBranchID != nil && c.BranchID != *lead.WaitingBranchID {
		name, err := q.BranchName(ctx, *lead.WaitingBranchID)
		if err != nil && !isNoRows(err) {
			return err
		}
		if name == "" {
			name = "chi nhánh đã chọn"
		}
		return iam.Invalid(fmt.Sprintf("Lớp phải thuộc chi nhánh đã chốt (%s).", name))
	}
	return nil
}

// waitingLead: khách CRM Chờ xếp lớp của học viên (khóa dòng), nil nếu không có hoặc chưa gắn CRM.
func (s *Service) waitingLead(ctx context.Context, tx pgx.Tx, q *classesdb.Queries, studentID uuid.UUID) (*crm.LeadSnapshot, error) {
	if s.CRM == nil {
		return nil, nil
	}
	id, err := q.WaitingLeadOfStudent(ctx, &studentID)
	if isNoRows(err) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	return s.CRM.LockWaitingLead(ctx, tx, id)
}

var _ crm.ClassStarts = (*CRMStudents)(nil)

// ClassStarts (crm.ClassStarts): lớp đã khai giảng (đang hoạt động và tới ngày khai giảng) hoặc đã kết thúc.
func (b *CRMStudents) ClassStarts(ctx context.Context, classIDs []uuid.UUID) (map[uuid.UUID]crm.ClassStart, error) {
	out := map[uuid.UUID]crm.ClassStart{}
	if len(classIDs) == 0 {
		return out, nil
	}
	rows, err := b.cls.q.ClassStartInfos(ctx, classIDs)
	if err != nil {
		return nil, err
	}
	today := b.cls.today()
	for _, r := range rows {
		out[r.ID] = crm.ClassStart{Started: isStudying(r.Status, r.StartDate, today) || r.Status == StatusCompleted, StartDate: r.StartDate}
	}
	return out, nil
}
