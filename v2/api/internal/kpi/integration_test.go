package kpi_test

import (
	"context"
	"encoding/csv"
	"strings"
	"testing"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam/iamtest"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/kpi"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/kpi/kpisrc"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/reports"
)

var vn = time.FixedZone("ICT", 7*3600)

// "Hôm nay" 17/10/2026: kỳ tháng 9 đã chốt, tháng 10 chưa.
var now = time.Date(2026, 10, 17, 9, 0, 0, 0, vn)

func setup(t *testing.T, wire func(*kpi.Service)) (*iamtest.Env, *kpi.Service) {
	e := iamtest.Setup(t, "hr.kpi_criteria", "core.audit_log")
	if _, err := e.Pool.Exec(context.Background(),
		"DELETE FROM core.settings WHERE key LIKE 'kpi\\_%' OR key = 'payroll_academic_kpi_fund'"); err != nil {
		t.Fatal(err)
	}
	svc := kpi.NewService(e.Pool, e.Log)
	svc.SetClock(func() time.Time { return now })
	if wire != nil {
		wire(svc)
	}
	e.Serve(kpi.NewHandler(svc, e.Log).Routes)
	return e, svc
}

func auditCount(t *testing.T, e *iamtest.Env, action string) int {
	t.Helper()
	var n int
	if err := e.Pool.QueryRow(context.Background(), "SELECT count(*) FROM core.audit_log WHERE action = $1", action).Scan(&n); err != nil {
		t.Fatal(err)
	}
	return n
}

func expectMsg(t *testing.T, r iamtest.Resp, status int, msg string) {
	t.Helper()
	if r.Status != status || r.Body["message"] != msg {
		t.Fatalf("muốn %d %q, nhận %d %s", status, msg, r.Status, r.Raw)
	}
}

// criterion: tiêu chí đếm số lần (0 lần 100%, đến max_half lần 50%).
func criterion(role, name string, weight float64, maxHalf int, extra map[string]any) map[string]any {
	in := map[string]any{"role": role, "group_name": "1. Vận hành", "name": name, "weight": weight, "measure": "count", "unit": "lan",
		"max_full": 0, "max_half": maxHalf}
	for k, v := range extra {
		in[k] = v
	}
	return in
}

func criteriaItems(t *testing.T, c *iamtest.Client, role string) []map[string]any {
	t.Helper()
	r := c.Do("GET", "/kpi/criteria?role="+role, nil)
	iamtest.Expect(t, r, 200, "")
	out := []map[string]any{}
	for _, g := range iamtest.List(t, r, "criteria_groups") {
		for _, it := range g["items"].([]any) {
			out = append(out, it.(map[string]any))
		}
	}
	return out
}

func TestKpiPermissions(t *testing.T) {
	e, _ := setup(t, nil)
	hn := e.Branch("HN01")
	iamtest.Expect(t, e.Anon().Do("GET", "/kpi/criteria", nil), 401, "")
	_, teacher := e.Member("GV01", "Giáo viên", []uuid.UUID{hn}, iam.RoleTeacherParttime)
	for _, p := range []string{"/kpi/criteria", "/kpi/monthly", "/kpi/evaluate/" + uuid.NewString(), "/kpi/attendance-review"} {
		iamtest.Expect(t, teacher.Do("GET", p, nil), 403, "")
	}
	iamtest.Expect(t, teacher.Do("POST", "/kpi/evaluate/"+uuid.NewString(), map[string]any{}), 403, "")
	// Vai trò chưa có tiêu chí → KPI của tôi bị chặn
	iamtest.Expect(t, teacher.Do("GET", "/kpi/mine", nil), 403, "no_kpi")
	// Bảng KPI tự động: giáo viên (work_task.view, phạm vi Của tôi) chỉ thấy mình
	r := teacher.Do("GET", "/kpi/board", nil)
	iamtest.Expect(t, r, 200, "")
	if r.Body["can_see_staff"] != false || len(iamtest.List(t, r, "staff")) != 1 {
		t.Fatalf("bảng KPI giáo viên: %s", r.Raw)
	}

	// Học vụ xem / duyệt được nhưng không sửa tiêu chí
	_, staff := e.Member("HV01", "Học vụ", []uuid.UUID{hn}, iam.RoleAcademicStaff)
	r = staff.Do("GET", "/kpi/criteria?role=academic_staff", nil)
	iamtest.Expect(t, r, 200, "")
	if r.Body["can_manage"] != false {
		t.Errorf("can_manage = %v", r.Body["can_manage"])
	}
	iamtest.Expect(t, staff.Do("POST", "/kpi/criteria", criterion("academic_staff", "Báo cáo trễ", 50, 2, nil)), 403, "")
	iamtest.Expect(t, staff.Do("POST", "/kpi/criteria/cycle", map[string]any{"role": "manager", "period_months": 3}), 403, "")
	iamtest.Expect(t, staff.Do("DELETE", "/kpi/criteria/"+uuid.NewString(), nil), 403, "")

	// Quản lý mất kpi.confirm: xem phiếu được, không duyệt
	e.Revoke("manager", "kpi.confirm")
	_, mgr := e.Member("QL01", "Quản lý", []uuid.UUID{hn}, iam.RoleManager)
	iamtest.Expect(t, mgr.Do("GET", "/kpi/monthly", nil), 200, "")
	iamtest.Expect(t, mgr.Do("POST", "/kpi/evaluate/"+uuid.NewString(), map[string]any{}), 403, "")
}

