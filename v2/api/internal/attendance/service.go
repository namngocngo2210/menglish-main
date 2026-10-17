// Package attendance: Chấm công (workspace "Chấm công" + "Xin duyệt → Đơn chấm công & nghỉ" của Laravel).
//
//   - Chấm công của tôi: ảnh khuôn mặt (kho file) + GPS trong bán kính chi nhánh chính, giờ vào / ra theo máy chủ
//     (MyAttendanceController, StaffAttendanceService). Đi muộn quá số phút cho phép → biên bản chờ giải trình (phân hệ
//     Lương & Phạt, qua Penalties).
//   - Đơn chấm công & nghỉ: bổ sung công, xin đi muộn / về sớm, xin nghỉ; Admin duyệt (staff_checkin.approve).
//   - Chấm công hằng ngày: quản lý xem theo ngày / tổng hợp tháng (StaffAttendanceController).
//   - Giờ dạy giáo viên: ca dạy (check-in cổng giáo viên, chấm tay, xác nhận theo lịch), đối soát, chốt bảng công, chỉnh
//     tay; lịch sử đồng bộ máy chấm công (phần timesheets của PayrollController).
//
// Phân hệ khác đọc dữ liệu chấm công qua API ở reads.go (Lương: ngày công / đi muộn / giờ dạy; cổng giáo viên: ghi
// check-in), kpi.go (nguồn KPI tự động) và dashboard.go (Tổng quan).
package attendance

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"strconv"
	"strings"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgtype"
	"github.com/jackc/pgx/v5/pgxpool"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/attendance/attendancedb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/files"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify"
)

// Giờ Việt Nam (không đổi giờ theo mùa): ngày công, giờ vào / ra tính theo giờ này.
var vnZone = time.FixedZone("ICT", 7*3600)

const (
	// Module phạm vi dữ liệu: chấm công hằng ngày (branch / all) và giờ dạy (own / branch / all).
	scopeCheckin   = "staff_checkin"
	scopeTimesheet = "attendance_staff"

	PermPortalStaff    = "portal.staff"
	PermCheckinView    = "staff_checkin.view"
	PermCheckinApprove = "staff_checkin.approve"
	PermTimesheetView  = "attendance_staff.view"
	PermManualRecord   = "attendance_staff.manual_record"
	PermSync           = "attendance_staff.sync"
	PermPayrollOwn     = "payroll.view_own"

	// Bán kính mặc định khi chi nhánh chưa đặt (Branch::DEFAULT_CHECKIN_RADIUS).
	DefaultRadius = 100
	// Đơn xin nghỉ tối đa bao nhiêu ngày một lần.
	MaxLeaveDays = 31

	// subject_type của ảnh khuôn mặt trong kho file.
	PhotoSubject = "staff_attendance_photo"
	// Ảnh tối đa 6 MB (validation photo max:6144 của Laravel).
	photoMaxBytes = 6 << 20

	notifyRequest = "staff_attendance_request"
	notifyPenalty = "penalty_created"
)

func init() {
	notify.RegisterType(notifyRequest, notify.TypeInfo{Label: "Đơn chấm công & nghỉ", Icon: "event_available", Tone: notify.ToneInfo})
	audit.RegisterSubject("staff_attendance", "Chấm công")
	audit.RegisterSubject("staff_attendance_request", "Đơn chấm công & nghỉ")
	audit.RegisterSubject("teacher_timesheet", "Ca dạy giáo viên")
}

// ───────────── Phân hệ khác ─────────────

// LockedRange: khoảng ngày của một kỳ lương đã duyệt / đã chi trả.
type LockedRange struct {
	From  time.Time
	To    time.Time
	Title string
}

// PayrollPeriod: kỳ lương của tháng (thẻ "Kỳ lương" ở màn Giờ dạy giáo viên).
type PayrollPeriod struct {
	StatusLabel string `json:"status_label"`
	Locked      bool   `json:"locked"`
}

// PayrollLock: kỳ lương đã duyệt / đã chi trả khóa dữ liệu chấm công của các ngày trong kỳ (PayrollPeriod::isLockedFor).
// TODO(gộp): adapter của phân hệ Lương & Phạt (kỳ lương trạng thái approved / paid); hiện không kỳ nào bị khóa.
type PayrollLock interface {
	LockedRanges(ctx context.Context) ([]LockedRange, error)
	// Period: kỳ lương của tháng chứa month; nil = chưa khởi tạo bảng lương.
	Period(ctx context.Context, month time.Time) (*PayrollPeriod, error)
}

