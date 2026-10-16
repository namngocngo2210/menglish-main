package iam

import (
	"context"
	"strings"
	"testing"
	"time"

	"github.com/google/uuid"
)

func TestContractStatus(t *testing.T) {
	today := time.Date(2026, 10, 16, 9, 0, 0, 0, vnZone)
	day := func(y int, m time.Month, d int) *time.Time { v := time.Date(y, m, d, 0, 0, 0, 0, time.UTC); return &v }
	ft := "Toàn thời gian"
	cases := []struct {
		typ       *string
		end       *time.Time
		status    string
		remaining string
	}{
		{nil, nil, "Chưa cập nhật", ""},
		{&ft, nil, "Đang hiệu lực", ""},
		{nil, day(2026, 10, 15), "Đã hết hạn", "Đã hết hạn"},
		{&ft, day(2026, 10, 16), "Sắp hết hạn", "Còn 0 ngày"},
		{&ft, day(2026, 11, 15), "Sắp hết hạn", "Còn 30 ngày"},
		{&ft, day(2026, 11, 16), "Đang hiệu lực", "Còn hiệu lực"},
	}
	for _, c := range cases {
		s, r := ContractStatus(c.typ, c.end, today, 30)
		got := ""
		if r != nil {
			got = r.Label
		}
		if s.Label != c.status || got != c.remaining {
			t.Errorf("end=%v: %s / %s, muốn %s / %s", c.end, s.Label, got, c.status, c.remaining)
		}
	}
}

