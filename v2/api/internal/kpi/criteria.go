package kpi

import (
	"cmp"
	"context"
	"encoding/json"
	"math"
	"slices"
	"strconv"
	"strings"
	"unicode/utf8"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/kpi/kpidb"
)

// ───────────── Màn Tiêu chí KPI ─────────────

type CriterionView struct {
	ID            uuid.UUID `json:"id"`
	Code          *string   `json:"code"`
	Name          string    `json:"name"`
	GroupName     *string   `json:"group_name"`
	Weight        string    `json:"weight"`
	FundAmount    *float64  `json:"fund_amount"`
	Unit          *string   `json:"unit"`
	AutoSource    *string   `json:"auto_source"`
	SourcePending *string   `json:"source_pending"`
	MaxFull       *int      `json:"max_full"`
	MaxHalf       *int      `json:"max_half"`
	ThresholdFull *string   `json:"threshold_full"`
	ThresholdHalf *string   `json:"threshold_half"`
	Measure       string    `json:"measure"`
	Tiers         []Tier    `json:"tiers"`
	Linear        bool      `json:"linear"`
	FullAt        *float64  `json:"full_at"`
	PerMonth      bool      `json:"per_month"`
	Knockout      bool      `json:"knockout"`
	AllowNA       bool      `json:"allow_na"`
	RuleLabel     *string   `json:"rule_label"`
	Description   *string   `json:"description"`
	IsActive      bool      `json:"is_active"`
}

type CriteriaGroup struct {
	Name         string          `json:"name"`
	Count        int             `json:"count"`
	ActiveWeight string          `json:"active_weight"`
	ActiveFund   *float64        `json:"active_fund"`
	Items        []CriterionView `json:"items"`
}

type GradeView struct {
	Grade string `json:"grade"`
	From  int    `json:"from"`
	Label string `json:"label"`
	Pay   int    `json:"pay"`
}

type CriteriaPage struct {
	Role             string          `json:"role"`
	RoleOptions      []Option        `json:"role_options"`
	UnitOptions      []Option        `json:"unit_options"`
	SourceOptions    []Option        `json:"source_options"`
	MeasureOptions   []Option        `json:"measure_options"`
	CycleOptions     []Option        `json:"cycle_options"`
	Groups           []string        `json:"groups"`
	Fund             *float64        `json:"fund"`
	PeriodMonths     int             `json:"period_months"`
	Grades           []GradeView     `json:"grades"`
	GradeFund        *float64        `json:"grade_fund"`
	TotalWeight      float64         `json:"total_weight"`
	TotalWeightLabel string          `json:"total_weight_label"`
	CriteriaGroups   []CriteriaGroup `json:"criteria_groups"`
	CanManage        bool            `json:"can_manage"`
}

// weightLabel: trọng số hiển thị trên màn Tiêu chí (number_format mặc định: dấu chấm thập phân).
func weightLabel(w float64) string {
	return strings.TrimRight(strings.TrimRight(strconv.FormatFloat(round2(w), 'f', 2, 64), "0"), ".")
}

// fundAmount: số tiền tối đa của mục = quỹ × trọng số %.
func fundAmount(fund, weight float64) float64 { return math.Round(fund * weight / 100) }

func gradeViews(role string, months int) []GradeView {
	out := []GradeView{}
	if HasGrades(role, months) {
		for _, g := range Grades {
			out = append(out, GradeView{g.Grade, g.From, g.Label, g.Pay})
		}
	}
	return out
}

func (s *Service) roleOptions(l *loader) []Option {
	out := make([]Option, 0, len(Roles))
	for _, r := range Roles {
		out = append(out, Option{r, l.roleLabel(r)})
	}
	return out
}

func ucfirst(s string) string {
	r, n := utf8.DecodeRuneInString(s)
	return strings.ToUpper(string(r)) + s[n:]
}