func TestKpiCriteriaCRUDAndCycle(t *testing.T) {
	e, svc := setup(t, func(s *kpi.Service) {
		s.AddSources(kpisrc.Sources{"daily_report_late": func(context.Context, uuid.UUID, time.Time, time.Time) (kpisrc.Result, error) {
			return kpisrc.Result{}, nil
		}})
	})
	_, admin := e.Member("AD01", "Quản trị", nil, iam.RoleAdmin)

	for _, c := range []struct {
		patch map[string]any
		msg   string
	}{
		{map[string]any{"role": "student"}, "Vai trò không hợp lệ."},
		{map[string]any{"name": " "}, "Vui lòng nhập tên tiêu chí."},
		{map[string]any{"weight": nil}, "Vui lòng nhập trọng số."},
		{map[string]any{"weight": 120}, "Trọng số phải từ 0 đến 100."},
		{map[string]any{"unit": ""}, "Vui lòng chọn đơn vị đếm."},
		{map[string]any{"unit": "kg"}, "Đơn vị đếm không hợp lệ."},
		{map[string]any{"measure": "sum"}, "Cách đo không hợp lệ."},
		{map[string]any{"max_half": nil}, "Vui lòng nhập ngưỡng đạt 50% (số lần)."},
		{map[string]any{"max_full": 3, "max_half": 1}, "Ngưỡng đạt 50% phải lớn hơn hoặc bằng ngưỡng đạt 100%."},
		{map[string]any{"auto_source": "magic"}, "Nguồn số liệu không hợp lệ."},
		{map[string]any{"description": strings.Repeat("a", 2001)}, "Cách đếm tối đa 2000 ký tự."},
	} {
		r := admin.Do("POST", "/kpi/criteria", criterion("academic_staff", "Báo cáo trễ", 50, 2, c.patch))
		iamtest.Expect(t, r, 422, "invalid")
		if r.Body["message"] != c.msg {
			t.Errorf("%v: %v, muốn %q", c.patch, r.Body["message"], c.msg)
		}
	}

	r := admin.Do("POST", "/kpi/criteria", criterion("academic_staff", "Báo cáo ngày trễ", 60, 2, map[string]any{"auto_source": "daily_report_late"}))
	expectMsg(t, r, 201, "Đã thêm tiêu chí KPI.")
	r = admin.Do("POST", "/kpi/criteria", criterion("academic_staff", "Phụ huynh phàn nàn", 40, 1, map[string]any{"new_group": "2. Chăm sóc",
		"auto_source": "crm_sla_late"}))
	expectMsg(t, r, 201, "Đã thêm tiêu chí KPI.")
	items := criteriaItems(t, admin, "academic_staff")
	if len(items) != 2 {
		t.Fatalf("tiêu chí: %v", items)
	}
	first, second := items[0], items[1]
	if first["name"] != "Báo cáo ngày trễ" || first["weight"] != "60" || first["fund_amount"] != 1_200_000.0 ||
		first["threshold_full"] != "0 lần" || first["threshold_half"] != "≤ 2 lần" || first["source_pending"] != nil {
		t.Errorf("tiêu chí 1: %v", first)
	}
	// Nguồn chưa có ở v2 → người chấm điền tay
	if p, _ := second["source_pending"].(string); !strings.HasPrefix(p, "Chờ phân hệ SLA / phạt CRM") {
		t.Errorf("tiêu chí 2: %v", second)
	}

	// Số liệu cho báo cáo tuần: nguồn đã có ở v2 là mục tự động
	m := svc.ReportMetrics(reports.Skeleton())
	kc, err := m.KpiCriteria(context.Background(), "academic_staff")
	if err != nil || len(kc) != 2 || !kc[0].Auto || kc[1].Auto || kc[0].Unit != "lần" {
		t.Fatalf("KpiCriteria: %+v %v", kc, err)
	}

	id := first["id"].(string)
	r = admin.Do("PUT", "/kpi/criteria/"+id, criterion("academic_staff", "Báo cáo ngày nộp trễ", 50, 3, map[string]any{"is_active": true,
		"auto_source": "daily_report_late"}))
	expectMsg(t, r, 200, "Đã cập nhật tiêu chí KPI.")
	iamtest.Expect(t, admin.Do("PUT", "/kpi/criteria/"+uuid.NewString(), criterion("academic_staff", "X", 5, 1, nil)), 404, "not_found")
	expectMsg(t, admin.Do("DELETE", "/kpi/criteria/"+second["id"].(string), nil), 200, "Đã xoá tiêu chí KPI.")
	items = criteriaItems(t, admin, "academic_staff")
	if len(items) != 1 || items[0]["name"] != "Báo cáo ngày nộp trễ" || items[0]["threshold_half"] != "≤ 3 lần" {
		t.Fatalf("sau sửa / xoá: %v", items)
	}
	for action, want := range map[string]int{"kpi.criteria.create": 2, "kpi.criteria.update": 1, "kpi.criteria.delete": 1} {
		if n := auditCount(t, e, action); n != want {
			t.Errorf("nhật ký %s = %d, muốn %d", action, n, want)
		}
	}

	// Chu kỳ chấm + thưởng theo xếp loại
	iamtest.Expect(t, admin.Do("POST", "/kpi/criteria/cycle", map[string]any{"role": "teacher_parttime", "period_months": 2}), 422, "invalid")
	r = admin.Do("POST", "/kpi/criteria/cycle", map[string]any{"role": "teacher_parttime", "period_months": 3, "grade_fund": -1})
	expectMsg(t, r, 422, "Thưởng KPI tối đa phải từ 0 đến 1.000.000.000 đ.")
	r = admin.Do("POST", "/kpi/criteria/cycle", map[string]any{"role": "teacher_parttime", "period_months": 3, "grade_fund": 1_500_000})
	expectMsg(t, r, 200, "Đã đổi chu kỳ chấm KPI: theo quý.")
	r = admin.Do("GET", "/kpi/criteria?role=teacher_parttime", nil)
	if r.Body["period_months"] != 3.0 || r.Body["grade_fund"] != 1_500_000.0 || len(iamtest.List(t, r, "grades")) != 5 {
		t.Fatalf("chu kỳ: %s", r.Raw)
	}
	if n := auditCount(t, e, "kpi.criteria.cycle"); n != 1 {
		t.Errorf("nhật ký chu kỳ = %d", n)
	}
}

