package tasks

import (
	"context"
	"errors"
	"strconv"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/tasks/tasksdb"
)

// Thông báo công việc (WorkTaskController::notifyAssignee / notifyStatusChange / handBackToCreator,
// MarkOverdueTasksCommand). Gửi trong giao dịch của thao tác; không báo chính người vừa thao tác.

const (
	linkMine      = "/tasks" // danh sách, tab "Của tôi"
	linkApprovals = "/tasks/approvals"
)

func taskLink(id uuid.UUID) string { return "/tasks/" + id.String() }

// sendTask gửi một thông báo công việc cho từng người (bỏ trùng, bỏ người thao tác khi except khác nil).
func sendTask(ctx context.Context, tx pgx.Tx, ids []*uuid.UUID, by *uuid.UUID, except *uuid.UUID, typ, title, body, link string, taskID uuid.UUID) error {
	list := make([]uuid.UUID, 0, len(ids))
	for _, id := range ids {
		if id != nil {
			list = append(list, *id)
		}
	}
	_, err := notify.Send(ctx, tx, list, notify.Notification{
		Type: typ, Title: title, Body: body, Link: link, Data: map[string]any{"task_id": taskID},
		CreatedBy: by, Except: except,
	})
	return err
}

// userName: họ tên người dùng (không tìm thấy thì fallback).
func userName(ctx context.Context, q *tasksdb.Queries, id *uuid.UUID, fallback string) (string, error) {
	if id == nil {
		return fallback, nil
	}
	u, err := q.GetUserBrief(ctx, *id)
	if errors.Is(err, pgx.ErrNoRows) {
		return fallback, nil
	}
	return u.FullName, err
}

func admins(ctx context.Context, tx pgx.Tx) ([]*uuid.UUID, error) {
	ids, err := iam.AdminIDs(ctx, tx)
	out := make([]*uuid.UUID, len(ids))
	for i := range ids {
		out[i] = &ids[i]
	}
	return out, err
}

// notifyAssigned: người nhận việc (trừ khi tự giao cho mình). count > 1: giao nhiều nhiệm vụ trực ca một lần.
func notifyAssigned(ctx context.Context, tx pgx.Tx, q *tasksdb.Queries, a *iam.Access, t tasksdb.OpsWorkTask, count int) error {
	if t.AssigneeID == nil || *t.AssigneeID == a.UserID {
		return nil
	}
	creator, err := userName(ctx, q, &a.UserID, "Hệ thống")
	if err != nil {
		return err
	}
	due := ""
	if t.DueDate != nil {
		due = t.DueDate.Format("02/01/2006")
	}
	title, body := "Bạn được giao việc: "+t.Title, creator+" đã giao việc, hạn "+due+"."
	if count > 1 {
		n := strconv.Itoa(count)
		title, body = "Bạn được giao "+n+" nhiệm vụ mới", creator+" đã giao "+n+" nhiệm vụ trực ca ngày "+due+"."
	}
	return sendTask(ctx, tx, []*uuid.UUID{t.AssigneeID}, &a.UserID, nil, "task_assigned", title, body, linkMine, t.ID)
}

// notifyConfirmer: người làm gửi "Chờ xác nhận" (không ảnh) → mọi Admin trừ người làm.
func notifyConfirmer(ctx context.Context, tx pgx.Tx, q *tasksdb.Queries, a *iam.Access, t tasksdb.OpsWorkTask) error {
	ids, err := admins(ctx, tx)
	if err != nil {
		return err
	}
	name, err := userName(ctx, q, t.AssigneeID, "Người thực hiện")
	if err != nil {
		return err
	}
	return sendTask(ctx, tx, ids, &a.UserID, t.AssigneeID, "task_assigned", "Việc chờ xác nhận: "+t.Title,
		name+" đã báo hoàn thành (không ảnh minh chứng), cần Admin xác nhận.", linkApprovals+"?selected_id="+t.ID.String(), t.ID)
}

