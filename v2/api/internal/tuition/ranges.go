package tuition

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"regexp"
	"slices"
	"strings"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/tuition/tuitiondb"
)

// Cấu hình dải số hóa đơn (TuitionController::config / updateConfig / storeInvoiceRange / toggleInvoiceRange): dải điện tử
// theo chi nhánh + dải mặc định dùng chung, dải giấy theo cuốn hóa đơn của chi nhánh. Số đã cấp không bao giờ cấp lại:
// dải không chồng lấn dải khác cùng ký hiệu, "số hiện tại" (số kế tiếp) không lùi về số đã cấp.

var seriesRe = regexp.MustCompile(`^[A-Za-z0-9]+$`)

const defaultRangeLabel = "Dải mặc định (dùng chung)"

type RecentInvoice struct {
	InvoiceNumber string `json:"invoice_number"`
	Label         string `json:"label"`
}

type RangeRow struct {
	ID             uuid.UUID       `json:"id"`
	BranchID       *uuid.UUID      `json:"branch_id"`
	BranchName     *string         `json:"branch_name"`
	Kind           string          `json:"kind"`
	KindLabel      string          `json:"kind_label"`
	SeriesCode     string          `json:"series_code"`
	TemplateCode   string          `json:"template_code"`
	Provider       string          `json:"provider"`
	StartNumber    int64           `json:"start_number"`
	EndNumber      *int64          `json:"end_number"`
	CurrentNumber  int64           `json:"current_number"`
	RangeLabel     string          `json:"range_label"`
	CurrentLabel   string          `json:"current_label"`
	MaxIssued      *int64          `json:"max_issued"`
	MaxIssuedLabel *string         `json:"max_issued_label"`
	Remaining      *int64          `json:"remaining"`
	RemainingLabel *string         `json:"remaining_label"`
	Low            bool            `json:"low"`
	IsActive       bool            `json:"is_active"`
	CanManage      bool            `json:"can_manage"`
	Recent         []RecentInvoice `json:"recent"`
}

type ConfigPage struct {
	Ranges           []RangeRow `json:"ranges"`
	Branches         []Option   `json:"branches"`
	CanManage        bool       `json:"can_manage"`
	CanManageDefault bool       `json:"can_manage_default"`
}

func pad(n int64) string { return fmt.Sprintf("%0*d", numberPad, n) }

func rangeBounds(series string, start int64, end *int64) tuitiondb.MaxIssuedInvoiceParams {
	high := int64(9_999_999)
	if end != nil {
		high = *end
	}
	return tuitiondb.MaxIssuedInvoiceParams{Series: series, Low: formatInvoice(series, max(0, start)), High: formatInvoice(series, high)}
}

// maxIssued: số lớn nhất đã cấp của ký hiệu trong khoảng [start, end] (đọc từ phiếu thật, kể cả phiếu đã hủy HĐ).
func maxIssued(ctx context.Context, q *tuitiondb.Queries, series string, start int64, end *int64) (*int64, error) {
	inv, err := q.MaxIssuedInvoice(ctx, rangeBounds(series, start, end))
	if err != nil || inv == "" {
		return nil, err
	}
	if n, ok := invoiceNumberOf(series, inv); ok {
		return &n, nil
	}
	return nil, nil
}

func remaining(r tuitiondb.FinanceInvoiceRange) *int64 {
	if r.EndNumber == nil {
		return nil
	}
	return ptr(max(0, *r.EndNumber-r.CurrentNumber+1))
}

// canManageRange: dải của chi nhánh → chi nhánh trong phạm vi học phí; dải mặc định → invoice_range.manage_default.
func canManageRange(a *iam.Access, branch *uuid.UUID) bool {
	if branch == nil {
		return a.Can("invoice_range.manage_default")
	}
	return covers(a, branch)
}

func rangeOf(r tuitiondb.ListInvoiceRangesRow) tuitiondb.FinanceInvoiceRange {
	return tuitiondb.FinanceInvoiceRange{ID: r.ID, BranchID: r.BranchID, Kind: r.Kind, TemplateCode: r.TemplateCode, SeriesCode: r.SeriesCode,
		StartNumber: r.StartNumber, EndNumber: r.EndNumber, CurrentNumber: r.CurrentNumber, Provider: r.Provider, AutoIssue: r.AutoIssue,
		IsActive: r.IsActive, CreatedBy: r.CreatedBy, CreatedAt: r.CreatedAt, UpdatedAt: r.UpdatedAt}
}

