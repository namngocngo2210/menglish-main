package kpi

import (
	"context"
	"encoding/json"
	"fmt"
	"math"
	"slices"
	"strconv"
	"strings"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/kpi/kpidb"
)

// percentLabel: tỉ lệ đạt "85,5".
func percentLabel(v float64) string { return formatNum(v, 2) }

func statusOf(rec *sheetRec) string {
	if rec == nil {
		return StatusPending
	}
	return rec.Status
}

// ───────────── Dòng phiếu gửi giao diện ─────────────

type LineView struct {
	ID            uuid.UUID   `json:"id"`
	Code          *string     `json:"code"`
	Name          string      `json:"name"`
	Description   *string     `json:"description"`
	Weight        float64     `json:"weight"`
	WeightLabel   string      `json:"weight_label"`
	MaxAmount     *float64    `json:"max_amount"`
	Amount        *float64    `json:"amount"`
	ThresholdFull string      `json:"threshold_full"`
	ThresholdHalf string      `json:"threshold_half"`
	MaxFull       *int        `json:"max_full"`
	MaxHalf       *int        `json:"max_half"`
	RuleLabel     string      `json:"rule_label"`
	Rule          Rule        `json:"rule"`
	Measure       string      `json:"measure"`
	Unit          string      `json:"unit"`
	PerMonth      bool        `json:"per_month"`
	Knockout      bool        `json:"knockout"`
	CountBased    bool        `json:"count_based"`
	Auto          bool        `json:"auto"`
	Pending       *string     `json:"pending"`
	Value         *float64    `json:"value"`
	Level         *float64    `json:"level"`
	AllowNA       bool        `json:"allow_na"`
	NA            bool        `json:"na"`
	Cap           bool        `json:"cap"`
	Evidence      []Evidence  `json:"evidence"`
	Suggestion    *Suggestion `json:"suggestion"`
}

type LineGroup struct {
	Name  string     `json:"name"`
	Items []LineView `json:"items"`
}

func dash(s string) string {
	if s == "" {
		return "—"
	}
	return s
}

func roundPtr(p *float64) *float64 {
	if p == nil {
		return nil
	}
	return ptr(math.Round(*p))
}

func lineView(l Line) LineView {
	c := l.C
	m := "count"
	if c.IsRate() {
		m = "rate"
	}
	v := LineView{ID: c.ID, Code: strPtr(c.Code), Name: c.Name, Description: strPtr(c.Description), Weight: c.Weight,
		WeightLabel: percentLabel(c.Weight), MaxAmount: roundPtr(l.MaxAmount), Amount: roundPtr(l.Amount),
		ThresholdFull: dash(c.ThresholdFull), ThresholdHalf: dash(c.ThresholdHalf), MaxFull: c.MaxFull, MaxHalf: c.MaxHalf,
		RuleLabel: dash(c.RuleLabel()), Rule: c.RuleForUI(), Measure: m, Unit: UnitLabel(c.Unit), PerMonth: c.PerMonth,
		Knockout: c.Knockout, CountBased: c.HasRule(), Auto: l.Auto, Pending: strPtr(l.Pending), Value: l.Value, Level: l.Level,
		AllowNA: c.AllowNA && !l.Auto, NA: l.NA, Cap: l.Cap, Evidence: nonNil(l.Evidence), Suggestion: l.Suggestion}
	return v
}

func lineGroups(lines []Line) []LineGroup {
	out := []LineGroup{}
	for _, l := range lines {
		name := l.C.Group
		if name == "" {
			name = "Chưa phân nhóm"
		}
		i := slices.IndexFunc(out, func(g LineGroup) bool { return g.Name == name })
		if i < 0 {
			out = append(out, LineGroup{Name: name, Items: []LineView{}})
			i = len(out) - 1
		}
		out[i].Items = append(out[i].Items, lineView(l))
	}
	return out
}

// simpleRules: phiếu chỉ gồm tiêu chí đếm 2 mức 100 / 50% (bộ Học vụ) → hai cột "Đạt 100% khi" / "Đạt 50% khi".
func simpleRules(lines []Line) bool {
	for _, l := range lines {
		if l.C.HasRule() && !l.C.twoLevel() {
			return false
		}
	}
	return true
}

