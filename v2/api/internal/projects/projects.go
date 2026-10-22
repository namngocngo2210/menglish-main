package projects

import (
	"cmp"
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
	"github.com/namngocngo2210/menglish-main/v2/api/internal/projects/projectsdb"
)

// ───────────────────────── Kiểu dữ liệu ─────────────────────────

type NextMilestone struct {
	Title   string `json:"title"`
	DueDate string `json:"due_date"`
}

// Summary là một dòng dự án (AcademicProjectController::summary).
type Summary struct {
	ID                uuid.UUID      `json:"id"`
	Code              string         `json:"code"`
	Name              string         `json:"name"`
	Type              string         `json:"type"`
	TypeLabel         string         `json:"type_label"`
	Status            string         `json:"status"`
	StatusLabel       string         `json:"status_label"`
	StatusColor       string         `json:"status_color"`
	Owner             *string        `json:"owner"`
	StartDate         *string        `json:"start_date"`
	Deadline          *string        `json:"deadline"`
	Progress          int            `json:"progress"`
	MilestonesTotal   int            `json:"milestones_total"`
	MilestonesDone    int            `json:"milestones_done"`
	MilestonesOverdue int            `json:"milestones_overdue"`
	NextMilestone     *NextMilestone `json:"next_milestone"`
	MembersCount      int            `json:"members_count"`
}

type Meta struct {
	Page    int   `json:"page"`
	PerPage int   `json:"per_page"`
	Total   int64 `json:"total"`
}

type ProjectsPage struct {
	Data      []Summary `json:"data"`
	Meta      Meta      `json:"meta"`
	Types     []Option  `json:"types"`
	Statuses  []Option  `json:"statuses"`
	Owners    []Option  `json:"owners"`
	CanManage bool      `json:"can_manage"`
}

type ProjectsFilter struct {
	Search  string
	Type    string
	Status  string
	OwnerID string
	Page    int
	PerPage int
}

type projectRow struct {
	ID           uuid.UUID
	Code, Name   string
	Type, Status string
	OwnerName    *string
	StartDate    *time.Time
	Deadline     *time.Time
	MembersCount int64
}

func toMile(m projectsdb.MilestonesOfRow) mile {
	return mile{DueDate: m.DueDate, Status: m.Status, CompletedAt: m.CompletedAt, Target: m.TargetQuantity, Done: m.DoneQuantity}
}

// summarize: dòng dự án + tiến độ (trung bình % các mốc) từ các mốc (đã theo hạn sớm trước).
func summarize(p projectRow, ms []projectsdb.MilestonesOfRow, now time.Time) Summary {
	out := Summary{
		ID: p.ID, Code: p.Code, Name: p.Name, Type: p.Type, TypeLabel: labelOf(Types, p.Type), Status: p.Status,
		StatusLabel: labelOf(Statuses, p.Status), StatusColor: colorOf(p.Status), Owner: p.OwnerName,
		StartDate: ymd(p.StartDate), Deadline: ymd(p.Deadline), MilestonesTotal: len(ms), MembersCount: int(p.MembersCount),
	}
	sum := 0
	for _, m := range ms {
		mm := toMile(m)
		sum += mm.progress()
		switch {
		case mm.done():
			out.MilestonesDone++
		case mm.overdue(now):
			out.MilestonesOverdue++
		}
		if !mm.done() && out.NextMilestone == nil {
			out.NextMilestone = &NextMilestone{Title: m.Title, DueDate: m.DueDate.Format("2006-01-02")}
		}
	}
	if len(ms) > 0 {
		out.Progress = int(float64(sum)/float64(len(ms)) + 0.5)
	}
	return out
}

func (s *Service) milestonesByProject(ctx context.Context, ids []uuid.UUID) (map[uuid.UUID][]projectsdb.MilestonesOfRow, error) {
	out := map[uuid.UUID][]projectsdb.MilestonesOfRow{}
	if len(ids) == 0 {
		return out, nil
	}
	rows, err := s.q.MilestonesOf(ctx, ids)
	if err != nil {
		return nil, err
	}
	for _, r := range rows {
		out[r.ProjectID] = append(out[r.ProjectID], r)
	}
	return out, nil
}

