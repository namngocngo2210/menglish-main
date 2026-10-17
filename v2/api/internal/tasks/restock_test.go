package tasks

import (
	"context"
	"log/slog"
	"strings"
	"testing"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/inventory"
)

// Kho hết / âm sau khi xuất → giao Admin việc "Nhập bù sách" (một việc mở cho mỗi chi nhánh + mặt hàng), nhập lên trên 0
// thì việc tự hoàn thành.
func TestRestockTasks(t *testing.T) {
	e := setup(t)
	ctx := context.Background()
	a := e.branch("CN-A")
	admin1 := e.member("AD001", nil, iam.RoleAdmin)
	admin2 := e.member("AD002", nil, iam.RoleAdmin)
	inv := inventory.NewService(e.pg, slog.Default(), inventory.DefaultConfig()).WithRestocker(e.tasks.Restocker())

	var item uuid.UUID
	code := "KB1-" + uuid.NewString()[:8]
	if err := e.pg.QueryRow(ctx, `INSERT INTO ops.inventory_items (code, name, unit) VALUES ($1, 'Sách Kids Box 1', 'Cuốn') RETURNING id`, code).
		Scan(&item); err != nil {
		t.Fatal(err)
	}
	record := func(typ string, qty int32, doc string) {
		t.Helper()
		err := pgx.BeginFunc(ctx, e.pg, func(tx pgx.Tx) error {
			_, err := inv.Record(ctx, tx, inventory.RecordInput{ItemID: item, BranchID: a, Type: typ, Quantity: qty, Source: "contract",
				ReceiptID: ptrUUID(uuid.New()), DocumentNumber: doc})
			return err
		})
		if err != nil {
			t.Fatal(err)
		}
	}
	type row struct {
		id                  uuid.UUID
		title, desc, status string
		assignee            uuid.UUID
		note                string
	}
	tasks := func() []row {
		t.Helper()
		rows, err := e.pg.Query(ctx, `SELECT id, title, coalesce(description, ''), status, assignee_id, coalesce(completion_note, '')
			FROM ops.work_tasks WHERE kind = 'merchandise_restock' AND merchandise_item_id = $1 ORDER BY created_at`, item)
		if err != nil {
			t.Fatal(err)
		}
		defer rows.Close()
		var out []row
		for rows.Next() {
			var r row
			if err := rows.Scan(&r.id, &r.title, &r.desc, &r.status, &r.assignee, &r.note); err != nil {
				t.Fatal(err)
			}
			out = append(out, r)
		}
		return out
	}

	// Xuất bán khi kho trống → âm 2: một việc giao Admin đầu tiên, mọi Admin nhận thông báo.
	record(inventory.TypeSale, 2, "PT-0001")
	list := tasks()
	if len(list) != 1 || list[0].title != "Nhập bù sách: Sách Kids Box 1 — Chi nhánh CN-A" || list[0].status != "new" ||
		list[0].assignee != admin1.id || !strings.Contains(list[0].desc, "đang âm 2 Cuốn") || !strings.Contains(list[0].desc, "sau khi duyệt phiếu thu PT-0001") {
		t.Fatalf("việc nhập bù: %+v", list)
	}
	for _, m := range []member{admin1, admin2} {
		if got := e.inbox(m.id); got != "Việc mới: Nhập bù sách: Sách Kids Box 1 — Chi nhánh CN-A" {
			t.Fatalf("thông báo Admin: %q", got)
		}
	}
	// Xuất tiếp: cập nhật việc đang mở, không tạo trùng, không báo lại.
	record(inventory.TypeSale, 1, "PT-0002")
	if list = tasks(); len(list) != 1 || !strings.Contains(list[0].desc, "đang âm 3 Cuốn") || strings.Count(e.inbox(admin1.id), "|") != 0 {
		t.Fatalf("cập nhật việc nhập bù: %+v", list)
	}
	// Hoàn kho đưa tồn lên trên 0 → việc tự hoàn thành.
	record(inventory.TypeReturn, 5, "")
	if list = tasks(); len(list) != 1 || list[0].status != "completed" || list[0].note != "Tự hoàn thành: chi nhánh đã nhập kho, tồn hiện tại 2." {
		t.Fatalf("tự hoàn thành: %+v", list)
	}
	// Hết lại → việc mới.
	record(inventory.TypeSale, 2, "")
	if list = tasks(); len(list) != 2 || list[1].status != "new" || !strings.Contains(list[1].desc, "đã hết (còn 0)") {
		t.Fatalf("việc nhập bù lần 2: %+v", list)
	}
}

func ptrUUID(id uuid.UUID) *uuid.UUID { return &id }
