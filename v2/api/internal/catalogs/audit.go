package catalogs

import (
	"context"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/catalogs/catalogsdb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// Nhật ký vận hành của danh mục (mô tả giữ như Audit::describe của Laravel).

func init() {
	audit.RegisterSubject("holiday", "Ngày nghỉ")
	audit.RegisterSubject("teaching_shift", "Khung giờ ca dạy")
	audit.RegisterSubject("system_category", "Danh mục hệ thống")
	for _, p := range []string{"/courses", "/course-levels", "/rooms", "/teaching-shifts", "/holidays"} {
		audit.RegisterPath(p, audit.ModuleSystem)
	}
}

// audited chạy fn trong giao dịch rồi ghi dòng nhật ký fn trả về cùng giao dịch (lỗi thì rollback cả hai).
func (s *Service) audited(ctx context.Context, actor *iam.Access, fn func(q *catalogsdb.Queries) (audit.Entry, error)) error {
	return pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
		e, err := fn(s.q.WithTx(tx))
		if err != nil {
			return err
		}
		e.ActorID = &actor.UserID
		if e.Module == "" {
			e.Module = audit.ModuleSystem
		}
		return audit.Record(ctx, tx, e)
	})
}

func holidaySnapshot(in HolidayInput, code string) map[string]any {
	return map[string]any{
		"code": code, "name": in.Name, "start_date": in.StartDate, "end_date": in.EndDate,
		"is_system_wide": in.IsSystemWide, "branch_ids": nonNil(in.BranchIDs),
	}
}

func holidayRowSnapshot(h catalogsdb.GetHolidayRow) map[string]any {
	return holidaySnapshot(HolidayInput{
		Name: h.Name, StartDate: h.StartDate, EndDate: h.EndDate, IsSystemWide: h.IsSystemWide, BranchIDs: h.BranchIds,
	}, h.Code)
}

func holidayEntry(id uuid.UUID, code, name, event, action, desc string, changes audit.Changes) audit.Entry {
	return audit.Entry{
		Event: event, Action: action, SubjectType: "holiday", SubjectID: id.String(),
		SubjectLabel: name + " (" + code + ")", Description: desc, Changes: changes,
	}
}

func shiftSnapshot(in ShiftInput) map[string]any {
	return map[string]any{
		"day_type": in.DayType, "name": in.Name, "start_time": in.StartTime, "end_time": in.EndTime,
		"alt_start_time": in.AltStartTime, "alt_end_time": in.AltEndTime, "note": in.Note,
	}
}

func shiftEntry(id uuid.UUID, label, event, action, desc string, changes audit.Changes) audit.Entry {
	return audit.Entry{
		Event: event, Action: action, SubjectType: "teaching_shift", SubjectID: id.String(), SubjectLabel: label,
		Description: desc, Changes: changes,
	}
}

func categorySnapshot(c Category) map[string]any {
	return map[string]any{"type": c.Type, "code": c.Code, "name": c.Name, "sort_order": c.SortOrder, "is_active": c.IsActive}
}

func categoryEntry(c Category, event, action, desc string, changes audit.Changes) audit.Entry {
	return audit.Entry{
		Event: event, Action: action, SubjectType: "system_category", SubjectID: c.ID.String(),
		SubjectLabel: c.Name + " (" + c.Type + "/" + c.Code + ")", Description: desc, Changes: changes,
	}
}