// InvoiceConfig: màn "Cấu hình dải số hóa đơn" — dải trong phạm vi (dải chi nhánh mình + dải mặc định).
func (s *Service) InvoiceConfig(ctx context.Context, actor *iam.Access) (*ConfigPage, error) {
	limit := branchLimit(actor)
	rows, err := s.q.ListInvoiceRanges(ctx, limit)
	if err != nil {
		return nil, err
	}
	bs, err := s.q.ScopeBranches(ctx, limit)
	if err != nil {
		return nil, err
	}
	canManage := actor.Can("invoice_range.manage")
	out := &ConfigPage{Ranges: make([]RangeRow, 0, len(rows)), Branches: []Option{}, CanManage: canManage,
		CanManageDefault: actor.Can("invoice_range.manage_default")}
	for _, b := range bs {
		out.Branches = append(out.Branches, Option{b.ID.String(), b.Name})
	}
	for _, x := range rows {
		r := rangeOf(x)
		row := RangeRow{ID: r.ID, BranchID: r.BranchID, BranchName: x.BranchName, Kind: r.Kind, KindLabel: kindLabels[r.Kind],
			SeriesCode: r.SeriesCode, TemplateCode: r.TemplateCode, Provider: r.Provider, StartNumber: r.StartNumber, EndNumber: r.EndNumber,
			CurrentNumber: r.CurrentNumber, CurrentLabel: pad(r.CurrentNumber), IsActive: r.IsActive,
			CanManage: canManage && canManageRange(actor, r.BranchID), Recent: []RecentInvoice{}}
		row.RangeLabel = pad(r.StartNumber) + " – ∞"
		if r.EndNumber != nil {
			row.RangeLabel = pad(r.StartNumber) + " – " + pad(*r.EndNumber)
		}
		if row.MaxIssued, err = maxIssued(ctx, s.q, r.SeriesCode, r.StartNumber, r.EndNumber); err != nil {
			return nil, err
		}
		if row.MaxIssued != nil {
			row.MaxIssuedLabel = ptr(pad(*row.MaxIssued))
		}
		if row.Remaining = remaining(r); row.Remaining != nil {
			row.RemainingLabel = ptr(groupThousands(*row.Remaining))
			row.Low = *row.Remaining > 0 && *row.Remaining <= lowRemainingThreshold
		}
		b := rangeBounds(r.SeriesCode, r.StartNumber, r.EndNumber)
		recent, err := s.q.RecentIssuedInvoices(ctx, tuitiondb.RecentIssuedInvoicesParams{Series: b.Series, Low: b.Low, High: b.High})
		if err != nil {
			return nil, err
		}
		for _, inv := range recent {
			label := inv.UpdatedAt.In(vnZone).Format("02/01/2006 15:04")
			if inv.Status == StatusCancelled {
				label = "Đã hủy HĐ"
			}
			row.Recent = append(row.Recent, RecentInvoice{InvoiceNumber: inv.InvoiceNumber, Label: label})
		}
		out.Ranges = append(out.Ranges, row)
	}
	return out, nil
}

// optInt: số nguyên tùy chọn phân biệt "không gửi" (Set = false) với null / rỗng (Set, V = nil).
type optInt struct {
	Set bool
	V   *int64
}

func (o *optInt) UnmarshalJSON(b []byte) error {
	o.Set = true
	b = bytes.TrimSpace(b)
	if string(b) == "null" || string(b) == `""` {
		o.V = nil
		return nil
	}
	var n int64
	if len(b) > 0 && b[0] == '"' {
		var s string
		if err := json.Unmarshal(b, &s); err != nil {
			return err
		}
		if _, err := fmt.Sscan(strings.TrimSpace(s), &n); err != nil {
			return iam.Invalid("Giá trị số không hợp lệ.")
		}
	} else if err := json.Unmarshal(b, &n); err != nil {
		return err
	}
	o.V = &n
	return nil
}

// RangeInput: form thêm dải (branch_id, kind) / sửa dải (config_id; không gửi = dải mặc định).
type RangeInput struct {
	ConfigID      *uuid.UUID `json:"config_id"`
	BranchID      *uuid.UUID `json:"branch_id"`
	Kind          string     `json:"kind"`
	TemplateCode  string     `json:"template_code"`
	SeriesCode    string     `json:"series_code"`
	StartNumber   optInt     `json:"start_number"`
	EndNumber     optInt     `json:"end_number"`
	CurrentNumber optInt     `json:"current_number"`
	Provider      *string    `json:"provider"`
}

