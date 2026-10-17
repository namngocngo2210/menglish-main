package main

import (
	"bytes"
	"context"
	"fmt"
	"strings"
	"testing"
	"time"

	"github.com/go-chi/chi/v5"
	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/attendance"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/catalogs"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/classes"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/files"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam/iamtest"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/payroll"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/filestore"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/portal"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/projects"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/students"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/surveys"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/syllabus"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/tasks"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/teacher"
)

// Test tích hợp các adapter ở wiring.go qua HTTP với Postgres / Redis thật: đi muộn → biên bản (Lương & Phạt), kỳ lương
// đã duyệt khóa chấm công, check-in ở cổng giáo viên → ca dạy của Chấm công, bảng lương đọc ca dạy / ngày công của Chấm
// công, mốc dự án trễ → biên bản, Big Test trả kết quả trễ → biên bản + việc duyệt đề (Công việc), khảo sát 5 sao của
// cổng PH/HS → phân hệ Khảo sát.

type wEnv struct {
	*ptEnv
	att  *attendance.Service
	pay  *payroll.Service
	proj *projects.Service
	syl  *syllabus.Service
	now  time.Time // đồng hồ của Chấm công / Lương / Dự án
}

func wSetup(t *testing.T) *wEnv {
	t.Helper()
	e := iamtest.Setup(t, "hr.staff_attendances", "hr.staff_attendance_requests", "hr.teacher_timesheets", "hr.timesheet_sync_logs",
		"hr.payroll_records", "hr.payroll_periods", "hr.penalties", "hr.teacher_hourly_rates", "academic.students", "academic.classes",
		"ops.surveys", "ops.survey_responses", "syllabus.project_updates", "syllabus.milestones", "syllabus.project_members",
		"syllabus.projects", "syllabus.big_tests", "ops.work_tasks", "core.files", "core.notifications", "core.audit_log")
	if _, err := e.Pool.Exec(context.Background(), "DELETE FROM core.settings WHERE key = 'sla_academic_project_live_since'"); err != nil {
		t.Fatal(err)
	}
	if _, err := catalogs.SyncSLA(context.Background(), e.Pool); err != nil {
		t.Fatal(err)
	}
	store, err := filestore.NewLocal(t.TempDir(), files.APIBase+files.BlobPrefix, bytes.Repeat([]byte{7}, 32))
	if err != nil {
		t.Fatal(err)
	}
	w := &wEnv{ptEnv: &ptEnv{Env: e, t: t}, now: time.Date(2026, 10, 17, 8, 12, 0, 0, vnZone)}
	clock := func() time.Time { return w.now }
	fileSvc := files.NewService(e.Pool, store, e.Log, 0)
	catalogSvc := catalogs.NewService(e.Pool, nil, e.Log)
	cls := classes.NewService(e.Pool, catalogSvc, e.Log)
	w.pay = payroll.NewService(e.Pool, e.Log).WithFiles(fileSvc)
	w.pay.SetClock(clock)
	w.att = attendance.NewService(e.Pool, e.IAM, e.Log).WithFiles(fileSvc).WithClasses(cls).
		WithPayrollLock(attendancePayroll{w.pay}).WithPenalties(attendancePayroll{w.pay})
	w.att.SetClock(clock)
	w.pay.WithAttendance(payrollAttendance{w.att})
	w.syl = syllabus.NewService(e.Pool, e.Log, syllabus.Deps{Classes: cls.SyllabusClasses(), Files: fileSvc, SLA: catalogSvc,
		Penalties: syllabusPenalties{w.pay}, Tasks: syllabusTasks{tasks.NewService(e.Pool, e.Log)}})
	teacherSvc := teacher.NewService(e.Pool, e.Log, teacher.Deps{Classes: cls.TeacherPortal(), SLA: fakeSLA{}, Files: fileSvc,
		Work: teacherWork{att: w.att, pay: w.pay}, Syllabus: teacherSyllabus{w.syl}})
	w.proj = projects.NewService(e.Pool, e.Log).WithSla(catalogSvc).WithPenalties(projectPenalties{p: w.pay, sla: catalogSvc})
	w.proj.SetClock(clock)
	surveySvc := surveys.NewService(e.Pool, e.Log)
	portalSvc := portal.NewService(e.Pool, fileSvc, students.NewService(e.Pool, e.IAM, e.Log), e.Log).WithSurveys(portalSurveys{surveySvc})
	fh := files.NewHandler(fileSvc, e.Log)
	e.Serve(func(r chi.Router) {
		attendance.NewHandler(w.att, e.Log).Routes(r)
		payroll.NewHandler(w.pay, e.Log).Routes(r)
		teacher.NewHandler(teacherSvc, e.Log).Routes(r)
		projects.NewHandler(w.proj, e.Log).Routes(r)
		portal.NewHandler(portalSvc, e.Log).Routes(r)
		surveys.NewHandler(surveySvc, e.Log).Routes(r)
		fh.Routes(r)
		fh.PublicRoutes(r)
	})
	return w
}

