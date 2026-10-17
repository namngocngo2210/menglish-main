package payroll

import (
	"testing"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/payroll/payrolldb"
)

func TestApplyLateRules(t *testing.T) {
	st := Settings{LateThreshold: 15, LatePerMinute: 5000}
	ts := Timesheet{ScheduledTime: "18:00-19:30", Hours: 1.5}
	if o := applyLateRules(ts, 300000, st); o.Amount != 300000 || !o.Counted || o.LateRule != "" {
		t.Errorf("đúng giờ: %+v", o)
	}
	ts.LateMinutes = 10
	if o := applyLateRules(ts, 300000, st); o.Amount != 250000 || o.LateRule != "deduct" || o.LateDeduction != 50000 {
		t.Errorf("muộn 10 phút: %+v", o)
	}
	ts.LateMinutes, ts.EarlyLeaveMinutes = 10, 5
	if o := applyLateRules(ts, 300000, st); o.Counted || o.Amount != 0 || o.LateRule != "void" {
		t.Errorf("muộn + về sớm 15 phút: %+v", o)
	}
	ts.LateNotified = true
	// Báo trước: trả theo phút thực dạy 75/90
	if o := applyLateRules(ts, 300000, st); o.Amount != 250000 || o.LateRule != "notified" || !o.Counted {
		t.Errorf("có báo trước: %+v", o)
	}
	// Trừ không vượt quá tiền ca
	ts = Timesheet{Hours: 1, LateMinutes: 14}
	if o := applyLateRules(ts, 50000, st); o.Amount != 0 || o.LateDeduction != 50000 {
		t.Errorf("trừ vượt tiền ca: %+v", o)
	}
}

func TestTuitionBase(t *testing.T) {
	if v := tuitionBase(5000000, 0, ptr[int64](5000000), ptr[int64](0)); v != 5000000 {
		t.Errorf("không phí khác: %d", v)
	}
	// Phụ thu bỏ ra; phí khác 1tr/5tr → 80% phần còn lại
	if v := tuitionBase(5200000, 200000, ptr[int64](5000000), ptr[int64](1000000)); v != 4000000 {
		t.Errorf("có phụ thu + phí khác: %d", v)
	}
	if v := tuitionBase(100000, 200000, nil, nil); v != 0 {
		t.Errorf("phụ thu lớn hơn số thu: %d", v)
	}
}

func TestTiersAndMilestone(t *testing.T) {
	tiers := []payrolldb.HrCommissionTier{
		{TierName: "Mốc 1", MinStudents: 0, MaxStudents: ptr[int32](5), NewSalePercent: 3},
		{TierName: "Mốc 2", MinStudents: 6, MaxStudents: ptr[int32](10), NewSalePercent: 4},
		{TierName: "Mốc 3", MinStudents: 11, NewSalePercent: 5},
	}
	for n, want := range map[int]float64{1: 3, 5: 3, 6: 4, 10: 4, 11: 5, 40: 5} {
		if got := tierFor(tiers, n); got == nil || got.NewSalePercent != want {
			t.Errorf("HS thứ %d: %v", n, got)
		}
	}
	m := milestoneFor(tiers, 4)
	if m.Percent != 3 || m.NextPercent == nil || *m.NextPercent != 4 || *m.ToNext != 2 || *m.Range != "HS thứ 1–5" {
		t.Errorf("mốc của 4 HS: %+v", m)
	}
	if m := milestoneFor(tiers, 12); m.NextPercent != nil || *m.Range != "Từ HS thứ 11" {
		t.Errorf("mốc cuối: %+v", m)
	}
	if m := milestoneFor(nil, 3); m.TierName != "Chưa cấu hình mốc" {
		t.Errorf("chưa cấu hình: %+v", m)
	}
}

func TestGate(t *testing.T) {
	end := time.Date(2026, 9, 30, 0, 0, 0, 0, vnZone)
	closed := time.Date(2026, 8, 31, 15, 0, 0, 0, vnZone)
	if g := gateOf(&closed, 3, end); !g.Passes || g.Reason != nil {
		t.Errorf("đủ 30 ngày + 3 mốc: %+v", g)
	}
	closed = time.Date(2026, 9, 1, 0, 0, 0, 0, vnZone)
	g := gateOf(&closed, 2, end)
	if g.Passes || *g.Reason != "Hoãn: chưa đủ 30 ngày từ ngày chốt 01/09/2026 (đủ ngày 01/10/2026); chăm sóc tháng đầu mới 2/3 mốc" {
		t.Errorf("chưa đủ: %+v %s", g, deref(g.Reason))
	}
	if g := gateOf(nil, 3, end); g.Passes || *g.Reason != "Hoãn: chưa xác định ngày chốt" {
		t.Errorf("không có ngày chốt: %s", deref(g.Reason))
	}
}

