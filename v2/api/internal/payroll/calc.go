package payroll

import (
	"context"
	"encoding/json"
	"math"
	"slices"
	"strconv"
	"strings"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/payroll/payrolldb"
)

// Tính bảng lương theo công thức Q3 (PayrollPeriod::runCalculation).

// ManualLine: khoản cộng / trừ tự do trên phiếu (giữ qua các lần tính lại).
type ManualLine struct {
	Kind   string `json:"kind"` // earning | deduction
	Label  string `json:"label"`
	Amount int64  `json:"amount"`
}

func parseManualLines(raw []byte) []ManualLine {
	var rows []struct {
		Kind   *string  `json:"kind"`
		Label  *string  `json:"label"`
		Amount *float64 `json:"amount"`
	}
	if len(raw) == 0 || json.Unmarshal(raw, &rows) != nil {
		return []ManualLine{}
	}
	out := []ManualLine{}
	for _, r := range rows {
		if r.Kind == nil || r.Label == nil {
			continue
		}
		out = append(out, ManualLine{Kind: *r.Kind, Label: *r.Label, Amount: roundVND(deref(r.Amount))})
	}
	return out
}

func linesOf(lines []ManualLine, kind string) []ManualLine {
	out := []ManualLine{}
	for _, l := range lines {
		if l.Kind == kind {
			out = append(out, l)
		}
	}
	return out
}

func sumLines(lines []ManualLine, kind string) int64 {
	var t int64
	for _, l := range linesOf(lines, kind) {
		t += l.Amount
	}
	return t
}

// gross: mọi khoản cộng của phiếu.
func gross(r *payrolldb.UpsertRecordParams) int64 {
	return r.BaseSalary + r.TeachingSalary + r.KpiBonus + r.TeachingKpiBonus + r.RenewBonus + r.CommissionBonus + r.Allowance +
		r.OtherBonus + r.ForeignSessionPay
}

// deductions: BHXH + Công đoàn + thuế + phạt + giảm trừ GVNN (cũ) + thu hồi hoa hồng + khấu trừ khác.
func deductions(r *payrolldb.UpsertRecordParams) int64 {
	return r.InsuranceDeduction + r.UnionDeduction + r.TaxDeduction + r.PenaltyDeduction + r.ForeignTeacherDeduction +
		r.CommissionClawback + r.OtherDeduction
}

// applyManualInputs: tính lại khoản phụ thuộc khoản nhập tay rồi thực lĩnh (PayrollRecord::applyManualInputs).
func applyManualInputs(r *payrolldb.UpsertRecordParams) {
	if r.EmployeeType == nil {
		r.NetSalary = max(0, gross(r)-deductions(r))
		return
	}
	lines := parseManualLines(r.ManualLines)
	r.Allowance = sumLines(lines, "earning")
	r.OtherDeduction = sumLines(lines, "deduction")
	switch deref(r.KpiSource) {
	case kpiRetention:
		r.KpiBonus = int64(r.RetentionStudents) * deref(r.RetentionTier)
	case kpiManual:
		r.KpiBonus = deref(r.KpiManualAmount)
	}
	r.TeachingKpiBonus = 0
	if r.TeachingConcurrent {
		r.TeachingKpiBonus = int64(r.RetentionStudents) * deref(r.RetentionTier)
	}
	if deref(r.EmployeeType) == typeParttime {
		r.InsuranceDeduction, r.UnionDeduction, r.TaxDeduction = 0, 0, 0
	}
	r.NetSalary = max(0, gross(r)-deductions(r))
}

func paramsOf(r payrolldb.HrPayrollRecord) payrolldb.UpsertRecordParams {
	return payrolldb.UpsertRecordParams{
		PeriodID: r.PeriodID, UserID: r.UserID, Department: r.Department, EmployeeType: r.EmployeeType, SalaryRole: r.SalaryRole,
		BaseSalary: r.BaseSalary, ActualHours: r.ActualHours, TeachingSessions: r.TeachingSessions, TeachingSalary: r.TeachingSalary,
		TeachingConcurrent: r.TeachingConcurrent, TeachingKpiBonus: r.TeachingKpiBonus, RetentionBaseStudents: r.RetentionBaseStudents,
		RetentionStudents: r.RetentionStudents, RetentionTier: r.RetentionTier, KpiSource: r.KpiSource, KpiManualAmount: r.KpiManualAmount,
		KpiScore: r.KpiScore, KpiBonus: r.KpiBonus, ForeignSessionPay: r.ForeignSessionPay,
		ForeignTeacherSessionsCount: r.ForeignTeacherSessionsCount, RenewBonus: r.RenewBonus, CommissionBonus: r.CommissionBonus,
		CommissionBase: r.CommissionBase, CommissionClawback: r.CommissionClawback, CommissionDeferred: r.CommissionDeferred,
		CommissionPercent: r.CommissionPercent, CommissionClosedCount: r.CommissionClosedCount, Allowance: r.Allowance,
		OtherBonus: r.OtherBonus, OtherDeduction: r.OtherDeduction, InsuranceDeduction: r.InsuranceDeduction,
		UnionDeduction: r.UnionDeduction, TaxDeduction: r.TaxDeduction, PenaltyDeduction: r.PenaltyDeduction,
		ForeignTeacherDeduction: r.ForeignTeacherDeduction, NetSalary: r.NetSalary, Notes: r.Notes, AdjustmentNotes: r.AdjustmentNotes,
		ManualLines: r.ManualLines, CalculationDetails: r.CalculationDetails,
	}
}

