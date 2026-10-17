package payroll

import (
	"regexp"
	"slices"
	"strconv"
	"strings"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/payroll/payrolldb"
)

// Loại nhân sự / vai trò lương trên phiếu (PayrollRecord).
const (
	typeParttime = "parttime"
	typeFulltime = "fulltime"

	kpiRetention = "retention"
	kpiManual    = "manual"
	kpiAcademic  = "academic_kpi"
	kpiSelf      = "self"
)

var typeLabels = map[string]string{typeParttime: "Part-time", typeFulltime: "Full-time"}

var salaryRoleLabels = map[string]string{
	"teacher_parttime": "GV Part-time", "teacher_fulltime": "GV Full-time", "academic_staff": "Học vụ",
	"academic_lead": "Học thuật", "sales": "Tư vấn tuyển sinh", "staff": "Nhân sự khác",
}

var salaryRoleOrder = []string{"teacher_parttime", "teacher_fulltime", "academic_staff", "academic_lead", "sales", "staff"}

var departmentLabels = map[string]string{"teacher": "Giáo viên", "fulltime": "GV Full-time", "academic": "Học thuật", "operations": "Vận hành"}

var kpiSourceLabels = map[string]string{kpiRetention: "Giữ học sinh", kpiAcademic: "KPI Học vụ (tự động)", kpiManual: "Nhập tự do",
	kpiSelf: "Admin: chỉ xem % KPI"}

var (
	parttimeContracts = []string{"bán thời gian", "cộng tác viên", "parttime", "part-time"}
	fulltimeContracts = []string{"toàn thời gian", "fulltime", "full-time"}
)

// staffMember: nhân sự cần tính lương (vai trò + hồ sơ lương).
type staffMember struct {
	ID               uuid.UUID
	Name, Code       string
	Roles            []string
	ContractType     string
	ContractStart    *time.Time
	BaseSalary       int64
	HourlyRate       int64
	AcademicTeaching bool
	BranchID         *uuid.UUID
}

func (m staffMember) has(role string) bool { return slices.Contains(m.Roles, role) }

type profile struct {
	EmployeeType, SalaryRole, Department string
}

// profileOf: loại nhân sự suy từ vai trò + loại hợp đồng (PayrollFormulaService::profile).
// v2 chưa có cột "khối" của nhân sự: nhân sự khác mặc định bảng Vận hành.
func profileOf(m staffMember) profile {
	switch {
	case m.has("academic_lead"):
		return profile{typeFulltime, "academic_lead", "academic"}
	case m.has("academic_staff"):
		return profile{typeFulltime, "academic_staff", "operations"}
	case m.has("teacher_parttime"):
		return profile{typeParttime, "teacher_parttime", "teacher"}
	case m.has("teacher_fulltime"):
		return profile{typeFulltime, "teacher_fulltime", "fulltime"}
	case m.has("assistant") || len(m.Roles) == 0:
		c := strings.ToLower(strings.TrimSpace(m.ContractType))
		part := slices.Contains(parttimeContracts, c) || (!slices.Contains(fulltimeContracts, c) && m.BaseSalary <= 0)
		if part {
			return profile{typeParttime, "teacher_parttime", "teacher"}
		}
		return profile{typeFulltime, "teacher_fulltime", "fulltime"}
	case m.has("sales_consultant"):
		return profile{typeFulltime, "sales", "operations"}
	}
	return profile{typeFulltime, "staff", "operations"}
}

// baseSalaryFor: Part-time không có lương cơ bản; hợp đồng bắt đầu sau cuối kỳ → 0.
func baseSalaryFor(m staffMember, end time.Time) int64 {
	if profileOf(m).EmployeeType == typeParttime {
		return 0
	}
	if m.ContractStart != nil && asDay(*m.ContractStart).After(asDay(end)) {
		return 0
	}
	return max(0, m.BaseSalary)
}

func teachesAsLead(m staffMember) bool { return m.AcademicTeaching && m.has("academic_lead") }

// defaultTeacherType: loại GV mặc định của phiên bản đơn giá (TeacherHourlyRate::defaultTeacherType).
func defaultTeacherType(m staffMember) string {
	switch {
	case m.has("foreign_teacher"):
		return "foreign"
	case m.has("assistant") && !m.has("teacher_fulltime") && !m.has("teacher_parttime"):
		return "assistant"
	case teachesAsLead(m):
		return "academic"
	case profileOf(m).EmployeeType == typeParttime:
		return "parttime"
	}
	return "fulltime"
}

// ───────────── Đơn giá giáo viên ─────────────

var teacherTypes = []Option{
	{"parttime", "Part-time"}, {"fulltime", "Full-time"}, {"foreign", "Giáo viên nước ngoài"}, {"assistant", "Trợ giảng"},
	{"academic", "Học thuật kiêm giảng dạy"},
}

