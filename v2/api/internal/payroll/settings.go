package payroll

import (
	"context"
	"encoding/json"
	"fmt"
	"slices"
	"sort"
	"strconv"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/payroll/payrolldb"
)

// Khóa core.settings (SystemSetting tiền tố payroll_ của Laravel).
const (
	keyInsurance     = "payroll_insurance_rate_percent"
	keyUnion         = "payroll_union_rate_percent"
	keyFund          = "payroll_academic_kpi_fund"
	keyRenewal       = "payroll_renewal_table"
	keyRenewalBeyond = "payroll_renewal_beyond_percent"
	keyLateThreshold = "payroll_late_threshold_minutes"
	keyLatePerMinute = "payroll_late_deduction_per_minute"
	keyTeachingShare = "payroll_teaching_share_percent"
)

var settingKeys = []string{keyInsurance, keyUnion, keyFund, keyRenewal, keyRenewalBeyond, keyLateThreshold, keyLatePerMinute, keyTeachingShare}

// Mặc định theo config/payroll.php.
const (
	defInsurance     = 10.5
	defUnion         = 0.5
	defFund          = 2000000
	defLateThreshold = 15
	defLatePerMinute = 5000
	defTeachingShare = 40
	latePerMinuteMin = 4000
	latePerMinuteMax = 5000
	gateDays         = 30
	gateMilestones   = 3
)

// retentionTiers: bậc KPI giữ học sinh (đ / HS / tháng), Admin chọn tay cho từng GV từng kỳ.
var retentionTiers = []int64{15000, 20000, 25000}

// RenewalRow: một mốc thưởng tái tục theo số HS nghỉ.
type RenewalRow struct {
	Quits   int     `json:"quits"`
	Percent float64 `json:"percent"`
	Pending bool    `json:"pending"`
}

func defaultRenewal() []RenewalRow {
	return []RenewalRow{{0, 1.0, false}, {1, 0.7, false}, {2, 0, true}, {3, 0, true}}
}

// Settings: tham số tính lương (PayrollPeriod::payrollSettings).
type Settings struct {
	InsuranceRate  float64      `json:"insurance_rate_percent"`
	UnionRate      float64      `json:"union_rate_percent"`
	AcademicFund   int64        `json:"academic_kpi_fund"`
	RetentionTiers []int64      `json:"retention_tiers"`
	Renewal        []RenewalRow `json:"renewal"`
	RenewalBeyond  float64      `json:"renewal_beyond_percent"`
	LateThreshold  int          `json:"late_threshold_minutes"`
	LatePerMinute  int64        `json:"late_deduction_per_minute"`
	TeachingShare  float64      `json:"teaching_share_percent"`
}

func numberOf(raw []byte) (float64, bool) {
	var v any
	if json.Unmarshal(raw, &v) != nil {
		return 0, false
	}
	switch x := v.(type) {
	case float64:
		return x, true
	case string:
		f, err := strconv.ParseFloat(x, 64)
		return f, err == nil
	}
	return 0, false
}

// parseRenewal đọc bảng tái tục {"0": {"percent": 1, "pending": false}} hoặc {"0": 1} (dữ liệu cũ) hoặc mảng RenewalRow.
func parseRenewal(raw []byte) ([]RenewalRow, bool) {
	var rows []RenewalRow
	if json.Unmarshal(raw, &rows) == nil && rows != nil {
		return sortRenewal(rows), true
	}
	var m map[string]json.RawMessage
	if json.Unmarshal(raw, &m) != nil || m == nil {
		return nil, false
	}
	for k, v := range m {
		q, err := strconv.Atoi(k)
		if err != nil {
			continue
		}
		row := RenewalRow{Quits: q}
		var obj struct {
			Percent any  `json:"percent"`
			Pending bool `json:"pending"`
		}
		if json.Unmarshal(v, &obj) == nil && obj.Percent != nil {
			if f, ok := numberOf(mustJSON(obj.Percent)); ok {
				row.Percent = f
			}
			row.Pending = obj.Pending
		} else if f, ok := numberOf(v); ok {
			row.Percent = f
		}
		rows = append(rows, row)
	}
	return sortRenewal(rows), true
}

