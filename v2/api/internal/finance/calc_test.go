package finance

import (
	"slices"
	"testing"
	"time"

	"github.com/google/uuid"
)

// Số liệu mẫu: 2 chi nhánh A, B + phiếu không xác định chi nhánh.
func sampleInput() (reportInput, uuid.UUID, uuid.UUID) {
	a, b := uuid.New(), uuid.New()
	in := reportInput{
		Branches: []BranchRef{{a, "Chi nhánh A"}, {b, "Chi nhánh B"}},
		Revenue: []RevenueRow{
			{BranchID: &a, PaymentMethod: "transfer", Receipts: 2, Amount: 10_000_000, Surcharge: 1_000_000},
			{BranchID: &a, PaymentMethod: "cash", Receipts: 1, Amount: 5_000_000},
			{BranchID: &b, PaymentMethod: "chuyen_khoan", Receipts: 1, Amount: 3_000_000},
			{BranchID: nil, PaymentMethod: "tien_mat", Receipts: 1, Amount: 2_000_000},
		},
		PrevRevenue: []RevenueRow{{BranchID: &a, PaymentMethod: "cash", Receipts: 4, Amount: 16_000_000}},
		Expenses: []ExpenseSum{
			{BranchID: &a, Category: "mat_bang_tien_ich", PaymentMethod: "chuyen_khoan", Items: 1, Amount: 2_000_000},
			{BranchID: &a, Category: "khac", PaymentMethod: "tien_mat", Items: 2, Amount: 1_000_000},
			{BranchID: &b, Category: "giao_trinh_van_hanh", PaymentMethod: "tien_mat", Items: 1, Amount: 500_000},
		},
		Salary:       4_000_000,
		BranchSalary: map[uuid.UUID]int64{a: 3_000_000, b: 1_000_000},
	}
	return in, a, b
}

func TestBuildReportAllBranches(t *testing.T) {
	in, _, _ := sampleInput()
	r := buildReport(in)
	check := func(name string, got, want any) {
		t.Helper()
		if got != want {
			t.Errorf("%s = %v, muốn %v", name, got, want)
		}
	}
	check("TotalRevenue", r.TotalRevenue, int64(20_000_000))
	check("ValidReceiptsCount", r.ValidReceiptsCount, 5)
	check("TuitionRevenue", r.TuitionRevenue, int64(19_000_000))
	check("SurchargeRevenue", r.SurchargeRevenue, int64(1_000_000))
	check("TuitionPercent", r.TuitionPercent, 95.0)
	check("SurchargePercent", r.SurchargePercent, 5.0)
	check("RevenueTransferPercent", r.RevenueTransferPercent, 65.0)
	check("RevenueCashPercent", r.RevenueCashPercent, 35.0)
	check("RevenueDiffPercent", r.RevenueDiffPercent, 25.0)
	check("RevenueDiffIsUp", r.RevenueDiffIsUp, true)

	check("TotalExpense", r.TotalExpense, int64(7_500_000))
	check("ExpensePercentageOfRevenue", r.ExpensePercentageOfRevenue, 37.5)
	check("TotalExpenseItemsCount", r.TotalExpenseItemsCount, 5) // 4 khoản tự nhập + 1 dòng lương
	check("SalaryTotal", r.SalaryTotal, int64(4_000_000))
	check("RentUtilitiesExpense", r.RentUtilitiesExpense, int64(2_000_000))
	check("CurriculumOperations", r.CurriculumOperations, int64(500_000))
	check("OtherExpense", r.OtherExpense, int64(1_000_000))
	check("SalaryPercentOfExpense", r.SalaryPercentOfExpense, 53.3)
	check("RentPercentOfExpense", r.RentPercentOfExpense, 26.7)
	check("CurriculumPercentOfExpense", r.CurriculumPercentOfExpense, 6.7)
	check("OtherPercentOfExpense", r.OtherPercentOfExpense, 13.3)

	check("ProvisionalRevenue", r.ProvisionalRevenue, int64(12_500_000))
	check("IsProfitPositive", r.IsProfitPositive, true)
	check("GrossProfitMargin", r.GrossProfitMargin, 62.5)

	if len(r.BranchMatrix) != 2 {
		t.Fatalf("ma trận %d dòng", len(r.BranchMatrix))
	}
	ma, mb := r.BranchMatrix[0], r.BranchMatrix[1]
	check("A.Revenue", ma.Revenue, int64(15_000_000))
	check("A.Expense", ma.Expense, int64(6_000_000))
	check("A.Profit", ma.Profit, int64(9_000_000))
	check("A.Margin", ma.Margin, 60.0)
	check("A.Status", ma.Status.Label, "Đạt chỉ tiêu")
	check("B.Revenue", mb.Revenue, int64(3_000_000))
	check("B.Expense", mb.Expense, int64(1_500_000))
	check("B.Margin", mb.Margin, 50.0)
	check("B.Status", mb.Status.Key, "stable")
	// Phiếu không xác định chi nhánh không vào ma trận
	check("TotalMatrixRevenue", r.TotalMatrixRevenue, int64(18_000_000))
	check("TotalMatrixExpense", r.TotalMatrixExpense, int64(7_500_000))
	check("TotalMatrixProfit", r.TotalMatrixProfit, int64(10_500_000))
	check("TotalMatrixMargin", r.TotalMatrixMargin, 58.3)
	check("TotalMatrixStatus", r.TotalMatrixStatus.Label, "Ổn định")
}

