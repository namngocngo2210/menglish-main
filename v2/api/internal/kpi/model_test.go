package kpi

import (
	"encoding/json"
	"errors"
	"strings"
	"testing"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/kpi/kpidb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/kpi/kpisrc"
)

func f(v float64) *float64 { return &v }
func i(v int) *int         { return &v }

var tableA = []Tier{{95, 100}, {90, 90}, {80, 75}, {70, 60}, {60, 30}}

func TestTierListAndLevel(t *testing.T) {
	// Bộ Học vụ: 2 mức max_full / max_half
	c := Criterion{Measure: "count", Unit: "ho_so", MaxFull: i(0), MaxHalf: i(2)}
	if got := c.TierList(); len(got) != 2 || got[0] != (Tier{0, 100}) || got[1] != (Tier{2, 50}) {
		t.Fatalf("tiers = %v", got)
	}
	for v, want := range map[float64]float64{0: 100, 1: 50, 2: 50, 3: 0} {
		if got := *c.LevelFor(v); got != want {
			t.Errorf("level(%v) = %v, muốn %v", v, got, want)
		}
	}
	if !c.HasRule() || !c.IsCountBased() || !c.twoLevel() {
		t.Error("đếm 2 mức phải có quy tắc")
	}
	// Tỉ lệ càng cao càng tốt: sắp giảm dần dù lưu lộn xộn
	r := Criterion{Measure: "rate", Unit: RateUnit, Tiers: []Tier{{60, 30}, {95, 100}, {80, 75}, {90, 90}, {70, 60}}}
	if got := r.TierList(); got[0][0] != 95 || got[4][0] != 60 {
		t.Fatalf("rate tiers = %v", got)
	}
	for v, want := range map[float64]float64{100: 100, 92: 90, 80: 75, 59.9: 0} {
		if got := *r.LevelFor(v); got != want {
			t.Errorf("rate level(%v) = %v, muốn %v", v, got, want)
		}
	}
	// Tính thẳng theo tỉ lệ: đủ điểm ở 10%
	lin := Criterion{Measure: "rate", Linear: true, FullAtValue: f(10)}
	if *lin.LevelFor(5) != 50 || *lin.LevelFor(25) != 100 || *lin.LevelFor(-3) != 0 || *lin.LevelFor(3.333) != 33.33 {
		t.Error("linear level sai")
	}
	// Tỉ lệ càng thấp càng tốt
	down := Criterion{Measure: "rate_down", Tiers: []Tier{{5, 50}, {0, 100}}}
	if *down.LevelFor(0) != 100 || *down.LevelFor(4) != 50 || *down.LevelFor(6) != 0 {
		t.Error("rate_down level sai")
	}
	// Tiêu chí % cũ không có quy tắc
	old := Criterion{Measure: "rate"}
	if old.HasRule() || old.LevelFor(50) != nil {
		t.Error("tiêu chí không quy tắc phải trả nil")
	}
}

func TestRuleLabel(t *testing.T) {
	cases := []struct {
		c    Criterion
		want string
	}{
		{Criterion{Measure: "count", Unit: "ho_so", MaxFull: i(0), MaxHalf: i(2)}, "0 hồ sơ: 100% · 1–2: 50% · từ 3: 0%"},
		{Criterion{Measure: "count", Unit: "lan", MaxFull: i(4), MaxHalf: i(10)}, "≤ 4 lần: 100% · 5–10: 50% · từ 11: 0%"},
		{Criterion{Measure: "count", Unit: "buoi", Tiers: []Tier{{0, 100}, {1, 50}}}, "0 buổi: 100% · 1: 50% · từ 2: 0%"},
		{Criterion{Measure: "rate", Tiers: tableA}, "≥ 95%: 100% · ≥ 90%: 90% · ≥ 80%: 75% · ≥ 70%: 60% · ≥ 60%: 30% · dưới 60%: 0%"},
		{Criterion{Measure: "rate_down", Tiers: []Tier{{0, 100}, {5, 50}}}, "0%: 100% · ≤ 5%: 50% · trên 5%: 0%"},
		{Criterion{Measure: "count", Unit: "buoi", Knockout: true, Tiers: []Tier{{0, 100}}}, "Có từ 1 buổi: mất toàn bộ KPI kỳ"},
		{Criterion{Measure: "rate", Linear: true, FullAtValue: f(62.5)}, "Theo tỉ lệ, đủ điểm khi đạt 62,5%"},
		{Criterion{Measure: "rate"}, ""},
	}
	for _, tc := range cases {
		if got := tc.c.RuleLabel(); got != tc.want {
			t.Errorf("RuleLabel = %q, muốn %q", got, tc.want)
		}
	}
}

