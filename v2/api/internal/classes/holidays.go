package classes

import (
	"context"
	"fmt"
	"regexp"
	"slices"
	"sort"
	"strings"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/catalogs"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/classes/classesdb"
)

// Áp ngày nghỉ lễ được thêm / sửa / xóa SAU khi lịch học đã sinh (HolidayRescheduleService):
//   - Buổi sắp tới (từ hôm nay) rơi vào ngày nghỉ, của chi nhánh bị ảnh hưởng, chưa có dữ liệu thực tế (điểm danh)
//     → hủy, gắn ngày nghỉ.
//   - Mỗi buổi bị hủy được xếp một buổi học bù vào ca học kế tiếp của lớp SAU buổi cuối cùng hiện có, bỏ qua ngày nghỉ
//     và không trùng phòng / nhân sự.
//   - Buổi đã có dữ liệu thực tế không bị đụng tới, chỉ đếm là "cần xử lý tay".
//   - Ngày nghỉ bị thu hẹp / xóa: buổi đã hủy không còn nằm trong ngày nghỉ được khôi phục, buổi bù (chưa diễn ra) bị xóa.

// makeupSearchDays: số ngày tối đa tìm ca học bù sau buổi cuối của lớp.
const makeupSearchDays = 180

var holidayNoteRe = regexp.MustCompile(`\n?Hủy do nghỉ lễ: .*$`)

// HolidayApplied (catalogs.ClassHooks).
func (s *Service) HolidayApplied(ctx context.Context, h catalogs.HolidayScope) (catalogs.HolidaySummary, error) {
	var out catalogs.HolidaySummary
	err := s.tx(ctx, func(_ pgx.Tx, q *classesdb.Queries) error {
		var err error
		if out.Restored, err = s.restoreUncovered(ctx, q, h, false); err != nil {
			return err
		}
		today := s.today()
		from := h.StartDate
		if from.Before(today) {
			from = today
		}
		if from.After(h.EndDate) {
			return nil
		}
		affected, err := q.HolidayAffectedSessions(ctx, classesdb.HolidayAffectedSessionsParams{
			FromDate: from, ToDate: h.EndDate, SystemWide: h.SystemWide, BranchIds: nonNil(h.BranchIDs),
		})
		if err != nil {
			return err
		}
		for _, se := range affected {
			if se.HasData {
				// TODO(hr): buổi đã chấm công / gắn phụ đạo cũng giữ nguyên khi có các bảng đó.
				out.Flagged++
				continue
			}
			notes := strings.TrimSpace(deref(se.Notes) + "\n" + "Hủy do nghỉ lễ: " + h.Name)
			if se.Notes == nil || *se.Notes == "" {
				notes = "Hủy do nghỉ lễ: " + h.Name
			}
			if err := q.CancelSessionForHoliday(ctx, classesdb.CancelSessionForHolidayParams{ID: se.ID, HolidayID: &h.ID, Notes: &notes}); err != nil {
				return err
			}
			out.Cancelled++
			if err := q.CancelTrialBookingsForHoliday(ctx, classesdb.CancelTrialBookingsForHolidayParams{
				SessionID: se.ID, Note: "Hủy: buổi học thử trùng nghỉ lễ " + h.Name,
			}); err != nil {
				return err
			}
			ok, err := s.scheduleMakeup(ctx, q, se, h)
			if err != nil {
				return err
			}
			if ok {
				out.Rescheduled++
			} else {
				out.Unscheduled++
			}
		}
		return nil
	})
	if err == nil && (out.Cancelled > 0 || out.Restored > 0) {
		s.log.Info("holiday applied to class sessions", "holiday_id", h.ID, "cancelled", out.Cancelled,
			"rescheduled", out.Rescheduled, "restored", out.Restored)
	}
	return out, err
}

// HolidayReleased (catalogs.ClassHooks): ngày nghỉ bị xóa → khôi phục mọi buổi đã hủy vì nó.
func (s *Service) HolidayReleased(ctx context.Context, holidayID uuid.UUID) (int, error) {
	var n int
	err := s.tx(ctx, func(_ pgx.Tx, q *classesdb.Queries) error {
		var err error
		n, err = s.restoreUncovered(ctx, q, catalogs.HolidayScope{ID: holidayID}, true)
		return err
	})
	return n, err
}

