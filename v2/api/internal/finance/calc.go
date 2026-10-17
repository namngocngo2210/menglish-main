package finance

import (
	"math"
	"regexp"
	"strconv"
	"strings"

	"github.com/google/uuid"
)

// Phép tính thuần của sổ chi / báo cáo doanh thu tạm tính (không đụng DB) — cùng công thức FinanceController.

// round1: làm tròn 1 chữ số thập phân, nửa ra xa số 0 như round() của PHP.
func round1(v float64) float64 { return math.Round(v*10) / 10 }

// share: tỉ lệ % part / total (0 khi total ≤ 0, như "$total > 0 ? … : 0"), làm tròn digits chữ số.
func share(part, total int64, digits int) float64 {
	if total <= 0 {
		return 0
	}
	p := math.Pow10(digits)
	return math.Round(float64(part)/float64(total)*100*p) / p
}

// Ngưỡng tỷ suất gộp (%) đánh giá chi nhánh — dùng chung cho màn hình và file xuất.
const (
	marginGood   = 69
	marginTarget = 60
)

// MarginStatus: tình trạng chi nhánh theo tỷ suất gộp (branchMarginStatus).
type MarginStatus struct {
	Key   string `json:"key"` // good | target | stable | optimize
	Label string `json:"label"`
}

func marginStatus(margin float64) MarginStatus {
	switch {
	case margin >= marginGood:
		return MarginStatus{"good", "Tăng trưởng tốt"}
	case margin >= marginTarget:
		return MarginStatus{"target", "Đạt chỉ tiêu"}
	case margin >= 0:
		return MarginStatus{"stable", "Ổn định"}
	default:
		return MarginStatus{"optimize", "Cần tối ưu"}
	}
}

