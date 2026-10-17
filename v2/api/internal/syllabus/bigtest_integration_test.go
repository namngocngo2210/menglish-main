package syllabus_test

import (
	"strings"
	"testing"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam/iamtest"
)

func ptrf(f float64) *float64 { return &f }

// Giao chặng → Big Test cuối chặng → nhập / duyệt / gửi phụ huynh → tự đóng chặng và mở chặng kế.
func TestStageProgressionByBigTest(t *testing.T) {
	e := setup(t)
	a, b := e.Branch("CN-A"), e.Branch("CN-B")
	_, admin := e.Member("AD001", "Quản trị", nil, iam.RoleAdmin)
	_, lead := e.Member("HT001", "Học thuật", []uuid.UUID{a}, iam.RoleAcademicLead)
	gv, teacher := e.Member("GV001", "Giáo viên A", []uuid.UUID{a}, iam.RoleTeacherFulltime)
	_, otherTeacher := e.Member("GV002", "Giáo viên B", []uuid.UUID{b}, iam.RoleTeacherFulltime)
	_, mgrB := e.Member("QL002", "Quản lý B", []uuid.UUID{b}, iam.RoleManager)

	cur, stages := e.curriculum(lead, "CUR-ST", 2)
	e.level("STA", uuid.MustParse(cur))
	class := e.class("ST-01", a, &gv.ID, "STA")
	s1 := e.student("HV001", "An", a, class, "0912345678")
	s2 := e.student("HV002", "Bình", a, class, "")
	classID := class.String()

	// Mở chặng: chỉ syllabus.manage; Quản lý chi nhánh khác không thấy lớp.
	iamtest.Expect(t, teacher.Do("POST", "/syllabus/assignments", map[string]any{"class_id": classID}), 403, "")
	iamtest.Expect(t, mgrB.Do("POST", "/syllabus/assignments", map[string]any{"class_id": classID}), 404, "class_not_found")
	expectMsg(t, lead.Do("POST", "/syllabus/assignments", map[string]any{"class_id": classID, "start_date": "2099-01-01"}), 422,
		"Ngày bắt đầu không được quá 60 ngày kể từ hôm nay.")
	r := lead.Do("POST", "/syllabus/assignments", map[string]any{"class_id": classID})
	expectMsg(t, r, 201, "Đã mở Chặng 1 cho lớp Lớp ST-01.")
	if got := e.notified("syllabus_stage"); got != "GV001" {
		t.Fatalf("báo GV khi mở chặng: %q", got)
	}
	expectMsg(t, lead.Do("POST", "/syllabus/assignments", map[string]any{"class_id": classID, "stage_id": stages[1], "replace_current": true}),
		422, "Vui lòng nhập lý do chuyển chặng.")
	ts := teacher.Do("GET", "/syllabus/teaching-stages", nil)
	if as := list(ts.Body["assignments"]); len(as) != 1 || str(as[0]["role_label"]) != "Giáo viên chính" || str(as[0]["exam"]) != "none" {
		t.Fatalf("chặng đang dạy của GV: %s", ts.Raw)
	}
	if as := list(otherTeacher.Do("GET", "/syllabus/teaching-stages", nil).Body["assignments"]); len(as) != 0 {
		t.Fatalf("GV khác lớp thấy chặng: %v", as)
	}

	// Tạo đợt thi (bản nháp) → mặc định gắn chặng đang mở.
	expectMsg(t, lead.Do("POST", "/syllabus/big-tests", map[string]any{"title": "Big Test 1", "class_id": classID, "test_type": "stage_end",
		"scheduled_at": "2026-10-10T08:00"}), 422, "Vui lòng nhập phòng thi.")
	r = lead.Do("POST", "/syllabus/big-tests", map[string]any{"title": "Big Test 1", "class_id": classID, "test_type": "stage_end",
		"scheduled_at": "2026-10-10T08:00", "room": "P101"})
	expectMsg(t, r, 201, "cần duyệt trước khi phân phối")
	if !strings.Contains(str(r.Body["message"]), "(BT-") {
		t.Fatalf("mã đợt thi: %s", r.Raw)
	}
	testID := e.id(`SELECT id FROM syllabus.big_tests WHERE class_id = $1`, class).String()
	if e.text(`SELECT stage_id::text FROM syllabus.big_tests WHERE id = $1`, testID) != stages[0] {
		t.Fatal("đợt thi chưa gắn chặng đang mở")
	}

	// Danh sách: GV lớp thấy, passcode che khi chưa phân phối; GV lớp khác không thấy.
	d := teacher.Do("GET", "/syllabus/big-tests/distribution", nil)
	tests := list(d.Body["big_tests"])
	if len(tests) != 1 || str(tests[0]["passcode"]) != "••••••" || tests[0]["content_url"] != nil {
		t.Fatalf("GV xem đợt thi: %s", d.Raw)
	}
	if n := len(list(otherTeacher.Do("GET", "/syllabus/big-tests/distribution", nil).Body["big_tests"])); n != 0 {
		t.Fatalf("GV lớp khác thấy %d đợt thi", n)
	}
	iamtest.Expect(t, otherTeacher.Do("GET", "/syllabus/big-tests/results?test_id="+testID, nil), 404, "big_test_not_found")
	// Phạm vi chi nhánh: thấy đợt thi của lớp thuộc chi nhánh mình.
	e.Revoke(iam.RoleTeacherParttime, "big_test.scope_own")
	e.Grant(iam.RoleTeacherParttime, "big_test.scope_branch")
	_, partA := e.Member("GV003", "GV bán thời gian A", []uuid.UUID{a}, iam.RoleTeacherParttime)
	_, partB := e.Member("GV004", "GV bán thời gian B", []uuid.UUID{b}, iam.RoleTeacherParttime)
	if n := len(list(partA.Do("GET", "/syllabus/big-tests/distribution", nil).Body["big_tests"])); n != 1 {
		t.Fatalf("phạm vi chi nhánh A thấy %d đợt thi", n)
	}
	if n := len(list(partB.Do("GET", "/syllabus/big-tests/schedules", nil).Body["big_tests"])); n != 0 {
		t.Fatalf("phạm vi chi nhánh B thấy %d đợt thi", n)
	}

	// Chưa phân phối: GV chưa nhập được kết quả. Học thuật (không phải Admin) không duyệt được.
	iamtest.Expect(t, teacher.Do("POST", "/syllabus/big-tests/"+testID+"/results", map[string]any{"results": []any{}}), 422, "invalid")
	iamtest.Expect(t, lead.Do("POST", "/syllabus/big-tests/"+testID+"/approve", nil), 403, "admin_only")
	expectMsg(t, admin.Do("POST", "/syllabus/big-tests/"+testID+"/approve", nil), 200, "Đã duyệt và phân phối đề BT-")
	tests = list(teacher.Do("GET", "/syllabus/big-tests/distribution", nil).Body["big_tests"])
	if str(tests[0]["passcode"]) == "••••••" || !strings.HasPrefix(str(tests[0]["passcode"]), "MEN") {
		t.Fatalf("GV chính xem passcode sau khi phân phối: %v", tests[0])
	}

	// Nhập kết quả.
	res := func(st uuid.UUID, l, rd, w, s *float64, absent bool) map[string]any {
		return map[string]any{"student_id": st.String(), "listening_score": l, "reading_score": rd, "writing_score": w,
			"speaking_score": s, "is_absent": absent}
	}
	expectMsg(t, teacher.Do("POST", "/syllabus/big-tests/"+testID+"/results", map[string]any{"results": []any{
		res(s1, ptrf(8), nil, nil, nil, false)}}), 422, "Gửi duyệt cần nhập đủ 4 kỹ năng")
	expectMsg(t, teacher.Do("POST", "/syllabus/big-tests/"+testID+"/results", map[string]any{"results": []any{
		res(s1, ptrf(8), ptrf(7), nil, nil, true)}, "action": "draft"}), 422, "Vắng thi")
	expectMsg(t, teacher.Do("POST", "/syllabus/big-tests/"+testID+"/results", map[string]any{"results": []any{
		res(s1, ptrf(11), ptrf(7), ptrf(7), ptrf(7), false)}}), 422, "từ 0 đến 10")
	outsider := e.student("HV009", "Người ngoài", a, e.class("ST-02", a, nil, ""), "0900")
	expectMsg(t, teacher.Do("POST", "/syllabus/big-tests/"+testID+"/results", map[string]any{"results": []any{
		res(outsider, ptrf(8), ptrf(8), ptrf(8), ptrf(8), false)}}), 422, "Học viên không thuộc lớp thi.")
	expectMsg(t, teacher.Do("POST", "/syllabus/big-tests/"+testID+"/results", map[string]any{"results": []any{}}), 422,
		"Chưa nhập điểm (hoặc đánh dấu vắng thi) cho học viên nào.")
	expectMsg(t, teacher.Do("POST", "/syllabus/big-tests/"+testID+"/results", map[string]any{"action": "draft", "results": []any{
		res(s1, ptrf(8), ptrf(7), nil, nil, false)}}), 200, "Đã lưu nháp kết quả 1 học viên")
	expectMsg(t, teacher.Do("POST", "/syllabus/big-tests/"+testID+"/results", map[string]any{"results": []any{
		res(s1, ptrf(8), ptrf(7), ptrf(9), ptrf(6.5), false), res(s2, nil, nil, nil, nil, true)}}), 200, "Đã gửi duyệt kết quả 2 học viên")
	if got := e.text(`SELECT overall_score::text FROM syllabus.big_test_results WHERE student_id = $1`, s1); got != "7.6" {
		t.Fatalf("điểm tổng = %s, muốn 7.6", got)
	}

	page := lead.Do("GET", "/syllabus/big-tests/results?test_id="+testID, nil)
	iamtest.Expect(t, page, 200, "")
	if st := obj(page.Body["stats"]); st["taken"] != 1.0 || st["absent"] != 1.0 || st["avg"] != 7.6 {
		t.Fatalf("thống kê: %v", st)
	}
	if page.Body["can_approve"] != false || page.Body["is_approver"] != true {
		t.Fatalf("Học thuật không phải Admin: %s", page.Raw)
	}

	// Admin duyệt, gửi phụ huynh (sandbox). Học viên vắng thi chỉ cần duyệt.
	iamtest.Expect(t, lead.Do("POST", "/syllabus/big-tests/"+testID+"/results/approve", nil), 403, "admin_only")
	expectMsg(t, admin.Do("POST", "/syllabus/big-tests/"+testID+"/results/approve", nil), 200, "Đã duyệt kết quả Big Test")
	expectMsg(t, teacher.Do("POST", "/syllabus/big-tests/"+testID+"/results", map[string]any{"results": []any{
		res(s1, ptrf(9), ptrf(9), ptrf(9), ptrf(9), false)}}), 422, "Không thể sửa điểm đã được duyệt/đã gửi phụ huynh: An.")
	iamtest.Expect(t, teacher.Do("POST", "/syllabus/big-tests/"+testID+"/send-zalo", nil), 403, "")
	r = lead.Do("POST", "/syllabus/big-tests/"+testID+"/send-zalo", nil)
	expectMsg(t, r, 200, "đã gửi Zalo ZNS 1 phụ huynh")
	if !strings.Contains(str(r.Body["message"]), "Đã đóng Chặng 1 và tự mở Chặng 2") {
		t.Fatalf("đóng chặng sau khi gửi đủ: %s", r.Raw)
	}
	if e.text(`SELECT stage_id::text FROM syllabus.assignments WHERE class_id = $1 AND status = 'in_progress'`, class) != stages[1] {
		t.Fatal("chưa mở chặng kế")
	}
	if e.count(`SELECT count(*) FROM syllabus.big_tests WHERE id = $1 AND results_completed_at IS NOT NULL`, testID) != 1 {
		t.Fatal("chưa đánh dấu đã trả đủ kết quả")
	}
	expectMsg(t, lead.Do("POST", "/syllabus/big-tests/"+testID+"/send-zalo", nil), 422, "Không có kết quả đã duyệt nào chưa gửi phụ huynh.")
	// Nhật ký
	if e.count(`SELECT count(*) FROM core.audit_log WHERE module = 'Giáo trình & Syllabus' AND action = 'big_test.distribute'`) != 1 {
		t.Fatal("thiếu nhật ký phân phối đề")
	}
}

