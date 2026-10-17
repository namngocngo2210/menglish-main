package payroll

import (
	"context"
	"encoding/json"
	"fmt"
	"slices"
	"strconv"
	"strings"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/payroll/payrolldb"
)

// Phiếu lương một người (PayrollRecord + PayrollController::showRecord / adjustRecord / updateRecord).

type userInfo struct {
	Name, Email, Code, Branch string
}

// details: calculation_details đã giải mã.
type details map[string]any

func detailsOf(raw []byte) details {
	d := details{}
	if len(raw) > 0 {
		_ = json.Unmarshal(raw, &d)
	}
	return d
}

// get: giá trị theo đường dẫn "a.b".
func (d details) get(path string) any {
	var cur any = map[string]any(d)
	for _, k := range strings.Split(path, ".") {
		m, ok := cur.(map[string]any)
		if !ok {
			return nil
		}
		cur = m[k]
	}
	return cur
}

func (d details) num(path string, def float64) float64 {
	if v, ok := d.get(path).(float64); ok {
		return v
	}
	return def
}

func (d details) list(path string) []any {
	if v, ok := d.get(path).([]any); ok {
		return v
	}
	return []any{}
}

func q3(r payrolldb.HrPayrollRecord) bool { return r.EmployeeType != nil }

func isPart(r payrolldb.HrPayrollRecord) bool { return deref(r.EmployeeType) == typeParttime }

func employeeTypeLabel(r payrolldb.HrPayrollRecord) string {
	if v, ok := typeLabels[deref(r.EmployeeType)]; ok {
		return v
	}
	return "Trước Q3"
}

func salaryRoleLabel(r payrolldb.HrPayrollRecord) string {
	if v, ok := salaryRoleLabels[deref(r.SalaryRole)]; ok {
		return v
	}
	return employeeTypeLabel(r)
}

// kpiState: trạng thái KPI của phiếu: done | pending | na | self (PayrollRecord::kpi_state).
func kpiState(r payrolldb.HrPayrollRecord) [2]string {
	if !q3(r) {
		return [2]string{"na", "Không áp dụng"}
	}
	src := deref(r.KpiSource)
	if src == kpiSelf {
		if r.KpiScore != nil {
			return [2]string{"self", "KPI " + percentLabel(*r.KpiScore) + "%"}
		}
		return [2]string{"self", ""}
	}
	var done *bool
	switch src {
	case kpiRetention:
		if r.RetentionBaseStudents != 0 {
			done = ptr(r.RetentionTier != nil)
		}
	case kpiAcademic:
		done = ptr(r.KpiScore != nil)
	case kpiManual:
		done = ptr(r.KpiManualAmount != nil)
	}
	if done != nil && *done && r.TeachingConcurrent && r.RetentionBaseStudents > 0 && r.RetentionTier == nil {
		done = ptr(false)
	}
	switch {
	case done == nil:
		return [2]string{"na", "Không áp dụng"}
	case *done:
		return [2]string{"done", "Đã chốt KPI"}
	}
	return [2]string{"pending", "Chưa chốt KPI"}
}

func grossOf(r payrolldb.HrPayrollRecord) int64 { p := paramsOf(r); return gross(&p) }

func deductionsOf(r payrolldb.HrPayrollRecord) int64 { p := paramsOf(r); return deductions(&p) }

// Line: một dòng cộng / trừ trên phiếu lương.
type Line struct {
	Key      string  `json:"key"`
	Label    string  `json:"label"`
	Amount   int64   `json:"amount"`
	Hint     *string `json:"hint"`
	Quantity string  `json:"quantity,omitempty"`
}

func commissionLine(r payrolldb.HrPayrollRecord) Line {
	var hint []string
	if r.CommissionBase > 0 {
		hint = append(hint, "Căn cứ thực thu "+money(r.CommissionBase))
	}
	if r.CommissionDeferred > 0 {
		hint = append(hint, "Hoãn sang kỳ sau "+money(r.CommissionDeferred))
	}
	l := Line{Key: "commission_bonus", Label: "Hoa hồng tuyển sinh (khách mới)", Amount: r.CommissionBonus}
	if len(hint) > 0 {
		l.Hint = ptr(strings.Join(hint, " · "))
	}
	return l
}

func i32s(v int32) string { return strconv.Itoa(int(v)) }

