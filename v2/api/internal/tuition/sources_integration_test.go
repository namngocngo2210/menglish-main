package tuition

import (
	"context"
	"testing"
	"time"

	"github.com/google/uuid"
)

func TestFinanceAndDashboardSources(t *testing.T) {
	e := setup(t)
	f := newFixture(e)
	ctx := context.Background()
	e.invoiced(f.ta, f.sa, "PT-1", "C26CG-0000001", "approved", 300_000, false)
	r2 := e.invoiced(f.tb, f.sb, "PT-2", "C26HD-0000001", "approved", 200_000, false)
	e.exec(`UPDATE finance.receipts SET payment_method = 'transfer', transaction_code = 'FT-S2', surcharge_amount = 50000 WHERE id = $1`, r2)
	old := e.invoiced(f.ta, f.sa, "PT-OLD", "C26CG-0000002", "approved", 100_000, false)
	e.exec(`UPDATE finance.receipts SET payment_date = CURRENT_DATE - 40 WHERE id = $1`, old)
	e.invoiced(f.ta, f.sa, "PT-3", "X-3", "pending", 400_000, false)
	e.invoiced(f.tb, f.sb, "PT-4", "X-4", "rejected", 400_000, false)
	// Quá hạn: A quá hạn; B quá hạn nhưng đang tạm dừng nhắc (khất nợ) → không tính.
	e.exec(`UPDATE finance.tuitions SET due_date = CURRENT_DATE - 5 WHERE id = $1`, f.ta)
	e.exec(`UPDATE finance.tuitions SET due_date = CURRENT_DATE - 3, reminder_paused_until = CURRENT_DATE + 2 WHERE id = $1`, f.tb)

	today := time.Now()
	// Ngày thu theo lịch Việt Nam (CURRENT_DATE của DB có thể lệch một ngày).
	e.exec(`UPDATE finance.receipts SET payment_date = $1 WHERE id <> $2`, dateOf(today), old)
	rev, err := e.svc.RevenueSource().Revenue(ctx, today, today)
	if err != nil {
		t.Fatal(err)
	}
	if e.svc.RevenueSource().Pending() || len(rev) != 2 {
		t.Fatalf("doanh thu Thu chi: %+v", rev)
	}
	byBranch := map[uuid.UUID]int64{}
	for _, r := range rev {
		byBranch[*r.BranchID] = r.Amount
		if *r.BranchID == f.b && (r.PaymentMethod != "transfer" || r.Surcharge != 50_000 || r.Receipts != 1) {
			t.Fatalf("dòng chi nhánh B: %+v", r)
		}
	}
	if byBranch[f.a] != 300_000 || byBranch[f.b] != 200_000 {
		t.Fatalf("doanh thu theo chi nhánh: %v", byBranch)
	}

	d := e.svc.DashboardFinance()
	if n, err := d.Revenue(ctx, nil, today, today); err != nil || *n != 500_000 {
		t.Fatalf("doanh thu Tổng quan: %v %v", n, err)
	}
	if n, _ := d.Revenue(ctx, []uuid.UUID{f.a}, today, today); *n != 300_000 {
		t.Fatalf("doanh thu chi nhánh A: %d", *n)
	}
	if n, _ := d.PendingReceipts(ctx, nil); *n != 1 {
		t.Fatalf("phiếu chờ duyệt: %d", *n)
	}
	if n, _ := d.PendingReceipts(ctx, []uuid.UUID{f.b}); *n != 0 {
		t.Fatalf("phiếu chờ duyệt chi nhánh B: %d", *n)
	}
	admin, err := e.IAM.LoadAccess(ctx, f.adminU.ID)
	if err != nil {
		t.Fatal(err)
	}
	mgrB, _ := e.IAM.LoadAccess(ctx, f.mgrBU.ID)
	if n, _ := d.OverdueTuitions(ctx, admin); *n != 1 {
		t.Fatalf("quá hạn (Admin): %d", *n)
	}
	if n, _ := d.OverdueTuitions(ctx, mgrB); *n != 0 {
		t.Fatalf("quá hạn (chi nhánh B, đang khất): %d", *n)
	}
}
