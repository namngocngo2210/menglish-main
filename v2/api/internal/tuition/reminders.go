package tuition

import (
	"context"
	"fmt"
	"slices"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/catalogs"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/tuition/tuitiondb"
)

// Việc chạy theo lịch của Học phí (routes/console.php bản Laravel), giờ Việt Nam:
//   - 06:45 students:start-deferrals, 06:50 students:end-deferrals — bảo lưu bắt đầu / hết hạn theo khoản học phí;
//   - 08:30 tuition:send-debt-reminders — nhắc nợ theo mốc;
//   - 08:40 tuition:notify-refund-deadlines — SLA hồ sơ hoàn phí / chuyển nhượng;
//   - mỗi 15 phút tuition:check-cash-deposits — SLA tiền mặt chưa nộp về TK công ty.
// Mỗi việc chống gửi trùng ở dữ liệu (nhật ký nhắc nợ, khóa thông báo), nên chạy lại / nhiều tiến trình không trùng.

// RunReminderLoop chạy các việc trên tới khi ctx hủy; việc hằng ngày chạy một lần mỗi ngày (nhớ trong tiến trình).
func (s *Service) RunReminderLoop(ctx context.Context, tick time.Duration) {
	last := map[string]string{}
	t := time.NewTicker(tick)
	defer t.Stop()
	for {
		s.runScheduled(ctx, last)
		select {
		case <-ctx.Done():
			return
		case <-t.C:
		}
	}
}

func (s *Service) runScheduled(ctx context.Context, last map[string]string) {
	now := s.nowVN()
	at := now.Hour()*60 + now.Minute()
	day := now.Format(time.DateOnly)
	jobs := []struct {
		name string
		from int
		key  string
		run  func(context.Context) (int, error)
	}{
		{"start-deferrals", 6*60 + 45, day, s.StartDueDeferrals},
		{"end-deferrals", 6*60 + 50, day, s.EndDueDeferrals},
		{"debt-reminders", 8*60 + 30, day, s.SendDueDebtReminders},
		{"refund-deadlines", 8*60 + 40, day, s.NotifyRefundDeadlines},
		{"cash-deposits", 0, fmt.Sprintf("%s-%d", day, at/15), s.NotifyUndepositedCash},
	}
	for _, j := range jobs {
		if at < j.from || last[j.name] == j.key {
			continue
		}
		last[j.name] = j.key
		n, err := j.run(ctx)
		if err != nil {
			s.log.Error("tuition scheduled job failed", "job", j.name, "err", err)
			continue
		}
		if n > 0 {
			s.log.Info("tuition scheduled job", "job", j.name, "count", n)
		}
	}
}

// SendDueDebtReminders: mỗi mốc (số ngày so với hạn đóng) gửi cho khoản còn nợ có hạn đóng = hôm nay − số ngày, không
// đang khất nợ / bảo lưu. Chưa cấu hình mốc nào thì dùng T-7 / T-3 / T0 / T+3.
func (s *Service) SendDueDebtReminders(ctx context.Context) (int, error) {
	rules, err := s.debtRules(ctx)
	if err != nil {
		return 0, err
	}
	milestones := defaultMilestones
	if len(rules) > 0 {
		milestones = enabledRules(rules)
	}
	today := s.today()
	sent := 0
	for _, m := range milestones {
		ids, err := s.q.RemindableTuitionsDue(ctx, tuitiondb.RemindableTuitionsDueParams{Due: today.AddDate(0, 0, -int(m.OffsetDays)), Today: today})
		if err != nil {
			return sent, err
		}
		for _, id := range ids {
			t, err := s.q.ReminderTarget(ctx, id)
			if err != nil {
				return sent, err
			}
			res, err := s.remindDebt(ctx, t, m.MilestoneKey, nil)
			if err != nil {
				s.log.Warn("debt reminder failed", "tuition_id", id, "milestone", m.MilestoneKey, "err", err)
				continue
			}
			if res.Sent {
				sent++
			} else {
				s.log.Info("debt reminder skipped", "tuition_id", id, "reason", res.Reason)
			}
		}
	}
	return sent, nil
}

// holders: người dùng đang hoạt động có quyền perm và phạm vi học phí bao chi nhánh (TuitionSlaService::holders).
func (s *Service) holders(ctx context.Context, db tuitiondb.DBTX, perm string, branch *uuid.UUID) ([]uuid.UUID, error) {
	if s.access == nil {
		return iam.UserIDsWithPermission(ctx, db, perm, branch)
	}
	ids, err := iam.UserIDsWithPermission(ctx, db, perm, nil)
	if err != nil {
		return nil, err
	}
	out := make([]uuid.UUID, 0, len(ids))
	for _, id := range ids {
		a, err := s.access(ctx, id)
		if err != nil {
			return nil, err
		}
		if a != nil && a.Can(perm) && covers(a, branch) {
			out = append(out, id)
		}
	}
	return out, nil
}