func (w *wEnv) exec(sql string, args ...any) {
	w.t.Helper()
	if _, err := w.Pool.Exec(context.Background(), sql, args...); err != nil {
		w.t.Fatal(err)
	}
}

func (w *wEnv) count(sql string, args ...any) int {
	w.t.Helper()
	var n int
	w.scalar(&n, sql, args...)
	return n
}

// lockMonth: kỳ lương của tháng chứa day đã duyệt.
func (w *wEnv) lockMonth(day time.Time) {
	w.t.Helper()
	start := time.Date(day.Year(), day.Month(), 1, 0, 0, 0, 0, time.UTC)
	w.exec(`INSERT INTO hr.payroll_periods (code, title, month, year, start_date, end_date, status, approved_at)
		VALUES ($1, $2, $3, $4, $5, $6, 'approved', now())`, start.Format("PR-2006-01"), "Bảng lương "+start.Format("01/2006"),
		int(start.Month()), start.Year(), start, start.AddDate(0, 1, -1))
}

func wPunch(c *iamtest.Client, kind string) iamtest.Resp {
	return c.Upload("/attendance/me/punch", map[string]string{"kind": kind, "latitude": "21.0288", "longitude": "105.8542",
		"accuracy": "12"}, "face.png", pngBytes)
}

func lockedMsg(day time.Time) string {
	return "Ngày " + day.Format("02/01/2006") + " thuộc kỳ lương đã duyệt/đã chi trả — không thể ghi nhận hoặc thay đổi dữ liệu lương cho ngày này."
}

// Đi muộn tính lỗi → biên bản chờ giải trình ở phân hệ Lương & Phạt; kỳ lương đã duyệt khóa chấm công.
func TestWiringAttendancePayroll(t *testing.T) {
	w := wSetup(t)
	hn := w.Branch("HN01")
	w.exec(`UPDATE core.branches SET latitude = 21.0285, longitude = 105.8542, checkin_radius = 100, work_start_time = '08:00',
		work_end_time = '17:00', late_grace_minutes = 5 WHERE id = $1`, hn)
	sales, salesC := w.Member("SA01", "Tư vấn HN", []uuid.UUID{hn}, iam.RoleSalesConsultant)

	r := wPunch(salesC, "in")
	ptMsg(t, r, 200, "Đã chấm công vào lúc 08:12. Bạn đi muộn 12 phút, hệ thống đã lập biên bản chờ giải trình.")
	pen, _ := r.Body["today"].(map[string]any)["penalty"].(map[string]any)
	var code, violation, status, category string
	var amount int64
	w.scalar(&code, `SELECT code FROM hr.penalties WHERE user_id = $1`, sales.ID)
	w.scalar(&violation, `SELECT violation_type FROM hr.penalties WHERE user_id = $1`, sales.ID)
	w.scalar(&status, `SELECT status FROM hr.penalties WHERE user_id = $1`, sales.ID)
	w.scalar(&category, `SELECT error_category FROM hr.penalties WHERE user_id = $1`, sales.ID)
	w.scalar(&amount, `SELECT amount FROM hr.penalties WHERE user_id = $1`, sales.ID)
	if pen == nil || pen["code"] != code || !strings.HasPrefix(code, "BB-2026-") || status != "pending" || amount != 0 ||
		category != "operations" || violation != "Đi muộn 12 phút (chấm công ngày 17/10/2026)" {
		t.Fatalf("biên bản đi muộn: %s / %s %s %s %d", r.Raw, code, violation, status, amount)
	}
	if n := w.count(`SELECT count(*) FROM core.notifications WHERE user_id = $1 AND type = 'penalty_created' AND title = $2`,
		sales.ID, "Biên bản "+code+": "+violation); n != 1 {
		t.Fatalf("thông báo biên bản: %d", n)
	}
	if n := w.count(`SELECT count(*) FROM hr.staff_attendances WHERE user_id = $1 AND penalty_id IS NOT NULL`, sales.ID); n != 1 {
		t.Fatalf("dòng chấm công chưa gắn biên bản: %d", n)
	}

	// Kỳ lương tháng 10 đã duyệt → không chấm ra được nữa
	w.lockMonth(w.now)
	w.now = time.Date(2026, 10, 17, 17, 5, 0, 0, vnZone)
	ptMsg(t, wPunch(salesC, "out"), 422, lockedMsg(w.now))
	if r := salesC.Do("GET", "/attendance/me", nil); r.Body["locked"] != lockedMsg(w.now) {
		t.Fatalf("màn chấm công không báo khóa: %s", r.Raw)
	}
}

