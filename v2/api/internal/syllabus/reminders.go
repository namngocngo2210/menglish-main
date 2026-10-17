package syllabus

import (
	"context"
	"fmt"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/syllabus/syllabusdb"
)

// Việc chạy nền (lịch của Laravel): 07:45 bigtests:remind-upcoming, 07:55 bigtests:enforce-sla, mỗi giờ phút 55
// bigtests:enforce-sla --paper-only, mỗi giờ phút 20 syllabus:notify-adjustment-sla.

// lateScanDays: chỉ quét kết quả trễ trong 90 ngày gần nhất (không phạt hồi tố).
const lateScanDays = 90

// RunLoop chạy các việc nhắc / SLA theo giờ Việt Nam; mỗi việc một lần mỗi ngày (hoặc mỗi giờ), nhớ trong tiến trình.
func (s *Service) RunLoop(ctx context.Context, tick time.Duration) {
	last := map[string]string{}
	t := time.NewTicker(tick)
	defer t.Stop()
	for {
		s.runDue(ctx, last)
		select {
		case <-ctx.Done():
			return
		case <-t.C:
		}
	}
}

func (s *Service) runDue(ctx context.Context, last map[string]string) {
	now := s.now().In(vnZone)
	day, hour := now.Format(time.DateOnly), now.Format("2006-01-02 15")
	at := now.Hour()*60 + now.Minute()
	jobs := []struct {
		name string
		key  string // chạy lại khi key đổi (ngày / giờ)
		due  bool
		run  func(context.Context) error
	}{
		{"remind-upcoming", day, at >= 7*60+45, func(ctx context.Context) error { _, err := s.RemindUpcoming(ctx); return err }},
		{"enforce-sla", day, at >= 7*60+55, func(ctx context.Context) error { _, err := s.EnforceSLA(ctx, false); return err }},
		{"paper-missing", hour, now.Minute() >= 55, func(ctx context.Context) error { _, err := s.EnforceSLA(ctx, true); return err }},
		{"adjustment-sla", hour, now.Minute() >= 20, func(ctx context.Context) error { _, err := s.NotifyPacingBreaches(ctx); return err }},
	}
	for _, j := range jobs {
		if !j.due || last[j.name] == j.key {
			continue
		}
		last[j.name] = j.key
		if err := j.run(ctx); err != nil {
			s.log.Error("syllabus job failed", "job", j.name, "err", err)
		}
	}
}

// NotifyPacingBreaches: yêu cầu giãn tiến độ quá hạn duyệt → báo người duyệt 1 lần.
func (s *Service) NotifyPacingBreaches(ctx context.Context) (int, error) {
	return s.notifyPacingBreaches(ctx)
}

// endOfDayIn: 23:59:59 giờ Việt Nam của ngày today+days.
func endOfDayIn(today time.Time, days int) time.Time {
	return startOfDayVN(today.AddDate(0, 0, days+1)).Add(-time.Second)
}

// ReminderStats: số việc đã làm của một lượt nhắc.
type ReminderStats struct {
	Tests         int `json:"tests"`
	TeacherNotes  int `json:"teacher_notes"`
	Stages        int `json:"stages"`
	PaperReminder int `json:"paper_reminders"`
}

