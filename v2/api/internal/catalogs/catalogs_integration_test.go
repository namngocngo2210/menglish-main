package catalogs

import (
	"context"
	"strings"
	"testing"

	"github.com/google/uuid"
)

func TestPermissionDenied(t *testing.T) {
	e := setup(t)
	b := e.branch("HN01")
	teacher := e.as([]uuid.UUID{b}, "teacher_fulltime")
	for _, c := range []struct{ method, path string }{
		{"GET", "/courses"}, {"POST", "/courses"}, {"GET", "/course-levels"}, {"GET", "/rooms"},
		{"GET", "/teaching-shifts"}, {"GET", "/holidays"}, {"GET", "/system-categories"},
		{"GET", "/system-config/sla"}, {"GET", "/system-config/bank-accounts"}, {"GET", "/system-config/debt-reminders"},
	} {
		var body any
		if c.method != "GET" {
			body = map[string]any{}
		}
		expect(t, teacher.do(c.method, c.path, body), 403, "forbidden")
	}
	// Quản lý cơ sở xem khóa học nhưng không thêm được; xem khung giờ nhưng không sửa được.
	manager := e.as([]uuid.UUID{b}, "manager")
	expect(t, manager.do("GET", "/courses", nil), 200, "")
	expect(t, manager.do("POST", "/courses", map[string]any{"name": "X"}), 403, "forbidden")
	expect(t, manager.do("GET", "/teaching-shifts", nil), 200, "")
	expect(t, manager.do("POST", "/teaching-shifts", map[string]any{"day_type": "weekday"}), 403, "forbidden")
	expect(t, manager.do("POST", "/rooms/types", map[string]any{"name": "Lab"}), 403, "forbidden")
}

