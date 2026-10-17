package attendance

import (
	"context"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/attendance/attendancedb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/dashboard"
)

// DashboardWork: ngày công / đi muộn / ngày phép / lỗi vi phạm cho Tổng quan (TeachingQuality::workStats).
func (s *Service) DashboardWork() dashboard.StaffWork { return dashboardWork{s} }

type dashboardWork struct{ s *Service }

// Stats: ngày công = ngày có ca dạy chưa bị từ chối ∪ ngày có chấm vào; đi muộn = số ca dạy muộn + ngày chấm vào muộn
// tính lỗi không trùng ngày có ca dạy muộn; ngày phép = số ngày đơn nghỉ được duyệt trong khoảng; vi phạm = biên bản chưa hủy.
func (d dashboardWork) Stats(ctx context.Context, ids []uuid.UUID, from, to time.Time) (map[uuid.UUID]dashboard.WorkStats, bool, error) {
	out := make(map[uuid.UUID]dashboard.WorkStats, len(ids))
	if len(ids) == 0 {
		return out, true, nil
	}
	start, end := dayOf(from), dayOf(to)
	sheets, err := d.s.Timesheets(ctx, TimesheetQuery{UserIDs: ids, From: start, To: end})
	if err != nil {
		return nil, false, err
	}
	ins, err := d.s.q.RangeAttendances(ctx, attendancedb.RangeAttendancesParams{UserIds: ids, FromDate: sqlDate(start), ToDate: sqlDate(end)})
	if err != nil {
		return nil, false, err
	}
	leaves, err := d.s.q.ApprovedLeaves(ctx, attendancedb.ApprovedLeavesParams{UserIds: ids, FromDate: sqlDate(start), ToDate: sqlDate(end)})
	if err != nil {
		return nil, false, err
	}
	violations, err := d.s.penalties.Violations(ctx, ids, start, end)
	if err != nil {
		return nil, false, err
	}
	workDays := map[uuid.UUID]map[string]bool{}
	lateDays := map[uuid.UUID]map[string]bool{}
	late := map[uuid.UUID]int{}
	mark := func(m map[uuid.UUID]map[string]bool, id uuid.UUID, day string) {
		if m[id] == nil {
			m[id] = map[string]bool{}
		}
		m[id][day] = true
	}
	for _, t := range sheets {
		day := isoDate(t.Date)
		mark(workDays, t.UserID, day)
		if t.LateMinutes > 0 {
			mark(lateDays, t.UserID, day)
			late[t.UserID]++
		}
	}
	for _, a := range ins {
		if a.CheckInAt == nil {
			continue
		}
		day := isoDate(a.WorkDate)
		mark(workDays, a.UserID, day)
		if isLate(a) && !lateDays[a.UserID][day] {
			late[a.UserID]++
		}
	}
	for _, id := range ids {
		out[id] = dashboard.WorkStats{WorkDays: len(workDays[id]), Late: late[id], Violations: violations[id]}
	}
	for _, l := range leaves {
		w := out[l.UserID]
		w.LeaveDays += overlapDays(l.DateFrom, l.DateTo, start, end)
		out[l.UserID] = w
	}
	return out, true, nil
}
