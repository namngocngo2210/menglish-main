package syllabus_test

import (
	"context"
	"strings"
	"testing"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam/iamtest"
)

// GV order đề cho chặng đang mở → Admin duyệt (tự tạo đợt thi) / từ chối.
func TestOrderTest(t *testing.T) {
	e := setup(t)
	a, b := e.Branch("CN-A"), e.Branch("CN-B")
	_, admin := e.Member("AD001", "Quản trị", nil, iam.RoleAdmin)
	_, lead := e.Member("HT001", "Học thuật", []uuid.UUID{a}, iam.RoleAcademicLead)
	gv, teacher := e.Member("GV001", "Giáo viên A", []uuid.UUID{a}, iam.RoleTeacherFulltime)
	_, otherTeacher := e.Member("GV002", "Giáo viên B", []uuid.UUID{b}, iam.RoleTeacherFulltime)
	_, sales := e.Member("TV001", "Tư vấn", []uuid.UUID{a}, iam.RoleSalesConsultant)

	cur, _ := e.curriculum(lead, "CUR-OR", 2)
	e.level("ORD", uuid.MustParse(cur))
	class := e.class("OR-01", a, &gv.ID, "ORD")
	path := "/teacher/order-test/" + class.String()

	iamtest.Expect(t, sales.Do("GET", path, nil), 403, "")
	iamtest.Expect(t, otherTeacher.Do("GET", path, nil), 403, "forbidden")
	expectMsg(t, teacher.Do("POST", path, map[string]any{"test_type": "big"}), 422, "Lớp chưa được giao chặng nào đang mở")
	iamtest.Expect(t, lead.Do("POST", "/syllabus/assignments", map[string]any{"class_id": class.String()}), 201, "")
	expectMsg(t, teacher.Do("POST", path, map[string]any{"test_type": "final"}), 422, "Loại đề không hợp lệ.")
	expectMsg(t, teacher.Do("POST", path, map[string]any{"test_type": "big", "exam_date": "2020-01-01"}), 422, "Ngày thi phải từ hôm nay trở đi.")
	exam := vnNow().AddDate(0, 0, 10).Format(time.DateOnly)
	r := teacher.Do("POST", path, map[string]any{"test_type": "big", "exam_date": exam, "note": "Đề có phần nghe"})
	expectMsg(t, r, 201, "Đã gửi yêu cầu đề test tới Ban Học thuật.")
	orderID := newID(t, r)
	expectMsg(t, teacher.Do("POST", path, map[string]any{"test_type": "big"}), 422, "Đã có order đề cho Chặng 1 đang chờ Học thuật duyệt.")
	if got := e.notified("big_test_order"); got != "AD001" {
		t.Fatalf("báo người duyệt order: %q", got)
	}
	// Hạn xử lý = ngày thi − 3 ngày (SLA big_test.paper_approval mặc định).
	due := e.text(`SELECT due_date::text FROM syllabus.big_test_orders WHERE id = $1`, orderID)
	if want := vnNow().AddDate(0, 0, 7).Format(time.DateOnly); due != want {
		t.Fatalf("hạn xử lý = %s, muốn %s", due, want)
	}
	p := teacher.Do("GET", path, nil)
	reqs := list(p.Body["requests"])
	if len(reqs) != 1 || str(reqs[0]["status_label"]) != "Đã order - Chờ HT duyệt" || str(obj(p.Body["open_assignment"])["label"]) != "Chặng 1" {
		t.Fatalf("màn order đề: %s", p.Raw)
	}

	// Danh sách order trong phạm vi: GV lớp khác không thấy, Học thuật thấy chi tiết.
	if n := len(list(otherTeacher.Do("GET", "/syllabus/big-tests/distribution", nil).Body["orders"])); n != 0 {
		t.Fatalf("GV lớp khác thấy %d order", n)
	}
	iamtest.Expect(t, otherTeacher.Do("GET", "/syllabus/big-tests/distribution?order="+orderID, nil), 404, "")
	d := lead.Do("GET", "/syllabus/big-tests/distribution?order="+orderID, nil)
	sel := obj(d.Body["selected_order"])
	if d.Body["pending_orders"] != 1.0 || sel["reviewing"] != false || !strings.Contains(str(sel["summary"]), "Đề Big Test cho Chặng 1.") {
		t.Fatalf("chi tiết order (Học thuật): %s", d.Raw)
	}
	if sel = obj(admin.Do("GET", "/syllabus/big-tests/distribution?order="+orderID, nil).Body["selected_order"]); sel["reviewing"] != true ||
		str(sel["default_scheduled_at"]) != exam+"T08:00" {
		t.Fatalf("chi tiết order (Admin): %v", sel)
	}

	// Duyệt: chỉ Admin; bắt buộc link đề; order Big Test chưa gắn đợt thi phải có giờ + phòng thi.
	approve := "/syllabus/big-test-orders/" + orderID + "/approve"
	iamtest.Expect(t, lead.Do("POST", approve, map[string]any{"test_link": "https://de.example/1"}), 403, "admin_only")
	expectMsg(t, admin.Do("POST", approve, map[string]any{}), 422, "Vui lòng nhập link đề trước khi phê duyệt.")
	expectMsg(t, admin.Do("POST", approve, map[string]any{"test_link": "https://de.example/1"}), 422, "Vui lòng chọn ngày giờ thi")
	expectMsg(t, admin.Do("POST", approve, map[string]any{"test_link": "https://de.example/1", "big_test_id": uuid.NewString()}), 422,
		"Đợt Big Test không thuộc lớp của order.")
	r = admin.Do("POST", approve, map[string]any{"test_link": "https://de.example/1", "speaking_link": "https://de.example/speak",
		"scheduled_at": exam + "T09:00", "room": "P201"})
	expectMsg(t, r, 200, "Đã duyệt và phân phối đề cho order ORDTEST-")
	if !strings.Contains(str(r.Body["message"]), "Đã tạo đợt thi BT-") || !strings.Contains(str(r.Body["message"]), "tại P201") {
		t.Fatalf("thông báo tạo đợt thi: %s", r.Raw)
	}
	var title, content string
	var distributed bool
	if err := e.Pool.QueryRow(context.Background(), `SELECT title, content_url, is_distributed FROM syllabus.big_tests WHERE class_id = $1`, class).
		Scan(&title, &content, &distributed); err != nil {
		t.Fatal(err)
	}
	if title != "Big Test Chặng 1 · Lớp OR-01" || content != "https://de.example/1" || !distributed {
		t.Fatalf("đợt thi tự tạo: %q %q %v", title, content, distributed)
	}
	if got := e.notified("syllabus_review"); got != "GV001" {
		t.Fatalf("báo GV order đã duyệt: %q", got)
	}
	expectMsg(t, admin.Do("POST", approve, map[string]any{"test_link": "https://de.example/1"}), 422, "Yêu cầu này đã được xử lý trước đó.")
	reqs = list(teacher.Do("GET", path, nil).Body["requests"])
	if reqs[0]["test_link"] != nil || str(reqs[0]["speaking_link"]) != "https://de.example/speak" {
		t.Fatalf("GV chỉ thấy link Speaking: %v", reqs[0])
	}

	// Từ chối order Mini Test: bắt buộc lý do.
	r = teacher.Do("POST", path, map[string]any{"test_type": "mini"})
	iamtest.Expect(t, r, 201, "")
	reject := "/syllabus/big-test-orders/" + newID(t, r) + "/reject"
	expectMsg(t, admin.Do("POST", reject, map[string]any{"rejection_reason": " "}), 422, "Vui lòng nhập lý do từ chối.")
	expectMsg(t, admin.Do("POST", reject, map[string]any{"rejection_reason": "Trùng đề"}), 200, "Đã từ chối order")
	if n := e.count(`SELECT count(*) FROM core.notifications WHERE type = 'syllabus_review' AND title = 'Order đề bị từ chối'`); n != 1 {
		t.Fatalf("báo GV order bị từ chối: %d", n)
	}
}

