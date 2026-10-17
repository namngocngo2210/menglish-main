// Package portal là Cổng Phụ huynh & Học sinh (bản Laravel: StudentPortalController, Pages/Portal/*): trang chủ (lịch
// học, điểm danh, học phí, thông tin học sinh, báo đóng học phí), học tập & nộp bài tập, luyện phát âm, hộp thư thông
// báo, khảo sát 5 sao, góp ý chặng học, App Shell; phía nhân sự: chấm bản ghi âm (màn Chấm bài nộp, tab Phát âm) và
// xác nhận "Học viên báo đã đóng học phí".
//
// Người xem: tài khoản học viên / phụ huynh (portal.student) thấy học viên gắn với mình; nhân sự có student.view xem hộ
// học viên trong phạm vi (student.scope_*), sửa hộ cần thêm student.update (authorizeStudent của Laravel).
package portal

import (
	"context"
	"crypto/rand"
	"errors"
	"fmt"
	"log/slog"
	"math/big"
	"slices"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgxpool"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/files"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/portal/portaldb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/students"
)

var (
	ErrForbidden = &iam.Error{Status: 403, Code: "forbidden", Message: "Bạn không được phép truy cập hồ sơ học viên này."}
	ErrNotLinked = &iam.Error{Status: 403, Code: "not_linked", Message: "Tài khoản chưa được liên kết với hồ sơ học viên."}
	ErrNotFound  = &iam.Error{Status: 404, Code: "not_found", Message: "Không tìm thấy bản ghi."}
	errNoStudent = &iam.Error{Status: 422, Code: "invalid", Message: "Vui lòng chọn học viên."}
)

// Trạng thái hồ sơ còn dùng cổng học viên (học viên vừa chốt / nghỉ hè vẫn vào được).
var portalStatuses = []string{students.StatusWaitingStart, students.StatusStudying, students.StatusSummerBreak}

// UpcomingDays: số ngày lịch học sắp tới ở trang chủ.
const UpcomingDays = 14

var vnZone = time.FixedZone("Asia/Ho_Chi_Minh", 7*3600)

func init() {
	audit.RegisterSubject("payment_report", "Báo đóng học phí")
	audit.RegisterSubject("pronunciation_record", "Bản ghi âm phát âm")
	audit.RegisterSubject("stage_feedback", "Góp ý chặng học")
	audit.RegisterSubject("homework_submission", "Bài nộp")
	audit.RegisterPath("/portal", audit.ModuleStudents)
	notify.RegisterType("tuition_payment_report", notify.TypeInfo{Label: "Báo đóng học phí", Icon: "task_alt", Tone: notify.ToneSuccess})
	notify.RegisterType("tuition_payment_report_rejected", notify.TypeInfo{Label: "Báo đóng học phí chưa xác nhận", Icon: "error", Tone: notify.ToneError})
	notify.RegisterType("pronunciation_reviewed", notify.TypeInfo{Label: "Bài phát âm đã chấm", Icon: "mic", Tone: notify.TonePrimary})
}

type Service struct {
	pool     *pgxpool.Pool
	q        *portaldb.Queries
	log      *slog.Logger
	now      func() time.Time
	files    *files.Service
	students Students
	tuition  Tuition
	teacher  TeacherPortal
	surveys  Surveys
	syllabus Syllabus
}

// NewService: fileSvc lưu bản ghi âm / bài nộp; studentSvc cho phạm vi học viên của nhân sự xem hộ.
func NewService(pool *pgxpool.Pool, fileSvc *files.Service, studentSvc Students, log *slog.Logger) *Service {
	s := &Service{pool: pool, q: portaldb.New(pool), log: log, now: time.Now, files: fileSvc, students: studentSvc,
		tuition: noTuition{}, teacher: noTeacherPortal{}, surveys: noSurveys{}, syllabus: noSyllabus{}}
	if fileSvc != nil {
		// File bài nộp (homework_submission) thuộc phân hệ Cổng giáo viên: nó hỏi lại StudentFileAccess / ClassFileAccess.
		fileSvc.RegisterReader(fileSubjectPronunciation, s.readStudentFile)
	}
	return s
}

func (s *Service) WithTuition(t Tuition) *Service {
	if t != nil {
		s.tuition = t
	}
	return s
}

func (s *Service) WithTeacherPortal(t TeacherPortal) *Service {
	if t != nil {
		s.teacher = t
	}
	return s
}

