package finance_test

import (
	"context"
	"encoding/csv"
	"net/url"
	"strings"
	"testing"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/finance"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam/iamtest"
)

// fakeRevenue: phiếu thu giả (phân hệ Học phí chưa gộp).
type fakeRevenue struct {
	rows map[string][]finance.RevenueRow
}

func (f fakeRevenue) Revenue(_ context.Context, from, _ time.Time) ([]finance.RevenueRow, error) {
	return f.rows[from.Format("2006-01")], nil
}
func (fakeRevenue) Pending() bool { return false }

type fakePayroll struct{ byBranch map[uuid.UUID]int64 }

func (f fakePayroll) Salary(_ context.Context, month time.Time, branch *uuid.UUID) (*finance.Salary, error) {
	if month.Format("2006-01") != "2026-10" {
		return nil, nil
	}
	var sum int64
	for b, v := range f.byBranch {
		if branch == nil || *branch == b {
			sum += v
		}
	}
	return &finance.Salary{Code: "PR-2026-10", Amount: sum, StaffCount: 3}, nil
}
func (fakePayroll) Pending() bool { return false }

var now = time.Date(2026, 10, 17, 9, 0, 0, 0, time.FixedZone("ICT", 7*3600))

// readCSV: bỏ BOM, số cột mỗi dòng khác nhau (dòng tiêu đề 1 cột).
func readCSV(raw []byte) ([][]string, error) {
	r := csv.NewReader(strings.NewReader(strings.TrimPrefix(string(raw), "\xEF\xBB\xBF")))
	r.FieldsPerRecord = -1
	return r.ReadAll()
}

func setup(t *testing.T, wire func(*finance.Service)) *iamtest.Env {
	e := iamtest.Setup(t, "finance.expenses", "core.audit_log")
	svc := finance.NewService(e.Pool, e.Log)
	svc.SetClock(func() time.Time { return now })
	if wire != nil {
		wire(svc)
	}
	e.Serve(finance.NewHandler(svc, e.Log).Routes)
	return e
}

func expense(branch uuid.UUID, title string, amount float64, extra map[string]any) map[string]any {
	in := map[string]any{"expense_date": "2026-10-05", "title": title, "amount": amount, "payment_method": "tien_mat",
		"branch_id": branch.String()}
	for k, v := range extra {
		in[k] = v
	}
	return in
}

func TestFinancePermissions(t *testing.T) {
	e := setup(t, nil)
	hn := e.Branch("HN01")
	iamtest.Expect(t, e.Anon().Do("GET", "/finance/expenses", nil), 401, "")
	// Giáo vụ có tuition.create nhưng không có finance.view → bị chặn cả nhóm route
	_, staff := e.Member("GV01", "Giáo vụ", []uuid.UUID{hn}, "academic_staff")
	iamtest.Expect(t, staff.Do("GET", "/finance/expenses", nil), 403, "")
	iamtest.Expect(t, staff.Do("POST", "/finance/expenses", expense(hn, "Tiền điện", 1_500_000, nil)), 403, "")
	iamtest.Expect(t, staff.Do("GET", "/finance/reports/provisional-revenue", nil), 403, "")
	_, teacher := e.Member("TE01", "Giáo viên", []uuid.UUID{hn}, "teacher_parttime")
	iamtest.Expect(t, teacher.Do("GET", "/finance/expenses/export", nil), 403, "")

	// Quản lý cơ sở mất tuition.create: vẫn xem được, không thêm / sửa / xóa
	e.Revoke("manager", "tuition.create")
	_, mgr := e.Member("QL01", "Quản lý", []uuid.UUID{hn}, "manager")
	r := mgr.Do("GET", "/finance/expenses", nil)
	iamtest.Expect(t, r, 200, "")
	if r.Body["can_manage"] != false {
		t.Errorf("can_manage = %v", r.Body["can_manage"])
	}
	iamtest.Expect(t, mgr.Do("POST", "/finance/expenses", expense(hn, "Tiền điện", 1_500_000, nil)), 403, "")
	iamtest.Expect(t, mgr.Do("DELETE", "/finance/expenses/"+uuid.NewString(), nil), 403, "")

	// Quản lý chưa gán chi nhánh (phạm vi Chi nhánh) → chặn với thông báo phạm vi
	_, lost := e.Member("QL02", "Quản lý lạc", nil, "manager")
	r = lost.Do("GET", "/finance/expenses", nil)
	iamtest.Expect(t, r, 403, "no_branch")
	if !strings.Contains(r.Body["message"].(string), "finance.scope_all") {
		t.Errorf("thông báo: %v", r.Body["message"])
	}
}