// RemindUpcoming: nhắc GV các đợt thi trong N ngày tới (1 lần / đợt thi), đề chưa phân phối thì báo người duyệt; nhắc chặng
// có ngày dự kiến Big Test mà chưa có đợt thi; nhắc Admin duyệt đề mỗi ngày.
func (s *Service) RemindUpcoming(ctx context.Context) (ReminderStats, error) {
	var st ReminderStats
	if !s.rule(ctx, rulePaperReminder).Enabled {
		return st, nil
	}
	days := max(1, s.warnDays(ctx))
	now, today := s.now(), s.today()
	end := endOfDayIn(today, days)
	approvers, err := approverIDs(ctx, s.pool)
	if err != nil {
		return st, err
	}
	tests, err := s.q.UpcomingUnreminded(ctx, syllabusdb.UpcomingUnremindedParams{FromAt: &now, ToAt: &end})
	if err != nil {
		return st, err
	}
	for _, t := range tests {
		when := fmtDateTime(t.ScheduledAt)
		left := s.daysUntil(*t.ScheduledAt)
		exam := "Đề CHƯA được duyệt — liên hệ Học thuật."
		if t.IsDistributed {
			exam = "Đề đã duyệt & phân phối."
		}
		staff := uniqueIDs(t.TeacherID, t.ForeignTeacherID, t.AssistantID)
		err := s.inTx(ctx, func(tx pgx.Tx, q *syllabusdb.Queries) error {
			if err := send(ctx, tx, staff, notify.Notification{
				Type: "big_test_upcoming", Title: "Nhắc lịch Big Test: " + t.Title,
				Body: fmt.Sprintf("Lớp %s thi \"%s\" lúc %s tại %s (còn %d ngày). %s", t.ClassName, t.Title, when, deref(t.Room), left, exam),
				Link: schedulesLink, Data: map[string]any{"big_test_id": t.ID.String()},
			}); err != nil {
				return err
			}
			if !t.IsDistributed {
				if err := send(ctx, tx, approvers, notify.Notification{
					Type: "big_test_upcoming", Title: "Big Test chưa duyệt đề: " + t.Code,
					Body: fmt.Sprintf("Lớp %s thi \"%s\" lúc %s (còn %d ngày) nhưng đề chưa được duyệt & phân phối.", t.ClassName, t.Title, when, left),
					Link: distributionLink, Data: map[string]any{"big_test_id": t.ID.String()},
				}); err != nil {
					return err
				}
			}
			return q.MarkTeacherReminded(ctx, t.ID)
		})
		if err != nil {
			return st, err
		}
		st.Tests++
		st.TeacherNotes += len(staff)
	}
	if st.Stages, err = s.remindExpectedStageDates(ctx, today, days, approvers); err != nil {
		return st, err
	}
	if st.PaperReminder, err = s.remindPaperApproval(ctx, approvers); err != nil {
		return st, err
	}
	s.log.Info("big test reminders", "tests", st.Tests, "stages", st.Stages, "paper", st.PaperReminder)
	return st, nil
}

// remindExpectedStageDates: chặng đang mở có ngày dự kiến Big Test trong N ngày tới mà chưa có đợt thi → nhắc GV + Học thuật;
// mỗi chặng 1 lần cho mỗi ngày dự kiến.
func (s *Service) remindExpectedStageDates(ctx context.Context, today time.Time, days int, approvers []uuid.UUID) (int, error) {
	as, err := s.q.OpenAssignments(ctx, syllabusdb.OpenAssignmentsParams{AllClasses: true, ClassIds: []uuid.UUID{}})
	if err != nil {
		return 0, err
	}
	until := today.AddDate(0, 0, days)
	var managers []uuid.UUID
	n := 0
	for _, a := range as {
		exp := a.ExpectedBigTestDate
		if a.ClassID == nil || exp == nil || exp.Before(today) || exp.After(until) {
			continue
		}
		if a.BigTestRemindedFor != nil && a.BigTestRemindedFor.Equal(*exp) {
			continue
		}
		has, err := s.q.StageHasBigTest(ctx, syllabusdb.StageHasBigTestParams{ClassID: a.ClassID, StageID: a.StageID,
			Since: deref(firstTime(a.OpenedAt, &a.CreatedAt))})
		if err != nil {
			return n, err
		}
		if has {
			continue
		}
		if managers == nil {
			ids, err := iam.UserIDsWithPermission(ctx, s.pool, "syllabus.manage", nil)
			if err != nil {
				return n, err
			}
			managers = dedupe(append(ids, approvers...))
		}
		label := stageOr(a.StagePosition, a.StageTitle, deref(a.StageName))
		msg := fmt.Sprintf("Lớp %s dự kiến thi Big Test %s ngày %s (còn %d ngày) nhưng chưa có đợt thi — GV chính order đề, Admin duyệt, Học thuật tạo đợt thi.",
			a.ClassName, label, fmtDate(exp), daysBetween(today, *exp))
		data := map[string]any{"syllabus_assignment_id": a.ID.String()}
		err = s.inTx(ctx, func(tx pgx.Tx, q *syllabusdb.Queries) error {
			if err := send(ctx, tx, uniqueIDs(a.TeacherID, a.ForeignTeacherID, a.AssistantID), notify.Notification{
				Type: "big_test_upcoming", Title: "Nhắc lịch Big Test dự kiến: " + label, Body: msg, Link: "/syllabus/teaching-stages", Data: data,
			}); err != nil {
				return err
			}
			if err := send(ctx, tx, managers, notify.Notification{
				Type: "big_test_upcoming", Title: "Chặng sắp thi chưa có đợt Big Test: " + a.ClassName, Body: msg, Link: schedulesLink, Data: data,
			}); err != nil {
				return err
			}
			return q.MarkStageReminded(ctx, syllabusdb.MarkStageRemindedParams{Day: exp, ID: a.ID})
		})
		if err != nil {
			return n, err
		}
		n++
	}
	return n, nil
}

