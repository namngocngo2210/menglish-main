// Package syllabus: phân hệ Giáo trình & Big Test — kho tài liệu giáo trình, soạn giáo trình 4 cấp (giáo trình → chặng →
// unit → buổi), giao chặng cho lớp (mỗi lớp 1 chặng đang mở, đóng khi Big Test của chặng duyệt & gửi đủ phụ huynh rồi
// tự mở chặng kế), đề xuất sửa giáo trình, giãn tiến độ (thêm buổi theo TKB của lớp), Big Test: order đề của giáo viên,
// duyệt & phân phối đề, nhắc lịch thi, nhập / duyệt kết quả và gửi phụ huynh, SLA Big Test.
// Bám theo bản Laravel: SyllabusController, TeacherPortalController::orderTest, SyllabusProgressionService,
// ScheduleExtensionService, AdjustmentSlaService, BigTestSlaService, bigtests:remind-upcoming / bigtests:enforce-sla.
package syllabus

import (
	"context"
	"errors"
	"log/slog"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgconn"
	"github.com/jackc/pgx/v5/pgxpool"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/catalogs"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/files"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/syllabus/syllabusdb"
)

// Giờ Việt Nam (không đổi giờ theo mùa).
var vnZone = time.FixedZone("ICT", 7*3600)

// Classes: phần việc của phân hệ Lớp học mà giáo trình cần (classes.Service.SyllabusClasses(), gắn ở cmd/menglish).
type Classes interface {
	// VisibleClassIDs: lớp người dùng được xem (ClassModel::visibleTo).
	VisibleClassIDs(ctx context.Context, actor *iam.Access) ([]uuid.UUID, error)
	// ExtendSessions: giãn tiến độ — thêm count buổi chính khóa nối tiếp sau buổi cuối theo ca học lặp tuần, bỏ ngày
	// nghỉ lễ, chặn trùng phòng / nhân sự; lùi ngày kết thúc lớp (ScheduleExtensionService::extend). Lỗi người dùng là
	// *iam.Error 422.
	ExtendSessions(ctx context.Context, tx pgx.Tx, classID uuid.UUID, count int, note string, by *uuid.UUID) (Extension, error)
}

// Extension: kết quả giãn tiến độ.
type Extension struct {
	Dates   []time.Time // ngày các buổi vừa thêm
	EndDate *time.Time  // ngày kết thúc lớp sau khi thêm
}

// SLA: đọc luật SLA (catalogs.Service).
type SLA interface {
	SlaRule(ctx context.Context, key string) (catalogs.SlaRule, error)
}

// Penalties: biên bản vi phạm tự lập của SLA Big Test (Penalty của Laravel).
// TODO(gộp): phân hệ Lương & biên bản (đang làm song song) cài interface này; chưa gắn thì chỉ gửi thông báo sla_breach
// như khi tắt "Tự lập biên bản".
type Penalties interface {
	// SyncLateResults lập (hoặc cập nhật số ngày trễ / mức gợi ý của) biên bản trả kết quả Big Test trễ hạn.
	SyncLateResults(ctx context.Context, tx pgx.Tx, in LatePenalty) (created bool, err error)
}

// LatePenalty: dữ liệu biên bản trả kết quả trễ (BigTestSlaService::enforceLateResults).
type LatePenalty struct {
	BigTestID   uuid.UUID
	ClassID     *uuid.UUID
	UserID      uuid.UUID // người chịu trách nhiệm (GV chính); uuid.Nil = không ai (đã nhập đủ, chờ Admin duyệt)
	Violation   string
	Date        time.Time // hạn trả kết quả
	Amount      int64
	Notes       string
	EnteredOnly bool // GV đã nhập đủ, chỉ còn chờ duyệt: dừng tăng mức phạt
}

// ApprovalTasks: việc "Duyệt & phân phối đề Big Test" của phân hệ Công việc (work_tasks.big_test_id).
// TODO(gộp): phân hệ Công việc cài interface này; chưa gắn thì không tạo việc (thông báo nhắc duyệt đề vẫn gửi).
type ApprovalTasks interface {
	EnsureBigTestTask(ctx context.Context, tx pgx.Tx, in BigTestTask) (created bool, err error)
	// CloseBigTestTasks đóng việc duyệt đề của các đợt thi đã phân phối (bigTestID nil = mọi đợt thi).
	CloseBigTestTasks(ctx context.Context, tx pgx.Tx, bigTestID *uuid.UUID) (int, error)
}

