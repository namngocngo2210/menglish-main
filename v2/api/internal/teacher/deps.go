package teacher

import (
	"context"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/files"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// Phân hệ đang làm song song: interface + mặc định "chưa có" (TODO(gộp): truyền adapter thật vào Deps ở cmd/menglish).

// ───────────── Chấm công giờ dạy, lương tạm tính, vi phạm (Lương & chấm công) ─────────────

// Work: chấm công giờ dạy của giáo viên (Laravel teacher_timesheets, TeacherHourlyRate, penalties).
// TODO(gộp): adapter của phân hệ Lương / chấm công (hr.timesheets, hr.rates, hr.penalties).
type Work interface {
	// Available: phân hệ đã có ở v2 (false → màn hiện "Chờ phân hệ…", nút check-in báo chưa dùng được).
	Available() bool
	// Checkins: lượt chấm công (khác "không hợp lệ") của người dùng theo buổi; buổi chưa check-in không có trong map.
	Checkins(ctx context.Context, userID uuid.UUID, sessionIDs []uuid.UUID) (map[uuid.UUID]Checkin, error)
	// CheckIn: ghi chấm công các buổi đã qua kiểm phân công + cửa sổ check-in (TeacherPortalController::checkin): bỏ qua
	// buổi đã chấm công tay / đã check-in (chỉ ca bị từ chối được check-in lại), kỳ lương đã khóa theo ngày dạy.
	CheckIn(ctx context.Context, userID uuid.UUID, now time.Time, sessions []CheckinSession) (CheckinResult, error)
	// Month: bảng công / lương tạm tính / vi phạm của người dùng trong [from, to].
	Month(ctx context.Context, userID uuid.UUID, from, to time.Time) (*WorkMonth, error)
}

// Checkin: chấm công của một buổi.
type Checkin struct {
	Time   string // HH:MM
	Status string // pending_review | valid | invalid | rejected
}

// CheckinSession: buổi được check-in (đã kiểm phân công và cửa sổ check-in).
type CheckinSession struct {
	SessionID   uuid.UUID
	ClassID     uuid.UUID
	ClassName   string
	Date        time.Time
	Start, End  string // HH:MM
	Type        string // regular | makeup | support
	LateMinutes int    // số phút check-in sau giờ bắt đầu (check-in sớm = 0, tối đa 1440)
}

// CheckinResult: số buổi đã ghi, lý do bỏ qua ("<lớp>: <lý do>"), thông báo kỳ lương đã khóa.
type CheckinResult struct {
	Count   int
	Skipped []string
	Locked  string
}

// WorkMonth: số liệu chấm công tháng của giáo viên.
type WorkMonth struct {
	Hours         float64         // tổng giờ dạy (trừ phiên bị từ chối)
	Sessions      int             // số phiên chấm công
	ValidHours    float64         // giờ đã đối soát
	ValidSessions int             // phiên hợp lệ
	Pending       int             // phiên chờ Học vụ duyệt
	Estimate      *int64          // lương tạm tính (giờ × đơn giá); nil = chưa có đơn giá
	Rows          []TimesheetRow  // chi tiết chấm công, mới nhất trước
	Violations    int             // vi phạm trong tháng
	Latest        *ViolationBrief // vi phạm gần nhất
}

type TimesheetRow struct {
	ID        uuid.UUID `json:"id"`
	Date      string    `json:"date"` // dd/mm/YYYY
	ClassName string    `json:"class_name"`
	Type      string    `json:"type"`
	Hours     float64   `json:"hours"`
	Status    string    `json:"status"`
}

type ViolationBrief struct {
	Type        string `json:"violation_type"`
	Date        string `json:"date"` // dd/mm
	StatusLabel string `json:"status_label"`
}

// NoWork: chưa có phân hệ chấm công / lương.
type NoWork struct{}

func (NoWork) Available() bool { return false }
func (NoWork) Checkins(context.Context, uuid.UUID, []uuid.UUID) (map[uuid.UUID]Checkin, error) {
	return map[uuid.UUID]Checkin{}, nil
}
func (NoWork) CheckIn(context.Context, uuid.UUID, time.Time, []CheckinSession) (CheckinResult, error) {
	return CheckinResult{}, errWorkPending
}
func (NoWork) Month(context.Context, uuid.UUID, time.Time, time.Time) (*WorkMonth, error) {
	return nil, nil
}

const workPending = "Chờ phân hệ Lương & chấm công chuyển sang hệ thống mới"

var errWorkPending = &iam.Error{Status: 409, Code: "work_pending",
	Message: "Chấm công giờ dạy chưa dùng được trên hệ thống mới (" + workPending + "). Vui lòng check-in trên hệ thống cũ."}

// ───────────── Giáo trình ─────────────

// Syllabus: giáo trình đang áp dụng cho lớp (SessionLessonService::unitsForClass / lessonsFor).
// TODO(gộp): adapter của phân hệ Giáo trình.
type Syllabus interface {
	// Units: Unit của giáo trình lớp; rỗng = lớp chưa gắn giáo trình (nhập tên bài tự do).
	Units(ctx context.Context, classID uuid.UUID) ([]Unit, error)
	// Lessons: bài học theo số buổi chính khóa của lớp (nhãn buổi khi giao bài).
	Lessons(ctx context.Context, classID uuid.UUID, numbers []int) (map[int]Lesson, error)
}

type Unit struct {
	ID     uuid.UUID
	Number int
	Title  string
}

type Lesson struct {
	Unit  string // "Unit 3: …"
	Title string
}

type NoSyllabus struct{}

func (NoSyllabus) Units(context.Context, uuid.UUID) ([]Unit, error) { return nil, nil }
func (NoSyllabus) Lessons(context.Context, uuid.UUID, []int) (map[int]Lesson, error) {
	return map[int]Lesson{}, nil
}

// ───────────── Danh sách bổ trợ ─────────────

// SupportList: điểm mini test dưới 7/10 tự vào danh sách bổ trợ (SupportListService::syncMiniTest).
// TODO(gộp): adapter của phân hệ Chất lượng lớp / buổi bổ trợ.
type SupportList interface {
	SyncMiniTest(ctx context.Context, tx pgx.Tx, score MiniTestRef) error
}

type MiniTestRef struct {
	ID        uuid.UUID
	ClassID   uuid.UUID
	StudentID uuid.UUID
	Name      string
	Score     float64
	MaxScore  float64
	TestDate  time.Time
}

type NoSupportList struct{}

func (NoSupportList) SyncMiniTest(context.Context, pgx.Tx, MiniTestRef) error { return nil }

// ───────────── Bài phát âm (cổng học viên) ─────────────

// Pronunciation: bản ghi âm học viên nộp (tab "Phát âm" của màn chấm bài) — phân hệ Cổng học viên (adapter ở
// cmd/menglish/portal_teacher.go, gắn bằng WithPronunciation).
type Pronunciation interface {
	Available() bool
	// ClassSubmissions: bài phát âm của học viên đang trong lớp, mới nhất trước.
	ClassSubmissions(ctx context.Context, classID uuid.UUID) ([]Submission, error)
	// Mark: chấm bài phát âm (điểm bắt buộc, thang 100); ok = false khi không có bài hoặc học viên không thuộc lớp
	// người chấm được (gradable kiểm học viên).
	Mark(ctx context.Context, id uuid.UUID, by uuid.UUID, score, feedback string, gradable func(studentID uuid.UUID) (bool, error)) (bool, error)
}

type NoPronunciation struct{}

func (NoPronunciation) Available() bool { return false }
func (NoPronunciation) ClassSubmissions(context.Context, uuid.UUID) ([]Submission, error) {
	return []Submission{}, nil
}
func (NoPronunciation) Mark(context.Context, uuid.UUID, uuid.UUID, string, string, func(uuid.UUID) (bool, error)) (bool, error) {
	return false, nil
}

// ───────────── File của cổng học viên ─────────────

// FileAccess: cổng học viên cho học viên / phụ huynh mở file bài nộp của mình và file nghe bài tập lớp mình
// (StudentPortal.SetFileAccess). Trả true khi người này được mở file.
type FileAccess func(ctx context.Context, a *iam.Access, f files.File) (bool, error)