func TestRetention(t *testing.T) {
	start, end := time.Date(2026, 9, 1, 0, 0, 0, 0, time.UTC), time.Date(2026, 9, 30, 0, 0, 0, 0, time.UTC)
	never := time.Date(9999, 12, 31, 0, 0, 0, 0, time.UTC)
	a, b, c, d := uuid.New(), uuid.New(), uuid.New(), uuid.New()
	c1, c2 := uuid.New(), uuid.New()
	rows := []payrolldb.RetentionEnrollmentsRow{
		{StudentID: a, ClassID: c1, DroppedOn: never, LostOn: never, Status: "active", StudentStatus: "studying"},
		{StudentID: b, ClassID: c1, DroppedOn: time.Date(2026, 9, 10, 0, 0, 0, 0, time.UTC), LostOn: never, Status: "dropped", StudentStatus: "dropped"},
		// Nghỉ lớp này nhưng còn học lớp khác trong tập lớp
		{StudentID: c, ClassID: c1, DroppedOn: time.Date(2026, 9, 12, 0, 0, 0, 0, time.UTC), LostOn: never, Status: "dropped", StudentStatus: "studying"},
		{StudentID: c, ClassID: c2, DroppedOn: never, LostOn: never, Status: "active", StudentStatus: "studying"},
		// Nghỉ trước kỳ: không tính
		{StudentID: d, ClassID: c1, DroppedOn: time.Date(2026, 8, 20, 0, 0, 0, 0, time.UTC), LostOn: never, Status: "dropped", StudentStatus: "dropped"},
	}
	r := retentionOf(rows, start, end)
	if r.Base != 3 || r.Lost != 1 || r.Retained != 2 || len(r.LostIDs) != 1 || r.LostIDs[0] != b {
		t.Errorf("giữ HS: %+v", r)
	}
}

func TestApplyManualInputs(t *testing.T) {
	r := payrolldb.UpsertRecordParams{EmployeeType: ptr(typeFulltime), KpiSource: ptr(kpiManual), KpiManualAmount: ptr[int64](500000),
		BaseSalary: 10000000, InsuranceDeduction: 1050000, UnionDeduction: 50000, TaxDeduction: 100000, PenaltyDeduction: 200000,
		ManualLines: []byte(`[{"kind":"earning","label":"Gửi xe","amount":100000},{"kind":"deduction","label":"Ứng lương","amount":1000000}]`)}
	applyManualInputs(&r)
	if r.KpiBonus != 500000 || r.Allowance != 100000 || r.OtherDeduction != 1000000 || r.NetSalary != 10600000-2400000 {
		t.Errorf("full-time: %+v", r)
	}
	p := payrolldb.UpsertRecordParams{EmployeeType: ptr(typeParttime), KpiSource: ptr(kpiRetention), RetentionStudents: 8,
		RetentionTier: ptr[int64](20000), TeachingSalary: 550000, TaxDeduction: 99, InsuranceDeduction: 5, ManualLines: []byte("[]")}
	applyManualInputs(&p)
	if p.KpiBonus != 160000 || p.TaxDeduction != 0 || p.InsuranceDeduction != 0 || p.NetSalary != 710000 {
		t.Errorf("part-time: %+v", p)
	}
	// Âm → 0
	n := payrolldb.UpsertRecordParams{EmployeeType: ptr(typeFulltime), KpiSource: ptr(kpiManual), PenaltyDeduction: 100, ManualLines: []byte("[]")}
	if applyManualInputs(&n); n.NetSalary != 0 {
		t.Errorf("thực lĩnh âm: %d", n.NetSalary)
	}
}

func TestKpiState(t *testing.T) {
	rec := func(src string) payrolldb.HrPayrollRecord {
		return payrolldb.HrPayrollRecord{EmployeeType: ptr(typeFulltime), SalaryRole: ptr("staff"), KpiSource: ptr(src)}
	}
	if s := kpiState(rec(kpiManual)); s[0] != "pending" {
		t.Errorf("nhập tự do chưa nhập: %v", s)
	}
	r := rec(kpiManual)
	r.KpiManualAmount = ptr[int64](0)
	if s := kpiState(r); s[0] != "done" {
		t.Errorf("đã nhập 0: %v", s)
	}
	r = rec(kpiRetention)
	if s := kpiState(r); s[0] != "na" {
		t.Errorf("không có HS đầu kỳ: %v", s)
	}
	r.RetentionBaseStudents = 4
	if s := kpiState(r); s[0] != "pending" {
		t.Errorf("chưa chọn bậc: %v", s)
	}
	r = rec(kpiSelf)
	r.KpiScore = ptr(85.5)
	if s := kpiState(r); s[0] != "self" || s[1] != "KPI 85,5%" {
		t.Errorf("admin: %v", s)
	}
}

func TestMoneyFormat(t *testing.T) {
	for v, want := range map[int64]string{0: "0 đ", 999: "999 đ", 1000: "1.000 đ", 1500000: "1.500.000 đ", -25000: "-25.000 đ"} {
		if got := money(v); got != want {
			t.Errorf("money(%d) = %q", v, got)
		}
	}
}