// Criteria: tiêu chí của một vai trò (mặc định Học vụ), nhóm theo cấu hình, chu kỳ chấm, xếp loại, quỹ.
func (s *Service) Criteria(ctx context.Context, a *iam.Access, role string) (*CriteriaPage, error) {
	if !slices.Contains(Roles, role) {
		role = iam.RoleAcademicStaff
	}
	l, err := s.newLoader(ctx, s.q)
	if err != nil {
		return nil, err
	}
	rows, err := s.q.ListCriteria(ctx, role)
	if err != nil {
		return nil, err
	}
	months := l.cfg.periodMonths(role)
	out := &CriteriaPage{Role: role, RoleOptions: s.roleOptions(l), MeasureOptions: Measures, CycleOptions: Cycles,
		Groups: []string{}, PeriodMonths: months, Grades: gradeViews(role, months), GradeFund: l.cfg.gradeFund(role),
		CriteriaGroups: []CriteriaGroup{}, CanManage: a.Can(PermManage)}
	for _, u := range Units {
		out.UnitOptions = append(out.UnitOptions, Option{u.Value, ucfirst(u.Label)})
	}
	out.SourceOptions = []Option{{"", "Người chấm điền tay"}}
	for _, src := range AutoSources {
		out.SourceOptions = append(out.SourceOptions, Option{src.Value, "Tự động: " + src.Label})
	}
	if role == iam.RoleAcademicStaff {
		out.Fund = ptr(l.cfg.fund)
	}
	for _, r := range rows {
		c := fromRow(r)
		if c.Group != "" && !slices.Contains(out.Groups, c.Group) {
			out.Groups = append(out.Groups, c.Group)
		}
		if c.IsActive {
			out.TotalWeight += c.Weight
		}
		name := c.Group
		if name == "" {
			name = "Chưa phân nhóm"
		}
		i := slices.IndexFunc(out.CriteriaGroups, func(g CriteriaGroup) bool { return g.Name == name })
		if i < 0 {
			out.CriteriaGroups = append(out.CriteriaGroups, CriteriaGroup{Name: name, Items: []CriterionView{}})
			i = len(out.CriteriaGroups) - 1
		}
		g := &out.CriteriaGroups[i]
		g.Items = append(g.Items, s.criterionView(c, out.Fund))
		g.Count++
	}
	for i := range out.CriteriaGroups {
		g := &out.CriteriaGroups[i]
		w, f := 0.0, 0.0
		for _, it := range g.Items {
			if it.IsActive {
				v, _ := strconv.ParseFloat(it.Weight, 64)
				w += v
				if it.FundAmount != nil {
					f += *it.FundAmount
				}
			}
		}
		g.ActiveWeight = weightLabel(w)
		if out.Fund != nil {
			g.ActiveFund = ptr(f)
		}
	}
	out.TotalWeight = round2(out.TotalWeight)
	out.TotalWeightLabel = weightLabel(out.TotalWeight)
	return out, nil
}

func strPtr(v string) *string {
	if v == "" {
		return nil
	}
	return &v
}

func (s *Service) criterionView(c Criterion, fund *float64) CriterionView {
	v := CriterionView{ID: c.ID, Code: strPtr(c.Code), Name: c.Name, GroupName: strPtr(c.Group), Weight: weightLabel(c.Weight),
		Unit: strPtr(c.Unit), MaxFull: c.MaxFull, MaxHalf: c.MaxHalf, ThresholdFull: strPtr(c.ThresholdFull),
		ThresholdHalf: strPtr(c.ThresholdHalf), Measure: c.Measure, Tiers: c.TierList(), Linear: c.Linear, FullAt: c.FullAtValue,
		PerMonth: c.PerMonth, Knockout: c.Knockout, AllowNA: c.AllowNA, RuleLabel: strPtr(c.RuleLabel()),
		Description: strPtr(c.Description), IsActive: c.IsActive}
	if fund != nil {
		v.FundAmount = ptr(fundAmount(*fund, c.Weight))
	}
	if c.IsAuto() {
		v.AutoSource = ptr(c.AutoSource)
		if !s.live(&c) {
			v.SourcePending = ptr(PendingLabel(c.AutoSource))
		}
	}
	return v
}

// ───────────── Thêm / sửa / xóa ─────────────

