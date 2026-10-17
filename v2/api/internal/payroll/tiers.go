package payroll

import (
	"context"
	"fmt"
	"strconv"
	"strings"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/payroll/payrolldb"
)

type TierRow struct {
	ID             uuid.UUID `json:"id"`
	TierName       string    `json:"tier_name"`
	MinStudents    int32     `json:"min_students"`
	MaxStudents    *int32    `json:"max_students"`
	NewSalePercent float64   `json:"new_sale_percent"`
	PercentLabel   string    `json:"percent_label"`
	Range          string    `json:"range"`
	EffectiveFrom  string    `json:"effective_from"`
	EffectiveDate  *string   `json:"effective_date"`
	EffectiveTo    string    `json:"effective_to"`
	Current        bool      `json:"current"`
	Creator        string    `json:"creator,omitempty"`
}

type TiersPage struct {
	AsOf           map[string]string `json:"as_of"`
	Tiers          []TierRow         `json:"tiers"`
	History        []TierRow         `json:"history"`
	Paging         Paging            `json:"paging"`
	GateDays       int               `json:"gate_days"`
	GateMilestones int               `json:"gate_milestones"`
	Today          string            `json:"today"`
	Tomorrow       string            `json:"tomorrow"`
	Renewal        []RenewalRow      `json:"renewal_rows"`
	RenewalBeyond  float64           `json:"renewal_beyond"`
}

func pctLabel(v float64) string { return percentLabel(v) + "%" }

func tierRow(t payrolldb.HrCommissionTier) TierRow {
	r := TierRow{ID: t.ID, TierName: t.TierName, MinStudents: t.MinStudents, MaxStudents: t.MaxStudents, NewSalePercent: t.NewSalePercent,
		PercentLabel: pctLabel(t.NewSalePercent), Range: rangeLabel(t), EffectiveFrom: "Từ đầu", EffectiveTo: "nay", Current: t.EffectiveTo == nil}
	if t.EffectiveFrom != nil {
		r.EffectiveFrom = dmy(*t.EffectiveFrom)
		r.EffectiveDate = ptr(asDay(*t.EffectiveFrom).Format("2006-01-02"))
	}
	if t.EffectiveTo != nil {
		r.EffectiveTo = dmy(*t.EffectiveTo)
	}
	return r
}

func (s *Service) TiersPage(ctx context.Context, asOf string, page int) (*TiersPage, error) {
	day := s.today()
	if d, err := parseDayParam(asOf); err == nil && d != nil {
		day = asDay(*d)
	}
	dbd := dbDay(day)
	tiers, err := s.q.TiersAt(ctx, &dbd)
	if err != nil {
		return nil, err
	}
	page = pageOf(page)
	const per = 15
	hist, err := s.q.TierHistory(ctx, payrolldb.TierHistoryParams{Lim: per, Off: int32((page - 1) * per)})
	if err != nil {
		return nil, err
	}
	total, err := s.q.CountTiers(ctx)
	if err != nil {
		return nil, err
	}
	st, err := s.settings(ctx, s.q)
	if err != nil {
		return nil, err
	}
	today := s.today()
	out := &TiersPage{AsOf: map[string]string{"label": day.Format("02/01/2006"), "date": day.Format("2006-01-02")},
		Tiers: []TierRow{}, History: []TierRow{}, Paging: paging(page, per, total), GateDays: gateDays, GateMilestones: gateMilestones,
		Today: today.Format("2006-01-02"), Tomorrow: today.AddDate(0, 0, 1).Format("2006-01-02"), Renewal: st.Renewal,
		RenewalBeyond: st.RenewalBeyond}
	for _, t := range tiers {
		out.Tiers = append(out.Tiers, tierRow(t))
	}
	for _, h := range hist {
		r := tierRow(payrolldb.HrCommissionTier{ID: h.ID, TierName: h.TierName, MinStudents: h.MinStudents, MaxStudents: h.MaxStudents,
			NewSalePercent: h.NewSalePercent, EffectiveFrom: h.EffectiveFrom, EffectiveTo: h.EffectiveTo})
		r.Creator = "—"
		if h.CreatorName != nil {
			r.Creator = *h.CreatorName
		}
		out.History = append(out.History, r)
	}
	return out, nil
}

