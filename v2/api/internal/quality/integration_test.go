package quality_test

import (
	"context"
	"crypto/rand"
	"encoding/base64"
	"fmt"
	"testing"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/catalogs"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/classes"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam/iamtest"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/secretbox"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/quality"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/reports"
)

// "Hôm nay": Thứ Bảy 17/10/2026 09:00 giờ Việt Nam.
var now = time.Date(2026, 10, 17, 9, 0, 0, 0, time.FixedZone("ICT", 7*3600))

type env struct {
	*iamtest.Env
	svc *quality.Service
}

func setup(t *testing.T) *env {
	e := iamtest.Setup(t, "academic.class_observations", "academic.class_checklists", "academic.teacher_meeting_reports",
		"academic.classes", "academic.students", "core.audit_log")
	key := make([]byte, 32)
	_, _ = rand.Read(key)
	box, _ := secretbox.FromBase64(base64.StdEncoding.EncodeToString(key))
	cls := classes.NewService(e.Pool, catalogs.NewService(e.Pool, box, e.Log), e.Log)
	svc := quality.NewService(e.Pool, cls, e.Log)
	svc.SetClock(func() time.Time { return now })
	e.Serve(quality.NewHandler(svc, e.Log).Routes)
	return &env{Env: e, svc: svc}
}

func (e *env) id(sql string, args ...any) uuid.UUID {
	e.T.Helper()
	var id uuid.UUID
	if err := e.Pool.QueryRow(context.Background(), sql, args...).Scan(&id); err != nil {
		e.T.Fatal(err)
	}
	return id
}

// class: lớp của chi nhánh, GV chính / GVNN (có thể nil), trạng thái và ngày khai giảng / kết thúc.
func (e *env) class(code string, branch uuid.UUID, teacher, foreign *uuid.UUID, status string, start, end *string) uuid.UUID {
	return e.id(`INSERT INTO academic.classes (code, name, branch_id, teacher_id, foreign_teacher_id, status, start_date, end_date)
		VALUES ($1, $2, $3, $4, $5, $6, $7::date, $8::date) RETURNING id`, code, "Lớp "+code, branch, teacher, foreign, status, start, end)
}

func str(s string) *string { return &s }

func op(class, teacher uuid.UUID, day, rating string) map[string]any {
	return map[string]any{"observed_on": day, "teacher_id": teacher.String(), "class_id": class.String(), "rating": rating,
		"lesson_content": "Bài Unit 3", "suggestions": "  "}
}

func msg(t *testing.T, r iamtest.Resp, status int, want string) {
	t.Helper()
	iamtest.Expect(t, r, status, "")
	if want != "" && r.Body["message"] != want {
		t.Fatalf("muốn thông báo %q, nhận %s", want, r.Raw)
	}
}

func TestPermissions(t *testing.T) {
	e := setup(t)
	hn := e.Branch("HN01")
	iamtest.Expect(t, e.Anon().Do("GET", "/class-quality/operations", nil), 401, "")
	// Giáo viên không có class_quality.view
	gv, gvc := e.Member("GV01", "Nguyễn Thị Hoa", []uuid.UUID{hn}, "teacher_fulltime")
	iamtest.Expect(t, gvc.Do("GET", "/class-quality/operations", nil), 403, "")
	iamtest.Expect(t, gvc.Do("GET", "/class-quality/teacher-meetings", nil), 403, "")
	// Trưởng học thuật: xem được dự giờ vận hành nhưng không ghi (thiếu observe_operations), không sửa checklist
	_, lead := e.Member("HT01", "Trần Học Thuật", []uuid.UUID{hn}, "academic_lead")
	r := lead.Do("GET", "/class-quality/operations", nil)
	iamtest.Expect(t, r, 200, "")
	if r.Body["can_record"] != false {
		t.Fatalf("academic_lead không được ghi dự giờ vận hành: %s", r.Raw)
	}
	c := e.class("IELTS-A1", hn, &gv.ID, nil, "active", nil, nil)
	iamtest.Expect(t, lead.Do("POST", "/class-quality/operations", op(c, gv.ID, "2026-10-16", "good")), 403, "")
	iamtest.Expect(t, lead.Do("PUT", "/class-quality/checklist", map[string]any{"month": "2026-10", "rows": []any{}}), 403, "")
	// Học vụ: ghi dự giờ vận hành, không ghi họp giáo viên / đánh giá học thuật
	_, staff := e.Member("HV01", "Lê Học Vụ", []uuid.UUID{hn}, "academic_staff")
	iamtest.Expect(t, staff.Do("POST", "/class-quality/teacher-meetings", map[string]any{}), 403, "")
	iamtest.Expect(t, staff.Do("PUT", "/class-quality/academic", map[string]any{}), 403, "")
}