func TestBuildReportFilteredBranch(t *testing.T) {
	in, a, _ := sampleInput()
	in.Filter = &a
	in.Salary = 3_000_000
	r := buildReport(in)
	if r.TotalRevenue != 15_000_000 || r.ValidReceiptsCount != 3 {
		t.Fatalf("doanh thu chi nhánh A = %d / %d phiếu", r.TotalRevenue, r.ValidReceiptsCount)
	}
	if r.RevenueTransferPercent != 67 || r.RevenueCashPercent != 33 {
		t.Errorf("cơ cấu thu %v / %v", r.RevenueTransferPercent, r.RevenueCashPercent)
	}
	// (15 − 16) / 16 = −6,25% → làm tròn ra xa số 0 → 6,3% giảm
	if r.RevenueDiffPercent != 6.3 || r.RevenueDiffIsUp {
		t.Errorf("so tháng trước %v up=%v", r.RevenueDiffPercent, r.RevenueDiffIsUp)
	}
	if r.TotalExpense != 6_000_000 || r.TotalExpenseItemsCount != 4 || r.CurriculumOperations != 0 {
		t.Errorf("chi phí %d / %d khoản / giáo trình %d", r.TotalExpense, r.TotalExpenseItemsCount, r.CurriculumOperations)
	}
	if r.ProvisionalRevenue != 9_000_000 || r.GrossProfitMargin != 60 {
		t.Errorf("tạm tính %d / %v%%", r.ProvisionalRevenue, r.GrossProfitMargin)
	}
}

func TestBuildReportEmptyAndLoss(t *testing.T) {
	r := buildReport(reportInput{})
	if r.TotalRevenue != 0 || r.TuitionPercent != 0 || r.RevenueCashPercent != 0 || !r.RevenueDiffIsUp || !r.IsProfitPositive {
		t.Errorf("báo cáo rỗng: %+v", r)
	}
	if r.TotalMatrixStatus.Label != "Ổn định" || r.BranchMatrix == nil {
		t.Errorf("tình trạng rỗng %+v", r.TotalMatrixStatus)
	}
	// Chi lớn hơn thu → lỗ, tỷ suất âm, "Cần tối ưu"
	b := uuid.New()
	r = buildReport(reportInput{
		Branches: []BranchRef{{b, "B"}},
		Revenue:  []RevenueRow{{BranchID: &b, PaymentMethod: "cash", Receipts: 1, Amount: 1_000_000}},
		Expenses: []ExpenseSum{{BranchID: &b, Category: "khac", Items: 1, Amount: 3_000_000}},
	})
	if r.IsProfitPositive || r.ProvisionalRevenue != -2_000_000 || r.GrossProfitMargin != -200 {
		t.Errorf("lỗ: %d %v", r.ProvisionalRevenue, r.GrossProfitMargin)
	}
	if r.BranchMatrix[0].Status.Key != "optimize" || r.TotalExpenseItemsCount != 1 {
		t.Errorf("chi nhánh lỗ %+v", r.BranchMatrix[0])
	}
	// Không có lương: không cộng dòng lương vào số khoản chi
	if r.SalaryPercentOfExpense != 0 {
		t.Errorf("tỉ lệ lương %v", r.SalaryPercentOfExpense)
	}
}

func TestMarginStatus(t *testing.T) {
	for _, c := range []struct {
		m    float64
		want string
	}{{80, "good"}, {69, "good"}, {68.9, "target"}, {60, "target"}, {59.9, "stable"}, {0, "stable"}, {-0.1, "optimize"}} {
		if got := marginStatus(c.m).Key; got != c.want {
			t.Errorf("marginStatus(%v) = %s, muốn %s", c.m, got, c.want)
		}
	}
}

