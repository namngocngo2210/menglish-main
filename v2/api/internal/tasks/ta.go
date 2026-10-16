package tasks

import (
	"context"
	"errors"
	"fmt"
	"net/url"
	"strconv"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/tasks/tasksdb"
)

// ───── Giao việc cho trợ giảng theo ca ─────

// managedBranches: chi nhánh giới hạn khi giao việc (phạm vi Công việc "Chi nhánh" → chi nhánh của mình; mức khác → nil).
func managedBranches(a *iam.Access) []uuid.UUID {
	if a.Level(module) != iam.ScopeBranch {
		return nil
	}
	if ids := a.BranchIDs(); ids != nil {
		return ids
	}
	return []uuid.UUID{}
}

type SlotOption struct {
	Value string `json:"value"`
	Label string `json:"label"`
	Due   string `json:"due"`
}

type TAAssignOptions struct {
	Assistants []Option     `json:"assistants"`
	Branches   []Option     `json:"branches"`
	Slots      []SlotOption `json:"slots"`
	// Lớp gắn được (đang học / sắp khai giảng) và buổi học thật của từng lớp (7 ngày trước → 30 ngày tới), giao diện lọc
	// theo ngày giao.
	Classes       []Option                   `json:"classes"`
	ClassSessions map[string][]SessionOption `json:"class_sessions"`
	Today         string                     `json:"today"`
	NowTime       string                     `json:"now_time"`
	Cutoff        string                     `json:"cutoff"`
}

func slotOptions() []SlotOption {
	out := make([]SlotOption, 0, len(slotOrder))
	for _, k := range slotOrder {
		out = append(out, SlotOption{k, slotLabels[k], slotDue[k]})
	}
	return out
}

// assistants: trợ giảng đang hoạt động trong phạm vi người giao (phạm vi Chi nhánh: chi nhánh chính thuộc chi nhánh mình).
func (s *Service) assistants(ctx context.Context, a *iam.Access) ([]tasksdb.ListAssistantsRow, error) {
	return s.q.ListAssistants(ctx, managedBranches(a))
}

func (s *Service) TAAssignOptions(ctx context.Context, a *iam.Access) (TAAssignOptions, error) {
	now := s.now()
	out := TAAssignOptions{
		Assistants: []Option{}, Branches: []Option{}, Slots: slotOptions(), Classes: []Option{}, ClassSessions: map[string][]SessionOption{},
		Today: dateOnly(now).Format(time.DateOnly), NowTime: now.In(vnZone).Format("15:04"), Cutoff: TAAssignCutoff,
	}
	list, err := s.assistants(ctx, a)
	if err != nil {
		return out, err
	}
	for _, u := range list {
		out.Assistants = append(out.Assistants, Option{u.ID, u.FullName})
	}
	branches, err := s.q.ListActiveBranches(ctx, managedBranches(a))
	if err != nil {
		return out, err
	}
	for _, b := range branches {
		out.Branches = append(out.Branches, Option{b.ID, b.Name})
	}
	if err := s.taClassOptions(ctx, a, &out); err != nil {
		return out, err
	}
	return out, nil
}

type TATaskInput struct {
	Category string `json:"category"`
	Content  string `json:"content"`
	// Gắn lớp: chọn lớp + buổi học trong ngày giao (hoặc gõ tên buổi khi lớp không có buổi hôm đó).
	AttachClass    bool   `json:"attach_class"`
	ClassID        string `json:"class_id"`
	ClassSessionID string `json:"class_session_id"`
	Session        string `json:"session"`
}

type TAAssignInput struct {
	AssistantID string        `json:"assistant_id"`
	AssignDate  string        `json:"assign_date"`
	BranchID    string        `json:"branch_id"`
	Tasks       []TATaskInput `json:"tasks"`
}

type TAAssignResult struct {
	Created int    `json:"created"`
	Late    bool   `json:"late"`
	Message string `json:"message"`
}