// ───────────────────────── Danh sách ─────────────────────────

func (s *Service) List(ctx context.Context, actor *iam.Access, f ProjectsFilter) (*ProjectsPage, error) {
	per := f.PerPage
	if per <= 0 {
		per = 15
	}
	per = min(per, 100)
	page := max(f.Page, 1)
	var typ, status *string
	if hasKey(Types, f.Type) {
		typ = &f.Type
	}
	if hasKey(Statuses, f.Status) {
		status = &f.Status
	}
	search, owner := likeArg(f.Search), parseUUID(f.OwnerID)
	all := seeAll(actor)
	total, err := s.q.CountProjects(ctx, projectsdb.CountProjectsParams{SeeAll: all, UserID: actor.UserID, Search: search, Type: typ, Status: status, OwnerID: owner})
	if err != nil {
		return nil, err
	}
	if last := int((total + int64(per) - 1) / int64(per)); page > last && last > 0 {
		page = last
	}
	rows, err := s.q.ListProjects(ctx, projectsdb.ListProjectsParams{
		SeeAll: all, UserID: actor.UserID, Search: search, Type: typ, Status: status, OwnerID: owner,
		Lim: int32(per), Off: int32((page - 1) * per),
	})
	if err != nil {
		return nil, err
	}
	ids := make([]uuid.UUID, 0, len(rows))
	for _, r := range rows {
		ids = append(ids, r.ID)
	}
	ms, err := s.milestonesByProject(ctx, ids)
	if err != nil {
		return nil, err
	}
	now := s.now()
	out := &ProjectsPage{Data: make([]Summary, 0, len(rows)), Meta: Meta{Page: page, PerPage: per, Total: total},
		Types: optionsOf(Types), Statuses: optionsOf(Statuses), CanManage: actor.Can(PermManage)}
	for _, r := range rows {
		out.Data = append(out.Data, summarize(projectRow{ID: r.ID, Code: r.Code, Name: r.Name, Type: r.Type, Status: r.Status,
			OwnerName: r.OwnerName, StartDate: r.StartDate, Deadline: r.Deadline, MembersCount: r.MembersCount}, ms[r.ID], now))
	}
	if out.Owners, _, err = s.staff(ctx, actor); err != nil {
		return nil, err
	}
	return out, nil
}

// ───────────────────────── Chi tiết ─────────────────────────

type Member struct {
	ID   uuid.UUID `json:"id"`
	Name string    `json:"name"`
}

type Project struct {
	Summary
	Description  *string    `json:"description"`
	OwnerID      *uuid.UUID `json:"owner_id"`
	MemberIDs    []string   `json:"member_ids"`
	Members      []Member   `json:"members"`
	KickoffNotes *string    `json:"kickoff_notes"`
	KickoffLink  *string    `json:"kickoff_link"`
	PlanLockedAt *time.Time `json:"plan_locked_at"`
	Locker       *string    `json:"locker"`
	Creator      *string    `json:"creator"`
	CompletedAt  *time.Time `json:"completed_at"`
}

type Milestone struct {
	ID             uuid.UUID  `json:"id"`
	Title          string     `json:"title"`
	Description    *string    `json:"description"`
	AssigneeID     *uuid.UUID `json:"assignee_id"`
	Assignee       *string    `json:"assignee"`
	StartDate      *string    `json:"start_date"`
	DueDate        string     `json:"due_date"`
	TargetQuantity float64    `json:"target_quantity"`
	DoneQuantity   float64    `json:"done_quantity"`
	QuantityLabel  string     `json:"quantity_label"`
	Unit           string     `json:"unit"`
	Status         string     `json:"status"`
	StatusLabel    string     `json:"status_label"`
	CompletedAt    *time.Time `json:"completed_at"`
	Progress       int        `json:"progress"`
	DeadlineState  *string    `json:"deadline_state"`
	Mine           bool       `json:"mine"`
	CanUpdate      bool       `json:"can_update"`
}