type noLock struct{}

func (noLock) LockedRanges(context.Context) ([]LockedRange, error)       { return nil, nil }
func (noLock) Period(context.Context, time.Time) (*PayrollPeriod, error) { return nil, nil }

// LatePenalty: thông tin dòng chấm công đi muộn để lập / cập nhật biên bản tự động.
type LatePenalty struct {
	AttendanceID uuid.UUID
	UserID       uuid.UUID
	BranchID     *uuid.UUID
	WorkDate     time.Time
	LateMinutes  int
	// "Đi muộn 12 phút (chấm công ngày 17/10/2026)"
	Violation string
	// "Tự động từ chấm công: giờ vào 08:12, giờ phải có mặt 08:00."
	Notes string
}

// PenaltyRef: biên bản gắn với dòng chấm công.
type PenaltyRef struct {
	ID      uuid.UUID `json:"-"`
	Code    string    `json:"code"`
	Status  string    `json:"status"`
	Created bool      `json:"-"` // vừa lập mới (gửi thông báo cho nhân sự)
}

// Penalties: biên bản đi muộn tự động (StaffAttendanceService::syncLatePenalty, bảng penalties của Lương & Phạt).
// TODO(gộp): adapter của phân hệ Lương & Phạt; hiện chưa lập biên bản (dòng chấm công vẫn ghi phút muộn).
type Penalties interface {
	// Late: dòng đi muộn tính lỗi. current = biên bản đang gắn (nil = chưa có). Chưa có hoặc đã hủy → lập mới (chờ giải
	// trình, 0đ, Created = true); còn chờ giải trình → cập nhật nội dung vi phạm. Trả biên bản đang gắn.
	Late(ctx context.Context, tx pgx.Tx, current *uuid.UUID, in LatePenalty) (*PenaltyRef, error)
	// Excused: không còn đi muộn tính lỗi → biên bản đang chờ / đã giải trình tự hủy với ghi chú note.
	Excused(ctx context.Context, tx pgx.Tx, current uuid.UUID, note string) error
	// Refs: mã + trạng thái các biên bản (hiển thị trên dòng chấm công).
	Refs(ctx context.Context, ids []uuid.UUID) (map[uuid.UUID]PenaltyRef, error)
	// Violations: số biên bản (chưa hủy) có ngày vi phạm trong khoảng của từng người (Tổng quan).
	Violations(ctx context.Context, userIDs []uuid.UUID, from, to time.Time) (map[uuid.UUID]int, error)
}

type noPenalties struct{}

func (noPenalties) Late(_ context.Context, _ pgx.Tx, current *uuid.UUID, _ LatePenalty) (*PenaltyRef, error) {
	if current == nil {
		return nil, nil
	}
	return &PenaltyRef{ID: *current}, nil
}
func (noPenalties) Excused(context.Context, pgx.Tx, uuid.UUID, string) error { return nil }
func (noPenalties) Refs(context.Context, []uuid.UUID) (map[uuid.UUID]PenaltyRef, error) {
	return map[uuid.UUID]PenaltyRef{}, nil
}
func (noPenalties) Violations(context.Context, []uuid.UUID, time.Time, time.Time) (map[uuid.UUID]int, error) {
	return map[uuid.UUID]int{}, nil
}

// ClassScope: lớp người dùng được xem (ClassModel::visibleTo) — classes.Service.VisibleClassIDs.
type ClassScope interface {
	VisibleClassIDs(ctx context.Context, actor *iam.Access) ([]uuid.UUID, error)
}

// ───────────── Service ─────────────

type Service struct {
	pool      *pgxpool.Pool
	q         *attendancedb.Queries
	iam       *iam.Service
	log       *slog.Logger
	now       func() time.Time
	files     *files.Service
	lock      PayrollLock
	penalties Penalties
	classes   ClassScope
}

func NewService(pool *pgxpool.Pool, iamSvc *iam.Service, log *slog.Logger) *Service {
	return &Service{pool: pool, q: attendancedb.New(pool), iam: iamSvc, log: log, now: time.Now,
		lock: noLock{}, penalties: noPenalties{}}
}

// WithFiles: kho file lưu ảnh khuôn mặt; đăng ký luôn quyền mở ảnh (chính người chấm, hoặc người xem chấm công trong
// phạm vi chi nhánh — StaffAttendanceController::canSee).
func (s *Service) WithFiles(f *files.Service) *Service {
	s.files = f
	if f != nil {
		f.RegisterReader(PhotoSubject, s.photoReader)
	}
	return s
}

