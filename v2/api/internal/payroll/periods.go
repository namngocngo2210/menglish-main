package payroll

import (
	"context"
	"errors"
	"fmt"
	"slices"
	"strconv"
	"strings"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5/pgconn"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/payroll/payrolldb"
)

// Kỳ lương: danh sách, khởi tạo, bảng lương của kỳ, duyệt, chi trả, xuất, bảng theo khối (PayrollController).

const (
	payDayFrom = 10
	payDayTo   = 15
)

// ───────────── Lịch chốt lương ─────────────

type periodClock struct {
	end, closeAt time.Time
	days         int
}

func (s *Service) clockOf(ctx context.Context, q *payrolldb.Queries, p payrolldb.HrPayrollPeriod) periodClock {
	days := s.closeAfterDays(ctx, q)
	end := asDay(p.EndDate)
	return periodClock{end: end, closeAt: endOfDay(end.AddDate(0, 0, days)), days: days}
}

func (c periodClock) canApprove(now time.Time) bool { return now.After(c.closeAt) }

func (c periodClock) payWindow() (time.Time, time.Time) {
	next := time.Date(c.end.Year(), c.end.Month(), 1, 0, 0, 0, 0, vnZone).AddDate(0, 1, 0)
	return next.AddDate(0, 0, payDayFrom-1), endOfDay(next.AddDate(0, 0, payDayTo-1))
}

func (c periodClock) inPayWindow(now time.Time) bool {
	from, to := c.payWindow()
	return !now.Before(from) && !now.After(to)
}

// Calendar: mốc lịch chốt KPI / công / lỗi và khung trả lương.
type Calendar struct {
	KpiCloseOn        string `json:"kpi_close_on"`
	AttendanceCloseOn string `json:"attendance_close_on"`
	ViolationCloseOn  string `json:"violation_close_on"`
	ApproveFrom       string `json:"approve_from"`
	CloseAfterDays    int    `json:"close_after_days"`
	PayFrom           string `json:"pay_from"`
	PayTo             string `json:"pay_to"`
	CanApprove        bool   `json:"can_approve"`
	InPayWindow       bool   `json:"in_pay_window"`
}

func (c periodClock) calendar(now time.Time) Calendar {
	from, to := c.payWindow()
	f := func(t time.Time) string { return t.In(vnZone).Format("02/01/2006") }
	return Calendar{KpiCloseOn: f(c.end), AttendanceCloseOn: f(c.closeAt), ViolationCloseOn: f(c.closeAt),
		ApproveFrom: f(c.closeAt.Add(time.Microsecond)), CloseAfterDays: c.days, PayFrom: f(from), PayTo: f(to),
		CanApprove: c.canApprove(now), InPayWindow: c.inPayWindow(now)}
}

// ───────────── Danh sách kỳ ─────────────

type PeriodListRow struct {
	ID          uuid.UUID `json:"id"`
	Code        string    `json:"code"`
	Title       string    `json:"title"`
	StartDate   string    `json:"start_date"`
	EndDate     string    `json:"end_date"`
	Staff       int       `json:"staff"`
	Hours       float64   `json:"hours"`
	Amount      int64     `json:"amount"`
	Status      string    `json:"status"`
	StatusLabel string    `json:"status_label"`
	StatusColor string    `json:"status_color"`
}

type PeriodsPage struct {
	Periods       []PeriodListRow `json:"periods"`
	Paging        Paging          `json:"paging"`
	AllPeriods    []Option        `json:"all_periods"`
	StatusOptions []Option        `json:"status_options"`
	DefaultMonth  int             `json:"default_month"`
	DefaultYear   int             `json:"default_year"`
}

var periodStatuses = []string{"draft", "reviewing", "approved", "paid"}

