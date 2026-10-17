package teacher

import (
	"context"
	"strings"
	"testing"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/files"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam/iamtest"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/reports"
)

func TestAccess(t *testing.T) {
	e := setup(t)
	gvC := e.Login(e.gv)
	_, sales := e.Member("SALE01", "Tư vấn", []uuid.UUID{e.a}, iam.RoleSalesConsultant)
	_, manager := e.Member("QL001", "Quản lý A", []uuid.UUID{e.a}, iam.RoleManager)

	iamtest.Expect(t, e.Anon().Do("GET", "/teacher/home", nil), 401, "")
	for _, p := range []string{"/teacher/home", "/teacher/general-report", "/teacher/classes/" + e.c1.String() + "/homework",
		"/teacher/classes/" + e.c1.String() + "/scores", "/teacher/classes/" + e.c1.String() + "/remarks", "/teacher/submissions"} {
		iamtest.Expect(t, sales.Do("GET", p, nil), 403, "forbidden")
	}
	iamtest.Expect(t, sales.Do("POST", "/teacher/checkin", map[string]any{"session_ids": []string{e.s0.String()}}), 403, "forbidden")

	// Giáo viên: chỉ lớp mình được phân công
	iamtest.Expect(t, gvC.Do("GET", "/teacher/classes/"+e.c1.String()+"/homework", nil), 200, "")
	expectMsg(t, gvC.Do("GET", "/teacher/classes/"+e.c2.String()+"/homework", nil), 403, "Bạn không được phân công phụ trách lớp này.")
	expectMsg(t, gvC.Do("GET", "/teacher/classes/"+e.c2.String()+"/scores", nil), 403, "Bạn không được phân công phụ trách lớp này.")
	expectMsg(t, gvC.Do("POST", "/teacher/classes/"+e.c2.String()+"/remarks", map[string]any{"action": "final"}), 403,
		"Bạn không được phân công phụ trách lớp này.")
	iamtest.Expect(t, gvC.Do("GET", "/teacher/classes/"+uuid.NewString()+"/homework", nil), 404, "class_not_found")
	iamtest.Expect(t, gvC.Do("GET", "/teacher/classes/abc/homework", nil), 404, "not_found")

	// Quản lý (attendance_student.record_any, phạm vi lớp theo chi nhánh): làm thay lớp CN A, không làm được lớp CN B
	iamtest.Expect(t, manager.Do("GET", "/teacher/classes/"+e.c1.String()+"/homework", nil), 200, "")
	iamtest.Expect(t, manager.Do("GET", "/teacher/classes/"+e.c1.String()+"/remarks", nil), 200, "")
	expectMsg(t, manager.Do("GET", "/teacher/classes/"+e.c2.String()+"/homework", nil), 403, "Bạn không được phân công phụ trách lớp này.")

	// Chấm bài cần homework.grade
	iamtest.Expect(t, gvC.Do("GET", "/teacher/submissions", nil), 200, "")
	e.Revoke(iam.RoleTeacherFulltime, "homework.grade")
	iamtest.Expect(t, gvC.Do("GET", "/teacher/submissions", nil), 403, "forbidden")
	iamtest.Expect(t, gvC.Do("POST", "/teacher/submissions/"+uuid.NewString()+"/mark", map[string]any{}), 403, "forbidden")
	e.Grant(iam.RoleTeacherFulltime, "homework.grade")
	// Bỏ quyền điểm danh → mất cổng giáo viên
	e.Revoke(iam.RoleTeacherFulltime, "attendance_student.record")
	iamtest.Expect(t, gvC.Do("GET", "/teacher/home", nil), 403, "forbidden")
}