type Update struct {
	ID            uuid.UUID  `json:"id"`
	User          *string    `json:"user"`
	Milestone     *string    `json:"milestone"`
	CreatedAt     time.Time  `json:"created_at"`
	Quantity      *string    `json:"quantity"`
	Content       string     `json:"content"`
	Difficulties  *string    `json:"difficulties"`
	Links         []string   `json:"links"`
	MarksComplete bool       `json:"marks_complete"`
	Response      *string    `json:"response"`
	Responder     *string    `json:"responder"`
	RespondedAt   *time.Time `json:"responded_at"`
}

type Detail struct {
	Project           Project     `json:"project"`
	Milestones        []Milestone `json:"milestones"`
	Updates           []Update    `json:"updates"`
	Penalties         []Penalty   `json:"penalties"`
	PenaltiesPending  bool        `json:"penalties_pending"`
	Staff             []Option    `json:"staff"`
	Types             []Option    `json:"types"`
	MilestoneStatuses []Option    `json:"milestone_statuses"`
	CanManage         bool        `json:"can_manage"`
	CanUpdate         bool        `json:"can_update"`
	CanRespond        bool        `json:"can_respond"`
	GraceHours        int32       `json:"grace_hours"`
	SlaEnabled        bool        `json:"sla_enabled"`
}

// involves: người được cập nhật tiến độ — phụ trách, thành viên, người nhận mốc.
func involves(user uuid.UUID, owner *uuid.UUID, members []projectsdb.ProjectMembersRow, ms []projectsdb.MilestonesOfRow) bool {
	if owner != nil && *owner == user {
		return true
	}
	if slices.ContainsFunc(members, func(m projectsdb.ProjectMembersRow) bool { return m.ID == user }) {
		return true
	}
	return slices.ContainsFunc(ms, func(m projectsdb.MilestonesOfRow) bool { return m.AssigneeID != nil && *m.AssigneeID == user })
}

func milestoneRow(m projectsdb.MilestonesOfRow, user uuid.UUID, canManage bool, now time.Time) Milestone {
	mm := toMile(m)
	mine := m.AssigneeID != nil && *m.AssigneeID == user
	out := Milestone{
		ID: m.ID, Title: m.Title, Description: m.Description, AssigneeID: m.AssigneeID, Assignee: m.AssigneeName,
		StartDate: ymd(m.StartDate), DueDate: m.DueDate.Format("2006-01-02"), TargetQuantity: m.TargetQuantity,
		DoneQuantity: m.DoneQuantity, Unit: m.Unit, Status: m.Status, StatusLabel: labelOf(MilestoneStatuses, m.Status),
		QuantityLabel: FormatQuantity(m.DoneQuantity) + " / " + FormatQuantity(m.TargetQuantity) + " " + m.Unit,
		CompletedAt:   m.CompletedAt, Progress: mm.progress(), Mine: mine, CanUpdate: canManage || mine,
	}
	if st := mm.deadlineState(now); st != "" {
		out.DeadlineState = &st
	}
	return out
}

// visibleProject: dự án (chưa xóa) người dùng được xem.
func (s *Service) visibleProject(ctx context.Context, actor *iam.Access, id uuid.UUID) (projectsdb.GetProjectRow, error) {
	ok, err := s.q.ProjectVisible(ctx, projectsdb.ProjectVisibleParams{ID: id, SeeAll: seeAll(actor), UserID: actor.UserID})
	if err != nil {
		return projectsdb.GetProjectRow{}, err
	}
	if !ok {
		return projectsdb.GetProjectRow{}, errProjectNotFound
	}
	return s.getProject(ctx, s.q, id)
}

