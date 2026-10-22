package payroll

import (
	"context"
	"fmt"
	"slices"
	"strings"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/files"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/payroll/payrolldb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/filestore"
)

// EvidencePolicy: bằng chứng vi phạm — ảnh / PDF tối đa 10MB.
var EvidencePolicy = filestore.Policy{Types: []string{"jpg", "jpeg", "png", "gif", "webp", "pdf"}, MaxBytes: 10 << 20,
	TooLarge: "File bằng chứng tối đa 10MB.", Required: "Bắt buộc đính kèm bằng chứng vi phạm (ảnh hoặc PDF)."}

const msgEvidenceType = "Bằng chứng phải là ảnh (JPG, PNG, GIF, WEBP) hoặc PDF."

// Loại lỗi → người chốt (Penalty::CATEGORIES).
type category struct {
	Label, Confirmer, Permission string
}

var categories = map[string]category{
	"academic":   {"Lỗi chuyên môn / giảng dạy", "Học thuật (HT)", PermDecideAcademic},
	"operations": {"Lỗi vận hành / nội quy", "Học vụ / Quản lý (CM)", PermDecideOperations},
}

var categoryOrder = []string{"academic", "operations"}

var penaltySteps = []Option{
	{"recorded", "Ghi nhận"}, {"confirmed", "Đã chốt lỗi"}, {"fined", "Đã chốt phạt"}, {"paid", "Đã nộp"},
	{"remedied", "Đã khắc phục"}, {"resolved", "Đóng - không phạt"}, {"cancelled", "Đã hủy"},
}

var openStatuses = []string{"pending", "explained", "confirmed", "fined"}

func penaltyStatusLabels(dueDays int) []Option {
	return []Option{
		{"pending", "Chờ giải trình"}, {"explained", "Đã giải trình — chờ chốt"}, {"confirmed", "Đã xác nhận lỗi"},
		{"fined", fmt.Sprintf("Đã quyết phạt (chờ nộp trong %d ngày)", dueDays)}, {"deducted", "Đã trừ vào bảng lương"},
		{"paid", "Đã nộp phạt trực tiếp"}, {"resolved", "Đã xử lý (không phạt tiền)"}, {"cancelled", "Đã hủy biên bản"},
	}
}

func labelOf(opts []Option, v string) string {
	for _, o := range opts {
		if o.Value == v {
			return o.Label
		}
	}
	return v
}

// Lỗi thường gặp theo vai trò (Penalty::ROLE_VIOLATIONS), giữ đúng thứ tự.
var (
	officeViolations = []string{"Đi làm muộn không báo trước", "Nghỉ làm không phép", "Vắng họp / đào tạo bắt buộc", "Vi phạm nội quy trung tâm"}
	crmViolations    = []string{"Quá hạn SLA liên hệ khách mới", "Quá hạn SLA chuyển trạng thái chăm sóc khách",
		"Quá hạn SLA trả kết quả test đầu vào", "Quá hạn SLA phản hồi sau học thử"}
	teacherViolations = [][2][]string{{
		{"academic"}, {"Gửi nhận xét sau buổi học trễ", "Không nộp giáo án / bài tập đúng hạn", "Dạy sai tiến độ giáo trình",
			"Không nhập điểm / kết quả kiểm tra đúng hạn", "Trả kết quả Big Test trễ"},
	}, {
		{"operations"}, {"Đến muộn > 15 phút không báo trước", "Nghỉ dạy không phép", "Không check-in / điểm danh đúng giờ",
			"Không thông báo kịp thời vấn đề lớp (nghỉ / đổi ca / case học sinh)", "Vắng họp / đào tạo bắt buộc", "Vi phạm nội quy trung tâm"},
	}}
)

type roleViolations struct {
	role   string
	groups [][2][]string // {{category}, {lỗi…}}
}

func join(lists ...[]string) []string {
	var out []string
	for _, l := range lists {
		out = append(out, l...)
	}
	return out
}

var roleViolationList = []roleViolations{
	{"teacher_fulltime", teacherViolations},
	{"teacher_parttime", teacherViolations},
	{"assistant", [][2][]string{
		{{"academic"}, {"Không hoàn thành nhiệm vụ trợ giảng được giao"}},
		{{"operations"}, {"Đến muộn > 15 phút không báo trước", "Nghỉ trực lớp không phép", "Không check-in / điểm danh đúng giờ",
			"Vắng họp / đào tạo bắt buộc", "Vi phạm nội quy trung tâm"}},
	}},
	{"academic_staff", [][2][]string{
		{{"operations"}, join(crmViolations, []string{"Quá hạn SLA chăm sóc học viên tháng đầu", "Không nhắc / follow học phí đúng quy trình",
			"Thu sai / thiếu học phí", "Sai sót dữ liệu học sinh / học phí trên hệ thống", "Nộp báo cáo ngày trễ hạn",
			"Sự cố vận hành lớp do lỗi học vụ", "Order học liệu quá hạn"}, officeViolations)},
	}},
	{"academic_lead", [][2][]string{
		{{"academic"}, {"Trễ deadline mốc dự án học thuật", "Quá hạn duyệt đề Big Test", "Order học liệu quá hạn",
			"Xử lý phản ánh về GV trễ hạn", "Không đào tạo GV đúng kế hoạch"}},
		{{"operations"}, officeViolations},
	}},
	{"sales_consultant", [][2][]string{
		{{"operations"}, join(crmViolations, []string{"Nhập sai / thiếu dữ liệu khách hàng trên CRM"}, officeViolations)},
	}},
	{"manager", [][2][]string{
		{{"operations"}, join([]string{"Thu sai / thiếu học phí", "Tiền mặt chưa nộp về TK công ty", "Quá hạn xử lý hoàn phí / chuyển nhượng",
			"Nộp báo cáo trễ hạn"}, officeViolations)},
	}},
}

// ViolationOption: lỗi thường gặp kèm loại lỗi (người chốt đi theo lỗi).
type ViolationOption struct {
	Value    string `json:"value"`
	Category string `json:"category"`
}

