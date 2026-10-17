package classes

import (
	"context"
	"testing"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// Phần việc cho KPI: chuyên cần lớp giáo viên dạy, rà soát điểm danh theo phạm vi lớp, số liệu bảng KPI tự động.
func TestKpiAdapters(t *testing.T) {
	e := setup(t)
	ctx := context.Background()
	a, b := e.branch("CN-A"), e.branch("CN-B")
	admin := e.member("MES001", nil, iam.RoleAdmin)
	adminC := e.as(admin)
	gv := e.member("GV001", []uuid.UUID{a}, iam.RoleTeacherFulltime)
	c1 := e.newClass(adminC, map[string]any{"name": "Lớp Một", "code": "L1", "branch_id": a, "teacher_id": gv.id})
	cb := e.newClass(adminC, map[string]any{"name": "Lớp B", "code": "LB", "branch_id": b})
	for _, id := range []uuid.UUID{c1, cb} {
		expectMessage(t, adminC.do("POST", "/schedule-config", weekly(id, nil)), 200, "Đã lưu lịch và tạo 8 buổi học thực tế.")
	}
	s1, sb := e.sessionOn(c1, "2026-10-19"), e.sessionOn(cb, "2026-10-19")
	enroll := func(code, name string, branch, class uuid.UUID, status string) uuid.UUID {
		st := e.student(code, name, &branch)
		e.exec("UPDATE academic.students SET current_class_id = $2, status = $3 WHERE id = $1", st, class, status)
		e.exec("INSERT INTO academic.enrollments (student_id, class_id, enrolled_at) VALUES ($1, $2, '2026-10-01')", st, class)
		return st
	}
	st1 := enroll("HV-00001", "Bé An", a, c1, "studying")
	st2 := enroll("HV-00002", "Bé Bình", a, c1, "dropped")
	st3 := enroll("HV-00003", "Bé Cúc", b, cb, "studying")
	var att1, attB uuid.UUID
	e.scalar(&att1, `INSERT INTO academic.student_attendance (session_id, class_id, student_id, session_date, status, teacher_id)
		VALUES ($1, $2, $3, '2026-10-19', 'present', $4) RETURNING id`, s1, c1, st1, gv.id)
	e.exec("INSERT INTO academic.student_attendance (session_id, class_id, student_id, session_date, status) VALUES ($1, $2, $3, '2026-10-19', 'absent')", s1, c1, st2)
	e.scalar(&attB, "INSERT INTO academic.student_attendance (session_id, class_id, student_id, session_date, status) VALUES ($1, $2, $3, '2026-10-19', 'late') RETURNING id", sb, cb, st3)

	from := time.Date(2026, 10, 1, 0, 0, 0, 0, vnZone)
	to := time.Date(2026, 10, 31, 23, 59, 59, 0, vnZone)

	// Chuyên cần: 1/2 lượt có mặt ở lớp L1 (danh sách lớp bỏ học viên thôi học)
	res, err := e.svc.KpiSources()["class_attendance_rate"](ctx, gv.id, from, to)
	if err != nil || res.Value == nil || *res.Value != 50 || len(res.Records) != 1 || res.Records[0].Text != "Lớp L1: 50% (1 học sinh)" {
		t.Fatalf("chuyên cần: %+v %v", res, err)
	}

	// Rà soát: giáo viên chỉ thấy lớp mình; admin thấy cả hai lớp
	ka := e.svc.KpiAttendance()
	day := time.Date(2026, 10, 19, 0, 0, 0, 0, vnZone)
	recs, opts, err := ka.AttendanceDay(ctx, e.access(gv), day, nil)
	if err != nil || len(recs) != 2 || len(opts) != 1 || opts[0].Value != c1.String() {
		t.Fatalf("điểm danh giáo viên: %+v %+v %v", recs, opts, err)
	}
	for _, r := range recs {
		if r.Student == "Bé An" && (r.StatusLabel == "" || r.Teacher != "Người GV001" || r.ReviewLabel == "") {
			t.Errorf("dòng điểm danh: %+v", r)
		}
	}
	if recs, _, _ := ka.AttendanceDay(ctx, e.access(admin), day, &cb); len(recs) != 1 || recs[0].Student != "Bé Cúc" {
		t.Fatalf("lọc lớp: %+v", recs)
	}

	review := func(m member, id uuid.UUID, decision string) bool {
		var ok bool
		err := pgx.BeginFunc(ctx, e.pg, func(tx pgx.Tx) error {
			r, found, err := ka.ReviewAttendance(ctx, tx, e.access(m), id, decision, "Kiểm tra lại")
			ok = found
			if found && (r.ID != id || r.Label == "") {
				t.Errorf("kết quả rà soát: %+v", r)
			}
			return err
		})
		if err != nil {
			t.Fatal(err)
		}
		return ok
	}
	if review(gv, attB, "approved") {
		t.Fatal("giáo viên rà soát được lớp không phụ trách")
	}
	if !review(gv, att1, "rejected") || review(gv, uuid.New(), "approved") {
		t.Fatal("rà soát lớp của mình")
	}
	var status string
	e.scalar(&status, "SELECT review_status FROM academic.student_attendance WHERE id = $1", att1)
	if status != "rejected" {
		t.Fatalf("trạng thái rà soát = %s", status)
	}

	// Bảng KPI: 1 lớp, 2 học viên (1 thôi học), điểm danh bỏ lượt bị trả lại → 1 lượt, 0 có mặt
	st, err := e.svc.KpiBoard().KpiBoardClasses(ctx, []uuid.UUID{gv.id, admin.id}, from, to)
	if err != nil {
		t.Fatal(err)
	}
	if g := st[gv.id]; g.Classes != 1 || g.Students != 2 || g.Retained != 1 || g.AttendanceTotal != 1 || g.AttendancePresent != 0 {
		t.Fatalf("bảng KPI: %+v", g)
	}
	if st[admin.id].Classes != 0 {
		t.Fatalf("admin: %+v", st[admin.id])
	}
}