// earningLines: các khoản cộng (PayrollRecord::earningLines).
func earningLines(r payrolldb.HrPayrollRecord) []Line {
	d := detailsOf(r.CalculationDetails)
	if !q3(r) {
		return legacyEarningLines(r)
	}
	var lines []Line
	if isPart(r) {
		lines = append(lines, Line{Key: "teaching_salary", Label: "Lương buổi dạy (" + i32s(r.TeachingSessions) + " buổi)", Amount: r.TeachingSalary,
			Hint: ptr("Số buổi chấm công hợp lệ × đơn giá buổi riêng của GV hiệu lực tại ngày dạy")})
		hint := i32s(r.RetentionStudents) + "/" + i32s(r.RetentionBaseStudents) + " HS giữ được — chưa chọn bậc KPI"
		if r.RetentionTier != nil {
			hint = i32s(r.RetentionStudents) + "/" + i32s(r.RetentionBaseStudents) + " HS giữ được × " + money(*r.RetentionTier) + "/HS"
		}
		lines = append(lines, Line{Key: "kpi_bonus", Label: "KPI giữ học sinh", Amount: r.KpiBonus, Hint: &hint})
		fh := "Kế toán nhập tay"
		if r.ForeignTeacherSessionsCount > 0 {
			fh += " · " + i32s(r.ForeignTeacherSessionsCount) + " buổi có GVNN cùng lớp trong kỳ"
		}
		lines = append(lines, Line{Key: "foreign_session_pay", Label: "Buổi có GVNN (chờ BA chốt)", Amount: r.ForeignSessionPay, Hint: &fh})
	} else {
		lines = append(lines, Line{Key: "base_salary", Label: "Lương cơ bản", Amount: r.BaseSalary})
		src := deref(r.KpiSource)
		if src == kpiSelf {
			if r.KpiScore != nil {
				lines = append(lines, Line{Key: "kpi_bonus", Label: "KPI", Amount: r.KpiBonus,
					Hint: ptr(percentLabel(*r.KpiScore) + "% KPI của bản thân (chỉ để xem, Admin không có tiền KPI)")})
			}
		} else {
			label := "KPI"
			if src == kpiAcademic {
				label = "KPI Học vụ (6 nhóm / 15 mục)"
			}
			var hint *string
			switch src {
			case kpiAcademic:
				if r.KpiScore != nil {
					hint = ptr("Quỹ " + money(int64(d.num("kpi.fund", 0))) + " × " + percentLabel(*r.KpiScore) + "% điểm KPI tháng")
				} else {
					hint = ptr("Chưa có đánh giá KPI tháng đã chốt")
				}
			case kpiManual:
				if r.KpiManualAmount != nil {
					hint = ptr("Nhập tay")
				} else {
					hint = ptr("Chưa nhập KPI")
				}
			}
			lines = append(lines, Line{Key: "kpi_bonus", Label: label, Amount: r.KpiBonus, Hint: hint})
		}
		if r.TeachingConcurrent {
			h := "Kiêm nhiệm giảng dạy: mỗi buổi chấm công hợp lệ × % học phí theo buổi của HS trong lớp"
			if v, ok := d.get("teaching_share.default_percent").(float64); ok {
				h += " (mặc định " + percentLabel(v) + "%)"
			}
			lines = append(lines, Line{Key: "teaching_salary", Label: "Lương đứng lớp (" + i32s(r.TeachingSessions) + " buổi)", Amount: r.TeachingSalary, Hint: &h})
			kh := i32s(r.RetentionStudents) + "/" + i32s(r.RetentionBaseStudents) + " HS giữ được"
			if r.RetentionTier != nil {
				kh += " × " + money(*r.RetentionTier) + "/HS"
			} else {
				kh += " — chưa chọn bậc KPI"
			}
			lines = append(lines, Line{Key: "teaching_kpi_bonus", Label: "KPI kiêm nhiệm giảng dạy", Amount: r.TeachingKpiBonus, Hint: &kh})
		}
		if deref(r.SalaryRole) == "sales" || r.CommissionBonus != 0 || r.CommissionDeferred != 0 {
			lines = append(lines, commissionLine(r))
		}
		if r.RenewBonus != 0 || len(d.list("renewal.classes")) > 0 {
			lines = append(lines, Line{Key: "renew_bonus", Label: "Thưởng tái tục", Amount: r.RenewBonus,
				Hint: ptr("% theo số HS nghỉ của lớp phụ trách × doanh thu lớp trong kỳ")})
		}
	}
	if isPart(r) && (r.CommissionBonus != 0 || r.CommissionDeferred != 0) {
		lines = append(lines, commissionLine(r))
	}
	for i, l := range linesOf(parseManualLines(r.ManualLines), "earning") {
		lines = append(lines, Line{Key: "manual_earning_" + strconv.Itoa(i), Label: l.Label, Amount: l.Amount, Hint: ptr("Khoản cộng tự do")})
	}
	if r.OtherBonus != 0 {
		lines = append(lines, Line{Key: "other_bonus", Label: "Thưởng / cộng khác", Amount: r.OtherBonus})
	}
	return lines
}

func rateLabel(d details, which string, st Settings) string {
	def := st.InsuranceRate
	if which == "union" {
		def = st.UnionRate
	}
	return percentLabel(d.num("rates."+which, def))
}

// deductionLines: các khoản trừ (PayrollRecord::deductionLines).
func deductionLines(r payrolldb.HrPayrollRecord, st Settings) []Line {
	if !q3(r) {
		return legacyDeductionLines(r)
	}
	d := detailsOf(r.CalculationDetails)
	var lines []Line
	if !isPart(r) {
		lines = append(lines,
			Line{Key: "insurance_deduction", Label: "BHXH (" + rateLabel(d, "insurance", st) + "% lương cơ bản)", Amount: r.InsuranceDeduction,
				Hint: ptr("Trên lương cơ bản " + groupThousands(r.BaseSalary) + "đ")},
			Line{Key: "union_deduction", Label: "Công đoàn (" + rateLabel(d, "union", st) + "% lương cơ bản)", Amount: r.UnionDeduction},
			Line{Key: "tax_deduction", Label: "Thuế TNCN", Amount: r.TaxDeduction, Hint: ptr("Admin / Kế toán nhập tay")})
	}
	lines = append(lines, Line{Key: "penalty_deduction", Label: "Phạt vi phạm (quá hạn nộp)", Amount: r.PenaltyDeduction})
	if r.CommissionClawback != 0 {
		lines = append(lines, Line{Key: "commission_clawback", Label: "Thu hồi hoa hồng (hoàn phí / hủy hóa đơn)", Amount: r.CommissionClawback})
	}
	if r.ForeignTeacherDeduction != 0 {
		lines = append(lines, Line{Key: "foreign_teacher_deduction", Label: "Giảm trừ ca có GVNN (dữ liệu cũ)", Amount: r.ForeignTeacherDeduction})
	}
	for i, l := range linesOf(parseManualLines(r.ManualLines), "deduction") {
		lines = append(lines, Line{Key: "manual_deduction_" + strconv.Itoa(i), Label: l.Label, Amount: l.Amount, Hint: ptr("Khoản trừ tự do")})
	}
	return lines
}

