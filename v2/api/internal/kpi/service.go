// Package kpi: KPI nhân sự (KpiController, App\Services\Kpi\*, KpiBoardService của Laravel): tiêu chí theo vai trò, phiếu KPI
// tháng / quý (tự tạo đầu kỳ, số liệu tự động + điền tay, Duyệt / Không duyệt), KPI của tôi, rà soát điểm danh và bảng KPI
// tự động. Số liệu tự động lấy từ phân hệ khác qua kpisrc.Sources; nguồn chưa có ở v2 thì người chấm điền tay.
package kpi

import (
	"context"
	"encoding/json"
	"errors"
	"log/slog"
	"slices"
	"strconv"
	"strings"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgxpool"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/kpi/kpidb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/kpi/kpisrc"
)

// Giờ Việt Nam (không đổi giờ theo mùa).
var vnZone = time.FixedZone("ICT", 7*3600)

const (
	PermView    = "kpi.view"
	PermConfirm = "kpi.confirm"
	PermManage  = "kpi.manage"
	scopeModule = "kpi"

	// Mảng việc của phiếu: main = vai trò chính; teaching = KPI giảng dạy (bộ GV part-time) của Học thuật kiêm nhiệm.
	TrackMain     = "main"
	TrackTeaching = "teaching"

	// Trạng thái phiếu: draft = Chờ duyệt (tự tạo đầu kỳ), confirmed = Đã duyệt (vào bảng lương), rejected = Không duyệt.
	StatusPending  = "draft"
	StatusApproved = "confirmed"
	StatusRejected = "rejected"
)

var statusLabels = map[string][2]string{
	StatusPending:  {"Chờ duyệt", "warning"},
	StatusApproved: {"Đã duyệt", "success"},
	StatusRejected: {"Không duyệt", "error"},
}

var (
	ErrNotFound      = &iam.Error{Status: 404, Code: "not_found", Message: "Không tìm thấy tiêu chí KPI."}
	ErrStaffNotFound = &iam.Error{Status: 404, Code: "not_found", Message: "Không tìm thấy nhân sự."}
	ErrNoKpi         = &iam.Error{Status: 403, Code: "no_kpi", Message: "Vai trò của bạn chưa có bộ tiêu chí KPI."}
	ErrSelf          = &iam.Error{Status: 403, Code: "self_evaluation", Message: "Bạn không được tự chấm KPI của chính mình."}
)

// ───────────── Phân hệ khác ─────────────

// PayrollLock: kỳ lương của tháng đã duyệt / đã chi trả thì khóa phiếu KPI tháng đó (PayrollPeriod::isLockedFor).
// TODO(gộp): nối phân hệ Lương khi chuyển sang v2; hiện không kỳ nào bị khóa.
type PayrollLock interface {
	Locked(ctx context.Context, day time.Time) (bool, error)
}

type noLock struct{}

func (noLock) Locked(context.Context, time.Time) (bool, error) { return false, nil }

// Journal: sự vụ Nhật ký Học vụ gắn mã KPI của một người (reports.Service.KpiJournal).
type Journal interface {
	KpiJournal(ctx context.Context, userID uuid.UUID, from, to time.Time) ([]kpisrc.JournalEntry, error)
}

type noJournal struct{}

func (noJournal) KpiJournal(context.Context, uuid.UUID, time.Time, time.Time) ([]kpisrc.JournalEntry, error) {
	return nil, nil
}

// AttendanceReview: rà soát điểm danh học viên trong phạm vi Lớp học của người xem (classes.KpiAttendance).
type AttendanceReview interface {
	AttendanceDay(ctx context.Context, a *iam.Access, date time.Time, classID *uuid.UUID) ([]kpisrc.AttendanceRecord, []kpisrc.Option, error)
	// ReviewAttendance: ghi quyết định trong tx; ok = false khi điểm danh không có / ngoài phạm vi.
	ReviewAttendance(ctx context.Context, tx pgx.Tx, a *iam.Access, id uuid.UUID, decision, note string) (kpisrc.ReviewedAttendance, bool, error)
}