func TestOperations(t *testing.T) {
	e := setup(t)
	hn, hcm := e.Branch("HN01"), e.Branch("HCM01")
	gv := e.User("GV01", "Nguyễn Thị Hoa", []uuid.UUID{hn}, "teacher_fulltime")
	gvnn := e.User("GV02", "John Smith", []uuid.UUID{hn}, "teacher_parttime")
	other := e.User("GV03", "Phạm Văn Nam", []uuid.UUID{hcm}, "teacher_fulltime")
	c1 := e.class("IELTS-A1", hn, &gv.ID, &gvnn.ID, "active", nil, nil)
	c2 := e.class("TOEIC-B1", hcm, &other.ID, nil, "active", nil, nil)
	done := e.class("KIDS-C1", hn, &gv.ID, nil, "completed", nil, str("2026-09-30"))
	// Học vụ phạm vi lớp theo chi nhánh: phụ trách cả HN và HCM để dự giờ lớp hai chi nhánh
	_, staff := e.Member("HV01", "Lê Học Vụ", []uuid.UUID{hn, hcm}, "academic_staff")

	// Kiểm tra form
	in := op(c1, gv.ID, "2026-10-18", "good")
	msg(t, staff.Do("POST", "/class-quality/operations", in), 422, "Ngày dự giờ không được ở tương lai.")
	in = op(c1, gv.ID, "2026-10-16", "")
	msg(t, staff.Do("POST", "/class-quality/operations", in), 422, "Bắt buộc đánh giá xếp loại.")
	msg(t, staff.Do("POST", "/class-quality/operations", map[string]any{"observed_on": "2026-10-16"}), 422, "Bắt buộc chọn giáo viên.")
	msg(t, staff.Do("POST", "/class-quality/operations", op(c1, other.ID, "2026-10-16", "good")), 422, "Giáo viên đã chọn không dạy lớp này.")
	msg(t, staff.Do("POST", "/class-quality/operations", op(done, gv.ID, "2026-10-16", "good")), 422,
		"Lớp không còn hoạt động hoặc nằm ngoài phạm vi bạn được xem.")

	// Ghi nhận: GV chính và GVNN đều được
	msg(t, staff.Do("POST", "/class-quality/operations", op(c1, gv.ID, "2026-10-15", "good")), 201, "Đã ghi nhận lượt dự giờ.")
	msg(t, staff.Do("POST", "/class-quality/operations", op(c1, gvnn.ID, "2026-10-16", "excellent")), 201, "Đã ghi nhận lượt dự giờ.")
	msg(t, staff.Do("POST", "/class-quality/operations", op(c2, other.ID, "2026-10-16", "pass")), 201, "")

	r := staff.Do("GET", "/class-quality/operations", nil)
	iamtest.Expect(t, r, 200, "")
	list := iamtest.List(t, r, "observations")
	if len(list) != 3 || list[0]["teacher"] != "Phạm Văn Nam" && list[0]["teacher"] != "John Smith" {
		t.Fatalf("danh sách dự giờ: %s", r.Raw)
	}
	first := list[len(list)-1]
	if first["rating_label"] != "Tốt" || first["class"] != "IELTS-A1 · Lớp IELTS-A1" || first["observer"] != "Lê Học Vụ" {
		t.Fatalf("dòng dự giờ: %v", first)
	}
	notes := first["notes"].(map[string]any)
	if notes["lesson_content"] != "Bài Unit 3" || notes["suggestions"] != nil {
		t.Fatalf("nội dung quan sát: %v", notes)
	}
	// Form: giáo viên đang có lớp hoạt động, kèm số lớp đang dạy
	teachers := iamtest.List(t, r, "teachers")
	if len(teachers) != 3 || teachers[1]["label"] != "Nguyễn Thị Hoa (1 lớp đang dạy)" {
		t.Fatalf("giáo viên của form: %v", teachers)
	}
	// Lọc theo xếp loại / giáo viên
	r = staff.Do("GET", "/class-quality/operations?rating=excellent", nil)
	if l := iamtest.List(t, r, "observations"); len(l) != 1 || l[0]["teacher"] != "John Smith" {
		t.Fatalf("lọc xếp loại: %s", r.Raw)
	}
	r = staff.Do("GET", "/class-quality/operations?teacher_id="+gv.ID.String(), nil)
	if l := iamtest.List(t, r, "observations"); len(l) != 1 {
		t.Fatalf("lọc giáo viên: %s", r.Raw)
	}

	// Sửa: lớp đã kết thúc sau khi ghi vẫn giữ được lớp cũ
	id := first["id"].(string)
	if _, err := e.Pool.Exec(context.Background(), "UPDATE academic.classes SET status = 'completed' WHERE id = $1", c1); err != nil {
		t.Fatal(err)
	}
	upd := op(c1, gv.ID, "2026-10-14", "needs_improvement")
	msg(t, staff.Do("PUT", "/class-quality/operations/"+id, upd), 200, "Đã cập nhật lượt dự giờ.")
	msg(t, staff.Do("DELETE", "/class-quality/operations/"+id, nil), 200, "Đã xóa lượt dự giờ.")
	msg(t, staff.Do("DELETE", "/class-quality/operations/"+id, nil), 404, "Không tìm thấy lượt dự giờ.")

	// Phạm vi chi nhánh: Quản lý cơ sở HN không thấy / không ghi lớp HCM
	_, mgr := e.Member("QL01", "Quản Lý HN", []uuid.UUID{hn}, "manager")
	r = mgr.Do("GET", "/class-quality/operations", nil)
	iamtest.Expect(t, r, 200, "")
	for _, o := range iamtest.List(t, r, "observations") {
		if o["class_id"] == c2.String() {
			t.Fatalf("Quản lý HN thấy dự giờ lớp HCM: %s", r.Raw)
		}
	}
	msg(t, mgr.Do("POST", "/class-quality/operations", op(c2, other.ID, "2026-10-16", "good")), 422,
		"Lớp không còn hoạt động hoặc nằm ngoài phạm vi bạn được xem.")
	var hcmID string
	r = staff.Do("GET", "/class-quality/operations?teacher_id="+other.ID.String(), nil)
	hcmID = iamtest.List(t, r, "observations")[0]["id"].(string)
	msg(t, mgr.Do("DELETE", "/class-quality/operations/"+hcmID, nil), 404, "")
}