// commonViolations: lỗi thường gặp của các vai trò (lỗi trùng giữ loại lỗi gặp đầu tiên); không vai trò nào khớp → mọi vai trò.
func commonViolations(roles []string) []ViolationOption {
	var picked []roleViolations
	for _, rv := range roleViolationList {
		if slices.Contains(roles, rv.role) {
			picked = append(picked, rv)
		}
	}
	if len(picked) == 0 {
		picked = roleViolationList
	}
	var out []ViolationOption
	for _, rv := range picked {
		for _, g := range rv.groups {
			for _, v := range g[1] {
				if !slices.ContainsFunc(out, func(o ViolationOption) bool { return o.Value == v }) {
					out = append(out, ViolationOption{Value: v, Category: g[0][0]})
				}
			}
		}
	}
	return out
}

func categoryFor(violation string, roles []string, chosen string) string {
	for _, o := range commonViolations(roles) {
		if o.Value == violation {
			return o.Category
		}
	}
	if _, ok := categories[chosen]; ok {
		return chosen
	}
	return "operations"
}

func categoryLabel(c *string) string {
	if c != nil {
		if x, ok := categories[*c]; ok {
			return x.Label
		}
	}
	return "Chưa phân loại"
}

func confirmerLabel(c *string) string {
	if c != nil {
		if x, ok := categories[*c]; ok {
			return x.Confirmer
		}
	}
	return "HT / CM"
}

// canDecide: người được chốt biên bản theo loại lỗi; người vi phạm không tự chốt (trừ Super Admin).
func canDecide(a *iam.Access, owner uuid.UUID, cat *string) bool {
	if a.UserID == owner && !a.SuperAdmin {
		return false
	}
	if cat != nil {
		if c, ok := categories[*cat]; ok {
			return a.Can(c.Permission)
		}
	}
	return a.CanAny(PermDecideAcademic, PermDecideOperations)
}

func stepOf(status string, remedied bool) string {
	switch status {
	case "pending", "explained":
		return "recorded"
	case "confirmed", "fined", "resolved":
		return status
	case "paid", "deducted":
		if remedied {
			return "remedied"
		}
		return "paid"
	}
	return "cancelled"
}

func isOverdue(status string, due *time.Time, today time.Time) bool {
	return status == "fined" && due != nil && asDay(*due).Before(today)
}

// ───────────── Danh sách ─────────────

type PenaltyFilter struct {
	Search   string `json:"search"`
	Status   string `json:"status"`
	Category string `json:"error_category"`
	Step     string `json:"step"`
	From     string `json:"from"`
	To       string `json:"to"`
	Page     int    `json:"page"`
}

type PenaltyRow struct {
	ID             uuid.UUID `json:"id"`
	Code           string    `json:"code"`
	UserName       string    `json:"user_name"`
	EmployeeCode   string    `json:"employee_code"`
	ViolationDate  string    `json:"violation_date"`
	ViolationTime  *string   `json:"violation_time"`
	EvidenceURL    *string   `json:"evidence_url"`
	SourceLabel    string    `json:"source_label"`
	ViolationType  string    `json:"violation_type"`
	Category       *string   `json:"category"`
	CategoryLabel  string    `json:"category_label"`
	ConfirmerLabel string    `json:"confirmer_label"`
	Step           string    `json:"step"`
	StepLabel      string    `json:"step_label"`
	Status         string    `json:"status"`
	StatusLabel    string    `json:"status_label"`
	Overdue        bool      `json:"overdue"`
	Amount         int64     `json:"amount"`
	DueDate        *string   `json:"due_date"`
	PaidAt         *string   `json:"paid_at"`
	EmployeeState  string    `json:"employee_state"`
	EmployeeColor  string    `json:"employee_color"`
	Remedied       bool      `json:"remedied"`
	ClassName      *string   `json:"class_name"`
	Reporter       *string   `json:"reporter"`
	Notes          *string   `json:"notes"`
	Explanation    *string   `json:"explanation"`
	DecisionNote   *string   `json:"decision_note"`
	Decider        *string   `json:"decider"`
	Remedy         *string   `json:"remedy"`
	CanDecide      bool      `json:"can_decide"`
	CanExplain     bool      `json:"can_explain"`
	CanMarkPaid    bool      `json:"can_mark_paid"`
	CanResolve     bool      `json:"can_resolve"`
	CanRemedy      bool      `json:"can_remedy"`
	CanCancel      bool      `json:"can_cancel"`
}

type StaffOption struct {
	Value string   `json:"value"`
	Label string   `json:"label"`
	Roles []string `json:"roles"`
}

type RoleViolations struct {
	Label      string            `json:"label"`
	Violations []ViolationOption `json:"violations"`
}

type PenaltiesPage struct {
	Penalties       []PenaltyRow              `json:"penalties"`
	Paging          Paging                    `json:"paging"`
	Filters         PenaltyFilter             `json:"filters"`
	Users           []StaffOption             `json:"users"`
	Classes         []Option                  `json:"classes"`
	CanViewAll      bool                      `json:"can_view_all"`
	CanCreate       bool                      `json:"can_create"`
	Counts          map[string]int            `json:"counts"`
	Steps           []Option                  `json:"steps"`
	CategoryOptions []Option                  `json:"category_options"`
	ConfirmerOpts   []Option                  `json:"category_confirmer_options"`
	StatusOptions   []Option                  `json:"status_options"`
	RoleViolations  map[string]RoleViolations `json:"role_violations"`
	AllViolations   []ViolationOption         `json:"all_violations"`
	PaymentDueDays  int                       `json:"payment_due_days"`
	Window          map[string]any            `json:"violation_window"`
}

func parseDayParam(s string) (*time.Time, error) {
	s = strings.TrimSpace(s)
	if s == "" {
		return nil, nil
	}
	t, err := time.ParseInLocation("2006-01-02", s, vnZone)
	if err != nil {
		return nil, iam.Invalid("Ngày lọc không hợp lệ.")
	}
	d := dbDay(t)
	return &d, nil
}

