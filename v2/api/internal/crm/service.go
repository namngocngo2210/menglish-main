package crm

import (
	"context"
	"errors"
	"log/slog"
	"net/http"
	"slices"
	"strconv"
	"strings"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgconn"
	"github.com/jackc/pgx/v5/pgxpool"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/crm/crmdb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// AccessLoader tính tập quyền của một người dùng bất kỳ (iam.Service) — để kiểm người phụ trách.
type AccessLoader interface {
	LoadAccess(ctx context.Context, uid uuid.UUID) (*iam.Access, error)
}

type Service struct {
	pool   *pgxpool.Pool
	q      *crmdb.Queries
	access AccessLoader
	log    *slog.Logger
	now    func() time.Time
	// students: tạo hồ sơ học viên khi chốt khách (phân hệ học viên); nil = chưa gộp, chốt khách trả 501.
	students StudentCreator
}

func NewService(pool *pgxpool.Pool, access AccessLoader, log *slog.Logger) *Service {
	return &Service{pool: pool, q: crmdb.New(pool), access: access, log: log, now: time.Now}
}

// WithStudents gắn phân hệ học viên (luồng Chốt & Xếp lớp).
func (s *Service) WithStudents(c StudentCreator) *Service { s.students = c; return s }

var (
	errLeadNotFound = &iam.Error{Status: http.StatusNotFound, Code: "lead_not_found", Message: "Không tìm thấy khách hàng."}
	errNotFound     = &iam.Error{Status: http.StatusNotFound, Code: "not_found", Message: "Không tìm thấy dữ liệu."}
)

func forbidden(msg string) *iam.Error {
	return &iam.Error{Status: http.StatusForbidden, Code: "forbidden", Message: msg}
}

func isUniqueViolation(err error) bool {
	var pg *pgconn.PgError
	return errors.As(err, &pg) && pg.Code == "23505"
}

func (s *Service) tx(ctx context.Context, fn func(q *crmdb.Queries) error) error {
	return pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error { return fn(s.q.WithTx(tx)) })
}

// ───────────────────────── Phạm vi dữ liệu ─────────────────────────

// scope: phạm vi khách của người xem (Laravel CrmCustomer::scopeVisibleTo):
// toàn hệ thống → mọi khách; chi nhánh → khách thuộc chi nhánh của mình (chưa gán chi nhánh → không thấy gì);
// của tôi → khách được giao cho mình phụ trách.
type scope struct {
	owner    *uuid.UUID
	branches []uuid.UUID
}

func scopeOf(a *iam.Access) scope {
	switch a.Level("lead") {
	case iam.ScopeAll:
		return scope{}
	case iam.ScopeBranch:
		b := a.BranchIDs()
		if b == nil {
			b = []uuid.UUID{}
		}
		return scope{branches: b}
	default:
		id := a.UserID
		return scope{owner: &id}
	}
}

// lead: khách trong phạm vi theo id hoặc mã; ngoài phạm vi → 404 như Laravel.
func (s *Service) lead(ctx context.Context, q *crmdb.Queries, a *iam.Access, idOrCode string) (crmdb.GetLeadRow, error) {
	sc := scopeOf(a)
	p := crmdb.GetLeadParams{ScopeOwner: sc.owner, ScopeBranches: sc.branches}
	if id, err := uuid.Parse(idOrCode); err == nil {
		p.ID = &id
	} else {
		p.Code = &idOrCode
	}
	row, err := q.GetLead(ctx, p)
	if errors.Is(err, pgx.ErrNoRows) {
		return row, errLeadNotFound
	}
	return row, err
}

// lockLead: khóa dòng khách trong giao dịch (sau khi đã kiểm phạm vi).
func lockLead(ctx context.Context, q *crmdb.Queries, id uuid.UUID) (crmdb.CrmLead, error) {
	l, err := q.LockLead(ctx, id)
	if errors.Is(err, pgx.ErrNoRows) {
		return l, errLeadNotFound
	}
	return l, err
}

// leadBranchOptions: chi nhánh được chọn khi thêm / sửa khách. Toàn hệ thống → mọi chi nhánh đang hoạt động
// (không có thì mọi chi nhánh); bị giới hạn chi nhánh → chỉ chi nhánh của mình.
func (s *Service) leadBranchOptions(ctx context.Context, q *crmdb.Queries, a *iam.Access) ([]crmdb.ListBranchesBriefRow, error) {
	if a.Level("lead") == iam.ScopeAll {
		rows, err := q.ListBranchesBrief(ctx, crmdb.ListBranchesBriefParams{OnlyActive: true})
		if err != nil || len(rows) > 0 {
			return rows, err
		}
		return q.ListBranchesBrief(ctx, crmdb.ListBranchesBriefParams{})
	}
	ids := a.BranchIDs()
	if len(ids) == 0 {
		return nil, nil
	}
	return q.ListBranchesBrief(ctx, crmdb.ListBranchesBriefParams{Ids: ids})
}

// ───────────────────────── Danh sách khách ─────────────────────────

// ListFilter: bộ lọc chung của Danh sách / Kanban / Đã chốt / Thất bại (Laravel applyListFilters).
type ListFilter struct {
	Search         string
	BranchID       *uuid.UUID
	AssignedUserID *uuid.UUID
	Source         string
	From, To       *time.Time // ngày (giờ Việt Nam)
	Stage          string
	Stale          bool // chip "Chưa liên hệ >24h"
	FollowUp       bool // chip "Cần gọi lại"
	TestToday      bool // chip "Hẹn test hôm nay"
	Page, PerPage  int
}

