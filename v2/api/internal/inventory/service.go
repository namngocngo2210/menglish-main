// Package inventory: Kho vật phẩm (hàng hóa & vật phẩm, tồn kho theo chi nhánh, nhật ký xuất nhập) và Order học liệu.
// Bám theo bản Laravel: MerchandiseItemController, MerchandiseStockController, StockService, MaterialOrderController,
// MaterialOrderService.
package inventory

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
	"github.com/jackc/pgx/v5/pgconn"
	"github.com/jackc/pgx/v5/pgxpool"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/inventory/inventorydb"
)

// Giờ Việt Nam (không đổi giờ theo mùa); dùng cố định để image không cần tzdata.
var vnZone = time.FixedZone("ICT", 7*3600)

// Config: các mốc lấy từ Cấu hình SLA của Laravel (config/sla.php, config/material_orders.php).
// TODO(sla): đọc từ hr.sla_rules khi module SLA có; hiện dùng giá trị mặc định của Laravel.
type Config struct {
	// material.monthly_deadline: hạn order GVNN / học thuật là 23:59 ngày này của tháng sử dụng
	MonthlyDeadline int
	// material.day_before_cutoff: giờ chốt order đạo cụ / in ấn ngày hôm trước ngày sử dụng (phút trong ngày)
	DayBeforeCutoff int
	// material_orders.due_soon_hours: còn dưới số giờ này thì "Sắp hết hạn"
	DueSoonHours int
}

func DefaultConfig() Config { return Config{MonthlyDeadline: 5, DayBeforeCutoff: 15 * 60, DueSoonHours: 24} }

type Service struct {
	pool *pgxpool.Pool
	q    *inventorydb.Queries
	log  *slog.Logger
	cfg  Config
	now  func() time.Time
}

func NewService(pool *pgxpool.Pool, log *slog.Logger, cfg Config) *Service {
	return &Service{pool: pool, q: inventorydb.New(pool), log: log, cfg: cfg, now: time.Now}
}

var (
	ErrItemNotFound   = &iam.Error{Status: 404, Code: "item_not_found", Message: "Không tìm thấy mặt hàng."}
	ErrOrderNotFound  = &iam.Error{Status: 404, Code: "order_not_found", Message: "Không tìm thấy order học liệu."}
	ErrNoBranch       = &iam.Error{Status: 403, Code: "no_branch", Message: "Tài khoản chưa được gán chi nhánh."}
	ErrOutsideBranch  = &iam.Error{Status: 403, Code: "outside_branch", Message: "Bạn chỉ được thao tác kho chi nhánh của mình."}
	ErrCodeTaken      = &iam.Error{Status: 422, Code: "invalid", Message: "Mã hàng hóa này đã tồn tại trong hệ thống."}
	ErrNotProcessable = &iam.Error{Status: 403, Code: "forbidden", Message: "Bạn không có quyền xử lý order này."}
)

func isNoRows(err error) bool { return errors.Is(err, pgx.ErrNoRows) }

func isUnique(err error) bool {
	var pg *pgconn.PgError
	return errors.As(err, &pg) && pg.Code == "23505"
}

// withTx chạy fn trong một giao dịch.
func (s *Service) withTx(ctx context.Context, fn func(q *inventorydb.Queries) error) error {
	return pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error { return fn(s.q.WithTx(tx)) })
}

// likePattern: chuỗi tìm kiếm đã thoát ký tự đặc biệt của ILIKE; rỗng → nil (không lọc).
func likePattern(s string) *string {
	s = strings.TrimSpace(s)
	if s == "" {
		return nil
	}
	s = strings.NewReplacer(`\`, `\\`, `%`, `\%`, `_`, `\_`).Replace(s)
	return &s
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

func tooLong(s *string, n int) bool { return s != nil && utf8.RuneCountInString(*s) > n }

func ptr[T any](v T) *T { return &v }

// Option là một lựa chọn cho ô chọn ở giao diện.
type Option struct {
	Value string `json:"value"`
	Label string `json:"label"`
}

// BranchRef: chi nhánh rút gọn.
type BranchRef struct {
	ID   uuid.UUID `json:"id"`
	Name string    `json:"name"`
}

// pickBranch: chi nhánh đang xem trong danh sách được phép: theo yêu cầu, rồi chi nhánh chính của người dùng, rồi
// chi nhánh đầu tiên.
func pickBranch(actor *iam.Access, branches []BranchRef, requested *uuid.UUID) *BranchRef {
	find := func(id uuid.UUID) *BranchRef {
		i := slices.IndexFunc(branches, func(b BranchRef) bool { return b.ID == id })
		if i < 0 {
			return nil
		}
		return &branches[i]
	}
	if requested != nil {
		if b := find(*requested); b != nil {
			return b
		}
	}
	for _, ub := range actor.Branches {
		if b := find(ub.ID); b != nil {
			return b
		}
	}
	if len(branches) > 0 {
		return &branches[0]
	}
	return nil
}

func formatVN(t time.Time) string { return t.In(vnZone).Format("15:04 02/01/2006") }
