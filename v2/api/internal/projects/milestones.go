package projects

import (
	"context"
	"math"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/projects/projectsdb"
)

type MilestoneInput struct {
	Title          *string  `json:"title"`
	Description    *string  `json:"description"`
	AssigneeID     string   `json:"assignee_id"`
	StartDate      *string  `json:"start_date"`
	DueDate        *string  `json:"due_date"`
	TargetQuantity *float64 `json:"target_quantity"`
	Unit           *string  `json:"unit"`
	Status         string   `json:"status"`
}

type milestoneData struct {
	title       string
	description *string
	assignee    *uuid.UUID
	start       *time.Time
	due         time.Time
	target      float64
	unit        string
	status      string
}

func (s *Service) validateMilestone(ctx context.Context, in MilestoneInput) (milestoneData, error) {
	d := milestoneData{title: deref(text(in.Title)), description: text(in.Description), unit: deref(text(in.Unit)), status: in.Status}
	switch {
	case d.title == "":
		return d, iam.Invalid("Nhập tên mốc.")
	case tooLong(&d.title, 255):
		return d, iam.Invalid("Tên mốc không được dài quá 255 ký tự.")
	case tooLong(d.description, 3000):
		return d, iam.Invalid("Mô tả mốc không được dài quá 3000 ký tự.")
	}
	start, ok := parseDate(in.StartDate)
	if !ok {
		return d, iam.Invalid("Ngày bắt đầu không hợp lệ.")
	}
	due, ok := parseDate(in.DueDate)
	switch {
	case !ok:
		return d, iam.Invalid("Deadline của mốc không hợp lệ.")
	case due == nil:
		return d, iam.Invalid("Chọn deadline của mốc.")
	case start != nil && due.Before(*start):
		return d, iam.Invalid("Deadline phải sau ngày bắt đầu.")
	case in.TargetQuantity == nil:
		return d, iam.Invalid("Nhập khối lượng cần làm (vd 12 unit).")
	case *in.TargetQuantity < 0.01:
		return d, iam.Invalid("Khối lượng phải lớn hơn 0.")
	case *in.TargetQuantity > 99999:
		return d, iam.Invalid("Khối lượng tối đa 99999.")
	case d.unit == "":
		return d, iam.Invalid("Nhập đơn vị khối lượng (unit, trang, bài…).")
	case tooLong(&d.unit, 30):
		return d, iam.Invalid("Đơn vị khối lượng không được dài quá 30 ký tự.")
	}
	d.start, d.due, d.target = start, *due, math.Round(*in.TargetQuantity*100)/100
	if d.status == "" {
		d.status = "todo"
	} else if !hasKey(MilestoneStatuses, d.status) {
		return d, iam.Invalid("Trạng thái mốc không hợp lệ.")
	}
	if raw := in.AssigneeID; raw != "" {
		const msg = "Người nhận không còn hoạt động hoặc không có quyền dự án học thuật."
		id := parseUUID(raw)
		if id == nil {
			return d, iam.Invalid(msg)
		}
		_, staff, err := s.staff(ctx)
		if err != nil {
			return d, err
		}
		if !staff[*id] {
			return d, iam.Invalid(msg)
		}
		d.assignee = id
	}
	return d, nil
}

func milestoneSnapshot(d milestoneData) map[string]any {
	a := ""
	if d.assignee != nil {
		a = d.assignee.String()
	}
	return map[string]any{
		"title": d.title, "description": deref(d.description), "assignee_id": a, "start_date": deref(ymd(d.start)),
		"due_date": d.due.Format("2006-01-02"), "target_quantity": d.target, "unit": d.unit, "status": d.status,
	}
}

// afterAssign: người nhận mốc tự thành thành viên dự án; báo khi được giao mốc mới.
func afterAssign(ctx context.Context, tx pgx.Tx, q *projectsdb.Queries, actor uuid.UUID, projectID uuid.UUID, projectName string, d milestoneData, previous *uuid.UUID) error {
	if d.assignee == nil || (previous != nil && *previous == *d.assignee) {
		return nil
	}
	if _, err := q.AddMembers(ctx, projectsdb.AddMembersParams{ProjectID: projectID, UserIds: []uuid.UUID{*d.assignee}}); err != nil {
		return err
	}
	return send(ctx, tx, []uuid.UUID{*d.assignee}, actor, "academic_project_milestone", `Bạn được giao mốc "`+d.title+`"`,
		"Dự án "+projectName+": deadline "+d.due.Format("02/01/2006")+", khối lượng "+FormatQuantity(d.target)+" "+d.unit+".", projectID)
}