func TestCoursesAndLevels(t *testing.T) {
	e := setup(t)
	admin := e.as(nil, "admin")

	// Trình độ
	expectMsg(t, admin.do("POST", "/course-levels", map[string]any{"code": "A1", "target": "x", "lessons_count": 24}), 422, "Vui lòng nhập tên trình độ.")
	expectMsg(t, admin.do("POST", "/course-levels", map[string]any{"code": "A1", "name": "Starter", "target": "Giao tiếp cơ bản", "lessons_count": 0}), 422, "Số buổi học phải từ 1 trở lên.")
	r := admin.do("POST", "/course-levels", map[string]any{
		"code": "A1", "name": "Starter", "target": "Giao tiếp cơ bản", "lessons_count": 24, "level_group": " kids ",
		"grade_levels": []string{"lop_2", "mau_giao"},
	})
	expectMsg(t, r, 201, "Đã thêm khung trình độ Starter (A1) thành công!")
	a1 := r.body["id"].(string)
	expectMsg(t, admin.do("POST", "/course-levels", map[string]any{"code": "a1", "name": "Trùng", "target": "x", "lessons_count": 1}), 422,
		"Mã trình độ đã tồn tại trên hệ thống, vui lòng chọn mã khác.")
	expectMsg(t, admin.do("POST", "/course-levels", map[string]any{"code": "B1", "name": "X", "target": "x", "lessons_count": 1, "grade_levels": []string{"lop_12"}}), 422,
		"Cấp độ test đầu vào không hợp lệ.")
	r = admin.do("POST", "/course-levels", map[string]any{"code": "B1", "name": "Pre-Inter", "target": "B1", "lessons_count": 36})
	expect(t, r, 201, "")
	b1 := r.body["id"].(string)

	r = admin.do("GET", "/course-levels", nil)
	expect(t, r, 200, "")
	lv := items(t, r, "items")
	if len(lv) != 2 || lv[0]["code"] != "A1" || lv[0]["level_group"] != "KIDS" || r.body["can_reorder"] != true {
		t.Fatalf("danh sách trình độ: %v", r.body)
	}
	if g := lv[0]["grade_levels"].([]any); len(g) != 2 || g[0] != "mau_giao" || lv[0]["grade_labels"].([]any)[1] != "Lớp 2" {
		t.Fatalf("cấp độ test phải theo thứ tự danh sách: %v", lv[0])
	}
	// Kéo thả: B1 lên trước A1
	expect(t, admin.do("POST", "/course-levels/reorder", map[string]any{"ids": []string{b1, a1}}), 200, "")
	lv = items(t, admin.do("GET", "/course-levels", nil), "items")
	if lv[0]["code"] != "B1" {
		t.Fatalf("sau khi sắp xếp B1 phải đứng đầu: %v", lv)
	}
	if r := admin.do("GET", "/course-levels?search=pre", nil); r.body["can_reorder"] != false || len(items(t, r, "items")) != 1 {
		t.Fatalf("lọc trình độ: %v", r.body)
	}

	// Khóa học
	expectMsg(t, admin.do("POST", "/courses", map[string]any{"tuition_fee": 1000, "total_lessons": 24}), 422, "Vui lòng nhập tên khóa học.")
	expectMsg(t, admin.do("POST", "/courses", map[string]any{"name": "IELTS", "total_lessons": 24}), 422, "Vui lòng nhập giá học phí.")
	expectMsg(t, admin.do("POST", "/courses", map[string]any{"name": "IELTS", "tuition_fee": 1000}), 422, "Vui lòng nhập số buổi học.")
	expectMsg(t, admin.do("POST", "/courses", map[string]any{"name": "IELTS", "tuition_fee": 1, "total_lessons": 2, "course_level_id": uuid.NewString()}), 422,
		"Khung trình độ đã chọn không tồn tại trong hệ thống.")
	r = admin.do("POST", "/courses", map[string]any{"name": "Starter Kids", "tuition_fee": 12500000, "total_lessons": 24, "course_level_id": a1})
	expectMsg(t, r, 201, "Đã thêm khóa học 'Starter Kids' (CS0001) với học phí 12.500.000 đ thành công!")
	c1 := r.body["id"].(string)
	r = admin.do("POST", "/courses", map[string]any{"name": "Pre-Inter", "tuition_fee": 9000000, "total_lessons": 30, "is_active": false})
	if r.body["code"] != "CS0002" {
		t.Fatalf("mã khóa thứ hai: %v", r.body)
	}
	c2 := r.body["id"].(string)

	r = admin.do("GET", "/courses?status=active", nil)
	cs := items(t, r, "items")
	stats := r.body["stats"].(map[string]any)
	if len(cs) != 1 || cs[0]["code"] != "CS0001" || cs[0]["level"].(map[string]any)["code"] != "A1" ||
		stats["total_courses"] != float64(2) || stats["active_courses"] != float64(1) || stats["max_tuition"] != float64(12500000) {
		t.Fatalf("danh sách khóa học: %v", r.body)
	}
	if lvls := r.body["levels"].([]any); len(lvls) != 2 || !strings.Contains(lvls[0].(map[string]any)["label"].(string), "(B1)") {
		t.Fatalf("ô chọn trình độ: %v", r.body["levels"])
	}
	expectMsg(t, admin.do("PUT", "/courses/"+c1, map[string]any{"name": "Starter Kids", "tuition_fee": 13000000, "total_lessons": 24, "course_level_id": a1}), 200,
		"Cập nhật khóa học 'Starter Kids' thành công! (Đã cập nhật giá từ 12.500.000 đ thành 13.000.000 đ)")
	expectMsg(t, admin.do("POST", "/courses/"+c2+"/toggle", nil), 200, "Đã Kích hoạt mở bán cho khóa học 'Pre-Inter'!")

	// Trình độ đang gắn khóa học không xóa được
	expectMsg(t, admin.do("DELETE", "/course-levels/"+a1, nil), 409,
		"Không thể xóa trình độ Starter: đang gắn với 1 khóa học và 0 lớp học. Hãy chuyển sang \"Ngừng hoạt động\".")
	expectMsg(t, admin.do("POST", "/course-levels/"+a1+"/toggle", nil), 200, "Đã chuyển trình độ Starter sang Ngừng hoạt động.")
	expectMsg(t, admin.do("DELETE", "/courses/"+c1, nil), 200, "Đã xóa khóa học 'Starter Kids' khỏi hệ thống!")
	expect(t, admin.do("DELETE", "/course-levels/"+a1, nil), 200, "")
	expect(t, admin.do("PUT", "/courses/"+c1, map[string]any{"name": "X", "tuition_fee": 1, "total_lessons": 1}), 404, "not_found")
	// Mã không dùng lại sau khi xóa
	if r := admin.do("POST", "/courses", map[string]any{"name": "Mới", "tuition_fee": 1, "total_lessons": 1}); r.body["code"] != "CS0003" {
		t.Fatalf("mã khóa sau khi xóa: %v", r.body)
	}
}

