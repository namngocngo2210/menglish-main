package kpi

import (
	"cmp"
	"encoding/json"
	"math"
	"slices"
	"strconv"
	"strings"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/kpi/kpisrc"
)

// Vai trò có bộ tiêu chí KPI (Roles::KPI_ROLES): mọi vai trò nhân sự, Admin và Học viên không chấm KPI.
var Roles = []string{iam.RoleAcademicStaff, iam.RoleAcademicLead, iam.RoleManager, iam.RoleSalesConsultant,
	iam.RoleTeacherFulltime, iam.RoleTeacherParttime, iam.RoleAssistant}

// Đơn vị đếm (khóa lưu DB → nhãn), chọn từ danh sách để số liệu cùng một chuẩn.
var Units = []Option{{"lan", "lần"}, {"ho_so", "hồ sơ"}, {"case", "case"}, {"sai_sot", "sai sót"}, {"su_co", "sự cố"},
	{"lop", "lớp"}, {"buoi", "buổi"}, {"hoc_vien", "học viên"}, {"bai", "bài"}, {"ngay", "ngày"}}

// RateUnit: đơn vị của tiêu chí đo bằng tỉ lệ.
const RateUnit = "phan_tram"

// AutoSources: nguồn số liệu tự động (KpiCriterion::AUTO_SOURCES) — hệ thống tự đếm, người chấm không điền.
var AutoSources = []Option{
	{"tuition_no_reminder", "Học phí quá hạn chưa có lần nhắc"},
	{"receipt_rejected", "Phiếu thu bị từ chối duyệt"},
	{"care_overdue", "Mốc chăm sóc tháng đầu quá hạn"},
	{"crm_sla_late", "Hạn SLA CRM bị trễ (liên hệ, follow, kết quả test, phản hồi học thử)"},
	{"daily_report_late", "Báo cáo ngày nộp sau 9h sáng hôm sau"},
	{"material_stock", "Học liệu xử lý trễ hạn, kiểm kê sách lệch"},
	{"teacher_absent_unexcused", "Buổi dạy không có giờ dạy và không có đơn nghỉ được duyệt"},
	{"teacher_leave", "Ngày nghỉ dạy có đơn được duyệt"},
	{"teacher_late", "Buổi dạy check-in muộn"},
	{"test_result", "Kết quả test: điểm TB × 50% + tỷ lệ đạt chuẩn × 50%"},
	{"test_progress", "% điểm test tăng (lần cuối so lần đầu trong kỳ)"},
	{"class_attendance_rate", "Tỷ lệ chuyên cần học sinh các lớp"},
	{"homework_rate", "Tỷ lệ nộp bài tập về nhà đúng hạn"},
	{"monthly_report_on_time", "Tỷ lệ tháng nộp báo cáo tháng trước 12h ngày mùng 2"},
	{"academic_deliverable_on_time", "Mốc dự án học thuật đúng hạn (hệ số trễ theo ngày)"},
	{"task_on_time", "Việc được giao đúng hạn (hệ số trễ theo giờ)"},
	{"academic_order_on_time", "Order học liệu học thuật giao đúng SLA trước giờ dùng"},
	{"student_pass_rate", "Tỷ lệ bài test học sinh đạt chuẩn, 3 tháng gần nhất"},
	{"staff_late", "Ngày chấm công đi muộn quá 10 phút"},
}

// RateSources: nguồn tự động trả tỉ lệ % (kỳ chưa có dữ liệu thì người chấm điền tay); còn lại đếm số lần.
var RateSources = []string{"test_result", "test_progress", "class_attendance_rate", "homework_rate", "monthly_report_on_time",
	"academic_deliverable_on_time", "task_on_time", "academic_order_on_time", "student_pass_rate"}

// FactorSources: nguồn tỉ lệ tính theo hệ số từng bản ghi (KpiAutoCounter::factorRate).
var FactorSources = []string{"academic_deliverable_on_time", "task_on_time", "academic_order_on_time"}

