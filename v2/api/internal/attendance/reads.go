package attendance

import (
	"context"
	"math"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/attendance/attendancedb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
)

// API đọc / ghi cho phân hệ khác: Lương (ngày công, đi muộn, giờ dạy theo tháng), cổng giáo viên (check-in ca dạy).

// Summaries: tổng hợp chấm công hằng ngày (ngày công, đi muộn, về sớm, thiếu giờ ra, ngày nghỉ có phép) của từng người
// trong [from, to] (ngày giờ VN, gồm 2 đầu). Người không có dữ liệu vẫn có mặt trong kết quả (số 0).
func (s *Service) Summaries(ctx context.Context, userIDs []uuid.UUID, from, to time.Time) (map[uuid.UUID]Summary, error) {
	return s.summaries(ctx, userIDs, dayOf(from), dayOf(to))
}

// Timesheet: một ca dạy cho phân hệ Lương.
type Timesheet struct {
	ID           uuid.UUID
	UserID       uuid.UUID
	ClassID      *uuid.UUID
	ClassCode    string
	ClassName    string
	SessionID    *uuid.UUID
	Date         time.Time // 0 giờ VN
	Scheduled    *string   // "HH:MM-HH:MM"
	CheckIn      *string
	CheckOut     *string
	Hours        float64
	LateMinutes  int
	EarlyMinutes int
	LateNotified bool
	HourlyRate   *int64 // VND / giờ riêng của ca; nil = đơn giá của giáo viên
	Type         string
	Source       string
	Status       string
}

// TimesheetQuery: UserIDs rỗng + All = mọi người; OnlyValid = chỉ ca hợp lệ (tính lương), không thì mọi ca chưa bị từ chối
// (WithInvalid = cả ca bị từ chối).
type TimesheetQuery struct {
	UserIDs     []uuid.UUID
	All         bool
	From, To    time.Time
	OnlyValid   bool
	WithInvalid bool
}

// Timesheets: ca dạy trong khoảng, theo ngày.
func (s *Service) Timesheets(ctx context.Context, in TimesheetQuery) ([]Timesheet, error) {
	ids := in.UserIDs
	if ids == nil {
		ids = []uuid.UUID{}
	}
	rows, err := s.q.RangeTimesheets(ctx, attendancedb.RangeTimesheetsParams{AllUsers: in.All, UserIds: ids,
		FromDate: sqlDate(in.From), ToDate: sqlDate(in.To), OnlyValid: in.OnlyValid, WithInvalid: in.WithInvalid})
	if err != nil {
		return nil, err
	}
	out := make([]Timesheet, 0, len(rows))
	for _, r := range rows {
		out = append(out, Timesheet{ID: r.ID, UserID: r.UserID, ClassID: r.ClassID, ClassCode: r.ClassCode, ClassName: r.ClassName,
			SessionID: r.ClassSessionID, Date: dateOnly(r.TeachingDate), Scheduled: r.ScheduledTime, CheckIn: hm(r.CheckinTime),
			CheckOut: hm(r.CheckoutTime), Hours: r.Hours, LateMinutes: int(r.LateMinutes), EarlyMinutes: int(r.EarlyLeaveMinutes),
			LateNotified: r.LateNotified, HourlyRate: r.HourlyRate, Type: r.Type, Source: r.Source, Status: r.Status})
	}
	return out, nil
}

// TeachingHours: tổng giờ dạy hợp lệ của từng người trong khoảng.
func (s *Service) TeachingHours(ctx context.Context, userIDs []uuid.UUID, from, to time.Time) (map[uuid.UUID]float64, error) {
	rows, err := s.Timesheets(ctx, TimesheetQuery{UserIDs: userIDs, From: from, To: to, OnlyValid: true})
	if err != nil {
		return nil, err
	}
	out := make(map[uuid.UUID]float64, len(userIDs))
	for _, id := range userIDs {
		out[id] = 0
	}
	for _, r := range rows {
		out[r.UserID] = math.Round((out[r.UserID]+r.Hours)*100) / 100
	}
	return out, nil
}

// ChangedSince: có ca dạy / ngày công / đơn nghỉ thuộc khoảng được thêm hoặc sửa sau since (kỳ lương cần tính lại).
func (s *Service) ChangedSince(ctx context.Context, from, to, since time.Time) (bool, error) {
	return s.q.ChangedSince(ctx, attendancedb.ChangedSinceParams{FromDate: sqlDate(from), ToDate: sqlDate(to), Since: since})
}

// ───────────── Check-in ca dạy (cổng giáo viên) ─────────────

// SessionCheckin: ca dạy của một buổi (giờ check-in HH:MM, rỗng khi chấm tay không có giờ).
type SessionCheckin struct {
	Time   string
	Status string
}