func (s *Service) WithSurveys(x Surveys) *Service {
	if x != nil {
		s.surveys = x
	}
	return s
}

func (s *Service) WithSyllabus(x Syllabus) *Service {
	if x != nil {
		s.syllabus = x
	}
	return s
}

func (s *Service) today() time.Time {
	n := s.now().In(vnZone)
	return time.Date(n.Year(), n.Month(), n.Day(), 0, 0, 0, 0, time.UTC)
}

// ───── Học viên đang xem ─────

// StudentOption: một học viên trong ô "Đổi học viên".
type StudentOption struct {
	ID        uuid.UUID `json:"id"`
	Code      string    `json:"code"`
	Name      string    `json:"name"`
	ClassName *string   `json:"class_name"`
}

// Context: phần chung của mọi màn cổng (portalProps): học viên đang chọn, danh sách đổi học viên, số chưa đọc.
type Context struct {
	Student     *StudentOption  `json:"student"`
	Students    []StudentOption `json:"students"`
	UnreadCount int             `json:"unread_count"`
	// CanWrite: được nộp bài / sửa / xóa trên hồ sơ này (chủ hồ sơ hoặc nhân sự có student.update trong phạm vi)
	CanWrite bool `json:"can_write"`
	// Staff: người xem là nhân sự xem hộ (không có học viên nào gắn với tài khoản)
	Staff bool `json:"staff"`
}

// active: học viên đang chọn cùng thông tin quyền của người xem.
type active struct {
	Context
	row     portaldb.GetStudentRow
	viewer  *uuid.UUID // người xem là học viên / phụ huynh của học viên (hộp thư gồm thông báo gửi chính người xem)
	classes []uuid.UUID
}

func (a *active) id() uuid.UUID { return a.row.ID }

func option(r portaldb.LinkedStudentsRow) StudentOption {
	return StudentOption{ID: r.ID, Code: r.Code, Name: r.FullName, ClassName: r.ClassName}
}

// resolve: học viên đang xem (getActiveStudent). Không chọn thì lấy học viên đầu tiên gắn với tài khoản; nhân sự
// chưa chọn học viên thì trả Context không có học viên. Ngoài phạm vi / không gắn → 403 như Laravel.
func (s *Service) resolve(ctx context.Context, actor *iam.Access, studentID *uuid.UUID) (*active, error) {
	uid := actor.UserID
	linked, err := s.q.LinkedStudents(ctx, &uid)
	if err != nil {
		return nil, err
	}
	a := &active{Context: Context{Students: make([]StudentOption, 0, len(linked))}}
	for _, l := range linked {
		a.Students = append(a.Students, option(l))
	}
	manage := actor.Can("student.view")
	a.Staff = len(linked) == 0 && manage
	if studentID == nil {
		if len(linked) == 0 {
			if manage {
				return a, nil
			}
			return nil, ErrNotLinked
		}
		studentID = &linked[0].ID
	}
	row, err := s.q.GetStudent(ctx, portaldb.GetStudentParams{UserID: &uid, ID: *studentID})
	if errors.Is(err, pgx.ErrNoRows) {
		return nil, ErrForbidden
	}
	if err != nil {
		return nil, err
	}
	if !slices.Contains(portalStatuses, row.Status) {
		return nil, ErrForbidden
	}
	visible := false
	if !row.Linked {
		if !manage {
			return nil, ErrForbidden
		}
		if visible, err = s.students.Visible(ctx, actor, row.ID); err != nil {
			return nil, err
		} else if !visible {
			return nil, ErrForbidden
		}
		a.Students = append(a.Students, StudentOption{ID: row.ID, Code: row.Code, Name: row.FullName, ClassName: row.ClassName})
	} else {
		a.viewer = &uid
	}
	a.row = row
	a.Student = &StudentOption{ID: row.ID, Code: row.Code, Name: row.FullName, ClassName: row.ClassName}
	a.CanWrite = row.Linked || (actor.Can("student.update") && visible)
	if a.classes, err = s.q.ActiveClassIDs(ctx, row.ID); err != nil {
		return nil, err
	}
	n, err := s.q.InboxUnread(ctx, s.inbox(a))
	if err != nil {
		return nil, err
	}
	a.UnreadCount = int(n)
	return a, nil
}

