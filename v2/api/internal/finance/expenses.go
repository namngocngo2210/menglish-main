package finance

import (
	"context"
	"fmt"
	"math"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/finance/financedb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// Sổ khoản chi vận hành (FinanceController::expenses / storeExpense / updateExpense / destroyExpense / exportExpenses).

// Expense: một khoản chi tự nhập.
type Expense struct {
	ID            uuid.UUID  `json:"id"`
	ExpenseDate   string     `json:"expense_date"` // YYYY-MM-DD
	Title         string     `json:"title"`
	Amount        int64      `json:"amount"`
	PaymentMethod string     `json:"payment_method"`
	BranchID      *uuid.UUID `json:"branch_id"`
	BranchName    *string    `json:"branch_name"`
	Category      string     `json:"category"`
	CategoryLabel string     `json:"category_label"`
	Notes         *string    `json:"notes"`
	CreatorName   *string    `json:"creator_name"`
	CreatedAt     string     `json:"created_at"` // "d/m H:i"
}

// SalaryRow: dòng chi lương tự động (khóa, không sửa / xóa).
type SalaryRow struct {
	ExpenseDate   string `json:"expense_date"` // d/m/Y
	DateSub       string `json:"date_sub"`
	Title         string `json:"title"`
	Description   string `json:"description"`
	Amount        int64  `json:"amount"`
	StaffCount    int    `json:"staff_count"`
	PaymentMethod string `json:"payment_method"`
	BranchName    string `json:"branch_name"`
	Notes         string `json:"notes"`
}

// ExpensesPage: dữ liệu màn Sổ khoản chi vận hành (props của Finance/Expenses/Index.vue).
type ExpensesPage struct {
	BranchScoped         bool        `json:"branch_scoped"`
	Month                string      `json:"month"`
	BranchID             string      `json:"branch_id"`
	Search               string      `json:"search"`
	Branches             []BranchRef `json:"branches"`
	Expenses             []Expense   `json:"expenses"`
	AutoSalaryRow        *SalaryRow  `json:"auto_salary_row"`
	GrandTotalExpense    int64       `json:"grand_total_expense"`
	AutoSalaryAmount     int64       `json:"auto_salary_amount"`
	AutoSalaryStaffCount int         `json:"auto_salary_staff_count"`
	TotalManualExpense   int64       `json:"total_manual_expense"`
	ManualExpensesCount  int         `json:"manual_expenses_count"`
	TotalItemsCount      int         `json:"total_items_count"`
	PrevMonthLabel       string      `json:"prev_month_label"`
	PercentDiff          float64     `json:"percent_diff"`
	IsDecreased          bool        `json:"is_decreased"`
	TotalTransfer        int64       `json:"total_transfer"`
	TotalCash            int64       `json:"total_cash"`
	TransferPercent      float64     `json:"transfer_percent"`
	CashPercent          float64     `json:"cash_percent"`
	MonthOptions         []Option    `json:"month_options"`
	Categories           []Option    `json:"categories"`
	Today                string      `json:"today"`
	CanManage            bool        `json:"can_manage"`
	PayrollPending       string      `json:"payroll_pending,omitempty"`
}

// ExpenseFilter: bộ lọc sổ chi (kỳ tháng "YYYY-MM", chi nhánh "all" / id, tìm kiếm).
type ExpenseFilter struct {
	Month    string
	BranchID string
	Search   string
}

// likePattern: chuỗi tìm kiếm đã thoát ký tự đặc biệt của ILIKE; rỗng → nil.
func likePattern(s string) *string {
	s = strings.TrimSpace(s)
	if s == "" {
		return nil
	}
	s = strings.NewReplacer(`\`, `\\`, `%`, `\%`, `_`, `\_`).Replace(s)
	return &s
}

func categoryOptions() []Option {
	return []Option{
		{"mat_bang_tien_ich", "Mặt bằng & Tiện ích (Thuê nhà, điện, nước, internet...)"},
		{"giao_trinh_van_hanh", "In ấn & Vận hành lớp (Giáo trình, VPP, điều hòa, nước uống...)"},
		{"khac", "Chi phí khác"},
	}
}

func toExpense(r financedb.ListExpensesRow) Expense {
	return Expense{
		ID: r.ID, ExpenseDate: r.ExpenseDate.Format("2006-01-02"), Title: r.Title, Amount: r.Amount,
		PaymentMethod: r.PaymentMethod, BranchID: r.BranchID, BranchName: r.BranchName, Category: r.Category,
		CategoryLabel: categoryLabels[r.Category], Notes: r.Notes, CreatorName: r.CreatorName,
		CreatedAt: r.CreatedAt.In(vnZone).Format("02/01 15:04"),
	}
}

// listRows: khoản chi tự nhập của tháng theo bộ lọc (ascending: thứ tự file xuất).
func (s *Service) listRows(ctx context.Context, start time.Time, branch *uuid.UUID, search string, ascending bool) ([]financedb.ListExpensesRow, error) {
	return s.q.ListExpenses(ctx, financedb.ListExpensesParams{
		FromDate: start, ToDate: monthEnd(start), BranchID: branch, Q: likePattern(search), Ascending: ascending,
	})
}

// manualTotal: tổng khoản chi tự nhập của tháng (không theo ô tìm kiếm), lọc chi nhánh.
func (s *Service) manualTotal(ctx context.Context, start time.Time, branch *uuid.UUID) (int64, error) {
	sums, err := s.expenseSums(ctx, start)
	if err != nil {
		return 0, err
	}
	var total int64
	for _, e := range sums {
		if sameBranch(e.BranchID, branch) {
			total += e.Amount
		}
	}
	return total, nil
}

func (s *Service) expenseSums(ctx context.Context, start time.Time) ([]ExpenseSum, error) {
	rows, err := s.q.ExpenseSums(ctx, financedb.ExpenseSumsParams{FromDate: start, ToDate: monthEnd(start)})
	if err != nil {
		return nil, err
	}
	out := make([]ExpenseSum, 0, len(rows))
	for _, r := range rows {
		out = append(out, ExpenseSum{BranchID: r.BranchID, Category: r.Category, PaymentMethod: r.PaymentMethod, Items: int(r.Items), Amount: r.Amount})
	}
	return out, nil
}

// Expenses: màn Sổ khoản chi vận hành — khoản chi tự nhập + dòng chi lương tự động, tổng chi, so với tháng trước, cơ cấu
// hình thức chi.
func (s *Service) Expenses(ctx context.Context, a *iam.Access, f ExpenseFilter) (*ExpensesPage, error) {
	sc, err := scope(a)
	if err != nil {
		return nil, err
	}
	now := s.now()
	start := parseMonth(f.Month, now)
	branch := resolveBranch(sc, f.BranchID)
	branches, err := s.visibleBranches(ctx, sc)
	if err != nil {
		return nil, err
	}
	rows, err := s.listRows(ctx, start, branch, f.Search, false)
	if err != nil {
		return nil, err
	}
	p := &ExpensesPage{
		BranchScoped: sc != nil, Month: start.Format("2006-01"), BranchID: branchKey(branch),
		Search: strings.TrimSpace(f.Search), Branches: branches, Expenses: make([]Expense, 0, len(rows)),
		MonthOptions: monthOptions(now), Categories: categoryOptions(), Today: now.In(vnZone).Format("2006-01-02"),
		CanManage: a.Can(PermManage),
	}
	var cashManual, transferManual int64
	for _, r := range rows {
		p.Expenses = append(p.Expenses, toExpense(r))
		p.TotalManualExpense += r.Amount
		switch r.PaymentMethod {
		case "tien_mat":
			cashManual += r.Amount
		case "chuyen_khoan":
			transferManual += r.Amount
		}
	}
	p.ManualExpensesCount = len(rows)

	// Chi lương tự động theo tháng đã chọn (chỉ hiện khi > 0)
	if s.payroll.Pending() {
		p.PayrollPending = msgPayrollPending
	}
	sal, err := s.payroll.Salary(ctx, start, branch)
	if err != nil {
		return nil, err
	}
	if sal != nil && sal.Amount > 0 {
		p.AutoSalaryAmount, p.AutoSalaryStaffCount = sal.Amount, sal.StaffCount
		target := fmt.Sprintf("Toàn hệ thống (%d CS)", len(branches))
		if branch != nil {
			target = "Chi nhánh được chọn"
			for _, b := range branches {
				if b.ID == *branch {
					target = b.Name
				}
			}
		}
		end := monthEnd(start)
		if sal.EndDate != nil {
			end = *sal.EndDate
		}
		p.AutoSalaryRow = &SalaryRow{
			ExpenseDate: end.Format("02/01/2006"), DateSub: "Cuối kỳ lương", Title: "Chi lương tháng " + start.Format("01/2006"),
			Description: "Tổng hợp từ bảng lương (trạng thái: Đã chốt, Đã trả) cho toàn bộ GV, TA và Nhân viên",
			Amount:      sal.Amount, StaffCount: sal.StaffCount, PaymentMethod: "chuyen_khoan", BranchName: target,
			Notes: "Bảng lương mã " + sal.Code + ". Đã hạch toán chi trả.",
		}
	}
	p.GrandTotalExpense = p.TotalManualExpense + p.AutoSalaryAmount

	// So với tháng trước: tổng chi tự nhập (không theo ô tìm kiếm) + chi lương
	prevStart := start.AddDate(0, -1, 0)
	p.PrevMonthLabel = prevStart.Format("01/2006")
	prev, err := s.manualTotal(ctx, prevStart, branch)
	if err != nil {
		return nil, err
	}
	if ps, err := s.payroll.Salary(ctx, prevStart, branch); err != nil {
		return nil, err
	} else if ps != nil {
		prev += ps.Amount
	}
	if prev > 0 {
		p.PercentDiff = round1(float64(p.GrandTotalExpense-prev) / float64(prev) * 100)
		p.IsDecreased = p.PercentDiff < 0
	}

	// Cơ cấu hình thức chi: chi lương mặc định là chuyển khoản
	p.TotalTransfer = transferManual + p.AutoSalaryAmount
	p.TotalCash = cashManual
	if p.GrandTotalExpense > 0 {
		p.TransferPercent = share(p.TotalTransfer, p.GrandTotalExpense, 0)
		p.CashPercent = 100 - p.TransferPercent
	}
	p.TotalItemsCount = p.ManualExpensesCount
	if p.AutoSalaryRow != nil {
		p.TotalItemsCount++
	}
	return p, nil
}

// ───────────── Thêm / sửa / xóa ─────────────

// ExpenseInput: form Thêm / Sửa khoản chi (6 ô theo spec).
type ExpenseInput struct {
	ExpenseDate   string   `json:"expense_date"`
	Title         string   `json:"title"`
	Amount        *float64 `json:"amount"`
	PaymentMethod string   `json:"payment_method"`
	BranchID      string   `json:"branch_id"`
	Notes         *string  `json:"notes"`
	Category      string   `json:"category"`
}

type expenseValues struct {
	date     time.Time
	title    string
	amount   int64
	method   string
	branch   uuid.UUID
	category string
	notes    *string
}

// maxAmount: decimal(15,2) của Laravel.
const maxAmount = 9_999_999_999_999

func (s *Service) validate(ctx context.Context, in ExpenseInput) (expenseValues, error) {
	var v expenseValues
	d := strings.TrimSpace(in.ExpenseDate)
	if d == "" {
		return v, iam.Invalid("Vui lòng chọn ngày chi.")
	}
	date, err := time.ParseInLocation("2006-01-02", d, vnZone)
	if err != nil {
		return v, iam.Invalid("Ngày chi không hợp lệ.")
	}
	v.date = date
	v.title = strings.TrimSpace(in.Title)
	if v.title == "" {
		return v, iam.Invalid("Vui lòng nhập nội dung khoản chi.")
	}
	if utf8.RuneCountInString(v.title) > 255 {
		return v, iam.Invalid("Nội dung khoản chi tối đa 255 ký tự.")
	}
	if in.Amount == nil {
		return v, iam.Invalid("Vui lòng nhập số tiền chi.")
	}
	if *in.Amount < 1000 {
		return v, iam.Invalid("Số tiền chi tối thiểu là 1.000 đ.")
	}
	if *in.Amount > maxAmount {
		return v, iam.Invalid("Số tiền chi quá lớn.")
	}
	v.amount = int64(math.Round(*in.Amount))
	switch in.PaymentMethod {
	case "":
		return v, iam.Invalid("Vui lòng chọn hình thức chi.")
	case "chuyen_khoan", "tien_mat":
		v.method = in.PaymentMethod
	default:
		return v, iam.Invalid("Hình thức chi không hợp lệ.")
	}
	if strings.TrimSpace(in.BranchID) == "" {
		return v, iam.Invalid("Vui lòng chọn chi nhánh áp dụng.")
	}
	b, err := uuid.Parse(strings.TrimSpace(in.BranchID))
	if err != nil {
		return v, iam.Invalid("Chi nhánh đã chọn không tồn tại.")
	}
	ok, err := s.q.BranchExists(ctx, b)
	if err != nil {
		return v, err
	}
	if !ok {
		return v, iam.Invalid("Chi nhánh đã chọn không tồn tại.")
	}
	v.branch = b
	if in.Notes != nil {
		if n := strings.TrimSpace(*in.Notes); n != "" {
			if utf8.RuneCountInString(n) > 1000 {
				return v, iam.Invalid("Ghi chú tối đa 1000 ký tự.")
			}
			v.notes = &n
		}
	}
	switch in.Category {
	case "":
		// Tự động phân loại theo nội dung nếu không chọn
		v.category = autoCategory(v.title)
	case "mat_bang_tien_ich", "giao_trinh_van_hanh", "khac":
		v.category = in.Category
	default:
		return v, iam.Invalid("Phân loại chi phí không hợp lệ.")
	}
	return v, nil
}

// authorizeBranch: người bị giới hạn chi nhánh chỉ quản lý khoản chi của chi nhánh mình.
func authorizeBranch(sc []uuid.UUID, branch *uuid.UUID) error {
	if sc == nil {
		return nil
	}
	if branch == nil {
		return ErrOutsideBranch
	}
	for _, id := range sc {
		if id == *branch {
			return nil
		}
	}
	return ErrOutsideBranch
}

func expenseSnapshot(e financedb.FinanceExpense) map[string]any {
	return map[string]any{
		"expense_date": e.ExpenseDate.Format("2006-01-02"), "title": e.Title, "amount": e.Amount,
		"payment_method": e.PaymentMethod, "branch_id": e.BranchID, "category": e.Category, "notes": e.Notes,
	}
}

func expenseEntry(a *iam.Access, e financedb.FinanceExpense, event, action, desc string, changes audit.Changes) audit.Entry {
	return audit.Entry{
		ActorID: &a.UserID, Module: audit.ModuleTuition, Event: event, Action: action,
		SubjectType: "operating_expense", SubjectID: e.ID.String(),
		SubjectLabel: fmt.Sprintf("%s (%s đ)", e.Title, vnd(e.Amount)), BranchID: e.BranchID,
		Description: desc, Changes: changes,
	}
}

// CreateExpense: thêm khoản chi (lưu ngay, không cần duyệt).
func (s *Service) CreateExpense(ctx context.Context, a *iam.Access, in ExpenseInput) (string, error) {
	sc, err := scope(a)
	if err != nil {
		return "", err
	}
	v, err := s.validate(ctx, in)
	if err != nil {
		return "", err
	}
	if err := authorizeBranch(sc, &v.branch); err != nil {
		return "", err
	}
	err = pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
		e, err := s.q.WithTx(tx).CreateExpense(ctx, financedb.CreateExpenseParams{
			ExpenseDate: v.date, Title: v.title, Amount: v.amount, PaymentMethod: v.method, BranchID: &v.branch,
			Category: v.category, Notes: v.notes, CreatedBy: &a.UserID,
		})
		if err != nil {
			return err
		}
		return audit.Record(ctx, tx, expenseEntry(a, e, audit.EventCreated, "finance.expenses.store",
			"Thêm khoản chi vận hành: "+e.Title, audit.Diff(nil, expenseSnapshot(e))))
	})
	if err != nil {
		return "", err
	}
	s.log.Info("finance expense created", "by", a.UserID, "amount", v.amount, "branch", v.branch)
	return "Khoản chi đã được lưu thành công vào sổ chi vận hành!", nil
}

// UpdateExpense: sửa khoản chi tự nhập (kiểm chi nhánh cũ và mới).
func (s *Service) UpdateExpense(ctx context.Context, a *iam.Access, id uuid.UUID, in ExpenseInput) (string, error) {
	sc, err := scope(a)
	if err != nil {
		return "", err
	}
	before, err := s.q.GetExpense(ctx, id)
	if isNoRows(err) {
		return "", ErrNotFound
	}
	if err != nil {
		return "", err
	}
	v, err := s.validate(ctx, in)
	if err != nil {
		return "", err
	}
	if err := authorizeBranch(sc, before.BranchID); err != nil {
		return "", err
	}
	if err := authorizeBranch(sc, &v.branch); err != nil {
		return "", err
	}
	err = pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
		e, err := s.q.WithTx(tx).UpdateExpense(ctx, financedb.UpdateExpenseParams{
			ID: id, ExpenseDate: v.date, Title: v.title, Amount: v.amount, PaymentMethod: v.method, BranchID: &v.branch,
			Category: v.category, Notes: v.notes,
		})
		if isNoRows(err) {
			return ErrNotFound
		}
		if err != nil {
			return err
		}
		return audit.Record(ctx, tx, expenseEntry(a, e, audit.EventUpdated, "finance.expenses.update",
			"Cập nhật khoản chi vận hành: "+e.Title, audit.Diff(expenseSnapshot(before), expenseSnapshot(e))))
	})
	if err != nil {
		return "", err
	}
	s.log.Info("finance expense updated", "by", a.UserID, "id", id)
	return "Đã cập nhật thông tin khoản chi thành công!", nil
}

// DeleteExpense: xóa (mềm) khoản chi tự nhập.
func (s *Service) DeleteExpense(ctx context.Context, a *iam.Access, id uuid.UUID) (string, error) {
	sc, err := scope(a)
	if err != nil {
		return "", err
	}
	e, err := s.q.GetExpense(ctx, id)
	if isNoRows(err) {
		return "", ErrNotFound
	}
	if err != nil {
		return "", err
	}
	if err := authorizeBranch(sc, e.BranchID); err != nil {
		return "", err
	}
	err = pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
		n, err := s.q.WithTx(tx).DeleteExpense(ctx, id)
		if err != nil {
			return err
		}
		if n == 0 {
			return ErrNotFound
		}
		return audit.Record(ctx, tx, expenseEntry(a, e, audit.EventDeleted, "finance.expenses.destroy",
			"Xóa khoản chi vận hành: "+e.Title, audit.Diff(expenseSnapshot(e), nil)))
	})
	if err != nil {
		return "", err
	}
	s.log.Info("finance expense deleted", "by", a.UserID, "id", id)
	return fmt.Sprintf("Đã xóa khoản chi: \"%s\" khỏi sổ chi vận hành.", e.Title), nil
}

// ───────────── Xuất file ─────────────

// Sheet: file CSV (dòng tiêu đề + dòng dữ liệu) đã dựng sẵn.
type Sheet struct {
	Name string
	Rows [][]string
}

// ExportExpenses: sổ khoản chi của tháng theo bộ lọc (CSV UTF-8 BOM như Laravel), ngày tăng dần.
func (s *Service) ExportExpenses(ctx context.Context, a *iam.Access, f ExpenseFilter) (*Sheet, error) {
	sc, err := scope(a)
	if err != nil {
		return nil, err
	}
	start := parseMonth(f.Month, s.now())
	branch := resolveBranch(sc, f.BranchID)
	rows, err := s.listRows(ctx, start, branch, f.Search, true)
	if err != nil {
		return nil, err
	}
	out := &Sheet{Name: "So_Khoan_Chi_Van_Hanh_" + start.Format("2006-01") + ".csv", Rows: [][]string{
		{"SỔ KHOẢN CHI VẬN HÀNH - MENGLISH ADMIN"},
		{"Kỳ tháng: " + start.Format("01/2006")},
		{},
		{"STT", "Ngày chi", "Nội dung khoản chi", "Số tiền (VNĐ)", "Hình thức", "Chi nhánh", "Người lập", "Ghi chú & Chứng từ"},
	}}
	for i, r := range rows {
		method := "Tiền mặt"
		if r.PaymentMethod == "chuyen_khoan" {
			method = "Chuyển khoản"
		}
		out.Rows = append(out.Rows, safeRow([]string{
			fmt.Sprint(i + 1), r.ExpenseDate.Format("02/01/2006"), r.Title, vnd(r.Amount), method,
			deref(r.BranchName, "Toàn hệ thống"), deref(r.CreatorName, "Admin"), deref(r.Notes, ""),
		}))
	}
	return out, nil
}

func deref(p *string, def string) string {
	if p == nil {
		return def
	}
	return *p
}