func legacyEarningLines(r payrolldb.HrPayrollRecord) []Line {
	hours := strings.TrimRight(strings.TrimRight(strconv.FormatFloat(r.ActualHours, 'f', 2, 64), "0"), ".")
	lines := []Line{
		{Key: "base_salary", Label: "Lương cơ bản / cứng", Amount: r.BaseSalary},
		{Key: "teaching_salary", Label: "Thù lao giờ dạy (" + hours + "h)", Amount: r.TeachingSalary, Hint: ptr("Theo chấm công hợp lệ × đơn giá hiệu lực tại ngày dạy")},
		{Key: "kpi_bonus", Label: "Thưởng KPI", Amount: r.KpiBonus},
		{Key: "allowance", Label: "Phụ cấp", Amount: r.Allowance},
	}
	c := Line{Key: "commission_bonus", Label: "Hoa hồng tuyển sinh (khách mới)", Amount: r.CommissionBonus}
	if r.CommissionBase > 0 {
		c.Hint = ptr("Căn cứ thực thu " + money(r.CommissionBase))
	}
	lines = append(lines, c)
	if r.RenewBonus != 0 {
		lines = append(lines, Line{Key: "renew_bonus", Label: "Thưởng tái tục", Amount: r.RenewBonus})
	}
	return append(lines, Line{Key: "other_bonus", Label: "Thưởng / cộng khác", Amount: r.OtherBonus})
}

func legacyDeductionLines(r payrolldb.HrPayrollRecord) []Line {
	return []Line{
		{Key: "insurance_deduction", Label: "Bảo hiểm xã hội & y tế", Amount: r.InsuranceDeduction},
		{Key: "tax_deduction", Label: "Thuế TNCN", Amount: r.TaxDeduction},
		{Key: "penalty_deduction", Label: "Phạt vi phạm (quá hạn nộp)", Amount: r.PenaltyDeduction},
		{Key: "foreign_teacher_deduction", Label: "Giảm trừ ca có GVNN cùng dạy (" + i32s(r.ForeignTeacherSessionsCount) + " buổi)", Amount: r.ForeignTeacherDeduction},
		{Key: "commission_clawback", Label: "Thu hồi hoa hồng (hoàn phí)", Amount: r.CommissionClawback},
		{Key: "other_deduction", Label: "Khấu trừ khác", Amount: r.OtherDeduction},
	}
}

// RecordRow: một dòng bảng lương (PayrollController::recordRow).
type RecordRow struct {
	ID                   uuid.UUID `json:"id"`
	UserID               uuid.UUID `json:"user_id"`
	Name                 string    `json:"name"`
	Email                string    `json:"email"`
	EmployeeCode         string    `json:"employee_code"`
	Branch               string    `json:"branch"`
	Department           string    `json:"department"`
	SalaryRole           string    `json:"salary_role"`
	IsPartTime           bool      `json:"is_part_time"`
	EmployeeTypeLabel    string    `json:"employee_type_label"`
	SalaryRoleLabel      string    `json:"salary_role_label"`
	KpiState             [2]string `json:"kpi_state"`
	KpiSource            *string   `json:"kpi_source"`
	KpiScore             *float64  `json:"kpi_score"`
	KpiManualAmount      *int64    `json:"kpi_manual_amount"`
	KpiBonus             int64     `json:"kpi_bonus"`
	RetentionStudents    int32     `json:"retention_students"`
	RetentionBase        int32     `json:"retention_base_students"`
	RetentionTier        *int64    `json:"retention_tier"`
	BaseSalary           int64     `json:"base_salary"`
	TeachingSalary       int64     `json:"teaching_salary"`
	TeachingSessions     int32     `json:"teaching_sessions"`
	ActualHours          float64   `json:"actual_hours"`
	TeachingConcurrent   bool      `json:"teaching_concurrent"`
	TeachingKpiBonus     int64     `json:"teaching_kpi_bonus"`
	ForeignSessionPay    int64     `json:"foreign_session_pay"`
	ForeignSessionsCount int32     `json:"foreign_teacher_sessions_count"`
	AdjustmentNotes      *string   `json:"adjustment_notes"`
	CommissionBonus      int64     `json:"commission_bonus"`
	CommissionDeferred   int64     `json:"commission_deferred"`
	RenewBonus           int64     `json:"renew_bonus"`
	FreeAllowance        int64     `json:"free_allowance"`
	InsuranceDeduction   int64     `json:"insurance_deduction"`
	UnionDeduction       int64     `json:"union_deduction"`
	TaxDeduction         int64     `json:"tax_deduction"`
	OtherDeductions      int64     `json:"other_deductions"`
	GrossIncome          int64     `json:"gross_income"`
	TotalDeductions      int64     `json:"total_deductions"`
	NetSalary            int64     `json:"net_salary"`
}

func recordRow(r payrolldb.HrPayrollRecord, u userInfo) RecordRow {
	return RecordRow{
		ID: r.ID, UserID: r.UserID, Name: u.Name, Email: u.Email, EmployeeCode: u.Code, Branch: u.Branch,
		Department: deref(r.Department), SalaryRole: deref(r.SalaryRole), IsPartTime: isPart(r),
		EmployeeTypeLabel: employeeTypeLabel(r), SalaryRoleLabel: salaryRoleLabel(r), KpiState: kpiState(r), KpiSource: r.KpiSource,
		KpiScore: r.KpiScore, KpiManualAmount: r.KpiManualAmount, KpiBonus: r.KpiBonus, RetentionStudents: r.RetentionStudents,
		RetentionBase: r.RetentionBaseStudents, RetentionTier: r.RetentionTier, BaseSalary: r.BaseSalary, TeachingSalary: r.TeachingSalary,
		TeachingSessions: r.TeachingSessions, ActualHours: r.ActualHours, TeachingConcurrent: r.TeachingConcurrent,
		TeachingKpiBonus: r.TeachingKpiBonus, ForeignSessionPay: r.ForeignSessionPay, ForeignSessionsCount: r.ForeignTeacherSessionsCount,
		AdjustmentNotes: r.AdjustmentNotes, CommissionBonus: r.CommissionBonus, CommissionDeferred: r.CommissionDeferred,
		RenewBonus: r.RenewBonus, FreeAllowance: r.Allowance + r.OtherBonus, InsuranceDeduction: r.InsuranceDeduction,
		UnionDeduction: r.UnionDeduction, TaxDeduction: r.TaxDeduction,
		OtherDeductions: r.PenaltyDeduction + r.CommissionClawback + r.OtherDeduction + r.ForeignTeacherDeduction,
		GrossIncome:     grossOf(r), TotalDeductions: deductionsOf(r), NetSalary: r.NetSalary,
	}
}