func (s *Service) Periods(ctx context.Context, a *iam.Access, search, status string, page int) (*PeriodsPage, error) {
	search = strings.TrimSpace(search)
	if !slices.Contains(periodStatuses, status) {
		status = ""
	}
	if runeLen(search) > 100 {
		return nil, iam.Invalid("Từ khóa tìm kiếm tối đa 100 ký tự.")
	}
	const per = 12
	page = pageOf(page)
	rows, err := s.q.ListPeriods(ctx, payrolldb.ListPeriodsParams{Status: status, Search: search, Lim: per, Off: int32((page - 1) * per)})
	if err != nil {
		return nil, err
	}
	total, err := s.q.CountPeriods(ctx, payrolldb.CountPeriodsParams{Status: status, Search: search})
	if err != nil {
		return nil, err
	}
	sc := scopeOf(a)
	ids := idsOf(rows, func(p payrolldb.HrPayrollPeriod) uuid.UUID { return p.ID })
	totals, err := s.q.ScopedPeriodTotals(ctx, payrolldb.ScopedPeriodTotalsParams{PeriodIds: ids, AllScope: sc.all, Me: sc.me, Branches: sc.branches})
	if err != nil {
		return nil, err
	}
	byID := map[uuid.UUID]payrolldb.ScopedPeriodTotalsRow{}
	for _, t := range totals {
		byID[t.PeriodID] = t
	}
	out := &PeriodsPage{Periods: []PeriodListRow{}, Paging: paging(page, per, total), AllPeriods: []Option{},
		DefaultMonth: int(s.now().In(vnZone).Month()), DefaultYear: s.now().In(vnZone).Year()}
	for _, st := range periodStatuses {
		out.StatusOptions = append(out.StatusOptions, Option{st, periodStatusLabels[st]})
	}
	for _, p := range rows {
		t := byID[p.ID]
		row := PeriodListRow{ID: p.ID, Code: p.Code, Title: p.Title, StartDate: asDay(p.StartDate).Format("2006-01-02"),
			EndDate: asDay(p.EndDate).Format("2006-01-02"), Status: p.Status, StatusLabel: periodStatusLabels[p.Status],
			StatusColor: periodStatusColors[p.Status], Staff: int(t.Staff), Hours: t.Hours, Amount: t.Amount}
		if sc.all {
			row.Hours, row.Amount = p.TotalHours, p.TotalAmount
			if t.Staff == 0 {
				row.Staff = int(p.TotalStaff)
			}
		}
		out.Periods = append(out.Periods, row)
	}
	all, err := s.q.AllPeriods(ctx)
	if err != nil {
		return nil, err
	}
	for _, p := range all {
		out.AllPeriods = append(out.AllPeriods, Option{p.ID.String(), p.Title + " (" + p.Code + ")"})
	}
	return out, nil
}

// PeriodInput: khởi tạo kỳ lương theo tháng.
type PeriodInput struct {
	Month *int `json:"month"`
	Year  *int `json:"year"`
}

func (s *Service) CreatePeriod(ctx context.Context, a *iam.Access, in PeriodInput) (uuid.UUID, string, error) {
	switch {
	case in.Month == nil:
		return uuid.Nil, "", iam.Invalid("Vui lòng chọn tháng.")
	case *in.Month < 1 || *in.Month > 12:
		return uuid.Nil, "", iam.Invalid("Tháng phải từ 1 đến 12.")
	case in.Year == nil:
		return uuid.Nil, "", iam.Invalid("Vui lòng nhập năm.")
	case *in.Year < 2025 || *in.Year > 2100:
		return uuid.Nil, "", iam.Invalid("Năm phải từ 2025 đến 2100.")
	}
	m, y := *in.Month, *in.Year
	dup := iam.Invalid(fmt.Sprintf("Đã tồn tại bảng lương cho tháng %d/%d.", m, y))
	start := time.Date(y, time.Month(m), 1, 0, 0, 0, 0, time.UTC)
	end := start.AddDate(0, 1, -1)
	var p payrolldb.HrPayrollPeriod
	err := s.tx(ctx, func(tx pgxTx, q *payrolldb.Queries) error {
		if _, err := q.PeriodByMonth(ctx, payrolldb.PeriodByMonthParams{Year: int16(y), Month: int16(m)}); err == nil {
			return dup
		} else if !isNoRows(err) {
			return err
		}
		var err error
		p, err = q.InsertPeriod(ctx, payrolldb.InsertPeriodParams{Code: fmt.Sprintf("PR-%d-%02d", y, m), Title: fmt.Sprintf("Bảng lương Tháng %d/%d", m, y),
			Month: int16(m), Year: int16(y), StartDate: start, EndDate: end, CreatedBy: actorPtr(a)})
		if err != nil {
			var pe *pgconn.PgError
			if errors.As(err, &pe) && pe.Code == "23505" {
				return dup
			}
			return err
		}
		if err := s.runCalculation(ctx, q, p); err != nil {
			return err
		}
		return audit.Record(ctx, tx, audit.Entry{ActorID: actorPtr(a), Module: audit.ModulePayroll, Event: audit.EventCreated,
			Action: "payroll_period.create", SubjectType: "payroll_period", SubjectID: p.ID.String(), SubjectLabel: p.Title,
			Description: "Khởi tạo và tính " + p.Title})
	})
	if err != nil {
		return uuid.Nil, "", err
	}
	s.log.Info("payroll period created", "by", a.UserID, "period", p.ID)
	return p.ID, "Đã khởi tạo và tự động tính toán bảng lương " + p.Title + " từ dữ liệu chấm công & hoa hồng!", nil
}

