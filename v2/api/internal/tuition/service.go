// Package tuition: phân hệ Học phí — công nợ học viên, phiếu thu (lập / sửa / duyệt / trả về / xác nhận nộp tiền
// mặt), sổ buổi, dải số hóa đơn, hủy hóa đơn, hoàn / chuyển phí / khất nợ / bảo lưu, nhắc phí quá hạn, nhập Excel,
// webhook SePay. Bám theo bản Laravel: TuitionController, SepayWebhookController, SessionLedger, PaymentConfirmation,
// TuitionSlaService, NotificationService::notifyDebtReminderByMilestone.
package tuition

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"math"
	"strings"
	"time"
	"unicode"
	"unicode/utf8"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgconn"
	"github.com/jackc/pgx/v5/pgtype"
	"github.com/jackc/pgx/v5/pgxpool"
	"golang.org/x/text/unicode/norm"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/catalogs"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/files"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/inventory"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/inventory/inventorydb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/secretbox"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/tasks"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/tuition/tuitiondb"
)

// Giờ Việt Nam (không đổi giờ theo mùa); dùng cố định để image không cần tzdata.
var vnZone = time.FixedZone("ICT", 7*3600)

// scopeModule: phạm vi dữ liệu Học phí (tuition.scope_branch / tuition.scope_all).
const scopeModule = "tuition"

type Service struct {
	pool *pgxpool.Pool
	q    *tuitiondb.Queries
	log  *slog.Logger
	box  *secretbox.Box
	// cat: Danh mục (mốc nhắc nợ, SLA học phí)
	cat *catalogs.Service
	now func() time.Time

	// Nối phân hệ khác (gắn ở cmd/menglish; nil = chưa nối, bỏ qua)
	stock       StockRecorder
	tasks       FeeTasks
	students    StudentStatus
	leads       LeadVisibility
	access      AccessLoader
	commissions Commissions
	files       *files.Service
	// sepay: bật webhook / khóa ký (env SEPAY_WEBHOOK_*)
	sepay SepaySettings
}

func NewService(pool *pgxpool.Pool, box *secretbox.Box, cat *catalogs.Service, log *slog.Logger) *Service {
	return &Service{pool: pool, q: tuitiondb.New(pool), log: log, box: box, cat: cat, now: time.Now}
}

// StockRecorder: kho vật phẩm ghi xuất bán / hoàn kho trong giao dịch của phiếu thu (inventory.Service.Record).
type StockRecorder interface {
	Record(ctx context.Context, tx pgx.Tx, in inventory.RecordInput) (inventorydb.OpsStockMovement, error)
}

// FeeTasks: phân hệ Công việc tạo task "Nhắc thu học phí" cho người phụ trách khi chốt khách còn nợ
// (tasks.Service.CreateFeeReminder).
type FeeTasks interface {
	CreateFeeReminder(ctx context.Context, tx pgx.Tx, in tasks.FeeReminder) (uuid.UUID, error)
}

// StudentStatus: phân hệ Học viên đổi trạng thái khi bảo lưu bắt đầu / kết thúc theo lịch (students.Service).
type StudentStatus interface {
	// StartScheduledDeferral: chuyển "Bảo lưu" trong giao dịch của Học phí; false = không đổi (đã bảo lưu / không học).
	StartScheduledDeferral(ctx context.Context, tx pgx.Tx, studentID uuid.UUID, until time.Time) (bool, error)
	// EndExpiredDeferral: hết bảo lưu → học lại (gọi lại ReleaseDeferral của Học phí); false = không ở trạng thái Bảo lưu.
	EndExpiredDeferral(ctx context.Context, studentID uuid.UUID) (bool, error)
	// ApproveDeferral: duyệt hồ sơ bảo lưu → ghi chú hồ sơ, start thì chuyển "Bảo lưu" ngay.
	ApproveDeferral(ctx context.Context, tx pgx.Tx, studentID uuid.UUID, start bool, note string, actor uuid.UUID) error
}

// Commissions: hoa hồng tư vấn (SalesCommissionService) — gợi ý số thu hồi khi duyệt hoàn phí và ghi quyết định thu hồi
// (hủy khoản còn hoãn, tạo khoản điều chỉnh âm). TODO(gộp): phân hệ Lương / hoa hồng chưa có ở v2; nil = không có khoản
// hoa hồng nào (gợi ý 0 đ, chỉ lưu quyết định trên hồ sơ).
type Commissions interface {
	SuggestedClawback(ctx context.Context, studentID, ownerID uuid.UUID, refundAmount int64) (int64, error)
	RecordClawback(ctx context.Context, tx pgx.Tx, refundID, studentID, ownerID uuid.UUID, amount int64, approver uuid.UUID) error
	// CancellationClawback: duyệt hủy hóa đơn của phiếu đã chi hoa hồng trong kỳ lương đã duyệt → khoản thu hồi ở kỳ kế
	// tiếp (recordCancellationClawback); nil = không có khoản nào.
	CancellationClawback(ctx context.Context, tx pgx.Tx, receiptID uuid.UUID, invoice string, approver uuid.UUID) (*Clawback, error)
}