// sheetEnv: bộ tiêu chí Học vụ (báo cáo trễ tự động + phản hồi phụ huynh điền tay), học vụ HN / HCM, trưởng học thuật duyệt.
type sheetEnv struct {
	e                *iamtest.Env
	svc              *kpi.Service
	hn, hcm          uuid.UUID
	hv1, hv2         iamtest.User
	staff1, staff2   *iamtest.Client
	lead, admin, mgr *iamtest.Client
	manualID         string
}

func newSheetEnv(t *testing.T) *sheetEnv {
	s := &sheetEnv{}
	var lateUser uuid.UUID
	s.e, s.svc = setup(t, func(svc *kpi.Service) {
		svc.AddSources(kpisrc.Sources{"daily_report_late": func(_ context.Context, uid uuid.UUID, from, to time.Time) (kpisrc.Result, error) {
			at := time.Date(2026, 9, 5, 10, 0, 0, 0, vn)
			if uid != lateUser || at.Before(from) || at.After(to) {
				return kpisrc.Result{}, nil
			}
			return kpisrc.Result{Records: []kpisrc.Record{{At: at, Text: "Báo cáo ngày 04/09 nộp trễ"},
				{At: at.Add(time.Hour), Text: "Báo cáo ngày 03/09", Uncounted: true, Note: "đã giải trình"}}}, nil
		}})
	})
	e := s.e
	s.hn, s.hcm = e.Branch("HN01"), e.Branch("HCM01")
	var admin iamtest.User
	admin, s.admin = e.Member("AD01", "Quản trị", nil, iam.RoleAdmin)
	_ = admin
	s.hv1, s.staff1 = e.Member("HV01", "Học vụ Hà Nội", []uuid.UUID{s.hn}, iam.RoleAcademicStaff)
	s.hv2, s.staff2 = e.Member("HV02", "Học vụ Sài Gòn", []uuid.UUID{s.hcm}, iam.RoleAcademicStaff)
	lateUser = s.hv1.ID
	_, s.lead = e.Member("TT01", "Trưởng học thuật", []uuid.UUID{s.hn}, iam.RoleAcademicLead)
	// Quản lý phạm vi Chi nhánh
	e.Revoke("manager", "kpi.scope_all")
	e.Grant("manager", "kpi.scope_branch")
	_, s.mgr = e.Member("QL01", "Quản lý HN", []uuid.UUID{s.hn}, iam.RoleManager)

	expectMsg(t, s.admin.Do("POST", "/kpi/criteria", criterion("academic_staff", "Báo cáo ngày trễ", 60, 2,
		map[string]any{"auto_source": "daily_report_late"})), 201, "Đã thêm tiêu chí KPI.")
	expectMsg(t, s.admin.Do("POST", "/kpi/criteria", criterion("academic_staff", "Phụ huynh phàn nàn", 40, 1,
		map[string]any{"group_name": "2. Chăm sóc", "allow_na": true})), 201, "Đã thêm tiêu chí KPI.")
	for _, it := range criteriaItems(t, s.admin, "academic_staff") {
		if it["name"] == "Phụ huynh phàn nàn" {
			s.manualID = it["id"].(string)
		}
	}
	return s
}