func TestHomeAndCheckin(t *testing.T) {
	e := setup(t)
	gvC := e.Login(e.gv)
	e.exec(`INSERT INTO academic.student_attendance (session_id, class_id, student_id, session_date, status, teacher_id)
		VALUES ($1, $2, $3, '2026-10-16', 'present', $4)`, e.sPast, e.c1, e.st1, e.gv.ID)

	r := gvC.Do("GET", "/teacher/home", nil)
	iamtest.Expect(t, r, 200, "")
	shifts := iamtest.List(t, r, "shifts")
	// Ca tối qua (CN 18/10 18:00, hạn check-in 18:00 hôm nay) + ca hôm nay
	if len(shifts) != 2 || shifts[0]["session_id"] != e.sYest.String() || shifts[1]["session_id"] != e.s0.String() {
		t.Fatalf("ca: %s", r.Raw)
	}
	if shifts[1]["is_today"] != true || shifts[1]["student_count"] != 2.0 || shifts[1]["room_label"] != "Phòng P101" ||
		shifts[1]["scheduled_time"] != "18:00 - 19:30" || shifts[1]["shift_name"] != "Ca 1" {
		t.Fatalf("ca hôm nay: %v", shifts[1])
	}
	pending := iamtest.List(t, r, "pending_sessions")
	if len(pending) != 1 || pending[0]["id"] != e.sYest.String() || pending[0]["date"] != "18/10/2026" {
		t.Fatalf("buổi chưa điểm danh: %v", pending)
	}
	if r.Body["can_checkin"] != false || r.Body["teacher_name"] != "Cô Hạnh" || r.Body["today_label"] != "Thứ Hai, 19/10" {
		t.Fatalf("home: %s", r.Raw)
	}
	w := r.Body["widgets"].(map[string]any)
	if w["work_pending"] != workPending {
		t.Fatalf("widgets: %v", w)
	}
	days := iamtest.List(t, r, "week_days")
	if len(days) != 7 || days[0]["label"] != "T2 · 19/10" || len(days[2]["sessions"].([]any)) != 1 {
		t.Fatalf("tuần: %v", days)
	}
	// Xem tuần trước
	r = gvC.Do("GET", "/teacher/home?week=2026-10-16", nil)
	if r.Body["week"].(map[string]any)["start"] != "2026-10-12" {
		t.Fatalf("tuần trước: %s", r.Raw)
	}

	expectMsg(t, gvC.Do("POST", "/teacher/checkin", map[string]any{"session_ids": []string{}}), 422, "Vui lòng chọn ít nhất 1 ca dạy để check-in.")
	expectMsg(t, gvC.Do("POST", "/teacher/checkin", map[string]any{"session_ids": []string{e.s2.String()}}), 422,
		"Không check-in được ca nào — Lớp B: không phải buổi được phân công cho bạn.")
	expectMsg(t, gvC.Do("POST", "/teacher/checkin", map[string]any{"session_ids": []string{e.sNext.String()}}), 422,
		"Không check-in được ca nào — Lớp Một: buổi học ngày 21/10/2026 chưa tới ngày — chưa check-in được.")
	expectMsg(t, gvC.Do("POST", "/teacher/checkin", map[string]any{"session_ids": []string{e.sPast.String()}}), 422,
		"Không check-in được ca nào — Lớp Một: Quá hạn chấm công (hạn 18:00 17/10/2026) — liên hệ Học vụ để được xác nhận công.")
	// Chưa có phân hệ chấm công
	iamtest.Expect(t, gvC.Do("POST", "/teacher/checkin", map[string]any{"session_ids": []string{e.s0.String()}}), 409, "work_pending")

	work := &fakeWork{}
	e.svc.work = work
	r = gvC.Do("POST", "/teacher/checkin", map[string]any{"session_ids": []string{e.s0.String(), e.s2.String(), e.s0.String()}})
	expectMsg(t, r, 200, "Đã check-in 1 ca dạy hôm nay.")
	if r.Body["warning"] != "Bỏ qua: Lớp B: không phải buổi được phân công cho bạn." || len(work.got) != 1 ||
		work.got[0].SessionID != e.s0 || work.got[0].LateMinutes != 0 || work.got[0].ClassName != "Lớp Một" {
		t.Fatalf("check-in: %s %+v", r.Raw, work.got)
	}
	expectMsg(t, gvC.Do("POST", "/teacher/checkin", map[string]any{"session_ids": []string{e.sYest.String()}}), 200,
		"Đã check-in thành công 1 ca dạy hôm nay!")
	if work.got[1].LateMinutes != 960 {
		t.Fatalf("đi muộn: %+v", work.got[1])
	}
	r = gvC.Do("GET", "/teacher/home", nil)
	if s := r.Body["stats"].(map[string]any); s["checked_in"] != 2.0 || s["total"] != 2.0 || r.Body["can_checkin"] != true {
		t.Fatalf("thống kê: %s", r.Raw)
	}
}