// ───────────── Bảng lương của kỳ ─────────────

func (s *Service) period(ctx context.Context, q *payrolldb.Queries, id uuid.UUID) (payrolldb.HrPayrollPeriod, error) {
	p, err := q.GetPeriod(ctx, id)
	if isNoRows(err) {
		return p, errPeriodNotFound
	}
	return p, err
}

func (s *Service) scopedRecords(ctx context.Context, q *payrolldb.Queries, a *iam.Access, periodID uuid.UUID) ([]payrolldb.PeriodRecordsRow, error) {
	sc := scopeOf(a)
	return q.PeriodRecords(ctx, payrolldb.PeriodRecordsParams{PeriodID: periodID, AllScope: sc.all, Me: sc.me, Branches: sc.branches})
}

func infoOf(r payrolldb.PeriodRecordsRow) userInfo {
	return userInfo{Name: r.FullName, Email: r.UserEmail, Code: r.UserCode, Branch: r.BranchName}
}

// hasChanges: dữ liệu ảnh hưởng lương đổi sau lần tính gần nhất (hasChangesSinceCalculation).
func (s *Service) hasChanges(ctx context.Context, q *payrolldb.Queries, p payrolldb.HrPayrollPeriod) (bool, error) {
	if p.CalculatedAt == nil {
		return true, nil
	}
	start, end := asDay(p.StartDate), asDay(p.EndDate)
	from, to := dayOf(start), endOfDay(end)
	changed, err := q.ChangesSinceCalculation(ctx, payrolldb.ChangesSinceCalculationParams{StartDay: p.StartDate, EndDay: p.EndDate,
		Since: *p.CalculatedAt, PeriodID: p.ID, StartAt: &from, EndAt: &to, Month: p.Month, Year: p.Year})
	if err != nil || changed {
		return changed, err
	}
	if changed, err = q.UnlinkedDeductibleExists(ctx, payrolldb.UnlinkedDeductibleExistsParams{EndDay: ptr(p.EndDate),
		Today: ptr(dbDay(s.now())), StartDay: p.StartDate, PeriodID: p.ID}); err != nil || changed {
		return changed, err
	}
	if changed, err = q.RecordsDeductionMismatch(ctx, p.ID); err != nil || changed {
		return changed, err
	}
	if changed, err = s.baseSalaryOutOfSync(ctx, q, p); err != nil || changed {
		return changed, err
	}
	return s.attendance.ChangedSince(ctx, start, end, *p.CalculatedAt)
}