func holidayCovers(h catalogs.HolidayScope, date time.Time, branch uuid.UUID) bool {
	if date.Before(h.StartDate) || date.After(h.EndDate) {
		return false
	}
	return h.SystemWide || slices.Contains(h.BranchIDs, branch)
}

func (s *Service) restoreUncovered(ctx context.Context, q *classesdb.Queries, h catalogs.HolidayScope, releaseAll bool) (int, error) {
	rows, err := q.HolidayCancelledSessions(ctx, &h.ID)
	if err != nil {
		return 0, err
	}
	restored := 0
	for _, se := range rows {
		if !releaseAll && holidayCovers(h, se.Date, se.BranchID) {
			continue
		}
		if se.MakeupID != nil {
			// Buổi bù đã diễn ra: giữ nguyên buổi gốc ở trạng thái hủy để không dạy trùng.
			if deref(se.MakeupStatus) != "scheduled" || se.MakeupHasData {
				continue
			}
			// Khách hẹn học thử vào buổi bù chuyển về buổi gốc được khôi phục (xóa buổi bù sẽ xóa luôn lịch hẹn).
			if err := q.DropReplacedTrialBookings(ctx, classesdb.DropReplacedTrialBookingsParams{ToSession: se.ID, FromSession: *se.MakeupID}); err != nil {
				return 0, err
			}
			if err := q.MoveTrialBookings(ctx, classesdb.MoveTrialBookingsParams{ToSession: se.ID, FromSession: *se.MakeupID}); err != nil {
				return 0, err
			}
			if err := q.DeleteSessions(ctx, []uuid.UUID{*se.MakeupID}); err != nil {
				return 0, err
			}
		}
		notes := optText(holidayNoteRe.ReplaceAllString(deref(se.Notes), ""))
		if err := q.RestoreSession(ctx, classesdb.RestoreSessionParams{ID: se.ID, Notes: notes}); err != nil {
			return 0, err
		}
		if se.MakeupID != nil && se.MakeupDate != nil {
			if err := pullBackEndDate(ctx, q, se.ClassID, *se.MakeupDate); err != nil {
				return 0, err
			}
		}
		restored++
	}
	return restored, nil
}

// pullBackEndDate: buổi bù cuối bị xóa → ngày kết thúc lớp lùi về buổi còn lại muộn nhất.
func pullBackEndDate(ctx context.Context, q *classesdb.Queries, classID uuid.UUID, removed time.Time) error {
	c, err := q.ClassForMakeup(ctx, classID)
	if isNoRows(err) {
		return nil
	}
	if err != nil {
		return err
	}
	if c.EndDate == nil || !c.EndDate.Equal(removed) {
		return nil
	}
	last, err := q.LastRegularOrMakeupDate(ctx, classID)
	if isNoRows(err) {
		return nil
	}
	if err != nil {
		return err
	}
	return q.SetClassEndDate(ctx, classesdb.SetClassEndDateParams{ID: classID, EndDate: &last})
}

// classSlots: ca học lặp tuần của lớp: ưu tiên cấu hình TKB, không có thì suy ra từ các buổi chính khóa đã sinh.
func classSlots(ctx context.Context, q *classesdb.Queries, classID uuid.UUID, fallback classesdb.HolidayAffectedSessionsRow) ([]slot, error) {
	rows, err := q.ListSlots(ctx, []uuid.UUID{classID})
	if err != nil {
		return nil, err
	}
	var out []slot
	for _, r := range rows {
		out = append(out, slot{Weekday: int(r.Weekday), Start: r.StartTime, End: r.EndTime, Name: r.ShiftName})
	}
	if len(out) > 0 {
		return out, nil
	}
	derived, err := q.RegularSessionSlots(ctx, classID)
	if err != nil {
		return nil, err
	}
	seen := map[string]bool{}
	add := func(sl slot) {
		k := fmt.Sprintf("%d|%s", sl.Weekday, sl.Start)
		if !seen[k] {
			seen[k] = true
			out = append(out, sl)
		}
	}
	for _, r := range derived {
		add(slot{Weekday: int(r.Weekday), Start: r.StartTime, End: r.EndTime, Name: r.ShiftName})
	}
	add(slot{Weekday: isoWeekday(fallback.Date), Start: fallback.StartTime, End: fallback.EndTime, Name: deref(fallback.ShiftName)})
	sort.SliceStable(out, func(i, j int) bool {
		if out[i].Weekday != out[j].Weekday {
			return out[i].Weekday < out[j].Weekday
		}
		return out[i].Start < out[j].Start
	})
	return out, nil
}