// BoardClasses / BoardTasks: số liệu bảng KPI tự động theo nhân sự (lớp / học viên / điểm danh; công việc).
type BoardClasses interface {
	KpiBoardClasses(ctx context.Context, userIDs []uuid.UUID, from, to time.Time) (map[uuid.UUID]kpisrc.ClassStats, error)
}

type BoardTasks interface {
	KpiBoardTasks(ctx context.Context, userIDs []uuid.UUID, from, to time.Time) (map[uuid.UUID]kpisrc.TaskStats, error)
}

// ───────────── Service ─────────────

type Service struct {
	pool       *pgxpool.Pool
	q          *kpidb.Queries
	log        *slog.Logger
	now        func() time.Time
	sources    kpisrc.Sources
	journal    Journal
	lock       PayrollLock
	attendance AttendanceReview
	classes    BoardClasses
	tasks      BoardTasks
}

func NewService(pool *pgxpool.Pool, log *slog.Logger) *Service {
	return &Service{pool: pool, q: kpidb.New(pool), log: log, now: time.Now, sources: kpisrc.Sources{},
		journal: noJournal{}, lock: noLock{}}
}

// AddSources gắn nguồn số liệu tự động của các phân hệ (gọi lúc khởi động, trước khi phục vụ request).
func (s *Service) AddSources(list ...kpisrc.Sources) *Service {
	for _, src := range list {
		for k, c := range src {
			s.sources[k] = c
		}
	}
	return s
}

func (s *Service) WithJournal(j Journal) *Service {
	if j != nil {
		s.journal = j
	}
	return s
}

func (s *Service) WithPayrollLock(l PayrollLock) *Service {
	if l != nil {
		s.lock = l
	}
	return s
}

func (s *Service) WithAttendance(a AttendanceReview) *Service {
	s.attendance = a
	return s
}

func (s *Service) WithBoard(c BoardClasses, t BoardTasks) *Service {
	s.classes, s.tasks = c, t
	return s
}

// SetClock: đồng hồ giả cho test.
func (s *Service) SetClock(now func() time.Time) { s.now = now }

func (s *Service) today() time.Time { return dayOf(s.now()) }

func dayOf(t time.Time) time.Time {
	t = t.In(vnZone)
	return time.Date(t.Year(), t.Month(), t.Day(), 0, 0, 0, 0, vnZone)
}

func isNoRows(err error) bool { return errors.Is(err, pgx.ErrNoRows) }

func ptr[T any](v T) *T { return &v }

func deref[T any](p *T) T {
	if p == nil {
		var z T
		return z
	}
	return *p
}

func trimPtr(p *string) *string {
	if p == nil {
		return nil
	}
	t := strings.TrimSpace(*p)
	if t == "" {
		return nil
	}
	return &t
}

// ───────────── Cấu hình (core.settings) ─────────────

const (
	keyFund       = "payroll_academic_kpi_fund"
	keyCycle      = "kpi_cycle_"
	keyGradeFund  = "kpi_grade_fund_"
	descCycle     = "Chu kỳ chấm KPI (1 = tháng, 3 = quý)"
	descGradeFund = "Thưởng KPI tối đa / tháng theo xếp loại (đ)"
)

type config struct {
	cycles     map[string]int
	gradeFunds map[string]float64 // đã cấu hình (0 = không có khoản thưởng)
	fund       float64
}

func settingNumber(raw []byte) (float64, bool) {
	var v any
	if json.Unmarshal(raw, &v) != nil {
		return 0, false
	}
	return numeric(v)
}

func (s *Service) loadConfig(ctx context.Context, q *kpidb.Queries) (config, error) {
	keys := []string{keyFund}
	for _, r := range Roles {
		keys = append(keys, keyCycle+r, keyGradeFund+r)
	}
	rows, err := q.GetSettings(ctx, keys)
	if err != nil {
		return config{}, err
	}
	cfg := config{cycles: map[string]int{}, gradeFunds: map[string]float64{}, fund: DefaultFund}
	for _, r := range rows {
		v, ok := settingNumber(r.Value)
		if !ok {
			continue
		}
		switch {
		case r.Key == keyFund:
			cfg.fund = v
		case strings.HasPrefix(r.Key, keyCycle):
			cfg.cycles[strings.TrimPrefix(r.Key, keyCycle)] = int(v)
		case strings.HasPrefix(r.Key, keyGradeFund):
			cfg.gradeFunds[strings.TrimPrefix(r.Key, keyGradeFund)] = v
		}
	}
	return cfg, nil
}

