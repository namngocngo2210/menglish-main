package dashboard_test

import (
	"context"
	"crypto/rand"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"strings"
	"testing"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/catalogs"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/classes"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/crm"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/dashboard"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam/iamtest"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/inventory"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/secretbox"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/reports"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/students"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/tasks"
)

// Test HTTP với Postgres / Redis thật: Tổng quan theo vai trò (bảng điều hành Admin / Quản lý, Học thuật, Việc của bạn),
// phạm vi dữ liệu (chi nhánh, khách mình phụ trách, lớp mình dạy), băng báo cáo ngày, chất lượng giảng dạy, Báo cáo &
// sự vụ lớp. Các phân hệ nguồn dựng như cmd/menglish/main.go.

var vn = time.FixedZone("ICT", 7*3600)

type env struct {
	*iamtest.Env
	t   *testing.T
	ctx context.Context
	seq int
}

func setup(t *testing.T, opts ...func(*dashboard.Deps)) *env {
	t.Helper()
	e := iamtest.Setup(t, "crm.leads", "crm.trial_bookings", "ops.work_tasks", "ops.staff_reports", "ops.material_orders",
		"academic.students", "academic.classes")
	key := make([]byte, 32)
	_, _ = rand.Read(key)
	box, _ := secretbox.FromBase64(base64.StdEncoding.EncodeToString(key))
	cat := catalogs.NewService(e.Pool, box, e.Log)
	studentSvc := students.NewService(e.Pool, e.IAM, e.Log)
	cls := classes.NewService(e.Pool, cat, e.Log)
	taskSvc := tasks.NewService(e.Pool, e.Log).WithClasses(cls.TaskClasses())
	inv := inventory.NewService(e.Pool, e.Log, inventory.DefaultConfig())
	studentSvc.Classes, cat.Classes, inv.Classes = cls, cls, cls
	crmSvc := crm.NewService(e.Pool, e.IAM, e.Log).WithStudents(cls.CRMStudents(studentSvc))
	cls.CRM = crmSvc
	reportSvc := reports.NewService(e.Pool, e.Log, reports.Deps{
		Metrics: cls.ReportMetrics(reports.NewMetrics(crmSvc.ReportSource()), e.IAM.LoadAccess),
		Classes: cls.ReportClasses(), Tasks: taskSvc.ReportTasks(), Calendar: cat.ReportCalendar(),
	})
	deps := dashboard.Deps{
		Leads: crmSvc.DashboardLeads(), Tasks: taskSvc.DashboardTasks(), Classes: cls.DashboardClasses(),
		Students: studentSvc.DashboardStudents(), Reports: reportSvc.DashboardReports(), Orders: inv.DashboardOrders(),
		Branches: e.IAM,
	}
	for _, o := range opts {
		o(&deps)
	}
	svc := dashboard.NewService(deps, e.Log)
	e.Serve(dashboard.NewHandler(svc, e.Log).Routes)
	return &env{Env: e, t: t, ctx: context.Background()}
}

func (e *env) exec(sql string, args ...any) {
	e.t.Helper()
	if _, err := e.Pool.Exec(e.ctx, sql, args...); err != nil {
		e.t.Fatalf("%s: %v", sql, err)
	}
}

func (e *env) id(sql string, args ...any) uuid.UUID {
	e.t.Helper()
	var id uuid.UUID
	if err := e.Pool.QueryRow(e.ctx, sql, args...).Scan(&id); err != nil {
		e.t.Fatalf("%s: %v", sql, err)
	}
	return id
}

func (e *env) next() int { e.seq++; return e.seq }

func today() time.Time {
	n := time.Now().In(vn)
	return time.Date(n.Year(), n.Month(), n.Day(), 0, 0, 0, 0, vn)
}

// laterToday: now+d nhưng không qua nửa đêm giờ VN (test chạy sau ~22h vẫn có lịch hẹn "hôm nay").
func laterToday(d time.Duration) time.Time {
	t, end := time.Now().Add(d), today().Add(24*time.Hour-time.Second)
	if t.After(end) {
		return end
	}
	return t
}