// baseSalaryOutOfSync: lương cơ bản hồ sơ đổi sau lần tính, hoặc người có lương cơ bản cho kỳ mà chưa có phiếu.
func (s *Service) baseSalaryOutOfSync(ctx context.Context, q *payrolldb.Queries, p payrolldb.HrPayrollPeriod) (bool, error) {
	if locked(p.Status) {
		return false, nil
	}
	records, err := q.ExistingRecords(ctx, p.ID)
	if err != nil {
		return false, err
	}
	has := map[uuid.UUID]payrolldb.HrPayrollRecord{}
	ids := []uuid.UUID{}
	for _, r := range records {
		has[r.UserID] = r
		ids = append(ids, r.UserID)
	}
	staff, err := q.StaffForPayroll(ctx, ids)
	if err != nil {
		return false, err
	}
	end := asDay(p.EndDate)
	for _, u := range staff {
		m := memberFromPayroll(u)
		r, ok := has[u.ID]
		switch {
		case ok && deref(r.EmployeeType) == typeFulltime && r.BaseSalary != baseSalaryFor(m, end):
			return true, nil
		case !ok && u.Status == "active" && m.BaseSalary > 0 && baseSalaryFor(m, end) > 0:
			return true, nil
		}
	}
	return false, nil
}

type MissingStaff struct {
	ID     uuid.UUID `json:"id"`
	Name   string    `json:"name"`
	Code   string    `json:"code"`
	Reason string    `json:"reason"`
}

func (s *Service) missingStaff(ctx context.Context, q *payrolldb.Queries, p payrolldb.HrPayrollPeriod) (map[string]any, error) {
	rows, err := q.MissingStaff(ctx, payrolldb.MissingStaffParams{PeriodID: p.ID, EndAt: endOfDay(asDay(p.EndDate)), EndDay: ptr(p.EndDate)})
	if err != nil {
		return nil, err
	}
	items := []MissingStaff{}
	for i, u := range rows {
		if i >= 50 {
			break
		}
		m := staffMember{ID: u.ID, Roles: u.Roles, ContractType: u.ContractType, BaseSalary: u.BaseSalary, AcademicTeaching: u.AcademicTeaching}
		reason := "Đã có lương cơ bản: bấm Đồng bộ & Tính lại"
		switch {
		case profileOf(m).EmployeeType == typeParttime:
			reason = "Part-time: chưa có buổi dạy hợp lệ trong kỳ"
		case u.BaseSalary <= 0:
			reason = "Chưa nhập lương cơ bản"
		}
		items = append(items, MissingStaff{ID: u.ID, Name: u.FullName, Code: u.Code, Reason: reason})
	}
	return map[string]any{"count": len(rows), "items": items}, nil
}

type PeriodView struct {
	PeriodData
	NextStep  string   `json:"next_step"`
	NextColor string   `json:"next_color"`
	Calendar  Calendar `json:"calendar"`
}

type PeriodPage struct {
	Period            PeriodView     `json:"period"`
	Stats             map[string]any `json:"stats"`
	KpiPending        map[string]any `json:"kpi_pending"`
	Records           []RecordRow    `json:"records"`
	Paging            Paging         `json:"paging"`
	PeriodOptions     []Option       `json:"period_options"`
	TypeOptions       []Option       `json:"type_options"`
	Filtered          bool           `json:"filtered"`
	MissingStaff      map[string]any `json:"missing_staff"`
	AttendancePending *string        `json:"attendance_pending"`
}

type PeriodFilter struct {
	Search, Type, Kpi string
	Page              int
}

