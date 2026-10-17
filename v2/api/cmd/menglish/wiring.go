package main

import (
	"context"
	"math"
	"slices"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/attendance"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/catalogs"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/payroll"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/portal"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/projects"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/surveys"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/syllabus"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/tasks"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/teacher"
)

// Nối các phân hệ không import nhau (mỗi adapter cài interface của phân hệ dùng bằng hàm của phân hệ cung cấp):
//   - Chấm công ↔ Lương & Phạt: kỳ lương đã duyệt / chi trả khóa chấm công, biên bản đi muộn tự động; bảng lương đọc
//     ca dạy hợp lệ + ngày công.
//   - Cổng giáo viên: check-in ca dạy / bảng công tháng (Chấm công), đơn giá + biên bản (Lương), Unit / bài học (Giáo trình).
//   - Cổng PH/HS: khảo sát 5 sao (Khảo sát), kết quả Big Test + chặng đang học (Giáo trình).
//   - Dự án học thuật / Giáo trình: biên bản trễ mốc, trả kết quả Big Test trễ (Lương & Phạt); việc duyệt đề (Công việc).

// ───── Chấm công ← Lương & Phạt ─────

type attendancePayroll struct{ p *payroll.Service }

var (
	_ attendance.PayrollLock = attendancePayroll{}
	_ attendance.Penalties   = attendancePayroll{}
)

func (x attendancePayroll) LockedRanges(ctx context.Context) ([]attendance.LockedRange, error) {
	rows, err := x.p.LockedPeriods(ctx)
	if err != nil {
		return nil, err
	}
	out := make([]attendance.LockedRange, 0, len(rows))
	for _, r := range rows {
		out = append(out, attendance.LockedRange{From: r.From, To: r.To, Title: r.Title})
	}
	return out, nil
}

func (x attendancePayroll) Period(ctx context.Context, month time.Time) (*attendance.PayrollPeriod, error) {
	p, err := x.p.MonthPeriod(ctx, month)
	if err != nil || p == nil {
		return nil, err
	}
	return &attendance.PayrollPeriod{StatusLabel: p.StatusLabel, Locked: p.Locked}, nil
}

func (x attendancePayroll) Late(ctx context.Context, tx pgx.Tx, current *uuid.UUID, in attendance.LatePenalty) (*attendance.PenaltyRef, error) {
	ref, created, err := x.p.SyncLatePenalty(ctx, tx, current, payroll.LatePenalty{UserID: in.UserID, WorkDate: in.WorkDate,
		Violation: in.Violation, Notes: in.Notes})
	if err != nil || ref == nil {
		return nil, err
	}
	return &attendance.PenaltyRef{ID: ref.ID, Code: ref.Code, Status: ref.Status, Created: created}, nil
}

func (x attendancePayroll) Excused(ctx context.Context, tx pgx.Tx, current uuid.UUID, note string) error {
	return x.p.AutoCancelPenalty(ctx, tx, current, note)
}

func (x attendancePayroll) Refs(ctx context.Context, ids []uuid.UUID) (map[uuid.UUID]attendance.PenaltyRef, error) {
	rows, err := x.p.PenaltyRefs(ctx, ids)
	if err != nil {
		return nil, err
	}
	out := make(map[uuid.UUID]attendance.PenaltyRef, len(rows))
	for id, r := range rows {
		out[id] = attendance.PenaltyRef{ID: r.ID, Code: r.Code, Status: r.Status}
	}
	return out, nil
}

func (x attendancePayroll) Violations(ctx context.Context, userIDs []uuid.UUID, from, to time.Time) (map[uuid.UUID]int, error) {
	return x.p.ViolationCounts(ctx, userIDs, from, to)
}

// ───── Lương ← Chấm công ─────

type payrollAttendance struct{ a *attendance.Service }

var _ payroll.Attendance = payrollAttendance{}

func (x payrollAttendance) ValidTimesheets(ctx context.Context, from, to time.Time) ([]payroll.Timesheet, error) {
	rows, err := x.a.Timesheets(ctx, attendance.TimesheetQuery{All: true, From: from, To: to, OnlyValid: true})
	if err != nil {
		return nil, err
	}
	out := make([]payroll.Timesheet, 0, len(rows))
	for _, r := range rows {
		sched := ""
		if r.Scheduled != nil {
			sched = *r.Scheduled
		}
		out = append(out, payroll.Timesheet{ID: r.ID, UserID: r.UserID, ClassID: r.ClassID, Date: r.Date, ScheduledTime: sched,
			Hours: r.Hours, LateMinutes: r.LateMinutes, EarlyLeaveMinutes: r.EarlyMinutes, LateNotified: r.LateNotified,
			HourlyRate: r.HourlyRate, Type: r.Type, Source: r.Source})
	}
	return out, nil
}

