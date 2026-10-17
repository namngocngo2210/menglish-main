package tuition

import (
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/go-chi/chi/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam/iamtest"
)

const hookSecret = "s3cret-hmac"

// hook gửi webhook SePay tới route gốc (ngoài /api/v1) như cổng SePay gọi.
func (e *env) hook(path string, body map[string]any, headers map[string]string) (int, map[string]any) {
	e.T.Helper()
	raw, _ := json.Marshal(body)
	r := chi.NewRouter()
	NewHandler(e.svc, e.Log).RootRoutes()(r)
	req := httptest.NewRequest("POST", path, strings.NewReader(string(raw)))
	req.Header.Set("Content-Type", "application/json")
	for k, v := range headers {
		req.Header.Set(k, v)
	}
	w := httptest.NewRecorder()
	r.ServeHTTP(w, req)
	out := map[string]any{}
	_ = json.Unmarshal(w.Body.Bytes(), &out)
	return w.Code, out
}

func sign(body map[string]any) map[string]string {
	raw, _ := json.Marshal(body)
	m := hmac.New(sha256.New, []byte(hookSecret))
	m.Write(raw)
	return map[string]string{"X-SePay-Signature": hex.EncodeToString(m.Sum(nil))}
}

func sepayTxn(id, content string, amount int64) map[string]any {
	return map[string]any{"id": id, "gateway": "VCB", "transactionDate": "2026-10-17 09:30:00", "accountNumber": "0123456789",
		"transferType": "in", "transferAmount": amount, "accumulated": 50_000_000, "content": content, "referenceCode": "FT" + id}
}

func expectHook(t *testing.T, status int, body map[string]any, wantStatus int, part string) {
	t.Helper()
	if status != wantStatus || !strings.Contains(str(body["message"]), part) {
		t.Fatalf("webhook: muốn %d %q, nhận %d %v", wantStatus, part, status, body)
	}
}

func (e *env) txnStatus(id string) (string, string) {
	e.T.Helper()
	var st, msg string
	if err := e.Pool.QueryRow(context.Background(), `SELECT status, coalesce(response_message, '') FROM finance.bank_transactions WHERE provider_txn_id = $1`, id).
		Scan(&st, &msg); err != nil {
		e.T.Fatal(err)
	}
	return st, msg
}