// autoNote: ghi chú tự động của phiếu ("Tự động tính: …").
func autoNote(r *payrolldb.UpsertRecordParams) string {
	parts := []string{typeLabels[deref(r.EmployeeType)] + " (" + salaryRoleLabels[deref(r.SalaryRole)] + ")"}
	n := func(v int32) string { return strconv.Itoa(int(v)) }
	if deref(r.EmployeeType) == typeParttime {
		parts = append(parts, n(r.TeachingSessions)+" buổi dạy = "+money(r.TeachingSalary),
			"giữ "+n(r.RetentionStudents)+"/"+n(r.RetentionBaseStudents)+" HS")
	} else {
		parts = append(parts, "lương cơ bản "+money(r.BaseSalary))
		if r.TeachingConcurrent {
			parts = append(parts, "đứng lớp "+n(r.TeachingSessions)+" buổi = "+money(r.TeachingSalary),
				"giữ "+n(r.RetentionStudents)+"/"+n(r.RetentionBaseStudents)+" HS")
		}
	}
	if r.CommissionBonus > 0 || r.CommissionDeferred > 0 {
		v := "hoa hồng " + money(r.CommissionBonus)
		if r.CommissionDeferred > 0 {
			v += " (hoãn " + money(r.CommissionDeferred) + ")"
		}
		parts = append(parts, v)
	}
	if r.RenewBonus > 0 {
		parts = append(parts, "tái tục "+money(r.RenewBonus))
	}
	if r.PenaltyDeduction > 0 {
		parts = append(parts, "phạt "+money(r.PenaltyDeduction))
	}
	if r.CommissionClawback > 0 {
		parts = append(parts, "thu hồi HH "+money(r.CommissionClawback))
	}
	return "Tự động tính: " + strings.Join(parts, " · ")
}

func memberFromPayroll(r payrolldb.StaffForPayrollRow) staffMember {
	m := staffMember{ID: r.ID, Name: r.FullName, Code: r.Code, Roles: r.Roles, ContractType: r.ContractType,
		ContractStart: r.ContractStartDate, BaseSalary: r.BaseSalary, HourlyRate: r.HourlyRate, AcademicTeaching: r.AcademicTeaching}
	if r.BranchID != uuid.Nil {
		b := r.BranchID
		m.BranchID = &b
	}
	return m
}

// calcData: dữ liệu nạp một lần cho cả kỳ.
type calcData struct {
	st          Settings
	rates       rateBook
	classes     map[uuid.UUID]payrolldb.ClassInfoRow
	enrollments []payrolldb.RetentionEnrollmentsRow
	sessionKeys map[string]bool
	tiers       []payrolldb.HrCommissionTier
}

func sessionKey(class uuid.UUID, day string, user uuid.UUID) string {
	return class.String() + "|" + day + "|" + user.String()
}

func dayKey(t time.Time) string { return asDay(t).Format("2006-01-02") }

// retentionFor: KPI giữ HS của tập lớp (enrollments nạp sẵn cho mọi lớp liên quan).
func (d *calcData) retentionFor(classIDs []uuid.UUID, p payrolldb.HrPayrollPeriod) retention {
	return retentionOf(filterEnrollments(d.enrollments, classIDs), asDay(p.StartDate), asDay(p.EndDate))
}

// CalculatePeriod: "Đồng bộ & Tính lại" (khóa dòng kỳ, kỳ đã duyệt / đã chi trả không tính lại).
func (s *Service) CalculatePeriod(ctx context.Context, a *iam.Access, id uuid.UUID) (string, error) {
	err := s.tx(ctx, func(tx pgxTx, q *payrolldb.Queries) error {
		p, err := q.LockPeriod(ctx, id)
		if err != nil {
			if isNoRows(err) {
				return errPeriodNotFound
			}
			return err
		}
		if locked(p.Status) {
			return iam.Invalid("Không thể tính lại kỳ lương đã duyệt hoặc đã chi trả.")
		}
		return s.runCalculation(ctx, q, p)
	})
	if err != nil {
		return "", err
	}
	s.log.Info("payroll calculated", "period", id, "by", a.UserID)
	return "Đã đồng bộ chấm công và tính lại bảng lương.", nil
}