// TierInput: một bậc gửi từ form (ô trống bị bỏ).
type TierInput struct {
	At      *float64 `json:"at"`
	Percent *float64 `json:"percent"`
}

type CriterionInput struct {
	Role        *string      `json:"role"`
	GroupName   *string      `json:"group_name"`
	NewGroup    *string      `json:"new_group"`
	Name        string       `json:"name"`
	Weight      *float64     `json:"weight"`
	Measure     *string      `json:"measure"`
	Unit        *string      `json:"unit"`
	Tiers       *[]TierInput `json:"tiers"`
	MaxFull     *int         `json:"max_full"`
	MaxHalf     *int         `json:"max_half"`
	Linear      bool         `json:"linear"`
	FullAt      *float64     `json:"full_at"`
	PerMonth    bool         `json:"per_month"`
	Knockout    bool         `json:"knockout"`
	AllowNA     bool         `json:"allow_na"`
	Description *string      `json:"description"`
	AutoSource  *string      `json:"auto_source"`
	IsActive    *bool        `json:"is_active"`
}

// normalized: tiêu chí đã kiểm tra và chuẩn hóa để lưu.
type normalized struct {
	group, unit, autoSource, description, thresholdFull, thresholdHalf *string
	name, measure                                                      string
	weight                                                             float64
	tiers                                                              []byte
	maxFull, maxHalf                                                   *int32
	linear, perMonth, knockout, allowNA                                bool
	fullAt                                                             *float64
}

func runes(p *string) int {
	if p == nil {
		return 0
	}
	return utf8.RuneCountInString(*p)
}