// remindPaperApproval: nhắc người duyệt MỖI NGÀY (1 thông báo / người / đợt thi / ngày) duyệt đề các đợt thi trong N ngày tới.
func (s *Service) remindPaperApproval(ctx context.Context, approvers []uuid.UUID) (int, error) {
	if !s.rule(ctx, rulePaperReminder).Enabled || len(approvers) == 0 {
		return 0, nil
	}
	now, today := s.now(), s.today()
	end := endOfDayIn(today, s.warnDays(ctx))
	before := s.approveBeforeDays(ctx)
	tests, err := s.q.UndistributedUpcoming(ctx, syllabusdb.UndistributedUpcomingParams{FromAt: &now, ToAt: &end})
	if err != nil {
		return 0, err
	}
	sent := 0
	for _, t := range tests {
		left := s.daysUntil(*t.ScheduledAt)
		overdue := left < before
		when := fmtDateTime(t.ScheduledAt)
		title := fmt.Sprintf("Cần duyệt đề Big Test: %s (còn %d ngày)", t.Code, left)
		body := fmt.Sprintf("Lớp %s thi \"%s\" lúc %s — hãy duyệt & phân phối đề trước ngày thi %d ngày.", t.ClassName, t.Title, when, before)
		if overdue {
			title = fmt.Sprintf("Quá hạn duyệt đề (trước %d ngày): %s", before, t.Code)
			body = fmt.Sprintf("Lớp %s thi \"%s\" lúc %s — đề phải được duyệt & phân phối trước ngày thi %d ngày, hiện chưa duyệt.",
				t.ClassName, t.Title, when, before)
		}
		n, err := notify.SendOnce(ctx, s.pool, approvers, "paper_due:"+t.ID.String()+":"+today.Format(time.DateOnly), notify.Notification{
			Type: "big_test_paper_due", Title: title, Body: body, Link: distributionLink,
			Data: map[string]any{"big_test_id": t.ID.String(), "days_left": left},
		})
		if err != nil {
			return sent, err
		}
		sent += n
	}
	return sent, nil
}

// SLAStats: kết quả một lượt SLA Big Test.
type SLAStats struct {
	PaperMissing int `json:"paper_missing"`
	LateResults  int `json:"late_results"`
	TasksCreated int `json:"tasks_created"`
	TasksClosed  int `json:"tasks_closed"`
}

// EnforceSLA: SLA Big Test (BigTestSlaService) — GV chưa nhận đề sát giờ thi, trả kết quả trễ, việc duyệt đề.
// paperOnly: chỉ quét "GV chưa nhận đề" (lượt mỗi giờ).
func (s *Service) EnforceSLA(ctx context.Context, paperOnly bool) (SLAStats, error) {
	var st SLAStats
	approvers, err := approverIDs(ctx, s.pool)
	if err != nil {
		return st, err
	}
	if st.PaperMissing, err = s.enforcePaperMissing(ctx, approvers); err != nil || paperOnly {
		return st, err
	}
	if st.LateResults, err = s.enforceLateResults(ctx); err != nil {
		return st, err
	}
	if st.TasksCreated, err = s.ensureApprovalTasks(ctx, approvers); err != nil {
		return st, err
	}
	if s.tasks != nil {
		err = pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
			var err error
			st.TasksClosed, err = s.tasks.CloseBigTestTasks(ctx, tx, nil)
			return err
		})
	}
	return st, err
}