func TestHomework(t *testing.T) {
	e := setup(t)
	gvC := e.Login(e.gv)
	_, sales := e.Member("SALE01", "Tư vấn", []uuid.UUID{e.a}, iam.RoleSalesConsultant)
	base := "/teacher/classes/" + e.c1.String() + "/homework"

	r := gvC.Do("GET", base, nil)
	iamtest.Expect(t, r, 200, "")
	sessions := iamtest.List(t, r, "sessions")
	if len(sessions) != 4 || sessions[2]["label"] != "Buổi 3: 19/10 18:00" || r.Body["selected_session_id"] != e.s0.String() ||
		r.Body["due_default"] != "2026-10-22T20:00" || len(iamtest.List(t, r, "categories")) != 6 {
		t.Fatalf("trang giao bài: %s", r.Raw)
	}

	valid := map[string]any{"session_id": e.s0, "due_at": "2026-10-22T20:00", "categories": []string{"video", "workbook"},
		"items": map[string]string{"video": "Quay video đọc bài 3", "workbook": "Trang 12-13"}, "class_note": "Nhớ mang sách"}
	with := func(k string, v any) map[string]any {
		m := map[string]any{}
		for kk, vv := range valid {
			m[kk] = vv
		}
		if v == nil {
			delete(m, k)
		} else {
			m[k] = v
		}
		return m
	}
	expectMsg(t, gvC.Do("POST", base, with("categories", []string{})), 422, "Cần giao ít nhất 1 hạng mục.")
	expectMsg(t, gvC.Do("POST", base, with("items", map[string]string{"video": "  "})), 422, `Nhập chi tiết yêu cầu cho hạng mục "Quay video".`)
	expectMsg(t, gvC.Do("POST", base, with("session_id", nil)), 422, "Vui lòng chọn buổi học.")
	expectMsg(t, gvC.Do("POST", base, with("session_id", e.s2)), 422, "Buổi học không thuộc lớp này.")
	expectMsg(t, gvC.Do("POST", base, with("due_at", "")), 422, "Vui lòng chọn hạn nộp.")
	expectMsg(t, gvC.Do("POST", base, with("youtube_url", "khong-phai-link")), 422, "Link YouTube không hợp lệ.")
	expectMsg(t, gvC.Do("POST", base, with("categories", []string{"dance"})), 422, "Hạng mục bài tập không hợp lệ.")

	// File nghe: tải lên trước, gắn khi lưu bài
	iamtest.Expect(t, gvC.Upload(base+"/audio", nil, "a.mp3", pngBytes), 422, "invalid")
	up := gvC.Upload(base+"/audio", nil, "bai-nghe.mp3", mp3Bytes)
	iamtest.Expect(t, up, 201, "")
	audioID := up.Body["id"].(string)
	r = gvC.Do("POST", base, with("audio_file_id", audioID))
	expectMsg(t, r, 201, "Đã giao bài tập về nhà cho lớp!")
	hw := r.Body["id"].(string)

	r = gvC.Do("GET", base+"?edit="+hw, nil)
	rows := iamtest.List(t, r, "homeworks")
	ed := r.Body["editing"].(map[string]any)
	if len(rows) != 1 || rows[0]["title"] != "Bài tập buổi 19/10: Quay video, Workbook" || rows[0]["due_label"] != "20:00 22/10/2026" ||
		rows[0]["audio_url"] != "/api/v1/files/"+audioID || rows[0]["teacher_name"] != "Cô Hạnh" || rows[0]["deletable"] != true ||
		ed["has_audio"] != true || ed["due_at"] != "2026-10-22T20:00" || ed["session_id"] != e.s0.String() {
		t.Fatalf("bài đã giao: %s", r.Raw)
	}
	// File nghe: GV lớp mở được, người ngoài không
	if g := gvC.Get("/api/v1/files/" + audioID); g.Status != 302 {
		t.Fatalf("GV tải file nghe: %d %s", g.Status, g.Raw)
	}
	if g := sales.Get("/api/v1/files/" + audioID); g.Status != 403 {
		t.Fatalf("người ngoài tải file nghe: %d", g.Status)
	}

	// Học sinh nộp bài hạng mục Quay video → hạng mục bị khóa, bài không xóa được
	portal := e.svc.StudentPortal()
	if _, err := portal.Submit(context.Background(), nil, SubmitInput{StudentID: e.st1, HomeworkType: "video", Notes: "Con đã quay"}); err != nil {
		t.Fatal(err)
	}
	r = gvC.Do("PUT", base+"/"+hw, with("categories", []string{"workbook"}))
	expectMsg(t, r, 200, "Đã cập nhật bài tập về nhà!")
	r = gvC.Do("GET", base+"?edit="+hw, nil)
	ed = r.Body["editing"].(map[string]any)
	if items := ed["items"].(map[string]any); items["video"] != "Quay video đọc bài 3" || len(ed["locked"].([]any)) != 1 {
		t.Fatalf("hạng mục khóa: %s", r.Raw)
	}
	expectMsg(t, gvC.Do("DELETE", base+"/"+hw, nil), 409, "Bài tập đã có học sinh nộp bài, không xóa được.")

	// Bài thứ hai (chưa có bài nộp hạng mục Quiz) xóa được
	r = gvC.Do("POST", base, with("categories", []string{"quiz"}))
	r = gvC.Do("POST", base, map[string]any{"session_id": e.sNext, "due_at": "2026-10-23T20:00", "categories": []string{"quiz"},
		"items": map[string]string{"quiz": "Quizizz Unit 2"}})
	expectMsg(t, r, 201, "Đã giao bài tập về nhà cho lớp!")
	hw2 := r.Body["id"].(string)
	expectMsg(t, gvC.Do("DELETE", base+"/"+hw2, nil), 200, "Đã xóa bài tập về nhà.")
	iamtest.Expect(t, gvC.Do("DELETE", base+"/"+hw2, nil), 404, "not_found")
	iamtest.Expect(t, gvC.Do("GET", base+"?edit="+hw2, nil), 404, "not_found")
	// Bài của lớp khác không sửa được qua lớp này
	iamtest.Expect(t, gvC.Do("PUT", "/teacher/classes/"+e.c1.String()+"/homework/"+uuid.NewString(), valid), 404, "not_found")

	// Cổng học viên đọc bài tập của lớp
	list, err := portal.Homeworks(context.Background(), []uuid.UUID{e.c1}, 10)
	if err != nil || len(list) != 1 || len(list[0].Items) != 2 || list[0].ClassName != "Lớp Một" {
		t.Fatalf("bài tập cổng học viên: %+v %v", list, err)
	}
	var n int
	e.scalar(&n, "SELECT count(*) FROM core.audit_log WHERE subject_type = 'homework' AND subject_id = $1 AND action IN ('homework.create', 'homework.update')", hw)
	if n != 2 {
		t.Fatalf("nhật ký: %d", n)
	}
}