func TestFormatting(t *testing.T) {
	for v, want := range map[float64]string{62.5: "62,5", 100: "100", 1000: "1.000", 12.345: "12,35", 0: "0", -2.5: "-2,5"} {
		if got := Number(v); got != want {
			t.Errorf("Number(%v) = %q, muốn %q", v, got, want)
		}
	}
	if Money(1_500_000) != "1.500.000 đ" || Money(1374999.6) != "1.375.000 đ" {
		t.Error("Money sai")
	}
	if pct(85.25) != "85,3%" || pct(100) != "100%" {
		t.Errorf("pct = %s", pct(85.25))
	}
	if weightLabel(12.5) != "12.5" || weightLabel(100) != "100" || weightLabel(3.75) != "3.75" {
		t.Error("weightLabel sai")
	}
	if ThresholdLabel(0, "ho_so") != "0 hồ sơ" || ThresholdLabel(4, "case") != "≤ 4 case" {
		t.Error("ThresholdLabel sai")
	}
	if kpisrc.LateText(0) != "đúng hạn" || kpisrc.LateText(0.41) != "trễ 25 phút" || kpisrc.LateText(3.5) != "trễ 3,5 giờ" {
		t.Error("LateText sai")
	}
	if kpisrc.HourFactor(0) != 1 || kpisrc.HourFactor(4) != 0.5 || kpisrc.HourFactor(10) != 0.25 || kpisrc.HourFactor(25) != 0 {
		t.Error("HourFactor sai")
	}
	if kpisrc.DayFactor(0) != 1 || kpisrc.DayFactor(3) != 0.5 || kpisrc.DayFactor(7) != 0.25 || kpisrc.DayFactor(8) != 0 {
		t.Error("DayFactor sai")
	}
}

func TestGrades(t *testing.T) {
	for _, tc := range []struct {
		total float64
		cap   string
		want  string
	}{{96, "", "A"}, {96, "B", "B"}, {85, "", "B"}, {84.99, "", "C"}, {50, "", "D"}, {49, "", "E"}, {70, "B", "C"}} {
		if g := GradeFor(tc.total, tc.cap); g.Grade != tc.want {
			t.Errorf("GradeFor(%v, %q) = %s, muốn %s", tc.total, tc.cap, g.Grade, tc.want)
		}
	}
	if !HasGrades("teacher_parttime", 1) || !HasGrades("academic_lead", 1) || HasGrades("academic_staff", 1) || !HasGrades("academic_staff", 3) {
		t.Error("HasGrades sai")
	}
}

func TestPeriod(t *testing.T) {
	q := makePeriod(3, 11, 2026)
	if q.Month != 10 || q.Label != "Quý 4/2026" || q.Key != "2026-10" || q.fullLabel() != "Quý 4/2026 (tháng 10–12)" {
		t.Fatalf("quý = %+v", q)
	}
	if q.To.Format("2006-01-02 15:04") != "2026-12-31 23:59" || q.closeOn().Format("02/01/2006") != "31/12/2026" {
		t.Errorf("cuối quý = %v", q.To)
	}
	if k := q.monthKeys(); strings.Join(k, ",") != "2026-10,2026-11,2026-12" {
		t.Errorf("monthKeys = %v", k)
	}
	m := makePeriod(1, 2, 2026)
	if m.Label != "Tháng 02/2026" || m.fullLabel() != "Kỳ lương tháng 02/2026" || m.To.Day() != 28 {
		t.Errorf("tháng = %+v", m)
	}
	if whatLabel(m, TrackMain) != "tháng 2/2026" || whatLabel(q, TrackTeaching) != "giảng dạy quý 4/2026" {
		t.Error("whatLabel sai")
	}
}

