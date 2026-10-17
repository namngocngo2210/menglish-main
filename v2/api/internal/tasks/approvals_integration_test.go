package tasks

import (
	"context"
	"testing"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// Nguồn "Xác nhận hoàn thành việc" của hộp Việc cần duyệt: đếm / liệt kê như màn gốc, duyệt và trả về qua nghiệp vụ gốc.
func TestApprovalSourceWorkTasks(t *testing.T) {
	e := setup(t)
	a := e.branch("CN-A")
	admin := e.member("AD001", &a, iam.RoleAdmin)
	manager := e.member("QL001", &a, iam.RoleManager)
	teacher := e.member("GV001", &a, iam.RoleTeacherFulltime)
	mc, tc := e.as(manager), e.as(teacher)
	id1, id2 := mc.create(newTask("Soạn đề", teacher.id)), mc.create(newTask("Chấm bài", teacher.id))
	for _, id := range []string{id1, id2} {
		expect(t, tc.do("POST", "/tasks/"+id+"/status", map[string]any{"status": StatusPendingConfirmation, "reason": "Xong"}), 200, "")
	}
	ctx := context.Background()
	src := e.tasks.ApprovalSource()
	acc, err := e.iam.LoadAccess(ctx, admin.id)
	if err != nil {
		t.Fatal(err)
	}
	mgr, _ := e.iam.LoadAccess(ctx, manager.id)
	if !src.CanView(acc) || !src.CanView(mgr) {
		t.Fatal("người giao việc / Admin thấy nguồn (hộp tự chặn người không phải Admin)")
	}
	if n, _ := src.Count(ctx, mgr); n != 0 {
		t.Fatalf("Quản lý không có việc chờ mình xác nhận: %d", n)
	}
	if n, err := src.Count(ctx, acc); err != nil || n != 2 {
		t.Fatalf("đếm: %d %v", n, err)
	}
	items, err := src.Pending(ctx, acc, 1)
	if err != nil || len(items) != 1 || items[0].Subtitle == nil || *items[0].Subtitle != "Người làm: "+teacher.name {
		t.Fatalf("liệt kê: %+v %v", items, err)
	}
	id := uuid.MustParse(id1)
	if it, _ := src.Find(ctx, acc, id); it == nil || it.Title != "Soạn đề" {
		t.Fatalf("tìm: %+v", it)
	}
	if msg, err := src.Approve(ctx, acc, id); err != nil || msg != "Đã xác nhận hoàn thành công việc 'Soạn đề'!" {
		t.Fatalf("duyệt: %q %v", msg, err)
	}
	if it, _ := src.Find(ctx, acc, id); it != nil {
		t.Fatal("việc đã duyệt không còn chờ")
	}
	if _, err := src.Reject(ctx, acc, uuid.MustParse(id2), "Thiếu file"); err != nil || e.status(id2) != StatusInProgress {
		t.Fatalf("trả về: %v %s", err, e.status(id2))
	}
}