// vnd: định dạng số tiền như number_format($v, 0, ',', '.'): 1500000 → "1.500.000".
func vnd(v int64) string {
	s := strconv.FormatInt(v, 10)
	neg := strings.HasPrefix(s, "-")
	if neg {
		s = s[1:]
	}
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

// pctLabel: "12.5%" như "$margin.'%'" của PHP (số nguyên không có phần thập phân).
func pctLabel(v float64) string { return strconv.FormatFloat(v, 'f', -1, 64) + "%" }

// Hình thức chuyển khoản của phiếu thu (mã mới transfer và mã cũ chuyen_khoan).
func isTransfer(m string) bool { return m == "transfer" || m == "chuyen_khoan" }

// ───────────── Phân loại khoản chi ─────────────

// Nhóm chi phí (OperatingExpense::category_label).
var categoryLabels = map[string]string{
	"mat_bang_tien_ich":   "Mặt bằng & Tiện ích",
	"giao_trinh_van_hanh": "In ấn & Vận hành lớp",
	"khac":                "Chi phí khác",
}

var (
	rentWords       = []string{"mặt bằng", "thuê", "tiền điện", "tiền nước", "internet", "wifi", "cáp quang", "vệ sinh"}
	operationsWords = []string{"in ấn", "giáo trình", "sách", "văn phòng phẩm", "bút", "mực", "điều hòa", "nước uống", "sửa chữa"}
)

// autoCategory: tự phân loại theo nội dung khi người lập không chọn (storeExpense).
func autoCategory(title string) string {
	t := strings.ToLower(title)
	has := func(words []string) bool {
		for _, w := range words {
			if strings.Contains(t, w) {
				return true
			}
		}
		return false
	}
	switch {
	case has(rentWords):
		return "mat_bang_tien_ich"
	case has(operationsWords):
		return "giao_trinh_van_hanh"
	}
	return "khac"
}

// ───────────── Ô bảng tính an toàn ─────────────

var signedNumber = regexp.MustCompile(`^[+-][\d.,]+(\s?[\p{L}%]{1,5})?$`)

// cellSafe chống "CSV / formula injection" như SpreadsheetCell::safe: ô bắt đầu bằng = @ tab xuống dòng, hoặc + - mà
// không phải số (vd. "-1.500.000 đ"), được đặt dấu nháy đơn đứng trước.
func cellSafe(v string) string {
	if v == "" {
		return v
	}
	switch v[0] {
	case '=', '@', '\t', '\r':
		return "'" + v
	case '+', '-':
		if !signedNumber.MatchString(v) {
			return "'" + v
		}
	}
	return v
}

func safeRow(row []string) []string {
	out := make([]string, len(row))
	for i, c := range row {
		out[i] = cellSafe(c)
	}
	return out
}

// ───────────── Báo cáo doanh thu tạm tính ─────────────

// ExpenseSum: tổng khoản chi tự nhập theo chi nhánh / nhóm / hình thức.
type ExpenseSum struct {
	BranchID      *uuid.UUID
	Category      string
	PaymentMethod string
	Items         int
	Amount        int64
}

// reportInput: số liệu đã nạp cho một tháng.
type reportInput struct {
	Branches []BranchRef // chi nhánh đang hoạt động trong phạm vi (ma trận)
	Filter   *uuid.UUID  // chi nhánh đang xem, nil = tất cả
	Revenue  []RevenueRow
	// Doanh thu tháng trước (so sánh)
	PrevRevenue []RevenueRow
	Expenses    []ExpenseSum
	// Chi lương của bộ lọc (tất cả / chi nhánh) và của từng chi nhánh trong ma trận
	Salary       int64
	BranchSalary map[uuid.UUID]int64
}

// MatrixRow: một chi nhánh trong ma trận so sánh hiệu quả.
type MatrixRow struct {
	Branch  BranchRef    `json:"branch"`
	Revenue int64        `json:"revenue"`
	Expense int64        `json:"expense"`
	Profit  int64        `json:"profit"`
	Margin  float64      `json:"margin"`
	Status  MarginStatus `json:"status"`
}

// Report: số liệu màn Báo cáo doanh thu tạm tính (tên trường như props của ProvisionalRevenue.vue).
type Report struct {
	TotalRevenue           int64   `json:"total_revenue"`
	ValidReceiptsCount     int     `json:"valid_receipts_count"`
	TuitionRevenue         int64   `json:"tuition_revenue"`
	SurchargeRevenue       int64   `json:"surcharge_revenue"`
	TuitionPercent         float64 `json:"tuition_percent"`
	SurchargePercent       float64 `json:"surcharge_percent"`
	RevenueTransferPercent float64 `json:"revenue_transfer_percent"`
	RevenueCashPercent     float64 `json:"revenue_cash_percent"`
	RevenueDiffPercent     float64 `json:"revenue_diff_percent"`
	RevenueDiffIsUp        bool    `json:"revenue_diff_is_up"`

	TotalExpense               int64   `json:"total_expense"`
	ExpensePercentageOfRevenue float64 `json:"expense_percentage_of_revenue"`
	TotalExpenseItemsCount     int     `json:"total_expense_items_count"`
	SalaryTotal                int64   `json:"salary_total"`
	RentUtilitiesExpense       int64   `json:"rent_utilities_expense"`
	CurriculumOperations       int64   `json:"curriculum_operations_expense"`
	OtherExpense               int64   `json:"other_expense"`
	SalaryPercentOfExpense     float64 `json:"salary_percent_of_expense"`
	RentPercentOfExpense       float64 `json:"rent_percent_of_expense"`
	CurriculumPercentOfExpense float64 `json:"curriculum_percent_of_expense"`
	OtherPercentOfExpense      float64 `json:"other_percent_of_expense"`

	ProvisionalRevenue int64   `json:"provisional_revenue"`
	IsProfitPositive   bool    `json:"is_profit_positive"`
	GrossProfitMargin  float64 `json:"gross_profit_margin"`

	BranchMatrix       []MatrixRow  `json:"branch_matrix"`
	TotalMatrixRevenue int64        `json:"total_matrix_revenue"`
	TotalMatrixExpense int64        `json:"total_matrix_expense"`
	TotalMatrixProfit  int64        `json:"total_matrix_profit"`
	TotalMatrixMargin  float64      `json:"total_matrix_margin"`
	TotalMatrixStatus  MarginStatus `json:"total_matrix_status"`
}

func sameBranch(a, b *uuid.UUID) bool { return b == nil || (a != nil && *a == *b) }

// buildReport: Doanh thu tạm tính = Tổng thu (phiếu đã duyệt) − Chi vận hành (chi tự nhập + chi lương), kèm bóc tách
// nguồn thu, cơ cấu chi và ma trận chi nhánh (provisionalRevenue của Laravel).
func buildReport(in reportInput) Report {
	var r Report
	var transfer int64
	for _, row := range in.Revenue {
		if !sameBranch(row.BranchID, in.Filter) {
			continue
		}
		r.TotalRevenue += row.Amount
		r.ValidReceiptsCount += row.Receipts
		r.SurchargeRevenue += row.Surcharge
		if isTransfer(row.PaymentMethod) {
			transfer += row.Amount
		}
	}
	r.TuitionRevenue = r.TotalRevenue - r.SurchargeRevenue
	r.TuitionPercent = share(r.TuitionRevenue, r.TotalRevenue, 1)
	r.SurchargePercent = share(r.SurchargeRevenue, r.TotalRevenue, 1)
	// Tiền mặt = phần còn lại (100 − chuyển khoản) như Laravel
	if r.TotalRevenue > 0 {
		r.RevenueTransferPercent = share(transfer, r.TotalRevenue, 0)
		r.RevenueCashPercent = 100 - r.RevenueTransferPercent
	}

	var prev int64
	for _, row := range in.PrevRevenue {
		if sameBranch(row.BranchID, in.Filter) {
			prev += row.Amount
		}
	}
	r.RevenueDiffIsUp = true
	if prev > 0 {
		diff := float64(r.TotalRevenue-prev) / float64(prev) * 100
		r.RevenueDiffPercent = math.Abs(round1(diff))
		r.RevenueDiffIsUp = diff >= 0
	}

	var manual int64
	items := 0
	for _, e := range in.Expenses {
		if !sameBranch(e.BranchID, in.Filter) {
			continue
		}
		manual += e.Amount
		items += e.Items
		switch e.Category {
		case "mat_bang_tien_ich":
			r.RentUtilitiesExpense += e.Amount
		case "giao_trinh_van_hanh":
			r.CurriculumOperations += e.Amount
		case "khac":
			r.OtherExpense += e.Amount
		}
	}
	r.SalaryTotal = in.Salary
	r.TotalExpense = manual + in.Salary
	r.ExpensePercentageOfRevenue = share(r.TotalExpense, r.TotalRevenue, 1)
	r.TotalExpenseItemsCount = items
	if in.Salary > 0 {
		r.TotalExpenseItemsCount++
	}
	r.SalaryPercentOfExpense = share(in.Salary, r.TotalExpense, 1)
	r.RentPercentOfExpense = share(r.RentUtilitiesExpense, r.TotalExpense, 1)
	r.CurriculumPercentOfExpense = share(r.CurriculumOperations, r.TotalExpense, 1)
	r.OtherPercentOfExpense = share(r.OtherExpense, r.TotalExpense, 1)

	r.ProvisionalRevenue = r.TotalRevenue - r.TotalExpense
	r.IsProfitPositive = r.ProvisionalRevenue >= 0
	r.GrossProfitMargin = share(r.ProvisionalRevenue, r.TotalRevenue, 1)

	r.BranchMatrix = matrix(in)
	for _, m := range r.BranchMatrix {
		r.TotalMatrixRevenue += m.Revenue
		r.TotalMatrixExpense += m.Expense
	}
	r.TotalMatrixProfit = r.TotalMatrixRevenue - r.TotalMatrixExpense
	r.TotalMatrixMargin = share(r.TotalMatrixProfit, r.TotalMatrixRevenue, 1)
	r.TotalMatrixStatus = marginStatus(r.TotalMatrixMargin)
	return r
}

// matrix: mỗi chi nhánh trong phạm vi một dòng (doanh thu, chi tự nhập + lương nhân sự chi nhánh, tỷ suất, tình trạng).
func matrix(in reportInput) []MatrixRow {
	out := make([]MatrixRow, 0, len(in.Branches))
	for _, b := range in.Branches {
		id := b.ID
		row := MatrixRow{Branch: b}
		for _, rv := range in.Revenue {
			if rv.BranchID != nil && *rv.BranchID == id {
				row.Revenue += rv.Amount
			}
		}
		for _, e := range in.Expenses {
			if e.BranchID != nil && *e.BranchID == id {
				row.Expense += e.Amount
			}
		}
		row.Expense += in.BranchSalary[id]
		row.Profit = row.Revenue - row.Expense
		row.Margin = share(row.Profit, row.Revenue, 1)
		row.Status = marginStatus(row.Margin)
		out = append(out, row)
	}
	return out
}
