package classes

import (
	"context"
	"fmt"
	"strings"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/catalogs"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/classes/classesdb"
)

// Phòng học dùng bởi lớp (RoomService): lớp "đang học" (Đang học, đã khai giảng) giữ chặt phòng — không xóa phòng,
// không chuyển phòng sang lớp khác; lớp chưa bắt đầu học chỉ "gán dự kiến": xóa / chuyển phòng thì gỡ phòng khỏi lớp.

// RoomUsage: một lớp còn mở đang gắn phòng.
type RoomUsage = catalogs.RoomClass

func isStudying(status string, start *time.Time, today time.Time) bool {
	return status == StatusActive && (start == nil || !start.After(today))
}

// slotLabels: ca học tuần của các lớp "Thứ 2 18:00–19:30".
func (s *Service) slotLabels(ctx context.Context, q *classesdb.Queries, classIDs []uuid.UUID) (map[uuid.UUID][]string, error) {
	out := map[uuid.UUID][]string{}
	if len(classIDs) == 0 {
		return out, nil
	}
	rows, err := q.ListSlots(ctx, classIDs)
	if err != nil {
		return nil, err
	}
	for _, r := range rows {
		out[r.ClassID] = append(out[r.ClassID], slot{Weekday: int(r.Weekday), Start: r.StartTime, End: r.EndTime}.label())
	}
	return out, nil
}

// roomUsage: lớp còn mở đang gắn từng phòng kèm ca học tuần (chưa cấu hình TKB thì mô tả lịch cũ).
func (s *Service) roomUsage(ctx context.Context, roomIDs []uuid.UUID, exclude *uuid.UUID) (map[uuid.UUID][]RoomUsage, error) {
	out := map[uuid.UUID][]RoomUsage{}
	if len(roomIDs) == 0 {
		return out, nil
	}
	rows, err := s.q.RoomOpenClasses(ctx, classesdb.RoomOpenClassesParams{RoomIds: roomIDs, Exclude: exclude})
	if err != nil {
		return nil, err
	}
	ids := make([]uuid.UUID, 0, len(rows))
	for _, r := range rows {
		ids = append(ids, r.ID)
	}
	labels, err := s.slotLabels(ctx, s.q, ids)
	if err != nil {
		return nil, err
	}
	today := s.today()
	for _, r := range rows {
		sl := labels[r.ID]
		if len(sl) == 0 && r.ScheduleText != nil && *r.ScheduleText != "" {
			sl = []string{*r.ScheduleText}
		}
		out[*r.RoomID] = append(out[*r.RoomID], RoomUsage{
			ID: r.ID, Name: r.Name, Code: r.Code, Studying: isStudying(r.Status, r.StartDate, today), Slots: nonNil(sl),
		})
	}
	return out, nil
}

// RoomClasses (catalogs.ClassHooks).
func (s *Service) RoomClasses(ctx context.Context, roomIDs []uuid.UUID) (map[uuid.UUID][]catalogs.RoomClass, error) {
	return s.roomUsage(ctx, roomIDs, nil)
}

// RoomRenamed (catalogs.ClassHooks): chép tên mới sang lớp gắn phòng và buổi sắp tới còn dùng tên cũ trong chi nhánh.
func (s *Service) RoomRenamed(ctx context.Context, tx pgx.Tx, roomID, branchID uuid.UUID, oldName, newName string) error {
	if oldName == newName {
		return nil
	}
	q := s.q.WithTx(tx)
	if err := q.RenameRoomOnClasses(ctx, classesdb.RenameRoomOnClassesParams{Name: &newName, RoomID: &roomID}); err != nil {
		return err
	}
	return q.RenameRoomOnSessions(ctx, classesdb.RenameRoomOnSessionsParams{
		NewName: &newName, BranchID: branchID, OldName: &oldName, Today: s.today(),
	})
}

// RoomDeleting (catalogs.ClassHooks): lớp đang học → chặn xóa; lớp chưa bắt đầu học → gỡ phòng khỏi lớp và buổi sắp tới.
func (s *Service) RoomDeleting(ctx context.Context, tx pgx.Tx, roomID uuid.UUID, name string) ([]string, error) {
	q := s.q.WithTx(tx)
	rows, err := q.RoomOpenClasses(ctx, classesdb.RoomOpenClassesParams{RoomIds: []uuid.UUID{roomID}})
	if err != nil {
		return nil, err
	}
	today := s.today()
	var studying, names []string
	for _, r := range rows {
		names = append(names, r.Name)
		if isStudying(r.Status, r.StartDate, today) {
			studying = append(studying, r.Name)
		}
	}
	if len(studying) > 0 {
		return nil, conflict(fmt.Sprintf("Không thể xóa: %s đang được lớp %s sử dụng.", name, strings.Join(studying, ", ")))
	}
	for _, r := range rows {
		if err := detachRoom(ctx, q, r.ID, name, today); err != nil {
			return nil, err
		}
	}
	return names, nil
}

// detachRoom: lớp về "chưa có phòng", buổi sắp tới (chưa có dữ liệu thực tế) đang dùng phòng này cũng bỏ phòng.
func detachRoom(ctx context.Context, q *classesdb.Queries, classID uuid.UUID, room string, today time.Time) error {
	if err := q.DetachRoomFromClass(ctx, classID); err != nil {
		return err
	}
	return q.ClearSessionRoom(ctx, classesdb.ClearSessionRoomParams{ClassID: classID, Room: &room, Today: today})
}