// slaBreachOnce: báo người chịu trách nhiệm 1 lần / người / SLA / đợt thi (loại sla_breach).
func (s *Service) slaBreachOnce(ctx context.Context, db notify.DB, ids []uuid.UUID, rule string, testID uuid.UUID, code, title, body, link string) (int, error) {
	return notify.SendOnce(ctx, db, ids, "sla:"+rule+":"+testID.String(), notify.Notification{
		Type: "sla_breach", Title: "Quá hạn SLA: " + title + " (" + code + ")", Body: body, Link: link,
		Data: map[string]any{"sla_rule": rule, "big_test_id": testID.String()},
	})
}

// enforcePaperMissing: còn dưới N giờ tới giờ thi mà đề chưa phân phối → nhắc người duyệt (Admin không bị lập biên bản).
func (s *Service) enforcePaperMissing(ctx context.Context, approvers []uuid.UUID) (int, error) {
	r := s.rule(ctx, rulePaperMissing)
	if !r.Enabled || len(approvers) == 0 {
		return 0, nil
	}
	hours := time.Duration(r.Value) * time.Hour
	now := s.now()
	from, to := now.Add(-hours), now.Add(hours)
	tests, err := s.q.UndistributedUpcoming(ctx, syllabusdb.UndistributedUpcomingParams{FromAt: &from, ToAt: &to})
	if err != nil {
		return 0, err
	}
	n := 0
	for _, t := range tests {
		if !t.ScheduledAt.Before(to) {
			continue
		}
		sent, err := s.slaBreachOnce(ctx, s.pool, approvers, rulePaperMissing, t.ID, t.Code, "GV chưa nhận đề Big Test",
			t.Title+" thi lúc "+fmtDateTime(t.ScheduledAt)+" nhưng đề chưa được phân phối — hãy duyệt ngay.", distributionLink)
		if err != nil {
			return n, err
		}
		n += sent
	}
	return n, nil
}

// resultsEntered: đã nhập đủ kết quả cả lớp (không còn nháp, mọi học viên của lớp có kết quả) — chỉ còn chờ duyệt / gửi.
func resultsEntered(ctx context.Context, q *syllabusdb.Queries, testID uuid.UUID, classID *uuid.UUID) (bool, error) {
	rs, err := q.ResultStatuses(ctx, testID)
	if err != nil || len(rs) == 0 {
		return false, err
	}
	have := map[uuid.UUID]bool{}
	for _, r := range rs {
		if r.Status == "draft" {
			return false, nil
		}
		have[r.StudentID] = true
	}
	roster, err := q.RosterIDs(ctx, classID)
	if err != nil {
		return false, err
	}
	for _, id := range roster {
		if !have[id] {
			return false, nil
		}
	}
	return true, nil
}