// BigTestTask: việc duyệt đề tự tạo cho một đợt thi chưa phân phối đề.
type BigTestTask struct {
	BigTestID   uuid.UUID
	ClassID     *uuid.UUID
	BranchID    *uuid.UUID
	Title       string
	Description string
	DueDate     time.Time
	AssigneeID  uuid.UUID
	CreatorID   uuid.UUID
}

// SupportList: danh sách bổ trợ (điểm Big Test dưới 7/10, không tính vắng thi → tự vào danh sách bổ trợ;
// SupportListService::syncBigTest). TODO(gộp): phân hệ Chất lượng lớp cài interface này; chưa gắn thì bỏ qua.
type SupportList interface {
	SyncBigTestResults(ctx context.Context, tx pgx.Tx, bigTestID uuid.UUID) error
}

// Zalo: gửi kết quả Big Test cho phụ huynh qua Zalo ZNS (ZaloZnsService::sendBigTestResult).
type Zalo interface {
	SendBigTestResult(ctx context.Context, m ZaloResult) error
}

// ZaloResult: nội dung mẫu tin ZNS kết quả Big Test.
type ZaloResult struct {
	Phone, StudentName, ClassName, TestTitle       string
	Listening, Reading, Writing, Speaking, Overall string
	ProgressNote                                   string
}

// SandboxZalo: chế độ sandbox của Laravel (chưa cấu hình access token): ghi log, coi như gửi thành công.
type SandboxZalo struct{ Log *slog.Logger }

func (z SandboxZalo) SendBigTestResult(_ context.Context, m ZaloResult) error {
	if z.Log != nil {
		z.Log.Info("[Zalo ZNS Sandbox] gửi kết quả Big Test", "phone", normalizePhone(m.Phone), "student", m.StudentName,
			"class", m.ClassName, "test", m.TestTitle, "overall", m.Overall)
	}
	return nil
}

// normalizePhone: số điện thoại dạng 84xxxxxxxxx.
func normalizePhone(p string) string {
	var b strings.Builder
	for _, r := range p {
		if r >= '0' && r <= '9' {
			b.WriteRune(r)
		}
	}
	s := b.String()
	if strings.HasPrefix(s, "0") {
		s = "84" + s[1:]
	}
	return s
}

type Service struct {
	pool      *pgxpool.Pool
	q         *syllabusdb.Queries
	log       *slog.Logger
	now       func() time.Time
	classes   Classes
	files     *files.Service
	sla       SLA
	penalties Penalties
	tasks     ApprovalTasks
	support   SupportList
	zalo      Zalo
}

// Deps: phần phụ thuộc gắn ở cmd/menglish.
type Deps struct {
	Classes Classes
	Files   *files.Service
	SLA     SLA
	// Penalties / Tasks / Support nil = chưa gắn (xem TODO(gộp) ở interface). Zalo nil = sandbox (ghi log).
	Penalties Penalties
	Tasks     ApprovalTasks
	Support   SupportList
	Zalo      Zalo
}

func NewService(pool *pgxpool.Pool, log *slog.Logger, d Deps) *Service {
	if log == nil {
		log = slog.Default()
	}
	s := &Service{
		pool: pool, log: log, now: time.Now, classes: d.Classes, files: d.Files, sla: d.SLA,
		penalties: d.Penalties, tasks: d.Tasks, support: d.Support, zalo: d.Zalo,
	}
	if pool != nil {
		s.q = syllabusdb.New(pool)
	}
	if s.zalo == nil {
		s.zalo = SandboxZalo{Log: log}
	}
	if d.Files != nil {
		s.registerFileReaders(d.Files)
	}
	return s
}

// SetClock: đổi đồng hồ (test).
func (s *Service) SetClock(now func() time.Time) { s.now = now }

