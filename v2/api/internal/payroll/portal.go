package payroll

import (
	"cmp"
	"context"
	"fmt"
	"math"
	"slices"
	"strconv"
	"strings"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/payroll/payrolldb"
)

// "Lương của tôi" (portal.my-salary) và "Xếp hạng KPI & Thưởng" (payroll.kpi-leaderboard).

type MySalaryRecord struct {
	ID                uuid.UUID `json:"id"`
	PeriodID          uuid.UUID `json:"payroll_period_id"`
	PeriodStatus      string    `json:"period_status"`
	PeriodStatusLabel string    `json:"period_status_label"`
	PeriodTitle       string    `json:"period_title"`
	GrossIncome       int64     `json:"gross_income"`
	TotalDeductions   int64     `json:"total_deductions"`
	NetSalary         int64     `json:"net_salary"`
	Change            *int      `json:"change"`
	IsFullTime        bool      `json:"is_full_time"`
	AdjustmentNotes   *string   `json:"adjustment_notes"`
	Variant           Variant   `json:"variant"`
	EarningLines      []Line    `json:"earning_lines"`
	DeductionLines    []Line    `json:"deduction_lines"`
}

type MySalaryPage struct {
	Record            *MySalaryRecord  `json:"record"`
	PeriodOptions     []Option         `json:"period_options"`
	Penalties         []map[string]any `json:"penalties"`
	Timesheets        []TimesheetRow   `json:"timesheets"`
	AttendancePending bool             `json:"attendance_pending"`
}

// quantity: cột "Số lượng" của dòng cộng trên "Lương của tôi".
func quantity(r payrolldb.HrPayrollRecord, key string) string {
	switch key {
	case "teaching_salary":
		if q3(r) {
			return i32s(r.TeachingSessions) + " buổi"
		}
		return strings.TrimRight(strings.TrimRight(strconv.FormatFloat(r.ActualHours, 'f', 2, 64), "0"), ".") + " giờ"
	case "kpi_bonus":
		if deref(r.KpiSource) == kpiRetention {
			return i32s(r.RetentionStudents) + "/" + i32s(r.RetentionBaseStudents) + " HS"
		}
		if r.KpiScore != nil {
			return strings.TrimRight(strings.TrimRight(strconv.FormatFloat(*r.KpiScore, 'f', 2, 64), "0"), ".") + "%"
		}
		return "-"
	case "foreign_session_pay":
		return i32s(r.ForeignTeacherSessionsCount) + " buổi"
	case "commission_bonus":
		return i32s(r.CommissionClosedCount) + " HS chốt"
	}
	return "-"
}

