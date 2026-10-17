// Package finance: Thu - Chi (Epic 13 của Laravel, FinanceController): sổ khoản chi vận hành (tự nhập + dòng chi lương tự
// động từ bảng lương) và báo cáo doanh thu tạm tính (tổng thu phiếu đã duyệt − chi vận hành, ma trận chi nhánh).
// Phiếu thu thuộc phân hệ Học phí, bảng lương thuộc phân hệ Lương: đọc qua RevenueSource / PayrollSource.
package finance

import (
	"context"
	"errors"
	"log/slog"
	"slices"
	"strings"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgxpool"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/finance/financedb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// Giờ Việt Nam (không đổi giờ theo mùa).
var vnZone = time.FixedZone("ICT", 7*3600)

// Module phạm vi dữ liệu của báo cáo thu chi / sổ khoản chi (TuitionBranchScope::FINANCE).
const scopeModule = "finance"

// Quyền thêm / sửa / xóa khoản chi (route Laravel gắn can:tuition.create).
const PermManage = "tuition.create"

// ───────────── Nguồn số liệu từ phân hệ khác ─────────────

// RevenueRow: phiếu thu đã duyệt gộp theo chi nhánh ghi nhận và hình thức thanh toán.
type RevenueRow struct {
	// Chi nhánh ghi nhận: chi nhánh hợp đồng học phí, hợp đồng chưa gán chi nhánh thì theo học viên (TuitionBranchScope).
	// nil = phiếu không xác định được chi nhánh (chỉ tính vào tổng "Tất cả chi nhánh").
	BranchID *uuid.UUID
	// transfer / chuyen_khoan = chuyển khoản; cash / tien_mat = tiền mặt; khác không tính vào cơ cấu hình thức.
	PaymentMethod string
	Receipts      int
	// Tổng tiền phiếu (đã gồm phụ thu; phiếu hoàn phí / chuyển nhượng âm)
	Amount int64
	// Phần phụ thu (học phí = Amount − Surcharge, như TuitionReceipt::tuitionPortion)
	Surcharge int64
}

// RevenueSource: phiếu thu tính doanh thu — CHỈ phiếu đã duyệt, theo ngày thu (revenueReceiptQuery của Laravel).
// TODO(gộp): nối phân hệ Học phí (internal/tuition, bảng finance.receipts) khi gộp; hiện dùng noRevenue.
type RevenueSource interface {
	Revenue(ctx context.Context, from, to time.Time) ([]RevenueRow, error)
	// Pending: chưa có nguồn thật (màn hình hiện "Chờ phân hệ Học phí").
	Pending() bool
}

// Salary: kỳ lương của tháng đã chốt / đã trả.
type Salary struct {
	Code       string
	EndDate    *time.Time
	Amount     int64 // tổng thực nhận
	StaffCount int
}

// PayrollSource: chi lương tự động (PayrollPeriod trạng thái approved / paid của tháng).
// TODO(gộp): nối phân hệ Lương khi chuyển sang v2; hiện dùng noPayroll.
type PayrollSource interface {
	// Salary: kỳ lương tháng month; branch != nil → chỉ phiếu lương của nhân sự có chi nhánh chính là branch.
	// nil = tháng chưa có kỳ lương đã chốt / đã trả.
	Salary(ctx context.Context, month time.Time, branch *uuid.UUID) (*Salary, error)
	Pending() bool
}

type noRevenue struct{}

func (noRevenue) Revenue(context.Context, time.Time, time.Time) ([]RevenueRow, error) {
	return nil, nil
}
func (noRevenue) Pending() bool { return true }

type noPayroll struct{}

func (noPayroll) Salary(context.Context, time.Time, *uuid.UUID) (*Salary, error) { return nil, nil }
func (noPayroll) Pending() bool                                                  { return true }

const (
	msgRevenuePending = "Chờ phân hệ Học phí chuyển sang hệ thống mới: chưa có phiếu thu để tính doanh thu."
	msgPayrollPending = "Chờ phân hệ Lương chuyển sang hệ thống mới: chưa có dòng chi lương tự động."
)

// ───────────── Service ─────────────

type Service struct {
	pool    *pgxpool.Pool
	q       *financedb.Queries
	log     *slog.Logger
	now     func() time.Time
	revenue RevenueSource
	payroll PayrollSource
}

func NewService(pool *pgxpool.Pool, log *slog.Logger) *Service {
	return &Service{pool: pool, q: financedb.New(pool), log: log, now: time.Now, revenue: noRevenue{}, payroll: noPayroll{}}
}

// WithRevenue gắn nguồn phiếu thu (phân hệ Học phí).
func (s *Service) WithRevenue(r RevenueSource) *Service {
	if r != nil {
		s.revenue = r
	}
	return s
}

// WithPayroll gắn nguồn chi lương (phân hệ Lương).
func (s *Service) WithPayroll(p PayrollSource) *Service {
	if p != nil {
		s.payroll = p
	}
	return s
}

// SetClock: đồng hồ giả cho test.
func (s *Service) SetClock(now func() time.Time) { s.now = now }

var (
	ErrNoScope = &iam.Error{Status: 403, Code: "no_branch",
		Message: `Tài khoản chưa được gán chi nhánh và chưa được cấp phạm vi báo cáo thu chi "Toàn hệ thống" (finance.scope_all).`}
	ErrOutsideBranch = &iam.Error{Status: 403, Code: "outside_branch", Message: "Bạn chỉ được quản lý khoản chi của chi nhánh mình."}
	ErrNotFound      = &iam.Error{Status: 404, Code: "expense_not_found", Message: "Không tìm thấy khoản chi."}
)

func isNoRows(err error) bool { return errors.Is(err, pgx.ErrNoRows) }

// BranchRef: chi nhánh rút gọn.
type BranchRef struct {
	ID   uuid.UUID `json:"id"`
	Name string    `json:"name"`
}

// Option: một lựa chọn của ô chọn.
type Option struct {
	Value string `json:"value"`
	Label string `json:"label"`
}

// scope: chi nhánh người dùng được xem (TuitionBranchScope::branchIds với module finance). nil = toàn hệ thống;
// mức Chi nhánh mà chưa gán chi nhánh nào thì bị chặn.
func scope(a *iam.Access) ([]uuid.UUID, error) {
	lim := a.BranchLimit(scopeModule)
	if lim == nil {
		return nil, nil
	}
	if len(lim) == 0 {
		return nil, ErrNoScope
	}
	return lim, nil
}

// resolveBranch chuẩn hóa bộ lọc chi nhánh: người bị giới hạn không được chọn "Tất cả" hay chi nhánh ngoài phạm vi
// (về chi nhánh đầu tiên của mình). Trả nil = tất cả chi nhánh.
func resolveBranch(sc []uuid.UUID, requested string) *uuid.UUID {
	id, err := uuid.Parse(strings.TrimSpace(requested))
	if sc == nil {
		if err != nil {
			return nil
		}
		return &id
	}
	if err == nil && slices.Contains(sc, id) {
		return &id
	}
	first := sc[0]
	return &first
}

func branchKey(b *uuid.UUID) string {
	if b == nil {
		return "all"
	}
	return b.String()
}

// visibleBranches: chi nhánh đang hoạt động trong phạm vi.
func (s *Service) visibleBranches(ctx context.Context, sc []uuid.UUID) ([]BranchRef, error) {
	ids := sc
	if ids == nil {
		ids = []uuid.UUID{}
	}
	rows, err := s.q.VisibleBranches(ctx, financedb.VisibleBranchesParams{AllBranches: sc == nil, Ids: ids})
	if err != nil {
		return nil, err
	}
	out := make([]BranchRef, 0, len(rows))
	for _, r := range rows {
		out = append(out, BranchRef{ID: r.ID, Name: r.Name})
	}
	return out, nil
}

// ───────────── Kỳ tháng ─────────────

// parseMonth: đầu tháng của khóa "2026-10" (ReportPeriod::parseMonth); sai định dạng → tháng hiện tại.
func parseMonth(key string, now time.Time) time.Time {
	if len(key) == 7 && key[4] == '-' {
		if t, err := time.ParseInLocation("2006-01", key, vnZone); err == nil {
			return t
		}
	}
	n := now.In(vnZone)
	return time.Date(n.Year(), n.Month(), 1, 0, 0, 0, 0, vnZone)
}

func monthEnd(start time.Time) time.Time { return start.AddDate(0, 1, -1) }

// monthOptions: 6 tháng gần nhất, tháng hiện tại có nhãn "(Hiện tại)".
func monthOptions(now time.Time) []Option {
	cur := parseMonth("", now)
	out := make([]Option, 0, 6)
	for i := range 6 {
		m := cur.AddDate(0, -i, 0)
		label := "Tháng " + m.Format("01/2006")
		if i == 0 {
			label += " (Hiện tại)"
		}
		out = append(out, Option{Value: m.Format("2006-01"), Label: label})
	}
	return out
}
