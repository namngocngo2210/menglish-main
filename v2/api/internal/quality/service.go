// Package quality: Dự giờ & chất lượng lớp — dự giờ vận hành, đánh giá dự giờ học thuật (ObservationController),
// checklist học phí & feedback theo lớp (ClassChecklistController) và báo cáo họp giáo viên theo tuần
// (TeacherMeetingReportController). Chỉ thấy / ghi cho lớp trong phạm vi Lớp học của người dùng (ClassModel::visibleTo),
// lấy qua ClassScope (phân hệ Lớp học).
package quality

import (
	"cmp"
	"context"
	"errors"
	"log/slog"
	"slices"
	"strconv"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgxpool"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/quality/qualitydb"
)

// Quyền (config/permission_catalog.php → class_quality).
const (
	PermView              = "class_quality.view"
	PermObserveOperations = "class_quality.observe_operations"
	PermObserveAcademic   = "class_quality.observe_academic"
	PermChecklist         = "class_quality.checklist"
	PermTeacherMeeting    = "class_quality.teacher_meeting"
)

// ClassScope: lớp người dùng được xem (ClassModel::visibleTo) — classes.Service.VisibleClassIDs.
type ClassScope interface {
	VisibleClassIDs(ctx context.Context, actor *iam.Access) ([]uuid.UUID, error)
}

type Service struct {
	pool    *pgxpool.Pool
	q       *qualitydb.Queries
	classes ClassScope
	log     *slog.Logger
	now     func() time.Time
}

func NewService(pool *pgxpool.Pool, classes ClassScope, log *slog.Logger) *Service {
	return &Service{pool: pool, q: qualitydb.New(pool), classes: classes, log: log, now: time.Now}
}

// SetClock: đồng hồ giả cho test.
func (s *Service) SetClock(now func() time.Time) { s.now = now }

var vnZone = time.FixedZone("ICT", 7*3600)

// dateOf: ngày (giờ Việt Nam) của t, lúc 00:00 UTC — cùng cách pgx đọc cột date.
func dateOf(t time.Time) time.Time {
	y, m, d := t.In(vnZone).Date()
	return time.Date(y, m, d, 0, 0, 0, 0, time.UTC)
}

func (s *Service) today() time.Time { return dateOf(s.now()) }

func (s *Service) tx(ctx context.Context, fn func(tx pgx.Tx, q *qualitydb.Queries) error) error {
	return pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error { return fn(tx, s.q.WithTx(tx)) })
}

func isNoRows(err error) bool { return errors.Is(err, pgx.ErrNoRows) }

func notFound(msg string) *iam.Error { return &iam.Error{Status: 404, Code: "not_found", Message: msg} }

// ───────────────────────── Phạm vi lớp ─────────────────────────

type scope struct {
	all bool
	ids []uuid.UUID
}

func (sc scope) has(id uuid.UUID) bool {
	if sc.all {
		return true
	}
	for _, x := range sc.ids {
		if x == id {
			return true
		}
	}
	return false
}

// visible: mức "Toàn hệ thống" thấy mọi lớp; còn lại hỏi phân hệ Lớp học.
func (s *Service) visible(ctx context.Context, actor *iam.Access) (scope, error) {
	if actor.Level("class") == iam.ScopeAll {
		return scope{all: true, ids: []uuid.UUID{}}, nil
	}
	if s.classes == nil {
		return scope{ids: []uuid.UUID{}}, nil
	}
	ids, err := s.classes.VisibleClassIDs(ctx, actor)
	if err != nil {
		return scope{}, err
	}
	if ids == nil {
		ids = []uuid.UUID{}
	}
	return scope{ids: ids}, nil
}

// ───────────────────────── Kiểu dùng chung ─────────────────────────

// Option: một lựa chọn của ô chọn.
type Option struct {
	Value string `json:"value"`
	Label string `json:"label"`
}

// Field: một mục nội dung (khóa + nhãn) theo thứ tự hiển thị.
type Field struct {
	Key   string `json:"key"`
	Label string `json:"label"`
}

// Meta: phân trang.
type Meta struct {
	Page    int   `json:"page"`
	PerPage int   `json:"per_page"`
	Total   int64 `json:"total"`
}

func pageOf(page, perPage int, total int64) (Meta, int32) {
	if page < 1 {
		page = 1
	}
	return Meta{Page: page, PerPage: perPage, Total: total}, int32((page - 1) * perPage)
}