// WithClasses: phạm vi lớp của giờ dạy (lớp người dùng được xem).
func (s *Service) WithClasses(c ClassScope) *Service {
	s.classes = c
	return s
}

// WithPayrollLock: kỳ lương khóa dữ liệu (phân hệ Lương).
func (s *Service) WithPayrollLock(l PayrollLock) *Service {
	if l != nil {
		s.lock = l
	}
	return s
}

// WithPenalties: biên bản đi muộn tự động (phân hệ Lương & Phạt).
func (s *Service) WithPenalties(p Penalties) *Service {
	if p != nil {
		s.penalties = p
	}
	return s
}

// SetClock: đồng hồ giả cho test.
func (s *Service) SetClock(now func() time.Time) { s.now = now }

var (
	ErrNotFound         = &iam.Error{Status: 404, Code: "attendance_not_found", Message: "Không tìm thấy dữ liệu chấm công."}
	ErrRequestNotFound  = &iam.Error{Status: 404, Code: "request_not_found", Message: "Không tìm thấy đơn."}
	ErrTimesheetMissing = &iam.Error{Status: 404, Code: "timesheet_not_found", Message: "Không tìm thấy ca dạy."}
	ErrSessionMissing   = &iam.Error{Status: 404, Code: "session_not_found", Message: "Không tìm thấy buổi học."}
	ErrSyncLogMissing   = &iam.Error{Status: 404, Code: "sync_log_not_found", Message: "Không tìm thấy đợt đồng bộ."}
	ErrOutOfScope       = &iam.Error{Status: 403, Code: "outside_scope", Message: "Lớp này nằm ngoài phạm vi bạn được chấm công."}
	errNoFiles          = errors.New("attendance: chưa gắn kho file")
)

func isNoRows(err error) bool { return errors.Is(err, pgx.ErrNoRows) }

func (s *Service) inTx(ctx context.Context, fn func(tx pgx.Tx, q *attendancedb.Queries) error) error {
	return pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error { return fn(tx, s.q.WithTx(tx)) })
}

// ───────────── Ngày giờ ─────────────

// dayOf: 0 giờ (giờ VN) của ngày chứa t, dạng UTC-độc lập (so sánh bằng Equal / Before).
func dayOf(t time.Time) time.Time {
	t = t.In(vnZone)
	return time.Date(t.Year(), t.Month(), t.Day(), 0, 0, 0, 0, vnZone)
}

// dateOnly: ngày của cột date (pgx trả 00:00 UTC) → 0 giờ VN của cùng ngày.
func dateOnly(t time.Time) time.Time {
	return time.Date(t.Year(), t.Month(), t.Day(), 0, 0, 0, 0, vnZone)
}

// sqlDate: tham số cột date (ngày theo lịch, không theo múi giờ).
func sqlDate(t time.Time) time.Time {
	t = t.In(vnZone)
	return time.Date(t.Year(), t.Month(), t.Day(), 0, 0, 0, 0, time.UTC)
}

func fmtDate(t time.Time) string    { return t.Format("02/01/2006") }
func isoDate(t time.Time) string    { return t.Format("2006-01-02") }
func clock(t time.Time) string      { return t.In(vnZone).Format("15:04") }
func monthKey(t time.Time) string   { return t.Format("2006-01") }
func monthLabel(t time.Time) string { return "Tháng " + t.Format("01/2006") }

// parseDate: "YYYY-MM-DD" → ngày (giờ VN).
func parseDate(s string) (time.Time, bool) {
	t, err := time.ParseInLocation("2006-01-02", strings.TrimSpace(s), vnZone)
	return t, err == nil
}

// parseMonth: "YYYY-MM" → ngày đầu tháng; sai định dạng → tháng của def.
func parseMonth(s string, def time.Time) time.Time {
	if t, err := time.ParseInLocation("2006-01", strings.TrimSpace(s), vnZone); err == nil {
		return t
	}
	d := dayOf(def)
	return time.Date(d.Year(), d.Month(), 1, 0, 0, 0, 0, vnZone)
}

func monthEnd(start time.Time) time.Time { return start.AddDate(0, 1, -1) }

// hm: cột time → "HH:MM" (nil nếu trống).
func hm(t pgtype.Time) *string {
	if !t.Valid {
		return nil
	}
	m := t.Microseconds / 60_000_000
	v := fmt.Sprintf("%02d:%02d", m/60, m%60)
	return &v
}