func (s *Service) CreateMilestone(ctx context.Context, actor *iam.Access, projectID uuid.UUID, in MilestoneInput) (string, error) {
	p, err := s.getProject(ctx, s.q, projectID)
	if err != nil {
		return "", err
	}
	d, err := s.validateMilestone(ctx, in)
	if err != nil {
		return "", err
	}
	err = s.tx(ctx, func(tx pgx.Tx, q *projectsdb.Queries) error {
		id, err := q.CreateMilestone(ctx, projectsdb.CreateMilestoneParams{
			ProjectID: projectID, Title: d.title, Description: d.description, AssigneeID: d.assignee, StartDate: d.start,
			DueDate: d.due, TargetQuantity: d.target, Unit: d.unit, Status: d.status, Now: s.now(),
		})
		if err != nil {
			return err
		}
		if err := audit.Record(ctx, tx, audit.Entry{
			ActorID: &actor.UserID, Module: audit.ModuleSyllabus, Event: audit.EventCreated, Action: PermManage,
			SubjectType: "academic_project_milestone", SubjectID: id.String(), SubjectLabel: p.Code + " · " + d.title,
			Description: `Thêm mốc "` + d.title + `" vào dự án ` + p.Code, Changes: audit.Diff(nil, milestoneSnapshot(d)),
		}); err != nil {
			return err
		}
		return afterAssign(ctx, tx, q, actor.UserID, projectID, p.Name, d, nil)
	})
	if err != nil {
		return "", err
	}
	s.log.Info("academic milestone created", "project_id", projectID, "by", actor.UserID)
	return `Đã thêm mốc "` + d.title + `".`, nil
}

func (s *Service) getMilestone(ctx context.Context, id uuid.UUID) (projectsdb.GetMilestoneRow, error) {
	m, err := s.q.GetMilestone(ctx, id)
	if isNoRows(err) {
		return m, errMilestoneNotFound
	}
	return m, err
}

func (s *Service) UpdateMilestone(ctx context.Context, actor *iam.Access, id uuid.UUID, in MilestoneInput) (string, error) {
	cur, err := s.getMilestone(ctx, id)
	if err != nil {
		return "", err
	}
	d, err := s.validateMilestone(ctx, in)
	if err != nil {
		return "", err
	}
	// Đổi hạn thì nhắc lại; đổi trạng thái thì ghi / bỏ thời điểm hoàn thành.
	reminded, completed := cur.RemindedAt, cur.CompletedAt
	if !d.due.Equal(cur.DueDate) {
		reminded = nil
	}
	if d.status != cur.Status {
		completed = nil
		if d.status == "done" {
			now := s.now()
			completed = &now
		}
	}
	before := milestoneSnapshot(milestoneData{title: cur.Title, description: cur.Description, assignee: cur.AssigneeID,
		start: cur.StartDate, due: cur.DueDate, target: cur.TargetQuantity, unit: cur.Unit, status: cur.Status})
	err = s.tx(ctx, func(tx pgx.Tx, q *projectsdb.Queries) error {
		if err := q.UpdateMilestone(ctx, projectsdb.UpdateMilestoneParams{
			ID: id, Title: d.title, Description: d.description, AssigneeID: d.assignee, StartDate: d.start, DueDate: d.due,
			TargetQuantity: d.target, Unit: d.unit, Status: d.status, CompletedAt: completed, RemindedAt: reminded,
		}); err != nil {
			return err
		}
		if err := audit.Record(ctx, tx, audit.Entry{
			ActorID: &actor.UserID, Module: audit.ModuleSyllabus, Event: audit.EventUpdated, Action: PermManage,
			SubjectType: "academic_project_milestone", SubjectID: id.String(), SubjectLabel: cur.ProjectCode + " · " + d.title,
			Description: `Cập nhật mốc "` + d.title + `" của dự án ` + cur.ProjectCode, Changes: audit.Diff(before, milestoneSnapshot(d)),
		}); err != nil {
			return err
		}
		return afterAssign(ctx, tx, q, actor.UserID, cur.ProjectID, cur.ProjectName, d, cur.AssigneeID)
	})
	if err != nil {
		return "", err
	}
	s.log.Info("academic milestone updated", "milestone_id", id, "by", actor.UserID)
	return `Đã lưu mốc "` + d.title + `".`, nil
}

func (s *Service) DeleteMilestone(ctx context.Context, actor *iam.Access, id uuid.UUID) (string, error) {
	cur, err := s.getMilestone(ctx, id)
	if err != nil {
		return "", err
	}
	err = s.tx(ctx, func(tx pgx.Tx, q *projectsdb.Queries) error {
		if err := q.DeleteMilestone(ctx, id); err != nil {
			return err
		}
		return audit.Record(ctx, tx, audit.Entry{
			ActorID: &actor.UserID, Module: audit.ModuleSyllabus, Event: audit.EventDeleted, Action: PermManage,
			SubjectType: "academic_project_milestone", SubjectID: id.String(), SubjectLabel: cur.ProjectCode + " · " + cur.Title,
			Description: `Xóa mốc "` + cur.Title + `" của dự án ` + cur.ProjectCode,
		})
	})
	if err != nil {
		return "", err
	}
	s.log.Info("academic milestone deleted", "milestone_id", id, "by", actor.UserID)
	return `Đã xóa mốc "` + cur.Title + `".`, nil
}
