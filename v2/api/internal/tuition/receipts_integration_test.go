package tuition

import (
	"bytes"
	"strings"
	"testing"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam/iamtest"
)

// fixture: chi nhánh A / B, khóa 24 buổi 2.400.000 đ, lớp ở A, học viên có khoản học phí ở A và một học viên ở B.
type fixture struct {
	a, b                 uuid.UUID
	class                uuid.UUID
	sa, sb               uuid.UUID
	ta, tb               uuid.UUID
	admin, mgrA, mgrB    *iamtest.Client
	adminU, mgrAU, mgrBU iamtest.User
	teacher              *iamtest.Client
}

func newFixture(e *env) fixture {
	var f fixture
	f.a, f.b = e.Branch("CG"), e.Branch("HD")
	course := e.course("IELTS1", 2_400_000, 24)
	f.class = e.class("IE-CG-01", f.a, course)
	classB := e.class("IE-HD-01", f.b, course)
	f.sa = e.student("HV0001", "Nguyễn Văn An", f.a, &f.class)
	f.sb = e.student("HV0002", "Trần Thị Bình", f.b, &classB)
	f.ta = e.tuition(f.sa, f.class, f.a, 2_400_000, 24)
	f.tb = e.tuition(f.sb, classB, f.b, 2_400_000, 24)
	f.adminU, f.admin = e.Member("AD01", "Kế toán Admin", []uuid.UUID{f.a}, iam.RoleAdmin)
	f.mgrAU, f.mgrA = e.Member("QL01", "Quản lý Cầu Giấy", []uuid.UUID{f.a}, iam.RoleManager)
	f.mgrBU, f.mgrB = e.Member("QL02", "Quản lý Hà Đông", []uuid.UUID{f.b}, iam.RoleManager)
	_, f.teacher = e.Member("GV01", "Giáo viên", []uuid.UUID{f.a}, iam.RoleTeacherFulltime)
	return f
}

func transfer(tuition uuid.UUID, amount int64, code string) map[string]any {
	return map[string]any{"student_tuition_id": tuition, "amount": amount, "tuition_amount": amount, "payment_method": "transfer",
		"transaction_code": code, "proof_url": "https://drive.example/uy-nhiem-chi.jpg", "submit_action": "submit"}
}

func TestTuitionListsAndScope(t *testing.T) {
	e := setup(t)
	f := newFixture(e)

	iamtest.Expect(t, f.teacher.Do("GET", "/tuition/students", nil), 403, "forbidden")
	iamtest.Expect(t, f.teacher.Do("GET", "/tuition/history", nil), 403, "forbidden")

	// Quản lý chỉ thấy khoản học phí của chi nhánh mình; Admin thấy mọi chi nhánh.
	r := f.mgrA.Do("GET", "/tuition/students", nil)
	iamtest.Expect(t, r, 200, "")
	rows := list(r.Body["tuitions"])
	if len(rows) != 1 || str(rows[0]["id"]) != f.ta.String() || num(rows[0]["debt_amount"]) != 2_400_000 {
		t.Fatalf("danh sách chi nhánh A: %s", r.Raw)
	}
	if st := obj(r.Body["stats"]); num(st["debt"]) != 2_400_000 {
		t.Fatalf("thống kê: %v", st)
	}
	if rows := list(f.admin.Do("GET", "/tuition/students", nil).Body["tuitions"]); len(rows) != 2 {
		t.Fatalf("Admin thấy %d khoản", len(rows))
	}
	if rows := list(f.admin.Do("GET", "/tuition/students?search=Bình", nil).Body["tuitions"]); len(rows) != 1 || str(rows[0]["id"]) != f.tb.String() {
		t.Fatalf("tìm kiếm: %v", rows)
	}

	// Lịch sử thanh toán của học viên chi nhánh khác: không có quyền.
	// Như Laravel (abort_unless($model, 404)): học viên ngoài phạm vi coi như không tồn tại.
	iamtest.Expect(t, f.mgrB.Do("GET", "/tuition/students/"+f.sa.String()+"/payments", nil), 404, "not_found")
	r = f.mgrA.Do("GET", "/tuition/students/"+f.sa.String()+"/payments", nil)
	iamtest.Expect(t, r, 200, "")
	if s := obj(r.Body["summary"]); num(s["debt_amount"]) != 2_400_000 || s["is_settled"] != false {
		t.Fatalf("tóm tắt: %s", r.Raw)
	}

	// Ô chọn học viên chỉ trong phạm vi.
	opts := list(f.mgrB.Do("GET", "/tuition/student-options?q=HV", nil).Body["students"])
	if len(opts) != 1 || str(opts[0]["id"]) != f.sb.String() {
		t.Fatalf("ô chọn học viên: %v", opts)
	}

	// Form lập phiếu: học viên ngoài phạm vi bị chặn; trong phạm vi có khoản học phí và sổ buổi.
	iamtest.Expect(t, f.mgrB.Do("GET", "/tuition/receipts/form?student_id="+f.sa.String(), nil), 403, "forbidden")
	r = f.mgrA.Do("GET", "/tuition/receipts/form?student_id="+f.sa.String(), nil)
	iamtest.Expect(t, r, 200, "")
	tu := list(r.Body["tuitions"])
	if len(tu) != 1 || num(tu[0]["debt_amount"]) != 2_400_000 {
		t.Fatalf("form: %s", r.Raw)
	}
	if q := obj(obj(r.Body["student"])["quote"]); str(q["mode"]) != "contract" || num(q["max_sessions"]) != 24 || q["unit_price"] != float64(100_000) {
		t.Fatalf("sổ buổi: %v", q)
	}
}