func TestFinanceExpensesValidation(t *testing.T) {
	e := setup(t, nil)
	hn := e.Branch("HN01")
	_, admin := e.Member("AD01", "Quản trị", nil, "admin")
	for _, c := range []struct {
		patch map[string]any
		msg   string
	}{
		{map[string]any{"expense_date": ""}, "Vui lòng chọn ngày chi."},
		{map[string]any{"expense_date": "05/10/2026"}, "Ngày chi không hợp lệ."},
		{map[string]any{"title": "  "}, "Vui lòng nhập nội dung khoản chi."},
		{map[string]any{"title": strings.Repeat("a", 256)}, "Nội dung khoản chi tối đa 255 ký tự."},
		{map[string]any{"amount": nil}, "Vui lòng nhập số tiền chi."},
		{map[string]any{"amount": 999}, "Số tiền chi tối thiểu là 1.000 đ."},
		{map[string]any{"payment_method": ""}, "Vui lòng chọn hình thức chi."},
		{map[string]any{"payment_method": "the"}, "Hình thức chi không hợp lệ."},
		{map[string]any{"branch_id": ""}, "Vui lòng chọn chi nhánh áp dụng."},
		{map[string]any{"branch_id": uuid.NewString()}, "Chi nhánh đã chọn không tồn tại."},
		{map[string]any{"notes": strings.Repeat("g", 1001)}, "Ghi chú tối đa 1000 ký tự."},
		{map[string]any{"category": "luong"}, "Phân loại chi phí không hợp lệ."},
	} {
		r := admin.Do("POST", "/finance/expenses", expense(hn, "Tiền điện", 1_500_000, c.patch))
		iamtest.Expect(t, r, 422, "invalid")
		if r.Body["message"] != c.msg {
			t.Errorf("%v: %v, muốn %q", c.patch, r.Body["message"], c.msg)
		}
	}
}