func (e *env) lead(name string, branch uuid.UUID, owner *uuid.UUID, cols map[string]any) uuid.UUID {
	e.t.Helper()
	n := e.next()
	names := []string{"code", "name", "phone", "branch_id", "assigned_user_id"}
	vals := []any{fmt.Sprintf("KH-T%04d", n), name, fmt.Sprintf("09%08d", n), branch, owner}
	for k, v := range cols {
		names = append(names, k)
		vals = append(vals, v)
	}
	ph := make([]string, len(vals))
	for i := range vals {
		ph[i] = fmt.Sprintf("$%d", i+1)
	}
	return e.id("INSERT INTO crm.leads ("+strings.Join(names, ", ")+") VALUES ("+strings.Join(ph, ", ")+") RETURNING id", vals...)
}

func (e *env) class(code string, branch uuid.UUID, teacher *uuid.UUID, status string) uuid.UUID {
	return e.id(`INSERT INTO academic.classes (code, name, branch_id, teacher_id, status) VALUES ($1, $2, $3, $4, $5) RETURNING id`,
		code, "Lớp "+code, branch, teacher, status)
}

func (e *env) student(branch uuid.UUID, class *uuid.UUID, status string) uuid.UUID {
	n := e.next()
	return e.id(`INSERT INTO academic.students (code, full_name, phone, branch_id, current_class_id, status) VALUES ($1, $2, $3, $4, $5, $6) RETURNING id`,
		fmt.Sprintf("HV-T%04d", n), fmt.Sprintf("Học viên %d", n), fmt.Sprintf("08%08d", n), branch, class, status)
}

func (e *env) session(class, branch uuid.UUID, day time.Time, start string) uuid.UUID {
	return e.id(`INSERT INTO academic.class_sessions (class_id, branch_id, date, start_time, end_time) VALUES ($1, $2, $3, $4::time, $4::time + interval '90 minutes') RETURNING id`,
		class, branch, day, start)
}

func (e *env) attend(session, class, student uuid.UUID, day time.Time, status string) {
	e.exec(`INSERT INTO academic.student_attendance (session_id, class_id, student_id, session_date, status) VALUES ($1, $2, $3, $4, $5)`,
		session, class, student, day, status)
}

func (e *env) task(title string, creator uuid.UUID, assignee *uuid.UUID, branch *uuid.UUID, status string, due *time.Time) uuid.UUID {
	return e.id(`INSERT INTO ops.work_tasks (title, creator_id, assignee_id, branch_id, status, due_date) VALUES ($1, $2, $3, $4, $5, $6) RETURNING id`,
		title, creator, assignee, branch, status, due)
}

func get[T any](t *testing.T, c *iamtest.Client, path string) T {
	t.Helper()
	r := c.Do("GET", path, nil)
	iamtest.Expect(t, r, 200, "")
	var out T
	if err := json.Unmarshal(r.Raw, &out); err != nil {
		t.Fatal(err)
	}
	return out
}

func statByLabel(t *testing.T, d *dashboard.RoleDashboard, prefix string) dashboard.Stat {
	t.Helper()
	for _, s := range d.Stats {
		if strings.HasPrefix(s.Label, prefix) {
			return s
		}
	}
	t.Fatalf("không có thẻ %q trong %+v", prefix, d.Stats)
	return dashboard.Stat{}
}

func queueByLabel(t *testing.T, d *dashboard.RoleDashboard, label string) dashboard.Queue {
	t.Helper()
	for _, q := range d.Queues {
		if q.Label == label {
			return q
		}
	}
	t.Fatalf("không có hàng chờ %q trong %+v", label, d.Queues)
	return dashboard.Queue{}
}

func val(s dashboard.Stat) string {
	if s.Value == nil {
		return "<nil>"
	}
	return *s.Value
}