func locked(status string) bool { return status == "approved" || status == "paid" }

func (s *Service) runCalculation(ctx context.Context, q *payrolldb.Queries, p payrolldb.HrPayrollPeriod) error {
	start, end := asDay(p.StartDate), asDay(p.EndDate)
	st, err := s.settings(ctx, q)
	if err != nil {
		return err
	}
	sheets, err := s.attendance.ValidTimesheets(ctx, start, end)
	if err != nil {
		return err
	}
	byUser := map[uuid.UUID][]Timesheet{}
	extra := []uuid.UUID{}
	d := &calcData{st: st, sessionKeys: map[string]bool{}, classes: map[uuid.UUID]payrolldb.ClassInfoRow{}}
	classSet := map[uuid.UUID]bool{}
	for _, ts := range sheets {
		if _, ok := byUser[ts.UserID]; !ok {
			extra = append(extra, ts.UserID)
		}
		byUser[ts.UserID] = append(byUser[ts.UserID], ts)
		if ts.ClassID != nil {
			d.sessionKeys[sessionKey(*ts.ClassID, dayKey(ts.Date), ts.UserID)] = true
			classSet[*ts.ClassID] = true
		}
	}
	staff, err := q.StaffForPayroll(ctx, extra)
	if err != nil {
		return err
	}
	ids := make([]uuid.UUID, 0, len(staff))
	for _, u := range staff {
		ids = append(ids, u.ID)
	}
	rateRows, err := q.HourlyRatesFor(ctx, ids)
	if err != nil {
		return err
	}
	d.rates = newRateBook(rateRows)
	if d.tiers, err = q.TiersAt(ctx, ptr(p.EndDate)); err != nil {
		return err
	}

	// Lớp GV chính của từng người (giữ HS, tái tục).
	owned := map[uuid.UUID][]payrolldb.ClassesOfTeacherRow{}
	for _, u := range staff {
		uid := u.ID
		rows, err := q.ClassesOfTeacher(ctx, &uid)
		if err != nil {
			return err
		}
		owned[u.ID] = rows
		for _, c := range rows {
			classSet[c.ID] = true
		}
	}
	allClasses := make([]uuid.UUID, 0, len(classSet))
	for id := range classSet {
		allClasses = append(allClasses, id)
	}
	info, err := q.ClassInfo(ctx, allClasses)
	if err != nil {
		return err
	}
	for _, c := range info {
		d.classes[c.ID] = c
	}
	if d.enrollments, err = q.RetentionEnrollments(ctx, payrolldb.RetentionEnrollmentsParams{ClassIds: allClasses, StartDay: p.StartDate}); err != nil {
		return err
	}
	from, to := dayOf(start), endOfDay(end)
	revRows, err := q.ClassRevenue(ctx, payrolldb.ClassRevenueParams{ClassIds: allClasses, StartAt: &from, EndAt: &to})
	if err != nil {
		return err
	}
	revenue := map[uuid.UUID]int64{}
	for _, r := range revRows {
		revenue[r.ClassID] = r.Revenue
	}

	existingRows, err := q.ExistingRecords(ctx, p.ID)
	if err != nil {
		return err
	}
	existing := map[uuid.UUID]payrolldb.HrPayrollRecord{}
	for _, r := range existingRows {
		existing[r.UserID] = r
	}
	closed, err := closedCounts(ctx, q, start, end)
	if err != nil {
		return err
	}

	// Gỡ liên kết của lần tính trước; lần tính này gắn lại đúng khoản.
	if err := q.UnlinkPeriodPenalties(ctx, p.ID); err != nil {
		return err
	}
	if err := q.UnlinkPeriodAdjustments(ctx, p.ID); err != nil {
		return err
	}
	if err := q.UnlinkPeriodItems(ctx, p.ID); err != nil {
		return err
	}
	if err := s.syncItems(ctx, q, p); err != nil {
		return err
	}
	commission, err := s.resolveItems(ctx, q, p)
	if err != nil {
		return err
	}
	adjRows, err := q.UnsettledAdjustments(ctx, endOfDay(end))
	if err != nil {
		return err
	}
	adjustments := map[uuid.UUID][]payrolldb.UnsettledAdjustmentsRow{}
	for _, a := range adjRows {
		adjustments[a.UserID] = append(adjustments[a.UserID], a)
	}
	penRows, err := q.DeductiblePenalties(ctx, payrolldb.DeductiblePenaltiesParams{
		EndDay: ptr(p.EndDate), Today: ptr(dbDay(s.now())), StartDay: p.StartDate, PeriodID: p.ID})
	if err != nil {
		return err
	}
	penalties := map[uuid.UUID][]payrolldb.DeductiblePenaltiesRow{}
	for _, x := range penRows {
		penalties[x.UserID] = append(penalties[x.UserID], x)
	}

	produced := []uuid.UUID{}
	for _, u := range staff {
		m := memberFromPayroll(u)
		var prev *payrolldb.HrPayrollRecord
		if e, ok := existing[u.ID]; ok {
			prev = &e
		}
		rec, ok, err := s.recordFor(ctx, q, p, m, prev, byUser[u.ID], owned[u.ID], revenue, d, commission[u.ID],
			adjustments[u.ID], penalties[u.ID], closed[u.ID])
		if err != nil {
			return err
		}
		if !ok {
			continue
		}
		saved, err := q.UpsertRecord(ctx, rec)
		if err != nil {
			return err
		}
		rid := saved.ID
		if ps := penalties[u.ID]; len(ps) > 0 {
			if err := q.LinkPenalties(ctx, payrolldb.LinkPenaltiesParams{RecordID: &rid, Ids: idsOf(ps, func(x payrolldb.DeductiblePenaltiesRow) uuid.UUID { return x.ID })}); err != nil {
				return err
			}
		}
		if as := adjustments[u.ID]; len(as) > 0 {
			if err := q.LinkAdjustments(ctx, payrolldb.LinkAdjustmentsParams{RecordID: &rid, Ids: idsOf(as, func(x payrolldb.UnsettledAdjustmentsRow) uuid.UUID { return x.ID })}); err != nil {
				return err
			}
		}
		if sp := commission[u.ID]; sp != nil {
			if len(sp.Payable) > 0 {
				if err := q.LinkPayableItems(ctx, payrolldb.LinkPayableItemsParams{RecordID: &rid, Ids: idsOf(sp.Payable, func(x payrolldb.HrCommissionItem) uuid.UUID { return x.ID })}); err != nil {
					return err
				}
			}
			for _, it := range sp.Deferred {
				if err := q.SetDeferredReason(ctx, payrolldb.SetDeferredReasonParams{Reason: it.DeferredReason, ID: it.ID}); err != nil {
					return err
				}
			}
		}
		produced = append(produced, u.ID)
	}
	// Khoản hoãn của người không có phiếu vẫn ghi lý do hoãn.
	for uid, sp := range commission {
		if slices.Contains(produced, uid) {
			continue
		}
		for _, it := range sp.Deferred {
			if err := q.SetDeferredReason(ctx, payrolldb.SetDeferredReasonParams{Reason: it.DeferredReason, ID: it.ID}); err != nil {
				return err
			}
		}
	}
	if err := q.DeleteRecordsExcept(ctx, payrolldb.DeleteRecordsExceptParams{PeriodID: p.ID, Keep: produced}); err != nil {
		return err
	}
	return q.FinishCalculation(ctx, p.ID)
}

