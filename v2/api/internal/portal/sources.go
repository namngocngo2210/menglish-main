package portal

import (
	"context"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// Phần dữ liệu cổng học viên lấy từ phân hệ khác qua interface nhỏ (module nguồn cài, nối trong cmd/menglish/main.go).
// Chưa gắn thì dùng bản rỗng: màn vẫn mở, phần đó trống, thao tác ghi báo "chờ phân hệ".

// ErrPending: thao tác cần phân hệ chưa chuyển sang v2.
func errPending(module string) error {
	return &iam.Error{Status: 503, Code: "module_pending", Message: "Chức năng này chờ phân hệ " + module + " chuyển sang hệ thống mới."}
}

// Students: phạm vi học viên của nhân sự (student.scope_*), để nhân sự xem hộ / sửa hộ (Student::visibleTo).
type Students interface {
	Visible(ctx context.Context, actor *iam.Access, id uuid.UUID) (bool, error)
}

// ───── Học phí ─────

// TuitionTotals: tổng các khoản học phí của học viên (chỉ phiếu đã duyệt tính vào đã đóng / còn nợ).
type TuitionTotals struct {
	HasTuition   bool  `json:"has_tuition"`
	FinalAmount  int64 `json:"final_amount"`
	PaidAmount   int64 `json:"paid_amount"`
	DebtAmount   int64 `json:"debt_amount"`
	Installments int   `json:"installments"`
}

// Tuition: lịch sử thu học phí dạng học viên xem (TuitionPaymentHistory::forStudent không có phần của Học vụ).
// history là JSON của phân hệ Học phí (summary, tuitions, payments), nil khi không có.
type Tuition interface {
	PortalPayments(ctx context.Context, studentID uuid.UUID) (TuitionTotals, any, error)
}

type noTuition struct{}

func (noTuition) PortalPayments(context.Context, uuid.UUID) (TuitionTotals, any, error) {
	return TuitionTotals{}, nil, nil
}

// ───── Cổng giáo viên: bài tập, bài nộp, nhận xét buổi học, mini test ─────

// Loại bài nộp cố định của màn "Học tập của tôi" (6 hạng mục) — nhãn như Laravel.
var HomeworkTypes = []struct{ Key, Label string }{
	{"video", "Quay video bài học"},
	{"vocabulary", "Viết từ vựng & Chụp ảnh"},
	{"workbook", "Làm Workbook bài tập"},
	{"extra_book", "Sách bổ trợ"},
	{"bgd_book", "Sách Bộ Giáo dục"},
	{"quiz", "Làm Quiz trực tuyến"},
}

func homeworkLabel(key string) (string, bool) {
	for _, t := range HomeworkTypes {
		if t.Key == key {
			return t.Label, true
		}
	}
	return "", false
}

// Submission: một bài nộp của học viên.
type Submission struct {
	ID             uuid.UUID `json:"id"`
	StudentID      uuid.UUID `json:"-"`
	HomeworkType   string    `json:"homework_type"`
	Status         string    `json:"status"` // submitted | reviewed
	SubmittedAt    time.Time `json:"submitted_at"`
	Score          *string   `json:"score"`
	Feedback       *string   `json:"feedback"`
	Notes          *string   `json:"notes"`
	AttachmentURL  *string   `json:"attachment_url"`
	AttachmentName *string   `json:"attachment_name"`
	StudentName    string    `json:"student_name,omitempty"`
}

// AssignedHomework: bài tập giáo viên giao gần nhất của các lớp.
type AssignedHomework struct {
	Title       string     `json:"title"`
	Description *string    `json:"description"`
	ClassName   *string    `json:"class_name"`
	DueDate     *time.Time `json:"due_date"`
}

// Remark: nhận xét buổi học đã gửi (không phải nháp) của giáo viên cho học viên.
type Remark struct {
	Date     time.Time `json:"date"`
	Monsters *string   `json:"monsters"`
	Grammar  *string   `json:"grammar"`
	Attitude *string   `json:"attitude"`
	Result   *string   `json:"result"`
	Comment  *string   `json:"comment"`
}

// MiniTest: điểm mini test.
type MiniTest struct {
	ID       uuid.UUID  `json:"id"`
	Name     string     `json:"name"`
	TestDate *time.Time `json:"test_date"`
	Score    float64    `json:"score"`
	MaxScore float64    `json:"max_score"`
}

// PracticeItem: file nghe giáo viên đính kèm bài tập (bài nghe mẫu luyện phát âm).
type PracticeItem struct {
	Title     string  `json:"title"`
	ClassName *string `json:"class_name"`
	AudioURL  string  `json:"audio_url"`
}

// Learning: dữ liệu màn "Học tập của tôi".
type Learning struct {
	// Bài nộp, mới nhất trước (màn lấy bài mới nhất của từng loại)
	Submissions []Submission
	Latest      *AssignedHomework
	// Nhận xét buổi học gần nhất (tối đa 3), điểm mini test (tối đa 10)
	Remarks   []Remark
	MiniTests []MiniTest
}

// HomeworkStats: bài GV giao của các lớp / số lượt nộp, số hạng mục đã nộp (trang chủ, App Shell).
type HomeworkStats struct {
	Assigned       int
	Submitted      int
	SubmittedTypes int
}

// SubmissionInput: học viên nộp bài (file đã lưu vào kho file trong cùng transaction).
type SubmissionInput struct {
	StudentID      uuid.UUID
	StudentName    string
	HomeworkType   string
	HomeworkLabel  string
	Notes          *string
	FileID         *uuid.UUID
	AttachmentName *string
	By             uuid.UUID
}

// SubmissionUpdate: sửa ghi chú / thay file bài nộp (nil = giữ nguyên).
type SubmissionUpdate struct {
	Notes          *string
	SetNotes       bool
	FileID         *uuid.UUID
	AttachmentName *string
	By             uuid.UUID
}

// TeacherPortal: bài tập GV giao và bài nộp của học viên (phân hệ Cổng giáo viên, TeacherPortalController).
// TODO(gộp): phân hệ Cổng giáo viên (homeworks, homework_submissions, remarks, mini_test_scores) đang làm song song;
// khi gộp thì cài interface này và truyền vào WithTeacherPortal trong cmd/menglish/main.go.
type TeacherPortal interface {
	// Ready: false khi phân hệ chưa gắn (nộp / sửa / chấm bài báo "chờ phân hệ").
	Ready() bool
	Learning(ctx context.Context, studentID uuid.UUID, classIDs []uuid.UUID) (Learning, error)
	HomeworkStats(ctx context.Context, studentID uuid.UUID, classIDs []uuid.UUID) (HomeworkStats, error)
	PracticeAudio(ctx context.Context, classIDs []uuid.UUID) ([]PracticeItem, error)
	// SubmissionOwner: học viên của bài nộp (pgx.ErrNoRows khi không có).
	SubmissionOwner(ctx context.Context, id uuid.UUID) (uuid.UUID, error)
	Submit(ctx context.Context, tx pgx.Tx, in SubmissionInput) (uuid.UUID, error)
	UpdateSubmission(ctx context.Context, tx pgx.Tx, id uuid.UUID, in SubmissionUpdate) error
	DeleteSubmission(ctx context.Context, tx pgx.Tx, id uuid.UUID) error
	// ClassSubmissions: bài nộp loại homeworkType của các học viên (màn Chấm bài nộp), mới nhất trước.
	ClassSubmissions(ctx context.Context, studentIDs []uuid.UUID, homeworkType string) ([]Submission, error)
	ReviewSubmission(ctx context.Context, tx pgx.Tx, id, by uuid.UUID, score, feedback *string) error
}

type noTeacherPortal struct{}

func (noTeacherPortal) Ready() bool { return false }
func (noTeacherPortal) Learning(context.Context, uuid.UUID, []uuid.UUID) (Learning, error) {
	return Learning{}, nil
}
func (noTeacherPortal) HomeworkStats(context.Context, uuid.UUID, []uuid.UUID) (HomeworkStats, error) {
	return HomeworkStats{}, nil
}
func (noTeacherPortal) PracticeAudio(context.Context, []uuid.UUID) ([]PracticeItem, error) {
	return nil, nil
}
func (noTeacherPortal) SubmissionOwner(context.Context, uuid.UUID) (uuid.UUID, error) {
	return uuid.Nil, pgx.ErrNoRows
}
func (noTeacherPortal) Submit(context.Context, pgx.Tx, SubmissionInput) (uuid.UUID, error) {
	return uuid.Nil, errPending("Cổng giáo viên")
}
func (noTeacherPortal) UpdateSubmission(context.Context, pgx.Tx, uuid.UUID, SubmissionUpdate) error {
	return errPending("Cổng giáo viên")
}
func (noTeacherPortal) DeleteSubmission(context.Context, pgx.Tx, uuid.UUID) error {
	return errPending("Cổng giáo viên")
}
func (noTeacherPortal) ClassSubmissions(context.Context, []uuid.UUID, string) ([]Submission, error) {
	return nil, nil
}
func (noTeacherPortal) ReviewSubmission(context.Context, pgx.Tx, uuid.UUID, uuid.UUID, *string, *string) error {
	return errPending("Cổng giáo viên")
}

// ───── Khảo sát 5 sao ─────

// OpenSurvey: đợt khảo sát đang mở do Học vụ tạo (Survey::active).
type OpenSurvey struct {
	ID          uuid.UUID  `json:"id"`
	Title       string     `json:"title"`
	Description *string    `json:"description"`
	Deadline    *time.Time `json:"deadline"`
}

// SurveyResponse: khảo sát học viên đã gửi.
type SurveyResponse struct {
	ID          uuid.UUID  `json:"id"`
	SurveyID    *uuid.UUID `json:"survey_id"`
	Title       string     `json:"title"`
	Rating      int        `json:"rating"`
	Feedback    string     `json:"feedback"`
	SubmittedAt time.Time  `json:"submitted_at"`
}

// SurveyInput: học viên / phụ huynh gửi khảo sát.
type SurveyInput struct {
	SurveyID    uuid.UUID
	StudentID   uuid.UUID
	StudentName string
	Rating      int
	Feedback    string
	By          uuid.UUID
}

// Surveys: đợt khảo sát và phản hồi (phân hệ Khảo sát, bảng ops.surveys / ops.survey_responses).
// TODO(gộp): phân hệ Khảo sát (ticket / khảo sát) đang làm song song; khi gộp cài interface này và truyền vào
// WithSurveys trong cmd/menglish/main.go.
type Surveys interface {
	Ready() bool
	Open(ctx context.Context) ([]OpenSurvey, error)
	Responses(ctx context.Context, studentID uuid.UUID) ([]SurveyResponse, error)
	Respond(ctx context.Context, tx pgx.Tx, in SurveyInput) error
	// ResponseOwner: học viên của phản hồi (pgx.ErrNoRows khi không có).
	ResponseOwner(ctx context.Context, id uuid.UUID) (uuid.UUID, error)
	DeleteResponse(ctx context.Context, tx pgx.Tx, id uuid.UUID) error
}

type noSurveys struct{}

func (noSurveys) Ready() bool                                { return false }
func (noSurveys) Open(context.Context) ([]OpenSurvey, error) { return nil, nil }
func (noSurveys) Respond(context.Context, pgx.Tx, SurveyInput) error {
	return errPending("Khảo sát")
}
func (noSurveys) Responses(context.Context, uuid.UUID) ([]SurveyResponse, error) {
	return nil, nil
}
func (noSurveys) ResponseOwner(context.Context, uuid.UUID) (uuid.UUID, error) {
	return uuid.Nil, pgx.ErrNoRows
}
func (noSurveys) DeleteResponse(context.Context, pgx.Tx, uuid.UUID) error {
	return errPending("Khảo sát")
}

// ───── Giáo trình: Big Test, chặng học ─────

// BigTestResult: kết quả Big Test đã duyệt / đã gửi phụ huynh.
type BigTestResult struct {
	ID          uuid.UUID  `json:"id"`
	Title       string     `json:"title"`
	ScheduledAt *time.Time `json:"scheduled_at"`
	IsAbsent    bool       `json:"is_absent"`
	Overall     *float64   `json:"overall_score"`
	Listening   *float64   `json:"listening_score"`
	Reading     *float64   `json:"reading_score"`
	Writing     *float64   `json:"writing_score"`
	Speaking    *float64   `json:"speaking_score"`
	Note        *string    `json:"progress_note"`
}

// Syllabus: kết quả Big Test và chặng đang học của lớp (phân hệ Giáo trình).
// TODO(gộp): phân hệ Giáo trình & Big Test đang làm song song; khi gộp cài interface này và truyền vào WithSyllabus.
type Syllabus interface {
	BigTestResults(ctx context.Context, studentID uuid.UUID, limit int) ([]BigTestResult, error)
	// CurrentStage: tên chặng đang học (giao chặng in_progress mới nhất) của lớp, nil khi chưa có.
	CurrentStage(ctx context.Context, classID uuid.UUID) (*string, error)
}

type noSyllabus struct{}

func (noSyllabus) BigTestResults(context.Context, uuid.UUID, int) ([]BigTestResult, error) {
	return nil, nil
}
func (noSyllabus) CurrentStage(context.Context, uuid.UUID) (*string, error) { return nil, nil }