func labelOf(fields []Field, key string) string {
	for _, f := range fields {
		if f.Key == key {
			return f.Label
		}
	}
	return key
}

func hasKey(fields []Field, key string) bool {
	for _, f := range fields {
		if f.Key == key {
			return true
		}
	}
	return false
}

func optionsOf(fields []Field) []Option {
	out := make([]Option, 0, len(fields))
	for _, f := range fields {
		out = append(out, Option{Value: f.Key, Label: f.Label})
	}
	return out
}

// text: chuỗi đã cắt khoảng trắng; rỗng → nil (TrimStrings + ConvertEmptyStringsToNull của Laravel).
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

// likeArg: chuỗi tìm kiếm cho ILIKE (đã thoát % _ \), nil nếu rỗng.
func likeArg(q string) *string {
	q = strings.TrimSpace(q)
	if q == "" {
		return nil
	}
	p := "%" + strings.NewReplacer(`\`, `\\`, "%", `\%`, "_", `\_`).Replace(q) + "%"
	return &p
}

// parseDate: "YYYY-MM-DD" (có thể kèm giờ phía sau) → ngày lúc 00:00 UTC.
func parseDate(s string) (time.Time, bool) {
	s = strings.TrimSpace(s)
	if len(s) >= 10 {
		if t, err := time.Parse("2006-01-02", s[:10]); err == nil {
			return t, true
		}
	}
	return time.Time{}, false
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

// ───────────────────────── Kỳ tháng (ReportPeriod) ─────────────────────────

// pickMonth: khóa tháng "YYYY-MM" hợp lệ hoặc tháng hiện tại.
func pickMonth(key string, now time.Time) string {
	if validMonth(key) {
		return key
	}
	return now.In(vnZone).Format("2006-01")
}

func validMonth(key string) bool {
	if len(key) != 7 || key[4] != '-' {
		return false
	}
	y, err1 := strconv.Atoi(key[:4])
	m, err2 := strconv.Atoi(key[5:])
	return err1 == nil && err2 == nil && y > 0 && m >= 1 && m <= 12 && key[0] != '-'
}

// monthRange: ngày đầu / cuối tháng (00:00 UTC như cột date).
func monthRange(key string) (time.Time, time.Time) {
	t, _ := time.Parse("2006-01", key)
	return t, t.AddDate(0, 1, -1)
}

func monthLabel(key string) string {
	t, _ := time.Parse("2006-01", key)
	return "Tháng " + t.Format("01/2006")
}

// monthOptions: từ forward tháng tới đến back tháng trước (ReportPeriod::monthOptions).
func monthOptions(now time.Time, back, forward int) []Option {
	n := now.In(vnZone)
	cur := time.Date(n.Year(), n.Month(), 1, 0, 0, 0, 0, time.UTC)
	out := []Option{}
	for i := forward; i >= -back; i-- {
		key := cur.AddDate(0, i, 0).Format("2006-01")
		out = append(out, Option{Value: key, Label: monthLabel(key)})
	}
	return out
}

// weekStart: thứ Hai của tuần chứa t.
func weekStart(t time.Time) time.Time {
	wd := (int(t.Weekday()) + 6) % 7
	return t.AddDate(0, 0, -wd)
}

// ───────────────────────── Người dùng ─────────────────────────

// usersWithPermission: tài khoản đang hoạt động có quyền perm (Rbac::scopeUsersWithPermission), theo tên.
func (s *Service) usersWithPermission(ctx context.Context, perm string) ([]Option, map[uuid.UUID]bool, error) {
	ids, err := iam.UserIDsWithPermission(ctx, s.pool, perm, nil)
	if err != nil {
		return nil, nil, err
	}
	rows, err := s.q.UserNames(ctx, ids)
	if err != nil {
		return nil, nil, err
	}
	sortByName(rows)
	out := make([]Option, 0, len(rows))
	set := map[uuid.UUID]bool{}
	for _, r := range rows {
		out = append(out, Option{Value: r.ID.String(), Label: r.FullName})
		set[r.ID] = true
	}
	return out, set, nil
}

func sortByName(rows []qualitydb.UserNamesRow) {
	slices.SortFunc(rows, func(a, b qualitydb.UserNamesRow) int {
		return cmp.Or(cmp.Compare(a.FullName, b.FullName), cmp.Compare(a.ID.String(), b.ID.String()))
	})
}