// Variant: mẫu phiếu theo loại nhân sự (payslipVariant).
type Variant struct {
	Key   string `json:"key"`
	Title string `json:"title"`
	Type  string `json:"type"`
}

func variantOf(r payrolldb.HrPayrollRecord) Variant {
	switch {
	case !q3(r):
		return Variant{"legacy", "Chi tiết bảng lương", "Phiếu trước Q3"}
	case isPart(r):
		return Variant{"parttime", "Chi tiết bảng lương GV Part-time", "Giáo viên (Part-time)"}
	case deref(r.SalaryRole) == "academic_staff":
		return Variant{"academic_staff", "Chi tiết bảng lương Học vụ", "Học vụ (Full-time)"}
	case deref(r.SalaryRole) == "academic_lead":
		return Variant{"academic_lead", "Chi tiết bảng lương Học thuật", "Học thuật (Full-time)"}
	case deref(r.SalaryRole) == "teacher_fulltime":
		return Variant{"fulltime", "Chi tiết bảng lương GV Full-time", "Giáo viên (Full-time)"}
	}
	l := salaryRoleLabel(r)
	return Variant{"fulltime", "Chi tiết bảng lương " + l, l + " (Full-time)"}
}

// visible: phiếu của userID thuộc phạm vi xem 'payroll' của người xem (Chi nhánh gồm cả phiếu của chính mình).
func (s *Service) visible(ctx context.Context, q *payrolldb.Queries, a *iam.Access, userID uuid.UUID) (bool, error) {
	sc := scopeOf(a)
	if sc.all || userID == a.UserID {
		return true, nil
	}
	rows, err := q.UserBranchIDs(ctx, []uuid.UUID{userID})
	if err != nil {
		return false, err
	}
	for _, r := range rows {
		if slices.Contains(sc.branches, r.BranchID) {
			return true, nil
		}
	}
	return false, nil
}

func (s *Service) scopedRecord(ctx context.Context, q *payrolldb.Queries, a *iam.Access, id uuid.UUID) (payrolldb.GetRecordRow, error) {
	r, err := q.GetRecord(ctx, id)
	if err != nil {
		if isNoRows(err) {
			return r, errRecordNotFound
		}
		return r, err
	}
	ok, err := s.visible(ctx, q, a, r.HrPayrollRecord.UserID)
	if err != nil {
		return r, err
	}
	if !ok {
		return r, errRecordNotFound
	}
	return r, nil
}

// PeriodData: thông tin kỳ dùng chung (periodData).
type PeriodData struct {
	ID          uuid.UUID `json:"id"`
	Code        string    `json:"code"`
	Title       string    `json:"title"`
	Month       int       `json:"month"`
	Year        int       `json:"year"`
	StartDate   string    `json:"start_date"`
	EndDate     string    `json:"end_date"`
	Status      string    `json:"status"`
	StatusLabel string    `json:"status_label"`
	StatusColor string    `json:"status_color"`
	Locked      bool      `json:"locked"`
}

var periodStatusLabels = map[string]string{"draft": "Đang tính", "reviewing": "Đang soát", "approved": "Đã duyệt", "paid": "Đã trả"}
var periodStatusColors = map[string]string{"draft": "warning", "reviewing": "info", "approved": "success", "paid": "primary"}

func periodData(p payrolldb.HrPayrollPeriod) PeriodData {
	return PeriodData{ID: p.ID, Code: p.Code, Title: p.Title, Month: int(p.Month), Year: int(p.Year),
		StartDate: asDay(p.StartDate).Format("2006-01-02"), EndDate: asDay(p.EndDate).Format("2006-01-02"), Status: p.Status,
		StatusLabel: periodStatusLabels[p.Status], StatusColor: periodStatusColors[p.Status], Locked: locked(p.Status)}
}

// ───────────── Màn phiếu lương ─────────────

type CommissionItemRow struct {
	ID             uuid.UUID `json:"id"`
	Student        *string   `json:"student"`
	ReceiptNumber  string    `json:"receipt_number"`
	Earned         string    `json:"earned"`
	ClosingRank    int32     `json:"closing_rank"`
	ClosedMonth    *string   `json:"closed_month"`
	DeferredReason *string   `json:"deferred_reason"`
	BaseAmount     int64     `json:"base_amount"`
	Percent        float64   `json:"percent"`
	Amount         int64     `json:"amount"`
}

type TimesheetRow struct {
	ID            uuid.UUID `json:"id"`
	Date          string    `json:"date"`
	ScheduledTime string    `json:"scheduled_time"`
	Class         string    `json:"class"`
	Type          string    `json:"type"`
	TypeLabel     string    `json:"type_label"`
	Hours         float64   `json:"hours"`
	Rate          float64   `json:"rate"`
	Unit          string    `json:"unit"`
	Amount        float64   `json:"amount"`
}

var timesheetTypeLabels = map[string]string{"regular": "Chính khóa", "sub": "Dạy thay", "1on1": "Kèm 1-1", "grading": "Chấm bài", "workshop": "Workshop"}