func TestRoomsBranchScope(t *testing.T) {
	e := setup(t)
	hn, hcm := e.branch("HN01"), e.branch("HCM01")
	admin := e.as(nil, "admin")
	staff := e.as([]uuid.UUID{hn}, "academic_staff") // room.scope_branch
	lead := e.as([]uuid.UUID{hn}, "academic_lead")   // room.scope_all
	nobranch := e.as(nil, "academic_staff")

	r := admin.do("POST", "/rooms/types", map[string]any{"name": "Phòng tiêu chuẩn"})
	expectMsg(t, r, 201, "Đã thêm loại phòng Phòng tiêu chuẩn.")
	std := r.body["id"].(string)
	expectMsg(t, admin.do("POST", "/rooms/types", map[string]any{"name": "phòng tiêu chuẩn"}), 422, "Loại phòng này đã có trong danh mục.")
	r = admin.do("POST", "/rooms/types", map[string]any{"name": "Lab cũ"})
	old := r.body["id"].(string)
	expectMsg(t, admin.do("POST", "/rooms/types/"+old+"/toggle", nil), 200, "Đã ngừng dùng loại phòng Lab cũ.")

	expectMsg(t, staff.do("POST", "/rooms", map[string]any{"branch_id": hcm, "name": "P101", "room_type_id": std}), 422,
		"Bạn chỉ được thêm phòng cho chi nhánh của mình.")
	expectMsg(t, staff.do("POST", "/rooms", map[string]any{"branch_id": hn, "name": "P101", "room_type_id": old}), 422,
		"Loại phòng đã ngừng dùng hoặc không tồn tại.")
	expectMsg(t, staff.do("POST", "/rooms", map[string]any{"branch_id": hn, "name": "P101", "room_type_id": std, "capacity": 0}), 422,
		"Sức chứa phải là số nguyên lớn hơn 0")
	r = staff.do("POST", "/rooms", map[string]any{"branch_id": hn, "name": "P101", "room_type_id": std, "capacity": 20})
	expectMsg(t, r, 201, "Đã lưu phòng học P101.")
	hnRoom := r.body["id"].(string)
	expectMsg(t, staff.do("POST", "/rooms", map[string]any{"branch_id": hn, "name": "p101", "room_type_id": std}), 422, "Tên phòng đã tồn tại trong chi nhánh này")
	r = admin.do("POST", "/rooms", map[string]any{"branch_id": hcm, "name": "P101", "room_type_id": std})
	expect(t, r, 201, "")
	hcmRoom := r.body["id"].(string)

	// Phạm vi chi nhánh
	r = staff.do("GET", "/rooms", nil)
	rooms := r.body["rooms"].(map[string]any)
	if rooms["total"] != float64(1) || r.body["locked_branch"] == nil || r.body["blocked"] != false {
		t.Fatalf("Học vụ chỉ thấy phòng chi nhánh mình: %v", r.body)
	}
	if r := lead.do("GET", "/rooms", nil); r.body["rooms"].(map[string]any)["total"] != float64(2) || r.body["locked_branch"] != nil {
		t.Fatalf("Học thuật thấy mọi chi nhánh: %v", r.body)
	}
	expect(t, staff.do("PUT", "/rooms/"+hcmRoom, map[string]any{"name": "X", "room_type_id": std}), 403, "outside_branch")
	expect(t, staff.do("GET", "/rooms/"+hcmRoom, nil), 403, "outside_branch")
	expect(t, staff.do("DELETE", "/rooms/"+hnRoom, nil), 403, "forbidden") // room.delete chỉ Admin

	// Phòng giữ loại đã ngừng dùng khi sửa; đổi sang loại ngừng dùng khác thì không được
	e.exec("UPDATE core.rooms SET room_type_id = $1 WHERE id = $2", old, hnRoom)
	expectMsg(t, staff.do("PUT", "/rooms/"+hnRoom, map[string]any{"name": "P102", "room_type_id": old, "capacity": 18}), 200, "Đã cập nhật phòng học P102.")
	expectMsg(t, admin.do("DELETE", "/rooms/types/"+old, nil), 409, "Loại phòng đang được sử dụng — chỉ có thể Ngừng dùng")

	r = nobranch.do("GET", "/rooms", nil)
	if r.body["blocked"] != true || r.body["rooms"] != nil {
		t.Fatalf("chưa gán chi nhánh phải bị chặn: %v", r.body)
	}
	expect(t, nobranch.do("POST", "/rooms", map[string]any{"branch_id": hn, "name": "P9", "room_type_id": std}), 403, "no_branch")

	expectMsg(t, admin.do("DELETE", "/rooms/"+hnRoom, nil), 200, "Đã xóa P102.")
	expect(t, admin.do("DELETE", "/rooms/types/"+old, nil), 200, "")
	types := items(t, admin.do("GET", "/rooms/types", nil), "room_types")
	if len(types) != 1 || types[0]["in_use"] != true {
		t.Fatalf("danh mục loại phòng: %v", types)
	}
}

