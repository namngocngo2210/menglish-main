package tuition

import (
	"bytes"
	"context"
	"testing"
	"time"

	"github.com/go-chi/chi/v5"
	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/files"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam/iamtest"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/filestore"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/students"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/tuition/tuitiondb"
)

// withRefundDeps: kho file local (ảnh bằng chứng) + phân hệ Học viên (duyệt bảo lưu).
func withRefundDeps(t *testing.T, e *env) {
	store, err := filestore.NewLocal(t.TempDir(), files.APIBase+files.BlobPrefix, bytes.Repeat([]byte{7}, 32))
	if err != nil {
		t.Fatal(err)
	}
	fsvc := files.NewService(e.Pool, store, e.Log, 0)
	e.svc.WithFiles(fsvc)
	stu := students.NewService(e.Pool, e.IAM, e.Log)
	stu.Tuition = e.svc
	e.svc.WithStudents(stu)
	fh := files.NewHandler(fsvc, e.Log)
	e.Serve(func(r chi.Router) {
		NewHandler(e.svc, e.Log).Routes(r)
		fh.Routes(r)
		fh.PublicRoutes(r)
	})
}

// pay: phiếu đã duyệt amount đồng cho khoản học phí, tính lại công nợ.
func (e *env) pay(tuition, student uuid.UUID, code string, amount int64) {
	e.T.Helper()
	e.exec(`INSERT INTO finance.receipts (code, tuition_id, student_id, amount, tuition_amount, payment_method, status, payment_date, approved_at)
		VALUES ($1, $2, $3, $4, $4, 'cash', 'approved', CURRENT_DATE, now())`, code, tuition, student, amount)
	if err := e.svc.inTx(context.Background(), func(_ pgx.Tx, q *tuitiondb.Queries) error {
		_, err := e.svc.recalculateDebt(context.Background(), q, tuition)
		return err
	}); err != nil {
		e.T.Fatal(err)
	}
}

var pngProof = append([]byte("\x89PNG\r\n\x1a\n"), bytes.Repeat([]byte{1}, 64)...)

