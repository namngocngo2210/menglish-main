package tuition

import (
	"testing"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam/iamtest"
)

// Tài khoản nhận tiền ở form lập phiếu / bill: tài khoản cơ sở → tài khoản dùng chung, không lấy tài khoản cơ sở khác
// (BankAccount::resolveFor, TuitionController bankByBranch, StudentTuition::resolveBankAccount).
func TestReceiptBankAccountByBranch(t *testing.T) {
	e := setup(t)
	f := newFixture(e)
	e.exec(`DELETE FROM finance.bank_accounts`)
	// Chỉ có tài khoản của cơ sở B (cờ mặc định VietQR) → học viên cơ sở A không có tài khoản dùng được.
	accB := e.id(`INSERT INTO finance.bank_accounts (bank_code, bank_name, account_number, account_holder, branch_id, is_default_vietqr)
		VALUES ('TCB', 'Techcombank', '9999', 'CS HA DONG', $1, true) RETURNING id`, f.b)
	form := func() (map[string]any, map[string]any) {
		t.Helper()
		r := f.mgrA.Do("GET", "/tuition/receipts/form?student_id="+f.sa.String(), nil)
		iamtest.Expect(t, r, 200, "")
		tu := list(r.Body["tuitions"])
		if len(tu) != 1 {
			t.Fatalf("form: %s", r.Raw)
		}
		return obj(r.Body["default_bank"]), obj(tu[0]["bank"])
	}
	bill := func() map[string]any {
		t.Helper()
		r := f.mgrA.Do("GET", "/tuition/bill/"+f.ta.String(), nil)
		iamtest.Expect(t, r, 200, "")
		return r.Body
	}
	if d, tb := form(); d != nil || tb != nil {
		t.Fatalf("không được lấy tài khoản cơ sở khác: %v / %v", d, tb)
	}
	if b := bill(); b["bank_account"] != nil || str(b["qr_warning"]) == "" {
		t.Fatalf("bill phải cảnh báo chưa cấu hình tài khoản: %v", b)
	}

	// Hợp đồng gắn tài khoản cơ sở B: vẫn không dùng cho khoản học phí cơ sở A.
	e.exec(`UPDATE finance.tuitions SET bank_account_id = $2 WHERE id = $1`, f.ta, accB)
	if _, tb := form(); tb != nil {
		t.Fatalf("tài khoản hợp đồng thuộc cơ sở khác: %v", tb)
	}

	// Có tài khoản dùng chung → dùng chung.
	shared := e.id(`INSERT INTO finance.bank_accounts (bank_code, bank_name, account_number, account_holder)
		VALUES ('VCB', 'Vietcombank', '0011', 'MENGLISH') RETURNING id`)
	d, tb := form()
	if str(d["id"]) != shared.String() || str(d["scope"]) != "Tài khoản dùng chung các cơ sở" || str(tb["id"]) != shared.String() {
		t.Fatalf("tài khoản dùng chung: %v / %v", d, tb)
	}
	if b := obj(bill()["bank_account"]); str(b["id"]) != shared.String() {
		t.Fatalf("bill dùng tài khoản dùng chung: %v", b)
	}

	// Có tài khoản riêng của cơ sở A → ưu tiên tài khoản cơ sở.
	accA := e.id(`INSERT INTO finance.bank_accounts (bank_code, bank_name, account_number, account_holder, branch_id)
		VALUES ('ACB', 'ACB', '5555', 'CS CAU GIAY', $1) RETURNING id`, f.a)
	d, tb = form()
	if str(d["id"]) != accA.String() || str(d["scope"]) != "Tài khoản cơ sở Chi nhánh CG" || str(tb["id"]) != accA.String() {
		t.Fatalf("tài khoản cơ sở A: %v / %v", d, tb)
	}
	if b := obj(bill()["bank_account"]); str(b["id"]) != accA.String() {
		t.Fatalf("bill dùng tài khoản cơ sở A: %v", b)
	}

	// Hợp đồng gắn tài khoản dùng chung: dùng tài khoản của hợp đồng.
	e.exec(`UPDATE finance.tuitions SET bank_account_id = $2 WHERE id = $1`, f.ta, shared)
	if _, tb := form(); str(tb["id"]) != shared.String() || str(tb["scope"]) != "Tài khoản gắn với hợp đồng" {
		t.Fatalf("tài khoản của hợp đồng: %v", tb)
	}
}