func TestReceiptValidation(t *testing.T) {
	e := setup(t)
	f := newFixture(e)
	post := func(body map[string]any) iamtest.Resp { return f.mgrA.Do("POST", "/tuition/receipts", body) }

	expectMsg(t, post(map[string]any{"student_tuition_id": f.ta, "payment_method": "transfer"}), 422, "Vui lòng nhập số tiền của phiếu.")
	expectMsg(t, post(map[string]any{"student_tuition_id": f.ta, "amount": 500, "payment_method": "transfer"}), 422, "Số tiền của phiếu tối thiểu 1.000 đ.")
	expectMsg(t, post(map[string]any{"student_tuition_id": f.ta, "amount": 100000}), 422, "Vui lòng chọn hình thức thu.")
	expectMsg(t, post(map[string]any{"student_tuition_id": f.ta, "amount": 100000, "payment_method": "momo"}), 422, "Hình thức thu không hợp lệ.")
	expectMsg(t, post(map[string]any{"amount": 100000, "payment_method": "transfer"}), 422, "Vui lòng chọn học viên hoặc khoản học phí.")
	expectMsg(t, post(map[string]any{"student_tuition_id": f.ta, "student_id": f.sb, "amount": 100000, "payment_method": "transfer"}), 422,
		"Học viên không khớp với khoản học phí đã chọn.")
	expectMsg(t, post(map[string]any{"student_tuition_id": f.ta, "amount": 100000, "tuition_amount": 90000, "payment_method": "transfer"}), 422,
		"Tổng thu phải bằng tiền học phí + tiền phụ thu.")
	expectMsg(t, post(map[string]any{"student_tuition_id": f.ta, "amount": 100000, "surcharge_amount": 200000, "payment_method": "transfer"}), 422,
		"Tiền phụ thu không được lớn hơn tổng tiền của phiếu.")
	expectMsg(t, post(map[string]any{"student_tuition_id": f.ta, "amount": 100000, "surcharge_amount": 20000, "payment_method": "transfer",
		"proof_url": "x"}), 422, "Bắt buộc nhập lý do khi có số tiền phụ thu.")
	expectMsg(t, post(map[string]any{"student_tuition_id": f.ta, "amount": 100000, "payment_method": "transfer", "submit_action": "submit"}), 422,
		"Bắt buộc đính kèm minh chứng")
	expectMsg(t, post(map[string]any{"student_tuition_id": f.ta, "amount": 100000, "payment_method": "cash"}), 422,
		"Thu tiền mặt cần nhập số hóa đơn giấy đã xuất cho khách.")
	expectMsg(t, post(transfer(f.ta, 3_000_000, "FT1")), 422, "vượt quá công nợ còn lại 2.400.000 đ")
	expectMsg(t, post(map[string]any{"student_tuition_id": f.ta, "amount": 100000, "payment_method": "transfer", "discount_amount": 10000,
		"proof_url": "x"}), 422, "Giảm trừ không theo ưu đãi có sẵn: nhập lý do giảm.")
	// Trường lạ bị từ chối.
	iamtest.Expect(t, post(map[string]any{"student_tuition_id": f.ta, "amount": 100000, "payment_method": "transfer", "status": "approved"}), 400, "")

	// Lập phiếu cho học viên chi nhánh khác / không có quyền lập.
	iamtest.Expect(t, f.mgrB.Do("POST", "/tuition/receipts", transfer(f.ta, 100000, "FT2")), 403, "forbidden")
	iamtest.Expect(t, f.teacher.Do("POST", "/tuition/receipts", transfer(f.ta, 100000, "FT2")), 403, "forbidden")
	if n := e.count(`SELECT count(*) FROM finance.receipts`); n != 0 {
		t.Fatalf("phiếu lỗi vẫn được lưu: %d", n)
	}

	// Lưu nháp bỏ qua điều kiện gửi duyệt (minh chứng), nhưng không bỏ qua kiểm số tiền.
	r := post(map[string]any{"student_tuition_id": f.ta, "amount": 100000, "payment_method": "transfer", "submit_action": "draft"})
	iamtest.Expect(t, r, 201, "")
	if str(r.Body["status"]) != StatusDraft || !strings.Contains(str(r.Body["message"]), "Đã lưu nháp phiếu thu") {
		t.Fatalf("nháp: %s", r.Raw)
	}
}

