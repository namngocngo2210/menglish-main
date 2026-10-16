package catalogs

import (
	"context"
	_ "embed"
	"encoding/json"
	"fmt"
	"regexp"
	"strconv"
	"strings"
	"sync"

	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgxpool"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/catalogs/catalogsdb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// Cấu hình SLA (SystemConfigController@sla / updateSla / updateSlaSettings, App\Services\Sla\Sla).
// Mặc định lấy từ config/sla.php của Laravel (sla/sla.json), đồng bộ xuống hr.sla_rules; Admin ghi đè từng SLA.

//go:embed sla/sla.json
var slaJSON []byte

type slaDefault struct {
	Key         string  `json:"key"`
	Group       string  `json:"group"`
	Label       string  `json:"label"`
	Description string  `json:"description"`
	Unit        string  `json:"unit"`
	Value       int32   `json:"value"`
	Penalty     *bool   `json:"penalty"`
	Amount      int64   `json:"amount"`
	Ladder      []int64 `json:"ladder"`
	AmountLabel *string `json:"amount_label"`
	AmountPer   *string `json:"amount_per"`
	Task        bool    `json:"task"`
	Switchable  bool    `json:"switchable"`
	Category    string  `json:"category"`
	Violation   string  `json:"violation"`
}

type slaCatalog struct {
	Groups []struct {
		Key   string `json:"key"`
		Label string `json:"label"`
	} `json:"groups"`
	LadderResetMonths int          `json:"ladder_reset_months"`
	Rules             []slaDefault `json:"rules"`
}

var loadSLA = sync.OnceValue(func() *slaCatalog {
	var c slaCatalog
	if err := json.Unmarshal(slaJSON, &c); err != nil {
		panic("catalogs: sla.json hỏng: " + err.Error())
	}
	return &c
})

func slaGroupLabel(key string) string {
	for _, g := range loadSLA().Groups {
		if g.Key == key {
			return g.Label
		}
	}
	return key
}

// SyncSLA đồng bộ danh mục SLA mặc định xuống hr.sla_rules (chạy sau mỗi lần migrate): thêm / cập nhật cột default_*,
// giữ nguyên giá trị Admin đã đổi, xóa SLA không còn trong danh mục.
func SyncSLA(ctx context.Context, pool *pgxpool.Pool) (int, error) {
	c := loadSLA()
	err := pgx.BeginFunc(ctx, pool, func(tx pgx.Tx) error {
		q := catalogsdb.New(tx)
		if err := q.LockKey(ctx, "hr.sla_rules.sync"); err != nil {
			return err
		}
		keys := make([]string, 0, len(c.Rules))
		for i, r := range c.Rules {
			keys = append(keys, r.Key)
			var ladder []byte
			if len(r.Ladder) > 0 {
				ladder, _ = json.Marshal(r.Ladder)
			}
			if err := q.UpsertSlaDefault(ctx, catalogsdb.UpsertSlaDefaultParams{
				Key: r.Key, GroupKey: r.Group, Label: r.Label, Description: r.Description, Unit: r.Unit,
				DefaultValue: r.Value, DefaultPenalty: r.Penalty, DefaultAmount: r.Amount, DefaultLadder: ladder,
				AmountLabel: r.AmountLabel, AmountPer: r.AmountPer, Task: r.Task, Switchable: r.Switchable,
				Category: r.Category, Violation: r.Violation, Sort: int32(i),
			}); err != nil {
				return fmt.Errorf("SLA %s: %w", r.Key, err)
			}
		}
		_, err := q.DeleteSlaRulesExcept(ctx, keys)
		return err
	})
	return len(c.Rules), err
}

// SlaRule là cấu hình hiệu lực của một SLA (mặc định + ghi đè), như Sla::rule của Laravel.
type SlaRule struct {
	Key         string  `json:"key"`
	Group       string  `json:"group"`
	GroupLabel  string  `json:"group_label"`
	Label       string  `json:"label"`
	Description string  `json:"description"`
	Unit        string  `json:"unit"`
	Value       int32   `json:"value"`
	Min         int32   `json:"min"`
	Max         int32   `json:"max"`
	Switchable  bool    `json:"switchable"`
	Enabled     bool    `json:"enabled"`
	HasPenalty  bool    `json:"has_penalty"`
	Penalty     bool    `json:"penalty"`
	Amount      int64   `json:"amount"`
	AmountLabel string  `json:"amount_label"`
	AmountPer   *string `json:"amount_per"`
	Task        bool    `json:"task"`
	Ladder      []int64 `json:"ladder"`
	HasLadder   bool    `json:"has_ladder"`
	Customized  bool    `json:"customized"`
	Category    string  `json:"category"`
	Violation   string  `json:"violation"`
	// Hiển thị giá trị mặc định kèm đơn vị: "24 giờ", "19:00"...
	DefaultLabel string `json:"default_label"`
}

// slaBounds: giới hạn hợp lệ [min, max] theo đơn vị.
func slaBounds(unit string) (int32, int32) {
	switch unit {
	case "hours":
		return 1, 720
	case "days":
		return 1, 90
	case "time":
		return 0, 1439
	case "day_of_month":
		return 1, 28
	}
	return 1, 20
}

// MinutesToTime: số phút từ 00:00 → "HH:MM".
func MinutesToTime(m int32) string {
	m = max(0, min(1439, m))
	return fmt.Sprintf("%02d:%02d", m/60, m%60)
}

// FormatSlaValue: "24 giờ", "3 ngày", "3 lần", "19:00", "ngày 5".
func FormatSlaValue(v int32, unit string) string {
	switch unit {
	case "hours":
		return fmt.Sprintf("%d giờ", v)
	case "days":
		return fmt.Sprintf("%d ngày", v)
	case "time":
		return MinutesToTime(v)
	case "day_of_month":
		return fmt.Sprintf("ngày %d", v)
	}
	return fmt.Sprintf("%d lần", v)
}

func ladderOf(b []byte) []int64 {
	if len(b) == 0 {
		return nil
	}
	var out []int64
	_ = json.Unmarshal(b, &out)
	if len(out) == 0 {
		return nil
	}
	return out
}

func effectiveRule(r catalogsdb.HrSlaRule) SlaRule {
	lo, hi := slaBounds(r.Unit)
	out := SlaRule{
		Key: r.Key, Group: r.GroupKey, GroupLabel: slaGroupLabel(r.GroupKey), Label: r.Label, Description: r.Description,
		Unit: r.Unit, Value: r.DefaultValue, Min: lo, Max: hi, Switchable: r.Switchable, Enabled: true,
		HasPenalty: r.DefaultPenalty != nil, Amount: r.DefaultAmount, AmountLabel: "Mức phạt gợi ý", AmountPer: r.AmountPer,
		Task: r.Task, Ladder: ladderOf(r.DefaultLadder), Category: r.Category, Violation: r.Violation,
		Customized:   r.UpdatedAt != nil,
		DefaultLabel: FormatSlaValue(r.DefaultValue, r.Unit),
	}
	out.HasLadder = len(out.Ladder) > 0
	if r.AmountLabel != nil {
		out.AmountLabel = *r.AmountLabel
	}
	if r.Value != nil {
		out.Value = *r.Value
	}
	if r.Switchable && r.Enabled != nil {
		out.Enabled = *r.Enabled
	}
	// penalty mặc định NULL = SLA không có biên bản: giữ "không phạt" dù có ghi đè.
	if r.DefaultPenalty != nil {
		out.Penalty = *r.DefaultPenalty
		if r.Penalty != nil {
			out.Penalty = *r.Penalty
		}
	}
	if r.Amount != nil {
		out.Amount = *r.Amount
	}
	if l := ladderOf(r.Ladder); l != nil {
		out.Ladder = l
	}
	return out
}

// SlaRule trả cấu hình hiệu lực của một SLA (module khác dùng: CRM, giáo viên, Big Test...).
func (s *Service) SlaRule(ctx context.Context, key string) (SlaRule, error) {
	r, err := s.q.GetSlaRule(ctx, key)
	if isNoRows(err) {
		return SlaRule{}, notFound("Không tìm thấy SLA.")
	}
	if err != nil {
		return SlaRule{}, err
	}
	return effectiveRule(r), nil
}

const slaResetKey = "sla.ladder_reset_months"

type SlaConfig struct {
	Rules       []SlaRule `json:"rules"`
	ResetMonths int       `json:"reset_months"`
}

func (s *Service) SlaConfig(ctx context.Context) (SlaConfig, error) {
	rows, err := s.q.ListSlaRules(ctx)
	if err != nil {
		return SlaConfig{}, err
	}
	out := SlaConfig{Rules: make([]SlaRule, 0, len(rows))}
	for _, r := range rows {
		out.Rules = append(out.Rules, effectiveRule(r))
	}
	out.ResetMonths, err = s.LadderResetMonths(ctx)
	return out, err
}

// LadderResetMonths: số tháng cộng dồn lần tái phạm cho bậc phạt.
func (s *Service) LadderResetMonths(ctx context.Context) (int, error) {
	n := loadSLA().LadderResetMonths
	vals, err := s.settings(ctx, slaResetKey)
	if err != nil {
		return 0, err
	}
	if v, ok := vals[slaResetKey]; ok {
		var stored int
		if json.Unmarshal(v, &stored) == nil && stored >= 1 {
			n = stored
		}
	}
	return max(1, n), nil
}

type SlaInput struct {
	// Số (giờ / ngày / lần / ngày trong tháng) hoặc "HH:MM" với đơn vị time
	Value   json.RawMessage `json:"value"`
	Enabled *bool           `json:"enabled"`
	Penalty *bool           `json:"penalty"`
	Amount  *int64          `json:"amount"`
	Ladder  string          `json:"ladder"` // "0, 30000, 60000"; trống = dùng mặc định
}

var ladderRe = regexp.MustCompile(`^\s*\d+(\s*,\s*\d+){0,9}\s*$`)
var timeRe = regexp.MustCompile(`^([01]\d|2[0-3]):[0-5]\d$`)

func (s *Service) UpdateSla(ctx context.Context, actor *iam.Access, key string, in SlaInput) (string, error) {
	r, err := s.q.GetSlaRule(ctx, key)
	if isNoRows(err) {
		return "", notFound("Không tìm thấy SLA.")
	}
	if err != nil {
		return "", err
	}
	lo, hi := slaBounds(r.Unit)
	raw := strings.TrimSpace(string(in.Value))
	if raw == "" || raw == "null" || raw == `""` {
		return "", iam.Invalid("Vui lòng nhập ngưỡng SLA.")
	}
	var value int32
	if r.Unit == "time" {
		var hm string
		if json.Unmarshal(in.Value, &hm) != nil || !timeRe.MatchString(hm) {
			return "", iam.Invalid("Giờ không hợp lệ (định dạng HH:MM).")
		}
		h, _ := strconv.Atoi(hm[:2])
		m, _ := strconv.Atoi(hm[3:])
		value = int32(h*60 + m)
	} else {
		var n json.Number
		dec := json.NewDecoder(strings.NewReader(raw))
		dec.UseNumber()
		if dec.Decode(&n) != nil {
			var str string
			if json.Unmarshal(in.Value, &str) != nil {
				return "", iam.Invalid("Ngưỡng SLA phải là số nguyên.")
			}
			n = json.Number(strings.TrimSpace(str))
		}
		v, err := strconv.ParseInt(string(n), 10, 32)
		if err != nil {
			return "", iam.Invalid("Ngưỡng SLA phải là số nguyên.")
		}
		value = int32(v)
		if value < lo {
			return "", iam.Invalid("Ngưỡng SLA tối thiểu là " + FormatSlaValue(lo, r.Unit) + ".")
		}
		if value > hi {
			return "", iam.Invalid("Ngưỡng SLA tối đa là " + FormatSlaValue(hi, r.Unit) + ".")
		}
	}
	if in.Amount != nil && (*in.Amount < 0 || *in.Amount > 100_000_000) {
		return "", iam.Invalid("Mức phạt phải từ 0 đến 100.000.000 đ.")
	}
	var ladder []byte
	if l := strings.TrimSpace(in.Ladder); l != "" {
		if len(l) > 200 || !ladderRe.MatchString(l) {
			return "", iam.Invalid("Bậc phạt nhập các số tiền cách nhau bởi dấu phẩy, ví dụ: 0, 30000, 60000.")
		}
		var nums []int64
		for _, p := range strings.Split(l, ",") {
			n, _ := strconv.ParseInt(strings.TrimSpace(p), 10, 64)
			nums = append(nums, n)
		}
		ladder, _ = json.Marshal(nums)
	}
	p := catalogsdb.SetSlaOverrideParams{Key: key, Value: &value, Ladder: ladder, UpdatedBy: &actor.UserID}
	// SLA luôn áp dụng / không có biên bản: không lưu bật tắt tương ứng.
	if r.Switchable {
		v := in.Enabled != nil && *in.Enabled
		p.Enabled = &v
	}
	if r.DefaultPenalty != nil {
		v := in.Penalty != nil && *in.Penalty
		amount := deref(in.Amount)
		p.Penalty, p.Amount = &v, &amount
	}
	if err := s.q.SetSlaOverride(ctx, p); err != nil {
		return "", err
	}
	s.log.Info("sla updated", "key", key, "value", value, "by", actor.UserID)
	return fmt.Sprintf("Đã lưu cấu hình SLA \"%s\". Áp dụng cho mốc phát sinh từ bây giờ.", r.Label), nil
}

func (s *Service) UpdateSlaSettings(ctx context.Context, actor *iam.Access, months *int) (string, error) {
	if months == nil {
		return "", iam.Invalid("Vui lòng nhập số tháng cộng dồn lần tái phạm.")
	}
	if *months < 1 || *months > 60 {
		return "", iam.Invalid("Số tháng cộng dồn phải từ 1 đến 60.")
	}
	if err := s.putSetting(ctx, s.q, actor, slaResetKey, *months, "Số tháng cộng dồn lần tái phạm cho bậc phạt SLA."); err != nil {
		return "", err
	}
	s.log.Info("sla settings updated", "ladder_reset_months", *months, "by", actor.UserID)
	return "Đã lưu thời gian cộng dồn lần tái phạm.", nil
}