func TestNormalizeCriterion(t *testing.T) {
	base := func() CriterionInput {
		return CriterionInput{Name: "Thu đúng học phí", Weight: f(7.5), Unit: ptr("lan"), MaxFull: i(0), MaxHalf: i(1)}
	}
	n, err := normalizeCriterion(base())
	if err != nil {
		t.Fatal(err)
	}
	if n.tiers != nil || *n.maxFull != 0 || *n.maxHalf != 1 || *n.thresholdFull != "0 lần" || *n.thresholdHalf != "≤ 1 lần" {
		t.Errorf("hai mức: %+v", n)
	}
	// Bậc gửi lên: bỏ dòng trống, bỏ ngưỡng trùng, sắp tăng dần; 3 bậc → lưu tiers
	in := base()
	in.Tiers = &[]TierInput{{f(4), f(50)}, {nil, f(10)}, {f(0), f(100)}, {f(4), f(30)}, {f(8), f(20)}}
	n, err = normalizeCriterion(in)
	if err != nil {
		t.Fatal(err)
	}
	var tiers []Tier
	_ = json.Unmarshal(n.tiers, &tiers)
	if len(tiers) != 3 || tiers[0] != (Tier{0, 100}) || tiers[1] != (Tier{4, 50}) || n.maxFull != nil {
		t.Errorf("tiers = %v", tiers)
	}
	// Tỉ lệ: đơn vị phan_tram, sắp giảm dần, bỏ knockout
	in = CriterionInput{Name: "Chuyên cần", Weight: f(10), Measure: ptr("rate"), Knockout: true,
		Tiers: &[]TierInput{{f(80), f(50)}, {f(90), f(100)}}}
	n, err = normalizeCriterion(in)
	if err != nil || *n.unit != RateUnit || n.knockout {
		t.Fatalf("rate: %+v %v", n, err)
	}
	_ = json.Unmarshal(n.tiers, &tiers)
	if tiers[0][0] != 90 {
		t.Errorf("rate tiers = %v", tiers)
	}
	// Knockout: bậc cố định [[0, 100]]
	in = CriterionInput{Name: "Nghỉ không phép", Weight: f(0), Unit: ptr("buoi"), Knockout: true}
	n, err = normalizeCriterion(in)
	if err != nil || string(n.tiers) != "[[0,100]]" || !n.knockout {
		t.Fatalf("knockout: %s %v", n.tiers, err)
	}
	// Tính thẳng: không cần bậc
	in = CriterionInput{Name: "Tăng điểm", Weight: f(10), Measure: ptr("rate"), Linear: true, FullAt: f(10), Tiers: &[]TierInput{}}
	n, err = normalizeCriterion(in)
	if err != nil || n.tiers != nil || *n.fullAt != 10 {
		t.Fatalf("linear: %+v %v", n, err)
	}
	msgs := map[string]func(*CriterionInput){
		"Vui lòng nhập tên tiêu chí.":                            func(c *CriterionInput) { c.Name = " " },
		"Vui lòng nhập trọng số.":                                func(c *CriterionInput) { c.Weight = nil },
		"Trọng số phải từ 0 đến 100.":                            func(c *CriterionInput) { c.Weight = f(101) },
		"Vui lòng chọn đơn vị đếm.":                              func(c *CriterionInput) { c.Unit = nil },
		"Đơn vị đếm không hợp lệ.":                               func(c *CriterionInput) { c.Unit = ptr("kg") },
		"Cách đo không hợp lệ.":                                  func(c *CriterionInput) { c.Measure = ptr("sum") },
		"Vui lòng nhập ngưỡng đạt 100% (số lần).":                func(c *CriterionInput) { c.MaxFull = nil },
		"Ngưỡng đạt 50% phải lớn hơn hoặc bằng ngưỡng đạt 100%.": func(c *CriterionInput) { c.MaxFull = i(3) },
		"Cần ít nhất một bậc tính điểm (ngưỡng và % điểm).":      func(c *CriterionInput) { c.Tiers = &[]TierInput{{nil, f(100)}} },
		"% điểm của bậc phải từ 0 đến 100.":                      func(c *CriterionInput) { c.Tiers = &[]TierInput{{f(1), f(120)}} },
		"Nguồn số liệu không hợp lệ.":                            func(c *CriterionInput) { c.AutoSource = ptr("abc") },
		"Tối đa 8 bậc tính điểm.":                                func(c *CriterionInput) { c.Tiers = &[]TierInput{{}, {}, {}, {}, {}, {}, {}, {}, {}} },
	}
	for want, mut := range msgs {
		in := base()
		mut(&in)
		if _, err := normalizeCriterion(in); errMsg(err) != want {
			t.Errorf("muốn %q, nhận %v", want, errMsg(err))
		}
	}
}