// MySalary: phiếu lương của chính mình ở các kỳ đã duyệt / đã chi trả.
func (s *Service) MySalary(ctx context.Context, a *iam.Access, periodID *uuid.UUID) (*MySalaryPage, error) {
	rows, err := s.q.MyLockedRecords(ctx, a.UserID)
	if err != nil {
		return nil, err
	}
	out := &MySalaryPage{PeriodOptions: []Option{}, Penalties: []map[string]any{}, Timesheets: []TimesheetRow{},
		AttendancePending: s.attendance.Pending()}
	for _, r := range rows {
		out.PeriodOptions = append(out.PeriodOptions, Option{r.HrPayrollRecord.PeriodID.String(), fmt.Sprintf("Tháng %02d/%d", r.Month, r.Year)})
	}
	idx := -1
	if periodID != nil {
		for i, r := range rows {
			if r.HrPayrollRecord.PeriodID == *periodID {
				idx = i
				break
			}
		}
		if idx < 0 {
			return nil, errRecordNotFound
		}
	} else if len(rows) > 0 {
		idx = 0
	}
	if idx < 0 {
		return out, nil
	}
	cur := rows[idx]
	r := cur.HrPayrollRecord
	st, err := s.settings(ctx, s.q)
	if err != nil {
		return nil, err
	}
	gross := grossOf(r)
	rec := &MySalaryRecord{ID: r.ID, PeriodID: r.PeriodID, PeriodStatus: cur.PeriodStatus, PeriodStatusLabel: periodStatusLabels[cur.PeriodStatus],
		PeriodTitle: cur.PeriodTitle, GrossIncome: gross, TotalDeductions: deductionsOf(r), NetSalary: r.NetSalary,
		IsFullTime: deref(r.EmployeeType) == typeFulltime, AdjustmentNotes: r.AdjustmentNotes, Variant: variantOf(r),
		DeductionLines: deductionLines(r, st)}
	for _, l := range earningLines(r) {
		l.Quantity = quantity(r, l.Key)
		rec.EarningLines = append(rec.EarningLines, l)
	}
	for _, prev := range rows {
		if asDay(prev.StartDate).Before(asDay(cur.StartDate)) {
			if pg := grossOf(prev.HrPayrollRecord); pg > 0 {
				rec.Change = ptr(int(math.Round(float64(gross-pg) / float64(pg) * 100)))
			}
			break
		}
	}
	out.Record = rec
	rid := r.ID
	pens, err := s.q.RecordPenalties(ctx, &rid)
	if err != nil {
		return nil, err
	}
	for _, x := range pens {
		out.Penalties = append(out.Penalties, map[string]any{"id": x.ID, "code": x.Code, "violation_type": x.ViolationType,
			"violation_date": asDay(x.ViolationDate).Format("02/01"), "amount": x.Amount})
	}
	p := payrolldb.HrPayrollPeriod{ID: r.PeriodID, StartDate: cur.StartDate, EndDate: cur.EndDate}
	if out.Timesheets, err = s.timesheetRows(ctx, s.q, a.UserID, p); err != nil {
		return nil, err
	}
	return out, nil
}

// ───────────── Xếp hạng KPI & Thưởng ─────────────

type RetentionRow struct {
	ID                uuid.UUID `json:"id"`
	UserID            uuid.UUID `json:"user_id"`
	Name              string    `json:"name"`
	Branch            string    `json:"branch"`
	RetentionStudents int32     `json:"retention_students"`
	RetentionBase     int32     `json:"retention_base_students"`
	RetentionTier     *int64    `json:"retention_tier"`
	KpiBonus          *int64    `json:"kpi_bonus"`
	kpi               int64
}

type SalesRow struct {
	ID          uuid.UUID `json:"id"`
	Name        string    `json:"name"`
	TierName    string    `json:"tier_name"`
	Deals       int       `json:"deals"`
	Branch      string    `json:"branch"`
	Closed      int       `json:"closed"`
	Percent     float64   `json:"percent"`
	NextPercent *float64  `json:"next_percent"`
	ToNext      *int      `json:"to_next"`
	Revenue     *int64    `json:"revenue"`
	Base        *int64    `json:"base"`
	Commission  *int64    `json:"commission"`
	revenue     int64
	commission  int64
}

type LeaderboardPage struct {
	Retention       []RetentionRow `json:"retention"`
	RetentionPaging Paging         `json:"retention_paging"`
	Sales           []SalesRow     `json:"sales"`
	SalesPaging     Paging         `json:"sales_paging"`
	Period          map[string]any `json:"period"`
	Month           int            `json:"month"`
	Year            int            `json:"year"`
	SelectedPeriod  string         `json:"selected_period"`
	BranchID        *uuid.UUID     `json:"branch_id"`
	PeriodOptions   []Option       `json:"period_options"`
	Branches        []Option       `json:"branches"`
	// Số tiền (KPI đ, đơn giá bậc, doanh thu, hoa hồng) chỉ người xem bảng lương (payroll.view) thấy.
	ShowMoney bool `json:"show_money"`
}

type LeaderboardFilter struct {
	Period             string
	Month, Year        int
	BranchID           *uuid.UUID
	KpiPage, SalesPage int
}

const systemBranch = "Hệ thống MEnglish"