func lines(t *testing.T, r iamtest.Resp) []map[string]any {
	t.Helper()
	out := []map[string]any{}
	for _, g := range iamtest.List(t, r, "groups") {
		for _, it := range g["items"].([]any) {
			out = append(out, it.(map[string]any))
		}
	}
	return out
}

func TestKpiSheetsFlow(t *testing.T) {
	s := newSheetEnv(t)
	e := s.e
	hv1 := s.hv1.ID.String()

	// Phiếu KPI tháng: trưởng học thuật (toàn hệ thống) thấy 2 học vụ; quản lý chi nhánh chỉ thấy HN
	r := s.lead.Do("GET", "/kpi/monthly?period=2026-09", nil)
	iamtest.Expect(t, r, 200, "")
	staff := iamtest.List(t, r, "staff")
	if r.Body["total"] != 2.0 || len(staff) != 2 {
		t.Fatalf("danh sách phiếu: %s", r.Raw)
	}
	sh := staff[0]["sheets"].([]any)[0].(map[string]any)
	if staff[0]["name"] != "Học vụ Hà Nội" || sh["status_label"] != "Chờ duyệt" || sh["role"] != "Học vụ (Academic Staff)" {
		t.Errorf("dòng phiếu: %v", staff[0])
	}
	r = s.mgr.Do("GET", "/kpi/monthly?period=2026-09", nil)
	if staff := iamtest.List(t, r, "staff"); len(staff) != 1 || staff[0]["id"] != hv1 || len(iamtest.List(t, r, "branch_options")) != 1 {
		t.Fatalf("quản lý chi nhánh: %s", r.Raw)
	}
	iamtest.Expect(t, s.mgr.Do("GET", "/kpi/evaluate/"+s.hv2.ID.String()+"?period=2026-09", nil), 404, "not_found")
	// Lọc vai trò không có ai
	if r := s.lead.Do("GET", "/kpi/monthly?period=2026-09&role=manager", nil); r.Body["total"] != 0.0 {
		t.Errorf("lọc vai trò: %s", r.Raw)
	}

	// Phiếu tháng 9: số tự động 1 lần (bản ghi đã giải trình không tính) → 50%; tiêu chí điền tay còn thiếu
	r = s.lead.Do("GET", "/kpi/evaluate/"+hv1+"?period=2026-09", nil)
	iamtest.Expect(t, r, 200, "")
	ls := lines(t, r)
	if r.Body["can_close"] != true || r.Body["can_decide"] != true || r.Body["missing"] != 1.0 || r.Body["fund"] != 2_000_000.0 || len(ls) != 2 {
		t.Fatalf("phiếu: %s", r.Raw)
	}
	auto := ls[0]
	if auto["auto"] != true || auto["value"] != 1.0 || auto["level"] != 50.0 || len(auto["evidence"].([]any)) != 2 {
		t.Fatalf("dòng tự động: %v", auto)
	}
	if ev := auto["evidence"].([]any)[1].(map[string]any); ev["counted"] != false || ev["note"] != "đã giải trình" || ev["date"] != "05/09" {
		t.Errorf("bằng chứng: %v", ev)
	}

	decide := func(c *iamtest.Client, user string, body map[string]any) iamtest.Resp {
		in := map[string]any{"month": 9, "year": 2026, "action": "approve"}
		for k, v := range body {
			in[k] = v
		}
		return c.Do("POST", "/kpi/evaluate/"+user, in)
	}
	// Tự duyệt phiếu mình bị chặn
	iamtest.Expect(t, decide(s.staff2, s.hv2.ID.String(), nil), 403, "self_evaluation")
	if r := s.staff2.Do("GET", "/kpi/evaluate/"+s.hv2.ID.String()+"?period=2026-09", nil); r.Body["is_self"] != true || r.Body["can_decide"] != false {
		t.Errorf("phiếu của mình: %s", r.Raw)
	}
	for _, c := range []struct {
		body map[string]any
		msg  string
	}{
		{map[string]any{"month": 13}, "Tháng không hợp lệ."},
		{map[string]any{"year": 1999}, "Năm không hợp lệ."},
		{map[string]any{"action": "draft"}, "Thao tác không hợp lệ."},
		{map[string]any{"action": "reject", "reject_reason": "  "}, "Cần ghi lý do không duyệt."},
		{map[string]any{"action": "reject", "reject_reason": strings.Repeat("x", 1001)}, "Lý do không duyệt tối đa 1000 ký tự."},
		{map[string]any{"month": 10}, "Duyệt KPI từ ngày cuối tháng 31/10."},
		{map[string]any{"actual": map[string]string{s.manualID: "1,5"}}, `Số liệu "Phụ huynh phàn nàn" phải là số nguyên từ 0.`},
		{map[string]any{"actual": map[string]string{s.manualID: "-1"}}, `Số liệu "Phụ huynh phàn nàn" phải là số nguyên từ 0.`},
		{nil, "Còn 1 tiêu chí chưa có số liệu."},
	} {
		expectMsg(t, decide(s.lead, hv1, c.body), 422, c.msg)
	}

	// Không duyệt (lưu số điền tay), rồi duyệt
	expectMsg(t, decide(s.lead, hv1, map[string]any{"action": "reject", "reject_reason": "Thiếu minh chứng",
		"actual": map[string]string{s.manualID: "1"}}), 200, "Đã không duyệt phiếu KPI của Học vụ Hà Nội.")
	r = s.staff1.Do("GET", "/kpi/mine?period=2026-09", nil)
	iamtest.Expect(t, r, 200, "")
	if r.Body["status"] != "rejected" || r.Body["reject_reason"] != "Thiếu minh chứng" || r.Body["decided_by"] != "Trưởng học thuật" ||
		r.Body["missing"] != 0.0 {
		t.Fatalf("KPI của tôi (không duyệt): %s", r.Raw)
	}
	// Đánh dấu Không phát sinh: tiêu chí bỏ khỏi tổng → 50%
	expectMsg(t, decide(s.lead, hv1, map[string]any{"na": map[string]bool{s.manualID: true}}), 200, "Đã duyệt KPI tháng 9/2026 của Học vụ Hà Nội (50%).")
	expectMsg(t, decide(s.lead, hv1, map[string]any{"actual": map[string]string{s.manualID: "0"}}), 422, "Phiếu KPI này đã duyệt.")
	r = s.lead.Do("GET", "/kpi/evaluate/"+hv1+"?period=2026-09", nil)
	if r.Body["status"] != "confirmed" || r.Body["can_decide"] != false || r.Body["total"] != 50.0 || r.Body["reject_reason"] != nil {
		t.Fatalf("phiếu đã duyệt: %s", r.Raw)
	}
	if ls := lines(t, r); ls[1]["na"] != true || len(ls[0]["evidence"].([]any)) != 2 {
		t.Errorf("dòng đã duyệt: %v", ls)
	}
	r = s.staff1.Do("GET", "/kpi/mine?period=2026-09", nil)
	if r.Body["status_label"] != "Đã duyệt" || r.Body["rate_label"] != "50%" || r.Body["amount"] != 1_000_000.0 {
		t.Fatalf("KPI của tôi: %s", r.Raw)
	}
	r = s.lead.Do("GET", "/kpi/monthly?period=2026-09", nil)
	if sh := iamtest.List(t, r, "staff")[0]["sheets"].([]any)[0].(map[string]any); sh["amount_label"] != "1.000.000 đ" || sh["rate_label"] != "50%" {
		t.Errorf("danh sách sau duyệt: %v", sh)
	}
	if auditCount(t, e, "kpi.sheet.reject") != 1 || auditCount(t, e, "kpi.sheet.approve") != 1 {
		t.Error("nhật ký duyệt phiếu")
	}

	// Trưởng học thuật chưa có bộ tiêu chí → không có KPI của tôi
	iamtest.Expect(t, s.lead.Do("GET", "/kpi/mine", nil), 403, "no_kpi")

	// Tạo phiếu đầu kỳ: 2 học vụ, chạy lại không tạo thêm
	if n, err := s.svc.EnsureSheets(context.Background(), 10, 2026); err != nil || n != 2 {
		t.Fatalf("tạo phiếu: %d %v", n, err)
	}
	if n, _ := s.svc.EnsureSheets(context.Background(), 10, 2026); n != 0 {
		t.Fatalf("tạo lại phiếu: %d", n)
	}

	// Báo cáo tuần: bằng chứng mục tự động
	m := s.svc.ReportMetrics(reports.Skeleton())
	kc, _ := m.KpiCriteria(context.Background(), "academic_staff")
	n, details, err := m.KpiEvidence(context.Background(), kc[0], reports.Writer{ID: s.hv1.ID},
		time.Date(2026, 9, 1, 0, 0, 0, 0, vn), time.Date(2026, 9, 30, 0, 0, 0, 0, vn))
	if err != nil || n != 1 || len(details) != 2 || details[0].Text != "05/09 · Báo cáo ngày 04/09 nộp trễ" || details[1].Tone != "muted" {
		t.Fatalf("KpiEvidence: %d %+v %v", n, details, err)
	}
}