func errMsg(err error) string {
	var e *iam.Error
	if errors.As(err, &e) {
		return e.Message
	}
	if err == nil {
		return ""
	}
	return err.Error()
}

// ───────────── Phiếu ─────────────

func academic(name string, w float64, mf, mh int, src string) Criterion {
	return Criterion{ID: uuid.New(), Role: "academic_staff", Group: "Nhóm", Code: name[:3], Name: name, Weight: w, Measure: "count",
		Unit: "lan", MaxFull: &mf, MaxHalf: &mh, AutoSource: src, IsActive: true}
}

func TestBuildSheetAcademic(t *testing.T) {
	auto := academic("1.1 Báo cáo", 12.5, 0, 2, "daily_report_late")
	manual := academic("1.2 Thu phí", 7.5, 0, 1, "")
	p := makePeriod(1, 10, 2026)
	in := sheetInput{role: "academic_staff", period: p, criteria: []Criterion{auto, manual}, fund: f(2_000_000),
		items:    map[uuid.UUID]kpidb.HrKpiItem{manual.ID: {CriterionID: manual.ID, Actual: ptr("0")}},
		measured: map[uuid.UUID]measured{auto.ID: {Live: true, Value: f(1), Evidence: []Evidence{{Date: "05/10", Month: "2026-10", Text: "x", Counted: true}}}},
		journal:  map[string][]kpisrc.JournalEntry{"1.2": {{Code: "1.2", Date: time.Date(2026, 10, 3, 0, 0, 0, 0, vnZone), Title: "Thu thiếu"}}}}
	sh := buildSheet(in)
	if sh.Total != 68.75 || *sh.Amount != 1_375_000 || sh.Missing != 0 || sh.Grade != nil {
		t.Fatalf("tổng = %v, tiền = %v, thiếu = %d", sh.Total, *sh.Amount, sh.Missing)
	}
	if l := sh.Lines[0]; !l.Auto || *l.Level != 50 || *l.MaxAmount != 1_250_000 || *l.Amount != 625_000 || l.Suggestion != nil {
		t.Errorf("dòng tự động: %+v", l)
	}
	if l := sh.Lines[1]; l.Auto || *l.Value != 0 || *l.Level != 100 || l.Suggestion == nil || l.Suggestion.Count != 1 ||
		l.Suggestion.Evidence[0].Date != "03/10" {
		t.Errorf("dòng điền tay: %+v", l)
	}
	// Chưa có số → mục chưa có số liệu, tính 0
	in.items = nil
	sh = buildSheet(in)
	if sh.Missing != 1 || sh.Total != 31.25 {
		t.Errorf("thiếu = %d, tổng = %v", sh.Missing, sh.Total)
	}
	// Lỗi nghiêm trọng đưa mục về 0
	in.items = map[uuid.UUID]kpidb.HrKpiItem{manual.ID: {CriterionID: manual.ID, Actual: ptr("0"), CriticalError: true}}
	if sh = buildSheet(in); *sh.Lines[1].Level != 0 {
		t.Error("critical phải về 0")
	}
	// Nguồn chưa có ở v2: điền tay, kèm lý do
	in.measured = map[uuid.UUID]measured{}
	in.items = map[uuid.UUID]kpidb.HrKpiItem{auto.ID: {CriterionID: auto.ID, Actual: ptr("3")}}
	sh = buildSheet(in)
	if l := sh.Lines[0]; l.Auto || l.Pending == "" || *l.Value != 3 || *l.Level != 0 {
		t.Errorf("nguồn chờ: %+v", l)
	}
}