func TestFinanceExpensesCRUDAndScope(t *testing.T) {
	e := setup(t, func(s *finance.Service) { s.WithPayroll(fakePayroll{}) })
	hn, hcm := e.Branch("HN01"), e.Branch("HCM01")
	_, admin := e.Member("AD01", "Quản trị", nil, "admin")
	_, mgr := e.Member("QL01", "Quản lý HN", []uuid.UUID{hn}, "manager")

	// Quản lý chỉ quản lý khoản chi chi nhánh mình
	r := mgr.Do("POST", "/finance/expenses", expense(hcm, "Tiền nước HCM", 800_000, nil))
	iamtest.Expect(t, r, 403, "outside_branch")
	r = mgr.Do("POST", "/finance/expenses", expense(hn, "Tiền điện tháng 10", 1_500_000, nil))
	iamtest.Expect(t, r, 201, "")
	if r.Body["message"] != "Khoản chi đã được lưu thành công vào sổ chi vận hành!" {
		t.Errorf("thông báo: %v", r.Body["message"])
	}
	iamtest.Expect(t, admin.Do("POST", "/finance/expenses",
		expense(hcm, "In ấn giáo trình", 2_000_000, map[string]any{"payment_method": "chuyen_khoan", "notes": "HĐ 12"})), 201, "")
	iamtest.Expect(t, admin.Do("POST", "/finance/expenses",
		expense(hn, "Tiếp khách", 500_000, map[string]any{"expense_date": "2026-09-20"})), 201, "")

	// Quản lý: chỉ thấy chi nhánh mình dù chọn "Tất cả" hoặc chi nhánh khác
	r = mgr.Do("GET", "/finance/expenses?"+url.Values{"month": {"2026-10"}, "branch_id": {hcm.String()}}.Encode(), nil)
	iamtest.Expect(t, r, 200, "")
	items := iamtest.List(t, r, "expenses")
	if len(items) != 1 || items[0]["title"] != "Tiền điện tháng 10" || items[0]["category"] != "mat_bang_tien_ich" {
		t.Fatalf("sổ chi quản lý: %v", items)
	}
	if r.Body["branch_scoped"] != true || r.Body["branch_id"] != hn.String() || len(iamtest.List(t, r, "branches")) != 1 {
		t.Errorf("phạm vi quản lý: %v %v", r.Body["branch_scoped"], r.Body["branch_id"])
	}
	// Chi lương tự động của chi nhánh (giả) + so với tháng trước (500.000 → 1.500.000 + 1.000.000)
	// fakePayroll rỗng → 0 nên không có dòng lương
	if r.Body["auto_salary_row"] != nil || r.Body["grand_total_expense"].(float64) != 1_500_000 {
		t.Errorf("tổng chi: %v %v", r.Body["auto_salary_row"], r.Body["grand_total_expense"])
	}
	if r.Body["percent_diff"].(float64) != 200 || r.Body["is_decreased"] != false || r.Body["cash_percent"].(float64) != 100 {
		t.Errorf("so tháng trước %v, tiền mặt %v", r.Body["percent_diff"], r.Body["cash_percent"])
	}

	// Admin: tất cả chi nhánh, tìm kiếm theo nội dung / ghi chú
	r = admin.Do("GET", "/finance/expenses?month=2026-10", nil)
	if all := iamtest.List(t, r, "expenses"); len(all) != 2 || r.Body["branch_id"] != "all" || r.Body["can_manage"] != true {
		t.Fatalf("sổ chi admin: %v", all)
	}
	if r.Body["transfer_percent"].(float64) != 57 || r.Body["cash_percent"].(float64) != 43 {
		t.Errorf("cơ cấu %v / %v", r.Body["transfer_percent"], r.Body["cash_percent"])
	}
	r = admin.Do("GET", "/finance/expenses?month=2026-10&search=HĐ", nil)
	found := iamtest.List(t, r, "expenses")
	if len(found) != 1 || found[0]["category"] != "giao_trinh_van_hanh" {
		t.Fatalf("tìm kiếm: %v", found)
	}
	hcmID := found[0]["id"].(string)

	// Quản lý HN không sửa / xóa được khoản chi HCM
	iamtest.Expect(t, mgr.Do("PUT", "/finance/expenses/"+hcmID, expense(hn, "Đổi", 1_000, nil)), 403, "outside_branch")
	iamtest.Expect(t, mgr.Do("DELETE", "/finance/expenses/"+hcmID, nil), 403, "outside_branch")

	r = admin.Do("PUT", "/finance/expenses/"+hcmID, expense(hcm, "In ấn giáo trình (bổ sung)", 2_500_000,
		map[string]any{"category": "khac"}))
	iamtest.Expect(t, r, 200, "")
	if r.Body["message"] != "Đã cập nhật thông tin khoản chi thành công!" {
		t.Errorf("sửa: %v", r.Body["message"])
	}
	iamtest.Expect(t, admin.Do("PUT", "/finance/expenses/"+uuid.NewString(), expense(hcm, "x", 1000, nil)), 404, "expense_not_found")

	// Xuất CSV: BOM, tiêu đề, dòng tăng dần theo ngày (csv.Reader bỏ qua dòng trống)
	res := admin.Do("GET", "/finance/expenses/export?month=2026-10", nil)
	iamtest.Expect(t, res, 200, "")
	if !strings.HasPrefix(string(res.Raw), "\xEF\xBB\xBF") ||
		!strings.Contains(res.Header.Get("Content-Disposition"), "So_Khoan_Chi_Van_Hanh_2026-10.csv") {
		t.Fatalf("file xuất: %q", res.Header.Get("Content-Disposition"))
	}
	rows, err := readCSV(res.Raw)
	if err != nil {
		t.Fatal(err)
	}
	if rows[0][0] != "SỔ KHOẢN CHI VẬN HÀNH - MENGLISH ADMIN" || len(rows) != 3+2 || rows[4][2] != "In ấn giáo trình (bổ sung)" || rows[4][3] != "2.500.000" {
		t.Errorf("nội dung xuất: %v", rows)
	}

	// Xóa
	r = admin.Do("DELETE", "/finance/expenses/"+hcmID, nil)
	iamtest.Expect(t, r, 200, "")
	if r.Body["message"] != `Đã xóa khoản chi: "In ấn giáo trình (bổ sung)" khỏi sổ chi vận hành.` {
		t.Errorf("xóa: %v", r.Body["message"])
	}
	iamtest.Expect(t, admin.Do("DELETE", "/finance/expenses/"+hcmID, nil), 404, "expense_not_found")

	// Nhật ký: thêm 3, sửa 1, xóa 1
	var n int
	if err := e.Pool.QueryRow(context.Background(),
		"SELECT count(*) FROM core.audit_log WHERE subject_type = 'operating_expense'").Scan(&n); err != nil {
		t.Fatal(err)
	}
	if n != 5 {
		t.Errorf("nhật ký %d dòng", n)
	}
}