// normalizeCriterion: kiểm tra (criterionRules) và chuẩn hóa (normalizeCriterion) như KpiController. Nhóm mới (nếu gõ) thay
// nhóm chọn; bậc chuẩn hóa (bỏ dòng trống, bỏ ngưỡng trùng, sắp theo ngưỡng). Đếm số lần đúng 2 bậc 100% / 50% lưu ở
// max_full / max_half (nhãn "Ngưỡng 100 / 50" tự sinh); còn lại lưu bậc riêng.
func normalizeCriterion(in CriterionInput) (normalized, error) {
	var n normalized
	in.Name = strings.TrimSpace(in.Name)
	switch {
	case runes(in.GroupName) > 255 || runes(in.NewGroup) > 255:
		return n, iam.Invalid("Tên nhóm tối đa 255 ký tự.")
	case in.Name == "":
		return n, iam.Invalid("Vui lòng nhập tên tiêu chí.")
	case utf8.RuneCountInString(in.Name) > 255:
		return n, iam.Invalid("Tên tiêu chí tối đa 255 ký tự.")
	case in.Weight == nil:
		return n, iam.Invalid("Vui lòng nhập trọng số.")
	case *in.Weight < 0 || *in.Weight > 100:
		return n, iam.Invalid("Trọng số phải từ 0 đến 100.")
	}
	n.measure = "count"
	if m := deref(trimPtr(in.Measure)); m != "" {
		if _, ok := label(Measures, m); !ok {
			return n, iam.Invalid("Cách đo không hợp lệ.")
		}
		n.measure = m
	}
	unit := deref(trimPtr(in.Unit))
	if unit != "" {
		if _, ok := label(Units, unit); !ok {
			return n, iam.Invalid("Đơn vị đếm không hợp lệ.")
		}
	} else if n.measure == "count" {
		return n, iam.Invalid("Vui lòng chọn đơn vị đếm.")
	}
	if in.Tiers != nil && len(*in.Tiers) > 8 {
		return n, iam.Invalid("Tối đa 8 bậc tính điểm.")
	}
	legacy := in.Tiers == nil && n.measure == "count" && !in.Knockout
	if legacy {
		if in.MaxFull == nil {
			return n, iam.Invalid("Vui lòng nhập ngưỡng đạt 100% (số lần).")
		}
		if in.MaxHalf == nil {
			return n, iam.Invalid("Vui lòng nhập ngưỡng đạt 50% (số lần).")
		}
	}
	for _, v := range []*int{in.MaxFull, in.MaxHalf} {
		if v != nil && (*v < 0 || *v > 9999) {
			return n, iam.Invalid("Ngưỡng số lần phải từ 0 đến 9999.")
		}
	}
	if in.MaxFull != nil && in.MaxHalf != nil && *in.MaxHalf < *in.MaxFull {
		return n, iam.Invalid("Ngưỡng đạt 50% phải lớn hơn hoặc bằng ngưỡng đạt 100%.")
	}
	if in.Tiers != nil {
		for _, t := range *in.Tiers {
			if t.At != nil && (*t.At < 0 || *t.At > 9999) {
				return n, iam.Invalid("Ngưỡng của bậc phải từ 0 đến 9999.")
			}
			if t.Percent != nil && (*t.Percent < 0 || *t.Percent > 100) {
				return n, iam.Invalid("% điểm của bậc phải từ 0 đến 100.")
			}
		}
	}
	if in.FullAt != nil && (*in.FullAt < 0.01 || *in.FullAt > 1000) {
		return n, iam.Invalid("Ngưỡng đủ điểm phải từ 0,01% đến 1000%.")
	}
	if runes(in.Description) > 2000 {
		return n, iam.Invalid("Cách đếm tối đa 2000 ký tự.")
	}
	if src := deref(trimPtr(in.AutoSource)); src != "" {
		if _, ok := label(AutoSources, src); !ok {
			return n, iam.Invalid("Nguồn số liệu không hợp lệ.")
		}
		n.autoSource = &src
	}

	n.name, n.weight = in.Name, *in.Weight
	n.group = trimPtr(in.GroupName)
	if g := trimPtr(in.NewGroup); g != nil {
		n.group = g
	}
	n.description = trimPtr(in.Description)
	rate := n.measure != "count"
	lowerBetter := n.measure != "rate"
	n.knockout = !rate && in.Knockout
	n.linear = n.measure == "rate" && in.Linear
	n.allowNA, n.perMonth = in.AllowNA, in.PerMonth
	if n.linear && in.FullAt != nil {
		n.fullAt = in.FullAt
	}
	if rate {
		unit = RateUnit
	}
	n.unit = strPtr(unit)

	var tiers []Tier
	switch {
	case in.Tiers != nil:
		for _, t := range *in.Tiers {
			if t.At == nil || t.Percent == nil {
				continue
			}
			if !slices.ContainsFunc(tiers, func(x Tier) bool { return x[0] == *t.At }) {
				tiers = append(tiers, Tier{*t.At, *t.Percent})
			}
		}
	case in.MaxFull != nil && in.MaxHalf != nil:
		tiers = []Tier{{float64(*in.MaxFull), 100}}
		if *in.MaxHalf != *in.MaxFull {
			tiers = append(tiers, Tier{float64(*in.MaxHalf), 50})
		}
	}
	slices.SortStableFunc(tiers, func(a, b Tier) int {
		if lowerBetter {
			return cmp.Compare(a[0], b[0])
		}
		return cmp.Compare(b[0], a[0])
	})
	if n.knockout {
		// Có từ 1 lần là mất toàn bộ KPI kỳ; mức đạt của chính mục không ảnh hưởng.
		tiers = []Tier{{0, 100}}
	}
	if !n.linear && len(tiers) == 0 {
		return n, iam.Invalid("Cần ít nhất một bậc tính điểm (ngưỡng và % điểm).")
	}
	twoLevel := !rate && !n.knockout && len(tiers) == 2 && tiers[0][1] == 100 && tiers[1][1] == 50
	if twoLevel {
		mf, mh := int(tiers[0][0]), int(tiers[1][0])
		n.maxFull, n.maxHalf = ptr(int32(mf)), ptr(int32(mh))
		n.thresholdFull, n.thresholdHalf = ptr(ThresholdLabel(mf, unit)), ptr(ThresholdLabel(mh, unit))
	}
	if !twoLevel && !n.linear {
		n.tiers, _ = json.Marshal(tiers)
	}
	return n, nil
}

