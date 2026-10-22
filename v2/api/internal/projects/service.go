// Package projects là phân hệ Dự án học thuật — soạn sách, xây chương trình (AcademicProjectController,
// AcademicProjectSlaService của Laravel): họp thống nhất → chốt tiến độ (mốc, deadline, khối lượng, người nhận) →
// thành viên cập nhật tiến độ (khối lượng xong, link sản phẩm, khó khăn) → Học thuật phản hồi; mốc trễ deadline lập
// biên bản chờ giải trình (SLA academic.milestone_late, qua phân hệ Lương & biên bản).
//
// Quyền: academic_project.view — xem & cập nhật dự án mình tham gia; view_all — xem mọi dự án + báo cáo;
// manage — tạo / sửa / xóa dự án, chốt tiến độ, quản lý mốc, phản hồi. Người phụ trách dự án cũng phản hồi được.
package projects

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"math"
	"net/url"
	"slices"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgxpool"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/catalogs"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/projects/projectsdb"
)

const (
	PermView    = "academic_project.view"
	PermViewAll = "academic_project.view_all"
	PermManage  = "academic_project.manage"

	// SLA trễ mốc (giờ ân hạn sau 23:59 ngày hạn) và nhắc trước hạn.
	SlaLate   = "academic.milestone_late"
	SlaRemind = "academic.milestone_remind"
)

// SlaRules đọc cấu hình SLA hiệu lực (catalogs.Service.SlaRule).
type SlaRules interface {
	SlaRule(ctx context.Context, key string) (catalogs.SlaRule, error)
}

type Service struct {
	pool      *pgxpool.Pool
	q         *projectsdb.Queries
	sla       SlaRules
	penalties Penalties
	log       *slog.Logger
	now       func() time.Time
}

func NewService(pool *pgxpool.Pool, log *slog.Logger) *Service {
	return &Service{pool: pool, q: projectsdb.New(pool), penalties: noPenalties{}, log: log, now: time.Now}
}

// WithSla: cấu hình SLA trễ mốc / nhắc hạn (thiếu = mặc định 1 giờ ân hạn, nhắc trước 24 giờ, đều bật).
func (s *Service) WithSla(r SlaRules) *Service { s.sla = r; return s }

// WithPenalties: phân hệ Lương & biên bản lập / liệt kê biên bản trễ deadline mốc.
func (s *Service) WithPenalties(p Penalties) *Service {
	if p != nil {
		s.penalties = p
	}
	return s
}

func (s *Service) SetClock(now func() time.Time) { s.now = now }

var vnZone = time.FixedZone("ICT", 7*3600)

// dateOf: ngày (00:00 UTC, kiểu date của pgx) theo giờ Việt Nam của t.
func dateOf(t time.Time) time.Time {
	y, m, d := t.In(vnZone).Date()
	return time.Date(y, m, d, 0, 0, 0, 0, time.UTC)
}

func (s *Service) tx(ctx context.Context, fn func(tx pgx.Tx, q *projectsdb.Queries) error) error {
	return pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error { return fn(tx, s.q.WithTx(tx)) })
}

func isNoRows(err error) bool { return errors.Is(err, pgx.ErrNoRows) }

func notFound(msg string) *iam.Error { return &iam.Error{Status: 404, Code: "not_found", Message: msg} }

func conflict(msg string) *iam.Error { return &iam.Error{Status: 409, Code: "conflict", Message: msg} }

func forbidden(msg string) *iam.Error {
	return &iam.Error{Status: 403, Code: "forbidden", Message: msg}
}

var (
	errProjectNotFound   = notFound("Không tìm thấy dự án.")
	errMilestoneNotFound = notFound("Không tìm thấy mốc.")
	errUpdateNotFound    = notFound("Không tìm thấy cập nhật tiến độ.")
)

// seeAll: người quản lý (view_all / manage) thấy mọi dự án (AcademicProject::visibleTo).
func seeAll(a *iam.Access) bool { return a.Can(PermViewAll) || a.Can(PermManage) }

