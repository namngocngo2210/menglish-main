package classes

import (
	"context"
	"slices"
	"strings"
	"testing"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/catalogs"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

func TestPermissions(t *testing.T) {
	e := setup(t)
	a := e.branch("CN-A")
	admin := e.as(e.member("MES001", nil, iam.RoleAdmin))
	id := e.newClass(admin, map[string]any{"name": "Lớp A1", "code": "A1", "branch_id": a})

	// Giáo viên xem được (lớp của mình) nhưng không tạo / sửa / xếp lịch
	teacher := e.as(e.member("GV001", []uuid.UUID{a}, iam.RoleTeacherFulltime))
	r := teacher.do("GET", "/classes", nil)
	expect(t, r, 200, "")
	if len(list(r, "classes")) != 0 || r.body["can_create"] != false {
		t.Fatalf("giáo viên không phụ trách lớp nào: %v", r.body)
	}
	expect(t, teacher.do("POST", "/classes", map[string]any{"name": "X"}), 403, "forbidden")
	expect(t, teacher.do("PUT", "/classes/"+id.String(), map[string]any{"name": "X"}), 403, "forbidden")
	expect(t, teacher.do("GET", "/classes/form-options", nil), 403, "forbidden")
	expect(t, teacher.do("POST", "/schedule-config", weekly(id, nil)), 403, "forbidden")
	expect(t, teacher.do("GET", "/classes/"+id.String(), nil), 404, "class_not_found")

	// Tài khoản cổng học viên không mở màn quản lý lớp
	stu := e.as(e.member("HS001", []uuid.UUID{a}, iam.RoleStudent))
	expectMessage(t, stu.do("GET", "/classes", nil), 403, "Bạn không có quyền truy cập trang này.")

	// Học thuật không có student.assign_class; Tư vấn viên không điểm danh
	lead := e.as(e.member("HT001", []uuid.UUID{a}, iam.RoleAcademicLead))
	expect(t, lead.do("GET", "/classes/"+id.String(), nil), 200, "")
	expect(t, lead.do("POST", "/class-enrollments", map[string]any{}), 403, "forbidden")
	sales := e.as(e.member("TV001", []uuid.UUID{a}, iam.RoleSalesConsultant))
	expect(t, sales.do("GET", "/class-attendance/"+id.String(), nil), 403, "forbidden")
	expect(t, sales.do("GET", "/classes", nil), 403, "forbidden")

	expect(t, (&client{e: e}).do("GET", "/classes", nil), 401, "unauthenticated")
}

func TestCreateValidateAndList(t *testing.T) {
	e := setup(t)
	a, b := e.branch("CN-A"), e.branch("CN-B")
	roomB := e.room(b, "B101")
	admin := e.as(e.member("MES001", nil, iam.RoleAdmin))
	gv := e.member("GV001", []uuid.UUID{a}, iam.RoleTeacherFulltime)
	sales := e.member("TV001", []uuid.UUID{a}, iam.RoleSalesConsultant)

	cases := []struct {
		body map[string]any
		msg  string
	}{
		{map[string]any{"branch_id": a, "program": "IELTS", "level": "B1", "max_capacity": 10}, "Chưa nhập Tên lớp."},
		{map[string]any{"name": "L", "program": "IELTS", "level": "B1", "max_capacity": 10}, "Chưa chọn Chi nhánh."},
		{map[string]any{"name": "L", "branch_id": a, "level": "B1", "max_capacity": 10}, "Chưa chọn Chương trình."},
		{map[string]any{"name": "L", "branch_id": a, "program": "IELTS", "max_capacity": 10}, "Chưa chọn Cấp độ."},
		{map[string]any{"name": "L", "branch_id": a, "program": "IELTS", "level": "B1", "max_capacity": 0}, "Sĩ số tối đa phải lớn hơn 0."},
		{map[string]any{"name": "L", "branch_id": a, "program": "IELTS", "level": "B1", "max_capacity": 5, "min_students": 6}, "Ngưỡng khai giảng không được lớn hơn sĩ số tối đa."},
		{map[string]any{"name": "L", "branch_id": a, "program": "IELTS", "level": "B1", "max_capacity": 5, "tuition_fee": -1}, "Học phí không được nhỏ hơn 0."},
		{map[string]any{"name": "L", "branch_id": uuid.New(), "program": "IELTS", "level": "B1", "max_capacity": 5}, "Chi nhánh không tồn tại, vui lòng chọn lại."},
		{map[string]any{"name": "L", "branch_id": a, "program": "IELTS", "level": "B1", "max_capacity": 5, "teacher_id": sales.id}, errStaffRole.Message},
		{map[string]any{"name": "L", "branch_id": a, "program": "IELTS", "level": "B1", "max_capacity": 5, "room_id": roomB}, msgRoomBranch},
	}
	for _, c := range cases {
		expectMessage(t, admin.do("POST", "/classes", c.body), 422, c.msg)
	}

	// Không nhập mã: mã sinh từ chương trình; mã trùng thì thêm hậu tố
	r := admin.do("POST", "/classes", map[string]any{"name": "IELTS tối", "branch_id": a, "program": "IELTS Foundation", "level": "B1", "max_capacity": 10, "teacher_id": gv.id})
	expect(t, r, 201, "")
	msg := r.body["message"].(string)
	if !strings.HasPrefix(msg, "Tạo lớp học 'IELTS tối' (IELTS-FOUNDATION-") || !strings.HasSuffix(msg, "thành công! Bước tiếp theo: cấu hình lịch học.") || r.body["tab"] != "schedule" {
		t.Fatalf("tạo lớp không mã: %v", r.body)
	}
	e.newClass(admin, map[string]any{"name": "Lớp L01", "code": "l01", "branch_id": a})
	r = admin.do("POST", "/classes", map[string]any{"name": "Lớp L01 bis", "code": "L01", "branch_id": b, "program": "Giao tiếp", "level": "A2", "max_capacity": 10})
	expectMessage(t, r, 201, "Tạo lớp học 'Lớp L01 bis' (L01-1) thành công! Bước tiếp theo: cấu hình lịch học.")

	// TKB render sẵn khi tạo: tạo buổi, kích hoạt lớp
	r = admin.do("POST", "/classes", map[string]any{"name": "Có lịch", "code": "CL", "branch_id": a, "program": "IELTS Foundation", "level": "B2", "max_capacity": 10,
		"schedule_sessions": []map[string]any{
			{"date": "2026-10-20", "shift": "Ca 1", "start": "18:00", "end": "19:30"},
			{"date": "2026-10-22", "shift": "Ca 1", "start": "18:00", "end": "19:30"},
		}})
	expectMessage(t, r, 201, "Tạo lớp học 'Có lịch' (CL) thành công với 2 buổi học đã lên lịch; lớp đã được kích hoạt.")

	// Danh sách: chip trạng thái / chương trình / cấp độ, lọc, tìm kiếm
	r = admin.do("GET", "/classes", nil)
	expect(t, r, 200, "")
	if r.body["total"] != float64(4) || r.body["can_create"] != true {
		t.Fatalf("danh sách lớp: %v", r.body)
	}
	counts := map[string]float64{}
	for _, s := range list(r, "statuses") {
		counts[s["key"].(string)] = s["count"].(float64)
	}
	if counts[StatusPendingSchedule] != 3 || counts[StatusActive] != 1 {
		t.Fatalf("đếm theo trạng thái: %v", counts)
	}
	programs := map[string]float64{}
	for _, p := range list(r, "program_counts") {
		programs[p["label"].(string)] = p["total"].(float64)
	}
	if programs["IELTS Foundation"] != 3 || programs["Giao tiếp"] != 1 {
		t.Fatalf("chip chương trình: %v", programs)
	}
	if got := list(admin.do("GET", "/classes?status=active", nil), "classes"); len(got) != 1 || got[0]["code"] != "CL" {
		t.Fatalf("lọc Đang học: %v", got)
	}
	if got := list(admin.do("GET", "/classes?program=Giao%20ti%E1%BA%BFp", nil), "classes"); len(got) != 1 || got[0]["code"] != "L01-1" {
		t.Fatalf("lọc chương trình: %v", got)
	}
	if got := list(admin.do("GET", "/classes?q=l01", nil), "classes"); len(got) != 2 {
		t.Fatalf("tìm theo mã: %v", got)
	}
	if got := list(admin.do("GET", "/classes?branch_id="+b.String(), nil), "classes"); len(got) != 1 {
		t.Fatalf("lọc chi nhánh: %v", got)
	}

	// Trang lớp
	id := e.newClass(admin, map[string]any{"name": "Trang lớp", "code": "TL", "branch_id": a, "teacher_id": gv.id})
	r = admin.do("GET", "/classes/"+id.String(), nil)
	expect(t, r, 200, "")
	k := r.body["klass"].(map[string]any)
	if k["code"] != "TL" || k["status"] != StatusPendingSchedule || k["teacher"] != "Người GV001" || r.body["can_manage"] != true || r.body["tab"] != "overview" {
		t.Fatalf("trang lớp: %v", r.body)
	}
	seat := r.body["seat"].(map[string]any)
	if seat["capacity"] != float64(10) || seat["min"] != float64(DefaultMinStudents) || seat["needed"] != float64(DefaultMinStudents) {
		t.Fatalf("sĩ số: %v", seat)
	}

	// Sửa lớp
	r = admin.do("PUT", "/classes/"+id.String(), map[string]any{"name": "Trang lớp 2", "code": "TL", "branch_id": a, "program": "IELTS Foundation", "level": "B1", "max_capacity": 12})
	expectMessage(t, r, 200, "Đã cập nhật lớp học 'Trang lớp 2' (TL) thành công!")
	if v := e.classField(id, "max_capacity"); strp(v) != "12" {
		t.Fatalf("sĩ số sau sửa: %s", strp(v))
	}
	r = admin.do("GET", "/classes/form-options?class_id="+id.String(), nil)
	expect(t, r, 200, "")
	if v := r.body["values"].(map[string]any); v["max_capacity"] != float64(12) || v["code"] != "TL" || len(list(r, "programs")) == 0 {
		t.Fatalf("form sửa lớp: %v", r.body)
	}
	expectMessage(t, admin.do("PUT", "/classes/"+id.String(), map[string]any{"name": "", "branch_id": a}), 422, "Chưa nhập Tên lớp.")
	expect(t, admin.do("GET", "/classes/"+uuid.NewString(), nil), 404, "class_not_found")
}

func TestBranchScope(t *testing.T) {
	e := setup(t)
	a, b := e.branch("CN-A"), e.branch("CN-B")
	admin := e.as(e.member("MES001", nil, iam.RoleAdmin))
	ma := e.as(e.member("QL001", []uuid.UUID{a}, iam.RoleManager))
	gv1 := e.member("GV001", []uuid.UUID{a}, iam.RoleTeacherFulltime)
	gv2 := e.member("GV002", []uuid.UUID{a}, iam.RoleTeacherFulltime)

	expectMessage(t, ma.do("POST", "/classes", map[string]any{"name": "L", "branch_id": b, "program": "IELTS", "level": "B1", "max_capacity": 5}),
		403, "Bạn chỉ được quản lý lớp thuộc chi nhánh của mình.")
	inA := e.newClass(ma, map[string]any{"name": "Lớp A", "code": "LA", "branch_id": a, "teacher_id": gv1.id})
	inB := e.newClass(admin, map[string]any{"name": "Lớp B", "code": "LB", "branch_id": b})

	if got := list(ma.do("GET", "/classes", nil), "classes"); len(got) != 1 || got[0]["code"] != "LA" {
		t.Fatalf("quản lý chi nhánh A chỉ thấy lớp chi nhánh A: %v", got)
	}
	expect(t, ma.do("GET", "/classes/"+inB.String(), nil), 404, "class_not_found")
	expectMessage(t, ma.do("PUT", "/classes/"+inB.String(), map[string]any{"name": "X", "branch_id": b, "program": "P", "level": "L", "max_capacity": 5}),
		403, ErrOutOfScope.Message)
	expectMessage(t, ma.do("DELETE", "/classes/"+inB.String(), nil), 403, ErrOutOfScope.Message)
	expectMessage(t, ma.do("POST", "/schedule-config", weekly(inB, nil)), 403, "Bạn không phụ trách lớp này.")
	// Chuyển lớp sang chi nhánh khác ngoài phạm vi
	expectMessage(t, ma.do("PUT", "/classes/"+inA.String(), map[string]any{"name": "Lớp A", "branch_id": b, "program": "P", "level": "L", "max_capacity": 5}),
		403, "Bạn chỉ được quản lý lớp thuộc chi nhánh của mình.")

	// Giáo viên chỉ thấy lớp mình dạy
	t1, t2 := e.as(gv1), e.as(gv2)
	if got := list(t1.do("GET", "/classes", nil), "classes"); len(got) != 1 || got[0]["code"] != "LA" {
		t.Fatalf("giáo viên thấy lớp mình dạy: %v", got)
	}
	expect(t, t1.do("GET", "/classes/"+inA.String(), nil), 200, "")
	expect(t, t2.do("GET", "/classes/"+inA.String(), nil), 404, "class_not_found")

	// Dạy thay một buổi cũng thấy lớp
	expectMessage(t, admin.do("POST", "/schedule-config", weekly(inA, nil)), 200, "Đã lưu lịch và tạo 8 buổi học thực tế.")
	e.exec("UPDATE academic.class_sessions SET teacher_id = $2 WHERE id = $1", e.sessionOn(inA, "2026-10-21"), gv2.id)
	expect(t, t2.do("GET", "/classes/"+inA.String(), nil), 200, "")

	// Cấp riêng class.view cho lớp B: quản lý A xem được lớp đó
	e.exec("INSERT INTO iam.permission_overrides (user_id, permission, allow, scope_type, scope_id) VALUES ($1, 'class.view', true, 'class', $2)", e.mustUser("QL001"), inB)
	if err := iam.InvalidateAllAccess(context.Background(), e.rdb); err != nil {
		t.Fatal(err)
	}
	expect(t, ma.do("GET", "/classes/"+inB.String(), nil), 200, "")
	if got := list(ma.do("GET", "/classes", nil), "classes"); len(got) != 2 {
		t.Fatalf("lớp được cấp riêng hiện trong danh sách: %v", got)
	}
}

func (e *env) mustUser(code string) uuid.UUID {
	e.t.Helper()
	var id uuid.UUID
	e.scalar(&id, "SELECT id FROM iam.users WHERE code = $1", code)
	return id
}

func TestScheduleConfig(t *testing.T) {
	e := setup(t)
	a := e.branch("CN-A")
	r101, r102 := e.room(a, "P101"), e.room(a, "P102")
	admin := e.member("MES001", nil, iam.RoleAdmin)
	hv := e.as(e.member("HV001", []uuid.UUID{a}, iam.RoleAcademicStaff))
	gv := e.member("GV001", []uuid.UUID{a}, iam.RoleTeacherFulltime)
	c1 := e.newClass(hv, map[string]any{"name": "Lớp 1", "code": "L1", "branch_id": a, "teacher_id": gv.id})

	// Khung giờ ca dạy
	expectMessage(t, hv.do("POST", "/schedule-config", weekly(c1, map[string]any{"slot1_end": "19:00"})), 422,
		"Thứ 2: mỗi ca dạy 90 phút — ca bắt đầu 18:00 phải kết thúc lúc 19:30.")
	expectPrefix(t, hv.do("POST", "/schedule-config", weekly(c1, map[string]any{"slot1_start": "18:20", "slot1_end": "19:50"})), 422,
		"Thứ 2 chỉ có Ca 1 (18:00")
	expectMessage(t, hv.do("POST", "/schedule-config", weekly(c1, map[string]any{"slot1_day": "", "slot2_day": ""})), 422,
		"Cần chọn ít nhất một ca học (ngày trong tuần).")
	expectMessage(t, hv.do("POST", "/schedule-config", weekly(c1, map[string]any{"end_date": "2026-10-01"})), 422,
		"Ngày kết thúc phải sau hoặc bằng ngày bắt đầu.")
	expectMessage(t, hv.do("POST", "/schedule-config", weekly(c1, map[string]any{"slot2_day": "Thứ 2"})), 422,
		"Hai ca học trùng ngày Thứ 2 và chồng giờ (18:00-19:30 và 18:00-19:30).")
	expectMessage(t, hv.do("POST", "/schedule-config", weekly(c1, map[string]any{"room_id": e.room(e.branch("CN-B"), "B1")})), 422,
		"Phòng học không thuộc chi nhánh của lớp, vui lòng chọn lại.")

	// Lưu TKB: 8 buổi, lớp Chờ lịch → Đang học, gắn phòng
	expectMessage(t, hv.do("POST", "/schedule-config", weekly(c1, map[string]any{"room_id": r101})), 200, "Đã lưu lịch và tạo 8 buổi học thực tế.")
	want := []string{"2026-10-19", "2026-10-21", "2026-10-26", "2026-10-28", "2026-11-02", "2026-11-04", "2026-11-09", "2026-11-11"}
	got := e.sessionDates(c1, "scheduled")
	if len(got) != len(want) {
		t.Fatalf("buổi học: %v", got)
	}
	for i, d := range want {
		if got[i] != d+":regular" {
			t.Fatalf("buổi %d: %s, muốn %s", i, got[i], d)
		}
	}
	if strp(e.classField(c1, "status")) != StatusActive || strp(e.classField(c1, "room")) != "P101" ||
		strp(e.classField(c1, "schedule_text")) != "Thứ 2 Ca 1 18:00-19:30; Thứ 4 Ca 1 18:00-19:30" {
		t.Fatalf("lớp sau khi lưu TKB: %s %s %s", strp(e.classField(c1, "status")), strp(e.classField(c1, "room")), strp(e.classField(c1, "schedule_text")))
	}
	r := hv.do("GET", "/classes/"+c1.String()+"?tab=schedule", nil)
	expect(t, r, 200, "")
	if len(list(r, "sessions")) != 8 || r.body["klass"].(map[string]any)["has_schedule_config"] != true {
		t.Fatalf("tab lịch: %v", r.body)
	}
	// Lưu lại: buổi chưa diễn ra được thay, không sinh trùng
	expectMessage(t, hv.do("POST", "/schedule-config", weekly(c1, nil)), 200, "Đã lưu lịch và tạo 8 buổi học thực tế.")
	if got := e.sessionDates(c1, "scheduled"); len(got) != 8 {
		t.Fatalf("lưu lại TKB sinh trùng: %v", got)
	}

	// Trùng giáo viên với lớp khác
	c2 := e.newClass(hv, map[string]any{"name": "Lớp 2", "code": "L2", "branch_id": a, "teacher_id": gv.id})
	expectPrefix(t, hv.do("POST", "/schedule-config", weekly(c2, nil)), 422, "Xung đột ")

	// Phòng đang có lớp đang học vào ca đó → chặn
	c3 := e.newClass(hv, map[string]any{"name": "Lớp 3", "code": "L3", "branch_id": a})
	msg := expectPrefix(t, hv.do("POST", "/schedule-config", weekly(c3, map[string]any{"room_id": r101})), 422, "P101 đã có lớp Lớp 1 đang học vào ")
	if !strings.HasSuffix(msg, "— vui lòng chọn phòng khác.") {
		t.Fatalf("thông báo phòng: %q", msg)
	}

	// Phòng gán cho lớp chưa bắt đầu học → hỏi chuyển phòng, xác nhận thì gỡ phòng khỏi lớp kia
	c4 := e.newClass(hv, map[string]any{"name": "Lớp 4", "code": "L4", "branch_id": a})
	expectMessage(t, hv.do("POST", "/schedule-config", weekly(c4, map[string]any{"room_id": r102, "start_date": "2026-11-02", "end_date": "2026-11-30"})),
		200, "Đã lưu lịch và tạo 9 buổi học thực tế.")
	r = hv.do("POST", "/schedule-config", weekly(c3, map[string]any{"room_id": r102}))
	expect(t, r, 409, "room_transfer")
	if m := r.body["message"].(string); !strings.HasPrefix(m, "P102 đang được gán cho lớp Lớp 4 (chưa bắt đầu học). Chuyển phòng sang lớp này?") {
		t.Fatalf("hỏi chuyển phòng: %q", m)
	}
	expectMessage(t, hv.do("POST", "/schedule-config", weekly(c3, map[string]any{"room_id": r102, "transfer_room": true})), 200, "Đã lưu lịch và tạo 8 buổi học thực tế.")
	if e.classField(c4, "room") != nil || strp(e.classField(c3, "room")) != "P102" {
		t.Fatalf("chuyển phòng: lớp 4 %s, lớp 3 %s", strp(e.classField(c4, "room")), strp(e.classField(c3, "room")))
	}
	var left int
	e.scalar(&left, "SELECT count(*) FROM academic.class_sessions WHERE class_id = $1 AND room = 'P102'", c4)
	if left != 0 {
		t.Fatalf("buổi của lớp 4 còn giữ phòng P102: %d", left)
	}

	// Trang Lịch & TKB lớp
	r = hv.do("GET", "/schedule-config?class_id="+c1.String(), nil)
	expect(t, r, 200, "")
	if r.body["can_schedule"] != true || r.body["initial"].(map[string]any)["slot1_day"] != "Thứ 2" {
		t.Fatalf("trang TKB: %v", r.body)
	}

	// Kết thúc / mở lại lớp
	r = hv.do("POST", "/schedule-config/toggle", map[string]any{"class_id": c1})
	expectMessage(t, r, 200, "Đã kết thúc lớp Lớp 1. P101 đã được giải phóng khỏi lớp này.")
	r = hv.do("POST", "/schedule-config/toggle", map[string]any{"class_id": c1})
	expectMessage(t, r, 200, "Đã mở lại lớp Lớp 1.")
	if r.body["warning"] != "Lớp chưa có phòng học — vui lòng gán lại phòng ở Lịch & TKB lớp hoặc Sửa lớp." {
		t.Fatalf("cảnh báo mở lại: %v", r.body)
	}
	c5 := e.newClass(hv, map[string]any{"name": "Lớp 5", "code": "L5", "branch_id": a})
	expectMessage(t, hv.do("POST", "/schedule-config/toggle", map[string]any{"class_id": c5}), 422,
		"Lớp Lớp 5 đang ở trạng thái 'Chờ lịch', chỉ lớp Đang học/Đã kết thúc mới đổi trạng thái được tại đây.")
	_ = admin
}

func TestHolidayReschedule(t *testing.T) {
	e := setup(t)
	a := e.branch("CN-A")
	admin := e.member("MES001", nil, iam.RoleAdmin)
	c := e.as(admin)
	id := e.newClass(c, map[string]any{"name": "Lớp lễ", "code": "LL", "branch_id": a})
	expectMessage(t, c.do("POST", "/schedule-config", weekly(id, nil)), 200, "Đã lưu lịch và tạo 8 buổi học thực tế.")

	ctx := context.Background()
	hid, msg, err := e.cat.CreateHoliday(ctx, e.access(admin), catalogs.HolidayInput{
		Code: "NL1", Name: "Nghỉ thử", StartDate: "2026-10-26", EndDate: "2026-10-26", IsSystemWide: true,
	})
	if err != nil {
		t.Fatal(err)
	}
	if msg != "Đã thêm ngày nghỉ. Đã hủy 1 buổi học trùng ngày nghỉ, xếp bù 1 buổi vào cuối lịch." {
		t.Fatalf("thông báo ngày nghỉ: %q", msg)
	}
	if got := e.sessionDates(id, "cancelled"); !slices.Equal(got, []string{"2026-10-26:regular"}) {
		t.Fatalf("buổi bị hủy: %v", got)
	}
	got := e.sessionDates(id, "scheduled")
	if len(got) != 8 || got[7] != "2026-11-16:makeup" {
		t.Fatalf("buổi bù: %v", got)
	}
	if strp(e.classField(id, "end_date")) != "2026-11-16" {
		t.Fatalf("ngày kết thúc kéo dài: %s", strp(e.classField(id, "end_date")))
	}
	// Ngày nghỉ hiện trên trang TKB
	r := c.do("GET", "/schedule-config", nil)
	if hs := list(r, "holiday_sessions"); len(hs) != 1 || hs[0]["holiday"] != "Nghỉ thử" {
		t.Fatalf("buổi nghỉ lễ trên trang TKB: %v", r.body["holiday_sessions"])
	}

	msg, err = e.cat.DeleteHoliday(ctx, e.access(admin), hid)
	if err != nil {
		t.Fatal(err)
	}
	if !strings.HasSuffix(msg, " Đã khôi phục 1 buổi học bị hủy do ngày nghỉ này (buổi bù tương ứng đã được gỡ).") {
		t.Fatalf("thông báo xóa ngày nghỉ: %q", msg)
	}
	if got := e.sessionDates(id, "scheduled"); len(got) != 8 || got[2] != "2026-10-26:regular" || got[7] != "2026-11-11:regular" {
		t.Fatalf("khôi phục buổi: %v", got)
	}
	if strp(e.classField(id, "end_date")) != "2026-11-11" {
		t.Fatalf("ngày kết thúc kéo lại: %s", strp(e.classField(id, "end_date")))
	}

	// Lịch tạo sau khi có ngày nghỉ bỏ qua ngày nghỉ
	if _, _, err := e.cat.CreateHoliday(ctx, e.access(admin), catalogs.HolidayInput{
		Code: "NL2", Name: "Nghỉ chi nhánh", StartDate: "2026-11-02", EndDate: "2026-11-02", BranchIDs: []uuid.UUID{a},
	}); err != nil {
		t.Fatal(err)
	}
	id2 := e.newClass(c, map[string]any{"name": "Lớp sau lễ", "code": "LS", "branch_id": a})
	expectMessage(t, c.do("POST", "/schedule-config", weekly(id2, nil)), 200, "Đã lưu lịch và tạo 7 buổi học thực tế.")
}

func TestForeignTeacherAndDelete(t *testing.T) {
	e := setup(t)
	a := e.branch("CN-A")
	admin := e.as(e.member("MES001", nil, iam.RoleAdmin))
	nn := e.member("NN001", []uuid.UUID{a}, iam.RoleTeacherFulltime)
	sales := e.member("TV001", []uuid.UUID{a}, iam.RoleSalesConsultant)
	id := e.newClass(admin, map[string]any{"name": "Lớp GVNN", "code": "NN", "branch_id": a})
	expectMessage(t, admin.do("POST", "/schedule-config", weekly(id, nil)), 200, "Đã lưu lịch và tạo 8 buổi học thực tế.")
	s1, s2 := e.sessionOn(id, "2026-10-21"), e.sessionOn(id, "2026-10-28")

	path := "/classes/" + id.String() + "/foreign-teacher"
	expectMessage(t, admin.do("POST", path, map[string]any{"foreign_teacher_id": nn.id, "session_ids": []uuid.UUID{}}), 422, "Chọn ít nhất một buổi học để gán GVNN.")
	expectMessage(t, admin.do("POST", path, map[string]any{"foreign_teacher_id": "", "session_ids": []uuid.UUID{s1}}), 422, `Chọn GVNN, hoặc chọn "Không có GVNN" để gỡ.`)
	expectMessage(t, admin.do("POST", path, map[string]any{"foreign_teacher_id": sales.id, "session_ids": []uuid.UUID{s1}}), 422, errStaffRole.Message)
	expectMessage(t, admin.do("POST", path, map[string]any{"foreign_teacher_id": nn.id, "session_ids": []uuid.UUID{s1, s2}}), 200, "Đã gán GVNN Người NN001 cho 2 buổi.")
	var n int
	e.scalar(&n, "SELECT count(*) FROM academic.class_sessions WHERE class_id = $1 AND foreign_teacher_id = $2", id, nn.id)
	if n != 2 {
		t.Fatalf("buổi có GVNN: %d", n)
	}
	var title, body, link string
	if err := e.pg.QueryRow(context.Background(), "SELECT title, body, link FROM core.notifications WHERE user_id = $1 AND type = 'class_assigned'", nn.id).
		Scan(&title, &body, &link); err != nil {
		t.Fatal(err)
	}
	if title != "Bạn được xếp dạy GVNN lớp NN" || body != "2 buổi lớp Lớp GVNN: 21/10, 28/10." || link != "/classes/"+id.String()+"?tab=schedule" {
		t.Fatalf("thông báo GVNN: %q %q %q", title, body, link)
	}
	var logs []string
	rows, err := e.pg.Query(context.Background(), "SELECT action || ': ' || description FROM core.audit_log WHERE subject_type = 'class' AND subject_id = $1 ORDER BY created_at, id", id.String())
	if err != nil {
		t.Fatal(err)
	}
	for rows.Next() {
		var l string
		_ = rows.Scan(&l)
		logs = append(logs, l)
	}
	rows.Close()
	if len(logs) != 2 || logs[0] != `class.create: Tạo mới lớp học "Lớp GVNN"` || logs[1] != `class.foreign_teacher: Gán GVNN cho 2 buổi lớp "Lớp GVNN"` {
		t.Fatalf("nhật ký lớp: %v", logs)
	}
	// GVNN thấy lớp qua buổi được gán
	expect(t, e.as(nn).do("GET", "/classes/"+id.String(), nil), 200, "")
	expectMessage(t, admin.do("POST", path, map[string]any{"foreign_teacher_id": "none", "session_ids": []uuid.UUID{s1}}), 200, "Đã gỡ GVNN khỏi 1 buổi.")

	// Không xóa lớp còn buổi chưa diễn ra; lớp trống thì xóa được
	expectMessage(t, admin.do("DELETE", "/classes/"+id.String(), nil), 409,
		"Không thể xóa lớp 'Lớp GVNN' (NN) vì còn 0 học viên đang xếp lớp và 8 buổi học chưa diễn ra. Hãy chuyển lớp sang trạng thái Đã hủy thay vì xóa.")
	empty := e.newClass(admin, map[string]any{"name": "Lớp trống", "code": "TRONG", "branch_id": a})
	expectMessage(t, admin.do("DELETE", "/classes/"+empty.String(), nil), 200, "Đã xóa lớp học 'Lớp trống' (TRONG) thành công.")
	e.scalar(&n, "SELECT count(*) FROM core.audit_log WHERE subject_type = 'class' AND subject_id = $1 AND event = 'deleted'", empty.String())
	if n != 1 {
		t.Fatalf("nhật ký xóa lớp: %d", n)
	}
	expect(t, admin.do("GET", "/classes/"+empty.String(), nil), 404, "class_not_found")
}

func TestEnrollments(t *testing.T) {
	e := setup(t)
	a, b := e.branch("CN-A"), e.branch("CN-B")
	admin := e.as(e.member("MES001", nil, iam.RoleAdmin))
	hv := e.as(e.member("HV001", []uuid.UUID{a}, iam.RoleAcademicStaff))
	c1 := e.newClass(admin, map[string]any{"name": "Lớp A1", "code": "A1", "branch_id": a, "max_capacity": 2})
	c2 := e.newClass(admin, map[string]any{"name": "Lớp A2", "code": "A2", "branch_id": a})
	expectMessage(t, admin.do("POST", "/schedule-config", weekly(c2, nil)), 200, "Đã lưu lịch và tạo 8 buổi học thực tế.")
	s1 := e.student("HV-0001", "Nguyễn An", &a)
	s2 := e.student("HV-0002", "Trần Bình", &a)
	s3 := e.student("HV-0003", "Lê Chi", &a)
	s4 := e.student("HV-0004", "Phạm Dũng", &b)

	enroll := func(c *client, st, cl uuid.UUID) resp {
		return c.do("POST", "/class-enrollments", map[string]any{"student_id": st, "class_id": cl})
	}
	const ok = "Đã xếp lớp. Học vụ cần hoàn tất checklist giáo trình và nhóm lớp."
	expectMessage(t, hv.do("POST", "/class-enrollments", map[string]any{"class_id": c1}), 422, "Vui lòng chọn học viên.")
	expectMessage(t, enroll(hv, s1, c1), 200, ok)
	expectMessage(t, enroll(hv, s1, c1), 422, "Học viên đã có trong danh sách của lớp này.")
	expect(t, enroll(hv, s4, c1), 404, "student_not_found")
	expectMessage(t, enroll(admin, s4, c1), 422, "Học viên và lớp phải thuộc cùng chi nhánh.")
	expectMessage(t, enroll(hv, s2, c1), 200, ok)
	expectMessage(t, enroll(hv, s3, c1), 422, "Lớp Lớp A1 đã đủ sĩ số (2 học viên).")

	// Chuyển lớp: lượt ở lớp cũ hết hiệu lực từ hôm nay, lớp cũ có chỗ trống
	expectMessage(t, enroll(hv, s1, c2), 200, ok)
	r := hv.do("GET", "/class-enrollments?student_id="+s1.String(), nil)
	expect(t, r, 200, "")
	rows := list(r, "enrollments")
	if len(rows) != 2 {
		t.Fatalf("lượt xếp lớp của học viên: %v", rows)
	}
	var newID string
	for _, row := range rows {
		switch row["class_code"] {
		case "A1":
			if row["left_at"] != "2026-10-19" {
				t.Fatalf("lượt lớp cũ chưa kết thúc: %v", row)
			}
		case "A2":
			if row["left_at"] != nil || row["status"] != "pending" {
				t.Fatalf("lượt lớp mới: %v", row)
			}
			newID = row["id"].(string)
		}
	}
	var current uuid.UUID
	e.scalar(&current, "SELECT current_class_id FROM academic.students WHERE id = $1", s1)
	if current != c2 {
		t.Fatalf("lớp chính sau chuyển lớp: %s", current)
	}
	expectMessage(t, enroll(hv, s3, c1), 200, ok)

	// Liên kết lớp khác: không đổi lớp chính
	expectMessage(t, hv.do("POST", "/class-enrollments/link", map[string]any{"student_id": s2, "class_id": c2}), 200, "Đã liên kết học viên Trần Bình với lớp Lớp A2.")
	e.scalar(&current, "SELECT current_class_id FROM academic.students WHERE id = $1", s2)
	if current != c1 {
		t.Fatalf("liên kết lớp đổi lớp chính: %s", current)
	}
	r = admin.do("GET", "/classes/"+c2.String()+"?tab=students", nil)
	if st := list(r, "students"); len(st) != 2 {
		t.Fatalf("danh sách lớp A2 (lớp chính + liên kết): %v", st)
	}

	// Bàn giao: chưa đủ checklist; đủ thì hoàn tất, lớp đã khai giảng → Đang học
	expectMessage(t, hv.do("PUT", "/class-enrollments/"+newID, map[string]any{"curriculum_delivered": true}), 200, "Đã cập nhật checklist; bàn giao vẫn đang chờ hoàn tất.")
	expectMessage(t, hv.do("PUT", "/class-enrollments/"+newID, map[string]any{"curriculum_delivered": true, "zalo_group_added": true}), 200, "Đã hoàn tất bàn giao học viên vào lớp.")
	var status string
	e.scalar(&status, "SELECT status FROM academic.students WHERE id = $1", s1)
	if status != "studying" {
		t.Fatalf("học viên sau bàn giao: %s", status)
	}
	expect(t, hv.do("PUT", "/class-enrollments/"+uuid.NewString(), map[string]any{}), 404, "enrollment_not_found")

	// Lệnh hằng ngày: bàn giao xong trước khai giảng → Đang học khi lớp khai giảng
	c3 := e.newClass(admin, map[string]any{"name": "Lớp A3", "code": "A3", "branch_id": a})
	s5 := e.student("HV-0005", "Võ Em", &a)
	expectMessage(t, enroll(hv, s5, c3), 200, ok)
	var eid uuid.UUID
	e.scalar(&eid, "SELECT id FROM academic.enrollments WHERE student_id = $1", s5)
	expectMessage(t, hv.do("PUT", "/class-enrollments/"+eid.String(), map[string]any{"curriculum_delivered": true, "zalo_group_added": true}), 200, "Đã hoàn tất bàn giao học viên vào lớp.")
	e.scalar(&status, "SELECT status FROM academic.students WHERE id = $1", s5)
	if status != "waiting_start" {
		t.Fatalf("lớp chưa khai giảng vẫn Chờ khai giảng: %s", status)
	}
	expectMessage(t, admin.do("POST", "/schedule-config", weekly(c3, nil)), 200, "Đã lưu lịch và tạo 8 buổi học thực tế.")
	if n, err := e.svc.StartStudying(context.Background()); err != nil || n != 1 {
		t.Fatalf("chuyển Đang học: %d %v", n, err)
	}

	// Xóa lớp còn học viên bị chặn
	expectPrefix(t, admin.do("DELETE", "/classes/"+c1.String(), nil), 409, "Không thể xóa lớp 'Lớp A1' (A1) vì còn 2 học viên đang xếp lớp")

	// Phạm vi: Học vụ chi nhánh A không thấy lượt của học viên chi nhánh B
	expectMessage(t, enroll(admin, e.student("HV-0006", "Đỗ Phúc", &b), e.newClass(admin, map[string]any{"name": "Lớp B1", "code": "B1", "branch_id": b})), 200, ok)
	r = hv.do("GET", "/class-enrollments", nil)
	for _, row := range list(r, "enrollments") {
		if row["branch"] == "Chi nhánh CN-B" {
			t.Fatalf("Học vụ chi nhánh A thấy lượt chi nhánh B: %v", row)
		}
	}
	if r.body["can_assign"] != true {
		t.Fatalf("can_assign: %v", r.body)
	}
}

func TestAttendance(t *testing.T) {
	e := setup(t)
	a := e.branch("CN-A")
	admin := e.as(e.member("MES001", nil, iam.RoleAdmin))
	gvm := e.member("GV001", []uuid.UUID{a}, iam.RoleTeacherFulltime)
	gv := e.as(gvm)
	other := e.as(e.member("GV002", []uuid.UUID{a}, iam.RoleTeacherFulltime))
	hv := e.as(e.member("HV001", []uuid.UUID{a}, iam.RoleAcademicStaff))
	id := e.newClass(admin, map[string]any{"name": "Lớp ĐD", "code": "DD", "branch_id": a, "teacher_id": gvm.id})
	expectMessage(t, admin.do("POST", "/schedule-config", weekly(id, nil)), 200, "Đã lưu lịch và tạo 8 buổi học thực tế.")
	s1 := e.student("HV-0001", "Nguyễn An", &a)
	s2 := e.student("HV-0002", "Trần Bình", &a)
	outsider := e.student("HV-0003", "Lê Chi", &a)
	for _, st := range []uuid.UUID{s1, s2} {
		expect(t, admin.do("POST", "/class-enrollments", map[string]any{"student_id": st, "class_id": id}), 200, "")
	}

	path := "/class-attendance/" + id.String()
	r := gv.do("GET", path, nil)
	expect(t, r, 200, "")
	if st := list(r, "students"); len(st) != 2 {
		t.Fatalf("danh sách điểm danh: %v", r.body)
	}
	expectMessage(t, other.do("GET", path, nil), 403, "Bạn không được phân công phụ trách lớp này.")

	rec := func(status1, note1 string) []map[string]any {
		return []map[string]any{
			{"student_id": s1, "status": status1, "note": note1},
			{"student_id": s2, "status": "present"},
		}
	}
	expectMessage(t, gv.do("POST", path, map[string]any{"records": []any{}}), 422, "Chưa có học viên nào để điểm danh.")
	expectMessage(t, gv.do("POST", path, map[string]any{"records": rec("sleeping", "")}), 422, "Trạng thái điểm danh không hợp lệ.")
	expectMessage(t, gv.do("POST", path, map[string]any{"records": rec("absent", " ")}), 422, `Vui lòng điền rõ lý do cho 1 học sinh được đánh dấu "Nghỉ".`)
	expectMessage(t, gv.do("POST", path, map[string]any{"records": append(rec("present", ""), map[string]any{"student_id": outsider, "status": "present"})}), 422,
		"Học viên không thuộc lớp/buổi học này.")
	expectMessage(t, other.do("POST", path, map[string]any{"records": rec("present", "")}), 403, "Bạn không được phân công phụ trách lớp này.")
	expectMessage(t, gv.do("POST", path, map[string]any{"records": rec("absent", "Ốm")}), 200, "Đã lưu điểm danh 2 học viên lớp Lớp ĐD buổi hôm nay.")
	var n int
	e.scalar(&n, "SELECT count(*) FROM academic.student_attendance WHERE session_id = $1", e.sessionOn(id, "2026-10-19"))
	if n != 2 {
		t.Fatalf("bản ghi điểm danh: %d", n)
	}
	var sessionStatus string
	e.scalar(&sessionStatus, "SELECT status FROM academic.class_sessions WHERE id = $1", e.sessionOn(id, "2026-10-19"))
	if sessionStatus != "completed" {
		t.Fatalf("buổi sau điểm danh: %s", sessionStatus)
	}
	var started *time.Time
	e.scalar(&started, "SELECT study_started_on FROM academic.students WHERE id = $1", s2)
	if started == nil || started.Format(time.DateOnly) != "2026-10-19" {
		t.Fatalf("ngày bắt đầu học: %v", started)
	}

	// Giáo viên ngoài khung ±24h; Học vụ không điểm danh trước buổi học
	expectMessage(t, gv.do("POST", path, map[string]any{"date": "2026-10-21", "records": rec("present", "")}), 422,
		"Ngoài khung ±24h so với giờ bắt đầu buổi học — liên hệ Học vụ để điểm danh.")
	expectMessage(t, hv.do("POST", path, map[string]any{"date": "2026-10-21", "records": rec("present", "")}), 422,
		"Buổi học ngày 21/10/2026 chưa diễn ra — chưa điểm danh được.")
	expectMessage(t, gv.do("POST", path, map[string]any{"date": "2026-10-20", "records": rec("present", "")}), 422,
		"Lớp không có buổi học trong ngày này. Hãy chọn buổi cần điểm danh.")

	// Hai ngày sau: Học vụ điểm danh bù buổi 21/10
	e.svc.now = func() time.Time { return fixedNow.AddDate(0, 0, 3) }
	sid := e.sessionOn(id, "2026-10-21")
	expectMessage(t, hv.do("POST", path, map[string]any{"session_id": sid, "records": rec("late", "")}), 200,
		"Đã lưu điểm danh 2 học viên lớp Lớp ĐD buổi ngày 21/10/2026 (điểm danh bù).")
	r = admin.do("GET", "/classes/"+id.String()+"?tab=attendance", nil)
	expect(t, r, 200, "")
	if rows := list(r, "attendance_rows"); len(rows) == 0 {
		t.Fatalf("tab điểm danh: %v", r.body)
	}
	expect(t, gv.do("GET", path+"?session="+uuid.NewString(), nil), 404, "session_not_found")
}

func TestCatalogHooks(t *testing.T) {
	e := setup(t)
	ctx := context.Background()
	a := e.branch("CN-A")
	adminM := e.member("MES001", nil, iam.RoleAdmin)
	admin := e.as(adminM)
	acc := e.access(adminM)
	studying, planned := e.room(a, "P201"), e.room(a, "P202")
	c1 := e.newClass(admin, map[string]any{"name": "Đang học", "code": "DH", "branch_id": a, "room_id": studying})
	expectMessage(t, admin.do("POST", "/schedule-config", weekly(c1, nil)), 200, "Đã lưu lịch và tạo 8 buổi học thực tế.")
	c2 := e.newClass(admin, map[string]any{"name": "Chưa học", "code": "CH", "branch_id": a, "room_id": planned})

	// Danh sách phòng hiện lớp đang dùng
	rooms, err := e.cat.ListRooms(ctx, acc, catalogs.RoomFilter{})
	if err != nil {
		t.Fatal(err)
	}
	for _, r := range rooms.Rooms.Items {
		if r.Name == "P201" && (len(r.Classes) != 1 || !r.Classes[0].Studying || r.Classes[0].Code != "DH") {
			t.Fatalf("lớp dùng phòng P201: %+v", r.Classes)
		}
	}

	// Đổi tên phòng: lớp và buổi sắp tới theo tên mới
	if _, err := e.cat.UpdateRoom(ctx, acc, studying, catalogs.RoomInput{Name: "P201A", RoomTypeID: &e.roomType}); err != nil {
		t.Fatal(err)
	}
	var stale int
	e.scalar(&stale, "SELECT count(*) FROM academic.class_sessions WHERE class_id = $1 AND room = 'P201'", c1)
	if strp(e.classField(c1, "room")) != "P201A" || stale != 0 {
		t.Fatalf("đổi tên phòng: lớp %s, buổi còn tên cũ %d", strp(e.classField(c1, "room")), stale)
	}

	// Xóa phòng: lớp đang học chặn; lớp chưa học thì gỡ phòng
	if _, err := e.cat.DeleteRoom(ctx, acc, studying); err == nil || err.Error() == "" {
		t.Fatal("xóa phòng lớp đang học phải bị chặn")
	} else if ie, ok := err.(*iam.Error); !ok || ie.Message != "Không thể xóa: P201A đang được lớp Đang học sử dụng." {
		t.Fatalf("lỗi xóa phòng: %v", err)
	}
	msg, err := e.cat.DeleteRoom(ctx, acc, planned)
	if err != nil {
		t.Fatal(err)
	}
	if msg != "Đã xóa P202. Đã bỏ liên kết phòng khỏi lớp Chưa học." || e.classField(c2, "room_id") != nil {
		t.Fatalf("xóa phòng lớp chưa học: %q", msg)
	}

	// Khóa học đang có lớp không xóa được; trình độ đếm lớp
	var course uuid.UUID
	e.scalar(&course, "INSERT INTO academic.courses (code, name, tuition_fee, total_lessons) VALUES ('CS0001', 'IELTS Foundation', 1000000, 24) RETURNING id")
	e.newClass(admin, map[string]any{"name": "Theo khóa", "code": "TK", "branch_id": a})
	if _, err := e.cat.DeleteCourse(ctx, acc, course); err == nil {
		t.Fatal("xóa khóa học đang có lớp phải bị chặn")
	} else if ie, ok := err.(*iam.Error); !ok || ie.Message != "Không thể xóa khóa học 'IELTS Foundation' vì đang có 1 lớp học liên kết!" {
		t.Fatalf("lỗi xóa khóa học: %v", err)
	}
	e.exec("INSERT INTO academic.course_levels (code, name, target, lessons_count) VALUES ('B1', 'Trung cấp', 'Giao tiếp', 24)")
	levels, err := e.cat.ListLevels(ctx, catalogs.LevelFilter{})
	if err != nil {
		t.Fatal(err)
	}
	if len(levels.Items) != 1 || levels.Items[0].ClassesCount != 3 {
		t.Fatalf("số lớp theo trình độ: %+v", levels.Items)
	}

	// Ca lệch khung giờ
	e.exec("UPDATE academic.class_sessions SET start_time = '17:00', end_time = '18:30' WHERE id = $1", e.sessionOn(c1, "2026-10-21"))
	shifts, err := e.cat.ListShifts(ctx, acc)
	if err != nil {
		t.Fatal(err)
	}
	if len(shifts.OffScheduleClasses) != 1 {
		t.Fatalf("lớp lệch khung giờ: %+v", shifts.OffScheduleClasses)
	}

	// Order học liệu: lớp chọn được / tồn tại
	opts, err := e.svc.ClassOptions(ctx, acc)
	if err != nil || len(opts) != 3 {
		t.Fatalf("lớp cho order: %v %v", opts, err)
	}
	if ok, err := e.svc.ClassExists(ctx, c1); err != nil || !ok {
		t.Fatalf("ClassExists: %v %v", ok, err)
	}
	if ok, _ := e.svc.ClassExists(ctx, uuid.New()); ok {
		t.Fatal("lớp không tồn tại")
	}
}
