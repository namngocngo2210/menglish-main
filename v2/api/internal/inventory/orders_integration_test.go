package inventory

import (
	"context"
	"testing"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

func vn(s string) time.Time {
	t, err := time.ParseInLocation("2006-01-02 15:04", s, vnZone)
	if err != nil {
		panic(err)
	}
	return t
}

func TestDueAtPerCategory(t *testing.T) {
	c := DefaultConfig()
	day := func(s string) time.Time { return vn(s + " 00:00") }
	cases := []struct{ cat, use, want string }{
		// Đạo cụ / In ấn: 15:00 ngày hôm trước ngày sử dụng
		{"props", "2026-10-12", "2026-10-11 15:00"},
		{"printing", "2026-10-12", "2026-10-11 15:00"},
		// Ngày sử dụng mùng 1 → chiều 30/09
		{"props", "2026-10-01", "2026-09-30 15:00"},
		// GVNN / học thuật: 23:59 ngày 5 của tháng chứa ngày sử dụng
		{"foreign_teacher", "2026-10-20", "2026-10-05 23:59"},
		{"academic", "2026-11-30", "2026-11-05 23:59"},
	}
	for _, tc := range cases {
		if got := c.DueAt(tc.cat, day(tc.use)); !got.Equal(vn(tc.want)) {
			t.Errorf("%s %s: %v, muốn %s", tc.cat, tc.use, got, tc.want)
		}
	}
	c.MonthlyDeadline = 10
	if got := c.DueAt("foreign_teacher", day("2026-10-20")); !got.Equal(vn("2026-10-10 23:59")) {
		t.Errorf("ngày 10: %v", got)
	}
	// N lớn hơn số ngày của tháng → ngày cuối tháng
	c.MonthlyDeadline = 31
	if got := c.DueAt("academic", day("2026-02-10")); !got.Equal(vn("2026-02-28 23:59")) {
		t.Errorf("tháng 2: %v", got)
	}
}

func TestDeadlineState(t *testing.T) {
	c := DefaultConfig()
	now := vn("2026-10-07 09:00")
	for _, tc := range []struct {
		status string
		due    time.Time
		want   string
	}{
		{OrderPending, now.Add(72 * time.Hour), "ok"},
		{OrderPending, now.Add(5 * time.Hour), "soon"},
		{OrderProcessing, now.Add(-time.Hour), "overdue"},
		{OrderDone, now.Add(-time.Hour), ""},
		{OrderRejected, now.Add(time.Hour), ""},
	} {
		if got := c.DeadlineState(tc.status, tc.due, now); got != tc.want {
			t.Errorf("%s %v: %q, muốn %q", tc.status, tc.due, got, tc.want)
		}
	}
}

func orderPayload(branch uuid.UUID, extra map[string]any) map[string]any {
	m := map[string]any{"category": "props", "branch_id": branch, "title": "Flashcard unit 5", "use_date": "2026-10-12"}
	for k, v := range extra {
		m[k] = v
	}
	return m
}

func TestMaterialOrderFlow(t *testing.T) {
	e := setup(t)
	now := vn("2026-10-07 09:00") // Thứ Tư
	e.svc.now = func() time.Time { return now }
	cg, hd := e.branch("CG", "Cầu Giấy"), e.branch("HD", "Hà Đông")

	teacher := e.as(e.member("GV001", []uuid.UUID{cg}, iam.RoleTeacherFulltime))
	teacherHD := e.as(e.member("GV002", []uuid.UUID{hd}, iam.RoleTeacherParttime))
	cm := e.as(e.member("HV001", []uuid.UUID{cg}, iam.RoleAcademicStaff))
	cmOther := e.as(e.member("HV002", []uuid.UUID{hd}, iam.RoleAcademicStaff))
	lead := e.as(e.member("HT001", []uuid.UUID{cg}, iam.RoleAcademicLead))
	admin := e.as(e.member("MES001", nil, iam.RoleAdmin))
	student := e.as(e.member("HS001", []uuid.UUID{cg}, iam.RoleStudent))

	// Form: giáo viên chỉ chọn được chi nhánh của mình
	r := teacher.do("GET", "/material-orders/form-options", nil)
	expect(t, r, 200, "")
	if len(r.body["branches"].([]any)) != 1 || str(r.body["default_branch_id"]) != cg.String() || r.body["day_before_cutoff"] != "15:00" {
		t.Fatalf("form: %v", r.body)
	}

	// Tạo order: mã, hạn, không trễ
	r = teacher.do("POST", "/material-orders", orderPayload(cg, nil))
	expect(t, r, 201, "")
	o := r.body["order"].(map[string]any)
	propsID := str(o["id"])
	if str(o["code"]) != "OH-2026-0001" || str(o["status"]) != "pending" || o["created_late"] != false || r.body["warning"] != nil ||
		r.body["message"] != "Đã tạo order OH-2026-0001." {
		t.Fatalf("tạo order: %v", r.body)
	}
	due, _ := time.Parse(time.RFC3339, str(o["due_at"]))
	if !due.Equal(vn("2026-10-11 15:00")) {
		t.Fatalf("hạn: %v", due)
	}
	// Báo người xử lý: Học vụ chi nhánh của order + Admin (không báo Học vụ chi nhánh khác)
	if got := e.notified("material_order_new", "/material-orders/"+propsID); got != "HV001,MES001" {
		t.Fatalf("người nhận order mới: %s", got)
	}

	// Tạo trễ: vẫn nhận, cảnh báo + cờ
	r = teacher.do("POST", "/material-orders", orderPayload(cg, map[string]any{"use_date": "2026-10-08", "title": "Kịp"}))
	if r.status != 201 || r.body["order"].(map[string]any)["created_late"] != false {
		t.Fatalf("dùng ngày mai: %v", r.body)
	}
	r = teacher.do("POST", "/material-orders", orderPayload(cg, map[string]any{"use_date": "2026-10-07", "title": "Gấp"}))
	expectMsg(t, r, 201, "Đã tạo order OH-2026-0003.")
	if r.body["order"].(map[string]any)["created_late"] != true ||
		r.body["warning"] != "Order được tạo trễ: đã quá hạn xử lý (15:00 06/10/2026). Học vụ vẫn tiếp nhận nhưng có thể không kịp." {
		t.Fatalf("tạo trễ: %v", r.body)
	}

	// Kiểm dữ liệu
	expectMsg(t, teacher.do("POST", "/material-orders", orderPayload(hd, nil)), 422, "Chi nhánh không hợp lệ")
	expectMsg(t, teacher.do("POST", "/material-orders", orderPayload(cg, map[string]any{"title": " "})), 422, "Vui lòng nhập nội dung order.")
	expectMsg(t, teacher.do("POST", "/material-orders", orderPayload(cg, map[string]any{"use_date": "12/10/2026"})), 422, "Ngày sử dụng không phải là ngày hợp lệ.")
	expectMsg(t, teacher.do("POST", "/material-orders", orderPayload(cg, map[string]any{"category": "x"})), 422, "Loại order không hợp lệ.")
	expectMsg(t, teacher.do("POST", "/material-orders", orderPayload(cg, map[string]any{"quantity": 0})), 422, "Số lượng phải từ 1 đến 100.000.")

	// Chỉ người có material_order.create tạo được; học viên không vào được
	expect(t, cm.do("POST", "/material-orders", orderPayload(cg, nil)), 403, "forbidden")
	expect(t, cm.do("GET", "/material-orders/form-options", nil), 403, "forbidden")
	expect(t, student.do("GET", "/material-orders", nil), 403, "forbidden")

	foreignID := str(teacher.do("POST", "/material-orders", orderPayload(cg, map[string]any{"category": "foreign_teacher", "use_date": "2026-10-20"})).body["order"].(map[string]any)["id"])
	academicID := str(teacher.do("POST", "/material-orders", orderPayload(cg, map[string]any{"category": "academic", "use_date": "2026-10-20"})).body["order"].(map[string]any)["id"])
	hdID := str(teacherHD.do("POST", "/material-orders", orderPayload(hd, map[string]any{"title": "Của Hà Đông"})).body["order"].(map[string]any)["id"])

	// Phạm vi xem: giáo viên chỉ order của mình; Học vụ theo chi nhánh; Trưởng Học thuật mọi order
	if r := teacher.do("GET", "/material-orders", nil); num(r.body["total"]) != 5 || r.body["can_create"] != true || len(r.body["branches"].([]any)) != 0 {
		t.Fatalf("giáo viên: %v", r.body)
	}
	if r := teacherHD.do("GET", "/material-orders", nil); num(r.body["total"]) != 1 {
		t.Fatalf("giáo viên HĐ: %v", r.body["total"])
	}
	expect(t, teacherHD.do("GET", "/material-orders/"+propsID, nil), 403, "forbidden")
	if r := cm.do("GET", "/material-orders", nil); num(r.body["total"]) != 5 || r.body["can_create"] != false || len(r.body["branches"].([]any)) != 1 {
		t.Fatalf("Học vụ CG: %v", r.body)
	}
	expect(t, cm.do("GET", "/material-orders/"+hdID, nil), 403, "forbidden")
	if r := lead.do("GET", "/material-orders", nil); num(r.body["total"]) != 6 || len(r.body["branches"].([]any)) != 2 {
		t.Fatalf("Trưởng Học thuật: %v", r.body["total"])
	}
	if r := lead.do("GET", "/material-orders?category=academic", nil); num(r.body["total"]) != 1 {
		t.Fatalf("lọc loại: %v", r.body["total"])
	}
	if r := lead.do("GET", "/material-orders?branch_id="+hd.String(), nil); num(r.body["total"]) != 1 {
		t.Fatalf("lọc chi nhánh: %v", r.body["total"])
	}
	if r := lead.do("GET", "/material-orders?search=OH-2026-0003", nil); num(r.body["total"]) != 1 {
		t.Fatalf("tìm mã: %v", r.body["total"])
	}
	expect(t, lead.do("GET", "/material-orders?status=lost", nil), 422, "invalid")
	// Sắp xếp: còn mở theo hạn sớm nhất (cùng hạn: tạo sau đứng trước)
	if orders := list(lead.do("GET", "/material-orders", nil).body["orders"]); str(orders[0]["code"]) != "OH-2026-0005" ||
		str(orders[1]["code"]) != "OH-2026-0004" || str(orders[2]["code"]) != "OH-2026-0003" || str(orders[0]["deadline_state"]) != "overdue" {
		t.Fatalf("thứ tự: %v", orders)
	}

	// Nút xử lý chỉ hiện với đúng người
	r = cm.do("GET", "/material-orders/"+propsID, nil)
	if acts := r.body["actions"].(map[string]any); acts["claim"] != true || acts["complete"] != true || acts["reject"] != true {
		t.Fatalf("Học vụ: %v", r.body)
	}
	r = lead.do("GET", "/material-orders/"+propsID, nil)
	if acts := r.body["actions"].(map[string]any); acts["claim"] != false || acts["complete"] != false || acts["reject"] != false {
		t.Fatalf("Trưởng Học thuật với order đạo cụ: %v", r.body)
	}

	// Quyền xử lý theo loại + chi nhánh
	expectMsg(t, cm.do("POST", "/material-orders/"+propsID+"/claim", nil), 200, "Đã nhận xử lý order.")
	expect(t, cm.do("POST", "/material-orders/"+propsID+"/claim", nil), 409, "invalid_status")
	expect(t, cm.do("POST", "/material-orders/"+foreignID+"/claim", nil), 200, "")
	expect(t, cm.do("POST", "/material-orders/"+academicID+"/claim", nil), 403, "forbidden")
	expect(t, cmOther.do("POST", "/material-orders/"+propsID+"/complete", map[string]any{}), 403, "forbidden")
	expect(t, lead.do("POST", "/material-orders/"+academicID+"/claim", nil), 200, "")
	expect(t, lead.do("POST", "/material-orders/"+propsID+"/complete", map[string]any{}), 403, "forbidden")
	expect(t, teacher.do("POST", "/material-orders/"+propsID+"/complete", map[string]any{}), 403, "forbidden")
	expectMsg(t, admin.do("POST", "/material-orders/"+academicID+"/complete", map[string]any{"processor_note": "Đã in"}), 200, "Đã hoàn thành order (xử lý trễ hạn).")
	expect(t, admin.do("POST", "/material-orders/"+academicID+"/complete", map[string]any{}), 409, "invalid_status")

	// Từ chối bắt buộc có lý do
	expectMsg(t, cm.do("POST", "/material-orders/"+foreignID+"/reject", map[string]any{}), 422, "Vui lòng nhập lý do từ chối.")
	expectMsg(t, cm.do("POST", "/material-orders/"+foreignID+"/reject", map[string]any{"reject_reason": "Hết đạo cụ"}), 200, "Đã từ chối order.")
	r = teacher.do("GET", "/material-orders/"+foreignID, nil)
	if od := r.body["order"].(map[string]any); od["status"] != "rejected" || od["reject_reason"] != "Hết đạo cụ" || od["processed_late"] != true ||
		od["processed_by"] != "Người HV001" || od["deadline_state"] != "" {
		t.Fatalf("order bị từ chối: %v", od)
	}

	// Quá hạn: job chuyển order Chờ / Đang xử lý đã qua hạn; chạy lại không đổi gì
	ctx := context.Background()
	if n, err := e.svc.MarkOverdue(ctx); err != nil || n != 1 { // order "Gấp" (hạn 06/10)
		t.Fatalf("quá hạn lần 1: %d %v", n, err)
	}
	now = vn("2026-10-11 15:01")
	if n, err := e.svc.MarkOverdue(ctx); err != nil || n != 3 { // đạo cụ (đang xử lý), "Kịp" (hạn 07/10), Hà Đông
		t.Fatalf("quá hạn lần 2: %d %v", n, err)
	}
	if n, _ := e.svc.MarkOverdue(ctx); n != 0 {
		t.Fatalf("chạy lại phải không đổi gì, nhận %d", n)
	}
	if st := cm.do("GET", "/material-orders/"+propsID, nil).body["order"].(map[string]any)["status"]; st != "overdue" {
		t.Fatalf("trạng thái: %v", st)
	}
	if st := lead.do("GET", "/material-orders/"+academicID, nil).body["order"].(map[string]any)["status"]; st != "done" {
		t.Fatalf("order đã xong giữ nguyên: %v", st)
	}

	// Order quá hạn vẫn xử lý được, ghi nhận xử lý trễ
	expect(t, cm.do("POST", "/material-orders/"+propsID+"/claim", nil), 200, "")
	expectMsg(t, cm.do("POST", "/material-orders/"+propsID+"/complete", map[string]any{"processor_note": "Đã chuẩn bị"}), 200, "Đã hoàn thành order (xử lý trễ hạn).")
	od := teacher.do("GET", "/material-orders/"+propsID, nil).body["order"].(map[string]any)
	if od["status"] != "done" || od["processed_late"] != true || od["processor_note"] != "Đã chuẩn bị" || od["processed_at"] == nil {
		t.Fatalf("hoàn thành trễ: %v", od)
	}

	// Số thứ tự mã tăng tiếp, sang năm mới bắt đầu lại
	now = vn("2027-01-02 09:00")
	if code := str(teacher.do("POST", "/material-orders", orderPayload(cg, map[string]any{"use_date": "2027-01-10"})).body["order"].(map[string]any)["code"]); code != "OH-2027-0001" {
		t.Fatalf("mã năm mới: %s", code)
	}
}
