// Package classes: phân hệ Lớp học — danh sách / trang lớp / tạo / sửa / xóa lớp (ClassManagementController),
// ca học lặp tuần và sinh buổi học (WorkTaskController::scheduleConfig, SessionScheduleService), xếp lớp học viên
// (StudentProfileController enrollments), điểm danh theo buổi (TeacherPortalController::attendance), xếp lại lịch
// theo ngày nghỉ lễ (HolidayRescheduleService) và phòng học dùng bởi lớp (RoomService).
package classes

import (
	"context"
	"errors"
	"log/slog"
	"slices"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgxpool"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/catalogs"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/classes/classesdb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/crm"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

var (
	ErrNotFound        = &iam.Error{Status: 404, Code: "class_not_found", Message: "Không tìm thấy lớp học."}
	ErrSessionNotFound = &iam.Error{Status: 404, Code: "session_not_found", Message: "Không tìm thấy buổi học."}
	ErrOutOfScope      = &iam.Error{Status: 403, Code: "forbidden", Message: "Lớp học này nằm ngoài phạm vi bạn được quản lý."}
	errPortalStudent   = &iam.Error{Status: 403, Code: "forbidden", Message: "Bạn không có quyền truy cập trang này."}
)

func forbidden(msg string) *iam.Error {
	return &iam.Error{Status: 403, Code: "forbidden", Message: msg}
}

func conflict(msg string) *iam.Error { return &iam.Error{Status: 409, Code: "conflict", Message: msg} }

type Service struct {
	pool *pgxpool.Pool
	q    *classesdb.Queries
	cat  *catalogs.Service
	log  *slog.Logger
	now  func() time.Time
	// CRM: khách Chờ xếp lớp khi xếp lớp từ màn Học viên (nil = chưa gắn, cmd/menglish gắn crm.Service).
	CRM *crm.Service
}

func NewService(pool *pgxpool.Pool, cat *catalogs.Service, log *slog.Logger) *Service {
	return &Service{pool: pool, q: classesdb.New(pool), cat: cat, log: log, now: time.Now}
}

var vnZone = time.FixedZone("ICT", 7*3600)

// dateOf: ngày (giờ Việt Nam) của t, lúc 00:00 UTC — cùng cách pgx đọc cột date.
func dateOf(t time.Time) time.Time {
	y, m, d := t.In(vnZone).Date()
	return time.Date(y, m, d, 0, 0, 0, 0, time.UTC)
}

func (s *Service) today() time.Time { return dateOf(s.now()) }

func (s *Service) tx(ctx context.Context, fn func(tx pgx.Tx, q *classesdb.Queries) error) error {
	return pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error { return fn(tx, s.q.WithTx(tx)) })
}

func isNoRows(err error) bool { return errors.Is(err, pgx.ErrNoRows) }

func fmtDate(t *time.Time) *string {
	if t == nil {
		return nil
	}
	v := t.Format(time.DateOnly)
	return &v
}

func ptr[T any](v T) *T { return &v }

func deref[T any](p *T) T {
	var zero T
	if p == nil {
		return zero
	}
	return *p
}

func optText(s string) *string {
	s = strings.TrimSpace(s)
	if s == "" {
		return nil
	}
	return &s
}

func tooLong(s string, n int) bool { return utf8.RuneCountInString(s) > n }