// Check-in ca dạy ở cổng giáo viên ghi ca chờ duyệt của Chấm công; thẻ lương tạm tính dùng đơn giá giờ của Lương; ngày
// thuộc kỳ lương đã duyệt không check-in được.
func TestWiringTeacherCheckin(t *testing.T) {
	w := wSetup(t)
	hn := w.Branch("HN01")
	gv, gvC := w.Member("GV01", "Cô Lan", []uuid.UUID{hn}, iam.RoleTeacherFulltime)
	now := time.Now().In(vnZone)
	today := time.Date(now.Year(), now.Month(), now.Day(), 0, 0, 0, 0, time.UTC)
	start := fmt.Sprintf("%02d:00", now.Hour())
	session := func(class uuid.UUID) uuid.UUID {
		var id uuid.UUID
		w.scalar(&id, `INSERT INTO academic.class_sessions (class_id, branch_id, date, start_time, end_time, type, shift_name)
			VALUES ($1, $2, $3, $4, '23:59', 'regular', 'Ca 1') RETURNING id`, class, hn, today.Format(time.DateOnly), start)
		return id
	}
	c1 := w.class("L1", hn, gv.ID)
	s1 := session(c1)
	w.exec(`INSERT INTO hr.teacher_hourly_rates (user_id, rate, rate_unit, effective_from) VALUES ($1, 200000, 'hour', '2025-01-01')`, gv.ID)

	ptMsg(t, gvC.Do("POST", "/teacher/checkin", map[string]any{"session_ids": []string{s1.String()}}), 200,
		"Đã check-in thành công 1 ca dạy hôm nay!")
	var hours float64
	w.scalar(&hours, `SELECT hours FROM hr.teacher_timesheets WHERE user_id = $1 AND class_session_id = $2 AND source = 'checkin'
		AND status = 'pending_review'`, gv.ID, s1)
	r := gvC.Do("GET", "/teacher/home", nil)
	iamtest.Expect(t, r, 200, "")
	shifts := iamtest.List(t, r, "shifts")
	wd := r.Body["widgets"].(map[string]any)
	if r.Body["can_checkin"] != true || len(shifts) != 1 || shifts[0]["checked_in"] != true || wd["timesheets_total"] != 1.0 ||
		wd["timesheets_pending"] != 1.0 || wd["estimate"] != hours*200000 || wd["work_pending"] != "" {
		t.Fatalf("trang chủ GV (giờ %v): %s", hours, r.Raw)
	}
	// Đã check-in → bỏ qua kèm giờ check-in
	var at string
	w.scalar(&at, `SELECT to_char(checkin_time, 'HH24:MI') FROM hr.teacher_timesheets WHERE class_session_id = $1`, s1)
	ptMsg(t, gvC.Do("POST", "/teacher/checkin", map[string]any{"session_ids": []string{s1.String()}}), 422,
		"Không check-in được ca nào — Lớp L1: bạn đã check-in buổi này lúc "+at+".")

	// Kỳ lương tháng này đã duyệt → không ghi ca mới
	s2 := session(w.class("L2", hn, gv.ID))
	w.lockMonth(today)
	ptMsg(t, gvC.Do("POST", "/teacher/checkin", map[string]any{"session_ids": []string{s2.String()}}), 422, lockedMsg(today))
	if n := w.count(`SELECT count(*) FROM hr.teacher_timesheets WHERE user_id = $1`, gv.ID); n != 1 {
		t.Fatalf("ca dạy: %d", n)
	}
}