// ───────────── Phiếu KPI tháng ─────────────

type SheetRow struct {
	Track       string  `json:"track"`
	Role        string  `json:"role"`
	PeriodLabel *string `json:"period_label"`
	RateLabel   string  `json:"rate_label"`
	AmountLabel string  `json:"amount_label"`
	Status      string  `json:"status"`
	StatusLabel string  `json:"status_label"`
	StatusColor string  `json:"status_color"`
	Period      string  `json:"period"`
}

type MonthlyStaff struct {
	ID     uuid.UUID  `json:"id"`
	Name   string     `json:"name"`
	Branch *string    `json:"branch"`
	Sheets []SheetRow `json:"sheets"`
}

type MonthlyFilter struct {
	Period   string `json:"period"`
	Role     string `json:"role"`
	BranchID string `json:"branch_id"`
	Page     int    `json:"page"`
}

type MonthlyPage struct {
	Staff         []MonthlyStaff `json:"staff"`
	Total         int            `json:"total"`
	Page          int            `json:"page"`
	PerPage       int            `json:"per_page"`
	Filters       MonthlyFilter  `json:"filters"`
	CurrentPeriod string         `json:"current_period"`
	PeriodOptions []Option       `json:"period_options"`
	RoleOptions   []Option       `json:"role_options"`
	BranchOptions []Option       `json:"branch_options"`
}

const perPage = 20

// periodOptions: 12 kỳ lương gần nhất (+ kỳ đang xem): "Kỳ lương tháng MM/YYYY".
func (s *Service) periodOptions(current string) []Option {
	start := dayOf(s.now())
	start = time.Date(start.Year(), start.Month(), 1, 0, 0, 0, 0, vnZone)
	keys := []string{}
	for i := 0; i < 12; i++ {
		keys = append(keys, start.AddDate(0, -i, 0).Format("2006-01"))
	}
	if !slices.Contains(keys, current) {
		keys = append(keys, current)
	}
	slices.Sort(keys)
	slices.Reverse(keys)
	out := make([]Option, 0, len(keys))
	for _, k := range keys {
		out = append(out, Option{k, "Kỳ lương tháng " + k[5:7] + "/" + k[:4]})
	}
	return out
}

// amountLabel: tiền KPI (Học vụ) / "Loại B · 1.700.000 đ" / "Loại B · hệ số 85%" / "—".
func amountLabel(sh Sheet) string {
	switch {
	case sh.Amount != nil:
		return Money(*sh.Amount)
	case sh.Grade != nil && sh.Bonus != nil:
		return "Loại " + sh.Grade.Grade + " · " + Money(*sh.Bonus)
	case sh.Grade != nil:
		return "Loại " + sh.Grade.Grade + " · hệ số " + strconv.Itoa(sh.Grade.Pay) + "%"
	}
	return "—"
}

func (s *Service) branchOptions(ctx context.Context, sc staffScope) ([]Option, error) {
	rows, err := s.q.BranchOptions(ctx, sc.branches)
	if err != nil {
		return nil, err
	}
	out := make([]Option, 0, len(rows))
	for _, r := range rows {
		out = append(out, Option{r.ID.String(), r.Name})
	}
	return out, nil
}

// itemsBySheet: dòng phiếu theo phiếu.
func (s *Service) itemsBySheet(ctx context.Context, q *kpidb.Queries, ids []uuid.UUID) (map[uuid.UUID][]kpidb.HrKpiItem, error) {
	out := map[uuid.UUID][]kpidb.HrKpiItem{}
	if len(ids) == 0 {
		return out, nil
	}
	rows, err := q.ItemsOfSheets(ctx, ids)
	if err != nil {
		return nil, err
	}
	for _, r := range rows {
		out[r.SheetID] = append(out[r.SheetID], r)
	}
	return out, nil
}

