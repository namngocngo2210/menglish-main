package tuition

import (
	"context"
	"strings"
	"testing"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam/iamtest"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/tuition/tuitiondb"
)

// invoiced: phiếu có số hóa đơn (status đã duyệt / chờ duyệt), tính lại công nợ.
func (e *env) invoiced(tuition, student uuid.UUID, code, invoice, status string, amount int64, paper bool) uuid.UUID {
	e.T.Helper()
	var paperNo *string
	if paper {
		paperNo = &invoice
	}
	var id uuid.UUID
	if err := e.Pool.QueryRow(context.Background(), `INSERT INTO finance.receipts (code, tuition_id, student_id, amount, tuition_amount,
		payment_method, status, payment_date, approved_at, invoice_number, paper_invoice_number)
		VALUES ($1, $2, $3, $4, $4, 'cash', $5, CURRENT_DATE, CASE WHEN $5 = 'approved' THEN now() END, $6, $7) RETURNING id`,
		code, tuition, student, amount, status, invoice, paperNo).Scan(&id); err != nil {
		e.T.Fatal(err)
	}
	if err := e.svc.inTx(context.Background(), func(_ pgx.Tx, q *tuitiondb.Queries) error {
		_, err := e.svc.recalculateDebt(context.Background(), q, tuition)
		return err
	}); err != nil {
		e.T.Fatal(err)
	}
	return id
}