// Measures: cách đo.
var Measures = []Option{{"count", "Đếm số lần (càng ít càng tốt)"}, {"rate", "Tỉ lệ % (càng cao càng tốt)"},
	{"rate_down", "Tỉ lệ % (càng thấp càng tốt)"}}

// Cycles: chu kỳ chấm KPI (số tháng mỗi phiếu → nhãn).
var Cycles = []Option{{"1", "Theo tháng"}, {"3", "Theo quý"}}

// GradedRoles: vai trò xếp loại A–E theo tổng % đạt (phiếu quý của vai trò khác cũng xếp loại).
var GradedRoles = []string{iam.RoleTeacherParttime, iam.RoleAcademicLead}

// GradeFundDefaults: thưởng KPI tối đa / tháng theo xếp loại (đ), thưởng = mức này × hệ số xếp loại.
var GradeFundDefaults = map[string]int64{iam.RoleAcademicLead: 2_000_000}

// DefaultFund: quỹ KPI Học vụ / tháng mặc định (config payroll.academic_kpi_fund).
const DefaultFund = 2_000_000

// Grade: xếp loại theo tổng % đạt (file KPI GV part-time).
type Grade struct {
	Grade string `json:"grade"`
	From  int    `json:"from"`
	Label string `json:"label"`
	Pay   int    `json:"pay"`
	Pass  bool   `json:"pass"`
}

var Grades = []Grade{
	{"A", 95, "Xuất sắc", 100, true},
	{"B", 85, "Tốt", 85, true},
	{"C", 70, "Đạt", 70, false},
	{"D", 50, "Cần cải thiện", 50, false},
	{"E", 0, "Không đạt", 0, false},
}

// Điều kiện chặn từ bằng chứng có cờ cap: mức đạt tối đa của mục và hạng cao nhất của phiếu.
const (
	CapLevel = 50
	CapGrade = "B"
)

type Option struct {
	Value string `json:"value"`
	Label string `json:"label"`
}

func label(opts []Option, v string) (string, bool) {
	i := slices.IndexFunc(opts, func(o Option) bool { return o.Value == v })
	if i < 0 {
		return "", false
	}
	return opts[i].Label, true
}

// UnitLabel: "%" với tiêu chí tỉ lệ, nhãn đơn vị, khóa lạ giữ nguyên.
func UnitLabel(unit string) string {
	if unit == RateUnit {
		return "%"
	}
	if l, ok := label(Units, unit); ok {
		return l
	}
	return unit
}

// ThresholdLabel: nhãn ngưỡng từ số lần tối đa: "0 hồ sơ", "≤ 4 case".
func ThresholdLabel(max int, unit string) string {
	s := "0"
	if max != 0 {
		s = "≤ " + strconv.Itoa(max)
	}
	return strings.TrimSpace(s + " " + UnitLabel(unit))
}

// Tier: một bậc tính điểm [ngưỡng, % điểm].
type Tier [2]float64

// Criterion là tiêu chí KPI theo vai trò.
type Criterion struct {
	ID            uuid.UUID
	Role          string
	Group         string
	Code          string
	Name          string
	Weight        float64
	ThresholdFull string
	ThresholdHalf string
	MaxFull       *int
	MaxHalf       *int
	Unit          string
	Measure       string
	Tiers         []Tier // bậc đã lưu (chưa chuẩn hóa)
	Linear        bool
	FullAtValue   *float64
	PerMonth      bool
	Knockout      bool
	AllowNA       bool
	AutoSource    string
	Description   string
	IsActive      bool
	SortOrder     int
}

// parseTiers: bậc lưu ở jsonb, bỏ dòng không phải [số, số].
func parseTiers(raw []byte) []Tier {
	var rows []json.RawMessage
	if len(raw) == 0 || json.Unmarshal(raw, &rows) != nil {
		return nil
	}
	var out []Tier
	for _, r := range rows {
		var pair []any
		if json.Unmarshal(r, &pair) != nil || len(pair) < 2 {
			continue
		}
		a, ok1 := numeric(pair[0])
		b, ok2 := numeric(pair[1])
		if ok1 && ok2 {
			out = append(out, Tier{a, b})
		}
	}
	return out
}