// Bảng lương đọc ca dạy hợp lệ và ngày công của phân hệ Chấm công.
func TestWiringPayrollReadsAttendance(t *testing.T) {
	w := wSetup(t)
	hn := w.Branch("HN01")
	_, admin := w.Member("AD01", "Quản trị", nil, iam.RoleAdmin)
	gv := w.User("GV01", "An Part-time", []uuid.UUID{hn}, iam.RoleTeacherParttime)
	w.exec(`INSERT INTO iam.staff_profiles (user_id, base_salary, hourly_rate, contract_type) VALUES ($1, 0, 200000, 'Bán thời gian')`, gv.ID)
	w.exec(`INSERT INTO hr.teacher_timesheets (user_id, teaching_date, scheduled_time, hours, type, source, status) VALUES
		($1, '2026-09-10', '18:00-19:30', 1.5, 'regular', 'manual', 'valid'),
		($1, '2026-09-12', '18:00-19:30', 1.5, 'regular', 'checkin', 'pending_review'),
		($1, '2026-09-14', '18:00-19:30', 1.5, 'regular', 'checkin', 'invalid')`, gv.ID)
	w.exec(`INSERT INTO hr.staff_attendances (user_id, branch_id, work_date, expected_start, expected_end, check_in_at, check_out_at)
		VALUES ($1, $2, '2026-09-10', '17:45', '19:30', '2026-09-10 17:40+07', '2026-09-10 19:35+07')`, gv.ID, hn)

	r := admin.Do("POST", "/payroll/periods", map[string]any{"month": 9, "year": 2026})
	iamtest.Expect(t, r, 201, "")
	r = admin.Do("GET", "/payroll/periods/"+r.Body["id"].(string), nil)
	iamtest.Expect(t, r, 200, "")
	var rec map[string]any
	for _, x := range iamtest.List(t, r, "records") {
		if x["user_id"] == gv.ID.String() {
			rec = x
		}
	}
	if rec == nil || rec["teaching_sessions"] != 1.0 || rec["teaching_salary"] != 300000.0 {
		t.Fatalf("phiếu lương GV: %s", r.Raw)
	}
	d := admin.Do("GET", "/payroll/records/"+rec["id"].(string), nil)
	iamtest.Expect(t, d, 200, "")
	daily, _ := d.Body["daily_attendance"].(map[string]any)
	if d.Body["attendance_pending"] != false || len(iamtest.List(t, d, "timesheets")) != 1 || daily == nil || daily["days"] != 1.0 {
		t.Fatalf("chi tiết phiếu lương: %s", d.Raw)
	}
}

