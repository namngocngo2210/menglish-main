// Package placement: test đầu vào (ngân hàng đề, link mời, làm bài công khai có khóa màn hình, tự chấm, chấm theo
// thang điểm khối lớp) và học thử (lịch học thử, nhận xét của giáo viên). Port từ Laravel PlacementTestController,
// TrialGuestController, phần học thử của ClassManagementController / CrmController.
package placement

import (
	"context"
	"crypto/rand"
	"errors"
	"log/slog"
	"math/big"
	"net/http"
	"regexp"
	"slices"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgxpool"
	"github.com/redis/go-redis/v9"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/files"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/placement/placementdb"
)

// Giờ Việt Nam (không có giờ mùa hè): "hôm nay" của lịch học thử tính theo múi này.
var vnZone = time.FixedZone("ICT", 7*3600)

// Options: phụ thuộc sang phân hệ khác (nil = chưa gộp, xem links.go) và đồng hồ (test).
type Options struct {
	Leads    Leads
	Sessions Sessions
	// Kho file chung: file nghe / ảnh câu hỏi, file đề PDF (nil = chưa cấu hình, tải file báo lỗi).
	Files *files.Service
	Now   func() time.Time
}

type Service struct {
	pool     *pgxpool.Pool
	q        *placementdb.Queries
	rdb      *redis.Client
	log      *slog.Logger
	leads    Leads
	sessions Sessions
	files    *files.Service
	now      func() time.Time
}

func NewService(pool *pgxpool.Pool, rdb *redis.Client, log *slog.Logger, opt Options) *Service {
	s := &Service{pool: pool, rdb: rdb, log: log, leads: opt.Leads, sessions: opt.Sessions, files: opt.Files, now: opt.Now}
	if pool != nil {
		s.q = placementdb.New(pool)
	}
	if s.leads == nil {
		s.leads = NoLeads{}
	}
	if s.sessions == nil {
		s.sessions = NoSessions{}
	}
	if s.now == nil {
		s.now = time.Now
	}
	if s.files != nil {
		s.registerFileReaders()
	}
	return s
}

func errInvalid(msg string) error { return iam.Invalid(msg) }

var (
	errNotFound     = &iam.Error{Status: http.StatusNotFound, Code: "not_found", Message: "Không tìm thấy."}
	errTestNotFound = &iam.Error{Status: http.StatusNotFound, Code: "not_found", Message: "Không tìm thấy đề test."}
	errSubNotFound  = &iam.Error{Status: http.StatusNotFound, Code: "not_found", Message: "Không tìm thấy bài làm."}
)

func conflict(code, msg string) error {
	return &iam.Error{Status: http.StatusConflict, Code: code, Message: msg}
}

func notFound(err, e error) error {
	if errors.Is(err, pgx.ErrNoRows) {
		return e
	}
	return err
}

func (s *Service) tx(ctx context.Context, fn func(q *placementdb.Queries) error) error {
	return pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error { return fn(s.q.WithTx(tx)) })
}

// leadScope: khách CRM người dùng được xem (Laravel CrmCustomer::visibleTo). Phạm vi lead "all" thấy mọi khách
// mà không cần hỏi CRM; còn lại hỏi phân hệ CRM danh sách khách (phụ trách / chi nhánh).
func (s *Service) leadScope(ctx context.Context, a *iam.Access) ([]uuid.UUID, bool, error) {
	if a.Level("lead") == iam.ScopeAll {
		return []uuid.UUID{}, true, nil
	}
	ids, all, err := s.leads.VisibleIDs(ctx, a)
	if ids == nil {
		ids = []uuid.UUID{}
	}
	return ids, all, err
}

// leadVisible: người dùng xem được khách này không.
func (s *Service) leadVisible(ctx context.Context, a *iam.Access, id uuid.UUID) (bool, error) {
	ids, all, err := s.leadScope(ctx, a)
	return all || slices.Contains(ids, id), err
}

// record ghi sự kiện sang hồ sơ khách; lỗi chỉ ghi log (không làm hỏng thao tác chính, như Laravel ghi lịch sử sau khi lưu).
func (s *Service) record(ctx context.Context, ev LeadEvent) {
	if err := s.leads.Record(ctx, ev); err != nil {
		s.log.Error("placement: ghi sự kiện sang khách CRM", "err", err, "lead", ev.LeadID, "type", ev.Type)
	}
}

func ptr[T any](v T) *T { return &v }

func strOrNil(s string) *string {
	if s = strings.TrimSpace(s); s == "" {
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

func runeLen(s string) int { return utf8.RuneCountInString(s) }

var emailRe = regexp.MustCompile(`^[^\s@]+@[^\s@]+\.[^\s@]+$`)

const alnum = "ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789"

func randomCode(n int) string {
	b := make([]byte, n)
	for i := range b {
		k, _ := rand.Int(rand.Reader, big.NewInt(int64(len(alnum))))
		b[i] = alnum[k.Int64()]
	}
	return string(b)
}

// NormalizePhone: chỉ giữ chữ số; +84 (11–12 số) → 0 (Laravel CrmCustomer::normalizePhone).
func NormalizePhone(p string) string {
	var b strings.Builder
	for _, r := range p {
		if r >= '0' && r <= '9' {
			b.WriteRune(r)
		}
	}
	d := b.String()
	if (len(d) == 11 || len(d) == 12) && strings.HasPrefix(d, "84") {
		d = "0" + d[2:]
	}
	return d
}

// today: ngày hiện tại theo giờ Việt Nam (00:00 UTC của ngày đó, để so với cột date).
func (s *Service) today() time.Time {
	n := s.now().In(vnZone)
	return time.Date(n.Year(), n.Month(), n.Day(), 0, 0, 0, 0, time.UTC)
}