// scopeViolation: module phạm vi dữ liệu của biên bản vi phạm (violation.scope_*).
const scopeViolation = "violation"

// penaltyScope: biên bản người xem được thấy (Penalty::constrainToViewer). Không có violation.view → chỉ biên bản của mình;
// có → theo violation.scope_*: Của tôi → biên bản mình vi phạm hoặc mình lập; Chi nhánh → thêm biên bản của nhân sự thuộc
// chi nhánh mình (chi nhánh chính hoặc được cấp thêm của người vi phạm); Toàn hệ thống → mọi biên bản.
type penaltyScope struct {
	owner    *uuid.UUID
	scoped   bool
	branches []uuid.UUID
}

func penaltyScopeOf(a *iam.Access) penaltyScope {
	if !a.Can(PermViolationView) {
		return penaltyScope{owner: actorPtr(a)}
	}
	switch a.Level(scopeViolation) {
	case iam.ScopeAll:
		return penaltyScope{}
	case iam.ScopeBranch:
		return penaltyScope{scoped: true, branches: a.BranchIDs()}
	}
	return penaltyScope{scoped: true, branches: []uuid.UUID{}}
}

// visiblePenalty: biên bản ngoài phạm vi người xem → không tìm thấy (Penalty::visibleTo(...)->findOrFail).
func (s *Service) visiblePenalty(ctx context.Context, q *payrolldb.Queries, a *iam.Access, id uuid.UUID) error {
	sc := penaltyScopeOf(a)
	ok, err := q.PenaltyVisible(ctx, payrolldb.PenaltyVisibleParams{ID: id, Owner: sc.owner, Scoped: sc.scoped, Me: a.UserID,
		WithReporter: sc.scoped, ScopeBranches: sc.branches})
	if err != nil {
		return err
	}
	if !ok {
		return errPenaltyNotFound
	}
	return nil
}

func (s *Service) Penalties(ctx context.Context, a *iam.Access, f PenaltyFilter) (*PenaltiesPage, error) {
	// Tài khoản cổng học viên không có hồ sơ kỷ luật nhân sự.
	if a.Can(PermPortalStudent) && !a.Can(PermViolationView) {
		return nil, forbidden("Bạn không có quyền xem biên bản vi phạm.")
	}
	dueDays := s.paymentDueDays(ctx, s.q)
	statusOpts := penaltyStatusLabels(dueDays)
	if f.Status != "" && f.Status != "overdue" && f.Status != "open" && labelOf(statusOpts, f.Status) == f.Status {
		return nil, iam.Invalid("Trạng thái lọc không hợp lệ.")
	}
	if _, ok := categories[f.Category]; f.Category != "" && !ok {
		return nil, iam.Invalid("Loại lỗi lọc không hợp lệ.")
	}
	if f.Step != "" && labelOf(penaltySteps, f.Step) == f.Step {
		return nil, iam.Invalid("Bước xử lý lọc không hợp lệ.")
	}
	if runeLen(f.Search) > 100 {
		return nil, iam.Invalid("Từ khóa tìm kiếm tối đa 100 ký tự.")
	}
	from, err := parseDayParam(f.From)
	if err != nil {
		return nil, err
	}
	to, err := parseDayParam(f.To)
	if err != nil {
		return nil, err
	}
	canAll := a.Can(PermViolationView)
	sc := penaltyScopeOf(a)
	today := s.today()
	todayDB := dbDay(today)
	page := pageOf(f.Page)
	const per = 15
	search := strings.TrimSpace(f.Search)
	rows, err := s.q.ListPenalties(ctx, payrolldb.ListPenaltiesParams{Owner: sc.owner, Scoped: sc.scoped, Me: a.UserID,
		WithReporter: sc.scoped, ScopeBranches: sc.branches, Search: search, Status: f.Status, Today: &todayDB,
		Category: f.Category, Step: f.Step, FromDay: from, ToDay: to, Lim: per, Off: int32((page - 1) * per)})
	if err != nil {
		return nil, err
	}
	total, err := s.q.CountPenalties(ctx, payrolldb.CountPenaltiesParams{Owner: sc.owner, Scoped: sc.scoped, Me: a.UserID,
		WithReporter: sc.scoped, ScopeBranches: sc.branches, Search: search, Status: f.Status, Today: &todayDB,
		Category: f.Category, Step: f.Step, FromDay: from, ToDay: to})
	if err != nil {
		return nil, err
	}
	counts, err := s.q.PenaltyCounts(ctx, payrolldb.PenaltyCountsParams{Owner: sc.owner, Scoped: sc.scoped, Me: a.UserID,
		WithReporter: sc.scoped, ScopeBranches: sc.branches, Today: &todayDB})
	if err != nil {
		return nil, err
	}
	out := &PenaltiesPage{Penalties: []PenaltyRow{}, Paging: paging(page, per, total), Filters: f, CanViewAll: canAll,
		CanCreate: a.Can(PermViolationCreate), Users: []StaffOption{}, Classes: []Option{}, Steps: penaltySteps,
		Counts: map[string]int{"pending": int(counts.Pending), "deciding": int(counts.Deciding),
			"fined": max(0, int(counts.Fined-counts.Overdue)), "overdue": int(counts.Overdue)},
		StatusOptions:  append([]Option{{"open", "Đang xử lý (chưa đóng)"}, {"overdue", "Quá hạn nộp"}}, statusOpts...),
		RoleViolations: map[string]RoleViolations{}, AllViolations: commonViolations(nil), PaymentDueDays: dueDays}
	for _, k := range categoryOrder {
		c := categories[k]
		out.CategoryOptions = append(out.CategoryOptions, Option{k, c.Label})
		out.ConfirmerOpts = append(out.ConfirmerOpts, Option{k, c.Label + " — " + c.Confirmer})
	}
	labels, err := s.q.RoleLabels(ctx)
	if err != nil {
		return nil, err
	}
	for _, rv := range roleViolationList {
		label := rv.role
		for _, l := range labels {
			if l.Name == rv.role {
				label = l.ShortLabel
			}
		}
		out.RoleViolations[rv.role] = RoleViolations{Label: label, Violations: commonViolations([]string{rv.role})}
	}
	hours := s.recordWindowHours(ctx, s.q)
	now := s.now().In(vnZone)
	out.Window = map[string]any{"hours": hours, "min": now.Add(-time.Duration(hours) * time.Hour).Format("2006-01-02T15:04"),
		"max": now.Format("2006-01-02T15:04")}
	if canAll {
		// Ô chọn nhân sự / lớp khi lập biên bản: chỉ trong chi nhánh của người lập (trừ mức Toàn hệ thống).
		staff, err := s.q.ActiveStaffOptions(ctx)
		if err != nil {
			return nil, err
		}
		inScope, err := iam.StaffInBranches(ctx, s.pool, a, scopeViolation)
		if err != nil {
			return nil, err
		}
		staff = iam.FilterUsers(inScope, staff, func(u payrolldb.ActiveStaffOptionsRow) uuid.UUID { return u.ID })
		for _, u := range staff {
			label := u.FullName
			if u.Code != "" {
				label += " — " + u.Code
			}
			if u.Email != "" {
				label += " (" + u.Email + ")"
			}
			out.Users = append(out.Users, StaffOption{Value: u.ID.String(), Label: label, Roles: u.Roles})
		}
		cls, err := s.q.ClassOptions(ctx, a.BranchLimit(scopeViolation))
		if err != nil {
			return nil, err
		}
		for _, c := range cls {
			out.Classes = append(out.Classes, Option{c.ID.String(), c.Name})
		}
	}
	for _, p := range rows {
		out.Penalties = append(out.Penalties, s.penaltyRow(a, p, today, statusOpts))
	}
	return out, nil
}