func TestKpiQuarterCycle(t *testing.T) {
	s := newSheetEnv(t)
	expectMsg(t, s.admin.Do("POST", "/kpi/criteria/cycle", map[string]any{"role": "academic_staff", "period_months": 3}), 200,
		"Đã đổi chu kỳ chấm KPI: theo quý.")
	hv1 := s.hv1.ID.String()
	r := s.lead.Do("GET", "/kpi/evaluate/"+hv1+"?period=2026-08", nil)
	if r.Body["period_months"] != 3.0 || r.Body["month"] != 7.0 || r.Body["period_label"] != "Quý 3/2026 (tháng 7–9)" || r.Body["grades"] == nil {
		t.Fatalf("phiếu quý: %s", r.Raw)
	}
	r = s.lead.Do("POST", "/kpi/evaluate/"+hv1, map[string]any{"month": 10, "year": 2026, "action": "approve"})
	expectMsg(t, r, 422, "Duyệt KPI quý từ ngày cuối quý 31/12.")
	r = s.lead.Do("POST", "/kpi/evaluate/"+hv1, map[string]any{"month": 8, "year": 2026, "action": "approve",
		"actual": map[string]string{s.manualID: "0"}})
	expectMsg(t, r, 200, "Đã duyệt KPI quý 3/2026 của Học vụ Hà Nội (70%).")
	r = s.staff1.Do("GET", "/kpi/mine?period=2026-09", nil)
	if r.Body["period"] != "2026-07" || r.Body["grade"] == nil || r.Body["status"] != "confirmed" {
		t.Fatalf("KPI quý của tôi: %s", r.Raw)
	}
}