func (s *Service) Period(ctx context.Context, a *iam.Access, id uuid.UUID, f PeriodFilter) (*PeriodPage, error) {
	p, err := s.period(ctx, s.q, id)
	if err != nil {
		return nil, err
	}
	rows, err := s.scopedRecords(ctx, s.q, a, p.ID)
	if err != nil {
		return nil, err
	}
	search := strings.ToLower(strings.TrimSpace(f.Search))
	typ := ""
	if _, ok := salaryRoleLabels[f.Type]; ok {
		typ = f.Type
	}
	kpi := ""
	if f.Kpi == "done" || f.Kpi == "pending" {
		kpi = f.Kpi
	}
	var filtered []RecordRow
	var pending []string
	var sessions int
	var hours float64
	var kpiBonus, deductionsSum, netSum int64
	for _, r := range rows {
		row := recordRow(r.HrPayrollRecord, infoOf(r))
		if row.KpiState[0] == "pending" {
			pending = append(pending, row.Name)
		}
		if isPart(r.HrPayrollRecord) {
			sessions += int(r.HrPayrollRecord.TeachingSessions)
		}
		hours += r.HrPayrollRecord.ActualHours
		kpiBonus += r.HrPayrollRecord.KpiBonus
		deductionsSum += row.TotalDeductions
		netSum += r.HrPayrollRecord.NetSalary
		if search != "" && !strings.Contains(strings.ToLower(row.Name+" "+row.Email+" "+row.EmployeeCode), search) {
			continue
		}
		if typ != "" && row.SalaryRole != typ {
			continue
		}
		if kpi != "" && row.KpiState[0] != kpi {
			continue
		}
		filtered = append(filtered, row)
	}
	const per = 20
	page := pageOf(f.Page)
	from := min(len(filtered), (page-1)*per)
	to := min(len(filtered), from+per)
	sc := scopeOf(a)
	total := netSum
	if sc.all {
		total = p.TotalAmount
	}
	names := pending
	if len(names) > 8 {
		names = names[:8]
	}
	clock := s.clockOf(ctx, s.q, p)
	view := PeriodView{PeriodData: periodData(p), Calendar: clock.calendar(s.now())}
	switch {
	case p.Status == "paid":
		view.NextStep, view.NextColor = "Hoàn tất: đã trả lương", "neutral"
	case p.Status == "approved":
		view.NextStep, view.NextColor = "Chờ Admin đánh dấu đã trả", "info"
		if a.Can(PermMarkPaid) {
			view.NextStep = "Bước tiếp: bạn đánh dấu đã trả"
		}
	case len(pending) > 0:
		view.NextStep, view.NextColor = "Còn "+strconv.Itoa(len(pending))+" nhân sự chưa chốt KPI", "error"
	default:
		changed, err := s.hasChanges(ctx, s.q, p)
		if err != nil {
			return nil, err
		}
		if changed {
			view.NextStep, view.NextColor = "Chờ Kế toán Đồng bộ & Tính lại", "warning"
			if a.Can(PermCalculate) {
				view.NextStep = "Bước tiếp: bạn bấm Đồng bộ & Tính lại (dữ liệu đã đổi)"
			}
		} else {
			view.NextStep, view.NextColor = "Chờ Admin chốt bảng lương", "warning"
			if a.Can(PermApprove) {
				view.NextStep = "Bước tiếp: bạn chốt bảng lương"
			}
		}
	}
	out := &PeriodPage{Period: view, Records: append([]RecordRow{}, filtered[from:to]...), Paging: paging(page, per, int64(len(filtered))),
		Stats: map[string]any{"total_amount": total, "records": len(rows), "parttime_sessions": sessions, "hours": round2(hours),
			"kpi_bonus": kpiBonus, "deductions": deductionsSum},
		KpiPending: map[string]any{"count": len(pending), "names": strings.Join(names, ", ")},
		Filtered:   search != "" || typ != "" || kpi != "", PeriodOptions: []Option{}}
	all, err := s.q.AllPeriods(ctx)
	if err != nil {
		return nil, err
	}
	for _, x := range all {
		out.PeriodOptions = append(out.PeriodOptions, Option{x.ID.String(), fmt.Sprintf("Tháng %02d/%d (%s)", x.Month, x.Year, x.Code)})
	}
	for _, k := range salaryRoleOrder {
		out.TypeOptions = append(out.TypeOptions, Option{k, salaryRoleLabels[k]})
	}
	if sc.all && !locked(p.Status) {
		if out.MissingStaff, err = s.missingStaff(ctx, s.q, p); err != nil {
			return nil, err
		}
	}
	if s.attendance.Pending() {
		out.AttendancePending = ptr(AttendancePending)
	}
	return out, nil
}

// ───────────── Duyệt / chi trả ─────────────