func TestPermissions(t *testing.T) {
	e := setup(t)
	b := e.Branch("A")
	_, student := e.Member("HV001", "Học viên", []uuid.UUID{b}, iam.RoleStudent)
	_, teacher := e.Member("GV001", "Giáo viên", []uuid.UUID{b}, iam.RoleTeacherFulltime)

	iamtest.Expect(t, e.Anon().Do("GET", "/dashboard", nil), 401, "unauthenticated")
	// Học viên / phụ huynh không có Tổng quan của nhân sự (Laravel chuyển sang cổng học viên).
	iamtest.Expect(t, student.Do("GET", "/dashboard", nil), 403, "forbidden")
	iamtest.Expect(t, teacher.Do("GET", "/dashboard", nil), 200, "")
	// Báo cáo & sự vụ lớp cần class.update.
	iamtest.Expect(t, teacher.Do("GET", "/academic/dashboards/reports", nil), 403, "forbidden")
	iamtest.Expect(t, teacher.Do("GET", "/academic/dashboards/incidents", nil), 403, "forbidden")
	e.Grant(iam.RoleTeacherFulltime, "class.update")
	iamtest.Expect(t, teacher.Do("GET", "/academic/dashboards/reports", nil), 200, "")
}

func TestOperationsScope(t *testing.T) {
	e := setup(t)
	a, b := e.Branch("A"), e.Branch("B")
	admin, adminC := e.Member("AD001", "Admin", []uuid.UUID{a}, iam.RoleAdmin)
	mgr, mgrC := e.Member("QL001", "Quản lý A", []uuid.UUID{a}, iam.RoleManager)
	staffB := e.User("HVU01", "Học vụ B", []uuid.UUID{b}, iam.RoleAcademicStaff)

	ca, cb := e.class("A1", a, nil, "active"), e.class("B1", b, nil, "active")
	e.class("A2", a, nil, "completed")
	e.student(a, &ca, "studying")
	e.student(a, &ca, "studying")
	e.student(b, &cb, "studying")
	e.student(a, nil, "waiting_start")
	e.lead("Khách A", a, &mgr.ID, nil)
	e.lead("Khách B", b, nil, nil)
	e.lead("Khách cũ", a, nil, map[string]any{"created_at": today().AddDate(0, -2, 0)})
	// Hẹn test hôm nay ở chi nhánh A (một khách chưa làm bài)
	e.lead("Hẹn test", a, nil, map[string]any{"stage": "test_scheduled", "appointment_at": laterToday(time.Hour)})

	yesterday := today().AddDate(0, 0, -1)
	e.task("Quá hạn A", admin.ID, &mgr.ID, &a, "new", &yesterday)
	e.task("Quá hạn B (người nhận chi nhánh B)", admin.ID, &staffB.ID, nil, "overdue", nil)
	e.task("Chờ xác nhận A", admin.ID, &mgr.ID, &a, "pending_confirmation", nil)
	e.exec(`UPDATE ops.work_tasks SET kind = 'ta_daily' WHERE id = $1`, e.task("Nhiệm vụ TA", admin.ID, &mgr.ID, &a, "new", &yesterday))

	all := get[dashboard.Overview](t, adminC, "/dashboard")
	if !all.IsOperations || all.RoleDashboard.Type != "admin" || all.RoleDashboard.Scope != "Toàn hệ thống" {
		t.Fatalf("Admin: %+v", all.RoleDashboard)
	}
	d := all.RoleDashboard
	if v := val(statByLabel(t, d, "Học viên đang học")); v != "3" {
		t.Errorf("học viên đang học toàn hệ thống = %s", v)
	}
	if v := val(statByLabel(t, d, "Lead mới trong tháng")); v != "3" {
		t.Errorf("lead mới = %s", v)
	}
	if v := val(statByLabel(t, d, "Lớp đang chạy")); v != "2" {
		t.Errorf("lớp đang chạy = %s", v)
	}
	if v := val(statByLabel(t, d, "Việc quá hạn")); v != "2" {
		t.Errorf("việc quá hạn (bỏ nhiệm vụ TA) = %s", v)
	}
	if rev := statByLabel(t, d, "Doanh thu tháng"); rev.Value != nil || rev.Pending == nil || *rev.Pending != "Chờ phân hệ Học phí" {
		t.Errorf("doanh thu phải chờ phân hệ Học phí: %+v", rev)
	}
	if q := queueByLabel(t, d, "Việc chờ xác nhận"); q.Value == nil || *q.Value != 1 || *q.Href != "/tasks/approvals" {
		t.Errorf("việc chờ xác nhận: %+v", q)
	}
	if q := queueByLabel(t, d, "Lịch hẹn test hôm nay"); *q.Value != 1 || q.Hint == nil || *q.Hint != "1 chưa làm bài" {
		t.Errorf("hẹn test hôm nay: %+v", q)
	}
	if len(d.OverdueTasks) != 2 {
		t.Errorf("danh sách việc quá hạn: %+v", d.OverdueTasks)
	}
	if all.Teaching == nil || all.Teaching.Team == nil || all.Teaching.TeacherReports == nil {
		t.Errorf("bảng điều hành có khối chất lượng giảng dạy + báo cáo tháng GV: %+v", all.Teaching)
	}

	// Quản lý cơ sở: chỉ chi nhánh A.
	m := get[dashboard.Overview](t, mgrC, "/dashboard").RoleDashboard
	if m.Type != "manager" || m.Scope != "Chi nhánh A" {
		t.Fatalf("Quản lý: %+v", m)
	}
	for label, want := range map[string]string{"Học viên đang học": "2", "Lead mới trong tháng": "2", "Lớp đang chạy": "1", "Việc quá hạn": "1"} {
		if v := val(statByLabel(t, m, label)); v != want {
			t.Errorf("Quản lý %s = %s, muốn %s", label, v, want)
		}
	}
	if len(m.OverdueTasks) != 1 || m.OverdueTasks[0].Title != "Quá hạn A" {
		t.Errorf("việc quá hạn chi nhánh A: %+v", m.OverdueTasks)
	}
}

