package reports

import (
	"context"
	"fmt"
	"strings"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/reports/reportsdb"
)

// Nhắc hạn báo cáo (lệnh Laravel reports:remind-daily 08:00, reports:remind-monthly 08:00, reports:daily-digest 09:05).
// Phần tính ai cần nhắc làm ở đây; gửi qua notify.SendOnce (không trùng khi lệnh chạy lại / tiến trình khởi động lại).

// Reminder là một thông báo cần gửi cho một người.
type Reminder struct {
	UserID  uuid.UUID
	Name    string
	Type    string
	Title   string
	Message string
	Link    string
}

// DailyReminders: Học vụ / Học thuật phải nộp báo cáo ngày hôm qua mà chưa nộp (hạn 09:00 sáng nay).
func (s *Service) DailyReminders(ctx context.Context) ([]Reminder, error) {
	yesterday := s.today().AddDate(0, 0, -1)
	writers, err := s.writers(ctx, nil, uuid.Nil)
	if err != nil {
		return nil, err
	}
	var out []Reminder
	for _, w := range writers {
		days, err := s.missingDays(ctx, rowWriter(w), yesterday, yesterday)
		if err != nil {
			return nil, err
		}
		if len(days) == 0 {
			continue
		}
		out = append(out, Reminder{UserID: w.ID, Name: w.FullName, Type: "daily_report_due",
			Title:   "Chưa nộp báo cáo ngày " + yesterday.Format("02/01"),
			Message: "Hạn nộp " + dailyDeadline(yesterday).Format("15:04 02/01") + ". Nộp sau hạn tính là nộp trễ.",
			Link:    "/reports/daily?date=" + dateKey(yesterday)})
	}
	return out, nil
}

var monthlyReminderLabels = map[string]string{"due_minus_3": "còn 3 ngày", "due_minus_1": "còn 1 ngày", "due": "hôm nay là hạn"}

// MonthlyReminders: GV / trợ giảng chưa nộp báo cáo tháng này, vào 3 ngày trước, 1 ngày trước và đúng hạn (Chủ nhật cuối
// tháng). Người kiêm giáo viên (kỳ chính khác tháng) vẫn được nhắc báo cáo tháng giáo viên.
func (s *Service) MonthlyReminders(ctx context.Context) ([]Reminder, error) {
	now := s.now()
	kind := monthlyReminderKind(now)
	if kind == "" {
		return nil, nil
	}
	month := monthKey(now)
	due := monthlyDueDate(now)
	users, err := s.q.ListActiveUsersWithRoles(ctx, reportsdb.ListActiveUsersWithRolesParams{Roles: teachingRoles})
	if err != nil {
		return nil, err
	}
	var out []Reminder
	for _, u := range users {
		teaches := hasAny(u.Roles, teacherRoles...)
		if reportCadence(u.Roles) == "monthly" {
			done, err := s.SubmittedCurrentPeriod(ctx, u.ID, u.Roles, now)
			if err != nil {
				return nil, err
			}
			if done {
				continue
			}
		} else {
			r, err := s.structuredReport(ctx, u.ID, KindTeacherMonthly, month)
			if err != nil {
				return nil, err
			}
			if r != nil && r.Status == "submitted" {
				continue
			}
		}
		link := "/reports"
		if teaches {
			link = "/reports/teacher-monthly?month=" + month
		}
		out = append(out, Reminder{UserID: u.ID, Name: u.FullName, Type: "monthly_report_due",
			Title:   "Nhắc nộp báo cáo giảng dạy tháng " + now.In(vnZone).Format("01/2006"),
			Message: "Hạn nộp: Chủ nhật " + due.Format("02/01") + " (" + monthlyReminderLabels[kind] + "). Bạn chưa nộp báo cáo tháng này.",
			Link:    link})
	}
	return out, nil
}

// Digest là bản tổng hợp báo cáo ngày hôm qua gửi Admin (thay cho thông báo từng báo cáo ngày).
type Digest struct {
	Date      string
	Due       int
	Submitted int
	Missing   []string
	Title     string
	Message   string
	Link      string
}