func hmText(t pgtype.Time) string {
	if v := hm(t); v != nil {
		return *v
	}
	return ""
}

// parseHM: "HH:MM" → số phút trong ngày.
func parseHM(s string) (int, bool) {
	s = strings.TrimSpace(s)
	if len(s) != 5 || s[2] != ':' {
		return 0, false
	}
	h, err1 := strconv.Atoi(s[:2])
	m, err2 := strconv.Atoi(s[3:])
	if err1 != nil || err2 != nil || h < 0 || h > 23 || m < 0 || m > 59 {
		return 0, false
	}
	return h*60 + m, true
}

// at: thời điểm "HH:MM" của ngày day (giờ VN).
func at(day time.Time, hhmm string) time.Time {
	m, _ := parseHM(hhmm)
	d := dayOf(day)
	return d.Add(time.Duration(m) * time.Minute)
}

func ptr[T any](v T) *T { return &v }

func strPtr(s string) *string {
	s = strings.TrimSpace(s)
	if s == "" {
		return nil
	}
	return &s
}

func deref(s *string) string {
	if s == nil {
		return ""
	}
	return *s
}

func escapeLike(s string) string {
	return strings.NewReplacer(`\`, `\\`, `%`, `\%`, `_`, `\_`).Replace(s)
}

func likePattern(s string) *string {
	s = strings.TrimSpace(s)
	if s == "" {
		return nil
	}
	if len([]rune(s)) > 100 {
		s = string([]rune(s)[:100])
	}
	return ptr("%" + escapeLike(s) + "%")
}

// ───────────── Kỳ lương đã khóa ─────────────

// lockedMessage: PayrollPeriod::lockedMessage.
func lockedMessage(day time.Time) string {
	return "Ngày " + fmtDate(day) + " thuộc kỳ lương đã duyệt/đã chi trả — không thể ghi nhận hoặc thay đổi dữ liệu lương cho ngày này."
}

// locks: khoảng ngày bị khóa, nạp một lần cho cả thao tác (màn danh sách kiểm từng dòng).
type locks []LockedRange

func (s *Service) locks(ctx context.Context) (locks, error) {
	r, err := s.lock.LockedRanges(ctx)
	return locks(r), err
}

func (l locks) has(day time.Time) bool {
	d := isoDate(day)
	for _, r := range l {
		if isoDate(r.From) <= d && isoDate(r.To) >= d {
			return true
		}
	}
	return false
}

// ensureOpen: lỗi 422 nếu một trong các ngày thuộc kỳ lương đã khóa.
func (s *Service) ensureOpen(ctx context.Context, days ...time.Time) error {
	l, err := s.locks(ctx)
	if err != nil {
		return err
	}
	for _, d := range days {
		if l.has(d) {
			return iam.Invalid(lockedMessage(d))
		}
	}
	return nil
}

// ───────────── Dùng chung ─────────────

// Option: lựa chọn của ô chọn.
type Option struct {
	Value string `json:"value"`
	Label string `json:"label"`
}

func (s *Service) access(ctx context.Context, id uuid.UUID) (*iam.Access, error) {
	return s.iam.LoadAccess(ctx, id)
}

// staffIDs: tài khoản có cổng nhân sự (Rbac::scopeUsersWithPermission 'portal.staff').
func (s *Service) staffIDs(ctx context.Context) ([]uuid.UUID, error) {
	ids, err := iam.UserIDsWithPermission(ctx, s.pool, PermPortalStaff, nil)
	if ids == nil {
		ids = []uuid.UUID{}
	}
	return ids, err
}

// branchScope: (mọi chi nhánh?, chi nhánh của người xem) của module.
func branchScope(a *iam.Access, module string) (bool, []uuid.UUID) {
	limit := a.BranchLimit(module)
	if limit == nil {
		return true, []uuid.UUID{}
	}
	return false, limit
}

func (s *Service) record(ctx context.Context, tx pgx.Tx, e audit.Entry) error {
	if e.Module == "" {
		e.Module = audit.ModulePayroll
	}
	return audit.Record(ctx, tx, e)
}

// pgTime: "HH:MM" → cột time (nil = trống).
func pgTime(s *string) pgtype.Time {
	if s == nil {
		return pgtype.Time{}
	}
	m, ok := parseHM(*s)
	if !ok {
		return pgtype.Time{}
	}
	return pgtype.Time{Microseconds: int64(m) * 60_000_000, Valid: true}
}