func idsOf[T any](rows []T, f func(T) uuid.UUID) []uuid.UUID {
	out := make([]uuid.UUID, 0, len(rows))
	for _, r := range rows {
		out = append(out, f(r))
	}
	return out
}

// recordFor: phiếu lương của một người; ok=false khi không có khoản nào phát sinh.
func (s *Service) recordFor(ctx context.Context, q *payrolldb.Queries, p payrolldb.HrPayrollPeriod, m staffMember,
	prev *payrolldb.HrPayrollRecord, sheets []Timesheet, owned []payrolldb.ClassesOfTeacherRow, revenue map[uuid.UUID]int64,
	d *calcData, sp *commissionSplit, adjs []payrolldb.UnsettledAdjustmentsRow, pens []payrolldb.DeductiblePenaltiesRow, closedCount int,
) (payrolldb.UpsertRecordParams, bool, error) {
	st := d.st
	start, end := asDay(p.StartDate), asDay(p.EndDate)
	prof := profileOf(m)
	partTime := prof.EmployeeType == typeParttime

	// 1. Ca dạy hợp lệ: Part-time theo đơn giá; Học thuật kiêm nhiệm theo % học phí buổi.
	var share *shareResult
	if !partTime && prof.SalaryRole == "academic_lead" && teachesAsLead(m) {
		sh, err := s.teachingShare(ctx, q, sheets, d)
		if err != nil {
			return payrolldb.UpsertRecordParams{}, false, err
		}
		share = &sh
	}
	paysSessions := partTime || share != nil
	outcomes := make([]payOutcome, len(sheets))
	for i, ts := range sheets {
		switch {
		case share != nil:
			if o, ok := share.Outcomes[ts.ID]; ok {
				outcomes[i] = o.payOutcome
			}
		default:
			outcomes[i] = outcomeFor(ts, m, d.rates, st)
		}
	}
	voided, perSession, perHour := 0, 0, 0
	var hours, pay, lateTotal float64
	lateLines := []map[string]any{}
	for i, ts := range sheets {
		o := outcomes[i]
		if paysSessions && !o.Counted {
			voided++
		}
		if !paysSessions || o.Counted {
			hours += ts.Hours
		}
		pay += o.Amount
		switch o.Unit {
		case "session":
			perSession++
		case "hour":
			perHour++
		}
		if paysSessions && o.LateRule != "" {
			cls := ""
			if ts.ClassID != nil {
				cls = d.classes[*ts.ClassID].Name
			}
			lateLines = append(lateLines, map[string]any{"timesheet_id": ts.ID, "date": asDay(ts.Date).Format("02/01/2006"), "class": cls,
				"minutes": o.LateMinutes, "rule": o.LateRule, "deduction": o.LateDeduction})
			lateTotal += o.LateDeduction
		}
	}
	sessions := len(sheets) - voided
	var teachingSalary int64
	switch {
	case share != nil:
		teachingSalary = share.Amount
	case paysSessions:
		teachingSalary = roundVND(pay)
	}

	// Buổi có GVNN cùng lớp (tham khảo khi nhập tay "Buổi có GVNN").
	foreign := 0
	for _, ts := range sheets {
		if ts.ClassID == nil {
			continue
		}
		c, ok := d.classes[*ts.ClassID]
		if ok && c.ForeignTeacherID != nil && *c.ForeignTeacherID != m.ID && d.sessionKeys[sessionKey(*ts.ClassID, dayKey(ts.Date), *c.ForeignTeacherID)] {
			foreign++
		}
	}

	// 2. Hoa hồng: khoản đạt gate kép; còn lại hoãn.
	if sp == nil {
		sp = &commissionSplit{}
	}
	commissionBonus, commissionBase, commissionDeferred := sumItems(sp.Payable, false), sumItems(sp.Payable, true), sumItems(sp.Deferred, false)
	var currentPercent *float64
	if closedCount > 0 {
		currentPercent = ptr(milestoneFor(d.tiers, closedCount).Percent)
	}
	var adjSum int64
	for _, a := range adjs {
		adjSum += a.Amount
	}
	clawback := max(0, -adjSum)

	// 3. Phạt quá hạn nộp.
	var penaltyDeduction int64
	for _, x := range pens {
		penaltyDeduction += x.Amount
	}

	// 4. Công thức theo loại nhân sự.
	baseSalary := baseSalaryFor(m, end)
	details := map[string]any{
		"rates":    map[string]any{"insurance": st.InsuranceRate, "union": st.UnionRate},
		"sessions": map[string]any{"count": sessions, "per_session": perSession, "per_hour": perHour},
		"late": map[string]any{"threshold": st.LateThreshold, "per_minute": st.LatePerMinute, "total_deduction": round2(lateTotal),
			"lines": lateLines},
	}
	var kpiSource string
	var kpiScore *float64
	var kpiAuto, renewAmount int64
	var ret retention
	taught := []uuid.UUID{}
	for _, ts := range sheets {
		if ts.ClassID != nil && !slices.Contains(taught, *ts.ClassID) {
			taught = append(taught, *ts.ClassID)
		}
	}
	retDetails := func(r retention) map[string]any {
		return map[string]any{"base": r.Base, "lost": r.Lost, "retained": r.Retained, "lost_ids": r.LostIDs}
	}
	if partTime {
		kpiSource = kpiRetention
		classIDs := idsOf(owned, func(c payrolldb.ClassesOfTeacherRow) uuid.UUID { return c.ID })
		for _, id := range taught {
			if !slices.Contains(classIDs, id) {
				classIDs = append(classIDs, id)
			}
		}
		ret = d.retentionFor(classIDs, p)
		details["retention"] = retDetails(ret)
	} else {
		if share != nil {
			ret = d.retentionFor(taught, p)
			details["retention"] = retDetails(ret)
			details["teaching_share"] = map[string]any{"default_percent": share.DefaultPercent, "classes": share.Classes, "skipped": share.Skipped}
		}
		switch {
		case prof.SalaryRole == "academic_staff":
			kpiSource = kpiAcademic
			k, err := s.academicKpi(ctx, q, m.ID, p, st)
			if err != nil {
				return payrolldb.UpsertRecordParams{}, false, err
			}
			kpiAuto, kpiScore = k.Amount, k.Score
			details["kpi"] = map[string]any{"fund": k.Fund, "score": k.Score, "evaluation_id": k.SheetID, "items": k.Items}
		case m.has("admin"):
			kpiSource = kpiSelf
			sc, err := ownKpiScore(ctx, q, m.ID, p)
			if err != nil {
				return payrolldb.UpsertRecordParams{}, false, err
			}
			kpiScore = sc
		default:
			kpiSource = kpiManual
		}
		rn := renewalFor(owned, d, p, revenue)
		renewAmount = rn.Amount
		details["renewal"] = map[string]any{"amount": rn.Amount, "classes": rn.Classes}
	}
	if sum, err := s.attendance.Summary(ctx, m.ID, start, end); err != nil {
		return payrolldb.UpsertRecordParams{}, false, err
	} else if len(sum) > 0 {
		details["attendance"] = sum
	}
	deferred := []map[string]any{}
	for _, it := range sp.Deferred {
		deferred = append(deferred, map[string]any{"id": it.ID, "amount": it.Amount, "reason": it.DeferredReason})
	}
	details["commission"] = map[string]any{"closed_count": closedCount, "deferred": deferred}

	insurance := roundVND(float64(baseSalary) * st.InsuranceRate / 100)
	union := roundVND(float64(baseSalary) * st.UnionRate / 100)

	// Khoản nhập tay của phiếu cũ (giữ qua các lần tính lại).
	rec := payrolldb.UpsertRecordParams{PeriodID: p.ID, UserID: m.ID, ManualLines: []byte("[]")}
	if prev != nil {
		rec = paramsOf(*prev)
	}
	manual := parseManualLines(rec.ManualLines)
	hasManual := len(manual) > 0 || rec.ForeignSessionPay > 0 || rec.TaxDeduction > 0 || rec.KpiManualAmount != nil ||
		(rec.RetentionTier != nil && ret.Retained > 0)
	if baseSalary == 0 && sessions == 0 && commissionBonus == 0 && clawback == 0 && commissionDeferred == 0 && kpiAuto == 0 &&
		renewAmount == 0 && !hasManual {
		return rec, false, nil
	}

	rec.Department, rec.EmployeeType, rec.SalaryRole = ptr(prof.Department), ptr(prof.EmployeeType), ptr(prof.SalaryRole)
	rec.BaseSalary = baseSalary
	rec.ActualHours = round2(hours)
	rec.TeachingSessions = int32(sessions)
	rec.TeachingSalary = teachingSalary
	rec.TeachingConcurrent = share != nil
	rec.RetentionBaseStudents, rec.RetentionStudents = int32(ret.Base), int32(ret.Retained)
	rec.KpiSource, rec.KpiScore, rec.KpiBonus = ptr(kpiSource), kpiScore, kpiAuto
	rec.RenewBonus = renewAmount
	rec.CommissionBonus, rec.CommissionBase, rec.CommissionClawback, rec.CommissionDeferred = commissionBonus, commissionBase, clawback, commissionDeferred
	rec.CommissionPercent, rec.CommissionClosedCount = currentPercent, int32(closedCount)
	rec.PenaltyDeduction = penaltyDeduction
	rec.ForeignTeacherSessionsCount, rec.ForeignTeacherDeduction = int32(foreign), 0
	rec.InsuranceDeduction, rec.UnionDeduction = insurance, union
	rec.OtherBonus = 0
	rec.CalculationDetails = mustJSON(details)
	applyManualInputs(&rec)
	rec.Notes = ptr(autoNote(&rec))
	return rec, true, nil
}