// DailyDigest: "Đã nộp x/y" + tên người còn thiếu; nil khi hôm qua không ai phải nộp (Chủ nhật, lễ).
func (s *Service) DailyDigest(ctx context.Context) (*Digest, error) {
	yesterday := s.today().AddDate(0, 0, -1)
	date := dateKey(yesterday)
	writers, err := s.writers(ctx, nil, uuid.Nil)
	if err != nil {
		return nil, err
	}
	d := &Digest{Date: date}
	for _, w := range writers {
		req, err := s.requiredDays(ctx, rowWriter(w), yesterday, yesterday)
		if err != nil {
			return nil, err
		}
		if len(req) == 0 {
			continue
		}
		d.Due++
		miss, err := s.missingDays(ctx, rowWriter(w), yesterday, yesterday)
		if err != nil {
			return nil, err
		}
		if len(miss) > 0 {
			d.Missing = append(d.Missing, w.FullName)
		}
	}
	if d.Due == 0 {
		return nil, nil
	}
	d.Submitted = d.Due - len(d.Missing)
	d.Title = fmt.Sprintf("Báo cáo ngày %s: đã nộp %d/%d", yesterday.Format("02/01"), d.Submitted, d.Due)
	if len(d.Missing) == 0 {
		d.Message = "Mọi người đã nộp đủ. Mở để kiểm tra."
		d.Link = "/reports/all?type=daily&from=" + date + "&to=" + date
	} else {
		d.Message = "Chưa nộp: " + strings.Join(d.Missing, ", ") + "."
		d.Link = "/reports/all?view=missing&from=" + date + "&to=" + date
	}
	return d, nil
}

// RunReminderLoop chạy các việc nhắc theo giờ Việt Nam: 08:00 nhắc báo cáo ngày + tháng, 09:05 tổng hợp cho Admin.
// Mỗi việc chạy một lần mỗi ngày (nhớ trong tiến trình).
func (s *Service) RunReminderLoop(ctx context.Context, tick time.Duration) {
	last := map[string]string{}
	t := time.NewTicker(tick)
	defer t.Stop()
	for {
		s.runDueReminders(ctx, last)
		select {
		case <-ctx.Done():
			return
		case <-t.C:
		}
	}
}

func (s *Service) runDueReminders(ctx context.Context, last map[string]string) {
	now := s.now().In(vnZone)
	today := dateKey(now)
	at := now.Hour()*60 + now.Minute()
	jobs := []struct {
		name string
		from int
		run  func(context.Context) error
	}{
		{"remind-daily", 8 * 60, s.sendDailyReminders},
		{"remind-monthly", 8 * 60, s.sendMonthlyReminders},
		{"daily-digest", 9*60 + 5, s.sendDailyDigest},
	}
	for _, j := range jobs {
		if at < j.from || last[j.name] == today {
			continue
		}
		last[j.name] = today
		if err := j.run(ctx); err != nil {
			s.log.Error("report reminder failed", "job", j.name, "err", err)
		}
	}
}

func (s *Service) sendDailyReminders(ctx context.Context) error {
	list, err := s.DailyReminders(ctx)
	if err != nil {
		return err
	}
	// Không gửi trùng theo (người, ngày báo cáo).
	key := "daily:" + dateKey(s.today().AddDate(0, 0, -1))
	if err := s.sendReminders(ctx, list, key); err != nil {
		return err
	}
	s.log.Info("daily report reminders", "count", len(list))
	return nil
}

// sendReminders gửi từng lời nhắc (mỗi người một nội dung), bỏ người đã nhận cùng key.
func (s *Service) sendReminders(ctx context.Context, list []Reminder, key string) error {
	for _, r := range list {
		if _, err := notify.SendOnce(ctx, s.pool, []uuid.UUID{r.UserID}, key, notify.Notification{
			Type: r.Type, Title: r.Title, Body: r.Message, Link: r.Link,
		}); err != nil {
			return err
		}
	}
	return nil
}

func (s *Service) sendMonthlyReminders(ctx context.Context) error {
	list, err := s.MonthlyReminders(ctx)
	if err != nil {
		return err
	}
	// Không trùng theo (người, tháng, loại nhắc).
	now := s.now()
	if err := s.sendReminders(ctx, list, "monthly:"+monthKey(now)+":"+monthlyReminderKind(now)); err != nil {
		return err
	}
	s.log.Info("monthly report reminders", "count", len(list))
	return nil
}

func (s *Service) sendDailyDigest(ctx context.Context) error {
	d, err := s.DailyDigest(ctx)
	if err != nil || d == nil {
		return err
	}
	// Gửi mọi Admin, không trùng theo ngày.
	admins, err := iam.AdminIDs(ctx, s.pool)
	if err != nil {
		return err
	}
	if _, err := notify.SendOnce(ctx, s.pool, admins, "digest:"+d.Date, notify.Notification{
		Type: "staff_report_digest", Title: d.Title, Body: d.Message, Link: d.Link,
	}); err != nil {
		return err
	}
	s.log.Info("daily report digest", "date", d.Date, "submitted", d.Submitted, "due", d.Due)
	return nil
}