// Summary: StaffAttendanceService::summary của PayrollService (ngày công / đi muộn / về sớm / thiếu giờ ra / nghỉ phép);
// tháng không có ngày công lẫn ngày nghỉ thì không ghi vào chi tiết lương.
func (x payrollAttendance) Summary(ctx context.Context, userID uuid.UUID, from, to time.Time) (map[string]any, error) {
	all, err := x.a.Summaries(ctx, []uuid.UUID{userID}, from, to)
	if err != nil {
		return nil, err
	}
	s := all[userID]
	if s.Days == 0 && s.LeaveDays == 0 {
		return nil, nil
	}
	return map[string]any{"days": s.Days, "late_count": s.LateCount, "late_minutes": s.LateMinutes, "excused_late": s.ExcusedLate,
		"early_count": s.EarlyCount, "missing_out": s.MissingOut, "leave_days": s.LeaveDays}, nil
}

func (x payrollAttendance) ChangedSince(ctx context.Context, from, to, since time.Time) (bool, error) {
	return x.a.ChangedSince(ctx, from, to, since)
}

func (payrollAttendance) Pending() bool { return false }

// ───── Cổng giáo viên ← Chấm công + Lương ─────

type teacherWork struct {
	att *attendance.Service
	pay *payroll.Service
}

var _ teacher.Work = teacherWork{}

func (teacherWork) Available() bool { return true }

func (x teacherWork) Checkins(ctx context.Context, userID uuid.UUID, sessionIDs []uuid.UUID) (map[uuid.UUID]teacher.Checkin, error) {
	rows, err := x.att.SessionCheckins(ctx, userID, sessionIDs)
	if err != nil {
		return nil, err
	}
	out := make(map[uuid.UUID]teacher.Checkin, len(rows))
	for id, r := range rows {
		out[id] = teacher.Checkin{Time: r.Time, Status: r.Status}
	}
	return out, nil
}

// CheckIn: ghi từng buổi qua Chấm công (bỏ qua buổi đã chấm tay / đã check-in, ngày thuộc kỳ lương đã khóa).
func (x teacherWork) CheckIn(ctx context.Context, userID uuid.UUID, now time.Time, sessions []teacher.CheckinSession) (teacher.CheckinResult, error) {
	var out teacher.CheckinResult
	for _, s := range sessions {
		res, err := x.att.RecordCheckin(ctx, attendance.CheckinInput{UserID: userID, SessionID: s.SessionID, At: now})
		if err != nil {
			return out, err
		}
		switch {
		case res.Recorded:
			out.Count++
		case res.Locked:
			out.Locked = res.Skip
		case res.Skip != "":
			out.Skipped = append(out.Skipped, s.ClassName+": "+res.Skip)
		}
	}
	return out, nil
}

// Month: thẻ "Lương tạm tính / Chấm công / Vi phạm" (homeWidgets) và báo cáo tháng của GV — mọi ca trong tháng (kể cả ca
// bị từ chối, như where('status', '!=', 'rejected') của Laravel), lương tạm tính = Σ giờ × đơn giá giờ hiệu lực ngày dạy.
func (x teacherWork) Month(ctx context.Context, userID uuid.UUID, from, to time.Time) (*teacher.WorkMonth, error) {
	rows, err := x.att.Timesheets(ctx, attendance.TimesheetQuery{UserIDs: []uuid.UUID{userID}, From: from, To: to, WithInvalid: true})
	if err != nil {
		return nil, err
	}
	out := &teacher.WorkMonth{Rows: []teacher.TimesheetRow{}}
	var estimate *float64
	for _, r := range rows {
		out.Hours += r.Hours
		out.Sessions++
		switch r.Status {
		case attendance.TsValid:
			out.ValidHours += r.Hours
			out.ValidSessions++
		case attendance.TsPending:
			out.Pending++
		}
		rate, unit, err := x.pay.RateOn(ctx, userID, r.Date)
		if err != nil {
			return nil, err
		}
		if rate != nil && unit == "hour" {
			v := *rate * r.Hours
			if estimate != nil {
				v += *estimate
			}
			estimate = &v
		}
		out.Rows = append(out.Rows, teacher.TimesheetRow{ID: r.ID, Date: r.Date.Format("02/01/2006"), ClassName: r.ClassName,
			Type: r.Type, Hours: r.Hours, Status: r.Status})
	}
	slices.Reverse(out.Rows) // mới nhất trước
	out.Hours, out.ValidHours = math.Round(out.Hours*100)/100, math.Round(out.ValidHours*100)/100
	if estimate != nil {
		v := int64(math.Round(*estimate))
		out.Estimate = &v
	}
	n, latest, err := x.pay.Violations(ctx, userID, from, to)
	if err != nil {
		return nil, err
	}
	out.Violations = n
	if latest != nil {
		out.Latest = &teacher.ViolationBrief{Type: latest.Type, Date: latest.Date.Format("02/01"), StatusLabel: latest.StatusLabel}
	}
	return out, nil
}