func TestScores(t *testing.T) {
	e := setup(t)
	gvC := e.Login(e.gv)
	base := "/teacher/classes/" + e.c1.String() + "/scores"
	r := gvC.Do("GET", base, nil)
	iamtest.Expect(t, r, 200, "")
	if r.Body["test_name"] != "Mini Test" || r.Body["test_date"] != "2026-10-19" || len(iamtest.List(t, r, "students")) != 2 ||
		len(iamtest.List(t, r, "skills")) != 4 {
		t.Fatalf("trang điểm: %s", r.Raw)
	}
	skills := map[string]string{"listening": "6", "speaking": "7", "reading": "5", "writing": "6"}
	in := func(over map[string]any) map[string]any {
		m := map[string]any{"name": "Mini Test", "student_id": e.st1, "skills": skills, "max_score": "10"}
		for k, v := range over {
			if v == nil {
				delete(m, k)
			} else {
				m[k] = v
			}
		}
		return m
	}
	expectMsg(t, gvC.Do("POST", base, in(map[string]any{"student_id": nil})), 422, "Vui lòng chọn học sinh.")
	expectMsg(t, gvC.Do("POST", base, in(map[string]any{"skills": map[string]string{"listening": "6"}})), 422, "Cần nhập đủ điểm 4 kỹ năng.")
	expectMsg(t, gvC.Do("POST", base, in(map[string]any{"skills": map[string]string{"listening": "11", "speaking": "7", "reading": "5", "writing": "6"}})),
		422, "Điểm kỹ năng không được vượt quá điểm tối đa (10).")
	expectMsg(t, gvC.Do("POST", base, in(map[string]any{"unit_id": uuid.New()})), 422, "Unit không thuộc giáo trình của lớp.")
	expectMsg(t, gvC.Do("POST", base, in(map[string]any{"name": " "})), 422, "Vui lòng chọn Unit bài học.")
	expectMsg(t, gvC.Do("POST", base, in(map[string]any{"student_id": e.st3})), 422, "Học viên không thuộc lớp này.")
	expectMsg(t, gvC.Do("POST", base, in(map[string]any{"max_score": "0"})), 422, "Điểm tối đa phải từ 1 đến 100.")

	r = gvC.Do("POST", base, in(nil))
	expectMsg(t, r, 200, "Đã lưu điểm Mini Test cho Bé An.")
	if r.Body["name"] != "Mini Test" || len(e.support.got) != 1 || e.support.got[0].Score != 6 || e.support.got[0].StudentID != e.st1 {
		t.Fatalf("lưu điểm: %s %+v", r.Raw, e.support.got)
	}
	// Sửa điểm (cùng bài) → cập nhật, không thêm dòng
	r = gvC.Do("POST", base, in(map[string]any{"skills": map[string]string{"listening": "8", "speaking": "8,5", "reading": "9", "writing": "8.5"}, "note": "Tiến bộ"}))
	expectMsg(t, r, 200, "Đã lưu điểm Mini Test cho Bé An.")
	r = gvC.Do("GET", base+"?name=Mini+Test&student_id="+e.st1.String(), nil)
	st := iamtest.List(t, r, "students")
	cur := r.Body["current"].(map[string]any)
	if r.Body["scored_count"] != 1.0 || st[0]["name"] != "Bé An" || st[0]["total"] != "8.5/10" || st[0]["low"] != false ||
		st[0]["skills"].(map[string]any)["speaking"] != "8.5" || st[1]["has_score"] != false || st[1]["skills"].(map[string]any)["reading"] != "—" ||
		cur["note"] != "Tiến bộ" || cur["skills"].(map[string]any)["reading"] != "9" {
		t.Fatalf("điểm đã nhập: %s", r.Raw)
	}
	// Bé Bình dưới 7/10 → "Học sinh cần chú ý" ở trang chủ
	expectMsg(t, gvC.Do("POST", base, in(map[string]any{"student_id": e.st2, "skills": map[string]string{"listening": "5", "speaking": "6", "reading": "4", "writing": "5"}})),
		200, "Đã lưu điểm Mini Test cho Bé Bình.")
	h := gvC.Do("GET", "/teacher/home", nil)
	att := h.Body["widgets"].(map[string]any)["attention"].([]any)
	if len(att) != 1 || att[0].(map[string]any)["student_name"] != "Bé Bình" || att[0].(map[string]any)["score"] != "5/10" {
		t.Fatalf("cần chú ý: %s", h.Raw)
	}
	tests, err := e.svc.StudentPortal().MiniTests(context.Background(), e.st2, 10)
	if err != nil || len(tests) != 1 || !tests[0].Low || tests[0].Skills["listening"] != 5 {
		t.Fatalf("mini test cổng học viên: %+v %v", tests, err)
	}
}