func TestAcademicEvaluation(t *testing.T) {
	e := setup(t)
	hn := e.Branch("HN01")
	gv := e.User("GV01", "Nguyễn Thị Hoa", []uuid.UUID{hn}, "teacher_fulltime")
	lead, leadc := e.Member("HT01", "Trần Học Thuật", []uuid.UUID{hn}, "academic_lead")
	c1 := e.class("IELTS-A1", hn, &gv.ID, nil, "active", str("2026-09-01"), nil)
	c2 := e.class("IELTS-A2", hn, &gv.ID, nil, "completed", str("2026-06-01"), str("2026-10-05"))
	e.class("IELTS-OLD", hn, &gv.ID, nil, "completed", str("2026-01-01"), str("2026-09-20"))
	e.class("IELTS-NEW", hn, &gv.ID, nil, "active", str("2026-11-02"), nil)

	// Điểm danh tháng 10 của lớp 1: 3 có mặt / muộn trên 4 lượt → 75%
	ctx := context.Background()
	st := e.id(`INSERT INTO academic.students (code, full_name, phone, branch_id, current_class_id, status) VALUES ('HS-1', 'Học Sinh', '0900000000', $1, $2, 'studying') RETURNING id`, hn, c1)
	for i, status := range []string{"present", "late", "absent", "present"} {
		day := fmt.Sprintf("2026-10-%02d", i+1)
		ss := e.id(`INSERT INTO academic.class_sessions (class_id, branch_id, date, start_time, end_time) VALUES ($1, $2, $3::date, '18:00', '19:30') RETURNING id`, c1, hn, day)
		if _, err := e.Pool.Exec(ctx, `INSERT INTO academic.student_attendance (session_id, class_id, student_id, session_date, status) VALUES ($1, $2, $3, $4::date, $5)`, ss, c1, st, day, status); err != nil {
			t.Fatal(err)
		}
	}

	r := leadc.Do("GET", "/class-quality/academic?month=2026-10", nil)
	iamtest.Expect(t, r, 200, "")
	cls := iamtest.List(t, r, "classes")
	if len(cls) != 2 || cls[0]["code"] != "IELTS-A1" || cls[0]["suggested_attendance"] != 75.0 || cls[1]["evaluation"] != nil {
		t.Fatalf("lớp có học trong tháng: %s", r.Raw)
	}
	if st := r.Body["stats"].(map[string]any); st["total"] != 2.0 || st["observed"] != 0.0 {
		t.Fatalf("thống kê: %v", st)
	}

	save := map[string]any{"class_id": c1.String(), "month": "2026-10", "attendance_rate": 75, "pass_rate": 80.5, "observed": true,
		"observed_on": "2026-09-30", "observer_id": lead.ID.String(), "overall": "Lớp tiến bộ tốt", "action_notes": "Theo dõi tiếp"}
	msg(t, leadc.Do("PUT", "/class-quality/academic", save), 422, "Ngày dự giờ phải nằm trong tháng đánh giá.")
	save["observed_on"] = nil
	msg(t, leadc.Do("PUT", "/class-quality/academic", save), 422, "Chọn ngày dự giờ.")
	save["observed_on"], save["observer_id"] = "2026-10-10", ""
	msg(t, leadc.Do("PUT", "/class-quality/academic", save), 422, "Chọn người dự giờ.")
	save["observer_id"], save["pass_rate"] = lead.ID.String(), 120
	msg(t, leadc.Do("PUT", "/class-quality/academic", save), 422, "Tỉ lệ đạt yêu cầu từ 0 đến 100%.")
	save["pass_rate"] = 80.5
	msg(t, leadc.Do("PUT", "/class-quality/academic", save), 200, "Đã lưu đánh giá dự giờ lớp IELTS-A1 Tháng 10/2026.")
	// Lớp không học trong tháng
	msg(t, leadc.Do("PUT", "/class-quality/academic", map[string]any{"class_id": c2.String(), "month": "2026-11"}), 422,
		"Lớp không học trong tháng đã chọn hoặc nằm ngoài phạm vi bạn được xem.")

	r = leadc.Do("GET", "/class-quality/academic?month=2026-10&status=observed", nil)
	cls = iamtest.List(t, r, "classes")
	if len(cls) != 1 {
		t.Fatalf("lọc đã dự giờ: %s", r.Raw)
	}
	ev := cls[0]["evaluation"].(map[string]any)
	if ev["observed"] != true || ev["pass_rate"] != 80.5 || ev["observer"] != "Trần Học Thuật" || ev["criteria"].(map[string]any)["overall"] != "Lớp tiến bộ tốt" {
		t.Fatalf("đánh giá: %v", ev)
	}
	if st := r.Body["stats"].(map[string]any); st["observed"] != 1.0 {
		t.Fatalf("thống kê đã dự giờ: %v", st)
	}
	// Bỏ "Đã dự giờ": giữ tỉ lệ, xóa đánh giá chi tiết
	msg(t, leadc.Do("PUT", "/class-quality/academic", map[string]any{"class_id": c1.String(), "month": "2026-10", "pass_rate": 60, "observed": false,
		"overall": "bỏ"}), 200, "")
	r = leadc.Do("GET", "/class-quality/academic?month=2026-10&status=pending", nil)
	cls = iamtest.List(t, r, "classes")
	if len(cls) != 2 || cls[0]["evaluation"].(map[string]any)["criteria"].(map[string]any)["overall"] != nil {
		t.Fatalf("chưa dự giờ: %s", r.Raw)
	}
}

