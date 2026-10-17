package tasks

import (
	"context"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/tasks/tasksdb"
)

// FeeReminder: việc "Nhắc thu học phí" phân hệ Học phí tạo khi chốt khách chưa đóng học phí đăng ký
// (CrmController::createFeeReminderTask): giao người phụ trách khách, hạn 17:00 sau 3 ngày.
type FeeReminder struct {
	Title       string
	Description string
	CreatorID   uuid.UUID
	AssigneeID  uuid.UUID
	BranchID    *uuid.UUID
	StudentID   *uuid.UUID
	DueDate     time.Time
}

// CreateFeeReminder tạo việc trong giao dịch của người gọi và báo người được giao (trừ khi tự giao cho mình).
func (s *Service) CreateFeeReminder(ctx context.Context, tx pgx.Tx, in FeeReminder) (uuid.UUID, error) {
	due := dateOnly(in.DueDate)
	id, err := tasksdb.New(tx).InsertFeeReminderTask(ctx, tasksdb.InsertFeeReminderTaskParams{
		Title: in.Title, Description: &in.Description, CreatorID: in.CreatorID, AssigneeID: &in.AssigneeID,
		BranchID: in.BranchID, StudentID: in.StudentID, DueDate: &due,
	})
	if err != nil {
		return id, err
	}
	s.log.Info("fee reminder task created", "task_id", id, "student_id", in.StudentID, "by", in.CreatorID)
	body := "Hạn 17:00 " + due.Format("02/01/2006") + "."
	return id, sendTask(ctx, tx, []*uuid.UUID{&in.AssigneeID}, &in.CreatorID, &in.CreatorID, "task_assigned", "Việc mới: "+in.Title, body, taskLink(id), id)
}