func TestRemarks(t *testing.T) {
	e := setup(t)
	gvC := e.Login(e.gv)
	base := "/teacher/classes/" + e.c1.String() + "/remarks"
	e.exec(`INSERT INTO academic.student_attendance (session_id, class_id, student_id, session_date, status) VALUES ($1, $2, $3, '2026-10-19', 'absent')`,
		e.s0, e.c1, e.st2)

	r := gvC.Do("GET", base, nil)
	iamtest.Expect(t, r, 200, "")
	sess := r.Body["session"].(map[string]any)
	students := iamtest.List(t, r, "students")
	if sess["id"] != e.s0.String() || sess["label"] != "Buổi 3: 19/10/2026 · 18:00-19:30" || r.Body["record_state"] != nil ||
		len(students) != 2 || students[1]["att_status"] != "absent" || students[1]["absent"] != true || students[0]["att_status"] != "none" ||
		len(iamtest.List(t, r, "recent_sessions")) != 3 {
		t.Fatalf("trang nhận xét: %s", r.Raw)
	}
	// Ngày không có buổi → không chọn được buổi
	r = gvC.Do("GET", base+"?date=2026-10-20", nil)
	if r.Status != 200 || r.Body["session"] != nil {
		t.Fatalf("ngày không có buổi: %s", r.Raw)
	}
	expectMsg(t, gvC.Do("POST", base, map[string]any{"date": "2026-10-20", "action": "final"}), 422,
		"Lớp không có buổi học trong ngày này. Hãy chọn buổi cần nhận xét.")
	expectMsg(t, gvC.Do("POST", base, map[string]any{"session_id": e.sNext, "action": "final"}), 422,
		"Buổi học ngày 21/10/2026 chưa diễn ra — chưa điểm danh được.")
	iamtest.Expect(t, gvC.Do("POST", base, map[string]any{"session_id": e.s2, "action": "final"}), 404, "session_not_found")
	expectMsg(t, gvC.Do("POST", base, map[string]any{"action": "publish"}), 422, "Thao tác không hợp lệ.")
	expectMsg(t, gvC.Do("POST", base, map[string]any{"remarks": map[string]any{e.st1.String(): map[string]string{"comment": strings.Repeat("a", 2001)}}}),
		422, "Nhận xét không được vượt quá 2000 ký tự.")

	remarks := map[string]any{
		e.st1.String(): map[string]string{"monsters_group": "Nhóm Rồng", "comment": "Con phát biểu tốt", "grammar": "Thì hiện tại"},
		e.st3.String(): map[string]string{"comment": "Không thuộc lớp"},
	}
	r = gvC.Do("POST", base, map[string]any{"session_id": e.s0, "action": "draft", "remarks": remarks})
	expectMsg(t, r, 200, "Đã lưu nháp nhận xét (chưa hiển thị cho học viên).")
	portal := e.svc.StudentPortal()
	if got, _ := portal.Remarks(context.Background(), e.st1, []uuid.UUID{e.c1}, 10); len(got) != 0 {
		t.Fatalf("nháp không hiện cho học viên: %+v", got)
	}
	r = gvC.Do("GET", base+"?session="+e.s0.String(), nil)
	students = iamtest.List(t, r, "students")
	if r.Body["record_state"] != "draft" || students[0]["remark"].(map[string]any)["comment"] != "Con phát biểu tốt" {
		t.Fatalf("nháp: %s", r.Raw)
	}
	// Lưu chính thức (không chọn buổi = buổi hôm nay)
	expectMsg(t, gvC.Do("POST", base, map[string]any{"action": "final", "remarks": remarks}), 200, "Đã lưu nhận xét buổi học!")
	got, err := portal.Remarks(context.Background(), e.st1, []uuid.UUID{e.c1}, 10)
	if err != nil || len(got) != 1 || got[0].Remark["monsters_group"] != "Nhóm Rồng" || got[0].Remark["attitude"] != "" {
		t.Fatalf("nhận xét học viên: %+v %v", got, err)
	}
	if other, _ := portal.Remarks(context.Background(), e.st3, []uuid.UUID{e.c1, e.c2}, 10); len(other) != 0 {
		t.Fatalf("học viên ngoài danh sách lớp: %+v", other)
	}
	r = gvC.Do("GET", base, nil)
	if r.Body["record_state"] != "saved" {
		t.Fatalf("đã lưu: %s", r.Raw)
	}
	// Buổi đã hủy
	e.exec("UPDATE academic.class_sessions SET status = 'cancelled' WHERE id = $1", e.sYest)
	expectMsg(t, gvC.Do("POST", base, map[string]any{"session_id": e.sYest, "action": "final"}), 422, "Buổi học này đã hủy — không điểm danh.")
}