func (s *Service) penaltyRow(a *iam.Access, p payrolldb.ListPenaltiesRow, today time.Time, statusOpts []Option) PenaltyRow {
	overdue := isOverdue(p.Status, p.DueDate, today)
	step := stepOf(p.Status, p.RemediedAt != nil)
	row := PenaltyRow{ID: p.ID, Code: p.Code, UserName: p.UserName, EmployeeCode: p.UserCode, ViolationDate: dmy(p.ViolationDate),
		SourceLabel: "Tự động", ViolationType: p.ViolationType, Category: p.ErrorCategory, CategoryLabel: categoryLabel(p.ErrorCategory),
		ConfirmerLabel: confirmerLabel(p.ErrorCategory), Step: step, StepLabel: labelOf(penaltySteps, step), Status: p.Status,
		StatusLabel: labelOf(statusOpts, p.Status), Overdue: overdue, Amount: p.Amount, DueDate: dmyp(p.DueDate),
		Remedied: p.RemediedAt != nil, ClassName: p.ClassName, Reporter: p.ReporterName, Notes: p.Notes, Explanation: p.Explanation,
		DecisionNote: p.DecisionNote, Decider: p.DeciderName}
	if overdue {
		row.StatusLabel = "Quá hạn nộp — sẽ trừ lương"
	}
	if p.ReporterID != nil {
		row.SourceLabel = "Thủ công"
	}
	if p.PaidAt != nil {
		v := p.PaidAt.In(vnZone).Format("02/01/2006")
		row.PaidAt = &v
	}
	if p.EvidenceFileID != nil {
		u := files.URL(*p.EvidenceFileID)
		row.EvidenceURL = &u
		if p.ViolationAt != nil {
			t := p.ViolationAt.In(vnZone).Format("15:04")
			row.ViolationTime = &t
		}
	}
	switch {
	case p.Explanation != nil && *p.Explanation != "":
		row.EmployeeState, row.EmployeeColor = "Đã xác nhận", "success"
	case p.Status == "pending":
		row.EmployeeState, row.EmployeeColor = "Chờ xác nhận", "warning"
	default:
		row.EmployeeState, row.EmployeeColor = "Bỏ qua", "neutral"
	}
	if p.RemediedAt != nil {
		r := p.RemediedAt.In(vnZone).Format("02/01/2006") + " — " + deref(p.RemedierName)
		if p.RemedyNote != nil && *p.RemedyNote != "" {
			r += ": " + *p.RemedyNote
		}
		row.Remedy = &r
	}
	decidable := slices.Contains([]string{"pending", "explained", "confirmed"}, p.Status)
	row.CanDecide = a.CanAny(PermConfirmFine, PermConfirmError) && canDecide(a, p.UserID, p.ErrorCategory) && decidable
	row.CanExplain = p.UserID == a.UserID && p.Status == "pending"
	row.CanMarkPaid = a.Can(PermViolationMarkPaid) && p.Status == "fined" && !overdue
	row.CanResolve = a.Can(PermViolationResolve) && slices.Contains(openStatuses, p.Status)
	row.CanRemedy = a.Can(PermViolationResolve) && (p.Status == "paid" || p.Status == "deducted") && p.RemediedAt == nil
	row.CanCancel = a.Can(PermViolationCancel) && decidable
	return row
}

// ───────────── Ghi nhận ─────────────

type PenaltyInput struct {
	UserID        string  `json:"user_id"`
	ErrorCategory string  `json:"error_category"`
	ViolationType string  `json:"violation_type"`
	ViolationAt   string  `json:"violation_at"` // "2006-01-02T15:04" giờ Việt Nam
	Amount        *int64  `json:"amount"`
	ClassID       string  `json:"class_id"`
	Notes         *string `json:"notes"`
}

func parseViolationAt(s string) (time.Time, error) {
	s = strings.TrimSpace(s)
	if s == "" {
		return time.Time{}, iam.Invalid("Vui lòng nhập thời điểm vi phạm.")
	}
	for _, layout := range []string{"2006-01-02T15:04", "2006-01-02T15:04:05", "2006-01-02 15:04", time.RFC3339} {
		if t, err := time.ParseInLocation(layout, s, vnZone); err == nil {
			return t, nil
		}
	}
	return time.Time{}, iam.Invalid("Thời điểm vi phạm không hợp lệ.")
}