func TestBuildSheetGradedQuarter(t *testing.T) {
	rate := Criterion{ID: uuid.New(), Group: "N1", Name: "Kết quả test", Weight: 40, Measure: "rate", Tiers: tableA, AutoSource: "test_result", AllowNA: true}
	late := Criterion{ID: uuid.New(), Group: "N2", Name: "Đi muộn", Weight: 60, Measure: "count", Unit: "buoi",
		Tiers: []Tier{{0, 100}, {1, 50}}, AutoSource: "task_on_time", PerMonth: true}
	ko := Criterion{ID: uuid.New(), Group: "N3", Name: "Nghỉ không phép", Weight: 0, Measure: "count", Unit: "buoi",
		Knockout: true, Tiers: []Tier{{0, 100}}}
	p := makePeriod(3, 10, 2026)
	ev := []Evidence{{Month: "2026-10", Counted: true}, {Month: "2026-10", Counted: true}, {Month: "2026-11", Counted: true}, {Month: "2026-11", Counted: false}}
	in := sheetInput{role: "teacher_parttime", period: p, criteria: []Criterion{rate, late, ko}, gradeFund: f(1_000_000),
		items:    map[uuid.UUID]kpidb.HrKpiItem{},
		measured: map[uuid.UUID]measured{late.ID: {Live: true, Value: f(3), Evidence: ev}}}
	sh := buildSheet(in)
	// Nguồn tỉ lệ chưa có ở v2 + cho phép Không phát sinh → mặc định Không phát sinh, bỏ khỏi tổng.
	if !sh.Lines[0].NA || sh.Lines[0].Auto {
		t.Fatalf("dòng tỉ lệ: %+v", sh.Lines[0])
	}
	// Quý: tháng 10 = 2 lần (0%), tháng 11 = 1 lần (50%), tháng 12 = 0 (100%) → TB 50%
	if *sh.Lines[1].Level != 50 {
		t.Errorf("TB quý = %v", *sh.Lines[1].Level)
	}
	// Tổng = 50 × 60 / (60 + 0) = 50 → loại D, thưởng = 1.000.000 × 50%
	if sh.Total != 50 || sh.Grade.Grade != "D" || *sh.Bonus != 500_000 || sh.Missing != 1 {
		t.Errorf("tổng %v loại %s thưởng %v thiếu %d", sh.Total, sh.Grade.Grade, *sh.Bonus, sh.Missing)
	}
	// Có buổi nghỉ không phép → mất toàn bộ KPI kỳ
	in.items[ko.ID] = kpidb.HrKpiItem{CriterionID: ko.ID, Actual: ptr("1")}
	if sh = buildSheet(in); sh.Total != 0 || sh.Knockout != "Nghỉ không phép" || sh.Grade.Grade != "E" {
		t.Errorf("knockout: %v %q", sh.Total, sh.Knockout)
	}
	// Bỏ đánh dấu Không phát sinh, điền 92% → 90; bằng chứng cap → mục tối đa 50%, phiếu tối đa loại B
	delete(in.items, ko.ID)
	in.items[rate.ID] = kpidb.HrKpiItem{CriterionID: rate.ID, Actual: ptr("92")}
	in.measured[late.ID] = measured{Live: true, Value: f(0), Evidence: []Evidence{}}
	sh = buildSheet(in)
	if *sh.Lines[0].Level != 90 || sh.Total != 96 || sh.Grade.Grade != "A" {
		t.Errorf("tổng %v loại %s", sh.Total, sh.Grade.Grade)
	}
	in.measured[late.ID] = measured{Live: true, Value: f(0), Evidence: []Evidence{{Text: "order", Counted: true, Cap: true, Month: "2026-12"}}}
	sh = buildSheet(in)
	if !sh.Lines[1].Cap || *sh.Lines[1].Level != 50 || sh.Total != 66 || sh.Grade.Grade != "D" {
		t.Errorf("cap: %v %v %s", *sh.Lines[1].Level, sh.Total, sh.Grade.Grade)
	}
	// Tổng ≥ 95 nhưng có bằng chứng cap → tối đa loại B
	light := late
	light.Weight = 2
	in.criteria = []Criterion{rate, light, ko}
	in.items[rate.ID] = kpidb.HrKpiItem{CriterionID: rate.ID, Actual: ptr("100")}
	if sh = buildSheet(in); sh.Total < 95 || sh.Grade.Grade != "B" {
		t.Errorf("cap loại: %v %s", sh.Total, sh.Grade.Grade)
	}
}

