package tuition

import (
	"context"
	"slices"
	"testing"
	"time"

	"github.com/go-chi/chi/v5"
	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/catalogs"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam/iamtest"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/kpi/kpisrc"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/secretbox"
)

func kpiTexts(rs []kpisrc.Record) []string {
	out := make([]string, 0, len(rs))
	for _, r := range rs {
		out = append(out, r.At.Format("02/01")+" "+r.Text)
	}
	return out
}

// Nguồn KPI của Học phí (KpiAutoCounter::tuitionNoReminder / receiptRejected).
func TestKpiSources(t *testing.T) {
	ie := iamtest.Setup(t, "academic.courses", "academic.classes", "academic.students", "crm.leads", "finance.tuitions",
		"finance.receipts", "finance.tuition_contact_logs", "core.notifications")
	box, _ := secretbox.FromBase64("MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=")
	e := &env{Env: ie, svc: NewService(ie.Pool, box, catalogs.NewService(ie.Pool, box, ie.Log), ie.Log)}
	e.Serve(func(r chi.Router) { NewHandler(e.svc, e.Log).Routes(r) })
	e.svc.now = func() time.Time { return time.Date(2026, 10, 20, 12, 0, 0, 0, vnZone) }
	ctx := context.Background()
	hn := e.Branch("HN01")
	sale, _ := e.Member("SALE01", "Sale", []uuid.UUID{hn}, iam.RoleSalesConsultant)
	other, _ := e.Member("SALE02", "Sale 2", []uuid.UUID{hn}, iam.RoleSalesConsultant)
	class := e.class("K1", hn, e.course("C1", 1000000, 10))
	an, binh, cuc := e.student("HV-1", "Bé An", hn, &class), e.student("HV-2", "Bé Bình", hn, &class), e.student("HV-3", "Bé Cúc", hn, &class)
	for i, st := range []uuid.UUID{an, binh} {
		e.exec(`INSERT INTO crm.leads (code, name, phone, stage, branch_id, assigned_user_id, converted_student_id)
			VALUES ($1, 'KH', '0912000111', 'won', $2, $3, $4)`, "KH-"+string(rune('A'+i)), hn, sale.ID, st)
	}
	e.exec(`INSERT INTO crm.leads (code, name, phone, stage, branch_id, assigned_user_id, converted_student_id)
		VALUES ('KH-C', 'KH', '0912000111', 'won', $1, $2, $3)`, hn, other.ID, cuc)
	tuition := func(st uuid.UUID, due string, debt int64, paused *string) uuid.UUID {
		return e.id(`INSERT INTO finance.tuitions (student_id, class_id, branch_id, total_amount, final_amount, debt_amount, due_date,
			reminder_paused_until) VALUES ($1, $2, $3, 1000000, 1000000, $4, $5, $6) RETURNING id`, st, class, hn, debt, due, paused)
	}
	paused := "2026-10-15"
	tuition(an, "2026-10-05", 500000, nil)               // quá hạn, không nhắc → tính
	reminded := tuition(binh, "2026-10-06", 500000, nil) // có nhắc 3 ngày trước hạn → không tính
	early := tuition(binh, "2026-10-09", 500000, nil)    // chỉ nhắc 6 ngày trước hạn → tính
	paidLate := tuition(an, "2026-10-08", 0, nil)        // thu đủ sau hạn → tính
	tuition(an, "2026-10-07", 0, nil)                    // thu đủ đúng hạn → không tính
	tuition(an, "2026-10-10", 500000, &paused)           // khất nợ qua hạn → không tính
	tuition(an, "2026-10-25", 500000, nil)               // chưa tới hạn
	tuition(cuc, "2026-10-05", 500000, nil)              // khách của người khác
	e.exec(`INSERT INTO finance.tuition_contact_logs (tuition_id, action, contacted_at) VALUES ($1, 'contacted', '2026-10-03 10:00+07'),
		($2, 'contacted', '2026-10-03 10:00+07')`, reminded, early)
	e.exec(`INSERT INTO finance.receipts (code, tuition_id, student_id, amount, status, approved_at)
		VALUES ('PT-1', $1, $2, 1000000, 'approved', '2026-10-09 09:00+07')`, paidLate, an)

	from := time.Date(2026, 10, 1, 0, 0, 0, 0, vnZone)
	to := time.Date(2026, 10, 31, 23, 59, 59, 0, vnZone)
	src := e.svc.KpiSources()
	res, err := src["tuition_no_reminder"](ctx, sale.ID, from, to)
	if err != nil {
		t.Fatal(err)
	}
	if got := kpiTexts(res.Records); !slices.Equal(got, []string{"05/10 Bé An: hạn 05/10, chưa có lần nhắc học phí",
		"08/10 Bé An: hạn 08/10, chưa có lần nhắc học phí", "09/10 Bé Bình: hạn 09/10, chưa có lần nhắc học phí"}) {
		t.Fatalf("không nhắc học phí: %q", got)
	}

	// Phiếu thu bị trả về: mỗi phiếu một lần trong ngày; thông báo không có nội dung lấy tiêu đề
	rid := uuid.New()
	note := func(user uuid.UUID, at, body string, receipt uuid.UUID) {
		e.exec(`INSERT INTO core.notifications (user_id, type, title, body, data, created_at)
			VALUES ($1, 'receipt_rejected', 'Phiếu thu bị trả về cần sửa', $2, jsonb_build_object('receipt_id', $3::text), $4)`,
			user, body, receipt, at)
	}
	note(sale.ID, "2026-10-02 09:00+07", "Phiếu thu #PT-9 bị từ chối: sai số tiền.", rid)
	note(sale.ID, "2026-10-02 15:00+07", "Phiếu thu #PT-9 bị từ chối lần 2.", rid)
	note(sale.ID, "2026-10-04 09:00+07", "", rid)
	note(other.ID, "2026-10-04 09:00+07", "Phiếu của người khác", uuid.New())
	note(sale.ID, "2026-09-30 09:00+07", "Ngoài kỳ", uuid.New())
	res, err = src["receipt_rejected"](ctx, sale.ID, from, to)
	if err != nil {
		t.Fatal(err)
	}
	if got := kpiTexts(res.Records); !slices.Equal(got, []string{"02/10 Phiếu thu #PT-9 bị từ chối: sai số tiền.",
		"04/10 Phiếu thu bị trả về cần sửa"}) {
		t.Fatalf("phiếu thu bị trả về: %q", got)
	}
}