func (s *Service) getProject(ctx context.Context, q *projectsdb.Queries, id uuid.UUID) (projectsdb.GetProjectRow, error) {
	p, err := q.GetProject(ctx, id)
	if isNoRows(err) {
		return p, errProjectNotFound
	}
	return p, err
}

func (s *Service) Get(ctx context.Context, actor *iam.Access, id uuid.UUID) (*Detail, error) {
	p, err := s.visibleProject(ctx, actor, id)
	if err != nil {
		return nil, err
	}
	members, err := s.q.ProjectMembers(ctx, id)
	if err != nil {
		return nil, err
	}
	ms, err := s.q.MilestonesOf(ctx, []uuid.UUID{id})
	if err != nil {
		return nil, err
	}
	now := s.now()
	canManage := actor.Can(PermManage)
	sum := summarize(projectRow{ID: p.ID, Code: p.Code, Name: p.Name, Type: p.Type, Status: p.Status, OwnerName: p.OwnerName,
		StartDate: p.StartDate, Deadline: p.Deadline, MembersCount: int64(len(members))}, ms, now)
	out := &Detail{
		Project: Project{
			Summary: sum, Description: p.Description, OwnerID: p.OwnerID, MemberIDs: make([]string, 0, len(members)),
			Members: make([]Member, 0, len(members)), KickoffNotes: p.KickoffNotes, KickoffLink: p.KickoffLink,
			PlanLockedAt: p.PlanLockedAt, Locker: p.LockerName, Creator: p.CreatorName, CompletedAt: p.CompletedAt,
		},
		Milestones: make([]Milestone, 0, len(ms)), Types: optionsOf(Types), MilestoneStatuses: optionsOf(MilestoneStatuses),
		CanManage:  canManage,
		CanUpdate:  isOpen(p.Status) && (canManage || involves(actor.UserID, p.OwnerID, members, ms)),
		CanRespond: canManage || (p.OwnerID != nil && *p.OwnerID == actor.UserID),
	}
	for _, m := range members {
		out.Project.MemberIDs = append(out.Project.MemberIDs, m.ID.String())
		out.Project.Members = append(out.Project.Members, Member{ID: m.ID, Name: m.FullName})
	}
	mids := make([]uuid.UUID, 0, len(ms))
	titles := map[uuid.UUID]string{}
	for _, m := range ms {
		out.Milestones = append(out.Milestones, milestoneRow(m, actor.UserID, canManage, now))
		mids = append(mids, m.ID)
		titles[m.ID] = m.Title
	}
	ups, err := s.q.ProjectUpdates(ctx, projectsdb.ProjectUpdatesParams{ProjectIds: []uuid.UUID{id}, Lim: 100})
	if err != nil {
		return nil, err
	}
	out.Updates = make([]Update, 0, len(ups))
	for _, u := range ups {
		out.Updates = append(out.Updates, updateRow(u))
	}
	penalties, err := s.penalties.ForMilestones(ctx, mids)
	if err != nil {
		return nil, err
	}
	out.Penalties = withTitles(penalties, titles)
	out.PenaltiesPending = !s.penalties.Enabled()
	// Người đang tham gia dự án (phụ trách, thành viên, người nhận mốc) luôn giữ trong ô chọn.
	keep := make([]uuid.UUID, 0, len(members)+len(ms)+1)
	if p.OwnerID != nil {
		keep = append(keep, *p.OwnerID)
	}
	for _, m := range members {
		keep = append(keep, m.ID)
	}
	for _, m := range ms {
		if m.AssigneeID != nil {
			keep = append(keep, *m.AssigneeID)
		}
	}
	if out.Staff, _, err = s.staff(ctx, actor, keep...); err != nil {
		return nil, err
	}
	late := s.rule(ctx, SlaLate, 1)
	out.GraceHours, out.SlaEnabled = late.Value, late.Enabled
	return out, nil
}