// Monthly: mỗi nhân sự (vai trò có tiêu chí KPI) một dòng / phiếu, lọc theo kỳ lương / vai trò / cơ sở, 20 người một trang.
func (s *Service) Monthly(ctx context.Context, a *iam.Access, f MonthlyFilter) (*MonthlyPage, error) {
	month, year := s.parsePeriod(f.Period)
	role := f.Role
	if !slices.Contains(Roles, role) {
		role = ""
	}
	sc := scopeOf(a)
	branches, err := s.branchOptions(ctx, sc)
	if err != nil {
		return nil, err
	}
	var branchID *uuid.UUID
	if id, err := uuid.Parse(f.BranchID); err == nil && slices.ContainsFunc(branches, func(o Option) bool { return o.Value == id.String() }) {
		branchID = &id
	}
	page := max(f.Page, 1)
	l, err := s.newLoader(ctx, s.q)
	if err != nil {
		return nil, err
	}
	// Vai trò có tiêu chí đang áp dụng; lọc GV part-time thì gồm cả Học thuật kiêm nhiệm giảng dạy.
	roles := []string{}
	for _, r := range l.active {
		if role == "" || r == role {
			roles = append(roles, r)
		}
	}
	teachingLeads := role == iam.RoleTeacherParttime && slices.Contains(roles, iam.RoleTeacherParttime)
	rows, err := s.q.ListStaff(ctx, kpidb.ListStaffParams{Roles: roles, TeachingLeads: teachingLeads, OwnOnly: sc.ownOnly,
		ViewerID: sc.viewer, ScopeBranches: sc.branches, BranchID: branchID, Lim: perPage, Off: int32((page - 1) * perPage)})
	if err != nil {
		return nil, err
	}
	periodValue := fmt.Sprintf("%04d-%02d", year, month)
	out := &MonthlyPage{Staff: []MonthlyStaff{}, Page: page, PerPage: perPage,
		Filters:       MonthlyFilter{Period: periodValue, Role: role, BranchID: uuidStr(branchID), Page: page},
		CurrentPeriod: s.now().In(vnZone).Format("2006-01"), PeriodOptions: s.periodOptions(periodValue),
		RoleOptions: s.roleOptions(l), BranchOptions: branches}
	if len(rows) > 0 {
		out.Total = int(rows[0].Total)
	}
	// Phiếu tháng lưu ở tháng đó, phiếu quý ở tháng đầu quý: nạp cả hai rồi chọn theo kỳ của từng người.
	ids := make([]uuid.UUID, 0, len(rows))
	for _, r := range rows {
		ids = append(ids, r.ID)
	}
	recs, err := s.q.SheetsOfUsers(ctx, kpidb.SheetsOfUsersParams{UserIds: ids, Year: int16(year),
		Months: []int16{int16(month), int16((month-1)/3*3 + 1)}})
	if err != nil {
		return nil, err
	}
	sheetIDs := make([]uuid.UUID, 0, len(recs))
	for _, r := range recs {
		sheetIDs = append(sheetIDs, r.HrKpiSheet.ID)
	}
	items, err := s.itemsBySheet(ctx, s.q, sheetIDs)
	if err != nil {
		return nil, err
	}
	for _, r := range rows {
		st := staff{ID: r.ID, Name: r.FullName, Roles: r.Roles, AcademicTeaching: r.AcademicTeaching, Branch: r.BranchName}
		ms := MonthlyStaff{ID: st.ID, Name: st.Name, Branch: strPtr(st.Branch), Sheets: []SheetRow{}}
		for _, track := range l.tracksFor(st) {
			// Lọc GV part-time chỉ hiện phiếu giảng dạy của Học thuật kiêm nhiệm.
			if role != "" && st.roleFor(track) != role {
				continue
			}
			p := l.period(st, track, month, year)
			var rec *sheetRec
			for _, x := range recs {
				if x.HrKpiSheet.UserID == st.ID && x.HrKpiSheet.Track == track && int(x.HrKpiSheet.Month) == p.Month {
					rec = &sheetRec{x.HrKpiSheet, x.EvaluatorName}
				}
			}
			var its []kpidb.HrKpiItem
			if rec != nil {
				its = items[rec.ID]
			}
			sh, err := l.sheet(ctx, st, month, year, rec, its, track)
			if err != nil {
				return nil, err
			}
			status := statusOf(rec)
			row := SheetRow{Track: track, Role: l.sheetRoleLabel(sh.Role, track), RateLabel: percentLabel(sh.Total) + "%",
				AmountLabel: amountLabel(sh), Status: status, StatusLabel: statusLabels[status][0], StatusColor: statusLabels[status][1],
				Period: periodValue}
			if p.Months > 1 {
				row.PeriodLabel = ptr("Phiếu " + strings.ToLower(p.Label))
			}
			ms.Sheets = append(ms.Sheets, row)
		}
		out.Staff = append(out.Staff, ms)
	}
	return out, nil
}