func TestSubmissions(t *testing.T) {
	e := setup(t)
	gvC := e.Login(e.gv)
	gv2C := e.Login(e.gv2)
	_, admin := e.Member("MES001", "Admin", nil, iam.RoleAdmin)
	portal := e.svc.StudentPortal()
	ctx := context.Background()

	sub, err := portal.Submit(ctx, nil, SubmitInput{StudentID: e.st1, HomeworkType: "workbook", Notes: "Trang 12", Upload: upload(t, "bai-lam.png", pngBytes)})
	if err != nil || sub.AttachmentID == nil || sub.AttachmentName != "bai-lam.png" || sub.Status != "submitted" {
		t.Fatalf("nộp bài: %+v %v", sub, err)
	}
	if _, err := portal.Submit(ctx, nil, SubmitInput{StudentID: e.st3, HomeworkType: "workbook"}); err != nil {
		t.Fatal(err)
	}
	if _, err := portal.Submit(ctx, nil, SubmitInput{StudentID: e.st1, HomeworkType: "dance"}); err == nil {
		t.Fatal("loại bài lạ phải lỗi")
	}

	r := gvC.Do("GET", "/teacher/submissions?type=workbook", nil)
	iamtest.Expect(t, r, 200, "")
	list := iamtest.List(t, r, "submissions")
	if r.Body["current_class"].(map[string]any)["id"] != e.c1.String() || r.Body["active_tab"] != "workbook" ||
		len(iamtest.List(t, r, "classes")) != 1 || len(list) != 1 || list[0]["student_name"] != "Bé An" ||
		list[0]["attachment_url"] != "/api/v1/files/"+sub.AttachmentID.String() || list[0]["notes"] != "Trang 12" ||
		r.Body["pronunciation_pending"] != true || len(iamtest.List(t, r, "tabs")) != 7 {
		t.Fatalf("bài nộp: %s", r.Raw)
	}
	if r := gvC.Do("GET", "/teacher/submissions?type=xyz", nil); r.Body["active_tab"] != "video" || len(iamtest.List(t, r, "submissions")) != 0 {
		t.Fatalf("tab mặc định: %s", r.Raw)
	}
	expectMsg(t, gvC.Do("GET", "/teacher/submissions?class_id="+e.c2.String(), nil), 403, "Bạn không phụ trách lớp này.")
	// Phạm vi lớp toàn hệ thống → mọi lớp
	if r := admin.Do("GET", "/teacher/submissions", nil); len(iamtest.List(t, r, "classes")) != 2 {
		t.Fatalf("admin: %s", r.Raw)
	}

	mark := "/teacher/submissions/" + sub.ID.String() + "/mark"
	expectMsg(t, gvC.Do("POST", mark, map[string]any{"score": strings.Repeat("9", 21)}), 422, "Điểm không được vượt quá 20 ký tự.")
	expectMsg(t, gvC.Do("POST", mark, map[string]any{"feedback": strings.Repeat("a", 1001)}), 422, "Phản hồi không được vượt quá 1000 ký tự.")
	iamtest.Expect(t, gv2C.Do("POST", mark, map[string]any{"score": "9"}), 404, "not_found")
	iamtest.Expect(t, gvC.Do("POST", "/teacher/submissions/"+uuid.NewString()+"/mark", map[string]any{"score": "9"}), 404, "not_found")
	expectMsg(t, gvC.Do("POST", mark, map[string]any{"score": " 9/10 ", "feedback": "Làm tốt"}), 200, "Đã chấm điểm và gửi phản hồi bài nộp thành công!")
	got, err := portal.Submission(ctx, e.st1, sub.ID)
	if err != nil || got.Status != "reviewed" || got.Score != "9/10" || got.Feedback != "Làm tốt" || got.ReviewedAt == nil {
		t.Fatalf("đã chấm: %+v %v", got, err)
	}
	if _, err := portal.Submission(ctx, e.st2, sub.ID); err != ErrSubmissionNotFound {
		t.Fatalf("bài của học viên khác: %v", err)
	}
	// File bài nộp: người chấm lớp mở được; GV lớp khác không
	if g := gvC.Get("/api/v1/files/" + sub.AttachmentID.String()); g.Status != 302 {
		t.Fatalf("GV tải bài nộp: %d %s", g.Status, g.Raw)
	}
	if g := gv2C.Get("/api/v1/files/" + sub.AttachmentID.String()); g.Status != 403 {
		t.Fatalf("GV lớp khác tải bài nộp: %d", g.Status)
	}
	// Cổng học viên mở được qua SetFileAccess
	portal.SetFileAccess(func(_ context.Context, a *iam.Access, _ files.File) (bool, error) { return a.UserID == e.gv2.ID, nil })
	if g := gv2C.Get("/api/v1/files/" + sub.AttachmentID.String()); g.Status != 302 {
		t.Fatalf("cổng học viên mở file: %d", g.Status)
	}
	portal.SetFileAccess(nil)

	// Như Laravel: bài đã chấm vẫn sửa ghi chú được (giữ trạng thái và điểm); học viên khác thì không
	if got, err := portal.Update(ctx, nil, e.st1, sub.ID, ptr("Sửa"), nil); err != nil || got.Notes != "Sửa" ||
		got.Status != "reviewed" || got.Score != "9/10" {
		t.Fatalf("sửa bài đã chấm: %+v %v", got, err)
	}
	if _, err := portal.Update(ctx, nil, e.st2, sub.ID, ptr("Sửa"), nil); err != ErrSubmissionNotFound {
		t.Fatalf("sửa bài học viên khác: %v", err)
	}
	if owner, err := portal.SubmissionOwner(ctx, sub.ID); err != nil || owner != e.st1 {
		t.Fatalf("chủ bài nộp: %v %v", owner, err)
	}
	s2, _ := portal.Submit(ctx, nil, SubmitInput{StudentID: e.st1, HomeworkType: "video", Upload: upload(t, "a.png", pngBytes)})
	s2, err = portal.Update(ctx, nil, e.st1, s2.ID, ptr("Đã sửa"), upload(t, "b.png", pngBytes))
	if err != nil || s2.Notes != "Đã sửa" || s2.AttachmentName != "b.png" {
		t.Fatalf("sửa bài: %+v %v", s2, err)
	}
	if err := portal.Delete(ctx, nil, e.st1, s2.ID); err != nil {
		t.Fatal(err)
	}
	if err := portal.Delete(ctx, nil, e.st1, s2.ID); err != ErrSubmissionNotFound {
		t.Fatalf("xóa lại: %v", err)
	}
	all, _ := portal.Submissions(ctx, e.st1)
	if len(all) != 1 {
		t.Fatalf("bài nộp còn lại: %+v", all)
	}
}