// resolveRequired như resolve nhưng bắt buộc có học viên (nhân sự chưa chọn → 422).
func (s *Service) resolveRequired(ctx context.Context, actor *iam.Access, studentID *uuid.UUID) (*active, error) {
	a, err := s.resolve(ctx, actor, studentID)
	if err != nil {
		return nil, err
	}
	if a.Student == nil {
		return nil, errNoStudent
	}
	return a, nil
}

// writable: học viên được ghi (authorizeStudent): chủ hồ sơ, hoặc nhân sự có student.update và học viên trong phạm vi.
// Không xét trạng thái hồ sơ như Laravel.
func (s *Service) writable(ctx context.Context, actor *iam.Access, studentID uuid.UUID) (portaldb.GetStudentRow, error) {
	uid := actor.UserID
	row, err := s.q.GetStudent(ctx, portaldb.GetStudentParams{UserID: &uid, ID: studentID})
	if errors.Is(err, pgx.ErrNoRows) {
		return row, ErrForbidden
	}
	if err != nil {
		return row, err
	}
	if row.Linked {
		return row, nil
	}
	if !actor.Can("student.update") {
		return row, ErrForbidden
	}
	ok, err := s.students.Visible(ctx, actor, studentID)
	if err != nil {
		return row, err
	}
	if !ok {
		return row, ErrForbidden
	}
	return row, nil
}

// readStudentFile: tải file bản ghi âm (subject = học viên).
func (s *Service) readStudentFile(ctx context.Context, a *iam.Access, f files.File) (string, error) {
	if f.SubjectID == nil {
		return "", iam.ErrForbidden
	}
	ok, err := s.StudentFileAccess(ctx, a, *f.SubjectID)
	if err != nil {
		return "", err
	}
	if !ok {
		return "", iam.ErrForbidden
	}
	return "", nil
}

// StudentFileAccess: người dùng mở được file của học viên (bản ghi âm, bài nộp): chủ hồ sơ (học viên / phụ huynh), giáo
// viên chấm bài của lớp học viên, nhân sự xem được học viên.
func (s *Service) StudentFileAccess(ctx context.Context, a *iam.Access, studentID uuid.UUID) (bool, error) {
	uid := a.UserID
	row, err := s.q.GetStudent(ctx, portaldb.GetStudentParams{UserID: &uid, ID: studentID})
	if errors.Is(err, pgx.ErrNoRows) {
		return false, nil
	}
	if err != nil {
		return false, err
	}
	if row.Linked {
		return true, nil
	}
	if a.Can("homework.grade") {
		ok, err := s.q.TeacherCoversStudent(ctx, portaldb.TeacherCoversStudentParams{
			AllClasses: a.Level("class") == iam.ScopeAll, UserID: &uid, StudentID: row.ID})
		if err != nil || ok {
			return ok, err
		}
	}
	if a.Can("student.view") {
		return s.students.Visible(ctx, a, row.ID)
	}
	return false, nil
}

// ClassFileAccess: người dùng mở được file nghe bài tập của lớp (bài nghe mẫu ở màn Luyện phát âm): học viên / phụ huynh
// của học viên đang học lớp; nhân sự student.view xem hộ (bản Laravel để file nghe công khai trong /storage).
func (s *Service) ClassFileAccess(ctx context.Context, a *iam.Access, classID uuid.UUID) (bool, error) {
	if a.Can("student.view") {
		return true, nil
	}
	rows, err := s.q.LinkedStudents(ctx, &a.UserID)
	if err != nil {
		return false, err
	}
	for _, r := range rows {
		ids, err := s.q.ActiveClassIDs(ctx, r.ID)
		if err != nil {
			return false, err
		}
		if slices.Contains(ids, classID) {
			return true, nil
		}
	}
	return false, nil
}

// ───── Mã nghiệp vụ ─────

const codeChars = "ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789"

// randomCode: tiền tố + 6 ký tự ngẫu nhiên viết hoa (Str::random(6) của Laravel).
func randomCode(prefix string) string {
	var b strings.Builder
	b.WriteString(prefix)
	for range 6 {
		n, _ := rand.Int(rand.Reader, big.NewInt(int64(len(codeChars))))
		b.WriteByte(codeChars[n.Int64()])
	}
	return b.String()
}

// ───── Kiểm dữ liệu ─────

func runes(s string) int { return utf8.RuneCountInString(s) }

