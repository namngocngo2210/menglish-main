package tuition

import (
	"testing"
)

func TestContractMath(t *testing.T) {
	// 24 buổi, 2.400.000 đ niêm yết, giảm 240.000, sách 300.000 → phải nộp 2.460.000.
	c := contract{Total: 2_400_000, Discount: 240_000, OtherFees: 300_000, Final: 2_460_000, Debt: 2_460_000, Sessions: 24}
	if c.net() != 2_160_000 || c.unitPrice() != 90_000 {
		t.Fatalf("net=%d unit=%v", c.net(), c.unitPrice())
	}
	if c.feeRemaining() != 300_000 || c.tuitionRemaining() != 2_160_000 || c.remainingSessions() != 24 {
		t.Fatalf("chưa nộp: fee=%d tuition=%d sessions=%d", c.feeRemaining(), c.tuitionRemaining(), c.remainingSessions())
	}
	// Nộp 750.000: cấn sách 300.000 trước, còn 450.000 = 5 buổi.
	c.Debt = 2_460_000 - 750_000
	if c.feeRemaining() != 0 || c.tuitionCovered() != 450_000 || c.paidSessions() != 5 || c.remainingSessions() != 19 {
		t.Fatalf("nộp một phần: fee=%d covered=%d paid=%d remaining=%d", c.feeRemaining(), c.tuitionCovered(), c.paidSessions(), c.remainingSessions())
	}
	// Nộp chưa đủ sách: chưa buổi nào được tính.
	c.Debt = 2_460_000 - 100_000
	if c.feeRemaining() != 200_000 || c.paidSessions() != 0 {
		t.Fatalf("chưa đủ sách: fee=%d paid=%d", c.feeRemaining(), c.paidSessions())
	}
	// Học phí 0 đ (học bổng): coi như đã đóng hết buổi.
	free := contract{Total: 1_000_000, Discount: 1_000_000, Sessions: 10}
	if free.paidSessions() != 10 || free.remainingSessions() != 0 {
		t.Fatalf("học bổng: %d", free.paidSessions())
	}
}

func TestCalculate(t *testing.T) {
	newCourse := Quote{Mode: "new", UnitPrice: 100_000, MaxSessions: 24}
	c := calculate(newCourse, 5, 150_000, 50_000, 20_000, 10_000, 80_000)
	if c.SessionValue != 500_000 || c.MaterialFee != 150_000 || c.Subtotal != 720_000 || c.Discount != 80_000 ||
		c.TotalDue != 640_000 || c.Amount != 650_000 || c.TuitionAmount != 420_000 || c.SurchargeAmount != 230_000 {
		t.Fatalf("khóa mới: %+v", c)
	}
	// Giảm trừ không vượt tiền buổi; số buổi âm coi là 0.
	if c := calculate(newCourse, 1, 0, 0, 0, 0, 999_999); c.Discount != 100_000 || c.Amount != 0 {
		t.Fatalf("giảm trừ vượt: %+v", c)
	}
	if c := calculate(newCourse, -3, 0, 0, 0, 0, 0); c.SessionCount != 0 || c.Amount != 0 {
		t.Fatalf("buổi âm: %+v", c)
	}
	// Hợp đồng: thu hết số buổi còn lại thì lấy đúng phần học phí còn lại (không làm tròn theo đơn giá) + sách còn nợ.
	rem, left := 3, int64(250_001)
	contractQ := Quote{Mode: "contract", UnitPrice: 83_333.67, ContractRemainingSessions: &rem, TuitionRemaining: &left, FeeDue: 120_000}
	if c := calculate(contractQ, 3, 0, 0, 0, 0, 0); c.SessionValue != 250_001 || c.FeeDue != 120_000 || c.Amount != 370_001 {
		t.Fatalf("thu hết hợp đồng: %+v", c)
	}
	if c := calculate(contractQ, 2, 0, 0, 0, 0, 0); c.SessionValue != 166_667 {
		t.Fatalf("thu một phần hợp đồng: %+v", c)
	}
}

func TestDebtStatusAndHelpers(t *testing.T) {
	cases := []struct {
		debt, paid int64
		overdue    bool
		want       string
	}{{0, 100, true, "paid"}, {10, 5, true, "partial"}, {10, 0, true, "overdue"}, {10, 0, false, "unpaid"}}
	for _, c := range cases {
		if got := debtStatus(c.debt, c.paid, c.overdue); got != c.want {
			t.Errorf("debtStatus(%d,%d,%v)=%s, muốn %s", c.debt, c.paid, c.overdue, got, c.want)
		}
	}
	if money(1_234_567) != "1.234.567 đ" || money(-5000) != "-5.000 đ" || money(0) != "0 đ" {
		t.Fatalf("money: %s %s", money(1_234_567), money(-5000))
	}
	code := " ft 12 3 "
	if normalizeReference(&code) != "FT123" || normalizeReference(nil) != "" {
		t.Fatal("normalizeReference")
	}
	class := "IELTS 6.5 – Tối 2/4/6"
	// Như TransferMemo::build: mỗi phần bỏ dấu, chỉ giữ chữ + số (kể cả khoảng trắng bên trong), viết hoa.
	if m := transferMemo("HV0012", "Nguyễn Văn Đức", &class); m != "NGUYENVANDUC HV0012 IELTS65TOI246" {
		t.Fatalf("transferMemo: %q", m)
	}
	if formatInvoice("C26MEN", 1001) != "C26MEN-0001001" {
		t.Fatal(formatInvoice("C26MEN", 1001))
	}
}
