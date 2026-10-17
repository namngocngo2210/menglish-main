package crm

import (
	"context"
	"encoding/json"
	"errors"
	"slices"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/crm/crmdb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

func jsonBytes(v any) ([]byte, error) { return json.Marshal(v) }

// LeadDetail: hồ sơ khách (Laravel showCustomer / showProps, phần thuộc CRM).
type LeadDetail struct {
	ID                 uuid.UUID       `json:"id"`
	Code               string          `json:"code"`
	ShortCode          string          `json:"short_code"`
	Name               string          `json:"name"`
	Phone              string          `json:"phone"`
	ParentName         *string         `json:"parent_name"`
	ParentPhone        *string         `json:"parent_phone"`
	Email              *string         `json:"email"`
	Dob                *string         `json:"dob"` // YYYY-MM-DD
	Gender             *string         `json:"gender"`
	Address            *string         `json:"address"`
	School             *string         `json:"school"`
	CourseInterest     *string         `json:"course_interest"`
	Source             *string         `json:"source"`
	DealValue          int64           `json:"deal_value"`
	Notes              *string         `json:"notes"`
	Stage              string          `json:"stage"`
	StageLabel         string          `json:"stage_label"`
	BranchID           *uuid.UUID      `json:"branch_id"`
	Branch             *string         `json:"branch"`
	AssignedUserID     *uuid.UUID      `json:"assigned_user_id"`
	AssignedUser       *string         `json:"assigned_user"`
	ConvertedStudentID *uuid.UUID      `json:"converted_student_id"`
	ConvertedAt        *time.Time      `json:"converted_at"`
	LostAt             *time.Time      `json:"lost_at"`
	LostReason         *string         `json:"lost_reason"`
	WaitingSince       *string         `json:"waiting_since"`
	WaitingBranch      *string         `json:"waiting_branch"`
	FeePaidAtClosing   bool            `json:"fee_paid_at_closing"`
	NextFollowUpAt     *time.Time      `json:"next_follow_up_at"`
	AppointmentAt      *time.Time      `json:"appointment_at"`
	AppointmentType    *string         `json:"appointment_type"`
	TestScore          *string         `json:"test_score"`
	TestDecision       *string         `json:"test_decision"`
	Examiner           *string         `json:"examiner"`
	CreatedAt          time.Time       `json:"created_at"`
	UpdatedAt          time.Time       `json:"updated_at"`
	ContractLocked     bool            `json:"contract_locked"`
	StatusCard         StatusCard      `json:"status_card"`
	StageControls      StageControls   `json:"stage_controls"`
	Actions            LeadActions     `json:"actions"`
	PendingTransfer    *PendingInfo    `json:"pending_transfer"`
	CareChecklist      []CareItem      `json:"care_checklist"`
	Events             []Event         `json:"events"`
	EventCounts        map[string]int  `json:"event_counts"`
	ReassignOwners     []OwnerOption   `json:"reassign_owners"`
	ReassignBranches   []Option        `json:"reassign_branches"`
	EditForm           *EditFormConfig `json:"edit_form"`
}

// StatusCard: thẻ "Trạng thái & hạn xử lý".
type StatusCard struct {
	StageSince  time.Time  `json:"stage_since"`
	DaysInStage int        `json:"days_in_stage"`
	LastContact *time.Time `json:"last_contact"`
	SLA         *SLA       `json:"sla"`
	Neglected   bool       `json:"neglected"`
	NeglectDays int        `json:"neglect_days"`
}

// StageControls: các nút chuyển giai đoạn người xem được dùng trên hồ sơ.
type StageControls struct {
	Next             *string  `json:"next"`
	NextLabel        *string  `json:"next_label"`
	NextBlocked      *string  `json:"next_blocked"`
	Backward         []Option `json:"backward"`
	CanLose          bool     `json:"can_lose"`
	CanReopen        bool     `json:"can_reopen"`
	CanCancelClosing bool     `json:"can_cancel_closing"`
}

// LeadActions: nút chính của hồ sơ.
type LeadActions struct {
	CanClose    bool    `json:"can_close"`
	CanPlace    bool    `json:"can_place"`
	CanUpdate   bool    `json:"can_update"`
	CanReassign bool    `json:"can_reassign"`
	Primary     *string `json:"primary"` // next | close | place
}

type PendingInfo struct {
	ID         uuid.UUID `json:"id"`
	Requester  *string   `json:"requester"`
	ToUser     *string   `json:"to_user"`
	FromBranch *string   `json:"from_branch"`
	ToBranch   *string   `json:"to_branch"`
	CreatedAt  time.Time `json:"created_at"`
}

type CareItem struct {
	Key    string  `json:"key"`
	Label  string  `json:"label"`
	Done   bool    `json:"done"`
	DoneAt *string `json:"done_at"`
	By     *string `json:"by"`
}

// Event: một dòng lịch sử hoạt động.
type Event struct {
	ID        uuid.UUID         `json:"id"`
	Type      string            `json:"type"`
	TypeLabel string            `json:"type_label"`
	Failed    bool              `json:"failed"`
	IsLost    bool              `json:"is_lost"`
	User      *string           `json:"user"`
	Content   string            `json:"content"`
	Reason    *string           `json:"reason"`
	FromStage *string           `json:"from_stage"`
	ToStage   *string           `json:"to_stage"`
	Changes   map[string]Change `json:"changes,omitempty"`
	CreatedAt time.Time         `json:"created_at"`
}

// EditFormConfig: lựa chọn cho form sửa trong hồ sơ.
type EditFormConfig struct {
	Branches     []Option      `json:"branches"`
	Owners       []OwnerOption `json:"owners"`
	Sources      []Option      `json:"sources"`
	LockedFields string        `json:"locked_fields"`
}

type careState map[string]*struct {
	DoneAt string `json:"done_at"`
	By     string `json:"by"`
}

func (s *Service) Detail(ctx context.Context, a *iam.Access, idOrCode string) (*LeadDetail, error) {
	l, err := s.lead(ctx, s.q, a, idOrCode)
	if err != nil {
		return nil, err
	}
	now := s.now()
	d := &LeadDetail{
		ID: l.ID, Code: l.Code, ShortCode: ShortCode(l.Code), Name: l.Name, Phone: l.Phone, ParentName: l.ParentName,
		ParentPhone: l.ParentPhone, Email: l.Email, Gender: l.Gender, Address: l.Address, School: l.School,
		CourseInterest: l.CourseInterest, Source: l.Source, DealValue: l.DealValue, Notes: l.Notes, Stage: l.Stage,
		StageLabel: StageLabel(l.Stage), BranchID: l.BranchID, Branch: l.BranchName, AssignedUserID: l.AssignedUserID,
		AssignedUser: l.AssignedUserName, ConvertedStudentID: l.ConvertedStudentID, ConvertedAt: l.ConvertedAt,
		LostAt: l.LostAt, LostReason: l.LostReason, WaitingBranch: l.WaitingBranchName, FeePaidAtClosing: l.FeePaidAtClosing,
		NextFollowUpAt: l.NextFollowUpAt, AppointmentAt: l.AppointmentAt, AppointmentType: l.AppointmentType,
		TestScore: l.TestScore, TestDecision: l.TestDecision, Examiner: l.ExaminerName, CreatedAt: l.CreatedAt,
		UpdatedAt: l.UpdatedAt, ContractLocked: isClosed(l.Stage) || l.ConvertedStudentID != nil,
		EventCounts: map[string]int{}, ReassignOwners: []OwnerOption{}, ReassignBranches: []Option{},
	}
	if l.Dob != nil {
		d.Dob = ptr(l.Dob.Format("2006-01-02"))
	}
	if l.WaitingSince != nil {
		d.WaitingSince = ptr(fmtDate(*l.WaitingSince))
	}
	evs, err := s.q.ListEvents(ctx, l.ID)
	if err != nil {
		return nil, err
	}
	d.Events = make([]Event, 0, len(evs))
	stageSince := l.CreatedAt
	foundStage := false
	var lastContact *time.Time
	for _, e := range evs {
		ev := Event{
			ID: e.ID, Type: e.Type, TypeLabel: eventTypeLabel(e.Type), Failed: deref(e.Outcome) == "failed",
			IsLost: e.Type == "stage_change" && deref(e.ToStage) == StageLost, User: e.UserName, Content: e.Content,
			Reason: e.Reason, FromStage: e.FromStage, ToStage: e.ToStage, CreatedAt: e.CreatedAt,
		}
		if len(e.Changes) > 0 {
			_ = json.Unmarshal(e.Changes, &ev.Changes)
		}
		d.Events = append(d.Events, ev)
		d.EventCounts[e.Type]++
		if !foundStage && e.Type == "stage_change" {
			stageSince, foundStage = e.CreatedAt, true
		}
		if lastContact == nil && slices.Contains(contactTypes, e.Type) && !ev.Failed {
			lastContact = &e.CreatedAt
		}
	}
	sla := contactSLA(l.Stage, l.CreatedAt, l.LastCareAt, l.NextFollowUpAt, now)
	lastActivity := l.CreatedAt
	if l.LastCareAt != nil {
		lastActivity = *l.LastCareAt
	}
	appt := activeAppointment(l.LastCareAt, l.NextFollowUpAt)
	d.StatusCard = StatusCard{
		StageSince: stageSince, DaysInStage: int(now.Sub(stageSince).Hours() / 24), LastContact: lastContact, SLA: sla,
		Neglected:   isActive(l.Stage) && lastActivity.Before(now.AddDate(0, 0, -neglectDays)) && (appt == nil || !appt.After(now)),
		NeglectDays: neglectDays,
	}
	d.StageControls = stageControls(a, l.Stage, l.AppointmentAt, l.TestScore)
	canClose := a.Can("lead.convert") && slices.Contains(ClosableStages, l.Stage) && l.ConvertedStudentID == nil
	canPlace := l.Stage == StageWaitingClass && a.Can("student.assign_class")
	d.Actions = LeadActions{CanClose: canClose, CanPlace: canPlace, CanUpdate: a.Can("lead.update"), CanReassign: a.Can("lead.assign")}
	switch {
	case d.StageControls.Next != nil:
		d.Actions.Primary = ptr("next")
	case canClose:
		d.Actions.Primary = ptr("close")
	case canPlace:
		d.Actions.Primary = ptr("place")
	}
	pt, err := s.q.PendingTransferOfLead(ctx, l.ID)
	switch {
	case err == nil:
		d.PendingTransfer = &PendingInfo{ID: pt.ID, Requester: pt.RequesterName, ToUser: pt.ToUserName,
			FromBranch: pt.FromBranchName, ToBranch: pt.ToBranchName, CreatedAt: pt.CreatedAt}
	case !errors.Is(err, pgx.ErrNoRows):
		return nil, err
	}
	var care careState
	if len(l.CareChecklist) > 0 {
		_ = json.Unmarshal(l.CareChecklist, &care)
	}
	for _, it := range careChecklistItems {
		ci := CareItem{Key: it.Key, Label: it.Label}
		if st := care[it.Key]; st != nil {
			ci.Done = true
			if t, err := time.Parse(time.RFC3339, st.DoneAt); err == nil {
				ci.DoneAt = ptr(fmtDate(t))
			}
			if st.By != "" {
				ci.By = ptr(st.By)
			}
		}
		d.CareChecklist = append(d.CareChecklist, ci)
	}
	if a.Can("lead.assign") {
		// Phân công lại: người phụ trách mọi cơ sở; cơ sở đang hoạt động (và cơ sở hiện tại của khách).
		all, err := s.candidates(ctx, s.q, nil)
		if err != nil {
			return nil, err
		}
		d.ReassignOwners = ownerOptions(all)
		branches, err := s.q.ListBranchesBrief(ctx, crmdb.ListBranchesBriefParams{})
		if err != nil {
			return nil, err
		}
		for _, b := range branches {
			if b.IsActive || (l.BranchID != nil && b.ID == *l.BranchID) {
				d.ReassignBranches = append(d.ReassignBranches, Option{b.ID.String(), b.Name})
			}
		}
	}
	if a.Can("lead.update") {
		if d.EditForm, err = s.editForm(ctx, a, l); err != nil {
			return nil, err
		}
	}
	return d, nil
}

func (s *Service) editForm(ctx context.Context, a *iam.Access, l crmdb.GetLeadRow) (*EditFormConfig, error) {
	f := &EditFormConfig{Branches: []Option{}, Sources: []Option{}}
	branches, err := s.leadBranchOptions(ctx, s.q, a)
	if err != nil {
		return nil, err
	}
	for _, b := range branches {
		f.Branches = append(f.Branches, Option{b.ID.String(), b.Name})
	}
	if l.BranchID != nil && !slices.ContainsFunc(branches, func(b crmdb.ListBranchesBriefRow) bool { return b.ID == *l.BranchID }) {
		f.Branches = append(f.Branches, Option{l.BranchID.String(), deref(l.BranchName)})
	}
	owners, err := s.candidates(ctx, s.q, nil)
	if err != nil {
		return nil, err
	}
	// Người phụ trách hiện tại luôn có trong danh sách (kể cả tài khoản khóa), tránh âm thầm đổi người phụ trách.
	if l.AssignedUserID != nil && !slices.ContainsFunc(owners, func(o *owner) bool { return o.ID == *l.AssignedUserID }) {
		cur, err := s.loadOwner(ctx, s.q, *l.AssignedUserID)
		if err != nil {
			return nil, err
		}
		if cur != nil {
			owners = append([]*owner{cur}, owners...)
		}
	}
	f.Owners = ownerOptions(owners)
	sources, err := s.leadSources(ctx)
	if err != nil {
		return nil, err
	}
	for _, src := range sources {
		f.Sources = append(f.Sources, Option{src, src})
	}
	if l.Source != nil && !slices.Contains(sources, *l.Source) {
		f.Sources = append(f.Sources, Option{*l.Source, *l.Source + " (hiện tại)"})
	}
	labels := make([]string, 0, len(contractLockedFields))
	for _, c := range contractLockedFields {
		labels = append(labels, c.Label)
	}
	f.LockedFields = joinComma(labels)
	return f, nil
}

func joinComma(parts []string) string {
	out := ""
	for i, p := range parts {
		if i > 0 {
			out += ", "
		}
		out += p
	}
	return out
}