// ───── Cổng giáo viên / Cổng PH-HS ← Giáo trình ─────

type teacherSyllabus struct{ s *syllabus.Service }

var _ teacher.Syllabus = teacherSyllabus{}

func (x teacherSyllabus) Units(ctx context.Context, classID uuid.UUID) ([]teacher.Unit, error) {
	rows, err := x.s.ClassUnits(ctx, classID)
	if err != nil {
		return nil, err
	}
	out := make([]teacher.Unit, 0, len(rows))
	for _, u := range rows {
		out = append(out, teacher.Unit{ID: u.ID, Number: u.Number, Title: u.Title})
	}
	return out, nil
}

func (x teacherSyllabus) Lessons(ctx context.Context, classID uuid.UUID, numbers []int) (map[int]teacher.Lesson, error) {
	rows, err := x.s.ClassLessons(ctx, classID, numbers)
	if err != nil {
		return nil, err
	}
	out := make(map[int]teacher.Lesson, len(rows))
	for n, l := range rows {
		out[n] = teacher.Lesson{Unit: l.Unit, Title: l.Title}
	}
	return out, nil
}

type portalSyllabus struct{ s *syllabus.Service }

var _ portal.Syllabus = portalSyllabus{}

func (x portalSyllabus) BigTestResults(ctx context.Context, studentID uuid.UUID, limit int) ([]portal.BigTestResult, error) {
	rows, err := x.s.StudentBigTestResults(ctx, studentID, limit)
	if err != nil {
		return nil, err
	}
	out := make([]portal.BigTestResult, 0, len(rows))
	for _, r := range rows {
		out = append(out, portal.BigTestResult(r))
	}
	return out, nil
}

func (x portalSyllabus) CurrentStage(ctx context.Context, classID uuid.UUID) (*string, error) {
	return x.s.CurrentStage(ctx, classID)
}

// ───── Cổng PH-HS ← Khảo sát ─────

type portalSurveys struct{ s *surveys.Service }

var _ portal.Surveys = portalSurveys{}

func (portalSurveys) Ready() bool { return true }

func (x portalSurveys) Open(ctx context.Context) ([]portal.OpenSurvey, error) {
	rows, err := x.s.OpenSurveys(ctx)
	if err != nil {
		return nil, err
	}
	out := make([]portal.OpenSurvey, 0, len(rows))
	for _, r := range rows {
		out = append(out, portal.OpenSurvey{ID: r.ID, Title: r.Title, Description: r.Description, Deadline: r.Deadline})
	}
	return out, nil
}

func (x portalSurveys) Responses(ctx context.Context, studentID uuid.UUID) ([]portal.SurveyResponse, error) {
	rows, err := x.s.StudentResponses(ctx, studentID)
	if err != nil {
		return nil, err
	}
	out := make([]portal.SurveyResponse, 0, len(rows))
	for _, r := range rows {
		out = append(out, portal.SurveyResponse{ID: r.ID, SurveyID: r.SurveyID, Title: r.Title, Rating: r.Rating,
			Feedback: r.Feedback, SubmittedAt: r.SubmittedAt})
	}
	return out, nil
}

func (x portalSurveys) Respond(ctx context.Context, tx pgx.Tx, in portal.SurveyInput) error {
	return x.s.Respond(ctx, tx, surveys.Answer{SurveyID: in.SurveyID, StudentID: in.StudentID, By: in.By, Rating: in.Rating,
		Feedback: in.Feedback})
}

func (x portalSurveys) ResponseOwner(ctx context.Context, id uuid.UUID) (uuid.UUID, error) {
	return x.s.ResponseStudent(ctx, id)
}

