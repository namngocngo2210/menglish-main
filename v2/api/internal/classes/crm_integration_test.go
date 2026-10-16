package classes

import (
	"context"
	"encoding/json"
	"io"
	"log/slog"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/go-chi/chi/v5"
	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/crm"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/students"
)

// withCRM gắn thêm API CRM (Chốt & Xếp lớp dùng CRMStudents) vào server test của phân hệ lớp học.
func (e *env) withCRM() {
	e.t.Helper()
	log := slog.New(slog.NewTextHandler(io.Discard, nil))
	stu := students.NewService(e.pg, e.iam, log)
	stu.Classes = e.svc
	crmSvc := crm.NewService(e.pg, e.iam, log).WithStudents(e.svc.CRMStudents(stu))
	e.svc.CRM = crmSvc
	auth := iam.NewHandler(e.iam, log)
	r := chi.NewRouter()
	r.Route("/api/v1", func(r chi.Router) {
		r.Use(iam.SameOrigin, auth.LoadSession)
		auth.Routes(r)
		NewHandler(e.svc, log).Routes(r)
		crm.NewHandler(crmSvc, log).Routes(r)
	})
	e.srv.Close()
	e.srv = httptest.NewServer(r)
	e.t.Cleanup(e.srv.Close)
}

func (e *env) lead(code, name string, branch uuid.UUID, extra string) uuid.UUID {
	e.t.Helper()
	var id uuid.UUID
	e.scalar(&id, `INSERT INTO crm.leads (code, name, phone, stage, branch_id, test_score, course_interest)
		VALUES ($1, $2, '0912000111', 'consulting', $3, nullif($4, ''), NULL) RETURNING id`, code, name, branch, extra)
	return id
}