func TestGeneralReport(t *testing.T) {
	e := setup(t)
	gvC := e.Login(e.gv)
	e.exec(`INSERT INTO academic.student_attendance (session_id, class_id, student_id, session_date, status, teacher_id)
		VALUES ($1, $2, $3, '2026-10-16', 'present', $4), ($1, $2, $5, '2026-10-16', 'late', $4)`, e.sPast, e.c1, e.st1, e.gv.ID, e.st2)
	e.exec(`INSERT INTO academic.mini_test_scores (class_id, student_id, teacher_id, name, score, max_score, test_date)
		VALUES ($1, $2, $3, 'Unit 1', 8, 10, '2026-10-10'), ($1, $2, $3, 'Unit 0', 8, 10, '2026-09-10')`, e.c1, e.st1, e.gv.ID)

	r := gvC.Do("GET", "/teacher/general-report?period=2026-10", nil)
	iamtest.Expect(t, r, 200, "")
	st := r.Body["stats"].(map[string]any)
	cls := iamtest.List(t, r, "my_classes")
	if r.Body["period"] != "2026-10" || st["attendance_marked"] != 2.0 || st["scores_entered"] != 1.0 || st["hours"] != "—" ||
		r.Body["work_pending"] != workPending || len(cls) != 1 || cls[0]["is_main"] != true || cls[0]["is_foreign"] != false {
		t.Fatalf("báo cáo: %s", r.Raw)
	}
	if r := gvC.Do("GET", "/teacher/general-report?period=bad", nil); r.Body["period"] != "2026-10" {
		t.Fatalf("kỳ mặc định: %s", r.Raw)
	}
	if r := gvC.Do("GET", "/teacher/general-report?period=2026-09", nil); r.Body["stats"].(map[string]any)["scores_entered"] != 1.0 ||
		r.Body["stats"].(map[string]any)["attendance_marked"] != 0.0 {
		t.Fatalf("tháng 9: %s", r.Raw)
	}
	e.svc.work = &fakeWork{}
	r = gvC.Do("GET", "/teacher/general-report?period=2026-10", nil)
	st = r.Body["stats"].(map[string]any)
	if st["hours"] != "3.0h" || st["valid_hours"] != "1.5h" || st["sessions"] != 2.0 || len(iamtest.List(t, r, "timesheets")) != 1 || r.Body["work_pending"] != "" {
		t.Fatalf("có chấm công: %s", r.Raw)
	}
}