func TestReceiptApprovalFlow(t *testing.T) {
	e := setup(t)
	f := newFixture(e)

	r := f.mgrA.Do("POST", "/tuition/receipts", transfer(f.ta, 1_000_000, "FT 123"))
	iamtest.Expect(t, r, 201, "")
	id, code := str(r.Body["id"]), str(r.Body["code"])
	if str(r.Body["status"]) != StatusPending || !strings.HasPrefix(code, "PT-") ||
		!strings.Contains(str(r.Body["message"]), "Đã gửi duyệt phiếu thu "+code+" (Số tiền: 1.000.000 đ)") {
		t.Fatalf("lập phiếu: %s", r.Raw)
	}
	// Phiếu chờ duyệt báo Admin; chưa giảm công nợ.
	if got := e.notified("receipt_pending"); got != "AD01" {
		t.Fatalf("thông báo chờ duyệt tới %q", got)
	}
	if d := e.debtOf(f.ta); d.Debt != 2_400_000 {
		t.Fatalf("công nợ trước duyệt: %+v", d)
	}
	// Mã giao dịch trùng (khác khoảng trắng / hoa thường) không được ghi nhận 2 lần.
	expectMsg(t, f.mgrA.Do("POST", "/tuition/receipts", transfer(f.ta, 200_000, "ft123")), 422, "Mã giao dịch ft123 đã được ghi nhận ở phiếu "+code)

	// Chi tiết: ngoài phạm vi bị chặn; màn duyệt mặc định lọc phiếu chờ duyệt.
	iamtest.Expect(t, f.mgrB.Do("GET", "/tuition/receipts/"+id, nil), 403, "forbidden")
	iamtest.Expect(t, f.mgrA.Do("GET", "/tuition/receipts/"+uuid.NewString(), nil), 404, "not_found")
	r = f.admin.Do("GET", "/tuition/receipts/approve?selected_id="+id, nil)
	iamtest.Expect(t, r, 200, "")
	if num(r.Body["pending_count"]) != 1 || len(list(r.Body["receipts"])) != 1 || str(obj(r.Body["selected"])["code"]) != code ||
		r.Body["can_approve"] != true {
		t.Fatalf("màn duyệt: %s", r.Raw)
	}
	if r := f.mgrA.Do("GET", "/tuition/receipts/approve", nil); r.Body["can_approve"] != false {
		t.Fatalf("quản lý không duyệt được: %s", r.Raw)
	}

	// Chỉ Admin duyệt / từ chối (quyền admin_only; cấp cho Quản lý cũng không có tác dụng).
	iamtest.Expect(t, f.mgrA.Do("POST", "/tuition/receipts/"+id+"/approve", map[string]any{}), 403, "forbidden")
	e.Grant(iam.RoleManager, "tuition.approve")
	iamtest.Expect(t, f.mgrA.Do("POST", "/tuition/receipts/"+id+"/approve", map[string]any{}), 403, "forbidden")
	iamtest.Expect(t, f.mgrA.Do("POST", "/tuition/receipts/"+id+"/reject", map[string]any{}), 403, "forbidden")

	r = f.admin.Do("POST", "/tuition/receipts/"+id+"/approve", map[string]any{})
	iamtest.Expect(t, r, 200, "")
	if str(r.Body["status"]) != StatusApproved || str(r.Body["message"]) != "Đã phê duyệt phiếu thu "+code+" và phát hành HĐĐT số C26MEN-0001001!" {
		t.Fatalf("duyệt: %s", r.Raw)
	}
	if d := e.debtOf(f.ta); d.Paid != 1_000_000 || d.Debt != 1_400_000 || d.Status != "partial" {
		t.Fatalf("công nợ sau duyệt: %+v", d)
	}
	if got := e.notified("receipt_approved"); got != "QL01" {
		t.Fatalf("thông báo đã duyệt tới %q", got)
	}
	if n := e.count(`SELECT count(*) FROM core.audit_log WHERE action = 'tuition.receipt.approve' AND subject_id = $1`, id); n != 1 {
		t.Fatalf("nhật ký duyệt: %d", n)
	}
	expectMsg(t, f.admin.Do("POST", "/tuition/receipts/"+id+"/approve", map[string]any{}), 422, "Phiếu thu này đã được xử lý trước đó.")
	expectMsg(t, f.admin.Do("POST", "/tuition/receipts/"+id+"/reject", map[string]any{}), 422, "Phiếu thu này đã được xử lý trước đó.")
	// Phiếu đã duyệt không sửa được.
	expectMsg(t, f.mgrA.Do("PUT", "/tuition/receipts/"+id, transfer(f.ta, 1_000_000, "FT123")), 422, "Chỉ sửa được phiếu ở trạng thái Bản nháp hoặc Bị từ chối.")

	// Phiếu thứ hai: bị trả về → người lập sửa, gửi lại → duyệt cấp số hóa đơn kế tiếp.
	r = f.mgrA.Do("POST", "/tuition/receipts", transfer(f.ta, 400_000, "FT456"))
	iamtest.Expect(t, r, 201, "")
	id2 := str(r.Body["id"])
	r = f.admin.Do("POST", "/tuition/receipts/"+id2+"/reject", map[string]any{"rejection_reason": "Ảnh chuyển khoản mờ"})
	iamtest.Expect(t, r, 200, "")
	if str(r.Body["status"]) != StatusRejected {
		t.Fatalf("từ chối: %s", r.Raw)
	}
	if got := e.notified("receipt_rejected"); got != "QL01" {
		t.Fatalf("thông báo trả về tới %q", got)
	}
	// Người khác (không phải người lập, không phải Admin) không sửa được.
	e.exec(`INSERT INTO iam.user_branches (user_id, branch_id) VALUES ($1, $2)`, f.mgrBU.ID, f.a)
	iamtest.Expect(t, f.mgrB.Do("PUT", "/tuition/receipts/"+id2, transfer(f.ta, 400_000, "FT456")), 403, "forbidden")
	// Form sửa hiện lý do trả về.
	r = f.mgrA.Do("GET", "/tuition/receipts/form?receipt_id="+id2, nil)
	iamtest.Expect(t, r, 200, "")
	if str(obj(r.Body["editing"])["rejection_reason"]) != "Ảnh chuyển khoản mờ" {
		t.Fatalf("form sửa: %s", r.Raw)
	}
	r = f.mgrA.Do("PUT", "/tuition/receipts/"+id2, transfer(f.ta, 400_000, "FT456"))
	iamtest.Expect(t, r, 200, "")
	if str(r.Body["status"]) != StatusPending || str(r.Body["message"]) != "Đã gửi duyệt lại phiếu thu "+str(r.Body["code"])+"." {
		t.Fatalf("gửi lại: %s", r.Raw)
	}
	r = f.admin.Do("POST", "/tuition/receipts/"+id2+"/approve", map[string]any{})
	iamtest.Expect(t, r, 200, "")
	if !strings.Contains(str(r.Body["message"]), "C26MEN-0001002") {
		t.Fatalf("số hóa đơn kế tiếp: %s", r.Raw)
	}
	if d := e.debtOf(f.ta); d.Paid != 1_400_000 || d.Debt != 1_000_000 {
		t.Fatalf("công nợ: %+v", d)
	}

	// Lịch sử thu và xuất CSV.
	r = f.mgrA.Do("GET", "/tuition/history?status=approved", nil)
	iamtest.Expect(t, r, 200, "")
	if len(list(r.Body["receipts"])) != 2 || num(r.Body["amount_total"]) != 1_400_000 {
		t.Fatalf("lịch sử: %s", r.Raw)
	}
	if rows := list(f.mgrB.Do("GET", "/tuition/history", nil).Body["receipts"]); len(rows) != 2 {
		// Quản lý B vừa được gắn thêm chi nhánh A ở trên.
		t.Fatalf("lịch sử QL02: %d", len(rows))
	}
	r = f.mgrA.Do("GET", "/tuition/history/export", nil)
	iamtest.Expect(t, r, 200, "")
	if !bytes.HasPrefix(r.Raw, []byte("\xEF\xBB\xBF")) || !bytes.Contains(r.Raw, []byte(code)) {
		t.Fatalf("CSV: %q", r.Raw)
	}
	// Lịch sử của học viên: 2 lần đóng.
	r = f.mgrA.Do("GET", "/tuition/students/"+f.sa.String()+"/payments", nil)
	if s := obj(r.Body["summary"]); num(s["paid_amount"]) != 1_400_000 || num(s["installments"]) != 2 || len(list(r.Body["payments"])) != 2 {
		t.Fatalf("lịch sử học viên: %s", r.Raw)
	}
}