// periodMonths: chu kỳ chấm của vai trò (1 = tháng, 3 = quý).
func (c config) periodMonths(role string) int {
	if c.cycles[role] == 3 {
		return 3
	}
	return 1
}

// gradeFund: thưởng KPI tối đa / tháng theo xếp loại; nil = vai trò không có khoản thưởng này.
func (c config) gradeFund(role string) *float64 {
	v, ok := c.gradeFunds[role]
	if !ok {
		d, has := GradeFundDefaults[role]
		if !has {
			return nil
		}
		v = float64(d)
	}
	if v <= 0 {
		return nil
	}
	return &v
}

// ───────────── Kỳ phiếu ─────────────

// Period: kỳ phiếu (tháng, hoặc quý lưu ở tháng đầu quý). To là cuối ngày cuối kỳ.
type Period struct {
	Month, Year, Months int
	Key, Label          string
	From, To            time.Time
}

func makePeriod(months, month, year int) Period {
	start := month
	if months == 3 {
		start = (month-1)/3*3 + 1
	} else {
		months = 1
	}
	from := time.Date(year, time.Month(start), 1, 0, 0, 0, 0, vnZone)
	to := from.AddDate(0, months, 0).Add(-time.Nanosecond)
	p := Period{Month: start, Year: year, Months: months, Key: from.Format("2006-01"), From: from, To: to}
	if months == 3 {
		p.Label = "Quý " + strconv.Itoa((start-1)/3+1) + "/" + strconv.Itoa(year)
	} else {
		p.Label = "Tháng " + from.Format("01/2006")
	}
	return p
}

// monthKeys: khóa tháng của kỳ.
func (p Period) monthKeys() []string {
	out := make([]string, 0, p.Months)
	for i := 0; i < p.Months; i++ {
		out = append(out, p.From.AddDate(0, i, 0).Format("2006-01"))
	}
	return out
}

// closeOn: ngày bắt đầu duyệt được (ngày cuối kỳ).
func (p Period) closeOn() time.Time { return dayOf(p.To) }

// fullLabel: "Kỳ lương tháng 10/2026" hoặc "Quý 4/2026 (tháng 10–12)".
func (p Period) fullLabel() string {
	if p.Months > 1 {
		return p.Label + " (tháng " + strconv.Itoa(p.Month) + "–" + strconv.Itoa(int(p.To.Month())) + ")"
	}
	return "Kỳ lương tháng " + p.From.Format("01/2006")
}

// parsePeriod: ?period=YYYY-MM, sai / trống → tháng hiện tại.
func (s *Service) parsePeriod(v string) (int, int) {
	if t, err := time.ParseInLocation("2006-01", strings.TrimSpace(v), vnZone); err == nil && t.Year() >= 2020 && t.Year() <= 2100 {
		return int(t.Month()), t.Year()
	}
	n := s.now().In(vnZone)
	return int(n.Month()), n.Year()
}

// ───────────── Nhân sự ─────────────

type staff struct {
	ID               uuid.UUID
	Name             string
	Code             string
	Roles            []string
	AcademicTeaching bool
	Branch           string
}

// kpiRole: vai trò đầu tiên của nhân sự nằm trong danh sách có bộ tiêu chí (KpiCriterion::roleFor); "" = không chấm KPI.
func (st staff) kpiRole() string {
	for _, r := range st.Roles {
		if slices.Contains(Roles, r) {
			return r
		}
	}
	return ""
}

// teachingLead: Học thuật kiêm nhiệm giảng dạy (StaffType::isTeachingAcademicLead).
func (st staff) teachingLead() bool {
	return st.AcademicTeaching && slices.Contains(st.Roles, iam.RoleAcademicLead)
}