func TestTeachingShifts(t *testing.T) {
	e := setup(t)
	admin := e.as(nil, "admin")
	expectMsg(t, admin.do("POST", "/teaching-shifts", map[string]any{"day_type": "weekday", "name": "Ca 1", "start_time": "18:00", "end_time": "19:00"}), 422,
		"Mỗi ca dạy 90 phút: giờ kết thúc phải sau giờ bắt đầu đúng 90 phút.")
	expectMsg(t, admin.do("POST", "/teaching-shifts", map[string]any{"day_type": "weekday", "name": "Ca 1", "start_time": "18:00", "end_time": "19:30", "alt_start_time": "18:10"}), 422,
		"Vui lòng nhập giờ lệch kết thúc khi có giờ lệch bắt đầu.")
	expectMsg(t, admin.do("POST", "/teaching-shifts", map[string]any{"day_type": "monday", "name": "Ca 1", "start_time": "18:00", "end_time": "19:30"}), 422,
		"Loại ngày không hợp lệ.")
	r := admin.do("POST", "/teaching-shifts", map[string]any{"day_type": "weekday", "name": "Ca 2", "start_time": "19:30", "end_time": "21:00"})
	expectMsg(t, r, 201, "Đã thêm khung Thứ 2 – Thứ 6 · Ca 2 19:30–21:00.")
	ca2 := r.body["id"].(string)
	expect(t, admin.do("POST", "/teaching-shifts", map[string]any{"day_type": "weekday", "name": "Ca 1", "start_time": "18:00", "end_time": "19:30",
		"alt_start_time": "18:10", "alt_end_time": "19:40", "note": "Một số lớp lệch 10 phút"}), 201, "")
	expect(t, admin.do("POST", "/teaching-shifts", map[string]any{"day_type": "sunday", "name": "Ca tối", "start_time": "18:30", "end_time": "20:00"}), 201, "")

	r = admin.do("GET", "/teaching-shifts", nil)
	shifts := items(t, r, "shifts")
	if len(shifts) != 3 || shifts[0]["name"] != "Ca 1" || shifts[0]["alt"] != "18:10–19:40" || shifts[2]["day_type"] != "sunday" {
		t.Fatalf("thứ tự khung giờ: %v", shifts)
	}
	if r.body["weekday_rule"] != "Ca 1 (18:00–19:30 hoặc 18:10–19:40) và Ca 2 (19:30–21:00)" {
		t.Fatalf("quy tắc Thứ 2–6: %v", r.body["weekday_rule"])
	}
	expectMsg(t, admin.do("POST", "/teaching-shifts/"+ca2+"/toggle", nil), 200, "Đã ngừng dùng khung Thứ 2 – Thứ 6 · Ca 2 19:30–21:00.")
	if r := admin.do("GET", "/teaching-shifts", nil); r.body["weekday_rule"] != "Ca 1 (18:00–19:30 hoặc 18:10–19:40)" {
		t.Fatalf("khung ngừng dùng không tính: %v", r.body["weekday_rule"])
	}
	expectMsg(t, admin.do("PUT", "/teaching-shifts/"+ca2, map[string]any{"day_type": "saturday", "name": "Ca chiều", "start_time": "15:00", "end_time": "16:30"}), 200,
		"Đã lưu khung Thứ 7 · Ca chiều 15:00–16:30. Lịch đã xếp không đổi — chỉ áp khi xếp / sửa TKB.")
	expect(t, admin.do("DELETE", "/teaching-shifts/"+ca2, nil), 200, "")
	expect(t, admin.do("DELETE", "/teaching-shifts/"+ca2, nil), 404, "not_found")
}