type RecordDetail struct {
	RecordRow
	UsesQ3         bool         `json:"uses_q3"`
	IsFullTime     bool         `json:"is_full_time"`
	RetentionLost  int          `json:"retention_lost"`
	ClosedCount    int32        `json:"commission_closed_count"`
	Percent        *float64     `json:"commission_percent"`
	KpiFund        float64      `json:"kpi_fund"`
	RateInsurance  float64      `json:"rate_insurance"`
	RateUnion      float64      `json:"rate_union"`
	Notes          *string      `json:"notes"`
	ManualLines    []ManualLine `json:"manual_lines"`
	EarningLines   []Line       `json:"earning_lines"`
	DeductionLines []Line       `json:"deduction_lines"`
}

type RecordPage struct {
	Record             RecordDetail        `json:"record"`
	Period             PeriodData          `json:"period"`
	Variant            Variant             `json:"variant"`
	CanEdit            bool                `json:"can_edit"`
	Lines              []map[string]any    `json:"lines"`
	RetentionTiers     []int64             `json:"retention_tiers"`
	CurrentRate        *map[string]any     `json:"current_rate"`
	LostStudents       string              `json:"lost_students"`
	TeachingShare      map[string]any      `json:"teaching_share"`
	Timesheets         []TimesheetRow      `json:"timesheets"`
	AttendancePending  bool                `json:"attendance_pending"`
	KpiGroups          []map[string]any    `json:"kpi_groups"`
	RenewalClasses     []any               `json:"renewal_classes"`
	LateLines          []any               `json:"late_lines"`
	LateInfo           map[string]any      `json:"late_info"`
	DailyAttendance    any                 `json:"daily_attendance"`
	CommissionTiers    []map[string]any    `json:"commission_tiers"`
	Penalties          []map[string]any    `json:"penalties"`
	Clawbacks          []map[string]any    `json:"clawbacks"`
	PaidCommission     []CommissionItemRow `json:"paid_commission"`
	DeferredCommission []CommissionItemRow `json:"deferred_commission"`
}

func itemRow(id uuid.UUID, student *string, receipt string, earned, closedAt *time.Time, rank int32, reason *string, base int64, pct float64, amount int64) CommissionItemRow {
	row := CommissionItemRow{ID: id, Student: student, ReceiptNumber: receipt, ClosingRank: rank, DeferredReason: reason,
		BaseAmount: base, Percent: pct, Amount: amount}
	if earned != nil {
		row.Earned = asDay(*earned).Format("01/2006")
	}
	if closedAt != nil {
		row.ClosedMonth = ptr(dayOf(*closedAt).Format("01/2006"))
	}
	return row
}

// timesheetRows: ca dạy hợp lệ của một người trong kỳ (đơn giá cơ bản của ca).
func (s *Service) timesheetRows(ctx context.Context, q *payrolldb.Queries, userID uuid.UUID, p payrolldb.HrPayrollPeriod) ([]TimesheetRow, error) {
	out := []TimesheetRow{}
	sheets, err := s.attendance.ValidTimesheets(ctx, asDay(p.StartDate), asDay(p.EndDate))
	if err != nil {
		return nil, err
	}
	var mine []Timesheet
	classIDs := []uuid.UUID{}
	for _, ts := range sheets {
		if ts.UserID == userID {
			mine = append(mine, ts)
			if ts.ClassID != nil {
				classIDs = append(classIDs, *ts.ClassID)
			}
		}
	}
	if len(mine) == 0 {
		return out, nil
	}
	staff, err := q.StaffForPayroll(ctx, []uuid.UUID{userID})
	if err != nil {
		return nil, err
	}
	var m staffMember
	for _, u := range staff {
		if u.ID == userID {
			m = memberFromPayroll(u)
		}
	}
	rates, err := q.HourlyRatesFor(ctx, []uuid.UUID{userID})
	if err != nil {
		return nil, err
	}
	book := newRateBook(rates)
	info, err := q.ClassInfo(ctx, classIDs)
	if err != nil {
		return nil, err
	}
	classes := map[uuid.UUID]payrolldb.ClassInfoRow{}
	for _, c := range info {
		classes[c.ID] = c
	}
	slices.SortStableFunc(mine, func(a, b Timesheet) int { return asDay(a.Date).Compare(asDay(b.Date)) })
	for _, ts := range mine {
		amount, unit, rate := basePay(ts, m, book)
		row := TimesheetRow{ID: ts.ID, Date: asDay(ts.Date).Format("02/01/2006"), ScheduledTime: ts.ScheduledTime, Type: ts.Type,
			TypeLabel: timesheetTypeLabels[ts.Type], Hours: ts.Hours, Rate: rate, Unit: unit, Amount: amount}
		if ts.ClassID != nil {
			c := classes[*ts.ClassID]
			row.Class = c.Code
			if row.Class == "" {
				row.Class = c.Name
			}
		}
		out = append(out, row)
	}
	return out, nil
}