// fakeAttendance: điểm danh giả (phân hệ Lớp học có adapter riêng, test ở gói classes).
type fakeAttendance struct{ id uuid.UUID }

func (f fakeAttendance) AttendanceDay(_ context.Context, _ *iam.Access, day time.Time, _ *uuid.UUID) ([]kpisrc.AttendanceRecord, []kpisrc.Option, error) {
	if day.Format(time.DateOnly) != "2026-10-16" {
		return nil, nil, nil
	}
	return []kpisrc.AttendanceRecord{
			{ID: f.id, Student: "Bé An", Class: "Lớp 1", Teacher: "Cô Lan", Status: "present", StatusLabel: "Có mặt", ReviewStatus: "pending_review"},
			{ID: uuid.New(), Student: "Bé Bình", Class: "Lớp 1", Teacher: "Cô Lan", Status: "late", StatusLabel: "Đi muộn"},
			{ID: uuid.New(), Student: "Bé Cúc", Class: "Lớp 1", Teacher: "Cô Lan", Status: "absent", StatusLabel: "Vắng"},
		},
		[]kpisrc.Option{{Value: uuid.NewString(), Label: "Lớp 1"}}, nil
}

func (f fakeAttendance) ReviewAttendance(_ context.Context, _ pgx.Tx, _ *iam.Access, id uuid.UUID, _, _ string) (kpisrc.ReviewedAttendance, bool, error) {
	if id != f.id {
		return kpisrc.ReviewedAttendance{}, false, nil
	}
	return kpisrc.ReviewedAttendance{ID: id, Label: "Bé An · Lớp 1 · 16/10/2026", OldStatus: "pending_review"}, true, nil
}

