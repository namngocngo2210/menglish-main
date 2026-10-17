package tuition

import (
	"context"
	"strings"
	"testing"

	"github.com/go-chi/chi/v5"
	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/catalogs"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/classes"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/crm"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam/iamtest"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/secretbox"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/students"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/tasks"
)

// setupClosing: server test có CRM, Lớp học, Học viên, Công việc và Học phí nối như cmd/menglish.
func setupClosing(t *testing.T) *env {
	t.Helper()
	e := iamtest.Setup(t, "academic.courses", "academic.classes", "academic.students", "academic.enrollments", "crm.leads", "finance.refund_requests",
		"crm.promotions", "ops.work_tasks", "finance.tuitions", "finance.receipts", "finance.bank_accounts", "finance.invoice_ranges",
		"core.notifications")
	box, _ := secretbox.FromBase64("MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=")
	cat := catalogs.NewService(e.Pool, box, e.Log)
	stu := students.NewService(e.Pool, e.IAM, e.Log)
	cls := classes.NewService(e.Pool, cat, e.Log)
	stu.Classes = cls
	taskSvc := tasks.NewService(e.Pool, e.Log)
	svc := NewService(e.Pool, box, cat, e.Log).WithTasks(taskSvc)
	crmSvc := crm.NewService(e.Pool, e.IAM, e.Log).WithStudents(svc.Closing(cls.CRMStudents(stu))).WithTuition(svc)
	cls.CRM = crmSvc
	svc.WithLeads(crmSvc)
	e.Serve(func(r chi.Router) {
		NewHandler(svc, e.Log).Routes(r)
		crm.NewHandler(crmSvc, e.Log).Routes(r)
	})
	return &env{Env: e, svc: svc}
}

func (e *env) lead(code, name string, branch, owner uuid.UUID) uuid.UUID {
	return e.id(`INSERT INTO crm.leads (code, name, phone, stage, branch_id, assigned_user_id) VALUES ($1, $2, '0912000111', 'consulting', $3, $4)
		RETURNING id`, code, name, branch, owner)
}

