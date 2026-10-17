package attendance

import (
	"context"
	"fmt"
	"strings"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/attendance/attendancedb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/kpi/kpisrc"
)

// Nguồn KPI tự động từ chấm công (KpiAutoCounter::staffLate / teacherLate / teacherAbsences).

// StaffLateMinutes: đi muộn quá chừng này phút mới tính KPI (KpiAutoCounter::STAFF_LATE_MINUTES).
const StaffLateMinutes = 10

// KpiSources: staff_late, teacher_late, teacher_absent_unexcused, teacher_leave.
func (s *Service) KpiSources() kpisrc.Sources {
	return kpisrc.Sources{
		"staff_late":   s.kpiStaffLate,
		"teacher_late": s.kpiTeacherLate,
		"teacher_absent_unexcused": func(ctx context.Context, u uuid.UUID, f, t time.Time) (kpisrc.Result, error) {
			return s.kpiAbsences(ctx, u, f, t, false)
		},
		"teacher_leave": func(ctx context.Context, u uuid.UUID, f, t time.Time) (kpisrc.Result, error) {
			return s.kpiAbsences(ctx, u, f, t, true)
		},
	}
}

// kpiStaffLate: ngày chấm công đi muộn quá 10 phút, không có đơn được duyệt.
func (s *Service) kpiStaffLate(ctx context.Context, userID uuid.UUID, from, to time.Time) (kpisrc.Result, error) {
	rows, err := s.q.UserAttendances(ctx, attendancedb.UserAttendancesParams{UserID: userID, FromDate: sqlDate(from), ToDate: sqlDate(to)})
	if err != nil {
		return kpisrc.Result{}, err
	}
	out := kpisrc.Result{Records: []kpisrc.Record{}}
	for i := len(rows) - 1; i >= 0; i-- {
		r := rows[i]
		if r.LateMinutes <= StaffLateMinutes || r.LateExcused {
			continue
		}
		text := fmt.Sprintf("Đi muộn %d phút", r.LateMinutes)
		if r.CheckInAt != nil {
			text += " (check-in " + clock(*r.CheckInAt) + ")"
		}
		out.Records = append(out.Records, kpisrc.Record{At: dateOnly(r.WorkDate), Text: text})
	}
	return out, nil
}

func classLabel(code, name string, id *uuid.UUID) string {
	switch {
	case code != "":
		return code
	case name != "":
		return name
	case id != nil:
		return "#" + id.String()
	}
	return "#"
}

// kpiTeacherLate: ca dạy (chưa bị từ chối) check-in muộn.
func (s *Service) kpiTeacherLate(ctx context.Context, userID uuid.UUID, from, to time.Time) (kpisrc.Result, error) {
	rows, err := s.Timesheets(ctx, TimesheetQuery{UserIDs: []uuid.UUID{userID}, From: from, To: to})
	if err != nil {
		return kpisrc.Result{}, err
	}
	out := kpisrc.Result{Records: []kpisrc.Record{}}
	for _, t := range rows {
		if t.LateMinutes <= 0 {
			continue
		}
		text := fmt.Sprintf("Lớp %s: muộn %d phút", classLabel(t.ClassCode, t.ClassName, t.ClassID), t.LateMinutes)
		if t.LateNotified {
			text += " (có báo trước)"
		}
		out.Records = append(out.Records, kpisrc.Record{At: t.Date, Text: text})
	}
	return out, nil
}

// kpiAbsences: buổi dạy đã qua (tới hôm qua) không có giờ dạy chưa bị từ chối. Có đơn nghỉ được duyệt trùng ngày → nghỉ
// có phép (đếm theo ngày); không có → nghỉ không phép (đếm theo buổi).
func (s *Service) kpiAbsences(ctx context.Context, userID uuid.UUID, from, to time.Time, excused bool) (kpisrc.Result, error) {
	out := kpisrc.Result{Records: []kpisrc.Record{}}
	until := dayOf(to)
	if y := dayOf(s.now()).AddDate(0, 0, -1); y.Before(until) {
		until = y
	}
	start := dayOf(from)
	if until.Before(start) {
		return out, nil
	}
	sessions, err := s.q.StaffSessionsRange(ctx, attendancedb.StaffSessionsRangeParams{UserID: &userID, FromDate: sqlDate(start), ToDate: sqlDate(until)})
	if err != nil || len(sessions) == 0 {
		return out, err
	}
	sheets, err := s.Timesheets(ctx, TimesheetQuery{UserIDs: []uuid.UUID{userID}, From: start, To: until})
	if err != nil {
		return out, err
	}
	taughtSession, taughtDay := map[uuid.UUID]bool{}, map[string]bool{}
	for _, t := range sheets {
		if t.SessionID != nil {
			taughtSession[*t.SessionID] = true
		} else if t.ClassID != nil {
			taughtDay[t.ClassID.String()+"|"+isoDate(t.Date)] = true
		}
	}
	leaves, err := s.q.ApprovedLeaves(ctx, attendancedb.ApprovedLeavesParams{UserIds: []uuid.UUID{userID}, FromDate: sqlDate(start), ToDate: sqlDate(until)})
	if err != nil {
		return out, err
	}
	onLeave := func(day string) bool {
		for _, l := range leaves {
			if isoDate(l.DateFrom) <= day && isoDate(l.DateTo) >= day {
				return true
			}
		}
		return false
	}
	var days []string
	byDay := map[string][]string{}
	for _, ss := range sessions {
		day := isoDate(ss.Date)
		if taughtSession[ss.ID] || taughtDay[ss.ClassID.String()+"|"+day] {
			continue
		}
		label := classLabel(ss.ClassCode, ss.ClassName, &ss.ClassID)
		if excused {
			if !onLeave(day) {
				continue
			}
			if _, ok := byDay[day]; !ok {
				days = append(days, day)
			}
			if !contains(byDay[day], label) {
				byDay[day] = append(byDay[day], label)
			}
			continue
		}
		if onLeave(day) {
			continue
		}
		text := "Buổi " + label
		if v := hm(ss.StartTime); v != nil {
			text += " " + *v
		}
		out.Records = append(out.Records, kpisrc.Record{At: dateOnly(ss.Date), Text: text + ": không có giờ dạy, không có đơn nghỉ được duyệt"})
	}
	for _, d := range days {
		t, _ := parseDate(d)
		out.Records = append(out.Records, kpisrc.Record{At: t, Text: "Nghỉ có phép: " + strings.Join(byDay[d], ", ")})
	}
	return out, nil
}

func contains(list []string, v string) bool {
	for _, x := range list {
		if x == v {
			return true
		}
	}
	return false
}