func TestStaffProfile(t *testing.T) {
	e := setup(t)
	a, b := e.branch(t, "CN-A"), e.branch(t, "CN-B")
	admin := e.as(t, e.member(t, "MES001", true, nil, RoleAdmin))
	gv := e.member(t, "GV001", false, []uuid.UUID{a}, RoleTeacherFulltime)
	lead := e.member(t, "HT001", true, []uuid.UUID{a}, RoleAcademicLead)
	other := e.member(t, "GV002", false, []uuid.UUID{b}, RoleTeacherFulltime)
	path := "/users/" + gv.id.String() + "/profile"

	r := admin.do("GET", path, nil)
	expect(t, r, 200, "")
	if r.body["can_view_sensitive"] != true || r.body["base_salary"] != float64(0) ||
		r.body["contract_status"].(map[string]any)["label"] != "Chưa cập nhật" || r.body["updated_at"] != nil {
		t.Fatalf("hồ sơ trống: %v", r.body)
	}

	end := e.svc.now().In(vnZone).AddDate(0, 0, 10).Format(time.DateOnly)
	in := map[string]any{
		"id_card_number": "001200012345", "hometown": "Nam Định", "current_address": "Ba Đình, Hà Nội",
		"emergency_contact": "Mẹ 0912345678", "graduation_school": "ĐH Sư phạm", "certificates": "IELTS 8.0",
		"teaching_level": "B2–C1", "contract_type": "Toàn thời gian", "contract_start_date": "2026-01-01",
		"contract_end_date": end, "base_salary": 9000000, "hourly_rate": 250000, "academic_teaching": true,
	}
	bad := func(k string, v any, msg string) {
		t.Helper()
		body := map[string]any{}
		for kk, vv := range in {
			body[kk] = vv
		}
		body[k] = v
		r := admin.do("PUT", path, body)
		expect(t, r, 422, "invalid")
		if r.body["message"] != msg {
			t.Fatalf("%s=%v: %v", k, v, r.body["message"])
		}
	}
	bad("contract_type", strings.Repeat("x", 51), "Loại hợp đồng tối đa 50 ký tự.")
	bad("id_card_number", strings.Repeat("1", 31), "Số CCCD tối đa 30 ký tự.")
	bad("contract_start_date", "01/01/2026", "Ngày bắt đầu hợp đồng không hợp lệ.")
	bad("base_salary", -1, "Lương cơ bản không được âm.")

	r = admin.do("PUT", path, in)
	expect(t, r, 200, "")
	if r.body["id_card_number"] != "001200012345" || r.body["hourly_rate"] != float64(250000) ||
		r.body["contract_end_date"] != end || r.body["academic_teaching"] != false ||
		r.body["contract_status"].(map[string]any)["label"] != "Sắp hết hạn" ||
		r.body["contract_remaining"].(map[string]any)["label"] != "Còn 10 ngày" {
		t.Fatalf("lưu hồ sơ (giáo viên không có kiêm nhiệm giảng dạy): %v", r.body)
	}
	var n int
	if err := e.svc.pool.QueryRow(context.Background(),
		"SELECT count(*) FROM core.audit_log WHERE subject_id = $1 AND description = 'Cập nhật hồ sơ nhân sự'", gv.id.String()).Scan(&n); err != nil || n != 1 {
		t.Fatalf("nhật ký vận hành: %d %v", n, err)
	}

	// Học thuật mới có "Kiêm nhiệm giảng dạy"
	r = admin.do("PUT", "/users/"+lead.id.String()+"/profile", map[string]any{"academic_teaching": true})
	expect(t, r, 200, "")
	if r.body["academic_teaching"] != true || r.body["academic_lead"] != true {
		t.Fatalf("học thuật kiêm giảng dạy: %v", r.body)
	}

	// Học thuật (sửa được tài khoản giáo viên, không xem được mọi phiếu lương): thấy hồ sơ nhưng không thấy / không sửa
	// được CCCD, lương
	manager := e.as(t, lead)
	r = manager.do("GET", path, nil)
	expect(t, r, 200, "")
	if r.body["can_view_sensitive"] != false || r.body["id_card_number"] != nil || r.body["base_salary"] != nil ||
		r.body["hometown"] != nil || r.body["graduation_school"] != "ĐH Sư phạm" {
		t.Fatalf("quản lý xem hồ sơ: %v", r.body)
	}
	r = manager.do("PUT", path, map[string]any{"id_card_number": "999", "base_salary": 1, "graduation_school": "ĐH Ngoại ngữ",
		"contract_type": "Thử việc"})
	expect(t, r, 200, "")
	r = admin.do("GET", path, nil)
	if r.body["id_card_number"] != "001200012345" || r.body["base_salary"] != float64(9000000) ||
		r.body["graduation_school"] != "ĐH Ngoại ngữ" || r.body["contract_type"] != "Thử việc" || r.body["contract_end_date"] != nil {
		t.Fatalf("quản lý chỉ sửa phần không nhạy cảm: %v", r.body)
	}

	// Có payroll.view và phạm vi phiếu lương "tất cả" thì xem được
	payroll := e.member(t, "QL002", true, []uuid.UUID{a}, RoleManager)
	for _, p := range []string{"payroll.view", "payroll.scope_all"} {
		if _, err := e.svc.pool.Exec(context.Background(),
			"INSERT INTO iam.permission_overrides (user_id, permission, allow) VALUES ($1, $2, true)", payroll.id, p); err != nil {
			t.Fatal(err)
		}
	}
	pc := e.as(t, payroll)
	r = pc.do("GET", path, nil)
	expect(t, r, 200, "")
	if r.body["can_view_sensitive"] != true || r.body["base_salary"] != float64(9000000) {
		t.Fatalf("người xem được mọi phiếu lương: %v", r.body)
	}
	// Vẫn theo phạm vi màn Người dùng: quản lý cơ sở chỉ xem nhân sự chi nhánh mình
	expect(t, pc.do("GET", "/users/"+other.id.String()+"/profile", nil), 403, "outside_branch")

	// Không có quyền xem người dùng
	expect(t, e.as(t, e.member(t, "TV001", false, []uuid.UUID{a}, RoleSalesConsultant)).do("GET", path, nil), 403, "forbidden")
}