func TestClosingCreatesTuitionReceiptAndTask(t *testing.T) {
	e := setupClosing(t)
	a, b := e.Branch("CN-A"), e.Branch("CN-B")
	sales, salesC := e.Member("TV001", "Tư vấn A", []uuid.UUID{a}, iam.RoleSalesConsultant)
	_, otherSales := e.Member("TV002", "Tư vấn khác", []uuid.UUID{a}, iam.RoleSalesConsultant)
	_, teacher := e.Member("GV001", "Giáo viên", []uuid.UUID{a}, iam.RoleTeacherFulltime)
	_, accB := e.Member("KT002", "Kế toán B", []uuid.UUID{b}, iam.RoleManager)
	_, admin := e.Member("AD001", "Quản trị", nil, iam.RoleAdmin)

	course := e.course("IE", 6_000_000, 24)
	class := e.class("IE-01", a, course)

	// Chốt chưa đóng học phí: khoản học phí đủ giá trị hợp đồng + task "Nhắc thu học phí" cho người phụ trách.
	l1 := e.lead("KH-0001", "Nguyễn Văn Đức", a, sales.ID)
	r := salesC.Do("POST", "/crm/leads/"+l1.String()+"/close", map[string]any{"class_id": class})
	expectMsg(t, r, 200, "Đã tạo task nhắc thu học phí cho người phụ trách.")
	if strings.Contains(str(r.Body["message"]), classes.MsgTuitionPending) {
		t.Fatalf("thông báo còn câu chờ phân hệ học phí: %s", r.Raw)
	}
	billURL := str(r.Body["bill_url"])
	if !strings.HasPrefix(billURL, "/tuition/bill/") {
		t.Fatalf("thiếu bill_url: %s", r.Raw)
	}
	var tid, sid uuid.UUID
	var final, debt int64
	var notes, memo string
	if err := e.Pool.QueryRow(context.Background(), `SELECT t.id, t.student_id, t.final_amount, t.debt_amount, t.notes, t.transfer_memo
		FROM finance.tuitions t JOIN academic.students s ON s.id = t.student_id WHERE s.lead_id = $1`, l1).
		Scan(&tid, &sid, &final, &debt, &notes, &memo); err != nil {
		t.Fatal(err)
	}
	if billURL != "/tuition/bill/"+tid.String() || final != 6_000_000 || debt != 6_000_000 ||
		notes != "Thu qua Closing Wizard (Khách hàng Nguyễn Văn Đức)" || !strings.HasPrefix(memo, "NGUYENVANDUC ") {
		t.Fatalf("khoản học phí khi chốt: %s %d %d %q %q", billURL, final, debt, notes, memo)
	}
	if n := e.count(`SELECT count(*) FROM ops.work_tasks WHERE assignee_id = $1 AND student_id = $2 AND title LIKE 'Nhắc thu học phí:%'`, sales.ID, sid); n != 1 {
		t.Fatalf("task nhắc thu học phí: %d", n)
	}
	if n := e.count(`SELECT count(*) FROM finance.receipts WHERE tuition_id = $1`, tid); n != 0 {
		t.Fatalf("chưa đóng thì không có phiếu thu: %d", n)
	}

	// Bill: tư vấn viên phụ trách xem được (lead.view, khách trong phạm vi); tư vấn viên khác / chi nhánh khác → 404;
	// không có lead.view lẫn tuition.view → 403.
	bill := salesC.Do("GET", billURL, nil)
	iamtest.Expect(t, bill, 200, "")
	if num(bill.Body["amount_to_pay"]) != 6_000_000 || str(bill.Body["transfer_memo"]) != memo || num(bill.Body["total_sessions"]) != 24 ||
		str(obj(bill.Body["customer"])["code"]) != "KH-0001" || str(bill.Body["qr_warning"]) == "" || bill.Body["schedule"] == nil {
		t.Fatalf("bill: %s", bill.Raw)
	}
	iamtest.Expect(t, otherSales.Do("GET", billURL, nil), 404, "not_found")
	iamtest.Expect(t, accB.Do("GET", billURL, nil), 404, "not_found")
	iamtest.Expect(t, teacher.Do("GET", billURL, nil), 403, "forbidden")
	// Mở bằng id học viên → khoản mới nhất.
	iamtest.Expect(t, admin.Do("GET", "/tuition/bill/"+sid.String(), nil), 200, "")

	// Đóng lúc chốt bằng chuyển khoản: kiểm số tiền, cần tài khoản ngân hàng; tạo phiếu chờ duyệt.
	l2 := e.lead("KH-0002", "Trần Thị Bình", a, sales.ID)
	path := "/crm/leads/" + l2.String() + "/close"
	expectMsg(t, salesC.Do("POST", path, map[string]any{"class_id": class, "fee_paid_at_closing": true, "paid_amount": 7_000_000,
		"payment_method": "transfer"}), 422, "Chuyển khoản cần một tài khoản ngân hàng đang hoạt động.")
	e.exec(`INSERT INTO finance.bank_accounts (bank_code, bank_name, account_number, account_holder, is_default_vietqr) VALUES
		('VCB', 'Vietcombank', '0011 0022', 'CONG TY MENGLISH', true)`)
	expectMsg(t, salesC.Do("POST", path, map[string]any{"class_id": class, "fee_paid_at_closing": true, "paid_amount": 7_000_000,
		"payment_method": "transfer"}), 422, "Số tiền thu vượt quá số tiền còn phải nộp.")
	expectMsg(t, salesC.Do("POST", path, map[string]any{"class_id": class, "fee_paid_at_closing": true, "prepaid_amount": 500_000,
		"payment_method": "transfer"}), 422, "Khoản thu trước phải được quản lý xác nhận.")
	expectMsg(t, salesC.Do("POST", path, map[string]any{"class_id": class, "fee_paid_at_closing": true, "paid_amount": 1_000_000,
		"payment_method": "cash"}), 422, "Thu tiền mặt cần nhập số hóa đơn giấy đã xuất cho khách.")
	if n := e.count(`SELECT count(*) FROM academic.students WHERE lead_id = $1`, l2); n != 0 {
		t.Fatalf("chốt lỗi vẫn tạo học viên: %d", n)
	}
	r = salesC.Do("POST", path, map[string]any{"class_id": class, "fee_paid_at_closing": true, "paid_amount": 2_000_000, "payment_method": "transfer"})
	expectMsg(t, r, 200, "Khoản thu đang chờ Kế toán/Admin duyệt.")
	var t2 uuid.UUID
	var source, status, method string
	var amount int64
	if err := e.Pool.QueryRow(context.Background(), `SELECT r.tuition_id, r.source, r.status, r.payment_method, r.amount FROM finance.receipts r
		JOIN finance.tuitions t ON t.id = r.tuition_id JOIN academic.students s ON s.id = t.student_id WHERE s.lead_id = $1`, l2).
		Scan(&t2, &source, &status, &method, &amount); err != nil {
		t.Fatal(err)
	}
	if source != "closing" || status != StatusPending || method != "transfer" || amount != 2_000_000 {
		t.Fatalf("phiếu thu khi chốt: %s %s %s %d", source, status, method, amount)
	}
	if n := e.count(`SELECT count(*) FROM ops.work_tasks w JOIN academic.students s ON s.id = w.student_id WHERE s.lead_id = $1`, l2); n != 0 {
		t.Fatalf("đã đóng thì không tạo task nhắc thu: %d", n)
	}
	if got := e.notified("receipt_pending"); !strings.Contains(got, "AD001") {
		t.Fatalf("thông báo phiếu chờ duyệt: %q", got)
	}
	bill = salesC.Do("GET", "/tuition/bill/"+t2.String(), nil)
	iamtest.Expect(t, bill, 200, "")
	if num(bill.Body["pending_amount"]) != 2_000_000 || num(bill.Body["amount_to_pay"]) != 4_000_000 || str(bill.Body["vietqr_url"]) == "" ||
		!strings.Contains(str(bill.Body["vietqr_url"]), "VCB-00110022-compact2.png?amount=4000000") || len(list(bill.Body["receipts"])) != 1 {
		t.Fatalf("bill có phiếu chờ: %s", bill.Raw)
	}

	// Chốt chỉ chọn khóa (Chờ xếp lớp) có thu tiền → duyệt phiếu → hủy chốt: học viên Thôi học, học phí = phần đã thu,
	// hồ sơ hoàn phí chờ duyệt.
	l3 := e.lead("KH-0003", "Phạm Hủy", a, sales.ID)
	r = salesC.Do("POST", "/crm/leads/"+l3.String()+"/close", map[string]any{"course_id": course, "fee_paid_at_closing": true,
		"paid_amount": 1_500_000, "payment_method": "transfer"})
	expectMsg(t, r, 200, "Khoản thu đang chờ Kế toán/Admin duyệt.")
	var t3, rc3, s3 uuid.UUID
	if err := e.Pool.QueryRow(context.Background(), `SELECT t.id, r.id, t.student_id FROM finance.tuitions t JOIN finance.receipts r ON r.tuition_id = t.id
		JOIN academic.students s ON s.id = t.student_id WHERE s.lead_id = $1`, l3).Scan(&t3, &rc3, &s3); err != nil {
		t.Fatal(err)
	}
	iamtest.Expect(t, admin.Do("POST", "/tuition/receipts/"+rc3.String()+"/approve", map[string]any{"confirm_not_duplicate": true}), 200, "")
	e.exec(`INSERT INTO finance.receipts (code, tuition_id, student_id, amount, payment_method, status) VALUES ('PT-CHO', $1, $2, 500000, 'cash', 'pending')`, t3, s3)
	cancelPath := "/crm/leads/" + l3.String() + "/cancel-closing"
	iamtest.Expect(t, salesC.Do("POST", cancelPath, map[string]any{"reason": "Gia đình đổi ý"}), 403, "forbidden")
	r = admin.Do("POST", cancelPath, map[string]any{"reason": "Gia đình đổi ý"})
	expectMsg(t, r, 200, "học viên chuyển Thôi học. Đã lập hồ sơ hoàn 1.500.000 đ chờ duyệt ở mục Hoàn phí.")
	d3 := e.debtOf(t3)
	var stStatus string
	var final3 int64
	if err := e.Pool.QueryRow(context.Background(), `SELECT s.status, t.final_amount FROM academic.students s JOIN finance.tuitions t ON t.student_id = s.id
		WHERE t.id = $1`, t3).Scan(&stStatus, &final3); err != nil {
		t.Fatal(err)
	}
	if stStatus != "dropped" || final3 != 1_500_000 || d3.Debt != 0 || d3.Paid != 1_500_000 ||
		e.count(`SELECT count(*) FROM finance.receipts WHERE tuition_id = $1 AND status = 'cancelled'`, t3) != 1 ||
		e.count(`SELECT count(*) FROM finance.refund_requests WHERE student_id = $1 AND type = 'refund' AND status = 'pending' AND refund_amount = 1500000`, s3) != 1 {
		t.Fatalf("sau huỷ chốt: %s %d %+v", stStatus, final3, d3)
	}

	// Màn Khách chốt thành công: tổng Đã thu (duyệt) / Còn nợ và nhãn học phí từng khách.
	won := admin.Do("GET", "/crm/won", nil)
	iamtest.Expect(t, won, 200, "")
	if num(won.Body["total_collected_amount"]) != 0 || num(won.Body["total_debt_amount"]) != 12_000_000 {
		t.Fatalf("tổng màn đã chốt: %s", won.Raw)
	}
	labels := map[string]string{}
	for _, row := range list(won.Body["data"]) {
		labels[str(row["code"])] = str(obj(row["tuition"])["label"])
	}
	if labels["KH-0001"] != "Chưa nộp · Còn 6.000.000 đ" || labels["KH-0002"] != "Chờ đối soát 2.000.000 đ · Còn 4.000.000 đ" {
		t.Fatalf("nhãn học phí: %v", labels)
	}
}