func TestPersonalBoardScope(t *testing.T) {
	e := setup(t)
	a := e.Branch("A")
	sales, salesC := e.Member("SA001", "Sales 1", []uuid.UUID{a}, iam.RoleSalesConsultant)
	other := e.User("SA002", "Sales 2", []uuid.UUID{a}, iam.RoleSalesConsultant)
	boss := e.User("AD001", "Admin", []uuid.UUID{a}, iam.RoleAdmin)

	now := time.Now()
	e.lead("Khách hẹn test", a, &sales.ID, map[string]any{"stage": "test_scheduled", "appointment_at": laterToday(2 * time.Hour), "appointment_type": "online"})
	e.lead("Khách người khác", a, &other.ID, map[string]any{"stage": "test_scheduled", "appointment_at": laterToday(2 * time.Hour)})
	e.lead("Gọi lại quá hạn", a, &sales.ID, map[string]any{"stage": "consulting", "next_follow_up_at": now.Add(-time.Hour)})
	e.lead("Gọi lại ngày mai", a, &sales.ID, map[string]any{"stage": "consulting", "next_follow_up_at": today().AddDate(0, 0, 1).Add(10 * time.Hour)})
	e.lead("Khách mới chưa gọi", a, &sales.ID, map[string]any{"created_at": now.Add(-30 * time.Hour)})
	e.lead("Khách chốt", a, &sales.ID, map[string]any{"stage": "won", "converted_at": now})
	trialLead := e.lead("Khách học thử", a, &sales.ID, map[string]any{"stage": "consulting"})
	cls := e.class("T1", a, nil, "active")
	day := today().AddDate(0, 0, 2)
	sess := e.session(cls, a, day, "18:00")
	e.exec(`INSERT INTO crm.trial_bookings (lead_id, class_id, class_session_id, session_date, start_time, end_time) VALUES ($1, $2, $3, $4, '18:00', '19:30')`,
		trialLead, cls, sess, day)
	thisWeek := today()
	e.task("Việc của tôi", boss.ID, &sales.ID, nil, "in_progress", &thisWeek)
	e.task("Việc không hạn", boss.ID, &sales.ID, nil, "new", nil)
	e.task("Việc đã xong", boss.ID, &sales.ID, nil, "completed", &thisWeek)

	ov := get[dashboard.Overview](t, salesC, "/dashboard")
	d := ov.RoleDashboard
	if ov.IsOperations || d.Type != "personal" || d.Scope != "Khách và việc bạn phụ trách" {
		t.Fatalf("Sales: %+v", d)
	}
	if s := statByLabel(t, d, "Hẹn test hôm nay"); val(s) != "1" || *s.Hint != "1 chưa làm bài" || *s.Href != "/crm/customers?chip=test_today" {
		t.Errorf("hẹn test hôm nay (chỉ khách của mình): %+v", s)
	}
	if s := statByLabel(t, d, "Học thử 7 ngày tới"); val(s) != "1" {
		t.Errorf("học thử: %+v", s)
	}
	if s := statByLabel(t, d, "Cần gọi lại hôm nay"); val(s) != "1" || s.Tone != "error" || *s.Hint != "1 khách đã quá hạn" {
		t.Errorf("cần gọi lại: %+v", s)
	}
	if s := statByLabel(t, d, "Việc của tôi đến hạn tuần này"); val(s) != "1" {
		t.Errorf("việc của tôi trong tuần: %+v", s)
	}
	if s := statByLabel(t, d, "Khách chốt tháng"); val(s) != "1" {
		t.Errorf("khách chốt: %+v", s)
	}
	if q := queueByLabel(t, d, "Khách chưa liên hệ >24h"); *q.Value != 1 {
		t.Errorf("khách chưa liên hệ: %+v", q)
	}
	if d.MyTasks == nil || d.MyTasks.Total != 2 || len(d.MyTasks.Items) != 2 || d.MyTasks.Items[0].Title != "Việc của tôi" {
		t.Errorf("việc của tôi (có hạn trước, không hạn sau, bỏ việc xong): %+v", d.MyTasks)
	}
	if d.Agenda == nil {
		t.Fatal("thiếu lịch hẹn")
	}
	var titles []string
	for _, day := range d.Agenda.Days {
		for _, it := range day.Items {
			titles = append(titles, it.KindLabel+":"+it.Title)
		}
	}
	joined := strings.Join(titles, "|")
	for _, want := range []string{"Test đầu vào:Khách hẹn test", "Gọi lại:Gọi lại ngày mai", "Học thử:Khách học thử"} {
		if !strings.Contains(joined, want) {
			t.Errorf("lịch hẹn thiếu %q: %s", want, joined)
		}
	}
	if strings.Contains(joined, "Khách người khác") {
		t.Errorf("lịch hẹn lộ khách của người khác: %s", joined)
	}
	if d.Agenda.Days[0].Label[:len("Hôm nay")] != "Hôm nay" {
		t.Errorf("ngày đầu phải là Hôm nay: %+v", d.Agenda.Days[0])
	}
	// Sales không phải nộp báo cáo ngày, không dạy lớp.
	if ov.DailyReport != nil || ov.Teaching != nil {
		t.Errorf("Sales không có băng báo cáo ngày / chất lượng giảng dạy: %+v %+v", ov.DailyReport, ov.Teaching)
	}
}

