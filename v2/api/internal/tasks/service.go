// Package tasks: công việc (giao việc 2 chiều, đổi trạng thái, chuyển lại cho người giao, Admin xác nhận hoàn thành,
// nhiệm vụ hằng ngày của trợ giảng). Bám theo WorkTaskController / WorkTask của bản Laravel.
package tasks

import (
	"context"
	"errors"
	"log/slog"
	"regexp"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgtype"
	"github.com/jackc/pgx/v5/pgxpool"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/tasks/tasksdb"
)

const module = "work_task"

var (
	ErrNotFound       = &iam.Error{Status: 404, Code: "not_found", Message: "Không tìm thấy công việc."}
	ErrNotParticipant = &iam.Error{Status: 403, Code: "forbidden", Message: "Bạn không phụ trách công việc này."}
	ErrCannotCreate   = &iam.Error{Status: 403, Code: "forbidden", Message: "Bạn không có quyền giao việc."}
	ErrAdminOnly      = &iam.Error{Status: 403, Code: "admin_only", Message: "Chỉ Admin được xác nhận / trả lại công việc."}
	ErrSelfApproval   = &iam.Error{Status: 403, Code: "self_approval", Message: "Không thể tự duyệt công việc của chính mình."}
	ErrNotPending     = &iam.Error{Status: 422, Code: "not_pending", Message: "Công việc không ở trạng thái chờ xác nhận."}
)

type Service struct {
	pool *pgxpool.Pool
	q    *tasksdb.Queries
	log  *slog.Logger
	now  func() time.Time
}

func NewService(pool *pgxpool.Pool, log *slog.Logger) *Service {
	return &Service{pool: pool, q: tasksdb.New(pool), log: log, now: time.Now}
}

// scopeOf: mức phạm vi Công việc của người dùng và chi nhánh của họ (dùng cho mức branch).
func scopeOf(a *iam.Access) (string, []uuid.UUID) {
	ids := a.BranchIDs()
	if ids == nil {
		ids = []uuid.UUID{}
	}
	return a.Level(module), ids
}

func isAdmin(a *iam.Access) bool { return a.SuperAdmin }

func (s *Service) inTx(ctx context.Context, fn func(q *tasksdb.Queries) error) error {
	return pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error { return fn(s.q.WithTx(tx)) })
}

// ───── Kiểu dữ liệu trả cho giao diện ─────

// Task: một công việc (danh sách dùng phần đầu, chi tiết điền thêm phần sau).
type Task struct {
	ID             uuid.UUID    `json:"id"`
	No             int64        `json:"no"`
	Title          string       `json:"title"`
	Description    *string      `json:"description"`
	Status         string       `json:"status"`
	StatusLabel    string       `json:"status_label"`
	TaskType       string       `json:"task_type"`
	TypeLabel      string       `json:"type_label"`
	Kind           *string      `json:"kind"`
	ClassID        *uuid.UUID   `json:"class_id"`
	LessonSession  *string      `json:"lesson_session"`
	SlotLabel      *string      `json:"slot_label"`
	BlockedReason  *string      `json:"blocked_reason"`
	HandedBackFrom *string      `json:"handed_back_from"`
	AssigneeID     *uuid.UUID   `json:"assignee_id"`
	Assignee       *string      `json:"assignee"`
	CreatorID      uuid.UUID    `json:"creator_id"`
	Creator        string       `json:"creator"`
	DueDate        *string      `json:"due_date"`
	DueTime        *string      `json:"due_time"`
	Allowed        []Transition `json:"allowed"`
	CreatedAt      time.Time    `json:"created_at"`
	UpdatedAt      time.Time    `json:"updated_at"`
	Version        int32        `json:"version"`

	// Chỉ có ở chi tiết
	CreatorIsMe      bool       `json:"creator_is_me"`
	BranchID         *uuid.UUID `json:"branch_id,omitempty"`
	Branch           *string    `json:"branch,omitempty"`
	HandedBackAt     *time.Time `json:"handed_back_at,omitempty"`
	ConfirmedBy      *string    `json:"confirmed_by,omitempty"`
	ConfirmedAt      *time.Time `json:"confirmed_at,omitempty"`
	CompletedAt      *time.Time `json:"completed_at,omitempty"`
	RejectionReason  *string    `json:"rejection_reason,omitempty"`
	CompletionNote   *string    `json:"completion_note,omitempty"`
	CompletionProof  *string    `json:"completion_proof_url,omitempty"`
	TimeSlotCategory string     `json:"time_slot_category,omitempty"`
}

