package crm

import (
	"context"
	"crypto/rand"
	"errors"
	"math"
	"math/big"
	"strconv"
	"strings"
	"time"
	"unicode"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"
	"golang.org/x/text/unicode/norm"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/crm/crmdb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// Ưu đãi học phí (Laravel PromotionController / Promotion). Danh mục dùng lại khi chốt khách / lập phiếu thu;
// ca đặc biệt tạo "ưu đãi riêng" (bắt buộc lý do, 1 lượt). Không xóa, chỉ ngừng áp dụng.

// foldASCII: bỏ dấu tiếng Việt (Str::ascii): "Ưu đãi Hè" → "Uu dai He".
func foldASCII(s string) string {
	var b strings.Builder
	for _, r := range norm.NFD.String(s) {
		switch {
		case unicode.Is(unicode.Mn, r):
		case r == 'đ':
			b.WriteRune('d')
		case r == 'Đ':
			b.WriteRune('D')
		default:
			b.WriteRune(r)
		}
	}
	return b.String()
}

const codeAlphabet = "ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789"

func randomCode(n int) string {
	b := make([]byte, n)
	for i := range b {
		x, _ := rand.Int(rand.Reader, big.NewInt(int64(len(codeAlphabet))))
		b[i] = codeAlphabet[x.Int64()]
	}
	return string(b)
}

// promotionPrefix: 4 ký tự chữ / số đầu của tên (không dấu, viết hoa).
func promotionPrefix(name string) string {
	var b strings.Builder
	for _, r := range foldASCII(name) {
		if r < 128 && (unicode.IsLetter(r) || unicode.IsDigit(r)) {
			b.WriteRune(unicode.ToUpper(r))
			if b.Len() == 4 {
				break
			}
		}
	}
	return b.String()
}

// CalculateDiscount: phần trăm → base×v/100, chặn trần, làm tròn, không quá base; cố định → min(base, v).
func CalculateDiscount(typ string, value float64, maxDiscount *int64, base float64) float64 {
	if typ == "percent" {
		d := base * value / 100
		if maxDiscount != nil && *maxDiscount > 0 && d > float64(*maxDiscount) {
			d = float64(*maxDiscount)
		}
		return math.Round(math.Min(base, d))
	}
	return math.Min(base, value)
}

func promotionApplicable(p crmdb.CrmPromotion, branch, course *uuid.UUID, now time.Time) bool {
	return p.IsActive && (p.StartsAt == nil || !p.StartsAt.After(now)) && (p.EndsAt == nil || !p.EndsAt.Before(now)) &&
		(p.UsageLimit == nil || p.UsedCount < *p.UsageLimit) &&
		(p.BranchID == nil || (branch != nil && *p.BranchID == *branch)) &&
		(p.CourseID == nil || (course != nil && *p.CourseID == *course))
}

// valueLabel: "10%" / "500.000 đ".
func valueLabel(typ string, v float64) string {
	if typ == "percent" {
		s := strconv.FormatFloat(v, 'f', 2, 64)
		s = strings.TrimRight(strings.TrimRight(s, "0"), ".")
		return strings.ReplaceAll(s, ".", ",") + "%"
	}
	return formatMoney(int64(math.Round(v)))
}

func periodLabel(from, to *time.Time) string {
	switch {
	case from != nil && to != nil:
		return fmtDate(*from) + " – " + fmtDate(*to)
	case from != nil:
		return "Từ " + fmtDate(*from)
	case to != nil:
		return "Đến " + fmtDate(*to)
	}
	return "Không thời hạn"
}

// PromotionRow: một dòng danh mục ưu đãi.
type PromotionRow struct {
	ID                uuid.UUID  `json:"id"`
	Code              string     `json:"code"`
	Name              string     `json:"name"`
	Type              string     `json:"type"`
	Value             float64    `json:"value"`
	ValueLabel        string     `json:"value_label"`
	MaxDiscountAmount *int64     `json:"max_discount_amount"`
	BranchID          *uuid.UUID `json:"branch_id"`
	CourseID          *uuid.UUID `json:"course_id"`
	ScopeLabel        string     `json:"scope_label"`
	PeriodLabel       string     `json:"period_label"`
	UsageLabel        string     `json:"usage_label"`
	StartsAt          *time.Time `json:"starts_at"`
	EndsAt            *time.Time `json:"ends_at"`
	UsageLimit        *int32     `json:"usage_limit"`
	UsedCount         int32      `json:"used_count"`
	IsActive          bool       `json:"is_active"`
	IsDefault         bool       `json:"is_default"`
	IsSpecial         bool       `json:"is_special"`
	IsAvailable       bool       `json:"is_available"`
	Description       *string    `json:"description"`
	Reason            *string    `json:"reason"`
	CreatorName       *string    `json:"creator_name"`
	CreatedAt         time.Time  `json:"created_at"`
}

func promotionRow(p crmdb.CrmPromotion, branchName, courseName, creator *string, now time.Time) PromotionRow {
	scopeParts := []string{}
	if branchName != nil {
		scopeParts = append(scopeParts, *branchName)
	}
	if courseName != nil {
		scopeParts = append(scopeParts, *courseName)
	}
	scopeLabel := strings.Join(scopeParts, " · ")
	if scopeLabel == "" {
		scopeLabel = "Mọi cơ sở, mọi khóa"
	}
	usage := strconv.Itoa(int(p.UsedCount))
	if p.UsageLimit != nil {
		usage += " / " + strconv.Itoa(int(*p.UsageLimit))
	}
	return PromotionRow{
		ID: p.ID, Code: p.Code, Name: p.Name, Type: p.Type, Value: p.Value, ValueLabel: valueLabel(p.Type, p.Value),
		MaxDiscountAmount: p.MaxDiscountAmount, BranchID: p.BranchID, CourseID: p.CourseID, ScopeLabel: scopeLabel,
		PeriodLabel: periodLabel(p.StartsAt, p.EndsAt), UsageLabel: usage, StartsAt: p.StartsAt, EndsAt: p.EndsAt,
		UsageLimit: p.UsageLimit, UsedCount: p.UsedCount, IsActive: p.IsActive, IsDefault: p.IsDefault, IsSpecial: p.IsSpecial,
		IsAvailable: promotionApplicable(p, p.BranchID, p.CourseID, now), Description: p.Description, Reason: p.Reason,
		CreatorName: creator, CreatedAt: p.CreatedAt,
	}
}

// PromotionList: màn Ưu đãi.
type PromotionList struct {
	Page[PromotionRow]
	Tab      string           `json:"tab"`
	Counts   map[string]int64 `json:"counts"`
	Branches []Option         `json:"branches"`
}

func (s *Service) ListPromotions(ctx context.Context, tab, status, search string, page int) (*PromotionList, error) {
	if tab != "special" {
		tab = "catalog"
	}
	switch status {
	case "active", "inactive", "default":
	default:
		status = ""
	}
	var q *string
	if t := strings.TrimSpace(search); t != "" {
		q = ptr("%" + escapeLike(t) + "%")
	}
	page, per, lim, off := pageArgs(page, 20, 20)
	rows, err := s.q.ListPromotions(ctx, crmdb.ListPromotionsParams{Special: tab == "special", Q: q, Status: status, Lim: lim, Off: off})
	if err != nil {
		return nil, err
	}
	now := s.now()
	out := &PromotionList{Page: Page[PromotionRow]{Data: make([]PromotionRow, 0, len(rows)), Meta: Meta{Page: page, PerPage: per}}, Tab: tab}
	for _, r := range rows {
		out.Meta.Total = r.Total
		p := crmdb.CrmPromotion{
			ID: r.ID, Code: r.Code, Name: r.Name, Type: r.Type, Value: r.Value, MaxDiscountAmount: r.MaxDiscountAmount,
			Description: r.Description, Reason: r.Reason, BranchID: r.BranchID, CourseID: r.CourseID, StartsAt: r.StartsAt,
			EndsAt: r.EndsAt, UsageLimit: r.UsageLimit, UsedCount: r.UsedCount, IsActive: r.IsActive, IsDefault: r.IsDefault,
			IsSpecial: r.IsSpecial, CreatedBy: r.CreatedBy, CreatedAt: r.CreatedAt, UpdatedAt: r.UpdatedAt,
		}
		out.Data = append(out.Data, promotionRow(p, r.BranchName, r.CourseName, r.CreatorName, now))
	}
	c, err := s.q.CountPromotions(ctx)
	if err != nil {
		return nil, err
	}
	out.Counts = map[string]int64{"catalog": c.Catalog, "special": c.Special}
	branches, err := s.q.ListBranchesBrief(ctx, crmdb.ListBranchesBriefParams{OnlyActive: true})
	if err != nil {
		return nil, err
	}
	out.Branches = []Option{}
	for _, b := range branches {
		out.Branches = append(out.Branches, Option{b.ID.String(), b.Name})
	}
	return out, nil
}

// PromotionInput: form ưu đãi.
type PromotionInput struct {
	Name              string     `json:"name"`
	Type              string     `json:"type"`
	Value             float64    `json:"value"`
	MaxDiscountAmount *int64     `json:"max_discount_amount"`
	Description       *string    `json:"description"`
	Reason            *string    `json:"reason"`
	BranchID          *uuid.UUID `json:"branch_id"`
	CourseID          *uuid.UUID `json:"course_id"`
	StartsAt          *string    `json:"starts_at"`
	EndsAt            *string    `json:"ends_at"`
	UsageLimit        *int32     `json:"usage_limit"`
	IsActive          *bool      `json:"is_active"`
	IsDefault         bool       `json:"is_default"`
	IsSpecial         bool       `json:"is_special"`
}

type promoClean struct {
	name, typ            string
	value                float64
	maxDiscount          *int64
	description, reason  *string
	branch, course       *uuid.UUID
	starts, ends         *time.Time
	usageLimit           *int32
	active, def, special bool
}

func (s *Service) cleanPromotion(ctx context.Context, q *crmdb.Queries, in PromotionInput, special bool, existing *crmdb.CrmPromotion) (*promoClean, error) {
	c := &promoClean{name: strings.TrimSpace(in.Name), typ: in.Type, value: in.Value, special: special}
	if c.name == "" {
		return nil, iam.Invalid("Vui lòng nhập tên ưu đãi.")
	}
	if runeLen(c.name) > 255 {
		return nil, iam.Invalid("Tên ưu đãi không được vượt quá 255 ký tự.")
	}
	if c.typ != "fixed" && c.typ != "percent" {
		return nil, iam.Invalid("Loại ưu đãi không hợp lệ.")
	}
	if !(c.value > 0) || math.IsInf(c.value, 0) {
		return nil, iam.Invalid("Giá trị ưu đãi phải lớn hơn 0.")
	}
	if c.value > 9999999999999 {
		return nil, iam.Invalid("Giá trị ưu đãi quá lớn.")
	}
	if in.MaxDiscountAmount != nil && *in.MaxDiscountAmount < 0 {
		return nil, iam.Invalid("Mức giảm tối đa không được âm.")
	}
	c.description, c.reason = strPtr(deref(in.Description)), strPtr(deref(in.Reason))
	if err := maxLen(c.description, 1000, "Mô tả"); err != nil {
		return nil, err
	}
	if err := maxLen(c.reason, 1000, "Lý do"); err != nil {
		return nil, err
	}
	if special && c.reason == nil {
		return nil, iam.Invalid("Ưu đãi riêng cho ca đặc biệt bắt buộc ghi lý do.")
	}
	if in.BranchID != nil {
		if _, err := q.GetBranchBrief(ctx, *in.BranchID); errors.Is(err, pgx.ErrNoRows) {
			return nil, iam.Invalid("Cơ sở không hợp lệ.")
		} else if err != nil {
			return nil, err
		}
		c.branch = in.BranchID
	}
	if in.CourseID != nil {
		if _, err := q.GetCourseName(ctx, *in.CourseID); errors.Is(err, pgx.ErrNoRows) {
			return nil, iam.Invalid("Khóa học không hợp lệ.")
		} else if err != nil {
			return nil, err
		}
		c.course = in.CourseID
	}
	for _, f := range []struct {
		in  *string
		out **time.Time
		lbl string
	}{{in.StartsAt, &c.starts, "Ngày bắt đầu"}, {in.EndsAt, &c.ends, "Ngày kết thúc"}} {
		if strings.TrimSpace(deref(f.in)) == "" {
			continue
		}
		t, ok := parseDateTime(*f.in)
		if !ok {
			return nil, iam.Invalid(f.lbl + " không hợp lệ.")
		}
		*f.out = &t
	}
	if in.UsageLimit != nil && *in.UsageLimit < 1 {
		return nil, iam.Invalid("Số lượt dùng phải từ 1 trở lên.")
	}
	c.usageLimit = in.UsageLimit
	if c.typ == "percent" && c.value > 100 {
		return nil, iam.Invalid("Ưu đãi phần trăm không được vượt quá 100%.")
	}
	if c.starts != nil && c.ends != nil && !c.ends.After(*c.starts) {
		return nil, iam.Invalid("Ngày kết thúc ưu đãi phải sau ngày bắt đầu.")
	}
	if existing != nil && c.usageLimit != nil && *c.usageLimit < existing.UsedCount {
		return nil, iam.Invalid("Ưu đãi đã dùng " + strconv.Itoa(int(existing.UsedCount)) + " lượt, giới hạn không được nhỏ hơn số này.")
	}
	if c.typ == "percent" {
		c.maxDiscount = in.MaxDiscountAmount
	}
	// Ưu đãi riêng: chỉ cho 1 khách (1 lượt), không thành mặc định.
	if special {
		c.usageLimit = ptr(int32(1))
	}
	c.def = !special && in.IsDefault
	return c, nil
}

func (s *Service) CreatePromotion(ctx context.Context, a *iam.Access, in PromotionInput) (*Saved, error) {
	var out *Saved
	err := s.tx(ctx, func(q *crmdb.Queries) error {
		c, err := s.cleanPromotion(ctx, q, in, in.IsSpecial, nil)
		if err != nil {
			return err
		}
		c.active = in.IsActive == nil || *in.IsActive
		prefix := promotionPrefix(c.name)
		var code string
		for {
			code = "UD" + prefix + randomCode(3)
			taken, err := q.PromotionCodeTaken(ctx, code)
			if err != nil {
				return err
			}
			if !taken {
				break
			}
		}
		p, err := q.CreatePromotion(ctx, crmdb.CreatePromotionParams{
			Code: code, Name: c.name, Type: c.typ, Value: c.value, MaxDiscountAmount: c.maxDiscount, Description: c.description,
			Reason: c.reason, BranchID: c.branch, CourseID: c.course, StartsAt: c.starts, EndsAt: c.ends, UsageLimit: c.usageLimit,
			IsActive: c.active, IsDefault: c.def, IsSpecial: c.special, CreatedBy: &a.UserID,
		})
		if isUniqueViolation(err) {
			return iam.Invalid("Không tạo được ưu đãi do trùng mã, vui lòng thử lại.")
		}
		if err != nil {
			return err
		}
		msg := "Đã tạo mới ưu đãi '" + p.Name + "' thành công!"
		if p.IsSpecial {
			msg = "Đã tạo ưu đãi riêng '" + p.Name + "' cho khách này."
		}
		out = &Saved{ID: p.ID, Message: msg}
		return nil
	})
	if err != nil {
		return nil, err
	}
	s.log.Info("crm: tạo ưu đãi", "promotion", out.ID, "by", a.UserID)
	return out, nil
}

func (s *Service) GetPromotion(ctx context.Context, id uuid.UUID) (*PromotionRow, error) {
	p, err := s.q.GetPromotion(ctx, id)
	if errors.Is(err, pgx.ErrNoRows) {
		return nil, errNotFound
	}
	if err != nil {
		return nil, err
	}
	bn, err := s.branchName(ctx, s.q, p.BranchID)
	if err != nil {
		return nil, err
	}
	cn, err := s.courseName(ctx, s.q, p.CourseID)
	if err != nil {
		return nil, err
	}
	row := promotionRow(p, bn, cn, nil, s.now())
	return &row, nil
}

func (s *Service) UpdatePromotion(ctx context.Context, a *iam.Access, id uuid.UUID, in PromotionInput) (*Saved, error) {
	var out *Saved
	err := s.tx(ctx, func(q *crmdb.Queries) error {
		p, err := q.LockPromotion(ctx, id)
		if errors.Is(err, pgx.ErrNoRows) {
			return errNotFound
		}
		if err != nil {
			return err
		}
		c, err := s.cleanPromotion(ctx, q, in, p.IsSpecial, &p)
		if err != nil {
			return err
		}
		// Không gửi is_active thì giữ nguyên trạng thái (form web luôn gửi ô này).
		c.active = p.IsActive
		if in.IsActive != nil {
			c.active = *in.IsActive
		}
		up, err := q.UpdatePromotion(ctx, crmdb.UpdatePromotionParams{
			ID: p.ID, Name: c.name, Type: c.typ, Value: c.value, MaxDiscountAmount: c.maxDiscount, Description: c.description,
			Reason: c.reason, BranchID: c.branch, CourseID: c.course, StartsAt: c.starts, EndsAt: c.ends, UsageLimit: c.usageLimit,
			IsActive: c.active, IsDefault: c.def,
		})
		if err != nil {
			return err
		}
		out = &Saved{ID: up.ID, Message: "Đã cập nhật ưu đãi '" + up.Name + "'."}
		return nil
	})
	if err != nil {
		return nil, err
	}
	s.log.Info("crm: sửa ưu đãi", "promotion", id, "by", a.UserID)
	return out, nil
}

func (s *Service) TogglePromotion(ctx context.Context, a *iam.Access, id uuid.UUID) (*Saved, error) {
	p, err := s.q.TogglePromotion(ctx, id)
	if errors.Is(err, pgx.ErrNoRows) {
		return nil, errNotFound
	}
	if err != nil {
		return nil, err
	}
	s.log.Info("crm: bật / ngừng ưu đãi", "promotion", id, "active", p.IsActive, "by", a.UserID)
	if p.IsActive {
		return &Saved{ID: p.ID, Message: "Đã bật lại ưu đãi '" + p.Name + "'."}, nil
	}
	return &Saved{ID: p.ID, Message: "Đã ngừng áp dụng ưu đãi '" + p.Name + "'. Hợp đồng đã dùng ưu đãi này không đổi."}, nil
}

// courseName: tên khóa học của ưu đãi (nil nếu không gắn khóa / khóa đã xóa).
func (s *Service) courseName(ctx context.Context, q *crmdb.Queries, id *uuid.UUID) (*string, error) {
	if id == nil {
		return nil, nil
	}
	n, err := q.GetCourseName(ctx, *id)
	if errors.Is(err, pgx.ErrNoRows) {
		return nil, nil
	}
	return &n, err
}