func TestPostCloseCare(t *testing.T) {
	e := setup(t)
	a := e.Branch("A")
	staff, staffC := e.Member("HVU01", "Học vụ", []uuid.UUID{a}, iam.RoleAcademicStaff)
	cls := e.class("C1", a, nil, "active")
	st := e.student(a, &cls, "waiting_start")
	e.session(cls, a, today().AddDate(0, 0, 3), "18:30")
	e.lead("Khách vừa chốt", a, &staff.ID, map[string]any{"stage": "won", "converted_at": time.Now().AddDate(0, 0, -25), "converted_student_id": st})
	ov := get[dashboard.Overview](t, staffC, "/dashboard")
	var care []dashboard.AgendaEntry
	for _, day := range ov.RoleDashboard.Agenda.Days {
		for _, it := range day.Items {
			if it.Kind == "care" {
				care = append(care, it)
			}
		}
	}
	// Buổi 1 vào buổi học đầu tiên (18:30) + Đủ 30 ngày (ngày chốt + 30, trong 7 ngày tới).
	if len(care) != 2 || care[0].Time == nil || *care[0].Time != "18:30" || !strings.HasPrefix(care[0].Subtitle, "Buổi học đầu tiên") ||
		care[1].Time != nil || !strings.HasPrefix(care[1].Subtitle, "Đủ 30 ngày") {
		t.Fatalf("support sau chốt: %+v", care)
	}
	// Đã tick checklist CRM thì không còn nhắc.
	e.exec(`UPDATE crm.leads SET care_checklist = '{"session_1": {"done_at": "2026-10-01"}, "day_30": {"done_at": "2026-10-01"}}'`)
	ov = get[dashboard.Overview](t, staffC, "/dashboard")
	for _, day := range ov.RoleDashboard.Agenda.Days {
		for _, it := range day.Items {
			if it.Kind == "care" {
				t.Errorf("mốc đã tick vẫn hiện: %+v", it)
			}
		}
	}
}