// Record: màn phiếu lương một người.
func (s *Service) Record(ctx context.Context, a *iam.Access, id uuid.UUID) (*RecordPage, error) {
	row, err := s.scopedRecord(ctx, s.q, a, id)
	if err != nil {
		return nil, err
	}
	r := row.HrPayrollRecord
	p, err := s.q.GetPeriod(ctx, r.PeriodID)
	if err != nil {
		return nil, err
	}
	st, err := s.settings(ctx, s.q)
	if err != nil {
		return nil, err
	}
	d := detailsOf(r.CalculationDetails)
	rid := r.ID
	u := userInfo{Name: row.FullName, Email: row.UserEmail, Code: row.UserCode, Branch: row.BranchName}
	page := &RecordPage{
		Record: RecordDetail{RecordRow: recordRow(r, u), UsesQ3: q3(r), IsFullTime: deref(r.EmployeeType) == typeFulltime,
			RetentionLost: int(d.num("retention.lost", 0)), ClosedCount: r.CommissionClosedCount, Percent: r.CommissionPercent,
			KpiFund: d.num("kpi.fund", float64(st.AcademicFund)), RateInsurance: d.num("rates.insurance", st.InsuranceRate),
			RateUnion: d.num("rates.union", st.UnionRate), Notes: r.Notes, ManualLines: parseManualLines(r.ManualLines),
			EarningLines: earningLines(r), DeductionLines: deductionLines(r, st)},
		Period: periodData(p), Variant: variantOf(r), RetentionTiers: st.RetentionTiers,
		RenewalClasses: d.list("renewal.classes"), LateLines: d.list("late.lines"),
		LateInfo: map[string]any{"threshold": d.num("late.threshold", float64(defLateThreshold)), "per_minute": d.num("late.per_minute", defLatePerMinute),
			"total": d.num("late.total_deduction", 0)},
		DailyAttendance: d.get("attendance"), AttendancePending: s.attendance.Pending(),
		KpiGroups: []map[string]any{}, CommissionTiers: []map[string]any{}, Penalties: []map[string]any{}, Clawbacks: []map[string]any{},
		PaidCommission: []CommissionItemRow{}, DeferredCommission: []CommissionItemRow{},
	}
	page.CanEdit = !locked(p.Status) && a.Can(PermEdit)
	lines := []map[string]any{}
	for _, l := range page.Record.ManualLines {
		lines = append(lines, map[string]any{"kind": l.Kind, "label": l.Label, "amount": l.Amount})
	}
	if page.CanEdit && len(lines) == 0 {
		// Gợi ý các khoản phụ cấp theo mẫu phiếu (khoản 0đ không được lưu).
		var names []string
		switch {
		case isPart(r):
			names = []string{"Hỗ trợ thỏa thuận", "Phụ cấp gửi xe", "Thưởng khác"}
		case page.Variant.Key == "academic_lead" && !r.TeachingConcurrent:
			names = []string{"Lương giảng dạy", "Hỗ trợ"}
		}
		for _, n := range names {
			lines = append(lines, map[string]any{"kind": "earning", "label": n, "amount": ""})
		}
	}
	page.Lines = lines

	if rt := newRateBook(must(s.q.HourlyRatesFor(ctx, []uuid.UUID{r.UserID}))).effective(r.UserID, asDay(p.EndDate)); rt != nil {
		cr := map[string]any{"rate": rt.Rate, "unit_label": rateUnitLabels[rt.RateUnit], "effective_from": asDay(rt.EffectiveFrom).Format("2006-01-02"),
			"is_tuition_share": rt.RateUnit == "tuition"}
		page.CurrentRate = &cr
	}
	if ids := d.list("retention.lost_ids"); len(ids) > 0 {
		var uids []uuid.UUID
		for _, v := range ids {
			if sv, ok := v.(string); ok {
				if u, err := uuid.Parse(sv); err == nil {
					uids = append(uids, u)
				}
			}
		}
		names, err := s.q.StudentNames(ctx, uids)
		if err != nil {
			return nil, err
		}
		var ns []string
		for _, n := range names {
			ns = append(ns, n.FullName)
		}
		page.LostStudents = strings.Join(ns, ", ")
	}
	if r.TeachingConcurrent {
		ts := map[string]any{"classes": d.list("teaching_share.classes"),
			"default_percent": d.num("teaching_share.default_percent", st.TeachingShare), "skipped": d.num("teaching_share.skipped", 0),
			"rate_percent": nil}
		if page.CurrentRate != nil && (*page.CurrentRate)["is_tuition_share"] == true {
			ts["rate_percent"] = (*page.CurrentRate)["rate"]
		}
		page.TeachingShare = ts
	}
	if page.Timesheets, err = s.timesheetRows(ctx, s.q, r.UserID, p); err != nil {
		return nil, err
	}
	// Nhóm KPI Học vụ.
	groups := map[string]map[string]any{}
	var order []string
	for _, it := range d.list("kpi.items") {
		m, _ := it.(map[string]any)
		g, _ := m["group"].(string)
		grp, ok := groups[g]
		if !ok {
			grp = map[string]any{"group": g, "weight": 0.0, "amount": 0.0, "items": []map[string]any{}}
			groups[g] = grp
			order = append(order, g)
		}
		w, _ := m["weight"].(float64)
		am, _ := m["amount"].(float64)
		grp["weight"] = grp["weight"].(float64) + w
		grp["amount"] = grp["amount"].(float64) + am
		grp["items"] = append(grp["items"].([]map[string]any), m)
	}
	for _, g := range order {
		page.KpiGroups = append(page.KpiGroups, groups[g])
	}
	if deref(r.SalaryRole) == "sales" || r.CommissionBonus > 0 || r.CommissionDeferred > 0 {
		tiers, err := s.q.TiersAt(ctx, ptr(p.EndDate))
		if err != nil {
			return nil, err
		}
		n := r.CommissionClosedCount
		for _, t := range tiers {
			applied := n > 0 && t.MinStudents <= n && (t.MaxStudents == nil || *t.MaxStudents >= n)
			page.CommissionTiers = append(page.CommissionTiers, map[string]any{"id": t.ID, "name": t.TierName, "range": rangeLabel(t),
				"percent": t.NewSalePercent, "applied": applied})
		}
	}
	pens, err := s.q.RecordPenalties(ctx, &rid)
	if err != nil {
		return nil, err
	}
	for _, x := range pens {
		page.Penalties = append(page.Penalties, map[string]any{"id": x.ID, "code": x.Code, "violation_type": x.ViolationType,
			"violation_date": dmy(x.ViolationDate), "due_date": dmyp(x.DueDate), "amount": x.Amount})
	}
	adjs, err := s.q.RecordAdjustments(ctx, &rid)
	if err != nil {
		return nil, err
	}
	for _, x := range adjs {
		page.Clawbacks = append(page.Clawbacks, map[string]any{"id": x.ID, "reason": x.Reason, "amount": x.Amount})
	}
	paid, err := s.q.RecordItems(ctx, &rid)
	if err != nil {
		return nil, err
	}
	for _, it := range paid {
		page.PaidCommission = append(page.PaidCommission, itemRow(it.ID, it.StudentName, it.ReceiptCode, &it.EarnedPeriodStart, it.ClosedAt,
			it.ClosingRank, it.DeferredReason, it.BaseAmount, it.Percent, it.Amount))
	}
	var deferredIDs []uuid.UUID
	for _, v := range d.list("commission.deferred") {
		if m, ok := v.(map[string]any); ok {
			if sv, ok := m["id"].(string); ok {
				if u, err := uuid.Parse(sv); err == nil {
					deferredIDs = append(deferredIDs, u)
				}
			}
		}
	}
	if len(deferredIDs) > 0 {
		items, err := s.q.ItemsByIDs(ctx, deferredIDs)
		if err != nil {
			return nil, err
		}
		for _, it := range items {
			page.DeferredCommission = append(page.DeferredCommission, itemRow(it.ID, it.StudentName, it.ReceiptCode, &it.EarnedPeriodStart,
				it.ClosedAt, it.ClosingRank, it.DeferredReason, it.BaseAmount, it.Percent, it.Amount))
		}
	}
	return page, nil
}

