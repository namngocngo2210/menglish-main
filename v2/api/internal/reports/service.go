// Package reports: Báo cáo & Nhật ký của nhân sự (Laravel StaffReportController) — báo cáo định kỳ theo vai trò
// (Học vụ ngày + tuần KPI; Học thuật ngày / tuần / tháng / quý; giáo viên tháng theo lớp + chấm công), báo cáo chữ tự do
// cho vai trò chưa có màn có cấu trúc, Nhật ký Học vụ, màn Tổng hợp cho Admin / Quản lý (kiểm tra, yêu cầu bổ sung,
// chưa nộp báo cáo ngày).
package reports

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"log/slog"
	"slices"
	"sort"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgxpool"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/reports/reportsdb"
)

const (
	PermSubmit  = "staff_report.submit"
	PermViewAll = "staff_report.view_all"
)

var (
	ErrNotFound      = &iam.Error{Status: 404, Code: "not_found", Message: "Không tìm thấy báo cáo."}
	ErrAdminOnly     = &iam.Error{Status: 403, Code: "admin_only", Message: "Chỉ Admin được thực hiện thao tác này."}
	ErrViewAllDenied = &iam.Error{Status: 403, Code: "forbidden", Message: "Chỉ Admin / Manager mới xem tổng hợp."}
	ErrClassOutside  = &iam.Error{Status: 403, Code: "class_outside_scope", Message: "Lớp học này nằm ngoài phạm vi bạn được xem."}
)

// Deps: nguồn dữ liệu của phân hệ khác (để trống → bản mặc định chưa có số liệu, xem metrics.go).
type Deps struct {
	Metrics  MetricsProvider
	Tasks    TaskService
	Classes  ClassDirectory
	Calendar Calendar
}

type Service struct {
	pool    *pgxpool.Pool
	q       *reportsdb.Queries
	log     *slog.Logger
	now     func() time.Time
	metrics MetricsProvider
	tasks   TaskService
	classes ClassDirectory
	cal     Calendar
}

func NewService(pool *pgxpool.Pool, log *slog.Logger, d Deps) *Service {
	s := &Service{pool: pool, q: reportsdb.New(pool), log: log, now: time.Now,
		metrics: d.Metrics, tasks: d.Tasks, classes: d.Classes, cal: d.Calendar}
	if s.metrics == nil {
		s.metrics = skeletonMetrics{}
	}
	if s.tasks == nil {
		s.tasks = noTasks{}
	}
	if s.classes == nil {
		s.classes = noClasses{}
	}
	if s.cal == nil {
		s.cal = noHolidays{}
	}
	return s
}

// SetClock đổi đồng hồ (test).
func (s *Service) SetClock(now func() time.Time) { s.now = now }

func (s *Service) today() time.Time { return dayOf(s.now()) }

// ───────────── Phạm vi người xem ─────────────

func isPrivileged(a *iam.Access) bool { return a.Can(PermViewAll) }

// canReview: kiểm tra / yêu cầu bổ sung / lỗi nghiêm trọng chỉ Admin (AdminOnlyApprovals::ABILITY).
func canReview(a *iam.Access) bool { return a.SuperAdmin }

// viewerScope: người xem tổng hợp không phải Admin (Quản lý cơ sở) chỉ thấy báo cáo / nhật ký của nhân sự chi nhánh mình
// (cộng chính mình). nil = không giới hạn (Admin, hoặc người xem chưa gán chi nhánh).
func viewerScope(a *iam.Access) []uuid.UUID {
	if a.SuperAdmin || len(a.Branches) == 0 {
		return nil
	}
	return a.BranchIDs()
}

func (s *Service) writer(ctx context.Context, a *iam.Access) (Writer, error) {
	row, err := s.q.GetWriter(ctx, a.UserID)
	if err != nil {
		return Writer{}, err
	}
	return Writer{ID: row.ID, Name: row.FullName, Roles: a.Roles, BranchIDs: a.BranchIDs(), CreatedAt: row.CreatedAt}, nil
}

func (s *Service) writerByID(ctx context.Context, id uuid.UUID) (Writer, error) {
	row, err := s.q.GetWriter(ctx, id)
	if err != nil {
		return Writer{}, err
	}
	return Writer{ID: row.ID, Name: row.FullName, Roles: row.Roles, BranchIDs: row.BranchIds, CreatedAt: row.CreatedAt}, nil
}

// guardView: mở màn báo cáo không thuộc vai trò mình → báo cho giao diện quay về báo cáo định kỳ chính.
func guardView(a *iam.Access, kind string) error {
	if slices.Contains(structuredKinds(a.Roles), kind) {
		return nil
	}
	k := structured[kind]
	return &iam.Error{Status: 403, Code: "report_not_for_role", Message: k.Label + " chỉ dành cho " + k.For + "."}
}