func sortRenewal(rows []RenewalRow) []RenewalRow {
	sort.Slice(rows, func(i, j int) bool { return rows[i].Quits < rows[j].Quits })
	return rows
}

func mustJSON(v any) []byte {
	b, _ := json.Marshal(v)
	return b
}

// renewalJSON: lưu theo dạng Laravel {"số nghỉ": {"percent", "pending"}}.
func renewalJSON(rows []RenewalRow) []byte {
	m := map[string]any{}
	for _, r := range rows {
		m[strconv.Itoa(r.Quits)] = map[string]any{"percent": r.Percent, "pending": r.Pending}
	}
	return mustJSON(m)
}

func (s *Service) settings(ctx context.Context, q *payrolldb.Queries) (Settings, error) {
	out := Settings{InsuranceRate: defInsurance, UnionRate: defUnion, AcademicFund: defFund, RetentionTiers: retentionTiers,
		Renewal: defaultRenewal(), LateThreshold: defLateThreshold, LatePerMinute: defLatePerMinute, TeachingShare: defTeachingShare}
	rows, err := q.GetSettings(ctx, settingKeys)
	if err != nil {
		return out, err
	}
	for _, r := range rows {
		if r.Key == keyRenewal {
			if t, ok := parseRenewal(r.Value); ok {
				out.Renewal = t
			}
			continue
		}
		f, ok := numberOf(r.Value)
		if !ok {
			continue
		}
		switch r.Key {
		case keyInsurance:
			out.InsuranceRate = f
		case keyUnion:
			out.UnionRate = f
		case keyFund:
			out.AcademicFund = roundVND(f)
		case keyRenewalBeyond:
			out.RenewalBeyond = f
		case keyLateThreshold:
			out.LateThreshold = int(f)
		case keyLatePerMinute:
			out.LatePerMinute = roundVND(f)
		case keyTeachingShare:
			out.TeachingShare = f
		}
	}
	return out, nil
}

// renewalPercent: % thưởng tái tục theo số HS nghỉ; ngoài bảng → beyond (chờ BA).
func (st Settings) renewalPercent(quits int) (float64, bool) {
	for _, r := range st.Renewal {
		if r.Quits == quits {
			return r.Percent, r.Pending
		}
	}
	return st.RenewalBeyond, true
}

// SettingsPage: màn Tham số tính lương.
type SettingsPage struct {
	Settings Settings `json:"settings"`
	Defaults Settings `json:"defaults"`
}

func (s *Service) SettingsPage(ctx context.Context) (*SettingsPage, error) {
	st, err := s.settings(ctx, s.q)
	if err != nil {
		return nil, err
	}
	return &SettingsPage{Settings: st, Defaults: Settings{InsuranceRate: defInsurance, UnionRate: defUnion, AcademicFund: defFund,
		RetentionTiers: retentionTiers, Renewal: defaultRenewal(), LateThreshold: defLateThreshold, LatePerMinute: defLatePerMinute,
		TeachingShare: defTeachingShare}}, nil
}

// SettingsInput: form Tham số tính lương (storeSettings).
type SettingsInput struct {
	InsuranceRate *float64 `json:"insurance_rate_percent"`
	UnionRate     *float64 `json:"union_rate_percent"`
	AcademicFund  *float64 `json:"academic_kpi_fund"`
	Renewal       []struct {
		Quits   *int     `json:"quits"`
		Percent *float64 `json:"percent"`
		Pending bool     `json:"pending"`
	} `json:"renewal"`
	RenewalBeyond *float64 `json:"renewal_beyond_percent"`
	LateThreshold *int     `json:"late_threshold_minutes"`
	LatePerMinute *float64 `json:"late_deduction_per_minute"`
	TeachingShare *float64 `json:"teaching_share_percent"`
}

const msgRequired = "Vui lòng nhập đầy đủ các tham số."

func between(v *float64, lo, hi float64) bool { return v != nil && *v >= lo && *v <= hi }

