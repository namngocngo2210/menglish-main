package payroll_test

import (
	"testing"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam/iamtest"
)

// Phạm vi chi nhánh (rà soát Laravel 19/10/2026): biên bản vi phạm (Penalty::constrainToViewer, staffVisibleTo), bảng xếp
// hạng KPI (DataScope kpi, ẩn tiền khi không có payroll.view), đơn giá giáo viên (PayrollController::staffInScope).
func TestPayrollStaffScope(t *testing.T) {
	l := newLifecycle(t)
	l.create(t)
	gvHCM := l.User("GV03", "Dung HCM", []uuid.UUID{l.hcm}, iam.RoleTeacherFulltime)
	classHN := l.id(`INSERT INTO academic.classes (code, name, branch_id, status) VALUES ('HN-1', 'Lớp HN', $1, 'active') RETURNING id`, l.hn)
	classHCM := l.id(`INSERT INTO academic.classes (code, name, branch_id, status) VALUES ('HCM-1', 'Lớp HCM', $1, 'active') RETURNING id`, l.hcm)
	pHCM := l.id(`INSERT INTO hr.penalties (code, user_id, violation_type, error_category, violation_date, amount, status)
		VALUES ('BB-2026-902', $1, 'Đi muộn', 'operations', '2026-10-16', 50000, 'pending') RETURNING id`, gvHCM.ID)
	_, mgr := l.Member("QL01", "Quản lý HN", []uuid.UUID{l.hn}, iam.RoleManager) // violation / kpi / attendance_staff mức chi nhánh

	// Biên bản: quản lý HN chỉ thấy biên bản của nhân sự HN; ô chọn nhân sự / lớp chỉ trong chi nhánh.
	r := mgr.Do("GET", "/penalties", nil)
	iamtest.Expect(t, r, 200, "")
	rows := iamtest.List(t, r, "penalties")
	if len(rows) != 1 || rows[0]["id"] != l.penalty.String() {
		t.Fatalf("biên bản trong phạm vi: %s", r.Raw)
	}
	for _, o := range iamtest.List(t, r, "users") {
		if o["value"] == gvHCM.ID.String() || o["value"] == l.sale.ID.String() {
			t.Fatalf("ô chọn nhân sự lộ chi nhánh khác: %s", r.Raw)
		}
	}
	if cls := iamtest.List(t, r, "classes"); len(cls) != 1 || cls[0]["value"] != classHN.String() {
		t.Fatalf("ô chọn lớp: %s", r.Raw)
	}
	iamtest.Expect(t, mgr.Do("POST", "/penalties/"+pHCM.String()+"/confirm", map[string]any{"decision": "error"}), 404, "")
	iamtest.Expect(t, mgr.Do("POST", "/penalties/"+pHCM.String()+"/cancel", nil), 404, "")
	fields := map[string]string{"user_id": gvHCM.ID.String(), "violation_type": "Nghỉ dạy không phép", "violation_at": "2026-10-17T07:00", "amount": "50000"}
	expectMsg(t, mgr.Upload("/penalties", fields, "a.png", png), 422, "Nhân sự này không thuộc chi nhánh của bạn.")
	fields["user_id"], fields["class_id"] = l.pt.ID.String(), classHCM.String()
	expectMsg(t, mgr.Upload("/penalties", fields, "a.png", png), 422, "Lớp này không thuộc chi nhánh của bạn.")
	// Toàn hệ thống (Admin) thấy cả hai.
	if rows := iamtest.List(t, l.admin.Do("GET", "/penalties", nil), "penalties"); len(rows) != 2 {
		t.Fatalf("admin thấy mọi biên bản: %d", len(rows))
	}

	// Xếp hạng KPI: quản lý HN không thấy tư vấn HCM, không thấy số tiền (không có payroll.view).
	r = mgr.Do("GET", "/payroll/kpi-leaderboard?month=9&year=2026", nil)
	iamtest.Expect(t, r, 200, "")
	if r.Body["show_money"] != false || len(iamtest.List(t, r, "sales")) != 0 {
		t.Fatalf("xếp hạng của quản lý HN: %s", r.Raw)
	}
	for _, x := range iamtest.List(t, r, "retention") {
		if x["kpi_bonus"] != nil || x["retention_tier"] != nil || x["user_id"] == l.sale.ID.String() {
			t.Fatalf("lộ tiền / nhân sự ngoài phạm vi: %s", r.Raw)
		}
	}
	for _, b := range iamtest.List(t, r, "branches") {
		if b["value"] == l.hcm.String() {
			t.Fatalf("ô chi nhánh ngoài phạm vi: %s", r.Raw)
		}
	}
	r = mgr.Do("GET", "/payroll/kpi-leaderboard?month=9&year=2026&branch_id="+l.hcm.String(), nil)
	if len(iamtest.List(t, r, "sales")) != 0 {
		t.Fatalf("lọc chi nhánh ngoài phạm vi: %s", r.Raw)
	}
	r = l.admin.Do("GET", "/payroll/kpi-leaderboard?month=9&year=2026", nil)
	if s := iamtest.List(t, r, "sales"); r.Body["show_money"] != true || len(s) != 1 || num(s[0]["commission"]) != 150000 {
		t.Fatalf("xếp hạng của admin: %s", r.Raw)
	}

	// Đơn giá giáo viên: danh sách / lịch sử chỉ nhân sự trong phạm vi, giáo viên chi nhánh khác → 404.
	r = mgr.Do("GET", "/payroll/config/teacher-rates", nil)
	iamtest.Expect(t, r, 200, "")
	for _, x := range iamtest.List(t, r, "teachers") {
		if x["id"] == gvHCM.ID.String() {
			t.Fatalf("đơn giá lộ giáo viên chi nhánh khác: %s", r.Raw)
		}
	}
	iamtest.Expect(t, mgr.Do("GET", "/payroll/config/teacher-rates?teacher_id="+gvHCM.ID.String(), nil), 404, "")
	iamtest.Expect(t, mgr.Do("POST", "/payroll/config/teacher-rates/personal", map[string]any{"user_id": gvHCM.ID.String(), "hourly_rate": 200000,
		"rate_unit": "hour", "effective_from": "2026-10-01"}), 404, "")
	iamtest.Expect(t, l.admin.Do("GET", "/payroll/config/teacher-rates?teacher_id="+gvHCM.ID.String(), nil), 200, "")
}