func TestHolidays(t *testing.T) {
	e := setup(t)
	hn := e.branch("HN01")
	admin := e.as(nil, "admin")
	expectMsg(t, admin.do("POST", "/holidays", map[string]any{"name": "Tết", "start_date": "2027-02-10", "end_date": "2027-02-01"}), 422,
		"Ngày kết thúc phải sau hoặc bằng ngày bắt đầu.")
	expectMsg(t, admin.do("POST", "/holidays", map[string]any{"start_date": "2027-02-10", "end_date": "2027-02-11"}), 422, "Vui lòng nhập tên ngày nghỉ.")
	r := admin.do("POST", "/holidays", map[string]any{"name": "Tết Nguyên Đán 2027", "start_date": "2027-02-05", "end_date": "2027-02-11"})
	expectMsg(t, r, 201, "Đã thêm ngày nghỉ.")
	tet := r.body["id"].(string)
	r = admin.do("POST", "/holidays", map[string]any{"name": "Giỗ Tổ", "start_date": "2027-04-16", "end_date": "2027-04-16", "branch_ids": []string{hn.String()}})
	expect(t, r, 201, "")
	gioTo := r.body["id"].(string)
	expectMsg(t, admin.do("POST", "/holidays", map[string]any{"code": "hol-2027-001", "name": "Trùng", "start_date": "2027-05-01", "end_date": "2027-05-01"}), 422,
		"Mã ngày nghỉ đã tồn tại trên hệ thống, vui lòng chọn mã khác.")

	list := items(t, admin.do("GET", "/holidays", nil), "items")
	if len(list) != 2 || list[0]["code"] != "HOL-2027-002" || list[0]["is_system_wide"] != false || list[0]["branches"].([]any)[0] != "Chi nhánh HN01" ||
		list[1]["code"] != "HOL-2027-001" || list[1]["is_system_wide"] != true {
		t.Fatalf("danh sách ngày nghỉ: %v", list)
	}
	// Bỏ chọn chi nhánh = áp dụng toàn hệ thống; giữ mã cũ khi để trống
	expectMsg(t, admin.do("PUT", "/holidays/"+gioTo, map[string]any{"name": "Giỗ Tổ Hùng Vương", "start_date": "2027-04-16", "end_date": "2027-04-16"}), 200, "Đã cập nhật ngày nghỉ.")
	r = admin.do("GET", "/holidays/"+gioTo, nil)
	if r.body["code"] != "HOL-2027-002" || r.body["is_system_wide"] != true {
		t.Fatalf("sửa ngày nghỉ: %v", r.body)
	}
	if r := admin.do("GET", "/holidays?search=tết", nil); len(items(t, r, "items")) != 1 {
		t.Fatalf("tìm ngày nghỉ: %v", r.body)
	}
	expectMsg(t, admin.do("DELETE", "/holidays/"+tet, nil), 200, "Đã xóa ngày nghỉ.")
	expect(t, admin.do("GET", "/holidays/"+tet, nil), 404, "not_found")

	// Nhật ký vận hành ghi cùng giao dịch, mô tả như Laravel
	var descs []string
	rows, err := e.pg.Query(context.Background(), `SELECT description FROM core.audit_log
		WHERE subject_type = 'holiday' AND subject_id = $1 AND module = 'Cấu hình hệ thống' ORDER BY id`, tet)
	if err != nil {
		t.Fatal(err)
	}
	for rows.Next() {
		var d string
		_ = rows.Scan(&d)
		descs = append(descs, d)
	}
	rows.Close()
	if strings.Join(descs, "|") != "Tạo ngày nghỉ mới|Xóa ngày nghỉ" {
		t.Fatalf("nhật ký ngày nghỉ: %v", descs)
	}
}

func TestSystemCategories(t *testing.T) {
	e := setup(t)
	admin := e.as(nil, "admin")
	r := admin.do("GET", "/system-categories?type=bogus", nil)
	if r.body["type"] != "lead_source" || r.body["suggested_code"] != "SRC_01" || r.body["next_order"] != float64(1) {
		t.Fatalf("nhóm mặc định: %v", r.body)
	}
	expectMsg(t, admin.do("POST", "/system-categories", map[string]any{"type": "lead_source", "name": "Facebook"}), 422, "Vui lòng nhập mã danh mục.")
	r = admin.do("POST", "/system-categories", map[string]any{"type": "lead_source", "code": "SRC_07", "name": "Facebook Ads"})
	expectMsg(t, r, 201, "Đã thêm danh mục \"Facebook Ads\".")
	id := r.body["category"].(map[string]any)["id"].(string)
	expectMsg(t, admin.do("POST", "/system-categories", map[string]any{"type": "lead_source", "code": "src_07", "name": "Trùng"}), 422,
		"Mã danh mục đã tồn tại trong nhóm này, vui lòng chọn mã khác.")
	expect(t, admin.do("POST", "/system-categories", map[string]any{"type": "lost_reason", "code": "SRC_07", "name": "Nhóm khác"}), 201, "")
	r = admin.do("GET", "/system-categories?type=lead_source", nil)
	if r.body["suggested_code"] != "SRC_08" || r.body["next_order"] != float64(2) || len(items(t, r, "items")) != 1 {
		t.Fatalf("gợi ý mã: %v", r.body)
	}
	expectMsg(t, admin.do("POST", "/system-categories/"+id+"/deactivate", nil), 200, "Đã ngừng sử dụng \"Facebook Ads\".")
	expectMsg(t, admin.do("POST", "/system-categories/"+id+"/reactivate", nil), 200, "Đã kích hoạt lại danh mục \"Facebook Ads\".")
	expectMsg(t, admin.do("POST", "/system-categories/"+id+"/reactivate", nil), 200, "Danh mục đang được sử dụng.")
	r = admin.do("PUT", "/system-categories/"+id, map[string]any{"type": "lead_source", "code": "SRC_07", "name": "Facebook", "sort_order": 5})
	if c := r.body["category"].(map[string]any); c["name"] != "Facebook" || c["sort_order"] != float64(5) || c["is_active"] != true {
		t.Fatalf("sửa danh mục: %v", r.body)
	}
}