func TestChecklist(t *testing.T) {
	e := setup(t)
	hn, hcm := e.Branch("HN01"), e.Branch("HCM01")
	c1 := e.class("IELTS-A1", hn, nil, nil, "active", nil, nil)
	c2 := e.class("IELTS-A2", hn, nil, nil, "active", nil, nil)
	c3 := e.class("TOEIC-B1", hcm, nil, nil, "active", nil, nil)
	_, mgr := e.Member("QL01", "Quản Lý HN", []uuid.UUID{hn}, "manager")

	r := mgr.Do("GET", "/class-quality/checklist?month=2026-10", nil)
	iamtest.Expect(t, r, 200, "")
	if l := iamtest.List(t, r, "classes"); len(l) != 2 || r.Body["can_edit"] != true {
		t.Fatalf("checklist HN: %s", r.Raw)
	}
	rows := []map[string]any{
		{"class_id": c1.String(), "tuition_due": "yes", "tuition_reminded": "no", "tuition_collected": "yes", "tuition_note": "Còn 2 HS"},
		{"class_id": c2.String()},
	}
	msg(t, mgr.Do("PUT", "/class-quality/checklist", map[string]any{"month": "2026-10", "rows": append(rows, map[string]any{"class_id": c3.String()})}), 422,
		"Có lớp không học trong tháng đã chọn hoặc nằm ngoài phạm vi bạn được xem.")
	msg(t, mgr.Do("PUT", "/class-quality/checklist", map[string]any{"month": "2026-10", "rows": []map[string]any{{"class_id": c1.String(), "big_test_due": "maybe"}}}), 422,
		"Đến mốc Big Test? chỉ nhận Có / Không / N-A.")
	msg(t, mgr.Do("PUT", "/class-quality/checklist", map[string]any{"month": "2026-10", "rows": rows}), 200, "Đã lưu checklist 1 lớp — Tháng 10/2026.")
	msg(t, mgr.Do("PUT", "/class-quality/checklist", map[string]any{"month": "2026-10", "rows": rows[1:]}), 200, "Chưa có lớp nào được đánh dấu.")

	r = mgr.Do("GET", "/class-quality/checklist?month=2026-10", nil)
	l := iamtest.List(t, r, "classes")
	if l[0]["tuition_note"] != "Còn 2 HS" || fmt.Sprint(l[0]["gaps"]) != "[tuition_reminded]" || l[0]["values"].(map[string]any)["tuition_due"] != "yes" {
		t.Fatalf("dòng checklist: %v", l[0])
	}
	// Bỏ hết đánh dấu của lớp đã có bản ghi: vẫn lưu (xóa giá trị)
	msg(t, mgr.Do("PUT", "/class-quality/checklist", map[string]any{"month": "2026-10", "rows": []map[string]any{{"class_id": c1.String()}}}), 200,
		"Đã lưu checklist 1 lớp — Tháng 10/2026.")
}