func uuidStr(p *uuid.UUID) string {
	if p == nil {
		return ""
	}
	return p.String()
}

// ───────────── Phiếu của một nhân sự ─────────────

type StaffRef struct {
	ID        uuid.UUID `json:"id"`
	Name      string    `json:"name"`
	RoleLabel string    `json:"role_label"`
	Branch    *string   `json:"branch"`
}

type EvaluatePage struct {
	Staff        StaffRef    `json:"staff"`
	Track        string      `json:"track"`
	Month        int         `json:"month"`
	Year         int         `json:"year"`
	Period       string      `json:"period"`
	PeriodMonths int         `json:"period_months"`
	PeriodLabel  string      `json:"period_label"`
	Status       string      `json:"status"`
	StatusLabel  string      `json:"status_label"`
	StatusColor  string      `json:"status_color"`
	RejectReason *string     `json:"reject_reason"`
	DecidedBy    *string     `json:"decided_by"`
	DecidedAt    *string     `json:"decided_at"`
	Fund         *float64    `json:"fund"`
	WeightTotal  float64     `json:"weight_total"`
	Grades       []GradeView `json:"grades"`
	SimpleRules  bool        `json:"simple_rules"`
	BonusFund    *float64    `json:"bonus_fund"`
	GradeCap     *string     `json:"grade_cap"`
	IsSelf       bool        `json:"is_self"`
	Locked       bool        `json:"locked"`
	CanClose     bool        `json:"can_close"`
	CloseOn      string      `json:"close_on"`
	CanDecide    bool        `json:"can_decide"`
	Total        float64     `json:"total"`
	Missing      int         `json:"missing"`
	Groups       []LineGroup `json:"groups"`
}

// track: mảng việc từ ?track= (teaching chỉ với Học thuật kiêm nhiệm giảng dạy, không thì 404).
func (l *loader) track(st staff, v string) (string, error) {
	if v == "" || v == TrackMain {
		return TrackMain, nil
	}
	if v == TrackTeaching && l.hasTeachingSheet(st) {
		return TrackTeaching, nil
	}
	return "", ErrStaffNotFound
}

func (s *Service) loadSheet(ctx context.Context, q *kpidb.Queries, userID uuid.UUID, p Period, track string) (*sheetRec, []kpidb.HrKpiItem, error) {
	r, err := q.GetSheet(ctx, kpidb.GetSheetParams{UserID: userID, Year: int16(p.Year), Month: int16(p.Month), Track: track})
	if isNoRows(err) {
		return nil, nil, nil
	}
	if err != nil {
		return nil, nil, err
	}
	items, err := q.ItemsOfSheets(ctx, []uuid.UUID{r.HrKpiSheet.ID})
	if err != nil {
		return nil, nil, err
	}
	return &sheetRec{r.HrKpiSheet, r.EvaluatorName}, items, nil
}

// periodLocked: kỳ lương tháng (phiếu quý: tháng cuối quý) đã duyệt / chi trả.
func (s *Service) periodLocked(ctx context.Context, p Period) (bool, error) {
	last := p.From.AddDate(0, p.Months-1, 0)
	for _, d := range []time.Time{last, last.AddDate(0, 1, -1)} {
		ok, err := s.lock.Locked(ctx, d)
		if err != nil || ok {
			return ok, err
		}
	}
	return false, nil
}