// scopeKpi: phạm vi dữ liệu của Xếp hạng KPI (kpi.scope_*).
const scopeKpi = "kpi"

func pageSlice[T any](rows []T, page, per int) ([]T, Paging) {
	page = pageOf(page)
	from := min(len(rows), (page-1)*per)
	to := min(len(rows), from+per)
	return append([]T{}, rows[from:to]...), paging(page, per, int64(len(rows)))
}

func (s *Service) Leaderboard(ctx context.Context, a *iam.Access, f LeaderboardFilter) (*LeaderboardPage, error) {
	now := s.now().In(vnZone)
	month, year := f.Month, f.Year
	var pm, py int
	if n, _ := fmt.Sscanf(f.Period, "%4d-%2d", &py, &pm); n == 2 && len(f.Period) == 7 {
		month, year = pm, py
	}
	if month == 0 {
		month = int(now.Month())
	}
	if year == 0 {
		year = now.Year()
	}
	month, year = min(12, max(1, month)), min(2100, max(2020, year))
	start := time.Date(year, time.Month(month), 1, 0, 0, 0, 0, vnZone)
	end := start.AddDate(0, 1, -1)
	// Phạm vi: dòng của nhân sự trong phạm vi "kpi" (của tôi / chi nhánh của tôi / mọi nhân sự, như KpiController);
	// chi nhánh lọc ngoài phạm vi thì bỏ lọc (vẫn chỉ thấy dữ liệu trong phạm vi).
	showMoney := a.Can(PermView)
	staffSet, err := iam.ScopedStaff(ctx, s.pool, a, scopeKpi)
	if err != nil {
		return nil, err
	}
	if f.BranchID != nil && !a.CoversBranch(scopeKpi, f.BranchID) {
		f.BranchID = nil
	}
	out := &LeaderboardPage{Month: month, Year: year, SelectedPeriod: fmt.Sprintf("%04d-%02d", year, month), BranchID: f.BranchID,
		Retention: []RetentionRow{}, Sales: []SalesRow{}, PeriodOptions: []Option{}, Branches: []Option{}, ShowMoney: showMoney}

	// 1. KPI giữ học sinh từ phiếu lương của kỳ.
	var retention []RetentionRow
	p, err := s.q.PeriodByMonth(ctx, payrolldb.PeriodByMonthParams{Year: int16(year), Month: int16(month)})
	switch {
	case err == nil:
		out.Period = map[string]any{"locked": locked(p.Status)}
		rows, err := s.q.PeriodRecords(ctx, payrolldb.PeriodRecordsParams{PeriodID: p.ID, AllScope: true, Branches: []uuid.UUID{}})
		if err != nil {
			return nil, err
		}
		for _, row := range rows {
			r := row.HrPayrollRecord
			if deref(r.KpiSource) != kpiRetention || !staffSet.Has(r.UserID) || (f.BranchID != nil && row.BranchID != *f.BranchID) {
				continue
			}
			b := row.BranchName
			if b == "" {
				b = systemBranch
			}
			retention = append(retention, RetentionRow{ID: r.ID, UserID: r.UserID, Name: row.FullName, Branch: b,
				RetentionStudents: r.RetentionStudents, RetentionBase: r.RetentionBaseStudents, RetentionTier: r.RetentionTier,
				KpiBonus: ptr(r.KpiBonus), kpi: r.KpiBonus})
		}
		slices.SortStableFunc(retention, func(x, y RetentionRow) int {
			if x.kpi != y.kpi {
				return cmp.Compare(y.kpi, x.kpi)
			}
			return int(y.RetentionStudents - x.RetentionStudents)
		})
	case !isNoRows(err):
		return nil, err
	}
	out.Retention, out.RetentionPaging = pageSlice(retention, f.KpiPage, 20)
	if !showMoney {
		for i := range out.Retention {
			out.Retention[i].RetentionTier, out.Retention[i].KpiBonus = nil, nil
		}
	}

	// 2. Hoa hồng tuyển sinh (trước gate kép): người có HS chốt / học phí thu trong tháng + Tư vấn & Học vụ đang hoạt động.
	summary, tiers, err := s.summaryByOwner(ctx, s.q, start, end, nil)
	if err != nil {
		return nil, err
	}
	active, err := s.q.StaffWithRoles(ctx, []string{"sales_consultant", "academic_staff"})
	if err != nil {
		return nil, err
	}
	type person struct {
		name, branch string
		branchID     uuid.UUID
	}
	people := map[uuid.UUID]person{}
	for _, u := range active {
		people[u.ID] = person{u.FullName, u.BranchName, u.BranchID}
	}
	var extra []uuid.UUID
	for id := range summary {
		if _, ok := people[id]; !ok {
			extra = append(extra, id)
		}
	}
	if len(extra) > 0 {
		brief, err := s.q.UsersBrief(ctx, extra)
		if err != nil {
			return nil, err
		}
		branches, err := s.q.UserBranchIDs(ctx, extra)
		if err != nil {
			return nil, err
		}
		first := map[uuid.UUID]uuid.UUID{}
		for _, b := range branches {
			if _, ok := first[b.UserID]; !ok {
				first[b.UserID] = b.BranchID
			}
		}
		for _, u := range brief {
			people[u.ID] = person{u.FullName, u.BranchName, first[u.ID]}
		}
	}
	var sales []SalesRow
	for id, pp := range people {
		if !staffSet.Has(id) || (f.BranchID != nil && pp.branchID != *f.BranchID) {
			continue
		}
		row, ok := summary[id]
		if !ok {
			row = ownerSummary{milestone: milestoneFor(tiers, 0)}
		}
		b := pp.branch
		if b == "" {
			b = systemBranch
		}
		sales = append(sales, SalesRow{ID: id, Name: pp.name, TierName: row.TierName, Deals: row.Students, Branch: b, Closed: row.Closed,
			Percent: row.Percent, NextPercent: row.NextPercent, ToNext: row.ToNext, Revenue: ptr(row.Collected), Base: ptr(row.Base),
			Commission: ptr(row.Amount), revenue: row.Collected, commission: row.Amount})
	}
	slices.SortStableFunc(sales, func(x, y SalesRow) int {
		switch {
		case x.commission != y.commission:
			return cmp.Compare(y.commission, x.commission)
		case x.revenue != y.revenue:
			return cmp.Compare(y.revenue, x.revenue)
		}
		return strings.Compare(x.Name, y.Name)
	})
	out.Sales, out.SalesPaging = pageSlice(sales, f.SalesPage, 20)
	if !showMoney {
		for i := range out.Sales {
			out.Sales[i].Revenue, out.Sales[i].Base, out.Sales[i].Commission = nil, nil, nil
		}
	}

	opts := map[string]string{now.Format("2006-01"): "Tháng " + now.Format("01/2006"), out.SelectedPeriod: fmt.Sprintf("Tháng %02d/%04d", month, year)}
	all, err := s.q.AllPeriods(ctx)
	if err != nil {
		return nil, err
	}
	for _, x := range all {
		opts[fmt.Sprintf("%04d-%02d", x.Year, x.Month)] = fmt.Sprintf("Tháng %02d/%04d", x.Month, x.Year)
	}
	keys := make([]string, 0, len(opts))
	for k := range opts {
		keys = append(keys, k)
	}
	slices.Sort(keys)
	slices.Reverse(keys)
	for _, k := range keys {
		out.PeriodOptions = append(out.PeriodOptions, Option{k, opts[k]})
	}
	branches, err := s.q.BranchOptions(ctx)
	if err != nil {
		return nil, err
	}
	for _, b := range branches {
		if a.CoversBranch(scopeKpi, &b.ID) {
			out.Branches = append(out.Branches, Option{b.ID.String(), b.Name})
		}
	}
	return out, nil
}