func must[T any](v T, _ error) T { return v }

// ───────────── Nhập tay ─────────────

// AdjustInput: khoản nhập tay của phiếu (adjustRecord).
type AdjustInput struct {
	RetentionTier     *float64 `json:"retention_tier"`
	KpiManualAmount   *float64 `json:"kpi_manual_amount"`
	ForeignSessionPay *float64 `json:"foreign_session_pay"`
	TaxDeduction      *float64 `json:"tax_deduction"`
	Lines             []struct {
		Kind   *string  `json:"kind"`
		Label  *string  `json:"label"`
		Amount *float64 `json:"amount"`
	} `json:"lines"`
	AdjustmentNotes *string `json:"adjustment_notes"`
}

func recordAuditMap(r payrolldb.HrPayrollRecord) map[string]any {
	return map[string]any{"retention_tier": r.RetentionTier, "kpi_manual_amount": r.KpiManualAmount, "foreign_session_pay": r.ForeignSessionPay,
		"tax_deduction": r.TaxDeduction, "manual_lines": string(r.ManualLines), "adjustment_notes": r.AdjustmentNotes, "net_salary": r.NetSalary}
}

func (s *Service) lockedRecord(ctx context.Context, q *payrolldb.Queries, a *iam.Access, id uuid.UUID, lockedMsg string) (payrolldb.HrPayrollRecord, payrolldb.HrPayrollPeriod, string, error) {
	row, err := s.scopedRecord(ctx, q, a, id)
	if err != nil {
		return payrolldb.HrPayrollRecord{}, payrolldb.HrPayrollPeriod{}, "", err
	}
	p, err := q.LockPeriod(ctx, row.HrPayrollRecord.PeriodID)
	if err != nil {
		return payrolldb.HrPayrollRecord{}, p, "", err
	}
	if locked(p.Status) {
		return payrolldb.HrPayrollRecord{}, p, "", iam.Invalid(lockedMsg)
	}
	r, err := q.LockRecord(ctx, id)
	return r, p, row.FullName, err
}

func (s *Service) saveInputs(ctx context.Context, q *payrolldb.Queries, r payrolldb.HrPayrollRecord) (payrolldb.HrPayrollRecord, error) {
	pr := paramsOf(r)
	applyManualInputs(&pr)
	saved, err := q.UpdateRecordInputs(ctx, payrolldb.UpdateRecordInputsParams{
		RetentionTier: pr.RetentionTier, KpiManualAmount: pr.KpiManualAmount, ForeignSessionPay: pr.ForeignSessionPay,
		TaxDeduction: pr.TaxDeduction, ManualLines: pr.ManualLines, AdjustmentNotes: pr.AdjustmentNotes, Notes: pr.Notes,
		Allowance: pr.Allowance, OtherDeduction: pr.OtherDeduction, KpiBonus: pr.KpiBonus, TeachingKpiBonus: pr.TeachingKpiBonus,
		InsuranceDeduction: pr.InsuranceDeduction, UnionDeduction: pr.UnionDeduction, NetSalary: pr.NetSalary, ID: r.ID,
	})
	if err != nil {
		return saved, err
	}
	return saved, q.RefreshPeriodTotals(ctx, r.PeriodID)
}

func nonNeg(v *float64, msg string) error {
	if v != nil && *v < 0 {
		return iam.Invalid(msg)
	}
	return nil
}