// Evaluate: phiếu KPI của một nhân sự (mở từ Phiếu KPI tháng): tiêu chí, số liệu hệ thống / ô điền tay, mức đạt, tiền / xếp
// loại, nút Duyệt / Không duyệt.
func (s *Service) Evaluate(ctx context.Context, a *iam.Access, userID uuid.UUID, period, trackParam string) (*EvaluatePage, error) {
	st, err := s.getStaff(ctx, s.q, scopeOf(a), userID)
	if err != nil {
		return nil, err
	}
	l, err := s.newLoader(ctx, s.q)
	if err != nil {
		return nil, err
	}
	track, err := l.track(st, trackParam)
	if err != nil {
		return nil, err
	}
	month, year := s.parsePeriod(period)
	p := l.period(st, track, month, year)
	rec, items, err := s.loadSheet(ctx, s.q, st.ID, p, track)
	if err != nil {
		return nil, err
	}
	sh, err := l.sheet(ctx, st, month, year, rec, items, track)
	if err != nil {
		return nil, err
	}
	locked, err := s.periodLocked(ctx, p)
	if err != nil {
		return nil, err
	}
	status := statusOf(rec)
	isSelf := st.ID == a.UserID
	out := &EvaluatePage{Staff: StaffRef{st.ID, st.Name, l.sheetRoleLabel(sh.Role, track), strPtr(st.Branch)}, Track: track,
		Month: p.Month, Year: p.Year, Period: p.Key, PeriodMonths: p.Months, PeriodLabel: p.fullLabel(), Status: status,
		StatusLabel: statusLabels[status][0], StatusColor: statusLabels[status][1], Fund: sh.Fund,
		Grades: gradeViews(sh.Role, p.Months), SimpleRules: simpleRules(sh.Lines), BonusFund: sh.BonusFund, IsSelf: isSelf,
		Locked: locked, CanClose: !dayOf(s.now()).Before(p.closeOn()), CloseOn: p.closeOn().Format("02/01/2006"),
		CanDecide: a.Can(PermConfirm) && !isSelf && !locked && status != StatusApproved,
		Total:     sh.Total, Missing: sh.Missing, Groups: lineGroups(sh.Lines)}
	if out.BonusFund == nil {
		out.BonusFund = l.cfg.gradeFund(sh.Role)
	}
	for _, ln := range sh.Lines {
		out.WeightTotal += ln.C.Weight
		if ln.Cap {
			out.GradeCap = ptr(CapGrade)
		}
	}
	if rec != nil {
		if status == StatusRejected {
			out.RejectReason = rec.RejectReason
		}
		out.DecidedBy = rec.EvaluatorName
		if rec.DecidedAt != nil {
			out.DecidedAt = ptr(rec.DecidedAt.In(vnZone).Format("15:04 02/01/2006"))
		}
	}
	return out, nil
}

// DecideInput: Duyệt (approve) / Không duyệt (reject, bắt buộc lý do); số các tiêu chí điền tay và "Không phát sinh".
type DecideInput struct {
	Month        int               `json:"month"`
	Year         int               `json:"year"`
	Track        string            `json:"track"`
	Action       string            `json:"action"`
	Actual       map[string]string `json:"actual"`
	NA           map[string]bool   `json:"na"`
	RejectReason *string           `json:"reject_reason"`
}