func TestSLAConfig(t *testing.T) {
	e := setup(t)
	admin := e.as(nil, "admin")
	r := admin.do("GET", "/system-config/sla", nil)
	rules := items(t, r, "rules")
	if len(rules) != 27 || r.body["reset_months"] != float64(12) {
		t.Fatalf("27 luật SLA, cộng dồn 12 tháng: %d %v", len(rules), r.body["reset_months"])
	}
	byKey := map[string]map[string]any{}
	for _, rule := range rules {
		byKey[rule["key"].(string)] = rule
	}
	if g := byKey["gv.remarks_late"]; g["has_ladder"] != true || g["value"] != float64(12) || g["group_label"] != "Giáo viên & Trợ giảng" {
		t.Fatalf("SLA nhận xét GV: %v", g)
	}
	if c := byKey["gv.checkin_window"]; c["switchable"] != false || c["has_penalty"] != false {
		t.Fatalf("SLA luôn áp dụng, không phạt: %v", c)
	}

	expectMsg(t, admin.do("PUT", "/system-config/sla/crm.first_contact", map[string]any{"value": 1000}), 422, "Ngưỡng SLA tối đa là 720 giờ.")
	expectMsg(t, admin.do("PUT", "/system-config/sla/crm.first_contact", map[string]any{"value": 0}), 422, "Ngưỡng SLA tối thiểu là 1 giờ.")
	expectMsg(t, admin.do("PUT", "/system-config/sla/gv.remarks_late", map[string]any{"value": 12, "ladder": "0; 30000"}), 422,
		"Bậc phạt nhập các số tiền cách nhau bởi dấu phẩy, ví dụ: 0, 30000, 60000.")
	expectMsg(t, admin.do("PUT", "/system-config/sla/crm.first_contact", map[string]any{"value": 48, "enabled": true, "penalty": false, "amount": 50000}), 200,
		"Đã lưu cấu hình SLA \"Liên hệ khách mới lần đầu\". Áp dụng cho mốc phát sinh từ bây giờ.")
	expectMsg(t, admin.do("PUT", "/system-config/sla/tuition.cash_deposit", map[string]any{"value": "25:00"}), 422, "Giờ không hợp lệ (định dạng HH:MM).")
	expect(t, admin.do("PUT", "/system-config/sla/tuition.cash_deposit", map[string]any{"value": "18:30", "enabled": true}), 200, "")
	expect(t, admin.do("PUT", "/system-config/sla/gv.remarks_late", map[string]any{"value": 10, "enabled": true, "penalty": true, "ladder": "0, 50000"}), 200, "")
	expect(t, admin.do("PUT", "/system-config/sla/khong.co", map[string]any{"value": 1}), 404, "not_found")
	expectMsg(t, admin.do("POST", "/system-config/sla/settings", map[string]any{"ladder_reset_months": 61}), 422, "Số tháng cộng dồn phải từ 1 đến 60.")
	expect(t, admin.do("POST", "/system-config/sla/settings", map[string]any{"ladder_reset_months": 6}), 200, "")

	r = admin.do("GET", "/system-config/sla", nil)
	byKey = map[string]map[string]any{}
	for _, rule := range items(t, r, "rules") {
		byKey[rule["key"].(string)] = rule
	}
	if c := byKey["crm.first_contact"]; c["value"] != float64(48) || c["penalty"] != false || c["amount"] != float64(50000) || c["customized"] != true {
		t.Fatalf("SLA đã đổi: %v", c)
	}
	if c := byKey["tuition.cash_deposit"]; c["value"] != float64(18*60+30) || c["default_label"] != "19:00" {
		t.Fatalf("SLA giờ trong ngày: %v", c)
	}
	if g := byKey["gv.remarks_late"]; len(g["ladder"].([]any)) != 2 {
		t.Fatalf("bậc phạt đã đổi: %v", g)
	}
	if r.body["reset_months"] != float64(6) {
		t.Fatalf("cộng dồn: %v", r.body["reset_months"])
	}
	// Đồng bộ lại danh mục không ghi đè giá trị Admin đã đổi
	if _, err := SyncSLA(t.Context(), e.pg); err != nil {
		t.Fatal(err)
	}
	for _, rule := range items(t, admin.do("GET", "/system-config/sla", nil), "rules") {
		if rule["key"] == "crm.first_contact" && rule["value"] != float64(48) {
			t.Fatalf("đồng bộ ghi đè cấu hình: %v", rule)
		}
	}
}