// ───────────────────────── Danh mục ─────────────────────────

type Option struct {
	Value string `json:"value"`
	Label string `json:"label"`
}

type field struct{ key, label string }

func labelOf(fields []field, key string) string {
	for _, f := range fields {
		if f.key == key {
			return f.label
		}
	}
	return key
}

func hasKey(fields []field, key string) bool {
	return slices.ContainsFunc(fields, func(f field) bool { return f.key == key })
}

func optionsOf(fields []field) []Option {
	out := make([]Option, 0, len(fields))
	for _, f := range fields {
		out = append(out, Option{Value: f.key, Label: f.label})
	}
	return out
}

var Types = []field{
	{"book", "Soạn sách / giáo trình"},
	{"curriculum", "Xây dựng chương trình"},
	{"other", "Dự án khác"},
}

var Statuses = []field{
	{"planning", "Lên kế hoạch"},
	{"active", "Đang thực hiện"},
	{"paused", "Tạm dừng"},
	{"completed", "Hoàn thành"},
	{"cancelled", "Đã hủy"},
}

var StatusColors = map[string]string{
	"planning": "secondary", "active": "primary", "paused": "warning", "completed": "success", "cancelled": "neutral",
}

var MilestoneStatuses = []field{
	{"todo", "Chưa bắt đầu"},
	{"in_progress", "Đang làm"},
	{"done", "Hoàn thành"},
}

// openStatuses: dự án còn mở — thành viên vẫn cập nhật tiến độ được.
var openStatuses = []string{"planning", "active", "paused"}

func isOpen(status string) bool { return slices.Contains(openStatuses, status) }

func colorOf(status string) string {
	if c, ok := StatusColors[status]; ok {
		return c
	}
	return "neutral"
}

// ───────────────────────── Mốc: hạn, tiến độ ─────────────────────────

// mile là phần tính toán của một mốc (AcademicProjectMilestone).
type mile struct {
	DueDate     time.Time
	Status      string
	CompletedAt *time.Time
	Target      float64
	Done        float64
}

// dueAt: hạn chót — hết ngày due_date (giờ Việt Nam).
func dueAt(due time.Time) time.Time {
	return time.Date(due.Year(), due.Month(), due.Day(), 23, 59, 59, 999999000, vnZone)
}

func (m mile) done() bool { return m.Status == "done" }

func (m mile) overdue(now time.Time) bool {
	if m.done() {
		return m.CompletedAt != nil && m.CompletedAt.After(dueAt(m.DueDate))
	}
	return dueAt(m.DueDate).Before(now)
}

func (m mile) progress() int {
	if m.done() {
		return 100
	}
	if m.Target <= 0 {
		return 0
	}
	return int(math.Min(100, math.Floor(m.Done*100/m.Target)))
}

// deadlineState: "overdue" (trễ hạn) | "soon" (≤ 3 ngày) | "".
func (m mile) deadlineState(now time.Time) string {
	if m.overdue(now) {
		return "overdue"
	}
	if !m.done() && !dueAt(m.DueDate).After(now.AddDate(0, 0, 3)) {
		return "soon"
	}
	return ""
}

// FormatQuantity: 12 / 12,5 / 1.234,5 — số gọn kiểu Việt Nam (number_format 2 số lẻ, bỏ 0 thừa).
func FormatQuantity(v float64) string {
	neg := v < 0
	cents := int64(math.Round(math.Abs(v) * 100))
	whole, frac := cents/100, cents%100
	digits := fmt.Sprint(whole)
	var b strings.Builder
	for i, r := range digits {
		if i > 0 && (len(digits)-i)%3 == 0 {
			b.WriteByte('.')
		}
		b.WriteRune(r)
	}
	out := b.String()
	if frac != 0 {
		out += "," + strings.TrimRight(fmt.Sprintf("%02d", frac), "0")
	}
	if neg && cents != 0 {
		out = "-" + out
	}
	return out
}