func TestCashReceiptDeposit(t *testing.T) {
	e := setup(t)
	f := newFixture(e)
	cash := map[string]any{"student_tuition_id": f.ta, "amount": 500_000, "tuition_amount": 500_000, "payment_method": "cash",
		"paper_invoice_number": "0001234", "submit_action": "submit"}
	r := f.mgrA.Do("POST", "/tuition/receipts", cash)
	iamtest.Expect(t, r, 201, "")
	id := str(r.Body["id"])
	// Phiếu chuyển khoản vào thẳng TK công ty: không áp dụng.
	tr := f.mgrA.Do("POST", "/tuition/receipts", transfer(f.ta, 100_000, "FT-D1"))
	iamtest.Expect(t, tr, 201, "")
	expectMsg(t, f.admin.Do("POST", "/tuition/receipts/"+str(tr.Body["id"])+"/confirm-deposit", nil), 422, "không thuộc diện phải nộp tiền mặt")
	// Phiếu tiền mặt chờ duyệt / đã duyệt đều phải nộp (REFERENCE_HOLDING_STATUSES).
	iamtest.Expect(t, f.admin.Do("POST", "/tuition/receipts/"+id+"/approve", nil), 200, "")
	r = f.mgrA.Do("GET", "/tuition/receipts/"+id, nil)
	if str(r.Body["deposit_state"]) != "pending" || r.Body["can_confirm_deposit"] != false {
		t.Fatalf("chờ nộp: %s", r.Raw)
	}
	// Quản lý không có quyền xác nhận nộp tiền.
	iamtest.Expect(t, f.mgrA.Do("POST", "/tuition/receipts/"+id+"/confirm-deposit", nil), 403, "forbidden")
	r = f.admin.Do("POST", "/tuition/receipts/"+id+"/confirm-deposit", nil)
	iamtest.Expect(t, r, 200, "")
	if !strings.HasPrefix(str(r.Body["message"]), "Đã xác nhận nộp về TK công ty") {
		t.Fatalf("xác nhận nộp: %s", r.Raw)
	}
	expectMsg(t, f.admin.Do("POST", "/tuition/receipts/"+id+"/confirm-deposit", nil), 422, "Phiếu này đã được xác nhận nộp trước đó.")
	if r := f.admin.Do("GET", "/tuition/receipts/"+id, nil); str(r.Body["deposit_state"]) == "pending" || r.Body["deposited_by_name"] != "Kế toán Admin" {
		t.Fatalf("đã nộp: %s", r.Raw)
	}
}