var rateUnitLabels = map[string]string{"session": "đ/buổi", "hour": "đ/giờ", "tuition": "% học phí"}

// rateBook: các phiên bản đơn giá riêng của từng GV (mới nhất trước).
type rateBook map[uuid.UUID][]payrolldb.HrTeacherHourlyRate

func newRateBook(rows []payrolldb.HrTeacherHourlyRate) rateBook {
	b := rateBook{}
	for _, r := range rows {
		b[r.UserID] = append(b[r.UserID], r)
	}
	return b
}

// effective: phiên bản hiệu lực tại ngày (effective_from ≤ ngày).
func (b rateBook) effective(user uuid.UUID, day time.Time) *payrolldb.HrTeacherHourlyRate {
	d := asDay(day)
	for i, r := range b[user] {
		if !asDay(r.EffectiveFrom).After(d) {
			return &b[user][i]
		}
	}
	return nil
}

func (b rateBook) tuitionPercent(user uuid.UUID, day time.Time) *float64 {
	if r := b.effective(user, day); r != nil && r.RateUnit == "tuition" {
		return &r.Rate
	}
	return nil
}

// payOutcome: tiền công một ca (TeacherTimesheet::payOutcome) — cơ bản rồi áp quy tắc đi muộn / về sớm.
type payOutcome struct {
	Amount, BaseAmount float64
	Counted            bool
	LateMinutes        int
	LateRule           string // "" | notified | deduct | void
	LateDeduction      float64
	Unit               string // session | hour
	Rate               float64
}

func basePay(ts Timesheet, m staffMember, rates rateBook) (float64, string, float64) {
	if ts.HourlyRate == nil || *ts.HourlyRate <= 0 {
		if r := rates.effective(ts.UserID, ts.Date); r != nil && r.RateUnit == "session" && r.Rate > 0 {
			return r.Rate, "session", r.Rate
		}
	}
	rate := hourlyRateFor(ts, m, rates)
	return round2(ts.Hours * rate), "hour", rate
}

// hourlyRateFor: đơn giá giờ của ca → đơn giá giờ riêng hiệu lực → hồ sơ → mặc định 250.000.
func hourlyRateFor(ts Timesheet, m staffMember, rates rateBook) float64 {
	if ts.HourlyRate != nil && *ts.HourlyRate > 0 {
		return float64(*ts.HourlyRate)
	}
	if r := rates.effective(ts.UserID, ts.Date); r != nil && r.RateUnit == "hour" && r.Rate > 0 {
		return r.Rate
	}
	if m.HourlyRate > 0 {
		return float64(m.HourlyRate)
	}
	return defaultHourlyRate
}

var schedRe = regexp.MustCompile(`^(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})$`)

func scheduledMinutes(ts Timesheet) int {
	if m := schedRe.FindStringSubmatch(strings.TrimSpace(ts.ScheduledTime)); m != nil {
		a, _ := strconv.Atoi(m[1])
		b, _ := strconv.Atoi(m[2])
		c, _ := strconv.Atoi(m[3])
		d, _ := strconv.Atoi(m[4])
		if v := (c*60 + d) - (a*60 + b); v > 0 {
			return v
		}
	}
	return max(1, int(ts.Hours*60+0.5))
}

// applyLateRules: có báo trước → trả theo phút thực dạy; không báo, dưới ngưỡng → trừ phút × đơn giá; từ ngưỡng → không tính buổi.
func applyLateRules(ts Timesheet, base float64, st Settings) payOutcome {
	minutes := ts.LateMinutes + ts.EarlyLeaveMinutes
	out := payOutcome{Amount: base, BaseAmount: base, Counted: true, LateMinutes: minutes}
	if minutes <= 0 {
		return out
	}
	switch {
	case ts.LateNotified:
		sched := scheduledMinutes(ts)
		paid := round2(base * float64(max(0, sched-minutes)) / float64(sched))
		out.Amount, out.LateRule, out.LateDeduction = paid, "notified", round2(base-paid)
	case minutes < st.LateThreshold:
		ded := min(base, round2(float64(minutes)*float64(st.LatePerMinute)))
		out.Amount, out.LateRule, out.LateDeduction = round2(base-ded), "deduct", ded
	default:
		out.Amount, out.Counted, out.LateRule, out.LateDeduction = 0, false, "void", base
	}
	return out
}

func outcomeFor(ts Timesheet, m staffMember, rates rateBook, st Settings) payOutcome {
	amount, unit, rate := basePay(ts, m, rates)
	o := applyLateRules(ts, amount, st)
	o.Unit, o.Rate = unit, rate
	return o
}

// ───────────── Giữ học sinh ─────────────