// Clawback: khoản hoa hồng bị thu hồi (số âm) và người hưởng.
type Clawback struct {
	Amount   int64
	UserName string
}

// WithCommissions gắn phân hệ hoa hồng (cmd/menglish).
func (s *Service) WithCommissions(x Commissions) *Service { s.commissions = x; return s }

// WithFiles gắn kho file (ảnh minh chứng hoàn tiền) và đăng ký quyền tải ảnh minh chứng.
func (s *Service) WithFiles(x *files.Service) *Service {
	s.files = x
	if x != nil {
		x.RegisterReader(refundProofSubject, s.readRefundProof)
		x.RegisterReader(cancellationProofSubject, s.readCancellationProof)
	}
	return s
}

// AccessLoader: quyền + phạm vi của một người dùng (iam.Service.LoadAccess) — lọc người nhận nhắc SLA theo phạm vi
// chi nhánh học phí như TuitionSlaService::holders. nil: lọc theo chi nhánh của người dùng.
type AccessLoader func(ctx context.Context, userID uuid.UUID) (*iam.Access, error)

// WithStock / WithTasks / WithStudents / WithAccess gắn phân hệ khác (cmd/menglish).
func (s *Service) WithStock(x StockRecorder) *Service    { s.stock = x; return s }
func (s *Service) WithTasks(x FeeTasks) *Service         { s.tasks = x; return s }
func (s *Service) WithStudents(x StudentStatus) *Service { s.students = x; return s }
func (s *Service) WithAccess(x AccessLoader) *Service    { s.access = x; return s }

const outOfScope = "Khoản học phí này thuộc chi nhánh ngoài phạm vi bạn được quản lý."

var (
	ErrOutOfScope       = &iam.Error{Status: 403, Code: "forbidden", Message: outOfScope}
	ErrTuitionNotFound  = &iam.Error{Status: 404, Code: "not_found", Message: "Không tìm thấy khoản học phí."}
	ErrReceiptNotFound  = &iam.Error{Status: 404, Code: "not_found", Message: "Không tìm thấy phiếu thu."}
	ErrStudentNotFound  = &iam.Error{Status: 404, Code: "not_found", Message: "Không tìm thấy học viên."}
	ErrNotCreator       = &iam.Error{Status: 403, Code: "forbidden", Message: "Chỉ người lập phiếu mới được sửa phiếu này."}
	ErrAlreadyProcessed = iam.Invalid("Phiếu thu này đã được xử lý trước đó.")
)

func forbidden(msg string) *iam.Error {
	return &iam.Error{Status: 403, Code: "forbidden", Message: msg}
}

// invalidf: lỗi 422 với thông báo định dạng.
func invalidf(format string, a ...any) *iam.Error {
	if len(a) == 0 {
		return iam.Invalid(format)
	}
	return iam.Invalid(fmt.Sprintf(format, a...))
}

func isNoRows(err error) bool { return errors.Is(err, pgx.ErrNoRows) }

func isUnique(err error) bool {
	var pg *pgconn.PgError
	return errors.As(err, &pg) && pg.Code == "23505"
}

// inTx chạy fn trong một giao dịch.
func (s *Service) inTx(ctx context.Context, fn func(tx pgx.Tx, q *tuitiondb.Queries) error) error {
	return pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error { return fn(tx, s.q.WithTx(tx)) })
}

// branchLimit: nil = mọi chi nhánh.
func branchLimit(a *iam.Access) []uuid.UUID { return a.BranchLimit(scopeModule) }

func covers(a *iam.Access, branch *uuid.UUID) bool { return a.CoversBranch(scopeModule, branch) }

// ───────────── Ngày giờ ─────────────

func (s *Service) nowVN() time.Time { return s.now().In(vnZone) }

// dateOf: ngày (00:00 UTC) theo lịch Việt Nam, dùng cho cột date.
func dateOf(t time.Time) time.Time {
	t = t.In(vnZone)
	return time.Date(t.Year(), t.Month(), t.Day(), 0, 0, 0, 0, time.UTC)
}

func (s *Service) today() time.Time { return dateOf(s.now()) }