// Mốc dự án quá hạn → biên bản chờ giải trình (Lương & Phạt) cho người nhận mốc, báo Admin; màn dự án thấy biên bản.
func TestWiringMilestonePenalty(t *testing.T) {
	w := wSetup(t)
	ctx := context.Background()
	w.now = time.Date(2026, 10, 17, 9, 0, 0, 0, vnZone)
	hn := w.Branch("HN01")
	adminU, _ := w.Member("AD01", "Quản trị", nil, iam.RoleAdmin)
	lead, lc := w.Member("HT01", "Trần Học Thuật", []uuid.UUID{hn}, "academic_lead")
	gv, _ := w.Member("GV01", "Nguyễn Thị Hoa", []uuid.UUID{hn}, iam.RoleTeacherFulltime)
	r := lc.Do("POST", "/academic-projects", map[string]any{"name": "Soạn sách IELTS Foundation", "type": "book",
		"owner_id": lead.ID.String(), "member_ids": []string{gv.ID.String()}, "start_date": "2026-10-01", "deadline": "2026-12-31",
		"kickoff_link": "https://docs.example.com/bien-ban"})
	iamtest.Expect(t, r, 201, "")
	pid := r.Body["id"].(string)
	iamtest.Expect(t, lc.Do("POST", "/academic-projects/"+pid+"/milestones", map[string]any{"title": "Unit 1-4", "due_date": "2026-10-15",
		"target_quantity": 12, "unit": "unit", "assignee_id": gv.ID.String()}), 201, "")
	iamtest.Expect(t, lc.Do("POST", "/academic-projects/"+pid+"/lock", nil), 200, "")
	if _, err := w.proj.RunSla(ctx); err != nil { // lần đầu ghi thời điểm bật SLA
		t.Fatal(err)
	}
	w.exec(`UPDATE core.settings SET value = to_jsonb('2026-10-01T00:00:00+07:00'::text) WHERE key = 'sla_academic_project_live_since'`)
	res, err := w.proj.RunSla(ctx)
	if err != nil || res.Breached != 1 {
		t.Fatalf("SLA: %+v %v", res, err)
	}
	var code, violation, category, key string
	w.scalar(&code, `SELECT code FROM hr.penalties WHERE user_id = $1 AND auto_source = 'academic.milestone_late'`, gv.ID)
	w.scalar(&violation, `SELECT violation_type FROM hr.penalties WHERE code = $1`, code)
	w.scalar(&category, `SELECT error_category FROM hr.penalties WHERE code = $1`, code)
	w.scalar(&key, `SELECT auto_key FROM hr.penalties WHERE code = $1`, code)
	label := `Soạn sách IELTS Foundation — mốc "Unit 1-4"`
	if !strings.HasPrefix(violation, "Trễ deadline mốc dự án học thuật: ") || !strings.HasSuffix(violation, label) || category != "academic" {
		t.Fatalf("biên bản: %s %s", violation, category)
	}
	subject := strings.TrimPrefix(violation, "Trễ deadline mốc dự án học thuật: ")
	title := "Biên bản " + code + ": Trễ deadline mốc dự án học thuật — " + subject
	if n := w.count(`SELECT count(*) FROM core.notifications WHERE user_id = $1 AND type = 'penalty_created' AND title = $2
		AND body LIKE 'Bạn là người phụ trách.%'`, gv.ID, title); n != 1 {
		t.Fatalf("báo người nhận mốc: %d", n)
	}
	if n := w.count(`SELECT count(*) FROM core.notifications WHERE user_id = $1 AND type = 'penalty_created' AND title = $2
		AND body LIKE 'Người phụ trách: Nguyễn Thị Hoa.%'`, adminU.ID, title); n != 1 {
		t.Fatalf("báo Admin: %d", n)
	}
	d := lc.Do("GET", "/academic-projects/"+pid, nil)
	iamtest.Expect(t, d, 200, "")
	ps := iamtest.List(t, d, "penalties")
	if d.Body["penalties_pending"] != false || len(ps) != 1 || ps[0]["code"] != code || ps[0]["milestone"] != "Unit 1-4" ||
		ps[0]["milestone_id"] != key || ps[0]["user"] != "Nguyễn Thị Hoa" || ps[0]["url"] != "/penalties?search="+code {
		t.Fatalf("biên bản ở màn dự án: %s", d.Raw)
	}
	if res, _ := w.proj.RunSla(ctx); res.Breached != 0 || w.count(`SELECT count(*) FROM hr.penalties`) != 1 {
		t.Fatalf("lập lại biên bản: %+v", res)
	}
}