// Decide: Duyệt / Không duyệt phiếu. Lưu số các tiêu chí điền tay; tiêu chí tự động lấy số hệ thống đếm (kèm bản ghi để
// đối chiếu về sau). Duyệt cần đủ số liệu mọi tiêu chí và từ ngày cuối kỳ; phiếu đã duyệt không sửa được.
func (s *Service) Decide(ctx context.Context, a *iam.Access, userID uuid.UUID, in DecideInput) (string, error) {
	st, err := s.getStaff(ctx, s.q, scopeOf(a), userID)
	if err != nil {
		return "", err
	}
	l, err := s.newLoader(ctx, s.q)
	if err != nil {
		return "", err
	}
	track, err := l.track(st, in.Track)
	if err != nil {
		return "", err
	}
	if st.ID == a.UserID {
		return "", ErrSelf
	}
	switch {
	case in.Month < 1 || in.Month > 12:
		return "", iam.Invalid("Tháng không hợp lệ.")
	case in.Year < 2020 || in.Year > 2100:
		return "", iam.Invalid("Năm không hợp lệ.")
	case in.Action != "approve" && in.Action != "reject":
		return "", iam.Invalid("Thao tác không hợp lệ.")
	}
	reason := trimPtr(in.RejectReason)
	if in.Action == "reject" && reason == nil {
		return "", iam.Invalid("Cần ghi lý do không duyệt.")
	}
	if runes(reason) > 1000 {
		return "", iam.Invalid("Lý do không duyệt tối đa 1000 ký tự.")
	}
	p := l.period(st, track, in.Month, in.Year)
	if locked, err := s.periodLocked(ctx, p); err != nil {
		return "", err
	} else if locked {
		if p.Months > 1 {
			return "", iam.Invalid("Kỳ lương tháng cuối " + strings.ToLower(p.Label) + " đã duyệt — không thể sửa đánh giá KPI của quý này.")
		}
		return "", iam.Invalid("Kỳ lương tháng " + p.From.Format("01/2006") + " đã duyệt — không thể sửa đánh giá KPI của tháng này.")
	}
	// KPI chốt vào ngày cuối kỳ (cuối tháng; phiếu quý: cuối quý) — trước đó không duyệt được.
	if in.Action == "approve" && dayOf(s.now()).Before(p.closeOn()) {
		if p.Months > 1 {
			return "", iam.Invalid("Duyệt KPI quý từ ngày cuối quý " + p.closeOn().Format("02/01") + ".")
		}
		return "", iam.Invalid("Duyệt KPI từ ngày cuối tháng " + p.closeOn().Format("02/01") + ".")
	}

	criteria, err := l.activeCriteria(ctx, st.roleFor(track))
	if err != nil {
		return "", err
	}
	// Số điền tay: tiêu chí không tự động, nguồn tỉ lệ tự động (kỳ chưa có dữ liệu thì điền tay), nguồn chưa có ở v2.
	na := map[uuid.UUID]bool{}
	values := map[uuid.UUID]float64{}
	for i := range criteria {
		c := &criteria[i]
		if !c.HasRule() || (s.live(c) && !c.IsRateSource()) {
			continue
		}
		key := c.ID.String()
		if v, ok := in.NA[key]; ok && c.AllowNA {
			na[c.ID] = v
			if v {
				continue
			}
		}
		raw := strings.ReplaceAll(strings.TrimSpace(in.Actual[key]), ",", ".")
		if raw == "" {
			continue
		}
		if utf8Len(raw) > 255 {
			return "", iam.Invalid("Số liệu \"" + c.Name + "\" tối đa 255 ký tự.")
		}
		if c.IsRate() {
			v, err := strconv.ParseFloat(raw, 64)
			if err != nil || v < 0 || v > 1000 || math.IsNaN(v) {
				return "", iam.Invalid("Số liệu \"" + c.Name + "\" phải là tỉ lệ % từ 0.")
			}
			values[c.ID] = round2(v)
			continue
		}
		v, err := strconv.Atoi(raw)
		if err != nil || v < 0 || v > 9999 || strings.ContainsAny(raw, "+-") {
			return "", iam.Invalid("Số liệu \"" + c.Name + "\" phải là số nguyên từ 0.")
		}
		values[c.ID] = float64(v)
	}

	var total float64
	err = pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
		q := s.q.WithTx(tx)
		if _, err := q.EnsureSheet(ctx, kpidb.EnsureSheetParams{UserID: st.ID, Month: int16(p.Month), Year: int16(p.Year), Track: track,
			PeriodMonths: int16(p.Months)}); err != nil {
			return err
		}
		got, err := q.GetSheet(ctx, kpidb.GetSheetParams{UserID: st.ID, Year: int16(p.Year), Month: int16(p.Month), Track: track})
		if err != nil {
			return err
		}
		sheet, err := q.LockSheet(ctx, got.HrKpiSheet.ID)
		if err != nil {
			return err
		}
		if sheet.Status == StatusApproved {
			return iam.Invalid("Phiếu KPI này đã duyệt.")
		}
		for id, v := range na {
			if err := q.SetItemNA(ctx, kpidb.SetItemNAParams{SheetID: sheet.ID, CriterionID: id, NotApplicable: v}); err != nil {
				return err
			}
		}
		for id, v := range values {
			if err := q.SetItemActual(ctx, kpidb.SetItemActualParams{SheetID: sheet.ID, CriterionID: id, Actual: ptr(valueString(v))}); err != nil {
				return err
			}
		}
		items, err := q.ItemsOfSheets(ctx, []uuid.UUID{sheet.ID})
		if err != nil {
			return err
		}
		ld := *l
		ld.q = q
		sh, err := ld.sheet(ctx, st, p.Month, p.Year, &sheetRec{HrKpiSheet: sheet}, items, track)
		if err != nil {
			return err
		}
		if in.Action == "approve" && sh.Missing > 0 {
			return iam.Invalid(fmt.Sprintf("Còn %d tiêu chí chưa có số liệu.", sh.Missing))
		}
		for _, ln := range sh.Lines {
			if ln.Value == nil && !ln.C.HasRule() {
				continue // tiêu chí % cũ chưa chấm: giữ nguyên
			}
			var actual *string
			if ln.Value != nil {
				actual = ptr(valueString(*ln.Value))
			}
			var ev []byte
			if ln.Auto {
				ev, _ = json.Marshal(nonNil(ln.Evidence))
			}
			if err := q.SaveItem(ctx, kpidb.SaveItemParams{SheetID: sheet.ID, CriterionID: ln.C.ID, Actual: actual,
				Score: deref(ln.Level), Evidence: ev, NotApplicable: ln.NA}); err != nil {
				return err
			}
		}
		total = sh.Total
		status := StatusApproved
		var rr *string
		if in.Action == "reject" {
			status, rr = StatusRejected, reason
		}
		if err := q.DecideSheet(ctx, kpidb.DecideSheetParams{ID: sheet.ID, EvaluatorID: &a.UserID, TotalScore: total, Status: status,
			RejectReason: rr}); err != nil {
			return err
		}
		desc := "Duyệt phiếu KPI " + whatLabel(p, track) + " của " + st.Name + " (" + percentLabel(total) + "%)"
		if status == StatusRejected {
			desc = "Không duyệt phiếu KPI " + whatLabel(p, track) + " của " + st.Name
		}
		changes := audit.Changes{"status": {Old: sheet.Status, New: status}}
		if sheet.TotalScore != total {
			changes["total_score"] = audit.Change{Old: sheet.TotalScore, New: total}
		}
		if rr != nil {
			changes["reject_reason"] = audit.Change{Old: sheet.RejectReason, New: *rr}
		}
		return audit.Record(ctx, tx, audit.Entry{ActorID: &a.UserID, Action: "kpi.sheet." + in.Action, SubjectType: "kpi_sheet",
			SubjectID: sheet.ID.String(), SubjectLabel: st.Name + " · " + p.Label, Description: desc, Changes: changes})
	})
	if err != nil {
		return "", err
	}
	if in.Action == "approve" {
		return "Đã duyệt KPI " + whatLabel(p, track) + " của " + st.Name + " (" + percentLabel(total) + "%).", nil
	}
	return "Đã không duyệt phiếu KPI của " + st.Name + ".", nil
}