func (s *Service) ApprovePeriod(ctx context.Context, a *iam.Access, id uuid.UUID) (string, error) {
	p, err := s.period(ctx, s.q, id)
	if err != nil {
		return "", err
	}
	if locked(p.Status) {
		return "", iam.Invalid("Kỳ lương đã khóa.")
	}
	clock := s.clockOf(ctx, s.q, p)
	if !clock.canApprove(s.now()) {
		return "", iam.Invalid(fmt.Sprintf("Chưa thể chốt bảng lương: chốt công và chốt lỗi vào hết ngày %s (cuối tháng + %d ngày) — vui lòng chốt từ ngày %s.",
			clock.closeAt.In(vnZone).Format("02/01"), clock.days, clock.closeAt.Add(time.Microsecond).In(vnZone).Format("02/01")))
	}
	changed, err := s.hasChanges(ctx, s.q, p)
	if err != nil {
		return "", err
	}
	if changed {
		return "", iam.Invalid("Chấm công hoặc biên bản phạt của kỳ đã thay đổi sau lần tính gần nhất — vui lòng bấm \"Đồng bộ & Tính lại\" trước khi duyệt.")
	}
	all, err := s.q.PeriodRecords(ctx, payrolldb.PeriodRecordsParams{PeriodID: p.ID, AllScope: true, Branches: []uuid.UUID{}})
	if err != nil {
		return "", err
	}
	var pending []string
	for _, r := range all {
		if kpiState(r.HrPayrollRecord)[0] == "pending" {
			pending = append(pending, r.FullName)
		}
	}
	if len(pending) > 0 {
		names := pending
		more := ""
		if len(names) > 5 {
			names, more = names[:5], "…"
		}
		return "", iam.Invalid(fmt.Sprintf("Chưa thể chốt bảng lương: còn %d nhân sự chưa chốt KPI (%s%s). Chốt KPI rồi bấm \"Đồng bộ & Tính lại\".",
			len(pending), strings.Join(names, ", "), more))
	}
	err = s.tx(ctx, func(tx pgxTx, q *payrolldb.Queries) error {
		lp, err := q.LockPeriod(ctx, id)
		if err != nil {
			return err
		}
		if locked(lp.Status) {
			return iam.Invalid("Kỳ lương đã khóa.")
		}
		now := s.now()
		if err := q.ApprovePeriod(ctx, payrolldb.ApprovePeriodParams{At: &now, By: actorPtr(a), ID: id}); err != nil {
			return err
		}
		if err := q.SetRecordsStatus(ctx, payrolldb.SetRecordsStatusParams{Status: "confirmed", PeriodID: id}); err != nil {
			return err
		}
		if err := q.DeductPeriodPenalties(ctx, id); err != nil {
			return err
		}
		if err := q.SettlePeriodAdjustments(ctx, payrolldb.SettlePeriodAdjustmentsParams{At: &now, PeriodID: id}); err != nil {
			return err
		}
		if err := q.SettlePeriodItems(ctx, payrolldb.SettlePeriodItemsParams{At: &now, PeriodID: id}); err != nil {
			return err
		}
		records, err := q.ExistingRecords(ctx, id)
		if err != nil {
			return err
		}
		// Chốt bảng lương: báo trong app cho từng người.
		for _, r := range records {
			if _, err := notify.Send(ctx, tx, []uuid.UUID{r.UserID}, notify.Notification{Type: notifyApproved,
				Title: "Phiếu lương " + lp.Title + " đã được duyệt",
				Body:  "Thực nhận " + money(r.NetSalary) + " — xem chi tiết tại \"Lương của tôi\".",
				Link:  "/portal/my-salary?period_id=" + id.String(), Data: map[string]any{"payroll_period_id": id}, CreatedBy: actorPtr(a)}); err != nil {
				return err
			}
		}
		return audit.Record(ctx, tx, audit.Entry{ActorID: actorPtr(a), Module: audit.ModulePayroll,
			Action: "payroll_period.approve", SubjectType: "payroll_period", SubjectID: id.String(), SubjectLabel: lp.Title,
			Description: "Phê duyệt " + lp.Title, Changes: statusChange(lp.Status, "approved")})
	})
	if err != nil {
		return "", err
	}
	s.log.Info("payroll period approved", "by", a.UserID, "period", id)
	return "Đã phê duyệt bảng lương " + p.Title + "!", nil
}