// TAAssign: tạo nhiều nhiệm vụ trực ca (Trước / Trong / Sau giờ học) cho một trợ giảng trong một ngày. Nhiệm vụ hằng ngày
// không có hạn thật (kind = ta_daily); giờ hạn là mốc mặc định của ca. Gửi trễ (ngày đã qua, hoặc hôm nay sau 15:30)
// vẫn lưu nhưng báo Admin và người giao. Gắn buổi học: giờ hạn theo giờ buổi, chi nhánh theo buổi khi không chọn.
func (s *Service) TAAssign(ctx context.Context, a *iam.Access, in TAAssignInput) (TAAssignResult, error) {
	assistant, err := uuid.Parse(strings.TrimSpace(in.AssistantID))
	if err != nil {
		return TAAssignResult{}, iam.Invalid("Vui lòng chọn trợ giảng.")
	}
	if strings.TrimSpace(in.AssignDate) == "" {
		return TAAssignResult{}, iam.Invalid("Vui lòng chọn ngày giao việc.")
	}
	day, ok := parseDate(strings.TrimSpace(in.AssignDate))
	if !ok {
		return TAAssignResult{}, iam.Invalid("Ngày giao việc không phải là ngày hợp lệ.")
	}
	var branch *uuid.UUID
	if b := strings.TrimSpace(in.BranchID); b != "" {
		id, err := uuid.Parse(b)
		if err != nil {
			return TAAssignResult{}, iam.Invalid("Chi nhánh đã chọn không tồn tại.")
		}
		branch = &id
	}
	if len(in.Tasks) == 0 {
		return TAAssignResult{}, iam.Invalid("Cần ít nhất một đầu việc.")
	}
	if len(in.Tasks) > 30 {
		return TAAssignResult{}, iam.Invalid("Tối đa 30 đầu việc mỗi lần giao.")
	}
	for i, t := range in.Tasks {
		if _, ok := slotLabels[t.Category]; !ok {
			return TAAssignResult{}, iam.Invalid(fmt.Sprintf("Đầu việc #%d: nhóm đầu mục không hợp lệ.", i+1))
		}
		c := strings.TrimSpace(t.Content)
		if c == "" {
			return TAAssignResult{}, iam.Invalid("Nội dung đầu việc không được để trống.")
		}
		if utf8.RuneCountInString(c) > 500 {
			return TAAssignResult{}, iam.Invalid(fmt.Sprintf("Đầu việc #%d: nội dung không được vượt quá 500 ký tự.", i+1))
		}
	}

	list, err := s.assistants(ctx, a)
	if err != nil {
		return TAAssignResult{}, err
	}
	var assistantName string
	for _, u := range list {
		if u.ID == assistant {
			assistantName = u.FullName
		}
	}
	if assistantName == "" {
		return TAAssignResult{}, iam.Invalid("Chỉ giao cho trợ giảng đang hoạt động trong phạm vi bạn quản lý.")
	}
	if branch != nil {
		if _, err := s.q.GetActiveBranch(ctx, *branch); errors.Is(err, pgx.ErrNoRows) {
			return TAAssignResult{}, iam.Invalid("Chi nhánh đã chọn không tồn tại.")
		} else if err != nil {
			return TAAssignResult{}, err
		}
		if m := managedBranches(a); m != nil && !containsID(m, *branch) {
			return TAAssignResult{}, iam.Invalid("Bạn chỉ giao việc trong chi nhánh mình quản lý.")
		}
	}

	links, err := s.taClassLinks(ctx, a, in.Tasks, day)
	if err != nil {
		return TAAssignResult{}, err
	}

	kind := KindTADaily
	err = s.inTx(ctx, func(q *tasksdb.Queries) error {
		for i, t := range in.Tasks {
			desc := "Nhiệm vụ trực ca " + slotLabels[t.Category]
			l := links[i]
			b := branch
			if b == nil && l.session != nil {
				b = &l.session.BranchID
			}
			if _, err := q.InsertTask(ctx, tasksdb.InsertTaskParams{
				Title: strings.TrimSpace(t.Content), Description: &desc, CreatorID: a.UserID, AssigneeID: &assistant,
				BranchID: b, ClassID: l.classID, LessonSession: l.lesson, TimeSlotCategory: t.Category, TaskType: "one_time", Kind: &kind,
				DueDate: &day, DueTime: pgTimeOf(slotDueFor(t.Category, l.session)),
			}); err != nil {
				return err
			}
		}
		return nil
	})
	if err != nil {
		return TAAssignResult{}, err
	}
	n := len(in.Tasks)
	now := s.now()
	late := isLateAssignment(day, now)
	s.log.Info("ta tasks assigned", "assistant", assistant, "date", day.Format(time.DateOnly), "count", n, "late", late, "by", a.UserID)
	// TODO(notify): trợ giảng: "Bạn được giao {n} nhiệm vụ mới", "{người giao} đã giao {n} nhiệm vụ trực ca ngày dd/mm/yyyy."
	// (1 việc: "Bạn được giao việc: {title}"). Gửi trễ: mọi Admin và người giao, mỗi người một thông báo
	// "Giao việc trợ giảng sau 15:30", "{người giao | Bạn} giao {n} nhiệm vụ ngày dd/mm/yyyy cho {trợ giảng} lúc HH:MM.",
	// link Portal trợ giảng (ta_id, date).
	msg := "Đã tạo thành công " + strconv.Itoa(n) + " nhiệm vụ cho Trợ giảng!"
	if late {
		msg += " (Gửi sau " + TAAssignCutoff + " — đã báo Admin và người giao.)"
	}
	return TAAssignResult{Created: n, Late: late, Message: msg}, nil
}