// TierInput: form mốc hoa hồng theo thứ tự HS chốt.
type TierInput struct {
	TierName       *string  `json:"tier_name"`
	MinStudents    *int     `json:"min_students"`
	MaxStudents    *int     `json:"max_students"`
	NewSalePercent *float64 `json:"new_sale_percent"`
	EffectiveFrom  string   `json:"effective_from"`
}

func (in TierInput) validate() error {
	if in.TierName != nil && runeLen(*in.TierName) > 255 {
		return iam.Invalid("Tên mốc tối đa 255 ký tự.")
	}
	if in.MinStudents == nil {
		return iam.Invalid("Vui lòng nhập số HS bắt đầu của mốc.")
	}
	if *in.MinStudents < 0 {
		return iam.Invalid("Số HS bắt đầu không được âm.")
	}
	if in.MaxStudents != nil && *in.MaxStudents < *in.MinStudents {
		return iam.Invalid("Số HS kết thúc phải lớn hơn hoặc bằng số HS bắt đầu.")
	}
	if in.NewSalePercent == nil {
		return iam.Invalid("Vui lòng nhập % hoa hồng.")
	}
	if *in.NewSalePercent < 0 || *in.NewSalePercent > 100 {
		return iam.Invalid("% hoa hồng phải từ 0 đến 100.")
	}
	return nil
}

// name: bỏ trống thì đặt theo ngưỡng số HS.
func (in TierInput) name() string {
	if in.TierName != nil && strings.TrimSpace(*in.TierName) != "" {
		return strings.TrimSpace(*in.TierName)
	}
	from := max(1, *in.MinStudents)
	if in.MaxStudents != nil {
		return "Mốc HS thứ " + strconv.Itoa(from) + "–" + strconv.Itoa(*in.MaxStudents)
	}
	return "Mốc từ HS thứ " + strconv.Itoa(from)
}

func i32p(p *int) *int32 {
	if p == nil {
		return nil
	}
	v := int32(*p)
	return &v
}

func tierMap(t payrolldb.HrCommissionTier) map[string]any {
	m := map[string]any{"tier_name": t.TierName, "min_students": t.MinStudents, "max_students": t.MaxStudents,
		"new_sale_percent": t.NewSalePercent, "effective_from": nil}
	if t.EffectiveFrom != nil {
		m["effective_from"] = dmy(*t.EffectiveFrom)
	}
	return m
}

func (s *Service) CreateTier(ctx context.Context, a *iam.Access, in TierInput) (string, error) {
	if err := in.validate(); err != nil {
		return "", err
	}
	from, err := parseDayParam(in.EffectiveFrom)
	if err != nil {
		return "", err
	}
	if from == nil {
		d := dbDay(s.today())
		from = &d
	}
	var msg string
	err = s.tx(ctx, func(tx pgxTx, q *payrolldb.Queries) error {
		t, err := q.InsertTier(ctx, payrolldb.InsertTierParams{TierName: in.name(), MinStudents: int32(*in.MinStudents),
			MaxStudents: i32p(in.MaxStudents), NewSalePercent: *in.NewSalePercent, EffectiveFrom: from, CreatedBy: actorPtr(a)})
		if err != nil {
			return err
		}
		msg = "Đã lưu mốc hoa hồng mới, hiệu lực từ " + dmy(*from) + "!"
		return audit.Record(ctx, tx, audit.Entry{ActorID: actorPtr(a), Module: audit.ModulePayroll, Event: audit.EventCreated,
			Action: "commission_tier.create", SubjectType: "commission_tier", SubjectID: t.ID.String(), SubjectLabel: t.TierName,
			Description: "Tạo mới bậc hoa hồng: " + t.TierName, Properties: tierMap(t)})
	})
	if err != nil {
		return "", err
	}
	s.log.Info("commission tier created", "by", a.UserID)
	return msg, nil
}