// scheduleMakeup: xếp buổi học bù cho buổi bị hủy vào ca kế tiếp của lớp, sau buổi cuối cùng hiện có.
func (s *Service) scheduleMakeup(ctx context.Context, q *classesdb.Queries, cancelled classesdb.HolidayAffectedSessionsRow, h catalogs.HolidayScope) (bool, error) {
	c, err := q.ClassForMakeup(ctx, cancelled.ClassID)
	if isNoRows(err) {
		return false, nil
	}
	if err != nil {
		return false, err
	}
	if c.Status == StatusCancelled || c.Status == StatusCompleted {
		return false, nil
	}
	slots, err := classSlots(ctx, q, c.ID, cancelled)
	if err != nil {
		return false, err
	}
	from := s.today()
	if h.EndDate.After(from) {
		from = h.EndDate
	}
	if last, err := q.LastSessionDate(ctx, c.ID); err == nil && last.After(from) {
		from = last
	} else if err != nil && !isNoRows(err) {
		return false, err
	}
	from = from.AddDate(0, 0, 1)
	to := from.AddDate(0, 0, makeupSearchDays)
	holidays, err := s.holidayDates(ctx, cancelled.BranchID, from, to)
	if err != nil {
		return false, err
	}
	staff := []*uuid.UUID{cancelled.TeacherID, cancelled.ForeignTeacherID, cancelled.AssistantID}
	for d := from; !d.After(to); d = d.AddDate(0, 0, 1) {
		if holidays[d.Format(time.DateOnly)] {
			continue
		}
		for _, sl := range slots {
			if isoWeekday(d) != sl.Weekday {
				continue
			}
			own, err := q.OwnOverlap(ctx, classesdb.OwnOverlapParams{ClassID: c.ID, Date: d, StartTime: sl.Start, EndTime: sl.End})
			if err != nil {
				return false, err
			}
			if own {
				continue
			}
			cand := planned{Date: d, Start: sl.Start, End: sl.End, Room: cancelled.Room}
			hit, err := findConflict(ctx, q, []planned{cand}, cancelled.BranchID, staff, cancelled.Room, &c.ID)
			if err != nil {
				return false, err
			}
			if hit != nil {
				continue
			}
			shift := sl.Name
			if shift == "" {
				shift = deref(cancelled.ShiftName)
			}
			notes := "Học bù cho buổi " + cancelled.Date.Format("02/01/2006") + " (nghỉ lễ: " + h.Name + ")"
			if _, err := q.InsertSession(ctx, classesdb.InsertSessionParams{
				ClassID: c.ID, BranchID: cancelled.BranchID, Date: d, ShiftName: optText(shift), Type: "makeup",
				StartTime: sl.Start, EndTime: sl.End, Room: cancelled.Room, TeacherID: cancelled.TeacherID,
				ForeignTeacherID: cancelled.ForeignTeacherID, AssistantID: cancelled.AssistantID, Notes: &notes,
				RescheduledFromID: &cancelled.ID,
			}); err != nil {
				return false, err
			}
			if c.EndDate == nil || c.EndDate.Before(d) {
				if err := q.SetClassEndDate(ctx, classesdb.SetClassEndDateParams{ID: c.ID, EndDate: &d}); err != nil {
					return false, err
				}
			}
			return true, nil
		}
	}
	return false, nil
}
