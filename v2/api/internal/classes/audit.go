package classes

import (
	"context"
	"fmt"
	"slices"
	"strings"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify"
)

// Nhật ký vận hành của lớp (ClassModel ghi tự động vào phân hệ "Học viên & Lớp học" ở Laravel) và thông báo xếp dạy.

func init() {
	audit.RegisterSubject("class", "Lớp học")
	for _, p := range []string{"/classes", "/schedule-config", "/class-enrollments", "/class-attendance"} {
		audit.RegisterPath(p, audit.ModuleStudents)
	}
}

// classValues: các trường lớp ghi trước / sau trong nhật ký.
type classValues struct {
	Name, Code, Program, Level, Room, Status, ScheduleText, Notes *string
	BranchID, TeacherID, AssistantID, ForeignTeacherID            *uuid.UUID
	MaxCapacity, MinStudents                                      int32
	TuitionFee                                                    *int64
	StartDate, EndDate                                            *time.Time
}

func idText(id *uuid.UUID) any {
	if id == nil {
		return nil
	}
	return id.String()
}

func (v classValues) snapshot() map[string]any {
	str := func(p *string) any {
		if p == nil {
			return nil
		}
		return *p
	}
	m := map[string]any{
		"name": str(v.Name), "code": str(v.Code), "program": str(v.Program), "level": str(v.Level), "room": str(v.Room),
		"status": str(v.Status), "schedule_text": str(v.ScheduleText), "notes": str(v.Notes), "branch_id": idText(v.BranchID),
		"teacher_id": idText(v.TeacherID), "assistant_id": idText(v.AssistantID), "foreign_teacher_id": idText(v.ForeignTeacherID),
		"max_capacity": v.MaxCapacity, "min_students": v.MinStudents, "start_date": str(fmtDate(v.StartDate)), "end_date": str(fmtDate(v.EndDate)),
		"tuition_fee": nil,
	}
	if v.TuitionFee != nil {
		m["tuition_fee"] = *v.TuitionFee
	}
	return m
}

// recordClass: một dòng nhật ký lớp trong giao dịch của thao tác.
func recordClass(ctx context.Context, tx pgx.Tx, actor *iam.Access, event, action string, id uuid.UUID, name, code string, branch uuid.UUID,
	desc string, changes audit.Changes, props map[string]any) error {
	return audit.Record(ctx, tx, audit.Entry{
		ActorID: &actor.UserID, Module: audit.ModuleStudents, Event: event, Action: action, SubjectType: "class",
		SubjectID: id.String(), SubjectLabel: fmt.Sprintf("%s (%s)", name, code), BranchID: &branch, Description: desc,
		Changes: changes, Properties: props,
	})
}

// notifyForeignTeacher: GVNN được xếp dạy (trừ khi tự xếp cho mình) — "Bạn được xếp dạy GVNN lớp <mã>",
// "<n> buổi lớp <tên>: <tối đa 6 ngày dd/mm>…".
func notifyForeignTeacher(ctx context.Context, tx pgx.Tx, actor *iam.Access, foreign, classID uuid.UUID, code, name string, dates []time.Time) error {
	sorted := slices.Clone(dates)
	slices.SortFunc(sorted, func(a, b time.Time) int { return a.Compare(b) })
	var shown []string
	for _, d := range sorted {
		if v := d.Format("02/01"); !slices.Contains(shown, v) && len(shown) < 6 {
			shown = append(shown, v)
		}
	}
	body := fmt.Sprintf("%d buổi lớp %s: %s", len(dates), name, strings.Join(shown, ", "))
	if len(dates) > 6 {
		body += "…"
	}
	_, err := notify.Send(ctx, tx, []uuid.UUID{foreign}, notify.Notification{
		Type: "class_assigned", Title: "Bạn được xếp dạy GVNN lớp " + code, Body: body + ".",
		Link: "/classes/" + classID.String() + "?tab=schedule", CreatedBy: &actor.UserID, Except: &actor.UserID,
	})
	return err
}