// AdjustRecord: Kế toán / Admin nhập bậc KPI giữ HS, KPI tự do, buổi có GVNN, thuế TNCN, khoản tự do, ghi chú.
func (s *Service) AdjustRecord(ctx context.Context, a *iam.Access, id uuid.UUID, in AdjustInput) (string, error) {
	st, err := s.settings(ctx, s.q)
	if err != nil {
		return "", err
	}
	if v := in.RetentionTier; v != nil && (*v != float64(roundVND(*v)) || !slices.Contains(st.RetentionTiers, roundVND(*v))) {
		var labels []string
		for _, t := range st.RetentionTiers {
			labels = append(labels, money(t))
		}
		return "", iam.Invalid("Bậc KPI giữ học sinh phải là một trong: " + strings.Join(labels, " / ") + ".")
	}
	for _, c := range []struct {
		v   *float64
		msg string
	}{{in.KpiManualAmount, "KPI nhập tay không được âm."}, {in.ForeignSessionPay, "Lương buổi có GVNN không được âm."},
		{in.TaxDeduction, "Thuế TNCN không được âm."}} {
		if err := nonNeg(c.v, c.msg); err != nil {
			return "", err
		}
	}
	if len(in.Lines) > 30 {
		return "", iam.Invalid("Tối đa 30 khoản cộng / trừ tự do.")
	}
	lines := []ManualLine{}
	for _, l := range in.Lines {
		label := strings.TrimSpace(deref(l.Label))
		amount := deref(l.Amount)
		kind := deref(l.Kind)
		if amount < 0 {
			return "", iam.Invalid("Số tiền của khoản tự do không được âm.")
		}
		if runeLen(label) > 150 {
			return "", iam.Invalid("Tên khoản tự do tối đa 150 ký tự.")
		}
		if kind != "" && kind != "earning" && kind != "deduction" {
			return "", iam.Invalid("Loại khoản tự do không hợp lệ.")
		}
		if label == "" && amount > 0 {
			return "", iam.Invalid("Mỗi khoản cộng / trừ tự do cần có tên (VD: Hỗ trợ thỏa thuận, Gửi xe, Thưởng khác).")
		}
		if label == "" || amount <= 0 {
			continue
		}
		if kind == "" {
			kind = "earning"
		}
		lines = append(lines, ManualLine{Kind: kind, Label: label, Amount: roundVND(amount)})
	}
	notes := trimPtr(in.AdjustmentNotes)
	if notes != nil && runeLen(*notes) > 1000 {
		return "", iam.Invalid("Ghi chú tối đa 1000 ký tự.")
	}
	var msg string
	err = s.tx(ctx, func(tx pgxTx, q *payrolldb.Queries) error {
		r, p, name, err := s.lockedRecord(ctx, q, a, id, "Không thể sửa phiếu lương của kỳ đã duyệt/đã chi trả.")
		if err != nil {
			return err
		}
		before := recordAuditMap(r)
		vnd := func(v *float64) *int64 {
			if v == nil {
				return nil
			}
			return ptr(roundVND(*v))
		}
		if q3(r) {
			src := deref(r.KpiSource)
			if src == kpiRetention {
				r.RetentionTier = vnd(in.RetentionTier)
				r.ForeignSessionPay = deref(vnd(in.ForeignSessionPay))
			} else if r.TeachingConcurrent {
				r.RetentionTier = vnd(in.RetentionTier)
			}
			if src == kpiManual {
				r.KpiManualAmount = vnd(in.KpiManualAmount)
			}
			if !isPart(r) {
				r.TaxDeduction = deref(vnd(in.TaxDeduction))
			}
		} else {
			r.OtherBonus, r.OtherDeduction = sumLines(lines, "earning"), sumLines(lines, "deduction")
		}
		r.ManualLines = mustJSON(lines)
		r.AdjustmentNotes = notes
		saved, err := s.saveInputs(ctx, q, r)
		if err != nil {
			return err
		}
		msg = "Đã lưu các khoản nhập tay — thực lĩnh mới " + money(saved.NetSalary) + "."
		return audit.Record(ctx, tx, audit.Entry{ActorID: actorPtr(a), Module: audit.ModulePayroll, Action: "payroll_record.adjust",
			SubjectType: "payroll_record", SubjectID: r.ID.String(), SubjectLabel: name + " — " + p.Title,
			Description: "Điều chỉnh phiếu lương " + name + " — " + p.Title, Changes: audit.Diff(before, recordAuditMap(saved))})
	})
	if err == nil {
		s.log.Info("payroll record adjusted", "by", a.UserID, "record", id)
	}
	return msg, err
}

// ForeignPayInput: dòng "Buổi có GVNN" + ghi chú (updateRecord).
type ForeignPayInput struct {
	ForeignSessionPay *float64 `json:"foreign_session_pay"`
	Notes             *string  `json:"notes"`
}

func (s *Service) UpdateForeignPay(ctx context.Context, a *iam.Access, id uuid.UUID, in ForeignPayInput) (string, error) {
	if in.ForeignSessionPay == nil {
		return "", iam.Invalid("Vui lòng nhập lương buổi có GVNN.")
	}
	if *in.ForeignSessionPay < 0 {
		return "", iam.Invalid("Lương buổi có GVNN không được âm.")
	}
	notes := trimPtr(in.Notes)
	if notes != nil && runeLen(*notes) > 1000 {
		return "", iam.Invalid("Ghi chú tối đa 1000 ký tự.")
	}
	err := s.tx(ctx, func(tx pgxTx, q *payrolldb.Queries) error {
		r, p, name, err := s.lockedRecord(ctx, q, a, id, "Không thể sửa kỳ lương đã khóa.")
		if err != nil {
			return err
		}
		before := map[string]any{"foreign_session_pay": r.ForeignSessionPay, "net_salary": r.NetSalary}
		r.ForeignSessionPay = roundVND(*in.ForeignSessionPay)
		r.AdjustmentNotes = notes
		saved, err := s.saveInputs(ctx, q, r)
		if err != nil {
			return err
		}
		return audit.Record(ctx, tx, audit.Entry{ActorID: actorPtr(a), Module: audit.ModulePayroll, Action: "payroll_record.foreign_pay",
			SubjectType: "payroll_record", SubjectID: r.ID.String(), SubjectLabel: name + " — " + p.Title,
			Description: fmt.Sprintf("Nhập lương buổi có GVNN cho %s — %s", name, p.Title),
			Changes:     audit.Diff(before, map[string]any{"foreign_session_pay": saved.ForeignSessionPay, "net_salary": saved.NetSalary})})
	})
	if err != nil {
		return "", err
	}
	s.log.Info("payroll foreign pay updated", "by", a.UserID, "record", id)
	return "Đã lưu lương buổi có GVNN và tính lại thực lĩnh.", nil
}
