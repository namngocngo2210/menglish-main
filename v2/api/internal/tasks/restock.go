package tasks

import (
	"context"
	"errors"
	"strconv"

	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/inventory"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/inventory/inventorydb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/tasks/tasksdb"
)

// Restocker: việc "Nhập bù sách" cho phân hệ kho (inventory.Restocker), nối ở cmd/menglish/main.go bằng
// inv.WithRestocker(taskSvc.Restocker()). Như Laravel RestockTaskService: mỗi chi nhánh + mặt hàng một việc đang mở, giao
// Admin đầu tiên, hạn SLA stock.restock_task ngày lúc 17:00; mọi Admin nhận thông báo "Việc mới: …".
func (s *Service) Restocker() inventory.Restocker { return restocker{s} }

type restocker struct{ s *Service }

func (r restocker) SyncRestock(ctx context.Context, db inventorydb.DBTX, in inventory.Restock) error {
	q := tasksdb.New(db)
	open, err := q.LockOpenRestockTask(ctx, tasksdb.LockOpenRestockTaskParams{ItemID: &in.ItemID, BranchID: &in.BranchID})
	found := err == nil
	if err != nil && !errors.Is(err, pgx.ErrNoRows) {
		return err
	}
	if !in.Open {
		if !found {
			return nil
		}
		note := "Tự hoàn thành: chi nhánh đã nhập kho, tồn hiện tại " + strconv.Itoa(int(in.Balance)) + "."
		return q.AutoCompleteRestockTask(ctx, tasksdb.AutoCompleteRestockTaskParams{ID: open, Note: &note})
	}
	if found {
		return q.SetRestockDescription(ctx, tasksdb.SetRestockDescriptionParams{ID: open, Description: &in.Description})
	}
	adminIDs, err := iam.AdminIDs(ctx, db)
	if err != nil || len(adminIDs) == 0 {
		return err
	}
	days, err := q.RestockDueDays(ctx)
	if err != nil {
		return err
	}
	due := dateOnly(r.s.now()).AddDate(0, 0, int(days))
	id, err := q.InsertRestockTask(ctx, tasksdb.InsertRestockTaskParams{
		Title: in.Title, Description: &in.Description, AssigneeID: adminIDs[0], BranchID: &in.BranchID, ItemID: &in.ItemID, DueDate: &due,
	})
	if err != nil {
		return err
	}
	r.s.log.Info("restock task created", "task_id", id, "item", in.ItemID, "branch", in.BranchID)
	_, err = notify.Send(ctx, db, adminIDs, notify.Notification{
		Type: "task_assigned", Title: "Việc mới: " + in.Title, Body: in.Summary + ". Hạn 17:00 " + due.Format("02/01/2006") + ".",
		Link: in.Link, Data: map[string]any{"task_id": id},
	})
	return err
}