func TestKpiAttendanceReview(t *testing.T) {
	// Chưa nối phân hệ Lớp học
	e, _ := setup(t, nil)
	_, admin := e.Member("AD01", "Quản trị", nil, iam.RoleAdmin)
	r := admin.Do("GET", "/kpi/attendance-review", nil)
	if r.Status != 200 || r.Body["pending"] == nil || r.Body["date"] != "2026-10-17" {
		t.Fatalf("chưa có phân hệ: %s", r.Raw)
	}

	att := uuid.New()
	e, _ = setup(t, func(s *kpi.Service) { s.WithAttendance(fakeAttendance{att}) })
	hn := e.Branch("HN01")
	_, staff := e.Member("HV01", "Học vụ", []uuid.UUID{hn}, iam.RoleAcademicStaff)
	r = staff.Do("GET", "/kpi/attendance-review?date=2026-10-16", nil)
	iamtest.Expect(t, r, 200, "")
	sum := r.Body["summary"].(map[string]any)
	if len(iamtest.List(t, r, "records")) != 3 || sum["present"] != 1.0 || sum["late"] != 1.0 || sum["absent"] != 1.0 || r.Body["pending"] != nil {
		t.Fatalf("điểm danh: %s", r.Raw)
	}
	expectMsg(t, staff.Do("POST", "/kpi/attendance-review/"+att.String(), map[string]any{"decision": "maybe"}), 422, "Quyết định rà soát không hợp lệ.")
	expectMsg(t, staff.Do("POST", "/kpi/attendance-review/"+att.String(), map[string]any{"decision": "approved",
		"review_note": strings.Repeat("a", 1001)}), 422, "Ghi chú rà soát tối đa 1000 ký tự.")
	iamtest.Expect(t, staff.Do("POST", "/kpi/attendance-review/"+uuid.NewString(), map[string]any{"decision": "approved"}), 404, "not_found")
	expectMsg(t, staff.Do("POST", "/kpi/attendance-review/"+att.String(), map[string]any{"decision": "approved"}), 200, "Đã duyệt điểm danh.")
	expectMsg(t, staff.Do("POST", "/kpi/attendance-review/"+att.String(), map[string]any{"decision": "rejected", "review_note": "Sai giờ"}), 200,
		"Đã trả lại điểm danh để chỉnh sửa.")
	if n := auditCount(t, e, "kpi.attendance_review"); n != 2 {
		t.Errorf("nhật ký rà soát = %d", n)
	}
	// Quản lý mất kpi.confirm → không rà soát được
	e.Revoke("manager", "kpi.confirm")
	_, mgr := e.Member("QL01", "Quản lý", []uuid.UUID{hn}, iam.RoleManager)
	iamtest.Expect(t, mgr.Do("POST", "/kpi/attendance-review/"+att.String(), map[string]any{"decision": "approved"}), 403, "")
}

type fakeClasses map[uuid.UUID]kpisrc.ClassStats

func (f fakeClasses) KpiBoardClasses(_ context.Context, ids []uuid.UUID, _, _ time.Time) (map[uuid.UUID]kpisrc.ClassStats, error) {
	return f, nil
}

type fakeTasks map[uuid.UUID]kpisrc.TaskStats

func (f fakeTasks) KpiBoardTasks(_ context.Context, ids []uuid.UUID, _, _ time.Time) (map[uuid.UUID]kpisrc.TaskStats, error) {
	return f, nil
}