func TestBankAccounts(t *testing.T) {
	e := setup(t)
	admin := e.as(nil, "admin")
	expectMsg(t, admin.do("POST", "/system-config/bank-accounts", map[string]any{"bank_name": "VCB", "account_number": "1", "account_holder": "A"}), 422, "Vui lòng nhập mã ngân hàng.")
	r := admin.do("POST", "/system-config/bank-accounts", map[string]any{"bank_code": "vcb", "bank_name": "Vietcombank", "account_number": "0011", "account_holder": "cong ty menglish"})
	expectMsg(t, r, 201, "Đã thêm tài khoản ngân hàng Vietcombank (0011) thành công!")
	first := r.body["id"].(string)
	r = admin.do("POST", "/system-config/bank-accounts", map[string]any{"bank_code": "TCB", "bank_name": "Techcombank", "account_number": "0022", "account_holder": "X"})
	second := r.body["id"].(string)

	r = admin.do("GET", "/system-config/bank-accounts", nil)
	accs := items(t, r, "accounts")
	if len(accs) != 2 || accs[0]["id"] != first || accs[0]["is_default_vietqr"] != true || accs[0]["bank_code"] != "VCB" || accs[0]["account_holder"] != "CONG TY MENGLISH" {
		t.Fatalf("tài khoản đầu tiên là mặc định: %v", accs)
	}
	expectMsg(t, admin.do("PUT", "/system-config/bank-accounts/"+first, map[string]any{"bank_code": "VCB", "bank_name": "Vietcombank", "account_number": "0011",
		"account_holder": "A", "is_active": false}), 422, "Không thể ngừng dùng tài khoản mặc định. Hãy đặt tài khoản khác làm mặc định trước.")
	expectMsg(t, admin.do("POST", "/system-config/bank-accounts/"+second+"/default", nil), 200,
		"Đã thiết lập Techcombank (0022) làm tài khoản nhận tiền mặc định VietQR!")
	expect(t, admin.do("PUT", "/system-config/bank-accounts/"+first, map[string]any{"bank_code": "VCB", "bank_name": "Vietcombank", "account_number": "0011",
		"account_holder": "A", "is_active": false}), 200, "")
	// Xóa tài khoản mặc định: tài khoản đang dùng còn lại thành mặc định (ở đây không còn tài khoản đang dùng)
	expect(t, admin.do("DELETE", "/system-config/bank-accounts/"+second, nil), 200, "")
	r = admin.do("GET", "/system-config/bank-accounts", nil)
	if r.body["total_accounts"] != float64(1) || r.body["default_account"] != nil {
		t.Fatalf("sau khi xóa: %v", r.body)
	}
	expect(t, admin.do("POST", "/system-config/bank-accounts/"+first+"/default", nil), 200, "")
	if r := admin.do("GET", "/system-config/bank-accounts", nil); items(t, r, "accounts")[0]["is_active"] != true {
		t.Fatalf("đặt mặc định thì bật dùng lại: %v", r.body)
	}
}