func (s *Service) slaRule(ctx context.Context, key string) (catalogs.SlaRule, bool) {
	if s.cat == nil {
		return catalogs.SlaRule{}, false
	}
	r, err := s.cat.SlaRule(ctx, key)
	if err != nil {
		return catalogs.SlaRule{}, false
	}
	return r, true
}

// NotifyUndepositedCash: phiếu tiền mặt thu hôm nay quá giờ chốt (SLA tuition.cash_deposit, mặc định 19:00) vẫn chưa
// xác nhận nộp về TK công ty → báo người có quyền xác nhận (theo chi nhánh) và người thu, mỗi phiếu một lần.
func (s *Service) NotifyUndepositedCash(ctx context.Context) (int, error) {
	if r, ok := s.slaRule(ctx, "tuition.cash_deposit"); ok && !r.Enabled {
		return 0, nil
	}
	cutoff := s.depositCutoff(ctx)
	now := s.now()
	rows, err := s.q.LateCashReceipts(ctx, s.today())
	if err != nil {
		return 0, err
	}
	sent := 0
	for _, r := range rows {
		if r.PaymentDate == nil || !now.After(dayStart(*r.PaymentDate).Add(time.Duration(cutoff)*time.Minute)) {
			continue
		}
		ids, err := s.holders(ctx, s.pool, "tuition.confirm_deposit", r.StudentBranchID)
		if err != nil {
			return sent, err
		}
		if r.CreatorID != nil && !slices.Contains(ids, *r.CreatorID) {
			ids = append(ids, *r.CreatorID)
		}
		student := "học viên"
		if r.StudentName != nil {
			student = *r.StudentName
		}
		n, err := notify.SendOnce(ctx, s.pool, ids, "cash_deposit:"+r.ID.String(), notify.Notification{
			Type: "cash_deposit_overdue", Title: "Tiền mặt chưa nộp về TK công ty",
			Body: fmt.Sprintf("Phiếu %s (%s, %s) thu ngày %s chưa được xác nhận nộp về tài khoản công ty trước %s.", r.Code, money(r.Amount), student,
				r.PaymentDate.Format("02/01/2006"), cutoffLabel(cutoff)),
			Link: "/tuition/receipts/approve?selected_id=" + r.ID.String() + "&status=all",
			Data: map[string]any{"receipt_id": r.ID},
		})
		if err != nil {
			return sent, err
		}
		sent += n
	}
	return sent, nil
}

var refundTypeLabels = map[string]string{"refund": "Hoàn phí", "transfer": "Chuyển nhượng", "extension": "Khất nợ", "deferral": "Bảo lưu"}

func refundTypeLabel(t string) string {
	if l, ok := refundTypeLabels[t]; ok {
		return l
	}
	return t
}

// refundApprovePermission: quyền duyệt theo loại hồ sơ (TuitionRefundRequest::APPROVE_PERMISSIONS).
func refundApprovePermission(t string) string {
	switch t {
	case "refund":
		return "refund_transfer.approve_refund"
	case "transfer":
		return "refund_transfer.approve_transfer"
	}
	return "refund_transfer.approve"
}

// refundProcessingDays: SLA tuition.refund_processing (mặc định 7 ngày).
func (s *Service) refundProcessingDays(ctx context.Context) int {
	if r, ok := s.slaRule(ctx, "tuition.refund_processing"); ok && r.Value > 0 {
		return int(r.Value)
	}
	return 7
}

// refundDeadline: hạn xử lý = min(ngày lập + N ngày (cuối ngày), cuối tháng lập) — khớp sổ sách trong tháng.
func refundDeadline(created time.Time, days int) time.Time {
	c := created.In(vnZone)
	week := dayStart(c).AddDate(0, 0, days+1).Add(-time.Nanosecond)
	monthEnd := time.Date(c.Year(), c.Month()+1, 1, 0, 0, 0, 0, vnZone).Add(-time.Nanosecond)
	if week.Before(monthEnd) {
		return week
	}
	return monthEnd
}