func (s *Service) CreatePenalty(ctx context.Context, a *iam.Access, in PenaltyInput, evidence *filestore.Upload) (string, error) {
	uid, err := uuid.Parse(strings.TrimSpace(in.UserID))
	if err != nil {
		return "", iam.Invalid("Vui lòng chọn nhân sự vi phạm.")
	}
	if ok, err := s.q.StaffExists(ctx, uid); err != nil {
		return "", err
	} else if !ok {
		return "", iam.Invalid("Nhân sự vi phạm không tồn tại.")
	}
	// Chỉ lập biên bản cho nhân sự / lớp thuộc chi nhánh của mình (trừ mức Toàn hệ thống — violation.scope_all).
	if inScope, err := iam.StaffInBranches(ctx, s.pool, a, scopeViolation); err != nil {
		return "", err
	} else if !inScope.Has(uid) {
		return "", iam.Invalid("Nhân sự này không thuộc chi nhánh của bạn.")
	}
	if _, ok := categories[in.ErrorCategory]; in.ErrorCategory != "" && !ok {
		return "", iam.Invalid("Loại lỗi không hợp lệ.")
	}
	violation := strings.TrimSpace(in.ViolationType)
	if violation == "" {
		return "", iam.Invalid("Vui lòng nhập lỗi vi phạm.")
	}
	if runeLen(violation) > 255 {
		return "", iam.Invalid("Lỗi vi phạm tối đa 255 ký tự.")
	}
	at, err := parseViolationAt(in.ViolationAt)
	if err != nil {
		return "", err
	}
	if in.Amount != nil && *in.Amount < 0 {
		return "", iam.Invalid("Mức phạt đề xuất không được âm.")
	}
	var classID *uuid.UUID
	if c := strings.TrimSpace(in.ClassID); c != "" {
		id, err := uuid.Parse(c)
		if err != nil {
			return "", iam.Invalid("Lớp học không tồn tại.")
		}
		if ok, err := s.q.ClassExists(ctx, id); err != nil {
			return "", err
		} else if !ok {
			return "", iam.Invalid("Lớp học không tồn tại.")
		}
		if limit := a.BranchLimit(scopeViolation); limit != nil {
			if ok, err := s.q.ClassInBranches(ctx, payrolldb.ClassInBranchesParams{ID: id, BranchIds: limit}); err != nil {
				return "", err
			} else if !ok {
				return "", iam.Invalid("Lớp này không thuộc chi nhánh của bạn.")
			}
		}
		classID = &id
	}
	notes := trimPtr(in.Notes)
	if notes != nil && runeLen(*notes) > 500 {
		return "", iam.Invalid("Ghi chú tối đa 500 ký tự.")
	}
	if err := evidence.Check(EvidencePolicy); err != nil {
		if ve, ok := err.(*filestore.ValidationError); ok {
			if strings.HasPrefix(ve.Message, "Định dạng") {
				return "", iam.Invalid(msgEvidenceType)
			}
			return "", iam.Invalid(ve.Message)
		}
		return "", err
	}
	now := s.now()
	if at.After(now) {
		return "", iam.Invalid("Thời điểm vi phạm không được ở tương lai.")
	}
	hours := s.recordWindowHours(ctx, s.q)
	if at.Before(now.Add(-time.Duration(hours) * time.Hour)) {
		return "", iam.Invalid(fmt.Sprintf("Vi phạm đã quá %dh — không thể ghi nhận.", hours))
	}
	if s.files == nil {
		return "", iam.Invalid("Kho file chưa sẵn sàng: chưa tải được bằng chứng vi phạm.")
	}
	users, err := s.q.UsersBrief(ctx, []uuid.UUID{uid})
	if err != nil {
		return "", err
	}
	var roles []string
	name := ""
	if len(users) > 0 {
		roles, name = users[0].Roles, users[0].FullName
	}
	cat := categoryFor(violation, roles, in.ErrorCategory)
	var created payrolldb.HrPenalty
	err = s.tx(ctx, func(tx pgxTx, q *payrolldb.Queries) error {
		p, err := s.insertPenalty(ctx, q, payrolldb.InsertPenaltyParams{UserID: uid, ClassID: classID, ViolationType: violation,
			ErrorCategory: &cat, ViolationDate: dbDay(at), ViolationAt: &at, Amount: deref(in.Amount), ReporterID: actorPtr(a), Notes: notes})
		if err != nil {
			return err
		}
		f, err := s.files.Save(ctx, tx, evidence, files.SaveInput{
			Owner: files.Owner{Module: "payroll", SubjectType: evidenceSubject, SubjectID: &p.ID}, By: actorPtr(a),
			Audit: audit.Entry{Module: audit.ModulePayroll, SubjectType: "penalty", SubjectID: p.ID.String(), SubjectLabel: p.Code,
				Description: "Tải bằng chứng vi phạm " + p.Code}})
		if err != nil {
			return err
		}
		if err := q.SetPenaltyEvidence(ctx, payrolldb.SetPenaltyEvidenceParams{FileID: &f.ID, ID: p.ID}); err != nil {
			return err
		}
		created = p
		return audit.Record(ctx, tx, audit.Entry{ActorID: actorPtr(a), Module: audit.ModulePayroll, Event: audit.EventCreated,
			Action: "penalty.create", SubjectType: "penalty", SubjectID: p.ID.String(), SubjectLabel: p.Code + " (" + name + ")",
			Description: "Ghi nhận vi phạm " + p.Code + ": " + violation,
			Properties:  map[string]any{"user_id": uid, "violation_type": violation, "error_category": cat}})
	})
	if err != nil {
		return "", err
	}
	s.log.Info("penalty created", "by", a.UserID, "penalty", created.ID, "code", created.Code)
	return fmt.Sprintf("Đã ghi nhận vi phạm %s — chờ nhân sự giải trình, sau đó %s chốt.", created.Code, confirmerLabel(&cat)), nil
}