func TestProvisionalRevenue(t *testing.T) {
	var hn, hcm uuid.UUID
	rev := fakeRevenue{rows: map[string][]finance.RevenueRow{}}
	pay := fakePayroll{byBranch: map[uuid.UUID]int64{}}
	e := setup(t, func(s *finance.Service) { s.WithRevenue(rev).WithPayroll(pay) })
	hn, hcm = e.Branch("HN01"), e.Branch("HCM01")
	rev.rows["2026-10"] = []finance.RevenueRow{
		{BranchID: &hn, PaymentMethod: "transfer", Receipts: 2, Amount: 10_000_000, Surcharge: 1_000_000},
		{BranchID: &hcm, PaymentMethod: "cash", Receipts: 1, Amount: 5_000_000},
	}
	rev.rows["2026-09"] = []finance.RevenueRow{{BranchID: &hn, PaymentMethod: "cash", Receipts: 1, Amount: 8_000_000}}
	pay.byBranch[hn], pay.byBranch[hcm] = 3_000_000, 1_000_000

	_, admin := e.Member("AD01", "Quản trị", nil, "admin")
	_, mgr := e.Member("QL01", "Quản lý HN", []uuid.UUID{hn}, "manager")
	iamtest.Expect(t, admin.Do("POST", "/finance/expenses", expense(hn, "Thuê mặt bằng", 2_000_000, nil)), 201, "")

	r := admin.Do("GET", "/finance/reports/provisional-revenue?month=2026-10", nil)
	iamtest.Expect(t, r, 200, "")
	num := func(k string) float64 { return r.Body[k].(float64) }
	if num("total_revenue") != 15_000_000 || num("valid_receipts_count") != 3 || num("total_expense") != 6_000_000 ||
		num("provisional_revenue") != 9_000_000 || num("gross_profit_margin") != 60 || num("revenue_diff_percent") != 87.5 {
		t.Fatalf("báo cáo admin: %s", r.Raw)
	}
	if r.Body["revenue_pending"] != nil || len(iamtest.List(t, r, "branch_matrix")) != 2 || r.Body["prev_month_label"] != "09/2026" {
		t.Errorf("trường phụ: %v", r.Body["revenue_pending"])
	}

	// Quản lý HN: chỉ số liệu chi nhánh mình
	r = mgr.Do("GET", "/finance/reports/provisional-revenue?month=2026-10&branch_id=all", nil)
	iamtest.Expect(t, r, 200, "")
	if num("total_revenue") != 10_000_000 || num("total_expense") != 5_000_000 || num("salary_total") != 3_000_000 ||
		r.Body["branch_id"] != hn.String() || len(iamtest.List(t, r, "branch_matrix")) != 1 {
		t.Fatalf("báo cáo quản lý: %s", r.Raw)
	}

	res := mgr.Do("GET", "/finance/reports/provisional-revenue/export?month=2026-10", nil)
	iamtest.Expect(t, res, 200, "")
	rows, err := readCSV(res.Raw)
	if err != nil {
		t.Fatal(err)
	}
	if len(rows) != 5 || rows[3][0] != "Chi nhánh HN01" || rows[3][3] != "5.000.000" || rows[4][0] != "TỔNG CỘNG" || rows[4][4] != "50%" {
		t.Errorf("xuất quản lý: %v", rows)
	}
	res = admin.Do("GET", "/finance/reports/provisional-revenue/export?month=2026-10", nil)
	rows, _ = readCSV(res.Raw)
	if len(rows) != 6 || rows[5][0] != "TỔNG CỘNG TOÀN HỆ THỐNG" {
		t.Errorf("xuất admin: %v", rows)
	}
}

func TestProvisionalRevenuePending(t *testing.T) {
	e := setup(t, nil)
	_, admin := e.Member("AD01", "Quản trị", nil, "admin")
	r := admin.Do("GET", "/finance/reports/provisional-revenue", nil)
	iamtest.Expect(t, r, 200, "")
	if !strings.HasPrefix(r.Body["revenue_pending"].(string), "Chờ phân hệ Học phí") ||
		!strings.HasPrefix(r.Body["payroll_pending"].(string), "Chờ phân hệ Lương") || r.Body["month"] != "2026-10" {
		t.Errorf("chờ phân hệ: %s", r.Raw)
	}
}