func (in *RangeInput) validateCommon() error {
	in.TemplateCode = strings.TrimSpace(in.TemplateCode)
	in.SeriesCode = strings.TrimSpace(in.SeriesCode)
	in.Provider = optText(in.Provider)
	switch {
	case in.TemplateCode == "":
		return iam.Invalid("Vui lòng nhập mẫu số.")
	case tooLong(&in.TemplateCode, 50):
		return iam.Invalid("Trường mẫu số không được vượt quá 50 ký tự.")
	case in.SeriesCode == "":
		return iam.Invalid("Vui lòng nhập ký hiệu.")
	case tooLong(&in.SeriesCode, 30):
		return iam.Invalid("Trường ký hiệu không được vượt quá 30 ký tự.")
	case !seriesRe.MatchString(in.SeriesCode):
		return iam.Invalid("Ký hiệu hóa đơn chỉ gồm chữ và số, không dấu, không khoảng trắng.")
	case in.StartNumber.V != nil && *in.StartNumber.V < 1:
		return iam.Invalid("Trường số bắt đầu phải tối thiểu là 1.")
	case in.EndNumber.V != nil && *in.EndNumber.V < 1:
		return iam.Invalid("Trường số kết thúc phải tối thiểu là 1.")
	case tooLong(in.Provider, 50):
		return iam.Invalid("Trường nhà cung cấp không được vượt quá 50 ký tự.")
	}
	in.SeriesCode = strings.ToUpper(in.SeriesCode)
	return nil
}

// rangeError: chồng lấn dải cùng ký hiệu, số kế tiếp ngoài dải hoặc lùi về số đã cấp.
func (s *Service) rangeError(ctx context.Context, q *tuitiondb.Queries, series string, start int64, end *int64, current int64, ignore uuid.UUID) error {
	if end != nil && *end < start {
		return iam.Invalid("Số kết thúc phải lớn hơn hoặc bằng số bắt đầu.")
	}
	others, err := q.SeriesRanges(ctx, tuitiondb.SeriesRangesParams{SeriesCode: series, IgnoreID: ignore})
	if err != nil {
		return err
	}
	for _, o := range others {
		if (end == nil || o.StartNumber <= *end) && (o.EndNumber == nil || start <= *o.EndNumber) {
			endLabel := "∞"
			if o.EndNumber != nil {
				endLabel = fmt.Sprint(*o.EndNumber)
			}
			where := "dải mặc định"
			if o.BranchID != nil {
				if bs, err := q.BranchNames(ctx, []uuid.UUID{*o.BranchID}); err == nil && len(bs) == 1 {
					where = bs[0].Name
				}
			}
			return invalidf("Dải số chồng lấn với dải %s %d – %s (%s). Mỗi dải phải duy nhất trên toàn hệ thống.", o.SeriesCode, o.StartNumber, endLabel, where)
		}
	}
	if current < start {
		return iam.Invalid("Số hiện tại (số kế tiếp) không được nhỏ hơn số bắt đầu của dải.")
	}
	if end != nil && current > *end+1 {
		return iam.Invalid("Số hiện tại vượt quá số kết thúc của dải.")
	}
	m, err := maxIssued(ctx, q, series, start, end)
	if err != nil {
		return err
	}
	if m != nil && current <= *m {
		return invalidf("Không được lùi số: số %s đã được cấp. Số kế tiếp phải từ %d trở lên.", formatInvoice(series, *m), *m+1)
	}
	return nil
}

