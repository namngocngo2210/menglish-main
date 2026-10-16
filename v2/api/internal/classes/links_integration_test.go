package classes

import (
	"context"
	"errors"
	"io"
	"log/slog"
	"testing"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/catalogs"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/reports"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/tasks"
)

// Phần việc phân hệ Lớp học làm cho test đầu vào (placement.Sessions), báo cáo (reports.ClassDirectory, số liệu lớp)
// và công việc (tasks.ClassLookup).

func TestPlacementSessions(t *testing.T) {
	e := setup(t)
	ctx := context.Background()
	a := e.branch("CN-A")
	admin := e.as(e.member("MES001", nil, iam.RoleAdmin))
	gv := e.member("GV001", []uuid.UUID{a}, iam.RoleTeacherFulltime)
	gv2 := e.member("GV002", []uuid.UUID{a}, iam.RoleTeacherFulltime)
	id := e.newClass(admin, map[string]any{"name": "Movers A", "code": "MV-A", "branch_id": a, "teacher_id": gv.id, "program": "Cambridge Movers"})
	expectMessage(t, admin.do("POST", "/schedule-config", weekly(id, nil)), 200, "Đã lưu lịch và tạo 8 buổi học thực tế.")
	sub := e.sessionOn(id, "2026-10-21")
	e.exec("UPDATE academic.class_sessions SET teacher_id = $2 WHERE id = $1", sub, gv2.id)

	ps := e.svc.PlacementSessions()
	se, err := ps.Get(ctx, sub)
	if err != nil {
		t.Fatal(err)
	}
	if se == nil || se.ClassID != id || se.ClassName != "Movers A" || se.CourseName != "Cambridge Movers" || se.ClassStatus != StatusActive ||
		se.Status != "scheduled" || se.BranchID == nil || *se.BranchID != a || se.BranchName != "Chi nhánh CN-A" ||
		se.Date.Format("2006-01-02") != "2026-10-21" || se.Start != "18:00" || se.End != "19:30" || se.TeacherName != "Người GV002" {
		t.Fatalf("buổi học: %+v", se)
	}
	if se, err := ps.Get(ctx, uuid.New()); err != nil || se != nil {
		t.Fatalf("buổi không có: %v %v", se, err)
	}
	m, err := ps.Lookup(ctx, []uuid.UUID{sub, e.sessionOn(id, "2026-10-19"), uuid.New()})
	if err != nil || len(m) != 2 {
		t.Fatalf("tra nhiều buổi: %d %v", len(m), err)
	}

	sids, cids, err := ps.TeachingScope(ctx, gv2.id)
	if err != nil || len(sids) != 1 || sids[0] != sub || len(cids) != 0 {
		t.Fatalf("phạm vi dạy thay: %v %v %v", sids, cids, err)
	}
	sids, cids, err = ps.TeachingScope(ctx, gv.id)
	if err != nil || len(sids) != 7 || len(cids) != 1 || cids[0] != id {
		t.Fatalf("phạm vi GV chính: %d %v %v", len(sids), cids, err)
	}

	e.exec("UPDATE academic.classes SET deleted_at = now() WHERE id = $1", id)
	if se, _ := ps.Get(ctx, sub); se != nil {
		t.Fatalf("lớp đã xóa vẫn trả buổi: %+v", se)
	}
}

func item(t *testing.T, secs []reports.Section, sec, key string) reports.Item {
	t.Helper()
	for _, s := range secs {
		if s.Key != sec {
			continue
		}
		for _, it := range s.Items {
			if it.Key == key {
				return it
			}
		}
	}
	t.Fatalf("không có mục %s/%s", sec, key)
	return reports.Item{}
}

func count(it reports.Item) int {
	if it.Count == nil {
		return -1
	}
	return *it.Count
}