func TestDebtReminders(t *testing.T) {
	e := setup(t)
	admin := e.as(nil, "admin")
	r := admin.do("GET", "/system-config/debt-reminders", nil)
	if r.body["must_contact_days"] != float64(7) || r.body["first_days"] != float64(7) || r.body["repeat_days"] != float64(3) {
		t.Fatalf("mặc định: %v", r.body)
	}
	expectMsg(t, admin.do("POST", "/system-config/debt-reminders", map[string]any{"title": "Nhắc", "template_content": "Chào {ten_phu_huynh}", "timing": "before", "days": 3}), 422,
		"Mẫu tin có biến hệ thống không hỗ trợ: {ten_phu_huynh}. Chỉ dùng: {ten_hoc_vien}, {ma_hoc_vien}, {so_dien_thoai}, {lop_hoc}, {so_tien}, {han_dong}, {moc_nhac}.")
	expectMsg(t, admin.do("POST", "/system-config/debt-reminders", map[string]any{"title": "Nhắc", "template_content": "Chào {TEN_HOC_VIEN}", "timing": "before"}), 422,
		"Vui lòng nhập số ngày trước / sau hạn đóng.")
	expectMsg(t, admin.do("POST", "/system-config/debt-reminders", map[string]any{"title": "Nhắc trước 3 ngày", "template_content": "Chào {TEN_HOC_VIEN}, hạn {han_nop}", "timing": "before", "days": 3}), 200,
		"Đã lưu mốc nhắc nợ T-3 (3 ngày trước hạn) thành công!")
	expectMsg(t, admin.do("POST", "/system-config/debt-reminders", map[string]any{"title": "Trùng", "template_content": "x", "timing": "before", "days": 3, "milestone_key": "KHAC"}), 422,
		"Đã có mốc T-3 (3 ngày trước hạn). Mỗi thời điểm chỉ một mốc nhắc.")
	expect(t, admin.do("POST", "/system-config/debt-reminders", map[string]any{"title": "Quá hạn", "template_content": "x", "timing": "after", "days": 3, "channels": []string{"portal"}}), 200, "")

	expectMsg(t, admin.do("POST", "/system-config/debt-reminders/settings", map[string]any{"must_contact_days": 10, "first_days": 7, "repeat_days": 4}), 422,
		"Giá trị không hợp lệ. Vui lòng nhập trong khoảng từ 1-3 ngày.")
	expectMsg(t, admin.do("POST", "/system-config/debt-reminders/settings", map[string]any{"must_contact_days": 10, "first_days": 7, "repeat_days": 3}), 200,
		"Đã lưu cấu hình nhắc nợ: nhắc trước hạn 7 ngày, nhắc lại 3 ngày trước hạn, quá hạn 10 ngày bắt buộc liên hệ.")
	r = admin.do("GET", "/system-config/debt-reminders", nil)
	rules := items(t, r, "rules")
	keys := []string{}
	for _, rule := range rules {
		keys = append(keys, rule["milestone_key"].(string))
	}
	// T-3 đã có nên mốc nhắc lại dùng T-3; mốc trước hạn 7 ngày tạo NHAC-TRUOC
	if strings.Join(keys, ",") != "NHAC-TRUOC,T-3,T+3" || r.body["must_contact_days"] != float64(10) {
		t.Fatalf("mốc sau thiết lập nhanh: %v %v", keys, r.body)
	}
	if ch := rules[2]["channels"].([]any); len(ch) != 1 || rules[2]["timing"] != "after" {
		t.Fatalf("kênh gửi: %v", rules[2])
	}
}

func TestTicketEmails(t *testing.T) {
	e := setup(t)
	b := e.branch("HN01")
	admin := e.as(nil, "admin")
	manager := e.as([]uuid.UUID{b}, "manager") // support_ticket.update, không có mail_config.manage

	expectMsg(t, manager.do("POST", "/system-config/ticket-emails", map[string]any{
		"emails": "IT@Meducation.vn; sai-dinh-dang\nit@meducation.vn, ops@meducation.vn", "events": map[string]bool{"created": true},
		"mail": map[string]any{"host": "smtp.evil.com"},
	}), 200, "Đã lưu danh sách email nhận và các sự kiện gửi thông báo.")
	r := manager.do("GET", "/system-config/ticket-emails", nil)
	emails := r.body["emails"].([]any)
	events := r.body["events"].(map[string]any)
	if len(emails) != 2 || emails[0] != "it@meducation.vn" || events["created"] != true || events["comment"] != false || r.body["mail"] != nil {
		t.Fatalf("email nhận ticket: %v", r.body)
	}

	expect(t, admin.do("POST", "/system-config/ticket-emails", map[string]any{"emails": []string{"a@b.vn"}, "events": map[string]bool{},
		"mail": map[string]any{"host": "smtp.gmail.com", "port": 465, "encryption": "ssl", "username": "a@b.vn", "password": "abcd efgh ijkl mnop"}}), 200, "")
	r = admin.do("GET", "/system-config/ticket-emails", nil)
	m := r.body["mail"].(map[string]any)
	if m["has_password"] != true || m["port"] != float64(465) || m["host"] != "smtp.gmail.com" {
		t.Fatalf("cấu hình SMTP: %v", m)
	}
	if _, leaked := m["password"]; leaked {
		t.Fatal("không được trả mật khẩu SMTP")
	}
	expectMsg(t, admin.do("POST", "/system-config/ticket-emails", map[string]any{"emails": []string{}, "events": map[string]bool{},
		"mail": map[string]any{"host": "smtp.office365.com", "username": "a@b.vn"}}), 422, "Đổi máy chủ hoặc tài khoản SMTP thì nhập lại mật khẩu ứng dụng.")
}
