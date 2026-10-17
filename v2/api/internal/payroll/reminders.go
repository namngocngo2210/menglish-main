package payroll

import (
	"context"
	"fmt"
	"slices"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/payroll/payrolldb"
)

// Việc chạy theo lịch (routes/console.php bản Laravel), giờ Việt Nam:
//   - 08:30 penalties:remind-due — nhắc nộp phạt khi hạn nộp là hôm nay / ngày mai;
//   - 08:35 payroll:remind-calendar — nhắc lịch chốt KPI / công / lỗi và khung trả lương 10–15.
// Chống gửi trùng bằng khóa thông báo (notify.SendOnce), chạy lại / nhiều tiến trình không trùng.

// RunReminderLoop chạy các việc trên tới khi ctx hủy; mỗi việc một lần mỗi ngày (nhớ trong tiến trình).
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
	now := s.now().In(vnZone)
	at := now.Hour()*60 + now.Minute()
	day := now.Format(time.DateOnly)
	jobs := []struct {
		name string
		from int
		run  func(context.Context) (int, error)
	}{
		{"penalty-due", 8*60 + 30, s.RemindPenaltiesDue},
		{"payroll-calendar", 8*60 + 35, s.RemindCalendar},
	}
	for _, j := range jobs {
		if at < j.from || last[j.name] == day {
			continue
		}
		last[j.name] = day
		n, err := j.run(ctx)
		if err != nil {
			s.log.Error("payroll scheduled job failed", "job", j.name, "err", err)
			continue
		}
		if n > 0 {
			s.log.Info("payroll scheduled job", "job", j.name, "count", n)
		}
	}
}

// RemindPenaltiesDue: biên bản đã quyết phạt, hạn nộp hôm nay / ngày mai → báo nhân sự vi phạm (1 lần / biên bản / ngày).
func (s *Service) RemindPenaltiesDue(ctx context.Context) (int, error) {
	today := s.today()
	rows, err := s.q.PenaltiesDueSoon(ctx, payrolldb.PenaltiesDueSoonParams{Today: ptr(dbDay(today)), Tomorrow: ptr(dbDay(today.AddDate(0, 0, 1)))})
	if err != nil {
		return 0, err
	}
	sent := 0
	for _, p := range rows {
		when := "là ngày mai"
		if p.DueDate != nil && asDay(*p.DueDate).Equal(today) {
			when = "là hôm nay"
		}
		n, err := notify.SendOnce(ctx, s.pool, []uuid.UUID{p.UserID}, p.ID.String()+"|"+today.Format(time.DateOnly), notify.Notification{
			Type:  "penalty_due_reminder",
			Title: "Biên bản " + p.Code + ": hạn nộp phạt " + when,
			Body: "Nộp " + money(p.Amount) + " trước hết ngày " + dmy(deref(p.DueDate)) +
				" — quá hạn không nhận nộp trực tiếp, khoản phạt sẽ trừ vào lương kỳ này.",
			Link: "/penalties?search=" + p.Code,
			Data: map[string]any{"penalty_id": p.ID, "remind_on": today.Format(time.DateOnly)},
		})
		if err != nil {
			return sent, err
		}
		sent += n
	}
	return sent, nil
}

// RemindCalendar: nhắc mốc lịch chốt lương của các kỳ chưa chi trả (payroll:remind-calendar).
func (s *Service) RemindCalendar(ctx context.Context) (int, error) {
	today := s.today()
	periods, err := s.q.AllPeriods(ctx)
	if err != nil {
		return 0, err
	}
	sent := 0
	for _, p := range periods {
		if p.Status == "paid" {
			continue
		}
		clock := s.clockOf(ctx, s.q, p)
		label := fmt.Sprintf("tháng %02d/%d", p.Month, p.Year)
		link := "/payroll/periods/" + p.ID.String()
		var n int
		if today.Equal(clock.end) && !locked(p.Status) {
			records, err := s.q.ExistingRecords(ctx, p.ID)
			if err != nil {
				return sent, err
			}
			pending := 0
			for _, r := range records {
				if kpiState(r)[0] == "pending" {
					pending++
				}
			}
			body := "Chốt đánh giá KPI tháng."
			if pending > 0 {
				body = fmt.Sprintf("Chốt đánh giá KPI tháng — còn %d nhân sự chưa chốt KPI trên bảng lương.", pending)
			}
			if n, err = s.calendarNotify(ctx, p, "kpi_close", []string{"kpi.confirm"}, "Hôm nay chốt KPI "+label, body,
				fmt.Sprintf("/kpi/monthly?period=%04d-%02d", p.Year, p.Month)); err != nil {
				return sent, err
			}
			sent += n
		}
		after := int(today.Sub(clock.end).Hours() / 24)
		if after >= 1 && after <= clock.days && !locked(p.Status) {
			if n, err = s.calendarNotify(ctx, p, fmt.Sprintf("close_d%d", after),
				[]string{"attendance_staff.view", PermConfirmFine, PermApprove},
				"Sắp chốt công và chốt lỗi "+label+" (hết ngày "+clock.closeAt.Format("02/01")+")",
				"Hoàn tất chấm công / duyệt giờ dạy và quyết định các biên bản vi phạm trước khi chốt bảng lương.", link); err != nil {
				return sent, err
			}
			sent += n
		}
		from, _ := clock.payWindow()
		if today.Day() == payDayFrom && today.Year() == from.Year() && today.Month() == from.Month() {
			body := fmt.Sprintf("Bảng lương chưa chốt — hoàn tất chốt lương để kịp trả trong khung %d–%d.", payDayFrom, payDayTo)
			if locked(p.Status) {
				body = fmt.Sprintf("Bảng lương đã chốt — chi trả từ hôm nay đến ngày %d.", payDayTo)
			}
			if n, err = s.calendarNotify(ctx, p, "pay_window", []string{PermApprove, PermMarkPaid},
				fmt.Sprintf("Trả lương %s: %d–%d tháng này", label, payDayFrom, payDayTo), body, link); err != nil {
				return sent, err
			}
			sent += n
		}
	}
	return sent, nil
}

func (s *Service) calendarNotify(ctx context.Context, p payrolldb.HrPayrollPeriod, stage string, perms []string, title, body, link string) (int, error) {
	var ids []uuid.UUID
	for _, perm := range perms {
		got, err := iam.UserIDsWithPermission(ctx, s.pool, perm, nil)
		if err != nil {
			// Quyền chưa có trong danh mục (phân hệ chưa gộp): bỏ qua.
			s.log.Warn("payroll calendar: permission lookup", "perm", perm, "err", err)
			continue
		}
		for _, id := range got {
			if !slices.Contains(ids, id) {
				ids = append(ids, id)
			}
		}
	}
	return notify.SendOnce(ctx, s.pool, ids, p.ID.String()+"|"+stage, notify.Notification{Type: "payroll_calendar", Title: title, Body: body,
		Link: link, Data: map[string]any{"period_id": p.ID, "stage": stage}})
}
