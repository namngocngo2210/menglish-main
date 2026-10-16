package classes

import (
	"context"
	"testing"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
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