// UpdateInvoiceRange: sửa một dải (config_id) hoặc dải mặc định (tạo mới nếu chưa có).
func (s *Service) UpdateInvoiceRange(ctx context.Context, actor *iam.Access, in RangeInput) (*Saved, error) {
	if err := in.validateCommon(); err != nil {
		return nil, err
	}
	if !in.CurrentNumber.Set || in.CurrentNumber.V == nil {
		return nil, iam.Invalid("Vui lòng nhập số hiện tại.")
	}
	if *in.CurrentNumber.V < 1 {
		return nil, iam.Invalid("Trường số hiện tại phải tối thiểu là 1.")
	}
	var out *Saved
	err := s.inTx(ctx, func(tx pgx.Tx, q *tuitiondb.Queries) error {
		var cfg tuitiondb.FinanceInvoiceRange
		isNew := false
		if in.ConfigID != nil {
			x, err := q.LockInvoiceRange(ctx, *in.ConfigID)
			if isNoRows(err) {
				return &iam.Error{Status: 404, Code: "not_found", Message: "Không tìm thấy dải số hóa đơn."}
			}
			if err != nil {
				return err
			}
			cfg = x
		} else {
			x, err := q.FirstDefaultRange(ctx)
			switch {
			case isNoRows(err):
				isNew = true
				cfg = tuitiondb.FinanceInvoiceRange{Kind: "electronic", StartNumber: 1, Provider: "vnpt", AutoIssue: true, IsActive: true}
			case err != nil:
				return err
			default:
				if cfg, err = q.LockInvoiceRange(ctx, x.ID); err != nil {
					return err
				}
			}
		}
		if !canManageRange(actor, cfg.BranchID) {
			return forbidden("Bạn chỉ được cấu hình dải số hóa đơn của chi nhánh mình.")
		}
		start := cfg.StartNumber
		if in.StartNumber.V != nil {
			start = *in.StartNumber.V
		}
		end := cfg.EndNumber
		if in.EndNumber.Set {
			end = in.EndNumber.V
		}
		current := *in.CurrentNumber.V
		if err := q.LockSeries(ctx, in.SeriesCode); err != nil {
			return err
		}
		if err := s.rangeError(ctx, q, in.SeriesCode, start, end, current, cfg.ID); err != nil {
			return err
		}
		provider := cfg.Provider
		if in.Provider != nil {
			provider = *in.Provider
		}
		var err error
		if isNew {
			cfg, err = q.CreateInvoiceRange(ctx, tuitiondb.CreateInvoiceRangeParams{Kind: "electronic", TemplateCode: in.TemplateCode,
				SeriesCode: in.SeriesCode, StartNumber: start, EndNumber: end, CurrentNumber: current, Provider: provider, AutoIssue: true,
				IsActive: true, CreatedBy: &actor.UserID})
		} else {
			cfg, err = q.UpdateInvoiceRange(ctx, tuitiondb.UpdateInvoiceRangeParams{ID: cfg.ID, TemplateCode: in.TemplateCode,
				SeriesCode: in.SeriesCode, StartNumber: start, EndNumber: end, CurrentNumber: current, Provider: provider, AutoIssue: cfg.AutoIssue})
		}
		if err != nil {
			return err
		}
		where := s.rangeBranchName(ctx, q, cfg.BranchID, "dải mặc định")
		name := s.actorName(ctx, q, actor)
		notice := notifyRange{cfg, fmt.Sprintf("%s đã cập nhật dải số %s (%s): số kế tiếp %d.", name, cfg.SeriesCode, where, cfg.CurrentNumber)}
		if err := s.recordRange(ctx, tx, actor, cfg, where, audit.EventUpdated, "tuition.invoice_range.update",
			fmt.Sprintf("Cập nhật dải số %s %s, số kế tiếp %d", cfg.SeriesCode, rangeText(cfg), cfg.CurrentNumber)); err != nil {
			return err
		}
		if err := s.notifyRangeChange(ctx, tx, actor, notice); err != nil {
			return err
		}
		out = &Saved{ID: cfg.ID, Code: cfg.SeriesCode, Status: "saved", Message: "Đã lưu cấu hình dải số hóa đơn điện tử thành công!"}
		return nil
	})
	if err != nil {
		return nil, err
	}
	s.log.Info("invoice range updated", "range_id", out.ID, "by", actor.UserID)
	return out, nil
}

func rangeText(r tuitiondb.FinanceInvoiceRange) string {
	if r.EndNumber == nil {
		return fmt.Sprintf("%d – ∞", r.StartNumber)
	}
	return fmt.Sprintf("%d – %d", r.StartNumber, *r.EndNumber)
}

func (s *Service) rangeBranchName(ctx context.Context, q *tuitiondb.Queries, branch *uuid.UUID, fallback string) string {
	if branch == nil {
		return fallback
	}
	if bs, err := q.BranchNames(ctx, []uuid.UUID{*branch}); err == nil && len(bs) == 1 {
		return bs[0].Name
	}
	return fallback
}