func TestFormatting(t *testing.T) {
	for v, want := range map[int64]string{0: "0", 999: "999", 1000: "1.000", 1_500_000: "1.500.000", -2_000_000: "-2.000.000"} {
		if got := vnd(v); got != want {
			t.Errorf("vnd(%d) = %s", v, got)
		}
	}
	if pctLabel(62.5) != "62.5%" || pctLabel(60) != "60%" || pctLabel(-200) != "-200%" {
		t.Errorf("pctLabel: %s %s", pctLabel(62.5), pctLabel(60))
	}
	if share(1, 3, 1) != 33.3 || share(2, 3, 0) != 67 || share(5, 0, 1) != 0 {
		t.Errorf("share sai")
	}
}

func TestCellSafe(t *testing.T) {
	for in, want := range map[string]string{
		"=SUM(A1)": "'=SUM(A1)", "@cmd": "'@cmd", "+1.500.000": "+1.500.000", "-1.500.000 đ": "-1.500.000 đ",
		"-62.5%": "-62.5%", "+abc": "'+abc", "-1+1": "'-1+1", "Tiền điện": "Tiền điện", "": "",
	} {
		if got := cellSafe(in); got != want {
			t.Errorf("cellSafe(%q) = %q, muốn %q", in, got, want)
		}
	}
}

func TestAutoCategory(t *testing.T) {
	for title, want := range map[string]string{
		"Tiền điện tháng 10":       "mat_bang_tien_ich",
		"Thanh toán THUÊ mặt bằng": "mat_bang_tien_ich",
		"In ấn giáo trình Starter": "giao_trinh_van_hanh",
		"Sửa chữa điều hòa":        "giao_trinh_van_hanh",
		"Tiếp khách":               "khac",
	} {
		if got := autoCategory(title); got != want {
			t.Errorf("autoCategory(%q) = %s, muốn %s", title, got, want)
		}
	}
}

func TestResolveBranchAndMonth(t *testing.T) {
	a, b, c := uuid.New(), uuid.New(), uuid.New()
	if resolveBranch(nil, "all") != nil || *resolveBranch(nil, c.String()) != c {
		t.Error("toàn hệ thống: all → nil, id → id")
	}
	sc := []uuid.UUID{a, b}
	if *resolveBranch(sc, "all") != a || *resolveBranch(sc, b.String()) != b || *resolveBranch(sc, c.String()) != a {
		t.Error("bị giới hạn: ngoài phạm vi / all → chi nhánh đầu")
	}
	now := time.Date(2026, 10, 17, 10, 0, 0, 0, vnZone)
	if got := parseMonth("2026-02", now); got.Format("2006-01-02") != "2026-02-01" || monthEnd(got).Format("02") != "28" {
		t.Errorf("parseMonth 2026-02 = %v", got)
	}
	if got := parseMonth("abc", now).Format("2006-01"); got != "2026-10" {
		t.Errorf("sai định dạng → tháng hiện tại, nhận %s", got)
	}
	opts := monthOptions(now)
	if len(opts) != 6 || opts[0].Label != "Tháng 10/2026 (Hiện tại)" || opts[5].Value != "2026-05" {
		t.Errorf("monthOptions %+v", opts)
	}
}

func TestRevenueSheet(t *testing.T) {
	in, _, _ := sampleInput()
	r := buildReport(in)
	start := time.Date(2026, 10, 1, 0, 0, 0, 0, vnZone)
	s := revenueSheet(start, false, r)
	if s.Name != "Bao_Cao_Doanh_Thu_Tam_Tinh_2026-10.csv" || len(s.Rows) != 4+2+1 {
		t.Fatalf("file %s, %d dòng", s.Name, len(s.Rows))
	}
	if !slices.Equal(s.Rows[4], []string{"Chi nhánh A", "15.000.000", "6.000.000", "9.000.000", "60%", "Đạt chỉ tiêu"}) {
		t.Errorf("dòng A %v", s.Rows[4])
	}
	if last := s.Rows[6]; last[0] != "TỔNG CỘNG TOÀN HỆ THỐNG" || last[4] != "58.3%" {
		t.Errorf("dòng tổng %v", last)
	}
	if revenueSheet(start, true, r).Rows[6][0] != "TỔNG CỘNG" {
		t.Error("đang lọc / bị giới hạn chi nhánh → TỔNG CỘNG")
	}
}