// SessionCheckins: ca dạy (mọi trạng thái) của người dùng theo buổi; buổi chưa có ca không có trong map.
func (s *Service) SessionCheckins(ctx context.Context, userID uuid.UUID, sessionIDs []uuid.UUID) (map[uuid.UUID]SessionCheckin, error) {
	out := map[uuid.UUID]SessionCheckin{}
	if len(sessionIDs) == 0 {
		return out, nil
	}
	rows, err := s.q.SessionCheckins(ctx, attendancedb.SessionCheckinsParams{UserID: userID, SessionIds: sessionIDs})
	if err != nil {
		return nil, err
	}
	for _, r := range rows {
		out[r.SessionID] = SessionCheckin{Time: deref(hm(r.CheckinTime)), Status: r.Status}
	}
	return out, nil
}

// CheckinInput: giáo viên / trợ giảng check-in một buổi học (cổng giáo viên đã kiểm buổi được phân công, hạn check-in).
type CheckinInput struct {
	UserID    uuid.UUID
	SessionID uuid.UUID
	At        time.Time
}

// CheckinOutcome: Recorded = đã ghi ca chờ duyệt; không thì Skip là lý do bỏ qua (ghép "Lớp: lý do"), Locked = ngày thuộc
// kỳ lương đã khóa (Skip là PayrollPeriod::lockedMessage).
type CheckinOutcome struct {
	Recorded    bool
	TimesheetID uuid.UUID
	Skip        string
	Locked      bool
}

// RecordCheckin: ghi ca check-in (TeacherPortalController::checkin). Buổi đã được Học vụ chấm tay hoặc đã check-in (chờ
// duyệt / đã duyệt) thì bỏ qua; ca bị từ chối được check-in lại. Đi muộn = số phút check-in sau giờ bắt đầu.
func (s *Service) RecordCheckin(ctx context.Context, in CheckinInput) (CheckinOutcome, error) {
	sess, err := s.q.GetSession(ctx, in.SessionID)
	if isNoRows(err) {
		return CheckinOutcome{}, ErrSessionMissing
	}
	if err != nil {
		return CheckinOutcome{}, err
	}
	day := dateOnly(sess.Date)
	l, err := s.locks(ctx)
	if err != nil {
		return CheckinOutcome{}, err
	}
	if l.has(day) {
		return CheckinOutcome{Skip: lockedMessage(day), Locked: true}, nil
	}
	var out CheckinOutcome
	err = s.inTx(ctx, func(tx pgx.Tx, q *attendancedb.Queries) error {
		dup, err := q.FindDuplicate(ctx, attendancedb.FindDuplicateParams{UserID: in.UserID, SessionID: &in.SessionID,
			ClassID: &sess.ClassID, Day: sqlDate(day)})
		if err == nil {
			if dup.Source == SourceManual {
				out.Skip = "buổi này đã được Học vụ chấm công tay"
			} else {
				at := hmText(dup.CheckinTime)
				if at == "" {
					at = "—"
				}
				out.Skip = "bạn đã check-in buổi này lúc " + at
			}
			return nil
		}
		if !isNoRows(err) {
			return err
		}
		start, end := hmText(sess.StartTime), hmText(sess.EndTime)
		hours := math.Max(0.5, hoursBetween(start, end))
		late := int(math.Floor(in.At.Sub(at(day, start)).Minutes()))
		late = min(1440, max(0, late))
		typ := "regular"
		if sess.Type == "support" {
			typ = "1on1"
		}
		checkin := clock(in.At)
		scheduled := start + "-" + end
		old, err := q.TimesheetBySession(ctx, attendancedb.TimesheetBySessionParams{UserID: in.UserID, SessionID: &in.SessionID})
		var ts attendancedb.HrTeacherTimesheet
		switch {
		case err == nil:
			ts, err = q.OverwriteTimesheet(ctx, attendancedb.OverwriteTimesheetParams{ID: old.ID, ClassID: &sess.ClassID,
				ClassSessionID: &in.SessionID, TeachingDate: sqlDate(day), ScheduledTime: &scheduled, CheckinTime: &checkin,
				Hours: hours, LateMinutes: int32(late), Type: typ, Source: SourceCheckin, Status: TsPending, Notes: old.Notes})
		case isNoRows(err):
			ts, err = q.InsertTimesheet(ctx, attendancedb.InsertTimesheetParams{UserID: in.UserID, ClassID: &sess.ClassID,
				ClassSessionID: &in.SessionID, TeachingDate: sqlDate(day), ScheduledTime: &scheduled, CheckinTime: &checkin,
				Hours: hours, LateMinutes: int32(late), Type: typ, Source: SourceCheckin, Status: TsPending, CreatedBy: &in.UserID})
		}
		if err != nil {
			return err
		}
		out = CheckinOutcome{Recorded: true, TimesheetID: ts.ID}
		return s.record(ctx, tx, audit.Entry{ActorID: &in.UserID, Event: audit.EventCreated, Action: "teacher_timesheet.checkin",
			SubjectType: "teacher_timesheet", SubjectID: ts.ID.String(), SubjectLabel: sess.ClassCode + " · " + fmtDate(day),
			BranchID: &sess.BranchID, Description: "Check-in ca dạy " + sess.ClassCode + " ngày " + fmtDate(day) + " lúc " + checkin,
			Properties: map[string]any{"late_minutes": late}})
	})
	return out, err
}