func TestSelfApprovalAndPaperRange(t *testing.T) {
	e := setup(t)
	f := newFixture(e)
	// Chi nhánh A dùng cuốn hóa đơn giấy: số giấy cấp ngay khi lập, phiếu tiền mặt phải có ảnh hóa đơn.
	e.exec(`INSERT INTO finance.invoice_ranges (kind, template_code, series_code, start_number, end_number, current_number, branch_id, is_active)
		VALUES ('paper', '01GTKT', 'CG', 1, 100, 1, $1, true)`, f.a)
	cash := map[string]any{"student_tuition_id": f.ta, "amount": 300_000, "payment_method": "cash", "submit_action": "submit"}
	expectMsg(t, f.mgrA.Do("POST", "/tuition/receipts", cash), 422, "Thu tiền mặt cần tải ảnh chụp hóa đơn giấy")
	cash["proof_url"] = "https://drive.example/hoa-don.jpg"
	cash["expected_paper_invoice_number"] = "CG-0000009"
	expectMsg(t, f.mgrA.Do("POST", "/tuition/receipts", cash), 422, "vừa được cấp")
	r := f.mgrA.Do("GET", "/tuition/receipts/form?student_id="+f.sa.String(), nil)
	next := str(r.Body["paper_invoice_next"])
	if next != "CG-0000001" {
		t.Fatalf("số giấy kế tiếp: %s", r.Raw)
	}
	cash["expected_paper_invoice_number"] = next
	r = f.mgrA.Do("POST", "/tuition/receipts", cash)
	iamtest.Expect(t, r, 201, "")
	if !strings.Contains(str(r.Body["message"]), "Số hóa đơn giấy: CG-0000001.") {
		t.Fatalf("cấp số giấy: %s", r.Raw)
	}

	// Admin lập phiếu rồi tự duyệt được (Laravel: chỉ người không phải người lập; Admin là ngoại lệ siêu quyền).
	r = f.admin.Do("POST", "/tuition/receipts", transfer(f.ta, 100_000, "FT999"))
	iamtest.Expect(t, r, 201, "")
	iamtest.Expect(t, f.admin.Do("POST", "/tuition/receipts/"+str(r.Body["id"])+"/approve", nil), 200, "")
}

