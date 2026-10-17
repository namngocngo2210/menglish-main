package tuition

import (
	"testing"

	"github.com/go-chi/chi/v5"
	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/approvals"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/catalogs"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam/iamtest"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/secretbox"
)

// Hộp "Việc cần duyệt" với nguồn Học phí thật: đếm, liệt kê, duyệt / từ chối hàng loạt, nguồn chỉ hỗ trợ từ chối.
func TestApprovalInboxTuitionSources(t *testing.T) {
	ie := iamtest.Setup(t, "academic.courses", "academic.classes", "academic.students", "finance.tuitions", "finance.receipts",
		"finance.invoice_ranges", "finance.refund_requests", "finance.invoice_cancellations", "core.notifications", "core.files",
		"core.audit_log")
	box, _ := secretbox.FromBase64("MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=")
	svc := NewService(ie.Pool, box, catalogs.NewService(ie.Pool, box, ie.Log), ie.Log)
	inbox := approvals.NewService(ie.RDB, ie.Log).Add(svc.ApprovalSources()...)
	ie.Serve(func(r chi.Router) {
		NewHandler(svc, ie.Log).Routes(r)
		approvals.NewHandler(inbox, ie.Log).Routes(r)
	})
	e := &env{Env: ie, svc: svc}
	f := newFixture(e)

	r1 := f.mgrA.Do("POST", "/tuition/receipts", transfer(f.ta, 1_000_000, "FT 001"))
	r2 := f.mgrA.Do("POST", "/tuition/receipts", transfer(f.ta, 300_000, "FT 002"))
	iamtest.Expect(t, r1, 201, "")
	iamtest.Expect(t, r2, 201, "")
	id1, id2 := str(r1.Body["id"]), str(r2.Body["id"])
	refund := e.id(`INSERT INTO finance.refund_requests (student_id, type, refund_amount, reason, requester_id)
		VALUES ($1, 'refund', 500000, 'Chuyển nhà', $2) RETURNING id`, f.sb, f.mgrBU.ID)

	// Chỉ Admin vào được hộp; Quản lý có quyền neo vẫn bị chặn
	iamtest.Expect(t, f.mgrA.Do("GET", "/approvals", nil), 403, "forbidden")
	iamtest.Expect(t, f.teacher.Do("GET", "/approvals", nil), 403, "")
	iamtest.Expect(t, f.mgrA.Do("POST", "/approvals/bulk", map[string]any{"action": "approve", "items": []string{"receipt:" + id1}}), 403, "forbidden")

	if r := f.admin.Do("GET", "/approvals/badge", nil); num(r.Body["count"]) != 3 {
		t.Fatalf("badge: %s", r.Raw)
	}
	r := f.admin.Do("GET", "/approvals", nil)
	iamtest.Expect(t, r, 200, "")
	groups := list(r.Body["groups"])
	if num(r.Body["total"]) != 3 || len(groups) != 1 || str(groups[0]["slug"]) != "hoc-phi" {
		t.Fatalf("hộp: %s", r.Raw)
	}
	secs := map[string]map[string]any{}
	for _, s := range list(r.Body["sections"]) {
		secs[str(s["key"])] = s
	}
	if num(secs["receipt"]["count"]) != 2 || len(list(secs["receipt"]["items"])) != 2 || secs["receipt"]["approve"] != true {
		t.Fatalf("nguồn phiếu thu: %v", secs["receipt"])
	}
	if secs["refund"]["approve"] != false || secs["refund"]["reject"] != true || num(secs["refund"]["count"]) != 1 {
		t.Fatalf("nguồn hoàn tiền chỉ từ chối: %v", secs["refund"])
	}
	// Chi tiết một mục
	d := f.admin.Do("GET", "/approvals/receipt/"+id1, nil)
	iamtest.Expect(t, d, 200, "")
	if obj(d.Body["item"])["amount"] != float64(1_000_000) || d.Body["can_approve"] != true || len(list(obj(d.Body["item"])["meta"])) == 0 {
		t.Fatalf("chi tiết: %s", d.Raw)
	}
	iamtest.Expect(t, f.admin.Do("GET", "/approvals/receipt/"+uuid.NewString(), nil), 404, "approval_not_found")

	// Kiểm dữ liệu
	expectMsg(t, f.admin.Do("POST", "/approvals/bulk", map[string]any{"action": "approve", "items": []string{}}), 422, "Chưa chọn mục nào.")
	expectMsg(t, f.admin.Do("POST", "/approvals/bulk", map[string]any{"action": "reject", "items": []string{"receipt:" + id2}}), 422,
		"Vui lòng nhập lý do từ chối.")
	many := make([]string, 101)
	for i := range many {
		many[i] = "receipt:" + uuid.NewString()
	}
	expectMsg(t, f.admin.Do("POST", "/approvals/bulk", map[string]any{"action": "approve", "items": many}), 422, "Mỗi lần xử lý tối đa 100 mục.")

	// Duyệt hàng loạt: phiếu 1 duyệt được, hồ sơ hoàn tiền phải duyệt ở màn gốc, mục lạ không còn chờ
	r = f.admin.Do("POST", "/approvals/bulk", map[string]any{"action": "approve",
		"items": []string{"receipt:" + id1, "refund:" + refund.String(), "receipt:" + uuid.NewString()}})
	iamtest.Expect(t, r, 200, "")
	if str(r.Body["message"]) != "Đã duyệt 1/3 mục, 2 mục không xử lý được." || str(r.Body["tone"]) != "warning" {
		t.Fatalf("duyệt hàng loạt: %s", r.Raw)
	}
	res := list(r.Body["results"])
	if res[0]["ok"] != true || res[1]["message"] != "Mục này cần xử lý ở màn gốc." ||
		res[2]["message"] != "Mục này không còn chờ duyệt hoặc ngoài phạm vi của bạn." {
		t.Fatalf("kết quả từng mục: %s", r.Raw)
	}
	if d := e.debtOf(f.ta); d.Paid != 1_000_000 {
		t.Fatalf("duyệt qua hộp phải chạy nghiệp vụ gốc: %+v", d)
	}
	// Từ chối hàng loạt phiếu 2 + hồ sơ hoàn tiền
	r = f.admin.Do("POST", "/approvals/bulk", map[string]any{"action": "reject", "reason": "Sai số tiền",
		"items": []string{"receipt:" + id2, "refund:" + refund.String()}})
	if str(r.Body["message"]) != "Đã từ chối 2/2 mục." || str(r.Body["tone"]) != "success" {
		t.Fatalf("từ chối hàng loạt: %s", r.Raw)
	}
	if e.count(`SELECT count(*) FROM finance.receipts WHERE id = $1 AND status = 'rejected' AND rejection_reason = 'Sai số tiền'`, id2) != 1 ||
		e.count(`SELECT count(*) FROM finance.refund_requests WHERE id = $1 AND status = 'rejected'`, refund) != 1 {
		t.Fatal("từ chối qua hộp phải ghi vào phân hệ gốc")
	}
	// Số đếm tính lại ngay sau khi xử lý qua hộp (đổi phiên bản cache)
	if r := f.admin.Do("GET", "/approvals/badge", nil); num(r.Body["count"]) != 0 {
		t.Fatalf("badge sau xử lý: %s", r.Raw)
	}
	if r := f.admin.Do("GET", "/approvals?group=hoc-phi", nil); str(r.Body["group"]) != "hoc-phi" || num(r.Body["total"]) != 0 {
		t.Fatalf("lọc nhóm: %s", r.Raw)
	}
}