// insertPenalty cấp mã BB-YYYY-NNN (khóa theo transaction) rồi ghi biên bản. Trùng auto_key → pgx.ErrNoRows.
func (s *Service) insertPenalty(ctx context.Context, q *payrolldb.Queries, p payrolldb.InsertPenaltyParams) (payrolldb.HrPenalty, error) {
	if err := q.LockPenaltyCodes(ctx); err != nil {
		return payrolldb.HrPenalty{}, err
	}
	year := s.now().In(vnZone).Year()
	n, err := q.NextPenaltyNumber(ctx, fmt.Sprint(year))
	if err != nil {
		return payrolldb.HrPenalty{}, err
	}
	p.Code = fmt.Sprintf("BB-%d-%03d", year, n)
	return q.InsertPenalty(ctx, p)
}

// AutoPenalty: biên bản do hệ thống tự lập (SLA của phân hệ khác: chăm sóc tháng đầu, Big Test, CRM…).
type AutoPenalty struct {
	UserID        uuid.UUID
	Source        string // mã SLA / nguồn, vd. "crm.first_contact"
	Key           string // khóa chống lập trùng trong cùng nguồn (vd. id sự kiện SLA)
	ViolationType string
	Category      string // academic | operations
	ViolationAt   time.Time
	Amount        int64
	ClassID       *uuid.UUID
	WorkTaskID    *uuid.UUID
	BigTestID     *uuid.UUID
	Notes         string
	Title         string // tiêu đề thông báo; trống = "Biên bản <mã>: <Subject>" (Subject trống = lỗi)
	Subject       string
	Body          string         // nội dung thông báo; trống = lời nhắc giải trình mặc định
	Data          map[string]any // thêm vào dữ liệu thông báo
}

// CreateAutoPenalty lập biên bản tự động trong tx của phân hệ gọi và báo người vi phạm (penalty_created).
// Đã có biên bản cùng Source + Key thì trả nil (không lập lại).
func (s *Service) CreateAutoPenalty(ctx context.Context, tx pgxTx, in AutoPenalty) (*payrolldb.HrPenalty, error) {
	q := s.q.WithTx(tx)
	cat := in.Category
	if _, ok := categories[cat]; !ok {
		cat = "operations"
	}
	src, key := in.Source, in.Key
	var keyPtr *string
	if key != "" {
		keyPtr = &key
	}
	at := in.ViolationAt
	p, err := s.insertPenalty(ctx, q, payrolldb.InsertPenaltyParams{UserID: in.UserID, ClassID: in.ClassID, WorkTaskID: in.WorkTaskID,
		BigTestID: in.BigTestID, AutoSource: &src, AutoKey: keyPtr, ViolationType: in.ViolationType, ErrorCategory: &cat,
		ViolationDate: dbDay(at), ViolationAt: &at, Amount: max(0, in.Amount), Notes: trimPtr(&in.Notes)})
	if isNoRows(err) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	title := in.Title
	if title == "" {
		subject := in.Subject
		if subject == "" {
			subject = in.ViolationType
		}
		title = "Biên bản " + p.Code + ": " + subject
	}
	body := in.Body
	if body == "" {
		body = "Bạn là người phụ trách. Hãy xử lý và gửi giải trình; biên bản chờ CM / Học thuật / Admin xác nhận trước khi tính vào lương."
	}
	data := map[string]any{"penalty_id": p.ID, "sla_rule": src}
	for k, v := range in.Data {
		data[k] = v
	}
	if _, err := notify.Send(ctx, tx, []uuid.UUID{in.UserID}, notify.Notification{Type: "penalty_created", Title: title,
		Body: body, Link: "/penalties?search=" + p.Code, Data: data}); err != nil {
		return nil, err
	}
	if err := audit.Record(ctx, tx, audit.Entry{Module: audit.ModulePayroll, Event: audit.EventCreated, Action: "penalty.auto",
		SubjectType: "penalty", SubjectID: p.ID.String(), SubjectLabel: p.Code, Description: "Hệ thống tự lập biên bản " + p.Code + ": " + in.ViolationType,
		Properties: map[string]any{"user_id": in.UserID, "source": src}}); err != nil {
		return nil, err
	}
	return &p, nil
}

// AutoOccurrences: số biên bản tự động cùng nguồn của một người từ mốc since (bậc phạt theo lần tái phạm).
func (s *Service) AutoOccurrences(ctx context.Context, tx pgxTx, userID uuid.UUID, source string, since time.Time) (int, error) {
	n, err := s.q.WithTx(tx).CountAutoPenalties(ctx, payrolldb.CountAutoPenaltiesParams{UserID: userID, AutoSource: &source, Since: since})
	return int(n), err
}

// readEvidence: quyền mở bằng chứng — người xem mọi biên bản hoặc chính nhân sự vi phạm.
func (s *Service) readEvidence(ctx context.Context, a *iam.Access, f files.File) (string, error) {
	if f.SubjectID == nil {
		return "", forbidden("Bạn không có quyền xem bằng chứng này.")
	}
	p, err := s.q.GetPenalty(ctx, *f.SubjectID)
	if isNoRows(err) {
		return "", errPenaltyNotFound
	}
	if err != nil {
		return "", err
	}
	if err := s.visiblePenalty(ctx, s.q, a, p.ID); err != nil {
		return "", err
	}
	return "", nil
}

// ───────────── Xử lý biên bản ─────────────

func (s *Service) lockPenalty(ctx context.Context, q *payrolldb.Queries, a *iam.Access, id uuid.UUID) (payrolldb.HrPenalty, error) {
	if err := s.visiblePenalty(ctx, q, a, id); err != nil {
		return payrolldb.HrPenalty{}, err
	}
	p, err := q.LockPenalty(ctx, id)
	if isNoRows(err) {
		return p, errPenaltyNotFound
	}
	return p, err
}