func trimPtr(s *string) *string {
	if s == nil {
		return nil
	}
	t := strings.TrimSpace(*s)
	if t == "" {
		return nil
	}
	return &t
}

func maxLen(s *string, n int, msg string) error {
	if s != nil && runes(*s) > n {
		return iam.Invalid(msg)
	}
	return nil
}

func ptr[T any](v T) *T { return &v }

// ───── Trang chủ ─────

// Profile: thông tin học sinh ở trang chủ.
type Profile struct {
	StatusLabel    string     `json:"status_label"`
	Dob            *time.Time `json:"dob"`
	Phone          string     `json:"phone"`
	Address        *string    `json:"address"`
	Notes          *string    `json:"notes"`
	TeacherName    *string    `json:"teacher_name"`
	StudyStartedOn *time.Time `json:"study_started_on"`
	TenureLabel    *string    `json:"tenure_label"`
}

type Progress struct {
	AttendancePresent int      `json:"attendance_present"`
	AttendanceTotal   int      `json:"attendance_total"`
	HomeworkSubmitted int      `json:"homework_submitted"`
	HomeworkTotal     int      `json:"homework_total"`
	LatestBigTest     *float64 `json:"latest_big_test"`
}

type SessionItem struct {
	ID        uuid.UUID `json:"id"`
	Date      time.Time `json:"date"`
	StartTime string    `json:"start_time"`
	EndTime   string    `json:"end_time"`
	ClassName string    `json:"class_name"`
	RoomLabel *string   `json:"room_label"`
	Cancelled bool      `json:"cancelled"`
	Type      string    `json:"type"`
}

type AttendanceItem struct {
	ID          uuid.UUID `json:"id"`
	Date        time.Time `json:"date"`
	ClassName   string    `json:"class_name"`
	Note        *string   `json:"note"`
	Present     bool      `json:"present"`
	StatusLabel string    `json:"status_label"`
}

// PaymentReport: một lần "Báo đóng học phí" (học viên xem trạng thái ở trang chủ).
type PaymentReport struct {
	ID              uuid.UUID  `json:"id"`
	Code            string     `json:"code"`
	Amount          int64      `json:"amount"`
	Content         string     `json:"content"`
	Status          string     `json:"status"`
	StatusLabel     string     `json:"status_label"`
	RejectionReason *string    `json:"rejection_reason"`
	CreatedAt       time.Time  `json:"created_at"`
	ResolvedAt      *time.Time `json:"resolved_at"`
}

type Home struct {
	Context
	Profile        *Profile         `json:"profile"`
	Tuition        TuitionTotals    `json:"tuition"`
	NextTermFee    int64            `json:"next_term_fee"`
	Progress       Progress         `json:"progress"`
	UpcomingDays   int              `json:"upcoming_days"`
	Upcoming       []SessionItem    `json:"upcoming_sessions"`
	Attendance     []AttendanceItem `json:"attendance_history"`
	BigTests       []BigTestResult  `json:"big_test_results"`
	Classes        []string         `json:"student_classes"`
	PaymentReports []PaymentReport  `json:"payment_reports"`
}

var attendanceLabels = map[string]string{"present": "Có mặt", "absent": "Vắng", "late": "Đi muộn", "excused": "Vắng có phép"}

func attendanceLabel(s string) string {
	if l, ok := attendanceLabels[s]; ok {
		return l
	}
	return s
}

// roomLabel: "Phòng 101"; tên đã có chữ "Phòng" thì giữ nguyên (ClassSession::roomLabel).
func roomLabel(room *string) *string {
	if room == nil || strings.TrimSpace(*room) == "" {
		return nil
	}
	r := strings.TrimSpace(*room)
	if strings.HasPrefix(strings.ToLower(r), "phòng") {
		return &r
	}
	return ptr("Phòng " + r)
}

var reportStatusLabels = map[string]string{"pending": "Chờ kế toán xác nhận", "confirmed": "Đã xác nhận", "rejected": "Chưa xác nhận"}

func toPaymentReport(r portaldb.FinancePaymentReport) PaymentReport {
	return PaymentReport{ID: r.ID, Code: r.Code, Amount: r.Amount, Content: r.Content, Status: r.Status,
		StatusLabel: reportStatusLabels[r.Status], RejectionReason: r.RejectionReason, CreatedAt: r.CreatedAt, ResolvedAt: r.ResolvedAt}
}