// ───────────── KPI Học vụ / Admin ─────────────

type academicKpiResult struct {
	Amount  int64
	Score   *float64
	Fund    int64
	SheetID *uuid.UUID
	Items   []map[string]any
}

// academicKpi: quỹ × điểm KPI có trọng số của phiếu KPI chính đã chốt (academicKpiFor).
func (s *Service) academicKpi(ctx context.Context, q *payrolldb.Queries, user uuid.UUID, p payrolldb.HrPayrollPeriod, st Settings) (academicKpiResult, error) {
	out := academicKpiResult{Fund: st.AcademicFund, Items: []map[string]any{}}
	sheet, err := q.MainKpiSheet(ctx, payrolldb.MainKpiSheetParams{UserID: user, Year: p.Year, Month: p.Month})
	if err != nil {
		if isNoRows(err) {
			return out, nil
		}
		return out, err
	}
	if sheet.Status != "confirmed" {
		return out, nil
	}
	criteria, err := q.AcademicKpiCriteria(ctx)
	if err != nil {
		return out, err
	}
	items, err := q.SheetItems(ctx, sheet.ID)
	if err != nil {
		return out, err
	}
	scores := map[uuid.UUID]float64{}
	for _, it := range items {
		scores[it.CriterionID] = it.Score
	}
	matched := 0
	points := map[uuid.UUID]float64{}
	var pointTotal, weightTotal float64
	for _, c := range criteria {
		if _, ok := scores[c.ID]; ok {
			matched++
		}
		points[c.ID] = scores[c.ID] * c.Weight
		pointTotal += points[c.ID]
		weightTotal += c.Weight
	}
	fund := float64(st.AcademicFund)
	var score float64
	amountOf := func(id uuid.UUID) int64 { return 0 }
	if len(items) > 0 && matched == 0 {
		// Phiếu chấm theo bộ tiêu chí cũ: giữ tổng điểm đã chốt, chia tiền từng mục theo tỉ lệ điểm × trọng số.
		score = round2(sheet.TotalScore)
		amountOf = func(id uuid.UUID) int64 {
			if pointTotal <= 0 {
				return 0
			}
			return roundVND(fund * score / 100 * points[id] / pointTotal)
		}
	} else if weightTotal > 0 {
		score = round2(pointTotal / weightTotal)
		amountOf = func(id uuid.UUID) int64 { return roundVND(fund * points[id] / weightTotal / 100) }
	}
	for _, c := range criteria {
		out.Items = append(out.Items, map[string]any{"code": deref(c.Code), "group": deref(c.GroupName), "name": c.Name,
			"weight": c.Weight, "score": scores[c.ID], "amount": amountOf(c.ID)})
	}
	id := sheet.ID
	out.Amount, out.Score, out.SheetID = roundVND(fund*score/100), &score, &id
	return out, nil
}