func (x portalSurveys) DeleteResponse(ctx context.Context, tx pgx.Tx, id uuid.UUID) error {
	return x.s.RemoveResponse(ctx, tx, id)
}

// ───── Dự án học thuật ← Lương & Phạt (SlaBreachService::breach) ─────

type slaRules interface {
	SlaRule(ctx context.Context, key string) (catalogs.SlaRule, error)
	LadderResetMonths(ctx context.Context) (int, error)
}

type projectPenalties struct {
	p   *payroll.Service
	sla slaRules
}

var _ projects.Penalties = projectPenalties{}

func (projectPenalties) Enabled() bool { return true }

func (x projectPenalties) Breach(ctx context.Context, b projects.Breach) (bool, error) {
	rule, err := x.sla.SlaRule(ctx, b.Rule)
	if err != nil {
		return false, err
	}
	months, err := x.sla.LadderResetMonths(ctx)
	if err != nil {
		return false, err
	}
	now := b.DetectedAt
	if now.IsZero() {
		now = time.Now()
	}
	_, err = x.p.BreachSla(ctx, payroll.SlaBreach{RuleKey: b.Rule, RuleLabel: rule.Label, Violation: rule.Violation,
		Category: rule.Category, Penalize: rule.Penalty, Amount: rule.Amount, Ladder: rule.Ladder, ResetMonths: months,
		Key: b.SubjectID.String(), OwnerID: b.UserID, Subject: b.Label, DueAt: b.DueAt, Now: now, Link: b.Link})
	return err == nil, err
}

func (x projectPenalties) ForMilestones(ctx context.Context, ids []uuid.UUID) ([]projects.Penalty, error) {
	keys := make([]string, 0, len(ids))
	for _, id := range ids {
		keys = append(keys, id.String())
	}
	rows, err := x.p.AutoPenalties(ctx, projects.SlaLate, keys)
	if err != nil {
		return nil, err
	}
	out := make([]projects.Penalty, 0, len(rows))
	for _, r := range rows {
		mid, err := uuid.Parse(r.Key)
		if err != nil {
			continue
		}
		uid, name, created := r.UserID, r.UserName, r.CreatedAt
		out = append(out, projects.Penalty{ID: r.ID.String(), Code: r.Code, UserID: &uid, User: &name, MilestoneID: mid,
			Status: r.Status, StatusLabel: r.StatusLabel, Amount: float64(r.Amount), CreatedAt: &created,
			URL: "/penalties?search=" + r.Code})
	}
	return out, nil
}

// ───── Giáo trình ← Lương & Phạt, Công việc ─────

type syllabusPenalties struct{ p *payroll.Service }

var _ syllabus.Penalties = syllabusPenalties{}

func (x syllabusPenalties) SyncLateResults(ctx context.Context, tx pgx.Tx, in syllabus.LatePenalty) (bool, error) {
	return x.p.SyncBigTestLate(ctx, tx, payroll.BigTestLate{BigTestID: in.BigTestID, ClassID: in.ClassID, UserID: in.UserID,
		Violation: in.Violation, DueDate: in.Date, Amount: in.Amount, Notes: in.Notes, EnteredOnly: in.EnteredOnly,
		Message: in.Message})
}

type syllabusTasks struct{ t *tasks.Service }

var _ syllabus.ApprovalTasks = syllabusTasks{}

// EnsureBigTestTask: người tạo việc là Admin đầu tiên, không có thì chính người nhận (như mẫu chăm sóc tháng đầu).
func (x syllabusTasks) EnsureBigTestTask(ctx context.Context, tx pgx.Tx, in syllabus.BigTestTask) (bool, error) {
	creator := in.CreatorID
	if admins, err := iam.AdminIDs(ctx, tx); err != nil {
		return false, err
	} else if len(admins) > 0 {
		creator = admins[0]
	}
	return x.t.EnsureBigTestTask(ctx, tx, tasks.BigTestTask{BigTestID: in.BigTestID, ClassID: in.ClassID, BranchID: in.BranchID,
		Title: in.Title, Description: in.Description, DueDate: in.DueDate, AssigneeID: in.AssigneeID, CreatorID: creator})
}

func (x syllabusTasks) CloseBigTestTasks(ctx context.Context, tx pgx.Tx, bigTestID *uuid.UUID) (int, error) {
	return x.t.CloseBigTestTasks(ctx, tx, bigTestID)
}