func TestTeacherMeetings(t *testing.T) {
	e := setup(t)
	hn, hcm := e.Branch("HN01"), e.Branch("HCM01")
	gv := e.User("GV01", "Nguyễn Thị Hoa", []uuid.UUID{hn}, "teacher_fulltime")
	gv2 := e.User("GV02", "Phạm Văn Nam", []uuid.UUID{hcm}, "teacher_fulltime")
	staffU := e.User("HV09", "Học Vụ Khác", []uuid.UUID{hn}, "academic_staff")
	c1 := e.class("IELTS-A1", hn, &gv.ID, nil, "active", nil, nil)
	c2 := e.class("TOEIC-B1", hcm, &gv2.ID, nil, "active", nil, nil)
	_, lead := e.Member("HT01", "Trần Học Thuật", []uuid.UUID{hn}, "academic_lead")

	in := map[string]any{"week_start": "2026-10-15", "teacher_id": gv.ID.String(), "class_id": c1.String(), "class_note": "Lớp ổn",
		"status": "in_progress"}
	in["class_note"] = " "
	msg(t, lead.Do("POST", "/class-quality/teacher-meetings", in), 422, "Nhập ghi chú tình hình lớp / học sinh.")
	in["class_note"], in["teacher_id"] = "Lớp ổn", staffU.ID.String()
	msg(t, lead.Do("POST", "/class-quality/teacher-meetings", in), 422, "Người được chọn không phải giáo viên.")
	in["teacher_id"], in["class_id"] = gv2.ID.String(), c1.String()
	msg(t, lead.Do("POST", "/class-quality/teacher-meetings", in), 422, "Giáo viên đã chọn không dạy lớp này.")
	in["teacher_id"] = gv.ID.String()
	msg(t, lead.Do("POST", "/class-quality/teacher-meetings", in), 201, "Đã lưu báo cáo họp giáo viên.")
	// Không gắn lớp; lớp HCM
	msg(t, lead.Do("POST", "/class-quality/teacher-meetings", map[string]any{"week_start": "2026-10-05", "teacher_id": gv2.ID.String(),
		"class_note": "Họp chung", "status": "handled"}), 201, "")
	msg(t, lead.Do("POST", "/class-quality/teacher-meetings", map[string]any{"week_start": "2026-10-05", "teacher_id": gv2.ID.String(),
		"class_id": c2.String(), "class_note": "Lớp HCM", "status": "sketchy"}), 201, "")

	r := lead.Do("GET", "/class-quality/teacher-meetings?week=2026-10-17", nil)
	iamtest.Expect(t, r, 200, "")
	l := iamtest.List(t, r, "reports")
	// Tuần được chuẩn hóa về thứ Hai 12/10
	if len(l) != 1 || l[0]["week_start"] != "2026-10-12" || l[0]["week_end"] != "2026-10-18" || l[0]["status_label"] != "Đang xử lý" {
		t.Fatalf("lọc tuần: %s", r.Raw)
	}
	if r.Body["current_week"] != "2026-10-12" {
		t.Fatalf("tuần hiện tại: %v", r.Body["current_week"])
	}
	id := l[0]["id"].(string)
	in["status"] = "handled"
	msg(t, lead.Do("PUT", "/class-quality/teacher-meetings/"+id, in), 200, "Đã cập nhật báo cáo họp giáo viên.")

	// Quản lý HN: thấy báo cáo không gắn lớp và lớp HN, không thấy lớp HCM
	_, mgr := e.Member("QL01", "Quản Lý HN", []uuid.UUID{hn}, "manager")
	r = mgr.Do("GET", "/class-quality/teacher-meetings", nil)
	if l := iamtest.List(t, r, "reports"); len(l) != 2 {
		t.Fatalf("phạm vi báo cáo họp: %s", r.Raw)
	}
	r = lead.Do("GET", "/class-quality/teacher-meetings?status=sketchy", nil)
	hcmID := iamtest.List(t, r, "reports")[0]["id"].(string)
	msg(t, mgr.Do("DELETE", "/class-quality/teacher-meetings/"+hcmID, nil), 404, "")
	msg(t, lead.Do("DELETE", "/class-quality/teacher-meetings/"+id, nil), 200, "Đã xóa báo cáo họp giáo viên.")
}