// penaltyLocked: biên bản đã trừ trong kỳ lương đã duyệt / đã chi trả.
func (s *Service) penaltyLocked(ctx context.Context, q *payrolldb.Queries, p payrolldb.HrPenalty) error {
	locked := p.Status == "deducted"
	if !locked && p.PayrollRecordID != nil {
		row, err := q.GetPenalty(ctx, p.ID)
		if err != nil {
			return err
		}
		locked = row.PeriodStatus == "approved" || row.PeriodStatus == "paid"
	}
	if locked {
		return iam.Invalid("Biên bản " + p.Code + " đã được trừ trong kỳ lương đã duyệt/đã chi trả — không thể thay đổi.")
	}
	return nil
}

func (s *Service) penaltyAudit(ctx context.Context, tx pgxTx, a *iam.Access, p payrolldb.HrPenalty, action, desc string, ch audit.Changes) error {
	return audit.Record(ctx, tx, audit.Entry{ActorID: actorPtr(a), Module: audit.ModulePayroll, Action: action, SubjectType: "penalty",
		SubjectID: p.ID.String(), SubjectLabel: p.Code, Description: desc, Changes: ch})
}

func statusChange(from, to string) audit.Changes {
	return audit.Changes{"status": {Old: from, New: to}}
}

func (s *Service) ExplainPenalty(ctx context.Context, a *iam.Access, id uuid.UUID, explanation string) (string, error) {
	var msg string
	err := s.tx(ctx, func(tx pgxTx, q *payrolldb.Queries) error {
		p, err := s.lockPenalty(ctx, q, a, id)
		if err != nil {
			return err
		}
		if p.UserID != a.UserID {
			return forbidden("Chỉ nhân sự vi phạm mới được giải trình biên bản này.")
		}
		if p.Status != "pending" {
			return iam.Invalid("Biên bản không còn ở bước giải trình.")
		}
		text := strings.TrimSpace(explanation)
		switch {
		case text == "":
			return iam.Invalid("Vui lòng nhập nội dung giải trình.")
		case runeLen(text) < 10:
			return iam.Invalid("Nội dung giải trình cần ít nhất 10 ký tự.")
		case runeLen(text) > 2000:
			return iam.Invalid("Nội dung giải trình tối đa 2000 ký tự.")
		}
		now := s.now()
		if err := q.ExplainPenalty(ctx, payrolldb.ExplainPenaltyParams{Explanation: &text, At: &now, ID: id}); err != nil {
			return err
		}
		msg = fmt.Sprintf("Đã gửi giải trình biên bản %s — chờ %s chốt.", p.Code, confirmerLabel(p.ErrorCategory))
		return s.penaltyAudit(ctx, tx, a, p, "penalty.explain", "Giải trình biên bản "+p.Code, statusChange(p.Status, "explained"))
	})
	if err == nil {
		s.log.Info("penalty explained", "by", a.UserID, "penalty", id)
	}
	return msg, err
}

type DecisionInput struct {
	Decision     string  `json:"decision"` // fine | error
	Amount       *int64  `json:"amount"`
	DecisionNote *string `json:"decision_note"`
}

func (s *Service) DecidePenalty(ctx context.Context, a *iam.Access, id uuid.UUID, in DecisionInput) (string, error) {
	decision := "error"
	if in.Decision == "fine" {
		decision = "fine"
	}
	if !a.Can("violation.confirm_" + decision) {
		return "", forbidden("Bạn không có quyền chốt biên bản này.")
	}
	var msg string
	err := s.tx(ctx, func(tx pgxTx, q *payrolldb.Queries) error {
		p, err := s.lockPenalty(ctx, q, a, id)
		if err != nil {
			return err
		}
		if !canDecide(a, p.UserID, p.ErrorCategory) {
			return forbidden(fmt.Sprintf("Biên bản %s do %s chốt.", categoryLabel(p.ErrorCategory), confirmerLabel(p.ErrorCategory)))
		}
		if decision == "fine" && in.Amount == nil {
			return iam.Invalid("Vui lòng nhập số tiền phạt khi quyết phạt.")
		}
		if in.Amount != nil && *in.Amount < 1000 {
			return iam.Invalid("Số tiền phạt tối thiểu 1.000đ.")
		}
		note := trimPtr(in.DecisionNote)
		if note != nil && runeLen(*note) > 1000 {
			return iam.Invalid("Ghi chú quyết định tối đa 1000 ký tự.")
		}
		if !slices.Contains([]string{"pending", "explained", "confirmed"}, p.Status) {
			return iam.Invalid("Biên bản không ở trạng thái cho phép chốt.")
		}
		if note == nil {
			note = p.DecisionNote
		}
		now := s.now()
		params := payrolldb.DecidePenaltyParams{ID: id, Status: "confirmed", Amount: p.Amount, DueDate: p.DueDate, DecidedBy: actorPtr(a),
			At: &now, DecisionNote: note}
		days := s.paymentDueDays(ctx, q)
		if decision == "fine" {
			due := dbDay(s.today().AddDate(0, 0, days))
			params.Status, params.Amount, params.DueDate = "fined", *in.Amount, &due
		}
		if err := q.DecidePenalty(ctx, params); err != nil {
			return err
		}
		if decision == "fine" {
			dueLabel := dmy(*params.DueDate)
			if _, err := notify.Send(ctx, tx, []uuid.UUID{p.UserID}, notify.Notification{Type: "penalty_fined",
				Title: "Biên bản " + p.Code + ": phạt " + money(params.Amount),
				Body:  fmt.Sprintf("Nộp phạt trước hết ngày %s (%d ngày) — quá hạn chưa nộp sẽ trừ vào lương kỳ này.", dueLabel, days),
				Link:  "/penalties?search=" + p.Code, Data: map[string]any{"penalty_id": p.ID}, CreatedBy: actorPtr(a)}); err != nil {
				return err
			}
			msg = fmt.Sprintf("Đã quyết phạt %s: %s — hạn nộp %s, quá hạn chưa nộp sẽ trừ vào kỳ lương.", p.Code, money(params.Amount), dueLabel)
		} else {
			msg = "Đã xác nhận lỗi của biên bản " + p.Code + "!"
		}
		ch := statusChange(p.Status, params.Status)
		if params.Amount != p.Amount {
			ch["amount"] = audit.Change{Old: p.Amount, New: params.Amount}
		}
		return s.penaltyAudit(ctx, tx, a, p, "penalty.decide", "Chốt biên bản "+p.Code+" ("+map[string]string{"fine": "quyết phạt", "error": "xác nhận lỗi"}[decision]+")", ch)
	})
	if err == nil {
		s.log.Info("penalty decided", "by", a.UserID, "penalty", id, "decision", decision)
	}
	return msg, err
}