func TestReportClassesAndMetrics(t *testing.T) {
	e := setup(t)
	ctx := context.Background()
	a, b := e.branch("CN-A"), e.branch("CN-B")
	admin := e.as(e.member("MES001", nil, iam.RoleAdmin))
	gv := e.member("GV001", []uuid.UUID{a}, iam.RoleTeacherFulltime)
	gv2 := e.member("GV002", []uuid.UUID{a}, iam.RoleTeacherFulltime)
	sub := e.member("GV003", []uuid.UUID{a}, iam.RoleTeacherFulltime)
	hv := e.member("HV001", []uuid.UUID{a}, iam.RoleAcademicStaff)
	c1 := e.newClass(admin, map[string]any{"name": "Lớp Một", "code": "L1", "branch_id": a, "teacher_id": gv.id, "room_id": e.room(a, "A101")})
	c2 := e.newClass(admin, map[string]any{"name": "Lớp Hai", "code": "L2", "branch_id": a, "teacher_id": gv2.id})
	cb := e.newClass(admin, map[string]any{"name": "Lớp B", "code": "LB", "branch_id": b})
	for _, id := range []uuid.UUID{c1, c2, cb} {
		expectMessage(t, admin.do("POST", "/schedule-config", weekly(id, nil)), 200, "Đã lưu lịch và tạo 8 buổi học thực tế.")
	}

	// Danh mục lớp: giáo viên chỉ thấy lớp mình
	dir := e.svc.ReportClasses()
	opts, err := dir.Options(ctx, e.access(gv))
	if err != nil || len(opts) != 1 || opts[0].Value != c1.String() || opts[0].Label != "L1 · Lớp Một" {
		t.Fatalf("lớp gắn được: %v %v", opts, err)
	}
	if _, _, ok, _ := dir.Visible(ctx, e.access(gv), c2); ok {
		t.Fatal("giáo viên thấy lớp không phụ trách")
	}
	if code, name, ok, _ := dir.Visible(ctx, e.access(gv), c1); !ok || code != "L1" || name != "Lớp Một" {
		t.Fatalf("lớp của giáo viên: %s %s %v", code, name, ok)
	}
	if codes, _ := dir.Codes(ctx, []uuid.UUID{c1, cb}); codes[c1] != "L1" || codes[cb] != "LB" {
		t.Fatalf("mã lớp: %v", codes)
	}

	// Hôm nay 19/10: L1 đã điểm danh (1 vắng), L2 do GV003 dạy thay; lớp chi nhánh B không tính
	s1, s2 := e.sessionOn(c1, "2026-10-19"), e.sessionOn(c2, "2026-10-19")
	st := e.student("HV-00001", "Bé An", &a)
	e.exec("UPDATE academic.students SET current_class_id = $2 WHERE id = $1", st, c1)
	e.exec("INSERT INTO academic.enrollments (student_id, class_id, enrolled_at) VALUES ($1, $2, '2026-10-01')", st, c1)
	e.exec("UPDATE academic.class_sessions SET status = 'completed' WHERE id = $1", s1)
	e.exec("INSERT INTO academic.student_attendance (session_id, class_id, student_id, session_date, status) VALUES ($1, $2, $3, '2026-10-19', 'absent')", s1, c1, st)
	e.exec("UPDATE academic.class_sessions SET teacher_id = $2 WHERE id = $1", s2, sub.id)

	m := e.svc.ReportMetrics(reports.Skeleton(), e.iam.LoadAccess)
	w := reports.Writer{ID: hv.id, BranchIDs: []uuid.UUID{a}}
	day := time.Date(2026, 10, 19, 0, 0, 0, 0, time.UTC)
	secs, fields, err := m.Daily(ctx, w, day, reports.AudienceOperations)
	if err != nil {
		t.Fatal(err)
	}
	if it := item(t, secs, "operations", "classes_planned"); count(it) != 2 || it.Unavailable != "" {
		t.Fatalf("lớp kế hoạch: %+v", it)
	}
	if it := item(t, secs, "operations", "classes_actual"); count(it) != 1 {
		t.Fatalf("lớp thực tế: %+v", it)
	}
	list := item(t, secs, "operations", "classes_list").Details
	if len(list) != 2 || (list[0].Text != "Lớp Một · 18:00–19:30 · Phòng A101" && list[1].Text != "Lớp Một · 18:00–19:30 · Phòng A101") {
		t.Fatalf("danh sách lớp: %+v", list)
	}
	teachers := item(t, secs, "operations", "teachers")
	if count(teachers) != 2 || len(teachers.Details) != 1 || teachers.Details[0].Text != "Người GV003 dạy thay · Lớp Hai · 18:00–19:30" {
		t.Fatalf("giáo viên: %+v", teachers)
	}
	if sit := item(t, secs, "operations", "class_situation").Details; len(sit) != 1 || sit[0].Text != "Lớp Một: vắng 1 (Bé An)" {
		t.Fatalf("tình hình lớp: %+v", sit)
	}
	if fields["classes_planned"] != 2 || fields["teachers"] != 2 {
		t.Fatalf("số chính: %v", fields)
	}
	if it := item(t, secs, "enrollment", "new_data"); it.Unavailable == "" {
		t.Fatal("mục ngoài phân hệ lớp học vẫn theo provider gốc")
	}

	scale, err := m.WeeklyScale(ctx, w, day, day.AddDate(0, 0, 6))
	if err != nil {
		t.Fatal(err)
	}
	sw := []reports.Section{scale}
	if count(item(t, sw, "metrics", "classes_running")) != 2 || count(item(t, sw, "metrics", "students")) != 1 ||
		count(item(t, sw, "metrics", "teachers")) != 3 || count(item(t, sw, "metrics", "transfers")) != 0 {
		t.Fatalf("chỉ số quy mô: %+v", scale.Items)
	}

	tc, err := m.TeacherClasses(ctx, reports.Writer{ID: gv.id}, "2026-10")
	if err != nil || len(tc) != 1 || tc[0].ID != c1 || tc[0].Attendance == nil || *tc[0].Attendance != 0 || tc[0].SessionsDone != 1 {
		t.Fatalf("lớp của giáo viên trong tháng: %+v %v", tc, err)
	}
	tc, _ = m.TeacherClasses(ctx, reports.Writer{ID: sub.id}, "2026-10")
	if len(tc) != 1 || tc[0].ID != c2 || tc[0].Attendance != nil || tc[0].SessionsDone != 1 {
		t.Fatalf("lớp dạy thay: %+v", tc)
	}
	if m.Pending("classes") || !m.Pending("meetings") {
		t.Fatal("ghi chú nguồn số liệu")
	}
}

