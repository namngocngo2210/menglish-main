package tasks

import (
	"context"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/tasks/tasksdb"
)

// BigTestTask: việc "Duyệt & phân phối đề Big Test" phân hệ Giáo trình tự giao khi đợt thi sắp tới chưa phân phối đề
// (BigTestSlaService::ensureApprovalTasks). Phân hệ gọi tự gửi thông báo "Việc mới".
type BigTestTask struct {
	BigTestID   uuid.UUID
	ClassID     *uuid.UUID
	BranchID    *uuid.UUID
	Title       string
	Description string
	DueDate     time.Time
	AssigneeID  uuid.UUID
	CreatorID   uuid.UUID
}

// EnsureBigTestTask tạo việc duyệt đề trong tx của người gọi; mỗi đợt thi một việc (đã có, kể cả đã đóng → false).
func (s *Service) EnsureBigTestTask(ctx context.Context, tx pgx.Tx, in BigTestTask) (bool, error) {
	ids, err := tasksdb.New(tx).InsertBigTestTask(ctx, tasksdb.InsertBigTestTaskParams{
		Title: in.Title, Description: in.Description, CreatorID: in.CreatorID, AssigneeID: in.AssigneeID,
		BranchID: in.BranchID, ClassID: in.ClassID, BigTestID: in.BigTestID, DueDate: dateOnly(in.DueDate),
	})
	if err != nil || len(ids) == 0 {
		return false, err
	}
	s.log.Info("big test approval task created", "task_id", ids[0], "big_test_id", in.BigTestID)
	return true, nil
}

// CloseBigTestTasks hoàn thành việc duyệt đề còn mở của các đợt thi đã phân phối đề (closeApprovalTasks); bigTestID nil
// = mọi đợt thi (lệnh hằng ngày quét bù).
func (s *Service) CloseBigTestTasks(ctx context.Context, tx pgx.Tx, bigTestID *uuid.UUID) (int, error) {
	n, err := tasksdb.New(tx).CloseBigTestTasks(ctx, bigTestID)
	return int(n), err
}