// Home: trang chủ cổng học viên (studentHome).
func (s *Service) Home(ctx context.Context, actor *iam.Access, studentID *uuid.UUID) (*Home, error) {
	a, err := s.resolve(ctx, actor, studentID)
	if err != nil {
		return nil, err
	}
	out := &Home{Context: a.Context, UpcomingDays: UpcomingDays, Upcoming: []SessionItem{}, Attendance: []AttendanceItem{},
		BigTests: []BigTestResult{}, Classes: []string{}, PaymentReports: []PaymentReport{}}
	if a.Student == nil {
		return out, nil
	}
	r := a.row
	today := s.today()
	out.Profile = &Profile{StatusLabel: students.StatusLabel(r.Status), Dob: r.Dob, Phone: r.Phone, Address: r.Address,
		Notes: r.Notes, TeacherName: r.TeacherName, StudyStartedOn: r.StudyStartedOn,
		TenureLabel: students.TenureLabel(r.StudyStartedOn, r.Status, today)}
	if r.CourseFee != nil {
		out.NextTermFee = *r.CourseFee
	}
	if out.Tuition, _, err = s.tuition.PortalPayments(ctx, r.ID); err != nil {
		return nil, err
	}
	names, err := s.q.ClassNames(ctx, nonNil(a.classes))
	if err != nil {
		return nil, err
	}
	for _, n := range names {
		out.Classes = append(out.Classes, n.Name)
	}
	att, err := s.q.AttendanceProgress(ctx, r.ID)
	if err != nil {
		return nil, err
	}
	out.Progress.AttendancePresent, out.Progress.AttendanceTotal = int(att.Present), int(att.Total)
	hw, err := s.teacher.HomeworkStats(ctx, r.ID, a.classes)
	if err != nil {
		return nil, err
	}
	out.Progress.HomeworkSubmitted, out.Progress.HomeworkTotal = hw.Submitted, hw.Assigned

	sessions, err := s.q.UpcomingSessions(ctx, portaldb.UpcomingSessionsParams{
		ClassIds: nonNil(a.classes), FromDate: today, ToDate: today.AddDate(0, 0, UpcomingDays)})
	if err != nil {
		return nil, err
	}
	// TODO(gộp): buổi phụ đạo riêng của học viên (support_sessions) khi phân hệ Bổ trợ có ở v2.
	for _, cs := range sessions {
		out.Upcoming = append(out.Upcoming, SessionItem{ID: cs.ID, Date: cs.Date, StartTime: cs.StartTime, EndTime: cs.EndTime,
			ClassName: cs.ClassName, RoomLabel: roomLabel(cs.Room), Cancelled: cs.Status == "cancelled", Type: cs.Type})
	}
	hist, err := s.q.AttendanceHistory(ctx, r.ID)
	if err != nil {
		return nil, err
	}
	for _, h := range hist {
		out.Attendance = append(out.Attendance, AttendanceItem{ID: h.ID, Date: h.Day, ClassName: h.ClassName, Note: h.Note,
			Present: h.Status == "present" || h.Status == "late", StatusLabel: attendanceLabel(h.Status)})
	}
	bt, err := s.syllabus.BigTestResults(ctx, r.ID, 0)
	if err != nil {
		return nil, err
	}
	out.BigTests = append(out.BigTests, bt...)
	if len(bt) > 0 {
		out.Progress.LatestBigTest = bt[0].Overall
	}
	reports, err := s.q.StudentPaymentReports(ctx, r.ID)
	if err != nil {
		return nil, err
	}
	for _, p := range reports {
		out.PaymentReports = append(out.PaymentReports, toPaymentReport(p))
	}
	return out, nil
}

func nonNil(v []uuid.UUID) []uuid.UUID {
	if v == nil {
		return []uuid.UUID{}
	}
	return v
}

// TuitionHistory: lịch sử thu học phí của học viên đang xem (hộp thoại "Lịch sử thu học phí").
func (s *Service) TuitionHistory(ctx context.Context, actor *iam.Access, studentID *uuid.UUID) (any, error) {
	a, err := s.resolveRequired(ctx, actor, studentID)
	if err != nil {
		return nil, err
	}
	_, h, err := s.tuition.PortalPayments(ctx, a.id())
	return h, err
}

// ───── App Shell ─────