// Giãn tiến độ: thêm đúng N buổi theo TKB, bỏ ngày nghỉ, lùi ngày kết thúc lớp; trùng lịch thì không duyệt.
func TestPacingExtendsSchedule(t *testing.T) {
	e := setup(t)
	a := e.Branch("CN-A")
	_, admin := e.Member("AD001", "Quản trị", nil, iam.RoleAdmin)
	_, lead := e.Member("HT001", "Học thuật", []uuid.UUID{a}, iam.RoleAcademicLead)
	gv, teacher := e.Member("GV001", "Giáo viên A", []uuid.UUID{a}, iam.RoleTeacherFulltime)
	_, other := e.Member("GV002", "Giáo viên B", []uuid.UUID{a}, iam.RoleTeacherFulltime)

	cur, _ := e.curriculum(lead, "CUR-PC", 1)
	e.level("PAC", uuid.MustParse(cur))
	class := e.class("PC-01", a, &gv.ID, "PAC")
	// TKB: Thứ 2 và Thứ 4, 18:00–19:30. Buổi cuối là thứ 2 tuần sau.
	e.exec(`INSERT INTO academic.class_slots (class_id, position, weekday, start_time, end_time, shift_name) VALUES
		($1, 1, 1, '18:00', '19:30', 'Ca tối'), ($1, 2, 3, '18:00', '19:30', 'Ca tối')`, class)
	today := time.Date(vnNow().Year(), vnNow().Month(), vnNow().Day(), 0, 0, 0, 0, time.UTC)
	last := today.AddDate(0, 0, 7)
	for last.Weekday() != time.Monday {
		last = last.AddDate(0, 0, 1)
	}
	e.exec(`INSERT INTO academic.class_sessions (class_id, branch_id, date, type, start_time, end_time, teacher_id)
		VALUES ($1, $2, $3, 'regular', '18:00', '19:30', $4)`, class, a, last, gv.ID)
	e.exec(`UPDATE academic.classes SET end_date = $2 WHERE id = $1`, class, last)

	body := map[string]any{"class_id": class.String(), "reason": "Lớp học chậm", "extra_sessions": 2}
	expectMsg(t, teacher.Do("POST", "/syllabus/adjustment-requests", body), 422, "không có chặng học nào đang mở")
	iamtest.Expect(t, lead.Do("POST", "/syllabus/assignments", map[string]any{"class_id": class.String()}), 201, "")
	iamtest.Expect(t, other.Do("POST", "/syllabus/adjustment-requests", body), 403, "forbidden")
	expectMsg(t, teacher.Do("POST", "/syllabus/adjustment-requests", map[string]any{"class_id": class.String(), "reason": "x", "extra_sessions": 11}),
		422, "Số buổi cần thêm từ 0 đến 10.")
	r := teacher.Do("POST", "/syllabus/adjustment-requests", body)
	expectMsg(t, r, 201, "Đã gửi yêu cầu điều chỉnh tiến độ giáo trình!")
	reqID := newID(t, r)
	if got := e.notified("adjustment_pending"); got != "AD001" {
		t.Fatalf("báo Admin yêu cầu mới: %q", got)
	}

	// Ngày nghỉ lễ rơi vào thứ 4 ngay sau buổi cuối → buổi thêm bỏ qua ngày đó.
	wed := last.AddDate(0, 0, 2)
	e.exec(`INSERT INTO core.holidays (code, name, start_date, end_date) VALUES ('HOL-T1', 'Nghỉ lễ', $1, $1)`, wed)
	// Lớp khác của GV đang chiếm thứ 2 tuần kế → trùng lịch, không duyệt được.
	busy := e.class("PC-99", a, &gv.ID, "")
	mon := last.AddDate(0, 0, 7)
	e.exec(`INSERT INTO academic.class_sessions (class_id, branch_id, date, type, start_time, end_time, teacher_id, room)
		VALUES ($1, $2, $3, 'regular', '18:30', '20:00', $4, 'P999')`, busy, a, mon, gv.ID)
	iamtest.Expect(t, lead.Do("POST", "/syllabus/adjustment-requests/"+reqID+"/approve", map[string]any{}), 403, "admin_only")
	expectMsg(t, admin.Do("POST", "/syllabus/adjustment-requests/"+reqID+"/approve", map[string]any{}), 422,
		"trùng lịch với lớp Lớp PC-99 (PC-99). Hãy xếp tay trên TKB.")
	if n := e.count(`SELECT count(*) FROM academic.class_sessions WHERE class_id = $1`, class); n != 1 {
		t.Fatalf("không được thêm buổi khi trùng lịch: %d buổi", n)
	}
	e.exec(`DELETE FROM academic.class_sessions WHERE class_id = $1`, busy)

	r = admin.Do("POST", "/syllabus/adjustment-requests/"+reqID+"/approve", map[string]any{})
	expectMsg(t, r, 200, "Đã thêm 2 buổi: ")
	want1, want2 := mon, mon.AddDate(0, 0, 2) // thứ 4 nghỉ lễ → thứ 2 và thứ 4 tuần kế
	if !strings.Contains(str(r.Body["message"]), want1.Format("02/01/2006")+", "+want2.Format("02/01/2006")) {
		t.Fatalf("ngày buổi thêm: %s", r.Raw)
	}
	if got := e.text(`SELECT end_date::text FROM academic.classes WHERE id = $1`, class); got != want2.Format(time.DateOnly) {
		t.Fatalf("ngày kết thúc lớp = %s, muốn %s", got, want2.Format(time.DateOnly))
	}
	if n := e.count(`SELECT extra_sessions FROM syllabus.assignments WHERE class_id = $1`, class); n != 2 {
		t.Fatalf("buổi giãn của chặng = %d", n)
	}
	expectMsg(t, admin.Do("POST", "/syllabus/adjustment-requests/"+reqID+"/reject", map[string]any{"rejection_reason": "x"}), 422,
		"Yêu cầu này đã được xử lý trước đó.")
}