// notifyStatusChange: đổi trạng thái → báo đúng người (WorkTaskController::notifyStatusChange); t là bản đã lưu.
func notifyStatusChange(ctx context.Context, tx pgx.Tx, q *tasksdb.Queries, a *iam.Access, t tasksdb.OpsWorkTask, previous, note string) error {
	actor, err := userName(ctx, q, &a.UserID, "Hệ thống")
	if err != nil {
		return err
	}
	me := &a.UserID
	send := func(ids []*uuid.UUID, typ, title, body, link string) error {
		return sendTask(ctx, tx, ids, me, me, typ, title, body, link, t.ID)
	}
	switch t.Status {
	case StatusBlocked:
		ids, err := admins(ctx, tx)
		if err != nil {
			return err
		}
		reason := ""
		if t.BlockedReason != nil {
			reason = *t.BlockedReason
		}
		return send(append([]*uuid.UUID{&t.CreatorID}, ids...), "task_blocked", "SOS – Việc bị chặn: "+t.Title,
			actor+" báo bị chặn, cần hỗ trợ. Lý do: "+reason, taskLink(t.ID))
	case StatusInProgress:
		switch previous {
		case StatusBlocked:
			return send([]*uuid.UUID{&t.CreatorID}, "task_assigned", "Việc đã gỡ chặn: "+t.Title,
				actor+" đã tiếp tục thực hiện công việc.", taskLink(t.ID))
		case StatusPendingConfirmation:
			body := actor + " trả về làm tiếp."
			if note != "" {
				body = actor + " trả về làm tiếp: " + note
			}
			return send([]*uuid.UUID{t.AssigneeID}, "task_assigned", "Công việc bị trả về: "+t.Title, body, linkMine)
		}
	case StatusPendingConfirmation:
		return notifyConfirmer(ctx, tx, q, a, t)
	case StatusCompleted:
		body := actor + " đã xác nhận hoàn thành."
		if note != "" {
			body += " Ghi chú: " + note
		}
		return send([]*uuid.UUID{t.AssigneeID}, "task_assigned", "Việc đã được xác nhận hoàn thành: "+t.Title, body, taskLink(t.ID))
	case StatusCanceled:
		reason := ""
		if t.RejectionReason != nil {
			reason = *t.RejectionReason
		}
		return send([]*uuid.UUID{t.AssigneeID, &t.CreatorID}, "task_assigned", "Công việc đã bị hủy: "+t.Title,
			actor+" đã hủy công việc. Lý do: "+reason, taskLink(t.ID))
	}
	return nil
}

// notifyHandBack: việc bị chặn chuyển về người giao — báo người giao và người làm cũ.
func notifyHandBack(ctx context.Context, tx pgx.Tx, q *tasksdb.Queries, a *iam.Access, t tasksdb.OpsWorkTask, blockedReason *string) error {
	actor, err := userName(ctx, q, &a.UserID, "Hệ thống")
	if err != nil {
		return err
	}
	from, err := userName(ctx, q, t.AssigneeID, "Người thực hiện")
	if err != nil {
		return err
	}
	creator, err := userName(ctx, q, &t.CreatorID, "người giao")
	if err != nil {
		return err
	}
	reason := ""
	if blockedReason != nil {
		reason = " Lý do bị chặn: " + *blockedReason
	}
	me := &a.UserID
	if err := sendTask(ctx, tx, []*uuid.UUID{&t.CreatorID}, me, me, "task_assigned", "Việc bị chặn chuyển lại cho bạn: "+t.Title,
		actor+" đã chuyển công việc từ "+from+" về cho bạn xử lý."+reason, taskLink(t.ID), t.ID); err != nil {
		return err
	}
	return sendTask(ctx, tx, []*uuid.UUID{t.AssigneeID}, me, me, "task_assigned", "Công việc đã chuyển về người giao: "+t.Title,
		actor+" đã chuyển công việc về cho "+creator+" xử lý, bạn không cần làm tiếp.", taskLink(t.ID), t.ID)
}

// notifyLateTAAssign: giao việc trợ giảng sau giờ chốt → mọi Admin và người giao, mỗi người một thông báo.
func notifyLateTAAssign(ctx context.Context, tx pgx.Tx, q *tasksdb.Queries, a *iam.Access, assistant uuid.UUID, assistantName string, day, now time.Time, count int) error {
	ids, err := admins(ctx, tx)
	if err != nil {
		return err
	}
	actor, err := userName(ctx, q, &a.UserID, "Hệ thống")
	if err != nil {
		return err
	}
	detail := strconv.Itoa(count) + " nhiệm vụ ngày " + day.Format("02/01/2006") + " cho " + assistantName + " lúc " + now.In(vnZone).Format("15:04") + "."
	link := "/portal/ta-tasks?ta_id=" + assistant.String() + "&date=" + day.Format(time.DateOnly)
	title := "Giao việc trợ giảng sau " + TAAssignCutoff
	others := make([]uuid.UUID, 0, len(ids))
	for _, id := range ids {
		if *id != a.UserID {
			others = append(others, *id)
		}
	}
	if _, err := notify.Send(ctx, tx, others, notify.Notification{
		Type: "task_assigned", Title: title, Body: actor + " giao " + detail, Link: link, CreatedBy: &a.UserID,
	}); err != nil {
		return err
	}
	_, err = notify.Send(ctx, tx, []uuid.UUID{a.UserID}, notify.Notification{
		Type: "task_assigned", Title: title, Body: "Bạn đã giao " + detail, Link: link, CreatedBy: &a.UserID,
	})
	return err
}