// CreateInvoiceRange: thêm dải số mới cho một chi nhánh (hoặc dải mặc định). Số kế tiếp = số bắt đầu, nhưng không thấp
// hơn số đã cấp của cùng ký hiệu.
func (s *Service) CreateInvoiceRange(ctx context.Context, actor *iam.Access, in RangeInput) (*Saved, error) {
	if in.Kind == "" {
		in.Kind = "electronic"
	}
	if _, ok := kindLabels[in.Kind]; !ok {
		return nil, iam.Invalid("Giá trị đã chọn trong trường loại hóa đơn không hợp lệ.")
	}
	if in.Kind == "paper" && in.BranchID == nil {
		return nil, iam.Invalid("Dải hóa đơn giấy phải gắn với một chi nhánh (mỗi chi nhánh một cuốn hóa đơn).")
	}
	if err := in.validateCommon(); err != nil {
		return nil, err
	}
	switch {
	case in.StartNumber.V == nil:
		return nil, iam.Invalid("Vui lòng nhập số bắt đầu.")
	case in.EndNumber.V == nil:
		return nil, iam.Invalid("Vui lòng nhập số kết thúc.")
	case *in.EndNumber.V < *in.StartNumber.V:
		return nil, iam.Invalid("Số kết thúc phải lớn hơn hoặc bằng số bắt đầu.")
	}
	if in.BranchID != nil {
		bs, err := s.q.BranchNames(ctx, []uuid.UUID{*in.BranchID})
		if err != nil {
			return nil, err
		}
		if len(bs) == 0 {
			return nil, iam.Invalid("Chi nhánh không tồn tại.")
		}
	}
	if !canManageRange(actor, in.BranchID) {
		return nil, forbidden("Bạn chỉ được cấp dải số hóa đơn cho chi nhánh mình.")
	}
	start, end := *in.StartNumber.V, in.EndNumber.V
	provider := "vnpt"
	if in.Provider != nil {
		provider = *in.Provider
	}
	var out *Saved
	err := s.inTx(ctx, func(tx pgx.Tx, q *tuitiondb.Queries) error {
		if err := q.LockSeries(ctx, in.SeriesCode); err != nil {
			return err
		}
		current := start
		m, err := maxIssued(ctx, q, in.SeriesCode, start, end)
		if err != nil {
			return err
		}
		if m != nil {
			current = max(start, *m+1)
		}
		if err := s.rangeError(ctx, q, in.SeriesCode, start, end, current, uuid.Nil); err != nil {
			return err
		}
		r, err := q.CreateInvoiceRange(ctx, tuitiondb.CreateInvoiceRangeParams{BranchID: in.BranchID, Kind: in.Kind, TemplateCode: in.TemplateCode,
			SeriesCode: in.SeriesCode, StartNumber: start, EndNumber: end, CurrentNumber: current, Provider: provider, AutoIssue: true,
			IsActive: true, CreatedBy: &actor.UserID})
		if err != nil {
			return err
		}
		where := s.rangeBranchName(ctx, q, r.BranchID, defaultRangeLabel)
		kind := strings.ToLower(kindLabels[r.Kind])
		msg := fmt.Sprintf("%s đã cấp dải số %s mới %s %d – %d cho %s.", s.actorName(ctx, q, actor), kind, r.SeriesCode, start, *end, where)
		if err := s.recordRange(ctx, tx, actor, r, where, audit.EventCreated, "tuition.invoice_range.create",
			fmt.Sprintf("Thêm dải số %s %s %s", kind, r.SeriesCode, rangeText(r))); err != nil {
			return err
		}
		if err := s.notifyRangeChange(ctx, tx, actor, notifyRange{r, msg}); err != nil {
			return err
		}
		out = &Saved{ID: r.ID, Code: r.SeriesCode, Status: "created",
			Message: fmt.Sprintf("Đã thêm dải số %s %s %s – %s cho %s.", kind, r.SeriesCode, pad(start), pad(*end), where)}
		return nil
	})
	if err != nil {
		return nil, err
	}
	s.log.Info("invoice range created", "range_id", out.ID, "series", out.Code, "by", actor.UserID)
	return out, nil
}