// Nhắc lịch Big Test và SLA đề / kết quả (lệnh chạy nền), không gửi trùng.
func TestRemindersAndSLA(t *testing.T) {
	e := setup(t)
	ctx := context.Background()
	a := e.Branch("CN-A")
	e.User("AD001", "Quản trị", nil, iam.RoleAdmin)
	gv := e.User("GV001", "Giáo viên A", []uuid.UUID{a}, iam.RoleTeacherFulltime)
	class := e.class("RM-01", a, &gv.ID, "")
	e.student("HV001", "An", a, class, "0912")
	now := time.Now()
	// Đợt thi sau 2 ngày chưa phân phối đề; đợt thi sau 12 giờ chưa phân phối; đợt thi 10 ngày trước chưa trả kết quả.
	e.exec(`INSERT INTO syllabus.big_tests (code, title, class_id, test_type, scheduled_at, room) VALUES
		('BT-T-1', 'Sắp thi', $1, 'stage_end', $2, 'P1'), ('BT-T-2', 'Sát giờ', $1, 'stage_end', $3, 'P1'),
		('BT-T-3', 'Đã thi', $1, 'stage_end', $4, 'P1')`, class, now.Add(48*time.Hour), now.Add(12*time.Hour), now.AddDate(0, 0, -10))

	st, err := e.svc.RemindUpcoming(ctx)
	if err != nil {
		t.Fatal(err)
	}
	if st.Tests != 2 || st.TeacherNotes != 2 {
		t.Fatalf("nhắc lịch: %+v", st)
	}
	if got := e.notified("big_test_upcoming"); got != "AD001,AD001,GV001,GV001" {
		t.Fatalf("người nhận nhắc lịch: %q", got)
	}
	if got := e.count(`SELECT count(*) FROM core.notifications WHERE type = 'big_test_paper_due'`); got != 2 {
		t.Fatalf("nhắc Admin duyệt đề: %d", got)
	}
	if n := e.count(`SELECT count(*) FROM core.notifications WHERE type = 'big_test_paper_due' AND title LIKE 'Quá hạn duyệt đề (trước 3 ngày)%'`); n != 2 {
		t.Fatalf("đề còn dưới 3 ngày là quá hạn duyệt: %d", n)
	}
	if st, _ = e.svc.RemindUpcoming(ctx); st.Tests != 0 || st.PaperReminder != 0 {
		t.Fatalf("chạy lại không gửi trùng: %+v", st)
	}

	sla, err := e.svc.EnforceSLA(ctx, false)
	if err != nil {
		t.Fatal(err)
	}
	if sla.PaperMissing != 1 {
		t.Fatalf("GV chưa nhận đề: %+v", sla)
	}
	if got := e.text(`SELECT string_agg(u.code || ':' || n.title, '|' ORDER BY n.title) FROM core.notifications n JOIN iam.users u ON u.id = n.user_id
		WHERE n.type = 'sla_breach'`); got != "AD001:Quá hạn SLA: GV chưa nhận đề Big Test (BT-T-2)|GV001:Quá hạn SLA: Quá hạn trả kết quả Big Test (BT-T-3)" {
		t.Fatalf("thông báo SLA: %q", got)
	}
	if _, err := e.svc.EnforceSLA(ctx, false); err != nil {
		t.Fatal(err)
	}
	if n := e.count(`SELECT count(*) FROM core.notifications WHERE type = 'sla_breach'`); n != 2 {
		t.Fatalf("SLA không báo trùng: %d", n)
	}
}