func (s *Service) toTask(t tasksdb.OpsWorkTask, assignee *string, creator string, handedBackFrom *string, a *iam.Access) Task {
	out := Task{
		ID: t.ID, No: t.No, Title: t.Title, Description: t.Description,
		Status: t.Status, StatusLabel: statusLabel(t.Status),
		TaskType: t.TaskType, TypeLabel: typeLabel(t.TaskType, t.Frequency), Kind: t.Kind,
		ClassID: t.ClassID, LessonSession: t.LessonSession,
		AssigneeID: t.AssigneeID, Assignee: assignee, CreatorID: t.CreatorID, Creator: creator,
		DueDate: formatDate(t.DueDate), DueTime: formatClock(t.DueTime),
		CreatedAt: t.CreatedAt, UpdatedAt: t.UpdatedAt, Version: t.Version,
	}
	// Nhãn ca trực: nhiệm vụ trợ giảng (giao theo ca) hoặc việc gắn buổi học.
	if (t.Kind != nil && *t.Kind == KindTADaily) || t.LessonSession != nil {
		l := slotLabel(t.TimeSlotCategory)
		out.SlotLabel = &l
	}
	if t.Status == StatusBlocked {
		out.BlockedReason = t.BlockedReason
	}
	if t.HandedBackAt != nil && t.Status != StatusCompleted && t.Status != StatusCanceled {
		out.HandedBackFrom = handedBackFrom
	}
	isCreator := t.CreatorID == a.UserID
	out.Allowed = transitionOptions(t.Status, allowedTransitions(t.Status, t.CreatorID, t.AssigneeID, a.UserID, isAdmin(a)), isCreator)
	return out
}

// ───── Danh sách ─────

type ListFilter struct {
	Tab        string // mine | assigned
	Status     string
	TaskType   string
	Search     string
	DateFrom   string
	DateTo     string
	AssigneeID string
	Page       int
	PerPage    int
}

type Option struct {
	ID    uuid.UUID `json:"id"`
	Label string    `json:"label"`
}

type Counts struct {
	Mine      int64 `json:"mine"`
	Assigned  int64 `json:"assigned"`
	Overdue   int64 `json:"overdue"`
	Pending   int64 `json:"pending"`
	Approvals int64 `json:"approvals"`
}

type ListResult struct {
	Tasks     []Task   `json:"tasks"`
	Total     int64    `json:"total"`
	Page      int      `json:"page"`
	PerPage   int      `json:"per_page"`
	Tab       string   `json:"tab"`
	Counts    Counts   `json:"counts"`
	Assignees []Option `json:"assignees"`
}

var perPageOptions = []int{10, 20, 50, 100}

func strOrNil(s string) *string {
	if s = strings.TrimSpace(s); s == "" {
		return nil
	}
	return &s
}