// roleFor: bộ tiêu chí chấm phiếu theo mảng việc.
func (st staff) roleFor(track string) string {
	if track == TrackTeaching {
		if st.teachingLead() {
			return iam.RoleTeacherParttime
		}
		return ""
	}
	return st.kpiRole()
}

// staffScope: phạm vi kpi của người xem — own → chỉ mình; branch → nhân sự chi nhánh mình (cộng chính mình); all → mọi người.
type staffScope struct {
	ownOnly  bool
	branches []uuid.UUID // nil = không giới hạn
	viewer   uuid.UUID
}

func scopeOf(a *iam.Access) staffScope {
	sc := staffScope{viewer: a.UserID}
	switch a.Level(scopeModule) {
	case iam.ScopeOwn:
		sc.ownOnly = true
	case iam.ScopeBranch:
		sc.branches = a.BranchIDs()
		if sc.branches == nil {
			sc.branches = []uuid.UUID{}
		}
	}
	return sc
}

func (s *Service) getStaff(ctx context.Context, q *kpidb.Queries, sc staffScope, id uuid.UUID) (staff, error) {
	r, err := q.GetStaff(ctx, kpidb.GetStaffParams{ID: id, OwnOnly: sc.ownOnly, ViewerID: sc.viewer, ScopeBranches: sc.branches})
	if isNoRows(err) {
		return staff{}, ErrStaffNotFound
	}
	if err != nil {
		return staff{}, err
	}
	return staff{ID: r.ID, Name: r.FullName, Roles: r.Roles, AcademicTeaching: r.AcademicTeaching, Branch: r.BranchName}, nil
}

// ───────────── Bộ đệm theo request ─────────────

// loader giữ cấu hình, bộ tiêu chí đang áp dụng và nhãn vai trò trong một request (danh sách 20 phiếu dùng chung).
type loader struct {
	s        *Service
	q        *kpidb.Queries
	cfg      config
	criteria map[string][]Criterion
	roles    map[string]string
	active   []string // vai trò đang có tiêu chí áp dụng
}

func (s *Service) newLoader(ctx context.Context, q *kpidb.Queries) (*loader, error) {
	cfg, err := s.loadConfig(ctx, q)
	if err != nil {
		return nil, err
	}
	active, err := q.RolesWithActiveCriteria(ctx)
	if err != nil {
		return nil, err
	}
	labels, err := q.RoleLabels(ctx)
	if err != nil {
		return nil, err
	}
	l := &loader{s: s, q: q, cfg: cfg, criteria: map[string][]Criterion{}, roles: map[string]string{}, active: active}
	for _, r := range labels {
		l.roles[r.Name] = r.Label
	}
	return l, nil
}

func (l *loader) activeCriteria(ctx context.Context, role string) ([]Criterion, error) {
	if role == "" {
		return nil, nil
	}
	if c, ok := l.criteria[role]; ok {
		return c, nil
	}
	rows, err := l.q.ActiveCriteria(ctx, role)
	if err != nil {
		return nil, err
	}
	out := make([]Criterion, 0, len(rows))
	for _, r := range rows {
		out = append(out, fromRow(r))
	}
	l.criteria[role] = out
	return out, nil
}

func (l *loader) roleLabel(role string) string {
	if v, ok := l.roles[role]; ok {
		return v
	}
	return role
}

// hasTeachingSheet: Học thuật kiêm nhiệm và bộ GV part-time đang có tiêu chí → có phiếu KPI giảng dạy.
func (l *loader) hasTeachingSheet(st staff) bool {
	return st.teachingLead() && slices.Contains(l.active, iam.RoleTeacherParttime)
}

func (l *loader) tracksFor(st staff) []string {
	if l.hasTeachingSheet(st) {
		return []string{TrackMain, TrackTeaching}
	}
	return []string{TrackMain}
}

func (l *loader) period(st staff, track string, month, year int) Period {
	return makePeriod(l.cfg.periodMonths(st.roleFor(track)), month, year)
}