func guardWrite(a *iam.Access, kind string) error {
	if slices.Contains(structuredKinds(a.Roles), kind) {
		return nil
	}
	return &iam.Error{Status: 403, Code: "report_not_for_role", Message: "Báo cáo này không thuộc vai trò của bạn."}
}

// ───────────── Kiểu dùng chung trả cho giao diện ─────────────

// Tab của màn "Báo cáo định kỳ của tôi": kind rỗng = báo cáo chữ tự do.
type Tab struct {
	Kind  string `json:"kind"`
	Label string `json:"label"`
}

// tabs: báo cáo định kỳ chính (chữ tự do, chỉ khi vai trò chưa có màn có cấu trúc cho kỳ chính) + báo cáo có cấu trúc
// theo vai trò. Chỉ một tab → không hiện thanh tab.
func tabs(roles []string) []Tab {
	out := []Tab{}
	if primaryStructuredKind(roles) == "" {
		out = append(out, Tab{"", typeLabel(reportCadence(roles))})
	}
	for _, k := range structuredKinds(roles) {
		if structured[k].Tab {
			out = append(out, Tab{k, structured[k].Label})
		}
	}
	if len(out) < 2 {
		return []Tab{}
	}
	return out
}

// Review là trạng thái Admin kiểm tra hiện cho người viết: pending | reviewed | returned (kèm nhận xét).
type Review struct {
	Status string     `json:"status"`
	Note   *string    `json:"note"`
	By     *string    `json:"by"`
	At     *time.Time `json:"at"`
}

func (s *Service) reviewState(ctx context.Context, r *reportsdb.OpsStaffReport) (*Review, error) {
	if r == nil || r.Status == "draft" {
		return nil, nil
	}
	out := &Review{Status: "pending", Note: r.ReviewNote, At: r.ReviewedAt}
	switch {
	case r.Status == "returned":
		out.Status = "returned"
	case r.ReviewedAt != nil:
		out.Status = "reviewed"
	}
	if r.ReviewedBy != nil {
		name, err := s.q.UserName(ctx, *r.ReviewedBy)
		if err != nil && !errors.Is(err, pgx.ErrNoRows) {
			return nil, err
		}
		if err == nil {
			out.By = &name
		}
	}
	return out, nil
}

// HistoryItem là một kỳ đã nộp (hoặc còn thiếu với báo cáo ngày) để chuyển nhanh sang xem / sửa.
type HistoryItem struct {
	Period    string     `json:"period"`
	Label     string     `json:"label"`
	UpdatedAt *time.Time `json:"updated_at"`
	Draft     bool       `json:"draft"`
	Returned  bool       `json:"returned"`
	Reviewed  bool       `json:"reviewed"`
	Missing   bool       `json:"missing"`
}

func (s *Service) history(ctx context.Context, uid uuid.UUID, kind string, label func(string) string) ([]HistoryItem, error) {
	rows, err := s.q.StructuredHistory(ctx, reportsdb.StructuredHistoryParams{UserID: uid, Kind: &kind})
	if err != nil {
		return nil, err
	}
	out := make([]HistoryItem, 0, len(rows))
	for _, r := range rows {
		at := r.UpdatedAt
		out = append(out, HistoryItem{Period: *r.PeriodKey, Label: label(*r.PeriodKey), UpdatedAt: &at,
			Draft: r.Status == "draft", Returned: r.Status == "returned", Reviewed: r.ReviewedAt != nil})
	}
	return out, nil
}

// LegacyReport là báo cáo chữ tự do đã nộp trước khi có màn có cấu trúc (giữ để xem lại).
type LegacyReport struct {
	ID         uuid.UUID `json:"id"`
	Title      string    `json:"title"`
	Content    *string   `json:"content"`
	ReportDate string    `json:"report_date"`
}

func (s *Service) legacy(ctx context.Context, uid uuid.UUID, typ string) ([]LegacyReport, error) {
	rows, err := s.q.LegacyReports(ctx, reportsdb.LegacyReportsParams{UserID: uid, Type: typ})
	if err != nil {
		return nil, err
	}
	out := make([]LegacyReport, 0, len(rows))
	for _, r := range rows {
		out = append(out, LegacyReport{r.ID, r.Title, r.Content, dateKey(r.ReportDate)})
	}
	return out, nil
}