func containsID(list []uuid.UUID, id uuid.UUID) bool {
	for _, x := range list {
		if x == id {
			return true
		}
	}
	return false
}

// ───── Portal trợ giảng: nhiệm vụ trong ngày ─────

type TAUser struct {
	ID     uuid.UUID `json:"id"`
	Name   string    `json:"name"`
	Branch *string   `json:"branch"`
}

type TACard struct {
	ID              uuid.UUID  `json:"id"`
	Title           string     `json:"title"`
	Status          string     `json:"status"`
	StatusLabel     string     `json:"status_label"`
	ClassID         *uuid.UUID `json:"class_id"`
	ClassLabel      *string    `json:"class_label"`
	Room            *string    `json:"room"`
	SessionTime     *string    `json:"session_time"`
	Creator         string     `json:"creator"`
	HasProof        bool       `json:"has_proof"`
	BlockedReason   *string    `json:"blocked_reason"`
	LateHours       int        `json:"late_hours"`
	CompletedAt     *string    `json:"completed_at"`
	DueLabel        string     `json:"due_label"`
	RejectionReason *string    `json:"rejection_reason"`
	PendingLabel    *string    `json:"pending_label"`
}

type TAGroup struct {
	Key   string   `json:"key"`
	Title string   `json:"title"`
	Tasks []TACard `json:"tasks"`
}

type TAPortal struct {
	Title        string    `json:"title"`
	TAUser       *TAUser   `json:"ta_user"`
	Done         int       `json:"done"`
	Total        int       `json:"total"`
	Date         string    `json:"date"`
	IsToday      bool      `json:"is_today"`
	CanPickTA    bool      `json:"can_pick_ta"`
	Assistants   []Option  `json:"assistants"`
	OverdueCount int64     `json:"overdue_count"`
	CanComplete  bool      `json:"can_complete"`
	HasTasks     bool      `json:"has_tasks"`
	FirstOpen    string    `json:"first_open"`
	Groups       []TAGroup `json:"groups"`
	// Lớp trực trong ngày: buổi mình được gán + buổi của các lớp có việc giao trong ngày.
	Sessions []PortalSession `json:"sessions"`
}