func TestMetrics(t *testing.T) {
	e := setup(t)
	ctx := context.Background()
	created := time.Date(2026, 10, 5, 9, 0, 0, 0, vnZone)
	var hw uuid.UUID
	e.scalar(&hw, `INSERT INTO academic.homeworks (class_id, session_id, teacher_id, title, items, due_date, due_at, created_at)
		VALUES ($1, $2, $3, 'Bài 1', '{"video":"x"}', '2026-10-08', $4, $5) RETURNING id`, e.c1, e.sPast, e.gv.ID, created.AddDate(0, 0, 3), created)
	// Bé An nộp đúng hạn, Bé Bình nộp sau hạn
	e.exec(`INSERT INTO academic.homework_submissions (student_id, homework_type, created_at) VALUES ($1, 'video', $3), ($2, 'video', $4)`,
		e.st1, e.st2, created.Add(24*time.Hour), created.AddDate(0, 0, 5))
	e.exec(`INSERT INTO academic.mini_test_scores (class_id, student_id, name, score, max_score, test_date)
		VALUES ($1, $2, 'A', 8, 10, '2026-10-10'), ($1, $3, 'A', 12, 20, '2026-10-10')`, e.c1, e.st1, e.st2)
	from := time.Date(2026, 10, 1, 0, 0, 0, 0, vnZone)
	to := time.Date(2026, 10, 31, 23, 59, 59, 0, vnZone)

	res, err := e.svc.KpiSources()["homework_rate"](ctx, e.gv.ID, from, to)
	if err != nil || res.Value == nil || *res.Value != 50 || len(res.Records) != 1 || res.Records[0].Text != "Lớp L1: 50% (2 học sinh)" {
		t.Fatalf("homework_rate: %+v %v", res, err)
	}
	if res, _ := e.svc.KpiSources()["homework_rate"](ctx, e.gv2.ID, from, to); res.Value != nil {
		t.Fatalf("GV không giao bài: %+v", res)
	}
	q, err := e.svc.Dashboard().ClassQuality(ctx, []uuid.UUID{e.c1, e.c2}, from, to)
	if err != nil || q[e.c1].Homework == nil || *q[e.c1].Homework != 50 || q[e.c1].Score == nil || *q[e.c1].Score != 7 || q[e.c2].Homework != nil {
		t.Fatalf("tổng quan: %+v %v", q, err)
	}
	board, err := e.svc.KpiBoard().KpiBoardHomework(ctx, []uuid.UUID{e.gv.ID, e.gv2.ID}, from, to)
	if err != nil || board[e.gv.ID].Expected != 2 || board[e.gv.ID].Submitted != 2 || board[e.gv2.ID].Expected != 0 {
		t.Fatalf("bảng KPI: %+v %v", board, err)
	}

	// Báo cáo tháng giáo viên: lớp của provider gốc + tỷ lệ bài về nhà của tháng (lớp không giao bài để trống)
	m := e.svc.ReportMetrics(monthClasses{reports.Skeleton(), []uuid.UUID{e.c1, e.c2}})
	rows, err := m.TeacherClasses(ctx, reports.Writer{ID: e.gv.ID}, "2026-10")
	if err != nil || len(rows) != 2 || rows[0].Homework == nil || *rows[0].Homework != 50 || rows[1].Homework != nil {
		t.Fatalf("báo cáo tháng: %+v %v", rows, err)
	}
	if rows, _ := m.TeacherClasses(ctx, reports.Writer{ID: e.gv.ID}, "2026-09"); rows[0].Homework != nil {
		t.Fatalf("tháng 9: %+v", rows)
	}
}

type monthClasses struct {
	reports.MetricsProvider
	ids []uuid.UUID
}

func (m monthClasses) TeacherClasses(context.Context, reports.Writer, string) ([]reports.TeacherClass, error) {
	out := []reports.TeacherClass{}
	for _, id := range m.ids {
		out = append(out, reports.TeacherClass{ID: id})
	}
	return out, nil
}