// Khảo sát 5 sao của cổng PH/HS đọc / ghi đợt khảo sát của phân hệ Khảo sát.
func TestWiringPortalSurveys(t *testing.T) {
	w := wSetup(t)
	hn := w.Branch("HN01")
	gv, _ := w.Member("GV01", "Cô Lan", []uuid.UUID{hn}, iam.RoleTeacherFulltime)
	_, staff := w.Member("HV01", "Học vụ", []uuid.UUID{hn}, iam.RoleAcademicStaff)
	st, hs := w.student("HS001", "Bé An", hn, w.class("L1", hn, gv.ID))

	r := staff.Do("POST", "/surveys", map[string]any{"title": "Cơ sở vật chất tháng 10"})
	iamtest.Expect(t, r, 201, "")
	open := r.Body["id"].(string)
	r = staff.Do("POST", "/surveys", map[string]any{"title": "Đợt sẽ đóng"})
	closed := r.Body["id"].(string)
	iamtest.Expect(t, staff.Do("PUT", "/surveys/"+closed, map[string]any{"title": "Đợt đã đóng"}), 200, "")

	p := hs.Do("GET", "/portal/surveys", nil)
	iamtest.Expect(t, p, 200, "")
	if svs := iamtest.List(t, p, "surveys"); p.Body["ready"] != true || len(svs) != 1 || svs[0]["id"] != open ||
		svs[0]["status_text"] != "Hạn: Chưa đặt hạn" {
		t.Fatalf("cổng khảo sát: %s", p.Raw)
	}
	ptMsg(t, hs.Do("POST", "/portal/surveys", map[string]any{"student_id": st.String(), "survey_id": closed, "feedback": "Rất tốt ạ"}),
		422, "Đợt khảo sát đã đóng hoặc không còn tồn tại.")
	ptMsg(t, hs.Do("POST", "/portal/surveys", map[string]any{"student_id": st.String(), "survey_id": open, "rating": 4,
		"feedback": "Phòng học sạch"}), 200, "Cảm ơn bạn đã gửi khảo sát! Trung tâm sẽ tiếp nhận và cải thiện chất lượng dịch vụ.")

	res := staff.Do("GET", "/surveys/"+open+"/responses", nil)
	if rows := iamtest.List(t, res, "responses"); len(rows) != 1 || rows[0]["student_code"] != "HS001" || res.Body["average"] != 4.0 {
		t.Fatalf("Học vụ xem bài: %s", res.Raw)
	}
	p = hs.Do("GET", "/portal/surveys", nil)
	past := iamtest.List(t, p, "past_surveys")
	if len(past) != 1 || past[0]["title"] != "Khảo sát: Cơ sở vật chất tháng 10 - Bé An" || past[0]["rating"] != 4.0 {
		t.Fatalf("lịch sử khảo sát: %s", p.Raw)
	}
	ptMsg(t, hs.Do("DELETE", "/portal/surveys/"+past[0]["id"].(string), nil), 200, "Đã xóa bài khảo sát thành công! Bạn có thể thực hiện lại.")
	if n := w.count(`SELECT count(*) FROM ops.survey_responses`); n != 0 {
		t.Fatalf("bài khảo sát còn lại: %d", n)
	}
}