// MarkPaid: ghi nhận đã chi trả; ngoài khung ngày 10–15 tháng sau chỉ cảnh báo.
func (s *Service) MarkPaid(ctx context.Context, a *iam.Access, id uuid.UUID) (string, *string, error) {
	var p payrolldb.HrPayrollPeriod
	err := s.tx(ctx, func(tx pgxTx, q *payrolldb.Queries) error {
		var err error
		p, err = q.LockPeriod(ctx, id)
		if err != nil {
			if isNoRows(err) {
				return errPeriodNotFound
			}
			return err
		}
		if p.Status != "approved" {
			return iam.Invalid("Chỉ kỳ lương đã duyệt mới được đánh dấu đã chi trả.")
		}
		now := s.now()
		if err := q.MarkPeriodPaid(ctx, payrolldb.MarkPeriodPaidParams{At: &now, By: actorPtr(a), ID: id}); err != nil {
			return err
		}
		if err := q.SetRecordsStatus(ctx, payrolldb.SetRecordsStatusParams{Status: "paid", PeriodID: id}); err != nil {
			return err
		}
		return audit.Record(ctx, tx, audit.Entry{ActorID: actorPtr(a), Module: audit.ModulePayroll, Action: "payroll_period.mark_paid",
			SubjectType: "payroll_period", SubjectID: id.String(), SubjectLabel: p.Title, Description: "Ghi nhận chi trả " + p.Title,
			Changes: statusChange("approved", "paid")})
	})
	if err != nil {
		return "", nil, err
	}
	s.log.Info("payroll period paid", "by", a.UserID, "period", id)
	var warn *string
	clock := s.clockOf(ctx, s.q, p)
	if !clock.inPayWindow(s.now()) {
		from, to := clock.payWindow()
		warn = ptr("Lưu ý: lương thường trả từ ngày " + from.Format("02/01") + " đến " + to.Format("02/01") + " — hôm nay nằm ngoài khung trả lương.")
	}
	return "Đã ghi nhận chi trả bảng lương " + p.Title + "!", warn, nil
}

// ───────────── Xuất / bảng theo khối ─────────────

var exportHeader = []string{
	"Nhân sự", "Mã NV", "Email", "Khối", "Loại", "Vai trò lương", "Lương cơ bản", "Số buổi", "Số giờ", "Lương buổi dạy",
	"Nguồn KPI", "Trạng thái KPI", "HS giữ được / đầu kỳ", "Bậc KPI giữ HS (đ/HS)", "Điểm KPI Học vụ (%)", "KPI", "KPI kiêm nhiệm giảng dạy",
	"Số buổi có GVNN", "Buổi có GVNN (chờ BA)", "Số HS chốt (hoa hồng)", "% hoa hồng", "Căn cứ thực thu", "Hoa hồng", "Hoa hồng hoãn",
	"Thưởng tái tục", "Chi tiết cộng tự do", "Phụ cấp / cộng khác", "Tổng thu nhập",
	"BHXH", "Công đoàn", "Thuế TNCN", "Phạt", "Thu hồi hoa hồng", "Chi tiết trừ tự do", "Khấu trừ khác", "Tổng khấu trừ", "Thực lĩnh", "Ghi chú",
}

func i64s(v int64) string { return strconv.FormatInt(v, 10) }

func fmtFloat(v float64) string { return strconv.FormatFloat(v, 'f', -1, 64) }

func lineText(lines []ManualLine, kind string) string {
	var out []string
	for _, l := range linesOf(lines, kind) {
		out = append(out, l.Label+": "+groupThousands(l.Amount))
	}
	return strings.Join(out, "; ")
}