// ownKpiScore: % KPI của chính Admin (phiếu KPI đã chốt), chỉ để xem.
func ownKpiScore(ctx context.Context, q *payrolldb.Queries, user uuid.UUID, p payrolldb.HrPayrollPeriod) (*float64, error) {
	sheet, err := q.MainKpiSheet(ctx, payrolldb.MainKpiSheetParams{UserID: user, Year: p.Year, Month: p.Month})
	if err != nil {
		if isNoRows(err) {
			return nil, nil
		}
		return nil, err
	}
	if sheet.Status != "confirmed" {
		return nil, nil
	}
	return ptr(round2(sheet.TotalScore)), nil
}

// ───────────── Thưởng tái tục ─────────────

type renewalResult struct {
	Amount  int64
	Classes []map[string]any
}

// renewalFor: mỗi lớp GV chính có HS đầu kỳ: % theo số HS nghỉ × doanh thu lớp trong kỳ (renewalBonusFor).
func renewalFor(owned []payrolldb.ClassesOfTeacherRow, d *calcData, p payrolldb.HrPayrollPeriod, revenue map[uuid.UUID]int64) renewalResult {
	out := renewalResult{Classes: []map[string]any{}}
	for _, c := range owned {
		r := d.retentionFor([]uuid.UUID{c.ID}, p)
		if r.Base == 0 {
			continue
		}
		pct, pending := d.st.renewalPercent(r.Lost)
		amount := roundVND(float64(revenue[c.ID]) * pct / 100)
		out.Amount += amount
		out.Classes = append(out.Classes, map[string]any{"class_id": c.ID, "class": classLabel(c.Code, c.Name), "base": r.Base,
			"quits": r.Lost, "percent": pct, "pending": pending, "revenue": revenue[c.ID], "amount": amount})
	}
	return out
}