func TestBuildSheetFrozen(t *testing.T) {
	c := academic("1.1 Báo cáo", 100, 0, 2, "daily_report_late")
	in := sheetInput{role: "academic_staff", period: makePeriod(1, 9, 2026), criteria: []Criterion{c}, frozen: true, frozenTotal: 77.5,
		fund: f(2_000_000), items: map[uuid.UUID]kpidb.HrKpiItem{c.ID: {CriterionID: c.ID, Actual: ptr("1"), Score: 50,
			Evidence: []byte(`[{"date":"02/09","month":"2026-09","text":"Báo cáo trễ","counted":true,"note":null}]`)}}}
	sh := buildSheet(in)
	if sh.Total != 77.5 || *sh.Lines[0].Level != 50 || !sh.Lines[0].Auto || len(sh.Lines[0].Evidence) != 1 || *sh.Amount != 1_550_000 {
		t.Errorf("phiếu đã duyệt: %+v", sh)
	}
}

func TestFactorRate(t *testing.T) {
	from := time.Date(2026, 10, 1, 0, 0, 0, 0, vnZone)
	calls := []time.Time{}
	rows := func(start time.Time) ([]kpisrc.Record, error) {
		calls = append(calls, start)
		out := []kpisrc.Record{
			{At: from.AddDate(0, 0, 3), Text: "Việc A", Factor: kpisrc.F(1)},
			{At: from.AddDate(0, 0, 1), Text: "Việc B", Factor: kpisrc.F(0.5)},
			{At: from.AddDate(0, 0, 2), Text: "Order sát giờ", Note: "không tính"},
		}
		if start.Before(from) {
			out = append(out, kpisrc.Record{At: from.AddDate(0, 0, -5), Text: "Việc tháng trước", Factor: kpisrc.F(0.25)})
		}
		return out, nil
	}
	v, ev, err := factorRate(rows, from)
	if err != nil {
		t.Fatal(err)
	}
	if len(calls) != 2 || calls[1].Format("2006-01-02") != "2026-09-01" {
		t.Fatalf("gộp tháng trước: %v", calls)
	}
	// (1 + 0,5 + 0,25) / 3 = 58,3%
	if *v != 58.3 || ev[0].Text != "Tháng có ít hơn 3 bản ghi: gộp thêm tháng trước" || ev[1].Text != "Việc tháng trước · hệ số 0,25" {
		t.Errorf("v = %v, ev = %+v", *v, ev)
	}
	if ev[3].Counted || *ev[3].Note != "không tính" || ev[2].Date != "02/10" {
		t.Errorf("dòng không tính: %+v", ev[3])
	}
	empty := func(time.Time) ([]kpisrc.Record, error) { return nil, nil }
	if v, _, _ := factorRate(empty, from); v != nil {
		t.Error("không có bản ghi → nil")
	}
}

func TestSimpleRules(t *testing.T) {
	two := Line{C: academic("1.1 A", 10, 0, 2, "")}
	many := Line{C: Criterion{Measure: "count", Tiers: []Tier{{0, 100}, {1, 60}, {2, 30}}}}
	noRule := Line{C: Criterion{Measure: "rate"}}
	if !simpleRules([]Line{two, noRule}) || simpleRules([]Line{two, many}) {
		t.Error("simpleRules sai")
	}
}