func TestReportMetrics(t *testing.T) {
	e := setup(t)
	ctx := context.Background()
	hn, hcm := e.Branch("HN01"), e.Branch("HCM01")
	gv := e.User("GV01", "Nguyễn Thị Hoa", []uuid.UUID{hn}, "teacher_fulltime")
	gv2 := e.User("GV02", "Phạm Văn Nam", []uuid.UUID{hcm}, "teacher_fulltime")
	c1 := e.class("IELTS-A1", hn, &gv.ID, nil, "active", str("2026-09-01"), nil)
	c2 := e.class("TOEIC-B1", hcm, &gv2.ID, nil, "active", nil, nil)
	// Học vụ phạm vi lớp theo chi nhánh: phụ trách cả HN và HCM để dự giờ lớp hai chi nhánh
	staff, sc := e.Member("HV01", "Lê Học Vụ", []uuid.UUID{hn, hcm}, "academic_staff")
	lead, lc := e.Member("HT01", "Trần Học Thuật", []uuid.UUID{hn}, "academic_lead")
	msg(t, sc.Do("POST", "/class-quality/operations", op(c1, gv.ID, "2026-10-16", "good")), 201, "")
	msg(t, sc.Do("POST", "/class-quality/operations", op(c2, gv2.ID, "2026-10-16", "pass")), 201, "")
	msg(t, lc.Do("PUT", "/class-quality/academic", map[string]any{"class_id": c1.String(), "month": "2026-10", "observed": true,
		"observed_on": "2026-10-14", "observer_id": lead.ID.String(), "overall": "Lớp tiến bộ tốt"}), 200, "")
	msg(t, lc.Do("POST", "/class-quality/teacher-meetings", map[string]any{"week_start": "2026-10-12", "teacher_id": gv.ID.String(),
		"class_note": "Lớp ổn", "status": "in_progress"}), 201, "")

	m := e.svc.ReportMetrics(reports.Skeleton(), e.IAM.LoadAccess)
	if m.Pending("meetings") || !m.Pending("classes") {
		t.Fatal("họp giáo viên đã có nguồn, lớp của giáo viên theo provider gốc")
	}
	find := func(secs []reports.Section, key string) reports.Item {
		for _, s := range secs {
			for _, it := range s.Items {
				if it.Key == key {
					return it
				}
			}
		}
		t.Fatalf("không có mục %s", key)
		return reports.Item{}
	}
	day := func(d int) time.Time { return time.Date(2026, 10, d, 0, 0, 0, 0, now.Location()) }
	// Báo cáo ngày 16/10 của Học thuật (chi nhánh HN): chỉ lớp HN
	secs, _, err := m.Daily(ctx, reports.Writer{ID: lead.ID, BranchIDs: []uuid.UUID{hn}}, day(16), reports.AudienceAcademic)
	if err != nil {
		t.Fatal(err)
	}
	if it := find(secs, "observations"); *it.Count != 1 || it.Details[0].Text != "Lớp IELTS-A1 · GV Nguyễn Thị Hoa · Tốt" {
		t.Fatalf("dự giờ ngày: %+v", it)
	}
	// Báo cáo tuần 12–18/10: phiếu vận hành + phiếu học thuật; dòng gợi ý "đã làm" của người dự
	sum, sug, err := m.WeeklyAcademic(ctx, reports.Writer{ID: lead.ID, BranchIDs: []uuid.UUID{hn}}, day(12), day(18))
	if err != nil {
		t.Fatal(err)
	}
	if it := find([]reports.Section{sum}, "observations"); *it.Count != 2 || it.Details[1].Text != "Lớp IELTS-A1 · 14/10" {
		t.Fatalf("dự giờ tuần: %+v", it)
	}
	if d := sug["done"]; len(d) != 1 || *d[0].Content != "Dự giờ đánh giá Lớp IELTS-A1" || *d[0].Detail != "Lớp tiến bộ tốt" || *d[0].Source == "" {
		t.Fatalf("gợi ý đã làm: %+v", d)
	}
	_, sug, _ = m.WeeklyAcademic(ctx, reports.Writer{ID: staff.ID}, day(12), day(18))
	if d := sug["done"]; len(d) != 2 || *d[0].Detail != "Đánh giá: Tốt" {
		t.Fatalf("gợi ý của học vụ: %+v", d)
	}
	// Báo cáo tháng: buổi họp người viết đã ghi
	mt, err := m.TeacherMeetings(ctx, lead.ID, day(12), day(31))
	if err != nil || len(mt) != 1 || mt[0].Teacher != "Nguyễn Thị Hoa" || mt[0].StatusLabel != "Đang xử lý" || mt[0].WeekStart != "2026-10-12" {
		t.Fatalf("họp giáo viên: %+v %v", mt, err)
	}
}