func classLabel(code, name string) string {
	if code != "" {
		return strings.TrimSpace(code + " — " + name)
	}
	return strings.TrimSpace(name)
}

// ───────────── Học thuật kiêm nhiệm giảng dạy (TeachingShareService) ─────────────

var sharedTypes = []string{"regular", "sub"}

const defaultCourseSessions = 24

type shareOutcome struct {
	payOutcome
	Percent  float64
	Students int
	Tuition  float64
}

type shareResult struct {
	Amount         int64
	Sessions       int
	Outcomes       map[uuid.UUID]shareOutcome
	Classes        []map[string]any
	Skipped        int
	DefaultPercent float64
}

func (s *Service) teachingShare(ctx context.Context, q *payrolldb.Queries, sheets []Timesheet, d *calcData) (shareResult, error) {
	out := shareResult{Outcomes: map[uuid.UUID]shareOutcome{}, Classes: []map[string]any{}, DefaultPercent: d.st.TeachingShare}
	var shared []Timesheet
	classIDs := []uuid.UUID{}
	for _, ts := range sheets {
		t := ts.Type
		if t == "" {
			t = "regular"
		}
		if ts.ClassID != nil && slices.Contains(sharedTypes, t) {
			shared = append(shared, ts)
			if !slices.Contains(classIDs, *ts.ClassID) {
				classIDs = append(classIDs, *ts.ClassID)
			}
		}
	}
	out.Skipped = len(sheets) - len(shared)
	if len(shared) == 0 {
		return out, nil
	}
	members, err := q.ClassMembers(ctx, classIDs)
	if err != nil {
		return out, err
	}
	studentIDs := []uuid.UUID{}
	for _, m := range members {
		if !slices.Contains(studentIDs, m.StudentID) {
			studentIDs = append(studentIDs, m.StudentID)
		}
	}
	prices := map[string]float64{}
	paused := map[uuid.UUID][]payrolldb.StudentDeferralsRow{}
	if len(studentIDs) > 0 {
		tuitions, err := q.TuitionUnitPrices(ctx, studentIDs)
		if err != nil {
			return out, err
		}
		for _, t := range tuitions {
			if t.SessionCount <= 0 {
				continue
			}
			price := round2(math.Max(0, float64(t.TotalAmount-t.DiscountAmount)) / float64(t.SessionCount))
			prices[t.StudentID.String()+"-*"] = price
			if t.ClassID != nil && slices.Contains(classIDs, *t.ClassID) {
				prices[t.StudentID.String()+"-"+t.ClassID.String()] = price
			}
		}
		defs, err := q.StudentDeferrals(ctx, studentIDs)
		if err != nil {
			return out, err
		}
		for _, df := range defs {
			paused[df.StudentID] = append(paused[df.StudentID], df)
		}
	}
	type classAcc struct {
		id                        uuid.UUID
		label                     string
		listed                    float64
		sessions, studentSessions int
		students                  []uuid.UUID
		sessionStudents           [][]uuid.UUID
		percents                  []float64
		tuition, amount           float64
	}
	acc := map[uuid.UUID]*classAcc{}
	order := []uuid.UUID{}
	for _, ts := range shared {
		cid := *ts.ClassID
		c, ok := d.classes[cid]
		day := asDay(ts.Date)
		pct := d.st.TeachingShare
		if v := d.rates.tuitionPercent(ts.UserID, ts.Date); v != nil {
			pct = *v
		}
		var listed float64
		if ok {
			n := int(c.CourseSessions)
			if n <= 0 {
				n = defaultCourseSessions
			}
			listed = round2(float64(c.Fee) / float64(n))
		}
		present := []uuid.UUID{}
		for _, m := range members {
			if m.ClassID != cid || asDay(m.FromDay).After(day) || !day.Before(asDay(m.UntilDay)) {
				continue
			}
			isPaused := false
			for _, df := range paused[m.StudentID] {
				if !day.Before(asDay(df.DeferFrom)) && !day.After(asDay(df.DeferTo)) {
					isPaused = true
					break
				}
			}
			if !isPaused && !slices.Contains(present, m.StudentID) {
				present = append(present, m.StudentID)
			}
		}
		var tuition float64
		for _, id := range present {
			if v, ok := prices[id.String()+"-"+cid.String()]; ok {
				tuition += v
			} else if v, ok := prices[id.String()+"-*"]; ok {
				tuition += v
			} else {
				tuition += listed
			}
		}
		tuition = round2(tuition)
		o := applyLateRules(ts, round2(tuition*pct/100), d.st)
		out.Outcomes[ts.ID] = shareOutcome{payOutcome: o, Percent: pct, Students: len(present), Tuition: tuition}

		a := acc[cid]
		if a == nil {
			label := "Lớp " + cid.String()[:8]
			if ok {
				label = classLabel(c.Code, c.Name)
			}
			a = &classAcc{id: cid, label: label, listed: listed}
			acc[cid] = a
			order = append(order, cid)
		}
		if o.Counted {
			a.sessions++
			a.tuition += tuition
			a.sessionStudents = append(a.sessionStudents, present)
			a.studentSessions += len(present)
		}
		for _, id := range present {
			if !slices.Contains(a.students, id) {
				a.students = append(a.students, id)
			}
		}
		if !slices.Contains(a.percents, pct) {
			a.percents = append(a.percents, pct)
		}
		a.amount += o.Amount
	}
	for _, cid := range order {
		a := acc[cid]
		always := 0
		if len(a.sessionStudents) > 0 {
			for _, id := range a.students {
				all := true
				for _, ss := range a.sessionStudents {
					if !slices.Contains(ss, id) {
						all = false
						break
					}
				}
				if all {
					always++
				}
			}
		}
		avg := a.listed
		if a.studentSessions > 0 {
			avg = float64(roundVND(a.tuition / float64(a.studentSessions)))
		}
		var pct *float64
		if len(a.percents) == 1 {
			pct = &a.percents[0]
		}
		amount := roundVND(a.amount)
		out.Amount += amount
		out.Sessions += a.sessions
		out.Classes = append(out.Classes, map[string]any{"class_id": a.id, "class": a.label, "listed_price": a.listed, "avg_price": avg,
			"students": len(a.students), "partial_students": max(0, len(a.students)-always), "sessions": a.sessions, "percent": pct,
			"tuition": roundVND(a.tuition), "amount": amount})
	}
	return out, nil
}