func TestDailyReportBanner(t *testing.T) {
	e := setup(t)
	a := e.Branch("A")
	staff, staffC := e.Member("HVU01", "Học vụ", []uuid.UUID{a}, iam.RoleAcademicStaff)
	// Tài khoản tạo từ 20 ngày trước: 14 ngày gần nhất đều phải nộp (trừ Chủ nhật).
	e.exec(`UPDATE iam.users SET created_at = now() - interval '20 days' WHERE id = $1`, staff.ID)
	t0 := today()
	var submitted []time.Time
	for i := 2; i <= 14; i++ {
		submitted = append(submitted, t0.AddDate(0, 0, -i))
	}
	for _, d := range submitted {
		key := d.Format("2006-01-02")
		e.exec(`INSERT INTO ops.staff_reports (user_id, type, kind, period_key, title, report_date, status) VALUES ($1, 'daily', 'daily_ops', $2, 'Báo cáo ngày', $3, 'submitted')`,
			staff.ID, key, d)
	}
	returned := t0.AddDate(0, 0, -3)
	e.exec(`UPDATE ops.staff_reports SET status = 'returned', review_note = 'Bổ sung số liệu tuyển sinh' WHERE report_date = $1`, returned)

	ov := get[dashboard.Overview](t, staffC, "/dashboard")
	dr := ov.DailyReport
	if dr == nil {
		t.Fatal("Học vụ phải có băng báo cáo ngày")
	}
	yesterday := t0.AddDate(0, 0, -1)
	wantMissing := 0
	if yesterday.Weekday() != time.Sunday {
		wantMissing = 1
	}
	if len(dr.Missing) != wantMissing {
		t.Errorf("ngày còn thiếu = %+v, muốn %d", dr.Missing, wantMissing)
	}
	if wantMissing == 1 && (dr.Missing[0].Label != yesterday.Format("02/01") || dr.Missing[0].Href != "/reports/daily?date="+yesterday.Format("2006-01-02")) {
		t.Errorf("ngày thiếu: %+v", dr.Missing[0])
	}
	if len(dr.Returned) != 1 || dr.Returned[0].Note == nil || *dr.Returned[0].Note != "Bổ sung số liệu tuyển sinh" {
		t.Errorf("báo cáo cần bổ sung: %+v", dr.Returned)
	}
	if dr.TodaySubmitted || dr.TodayHref != "/reports/daily?date="+t0.Format("2006-01-02") {
		t.Errorf("hôm nay: %+v", dr)
	}
}