func TestSepayWebhook(t *testing.T) {
	e := setup(t)
	f := newFixture(e)
	clean := func() {
		e.exec(`DELETE FROM finance.payment_providers`)
		e.exec(`DELETE FROM finance.bank_accounts`)
		e.exec(`DELETE FROM crm.leads WHERE code = 'KH-01J9ZQ8X7Y6W5V4T3S2R1P0N9M'`)
	}
	clean()
	t.Cleanup(clean)
	const hookPath = "/hook/sepay-gateway/v1/add-payment"

	// Tắt bằng cờ env: 503 để SePay gửi lại sau; nhật ký giao dịch cũng báo tắt.
	body := sepayTxn("1001", "CHUYEN TIEN", 100_000)
	st, out := e.hook(hookPath, body, sign(body))
	expectHook(t, st, out, 503, "SePay webhook hiện đang tắt trên hệ thống.")
	expectMsg(t, f.mgrA.Do("GET", "/tuition/sepay/transactions", nil), 503, "SePay webhook hiện đang tắt trên hệ thống.")
	if r := f.admin.Do("GET", "/tuition/sepay", nil); r.Status != 200 || r.Body["enabled"] != false || r.Body["config"] != nil {
		t.Fatalf("màn SePay khi tắt: %s", r.Raw)
	}

	e.svc.WithSepay(SepaySettings{WebhookEnabled: true, WebhookSecret: hookSecret})
	e.exec(`INSERT INTO finance.bank_accounts (bank_code, bank_name, account_number, account_holder) VALUES ('VCB', 'Vietcombank', '0123 456 789', 'MENGLISH')`)

	// Xác thực HMAC: thiếu / sai chữ ký → 401; chữ ký có tiền tố sha256= qua X-Signature được chấp nhận.
	st, out = e.hook(hookPath, body, nil)
	expectHook(t, st, out, 401, "Thiếu chữ ký xác thực webhook.")
	st, out = e.hook(hookPath, body, map[string]string{"X-SePay-Signature": "deadbeef"})
	expectHook(t, st, out, 401, "Chữ ký xác thực HMAC-SHA256 không hợp lệ!")
	e.exec(`UPDATE finance.payment_providers SET is_active = false`)
	st, out = e.hook(hookPath, body, sign(body))
	expectHook(t, st, out, 403, "SePay Webhook is currently disabled in system settings.")
	e.exec(`UPDATE finance.payment_providers SET is_active = true, auth_method = 'none'`)
	st, out = e.hook(hookPath, body, sign(body))
	expectHook(t, st, out, 503, "Webhook authentication is not configured.")
	e.exec(`UPDATE finance.payment_providers SET auth_method = 'hmac_sha256'`)
	noID := sepayTxn("", "X", 1)
	st, out = e.hook(hookPath, noID, sign(noID))
	expectHook(t, st, out, 422, "Thiếu mã giao dịch SePay.")

	// Chưa khớp học viên: lưu lại, báo người đối soát (Admin quản lý tài khoản ngân hàng).
	st, out = e.hook("/api/sepay/webhook", body, map[string]string{"X-Signature": "sha256=" + sign(body)["X-SePay-Signature"]})
	expectHook(t, st, out, 200, "chưa tìm thấy mã học viên để gạch nợ tự động")
	if s, _ := e.txnStatus("1001"); s != "unmatched" || e.notified("sepay_review") != "AD01" {
		t.Fatalf("giao dịch chưa khớp: %s, người nhận %q", s, e.notified("sepay_review"))
	}
	// Gửi lại cùng mã: không xử lý lần 2.
	st, out = e.hook(hookPath, body, sign(body))
	expectHook(t, st, out, 200, "(Duplicate)")
	if n := e.count(`SELECT count(*) FROM finance.bank_transactions`); n != 1 {
		t.Fatalf("giao dịch trùng được ghi %d lần", n)
	}

	// Tiền ra: ghi nhận, bỏ qua công nợ. Tài khoản lạ: lưu để kiểm tra, không gạch nợ.
	outTx := sepayTxn("1002", "HV0001", 100_000)
	outTx["transferType"] = "out"
	st, out = e.hook(hookPath, outTx, sign(outTx))
	expectHook(t, st, out, 200, "Giao dịch tiền ra được ghi nhận nhưng không xử lý công nợ.")
	foreign := sepayTxn("1003", "NGUYEN VAN AN", 100_000)
	foreign["accountNumber"] = "999888777"
	st, out = e.hook(hookPath, foreign, sign(foreign))
	expectHook(t, st, out, 200, "Tài khoản nhận tiền không thuộc cấu hình của hệ thống")
	if out["success"] != false {
		t.Fatalf("tài khoản lạ phải success=false: %v", out)
	}
	if a, _ := e.txnStatus("1002"); a != "ignored" {
		t.Fatalf("tiền ra: %s", a)
	}
	if a, msg := e.txnStatus("1003"); a != "rejected_account" || !strings.Contains(msg, "999888777") {
		t.Fatalf("tài khoản lạ: %s %s", a, msg)
	}

	// Khớp theo nội dung CK gợi ý của khoản học phí: phiếu đã duyệt nguồn SePay, cấp HĐĐT, giảm công nợ.
	e.exec(`UPDATE finance.tuitions SET transfer_memo = 'NGUYEN VAN AN HV0001 IE' WHERE id = $1`, f.ta)
	memo := sepayTxn("1004", "MBVCB.123 nguyen van an hv0001 ie chuyen khoan", 1_000_000)
	st, out = e.hook(hookPath, memo, sign(memo))
	expectHook(t, st, out, 200, "Khớp thanh toán thành công cho học viên Nguyễn Văn An!")
	if num(out["remaining_debt"]) != 1_400_000 || !strings.HasPrefix(str(out["invoice_number"]), "C26MEN-") {
		t.Fatalf("kết quả khớp: %v", out)
	}
	if d := e.debtOf(f.ta); d.Debt != 1_400_000 || d.Status != "partial" {
		t.Fatalf("công nợ sau SePay: %+v", d)
	}
	if n := e.count(`SELECT count(*) FROM finance.receipts WHERE tuition_id = $1 AND source = 'sepay' AND status = 'approved' AND amount = 1000000
		AND transaction_code = '1004' AND creator_id IS NULL AND invoice_number IS NOT NULL`, f.ta); n != 1 {
		t.Fatalf("phiếu SePay: %d", n)
	}
	if a, msg := e.txnStatus("1004"); a != "matched" || !strings.Contains(msg, "Đã gạch nợ thành công 1.000.000") {
		t.Fatalf("giao dịch khớp: %s %s", a, msg)
	}
	if got := e.notified("sepay_payment"); got != "AD01,QL01" {
		t.Fatalf("người nhận báo khớp lệnh: %q", got)
	}
	if n := e.count(`SELECT count(*) FROM core.audit_log WHERE action = 'tuition.receipt.sepay'`); n != 1 {
		t.Fatalf("nhật ký SePay: %d", n)
	}

	// Khớp theo mã học viên; chuyển thừa: chỉ ghi phần còn nợ, báo đối soát phần thừa; hết nợ rồi thì không ghi nữa.
	course := e.course("TOEIC", 500_000, 10)
	cls := e.class("TO-01", f.a, course)
	s3 := e.student("HV-00123", "Lê Thị Cúc", f.a, &cls)
	t3 := e.tuition(s3, cls, f.a, 500_000, 10)
	over := sepayTxn("1005", "LETHICUC HV-00123 DONG HOC", 800_000)
	st, out = e.hook(hookPath, over, sign(over))
	expectHook(t, st, out, 200, "Khớp thanh toán thành công cho học viên Lê Thị Cúc!")
	if d := e.debtOf(t3); d.Debt != 0 || d.Status != "paid" {
		t.Fatalf("công nợ chuyển thừa: %+v", d)
	}
	if n := e.count(`SELECT count(*) FROM finance.receipts WHERE tuition_id = $1 AND amount = 500000`, t3); n != 1 {
		t.Fatalf("phiếu chuyển thừa phải ghi đúng phần nợ: %d", n)
	}
	if _, msg := e.txnStatus("1005"); !strings.Contains(msg, "Khách chuyển thừa 300.000") {
		t.Fatalf("ghi chú chuyển thừa: %s", msg)
	}
	again := sepayTxn("1006", "HV-00123 DONG THEM", 200_000)
	st, out = e.hook(hookPath, again, sign(again))
	expectHook(t, st, out, 200, "Hợp đồng đã thanh toán đủ trước đó")
	if a, _ := e.txnStatus("1006"); a != "overpaid" {
		t.Fatalf("đã hết nợ: %s", a)
	}

	// Đã có phiếu chuyển khoản tay cùng mã giao dịch → không ghi lần 2; cùng số tiền gần ngày → chờ đối soát.
	e.exec(`UPDATE finance.tuitions SET transfer_memo = 'TRAN THI BINH HV0002' WHERE id = $1`, f.tb)
	manual := e.invoiced(f.tb, f.sb, "PT-TAY1", "C26HD-0000001", "approved", 300_000, false)
	e.exec(`UPDATE finance.receipts SET payment_method = 'transfer', transaction_code = 'ft 777' WHERE id = $1`, manual)
	dup := sepayTxn("1007", "TRAN THI BINH HV0002", 300_000)
	dup["referenceCode"] = "FT777"
	st, out = e.hook(hookPath, dup, sign(dup))
	expectHook(t, st, out, 200, "Giao dịch đã có phiếu thu tay tương ứng; không ghi nhận 2 lần.")
	if a, msg := e.txnStatus("1007"); a != "duplicate_manual" || !strings.Contains(msg, "PT-TAY1") {
		t.Fatalf("trùng phiếu tay: %s %s", a, msg)
	}
	e.invoiced(f.tb, f.sb, "PT-TAY2", "C26HD-0000002", "approved", 250_000, false)
	e.exec(`UPDATE finance.receipts SET payment_method = 'transfer' WHERE code = 'PT-TAY2'`)
	near := sepayTxn("1008", "TRAN THI BINH HV0002", 250_000)
	near["transactionDate"] = "2026-10-17 10:00:00"
	e.exec(`UPDATE finance.receipts SET payment_date = DATE '2026-10-16' WHERE code = 'PT-TAY2'`)
	st, out = e.hook(hookPath, near, sign(near))
	expectHook(t, st, out, 200, "không ghi nhận 2 lần")
	if a, _ := e.txnStatus("1008"); a != "needs_review" {
		t.Fatalf("phiếu tay gần ngày: %s", a)
	}
	if n := e.count(`SELECT count(*) FROM finance.receipts WHERE tuition_id = $1 AND source = 'sepay'`, f.tb); n != 0 {
		t.Fatalf("trùng phiếu tay vẫn tạo phiếu SePay: %d", n)
	}

	// Mã khách CRM: khách chưa chuyển thành học viên thì khớp học viên theo SĐT chuẩn hóa (84… → 0…).
	s4 := e.id(`INSERT INTO academic.students (code, full_name, phone, branch_id, status) VALUES ('HS-LEAD4', 'Phạm Minh Đức', '0912 777 888', $1, 'studying') RETURNING id`, f.a)
	t4 := e.tuition(s4, cls, f.a, 900_000, 10)
	e.exec(`INSERT INTO crm.leads (code, name, phone, stage, branch_id) VALUES ('KH-01J9ZQ8X7Y6W5V4T3S2R1P0N9M', 'Phạm Minh Đức', '+84 912 777 888', 'consulting', $1)`, f.a)
	lead := sepayTxn("1009", "KH-01J9ZQ8X7Y6W5V4T3S2R1P0N9M PHAM MINH DUC", 400_000)
	st, out = e.hook(hookPath, lead, sign(lead))
	expectHook(t, st, out, 200, "Khớp thanh toán thành công cho học viên Phạm Minh Đức!")
	if d := e.debtOf(t4); d.Debt != 500_000 {
		t.Fatalf("khớp theo mã khách CRM: %+v", d)
	}

	// Cấu hình SePay: chỉ người quản lý tài khoản ngân hàng; khóa không gửi xuống trình duyệt.
	iamtest.Expect(t, f.teacher.Do("GET", "/tuition/sepay", nil), 403, "forbidden")
	iamtest.Expect(t, f.mgrA.Do("GET", "/tuition/sepay", nil), 403, "forbidden")
	iamtest.Expect(t, f.mgrA.Do("POST", "/tuition/sepay/config", map[string]any{}), 403, "forbidden")
	iamtest.Expect(t, f.teacher.Do("GET", "/tuition/sepay/transactions", nil), 403, "forbidden")
	r := f.admin.Do("GET", "/tuition/sepay", nil)
	cfg := obj(r.Body["config"])
	if r.Status != 200 || r.Body["enabled"] != true || cfg["has_secret"] != true || cfg["has_api_key"] != false ||
		!strings.HasSuffix(str(cfg["current_endpoint"]), "/hook/sepay-gateway/v1/add-payment") || len(list(r.Body["transactions"])) != 9 ||
		strings.Contains(string(r.Raw), hookSecret) || cfg["saved_host"] != "dungthu.meducation.vn" {
		t.Fatalf("màn SePay: %s", r.Raw)
	}
	r = f.mgrA.Do("GET", "/tuition/sepay/transactions", nil)
	if rows := list(r.Body["data"]); r.Status != 200 || r.Body["success"] != true || len(rows) != 9 || str(rows[0]["sepay_id"]) != "1009" ||
		str(rows[0]["status_label"]) != "Đã khớp" {
		t.Fatalf("giao dịch gần đây: %s", r.Raw)
	}

	form := map[string]any{"webhook_name": "Xác Thực Thanh Toán", "webhook_url": "https://hoc.menglish.vn/hook/sepay-gateway/v1/add-payment",
		"transaction_type": "in", "data_format": "json", "auth_method": "hmac_sha256", "secret_key": "", "is_active": true, "auto_retry": true}
	with := func(k string, v any) map[string]any {
		m := map[string]any{}
		for kk, vv := range form {
			m[kk] = vv
		}
		m[k] = v
		return m
	}
	save := func(b map[string]any) iamtest.Resp { return f.admin.Do("POST", "/tuition/sepay/config", b) }
	expectMsg(t, save(with("webhook_name", " ")), 422, "Vui lòng nhập tên webhook.")
	expectMsg(t, save(with("webhook_url", "ftp://x")), 422, "Trường URL webhook không phải là một URL hợp lệ.")
	expectMsg(t, save(with("transaction_type", "both")), 422, "Giá trị đã chọn trong trường loại giao dịch không hợp lệ.")
	expectMsg(t, save(with("data_format", "xml")), 422, "Giá trị đã chọn trong trường định dạng dữ liệu không hợp lệ.")
	expectMsg(t, save(with("auth_method", "none")), 422, "Giá trị đã chọn trong trường phương thức xác thực không hợp lệ.")
	// Để trống Secret Key = giữ khóa cũ: chữ ký cũ vẫn hợp lệ.
	expectMsg(t, save(form), 200, "Đã lưu cấu hình kết nối SePay Gateway thành công!")
	ok := sepayTxn("1010", "CHUYEN TIEN", 10_000)
	st, out = e.hook(hookPath, ok, sign(ok))
	expectHook(t, st, out, 200, "chưa tìm thấy mã học viên")

	// Chuyển sang API key.
	expectMsg(t, save(with("auth_method", "api_key")), 200, "thành công")
	bad := sepayTxn("1011", "CHUYEN TIEN", 10_000)
	st, out = e.hook(hookPath, bad, map[string]string{"Authorization": "Bearer k-123"})
	expectHook(t, st, out, 401, "API key không hợp lệ.")
	keyed := with("auth_method", "api_key")
	keyed["api_key"] = "k-123"
	expectMsg(t, save(keyed), 200, "thành công")
	st, out = e.hook(hookPath, bad, map[string]string{"Authorization": "Bearer k-123"})
	expectHook(t, st, out, 200, "chưa tìm thấy mã học viên")
	st, out = e.hook(hookPath, sepayTxn("1012", "X", 1), map[string]string{"X-API-Key": "k-999"})
	expectHook(t, st, out, 401, "API key không hợp lệ.")
	if n := e.count(`SELECT count(*) FROM core.audit_log WHERE action = 'tuition.sepay_config.update' AND NOT properties::text LIKE '%k-123%'`); n != 3 {
		t.Fatalf("nhật ký cấu hình SePay: %d", n)
	}

	// Giao dịch gần đây ở đường dẫn cũ cần đăng nhập.
	rr := chi.NewRouter()
	NewHandler(e.svc, e.Log).RootRoutes()(rr)
	w := httptest.NewRecorder()
	rr.ServeHTTP(w, httptest.NewRequest("GET", "/api/sepay/transactions", nil))
	if w.Code != http.StatusUnauthorized {
		t.Fatalf("giao dịch gần đây không đăng nhập: %d %s", w.Code, w.Body.String())
	}
}

func TestHookLimiter(t *testing.T) {
	l := newHookLimiter(2, 60e9)
	now := time.Now()
	if !l.allow("1.2.3.4", now) || !l.allow("1.2.3.4", now) || l.allow("1.2.3.4", now) || !l.allow("5.6.7.8", now) {
		t.Fatal("giới hạn theo IP sai")
	}
	if !l.allow("1.2.3.4", now.Add(61e9)) {
		t.Fatal("cửa sổ mới phải cho qua")
	}
}