func numeric(v any) (float64, bool) {
	switch x := v.(type) {
	case float64:
		return x, true
	case string:
		f, err := strconv.ParseFloat(strings.TrimSpace(x), 64)
		return f, err == nil
	}
	return 0, false
}

// IsAuto: số liệu do hệ thống tự đếm (nguồn có trong danh sách).
func (c *Criterion) IsAuto() bool {
	_, ok := label(AutoSources, c.AutoSource)
	return c.AutoSource != "" && ok
}

// IsRateSource: nguồn tự động trả tỉ lệ %.
func (c *Criterion) IsRateSource() bool {
	return c.IsAuto() && slices.Contains(RateSources, c.AutoSource)
}

// IsRate: đo bằng tỉ lệ % (càng cao hoặc càng thấp càng tốt).
func (c *Criterion) IsRate() bool { return c.Measure == "rate" || c.Measure == "rate_down" }

// LowerBetter: số càng nhỏ càng tốt (đếm số lần, tỉ lệ càng thấp càng tốt) — bậc là ngưỡng tối đa.
func (c *Criterion) LowerBetter() bool { return c.Measure != "rate" }

// TierList: bậc đã chuẩn hóa — đếm / tỉ lệ càng thấp: ngưỡng tăng dần; tỉ lệ càng cao: giảm dần. Tiêu chí đếm cũ không có bậc
// riêng dùng max_full (100%) / max_half (50%).
func (c *Criterion) TierList() []Tier {
	tiers := slices.Clone(c.Tiers)
	if len(tiers) == 0 && !c.IsRate() && c.MaxFull != nil && c.MaxHalf != nil {
		tiers = []Tier{{float64(*c.MaxFull), 100}, {float64(*c.MaxHalf), 50}}
	}
	if c.LowerBetter() {
		slices.SortStableFunc(tiers, func(a, b Tier) int { return cmp.Compare(a[0], b[0]) })
	} else {
		slices.SortStableFunc(tiers, func(a, b Tier) int { return cmp.Compare(b[0], a[0]) })
	}
	if tiers == nil {
		tiers = []Tier{}
	}
	return tiers
}

// HasRule: có quy tắc tính mức đạt từ số liệu (tiêu chí % cũ không có quy tắc: người chấm chọn % trực tiếp).
func (c *Criterion) HasRule() bool { return (c.Measure == "rate" && c.Linear) || len(c.TierList()) > 0 }

// IsCountBased: tiêu chí đếm số lần có bậc tính điểm.
func (c *Criterion) IsCountBased() bool { return !c.IsRate() && len(c.TierList()) > 0 }

// FullAt: ngưỡng tỉ lệ đủ điểm khi tính thẳng theo tỉ lệ (mặc định 100%).
func (c *Criterion) FullAt() float64 {
	if c.FullAtValue != nil && *c.FullAtValue > 0 {
		return *c.FullAtValue
	}
	return 100
}

// LevelFor: mức đạt (% điểm của tiêu chí) từ số liệu; nil nếu không có quy tắc.
func (c *Criterion) LevelFor(v float64) *float64 {
	if !c.HasRule() {
		return nil
	}
	if c.Measure == "rate" && c.Linear {
		l := round2(math.Max(0, math.Min(100, v/c.FullAt()*100)))
		return &l
	}
	for _, t := range c.TierList() {
		if (c.LowerBetter() && v <= t[0]) || (!c.LowerBetter() && v >= t[0]) {
			l := t[1]
			return &l
		}
	}
	zero := 0.0
	return &zero
}