// UpdateTier: sửa mốc = tạo phiên bản mới từ ngày chọn và đóng phiên bản cũ vào hôm trước (kỳ cũ giữ mốc cũ);
// phiên bản chưa tới ngày hiệu lực thì sửa trực tiếp.
func (s *Service) UpdateTier(ctx context.Context, a *iam.Access, id uuid.UUID, in TierInput) (string, error) {
	if err := in.validate(); err != nil {
		return "", err
	}
	from, err := parseDayParam(in.EffectiveFrom)
	if err != nil {
		return "", err
	}
	today := s.today()
	if from == nil {
		d := dbDay(today)
		from = &d
	}
	var msg string
	err = s.tx(ctx, func(tx pgxTx, q *payrolldb.Queries) error {
		cur, err := q.LockTier(ctx, id)
		if isNoRows(err) {
			return errTierNotFound
		}
		if err != nil {
			return err
		}
		if cur.EffectiveTo != nil {
			return iam.Invalid("Phiên bản này đã hết hiệu lực — hãy sửa phiên bản đang hiệu lực.")
		}
		var version payrolldb.HrCommissionTier
		if cur.EffectiveFrom != nil && asDay(*cur.EffectiveFrom).After(today) && asDay(*cur.EffectiveFrom).Equal(asDay(*from)) {
			version, err = q.UpdateTierInPlace(ctx, payrolldb.UpdateTierInPlaceParams{ID: id, TierName: in.name(),
				MinStudents: int32(*in.MinStudents), MaxStudents: i32p(in.MaxStudents), NewSalePercent: *in.NewSalePercent, EffectiveFrom: from})
			if err != nil {
				return err
			}
		} else {
			if cur.EffectiveFrom != nil && !asDay(*from).After(asDay(*cur.EffectiveFrom)) {
				return iam.Invalid("Ngày hiệu lực mới phải sau ngày hiệu lực của phiên bản hiện tại (" + dmy(*cur.EffectiveFrom) + ").")
			}
			to := dbDay(asDay(*from).AddDate(0, 0, -1))
			if err := q.CloseTier(ctx, payrolldb.CloseTierParams{ID: id, EffectiveTo: &to}); err != nil {
				return err
			}
			version, err = q.InsertTier(ctx, payrolldb.InsertTierParams{TierName: in.name(), MinStudents: int32(*in.MinStudents),
				MaxStudents: i32p(in.MaxStudents), NewSalePercent: *in.NewSalePercent, EffectiveFrom: from, ReplacesID: &id, CreatedBy: actorPtr(a)})
			if err != nil {
				return err
			}
		}
		msg = "Đã tạo phiên bản mới của mốc hoa hồng, hiệu lực từ " + dmy(*from) + " — các kỳ trước giữ nguyên mốc cũ."
		return audit.Record(ctx, tx, audit.Entry{ActorID: actorPtr(a), Module: audit.ModulePayroll, Action: "commission_tier.update",
			SubjectType: "commission_tier", SubjectID: version.ID.String(), SubjectLabel: version.TierName,
			Description: fmt.Sprintf("Cập nhật bậc hoa hồng: %s (phiên bản mới hiệu lực từ %s)", version.TierName, dmy(*from)),
			Changes:     audit.Diff(tierMap(cur), tierMap(version))})
	})
	if err != nil {
		return "", err
	}
	s.log.Info("commission tier updated", "by", a.UserID, "tier", id)
	return msg, nil
}

// RetireTier: ngừng áp dụng (đóng hiệu lực từ hôm nay, giữ lịch sử); phiên bản chưa từng hiệu lực thì xóa hẳn.
func (s *Service) RetireTier(ctx context.Context, a *iam.Access, id uuid.UUID) (string, error) {
	today := s.today()
	var msg string
	err := s.tx(ctx, func(tx pgxTx, q *payrolldb.Queries) error {
		t, err := q.LockTier(ctx, id)
		if isNoRows(err) {
			return errTierNotFound
		}
		if err != nil {
			return err
		}
		if t.EffectiveFrom != nil && asDay(*t.EffectiveFrom).After(today) {
			if err := q.DeleteTier(ctx, id); err != nil {
				return err
			}
			msg = "Đã xoá mốc hoa hồng chưa có hiệu lực: " + t.TierName
		} else {
			to := dbDay(today.AddDate(0, 0, -1))
			if err := q.CloseTier(ctx, payrolldb.CloseTierParams{ID: id, EffectiveTo: &to}); err != nil {
				return err
			}
			msg = "Đã ngừng áp dụng mốc hoa hồng " + t.TierName + " từ hôm nay (giữ lại lịch sử)."
		}
		return audit.Record(ctx, tx, audit.Entry{ActorID: actorPtr(a), Module: audit.ModulePayroll, Event: audit.EventDeleted,
			Action: "commission_tier.retire", SubjectType: "commission_tier", SubjectID: id.String(), SubjectLabel: t.TierName,
			Description: "Ngừng áp dụng bậc hoa hồng: " + t.TierName})
	})
	if err != nil {
		return "", err
	}
	s.log.Info("commission tier retired", "by", a.UserID, "tier", id)
	return msg, nil
}