// Big Test trả kết quả trễ → biên bản (Lương & Phạt) cho GV chính, cập nhật mỗi ngày không lập trùng; đợt thi sắp tới chưa
// phân phối đề → việc "Duyệt & phân phối đề" (Công việc), tự đóng khi đề đã phân phối.
func TestWiringBigTestSla(t *testing.T) {
	w := wSetup(t)
	ctx := context.Background()
	hn := w.Branch("HN01")
	adminU, _ := w.Member("AD01", "Quản trị", nil, iam.RoleAdmin)
	gv, _ := w.Member("GV01", "Cô Lan", []uuid.UUID{hn}, iam.RoleTeacherFulltime)
	class := w.class("BT1", hn, gv.ID)
	w.student("HS001", "Bé An", hn, class)
	now := time.Now()
	var late, soon uuid.UUID
	w.scalar(&late, `INSERT INTO syllabus.big_tests (code, title, class_id, test_type, scheduled_at, room)
		VALUES ('BT-W-1', 'Đã thi', $1, 'stage_end', $2, 'P1') RETURNING id`, class, now.AddDate(0, 0, -10))
	w.scalar(&soon, `INSERT INTO syllabus.big_tests (code, title, class_id, test_type, scheduled_at, room)
		VALUES ('BT-W-2', 'Sắp thi', $1, 'stage_end', $2, 'P1') RETURNING id`, class, now.Add(48*time.Hour))

	st, err := w.syl.EnforceSLA(ctx, false)
	if err != nil || st.LateResults != 1 || st.TasksCreated != 1 {
		t.Fatalf("SLA Big Test: %+v %v", st, err)
	}
	var code, violation, category string
	var amount int64
	w.scalar(&code, `SELECT code FROM hr.penalties WHERE big_test_id = $1 AND auto_source = 'big_test_late_results' AND user_id = $2`, late, gv.ID)
	w.scalar(&violation, `SELECT violation_type FROM hr.penalties WHERE code = $1`, code)
	w.scalar(&category, `SELECT error_category FROM hr.penalties WHERE code = $1`, code)
	w.scalar(&amount, `SELECT amount FROM hr.penalties WHERE code = $1`, code)
	if violation != "Trả kết quả Big Test trễ 3 ngày (hạn 7 ngày)" || amount != 150000 || category != "academic" {
		t.Fatalf("biên bản trả kết quả trễ: %s %d %s", violation, amount, category)
	}
	if n := w.count(`SELECT count(*) FROM core.notifications WHERE user_id = $1 AND type = 'penalty_created' AND title = $2
		AND body LIKE 'Đã thi — trễ 3 ngày so với hạn %. Hãy hoàn tất kết quả và gửi giải trình.'`, gv.ID,
		"Biên bản "+code+": trả kết quả Big Test trễ hạn"); n != 1 {
		t.Fatalf("thông báo biên bản: %d", n)
	}
	var creator, assignee uuid.UUID
	var title, status string
	w.scalar(&title, `SELECT title FROM ops.work_tasks WHERE big_test_id = $1`, soon)
	w.scalar(&creator, `SELECT creator_id FROM ops.work_tasks WHERE big_test_id = $1`, soon)
	w.scalar(&assignee, `SELECT assignee_id FROM ops.work_tasks WHERE big_test_id = $1`, soon)
	if title != "Duyệt & phân phối đề Big Test BT-W-2: Lớp BT1" || creator != adminU.ID || assignee != adminU.ID {
		t.Fatalf("việc duyệt đề: %s %s %s", title, creator, assignee)
	}

	// Ngày hôm sau: trễ thêm 1 ngày → cập nhật biên bản cũ; đề đã phân phối → việc tự hoàn thành
	w.exec(`UPDATE syllabus.big_tests SET scheduled_at = scheduled_at - interval '1 day' WHERE id = $1`, late)
	w.exec(`UPDATE syllabus.big_tests SET is_distributed = true WHERE id = $1`, soon)
	if st, err = w.syl.EnforceSLA(ctx, false); err != nil || st.LateResults != 0 || st.TasksCreated != 0 || st.TasksClosed != 1 {
		t.Fatalf("SLA lần 2: %+v %v", st, err)
	}
	w.scalar(&violation, `SELECT violation_type FROM hr.penalties WHERE code = $1`, code)
	w.scalar(&amount, `SELECT amount FROM hr.penalties WHERE code = $1`, code)
	w.scalar(&status, `SELECT status FROM ops.work_tasks WHERE big_test_id = $1`, soon)
	if violation != "Trả kết quả Big Test trễ 4 ngày (hạn 7 ngày)" || amount != 200000 || status != "completed" ||
		w.count(`SELECT count(*) FROM hr.penalties`) != 1 {
		t.Fatalf("lần 2: %s %d %s", violation, amount, status)
	}
}