var (
	ErrNotFound       = &iam.Error{Status: 404, Code: "not_found", Message: "Không tìm thấy dữ liệu."}
	ErrClassNotFound  = &iam.Error{Status: 404, Code: "class_not_found", Message: "Không tìm thấy lớp học."}
	ErrTestNotFound   = &iam.Error{Status: 404, Code: "big_test_not_found", Message: "Không tìm thấy đợt Big Test."}
	ErrAdminOnly      = &iam.Error{Status: 403, Code: "admin_only", Message: "Chỉ Admin được duyệt / từ chối."}
	ErrNotDistributed = &iam.Error{Status: 422, Code: "invalid", Message: "Đề thi chưa được duyệt và phân phối."}
	errAlreadyHandled = iam.Invalid("Yêu cầu này đã được xử lý trước đó.")
)

func forbidden(msg string) *iam.Error {
	return &iam.Error{Status: 403, Code: "forbidden", Message: msg}
}

func isNoRows(err error) bool { return errors.Is(err, pgx.ErrNoRows) }

func isUnique(err error) bool {
	var pg *pgconn.PgError
	return errors.As(err, &pg) && pg.Code == "23505"
}

// inTx chạy fn trong một giao dịch.
func (s *Service) inTx(ctx context.Context, fn func(tx pgx.Tx, q *syllabusdb.Queries) error) error {
	return pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error { return fn(tx, s.q.WithTx(tx)) })
}

// dateOf: ngày (giờ Việt Nam) của t, lúc 00:00 UTC — cùng cách pgx đọc cột date.
func dateOf(t time.Time) time.Time {
	y, m, d := t.In(vnZone).Date()
	return time.Date(y, m, d, 0, 0, 0, 0, time.UTC)
}

func (s *Service) today() time.Time { return dateOf(s.now()) }

// startOfDayVN: 00:00 giờ Việt Nam của ngày (dạng dateOf).
func startOfDayVN(day time.Time) time.Time {
	return time.Date(day.Year(), day.Month(), day.Day(), 0, 0, 0, 0, vnZone)
}

// daysBetween: số ngày lịch từ a tới b (cả hai dạng dateOf).
func daysBetween(a, b time.Time) int { return int(b.Sub(a).Hours() / 24) }

func fmtDate(t *time.Time) string {
	if t == nil {
		return ""
	}
	return t.Format("02/01/2006")
}

func fmtDay(t time.Time) string { return t.Format("02/01/2006") }

// fmtDateTime: "15:04 02/01/2006" theo giờ Việt Nam.
func fmtDateTime(t *time.Time) string {
	if t == nil {
		return ""
	}
	return t.In(vnZone).Format("15:04 02/01/2006")
}

// isoDate: "2006-01-02" (ô nhập ngày); nil → "".
func isoDate(t *time.Time) string {
	if t == nil {
		return ""
	}
	return t.Format(time.DateOnly)
}

func ptr[T any](v T) *T { return &v }

func deref[T any](p *T) T {
	var zero T
	if p == nil {
		return zero
	}
	return *p
}

// optText: chuỗi đã cắt khoảng trắng; rỗng → nil.
func optText(s *string) *string {
	if s == nil {
		return nil
	}
	t := strings.TrimSpace(*s)
	if t == "" {
		return nil
	}
	return &t
}

func trimmed(s *string) string { return strings.TrimSpace(deref(s)) }

func tooLong(s *string, n int) bool { return s != nil && utf8.RuneCountInString(*s) > n }

func runeLen(s string) int { return utf8.RuneCountInString(s) }

// cut: cắt chuỗi tối đa n ký tự.
func cut(s string, n int) string {
	if utf8.RuneCountInString(s) <= n {
		return s
	}
	return string([]rune(s)[:n])
}