type AppShell struct {
	Context
	StatusLabel       *string       `json:"status_label"`
	Phone             *string       `json:"phone"`
	Tuition           TuitionTotals `json:"tuition"`
	PendingHomeworks  int           `json:"pending_homeworks"`
	SubmittedHomework int           `json:"submitted_homeworks"`
	Pronunciations    int           `json:"pronunciation_count"`
	Surveys           int           `json:"survey_count"`
	HasFeedback       bool          `json:"has_feedback"`
	HomeworkTypes     int           `json:"homework_types"`
}

// AppShell: màn "Ứng dụng di động" (appShell): lưới chức năng kèm số liệu thật của học viên đang chọn.
func (s *Service) AppShell(ctx context.Context, actor *iam.Access, studentID *uuid.UUID) (*AppShell, error) {
	a, err := s.resolve(ctx, actor, studentID)
	if err != nil {
		return nil, err
	}
	out := &AppShell{Context: a.Context, HomeworkTypes: len(HomeworkTypes), PendingHomeworks: len(HomeworkTypes)}
	if a.Student == nil {
		return out, nil
	}
	r := a.row
	out.StatusLabel, out.Phone = ptr(students.StatusLabel(r.Status)), &r.Phone
	if out.Tuition, _, err = s.tuition.PortalPayments(ctx, r.ID); err != nil {
		return nil, err
	}
	hw, err := s.teacher.HomeworkStats(ctx, r.ID, a.classes)
	if err != nil {
		return nil, err
	}
	out.SubmittedHomework = hw.SubmittedTypes
	out.PendingHomeworks = max(0, len(HomeworkTypes)-hw.SubmittedTypes)
	n, err := s.q.CountPronunciations(ctx, r.ID)
	if err != nil {
		return nil, err
	}
	out.Pronunciations = int(n)
	sv, err := s.surveys.Responses(ctx, r.ID)
	if err != nil {
		return nil, err
	}
	out.Surveys = len(sv)
	fb, err := s.q.StudentFeedbacks(ctx, r.ID)
	if err != nil {
		return nil, err
	}
	out.HasFeedback = len(fb) > 0
	return out, nil
}

// Summary: số thông báo chưa đọc của mọi học viên gắn với tài khoản (chuông topbar của tài khoản cổng).
func (s *Service) Summary(ctx context.Context, actor *iam.Access) (int, error) {
	n, err := s.q.UnreadForUser(ctx, actor.UserID)
	return int(n), err
}

// ───── Sửa thông tin liên hệ ─────

type ContactInput struct {
	Phone   string  `json:"phone"`
	Address *string `json:"address"`
	Notes   *string `json:"notes"`
}

func (in *ContactInput) validate() error {
	in.Phone = strings.TrimSpace(in.Phone)
	in.Address, in.Notes = trimPtr(in.Address), trimPtr(in.Notes)
	switch {
	case in.Phone == "":
		return iam.Invalid("Vui lòng nhập Số điện thoại.")
	case runes(in.Phone) > 20:
		return iam.Invalid("Trường Số điện thoại không được vượt quá 20 ký tự.")
	}
	if err := maxLen(in.Address, 255, "Trường Địa chỉ không được vượt quá 255 ký tự."); err != nil {
		return err
	}
	return maxLen(in.Notes, 500, "Trường Ghi chú không được vượt quá 500 ký tự.")
}

// UpdateContact: học viên / phụ huynh sửa SĐT liên hệ, địa chỉ, ghi chú (updateProfile).
func (s *Service) UpdateContact(ctx context.Context, actor *iam.Access, studentID uuid.UUID, in ContactInput) error {
	if err := in.validate(); err != nil {
		return err
	}
	st, err := s.writable(ctx, actor, studentID)
	if err != nil {
		return err
	}
	err = pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
		q := s.q.WithTx(tx)
		if _, err := q.UpdateContact(ctx, portaldb.UpdateContactParams{ID: st.ID, Phone: in.Phone, Address: in.Address, Notes: in.Notes}); err != nil {
			return err
		}
		if err := q.AddStudentEvent(ctx, portaldb.AddStudentEventParams{StudentID: st.ID,
			Message: "Cập nhật liên hệ / ghi chú từ Cổng học viên.", CreatedBy: &actor.UserID}); err != nil {
			return err
		}
		return audit.Record(ctx, tx, audit.Entry{ActorID: &actor.UserID, Module: audit.ModuleStudents, Event: audit.EventUpdated,
			Action: "portal.profile_update", SubjectType: "student", SubjectID: st.ID.String(),
			SubjectLabel: fmt.Sprintf("%s (%s)", st.FullName, st.Code), BranchID: st.BranchID,
			Description: "Cập nhật thông tin liên hệ học viên từ Cổng học viên",
			Changes: audit.Diff(map[string]any{"phone": st.Phone, "address": st.Address, "notes": st.Notes},
				map[string]any{"phone": in.Phone, "address": in.Address, "notes": in.Notes})})
	})
	if err != nil {
		return err
	}
	s.log.Info("portal contact updated", "student", st.ID, "by", actor.UserID)
	return nil
}

