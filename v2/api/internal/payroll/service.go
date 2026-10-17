// Package payroll: phân hệ Lương & Phạt (Laravel: PayrollController trừ màn chấm công, PenaltyController, PayrollPeriod,
// PayrollRecord, Penalty, SalesCommissionService, PayrollFormulaService, TeachingShareService, lệnh payroll:remind-calendar /
// penalties:remind-due).
//
// Chấm công (ca dạy hợp lệ, chấm công hằng ngày) thuộc phân hệ Chấm công: đọc qua interface Attendance.
package payroll

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"math"
	"net/http"
	"strings"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgxpool"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/files"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/payroll/payrolldb"
)

// Quyền dùng trong phân hệ (catalog: payroll, violation, teacher_rate, commission_config, kpi).
const (
	PermView      = "payroll.view"
	PermViewOwn   = "payroll.view_own"
	PermCreate    = "payroll.create"
	PermEdit      = "payroll.edit"
	PermCalculate = "payroll.calculate"
	PermApprove   = "payroll.approve"
	PermMarkPaid  = "payroll.mark_paid"
	PermRates     = "teacher_rate.manage"
	PermTiers     = "commission_config.manage"
	PermKpiView   = "kpi.view"

	PermViolationView     = "violation.view"
	PermViolationCreate   = "violation.create"
	PermConfirmError      = "violation.confirm_error"
	PermConfirmFine       = "violation.confirm_fine"
	PermDecideAcademic    = "violation.decide_academic"
	PermDecideOperations  = "violation.decide_operations"
	PermViolationCancel   = "violation.cancel"
	PermViolationMarkPaid = "violation.mark_paid"
	PermViolationResolve  = "violation.mark_resolved"
	PermPortalStaff       = "portal.staff"
	PermPortalStudent     = "portal.student"
)

const (
	moduleKey         = "payroll"
	evidenceSubject   = "penalty_evidence"
	notifyApproved    = "payroll_approved"
	defaultHourlyRate = 250000
)

var vnZone = time.FixedZone("ICT", 7*3600)

func init() {
	audit.RegisterPath("/penalties", audit.ModulePayroll)
	audit.RegisterPath("/portal/my-salary", audit.ModulePayroll)
	audit.RegisterSubject("payroll_period", "Kỳ lương")
	audit.RegisterSubject("payroll_record", "Phiếu lương")
	audit.RegisterSubject("penalty", "Biên bản vi phạm")
	audit.RegisterSubject("commission_tier", "Mốc hoa hồng")
	audit.RegisterSubject("teacher_rate", "Đơn giá giáo viên")
	audit.RegisterSubject("payroll_setting", "Tham số tính lương")
	notify.RegisterType(notifyApproved, notify.TypeInfo{Label: "Phiếu lương đã duyệt", Icon: "payments", Tone: notify.ToneSuccess})
}

// Timesheet: một ca dạy HỢP LỆ của phân hệ Chấm công (hr.teacher_timesheets status = valid).
type Timesheet struct {
	ID                uuid.UUID
	UserID            uuid.UUID
	ClassID           *uuid.UUID
	Date              time.Time
	ScheduledTime     string // "HH:MM-HH:MM"
	Hours             float64
	LateMinutes       int
	EarlyLeaveMinutes int
	LateNotified      bool
	HourlyRate        *int64 // đơn giá giờ riêng của ca; nil = đơn giá của giáo viên
	Type              string // regular | sub | 1on1 | grading | workshop
	Source            string
}

// Attendance: dữ liệu của phân hệ Chấm công cho bảng lương.
// TODO(gộp): adapter của phân hệ Chấm công (hr.teacher_timesheets, hr.staff_attendances, đơn nghỉ) khi gộp; hiện no-op.
type Attendance interface {
	// ValidTimesheets: ca dạy hợp lệ trong [from, to] (ngày), mọi nhân sự.
	ValidTimesheets(ctx context.Context, from, to time.Time) ([]Timesheet, error)
	// Summary: chấm công hằng ngày của một người trong kỳ (ngày công, đi muộn, nghỉ phép…); nil = không có dữ liệu.
	Summary(ctx context.Context, userID uuid.UUID, from, to time.Time) (map[string]any, error)
	// ChangedSince: chấm công / đơn nghỉ trong kỳ đổi sau mốc (phải tính lại bảng lương).
	ChangedSince(ctx context.Context, from, to, since time.Time) (bool, error)
	// Pending: true khi phân hệ Chấm công chưa có ở v2.
	Pending() bool
}

type noAttendance struct{}

func (noAttendance) ValidTimesheets(context.Context, time.Time, time.Time) ([]Timesheet, error) {
	return nil, nil
}
func (noAttendance) Summary(context.Context, uuid.UUID, time.Time, time.Time) (map[string]any, error) {
	return nil, nil
}
func (noAttendance) ChangedSince(context.Context, time.Time, time.Time, time.Time) (bool, error) {
	return false, nil
}
func (noAttendance) Pending() bool { return true }

// AttendancePending: thông báo trên màn bảng lương khi chưa có phân hệ Chấm công.
const AttendancePending = "Chờ phân hệ Chấm công chuyển sang hệ thống mới: chưa có ca dạy hợp lệ để tính lương buổi dạy."

type Service struct {
	pool       *pgxpool.Pool
	q          *payrolldb.Queries
	log        *slog.Logger
	now        func() time.Time
	attendance Attendance
	files      *files.Service
}

func NewService(pool *pgxpool.Pool, log *slog.Logger) *Service {
	if log == nil {
		log = slog.Default()
	}
	s := &Service{pool: pool, log: log, now: time.Now, attendance: noAttendance{}}
	if pool != nil {
		s.q = payrolldb.New(pool)
	}
	return s
}