// sheetRoleLabel: nhãn bộ tiêu chí của phiếu.
func (l *loader) sheetRoleLabel(role, track string) string {
	if track == TrackTeaching {
		return "KPI giảng dạy (kiêm nhiệm)"
	}
	return l.roleLabel(role)
}

func fromRow(r kpidb.HrKpiCriterium) Criterion {
	c := Criterion{ID: r.ID, Role: r.Role, Group: deref(r.GroupName), Code: deref(r.Code), Name: r.Name, Weight: r.Weight,
		ThresholdFull: deref(r.ThresholdFull), ThresholdHalf: deref(r.ThresholdHalf), Unit: deref(r.Unit), Measure: r.Measure,
		Tiers: parseTiers(r.Tiers), Linear: r.Linear, FullAtValue: r.FullAt, PerMonth: r.PerMonth, Knockout: r.Knockout,
		AllowNA: r.AllowNa, AutoSource: deref(r.AutoSource), Description: deref(r.Description), IsActive: r.IsActive,
		SortOrder: int(r.SortOrder)}
	if r.MaxFull != nil {
		c.MaxFull = ptr(int(*r.MaxFull))
	}
	if r.MaxHalf != nil {
		c.MaxHalf = ptr(int(*r.MaxHalf))
	}
	if !slices.ContainsFunc(Measures, func(o Option) bool { return o.Value == c.Measure }) {
		c.Measure = "count"
	}
	return c
}

// ───────────── Tạo phiếu đầu kỳ ─────────────

// EnsureSheets: tạo phiếu "Chờ duyệt" cho nhân sự chưa có phiếu của kỳ chứa tháng này (chạy hằng ngày; nhân sự mới vào
// giữa kỳ cũng có phiếu). Trả số phiếu mới tạo.
func (s *Service) EnsureSheets(ctx context.Context, month, year int) (int, error) {
	l, err := s.newLoader(ctx, s.q)
	if err != nil {
		return 0, err
	}
	created := 0
	for off := 0; ; off += 500 {
		rows, err := s.q.ListStaff(ctx, kpidb.ListStaffParams{Roles: nonNil(l.active), TeachingLeads: false, Lim: 500, Off: int32(off)})
		if err != nil {
			return created, err
		}
		for _, r := range rows {
			st := staff{ID: r.ID, Name: r.FullName, Roles: r.Roles, AcademicTeaching: r.AcademicTeaching}
			if !slices.Contains(l.active, st.kpiRole()) && !l.hasTeachingSheet(st) {
				continue
			}
			for _, track := range l.tracksFor(st) {
				if st.roleFor(track) == "" || !slices.Contains(l.active, st.roleFor(track)) {
					continue
				}
				p := l.period(st, track, month, year)
				n, err := s.q.EnsureSheet(ctx, kpidb.EnsureSheetParams{UserID: st.ID, Month: int16(p.Month), Year: int16(p.Year),
					Track: track, PeriodMonths: int16(p.Months)})
				if err != nil {
					return created, err
				}
				created += int(n)
			}
		}
		if len(rows) < 500 {
			return created, nil
		}
	}
}

// RunSheetLoop: tạo phiếu kỳ hiện tại ngay khi chạy rồi lặp theo interval (lệnh kpi:create-sheets của Laravel).
func (s *Service) RunSheetLoop(ctx context.Context, interval time.Duration) {
	tick := time.NewTicker(interval)
	defer tick.Stop()
	for {
		n := s.now().In(vnZone)
		if c, err := s.EnsureSheets(ctx, int(n.Month()), n.Year()); err != nil {
			if ctx.Err() != nil {
				return
			}
			s.log.Error("kpi: tạo phiếu KPI đầu kỳ", "err", err)
		} else if c > 0 {
			s.log.Info("kpi: đã tạo phiếu KPI", "count", c)
		}
		select {
		case <-ctx.Done():
			return
		case <-tick.C:
		}
	}
}

func nonNil[T any](v []T) []T {
	if v == nil {
		return []T{}
	}
	return v
}
