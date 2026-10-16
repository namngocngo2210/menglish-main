package catalogs

import (
	"context"
	"fmt"
	"net/url"
	"regexp"
	"slices"
	"strings"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/catalogs/catalogsdb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// Cấu hình học phí: tài khoản ngân hàng nhận tiền / VietQR (SystemConfigController@bankAccounts...) và mốc nhắc nợ
// (SystemConfigController@debtReminders...).

var errBankNotFound = notFound("Không tìm thấy tài khoản ngân hàng.")

// BankAccountTypes: loại tài khoản (Công ty: chủ sở hữu chính; Khác: cá nhân / đại diện).
var BankAccountTypes = []Option{{"company", "Công ty"}, {"other", "Khác"}}

type BankAccount struct {
	ID               uuid.UUID  `json:"id"`
	AccountType      string     `json:"account_type"`
	TypeLabel        string     `json:"type_label"`
	BankCode         string     `json:"bank_code"`
	BankName         string     `json:"bank_name"`
	AccountNumber    string     `json:"account_number"`
	AccountHolder    string     `json:"account_holder"`
	BranchLocation   *string    `json:"branch_location"`
	BranchID         *uuid.UUID `json:"branch_id"`
	BranchName       *string    `json:"branch_name"`
	IsDefaultVietqr  bool       `json:"is_default_vietqr"`
	IsActive         bool       `json:"is_active"`
	VietqrPreviewURL string     `json:"vietqr_preview_url"`
}

func typeLabel(t string) string {
	if t == "other" {
		return "KHÁC"
	}
	return "CÔNG TY"
}

// vietqrPreview: ảnh VietQR xem trước (không kèm số tiền).
func vietqrPreview(code, number, holder string) string {
	return "https://img.vietqr.io/image/" + url.PathEscape(code) + "-" + url.PathEscape(number) +
		"-compact2.png?accountName=" + url.QueryEscape(holder)
}

type BankAccountList struct {
	Accounts       []BankAccount  `json:"accounts"`
	TotalAccounts  int64          `json:"total_accounts"`
	DefaultAccount *BankAccount   `json:"default_account"`
	Branches       []BranchOption `json:"branches"`
	Types          []Option       `json:"types"`
}

func (s *Service) BankAccounts(ctx context.Context, search string) (BankAccountList, error) {
	rows, err := s.q.ListBankAccounts(ctx, likeArg(search))
	if err != nil {
		return BankAccountList{}, err
	}
	out := BankAccountList{Accounts: make([]BankAccount, 0, len(rows)), Types: BankAccountTypes}
	for _, r := range rows {
		out.Accounts = append(out.Accounts, BankAccount{
			ID: r.ID, AccountType: r.AccountType, TypeLabel: typeLabel(r.AccountType), BankCode: r.BankCode, BankName: r.BankName,
			AccountNumber: r.AccountNumber, AccountHolder: r.AccountHolder, BranchLocation: r.BranchLocation, BranchID: r.BranchID,
			BranchName: r.BranchName, IsDefaultVietqr: r.IsDefaultVietqr, IsActive: r.IsActive,
			VietqrPreviewURL: vietqrPreview(r.BankCode, r.AccountNumber, r.AccountHolder),
		})
	}
	if out.TotalAccounts, err = s.q.CountBankAccounts(ctx); err != nil {
		return out, err
	}
	if d, err := s.q.DefaultBankAccount(ctx); err == nil {
		out.DefaultAccount = &BankAccount{ID: d.ID, BankCode: d.BankCode, BankName: d.BankName, AccountNumber: d.AccountNumber,
			AccountHolder: d.AccountHolder, VietqrPreviewURL: vietqrPreview(d.BankCode, d.AccountNumber, d.AccountHolder)}
	} else if !isNoRows(err) {
		return out, err
	}
	branches, err := s.q.ScopeBranches(ctx, nil)
	if err != nil {
		return out, err
	}
	out.Branches = make([]BranchOption, 0, len(branches))
	for _, b := range branches {
		out.Branches = append(out.Branches, BranchOption{ID: b.ID, Code: b.Code, Name: b.Name})
	}
	return out, nil
}

type BankAccountInput struct {
	AccountType     string     `json:"account_type"`
	BankCode        string     `json:"bank_code"`
	BankName        string     `json:"bank_name"`
	AccountNumber   string     `json:"account_number"`
	AccountHolder   string     `json:"account_holder"`
	BranchLocation  string     `json:"branch_location"`
	BranchID        *uuid.UUID `json:"branch_id"`
	IsDefaultVietqr bool       `json:"is_default_vietqr"`
	IsActive        *bool      `json:"is_active"` // chỉ dùng khi sửa; nil = đang dùng
}

func (s *Service) validateBank(ctx context.Context, in *BankAccountInput) error {
	in.BankCode = strings.ToUpper(strings.TrimSpace(in.BankCode))
	in.BankName = strings.TrimSpace(in.BankName)
	in.AccountNumber = strings.TrimSpace(in.AccountNumber)
	in.AccountHolder = strings.ToUpper(strings.TrimSpace(in.AccountHolder))
	in.BranchLocation = strings.TrimSpace(in.BranchLocation)
	if in.AccountType == "" {
		in.AccountType = "company"
	}
	switch {
	case in.AccountType != "company" && in.AccountType != "other":
		return iam.Invalid("Loại tài khoản không hợp lệ.")
	case in.BankCode == "":
		return iam.Invalid("Vui lòng nhập mã ngân hàng.")
	case tooLong(in.BankCode, 20):
		return iam.Invalid("Mã ngân hàng không được vượt quá 20 ký tự.")
	case in.BankName == "":
		return iam.Invalid("Vui lòng nhập tên ngân hàng.")
	case tooLong(in.BankName, 255):
		return iam.Invalid("Tên ngân hàng không được vượt quá 255 ký tự.")
	case in.AccountNumber == "":
		return iam.Invalid("Vui lòng nhập số tài khoản.")
	case tooLong(in.AccountNumber, 50):
		return iam.Invalid("Số tài khoản không được vượt quá 50 ký tự.")
	case in.AccountHolder == "":
		return iam.Invalid("Vui lòng nhập tên chủ tài khoản.")
	case tooLong(in.AccountHolder, 255):
		return iam.Invalid("Tên chủ tài khoản không được vượt quá 255 ký tự.")
	case tooLong(in.BranchLocation, 255):
		return iam.Invalid("Chi nhánh ngân hàng không được vượt quá 255 ký tự.")
	}
	if in.BranchID != nil {
		if ok, err := s.q.BranchExists(ctx, *in.BranchID); err != nil {
			return err
		} else if !ok {
			return iam.Invalid("Chi nhánh đã chọn không tồn tại trong hệ thống.")
		}
	}
	return nil
}

func (s *Service) CreateBankAccount(ctx context.Context, actor *iam.Access, in BankAccountInput) (uuid.UUID, string, error) {
	if err := s.validateBank(ctx, &in); err != nil {
		return uuid.Nil, "", err
	}
	var a catalogsdb.FinanceBankAccount
	err := s.tx(ctx, func(q *catalogsdb.Queries) error {
		if err := q.LockKey(ctx, "finance.bank_accounts.default"); err != nil {
			return err
		}
		n, err := q.CountBankAccounts(ctx)
		if err != nil {
			return err
		}
		// Tài khoản đầu tiên luôn là mặc định.
		def := in.IsDefaultVietqr || n == 0
		if def {
			if err := q.ClearDefaultBankAccount(ctx, uuid.Nil); err != nil {
				return err
			}
		}
		a, err = q.CreateBankAccount(ctx, catalogsdb.CreateBankAccountParams{
			AccountType: in.AccountType, BankCode: in.BankCode, BankName: in.BankName, AccountNumber: in.AccountNumber,
			AccountHolder: in.AccountHolder, BranchLocation: optText(in.BranchLocation), BranchID: in.BranchID,
			IsDefaultVietqr: def, CreatedBy: &actor.UserID,
		})
		return err
	})
	if err != nil {
		return uuid.Nil, "", err
	}
	s.log.Info("bank account created", "bank_account_id", a.ID, "by", actor.UserID)
	return a.ID, fmt.Sprintf("Đã thêm tài khoản ngân hàng %s (%s) thành công!", a.BankName, a.AccountNumber), nil
}

func (s *Service) UpdateBankAccount(ctx context.Context, actor *iam.Access, id uuid.UUID, in BankAccountInput) (string, error) {
	if err := s.validateBank(ctx, &in); err != nil {
		return "", err
	}
	var a catalogsdb.FinanceBankAccount
	err := s.tx(ctx, func(q *catalogsdb.Queries) error {
		if err := q.LockKey(ctx, "finance.bank_accounts.default"); err != nil {
			return err
		}
		old, err := q.GetBankAccount(ctx, id)
		if isNoRows(err) {
			return errBankNotFound
		}
		if err != nil {
			return err
		}
		active := in.IsActive == nil || *in.IsActive
		if !active && (old.IsDefaultVietqr || in.IsDefaultVietqr) {
			return iam.Invalid("Không thể ngừng dùng tài khoản mặc định. Hãy đặt tài khoản khác làm mặc định trước.")
		}
		if in.IsDefaultVietqr {
			if err := q.ClearDefaultBankAccount(ctx, id); err != nil {
				return err
			}
		}
		a, err = q.UpdateBankAccount(ctx, catalogsdb.UpdateBankAccountParams{
			ID: id, AccountType: in.AccountType, BankCode: in.BankCode, BankName: in.BankName, AccountNumber: in.AccountNumber,
			AccountHolder: in.AccountHolder, BranchLocation: optText(in.BranchLocation), BranchID: in.BranchID,
			IsDefaultVietqr: in.IsDefaultVietqr || old.IsDefaultVietqr, IsActive: active,
		})
		return err
	})
	if err != nil {
		return "", err
	}
	s.log.Info("bank account updated", "bank_account_id", id, "by", actor.UserID)
	return fmt.Sprintf("Đã cập nhật thông tin tài khoản ngân hàng %s (%s) thành công!", a.BankName, a.AccountNumber), nil
}

func (s *Service) DeleteBankAccount(ctx context.Context, actor *iam.Access, id uuid.UUID) (string, error) {
	var a catalogsdb.FinanceBankAccount
	err := s.tx(ctx, func(q *catalogsdb.Queries) error {
		if err := q.LockKey(ctx, "finance.bank_accounts.default"); err != nil {
			return err
		}
		var err error
		a, err = q.GetBankAccount(ctx, id)
		if isNoRows(err) {
			return errBankNotFound
		}
		if err != nil {
			return err
		}
		if err := q.DeleteBankAccount(ctx, id); err != nil {
			return err
		}
		// Còn tài khoản thì luôn có một tài khoản mặc định.
		if a.IsDefaultVietqr {
			return q.PromoteFirstBankAccount(ctx)
		}
		return nil
	})
	if err != nil {
		return "", err
	}
	s.log.Info("bank account deleted", "bank_account_id", id, "by", actor.UserID)
	return fmt.Sprintf("Đã xóa tài khoản ngân hàng %s (%s)!", a.BankName, a.AccountNumber), nil
}

func (s *Service) SetDefaultBankAccount(ctx context.Context, actor *iam.Access, id uuid.UUID) (string, error) {
	var a catalogsdb.FinanceBankAccount
	err := s.tx(ctx, func(q *catalogsdb.Queries) error {
		if err := q.LockKey(ctx, "finance.bank_accounts.default"); err != nil {
			return err
		}
		if _, err := q.GetBankAccount(ctx, id); isNoRows(err) {
			return errBankNotFound
		} else if err != nil {
			return err
		}
		if err := q.ClearDefaultBankAccount(ctx, id); err != nil {
			return err
		}
		var err error
		a, err = q.SetDefaultBankAccount(ctx, id)
		return err
	})
	if err != nil {
		return "", err
	}
	s.log.Info("default bank account changed", "bank_account_id", id, "by", actor.UserID)
	return fmt.Sprintf("Đã thiết lập %s (%s) làm tài khoản nhận tiền mặc định VietQR!", a.BankName, a.AccountNumber), nil
}

// ───────────────────────── Nhắc nợ học phí ─────────────────────────

// DebtChannels: kênh gửi nhắc nợ.
var DebtChannels = []Option{
	{"portal", "Chuông thông báo in-app (Cổng PH/HS)"},
	{"email", "Email cảnh báo nội bộ (Kế toán / Admin)"},
}

// DebtVariables: biến dùng được trong mẫu tin (thường hoặc IN HOA).
var DebtVariables = []Option{
	{"{ten_hoc_vien}", "Họ tên học viên"},
	{"{ma_hoc_vien}", "Mã học viên"},
	{"{so_dien_thoai}", "SĐT học viên"},
	{"{lop_hoc}", "Tên lớp"},
	{"{so_tien}", "Số tiền còn nợ"},
	{"{han_dong}", "Hạn đóng (dd/mm/yyyy)"},
	{"{moc_nhac}", "Tên mốc nhắc"},
}

// debtAliases: biến đồng nghĩa (đã từng hướng dẫn trên màn cấu hình cũ).
var debtAliases = []string{"{ten_lop}", "{han_nop}", "{han_chot}"}

var varRe = regexp.MustCompile(`\{[^{}\s]+\}`)

// unknownVariables: các biến {..} trong mẫu mà hệ thống không thay được.
func unknownVariables(tpl string) []string {
	var out []string
	for _, v := range varRe.FindAllString(tpl, -1) {
		low := strings.ToLower(v)
		known := slices.Contains(debtAliases, low) || slices.ContainsFunc(DebtVariables, func(o Option) bool { return o.Value == low })
		if !known && !slices.Contains(out, v) {
			out = append(out, v)
		}
	}
	return out
}

const (
	debtRepeatMax     = 3
	keyMustContact    = "debt_reminder.must_contact_days"
	keyFirstDays      = "debt_reminder.first_days"
	keyRepeatDays     = "debt_reminder.repeat_days"
	defaultQuickTitle = "Chào phụ huynh học viên {ten_hoc_vien}, học phí lớp {lop_hoc} ({so_tien}) sẽ đến hạn ngày {han_dong}. Vui lòng hoàn thành đúng hạn. Xin cảm ơn!"
)

type DebtRule struct {
	ID              uuid.UUID `json:"id"`
	MilestoneKey    string    `json:"milestone_key"`
	OffsetDays      int32     `json:"offset_days"`
	OffsetLabel     string    `json:"offset_label"`
	Title           string    `json:"title"`
	TemplateContent string    `json:"template_content"`
	IsEnabled       bool      `json:"is_enabled"`
	Timing          string    `json:"timing"` // before | due | after
	Days            *int32    `json:"days"`
	Channels        []string  `json:"channels"`
}

func offsetLabel(o int32) string {
	switch {
	case o < 0:
		return fmt.Sprintf("%d ngày trước hạn", -o)
	case o == 0:
		return "Đúng ngày đến hạn"
	}
	return fmt.Sprintf("Quá hạn %d ngày", o)
}

func toDebtRule(r catalogsdb.FinanceDebtReminderRule) DebtRule {
	d := DebtRule{ID: r.ID, MilestoneKey: r.MilestoneKey, OffsetDays: r.OffsetDays, OffsetLabel: offsetLabel(r.OffsetDays),
		Title: r.Title, TemplateContent: r.TemplateContent, IsEnabled: r.IsEnabled, Channels: nonNil(r.Channels)}
	switch {
	case r.OffsetDays < 0:
		d.Timing = "before"
	case r.OffsetDays == 0:
		d.Timing = "due"
	default:
		d.Timing = "after"
	}
	if r.OffsetDays != 0 {
		v := max(r.OffsetDays, -r.OffsetDays)
		d.Days = &v
	}
	return d
}

type DebtReminderConfig struct {
	Rules           []DebtRule `json:"rules"`
	Channels        []Option   `json:"channels"`
	Variables       []Option   `json:"variables"`
	MustContactDays int        `json:"must_contact_days"`
	FirstDays       int        `json:"first_days"`
	RepeatDays      int        `json:"repeat_days"`
}

func (s *Service) DebtReminders(ctx context.Context) (DebtReminderConfig, error) {
	rows, err := s.q.ListDebtRules(ctx)
	if err != nil {
		return DebtReminderConfig{}, err
	}
	out := DebtReminderConfig{Rules: make([]DebtRule, 0, len(rows)), Channels: DebtChannels, Variables: DebtVariables}
	var before []int
	for _, r := range rows {
		out.Rules = append(out.Rules, toDebtRule(r))
		if r.IsEnabled && r.OffsetDays < 0 {
			before = append(before, int(-r.OffsetDays))
		}
	}
	vals, err := s.settings(ctx, keyMustContact, keyFirstDays, keyRepeatDays)
	if err != nil {
		return out, err
	}
	// Mặc định theo SLA học phí: thông báo trước 1 tuần, nhắc lại 1–3 ngày trước hạn.
	firstDef := 7
	if len(before) > 0 && slices.Max(before) > debtRepeatMax {
		firstDef = slices.Max(before)
	}
	repeatDef := 3
	var near []int
	for _, d := range before {
		if d <= debtRepeatMax {
			near = append(near, d)
		}
	}
	if len(near) > 0 {
		repeatDef = slices.Min(near)
	}
	out.MustContactDays = settingInt(vals, keyMustContact, 7)
	out.FirstDays = settingInt(vals, keyFirstDays, firstDef)
	out.RepeatDays = settingInt(vals, keyRepeatDays, repeatDef)
	return out, nil
}

type DebtRuleInput struct {
	MilestoneKey    string   `json:"milestone_key"`
	Title           string   `json:"title"`
	TemplateContent string   `json:"template_content"`
	Timing          string   `json:"timing"` // before | due | after
	Days            *int32   `json:"days"`
	Channels        []string `json:"channels"` // nil = giữ kênh cũ (mốc mới: cả hai kênh)
	IsEnabled       *bool    `json:"is_enabled"`
}

// SaveDebtRule: thêm mới / cập nhật theo milestone_key một mốc nhắc nợ.
func (s *Service) SaveDebtRule(ctx context.Context, actor *iam.Access, in DebtRuleInput) (DebtRule, string, error) {
	in.MilestoneKey = strings.TrimSpace(in.MilestoneKey)
	in.Title = strings.TrimSpace(in.Title)
	in.TemplateContent = strings.TrimSpace(in.TemplateContent)
	switch {
	case tooLong(in.MilestoneKey, 30):
		return DebtRule{}, "", iam.Invalid("Mã mốc không được vượt quá 30 ký tự.")
	case in.Title == "":
		return DebtRule{}, "", iam.Invalid("Vui lòng nhập tiêu đề mốc nhắc.")
	case tooLong(in.Title, 255):
		return DebtRule{}, "", iam.Invalid("Tiêu đề không được vượt quá 255 ký tự.")
	case in.TemplateContent == "":
		return DebtRule{}, "", iam.Invalid("Vui lòng nhập mẫu tin nhắn.")
	case tooLong(in.TemplateContent, 2000):
		return DebtRule{}, "", iam.Invalid("Mẫu tin nhắn không được vượt quá 2000 ký tự.")
	case in.Timing != "" && in.Timing != "before" && in.Timing != "due" && in.Timing != "after":
		return DebtRule{}, "", iam.Invalid("Thời điểm gửi không hợp lệ.")
	case in.Days != nil && (*in.Days < 1 || *in.Days > 60):
		return DebtRule{}, "", iam.Invalid("Số ngày phải từ 1 đến 60.")
	}
	for _, c := range in.Channels {
		if !slices.ContainsFunc(DebtChannels, func(o Option) bool { return o.Value == c }) {
			return DebtRule{}, "", iam.Invalid("Kênh gửi không hợp lệ.")
		}
	}
	if unknown := unknownVariables(in.TemplateContent); len(unknown) > 0 {
		known := make([]string, 0, len(DebtVariables))
		for _, v := range DebtVariables {
			known = append(known, v.Value)
		}
		return DebtRule{}, "", iam.Invalid("Mẫu tin có biến hệ thống không hỗ trợ: " + strings.Join(unknown, ", ") + ". Chỉ dùng: " + strings.Join(known, ", ") + ".")
	}

	var saved catalogsdb.FinanceDebtReminderRule
	err := s.tx(ctx, func(q *catalogsdb.Queries) error {
		if err := q.LockKey(ctx, "finance.debt_reminder_rules"); err != nil {
			return err
		}
		var existing *catalogsdb.FinanceDebtReminderRule
		if in.MilestoneKey != "" {
			if r, err := q.GetDebtRuleByKey(ctx, in.MilestoneKey); err == nil {
				existing = &r
			} else if !isNoRows(err) {
				return err
			}
		}
		var offset *int32
		if in.Timing != "" {
			if in.Timing != "due" && in.Days == nil {
				return iam.Invalid("Vui lòng nhập số ngày trước / sau hạn đóng.")
			}
			var o int32
			switch in.Timing {
			case "before":
				o = -*in.Days
			case "after":
				o = *in.Days
			}
			offset = &o
		}
		if offset == nil && existing != nil {
			offset = &existing.OffsetDays
		}
		if offset == nil {
			offset = offsetFromKey(in.MilestoneKey)
		}
		if offset == nil {
			return iam.Invalid("Vui lòng chọn thời điểm gửi (trước hạn / đúng hạn / quá hạn).")
		}
		key := in.MilestoneKey
		if key == "" {
			key = fmt.Sprintf("T%+d", *offset)
			if *offset == 0 {
				key = "T0"
			}
		}
		all, err := q.ListDebtRules(ctx)
		if err != nil {
			return err
		}
		for _, r := range all {
			if r.MilestoneKey != key && (existing == nil || r.ID != existing.ID) && r.OffsetDays == *offset {
				return iam.Invalid(fmt.Sprintf("Đã có mốc %s (%s). Mỗi thời điểm chỉ một mốc nhắc.", r.MilestoneKey, offsetLabel(r.OffsetDays)))
			}
		}
		channels := in.Channels
		if channels == nil {
			channels = []string{"portal", "email"}
			if existing != nil {
				channels = existing.Channels
			}
		}
		enabled := true
		if in.IsEnabled != nil {
			enabled = *in.IsEnabled
		} else if existing != nil {
			enabled = existing.IsEnabled
		}
		saved, err = q.UpsertDebtRule(ctx, catalogsdb.UpsertDebtRuleParams{
			MilestoneKey: key, OffsetDays: *offset, Title: in.Title, TemplateContent: in.TemplateContent,
			Channels: nonNil(channels), IsEnabled: enabled, CreatedBy: &actor.UserID,
		})
		return err
	})
	if err != nil {
		return DebtRule{}, "", err
	}
	s.log.Info("debt reminder rule saved", "milestone_key", saved.MilestoneKey, "by", actor.UserID)
	return toDebtRule(saved), fmt.Sprintf("Đã lưu mốc nhắc nợ %s (%s) thành công!", saved.MilestoneKey, offsetLabel(saved.OffsetDays)), nil
}

var keyOffsetRe = regexp.MustCompile(`^T([+-]?\d+)$`)
var keyDRe = regexp.MustCompile(`^D_(PLUS|MINUS)_(\d+)$`)

// offsetFromKey: số ngày từ mã mốc dạng T-3 / T0 / T+3 hoặc D_PLUS_3 (dữ liệu cũ).
func offsetFromKey(key string) *int32 {
	key = strings.ToUpper(key)
	var n int32
	if m := keyOffsetRe.FindStringSubmatch(key); m != nil {
		if _, err := fmt.Sscan(m[1], &n); err == nil {
			return &n
		}
	}
	if m := keyDRe.FindStringSubmatch(key); m != nil {
		if _, err := fmt.Sscan(m[2], &n); err == nil {
			if m[1] == "MINUS" {
				n = -n
			}
			return &n
		}
	}
	return nil
}

type DebtSettingsInput struct {
	MustContactDays *int `json:"must_contact_days"`
	FirstDays       *int `json:"first_days"`
	RepeatDays      *int `json:"repeat_days"`
}

// quickRules: mốc do "Thiết lập nhanh" quản lý.
var quickRules = map[string][2]string{
	"first":  {"NHAC-TRUOC", "Nhắc nợ trước hạn"},
	"repeat": {"NHAC-LAI", "Nhắc lại sát hạn"},
}

// UpdateDebtSettings: thiết lập nhanh mốc nhắc trước hạn, mốc nhắc lại (1–3 ngày trước hạn) và ngưỡng "quá hạn bắt buộc
// liên hệ". Hai mốc nhắc được đồng bộ thành mốc nhắc thật; đã có mốc khác cùng số ngày thì dùng mốc đó.
func (s *Service) UpdateDebtSettings(ctx context.Context, actor *iam.Access, in DebtSettingsInput) (string, error) {
	invalidRange := "Giá trị không hợp lệ. Vui lòng nhập trong khoảng từ 1–60 ngày."
	switch {
	case in.MustContactDays == nil:
		return "", iam.Invalid("Vui lòng nhập mốc quá hạn bắt buộc liên hệ.")
	case *in.MustContactDays < 1 || *in.MustContactDays > 60:
		return "", iam.Invalid(invalidRange)
	case in.FirstDays != nil && (*in.FirstDays < 1 || *in.FirstDays > 60):
		return "", iam.Invalid("Mốc nhắc trước hạn phải từ 1–60 ngày.")
	case in.FirstDays != nil && in.RepeatDays == nil:
		return "", iam.Invalid("Vui lòng nhập mốc nhắc lại (1-3 ngày).")
	case in.RepeatDays != nil && (*in.RepeatDays < 1 || *in.RepeatDays > debtRepeatMax):
		return "", iam.Invalid("Giá trị không hợp lệ. Vui lòng nhập trong khoảng từ 1-3 ngày.")
	case in.FirstDays != nil && *in.RepeatDays >= *in.FirstDays:
		return "", iam.Invalid("Mốc nhắc lại phải gần hạn hơn mốc nhắc trước hạn.")
	}
	err := s.tx(ctx, func(q *catalogsdb.Queries) error {
		if err := s.putSetting(ctx, q, actor, keyMustContact, *in.MustContactDays, "Số ngày quá hạn phải gọi điện liên hệ trực tiếp (nhóm quá hạn nghiêm trọng)."); err != nil {
			return err
		}
		if in.FirstDays == nil {
			return nil
		}
		if err := q.LockKey(ctx, "finance.debt_reminder_rules"); err != nil {
			return err
		}
		if err := s.putSetting(ctx, q, actor, keyFirstDays, *in.FirstDays, "Mốc nhắc nợ trước hạn (ngày)."); err != nil {
			return err
		}
		if err := s.putSetting(ctx, q, actor, keyRepeatDays, *in.RepeatDays, "Mốc nhắc lại sát hạn (1–3 ngày)."); err != nil {
			return err
		}
		if err := syncQuickRule(ctx, q, actor, quickRules["first"], int32(-*in.FirstDays)); err != nil {
			return err
		}
		return syncQuickRule(ctx, q, actor, quickRules["repeat"], int32(-*in.RepeatDays))
	})
	if err != nil {
		return "", err
	}
	s.log.Info("debt reminder settings updated", "by", actor.UserID)
	if in.FirstDays == nil {
		return fmt.Sprintf("Đã lưu mốc quá hạn bắt buộc liên hệ: %d ngày.", *in.MustContactDays), nil
	}
	return fmt.Sprintf("Đã lưu cấu hình nhắc nợ: nhắc trước hạn %d ngày, nhắc lại %d ngày trước hạn, quá hạn %d ngày bắt buộc liên hệ.",
		*in.FirstDays, *in.RepeatDays, *in.MustContactDays), nil
}

func syncQuickRule(ctx context.Context, q *catalogsdb.Queries, actor *iam.Access, meta [2]string, offset int32) error {
	all, err := q.ListDebtRules(ctx)
	if err != nil {
		return err
	}
	var own, same *catalogsdb.FinanceDebtReminderRule
	for i := range all {
		switch {
		case all[i].MilestoneKey == meta[0]:
			own = &all[i]
		case all[i].OffsetDays == offset && same == nil:
			same = &all[i]
		}
	}
	if same != nil {
		// Đã có mốc cùng thời điểm (vd. T-3 cũ): bật mốc đó, tắt mốc nhanh để không gửi 2 tin trong 1 ngày.
		if err := q.SetDebtRuleEnabled(ctx, catalogsdb.SetDebtRuleEnabledParams{ID: same.ID, IsEnabled: true}); err != nil {
			return err
		}
		if own != nil {
			return q.SetDebtRuleEnabled(ctx, catalogsdb.SetDebtRuleEnabledParams{ID: own.ID, IsEnabled: false})
		}
		return nil
	}
	p := catalogsdb.UpsertDebtRuleParams{MilestoneKey: meta[0], OffsetDays: offset, Title: meta[1], TemplateContent: defaultQuickTitle,
		Channels: []string{"portal", "email"}, IsEnabled: true, CreatedBy: &actor.UserID}
	if own != nil {
		p.Title, p.TemplateContent, p.Channels = own.Title, own.TemplateContent, own.Channels
	}
	_, err = q.UpsertDebtRule(ctx, p)
	return err
}