func TestTeachingQuality(t *testing.T) {
	e := setup(t)
	a, b := e.Branch("A"), e.Branch("B")
	gv, gvC := e.Member("GV001", "Giáo viên Một", []uuid.UUID{a}, iam.RoleTeacherFulltime)
	gv2 := e.User("GV002", "Giáo viên Hai", []uuid.UUID{b}, iam.RoleTeacherFulltime)
	_, leadC := e.Member("HT001", "Học thuật", []uuid.UUID{a}, iam.RoleAcademicLead)

	c1 := e.class("G1", a, &gv.ID, "active")
	c2 := e.class("G2", b, &gv2.ID, "active")
	s1, s2 := e.student(a, &c1, "studying"), e.student(a, &c1, "studying")
	e.student(b, &c2, "studying")
	day := today()
	if day.Day() > 1 {
		day = day.AddDate(0, 0, -1)
	}
	sess := e.session(c1, a, day, "08:00")
	e.attend(sess, c1, s1, day, "present")
	e.attend(sess, c1, s2, day, "absent")
	month := day.Format("2006-01")
	e.exec(`INSERT INTO ops.staff_reports (user_id, type, kind, period_key, title, report_date, status, data) VALUES ($1, 'monthly', 'teacher_monthly', $2, 'Báo cáo tháng', $3, 'submitted', $4)`,
		gv.ID, month, day, fmt.Sprintf(`{"general": {"difficulties": "Lớp đông"}, "classes": {"%s": {"situation": "Học viên nghỉ nhiều", "need_support": true}}}`, c1))
	e.exec(`INSERT INTO ops.material_orders (code, branch_id, class_id, requester_id, category, title, use_date, due_at) VALUES ('OH-2026-0001', $1, $2, $3, 'academic', 'In đề kiểm tra', $4, now())`,
		a, c1, gv.ID, day)

	// Giáo viên: số liệu của mình, link viết báo cáo tháng; không có bảng theo giáo viên.
	ov := get[dashboard.Overview](t, gvC, "/dashboard?month="+month)
	tq := ov.Teaching
	if tq == nil || tq.Mine == nil || tq.Team != nil {
		t.Fatalf("giáo viên: %+v", tq)
	}
	if tq.Mine.Totals.Classes != 1 || tq.Mine.Totals.Students != 2 || tq.Mine.Totals.Attendance == nil || *tq.Mine.Totals.Attendance != 50 {
		t.Errorf("số liệu lớp của giáo viên: %+v", tq.Mine.Totals)
	}
	if tq.Mine.Work != nil || tq.ReportURL == nil || *tq.ReportURL != "/reports/teacher-monthly?month="+month {
		t.Errorf("chấm công chờ phân hệ, link báo cáo tháng: %+v %v", tq.Mine.Work, tq.ReportURL)
	}

	// Học thuật (lớp toàn hệ thống): bảng theo giáo viên + báo cáo tháng + order học thuật.
	ov = get[dashboard.Overview](t, leadC, "/dashboard?month="+month)
	if ov.RoleDashboard.Type != "academic" || ov.RoleDashboard.Agenda == nil || ov.RoleDashboard.MyTasks == nil {
		t.Fatalf("Học thuật: %+v", ov.RoleDashboard)
	}
	tq = ov.Teaching
	if tq == nil || tq.Team == nil || len(tq.Team.Rows) != 2 || tq.Team.Rows[0].Name != "Giáo viên Hai" {
		t.Fatalf("bảng theo giáo viên: %+v", tq)
	}
	if len(tq.TeacherReports) != 2 || !tq.TeacherReports[0].Submitted || tq.TeacherReports[0].Teacher != "Giáo viên Một" ||
		len(tq.TeacherReports[0].Classes) != 1 || !tq.TeacherReports[0].Classes[0].NeedSupport || tq.TeacherReports[0].Classes[0].Class != "Lớp G1" ||
		tq.TeacherReports[1].Submitted {
		t.Errorf("báo cáo tháng giáo viên: %+v", tq.TeacherReports)
	}
	if len(tq.AcademicOrders) != 1 || tq.AcademicOrders[0].Title != "In đề kiểm tra" || tq.OrdersURL == nil {
		t.Errorf("order học thuật: %+v", tq.AcademicOrders)
	}
	// Tháng sai định dạng → tháng hiện tại.
	ov = get[dashboard.Overview](t, leadC, "/dashboard?month=2026-13")
	if ov.Teaching.Month != time.Now().In(vn).Format("2006-01") {
		t.Errorf("tháng sai định dạng phải về tháng hiện tại: %s", ov.Teaching.Month)
	}
}