func updateRow(u projectsdb.ProjectUpdatesRow) Update {
	out := Update{
		ID: u.ID, User: u.UserName, Milestone: u.MilestoneTitle, CreatedAt: u.CreatedAt, Content: u.Content,
		Difficulties: u.Difficulties, Links: nonNil(u.Links), MarksComplete: u.MarksComplete, Response: u.Response,
		Responder: u.ResponderName, RespondedAt: u.RespondedAt,
	}
	if u.QuantityDone != nil && u.MilestoneTitle != nil {
		q := FormatQuantity(*u.QuantityDone) + " " + deref(u.MilestoneUnit)
		out.Quantity = &q
	}
	return out
}

// ───────────────────────── Tạo / sửa / xóa ─────────────────────────

type ProjectInput struct {
	Name         *string  `json:"name"`
	Type         string   `json:"type"`
	OwnerID      string   `json:"owner_id"`
	MemberIDs    []string `json:"member_ids"`
	StartDate    *string  `json:"start_date"`
	Deadline     *string  `json:"deadline"`
	Description  *string  `json:"description"`
	KickoffNotes *string  `json:"kickoff_notes"`
	KickoffLink  *string  `json:"kickoff_link"`
}

type projectData struct {
	name, typ   string
	owner       uuid.UUID
	members     []uuid.UUID // người phụ trách + thành viên đã chọn
	start, end  *time.Time
	description *string
	notes, link *string
}

const errStaff = "Có người được chọn không còn hoạt động hoặc không có quyền dự án học thuật."

func (s *Service) validateProject(ctx context.Context, in ProjectInput) (projectData, error) {
	d := projectData{name: deref(text(in.Name)), typ: in.Type, description: text(in.Description), notes: text(in.KickoffNotes), link: text(in.KickoffLink)}
	switch {
	case d.name == "":
		return d, iam.Invalid("Nhập tên dự án.")
	case tooLong(&d.name, 255):
		return d, iam.Invalid("Tên dự án không được dài quá 255 ký tự.")
	case d.typ == "":
		return d, iam.Invalid("Chọn loại dự án.")
	case !hasKey(Types, d.typ):
		return d, iam.Invalid("Loại dự án không hợp lệ.")
	case strings.TrimSpace(in.OwnerID) == "":
		return d, iam.Invalid("Chọn người phụ trách.")
	case len(in.MemberIDs) > 50:
		return d, iam.Invalid("Chọn tối đa 50 thành viên.")
	}
	var ok bool
	if d.start, ok = parseDate(in.StartDate); !ok {
		return d, iam.Invalid("Ngày bắt đầu không hợp lệ.")
	}
	if d.end, ok = parseDate(in.Deadline); !ok {
		return d, iam.Invalid("Deadline không hợp lệ.")
	}
	switch {
	case d.start != nil && d.end != nil && d.end.Before(*d.start):
		return d, iam.Invalid("Deadline phải sau ngày bắt đầu.")
	case tooLong(d.description, 5000):
		return d, iam.Invalid("Mô tả không được dài quá 5000 ký tự.")
	case tooLong(d.notes, 10000):
		return d, iam.Invalid("Biên bản họp không được dài quá 10000 ký tự.")
	case d.link != nil && (!validURL(*d.link) || len(*d.link) > 500):
		return d, iam.Invalid("Link biên bản họp phải là đường dẫn đầy đủ (https://…).")
	}
	owner := parseUUID(in.OwnerID)
	if owner == nil {
		return d, iam.Invalid(errStaff)
	}
	d.owner = *owner
	d.members = []uuid.UUID{*owner}
	for _, raw := range in.MemberIDs {
		id := parseUUID(raw)
		if id == nil {
			return d, iam.Invalid(errStaff)
		}
		if !slices.Contains(d.members, *id) {
			d.members = append(d.members, *id)
		}
	}
	_, staff, err := s.staff(ctx, nil)
	if err != nil {
		return d, err
	}
	for _, id := range d.members {
		if !staff[id] {
			return d, iam.Invalid(errStaff)
		}
	}
	return d, nil
}