func TestTaskClasses(t *testing.T) {
	e := setup(t)
	ctx := context.Background()
	a, b := e.branch("CN-A"), e.branch("CN-B")
	admin := e.as(e.member("MES001", nil, iam.RoleAdmin))
	manager := e.member("QL001", []uuid.UUID{a}, iam.RoleManager)
	ta := e.member("TG001", []uuid.UUID{a}, iam.RoleAssistant)
	c1 := e.newClass(admin, map[string]any{"name": "Lớp Một", "code": "L1", "branch_id": a})
	c2 := e.newClass(admin, map[string]any{"name": "Lớp Hai", "code": "L2", "branch_id": a})
	cb := e.newClass(admin, map[string]any{"name": "Lớp B", "code": "LB", "branch_id": b})
	e.exec("UPDATE academic.classes SET status = 'active', schedule_text = 'T2-T4 18:00' WHERE id = $1", c1)
	e.exec("UPDATE academic.classes SET status = 'completed' WHERE id = $1", c2)
	e.exec("UPDATE academic.classes SET status = 'active' WHERE id IN ($1)", cb)

	// Buổi học theo ngày thật (giao việc / portal tính theo hôm nay): buổi hủy không đánh số, buổi 1 hôm nay, buổi 2 ngày mai.
	now := time.Now().In(vnZone)
	day := time.Date(now.Year(), now.Month(), now.Day(), 0, 0, 0, 0, time.UTC)
	today, tomorrow := day.Format(time.DateOnly), day.AddDate(0, 0, 1).Format(time.DateOnly)
	ins := func(class, branch uuid.UUID, date, status, room string) uuid.UUID {
		var id uuid.UUID
		e.scalar(&id, `INSERT INTO academic.class_sessions (class_id, branch_id, date, start_time, end_time, room, status)
			VALUES ($1, $2, $3, '18:00', '19:30', $4, $5) RETURNING id`, class, branch, date, room, status)
		return id
	}
	ins(c1, a, day.AddDate(0, 0, -1).Format(time.DateOnly), "cancelled", "A101")
	s1 := ins(c1, a, today, "scheduled", "A101")
	s2 := ins(c1, a, tomorrow, "scheduled", "A102")
	sb := ins(cb, b, today, "scheduled", "B1")
	label1 := "Buổi 1 (" + day.Format("02/01") + ")"

	tc := e.svc.TaskClasses()
	if ok, _ := tc.Visible(ctx, e.access(manager), cb); ok {
		t.Fatal("quản lý thấy lớp chi nhánh khác")
	}
	if ok, _ := tc.Exists(ctx, cb); !ok {
		t.Fatal("lớp có")
	}
	if se, err := tc.Session(ctx, s2); err != nil || se == nil || se.LessonNo != 2 || se.Room == nil || *se.Room != "A102" || se.BranchID != a {
		t.Fatalf("buổi học: %+v %v", se, err)
	}

	log := slog.New(slog.NewTextHandler(io.Discard, nil))
	svc := tasks.NewService(e.pg, log).WithClasses(tc)
	mgr := e.access(manager)

	opts, err := svc.TAAssignOptions(ctx, mgr)
	if err != nil {
		t.Fatal(err)
	}
	if len(opts.Classes) != 1 || opts.Classes[0].ID != c1 || opts.Classes[0].Label != "Lớp Một (L1) - T2-T4 18:00" {
		t.Fatalf("lớp gắn được: %+v", opts.Classes)
	}
	ss := opts.ClassSessions[c1.String()]
	if len(ss) != 2 || ss[0].ID != s1 || ss[0].Date != today || ss[0].Label != label1+" · 18:00-19:30" {
		t.Fatalf("buổi học để chọn: %+v", ss)
	}

	assign := func(items ...tasks.TATaskInput) error {
		_, err := svc.TAAssign(ctx, mgr, tasks.TAAssignInput{AssistantID: ta.id.String(), AssignDate: today, Tasks: items})
		return err
	}
	invalid := func(err error, msg string) {
		t.Helper()
		var ie *iam.Error
		if !errors.As(err, &ie) || ie.Message != msg {
			t.Fatalf("lỗi: %v, cần %q", err, msg)
		}
	}
	invalid(assign(tasks.TATaskInput{Category: "before", Content: "Mở phòng", AttachClass: true}),
		`Đầu việc #1: đã chọn "Gắn lớp" thì phải chọn lớp học.`)
	invalid(assign(tasks.TATaskInput{Category: "before", Content: "Mở phòng", AttachClass: true, ClassID: cb.String(), ClassSessionID: sb.String()}),
		"Đầu việc #1: lớp nằm ngoài phạm vi bạn quản lý.")
	invalid(assign(tasks.TATaskInput{Category: "before", Content: "a"}, tasks.TATaskInput{Category: "before", Content: "Mở phòng", AttachClass: true, ClassID: c1.String(), ClassSessionID: s2.String()}),
		"Đầu việc #2: buổi học không thuộc lớp hoặc không đúng ngày giao việc.")
	invalid(assign(tasks.TATaskInput{Category: "before", Content: "Mở phòng", AttachClass: true, ClassID: c1.String()}),
		"Đầu việc #1: vui lòng chọn buổi học.")
	if err := assign(
		tasks.TATaskInput{Category: "before", Content: "Mở phòng", AttachClass: true, ClassID: c1.String(), ClassSessionID: s1.String()},
		tasks.TATaskInput{Category: "after", Content: "Dọn phòng", AttachClass: true, ClassID: c1.String(), ClassSessionID: s1.String()},
		tasks.TATaskInput{Category: "during", Content: "Điểm danh", AttachClass: true, ClassID: c1.String(), Session: "Buổi ôn tập"},
		tasks.TATaskInput{Category: "during", Content: "Trực chung"},
	); err != nil {
		t.Fatal(err)
	}
	type row struct {
		Due    string
		Lesson *string
		Branch *uuid.UUID
	}
	var got row
	e.scalar(&got.Due, "SELECT to_char(due_time, 'HH24:MI') FROM ops.work_tasks WHERE title = 'Dọn phòng'")
	if got.Due != "20:30" {
		t.Fatalf("hạn ca sau giờ học: %s", got.Due)
	}
	e.scalar(&got.Due, "SELECT to_char(due_time, 'HH24:MI') FROM ops.work_tasks WHERE title = 'Mở phòng'")
	e.scalar(&got.Lesson, "SELECT lesson_session FROM ops.work_tasks WHERE title = 'Mở phòng'")
	e.scalar(&got.Branch, "SELECT branch_id FROM ops.work_tasks WHERE title = 'Mở phòng'")
	if got.Due != "18:00" || got.Lesson == nil || *got.Lesson != label1 || got.Branch == nil || *got.Branch != a {
		t.Fatalf("việc gắn buổi học: %+v", got)
	}
	e.scalar(&got.Due, "SELECT to_char(due_time, 'HH24:MI') FROM ops.work_tasks WHERE title = 'Điểm danh'")
	e.scalar(&got.Lesson, "SELECT lesson_session FROM ops.work_tasks WHERE title = 'Điểm danh'")
	if got.Due != "18:00" || got.Lesson == nil || *got.Lesson != "Buổi ôn tập" {
		t.Fatalf("việc gõ tên buổi: %+v", got)
	}

	// Portal trợ giảng: lớp trực hôm nay (lớp có việc giao) và thông tin lớp trên thẻ việc
	p, err := svc.TAPortal(ctx, e.access(ta), "", "")
	if err != nil {
		t.Fatal(err)
	}
	if len(p.Sessions) != 1 || p.Sessions[0].ID != s1 || p.Sessions[0].ClassName != "Lớp Một" || p.Sessions[0].Branch != "Chi nhánh CN-A" {
		t.Fatalf("lớp trực: %+v", p.Sessions)
	}
	card := p.Groups[0].Tasks[0]
	if card.ClassLabel == nil || *card.ClassLabel != "L1 · "+label1 || card.Room == nil || *card.Room != "A101" ||
		card.SessionTime == nil || *card.SessionTime != "18:00 - 19:30" {
		t.Fatalf("thẻ việc gắn lớp: %+v", card)
	}
	// Buổi mình được gán trợ giảng cũng hiện
	e.exec("UPDATE academic.class_sessions SET assistant_id = $2 WHERE id = $1", sb, ta.id)
	if p, _ = svc.TAPortal(ctx, e.access(ta), "", ""); len(p.Sessions) != 2 {
		t.Fatalf("buổi được gán: %+v", p.Sessions)
	}

	// Giao việc thường gắn lớp
	co, err := svc.CreateOptions(ctx, mgr)
	if err != nil || len(co.Classes) != 1 || co.Classes[0].Label != "Lớp Một (L1)" {
		t.Fatalf("lớp ở form giao việc: %+v %v", co.Classes, err)
	}
	in := tasks.CreateInput{Title: "Chuẩn bị tài liệu", AssigneeID: ta.id.String(), DueDate: tomorrow, TaskType: "one_time", ClassID: uuid.NewString()}
	_, err = svc.Create(ctx, mgr, in)
	invalid(err, "Lớp học đã chọn không tồn tại.")
	in.ClassID = c1.String()
	task, err := svc.Create(ctx, mgr, in)
	if err != nil || task.ClassID == nil || *task.ClassID != c1 || task.ClassLabel == nil || *task.ClassLabel != "Lớp Một" {
		t.Fatalf("việc gắn lớp: %+v %v", task, err)
	}
}