// snapshot: các trường tiêu chí cho nhật ký.
func snapshot(r kpidb.HrKpiCriterium) map[string]any {
	c := fromRow(r)
	return map[string]any{"group_name": c.Group, "name": c.Name, "weight": c.Weight, "measure": c.Measure, "unit": c.Unit,
		"rule": c.RuleLabel(), "auto_source": c.AutoSource, "description": c.Description, "is_active": c.IsActive,
		"per_month": c.PerMonth, "allow_na": c.AllowNA}
}

func (s *Service) CreateCriterion(ctx context.Context, a *iam.Access, in CriterionInput) (string, string, error) {
	role := deref(trimPtr(in.Role))
	if !slices.Contains(Roles, role) {
		return "", "", iam.Invalid("Vai trò không hợp lệ.")
	}
	n, err := normalizeCriterion(in)
	if err != nil {
		return "", "", err
	}
	err = pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
		q := s.q.WithTx(tx)
		next, err := q.NextSortOrder(ctx, role)
		if err != nil {
			return err
		}
		r, err := q.InsertCriterion(ctx, kpidb.InsertCriterionParams{Role: role, GroupName: n.group, Name: n.name, Weight: n.weight,
			ThresholdFull: n.thresholdFull, ThresholdHalf: n.thresholdHalf, MaxFull: n.maxFull, MaxHalf: n.maxHalf, Unit: n.unit,
			Measure: n.measure, Tiers: n.tiers, Linear: n.linear, FullAt: n.fullAt, PerMonth: n.perMonth, Knockout: n.knockout,
			AllowNa: n.allowNA, AutoSource: n.autoSource, Description: n.description, SortOrder: next})
		if err != nil {
			return err
		}
		return audit.Record(ctx, tx, audit.Entry{ActorID: &a.UserID, Event: audit.EventCreated, Action: "kpi.criteria.create",
			SubjectType: "kpi_criterion", SubjectID: r.ID.String(), SubjectLabel: r.Name,
			Description: "Thêm tiêu chí KPI " + r.Name, Changes: audit.Diff(nil, snapshot(r))})
	})
	if err != nil {
		return "", "", err
	}
	return "Đã thêm tiêu chí KPI.", role, nil
}

func (s *Service) UpdateCriterion(ctx context.Context, a *iam.Access, id uuid.UUID, in CriterionInput) (string, string, error) {
	n, err := normalizeCriterion(in)
	if err != nil {
		return "", "", err
	}
	var role string
	err = pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
		q := s.q.WithTx(tx)
		old, err := q.GetCriterion(ctx, id)
		if isNoRows(err) {
			return ErrNotFound
		}
		if err != nil {
			return err
		}
		role = old.Role
		active := in.IsActive != nil && *in.IsActive
		r, err := q.UpdateCriterion(ctx, kpidb.UpdateCriterionParams{ID: id, GroupName: n.group, Name: n.name, Weight: n.weight,
			ThresholdFull: n.thresholdFull, ThresholdHalf: n.thresholdHalf, MaxFull: n.maxFull, MaxHalf: n.maxHalf, Unit: n.unit,
			Measure: n.measure, Tiers: n.tiers, Linear: n.linear, FullAt: n.fullAt, PerMonth: n.perMonth, Knockout: n.knockout,
			AllowNa: n.allowNA, AutoSource: n.autoSource, Description: n.description, IsActive: active})
		if err != nil {
			return err
		}
		changes := audit.Diff(snapshot(old), snapshot(r))
		if len(changes) == 0 {
			return nil
		}
		return audit.Record(ctx, tx, audit.Entry{ActorID: &a.UserID, Action: "kpi.criteria.update",
			SubjectType: "kpi_criterion", SubjectID: r.ID.String(), SubjectLabel: r.Name,
			Description: "Cập nhật tiêu chí KPI " + r.Name, Changes: changes})
	})
	if err != nil {
		return "", "", err
	}
	return "Đã cập nhật tiêu chí KPI.", role, nil
}