// likePattern: chuỗi tìm kiếm ILIKE %…% đã thoát ký tự đặc biệt; rỗng → nil.
func likePattern(s string) *string {
	s = strings.TrimSpace(s)
	if s == "" {
		return nil
	}
	s = strings.NewReplacer(`\`, `\\`, `%`, `\%`, `_`, `\_`).Replace(s)
	return ptr("%" + s + "%")
}

// validURL: link http(s) như rule url của Laravel.
func validURL(s string) bool {
	s = strings.TrimSpace(s)
	return (strings.HasPrefix(s, "https://") || strings.HasPrefix(s, "http://")) && len(s) > 8 && !strings.ContainsAny(s, " \t\r\n")
}

// parseDay: "2006-01-02" → ngày dạng dateOf.
func parseDay(s string) (time.Time, bool) {
	t, err := time.Parse(time.DateOnly, strings.TrimSpace(s))
	return t, err == nil
}

// parseLocalDateTime: "2006-01-02T15:04" (ô datetime-local) hoặc RFC3339 → thời điểm giờ Việt Nam.
func parseLocalDateTime(s string) (time.Time, bool) {
	s = strings.TrimSpace(s)
	for _, layout := range []string{"2006-01-02T15:04", "2006-01-02T15:04:05", "2006-01-02 15:04"} {
		if t, err := time.ParseInLocation(layout, s, vnZone); err == nil {
			return t, true
		}
	}
	if t, err := time.Parse(time.RFC3339, s); err == nil {
		return t, true
	}
	return time.Time{}, false
}

// Option: một lựa chọn cho ô chọn ở giao diện.
type Option struct {
	Value string `json:"value"`
	Label string `json:"label"`
}

// Page: thông tin phân trang.
type Page struct {
	Total    int64 `json:"total"`
	Page     int   `json:"page"`
	PageSize int   `json:"page_size"`
}

func pageOf(page, size int) (int32, int32, int) {
	if page < 1 {
		page = 1
	}
	return int32(size), int32((page - 1) * size), page
}

// Message: kết quả thao tác (thông báo cho giao diện).
type Message struct {
	Message string     `json:"message"`
	ID      *uuid.UUID `json:"id,omitempty"`
	// Tone: success (mặc định) | warning (làm được một phần, vd. duyệt xong nhưng chưa gửi được phụ huynh)
	Tone string `json:"tone,omitempty"`
}

func ok(msg string) *Message { return &Message{Message: msg} }

func okID(msg string, id uuid.UUID) *Message { return &Message{Message: msg, ID: &id} }

// visibleClassIDs: lớp người dùng được xem (rỗng khi chưa gắn phân hệ Lớp học).
func (s *Service) visibleClassIDs(ctx context.Context, actor *iam.Access) ([]uuid.UUID, error) {
	if s.classes == nil {
		return []uuid.UUID{}, nil
	}
	ids, err := s.classes.VisibleClassIDs(ctx, actor)
	if ids == nil {
		ids = []uuid.UUID{}
	}
	return ids, err
}

func (s *Service) classVisible(ctx context.Context, actor *iam.Access, classID uuid.UUID) (bool, error) {
	ids, err := s.visibleClassIDs(ctx, actor)
	if err != nil {
		return false, err
	}
	for _, id := range ids {
		if id == classID {
			return true, nil
		}
	}
	return false, nil
}

// bigTestScope: phạm vi "big_test.scope_*" (DataScope::apply, branchIncludesOwn): all → mọi đợt thi; branch → lớp mình
// phụ trách + lớp thuộc chi nhánh mình; own → lớp mình phụ trách.
type bigTestScope struct {
	all      bool
	userID   uuid.UUID
	branches []uuid.UUID
}

func scopeOf(actor *iam.Access) bigTestScope {
	sc := bigTestScope{userID: actor.UserID, branches: []uuid.UUID{}}
	switch actor.Level("big_test") {
	case iam.ScopeAll:
		sc.all = true
	case iam.ScopeBranch:
		sc.branches = actor.BranchIDs()
	}
	return sc
}

// covers: đợt thi / order của lớp (chi nhánh, nhân sự) nằm trong phạm vi (BigTest::isAccessibleBy).
func (sc bigTestScope) covers(branch *uuid.UUID, staff ...*uuid.UUID) bool {
	if sc.all {
		return true
	}
	if branch != nil {
		for _, b := range sc.branches {
			if b == *branch {
				return true
			}
		}
	}
	for _, id := range staff {
		if id != nil && *id == sc.userID {
			return true
		}
	}
	return false
}

func sameUser(id *uuid.UUID, user uuid.UUID) bool { return id != nil && *id == user }

// requireAdmin: nút duyệt / từ chối chỉ Admin (AdminOnlyApprovals::ABILITY, 06/10/2026).
func requireAdmin(actor *iam.Access) error {
	if !actor.SuperAdmin {
		return ErrAdminOnly
	}
	return nil
}