func TestAcademicReportsAndIncidents(t *testing.T) {
	e := setup(t)
	a, b := e.Branch("A"), e.Branch("B")
	staff, staffC := e.Member("HVU01", "Học vụ", []uuid.UUID{a}, iam.RoleAcademicStaff)
	staffB := e.User("HVU02", "Học vụ B", []uuid.UUID{b}, iam.RoleAcademicStaff)
	ca := e.class("R1", a, nil, "active")
	e.class("R2", b, nil, "completed")
	t0 := today()
	e.exec(`INSERT INTO ops.staff_reports (user_id, type, kind, period_key, title, report_date, status, data) VALUES ($1, 'daily', 'daily_ops', $2, 'Báo cáo ngày', $3, 'submitted', $4)`,
		staff.ID, t0.Format("2006-01-02"), t0, `{"fields": {"classes_planned": 5, "classes_actual": 4, "teachers": 3, "assistants": 2, "new_data": 7}, "task_ids": ["0192a9a0-0000-7000-8000-000000000001"]}`)
	e.exec(`INSERT INTO ops.staff_reports (user_id, type, kind, period_key, title, report_date, status) VALUES ($1, 'daily', 'daily_ops', $2, 'Bản nháp', $3, 'draft')`,
		staffB.ID, t0.Format("2006-01-02"), t0)
	e.exec(`INSERT INTO ops.staff_reports (user_id, type, kind, period_key, title, report_date, status) VALUES ($1, 'weekly', 'weekly_kpi', '2026-W42', 'Tuần KPI', $2, 'submitted')`, staff.ID, t0)
	e.exec(`INSERT INTO ops.staff_reports (user_id, type, title, report_date, status, severity, class_id) VALUES ($1, 'journal', 'Phụ huynh phàn nàn', $2, 'open', 'urgent', $3)`, staff.ID, t0, ca)
	e.exec(`INSERT INTO ops.staff_reports (user_id, type, title, report_date, status, severity) VALUES ($1, 'journal', 'Mất điện chi nhánh B', $2, 'resolved', 'normal')`, staffB.ID, t0)

	r := get[dashboard.AcademicReports](t, staffC, "/academic/dashboards/reports")
	if r.Tab != "daily" || len(r.StaffDailyReports) != 1 || r.TotalClasses != 2 || r.ActiveClasses != 1 || r.TotalDailyReportsToday != 2 {
		t.Fatalf("báo cáo ngày: %+v", r)
	}
	dr := r.StaffDailyReports[0]
	if !dr.Structured || *dr.Classes != "4 / 5" || *dr.Staff != "3 GV · 2 TG" || *dr.Enrollment != "Data 7" || !dr.HasTask {
		t.Errorf("dòng báo cáo ngày: %+v", dr)
	}
	r = get[dashboard.AcademicReports](t, staffC, "/academic/dashboards/reports?tab=weekly")
	if len(r.WeeklyKpiReports) != 1 || len(r.WeeklyReports) != 0 || r.WeeklyKpiReports[0].StatusLabel == "" {
		t.Errorf("tab tuần: %+v", r)
	}
	if r = get[dashboard.AcademicReports](t, staffC, "/academic/dashboards/reports?tab=khac"); r.Tab != "daily" {
		t.Errorf("tab lạ phải về daily: %s", r.Tab)
	}

	in := get[dashboard.Incidents](t, staffC, "/academic/dashboards/incidents")
	if len(in.Journals) != 2 || in.TotalIncidents != 2 || in.ResolvedCount != 1 || in.OpenCount != 1 || in.UrgentCount != 1 || !in.CanSubmitJournal {
		t.Fatalf("sự vụ: %+v", in)
	}
	in = get[dashboard.Incidents](t, staffC, "/academic/dashboards/incidents?severity=urgent")
	if len(in.Journals) != 1 || in.Journals[0].Priority == nil || *in.Journals[0].Priority != "urgent" || *in.Journals[0].ClassCode != "R1" {
		t.Errorf("lọc khẩn cấp: %+v", in.Journals)
	}
	// Lọc cơ sở B: nhật ký không gắn lớp theo chi nhánh chính của người ghi.
	in = get[dashboard.Incidents](t, staffC, "/academic/dashboards/incidents?branch_id="+b.String())
	if len(in.Journals) != 1 || in.Journals[0].Title != "Mất điện chi nhánh B" || *in.Journals[0].Branch != "Chi nhánh B" {
		t.Errorf("lọc cơ sở B: %+v", in.Journals)
	}
	if in = get[dashboard.Incidents](t, staffC, "/academic/dashboards/incidents?severity=low"); len(in.Journals) != 0 {
		t.Errorf("mức thấp không có nhật ký: %+v", in.Journals)
	}
	if in = get[dashboard.Incidents](t, staffC, "/academic/dashboards/incidents?status=open&severity=bogus"); in.Severity != "all" || len(in.Journals) != 1 {
		t.Errorf("lọc chưa xử lý: %+v", in)
	}
}