// TAPortal: "Nhiệm vụ hôm nay" của trợ giảng. Người có quyền giao / duyệt việc chọn xem trợ giảng bất kỳ trong phạm vi;
// người khác chỉ xem nhiệm vụ của chính mình.
func (s *Service) TAPortal(ctx context.Context, a *iam.Access, date, taID string) (TAPortal, error) {
	now := s.now()
	today := dateOnly(now)
	day := today
	if date != "" {
		d, ok := parseDate(date)
		if !ok {
			return TAPortal{}, iam.Invalid("Ngày không hợp lệ.")
		}
		day = d
	}
	out := TAPortal{
		Date: day.Format(time.DateOnly), IsToday: day.Equal(today), Assistants: []Option{},
		Groups: []TAGroup{}, Sessions: []PortalSession{}, FirstOpen: "before",
		CanPickTA: a.CanAny("work_task.assign", "work_task.approve"),
	}
	out.Title = "Nhiệm vụ hôm nay"
	if !out.IsToday {
		out.Title = "Nhiệm vụ ngày " + day.Format("02/01/2006")
	}

	var ta *uuid.UUID
	if out.CanPickTA {
		list, err := s.assistants(ctx, a)
		if err != nil {
			return out, err
		}
		for _, u := range list {
			out.Assistants = append(out.Assistants, Option{u.ID, u.FullName})
		}
		switch {
		case taID != "":
			id, err := uuid.Parse(taID)
			if err != nil {
				return out, iam.Invalid("Trợ giảng đã chọn không hợp lệ.")
			}
			inScope := id == a.UserID
			for _, u := range list {
				inScope = inScope || u.ID == id
			}
			if !inScope {
				return out, &iam.Error{Status: 403, Code: "outside_scope", Message: "Trợ giảng này nằm ngoài phạm vi bạn quản lý."}
			}
			ta = &id
		case a.Can("portal.assistant"):
			ta = &a.UserID
		case len(list) > 0:
			ta = &list[0].ID
		}
	} else {
		ta = &a.UserID
	}
	if ta == nil {
		out.Groups = emptyGroups()
		return out, nil
	}

	u, err := s.q.GetUserBrief(ctx, *ta)
	if errors.Is(err, pgx.ErrNoRows) {
		return out, ErrNotFound
	}
	if err != nil {
		return out, err
	}
	out.TAUser = &TAUser{ID: u.ID, Name: u.FullName, Branch: u.BranchName}
	rows, err := s.q.ListAssigneeTasksOn(ctx, tasksdb.ListAssigneeTasksOnParams{AssigneeID: *ta, Day: day})
	if err != nil {
		return out, err
	}
	if out.OverdueCount, err = s.q.CountOverdueBefore(ctx, tasksdb.CountOverdueBeforeParams{AssigneeID: *ta, Day: day}); err != nil {
		return out, err
	}
	out.CanComplete = *ta == a.UserID || a.Can("work_task.approve")
	out.HasTasks = len(rows) > 0
	classIDs := []uuid.UUID{}
	for _, r := range rows {
		if id := r.OpsWorkTask.ClassID; id != nil && !containsID(classIDs, *id) {
			classIDs = append(classIDs, *id)
		}
	}
	cards, err := s.portalClasses(ctx, *ta, classIDs, day, &out)
	if err != nil {
		return out, err
	}

	groups := emptyGroups()
	for _, r := range rows {
		t := r.OpsWorkTask
		if t.Status == StatusCompleted {
			out.Done++
		}
		if t.Status != StatusCanceled {
			out.Total++
		}
		c := TACard{
			ID: t.ID, Title: t.Title, Status: t.Status, StatusLabel: statusLabel(t.Status), ClassID: t.ClassID,
			Creator: r.CreatorName, BlockedReason: t.BlockedReason,
			HasProof: t.Status == StatusCompleted && t.CompletionProofUrl != nil && *t.CompletionProofUrl != "",
		}
		if t.ClassID != nil {
			cards.fill(&c, *t.ClassID, t.LessonSession)
		}
		if t.Status == StatusOverdue {
			c.LateHours = max(1, lateHours(t.Kind, t.DueDate, t.DueTime, now))
		}
		if t.CompletedAt != nil {
			v := t.CompletedAt.In(vnZone).Format("15:04")
			c.CompletedAt = &v
		}
		dueClock := "—"
		if v := formatClock(t.DueTime); v != nil {
			dueClock = *v
		}
		if out.IsToday {
			c.DueLabel = dueClock + ", Hôm nay"
		} else {
			c.DueLabel = dueClock + ", " + day.Format("02/01/2006")
		}
		if t.Status == StatusInProgress || t.Status == StatusOverdue {
			c.RejectionReason = t.RejectionReason
		}
		if t.Status == StatusPendingConfirmation {
			// TODO(module lớp): "Đang chờ Admin xác nhận báo cáo trực lớp" khi việc có báo cáo trực lớp chờ xác nhận.
			v := "Đang chờ Admin xác nhận"
			c.PendingLabel = &v
		}
		// Nhiệm vụ không gắn ca (before / during) xếp vào "Sau giờ học" để không bị ẩn.
		i := 2
		switch t.TimeSlotCategory {
		case "before":
			i = 0
		case "during":
			i = 1
		}
		groups[i].Tasks = append(groups[i].Tasks, c)
	}
	out.Groups = groups
	for _, g := range groups {
		if len(g.Tasks) > 0 {
			out.FirstOpen = g.Key
			break
		}
	}
	return out, nil
}