// dayStart: 00:00 giờ Việt Nam của ngày d (cột date) dưới dạng thời điểm.
func dayStart(d time.Time) time.Time {
	return time.Date(d.Year(), d.Month(), d.Day(), 0, 0, 0, 0, vnZone)
}

func pgTime(t time.Time) pgtype.Time {
	t = t.In(vnZone)
	us := int64(t.Hour())*3600e6 + int64(t.Minute())*60e6 + int64(t.Second())*1e6
	return pgtype.Time{Microseconds: us, Valid: true}
}

func fmtDate(t *time.Time) *string {
	if t == nil {
		return nil
	}
	v := t.Format("02/01/2006")
	return &v
}

func fmtDateTime(t *time.Time, layout string) *string {
	if t == nil {
		return nil
	}
	v := t.In(vnZone).Format(layout)
	return &v
}

func daysBetween(from, to time.Time) int { return int(math.Round(to.Sub(from).Hours() / 24)) }

// parseDate: "2026-10-17" (input date) hoặc "17/10/2026".
func parseDate(s string) (time.Time, bool) {
	s = strings.TrimSpace(s)
	for _, layout := range []string{"2006-01-02", "02/01/2006"} {
		if t, err := time.Parse(layout, s); err == nil {
			return t, true
		}
	}
	return time.Time{}, false
}

// ───────────── Chuỗi / tiền ─────────────

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

func textOf(s *string) string {
	if s == nil {
		return ""
	}
	return *s
}

func tooLong(s *string, n int) bool { return s != nil && utf8.RuneCountInString(*s) > n }

func ptr[T any](v T) *T { return &v }

// nonZero: uuid từ cột COALESCE (NULL đọc ra uuid rỗng) → nil.
func nonZero(id uuid.UUID) *uuid.UUID {
	if id == uuid.Nil {
		return nil
	}
	return &id
}

func val[T any](p *T) T {
	var zero T
	if p == nil {
		return zero
	}
	return *p
}

func firstText(vals ...*string) *string {
	for _, v := range vals {
		if v != nil && strings.TrimSpace(*v) != "" {
			return v
		}
	}
	return nil
}

// limitText: cắt chuỗi còn n ký tự (Str::limit).
func limitText(s string, n int) string {
	r := []rune(s)
	if len(r) <= n {
		return s
	}
	return string(r[:n]) + "..."
}

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

// foldASCII: bỏ dấu tiếng Việt (Str::ascii).
func foldASCII(s string) string {
	var b strings.Builder
	for _, r := range norm.NFD.String(s) {
		switch {
		case unicode.Is(unicode.Mn, r):
		case r == 'đ':
			b.WriteRune('d')
		case r == 'Đ':
			b.WriteRune('D')
		default:
			b.WriteRune(r)
		}
	}
	return b.String()
}

// memoPart: chữ in hoa không dấu, chỉ chữ và số (TransferMemo::clean).
func memoPart(s string) string {
	var b strings.Builder
	for _, r := range strings.ToUpper(foldASCII(s)) {
		if (r >= 'A' && r <= 'Z') || (r >= '0' && r <= '9') {
			b.WriteRune(r)
		}
	}
	return b.String()
}

// transferMemo: nội dung chuyển khoản gợi ý "TEN MAHV LOP" (TransferMemo::build).
func transferMemo(code, name string, class *string) string {
	parts := []string{}
	for _, p := range []string{name, code, textOf(class)} {
		if c := memoPart(p); c != "" {
			parts = append(parts, c)
		}
	}
	return strings.Join(parts, " ")
}

// normalizeReference: mã giao dịch ngân hàng để so trùng (bỏ khoảng trắng, in hoa).
func normalizeReference(code *string) string {
	if code == nil {
		return ""
	}
	return strings.ToUpper(strings.Join(strings.Fields(*code), ""))
}

// Option là một lựa chọn cho ô chọn ở giao diện.
type Option struct {
	Value string `json:"value"`
	Label string `json:"label"`
}

// pageOf: số trang và offset (mỗi trang n dòng).
func pageOf(page, n int) (int32, int32) {
	page = max(1, page)
	return int32(n), int32((page - 1) * n)
}

// Page: thông tin phân trang.
type Page struct {
	Page     int   `json:"page"`
	PerPage  int   `json:"per_page"`
	Total    int64 `json:"total"`
	LastPage int   `json:"last_page"`
}

func newPage(page, per int, total int64) Page {
	last := int((total + int64(per) - 1) / int64(per))
	return Page{Page: max(1, page), PerPage: per, Total: total, LastPage: max(1, last)}
}
