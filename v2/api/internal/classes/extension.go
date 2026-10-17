package classes

import (
	"context"
	"fmt"
	"strings"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/classes/classesdb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/syllabus"
)

// Phần việc phân hệ Lớp học làm cho Giáo trình (syllabus.Classes): lớp được xem, giãn tiến độ (thêm buổi theo TKB của lớp
// — ScheduleExtensionService).

type SyllabusClasses struct{ cls *Service }

func (s *Service) SyllabusClasses() SyllabusClasses { return SyllabusClasses{cls: s} }

var _ syllabus.Classes = SyllabusClasses{}

// extensionSearchDays: cửa sổ tìm ngày tối đa (tránh lặp vô hạn khi lịch nghỉ quá dài).
const extensionSearchDays = 366

func (c SyllabusClasses) VisibleClassIDs(ctx context.Context, actor *iam.Access) ([]uuid.UUID, error) {
	return c.cls.VisibleClassIDs(ctx, actor)
}

// extensionSlots: ca học lặp tuần của lớp: ưu tiên cấu hình TKB, không có thì suy ra từ các buổi chính khóa đã sinh.
func extensionSlots(ctx context.Context, q *classesdb.Queries, classID uuid.UUID) ([]slot, error) {
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
	for _, r := range derived {
		k := fmt.Sprintf("%d|%s", r.Weekday, r.StartTime)
		if !seen[k] {
			seen[k] = true
			out = append(out, slot{Weekday: int(r.Weekday), Start: r.StartTime, End: r.EndTime, Name: r.ShiftName})
		}
	}
	return out, nil
}

// ExtendSessions thêm count buổi chính khóa nối tiếp sau buổi cuối (chính khóa / học bù) theo ca lặp tuần, bỏ ngày nghỉ
// lễ, chặn trùng phòng / nhân sự với lớp khác; lùi ngày kết thúc lớp nếu muộn hơn. Chạy trong tx của bên gọi.
func (c SyllabusClasses) ExtendSessions(ctx context.Context, tx pgx.Tx, classID uuid.UUID, count int, note string, by *uuid.UUID) (syllabus.Extension, error) {
	var out syllabus.Extension
	if count < 1 {
		return out, nil
	}
	s := c.cls
	q := s.q.WithTx(tx)
	cl, err := q.LockClass(ctx, classID)
	if isNoRows(err) {
		return out, ErrNotFound
	}
	if err != nil {
		return out, err
	}
	slots, err := extensionSlots(ctx, q, classID)
	if err != nil {
		return out, err
	}
	if len(slots) == 0 {
		return out, iam.Invalid("Lớp " + cl.Name + " chưa có thời khóa biểu (ca học lặp tuần) nên không thể tự thêm buổi. Hãy xếp TKB cho lớp trước.")
	}
	today := s.today()
	anchor := today.AddDate(0, 0, -1)
	if last, err := q.LastRegularOrMakeupDate(ctx, classID); err == nil {
		anchor = last
	} else if !isNoRows(err) {
		return out, err
	} else if cl.EndDate != nil {
		anchor = *cl.EndDate
	}
	from := anchor.AddDate(0, 0, 1)
	if from.Before(today) {
		from = today
	}
	var sessions []planned
	window := (count+len(slots)-1)/len(slots)*7 + 7
	for len(sessions) < count && window <= extensionSearchDays {
		if sessions, err = s.generate(ctx, slots, from, from.AddDate(0, 0, window), cl.BranchID); err != nil {
			return out, err
		}
		window += 28
	}
	if len(sessions) < count {
		return out, iam.Invalid(fmt.Sprintf("Không tìm đủ %d buổi trống trong 12 tháng tới theo lịch học của lớp (vướng ngày nghỉ lễ).", count))
	}
	sessions = sessions[:count]
	for i := range sessions {
		sessions[i].Room = cl.Room
	}
	hit, err := findConflict(ctx, q, sessions, cl.BranchID, []*uuid.UUID{cl.TeacherID, cl.AssistantID, cl.ForeignTeacherID}, cl.Room, &cl.ID)
	if err != nil {
		return out, err
	}
	if hit != nil {
		return out, iam.Invalid(fmt.Sprintf("Buổi thêm %s %s-%s trùng lịch với lớp %s (%s). Hãy xếp tay trên TKB.",
			hit.Session.day(), hit.Session.Start, hit.Session.End, hit.Existing.ClassName, hit.Existing.ClassCode))
	}
	teacher := cl.TeacherID
	if teacher == nil {
		teacher = cl.ForeignTeacherID
	}
	var notes *string
	if n := strings.TrimSpace(note); n != "" {
		notes = &n
	}
	for _, p := range sessions {
		if _, err := q.InsertSession(ctx, classesdb.InsertSessionParams{
			ClassID: cl.ID, BranchID: cl.BranchID, Date: p.Date, ShiftName: optText(p.Shift), Type: "regular",
			StartTime: p.Start, EndTime: p.End, Room: cl.Room, TeacherID: teacher, ForeignTeacherID: cl.ForeignTeacherID,
			AssistantID: cl.AssistantID, Notes: notes, CreatedBy: by,
		}); err != nil {
			return out, err
		}
		out.Dates = append(out.Dates, p.Date)
	}
	end := sessions[len(sessions)-1].Date
	out.EndDate = cl.EndDate
	if cl.EndDate == nil || cl.EndDate.Before(end) {
		if err := q.SetClassEndDate(ctx, classesdb.SetClassEndDateParams{ID: cl.ID, EndDate: &end}); err != nil {
			return out, err
		}
		out.EndDate = &end
	}
	return out, nil
}