func (s *Service) DeleteCriterion(ctx context.Context, a *iam.Access, id uuid.UUID) (string, string, error) {
	var role string
	err := pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
		q := s.q.WithTx(tx)
		old, err := q.GetCriterion(ctx, id)
		if isNoRows(err) {
			return ErrNotFound
		}
		if err != nil {
			return err
		}
		role = old.Role
		if err := q.DeleteCriterion(ctx, id); err != nil {
			return err
		}
		return audit.Record(ctx, tx, audit.Entry{ActorID: &a.UserID, Event: audit.EventDeleted, Action: "kpi.criteria.delete",
			SubjectType: "kpi_criterion", SubjectID: old.ID.String(), SubjectLabel: old.Name,
			Description: "Xóa tiêu chí KPI " + old.Name, Changes: audit.Diff(snapshot(old), nil)})
	})
	if err != nil {
		return "", "", err
	}
	return "Đã xoá tiêu chí KPI.", role, nil
}

// CycleInput: chu kỳ chấm (1 = tháng, 3 = quý) và thưởng KPI tối đa / tháng theo xếp loại của vai trò.
type CycleInput struct {
	Role         string `json:"role"`
	PeriodMonths *int   `json:"period_months"`
	// Có gửi grade_fund (kể cả null) thì lưu; null / 0 = vai trò không có khoản thưởng này.
	GradeFund json.RawMessage `json:"grade_fund"`
}

func (s *Service) SetCycle(ctx context.Context, a *iam.Access, in CycleInput) (string, error) {
	if !slices.Contains(Roles, in.Role) {
		return "", iam.Invalid("Vai trò không hợp lệ.")
	}
	if in.PeriodMonths == nil || (*in.PeriodMonths != 1 && *in.PeriodMonths != 3) {
		return "", iam.Invalid("Chu kỳ chấm không hợp lệ.")
	}
	var fund *int64
	if len(in.GradeFund) > 0 {
		var v *float64
		if err := json.Unmarshal(in.GradeFund, &v); err != nil || (v != nil && (*v < 0 || *v > 1_000_000_000)) {
			return "", iam.Invalid("Thưởng KPI tối đa phải từ 0 đến 1.000.000.000 đ.")
		}
		fund = ptr(int64(0))
		if v != nil {
			*fund = int64(*v)
		}
	}
	months := *in.PeriodMonths
	err := pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
		q := s.q.WithTx(tx)
		before, err := s.loadConfig(ctx, q)
		if err != nil {
			return err
		}
		mv, _ := json.Marshal(months)
		if err := q.UpsertSetting(ctx, kpidb.UpsertSettingParams{Key: keyCycle + in.Role, Value: mv, Description: ptr(descCycle), UpdatedBy: &a.UserID}); err != nil {
			return err
		}
		changes := audit.Changes{}
		if before.periodMonths(in.Role) != months {
			changes["period_months"] = audit.Change{Old: before.periodMonths(in.Role), New: months}
		}
		if fund != nil {
			fv, _ := json.Marshal(*fund)
			if err := q.UpsertSetting(ctx, kpidb.UpsertSettingParams{Key: keyGradeFund + in.Role, Value: fv, Description: ptr(descGradeFund), UpdatedBy: &a.UserID}); err != nil {
				return err
			}
			old := before.gradeFund(in.Role)
			if deref(old) != float64(*fund) {
				changes["grade_fund"] = audit.Change{Old: old, New: *fund}
			}
		}
		if len(changes) == 0 {
			return nil
		}
		return audit.Record(ctx, tx, audit.Entry{ActorID: &a.UserID, Action: "kpi.criteria.cycle",
			SubjectType: "kpi_setting", SubjectID: keyCycle + in.Role, SubjectLabel: "Chu kỳ chấm KPI · " + in.Role,
			Description: "Đổi chu kỳ chấm KPI / thưởng theo xếp loại", Changes: changes})
	})
	if err != nil {
		return "", err
	}
	lbl, _ := label(Cycles, strconv.Itoa(months))
	return "Đã đổi chu kỳ chấm KPI: " + strings.ToLower(lbl) + ".", nil
}