// Export: bảng lương của kỳ (CSV UTF-8 có BOM, thay cho .xlsx), lọc theo khối.
func (s *Service) Export(ctx context.Context, a *iam.Access, id uuid.UUID, department string) (string, [][]string, error) {
	p, err := s.period(ctx, s.q, id)
	if err != nil {
		return "", nil, err
	}
	if department != "" {
		if _, ok := departmentLabels[department]; !ok {
			return "", nil, iam.Invalid("Khối không hợp lệ.")
		}
	}
	rows, err := s.scopedRecords(ctx, s.q, a, p.ID)
	if err != nil {
		return "", nil, err
	}
	slices.SortStableFunc(rows, func(x, y payrolldb.PeriodRecordsRow) int {
		return strings.Compare(deref(x.HrPayrollRecord.Department), deref(y.HrPayrollRecord.Department))
	})
	out := [][]string{exportHeader}
	for _, row := range rows {
		r := row.HrPayrollRecord
		if department != "" && deref(r.Department) != department {
			continue
		}
		name := row.FullName
		if name == "" {
			name = "Chưa cập nhật"
		}
		dep := departmentLabels[deref(r.Department)]
		if dep == "" {
			dep = deref(r.Department)
		}
		ret := ""
		if deref(r.KpiSource) == kpiRetention || r.TeachingConcurrent {
			ret = i32s(r.RetentionStudents) + "/" + i32s(r.RetentionBaseStudents)
		}
		opt := func(v *int64) string {
			if v == nil {
				return ""
			}
			return i64s(*v)
		}
		optf := func(v *float64) string {
			if v == nil {
				return ""
			}
			return fmtFloat(*v)
		}
		lines := parseManualLines(r.ManualLines)
		out = append(out, []string{name, row.UserCode, row.UserEmail, dep, employeeTypeLabel(r), salaryRoleLabel(r), i64s(r.BaseSalary),
			i32s(r.TeachingSessions), fmtFloat(r.ActualHours), i64s(r.TeachingSalary), kpiSourceLabels[deref(r.KpiSource)], kpiState(r)[1], ret,
			opt(r.RetentionTier), optf(r.KpiScore), i64s(r.KpiBonus), i64s(r.TeachingKpiBonus), i32s(r.ForeignTeacherSessionsCount),
			i64s(r.ForeignSessionPay), i32s(r.CommissionClosedCount), optf(r.CommissionPercent), i64s(r.CommissionBase), i64s(r.CommissionBonus),
			i64s(r.CommissionDeferred), i64s(r.RenewBonus), lineText(lines, "earning"), i64s(r.Allowance + r.OtherBonus), i64s(grossOf(r)),
			i64s(r.InsuranceDeduction), i64s(r.UnionDeduction), i64s(r.TaxDeduction), i64s(r.PenaltyDeduction), i64s(r.CommissionClawback),
			lineText(lines, "deduction"), i64s(r.OtherDeduction + r.ForeignTeacherDeduction), i64s(deductionsOf(r)), i64s(r.NetSalary),
			deref(r.AdjustmentNotes)})
	}
	name := "bang-luong-" + strings.ToLower(p.Code)
	if department != "" {
		name += "-" + department
	}
	return name + ".csv", out, nil
}

type DepartmentPage struct {
	Department string           `json:"department"`
	Label      string           `json:"label"`
	Period     PeriodData       `json:"period"`
	Records    []RecordRow      `json:"records"`
	Totals     map[string]int64 `json:"totals"`
}

// Department: bảng lương theo khối (GV Full-time / Học thuật / Vận hành).
func (s *Service) Department(ctx context.Context, a *iam.Access, id uuid.UUID, department string) (*DepartmentPage, error) {
	if !slices.Contains([]string{"fulltime", "academic", "operations"}, department) {
		return nil, notFound("Không tìm thấy bảng lương theo khối.")
	}
	p, err := s.period(ctx, s.q, id)
	if err != nil {
		return nil, err
	}
	rows, err := s.scopedRecords(ctx, s.q, a, p.ID)
	if err != nil {
		return nil, err
	}
	out := &DepartmentPage{Department: department, Label: departmentLabels[department], Period: periodData(p), Records: []RecordRow{},
		Totals: map[string]int64{}}
	for _, row := range rows {
		r := row.HrPayrollRecord
		if deref(r.Department) != department {
			continue
		}
		out.Records = append(out.Records, recordRow(r, infoOf(row)))
		for k, v := range map[string]int64{"net_salary": r.NetSalary, "renew_bonus": r.RenewBonus, "kpi_bonus": r.KpiBonus, "base_salary": r.BaseSalary,
			"teaching_salary": r.TeachingSalary, "teaching_kpi_bonus": r.TeachingKpiBonus, "allowance": r.Allowance, "commission_bonus": r.CommissionBonus} {
			out.Totals[k] += v
		}
	}
	return out, nil
}