// ToggleInvoiceRange: ngừng dùng / dùng lại một dải. Số đã cấp của dải vẫn giữ nguyên, không cấp lại.
func (s *Service) ToggleInvoiceRange(ctx context.Context, actor *iam.Access, id uuid.UUID) (*Saved, error) {
	var out *Saved
	err := s.inTx(ctx, func(tx pgx.Tx, q *tuitiondb.Queries) error {
		r, err := q.LockInvoiceRange(ctx, id)
		if isNoRows(err) {
			return &iam.Error{Status: 404, Code: "not_found", Message: "Không tìm thấy dải số hóa đơn."}
		}
		if err != nil {
			return err
		}
		if !canManageRange(actor, r.BranchID) {
			return forbidden("Bạn chỉ được cấu hình dải số hóa đơn của chi nhánh mình.")
		}
		if r, err = q.ToggleInvoiceRange(ctx, id); err != nil {
			return err
		}
		where := s.rangeBranchName(ctx, q, r.BranchID, "dải mặc định")
		verb, action := " đã ngừng dùng", "tuition.invoice_range.deactivate"
		if r.IsActive {
			verb, action = " đã kích hoạt lại", "tuition.invoice_range.activate"
		}
		if err := s.recordRange(ctx, tx, actor, r, where, audit.EventUpdated, action, strings.TrimSpace(verb)+" dải số "+r.SeriesCode); err != nil {
			return err
		}
		if err := s.notifyRangeChange(ctx, tx, actor, notifyRange{r, fmt.Sprintf("%s%s dải số %s (%s).", s.actorName(ctx, q, actor), verb, r.SeriesCode, where)}); err != nil {
			return err
		}
		msg := "Đã kích hoạt lại dải số " + r.SeriesCode + "."
		switch {
		case r.IsActive:
		case r.Kind == "paper":
			msg = "Đã ngừng dùng dải hóa đơn giấy " + r.SeriesCode + ". Phiếu tiền mặt lấy số từ dải giấy khác của chi nhánh; không còn dải nào thì Học vụ nhập tay số hóa đơn giấy."
		default:
			msg = "Đã ngừng dùng dải số " + r.SeriesCode + ". Hóa đơn mới sẽ lấy từ dải khác của chi nhánh hoặc dải mặc định."
		}
		out = &Saved{ID: r.ID, Code: r.SeriesCode, Status: map[bool]string{true: "active", false: "inactive"}[r.IsActive], Message: msg}
		return nil
	})
	if err != nil {
		return nil, err
	}
	s.log.Info("invoice range toggled", "range_id", id, "status", out.Status, "by", actor.UserID)
	return out, nil
}

func (s *Service) recordRange(ctx context.Context, tx pgx.Tx, actor *iam.Access, r tuitiondb.FinanceInvoiceRange, where, event, action, desc string) error {
	return audit.Record(ctx, tx, audit.Entry{
		ActorID: &actor.UserID, Module: audit.ModuleTuition, Event: event, Action: action,
		SubjectType: "invoice_range", SubjectID: r.ID.String(), SubjectLabel: r.SeriesCode + " (" + where + ")", BranchID: r.BranchID,
		Description: desc + ".",
		Properties: map[string]any{"kind": r.Kind, "series_code": r.SeriesCode, "template_code": r.TemplateCode, "start_number": r.StartNumber,
			"end_number": r.EndNumber, "current_number": r.CurrentNumber, "is_active": r.IsActive},
	})
}

type notifyRange struct {
	r   tuitiondb.FinanceInvoiceRange
	msg string
}

// notifyRangeChange: "Chính sách đồng bộ số hóa đơn" — dải chi nhánh: Quản lý cơ sở + người có invoice_range.manage
// (không phải Admin) của chi nhánh; dải mặc định: Admin. Không gửi cho chính người thao tác.
func (s *Service) notifyRangeChange(ctx context.Context, tx pgx.Tx, actor *iam.Access, n notifyRange) error {
	var ids []uuid.UUID
	if n.r.BranchID == nil {
		x, err := iam.AdminIDs(ctx, tx)
		if err != nil {
			return err
		}
		ids = x
	} else {
		managers, err := iam.UserIDsWithRole(ctx, tx, iam.RoleManager, n.r.BranchID)
		if err != nil {
			return err
		}
		holders, err := iam.UserIDsWithPermission(ctx, tx, "invoice_range.manage", n.r.BranchID)
		if err != nil {
			return err
		}
		admins, err := iam.AdminIDs(ctx, tx)
		if err != nil {
			return err
		}
		ids = managers
		for _, id := range holders {
			if !slices.Contains(admins, id) && !slices.Contains(ids, id) {
				ids = append(ids, id)
			}
		}
	}
	_, err := notify.Send(ctx, tx, ids, notify.Notification{
		Type: "invoice_range_changed", Title: "Thay đổi dải số hóa đơn", Body: n.msg, Link: "/tuition/config",
		Data: map[string]any{"invoice_range_id": n.r.ID}, CreatedBy: &actor.UserID, Except: &actor.UserID,
	})
	return err
}