func validRenewal(in SettingsInput) ([]RenewalRow, error) {
	rows := []RenewalRow{}
	for _, r := range in.Renewal {
		if r.Quits == nil || r.Percent == nil {
			return nil, iam.Invalid(msgRequired)
		}
		if *r.Quits < 0 || *r.Quits > 50 {
			return nil, iam.Invalid("Số HS nghỉ phải từ 0 đến 50.")
		}
		if *r.Percent < 0 || *r.Percent > 100 {
			return nil, iam.Invalid("% thưởng tái tục phải từ 0 đến 100.")
		}
		if slices.ContainsFunc(rows, func(x RenewalRow) bool { return x.Quits == *r.Quits }) {
			return nil, iam.Invalid("Mỗi mức số HS nghỉ chỉ được khai báo một lần.")
		}
		rows = append(rows, RenewalRow{Quits: *r.Quits, Percent: *r.Percent, Pending: r.Pending})
	}
	return sortRenewal(rows), nil
}

// SaveSettings: lưu tham số tính lương (áp dụng cho các lần tính / tính lại sau).
func (s *Service) SaveSettings(ctx context.Context, a *iam.Access, in SettingsInput) (string, error) {
	if in.InsuranceRate == nil || in.UnionRate == nil || in.AcademicFund == nil {
		return "", iam.Invalid(msgRequired)
	}
	if !between(in.InsuranceRate, 0, 100) || !between(in.UnionRate, 0, 100) {
		return "", iam.Invalid("Tỉ lệ BHXH / Công đoàn phải từ 0 đến 100%.")
	}
	if *in.AcademicFund < 0 {
		return "", iam.Invalid("Quỹ KPI Học vụ không được âm.")
	}
	renewal, err := validRenewal(in)
	if err != nil {
		return "", err
	}
	if in.RenewalBeyond != nil && !between(in.RenewalBeyond, 0, 100) {
		return "", iam.Invalid("% thưởng tái tục ngoài bảng phải từ 0 đến 100.")
	}
	if in.LateThreshold != nil && (*in.LateThreshold < 1 || *in.LateThreshold > 120) {
		return "", iam.Invalid("Ngưỡng phút đi muộn phải từ 1 đến 120.")
	}
	if in.LatePerMinute != nil && !between(in.LatePerMinute, latePerMinuteMin, latePerMinuteMax) {
		return "", iam.Invalid("Đơn giá trừ mỗi phút đi muộn phải từ 4.000đ đến 5.000đ.")
	}
	if in.TeachingShare != nil && !between(in.TeachingShare, 0, 100) {
		return "", iam.Invalid("% học phí kiêm nhiệm giảng dạy phải từ 0 đến 100.")
	}
	err = s.tx(ctx, func(tx pgxTx, q *payrolldb.Queries) error {
		before, err := s.settings(ctx, q)
		if err != nil {
			return err
		}
		put := func(key string, v any) error {
			return q.UpsertSetting(ctx, payrolldb.UpsertSettingParams{Key: key, Value: mustJSON(v), UpdatedBy: actorPtr(a)})
		}
		vals := map[string]any{keyInsurance: *in.InsuranceRate, keyUnion: *in.UnionRate, keyFund: *in.AcademicFund}
		if in.RenewalBeyond != nil {
			vals[keyRenewalBeyond] = *in.RenewalBeyond
		}
		if in.LateThreshold != nil {
			vals[keyLateThreshold] = *in.LateThreshold
		}
		if in.LatePerMinute != nil {
			vals[keyLatePerMinute] = *in.LatePerMinute
		}
		if in.TeachingShare != nil {
			vals[keyTeachingShare] = *in.TeachingShare
		}
		for _, k := range settingKeys {
			if v, ok := vals[k]; ok {
				if err := put(k, v); err != nil {
					return err
				}
			}
		}
		if len(renewal) > 0 {
			if err := q.UpsertSetting(ctx, payrolldb.UpsertSettingParams{Key: keyRenewal, Value: renewalJSON(renewal), UpdatedBy: actorPtr(a)}); err != nil {
				return err
			}
		}
		after, err := s.settings(ctx, q)
		if err != nil {
			return err
		}
		return audit.Record(ctx, tx, audit.Entry{ActorID: actorPtr(a), Module: audit.ModulePayroll, Action: "payroll.settings",
			SubjectType: "payroll_setting", SubjectID: "payroll", SubjectLabel: "Tham số tính lương",
			Description: "Cập nhật tham số tính lương", Changes: audit.Diff(settingsMap(before), settingsMap(after))})
	})
	if err != nil {
		return "", err
	}
	s.log.Info("payroll settings saved", "by", a.UserID)
	return "Đã lưu tham số tính lương — áp dụng cho các lần tính/tính lại kỳ lương sau.", nil
}

