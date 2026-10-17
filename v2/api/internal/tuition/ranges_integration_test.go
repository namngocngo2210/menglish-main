package tuition

import (
	"testing"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam/iamtest"
)

func TestInvoiceRanges(t *testing.T) {
	e := setup(t)
	f := newFixture(e)
	e.Member("QL03", "Quản lý Cầu Giấy 2", []uuid.UUID{f.a}, iam.RoleManager)

	iamtest.Expect(t, f.teacher.Do("GET", "/tuition/config", nil), 403, "forbidden")
	r := f.mgrA.Do("GET", "/tuition/config", nil)
	iamtest.Expect(t, r, 200, "")
	if r.Body["can_manage"] != false || len(list(r.Body["ranges"])) != 0 {
		t.Fatalf("màn cấu hình: %s", r.Raw)
	}
	elec := map[string]any{"branch_id": f.a, "kind": "electronic", "template_code": "1/001", "series_code": "C26CG", "start_number": 1, "end_number": 100}
	iamtest.Expect(t, f.mgrA.Do("POST", "/tuition/config/ranges", elec), 403, "forbidden")

	e.Grant(iam.RoleManager, "invoice_range.manage")
	t.Cleanup(func() { e.Revoke(iam.RoleManager, "invoice_range.manage") })

	post := func(c *iamtest.Client, body map[string]any) iamtest.Resp {
		return c.Do("POST", "/tuition/config/ranges", body)
	}
	with := func(k string, v any) map[string]any {
		m := map[string]any{}
		for kk, vv := range elec {
			m[kk] = vv
		}
		m[k] = v
		return m
	}
	expectMsg(t, post(f.mgrA, with("branch_id", f.b)), 403, "Bạn chỉ được cấp dải số hóa đơn cho chi nhánh mình.")
	expectMsg(t, post(f.mgrA, with("branch_id", nil)), 403, "Bạn chỉ được cấp dải số hóa đơn cho chi nhánh mình.")
	paper := with("kind", "paper")
	paper["branch_id"] = nil
	expectMsg(t, post(f.mgrA, paper), 422, "Dải hóa đơn giấy phải gắn với một chi nhánh (mỗi chi nhánh một cuốn hóa đơn).")
	expectMsg(t, post(f.mgrA, with("series_code", "C26 CG")), 422, "Ký hiệu hóa đơn chỉ gồm chữ và số, không dấu, không khoảng trắng.")
	expectMsg(t, post(f.mgrA, with("end_number", 0)), 422, "Trường số kết thúc phải tối thiểu là 1.")
	expectMsg(t, post(f.mgrA, with("start_number", 200)), 422, "Số kết thúc phải lớn hơn hoặc bằng số bắt đầu.")
	expectMsg(t, post(f.mgrA, with("template_code", "")), 422, "Vui lòng nhập mẫu số.")
	r = post(f.mgrA, elec)
	expectMsg(t, r, 201, "Đã thêm dải số hóa đơn điện tử C26CG 0000001 – 0000100 cho Chi nhánh CG.")
	id := str(r.Body["id"])
	if got := e.notified("invoice_range_changed"); got != "QL03" {
		t.Fatalf("người nhận thay đổi dải số: %q", got)
	}
	over := with("start_number", 50)
	over["end_number"] = 200
	expectMsg(t, post(f.admin, over), 422, "Dải số chồng lấn với dải C26CG 1 – 100 (Chi nhánh CG). Mỗi dải phải duy nhất trên toàn hệ thống.")

	// Sửa: không lùi số đã cấp, không vượt dải.
	e.invoiced(f.ta, f.sa, "PT-A1", "C26CG-0000005", "approved", 100_000, false)
	update := func(c *iamtest.Client, current any) iamtest.Resp {
		return c.Do("POST", "/tuition/config", map[string]any{"config_id": id, "template_code": "1/001", "series_code": "c26cg", "current_number": current})
	}
	expectMsg(t, update(f.mgrA, 3), 422, "Không được lùi số: số C26CG-0000005 đã được cấp. Số kế tiếp phải từ 6 trở lên.")
	expectMsg(t, update(f.mgrA, 102), 422, "Số hiện tại vượt quá số kết thúc của dải.")
	expectMsg(t, update(f.mgrA, nil), 422, "Vui lòng nhập số hiện tại.")
	expectMsg(t, update(f.mgrB, 6), 403, "Bạn chỉ được cấu hình dải số hóa đơn của chi nhánh mình.")
	expectMsg(t, update(f.mgrA, 6), 200, "Đã lưu cấu hình dải số hóa đơn điện tử thành công!")
	r = f.mgrA.Do("GET", "/tuition/config", nil)
	rows := list(r.Body["ranges"])
	if len(rows) != 1 || num(rows[0]["max_issued"]) != 5 || num(rows[0]["remaining"]) != 95 || str(rows[0]["current_label"]) != "0000006" ||
		rows[0]["can_manage"] != true || len(list(rows[0]["recent"])) != 1 || r.Body["can_manage_default"] != false {
		t.Fatalf("dải sau sửa: %s", r.Raw)
	}
	if rows := list(f.mgrB.Do("GET", "/tuition/config", nil).Body["ranges"]); len(rows) != 0 {
		t.Fatalf("chi nhánh B thấy dải chi nhánh A: %v", rows)
	}

	// Ngừng dùng / dùng lại.
	toggle := "/tuition/config/ranges/" + id + "/toggle"
	expectMsg(t, f.mgrB.Do("POST", toggle, nil), 403, "Bạn chỉ được cấu hình dải số hóa đơn của chi nhánh mình.")
	expectMsg(t, f.mgrA.Do("POST", toggle, nil), 200, "Đã ngừng dùng dải số C26CG. Hóa đơn mới sẽ lấy từ dải khác của chi nhánh hoặc dải mặc định.")
	expectMsg(t, f.mgrA.Do("POST", toggle, nil), 200, "Đã kích hoạt lại dải số C26CG.")

	// Dải mặc định: cần invoice_range.manage_default; chưa có thì tạo khi lưu.
	def := map[string]any{"template_code": "1/001", "series_code": "C26MEN", "start_number": 1, "end_number": nil, "current_number": 1001}
	expectMsg(t, f.mgrA.Do("POST", "/tuition/config", def), 403, "Bạn chỉ được cấu hình dải số hóa đơn của chi nhánh mình.")
	expectMsg(t, f.admin.Do("POST", "/tuition/config", def), 200, "Đã lưu cấu hình dải số hóa đơn điện tử thành công!")
	if n := e.count(`SELECT count(*) FROM finance.invoice_ranges WHERE branch_id IS NULL AND series_code = 'C26MEN' AND end_number IS NULL AND current_number = 1001`); n != 1 {
		t.Fatalf("dải mặc định: %d", n)
	}
	if n := e.count(`SELECT count(*) FROM core.audit_log WHERE action LIKE 'tuition.invoice_range.%'`); n != 5 {
		t.Fatalf("nhật ký dải số: %d", n)
	}
}
