package classes

import (
	"context"
	"testing"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/reports"
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