func TestKpiBoard(t *testing.T) {
	cls, tasks := fakeClasses{}, fakeTasks{}
	e, _ := setup(t, func(s *kpi.Service) { s.WithBoard(cls, tasks) })
	hn, hcm := e.Branch("HN01"), e.Branch("HCM01")
	gv, _ := e.Member("GV01", "An Giáo viên", []uuid.UUID{hn}, iam.RoleTeacherFulltime)
	tg := e.User("TG01", "Bình Trợ giảng", []uuid.UUID{hcm}, iam.RoleAssistant)
	hv := e.User("HV01", "Cúc Học vụ", []uuid.UUID{hn}, iam.RoleAcademicStaff)
	e.User("SA01", "Dung Tư vấn", []uuid.UUID{hn}, iam.RoleSalesConsultant)
	cls[gv.ID] = kpisrc.ClassStats{Classes: 2, Students: 20, Retained: 18, AttendanceTotal: 40, AttendancePresent: 36}
	tasks[gv.ID] = kpisrc.TaskStats{Total: 4, Done: 3}
	_, lead := e.Member("TT01", "Trưởng học thuật", []uuid.UUID{hn}, iam.RoleAcademicLead)
	_, mgr := e.Member("QL01", "Quản lý HN", []uuid.UUID{hn}, iam.RoleManager)

	// Toàn hệ thống: 3 nhân sự giảng dạy / Học vụ (không gồm tư vấn)
	r := lead.Do("GET", "/kpi/board?month=2026-10", nil)
	iamtest.Expect(t, r, 200, "")
	rows := iamtest.List(t, r, "staff")
	if len(rows) != 3 || r.Body["can_see_staff"] != true || len(iamtest.List(t, r, "staff_options")) != 3 || r.Body["pending"] != nil {
		t.Fatalf("bảng KPI: %s", r.Raw)
	}
	if g := rows[0]; g["name"] != "An Giáo viên" || g["classes"] != 2.0 || g["retention"] != 90.0 || g["attendance"] != 90.0 ||
		g["tasks"] != 75.0 || g["tasks_detail"] != "3/4 việc" || g["homework"] != nil {
		t.Errorf("dòng giáo viên: %v", g)
	}
	if r.Body["period_label"] != "Tháng 10/2026 (01/10/2026 - 31/10/2026)" {
		t.Errorf("kỳ: %v", r.Body["period_label"])
	}
	// Lọc một người
	if r := lead.Do("GET", "/kpi/board?user_id="+hv.ID.String(), nil); len(iamtest.List(t, r, "staff")) != 1 {
		t.Errorf("lọc nhân sự: %s", r.Raw)
	}
	// Quản lý (Công việc phạm vi Chi nhánh) không thấy trợ giảng HCM
	r = mgr.Do("GET", "/kpi/board", nil)
	for _, row := range iamtest.List(t, r, "staff") {
		if row["id"] == tg.ID.String() {
			t.Fatal("quản lý HN thấy nhân sự HCM")
		}
	}
	if r.Body["total"] != 2.0 {
		t.Errorf("tổng quản lý: %v", r.Body["total"])
	}
	expectMsg(t, lead.Do("GET", "/kpi/board?month=10-2026", nil), 422, "Tháng không hợp lệ (định dạng YYYY-MM).")

	// Xuất CSV: tháng đảo ngược tự sắp lại
	r = lead.Do("GET", "/kpi/board/export?month=2026-10&month_to=2026-09", nil)
	iamtest.Expect(t, r, 200, "")
	if cd := r.Header.Get("Content-Disposition"); !strings.Contains(cd, "kpi-2026-09-2026-10.csv") {
		t.Errorf("tên file: %s", cd)
	}
	recs, err := csv.NewReader(strings.NewReader(strings.TrimPrefix(string(r.Raw), "\xEF\xBB\xBF"))).ReadAll()
	if err != nil || len(recs) != 4 || recs[0][0] != "Mã NS" || recs[1][1] != "An Giáo viên" || recs[1][3] != "90%" || recs[2][5] != "Chưa có dữ liệu" {
		t.Fatalf("CSV: %v %v", recs, err)
	}

	// Chưa nối phân hệ
	e2, _ := setup(t, nil)
	_, lead2 := e2.Member("TT01", "Trưởng học thuật", nil, iam.RoleAcademicLead)
	if r := lead2.Do("GET", "/kpi/board", nil); r.Body["pending"] != "Chờ phân hệ Lớp học / Công việc chuyển sang hệ thống mới." {
		t.Errorf("chưa có phân hệ: %s", r.Raw)
	}
}