func TestTrialBookingsAndTaskAssistants(t *testing.T) {
	e := setup(t)
	ctx := context.Background()
	a := e.branch("CN-A")
	adm := e.member("MES001", nil, iam.RoleAdmin)
	admin := e.as(adm)
	ta := e.member("TG001", []uuid.UUID{a}, iam.RoleAssistant)
	id := e.newClass(admin, map[string]any{"name": "Lớp thử", "code": "LT", "branch_id": a})
	expectMessage(t, admin.do("POST", "/schedule-config", weekly(id, nil)), 200, "Đã lưu lịch và tạo 8 buổi học thực tế.")

	lead := e.lead("KH-0001", "Khách thử", a, "")
	book := func(session uuid.UUID, date string) uuid.UUID {
		var bid uuid.UUID
		e.scalar(&bid, `INSERT INTO crm.trial_bookings (lead_id, class_id, class_session_id, branch_id, session_date, start_time, end_time)
			VALUES ($1, $2, $3, $4, $5, '18:00', '19:30') RETURNING id`, lead, id, session, a, date)
		return bid
	}
	onHoliday := book(e.sessionOn(id, "2026-10-26"), "2026-10-26")
	kept := e.sessionOn(id, "2026-10-28")
	book(kept, "2026-10-28")

	// Nghỉ lễ: lịch hẹn học thử của buổi bị hủy cũng hủy
	hid, _, err := e.cat.CreateHoliday(ctx, e.access(adm), catalogs.HolidayInput{
		Code: "NL1", Name: "Nghỉ thử", StartDate: "2026-10-26", EndDate: "2026-10-26", IsSystemWide: true,
	})
	if err != nil {
		t.Fatal(err)
	}
	var status, notes string
	if err := e.pg.QueryRow(ctx, "SELECT status, coalesce(notes, '') FROM crm.trial_bookings WHERE id = $1", onHoliday).Scan(&status, &notes); err != nil {
		t.Fatal(err)
	}
	if status != "cancelled" || notes != "Hủy: buổi học thử trùng nghỉ lễ Nghỉ thử" {
		t.Fatalf("lịch học thử trùng nghỉ lễ: %s %q", status, notes)
	}
	// Gỡ ngày nghỉ: lịch hẹn vào buổi bù chuyển về buổi gốc
	moved := book(e.sessionOn(id, "2026-11-16"), "2026-11-16")
	if _, err := e.cat.DeleteHoliday(ctx, e.access(adm), hid); err != nil {
		t.Fatal(err)
	}
	var day string
	var session uuid.UUID
	if err := e.pg.QueryRow(ctx, "SELECT class_session_id, session_date::text FROM crm.trial_bookings WHERE id = $1", moved).Scan(&session, &day); err != nil {
		t.Fatal(err)
	}
	if session != e.sessionOn(id, "2026-10-26") || day != "2026-10-26" {
		t.Fatalf("lịch hẹn buổi bù: %s %s", session, day)
	}

	// Xếp lại TKB không xóa buổi đã có khách hẹn học thử
	expect(t, admin.do("POST", "/schedule-config", weekly(id, nil)), 200, "")
	var n int
	e.scalar(&n, "SELECT count(*) FROM academic.class_sessions WHERE id = $1", kept)
	if n != 1 {
		t.Fatal("buổi có khách hẹn học thử bị xóa khi xếp lại TKB")
	}

	// Trợ giảng của lớp = người được giao việc gắn lớp
	e.exec(`INSERT INTO ops.work_tasks (title, creator_id, assignee_id, class_id, time_slot_category, task_type, kind, due_date)
		VALUES ('Trực lớp', $1, $2, $3, 'during', 'one_time', 'ta_daily', '2026-10-21')`, adm.id, ta.id, id)
	if r := admin.do("GET", "/classes/"+id.String()+"?tab=overview", nil); jsonString(r.body["upcoming_assistants"]) != `["Người TG001"]` {
		t.Fatalf("trợ giảng 7 ngày tới: %v", r.body["upcoming_assistants"])
	}
	r := admin.do("GET", "/classes/"+id.String()+"?tab=schedule", nil)
	found := false
	for _, s := range list(r, "sessions") {
		if s["date"] == "2026-10-21" {
			found = s["assistants"] == "Người TG001"
		}
	}
	if !found {
		t.Fatalf("trợ giảng của buổi: %v", r.body["sessions"])
	}
}