// likeArg: chuỗi tìm kiếm cho ILIKE (đã thoát % _ \), nil nếu rỗng.
func likeArg(q string) *string {
	q = strings.TrimSpace(q)
	if q == "" {
		return nil
	}
	p := "%" + strings.NewReplacer(`\`, `\\`, "%", `\%`, "_", `\_`).Replace(q) + "%"
	return &p
}

func sameID(a, b *uuid.UUID) bool {
	if a == nil || b == nil {
		return a == nil && b == nil
	}
	return *a == *b
}

// ───────────────────────── Phạm vi ─────────────────────────

// scope: lớp người dùng được xem (ClassModel::visibleTo). all = mọi lớp (phạm vi "Toàn hệ thống").
type scope struct {
	all bool
	ids []uuid.UUID
}

func (sc scope) has(id uuid.UUID) bool { return sc.all || slices.Contains(sc.ids, id) }

// classScopedPerms: quyền lớp cấp riêng theo chi nhánh / lớp ("Phân quyền cá nhân").
var classScopedPerms = []string{"class.view", "class.update", "class.delete"}

// visible: Toàn hệ thống → mọi lớp; còn lại: lớp mình là GV / trợ giảng / GVNN hoặc có buổi dạy (kể cả dạy thay),
// lớp thuộc chi nhánh mình (mức Chi nhánh), lớp / chi nhánh được cấp riêng.
func (s *Service) visible(ctx context.Context, actor *iam.Access) (scope, error) {
	level := actor.Level("class")
	if level == iam.ScopeAll {
		return scope{all: true}, nil
	}
	branches := []uuid.UUID{}
	if level == iam.ScopeBranch {
		branches = append(branches, actor.BranchIDs()...)
	}
	classIDs := []uuid.UUID{}
	for _, perm := range classScopedPerms {
		for _, g := range actor.Scoped[perm] {
			switch g.Type {
			case "branch":
				branches = append(branches, g.ID)
			case "class":
				classIDs = append(classIDs, g.ID)
			}
		}
	}
	ids, err := s.q.VisibleClassIDs(ctx, classesdb.VisibleClassIDsParams{UserID: &actor.UserID, BranchIds: branches, ClassIds: classIDs})
	if err != nil {
		return scope{}, err
	}
	return scope{ids: nonNil(ids)}, nil
}

func nonNil[T any](v []T) []T {
	if v == nil {
		return []T{}
	}
	return v
}

// canOn: quyền class.<action> trên một lớp cụ thể (ClassModel::userCan, chưa xét phạm vi xem): cấp riêng cho đúng lớp /
// chi nhánh, rồi phân quyền cá nhân toàn hệ thống, rồi quyền của vai trò.
func canOn(actor *iam.Access, perm string, classID, branchID uuid.UUID) bool {
	if actor.SuperAdmin {
		return true
	}
	for _, g := range actor.Scoped[perm] {
		if (g.Type == "class" && g.ID == classID) || (g.Type == "branch" && g.ID == branchID) {
			return true
		}
	}
	if v, ok := actor.Overrides[perm]; ok {
		return v
	}
	return actor.Granted[perm]
}

// ensureBrowse: tài khoản cổng học viên (không quản lý mọi lớp) không mở màn quản lý lớp (ensureCanBrowseClasses).
func ensureBrowse(actor *iam.Access) error {
	if actor.Level("class") != iam.ScopeAll && actor.Can("portal.student") {
		return errPortalStudent
	}
	return nil
}

// classFor: lớp (chưa xóa) người dùng được xem và có quyền perm trên lớp đó ("" = chỉ cần xem được).
func (s *Service) classFor(ctx context.Context, actor *iam.Access, id uuid.UUID, perm string) (classesdb.GetClassRow, error) {
	c, err := s.q.GetClass(ctx, id)
	if isNoRows(err) {
		return c, ErrNotFound
	}
	if err != nil {
		return c, err
	}
	sc, err := s.visible(ctx, actor)
	if err != nil {
		return c, err
	}
	if !sc.has(id) {
		if perm == "" {
			return c, ErrNotFound
		}
		return c, ErrOutOfScope
	}
	if perm != "" && !canOn(actor, perm, c.ID, c.BranchID) {
		return c, ErrOutOfScope
	}
	return c, nil
}

// VisibleClassIDs (students.ClassHooks): lớp người dùng được xem; luôn trả danh sách id (kể cả mức Toàn hệ thống).
func (s *Service) VisibleClassIDs(ctx context.Context, actor *iam.Access) ([]uuid.UUID, error) {
	sc, err := s.visible(ctx, actor)
	if err != nil || !sc.all {
		return sc.ids, err
	}
	rows, err := s.q.ClassOptions(ctx, classesdb.ClassOptionsParams{AllClasses: true, Ids: []uuid.UUID{}})
	if err != nil {
		return nil, err
	}
	ids := make([]uuid.UUID, 0, len(rows))
	for _, r := range rows {
		ids = append(ids, r.ID)
	}
	return ids, nil
}