func emptyGroups() []TAGroup {
	return []TAGroup{
		{Key: "before", Title: "Trước giờ học", Tasks: []TACard{}},
		{Key: "during", Title: "Trong giờ học", Tasks: []TACard{}},
		{Key: "after", Title: "Sau giờ học", Tasks: []TACard{}},
	}
}

// ───── Báo hoàn thành từ Portal trợ giảng ─────

type CompleteInput struct {
	Note          string `json:"note"`
	ProofImageURL string `json:"proof_image_url"`
}

// Complete: có ảnh minh chứng → Hoàn thành ngay; không ảnh → Chờ Admin xác nhận. Người làm, hoặc người có quyền duyệt
// công việc thấy được việc này.
// Ảnh minh chứng: hiện chỉ nhận đường dẫn ảnh (http/https); tải tệp ảnh lên chờ module tệp (core.files).
func (s *Service) Complete(ctx context.Context, a *iam.Access, id uuid.UUID, in CompleteInput) (string, error) {
	proof := strings.TrimSpace(in.ProofImageURL)
	if proof != "" {
		u, err := url.Parse(proof)
		if err != nil || (u.Scheme != "http" && u.Scheme != "https") || u.Host == "" {
			return "", iam.Invalid("Đường dẫn ảnh minh chứng phải là URL http/https hợp lệ.")
		}
		if len(proof) > 2048 {
			return "", iam.Invalid("Đường dẫn ảnh minh chứng không được vượt quá 2048 ký tự.")
		}
	}
	note := strings.TrimSpace(in.Note)
	if utf8.RuneCountInString(note) > 2000 {
		return "", iam.Invalid("Ghi chú không được vượt quá 2000 ký tự.")
	}
	var msg string
	err := s.inTx(ctx, func(q *tasksdb.Queries) error {
		t, err := q.LockTask(ctx, id)
		if errors.Is(err, pgx.ErrNoRows) {
			return ErrNotFound
		}
		if err != nil {
			return err
		}
		mine := t.AssigneeID != nil && *t.AssigneeID == a.UserID
		if !mine {
			ok := a.Can("work_task.approve")
			if ok {
				ok, err = s.isParticipant(ctx, q, a, t)
				if err != nil {
					return err
				}
			}
			if !ok {
				return iam.ErrForbidden
			}
		}
		if t.Status == StatusCompleted || t.Status == StatusCanceled || t.Status == StatusPendingConfirmation {
			return &iam.Error{Status: 422, Code: "closed", Message: "Nhiệm vụ đã hoàn thành, đã hủy hoặc đang chờ xác nhận."}
		}
		p := stateParams(t)
		p.CompletionNote = strOrNil(note)
		if proof != "" {
			now := s.now()
			p.Status, p.CompletionProofUrl, p.CompletedAt = StatusCompleted, &proof, &now
			saved, err := q.SaveTaskState(ctx, p)
			if err != nil {
				return err
			}
			msg = "Đã hoàn thành nhiệm vụ '" + t.Title + "' thành công!"
			s.log.Info("work task completed with proof", "task_id", id, "by", a.UserID)
			return s.spawnNext(ctx, q, saved)
		}
		p.Status = StatusPendingConfirmation
		if _, err := q.SaveTaskState(ctx, p); err != nil {
			return err
		}
		msg = "Đã gửi báo cáo tiến độ. Do không đính kèm ảnh, nhiệm vụ chuyển sang 'Chờ Admin xác nhận'!"
		s.log.Info("work task sent for confirmation", "task_id", id, "by", a.UserID)
		// TODO(notify): mọi Admin trừ người làm: "Việc chờ xác nhận: {title}", "{người làm} đã báo hoàn thành (không ảnh
		// minh chứng), cần Admin xác nhận.", link màn xác nhận (selected_id).
		return nil
	})
	return msg, err
}