// ───────────────────────── Nhân sự ─────────────────────────

// staff: người chọn làm phụ trách / thành viên / người nhận mốc — tài khoản đang hoạt động có quyền academic_project.view.
// Ô chọn (actor khác nil) chỉ gồm nhân sự thuộc chi nhánh của người xem theo phạm vi Người dùng (User::scopePickableBy
// 'user') + người đang tham gia dự án (keep); tập kiểm tra khi lưu (map) không lọc chi nhánh, như Laravel staff().
func (s *Service) staff(ctx context.Context, actor *iam.Access, keep ...uuid.UUID) ([]Option, map[uuid.UUID]bool, error) {
	ids, err := iam.UserIDsWithPermission(ctx, s.pool, PermView, nil)
	if err != nil {
		return nil, nil, err
	}
	set := make(map[uuid.UUID]bool, len(ids))
	for _, id := range ids {
		set[id] = true
	}
	rows, err := s.q.StaffNames(ctx, nonNil(ids))
	if err != nil {
		return nil, nil, err
	}
	var pickable iam.UserSet
	if actor != nil {
		if pickable, err = iam.PickableUsers(ctx, s.pool, actor, "user", keep...); err != nil {
			return nil, nil, err
		}
	}
	out := make([]Option, 0, len(rows))
	for _, r := range rows {
		if pickable.Has(r.ID) {
			out = append(out, Option{Value: r.ID.String(), Label: r.FullName})
		}
	}
	return out, set, nil
}

// ───────────────────────── Tiện ích ─────────────────────────

func nonNil[T any](v []T) []T {
	if v == nil {
		return []T{}
	}
	return v
}

func deref[T any](p *T) T {
	var zero T
	if p == nil {
		return zero
	}
	return *p
}

// text: chuỗi đã cắt khoảng trắng; rỗng = nil.
func text(p *string) *string {
	if p == nil {
		return nil
	}
	v := strings.TrimSpace(*p)
	if v == "" {
		return nil
	}
	return &v
}

func tooLong(p *string, n int) bool { return p != nil && utf8.RuneCountInString(*p) > n }

func likeArg(q string) *string {
	q = strings.TrimSpace(q)
	if q == "" {
		return nil
	}
	if utf8.RuneCountInString(q) > 100 {
		q = string([]rune(q)[:100])
	}
	r := strings.NewReplacer(`\`, `\\`, `%`, `\%`, `_`, `\_`)
	v := "%" + r.Replace(q) + "%"
	return &v
}

// parseDate đọc "YYYY-MM-DD" (cho phép kèm giờ phía sau); rỗng = nil, sai = lỗi.
func parseDate(p *string) (*time.Time, bool) {
	v := text(p)
	if v == nil {
		return nil, true
	}
	s := *v
	if len(s) > 10 {
		s = s[:10]
	}
	t, err := time.Parse("2006-01-02", s)
	if err != nil {
		return nil, false
	}
	return &t, true
}

func parseUUID(s string) *uuid.UUID {
	id, err := uuid.Parse(strings.TrimSpace(s))
	if err != nil {
		return nil
	}
	return &id
}

func ymd(t *time.Time) *string {
	if t == nil {
		return nil
	}
	v := t.Format("2006-01-02")
	return &v
}

// validURL: đường dẫn đầy đủ có http(s) và tên miền.
func validURL(s string) bool {
	u, err := url.Parse(s)
	if err != nil || u.Host == "" {
		return false
	}
	return u.Scheme == "http" || u.Scheme == "https"
}

// limit: Str::limit của Laravel (cắt theo ký tự, thêm "...").
func limit(s string, n int) string {
	if utf8.RuneCountInString(s) <= n {
		return s
	}
	return string([]rune(s)[:n]) + "..."
}

func projectLink(id uuid.UUID) string { return "/academic-projects/" + id.String() }