type listParams struct {
	q, qDigits     *string
	branchID       *uuid.UUID
	assignedUserID *uuid.UUID
	source         *string
	from, to       *time.Time
}

// prepare chuẩn hoá bộ lọc: lọc chi nhánh chỉ khi người xem toàn hệ thống, hoặc phạm vi chi nhánh và chi nhánh đó
// thuộc mình (ngoài phạm vi → bỏ qua như Laravel).
func (f ListFilter) prepare(a *iam.Access) listParams {
	var p listParams
	if q := strings.TrimSpace(f.Search); q != "" {
		like := "%" + escapeLike(q) + "%"
		p.q = &like
		if d := NormalizePhone(q); len(d) >= 3 {
			dl := "%" + d + "%"
			p.qDigits = &dl
		}
	}
	if f.BranchID != nil {
		switch a.Level("lead") {
		case iam.ScopeAll:
			p.branchID = f.BranchID
		case iam.ScopeBranch:
			if slices.Contains(a.BranchIDs(), *f.BranchID) {
				p.branchID = f.BranchID
			}
		}
	}
	p.assignedUserID = f.AssignedUserID
	p.source = strPtr(f.Source)
	if f.From != nil {
		start, _ := dayBounds(*f.From)
		p.from = &start
	}
	if f.To != nil {
		_, end := dayBounds(*f.To)
		p.to = &end
	}
	return p
}

