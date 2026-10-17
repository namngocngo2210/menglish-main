package classes

import (
	"context"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/classes/classesdb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/tasks"
)

// Phần việc phân hệ Lớp học làm cho công việc (tasks.Classes): lớp gắn vào việc, buổi học để giao việc trực ca cho
// trợ giảng, lớp trực trong ngày ở portal trợ giảng.

type TaskClasses struct{ cls *Service }

func (s *Service) TaskClasses() TaskClasses { return TaskClasses{cls: s} }

var _ tasks.Classes = TaskClasses{}

func (t TaskClasses) Options(ctx context.Context, a *iam.Access, statuses []string) ([]tasks.ClassOption, error) {
	sc, err := t.cls.visible(ctx, a)
	if err != nil {
		return nil, err
	}
	rows, err := t.cls.q.TaskClassOptions(ctx, classesdb.TaskClassOptionsParams{
		Statuses: nonNil(statuses), AllClasses: sc.all, Ids: nonNil(sc.ids),
	})
	if err != nil {
		return nil, err
	}
	out := make([]tasks.ClassOption, 0, len(rows))
	for _, r := range rows {
		out = append(out, tasks.ClassOption{ID: r.ID, Code: r.Code, Name: r.Name, ScheduleText: r.ScheduleText})
	}
	return out, nil
}

func (t TaskClasses) Exists(ctx context.Context, id uuid.UUID) (bool, error) {
	return t.cls.q.ClassExists(ctx, id)
}

func (t TaskClasses) Visible(ctx context.Context, a *iam.Access, id uuid.UUID) (bool, error) {
	sc, err := t.cls.visible(ctx, a)
	if err != nil || !sc.has(id) {
		return false, err
	}
	return t.cls.q.ClassExists(ctx, id)
}

func (t TaskClasses) Sessions(ctx context.Context, classIDs []uuid.UUID, from, to time.Time) ([]tasks.ClassSession, error) {
	rows, err := t.cls.q.TaskSessions(ctx, classesdb.TaskSessionsParams{ClassIds: nonNil(classIDs), FromDate: from, ToDate: to})
	if err != nil {
		return nil, err
	}
	out := make([]tasks.ClassSession, 0, len(rows))
	for _, r := range rows {
		out = append(out, taskSession(classesdb.TaskSessionRow(r)))
	}
	return out, nil
}

func (t TaskClasses) Session(ctx context.Context, id uuid.UUID) (*tasks.ClassSession, error) {
	r, err := t.cls.q.TaskSession(ctx, id)
	if isNoRows(err) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	se := taskSession(r)
	return &se, nil
}

func (t TaskClasses) DaySessions(ctx context.Context, staffID uuid.UUID, classIDs []uuid.UUID, day time.Time) ([]tasks.ClassSession, error) {
	rows, err := t.cls.q.TaskDaySessions(ctx, classesdb.TaskDaySessionsParams{Day: day, StaffID: staffID, ClassIds: nonNil(classIDs)})
	if err != nil {
		return nil, err
	}
	out := make([]tasks.ClassSession, 0, len(rows))
	for _, r := range rows {
		out = append(out, taskSession(classesdb.TaskSessionRow{
			ID: r.ID, ClassID: r.ClassID, ClassName: r.ClassName, Date: r.Date, ShiftName: r.ShiftName, Type: r.Type, Room: r.Room,
			BranchID: r.BranchID, BranchName: r.BranchName, StartTime: r.StartTime, EndTime: r.EndTime,
		}))
	}
	return out, nil
}

func (t TaskClasses) Refs(ctx context.Context, ids []uuid.UUID) (map[uuid.UUID]tasks.ClassRef, error) {
	out := map[uuid.UUID]tasks.ClassRef{}
	if len(ids) == 0 {
		return out, nil
	}
	rows, err := t.cls.q.ClassCodesByIDs(ctx, ids)
	if err != nil {
		return nil, err
	}
	for _, r := range rows {
		out[r.ID] = tasks.ClassRef{Code: r.Code, Name: r.Name}
	}
	return out, nil
}

func taskSession(r classesdb.TaskSessionRow) tasks.ClassSession {
	return tasks.ClassSession{
		ID: r.ID, ClassID: r.ClassID, ClassName: r.ClassName, Date: r.Date, Start: r.StartTime, End: r.EndTime, Room: r.Room,
		BranchID: r.BranchID, Branch: r.BranchName, Makeup: r.Type == "makeup", LessonNo: int(r.LessonNo), ShiftName: r.ShiftName,
	}
}