func TestInvoiceCancellations(t *testing.T) {
	e := setup(t)
	withRefundDeps(t, e)
	f := newFixture(e)
	rA := e.invoiced(f.ta, f.sa, "PT-A1", "C26MEN-0001001", "approved", 1_000_000, false)
	e.invoiced(f.tb, f.sb, "PT-B1", "C26MEN-0001002", "approved", 500_000, false)
	rPaper := e.invoiced(f.ta, f.sa, "PT-A2", "GIAY-0000001", "pending", 300_000, true)
	e.exec(`INSERT INTO finance.receipts (code, tuition_id, student_id, amount, tuition_amount, payment_method, status, approved_at,
		invoice_number, transaction_code, source) VALUES ('PT-R1', NULL, $1, -200000, -200000, 'cash', 'approved', now(), 'C26MEN-0001003', 'REFUND-1', 'refund')`, f.sa)
	if d := e.debtOf(f.ta); d.Paid != 1_000_000 {
		t.Fatalf("đã nộp trước khi hủy: %+v", d)
	}

	const list_ = "/tuition/invoices/cancellations"
	iamtest.Expect(t, f.teacher.Do("GET", list_, nil), 403, "forbidden")
	iamtest.Expect(t, f.teacher.Do("POST", list_, map[string]any{}), 403, "forbidden")
	r := f.mgrA.Do("GET", list_+"?cancel_invoice=C26MEN-0001001", nil)
	iamtest.Expect(t, r, 200, "")
	if p := obj(r.Body["prefill"]); str(p["invoice_number"]) != "C26MEN-0001001" || num(p["amount"]) != 1_000_000 || r.Body["can_approve_cancel"] != false {
		t.Fatalf("điền sẵn: %s", r.Raw)
	}
	if f.mgrA.Do("GET", list_+"?cancel_invoice=C26MEN-0001002", nil).Body["prefill"] != nil {
		t.Fatal("điền sẵn hóa đơn ngoài phạm vi")
	}

	// Gửi yêu cầu: kiểm dữ liệu, phạm vi, số tiền.
	post := func(c *iamtest.Client, body map[string]any) iamtest.Resp { return c.Do("POST", list_, body) }
	expectMsg(t, post(f.mgrA, map[string]any{"amount": 1, "reason": "x"}), 422, "Vui lòng nhập số hóa đơn.")
	expectMsg(t, post(f.mgrA, map[string]any{"invoice_number": "C26MEN-0001001", "reason": "x"}), 422, "Vui lòng nhập số tiền.")
	expectMsg(t, post(f.mgrA, map[string]any{"invoice_number": "C26MEN-0001001", "amount": 1}), 422, "Vui lòng nhập lý do.")
	expectMsg(t, post(f.mgrA, map[string]any{"invoice_number": "C26MEN-0001001", "amount": 1, "reason": strings.Repeat("a", 1001)}), 422,
		"không được vượt quá 1000 ký tự")
	expectMsg(t, post(f.mgrA, map[string]any{"invoice_number": "KHONG-CO", "amount": 1, "reason": "x"}), 422,
		"Không tìm thấy hóa đơn đã duyệt (hoặc hóa đơn giấy đã cấp số) với số KHONG-CO.")
	expectMsg(t, post(f.mgrA, map[string]any{"invoice_number": "C26MEN-0001002", "amount": 500_000, "reason": "x"}), 403, "ngoài phạm vi")
	expectMsg(t, post(f.mgrA, map[string]any{"invoice_number": "C26MEN-0001003", "amount": -200_000, "reason": "x"}), 422,
		"là phiếu hoàn phí / chuyển phí do hệ thống tạo — không hủy riêng được.")
	expectMsg(t, post(f.mgrA, map[string]any{"invoice_number": "C26MEN-0001001", "amount": 999_000, "reason": "x"}), 422,
		"Số tiền hủy phải bằng đúng giá trị hóa đơn (1.000.000 đ).")
	expectMsg(t, f.mgrA.Upload(list_, map[string]string{"invoice_number": "C26MEN-0001001", "amount": "1000000", "reason": "Viết sai tên"},
		"hong.txt", []byte("hello")), 422, "Định dạng file không được phép.")
	r = f.mgrA.Upload(list_, map[string]string{"invoice_number": "C26MEN-0001001", "amount": "1000000", "reason": "Viết sai tên phụ huynh"},
		"hong.png", pngProof)
	expectMsg(t, r, 201, "Đã gửi yêu cầu hủy hóa đơn GTGT lên cấp quản lý phê duyệt!")
	idA := str(r.Body["id"])
	expectMsg(t, post(f.mgrA, map[string]any{"invoice_number": "C26MEN-0001001", "amount": 1_000_000, "reason": "Lần 2"}), 422,
		"Hóa đơn này đang có yêu cầu hủy chờ duyệt.")
	if got := e.notified("invoice_cancel_pending"); got != "AD01" {
		t.Fatalf("người nhận yêu cầu hủy: %q", got)
	}
	r = post(f.mgrA, map[string]any{"invoice_number": "GIAY-0000001", "amount": 300_000, "reason": "Ghi sai số trên giấy"})
	expectMsg(t, r, 201, "Đã gửi yêu cầu hủy hóa đơn giấy GIAY-0000001 lên cấp quản lý phê duyệt! Phiếu lập mới sẽ nhận số hóa đơn kế tiếp.")
	idPaper := str(r.Body["id"])
	r = f.admin.Do("POST", "/tuition/receipts/"+rPaper.String()+"/approve", map[string]any{})
	expectMsg(t, r, 422, "Hóa đơn GIAY-0000001 của phiếu này đang có yêu cầu hủy chờ duyệt — xử lý yêu cầu hủy trước.")

	// Danh sách theo phạm vi, chi tiết, ảnh minh chứng.
	r = f.mgrA.Do("GET", list_+"?selected_id="+idA, nil)
	if rows := list(r.Body["cancellations"]); len(rows) != 2 || num(r.Body["pending_count"]) != 2 {
		t.Fatalf("danh sách chi nhánh A: %s", r.Raw)
	}
	sel := obj(r.Body["selected"])
	if str(sel["student_name"]) != "Nguyễn Văn An" || str(obj(sel["receipt"])["receipt_number"]) != "PT-A1" || str(sel["class_name"]) == "" {
		t.Fatalf("chi tiết: %v", sel)
	}
	if g := f.mgrA.Get(str(sel["proof_url"])); g.Status != 302 {
		t.Fatalf("mở ảnh minh chứng: %d", g.Status)
	}
	if g := f.mgrB.Get(str(sel["proof_url"])); g.Status != 403 && g.Status != 404 {
		t.Fatalf("chi nhánh khác mở ảnh minh chứng: %d", g.Status)
	}
	if r := f.mgrB.Do("GET", list_+"?selected_id="+idA, nil); len(list(r.Body["cancellations"])) != 0 || r.Body["selected"] != nil {
		t.Fatalf("chi nhánh B: %s", r.Raw)
	}
	if rows := list(f.mgrA.Do("GET", list_+"?status=all&q=GIAY", nil).Body["cancellations"]); len(rows) != 1 || str(rows[0]["id"]) != idPaper {
		t.Fatalf("tìm kiếm: %v", rows)
	}
	csv := f.mgrA.Do("GET", list_+"/export?status=all", nil)
	if csv.Status != 200 || !strings.Contains(string(csv.Raw), "C26MEN-0001001") || !strings.Contains(string(csv.Raw), "Chờ duyệt hủy") {
		t.Fatalf("xuất CSV: %d %s", csv.Status, csv.Raw)
	}

	// Duyệt: chỉ invoice.approve_cancel; phiếu đã duyệt → vô hiệu + hoàn tác công nợ.
	approve := list_ + "/" + idA + "/approve"
	iamtest.Expect(t, f.mgrA.Do("POST", approve, nil), 403, "forbidden")
	expectMsg(t, f.admin.Do("POST", approve, nil), 200, "Đã duyệt hủy hóa đơn C26MEN-0001001 và hoàn tác công nợ học viên!")
	expectMsg(t, f.admin.Do("POST", approve, nil), 422, "Yêu cầu hủy này đã được xử lý trước đó.")
	var status, inv string
	if err := e.Pool.QueryRow(context.Background(), `SELECT status, invoice_number FROM finance.receipts WHERE id = $1`, rA).Scan(&status, &inv); err != nil ||
		status != "cancelled" || inv != "C26MEN-0001001" {
		t.Fatalf("phiếu sau hủy: %s %s %v", status, inv, err)
	}
	if d := e.debtOf(f.ta); d.Paid != 0 || d.Debt != 2_400_000 {
		t.Fatalf("công nợ sau hủy: %+v", d)
	}
	if got := e.notified("invoice_cancel_processed"); got != "QL01" {
		t.Fatalf("báo kết quả: %q", got)
	}

	// Hóa đơn giấy của phiếu chưa duyệt: chỉ vô hiệu phiếu.
	expectMsg(t, f.admin.Do("POST", list_+"/"+idPaper+"/approve", nil), 200,
		"Đã duyệt hủy hóa đơn giấy GIAY-0000001. Phiếu thu lập mới sẽ nhận số hóa đơn kế tiếp của chi nhánh.")
	if n := e.count(`SELECT count(*) FROM finance.receipts WHERE id = $1 AND status = 'cancelled' AND rejection_reason LIKE 'Hủy số hóa đơn giấy:%'`, rPaper); n != 1 {
		t.Fatalf("phiếu giấy sau hủy: %d", n)
	}

	// Từ chối (lý do mặc định).
	r = f.admin.Do("POST", list_, map[string]any{"invoice_number": "C26MEN-0001002", "amount": 500_000, "reason": "Sai số tiền"})
	expectMsg(t, r, 201, "Đã gửi yêu cầu hủy")
	reject := list_ + "/" + str(r.Body["id"]) + "/reject"
	iamtest.Expect(t, f.mgrB.Do("POST", reject, nil), 403, "forbidden")
	expectMsg(t, f.admin.Do("POST", reject, nil), 200, "Đã từ chối yêu cầu hủy hóa đơn C26MEN-0001002!")
	if n := e.count(`SELECT count(*) FROM finance.invoice_cancellations WHERE rejection_reason = 'Yêu cầu hủy không hợp lệ'`); n != 1 {
		t.Fatalf("lý do từ chối mặc định: %d", n)
	}
	expectMsg(t, f.admin.Do("POST", reject, nil), 422, "Yêu cầu hủy này đã được xử lý trước đó.")
	if r := f.admin.Do("GET", list_, nil); num(r.Body["approved_month_count"]) != 2 || num(r.Body["rejected_count"]) != 1 || num(r.Body["pending_count"]) != 0 {
		t.Fatalf("số liệu: %s", r.Raw)
	}
	if n := e.count(`SELECT count(*) FROM core.audit_log WHERE action LIKE 'tuition.invoice_cancellation.%'`); n != 6 {
		t.Fatalf("nhật ký: %d", n)
	}
}