// SetClock: đặt giờ hiện tại (test).
func (s *Service) SetClock(now func() time.Time) { s.now = now }

// WithAttendance gắn nguồn chấm công (phân hệ Chấm công).
func (s *Service) WithAttendance(a Attendance) *Service {
	if a != nil {
		s.attendance = a
	}
	return s
}

// WithFiles gắn kho file (bằng chứng vi phạm).
func (s *Service) WithFiles(f *files.Service) *Service {
	s.files = f
	if f != nil {
		f.RegisterReader(evidenceSubject, s.readEvidence)
	}
	return s
}

func (s *Service) today() time.Time { return dayOf(s.now()) }

func dayOf(t time.Time) time.Time {
	t = t.In(vnZone)
	return time.Date(t.Year(), t.Month(), t.Day(), 0, 0, 0, 0, vnZone)
}

func endOfDay(t time.Time) time.Time { return dayOf(t).AddDate(0, 0, 1).Add(-time.Microsecond) }

// asDay: ngày lịch (cột date đọc từ DB là UTC 00:00) → 00:00 giờ Việt Nam cùng ngày.
func asDay(t time.Time) time.Time {
	return time.Date(t.Year(), t.Month(), t.Day(), 0, 0, 0, 0, vnZone)
}

// dbDay: ngày lịch để ghi vào cột date.
func dbDay(t time.Time) time.Time {
	t = t.In(vnZone)
	return time.Date(t.Year(), t.Month(), t.Day(), 0, 0, 0, 0, time.UTC)
}

func dmy(t time.Time) string { return asDay(t).Format("02/01/2006") }

func dmyp(t *time.Time) *string {
	if t == nil {
		return nil
	}
	v := dmy(*t)
	return &v
}

func (s *Service) tx(ctx context.Context, fn func(pgx.Tx, *payrolldb.Queries) error) error {
	return pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error { return fn(tx, s.q.WithTx(tx)) })
}

// ───────────── Lỗi ─────────────

func forbidden(msg string) error {
	return &iam.Error{Status: http.StatusForbidden, Code: "forbidden", Message: msg}
}

func notFound(msg string) error {
	return &iam.Error{Status: http.StatusNotFound, Code: "not_found", Message: msg}
}

func conflict(msg string) error {
	return &iam.Error{Status: http.StatusConflict, Code: "conflict", Message: msg}
}

var (
	errPeriodNotFound  = notFound("Không tìm thấy kỳ lương.")
	errRecordNotFound  = notFound("Không tìm thấy phiếu lương.")
	errPenaltyNotFound = notFound("Không tìm thấy biên bản vi phạm.")
	errTierNotFound    = notFound("Không tìm thấy mốc hoa hồng.")
)

func isNoRows(err error) bool { return errors.Is(err, pgx.ErrNoRows) }

// ───────────── Phạm vi xem phiếu lương (DataScope 'payroll') ─────────────

type scope struct {
	all      bool
	me       uuid.UUID
	branches []uuid.UUID
}

func scopeOf(a *iam.Access) scope {
	switch a.Level(moduleKey) {
	case iam.ScopeAll:
		return scope{all: true, me: a.UserID, branches: []uuid.UUID{}}
	case iam.ScopeBranch:
		b := a.BranchIDs()
		if b == nil {
			b = []uuid.UUID{}
		}
		return scope{me: a.UserID, branches: b}
	default:
		return scope{me: a.UserID, branches: []uuid.UUID{}}
	}
}

// ───────────── Định dạng ─────────────

// money: 1500000 → "1.500.000 đ" (Money::format).
func money(v int64) string { return groupThousands(v) + " đ" }

func groupThousands(v int64) string {
	neg := v < 0
	if neg {
		v = -v
	}
	s := fmt.Sprintf("%d", v)
	var b strings.Builder
	for i, c := range s {
		if i > 0 && (len(s)-i)%3 == 0 {
			b.WriteByte('.')
		}
		b.WriteRune(c)
	}
	if neg {
		return "-" + b.String()
	}
	return b.String()
}

// percentLabel: 85.50 → "85,5" (PayrollRecord::percent).
func percentLabel(v float64) string {
	s := fmt.Sprintf("%.2f", v)
	s = strings.TrimRight(strings.TrimRight(s, "0"), ".")
	return strings.ReplaceAll(s, ".", ",")
}

// roundVND: làm tròn tới đồng (Laravel round(x, 0)).
func roundVND(v float64) int64 { return int64(math.Round(v)) }

func round2(v float64) float64 { return math.Round(v*100) / 100 }

func ptr[T any](v T) *T { return &v }

func deref[T any](p *T) T {
	var zero T
	if p == nil {
		return zero
	}
	return *p
}

func trimPtr(p *string) *string {
	if p == nil {
		return nil
	}
	v := strings.TrimSpace(*p)
	if v == "" {
		return nil
	}
	return &v
}

func runeLen(s string) int { return len([]rune(s)) }

// Option: lựa chọn cho ô chọn.
type Option struct {
	Value string `json:"value"`
	Label string `json:"label"`
}

// Paging: phân trang kiểu Laravel paginate.
type Paging struct {
	Page     int   `json:"current_page"`
	PerPage  int   `json:"per_page"`
	Total    int64 `json:"total"`
	LastPage int   `json:"last_page"`
}

func paging(page, per int, total int64) Paging {
	last := int((total + int64(per) - 1) / int64(per))
	if last < 1 {
		last = 1
	}
	return Paging{Page: page, PerPage: per, Total: total, LastPage: last}
}

func pageOf(p int) int {
	if p < 1 {
		return 1
	}
	return p
}

func actorPtr(a *iam.Access) *uuid.UUID { id := a.UserID; return &id }

type pgxTx = pgx.Tx