type retention struct {
	Base, Lost, Retained int
	LostIDs              []uuid.UUID
}

// retentionOf: HS có lượt xếp lớp hiệu lực tại đầu kỳ và số HS nghỉ tới cuối kỳ (PayrollFormulaService::retentionFor);
// HS còn học ở lớp khác trong cùng tập lớp không tính là nghỉ.
func retentionOf(rows []payrolldb.RetentionEnrollmentsRow, start, end time.Time) retention {
	s, e := asDay(start), asDay(end)
	base := map[uuid.UUID]bool{}
	lost := map[uuid.UUID]bool{}
	var order []uuid.UUID
	for _, r := range rows {
		dropped, lostOn := asDay(r.DroppedOn), asDay(r.LostOn) // 9999-12-31 = chưa nghỉ
		if dropped.Before(s) || lostOn.Before(s) {
			continue
		}
		if !base[r.StudentID] {
			order = append(order, r.StudentID)
		}
		base[r.StudentID] = true
		if !dropped.After(e) || !lostOn.After(e) {
			lost[r.StudentID] = true
		}
	}
	for id := range lost {
		for _, r := range rows {
			if r.StudentID == id && r.Status != "dropped" && r.StudentStatus != "dropped" {
				delete(lost, id)
				break
			}
		}
	}
	out := retention{Base: len(base), Lost: len(lost), Retained: len(base) - len(lost), LostIDs: []uuid.UUID{}}
	for _, id := range order {
		if lost[id] {
			out.LostIDs = append(out.LostIDs, id)
		}
	}
	return out
}

func filterEnrollments(rows []payrolldb.RetentionEnrollmentsRow, classIDs []uuid.UUID) []payrolldb.RetentionEnrollmentsRow {
	var out []payrolldb.RetentionEnrollmentsRow
	for _, r := range rows {
		if slices.Contains(classIDs, r.ClassID) {
			out = append(out, r)
		}
	}
	return out
}

// ───────────── Hoa hồng ─────────────

// tuitionBase: phần HỌC PHÍ của phiếu (bỏ phụ thu và phần phí khác của khoản học phí theo tỉ lệ).
func tuitionBase(amount, surcharge int64, final, otherFees *int64) int64 {
	portion := float64(max(0, amount-surcharge))
	f, o := float64(deref(final)), float64(deref(otherFees))
	if portion <= 0 || o <= 0 || f <= 0 {
		return roundVND(portion)
	}
	ratio := min(1, max(0, (f-o)/f))
	return roundVND(portion * ratio)
}

// tierFor: mốc chứa HS thứ n (min ≤ n ≤ max, ưu tiên min cao nhất) trong các mốc hiệu lực.
func tierFor(tiers []payrolldb.HrCommissionTier, n int) *payrolldb.HrCommissionTier {
	var best *payrolldb.HrCommissionTier
	for i, t := range tiers {
		if int(t.MinStudents) <= n && (t.MaxStudents == nil || int(*t.MaxStudents) >= n) {
			if best == nil || t.MinStudents > best.MinStudents {
				best = &tiers[i]
			}
		}
	}
	return best
}

func rangeLabel(t payrolldb.HrCommissionTier) string {
	from := max(1, int(t.MinStudents))
	if t.MaxStudents == nil {
		return "Từ HS thứ " + strconv.Itoa(from)
	}
	return "HS thứ " + strconv.Itoa(from) + "–" + strconv.Itoa(int(*t.MaxStudents))
}

// milestone: mốc hiện tại của người đã chốt n HS và mốc kế tiếp (SalesCommissionService::milestoneFor).
type milestone struct {
	TierName    string   `json:"tier_name"`
	Percent     float64  `json:"percent"`
	Range       *string  `json:"range"`
	NextPercent *float64 `json:"next_percent"`
	NextRange   *string  `json:"next_range"`
	ToNext      *int     `json:"to_next"`
}

func milestoneFor(tiers []payrolldb.HrCommissionTier, closed int) milestone {
	cur := tierFor(tiers, max(1, closed))
	if cur == nil {
		return milestone{TierName: "Chưa cấu hình mốc"}
	}
	out := milestone{TierName: cur.TierName, Percent: cur.NewSalePercent, Range: ptr(rangeLabel(*cur))}
	if cur.MaxStudents != nil {
		var next *payrolldb.HrCommissionTier
		for i, t := range tiers {
			if t.MinStudents > *cur.MaxStudents && (next == nil || t.MinStudents < next.MinStudents) {
				next = &tiers[i]
			}
		}
		if next != nil {
			out.NextPercent, out.NextRange = ptr(next.NewSalePercent), ptr(rangeLabel(*next))
			out.ToNext = ptr(max(1, int(next.MinStudents)-closed))
		}
	}
	return out
}