// Đề xuất sửa giáo trình: GV gửi kèm file, chỉ Admin duyệt / từ chối.
func TestProposals(t *testing.T) {
	e := setup(t)
	a := e.Branch("CN-A")
	_, admin := e.Member("AD001", "Quản trị", nil, iam.RoleAdmin)
	_, lead := e.Member("HT001", "Học thuật", []uuid.UUID{a}, iam.RoleAcademicLead)
	_, teacher := e.Member("GV001", "Giáo viên A", []uuid.UUID{a}, iam.RoleTeacherFulltime)
	_, student := e.Member("HV001", "Học viên", nil, iam.RoleStudent)
	cur, _ := e.curriculum(lead, "CUR-PR", 1)

	fields := map[string]string{"curriculum_id": cur, "new_content": "Thêm bài nghe", "reason": "Học viên yếu nghe"}
	iamtest.Expect(t, student.Upload("/syllabus/proposals", fields, "", nil), 403, "")
	expectMsg(t, teacher.Upload("/syllabus/proposals", map[string]string{"curriculum_id": cur}, "", nil), 422,
		"Vui lòng nhập mô tả thay đổi đề xuất.")
	expectMsg(t, teacher.Upload("/syllabus/proposals", fields, "virus.exe", []byte("MZ")), 422, "")
	r := teacher.Upload("/syllabus/proposals", fields, "goi-y.pdf", append([]byte("%PDF-1.4\n"), make([]byte, 32)...))
	expectMsg(t, r, 201, "Đã gửi đề xuất sửa giáo trình tới Ban Học thuật.")
	id := newID(t, r)
	if got := e.notified("syllabus_proposal"); !strings.Contains(got, "HT001") {
		t.Fatalf("báo người duyệt đề xuất: %q", got)
	}
	v := lead.Do("GET", "/syllabus/versions?proposal="+id, nil)
	iamtest.Expect(t, v, 200, "")
	if v.Body["can_review"] != false || str(obj(v.Body["selected"])["attachment_url"]) == "" {
		t.Fatalf("màn duyệt đề xuất (Học thuật): %s", v.Raw)
	}
	iamtest.Expect(t, teacher.Get(str(obj(v.Body["selected"])["attachment_url"])), 302, "")
	iamtest.Expect(t, lead.Do("POST", "/syllabus/proposals/"+id+"/approve", map[string]any{}), 403, "admin_only")
	iamtest.Expect(t, teacher.Do("POST", "/syllabus/proposals/"+id+"/reject", map[string]any{"review_note": "x"}), 403, "")
	expectMsg(t, admin.Do("POST", "/syllabus/proposals/"+id+"/reject", map[string]any{"review_note": ""}), 422, "Vui lòng nhập lý do từ chối.")
	iamtest.Expect(t, admin.Do("POST", "/syllabus/proposals/"+id+"/approve", map[string]any{"review_note": "Đồng ý"}), 200, "")
	if got := e.notified("syllabus_review"); got != "GV001" {
		t.Fatalf("báo GV kết quả duyệt: %q", got)
	}
	if rows := list(teacher.Do("GET", "/syllabus/teacher-propose", nil).Body["proposals"]); len(rows) != 1 || str(rows[0]["status"]) != "approved" {
		t.Fatalf("đề xuất của tôi: %v", rows)
	}
}