// Học viên thiếu SĐT phụ huynh: Duyệt & gửi từng em báo chưa gửi được.
func TestApproveAndSendSingle(t *testing.T) {
	e := setup(t)
	a := e.Branch("CN-A")
	_, admin := e.Member("AD001", "Quản trị", nil, iam.RoleAdmin)
	gv, teacher := e.Member("GV001", "Giáo viên A", []uuid.UUID{a}, iam.RoleTeacherFulltime)
	class := e.class("SG-01", a, &gv.ID, "")
	s1 := e.student("HV001", "An", a, class, "")
	r := admin.Do("POST", "/syllabus/big-tests", map[string]any{"title": "Giữa kỳ", "class_id": class.String(), "test_type": "midterm",
		"scheduled_at": "2026-10-01T08:00", "room": "P1"})
	iamtest.Expect(t, r, 201, "")
	testID := e.id(`SELECT id FROM syllabus.big_tests WHERE class_id = $1`, class).String()
	iamtest.Expect(t, admin.Do("POST", "/syllabus/big-tests/"+testID+"/approve", nil), 200, "")
	iamtest.Expect(t, teacher.Do("POST", "/syllabus/big-tests/"+testID+"/results", map[string]any{"results": []any{map[string]any{
		"student_id": s1.String(), "listening_score": 5, "reading_score": 5, "writing_score": 5, "speaking_score": 5}}}), 200, "")
	resultID := e.id(`SELECT id FROM syllabus.big_test_results WHERE student_id = $1`, s1).String()
	p := admin.Do("GET", "/syllabus/big-tests/results?test_id="+testID+"&result="+resultID, nil)
	if obj(p.Body["selected_result"])["status"] != "pending_review" || str(obj(p.Body["test"])["stage_label"]) != "Big Test - Giữa kỳ" {
		t.Fatalf("kết quả đang xét: %s", p.Raw)
	}
	r = admin.Do("POST", "/syllabus/big-test-results/"+resultID+"/approve-send", nil)
	expectMsg(t, r, 200, "chưa gửi được: Thiếu SĐT phụ huynh")
	if r.Body["tone"] != "warning" {
		t.Fatalf("muốn cảnh báo: %s", r.Raw)
	}
	expectMsg(t, admin.Do("POST", "/syllabus/big-test-results/"+resultID+"/send-zalo", nil), 422, "Thiếu SĐT phụ huynh")
	e.exec(`UPDATE academic.students SET parent_phone = '0987654321' WHERE id = $1`, s1)
	expectMsg(t, admin.Do("POST", "/syllabus/big-test-results/"+resultID+"/send-zalo", nil), 200, "thành công")
	expectMsg(t, admin.Do("POST", "/syllabus/big-test-results/"+resultID+"/send-zalo", nil), 422, "đã được gửi phụ huynh trước đó")
}