func (s *Service) structuredReport(ctx context.Context, uid uuid.UUID, kind, period string) (*reportsdb.OpsStaffReport, error) {
	r, err := s.q.GetStructured(ctx, reportsdb.GetStructuredParams{UserID: uid, Kind: &kind, PeriodKey: &period})
	if errors.Is(err, pgx.ErrNoRows) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	return &r, nil
}

// saveStructured: mỗi người một báo cáo / loại / kỳ (unique user_id, kind, period_key) nên hai tab cùng bấm Lưu không tạo
// hai bản. Nộp (hoặc sửa bản đã nộp / bản bị yêu cầu bổ sung) → về lại "Chờ kiểm tra".
func (s *Service) saveStructured(ctx context.Context, a *iam.Access, kind, period, title string, reportDate time.Time,
	data any, content, status string) (reportsdb.OpsStaffReport, error) {
	payload, err := json.Marshal(data)
	if err != nil {
		return reportsdb.OpsStaffReport{}, err
	}
	var saved reportsdb.OpsStaffReport
	var before *reportsdb.OpsStaffReport
	err = pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
		q := s.q.WithTx(tx)
		for range 2 {
			cur, err := q.GetStructuredForUpdate(ctx, reportsdb.GetStructuredForUpdateParams{UserID: a.UserID, Kind: &kind, PeriodKey: &period})
			if err == nil {
				before = &cur
				saved, err = q.UpdateStructured(ctx, reportsdb.UpdateStructuredParams{
					ID: cur.ID, Title: title, Content: &content, Data: payload, ReportDate: reportDate, Status: status})
				return err
			}
			if !errors.Is(err, pgx.ErrNoRows) {
				return err
			}
			saved, err = q.InsertStructured(ctx, reportsdb.InsertStructuredParams{
				UserID: a.UserID, Type: structured[kind].Type, Kind: &kind, PeriodKey: &period, Title: title,
				Content: &content, Data: payload, ReportDate: reportDate, Status: status})
			if err == nil {
				return nil
			}
			if !errors.Is(err, pgx.ErrNoRows) { // ON CONFLICT DO NOTHING: tab khác vừa tạo → đọc lại và cập nhật
				return err
			}
		}
		return errors.New("reports: không lưu được báo cáo sau hai lần thử")
	})
	if err != nil {
		return saved, err
	}
	s.log.Info("staff report saved", "by", a.UserID, "kind", kind, "period", period, "status", status, "id", saved.ID)
	if status != "submitted" {
		return saved, nil
	}
	switch {
	case before != nil && before.Status == "returned" && before.ReviewedBy != nil:
		// TODO(notify): báo người đã trả về (before.ReviewedBy), loại staff_report_resubmitted,
		// "Đã bổ sung: <title>" — "<tên> đã nộp lại <title> sau khi bạn yêu cầu bổ sung.", link Tổng hợp ?open=<id>.
	case kind != KindDailyOps && (before == nil || before.Status != "submitted" || before.ReviewedAt != nil):
		// Báo cáo ngày không báo từng bản (Admin nhận bản tổng hợp mỗi sáng). Kỳ khác: báo Admin khi báo cáo vào hàng chờ.
		// TODO(notify): báo mọi Admin, loại staff_report_submitted, "Báo cáo mới: <title>" (sửa sau khi đã kiểm tra:
		// "Báo cáo cập nhật: <title>") — "<tên> vừa nộp / cập nhật <title>. Mở để kiểm tra.", link Tổng hợp ?open=<id>.
	}
	return saved, nil
}

// ───────────── Báo cáo ngày phải nộp / còn thiếu (DailyReportDue) ─────────────

// requiredDays: thứ Hai → thứ Bảy trong [from, to], chỉ ngày đã qua (không tính hôm nay trở đi), không tính trước ngày tạo
// tài khoản, trừ ngày lễ và ngày nghỉ phép đã duyệt.
func (s *Service) requiredDays(ctx context.Context, w Writer, from, to time.Time) ([]time.Time, error) {
	from, to = dayOf(from), dayOf(to)
	if y := s.today().AddDate(0, 0, -1); to.After(y) {
		to = y
	}
	if c := dayOf(w.CreatedAt); c.After(from) {
		from = c
	}
	if from.After(to) {
		return nil, nil
	}
	var branch *uuid.UUID
	if len(w.BranchIDs) > 0 {
		branch = &w.BranchIDs[0]
	}
	off, err := s.cal.OffDays(ctx, w.ID, branch, from, to)
	if err != nil {
		return nil, err
	}
	var out []time.Time
	for d := from; !d.After(to); d = d.AddDate(0, 0, 1) {
		if d.Weekday() != time.Sunday && !off[dateKey(d)] {
			out = append(out, d)
		}
	}
	return out, nil
}