func TestRefundRequests(t *testing.T) {
	e := setup(t)
	withRefundDeps(t, e)
	f := newFixture(e)
	ctx := context.Background()
	today := e.svc.today()
	e.pay(f.ta, f.sa, "PT-A1", 1_200_000)

	iamtest.Expect(t, f.teacher.Do("GET", "/tuition/refunds", nil), 403, "forbidden")
	r := f.mgrA.Do("GET", "/tuition/refunds", nil)
	iamtest.Expect(t, r, 200, "")
	if opts := list(r.Body["source_options"]); len(opts) != 1 || str(opts[0]["value"]) != f.sa.String() {
		t.Fatalf("học viên trong phạm vi: %s", r.Raw)
	}
	if b := obj(obj(r.Body["student_finance"])[f.sa.String()]); num(b["paid"]) != 1_200_000 || num(b["total_sessions"]) != 24 ||
		num(b["remaining_value"]) != 1_200_000 || num(b["admin_fee"]) != 120_000 || num(b["suggested_refund"]) != 1_080_000 {
		t.Fatalf("căn cứ hoàn phí: %v", b)
	}

	// Lập hồ sơ: kiểm dữ liệu, phạm vi.
	post := func(c *iamtest.Client, body map[string]any) iamtest.Resp {
		return c.Do("POST", "/tuition/refunds", body)
	}
	refund := map[string]any{"student_id": f.sa, "type": "refund", "refund_amount": 500_000, "reason": "Chuyển công tác"}
	iamtest.Expect(t, f.teacher.Do("POST", "/tuition/refunds", refund), 403, "forbidden")
	expectMsg(t, post(f.mgrA, refund), 422, "Hoàn tiền là phương án cuối")
	refund["no_transfer_reason"] = "abc"
	expectMsg(t, post(f.mgrA, refund), 422, "Lý do không chuyển nhượng quá ngắn.")
	refund["no_transfer_reason"] = "Không có học viên nhận"
	expectMsg(t, post(f.mgrA, map[string]any{"student_id": f.sa, "type": "extension", "extended_due_date": today.Format(time.DateOnly), "reason": "Khó khăn"}),
		422, "Hạn đóng mới phải sau hôm nay.")
	expectMsg(t, post(f.mgrA, map[string]any{"student_id": f.sa, "type": "deferral", "defer_from": today.Format(time.DateOnly),
		"defer_to": today.Format(time.DateOnly), "reason": "Ốm"}), 422, "Ngày kết thúc bảo lưu phải sau ngày bắt đầu.")
	expectMsg(t, post(f.mgrA, map[string]any{"student_id": f.sb, "type": "refund", "refund_amount": 1, "reason": "x", "no_transfer_reason": "Không có ai nhận"}),
		403, outOfScope)
	r = post(f.mgrA, refund)
	expectMsg(t, r, 201, "Đã lập hồ sơ hoàn trả học phí và gửi lên Admin phê duyệt! Hạn xử lý: ")
	refundID := str(r.Body["id"])

	// Duyệt hoàn tiền: chỉ người có quyền, bắt buộc ảnh bằng chứng.
	approve := "/tuition/refunds/" + refundID + "/approve"
	iamtest.Expect(t, f.mgrA.Do("POST", approve, nil), 403, "forbidden")
	expectMsg(t, f.admin.Do("POST", approve, map[string]any{"clawback_commission": false}), 422,
		"Hoàn tiền bắt buộc đính kèm ảnh bằng chứng (ủy nhiệm chi / biên nhận).")
	expectMsg(t, f.admin.Upload(approve, nil, "uy-nhiem-chi.pdf", []byte("%PDF-1.4\n")), 422, "Định dạng file không được phép.")
	expectMsg(t, f.admin.Upload(approve, map[string]string{"clawback_commission": "0"}, "uy-nhiem-chi.png", pngProof), 200,
		"Đã duyệt hoàn trả 500.000 đ cho học viên Nguyễn Văn An và cập nhật lại sổ nợ học phí!")
	var final, paid, debt int64
	if err := e.Pool.QueryRow(ctx, `SELECT final_amount, paid_amount, debt_amount FROM finance.tuitions WHERE id = $1`, f.ta).Scan(&final, &paid, &debt); err != nil ||
		final != 1_900_000 || paid != 700_000 || debt != 1_200_000 {
		t.Fatalf("sau hoàn phí: %d %d %d %v", final, paid, debt, err)
	}
	if n := e.count(`SELECT count(*) FROM finance.receipts WHERE source = 'refund' AND amount = -500000 AND status = 'approved' AND invoice_number IS NOT NULL`); n != 1 {
		t.Fatalf("phiếu âm hoàn phí: %d", n)
	}
	expectMsg(t, f.admin.Upload(approve, nil, "lai.png", pngProof), 422, "Yêu cầu này đã được xử lý trước đó.")
	var row map[string]any
	for _, x := range list(f.mgrA.Do("GET", "/tuition/refunds", nil).Body["history_requests"]) {
		if str(x["id"]) == refundID {
			row = x
		}
	}
	if row == nil || str(row["status"]) != "approved" || row["has_proof"] != true || row["clawback_commission"] != false {
		t.Fatalf("hồ sơ đã duyệt: %v", row)
	}
	proof := str(row["proof_url"])
	if g := f.mgrA.Get(proof); g.Status != 302 {
		t.Fatalf("Quản lý mở ảnh bằng chứng: %d", g.Status)
	}
	if g := f.mgrB.Get(proof); g.Status != 403 && g.Status != 404 {
		t.Fatalf("Quản lý chi nhánh khác mở ảnh bằng chứng: %d", g.Status)
	}

	// Chuyển nhượng sang học viên B: vượt công nợ bên nhận thì chặn; đúng thì phiếu âm / dương cấn trừ.
	r = post(f.admin, map[string]any{"student_id": f.sa, "type": "transfer", "target_student_id": f.sb, "refund_amount": 300_000, "reason": "Chuyển cho em"})
	expectMsg(t, r, 201, "Đã lập hồ sơ chuyển nhượng số dư học phí sang học viên khác và gửi lên cấp Quản lý/Kế toán phê duyệt!")
	expectMsg(t, f.admin.Do("POST", "/tuition/refunds/"+str(r.Body["id"])+"/approve", nil), 200,
		"Đã duyệt chuyển nhượng 300.000 đ từ học viên Nguyễn Văn An sang Trần Thị Bình")
	if d := e.debtOf(f.tb); d.Paid != 300_000 || d.Debt != 2_100_000 {
		t.Fatalf("bên nhận: %+v", d)
	}
	r = post(f.admin, map[string]any{"student_id": f.sa, "type": "transfer", "target_student_id": f.sb, "refund_amount": 400_000, "reason": "Lần 2"})
	e.exec(`UPDATE finance.tuitions SET final_amount = 0 WHERE id = $1`, f.tb)
	expectMsg(t, f.admin.Do("POST", "/tuition/refunds/"+str(r.Body["id"])+"/approve", nil), 422,
		"Số tiền chuyển nhượng vượt quá công nợ còn lại của học viên nhận (0 đ).")

	// Khất nợ: dời hạn đóng, dừng nhắc nợ tới hạn mới.
	newDue := today.AddDate(0, 0, 30)
	r = post(f.mgrA, map[string]any{"student_id": f.sa, "type": "extension", "extended_due_date": newDue.Format(time.DateOnly), "reason": "Chờ lương"})
	expectMsg(t, r, 201, "Đã lập hồ sơ khất nợ (dời hạn đóng)")
	expectMsg(t, f.admin.Do("POST", "/tuition/refunds/"+str(r.Body["id"])+"/approve", nil), 200,
		"Đã duyệt khất nợ cho học viên Nguyễn Văn An: hạn đóng mới "+newDue.Format("02/01/2006")+", tạm dừng nhắc nợ tới ngày này.")
	var due, paused time.Time
	if err := e.Pool.QueryRow(ctx, `SELECT due_date, reminder_paused_until FROM finance.tuitions WHERE id = $1`, f.ta).Scan(&due, &paused); err != nil ||
		!due.Equal(newDue) || !paused.Equal(newDue) {
		t.Fatalf("khất nợ: %v %v %v", due, paused, err)
	}

	// Bảo lưu bắt đầu hôm nay: học viên sang Bảo lưu, đóng băng công nợ.
	r = post(f.mgrA, map[string]any{"student_id": f.sa, "type": "deferral", "defer_from": today.Format(time.DateOnly),
		"defer_to": today.AddDate(0, 0, 20).Format(time.DateOnly), "reason": "Đi viện"})
	expectMsg(t, r, 201, "Đã lập hồ sơ bảo lưu học phí")
	expectMsg(t, f.admin.Do("POST", "/tuition/refunds/"+str(r.Body["id"])+"/approve", nil), 200, "đã đóng băng số buổi còn lại và công nợ.")
	var status string
	var frozen *int64
	if err := e.Pool.QueryRow(ctx, `SELECT s.status, t.frozen_debt_amount FROM academic.students s JOIN finance.tuitions t ON t.student_id = s.id
		WHERE s.id = $1`, f.sa).Scan(&status, &frozen); err != nil || status != "deferred" || frozen == nil || *frozen != e.debtOf(f.ta).Debt {
		t.Fatalf("bảo lưu: %s %v %v", status, frozen, err)
	}

	// Từ chối.
	r = post(f.mgrA, map[string]any{"student_id": f.sa, "type": "refund", "refund_amount": 100_000, "reason": "Lần nữa", "no_transfer_reason": "Không ai nhận"})
	reject := "/tuition/refunds/" + str(r.Body["id"]) + "/reject"
	iamtest.Expect(t, f.mgrA.Do("POST", reject, nil), 403, "forbidden")
	expectMsg(t, f.admin.Do("POST", reject, map[string]any{"rejection_reason": "Thiếu chứng từ"}), 200, "Đã từ chối hồ sơ xử lý học phí của học viên Nguyễn Văn An!")
	expectMsg(t, f.admin.Do("POST", reject, nil), 422, "Yêu cầu này đã được xử lý trước đó.")
	if n := e.count(`SELECT count(*) FROM core.audit_log WHERE action LIKE 'tuition.refund_request.%'`); n < 8 {
		t.Fatalf("nhật ký hồ sơ: %d", n)
	}
}