func (s *Service) MarkPenaltyPaid(ctx context.Context, a *iam.Access, id uuid.UUID) (string, error) {
	var msg string
	err := s.tx(ctx, func(tx pgxTx, q *payrolldb.Queries) error {
		p, err := s.lockPenalty(ctx, q, a, id)
		if err != nil {
			return err
		}
		if p.Status != "fined" {
			return iam.Invalid("Chỉ biên bản đã quyết phạt mới ghi nhận nộp phạt.")
		}
		if err := s.penaltyLocked(ctx, q, p); err != nil {
			return err
		}
		if isOverdue(p.Status, p.DueDate, s.today()) {
			return iam.Invalid(fmt.Sprintf("Quá hạn nộp phạt %d ngày — khoản phạt sẽ trừ vào lương kỳ này.", s.paymentDueDays(ctx, q)))
		}
		now := s.now()
		users, err := q.UsersBrief(ctx, []uuid.UUID{a.UserID})
		if err != nil {
			return err
		}
		by := ""
		if len(users) > 0 {
			by = users[0].FullName
		}
		line := "Nộp phạt trực tiếp ngày " + now.In(vnZone).Format("02/01/2006") + " bởi " + by
		notes := strings.TrimSpace(deref(p.Notes) + "\n" + line)
		if err := q.PayPenalty(ctx, payrolldb.PayPenaltyParams{At: &now, Notes: &notes, ID: id}); err != nil {
			return err
		}
		msg = "Đã ghi nhận nhân sự nộp phạt " + p.Code + " trực tiếp — không trừ vào bảng lương."
		return s.penaltyAudit(ctx, tx, a, p, "penalty.mark_paid", "Ghi nhận nộp phạt trực tiếp "+p.Code, statusChange(p.Status, "paid"))
	})
	if err == nil {
		s.log.Info("penalty paid", "by", a.UserID, "penalty", id)
	}
	return msg, err
}

func (s *Service) ResolvePenalty(ctx context.Context, a *iam.Access, id uuid.UUID) (string, error) {
	var msg string
	err := s.tx(ctx, func(tx pgxTx, q *payrolldb.Queries) error {
		p, err := s.lockPenalty(ctx, q, a, id)
		if err != nil {
			return err
		}
		if !slices.Contains(openStatuses, p.Status) {
			return iam.Invalid("Biên bản đã đóng.")
		}
		if err := s.penaltyLocked(ctx, q, p); err != nil {
			return err
		}
		if err := q.ResolvePenalty(ctx, id); err != nil {
			return err
		}
		msg = "Đã xử lý (miễn phạt) biên bản " + p.Code + "."
		return s.penaltyAudit(ctx, tx, a, p, "penalty.resolve", "Đóng biên bản "+p.Code+" (miễn phạt)", statusChange(p.Status, "resolved"))
	})
	if err == nil {
		s.log.Info("penalty resolved", "by", a.UserID, "penalty", id)
	}
	return msg, err
}

func (s *Service) RemedyPenalty(ctx context.Context, a *iam.Access, id uuid.UUID, note *string) (string, error) {
	var msg string
	err := s.tx(ctx, func(tx pgxTx, q *payrolldb.Queries) error {
		p, err := s.lockPenalty(ctx, q, a, id)
		if err != nil {
			return err
		}
		if (p.Status != "paid" && p.Status != "deducted") || p.RemediedAt != nil {
			return iam.Invalid("Chỉ biên bản đã nộp / đã trừ lương mới ghi nhận khắc phục.")
		}
		n := trimPtr(note)
		if n != nil && runeLen(*n) > 1000 {
			return iam.Invalid("Ghi chú khắc phục tối đa 1000 ký tự.")
		}
		now := s.now()
		if err := q.RemedyPenalty(ctx, payrolldb.RemedyPenaltyParams{At: &now, By: actorPtr(a), Note: n, ID: id}); err != nil {
			return err
		}
		msg = "Đã ghi nhận khắc phục biên bản " + p.Code + "."
		return s.penaltyAudit(ctx, tx, a, p, "penalty.remedy", "Ghi nhận khắc phục biên bản "+p.Code, nil)
	})
	if err == nil {
		s.log.Info("penalty remedied", "by", a.UserID, "penalty", id)
	}
	return msg, err
}

func (s *Service) CancelPenalty(ctx context.Context, a *iam.Access, id uuid.UUID) (string, error) {
	var msg string
	err := s.tx(ctx, func(tx pgxTx, q *payrolldb.Queries) error {
		p, err := s.lockPenalty(ctx, q, a, id)
		if err != nil {
			return err
		}
		if !slices.Contains([]string{"pending", "explained", "confirmed"}, p.Status) {
			return iam.Invalid("Biên bản đã vào vòng phạt không thể hủy — dùng Đóng vụ nếu cần miễn.")
		}
		if err := s.penaltyLocked(ctx, q, p); err != nil {
			return err
		}
		if err := q.CancelPenalty(ctx, id); err != nil {
			return err
		}
		msg = "Đã hủy bỏ biên bản vi phạm " + p.Code + "!"
		return s.penaltyAudit(ctx, tx, a, p, "penalty.cancel", "Hủy biên bản "+p.Code, statusChange(p.Status, "cancelled"))
	})
	if err == nil {
		s.log.Info("penalty cancelled", "by", a.UserID, "penalty", id)
	}
	return msg, err
}