func projectSnapshot(d projectData) map[string]any {
	return map[string]any{
		"name": d.name, "type": d.typ, "owner_id": d.owner.String(), "start_date": deref(ymd(d.start)),
		"deadline": deref(ymd(d.end)), "description": deref(d.description), "kickoff_notes": deref(d.notes),
		"kickoff_link": deref(d.link),
	}
}

// nextCode: mã DA-<năm>-NNN kế tiếp (gọi trong giao dịch, sau khóa LockProjectCodes).
func (s *Service) nextCode(ctx context.Context, q *projectsdb.Queries) (string, error) {
	if err := q.LockProjectCodes(ctx); err != nil {
		return "", err
	}
	year := s.now().In(vnZone).Format("2006")
	n, err := q.MaxProjectNumber(ctx, year)
	if err != nil {
		return "", err
	}
	return fmt.Sprintf("DA-%s-%03d", year, n+1), nil
}

const memberTitle, memberBody = "Bạn tham gia dự án %s", "Xem kế hoạch, mốc và cập nhật tiến độ phần việc của bạn."

// send: thông báo cho từng người (bỏ người thao tác), link về trang dự án.
func send(ctx context.Context, db notify.DB, ids []uuid.UUID, actor uuid.UUID, typ, title, body string, project uuid.UUID) error {
	out := make([]uuid.UUID, 0, len(ids))
	for _, id := range ids {
		if id != actor && !slices.Contains(out, id) {
			out = append(out, id)
		}
	}
	if len(out) == 0 {
		return nil
	}
	_, err := notify.Send(ctx, db, out, notify.Notification{
		Type: typ, Title: title, Body: body, Link: projectLink(project), CreatedBy: &actor, Except: &actor,
	})
	return err
}

// Create: dự án mới ở "Lên kế hoạch"; thành viên = danh sách chọn + người phụ trách.
func (s *Service) Create(ctx context.Context, actor *iam.Access, in ProjectInput) (uuid.UUID, string, error) {
	d, err := s.validateProject(ctx, in)
	if err != nil {
		return uuid.Nil, "", err
	}
	var id uuid.UUID
	var code string
	err = s.tx(ctx, func(tx pgx.Tx, q *projectsdb.Queries) error {
		if code, err = s.nextCode(ctx, q); err != nil {
			return err
		}
		id, err = q.CreateProject(ctx, projectsdb.CreateProjectParams{
			Code: code, Name: d.name, Type: d.typ, Description: d.description, OwnerID: &d.owner, StartDate: d.start,
			Deadline: d.end, KickoffNotes: d.notes, KickoffLink: d.link, CreatedBy: &actor.UserID,
		})
		if err != nil {
			return err
		}
		added, err := q.AddMembers(ctx, projectsdb.AddMembersParams{ProjectID: id, UserIds: d.members})
		if err != nil {
			return err
		}
		if err := audit.Record(ctx, tx, audit.Entry{
			ActorID: &actor.UserID, Module: audit.ModuleSyllabus, Event: audit.EventCreated, Action: PermManage,
			SubjectType: "academic_project", SubjectID: id.String(), SubjectLabel: code + " · " + d.name,
			Description: "Tạo dự án học thuật " + code, Changes: audit.Diff(nil, projectSnapshot(d)),
		}); err != nil {
			return err
		}
		return send(ctx, tx, added, actor.UserID, "academic_project_member", fmt.Sprintf(memberTitle, d.name), memberBody, id)
	})
	if err != nil {
		return uuid.Nil, "", err
	}
	s.log.Info("academic project created", "project_id", id, "code", code, "by", actor.UserID)
	return id, "Đã tạo dự án " + code + `. Thêm các mốc rồi bấm "Chốt tiến độ".`, nil
}