func escapeLike(s string) string {
	return strings.NewReplacer(`\`, `\\`, `%`, `\%`, `_`, `\_`).Replace(s)
}

// LeadRow: một dòng danh sách khách.
type LeadRow struct {
	ID                 uuid.UUID  `json:"id"`
	Code               string     `json:"code"`
	ShortCode          string     `json:"short_code"`
	Name               string     `json:"name"`
	Phone              string     `json:"phone"`
	ParentName         *string    `json:"parent_name"`
	ParentPhone        *string    `json:"parent_phone"`
	Email              *string    `json:"email"`
	Source             *string    `json:"source"`
	Stage              string     `json:"stage"`
	StageLabel         string     `json:"stage_label"`
	CourseInterest     *string    `json:"course_interest"`
	DealValue          int64      `json:"deal_value"`
	BranchID           *uuid.UUID `json:"branch_id"`
	Branch             *string    `json:"branch"`
	AssignedUserID     *uuid.UUID `json:"assigned_user_id"`
	AssignedUser       *string    `json:"assigned_user"`
	AppointmentAt      *time.Time `json:"appointment_at"`
	AppointmentType    *string    `json:"appointment_type"`
	NextFollowUpAt     *time.Time `json:"next_follow_up_at"`
	LastCareAt         *time.Time `json:"last_care_at"`
	LostReason         *string    `json:"lost_reason"`
	LostAt             *time.Time `json:"lost_at"`
	ConvertedAt        *time.Time `json:"converted_at"`
	ConvertedStudentID *uuid.UUID `json:"converted_student_id"`
	ClassName          *string    `json:"class_name"`
	EnrollmentDone     *bool      `json:"enrollment_confirmed"` // null = chưa có ghi danh
	Notes              *string    `json:"notes"`
	CreatedAt          time.Time  `json:"created_at"`
	UpdatedAt          time.Time  `json:"updated_at"`
	SLA                *SLA       `json:"sla"`
	TestTodayAt        *time.Time `json:"test_today_at"`
}

// Page: dữ liệu phân trang.
type Page[T any] struct {
	Data []T  `json:"data"`
	Meta Meta `json:"meta"`
}

type Meta struct {
	Page    int   `json:"page"`
	PerPage int   `json:"per_page"`
	Total   int64 `json:"total"`
}

func pageArgs(page, per, def int) (int, int, int32, int32) {
	if per <= 0 {
		per = def
	}
	per = min(per, 100)
	page = max(page, 1)
	return page, per, int32(per), int32((page - 1) * per)
}

// ChipCounts: số trên chip lọc nhanh của header CRM.
type ChipCounts struct {
	SLA          int64 `json:"sla"`
	TestToday    int64 `json:"test_today"`
	FollowUp     int64 `json:"follow_up"`
	WaitingClass int64 `json:"waiting_class"`
	Won          int64 `json:"won"`
	Lost         int64 `json:"lost"`
	Deleted      int64 `json:"deleted"`
}

func (s *Service) chipCounts(ctx context.Context, a *iam.Access) (ChipCounts, error) {
	now := s.now()
	start, end := dayBounds(now)
	sc := scopeOf(a)
	r, err := s.q.ChipCounts(ctx, crmdb.ChipCountsParams{
		FirstContactCutoff: now.Add(-firstContactSLAHours * time.Hour), TodayStart: start, TodayEnd: end,
		ScopeOwner: sc.owner, ScopeBranches: sc.branches,
	})
	return ChipCounts{SLA: r.Sla, TestToday: r.TestToday, FollowUp: r.FollowUp, WaitingClass: r.WaitingClass, Won: r.Won,
		Lost: r.Lost, Deleted: r.Deleted}, err
}

// ChipCounts (công khai cho handler).
func (s *Service) ChipCounts(ctx context.Context, a *iam.Access) (ChipCounts, error) {
	return s.chipCounts(ctx, a)
}

// listKind: màn danh sách (khác nhau ở cột ngày lọc và thứ tự).
type listKind int

const (
	listAll listKind = iota
	listWon
	listLost
)

func (s *Service) listLeads(ctx context.Context, a *iam.Access, f ListFilter, kind listKind, exportAll bool) ([]LeadRow, int64, error) {
	now := s.now()
	start, end := dayBounds(now)
	sc := scopeOf(a)
	p := f.prepare(a)
	args := crmdb.ListLeadsParams{
		ScopeOwner: sc.owner, ScopeBranches: sc.branches, Q: p.q, QDigits: p.qDigits, BranchID: p.branchID,
		AssignedUserID: p.assignedUserID, Source: p.source, DateFrom: p.from, DateTo: p.to, DateCol: "created_at",
		FirstContactCutoff: now.Add(-firstContactSLAHours * time.Hour), TodayStart: start, TodayEnd: end,
		OnlyStale: f.Stale, OnlyFollowUp: f.FollowUp, OnlyTestToday: f.TestToday, Sort: "updated",
	}
	switch kind {
	case listWon:
		args.Stage, args.DateCol, args.Sort = ptr(StageWon), "converted_at", "converted"
	case listLost:
		args.Stage, args.DateCol, args.Sort, args.SearchLostReason = ptr(StageLost), "lost_at", "lost", true
	default:
		if validStage(f.Stage) {
			args.Stage = &f.Stage
		}
		switch {
		case f.TestToday:
			args.Sort = "test_today"
		case f.FollowUp:
			args.Sort = "follow_up"
		}
	}
	_, _, args.Lim, args.Off = pageArgs(f.Page, f.PerPage, 15)
	if exportAll {
		args.Lim, args.Off = 100000, 0
	}
	rows, err := s.q.ListLeads(ctx, args)
	if err != nil {
		return nil, 0, err
	}
	out := make([]LeadRow, 0, len(rows))
	var total int64
	for _, r := range rows {
		total = r.Total
		row := LeadRow{
			ID: r.ID, Code: r.Code, ShortCode: ShortCode(r.Code), Name: r.Name, Phone: r.Phone, ParentName: r.ParentName,
			ParentPhone: r.ParentPhone, Email: r.Email, Source: r.Source, Stage: r.Stage, StageLabel: StageLabel(r.Stage),
			CourseInterest: r.CourseInterest, DealValue: r.DealValue, BranchID: r.BranchID, Branch: r.BranchName,
			AssignedUserID: r.AssignedUserID, AssignedUser: r.AssignedUserName, AppointmentAt: r.AppointmentAt,
			AppointmentType: r.AppointmentType, NextFollowUpAt: r.NextFollowUpAt, LastCareAt: r.LastCareAt,
			LostReason: r.LostReason, LostAt: r.LostAt, ConvertedAt: r.ConvertedAt, ConvertedStudentID: r.ConvertedStudentID,
			ClassName: strPtr(r.ClassName), Notes: r.Notes, CreatedAt: r.CreatedAt, UpdatedAt: r.UpdatedAt,
			SLA: contactSLA(r.Stage, r.CreatedAt, r.LastCareAt, r.NextFollowUpAt, now),
		}
		if r.ClassName != "" {
			row.EnrollmentDone = ptr(r.EnrollmentConfirmedAt != nil)
		}
		if r.AppointmentAt != nil && !r.AppointmentAt.Before(start) && !r.AppointmentAt.After(end) &&
			r.Stage != StageLost && !isClosed(r.Stage) {
			row.TestTodayAt = r.AppointmentAt
		}
		out = append(out, row)
	}
	return out, total, nil
}

// LeadList: kết quả màn Danh sách khách.
type LeadList struct {
	Page[LeadRow]
	Counts ChipCounts `json:"counts"`
}

func (s *Service) ListLeads(ctx context.Context, a *iam.Access, f ListFilter) (*LeadList, error) {
	rows, total, err := s.listLeads(ctx, a, f, listAll, false)
	if err != nil {
		return nil, err
	}
	counts, err := s.chipCounts(ctx, a)
	if err != nil {
		return nil, err
	}
	page, per, _, _ := pageArgs(f.Page, f.PerPage, 15)
	return &LeadList{Page: Page[LeadRow]{Data: rows, Meta: Meta{page, per, total}}, Counts: counts}, nil
}

// WonList: màn Khách chốt thành công.
type WonList struct {
	Page[LeadRow]
	TotalCount     int64      `json:"total_count"`
	TotalContract  int64      `json:"total_contract_amount"`
	TotalCollected int64      `json:"total_collected_amount"` // TODO(merge): học phí đã thu (phân hệ tài chính)
	TotalDebt      int64      `json:"total_debt_amount"`      // TODO(merge): học phí còn nợ (phân hệ tài chính)
	Counts         ChipCounts `json:"counts"`
}

func (s *Service) ListWon(ctx context.Context, a *iam.Access, f ListFilter) (*WonList, error) {
	rows, total, err := s.listLeads(ctx, a, f, listWon, false)
	if err != nil {
		return nil, err
	}
	sc := scopeOf(a)
	p := f.prepare(a)
	tot, err := s.q.LeadTotals(ctx, crmdb.LeadTotalsParams{
		Stage: StageWon, ScopeOwner: sc.owner, ScopeBranches: sc.branches, Q: p.q, QDigits: p.qDigits,
		BranchID: p.branchID, AssignedUserID: p.assignedUserID, Source: p.source, DateFrom: p.from, DateTo: p.to,
	})
	if err != nil {
		return nil, err
	}
	counts, err := s.chipCounts(ctx, a)
	if err != nil {
		return nil, err
	}
	page, per, _, _ := pageArgs(f.Page, f.PerPage, 20)
	return &WonList{
		Page: Page[LeadRow]{Data: rows, Meta: Meta{page, per, total}}, TotalCount: tot.Total, TotalContract: tot.DealTotal,
		Counts: counts,
	}, nil
}

func (s *Service) ListLost(ctx context.Context, a *iam.Access, f ListFilter) (*LeadList, error) {
	rows, total, err := s.listLeads(ctx, a, f, listLost, false)
	if err != nil {
		return nil, err
	}
	counts, err := s.chipCounts(ctx, a)
	if err != nil {
		return nil, err
	}
	page, per, _, _ := pageArgs(f.Page, f.PerPage, 20)
	return &LeadList{Page: Page[LeadRow]{Data: rows, Meta: Meta{page, per, total}}, Counts: counts}, nil
}

// ───────────────────────── Lựa chọn của bộ lọc / form ─────────────────────────

// Options: lựa chọn cho bộ lọc và form thêm / sửa khách.
type Options struct {
	Stages            []Option      `json:"stages"`
	FilterBranches    []Option      `json:"filter_branches"`
	FilterOwners      []Option      `json:"filter_owners"`
	FilterSources     []string      `json:"filter_sources"`
	FormBranches      []Option      `json:"form_branches"`
	FormOwners        []OwnerOption `json:"form_owners"`
	AllOwners         []OwnerOption `json:"all_owners"` // phân công lại: mọi cơ sở
	Sources           []string      `json:"sources"`
	DefaultBranchID   *uuid.UUID    `json:"default_branch_id"`
	DefaultAssigneeID uuid.UUID     `json:"default_assignee_id"`
	EventTypes        []Option      `json:"event_types"`
	CareItems         []Option      `json:"care_items"`
	Scope             string        `json:"scope"`
	Permissions       StagePerms    `json:"permissions"`
}

// StagePerms: quyền chuyển giai đoạn gửi cho Kanban / hồ sơ.
type StagePerms struct {
	CanForward  bool `json:"can_forward"`
	CanBackward bool `json:"can_backward"`
	CanConvert  bool `json:"can_convert"`
	CanMarkLost bool `json:"can_mark_lost"`
}

func stagePerms(a *iam.Access) StagePerms {
	return StagePerms{
		CanForward: a.Can("lead.stage_forward"), CanBackward: a.Can("lead.stage_back"),
		CanConvert: a.Can("lead.convert"), CanMarkLost: a.Can("lead.mark_lost"),
	}
}

func (s *Service) Options(ctx context.Context, a *iam.Access) (*Options, error) {
	sc := scopeOf(a)
	level := a.Level("lead")
	o := &Options{
		Scope: level, Sources: DefaultSources, DefaultAssigneeID: a.UserID, Permissions: stagePerms(a),
		FilterBranches: []Option{}, FilterOwners: []Option{}, FormBranches: []Option{}, AllOwners: []OwnerOption{},
	}
	for _, st := range append(slices.Clone(PipelineStages), StageLost) {
		o.Stages = append(o.Stages, Option{st, StageLabel(st)})
	}
	for _, e := range eventTypeLabels {
		o.EventTypes = append(o.EventTypes, Option{e.Key, e.Label})
	}
	for _, c := range careChecklistItems {
		o.CareItems = append(o.CareItems, Option{c.Key, c.Label})
	}
	// Bộ lọc chi nhánh: toàn hệ thống → mọi chi nhánh; phạm vi chi nhánh nhiều chi nhánh → chi nhánh của mình.
	var fb []crmdb.ListBranchesBriefRow
	var err error
	switch {
	case level == iam.ScopeAll:
		fb, err = s.q.ListBranchesBrief(ctx, crmdb.ListBranchesBriefParams{})
	case level == iam.ScopeBranch && len(a.BranchIDs()) > 1:
		fb, err = s.q.ListBranchesBrief(ctx, crmdb.ListBranchesBriefParams{Ids: a.BranchIDs()})
	}
	if err != nil {
		return nil, err
	}
	for _, b := range fb {
		o.FilterBranches = append(o.FilterBranches, Option{b.ID.String(), b.Name})
	}
	// Người phụ trách cho bộ lọc: ứng viên trong phạm vi + người đang phụ trách khách trong phạm vi.
	var candidates []*owner
	if level != iam.ScopeOwn {
		var limit []uuid.UUID
		if level != iam.ScopeAll {
			limit = a.BranchIDs()
			if limit == nil {
				limit = []uuid.UUID{}
			}
		}
		if candidates, err = s.candidates(ctx, s.q, limit); err != nil {
			return nil, err
		}
	}
	seen := map[uuid.UUID]bool{}
	for _, c := range candidates {
		seen[c.ID] = true
		o.FilterOwners = append(o.FilterOwners, Option{c.ID.String(), c.Label()})
	}
	current, err := s.q.ListCurrentOwners(ctx, crmdb.ListCurrentOwnersParams{ScopeOwner: sc.owner, ScopeBranches: sc.branches})
	if err != nil {
		return nil, err
	}
	var extra []uuid.UUID
	for _, c := range current {
		if !seen[c.ID] {
			extra = append(extra, c.ID)
		}
	}
	extraOwners, err := s.loadOwners(ctx, s.q, extra)
	if err != nil {
		return nil, err
	}
	for _, c := range extraOwners {
		o.FilterOwners = append(o.FilterOwners, Option{c.ID.String(), c.Label()})
	}
	if o.FilterSources, err = s.q.ListSources(ctx, crmdb.ListSourcesParams{ScopeOwner: sc.owner, ScopeBranches: sc.branches}); err != nil {
		return nil, err
	}
	// Form thêm khách.
	branches, err := s.leadBranchOptions(ctx, s.q, a)
	if err != nil {
		return nil, err
	}
	for _, b := range branches {
		o.FormBranches = append(o.FormBranches, Option{b.ID.String(), b.Name})
	}
	if len(branches) == 1 {
		o.DefaultBranchID = &branches[0].ID
	} else if len(a.Branches) > 0 && a.Branches[0].Primary {
		o.DefaultBranchID = &a.Branches[0].ID
	}
	o.FormOwners = ownerOptions(candidates)
	if a.Can("lead.assign") {
		all, err := s.candidates(ctx, s.q, nil)
		if err != nil {
			return nil, err
		}
		o.AllOwners = ownerOptions(all)
	}
	return o, nil
}

// ───────────────────────── Thêm / sửa khách ─────────────────────────

// LeadInput: dữ liệu form thêm / sửa khách. Trường con trỏ nil = không gửi.
type LeadInput struct {
	Name           string     `json:"name"`
	Phone          string     `json:"phone"`
	ParentName     *string    `json:"parent_name"`
	ParentPhone    *string    `json:"parent_phone"`
	Email          *string    `json:"email"`
	Dob            *string    `json:"dob"` // YYYY-MM-DD
	Gender         *string    `json:"gender"`
	Address        *string    `json:"address"`
	School         *string    `json:"school"`
	BranchID       *uuid.UUID `json:"branch_id"`
	CourseInterest *string    `json:"course_interest"`
	Source         *string    `json:"source"`
	AssignedUserID *uuid.UUID `json:"assigned_user_id"`
	DealValue      *int64     `json:"deal_value"`
	NextFollowUpAt *string    `json:"next_follow_up_at"`
	Notes          *string    `json:"notes"`
}

// cleaned: dữ liệu đã kiểm tra dùng để ghi.
type cleaned struct {
	name, phone, phoneNorm                  string
	parentName, parentPhone, email          *string
	gender, address, school, course, source *string
	notes                                   *string
	dob, followUp                           *time.Time
	deal                                    int64
}

func maxLen(p *string, n int, label string) error {
	if p != nil && runeLen(*p) > n {
		return iam.Invalid(label + " không được vượt quá " + strconv.Itoa(n) + " ký tự.")
	}
	return nil
}

// parseDate: YYYY-MM-DD hoặc dd/mm/yyyy.
func parseDate(s string) (time.Time, bool) {
	s = strings.TrimSpace(s)
	for _, layout := range []string{"2006-01-02", "02/01/2006", "2/1/2006"} {
		if t, err := time.ParseInLocation(layout, s, time.UTC); err == nil {
			return t, true
		}
	}
	if t, err := time.Parse(time.RFC3339, s); err == nil {
		t = t.In(vnZone)
		return time.Date(t.Year(), t.Month(), t.Day(), 0, 0, 0, 0, time.UTC), true
	}
	return time.Time{}, false
}

// parseDateTime: RFC3339 hoặc "YYYY-MM-DDTHH:MM" (ô datetime-local, giờ Việt Nam).
func parseDateTime(s string) (time.Time, bool) {
	s = strings.TrimSpace(s)
	if t, err := time.Parse(time.RFC3339, s); err == nil {
		return t, true
	}
	for _, layout := range []string{"2006-01-02T15:04", "2006-01-02T15:04:05", "2006-01-02 15:04", "2006-01-02"} {
		if t, err := time.ParseInLocation(layout, s, vnZone); err == nil {
			return t, true
		}
	}
	return time.Time{}, false
}

// clean kiểm tra các trường chung của form thêm / sửa (Laravel storeCustomer / updateCustomer).
func (s *Service) clean(ctx context.Context, q *crmdb.Queries, in LeadInput, ignore *uuid.UUID) (*cleaned, error) {
	c := &cleaned{name: strings.TrimSpace(in.Name), phone: strings.TrimSpace(in.Phone)}
	if c.name == "" {
		return nil, iam.Invalid("Vui lòng nhập họ và tên khách hàng.")
	}
	if runeLen(c.name) > 255 {
		return nil, iam.Invalid("Họ tên không được vượt quá 255 ký tự.")
	}
	if c.phone == "" {
		return nil, iam.Invalid("Vui lòng nhập số điện thoại.")
	}
	if runeLen(c.phone) > 20 {
		return nil, iam.Invalid("Số điện thoại không được vượt quá 20 ký tự.")
	}
	c.parentName, c.parentPhone, c.email = strPtr(deref(in.ParentName)), strPtr(deref(in.ParentPhone)), strPtr(deref(in.Email))
	c.gender, c.address, c.school = strPtr(deref(in.Gender)), strPtr(deref(in.Address)), strPtr(deref(in.School))
	c.course, c.source, c.notes = strPtr(deref(in.CourseInterest)), strPtr(deref(in.Source)), strPtr(deref(in.Notes))
	if c.source == nil {
		return nil, iam.Invalid("Vui lòng chọn nguồn khách.")
	}
	for _, f := range []struct {
		p     *string
		n     int
		label string
	}{
		{c.parentName, 255, "Tên phụ huynh"}, {c.parentPhone, 20, "SĐT phụ huynh"}, {c.gender, 20, "Giới tính"},
		{c.address, 255, "Địa chỉ"}, {c.school, 255, "Trường học"}, {c.course, 255, "Khóa học quan tâm"},
		{c.source, 255, "Nguồn"}, {c.notes, 1000, "Ghi chú"},
	} {
		if err := maxLen(f.p, f.n, f.label); err != nil {
			return nil, err
		}
	}
	if c.email != nil && !validEmail(*c.email) {
		return nil, iam.Invalid("Địa chỉ email không đúng định dạng.")
	}
	if in.Dob != nil && strings.TrimSpace(*in.Dob) != "" {
		d, ok := parseDate(*in.Dob)
		if !ok {
			return nil, iam.Invalid("Ngày sinh không hợp lệ.")
		}
		c.dob = &d
	}
	if in.NextFollowUpAt != nil && strings.TrimSpace(*in.NextFollowUpAt) != "" {
		t, ok := parseDateTime(*in.NextFollowUpAt)
		if !ok {
			return nil, iam.Invalid("Hạn liên hệ tiếp theo không hợp lệ.")
		}
		c.followUp = &t
	}
	if in.DealValue != nil {
		if *in.DealValue < 0 || *in.DealValue > 9999999999999 {
			return nil, iam.Invalid("Giá trị hợp đồng không hợp lệ.")
		}
		c.deal = *in.DealValue
	}
	norm, err := s.assertUniqueLead(ctx, q, c.phone, c.email, ignore)
	if err != nil {
		return nil, err
	}
	c.phoneNorm = norm
	if c.parentPhone != nil && !ValidPhone(*c.parentPhone) {
		return nil, iam.Invalid("SĐT phụ huynh không hợp lệ (10 số bắt đầu bằng 0 hoặc +84).")
	}
	return c, nil
}

// assertUniqueLead: SĐT Việt Nam hợp lệ, không trùng SĐT / email với khách đang hoạt động.
func (s *Service) assertUniqueLead(ctx context.Context, q *crmdb.Queries, phone string, email *string, ignore *uuid.UUID) (string, error) {
	if !ValidPhone(phone) {
		return "", iam.Invalid("Số điện thoại không hợp lệ. Nhập số Việt Nam 10 số bắt đầu bằng 0 (hoặc +84), ví dụ 0912 345 678.")
	}
	norm := NormalizePhone(phone)
	dup, err := q.FindLeadByPhone(ctx, crmdb.FindLeadByPhoneParams{Phone: norm, IgnoreID: ignore})
	switch {
	case err == nil:
		if dup.Stage == StageLost {
			return "", iam.Invalid("Số điện thoại này đã tồn tại trong CRM (khách " + ShortCode(dup.Code) +
				`, đang Thất bại). Khách quay lại: mở hồ sơ khách đó và bấm "Mở lại khách", không tạo mới.`)
		}
		return "", iam.Invalid("Số điện thoại này đã tồn tại trong CRM (khách " + ShortCode(dup.Code) + ", " + StageLabel(dup.Stage) + ").")
	case !errors.Is(err, pgx.ErrNoRows):
		return "", err
	}
	if email != nil {
		taken, err := q.EmailTaken(ctx, crmdb.EmailTakenParams{Email: *email, IgnoreID: ignore})
		if err != nil {
			return "", err
		}
		if taken {
			return "", iam.Invalid("Email này đã tồn tại trong CRM.")
		}
	}
	return norm, nil
}

// Saved: kết quả thao tác ghi kèm thông báo cho người dùng.
type Saved struct {
	ID      uuid.UUID `json:"id"`
	Message string    `json:"message"`
}

func (s *Service) CreateLead(ctx context.Context, a *iam.Access, in LeadInput) (*Saved, error) {
	var out *Saved
	err := s.tx(ctx, func(q *crmdb.Queries) error {
		c, err := s.clean(ctx, q, in, nil)
		if err != nil {
			return err
		}
		branches, err := s.leadBranchOptions(ctx, q, a)
		if err != nil {
			return err
		}
		if in.BranchID == nil || !slices.ContainsFunc(branches, func(b crmdb.ListBranchesBriefRow) bool { return b.ID == *in.BranchID }) {
			return iam.Invalid("Vui lòng chọn cơ sở trong phạm vi của bạn.")
		}
		assignee := a.UserID
		if a.Can("lead.assign") && in.AssignedUserID != nil {
			o, err := s.loadOwner(ctx, q, *in.AssignedUserID)
			if err != nil {
				return err
			}
			if o == nil || !o.Candidate {
				return iam.Invalid(ownerInvalidMsg)
			}
			// Khách mới: người phụ trách phải thuộc cơ sở đã chọn (chuyển cơ sở chỉ qua đổi người phụ trách sau khi tạo).
			if !o.belongsToBranch(in.BranchID) {
				return iam.Invalid(o.Name + " không thuộc cơ sở đã chọn. Chọn Học vụ cùng cơ sở hoặc Admin.")
			}
			assignee = o.ID
		}
		id, err := uuid.NewV7()
		if err != nil {
			return err
		}
		l, err := q.CreateLead(ctx, crmdb.CreateLeadParams{
			ID: id, Code: leadCode(id), Name: c.name, Phone: c.phone, PhoneNormalized: &c.phoneNorm, Email: c.email,
			ParentName: c.parentName, ParentPhone: c.parentPhone, Dob: c.dob, Gender: c.gender, Address: c.address,
			School: c.school, BranchID: in.BranchID, CourseInterest: c.course, Source: c.source, AssignedUserID: &assignee,
			DealValue: c.deal, Notes: c.notes, NextFollowUpAt: c.followUp, CreatedBy: &a.UserID,
		})
		if isUniqueViolation(err) {
			// Hai phiên tạo khách cùng lúc: ràng buộc UNIQUE là chốt chặn cuối.
			return iam.Invalid("Số điện thoại hoặc email này vừa được tạo bởi phiên khác. Vui lòng kiểm tra lại.")
		}
		if err != nil {
			return err
		}
		if _, err := q.InsertEvent(ctx, crmdb.InsertEventParams{
			LeadID: l.ID, UserID: &a.UserID, Type: "system",
			Content: "Thêm mới khách hàng vào hệ thống CRM qua form nhập liệu (Nguồn: " + deref(c.source) + ").",
		}); err != nil {
			return err
		}
		if err := recordLead(ctx, q, a, l.ID, l.Code, l.Name, l.BranchID, audit.EventCreated, "lead.create",
			`Tạo mới khách hàng "`+l.Name+`"`, nil); err != nil {
			return err
		}
		if assignee != a.UserID {
			if err := notifyNewOwner(ctx, q, a.UserID, l, assignee); err != nil {
				return err
			}
		}
		out = &Saved{ID: l.ID, Message: "Đã thêm khách hàng " + l.Name + " (" + ShortCode(l.Code) + ") thành công vào Cơ sở dữ liệu!"}
		return nil
	})
	if err != nil {
		return nil, err
	}
	s.log.Info("crm: thêm khách", "lead", out.ID, "by", a.UserID)
	return out, nil
}

// Trường theo dõi khi sửa thông tin khách (ghi lịch sử trước / sau).
var trackedFields = []struct{ Key, Label string }{
	{"name", "Họ tên"}, {"phone", "Số điện thoại"}, {"parent_name", "Tên phụ huynh"}, {"parent_phone", "SĐT phụ huynh"},
	{"email", "Email"}, {"dob", "Ngày sinh"}, {"gender", "Giới tính"}, {"address", "Địa chỉ"}, {"school", "Trường học"},
	{"branch_id", "Cơ sở"}, {"course_interest", "Khóa học quan tâm"}, {"source", "Nguồn"},
	{"assigned_user_id", "Người phụ trách"}, {"deal_value", "Giá trị hợp đồng"},
	{"next_follow_up_at", "Hạn liên hệ tiếp theo"}, {"notes", "Ghi chú"},
}

// Change: một trường thay đổi (lịch sử "Sửa thông tin").
type Change struct {
	Label string  `json:"label"`
	Old   *string `json:"old"`
	New   *string `json:"new"`
}

// diffValues: so giá trị hiển thị cũ / mới theo thứ tự trackedFields.
func diffValues(old, new map[string]*string) ([]string, map[string]Change) {
	var keys []string
	changes := map[string]Change{}
	for _, f := range trackedFields {
		n, ok := new[f.Key]
		if !ok {
			continue
		}
		o := old[f.Key]
		if deref(o) != deref(n) {
			keys = append(keys, f.Key)
			changes[f.Key] = Change{f.Label, o, n}
		}
	}
	return keys, changes
}

// changesContent: "Tiêu đề. Lý do: …:\n• Nhãn: cũ → mới".
func changesContent(title string, reason *string, keys []string, changes map[string]Change) string {
	var b strings.Builder
	b.WriteString(title)
	if reason != nil && *reason != "" {
		b.WriteString(". Lý do: " + *reason)
	}
	b.WriteString(":")
	for _, k := range keys {
		c := changes[k]
		b.WriteString("\n• " + c.Label + ": " + orEmpty(c.Old) + " → " + orEmpty(c.New))
	}
	return b.String()
}

func orEmpty(p *string) string {
	if p == nil || *p == "" {
		return "(trống)"
	}
	return *p
}

func dispMoney(v int64) *string {
	if v == 0 {
		return ptr(formatMoney(0))
	}
	return ptr(formatMoney(v))
}

func dispDate(t *time.Time) *string {
	if t == nil {
		return nil
	}
	return ptr(t.Format("02/01/2006"))
}

func dispDateTime(t *time.Time) *string {
	if t == nil {
		return nil
	}
	return ptr(t.In(vnZone).Format("02/01/2006 15:04"))
}

// leadDisplay: giá trị hiển thị các trường theo dõi của khách hiện tại.
func leadDisplay(l crmdb.GetLeadRow) map[string]*string {
	return map[string]*string{
		"name": &l.Name, "phone": &l.Phone, "parent_name": l.ParentName, "parent_phone": l.ParentPhone, "email": l.Email,
		"dob": dispDate(l.Dob), "gender": l.Gender, "address": l.Address, "school": l.School, "branch_id": l.BranchName,
		"course_interest": l.CourseInterest, "source": l.Source, "assigned_user_id": l.AssignedUserName,
		"deal_value": dispMoney(l.DealValue), "next_follow_up_at": dispDateTime(l.NextFollowUpAt), "notes": l.Notes,
	}
}

func (s *Service) UpdateLead(ctx context.Context, a *iam.Access, idOrCode string, in LeadInput) (*Saved, error) {
	var out *Saved
	var newOwner *owner
	var leadID uuid.UUID
	err := s.tx(ctx, func(q *crmdb.Queries) error {
		l, err := s.lead(ctx, q, a, idOrCode)
		if err != nil {
			return err
		}
		if _, err := lockLead(ctx, q, l.ID); err != nil {
			return err
		}
		leadID = l.ID
		locked := isClosed(l.Stage) || l.ConvertedStudentID != nil
		// Khách đã chốt: trường hợp đồng không gửi → giữ giá trị cũ; gửi giá trị khác → báo khóa.
		if locked {
			if in.DealValue == nil {
				in.DealValue = &l.DealValue
			}
			if in.CourseInterest == nil {
				in.CourseInterest = l.CourseInterest
			}
			if in.BranchID == nil {
				in.BranchID = l.BranchID
			}
			for _, f := range contractLockedFields {
				changed := false
				switch f.Key {
				case "deal_value":
					changed = *in.DealValue != l.DealValue
				case "course_interest":
					changed = deref(strPtr(deref(in.CourseInterest))) != deref(l.CourseInterest)
				case "branch_id":
					changed = deref(in.BranchID) != deref(l.BranchID)
				}
				if changed {
					return iam.Invalid(f.Label + " đã khóa vì khách đã chốt (" + StageLabel(l.Stage) +
						"). Liên hệ Kế toán / Admin nếu cần điều chỉnh hợp đồng.")
				}
			}
		}
		c, err := s.clean(ctx, q, in, &l.ID)
		if err != nil {
			return err
		}
		// Khách đã có chi nhánh: đổi chi nhánh chỉ qua "Phân công lại".
		branchID := l.BranchID
		branchName := l.BranchName
		if branchID == nil {
			branches, err := s.leadBranchOptions(ctx, q, a)
			if err != nil {
				return err
			}
			i := -1
			if in.BranchID != nil {
				i = slices.IndexFunc(branches, func(b crmdb.ListBranchesBriefRow) bool { return b.ID == *in.BranchID })
			}
			if i < 0 {
				return iam.Invalid("Vui lòng chọn cơ sở trong phạm vi của bạn.")
			}
			branchID, branchName = in.BranchID, &branches[i].Name
		}
		// Đổi người phụ trách đi riêng (có thể phải chuyển cơ sở) sau khi lưu các trường khác; để trống không xóa.
		if a.Can("lead.assign") && in.AssignedUserID != nil && (l.AssignedUserID == nil || *in.AssignedUserID != *l.AssignedUserID) {
			o, err := s.loadOwner(ctx, q, *in.AssignedUserID)
			if err != nil {
				return err
			}
			if o == nil || !o.Candidate {
				return iam.Invalid(ownerInvalidMsg)
			}
			newOwner = o
		}
		next := map[string]*string{
			"name": &c.name, "phone": &c.phone, "parent_name": c.parentName, "parent_phone": c.parentPhone, "email": c.email,
			"dob": dispDate(c.dob), "gender": c.gender, "address": c.address, "school": c.school, "branch_id": branchName,
			"course_interest": c.course, "source": c.source, "deal_value": dispMoney(c.deal),
			"next_follow_up_at": dispDateTime(c.followUp), "notes": c.notes,
		}
		keys, changes := diffValues(leadDisplay(l), next)
		err = q.UpdateLeadInfo(ctx, crmdb.UpdateLeadInfoParams{
			ID: l.ID, Name: c.name, Phone: c.phone, PhoneNormalized: &c.phoneNorm, Email: c.email, ParentName: c.parentName,
			ParentPhone: c.parentPhone, Dob: c.dob, Gender: c.gender, Address: c.address, School: c.school, BranchID: branchID,
			CourseInterest: c.course, Source: c.source, DealValue: c.deal, NextFollowUpAt: c.followUp, Notes: c.notes,
		})
		if isUniqueViolation(err) {
			return iam.Invalid("Số điện thoại hoặc email này vừa được phiên khác sử dụng. Vui lòng kiểm tra lại.")
		}
		if err != nil {
			return err
		}
		if len(keys) > 0 {
			if err := insertChanges(ctx, q, l.ID, a.UserID, "update", "Sửa thông tin khách hàng", nil, keys, changes); err != nil {
				return err
			}
			if err := recordLead(ctx, q, a, l.ID, l.Code, c.name, branchID, audit.EventUpdated, "lead.update",
				`Cập nhật khách hàng "`+c.name+`"`, leadAuditChanges(keys, changes)); err != nil {
				return err
			}
		}
		// TODO(merge): đồng bộ name, phone, parent_name, parent_phone, dob sang hồ sơ học viên đã chốt
		// (students.Service) và tên tài khoản cổng học viên.
		out = &Saved{ID: l.ID, Message: "Cập nhật thông tin khách hàng thành công!"}
		return nil
	})
	if err != nil {
		return nil, err
	}
	if newOwner != nil {
		msg, err := s.changeOwner(ctx, a, leadID, newOwner, nil, "Đổi người phụ trách", nil)
		if err != nil {
			return nil, err
		}
		if msg != "" {
			out.Message += " " + msg
		}
	}
	s.log.Info("crm: sửa khách", "lead", out.ID, "by", a.UserID)
	return out, nil
}

func insertChanges(ctx context.Context, q *crmdb.Queries, lead, actor uuid.UUID, typ, title string, reason *string,
	keys []string, changes map[string]Change) error {
	data, err := jsonBytes(changes)
	if err != nil {
		return err
	}
	_, err = q.InsertEvent(ctx, crmdb.InsertEventParams{
		LeadID: lead, UserID: &actor, Type: typ, Reason: reason, Changes: data,
		Content: changesContent(title, reason, keys, changes),
	})
	return err
}