// likePattern: chuỗi tìm kiếm cho ILIKE (thoát % và _).
func likePattern(s string) *string {
	s = strings.TrimSpace(s)
	if s == "" {
		return nil
	}
	s = strings.NewReplacer(`\`, `\\`, "%", `\%`, "_", `\_`).Replace(s)
	p := "%" + s + "%"
	return &p
}

// userLabel: "Tên (Vai trò, ...)" như ô chọn nhân sự của Laravel; chưa có vai trò thì "(Nhân viên)".
func userLabel(name string, roles []string) string {
	r := strings.Join(roles, ", ")
	if r == "" {
		r = "Nhân viên"
	}
	return name + " (" + r + ")"
}

// List: danh sách đầu việc — chỉ "Của tôi" (việc mình được giao) và "Tôi giao" (việc mình giao), kể cả Admin.
func (s *Service) List(ctx context.Context, a *iam.Access, f ListFilter) (ListResult, error) {
	tab := f.Tab
	if tab != "assigned" {
		tab = "mine"
	}
	per := 10
	for _, p := range perPageOptions {
		if f.PerPage == p {
			per = p
		}
	}
	page := max(f.Page, 1)
	p := tasksdb.ListTasksParams{
		Tab: tab, Me: a.UserID, Search: likePattern(f.Search),
		Lim: int32(per), Off: int32((page - 1) * per),
	}
	p.Scope, p.BranchIds = scopeOf(a)
	if v := strings.TrimSpace(f.Status); v != "" && v != "all" {
		p.Status = &v
	}
	if v := strings.TrimSpace(f.TaskType); v != "" && v != "all" {
		p.TaskType = &v
	}
	if f.DateFrom != "" {
		d, ok := parseDate(f.DateFrom)
		if !ok {
			return ListResult{}, iam.Invalid("Hạn từ ngày không phải là ngày hợp lệ.")
		}
		p.DateFrom = &d
	}
	if f.DateTo != "" {
		d, ok := parseDate(f.DateTo)
		if !ok {
			return ListResult{}, iam.Invalid("Hạn đến ngày không phải là ngày hợp lệ.")
		}
		if p.DateFrom != nil && d.Before(*p.DateFrom) {
			return ListResult{}, iam.Invalid("Hạn đến ngày phải sau hoặc bằng hạn từ ngày.")
		}
		p.DateTo = &d
	}
	if f.AssigneeID != "" {
		id, err := uuid.Parse(f.AssigneeID)
		if err != nil {
			return ListResult{}, iam.Invalid("Nhân viên đã chọn không hợp lệ.")
		}
		p.AssigneeID = &id
	}

	rows, err := s.q.ListTasks(ctx, p)
	if err != nil {
		return ListResult{}, err
	}
	out := ListResult{Tasks: make([]Task, 0, len(rows)), Page: page, PerPage: per, Tab: tab, Assignees: []Option{}}
	for _, r := range rows {
		out.Total = r.Total
		out.Tasks = append(out.Tasks, s.toTask(r.OpsWorkTask, r.AssigneeName, r.CreatorName, r.HandedBackFromName, a))
	}
	if len(rows) == 0 && page > 1 {
		// Trang vượt quá: vẫn trả tổng số để giao diện quay về trang cuối.
		p.Lim, p.Off = 1, 0
		if first, err := s.q.ListTasks(ctx, p); err == nil && len(first) > 0 {
			out.Total = first[0].Total
		}
	}

	c, err := s.q.TaskCounts(ctx, a.UserID)
	if err != nil {
		return ListResult{}, err
	}
	out.Counts = Counts{Mine: c.Mine, Assigned: c.Assigned, Overdue: c.Overdue, Pending: c.Pending}
	if out.Counts.Approvals, err = s.countAwaiting(ctx, a); err != nil {
		return ListResult{}, err
	}
	users, err := s.q.ListOwnAssignees(ctx, a.UserID)
	if err != nil {
		return ListResult{}, err
	}
	for _, u := range users {
		out.Assignees = append(out.Assignees, Option{u.ID, userLabel(u.FullName, u.RoleLabels)})
	}
	return out, nil
}

// countAwaiting: số việc đang chờ chính người này xác nhận (chỉ Admin xác nhận).
func (s *Service) countAwaiting(ctx context.Context, a *iam.Access) (int64, error) {
	if !isAdmin(a) {
		return 0, nil
	}
	return s.q.CountAwaitingConfirmation(ctx, a.UserID)
}

// ───── Chi tiết ─────

// Get: chi tiết công việc trong phạm vi xem; ngoài phạm vi → không tìm thấy.
func (s *Service) Get(ctx context.Context, a *iam.Access, id uuid.UUID) (Task, error) {
	scope, branches := scopeOf(a)
	r, err := s.q.GetVisibleTask(ctx, tasksdb.GetVisibleTaskParams{ID: id, Me: a.UserID, Scope: scope, BranchIds: branches})
	if errors.Is(err, pgx.ErrNoRows) {
		return Task{}, ErrNotFound
	}
	if err != nil {
		return Task{}, err
	}
	t := r.OpsWorkTask
	out := s.toTask(t, r.AssigneeName, r.CreatorName, r.HandedBackFromName, a)
	out.CreatorIsMe = t.CreatorID == a.UserID
	out.BlockedReason = t.BlockedReason
	out.BranchID, out.Branch = t.BranchID, r.BranchName
	if t.HandedBackAt != nil {
		out.HandedBackFrom = r.HandedBackFromName
		out.HandedBackAt = t.HandedBackAt
	}
	out.ConfirmedBy, out.ConfirmedAt, out.CompletedAt = r.ConfirmedByName, t.ConfirmedAt, t.CompletedAt
	out.RejectionReason, out.CompletionNote, out.CompletionProof = t.RejectionReason, t.CompletionNote, t.CompletionProofUrl
	out.TimeSlotCategory = t.TimeSlotCategory
	return out, nil
}

// ───── Giao việc ─────

// CreateInput: form "Giao việc mới". ID gửi dạng chuỗi để báo lỗi tiếng Việt thay vì lỗi đọc JSON.
type CreateInput struct {
	Title            string `json:"title"`
	Description      string `json:"description"`
	AssigneeID       string `json:"assignee_id"`
	DueDate          string `json:"due_date"`
	DueTime          string `json:"due_time"`
	TaskType         string `json:"task_type"`
	Frequency        string `json:"frequency"`
	BranchID         string `json:"branch_id"`
	TimeSlotCategory string `json:"time_slot_category"`
}

type CreateOptions struct {
	Title          string   `json:"title"`
	Users          []Option `json:"users"`
	Branches       []Option `json:"branches"`
	DefaultDueDate string   `json:"default_due_date"`
	CanTAAssign    bool     `json:"can_ta_assign"`
}

func ensureCanCreate(a *iam.Access) error {
	if !a.CanAny("work_task.create", "work_task.request") {
		return ErrCannotCreate
	}
	return nil
}

// assignable: người nhận việc được chọn. Có quyền giao việc (work_task.create): mọi nhân sự đang hoạt động (phạm vi
// Chi nhánh: nhân sự chi nhánh mình + chính mình + Admin); chỉ có quyền đề xuất (work_task.request): Admin và người có
// quyền duyệt công việc.
func (s *Service) assignable(ctx context.Context, a *iam.Access) ([]tasksdb.ListAssignableUsersRow, error) {
	p := tasksdb.ListAssignableUsersParams{Mode: "all", Me: a.UserID, BranchIds: []uuid.UUID{}}
	switch {
	case !a.Can("work_task.create"):
		p.Mode = "approvers"
	case a.Level(module) == iam.ScopeBranch:
		p.Mode, p.BranchIds = "branch", a.BranchIDs()
		if p.BranchIds == nil {
			p.BranchIds = []uuid.UUID{}
		}
	}
	return s.q.ListAssignableUsers(ctx, p)
}

func (s *Service) CreateOptions(ctx context.Context, a *iam.Access) (CreateOptions, error) {
	if err := ensureCanCreate(a); err != nil {
		return CreateOptions{}, err
	}
	out := CreateOptions{
		Title:          "Giao việc mới",
		Users:          []Option{},
		Branches:       []Option{},
		DefaultDueDate: dateOnly(s.now()).AddDate(0, 0, 2).Format(time.DateOnly),
		CanTAAssign:    a.Can("work_task.assign"),
	}
	if !a.Can("work_task.create") {
		out.Title = "Đề xuất việc cho Admin / Học vụ"
	}
	users, err := s.assignable(ctx, a)
	if err != nil {
		return out, err
	}
	for _, u := range users {
		out.Users = append(out.Users, Option{u.ID, userLabel(u.FullName, u.RoleLabels)})
	}
	branches, err := s.q.ListActiveBranches(ctx, nil)
	if err != nil {
		return out, err
	}
	for _, b := range branches {
		out.Branches = append(out.Branches, Option{b.ID, b.Name})
	}
	return out, nil
}

func (in *CreateInput) validate() (tasksdb.InsertTaskParams, uuid.UUID, error) {
	var p tasksdb.InsertTaskParams
	title := strings.TrimSpace(in.Title)
	if title == "" {
		return p, uuid.Nil, iam.Invalid("Vui lòng nhập tiêu đề công việc.")
	}
	if utf8.RuneCountInString(title) > 255 {
		return p, uuid.Nil, iam.Invalid("Tiêu đề công việc không được vượt quá 255 ký tự.")
	}
	assignee, err := uuid.Parse(strings.TrimSpace(in.AssigneeID))
	if err != nil {
		return p, uuid.Nil, iam.Invalid("Vui lòng chọn người nhận.")
	}
	if strings.TrimSpace(in.DueDate) == "" {
		return p, uuid.Nil, iam.Invalid("Vui lòng chọn hạn hoàn thành.")
	}
	due, ok := parseDate(strings.TrimSpace(in.DueDate))
	if !ok {
		return p, uuid.Nil, iam.Invalid("Hạn hoàn thành không phải là ngày hợp lệ.")
	}
	clock := strings.TrimSpace(in.DueTime)
	if clock == "" {
		clock = "18:00"
	}
	dueTime, ok := parseClock(clock)
	if !ok {
		return p, uuid.Nil, iam.Invalid("Giờ hạn không hợp lệ (dạng HH:MM).")
	}
	var taskType string
	switch strings.TrimSpace(in.TaskType) {
	case "one_time", "one-time":
		taskType = "one_time"
	case "recurring":
		taskType = "recurring"
	case "":
		return p, uuid.Nil, iam.Invalid("Vui lòng chọn loại công việc.")
	default:
		return p, uuid.Nil, iam.Invalid("Loại công việc không hợp lệ.")
	}
	freq := strings.TrimSpace(in.Frequency)
	if freq != "" && frequencyLabels[freq] == "" {
		return p, uuid.Nil, iam.Invalid("Tần suất không hợp lệ.")
	}
	var frequency *string
	if taskType == "recurring" {
		if freq == "" {
			freq = "weekly"
		}
		frequency = &freq
	}
	slot := strings.TrimSpace(in.TimeSlotCategory)
	if slot == "" {
		slot = "during"
	}
	if _, ok := slotLabels[slot]; !ok {
		return p, uuid.Nil, iam.Invalid("Ca trực không hợp lệ.")
	}
	p = tasksdb.InsertTaskParams{
		Title: title, Description: strOrNil(in.Description), AssigneeID: &assignee,
		TimeSlotCategory: slot, TaskType: taskType, Frequency: frequency, DueDate: &due, DueTime: dueTime,
	}
	if b := strings.TrimSpace(in.BranchID); b != "" {
		id, err := uuid.Parse(b)
		if err != nil {
			return p, uuid.Nil, iam.Invalid("Chi nhánh đã chọn không tồn tại.")
		}
		p.BranchID = &id
	}
	return p, assignee, nil
}

// Create: giao việc 2 chiều. Người chỉ có quyền đề xuất (GV / TA / Sales) chỉ giao cho Admin và người duyệt công việc.
func (s *Service) Create(ctx context.Context, a *iam.Access, in CreateInput) (Task, error) {
	if err := ensureCanCreate(a); err != nil {
		return Task{}, err
	}
	p, assignee, err := in.validate()
	if err != nil {
		return Task{}, err
	}
	if p.BranchID != nil {
		if _, err := s.q.GetActiveBranch(ctx, *p.BranchID); errors.Is(err, pgx.ErrNoRows) {
			return Task{}, iam.Invalid("Chi nhánh đã chọn không tồn tại.")
		} else if err != nil {
			return Task{}, err
		}
	}
	users, err := s.assignable(ctx, a)
	if err != nil {
		return Task{}, err
	}
	allowed := false
	for _, u := range users {
		allowed = allowed || u.ID == assignee
	}
	if !allowed {
		return Task{}, iam.Invalid("Bạn chỉ được giao việc cho Admin, Quản lý cơ sở, Học vụ hoặc Học thuật.")
	}
	p.CreatorID = a.UserID
	t, err := s.q.InsertTask(ctx, p)
	if err != nil {
		return Task{}, err
	}
	s.log.Info("work task created", "task_id", t.ID, "assignee", assignee, "by", a.UserID)
	// TODO(notify): báo người nhận (trừ khi tự giao cho mình) — "Bạn được giao việc: {title}",
	// "{người giao} đã giao việc, hạn dd/mm/yyyy.", link danh sách tab "Của tôi".
	return s.Get(ctx, a, t.ID)
}

// ───── Đổi trạng thái ─────

type StatusInput struct {
	Status string `json:"status"`
	Reason string `json:"reason"`
	Note   string `json:"note"`
}

// isParticipant: người làm, người giao, hoặc người có quyền duyệt công việc thấy được việc này.
func (s *Service) isParticipant(ctx context.Context, q *tasksdb.Queries, a *iam.Access, t tasksdb.OpsWorkTask) (bool, error) {
	if t.CreatorID == a.UserID || (t.AssigneeID != nil && *t.AssigneeID == a.UserID) {
		return true, nil
	}
	if !a.Can("work_task.approve") {
		return false, nil
	}
	scope, branches := scopeOf(a)
	_, err := q.GetVisibleTask(ctx, tasksdb.GetVisibleTaskParams{ID: t.ID, Me: a.UserID, Scope: scope, BranchIds: branches})
	if errors.Is(err, pgx.ErrNoRows) {
		return false, nil
	}
	return err == nil, err
}

func stateParams(t tasksdb.OpsWorkTask) tasksdb.SaveTaskStateParams {
	return tasksdb.SaveTaskStateParams{
		ID: t.ID, Status: t.Status, AssigneeID: t.AssigneeID,
		HandedBackFromID: t.HandedBackFromID, HandedBackAt: t.HandedBackAt,
		BlockedReason: t.BlockedReason, RejectionReason: t.RejectionReason,
		CompletionNote: t.CompletionNote, CompletionProofUrl: t.CompletionProofUrl,
		ConfirmedBy: t.ConfirmedBy, ConfirmedAt: t.ConfirmedAt, CompletedAt: t.CompletedAt,
	}
}

// ChangeStatus: đổi trạng thái theo luật allowedTransitions, hoặc "Chuyển lại cho người giao" (hand_back).
func (s *Service) ChangeStatus(ctx context.Context, a *iam.Access, id uuid.UUID, in StatusInput) (Task, string, error) {
	target := strings.TrimSpace(in.Status)
	if !validTarget(target) {
		return Task{}, "", iam.Invalid("Trạng thái mới không hợp lệ.")
	}
	reason, note := strings.TrimSpace(in.Reason), strings.TrimSpace(in.Note)
	if utf8.RuneCountInString(reason) > 1000 {
		return Task{}, "", iam.Invalid("Lý do không được vượt quá 1000 ký tự.")
	}
	if utf8.RuneCountInString(note) > 2000 {
		return Task{}, "", iam.Invalid("Ghi chú không được vượt quá 2000 ký tự.")
	}
	msg := "Đã cập nhật trạng thái công việc thành công!"
	err := s.inTx(ctx, func(q *tasksdb.Queries) error {
		t, err := q.LockTask(ctx, id)
		if errors.Is(err, pgx.ErrNoRows) {
			return ErrNotFound
		}
		if err != nil {
			return err
		}
		ok, err := s.isParticipant(ctx, q, a, t)
		if err != nil {
			return err
		}
		if !ok {
			return ErrNotParticipant
		}
		allowed := allowedTransitions(t.Status, t.CreatorID, t.AssigneeID, a.UserID, isAdmin(a))
		if !contains(allowed, target) {
			if target == StatusCompleted && t.AssigneeID != nil && *t.AssigneeID == a.UserID {
				return iam.Invalid(`Người thực hiện không tự xác nhận hoàn thành: hãy gửi "Chờ xác nhận" để người giao việc duyệt.`)
			}
			return iam.Invalid(`Không thể chuyển công việc từ "` + statusLabel(t.Status) + `" sang trạng thái này.`)
		}
		now := s.now()
		p := stateParams(t)

		if target == HandBack {
			text := note
			if text == "" {
				text = reason
			}
			if err := s.handBack(ctx, q, a, &p, t, text, now); err != nil {
				return err
			}
			msg = "Đã chuyển công việc lại cho người giao."
			_, err := q.SaveTaskState(ctx, p)
			return err
		}

		// Bị chặn / Hủy bắt buộc ghi lý do.
		if (target == StatusBlocked || target == StatusCanceled) && reason == "" && note == "" {
			if target == StatusBlocked {
				return iam.Invalid("Vui lòng nhập lý do khiến công việc bị chặn.")
			}
			return iam.Invalid("Vui lòng nhập lý do hủy công việc.")
		}
		if note == "" {
			note = reason
		}
		if reason == "" {
			reason = note
		}
		p.Status = target
		switch target {
		case StatusBlocked:
			p.BlockedReason = &reason
		case StatusCompleted:
			p.CompletedAt, p.ConfirmedBy, p.ConfirmedAt = &now, &a.UserID, &now
			if note != "" {
				p.CompletionNote = &note
			}
		case StatusPendingConfirmation:
			if note != "" {
				p.CompletionNote = &note
			}
		case StatusCanceled:
			p.RejectionReason = &reason
		}
		saved, err := q.SaveTaskState(ctx, p)
		if err != nil {
			return err
		}
		if saved.Status == StatusCompleted {
			if err := s.spawnNext(ctx, q, saved); err != nil {
				return err
			}
		}
		s.log.Info("work task status changed", "task_id", t.ID, "from", t.Status, "to", target, "by", a.UserID)
		// TODO(notify): theo WorkTaskController::notifyStatusChange (không báo người vừa thao tác):
		//   blocked → người giao + mọi Admin, loại task_blocked: "SOS – Việc bị chặn: {title}", "{người} báo bị chặn, cần hỗ trợ. Lý do: {lý do}";
		//   blocked → in_progress → người giao: "Việc đã gỡ chặn: {title}";
		//   pending_confirmation → in_progress → người làm: "Công việc bị trả về: {title}", "{người} trả về làm tiếp: {ghi chú}";
		//   pending_confirmation → mọi Admin trừ người làm: "Việc chờ xác nhận: {title}";
		//   completed → người làm: "Việc đã được xác nhận hoàn thành: {title}";
		//   canceled → người làm + người giao: "Công việc đã bị hủy: {title}", "... Lý do: {lý do}".
		return nil
	})
	if err != nil {
		return Task{}, "", err
	}
	t, err := s.Get(ctx, a, id)
	if errors.Is(err, ErrNotFound) {
		// Sau khi chuyển lại cho người giao, người làm cũ vẫn xem được (handed_back_from); trường hợp khác coi như đã lưu.
		return Task{ID: id}, msg, nil
	}
	return t, msg, err
}

// handBack: việc bị chặn về tay người giao — người giao thành người làm, trạng thái Mới, giữ lý do bị chặn (ghi chú khi
// chuyển nối thêm vào lý do), ghi người làm cũ.
func (s *Service) handBack(ctx context.Context, q *tasksdb.Queries, a *iam.Access, p *tasksdb.SaveTaskStateParams, t tasksdb.OpsWorkTask, note string, now time.Time) error {
	reason := ""
	if t.BlockedReason != nil {
		reason = *t.BlockedReason
	}
	if note != "" {
		actor, err := q.GetUserBrief(ctx, a.UserID)
		if err != nil {
			return err
		}
		reason += "\n[Ghi chú khi chuyển lại – " + actor.FullName + "]: " + note
	}
	creator := t.CreatorID
	p.AssigneeID = &creator
	p.HandedBackFromID = t.AssigneeID
	p.HandedBackAt = &now
	p.Status = StatusNew
	p.BlockedReason = strOrNil(reason)
	s.log.Info("work task handed back", "task_id", t.ID, "from", t.AssigneeID, "by", a.UserID)
	// TODO(notify): người giao: "Việc bị chặn chuyển lại cho bạn: {title}", "{người} đã chuyển công việc từ {người làm} về
	// cho bạn xử lý. Lý do bị chặn: ..."; người làm cũ: "Công việc đã chuyển về người giao: {title}", "... bạn không cần làm tiếp."
	return nil
}

// spawnNext: việc "Lặp đi lặp lại" vừa hoàn thành → tạo lượt kế tiếp theo tần suất (không tạo trùng).
func (s *Service) spawnNext(ctx context.Context, q *tasksdb.Queries, t tasksdb.OpsWorkTask) error {
	if t.TaskType != "recurring" || t.DueDate == nil {
		return nil
	}
	next := nextOccurrence(*t.DueDate, t.Frequency)
	exists, err := q.RecurringOccurrenceExists(ctx, tasksdb.RecurringOccurrenceExistsParams{Title: t.Title, AssigneeID: t.AssigneeID, DueDate: next})
	if err != nil || exists {
		return err
	}
	freq := "weekly"
	if t.Frequency != nil && *t.Frequency != "" {
		freq = *t.Frequency
	}
	n, err := q.InsertTask(ctx, tasksdb.InsertTaskParams{
		Title: t.Title, Description: t.Description, CreatorID: t.CreatorID, AssigneeID: t.AssigneeID,
		BranchID: t.BranchID, ClassID: t.ClassID, TimeSlotCategory: t.TimeSlotCategory,
		TaskType: "recurring", Frequency: &freq, DueDate: &next, DueTime: t.DueTime,
	})
	if err == nil {
		s.log.Info("recurring task spawned", "task_id", n.ID, "from", t.ID)
	}
	return err
}

func contains(list []string, v string) bool {
	for _, x := range list {
		if x == v {
			return true
		}
	}
	return false
}

// ───── Xác nhận hoàn thành (chỉ Admin) ─────

// ApprovalItem: một việc chờ xác nhận (màn "Xác nhận hoàn thành thủ công"), đủ dữ liệu cho hộp chi tiết.
type ApprovalItem struct {
	ID             uuid.UUID  `json:"id"`
	No             int64      `json:"no"`
	Kind           string     `json:"kind"` // task (báo cáo trực lớp: chờ module lớp)
	Title          string     `json:"title"`
	ClassID        *uuid.UUID `json:"class_id"`
	LessonSession  *string    `json:"lesson_session"`
	Person         *string    `json:"person"`
	Creator        string     `json:"creator"`
	AssignedDate   string     `json:"assigned_date"`
	Due            *string    `json:"due"`
	Note           *string    `json:"note"`
	Links          []string   `json:"links"`
	UpdatedAt      time.Time  `json:"updated_at"`
}

type ApprovalsResult struct {
	Items           []ApprovalItem `json:"items"`
	AssigneeOptions []Option       `json:"assignee_options"`
	CanApprove      bool           `json:"can_approve"`
}

var linkRe = regexp.MustCompile(`https?://[^\s<>"']+`)

func extractLinks(s *string) []string {
	out := []string{}
	if s == nil {
		return out
	}
	seen := map[string]bool{}
	for _, l := range linkRe.FindAllString(*s, -1) {
		l = strings.TrimRight(l, ".,;:!?)")
		if !seen[l] {
			seen[l] = true
			out = append(out, l)
		}
	}
	return out
}

// Approvals: việc "Chờ xác nhận" chờ Admin xác nhận (Admin không tự duyệt việc của mình); người khác thấy danh sách rỗng.
func (s *Service) Approvals(ctx context.Context, a *iam.Access, search, assignee string) (ApprovalsResult, error) {
	out := ApprovalsResult{Items: []ApprovalItem{}, AssigneeOptions: []Option{}, CanApprove: isAdmin(a)}
	if !isAdmin(a) {
		return out, nil
	}
	p := tasksdb.ListAwaitingConfirmationParams{Me: a.UserID, Search: likePattern(search)}
	if assignee != "" {
		id, err := uuid.Parse(assignee)
		if err != nil {
			return out, iam.Invalid("Người thực hiện đã chọn không hợp lệ.")
		}
		p.AssigneeID = &id
	}
	rows, err := s.q.ListAwaitingConfirmation(ctx, p)
	if err != nil {
		return out, err
	}
	seen := map[uuid.UUID]bool{}
	for _, r := range rows {
		t := r.OpsWorkTask
		item := ApprovalItem{
			ID: t.ID, No: t.No, Kind: "task", Title: t.Title, ClassID: t.ClassID, LessonSession: t.LessonSession,
			Person: r.AssigneeName, Creator: r.CreatorName,
			AssignedDate: t.CreatedAt.In(vnZone).Format("02/01/2006"),
			Note:         t.CompletionNote, Links: extractLinks(t.CompletionNote), UpdatedAt: t.UpdatedAt,
		}
		if t.DueDate != nil {
			due := t.DueDate.Format("02/01/2006") + " "
			if c := formatClock(t.DueTime); c != nil {
				due += *c
			} else {
				due += "23:59"
			}
			item.Due = &due
		}
		out.Items = append(out.Items, item)
		if t.AssigneeID != nil && r.AssigneeName != nil && !seen[*t.AssigneeID] {
			seen[*t.AssigneeID] = true
			out.AssigneeOptions = append(out.AssigneeOptions, Option{*t.AssigneeID, *r.AssigneeName})
		}
	}
	return out, nil
}

// lockForApproval: Admin, không tự duyệt việc của mình, việc đang chờ xác nhận.
func lockForApproval(ctx context.Context, q *tasksdb.Queries, a *iam.Access, id uuid.UUID) (tasksdb.OpsWorkTask, error) {
	t, err := q.LockTask(ctx, id)
	if errors.Is(err, pgx.ErrNoRows) {
		return t, ErrNotFound
	}
	if err != nil {
		return t, err
	}
	if t.AssigneeID != nil && *t.AssigneeID == a.UserID {
		return t, ErrSelfApproval
	}
	if t.Status != StatusPendingConfirmation {
		return t, ErrNotPending
	}
	return t, nil
}

// Approve: Admin xác nhận hoàn thành (ghi chú duyệt nối vào ghi chú / kết quả).
// TODO(module lớp): việc "Trực lớp" có báo cáo trực lớp chờ xác nhận thì xác nhận báo cáo (confirmClassReport).
func (s *Service) Approve(ctx context.Context, a *iam.Access, id uuid.UUID, note string) (string, error) {
	if !isAdmin(a) {
		return "", ErrAdminOnly
	}
	note = strings.TrimSpace(note)
	if utf8.RuneCountInString(note) > 1000 {
		return "", iam.Invalid("Ghi chú xác nhận không được vượt quá 1000 ký tự.")
	}
	var title string
	err := s.inTx(ctx, func(q *tasksdb.Queries) error {
		t, err := lockForApproval(ctx, q, a, id)
		if err != nil {
			return err
		}
		now := s.now()
		p := stateParams(t)
		p.Status, p.ConfirmedBy, p.ConfirmedAt, p.CompletedAt, p.RejectionReason = StatusCompleted, &a.UserID, &now, &now, nil
		if note != "" {
			v := "[Ghi chú duyệt]: " + note
			if t.CompletionNote != nil && *t.CompletionNote != "" {
				v = *t.CompletionNote + "\n" + v
			}
			p.CompletionNote = &v
		}
		saved, err := q.SaveTaskState(ctx, p)
		if err != nil {
			return err
		}
		title = t.Title
		s.log.Info("work task approved", "task_id", id, "by", a.UserID)
		// TODO(notify): người làm: "Việc đã được xác nhận hoàn thành: {title}", "{Admin} đã xác nhận hoàn thành. Ghi chú: ...".
		return s.spawnNext(ctx, q, saved)
	})
	if err != nil {
		return "", err
	}
	return "Đã xác nhận hoàn thành công việc '" + title + "'!", nil
}

// Reject: Admin trả về làm tiếp kèm lý do.
func (s *Service) Reject(ctx context.Context, a *iam.Access, id uuid.UUID, note string) (string, error) {
	if !isAdmin(a) {
		return "", ErrAdminOnly
	}
	note = strings.TrimSpace(note)
	if note == "" {
		return "", iam.Invalid("Vui lòng nhập lý do từ chối / yêu cầu bổ sung.")
	}
	if utf8.RuneCountInString(note) > 1000 {
		return "", iam.Invalid("Lý do từ chối không được vượt quá 1000 ký tự.")
	}
	var title string
	err := s.inTx(ctx, func(q *tasksdb.Queries) error {
		t, err := lockForApproval(ctx, q, a, id)
		if err != nil {
			return err
		}
		p := stateParams(t)
		p.Status, p.RejectionReason = StatusInProgress, &note
		if _, err := q.SaveTaskState(ctx, p); err != nil {
			return err
		}
		title = t.Title
		s.log.Info("work task rejected", "task_id", id, "by", a.UserID)
		// TODO(notify): người làm: "Công việc bị trả về: {title}", nội dung = lý do, link danh sách tab "Của tôi".
		return nil
	})
	if err != nil {
		return "", err
	}
	return "Đã từ chối/yêu cầu bổ sung cho công việc '" + title + "'!", nil
}

// ───── Quá hạn ─────

// MarkOverdue: việc Mới / Đang thực hiện đã qua hạn → Quá hạn (lệnh tasks:mark-overdue của Laravel). Nhiệm vụ hằng ngày
// của trợ giảng không có hạn nên không bị đổi. Trả số việc vừa chuyển.
func (s *Service) MarkOverdue(ctx context.Context) (int, error) {
	n := s.now().In(vnZone)
	local := time.Date(n.Year(), n.Month(), n.Day(), n.Hour(), n.Minute(), n.Second(), 0, time.UTC)
	rows, err := s.q.MarkOverdue(ctx, local)
	if err != nil {
		return 0, err
	}
	for _, r := range rows {
		s.log.Info("work task overdue", "task_id", r.ID)
		// TODO(notify): người làm: "Công việc quá hạn: {title}", "Hạn HH:MM dd/mm/yyyy — hãy hoàn thành hoặc báo lý do.",
		// link danh sách tab "Của tôi" lọc Quá hạn.
	}
	// TODO(module CRM): việc chăm sóc tháng đầu quá SLA → biên bản vi phạm (FirstMonthCareService::enforceSla).
	return len(rows), nil
}

// RunOverdueLoop chạy MarkOverdue ngay và sau mỗi khoảng interval tới khi ctx kết thúc (gọi trong tiến trình api).
func (s *Service) RunOverdueLoop(ctx context.Context, interval time.Duration) {
	tick := time.NewTicker(interval)
	defer tick.Stop()
	for {
		if n, err := s.MarkOverdue(ctx); err != nil && ctx.Err() == nil {
			s.log.Error("mark overdue tasks", "err", err)
		} else if n > 0 {
			s.log.Info("marked overdue tasks", "count", n)
		}
		select {
		case <-ctx.Done():
			return
		case <-tick.C:
		}
	}
}

// pgTimeOf: giờ "HH:MM" thành pgtype.Time (dùng cho giờ mặc định của ca).
func pgTimeOf(s string) pgtype.Time {
	t, _ := parseClock(s)
	return t
}