// missingDays: ngày phải nộp mà chưa có báo cáo ngày (bản có cấu trúc đã nộp hoặc báo cáo ngày chữ tự do).
func (s *Service) missingDays(ctx context.Context, w Writer, from, to time.Time) ([]time.Time, error) {
	req, err := s.requiredDays(ctx, w, from, to)
	if err != nil || len(req) == 0 {
		return nil, err
	}
	dates, err := s.q.SubmittedDailyDates(ctx, reportsdb.SubmittedDailyDatesParams{UserID: w.ID, FromDate: req[0], ToDate: req[len(req)-1]})
	if err != nil {
		return nil, err
	}
	done := map[string]bool{}
	for _, d := range dates {
		done[d.Format("2006-01-02")] = true
	}
	var out []time.Time
	for _, d := range req {
		if !done[dateKey(d)] {
			out = append(out, d)
		}
	}
	return out, nil
}

// isLate: báo cáo ngày nộp lần đầu sau hạn 09:00 sáng hôm sau.
func isLate(typ string, submittedAt *time.Time, createdAt, reportDate time.Time) bool {
	if typ != "daily" {
		return false
	}
	first := createdAt
	if submittedAt != nil {
		first = *submittedAt
	}
	return first.After(dailyDeadline(time.Date(reportDate.Year(), reportDate.Month(), reportDate.Day(), 0, 0, 0, 0, vnZone)))
}

// writers: người phải nộp báo cáo ngày (Học vụ / Học thuật đang làm) trong phạm vi.
func (s *Service) writers(ctx context.Context, scope []uuid.UUID, viewer uuid.UUID) ([]reportsdb.ListActiveUsersWithRolesRow, error) {
	rows, err := s.q.ListActiveUsersWithRoles(ctx, reportsdb.ListActiveUsersWithRolesParams{
		Roles: []string{iam.RoleAcademicStaff, iam.RoleAcademicLead}, ScopeBranches: scope, ViewerID: viewer})
	if err != nil {
		return nil, err
	}
	out := rows[:0]
	for _, r := range rows {
		if slices.Contains(structuredKinds(r.Roles), KindDailyOps) {
			out = append(out, r)
		}
	}
	return out, nil
}

func rowWriter(r reportsdb.ListActiveUsersWithRolesRow) Writer {
	return Writer{ID: r.ID, Name: r.FullName, Roles: r.Roles, BranchIDs: r.BranchIds, CreatedAt: r.CreatedAt}
}

// ───────────── Chuỗi ─────────────

func trimPtr(p *string) *string {
	if p == nil {
		return nil
	}
	t := strings.TrimSpace(*p)
	if t == "" {
		return nil
	}
	return &t
}

func runeLen(s string) int { return utf8.RuneCountInString(s) }

// limitText cắt chuỗi còn n ký tự kèm "..." (Str::limit).
func limitText(s string, n int) string {
	if runeLen(s) <= n {
		return s
	}
	r := []rune(s)
	return strings.TrimRight(string(r[:n]), " ") + "..."
}

// squish gộp mọi khoảng trắng liên tiếp thành một dấu cách.
func squish(s string) string { return strings.Join(strings.Fields(s), " ") }

func ptr[T any](v T) *T { return &v }

func strOr(p *string, def string) string {
	if p == nil {
		return def
	}
	return *p
}

// narrativeText: "Nhãn:\nnội dung" theo thứ tự ô, bỏ ô trống.
func narrativeText(values map[string]*string, fields []Option) string {
	var parts []string
	for _, f := range fields {
		if v := values[f.Value]; v != nil && *v != "" {
			parts = append(parts, f.Label+":\n"+*v)
		}
	}
	return strings.Join(parts, "\n\n")
}

// validateNarrative: ô chữ (tối đa max ký tự), ít nhất một ô có nội dung.
func validateNarrative(in map[string]*string, fields []Option, max int) (map[string]*string, error) {
	out := map[string]*string{}
	filled := false
	for _, f := range fields {
		v := in[f.Value]
		if v != nil && strings.TrimSpace(*v) == "" {
			v = nil
		}
		if v != nil && runeLen(*v) > max {
			return nil, iam.Invalid(fmt.Sprintf("%s tối đa %d ký tự.", f.Label, max))
		}
		out[f.Value] = v
		filled = filled || v != nil
	}
	if !filled {
		return nil, iam.Invalid("Nhập ít nhất một mục của báo cáo.")
	}
	return out, nil
}

func sortHistory(items []HistoryItem) {
	sort.SliceStable(items, func(i, j int) bool { return items[i].Period > items[j].Period })
}