func TestSessionReceiptCreatesNextCourse(t *testing.T) {
	e := setup(t)
	f := newFixture(e)
	// Học viên mới trong lớp, chưa có khoản học phí: thu theo buổi → khoản học phí tạo khi duyệt.
	sc := e.student("HV0003", "Lê Minh Châu", f.a, &f.class)
	r := f.mgrA.Do("GET", "/tuition/receipts/form?student_id="+sc.String(), nil)
	iamtest.Expect(t, r, 200, "")
	q := obj(obj(r.Body["student"])["quote"])
	if str(q["mode"]) != "new" || num(q["max_sessions"]) != 24 || num(q["unit_price"]) != 100_000 {
		t.Fatalf("sổ buổi khóa mới: %v", q)
	}
	body := map[string]any{"student_id": sc, "session_count": 5, "amount": 450_000, "payment_method": "transfer",
		"transaction_code": "FT-S1", "proof_url": "x", "submit_action": "submit"}
	expectMsg(t, f.mgrA.Do("POST", "/tuition/receipts", body), 422, "không khớp cách tính theo sổ buổi (500.000 đ)")
	body["session_count"] = 30
	body["amount"] = 3_000_000
	expectMsg(t, f.mgrA.Do("POST", "/tuition/receipts", body), 422, "Theo sổ buổi chỉ cần thu tối đa 24 buổi")
	body["session_count"], body["amount"] = 5, 500_000
	r = f.mgrA.Do("POST", "/tuition/receipts", body)
	iamtest.Expect(t, r, 201, "")
	id := str(r.Body["id"])
	if n := e.count(`SELECT count(*) FROM finance.tuitions WHERE student_id = $1`, sc); n != 0 {
		t.Fatalf("chưa duyệt đã tạo khoản: %d", n)
	}
	iamtest.Expect(t, f.admin.Do("POST", "/tuition/receipts/"+id+"/approve", nil), 200, "")
	var tid uuid.UUID
	var sessions int
	var final, debtAmt int64
	if err := e.Pool.QueryRow(t.Context(), `SELECT id, session_count, final_amount, debt_amount FROM finance.tuitions WHERE student_id = $1`, sc).
		Scan(&tid, &sessions, &final, &debtAmt); err != nil {
		t.Fatal(err)
	}
	if sessions != 5 || final != 500_000 || debtAmt != 0 {
		t.Fatalf("khoản khóa kế tiếp: %d buổi, %d / nợ %d", sessions, final, debtAmt)
	}
	if n := e.count(`SELECT count(*) FROM finance.receipts WHERE id = $1 AND tuition_id = $2`, uuid.MustParse(id), tid); n != 1 {
		t.Fatal("phiếu chưa gắn khoản học phí mới")
	}
	// Sổ buổi sau duyệt: đã đóng 5 buổi.
	r = f.mgrA.Do("GET", "/tuition/receipts/form?student_id="+sc.String(), nil)
	if q := obj(obj(r.Body["student"])["quote"]); num(q["paid"]) != 5 || num(q["needed"]) != 19 {
		t.Fatalf("sổ buổi sau duyệt: %v", q)
	}
}