func (s *Service) Update(ctx context.Context, actor *iam.Access, id uuid.UUID, in ProjectInput) (string, error) {
	cur, err := s.getProject(ctx, s.q, id)
	if err != nil {
		return "", err
	}
	d, err := s.validateProject(ctx, in)
	if err != nil {
		return "", err
	}
	before := projectSnapshot(projectData{name: cur.Name, typ: cur.Type, owner: deref(cur.OwnerID), start: cur.StartDate,
		end: cur.Deadline, description: cur.Description, notes: cur.KickoffNotes, link: cur.KickoffLink})
	err = s.tx(ctx, func(tx pgx.Tx, q *projectsdb.Queries) error {
		if err := q.UpdateProject(ctx, projectsdb.UpdateProjectParams{
			ID: id, Name: d.name, Type: d.typ, Description: d.description, OwnerID: &d.owner, StartDate: d.start,
			Deadline: d.end, KickoffNotes: d.notes, KickoffLink: d.link,
		}); err != nil {
			return err
		}
		if err := q.RemoveMembersExcept(ctx, projectsdb.RemoveMembersExceptParams{ProjectID: id, Keep: d.members}); err != nil {
			return err
		}
		added, err := q.AddMembers(ctx, projectsdb.AddMembersParams{ProjectID: id, UserIds: d.members})
		if err != nil {
			return err
		}
		if err := audit.Record(ctx, tx, audit.Entry{
			ActorID: &actor.UserID, Module: audit.ModuleSyllabus, Event: audit.EventUpdated, Action: PermManage,
			SubjectType: "academic_project", SubjectID: id.String(), SubjectLabel: cur.Code + " · " + d.name,
			Description: "Cập nhật dự án học thuật " + cur.Code, Changes: audit.Diff(before, projectSnapshot(d)),
		}); err != nil {
			return err
		}
		return send(ctx, tx, added, actor.UserID, "academic_project_member", fmt.Sprintf(memberTitle, d.name), memberBody, id)
	})
	if err != nil {
		return "", err
	}
	s.log.Info("academic project updated", "project_id", id, "by", actor.UserID)
	return "Đã lưu dự án.", nil
}

func (s *Service) Delete(ctx context.Context, actor *iam.Access, id uuid.UUID) (string, error) {
	cur, err := s.getProject(ctx, s.q, id)
	if err != nil {
		return "", err
	}
	err = s.tx(ctx, func(tx pgx.Tx, q *projectsdb.Queries) error {
		if err := q.DeleteProject(ctx, id); err != nil {
			return err
		}
		return audit.Record(ctx, tx, audit.Entry{
			ActorID: &actor.UserID, Module: audit.ModuleSyllabus, Event: audit.EventDeleted, Action: PermManage,
			SubjectType: "academic_project", SubjectID: id.String(), SubjectLabel: cur.Code + " · " + cur.Name,
			Description: "Xóa dự án học thuật " + cur.Code,
		})
	})
	if err != nil {
		return "", err
	}
	s.log.Info("academic project deleted", "project_id", id, "by", actor.UserID)
	return "Đã xóa dự án " + cur.Code + ".", nil
}

// ───────────────────────── Chốt tiến độ / trạng thái ─────────────────────────