// RuleLabel: diễn giải cách tính: "≤ 1 lần: 100% · 2: 50% · từ 3: 0%", "≥ 90%: 100% · dưới 80%: 0%"…; "" nếu không có quy tắc.
func (c *Criterion) RuleLabel() string {
	if c.Knockout {
		return "Có từ 1 " + UnitLabel(c.Unit) + ": mất toàn bộ KPI kỳ"
	}
	if c.Measure == "rate" && c.Linear {
		return "Theo tỉ lệ, đủ điểm khi đạt " + Number(c.FullAt()) + "%"
	}
	tiers := c.TierList()
	if len(tiers) == 0 {
		return ""
	}
	var parts []string
	last := tiers[len(tiers)-1][0]
	switch {
	case c.Measure == "rate_down":
		for _, t := range tiers {
			p := ""
			if t[0] > 0 {
				p = "≤ "
			}
			parts = append(parts, p+Number(t[0])+"%: "+Number(t[1])+"%")
		}
		parts = append(parts, "trên "+Number(last)+"%: 0%")
	case c.IsRate():
		for _, t := range tiers {
			parts = append(parts, "≥ "+Number(t[0])+"%: "+Number(t[1])+"%")
		}
		parts = append(parts, "dưới "+Number(last)+"%: 0%")
	default:
		prev := -1.0
		for i, t := range tiers {
			var r string
			switch {
			case t[0] <= prev+1:
				r = Number(t[0])
			case prev < 0:
				r = "≤ " + Number(t[0])
			default:
				r = Number(prev+1) + "–" + Number(t[0])
			}
			if i == 0 {
				r += " " + UnitLabel(c.Unit)
			}
			parts = append(parts, r+": "+Number(t[1])+"%")
			prev = t[0]
		}
		parts = append(parts, "từ "+Number(prev+1)+": 0%")
	}
	return strings.Join(parts, " · ")
}

// Rule: quy tắc gửi xuống giao diện để tính mức đạt ngay khi điền (cùng cách tính với LevelFor).
type Rule struct {
	Measure     string  `json:"measure"` // rate | count
	LowerBetter bool    `json:"lower_better"`
	Tiers       []Tier  `json:"tiers"`
	Linear      bool    `json:"linear"`
	FullAt      float64 `json:"full_at"`
	HasRule     bool    `json:"has_rule"`
	Knockout    bool    `json:"knockout"`
}

func (c *Criterion) RuleForUI() Rule {
	m := "count"
	if c.IsRate() {
		m = "rate"
	}
	return Rule{Measure: m, LowerBetter: c.LowerBetter(), Tiers: c.TierList(), Linear: c.Measure == "rate" && c.Linear,
		FullAt: c.FullAt(), HasRule: c.HasRule(), Knockout: c.Knockout}
}

// twoLevel: tiêu chí đếm 2 mức 100 / 50% (bộ Học vụ) — phiếu giữ hai cột "Đạt 100% khi" / "Đạt 50% khi".
func (c *Criterion) twoLevel() bool {
	t := c.TierList()
	return c.IsCountBased() && !c.Knockout && len(t) == 2 && t[0][1] == 100 && t[1][1] == 50
}

// GradeFor: xếp loại theo tổng % đạt; cap = hạng cao nhất được xếp ("" = không chặn).
func GradeFor(total float64, cap string) Grade {
	capped := cap == ""
	for _, g := range Grades {
		capped = capped || g.Grade == cap
		if capped && total >= float64(g.From) {
			return g
		}
	}
	return Grades[len(Grades)-1]
}

// HasGrades: vai trò xếp loại A–E (GV part-time, Học thuật; phiếu quý của vai trò khác).
func HasGrades(role string, months int) bool {
	return slices.Contains(GradedRoles, role) || months == 3
}

// ───────────── Số ─────────────

func round2(v float64) float64 { return math.Round(v*100) / 100 }
func round1(v float64) float64 { return math.Round(v*10) / 10 }

// formatNum: number_format(v, decimals, ',', '.') rồi bỏ số 0 thừa sau dấu phẩy.
func formatNum(v float64, decimals int) string { return kpisrc.Num(v, decimals) }

// Number: số gọn 2 chữ số lẻ: 62.5 → "62,5", 100 → "100".
func Number(v float64) string { return formatNum(v, 2) }

// pct: tỉ lệ 1 chữ số lẻ kèm "%".
func pct(v float64) string { return kpisrc.Pct(v) }

// Money: "9.500.000 đ" (Money::format).
func Money(v float64) string {
	s := formatNum(math.Round(v), 0)
	return s + " đ"
}