func settingsMap(st Settings) map[string]any {
	return map[string]any{
		"insurance_rate_percent": st.InsuranceRate, "union_rate_percent": st.UnionRate, "academic_kpi_fund": st.AcademicFund,
		"renewal_table": fmt.Sprint(st.Renewal), "renewal_beyond_percent": st.RenewalBeyond,
		"late_threshold_minutes": st.LateThreshold, "late_deduction_per_minute": st.LatePerMinute,
		"teaching_share_percent": st.TeachingShare,
	}
}

// RenewalInput: lưu riêng bảng thưởng tái tục (tab Thưởng tái tục của màn Mốc hoa hồng).
type RenewalInput struct {
	Renewal []struct {
		Quits   *int     `json:"quits"`
		Percent *float64 `json:"percent"`
		Pending bool     `json:"pending"`
	} `json:"renewal"`
	RenewalBeyond *float64 `json:"renewal_beyond_percent"`
}

func (s *Service) SaveRenewal(ctx context.Context, a *iam.Access, in RenewalInput) (string, error) {
	if len(in.Renewal) == 0 {
		return "", iam.Invalid("Cần ít nhất một mốc thưởng tái tục.")
	}
	rows, err := validRenewal(SettingsInput{Renewal: in.Renewal})
	if err != nil {
		return "", err
	}
	if in.RenewalBeyond != nil && !between(in.RenewalBeyond, 0, 100) {
		return "", iam.Invalid("% thưởng tái tục ngoài bảng phải từ 0 đến 100.")
	}
	err = s.tx(ctx, func(tx pgxTx, q *payrolldb.Queries) error {
		before, err := s.settings(ctx, q)
		if err != nil {
			return err
		}
		if err := q.UpsertSetting(ctx, payrolldb.UpsertSettingParams{Key: keyRenewal, Value: renewalJSON(rows), UpdatedBy: actorPtr(a)}); err != nil {
			return err
		}
		if in.RenewalBeyond != nil {
			if err := q.UpsertSetting(ctx, payrolldb.UpsertSettingParams{Key: keyRenewalBeyond, Value: mustJSON(*in.RenewalBeyond), UpdatedBy: actorPtr(a)}); err != nil {
				return err
			}
		}
		after, err := s.settings(ctx, q)
		if err != nil {
			return err
		}
		return audit.Record(ctx, tx, audit.Entry{ActorID: actorPtr(a), Module: audit.ModulePayroll, Action: "payroll.renewal",
			SubjectType: "payroll_setting", SubjectID: "renewal", SubjectLabel: "Bảng thưởng tái tục",
			Description: "Cập nhật bảng thưởng tái tục", Changes: audit.Diff(settingsMap(before), settingsMap(after))})
	})
	if err != nil {
		return "", err
	}
	s.log.Info("payroll renewal table saved", "by", a.UserID)
	return "Đã lưu bảng thưởng tái tục — áp dụng cho các lần tính / tính lại kỳ lương sau.", nil
}

// sla: giá trị SLA hiệu lực (hr.sla_rules); chưa đồng bộ danh mục thì dùng mặc định.
func (s *Service) sla(ctx context.Context, q *payrolldb.Queries, key string, def int) int {
	v, err := q.SlaValue(ctx, key)
	if err != nil || v <= 0 {
		return def
	}
	return int(v)
}

func (s *Service) paymentDueDays(ctx context.Context, q *payrolldb.Queries) int {
	return s.sla(ctx, q, "penalty.payment_due", 2)
}
func (s *Service) recordWindowHours(ctx context.Context, q *payrolldb.Queries) int {
	return s.sla(ctx, q, "penalty.record_window", 24)
}
func (s *Service) closeAfterDays(ctx context.Context, q *payrolldb.Queries) int {
	return s.sla(ctx, q, "payroll.close_after", 2)
}