// Lock: chốt tiến độ sau buổi họp thống nhất — dự án chuyển "Đang thực hiện", từ đây mốc trễ hạn bị lập biên bản.
func (s *Service) Lock(ctx context.Context, actor *iam.Access, id uuid.UUID) (string, error) {
	cur, err := s.getProject(ctx, s.q, id)
	if err != nil {
		return "", err
	}
	if cur.Status != "planning" {
		return "", conflict("Chỉ chốt tiến độ khi dự án đang lên kế hoạch.")
	}
	ms, err := s.q.MilestonesOf(ctx, []uuid.UUID{id})
	if err != nil {
		return "", err
	}
	if len(ms) == 0 {
		return "", conflict("Thêm ít nhất một mốc (deadline + khối lượng) trước khi chốt tiến độ.")
	}
	members, err := s.q.ProjectMembers(ctx, id)
	if err != nil {
		return "", err
	}
	// Người tham gia: phụ trách + thành viên + người nhận mốc.
	var ids []uuid.UUID
	if cur.OwnerID != nil {
		ids = append(ids, *cur.OwnerID)
	}
	for _, m := range members {
		ids = append(ids, m.ID)
	}
	for _, m := range ms {
		if m.AssigneeID != nil {
			ids = append(ids, *m.AssigneeID)
		}
	}
	err = s.tx(ctx, func(tx pgx.Tx, q *projectsdb.Queries) error {
		if err := q.LockProject(ctx, projectsdb.LockProjectParams{ID: id, By: &actor.UserID}); err != nil {
			return err
		}
		if err := audit.Record(ctx, tx, audit.Entry{
			ActorID: &actor.UserID, Module: audit.ModuleSyllabus, Event: audit.EventUpdated, Action: PermManage,
			SubjectType: "academic_project", SubjectID: id.String(), SubjectLabel: cur.Code + " · " + cur.Name,
			Description: "Chốt tiến độ dự án học thuật " + cur.Code,
			Changes:     audit.Diff(map[string]any{"status": "planning"}, map[string]any{"status": "active"}),
		}); err != nil {
			return err
		}
		return send(ctx, tx, ids, actor.UserID, "academic_project_locked", "Dự án "+cur.Name+" đã chốt tiến độ",
			"Kiểm tra mốc và deadline của bạn; mốc trễ hạn sẽ bị lập biên bản theo quy định.", id)
	})
	if err != nil {
		return "", err
	}
	s.log.Info("academic project locked", "project_id", id, "by", actor.UserID)
	return `Đã chốt tiến độ. Dự án chuyển sang "Đang thực hiện".`, nil
}

type statusAction struct {
	from    []string
	to, msg string
}

var statusActions = map[string]statusAction{
	"pause":    {[]string{"active"}, "paused", "Đã tạm dừng dự án (mốc không bị tính trễ khi tạm dừng)."},
	"resume":   {[]string{"paused"}, "active", "Dự án tiếp tục thực hiện."},
	"complete": {[]string{"active", "paused"}, "completed", "Đã đóng dự án: hoàn thành."},
	"cancel":   {openStatuses, "cancelled", "Đã hủy dự án."},
}

func (s *Service) ChangeStatus(ctx context.Context, actor *iam.Access, id uuid.UUID, action string) (string, error) {
	act, ok := statusActions[action]
	if !ok {
		return "", iam.Invalid("Thao tác không hợp lệ.")
	}
	cur, err := s.getProject(ctx, s.q, id)
	if err != nil {
		return "", err
	}
	if !slices.Contains(act.from, cur.Status) {
		return "", conflict("Trạng thái hiện tại của dự án không cho phép thao tác này.")
	}
	err = s.tx(ctx, func(tx pgx.Tx, q *projectsdb.Queries) error {
		if err := q.SetProjectStatus(ctx, projectsdb.SetProjectStatusParams{ID: id, Status: act.to}); err != nil {
			return err
		}
		return audit.Record(ctx, tx, audit.Entry{
			ActorID: &actor.UserID, Module: audit.ModuleSyllabus, Event: audit.EventUpdated, Action: PermManage,
			SubjectType: "academic_project", SubjectID: id.String(), SubjectLabel: cur.Code + " · " + cur.Name,
			Description: "Đổi trạng thái dự án học thuật " + cur.Code + ": " + labelOf(Statuses, act.to),
			Changes:     audit.Diff(map[string]any{"status": cur.Status}, map[string]any{"status": act.to}),
		})
	})
	if err != nil {
		return "", err
	}
	s.log.Info("academic project status changed", "project_id", id, "status", act.to, "by", actor.UserID)
	return act.msg, nil
}

// sortedBy: sắp ổn định theo khóa giảm dần.
func sortedDesc[T any](v []T, key func(T) int) {
	slices.SortStableFunc(v, func(a, b T) int { return cmp.Compare(key(b), key(a)) })
}