// whatLabel: "tháng 10/2026", "quý 4/2026", "giảng dạy quý 4/2026".
func whatLabel(p Period, track string) string {
	w := "tháng " + strconv.Itoa(p.Month) + "/" + strconv.Itoa(p.Year)
	if p.Months > 1 {
		w = strings.ToLower(p.Label)
	}
	if track == TrackTeaching {
		w = "giảng dạy " + w
	}
	return w
}

func valueString(v float64) string { return strconv.FormatFloat(v, 'f', -1, 64) }

func utf8Len(s string) int { return len([]rune(s)) }

// ───────────── KPI của tôi ─────────────

type MinePage struct {
	Name         string      `json:"name"`
	RoleLabel    string      `json:"role_label"`
	Track        string      `json:"track"`
	TrackOptions []Option    `json:"track_options"`
	Period       string      `json:"period"`
	PeriodMonths int         `json:"period_months"`
	PeriodLabel  string      `json:"period_label"`
	PeriodOpts   []Option    `json:"period_options"`
	Status       string      `json:"status"`
	StatusLabel  string      `json:"status_label"`
	StatusColor  string      `json:"status_color"`
	RejectReason *string     `json:"reject_reason"`
	DecidedBy    *string     `json:"decided_by"`
	DecidedAt    *string     `json:"decided_at"`
	Fund         *float64    `json:"fund"`
	Amount       *float64    `json:"amount"`
	RateLabel    string      `json:"rate_label"`
	Grade        *Grade      `json:"grade"`
	Bonus        *float64    `json:"bonus"`
	BonusFund    *float64    `json:"bonus_fund"`
	Missing      int         `json:"missing"`
	Knockout     *string     `json:"knockout"`
	Groups       []LineGroup `json:"groups"`
}