// enforceLateResults: đợt thi trả kết quả trễ hạn → biên bản (qua Penalties) mức gợi ý × số ngày trễ; chưa gắn phân hệ biên
// bản hoặc tắt "Tự lập biên bản" → chỉ báo GV chính 1 lần.
func (s *Service) enforceLateResults(ctx context.Context) (int, error) {
	r := s.rule(ctx, ruleResultsLate)
	if !r.Enabled {
		return 0, nil
	}
	deadline := int(r.Value)
	today := s.today()
	since, before := s.now().AddDate(0, 0, -lateScanDays), startOfDayVN(today.AddDate(0, 0, -deadline))
	tests, err := s.q.LateResultTests(ctx, syllabusdb.LateResultTestsParams{Since: &since, Before: &before})
	if err != nil {
		return 0, err
	}
	created := 0
	for _, t := range tests {
		due := dateOf(*t.ScheduledAt).AddDate(0, 0, deadline)
		days := daysBetween(due, today)
		if days <= 0 {
			continue
		}
		entered, err := resultsEntered(ctx, s.q, t.ID, t.ClassID)
		if err != nil {
			return created, err
		}
		// Còn thiếu / nháp → GV chính chịu; đã nhập đủ, chờ Admin duyệt & gửi → không ai.
		var responsible uuid.UUID
		if !entered && t.TeacherID != nil {
			responsible = *t.TeacherID
		}
		if s.penalties != nil && r.Penalty {
			in := LatePenalty{
				BigTestID: t.ID, ClassID: t.ClassID, UserID: responsible, Date: due, Amount: r.Amount * int64(days), EnteredOnly: entered,
				Violation: fmt.Sprintf("Trả kết quả Big Test trễ %d ngày (hạn %d ngày)", days, deadline),
				Notes: fmt.Sprintf("Big Test %s (%s) thi ngày %s, hạn trả kết quả %s. Mức phạt gợi ý %sđ/ngày trễ, cập nhật mỗi ngày tới khi trả đủ kết quả.",
					t.Code, t.Title, fmtDay(dateOf(*t.ScheduledAt)), fmtDay(due), formatMoney(r.Amount)),
			}
			err := pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
				ok, err := s.penalties.SyncLateResults(ctx, tx, in)
				if ok {
					created++
				}
				return err
			})
			if err != nil {
				return created, err
			}
			continue
		}
		if responsible == uuid.Nil {
			continue
		}
		if _, err := s.slaBreachOnce(ctx, s.pool, []uuid.UUID{responsible}, ruleResultsLate, t.ID, t.Code, "Quá hạn trả kết quả Big Test",
			fmt.Sprintf("%s — trễ %d ngày so với hạn %s. Hãy hoàn tất và gửi kết quả cho phụ huynh.", t.Title, days, fmtDay(due)),
			resultsLink(t.ID)); err != nil {
			return created, err
		}
	}
	return created, nil
}

// formatMoney: 50000 → "50.000".
func formatMoney(v int64) string {
	s := fmt.Sprintf("%d", v)
	out := []byte{}
	for i := range len(s) {
		if i > 0 && (len(s)-i)%3 == 0 {
			out = append(out, '.')
		}
		out = append(out, s[i])
	}
	return string(out)
}

// ensureApprovalTasks: đợt thi trong N ngày tới chưa phân phối đề → việc "Duyệt & phân phối đề" cho người duyệt đầu tiên
// (hạn = ngày thi, 1 việc / đợt thi). Chưa gắn phân hệ Công việc thì bỏ qua.
func (s *Service) ensureApprovalTasks(ctx context.Context, approvers []uuid.UUID) (int, error) {
	if s.tasks == nil || !s.rule(ctx, rulePaperReminder).Enabled || len(approvers) == 0 {
		return 0, nil
	}
	now := s.now()
	end := endOfDayIn(s.today(), s.warnDays(ctx))
	before := s.approveBeforeDays(ctx)
	tests, err := s.q.UndistributedUpcoming(ctx, syllabusdb.UndistributedUpcomingParams{FromAt: &now, ToAt: &end})
	if err != nil {
		return 0, err
	}
	assignee := approvers[0]
	created := 0
	for _, t := range tests {
		branch := t.BranchID
		in := BigTestTask{
			BigTestID: t.ID, ClassID: t.ClassID, BranchID: &branch, DueDate: dateOf(*t.ScheduledAt), AssigneeID: assignee, CreatorID: assignee,
			Title: "Duyệt & phân phối đề Big Test " + t.Code + ": " + t.ClassName,
			Description: fmt.Sprintf("Big Test \"%s\" thi lúc %s. Duyệt & phân phối đề trước ngày thi %d ngày; việc tự đóng khi đề được phân phối.",
				t.Title, fmtDateTime(t.ScheduledAt), before),
		}
		err := pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
			ok, err := s.tasks.EnsureBigTestTask(ctx, tx, in)
			if err != nil || !ok {
				return err
			}
			created++
			return send(ctx, tx, []uuid.UUID{assignee}, notify.Notification{
				Type: "task_assigned", Title: "Việc mới: " + in.Title, Body: "Hạn " + fmtDay(in.DueDate) + " (ngày thi).",
				Link: "/tasks?tab=mine", Data: map[string]any{"big_test_id": t.ID.String()},
			})
		})
		if err != nil {
			return created, err
		}
	}
	return created, nil
}