// NotifyRefundDeadlines: hồ sơ hoàn tiền / chuyển nhượng còn chờ duyệt, còn ≤ 1 ngày tới hạn hoặc đã quá hạn → nhắc
// người có quyền duyệt loại đó (mỗi hồ sơ / loại nhắc / ngày một lần).
func (s *Service) NotifyRefundDeadlines(ctx context.Context) (int, error) {
	if r, ok := s.slaRule(ctx, "tuition.refund_processing"); ok && !r.Enabled {
		return 0, nil
	}
	days := s.refundProcessingDays(ctx)
	now, today := s.now(), s.today()
	rows, err := s.q.PendingDeadlineRefunds(ctx)
	if err != nil {
		return 0, err
	}
	sent := 0
	for _, r := range rows {
		deadline := refundDeadline(r.CreatedAt, days)
		overdue := now.After(deadline)
		if !overdue && daysBetween(today, dayStart(deadline)) > 1 {
			continue
		}
		kind, title, tail := "due_soon", "Hồ sơ %s sắp hết hạn xử lý", " — còn chưa tới 1 ngày."
		if overdue {
			kind, title, tail = "overdue", "Hồ sơ %s quá hạn xử lý", " — đã quá hạn."
		}
		label := refundTypeLabel(r.Type)
		ids, err := s.holders(ctx, s.pool, refundApprovePermission(r.Type), r.StudentBranchID)
		if err != nil {
			return sent, err
		}
		n, err := notify.SendOnce(ctx, s.pool, ids, "refund_deadline:"+r.ID.String()+":"+kind+":"+today.Format(time.DateOnly), notify.Notification{
			Type: "refund_deadline", Title: fmt.Sprintf(title, label),
			Body: fmt.Sprintf("%s của %s phải xử lý trong %d ngày (cùng tháng), hạn %s%s", label, r.StudentName, days, deadline.Format("02/01/2006"), tail),
			Link: "/tuition/refunds",
			Data: map[string]any{"request_id": r.ID, "kind": kind, "date": today.Format(time.DateOnly)},
		})
		if err != nil {
			return sent, err
		}
		sent += n
	}
	return sent, nil
}

// StartDueDeferrals: bảo lưu đã duyệt trước tới ngày bắt đầu → đóng băng số buổi còn lại / công nợ tại ngày bắt đầu và
// học viên chuyển "Bảo lưu" (StudentDeferralService::start).
func (s *Service) StartDueDeferrals(ctx context.Context) (int, error) {
	if s.students == nil {
		return 0, nil
	}
	ids, err := s.q.StartingDeferralStudents(ctx, s.today())
	if err != nil {
		return 0, err
	}
	started := 0
	for _, id := range ids {
		ok := false
		err := s.inTx(ctx, func(tx pgx.Tx, q *tuitiondb.Queries) error {
			t, err := q.LockLatestTuitionDeferral(ctx, id)
			if isNoRows(err) {
				return nil
			}
			if err != nil {
				return err
			}
			today := s.today()
			if t.DeferredFrom == nil || t.DeferredUntil == nil || t.DeferredFrom.After(today) || t.DeferredUntil.Before(today) {
				return nil
			}
			if ok, err = s.students.StartScheduledDeferral(ctx, tx, id, *t.DeferredUntil); err != nil || !ok {
				return err
			}
			cur, err := s.recalculateDebt(ctx, q, t.ID)
			if err != nil {
				return err
			}
			st, err := q.TuitionSessionStats(ctx, t.ID)
			if err != nil {
				return err
			}
			var remaining *int32
			what := "số buổi còn lại"
			if total, attended, has := sessionStats(st.CourseLessons, st.ScheduledSessions, st.AttendedSessions, st.StatsClassID != nil); has {
				remaining = ptr(int32(max(0, total-attended)))
				what = fmt.Sprintf("%d buổi còn lại", *remaining)
			}
			line := "[" + s.nowVN().Format("02/01/2006 15:04") + "] Bắt đầu bảo lưu: đóng băng " + what + " và công nợ " + money(cur.DebtAmount) + "."
			notes := line
			if t.Notes != nil && *t.Notes != "" {
				notes = *t.Notes + "\n" + line
			}
			return q.FreezeTuitionDeferral(ctx, tuitiondb.FreezeTuitionDeferralParams{ID: t.ID, FrozenRemainingSessions: remaining,
				FrozenDebtAmount: &cur.DebtAmount, Notes: &notes})
		})
		if err != nil {
			s.log.Warn("start deferral failed", "student_id", id, "err", err)
			continue
		}
		if ok {
			started++
		}
	}
	return started, nil
}

// EndDueDeferrals: học viên đang Bảo lưu có ngày cuối bảo lưu trước hôm nay → học lại (StudentDeferralService::end).
func (s *Service) EndDueDeferrals(ctx context.Context) (int, error) {
	if s.students == nil {
		return 0, nil
	}
	ids, err := s.q.DueDeferralStudents(ctx, s.today())
	if err != nil {
		return 0, err
	}
	ended := 0
	for _, id := range ids {
		ok, err := s.students.EndExpiredDeferral(ctx, id)
		if err != nil {
			s.log.Warn("end deferral failed", "student_id", id, "err", err)
			continue
		}
		if ok {
			ended++
		}
	}
	return ended, nil
}