func TestCRMCloseCreatesStudentAndEnrolls(t *testing.T) {
	e := setup(t)
	e.withCRM()
	a, b := e.branch("CN-A"), e.branch("CN-B")
	admin := e.as(e.member("MES001", nil, iam.RoleAdmin))
	staff := e.as(e.member("HV001", []uuid.UUID{a}, iam.RoleAcademicStaff))
	teacher := e.as(e.member("GV001", []uuid.UUID{a}, iam.RoleTeacherFulltime))

	var lvl1, lvl2, ielts, toeic uuid.UUID
	e.scalar(&lvl1, "INSERT INTO academic.course_levels (code, name, target, lessons_count) VALUES ('B1', 'Trung cấp B1', 'B1', 24) RETURNING id")
	e.scalar(&lvl2, "INSERT INTO academic.course_levels (code, name, target, lessons_count) VALUES ('A2', 'Sơ cấp A2', 'A2', 24) RETURNING id")
	e.scalar(&ielts, "INSERT INTO academic.courses (code, name, course_level_id, tuition_fee, total_lessons) VALUES ('CS0001', 'IELTS Foundation', $1, 6000000, 24) RETURNING id", lvl1)
	e.scalar(&toeic, "INSERT INTO academic.courses (code, name, course_level_id, tuition_fee, total_lessons) VALUES ('CS0002', 'TOEIC 450', $1, 5000000, 20) RETURNING id", lvl2)
	open := func(name, code, program, level string, branch uuid.UUID, capacity int) uuid.UUID {
		id := e.newClass(admin, map[string]any{"name": name, "code": code, "branch_id": branch, "program": program, "level": level, "max_capacity": capacity, "min_students": 1})
		e.exec("UPDATE academic.classes SET status = 'active', start_date = '2026-10-19' WHERE id = $1", id)
		return id
	}
	small := open("IELTS tối 2-4", "IE-01", "IELTS Foundation", "B1", a, 1)
	big := open("IELTS tối 3-5", "IE-02", "IELTS Foundation", "B1", a, 10)
	other := open("TOEIC sáng", "TO-01", "TOEIC 450", "A2", a, 10)
	var promo uuid.UUID
	e.scalar(&promo, "INSERT INTO crm.promotions (code, name, type, value, course_id) VALUES ('UD10', 'Giảm 10%', 'percent', 10, $1) RETURNING id", ielts)

	won := e.lead("KH-0001", "Lê Chốt", a, "6.0")
	path := "/crm/leads/" + won.String() + "/close"
	expect(t, teacher.do("POST", path, map[string]any{"class_id": small}), 403, "forbidden")

	// Chốt có lớp: hồ sơ học viên gắn Lead, ghi danh lớp, giá trị hợp đồng = học phí khóa − ưu đãi
	r := staff.do("POST", path, map[string]any{"class_id": small, "promotion_id": promo})
	msg := expectPrefix(t, r, 200, "Đã chốt deal và tạo hồ sơ HV-")
	if !strings.Contains(msg, msgTuitionPending) || !strings.Contains(msg, "mật khẩu tạm") {
		t.Fatalf("thông báo chốt: %s", msg)
	}
	var sid uuid.UUID
	var cur *uuid.UUID
	var branch *uuid.UUID
	var score, target string
	var lessons int32
	e.scalar(&sid, "SELECT id FROM academic.students WHERE lead_id = $1", won)
	if err := e.pg.QueryRow(context.Background(), "SELECT current_class_id, branch_id, entrance_score, target, total_lessons FROM academic.students WHERE id = $1", sid).
		Scan(&cur, &branch, &score, &target, &lessons); err != nil {
		t.Fatal(err)
	}
	if cur == nil || *cur != small || branch == nil || *branch != a || score != "6.0" || target != "IELTS Foundation" || lessons != 24 {
		t.Fatalf("hồ sơ học viên: lớp %v cơ sở %v điểm %q mục tiêu %q buổi %d", cur, branch, score, target, lessons)
	}
	var enrolledLead *uuid.UUID
	e.scalar(&enrolledLead, "SELECT lead_id FROM academic.enrollments WHERE student_id = $1 AND class_id = $2", sid, small)
	if enrolledLead == nil || *enrolledLead != won {
		t.Fatalf("lượt xếp lớp không gắn Lead: %v", enrolledLead)
	}
	var stage string
	var deal int64
	var converted *uuid.UUID
	if err := e.pg.QueryRow(context.Background(), "SELECT stage, deal_value, converted_student_id FROM crm.leads WHERE id = $1", won).Scan(&stage, &deal, &converted); err != nil {
		t.Fatal(err)
	}
	if stage != crm.StageWon || deal != 5_400_000 || converted == nil || *converted != sid {
		t.Fatalf("khách sau chốt: %s %d %v", stage, deal, converted)
	}
	var confirmations int
	e.scalar(&confirmations, "SELECT count(*) FROM crm.enrollment_confirmations c JOIN academic.enrollments en ON en.id = c.enrollment_id WHERE c.lead_id = $1", won)
	if confirmations != 1 {
		t.Fatalf("chờ xác nhận chính thức: %d", confirmations)
	}
	// Trang lớp thấy học viên mới
	if r := admin.do("GET", "/classes/"+small.String()+"?tab=students", nil); r.status != 200 || !strings.Contains(strings.ToLower(jsonString(r.body)), "lê chốt") {
		t.Fatalf("học viên chưa vào lớp: %v", r.body)
	}

	// Lớp đủ sĩ số / khác chi nhánh / khóa ngừng hoạt động: không tạo gì
	full := e.lead("KH-0002", "Trần Đủ", a, "")
	expectMessage(t, staff.do("POST", "/crm/leads/"+full.String()+"/close", map[string]any{"class_id": small}), 422, "Lớp đã đủ sĩ số, vui lòng chọn lớp khác.")
	farLead := e.lead("KH-0003", "Ngô Xa", b, "")
	expectMessage(t, admin.do("POST", "/crm/leads/"+farLead.String()+"/close", map[string]any{"class_id": big}), 422, "Lớp được chọn phải thuộc cùng chi nhánh với Lead.")
	e.exec("UPDATE academic.courses SET is_active = false WHERE id = $1", toeic)
	expectMessage(t, staff.do("POST", "/crm/leads/"+full.String()+"/close", map[string]any{"course_id": toeic}), 422, "Khóa học không còn hoạt động.")
	expectMessage(t, staff.do("POST", "/crm/leads/"+full.String()+"/close", map[string]any{"class_id": other}), 422, "Lớp hoặc khóa học không còn hoạt động.")
	e.exec("UPDATE academic.courses SET is_active = true WHERE id = $1", toeic)
	var n int
	e.scalar(&n, "SELECT count(*) FROM academic.students WHERE lead_id = ANY ($1)", []uuid.UUID{full, farLead})
	if n != 0 {
		t.Fatalf("chốt lỗi vẫn tạo hồ sơ: %d", n)
	}

	// Chốt chưa có lớp → Chờ xếp lớp → Học vụ gán lớp đúng khóa
	r = staff.do("POST", "/crm/leads/"+full.String()+"/close", map[string]any{"course_id": ielts})
	if msg := expectPrefix(t, r, 200, "Đã chốt deal và tạo hồ sơ HV-"); !strings.Contains(msg, "Học viên đang ở danh sách Chờ xếp lớp.") {
		t.Fatalf("chốt chờ xếp lớp: %s", msg)
	}
	var waitingCourse *uuid.UUID
	e.scalar(&waitingCourse, "SELECT waiting_course_id FROM crm.leads WHERE id = $1", full)
	if waitingCourse == nil || *waitingCourse != ielts {
		t.Fatalf("khóa chờ xếp lớp: %v", waitingCourse)
	}
	assign := "/crm/leads/" + full.String() + "/assign-class"
	expectMessage(t, staff.do("POST", assign, map[string]any{"class_id": other}), 422, "Lớp phải thuộc khóa học đã chốt (IELTS Foundation) hoặc cùng cấp độ (Trung cấp B1).")
	expectMessage(t, staff.do("POST", assign, map[string]any{"class_id": small}), 422, "Lớp đã đủ sĩ số, vui lòng chọn lớp khác.")
	expectMessage(t, staff.do("POST", assign, map[string]any{"class_id": big}), 200, "Đã xếp lớp cho học viên và chuyển Lead sang Đã chốt.")
	var wsid uuid.UUID
	e.scalar(&wsid, "SELECT id FROM academic.students WHERE lead_id = $1 AND current_class_id = $2", full, big)
	e.scalar(&n, "SELECT count(*) FROM academic.enrollments WHERE student_id = $1 AND class_id = $2 AND lead_id = $3", wsid, big, full)
	if n != 1 {
		t.Fatalf("lượt xếp lớp khách chờ: %d", n)
	}
	e.scalar(&stage, "SELECT stage FROM crm.leads WHERE id = $1", full)
	if stage != crm.StageWon {
		t.Fatalf("khách sau xếp lớp: %s", stage)
	}

	// Xếp lớp từ màn Tiếp nhận & Xếp lớp cũng hoàn tất khách Chờ xếp lớp (cùng luật khóa / chi nhánh đã chốt)
	late := e.lead("KH-0004", "Đỗ Muộn", a, "")
	expectPrefix(t, staff.do("POST", "/crm/leads/"+late.String()+"/close", map[string]any{"course_id": ielts}), 200, "Đã chốt deal")
	var lsid uuid.UUID
	e.scalar(&lsid, "SELECT id FROM academic.students WHERE lead_id = $1", late)
	if r := staff.do("GET", "/class-enrollments", nil); r.body["crm_waiting_count"] != float64(1) {
		t.Fatalf("số khách chờ xếp lớp: %v", r.body["crm_waiting_count"])
	}
	expectMessage(t, staff.do("POST", "/class-enrollments", map[string]any{"student_id": lsid, "class_id": other}), 422,
		"Lớp phải thuộc khóa học đã chốt (IELTS Foundation) hoặc cùng cấp độ (Trung cấp B1).")
	expect(t, staff.do("POST", "/class-enrollments", map[string]any{"student_id": lsid, "class_id": big}), 200, "")
	e.scalar(&stage, "SELECT stage FROM crm.leads WHERE id = $1", late)
	e.scalar(&n, "SELECT count(*) FROM crm.enrollment_confirmations c JOIN academic.enrollments en ON en.id = c.enrollment_id AND en.lead_id = c.lead_id WHERE c.lead_id = $1", late)
	if stage != crm.StageWon || n != 1 {
		t.Fatalf("khách xếp lớp từ màn Học viên: %s, xác nhận %d", stage, n)
	}
	if r := staff.do("GET", "/class-enrollments", nil); r.body["crm_waiting_count"] != float64(0) {
		t.Fatalf("số khách chờ xếp lớp sau khi xếp: %v", r.body["crm_waiting_count"])
	}
}

func jsonString(v any) string {
	b, _ := json.Marshal(v)
	return string(b)
}
