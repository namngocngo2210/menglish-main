package classes

import (
	"context"
	"fmt"
	"slices"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/catalogs"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/classes/classesdb"
)

// Sinh buổi học theo ca lặp tuần, loại ngày nghỉ lễ và phát hiện xung đột phòng / nhân sự với buổi học của lớp khác
// (SessionScheduleService). Dùng chung cho tạo lớp, xếp TKB, sửa lớp, gán GVNN và xếp bù nghỉ lễ.

// planned: một buổi dự kiến (chưa lưu).
type planned struct {
	Date  time.Time
	Start string // HH:MM
	End   string
	Room  *string
	Shift string
}

func (p planned) day() string { return p.Date.Format(time.DateOnly) }

// slot: ca học lặp tuần.
type slot struct {
	Weekday int // 1 = Thứ 2 … 7 = Chủ nhật
	Start   string
	End     string
	Name    string
}

func (sl slot) label() string { return catalogs.DayName(sl.Weekday) + " " + sl.Start + "–" + sl.End }

func isoWeekday(t time.Time) int {
	wd := int(t.Weekday())
	if wd == 0 {
		return 7
	}
	return wd
}

// holidayDates: tập ngày nghỉ (YYYY-MM-DD) trong khoảng, gồm nghỉ toàn hệ thống và nghỉ riêng của chi nhánh.
func (s *Service) holidayDates(ctx context.Context, branch uuid.UUID, from, to time.Time) (map[string]bool, error) {
	out := map[string]bool{}
	if from.After(to) {
		return out, nil
	}
	hs, err := s.cat.HolidaysBetween(ctx, branch, from, to)
	if err != nil {
		return nil, err
	}
	for _, h := range hs {
		start, err1 := time.Parse(time.DateOnly, h.StartDate)
		end, err2 := time.Parse(time.DateOnly, h.EndDate)
		if err1 != nil || err2 != nil {
			continue
		}
		if start.Before(from) {
			start = from
		}
		if end.After(to) {
			end = to
		}
		for d := start; !d.After(end); d = d.AddDate(0, 0, 1) {
			out[d.Format(time.DateOnly)] = true
		}
	}
	return out, nil
}

// generate: buổi học cho các ca trong khoảng ngày, bỏ ngày nghỉ lễ của chi nhánh.
func (s *Service) generate(ctx context.Context, slots []slot, from, to time.Time, branch uuid.UUID) ([]planned, error) {
	if from.After(to) || len(slots) == 0 {
		return nil, nil
	}
	holidays, err := s.holidayDates(ctx, branch, from, to)
	if err != nil {
		return nil, err
	}
	var out []planned
	for d := from; !d.After(to); d = d.AddDate(0, 0, 1) {
		if holidays[d.Format(time.DateOnly)] {
			continue
		}
		for _, sl := range slots {
			if isoWeekday(d) == sl.Weekday {
				out = append(out, planned{Date: d, Start: sl.Start, End: sl.End, Shift: sl.Name})
			}
		}
	}
	return out, nil
}

// conflictHit: buổi dự kiến bị trùng và buổi (của lớp khác) đang chiếm.
type conflictHit struct {
	Session  planned
	Existing classesdb.ConflictCandidatesRow
}

func (c *conflictHit) where() string {
	return fmt.Sprintf("%s %s-%s với lớp %s (%s)", c.Session.day(), c.Session.Start, c.Session.End, c.Existing.ClassName, c.Existing.ClassCode)
}

// findConflict: buổi (chưa hủy) của lớp khác trùng giờ và trùng phòng cùng chi nhánh hoặc trùng GV / GVNN / trợ giảng.
// Một truy vấn cho cả đợt.
func findConflict(ctx context.Context, q *classesdb.Queries, sessions []planned, branch uuid.UUID, staff []*uuid.UUID, defaultRoom *string, exclude *uuid.UUID) (*conflictHit, error) {
	ids := []uuid.UUID{}
	for _, id := range staff {
		if id != nil && *id != uuid.Nil && !slices.Contains(ids, *id) {
			ids = append(ids, *id)
		}
	}
	roomOf := func(p planned) string {
		if p.Room != nil && *p.Room != "" {
			return *p.Room
		}
		return deref(defaultRoom)
	}
	rooms := []string{}
	for _, p := range sessions {
		if r := roomOf(p); r != "" && !slices.Contains(rooms, r) {
			rooms = append(rooms, r)
		}
	}
	if len(sessions) == 0 || (len(ids) == 0 && len(rooms) == 0) {
		return nil, nil
	}
	from, to := sessions[0].Date, sessions[0].Date
	for _, p := range sessions {
		if p.Date.Before(from) {
			from = p.Date
		}
		if p.Date.After(to) {
			to = p.Date
		}
	}
	cands, err := q.ConflictCandidates(ctx, classesdb.ConflictCandidatesParams{
		ExcludeClass: exclude, FromDate: from, ToDate: to, BranchID: branch, Rooms: rooms, Staff: ids,
	})
	if err != nil {
		return nil, err
	}
	byDate := map[string][]classesdb.ConflictCandidatesRow{}
	for _, c := range cands {
		k := c.Date.Format(time.DateOnly)
		byDate[k] = append(byDate[k], c)
	}
	has := func(id *uuid.UUID) bool { return id != nil && slices.Contains(ids, *id) }
	for _, p := range sessions {
		room := roomOf(p)
		for _, c := range byDate[p.day()] {
			if !(c.StartTime < p.End && c.EndTime > p.Start) {
				continue
			}
			sameRoom := room != "" && c.BranchID == branch && deref(c.Room) == room
			if sameRoom || has(c.TeacherID) || has(c.ForeignTeacherID) || has(c.AssistantID) {
				return &conflictHit{Session: p, Existing: c}, nil
			}
		}
	}
	return nil, nil
}