// ───── Báo đóng học phí ─────

type PaymentReportInput struct {
	StudentID *uuid.UUID `json:"student_id"`
	Amount    *int64     `json:"amount"`
	Content   string     `json:"content"`
}

func (in *PaymentReportInput) validate() error {
	in.Content = strings.TrimSpace(in.Content)
	switch {
	case in.StudentID == nil:
		return errNoStudent
	case in.Amount == nil:
		return iam.Invalid("Vui lòng nhập Số tiền.")
	case *in.Amount < 1000:
		return iam.Invalid("Trường Số tiền phải tối thiểu là 1.000.")
	case in.Content == "":
		return iam.Invalid("Vui lòng nhập nội dung chuyển khoản / ghi chú.")
	case runes(in.Content) > 500:
		return iam.Invalid("Trường nội dung không được vượt quá 500 ký tự.")
	}
	return nil
}

// SubmitPaymentReport: học viên / phụ huynh báo đã chuyển khoản học phí (submitTuitionRequest); kế toán xác nhận
// hoặc từ chối ở màn "Học viên báo đóng học phí".
func (s *Service) SubmitPaymentReport(ctx context.Context, actor *iam.Access, in PaymentReportInput) (*PaymentReport, error) {
	if err := in.validate(); err != nil {
		return nil, err
	}
	st, err := s.writable(ctx, actor, *in.StudentID)
	if err != nil {
		return nil, err
	}
	var out PaymentReport
	err = pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
		r, err := s.q.WithTx(tx).InsertPaymentReport(ctx, portaldb.InsertPaymentReportParams{Code: randomCode("YCHOCPHI-"),
			StudentID: st.ID, BranchID: st.BranchID, Amount: *in.Amount, Content: in.Content, CreatedBy: &actor.UserID})
		if err != nil {
			return err
		}
		out = toPaymentReport(r)
		return audit.Record(ctx, tx, audit.Entry{ActorID: &actor.UserID, Module: audit.ModuleTuition, Event: audit.EventCreated,
			Action: "portal.payment_report", SubjectType: "payment_report", SubjectID: r.ID.String(), SubjectLabel: r.Code,
			BranchID: st.BranchID, Description: "Báo đóng học phí: " + money(r.Amount) + " - " + st.FullName})
	})
	if err != nil {
		return nil, err
	}
	s.log.Info("portal payment report", "report", out.ID, "student", st.ID, "by", actor.UserID)
	return &out, nil
}

// money: 1500000 → "1.500.000đ" (Money::format).
func money(v int64) string {
	neg := v < 0
	if neg {
		v = -v
	}
	s := fmt.Sprint(v)
	var b strings.Builder
	for i, c := range s {
		if i > 0 && (len(s)-i)%3 == 0 {
			b.WriteByte('.')
		}
		b.WriteRune(c)
	}
	if neg {
		return "-" + b.String() + "đ"
	}
	return b.String() + "đ"
}

// sendPortal: thông báo vào hộp thư cổng của học viên (tài khoản học viên, chưa có thì phụ huynh), kèm student_id.
func (s *Service) sendPortal(ctx context.Context, tx pgx.Tx, studentID uuid.UUID, n notify.Notification) error {
	ids, err := s.q.WithTx(tx).StudentRecipients(ctx, studentID)
	if err != nil {
		return err
	}
	users := make([]uuid.UUID, 0, len(ids))
	for _, id := range ids {
		if id != nil {
			users = append(users, *id)
		}
	}
	data := map[string]any{"student_id": studentID.String()}
	for k, v := range n.Data {
		data[k] = v
	}
	n.Data = data
	_, err = notify.Send(ctx, tx, users, n)
	return err
}
