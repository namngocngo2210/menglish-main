package approvals_test

import (
	"context"
	"testing"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/approvals"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/catalogs"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/classes"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/crm"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam/iamtest"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/secretbox"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/tasks"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/tuition"
)

// Đủ nguồn như main.go: mọi truy vấn chạy được, Admin thấy đủ nhóm, nguồn CRM / Lớp học có dữ liệu thật.
func TestInboxAllSources(t *testing.T) {
	e := iamtest.Setup(t, "crm.leads", "crm.branch_transfers", "crm.enrollment_confirmations", "academic.courses", "academic.classes",
		"academic.students", "academic.enrollments", "finance.receipts", "ops.work_tasks")
	ctx := context.Background()
	box, _ := secretbox.FromBase64("MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=")
	cat := catalogs.NewService(e.Pool, box, e.Log)
	cls := classes.NewService(e.Pool, cat, e.Log)
	crmSvc := crm.NewService(e.Pool, e.IAM, e.Log)
	inbox := approvals.NewService(e.RDB, e.Log).Add(tuition.NewService(e.Pool, box, cat, e.Log).ApprovalSources()...).
		Add(crmSvc.ApprovalSources()...).Add(cls.ApprovalSource(), tasks.NewService(e.Pool, e.Log).ApprovalSource())
	e.Serve(approvals.NewHandler(inbox, e.Log).Routes)

	hn, hcm := e.Branch("HN"), e.Branch("HCM")
	_, ad := e.Member("AD01", "Admin", []uuid.UUID{hn}, iam.RoleAdmin)
	tvU := e.User("TV01", "Tư vấn HN", []uuid.UUID{hn}, iam.RoleSalesConsultant)
	tv2 := e.User("TV02", "Tư vấn HCM", []uuid.UUID{hcm}, iam.RoleSalesConsultant)
	exec := func(sql string, args ...any) uuid.UUID {
		t.Helper()
		var id uuid.UUID
		if err := e.Pool.QueryRow(ctx, sql, args...).Scan(&id); err != nil {
			t.Fatal(err)
		}
		return id
	}
	lead := exec(`INSERT INTO crm.leads (code, name, phone, branch_id, assigned_user_id) VALUES ('KH-01HZZZZZZZZZZZZZZZZZABCDEF', 'Khách A', '0901000001', $1, $2) RETURNING id`, hn, tvU.ID)
	transfer := exec(`INSERT INTO crm.branch_transfers (lead_id, from_branch_id, to_branch_id, from_user_id, to_user_id, requested_by, reason)
		VALUES ($1, $2, $3, $4, $5, $4, 'Khách chuyển nhà') RETURNING id`, lead, hn, hcm, tvU.ID, tv2.ID)
	course := exec(`INSERT INTO academic.courses (code, name, tuition_fee, total_lessons) VALUES ('C1', 'Khóa 1', 1000000, 10) RETURNING id`)
	class := exec(`INSERT INTO academic.classes (code, name, branch_id, course_id, status) VALUES ('L1', 'Lớp 1', $1, $2, 'active') RETURNING id`, hn, course)
	student := exec(`INSERT INTO academic.students (code, full_name, phone, branch_id, status) VALUES ('HV01', 'Học viên 1', '0901000002', $1, 'studying') RETURNING id`, hn)
	exec(`INSERT INTO academic.enrollments (student_id, class_id, enrolled_at, curriculum_delivered) VALUES ($1, $2, CURRENT_DATE, true) RETURNING id`, student, class)

	r := ad.Do("GET", "/approvals", nil)
	iamtest.Expect(t, r, 200, "")
	got := map[string]float64{}
	for _, s := range iamtest.List(t, r, "sections") {
		got[s["key"].(string)] = s["count"].(float64)
	}
	want := map[string]float64{"receipt": 0, "invoice_cancellation": 0, "refund": 0, "enrollment": 1, "crm_confirmation": 0,
		"crm_branch_transfer": 1, "work_task": 0}
	for k, v := range want {
		if n, ok := got[k]; !ok || n != v {
			t.Fatalf("nguồn %s: muốn %v, nhận %v (%s)", k, v, got[k], r.Raw)
		}
	}
	for _, src := range []string{"receipt", "invoice_cancellation", "refund", "crm_confirmation", "enrollment", "work_task"} {
		iamtest.Expect(t, ad.Do("GET", "/approvals/"+src+"/"+uuid.NewString(), nil), 404, "approval_not_found")
	}
	d := ad.Do("GET", "/approvals/crm_branch_transfer/"+transfer.String(), nil)
	if item := d.Body["item"].(map[string]any); item["title"] != "Khách A: Chi nhánh HN → Chi nhánh HCM" || d.Body["can_approve"] != true {
		t.Fatalf("chuyển cơ sở: %s", d.Raw)
	}
	// Xác nhận nhập học chỉ link màn gốc
	enr := iamtest.List(t, ad.Do("GET", "/approvals?group=dao-tao", nil), "sections")
	for _, s := range enr {
		if s["key"] == "enrollment" {
			items := s["items"].([]any)
			if s["approve"] != false || len(items) != 1 || items[0].(map[string]any)["subtitle"] != "✓ Giáo trình · ✗ Nhóm Zalo" {
				t.Fatalf("xác nhận nhập học: %v", s)
			}
		}
	}
	r = ad.Do("POST", "/approvals/bulk", map[string]any{"action": "reject", "reason": "Chưa đủ hồ sơ", "items": []string{"crm_branch_transfer:" + transfer.String()}})
	if r.Body["message"] != "Đã từ chối chuyển cơ sở." {
		t.Fatalf("từ chối chuyển cơ sở: %s", r.Raw)
	}
}