// ownPeriodOptions: 12 tháng gần nhất, hoặc 4 quý gần nhất với vai trò chấm theo quý.
func (s *Service) ownPeriodOptions(months int, current Period) []Option {
	if months == 1 {
		return s.periodOptions(current.Key)
	}
	n := dayOf(s.now())
	q := makePeriod(3, int(n.Month()), n.Year())
	ps := []Period{}
	for i := 0; i < 4; i++ {
		d := q.From.AddDate(0, -3*i, 0)
		ps = append(ps, makePeriod(3, int(d.Month()), d.Year()))
	}
	if !slices.ContainsFunc(ps, func(x Period) bool { return x.Key == current.Key }) {
		ps = append(ps, current)
	}
	slices.SortFunc(ps, func(a, b Period) int { return strings.Compare(b.Key, a.Key) })
	out := make([]Option, 0, len(ps))
	for _, x := range ps {
		out = append(out, Option{x.Key, x.Label})
	}
	return out
}

// Mine: phiếu KPI của chính người đang đăng nhập. Chỉ nhân sự có vai trò đang có tiêu chí KPI (Gate view-own-kpi).
func (s *Service) Mine(ctx context.Context, a *iam.Access, period, trackParam string) (*MinePage, error) {
	st, err := s.getStaff(ctx, s.q, staffScope{ownOnly: true, viewer: a.UserID}, a.UserID)
	if isNoRows(err) || err == ErrStaffNotFound {
		return nil, ErrNoKpi
	}
	if err != nil {
		return nil, err
	}
	l, err := s.newLoader(ctx, s.q)
	if err != nil {
		return nil, err
	}
	if st.kpiRole() == "" || !slices.Contains(l.active, st.kpiRole()) {
		return nil, ErrNoKpi
	}
	track, err := l.track(st, trackParam)
	if err != nil {
		return nil, err
	}
	month, year := s.parsePeriod(period)
	p := l.period(st, track, month, year)
	rec, items, err := s.loadSheet(ctx, s.q, st.ID, p, track)
	if err != nil {
		return nil, err
	}
	sh, err := l.sheet(ctx, st, month, year, rec, items, track)
	if err != nil {
		return nil, err
	}
	status := statusOf(rec)
	out := &MinePage{Name: st.Name, RoleLabel: l.sheetRoleLabel(sh.Role, track), Track: track, TrackOptions: []Option{},
		Period: p.Key, PeriodMonths: p.Months, PeriodLabel: p.fullLabel(), PeriodOpts: s.ownPeriodOptions(p.Months, p),
		Status: status, StatusLabel: statusLabels[status][0], StatusColor: statusLabels[status][1], Fund: sh.Fund,
		Amount: sh.Amount, RateLabel: percentLabel(sh.Total) + "%", Grade: sh.Grade, Bonus: sh.Bonus, BonusFund: sh.BonusFund,
		Missing: sh.Missing, Knockout: strPtr(sh.Knockout), Groups: lineGroups(sh.Lines)}
	if status == StatusApproved {
		out.Missing = 0
	}
	if l.hasTeachingSheet(st) {
		out.TrackOptions = []Option{{TrackMain, l.roleLabel(st.kpiRole())}, {TrackTeaching, "KPI giảng dạy (kiêm nhiệm)"}}
	}
	if rec != nil {
		if status == StatusRejected {
			out.RejectReason = rec.RejectReason
		}
		out.DecidedBy = rec.EvaluatorName
		if rec.DecidedAt != nil {
			out.DecidedAt = ptr(rec.DecidedAt.In(vnZone).Format("02/01/2006"))
		}
	}
	return out, nil
}
