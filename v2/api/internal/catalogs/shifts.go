package catalogs

import (
	"context"
	"fmt"
	"strings"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/catalogs/catalogsdb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// Khung giờ ca dạy (TeachingShiftController): ai xếp lịch lớp (class.update) được xem; thêm / sửa / ngừng dùng / xóa
// chỉ teaching_shift.manage (Admin). Mỗi ca dạy 90 phút; khung có giờ chuẩn và (tùy chọn) giờ lệch.

// ShiftMinutes: thời lượng một ca dạy (file khung giờ chấm công).
const ShiftMinutes = 90

var errShiftNotFound = notFound("Không tìm thấy khung giờ ca dạy.")

// DayTypes: loại ngày của khung giờ, đúng thứ tự hiển thị.
var DayTypes = []Option{{"weekday", "Thứ 2 – Thứ 6"}, {"saturday", "Thứ 7"}, {"sunday", "Chủ nhật"}}

func dayLabel(v string) string {
	for _, d := range DayTypes {
		if d.Value == v {
			return d.Label
		}
	}
	return v
}

type Shift struct {
	ID           uuid.UUID `json:"id"`
	DayType      string    `json:"day_type"`
	DayLabel     string    `json:"day_label"`
	Name         string    `json:"name"`
	StartTime    string    `json:"start_time"`
	EndTime      string    `json:"end_time"`
	AltStartTime *string   `json:"alt_start_time"`
	AltEndTime   *string   `json:"alt_end_time"`
	Standard     string    `json:"standard"`
	Alt          *string   `json:"alt"`
	Note         *string   `json:"note"`
	IsActive     bool      `json:"is_active"`
}

func (s Shift) label() string { return s.DayLabel + " · " + s.Name + " " + s.Standard }

// input: dữ liệu khung giờ dạng form (để so trước / sau trong nhật ký).
func (s Shift) input() ShiftInput {
	return ShiftInput{
		DayType: s.DayType, Name: s.Name, StartTime: s.StartTime, EndTime: s.EndTime,
		AltStartTime: deref(s.AltStartTime), AltEndTime: deref(s.AltEndTime), Note: deref(s.Note),
	}
}

type ShiftList struct {
	DayTypes []Option `json:"day_types"`
	Shifts   []Shift  `json:"shifts"`
	// "Ca 1 (18:00–19:30 hoặc 18:10–19:40) và Ca 2 (...)": khung Thứ 2–6 đang dùng
	WeekdayRule string `json:"weekday_rule"`
	Duration    int    `json:"duration"`
	// Lớp (trong phạm vi người xem) có buổi chính khóa 60 ngày tới lệch khung giờ
	OffScheduleClasses []OffScheduleClass `json:"off_schedule_classes"`
}

// offScheduleDays: số ngày tới xét buổi lệch khung giờ.
const offScheduleDays = 60

func (s *Service) ListShifts(ctx context.Context, actor *iam.Access) (ShiftList, error) {
	rows, err := s.q.ListShifts(ctx)
	if err != nil {
		return ShiftList{}, err
	}
	shifts := make([]Shift, 0, len(rows))
	var weekday []string
	for _, r := range rows {
		sh := toShift(r)
		shifts = append(shifts, sh)
		if sh.DayType == "weekday" && sh.IsActive {
			part := sh.Name + " (" + sh.Standard
			if sh.Alt != nil {
				part += " hoặc " + *sh.Alt
			}
			weekday = append(weekday, part+")")
		}
	}
	out := ShiftList{DayTypes: DayTypes, Shifts: shifts, WeekdayRule: joinVi(weekday, "ca theo danh mục"),
		Duration: ShiftMinutes, OffScheduleClasses: []OffScheduleClass{}}
	if s.Classes != nil {
		from := dateOnly(time.Now())
		sessions, err := s.Classes.RegularSessions(ctx, actor, from, from.AddDate(0, 0, offScheduleDays))
		if err != nil {
			return out, err
		}
		rules, err := s.ShiftRules(ctx)
		if err != nil {
			return out, err
		}
		out.OffScheduleClasses = rules.offSchedule(sessions)
	}
	return out, nil
}

// dateOnly: ngày (giờ Việt Nam) của t, lúc 00:00 UTC — cùng cách biểu diễn cột date của pgx.
func dateOnly(t time.Time) time.Time {
	y, m, d := t.In(time.FixedZone("ICT", 7*3600)).Date()
	return time.Date(y, m, d, 0, 0, 0, 0, time.UTC)
}

func toShift(r catalogsdb.ListShiftsRow) Shift {
	sh := Shift{ID: r.ID, DayType: r.DayType, DayLabel: dayLabel(r.DayType), Name: r.Name, StartTime: r.StartTime, EndTime: r.EndTime,
		Standard: r.StartTime + "–" + r.EndTime, Note: r.Note, IsActive: r.IsActive}
	if r.AltStartTime != "" && r.AltEndTime != "" {
		a, b := r.AltStartTime, r.AltEndTime
		alt := a + "–" + b
		sh.AltStartTime, sh.AltEndTime, sh.Alt = &a, &b, &alt
	}
	return sh
}

// joinVi: "A", "A và B", "A, B và C"; rỗng thì empty.
func joinVi(parts []string, empty string) string {
	switch len(parts) {
	case 0:
		return empty
	case 1:
		return parts[0]
	}
	return strings.Join(parts[:len(parts)-1], ", ") + " và " + parts[len(parts)-1]
}

type ShiftInput struct {
	DayType      string `json:"day_type"`
	Name         string `json:"name"`
	StartTime    string `json:"start_time"`
	EndTime      string `json:"end_time"`
	AltStartTime string `json:"alt_start_time"`
	AltEndTime   string `json:"alt_end_time"`
	Note         string `json:"note"`
}

func parseHM(v string) (int, bool) {
	t, err := time.Parse("15:04", v)
	if err != nil || len(v) != 5 {
		return 0, false
	}
	return t.Hour()*60 + t.Minute(), true
}

func (in *ShiftInput) validate() error {
	in.Name = strings.TrimSpace(in.Name)
	in.Note = strings.TrimSpace(in.Note)
	in.AltStartTime = strings.TrimSpace(in.AltStartTime)
	in.AltEndTime = strings.TrimSpace(in.AltEndTime)
	if dayLabel(in.DayType) == in.DayType {
		return iam.Invalid("Loại ngày không hợp lệ.")
	}
	switch {
	case in.Name == "":
		return iam.Invalid("Vui lòng nhập tên ca.")
	case tooLong(in.Name, 50):
		return iam.Invalid("Tên ca không được vượt quá 50 ký tự.")
	case tooLong(in.Note, 255):
		return iam.Invalid("Ghi chú không được vượt quá 255 ký tự.")
	case in.StartTime == "":
		return iam.Invalid("Vui lòng nhập giờ bắt đầu.")
	case in.EndTime == "":
		return iam.Invalid("Vui lòng nhập giờ kết thúc.")
	case in.AltStartTime == "" && in.AltEndTime != "":
		return iam.Invalid("Vui lòng nhập giờ lệch bắt đầu khi có giờ lệch kết thúc.")
	case in.AltEndTime == "" && in.AltStartTime != "":
		return iam.Invalid("Vui lòng nhập giờ lệch kết thúc khi có giờ lệch bắt đầu.")
	}
	pairs := [][3]string{{in.StartTime, in.EndTime, "giờ"}, {in.AltStartTime, in.AltEndTime, "giờ lệch"}}
	for _, p := range pairs {
		if p[0] == "" {
			continue
		}
		from, ok1 := parseHM(p[0])
		to, ok2 := parseHM(p[1])
		if !ok1 || !ok2 {
			return iam.Invalid("Giờ không hợp lệ (định dạng HH:MM).")
		}
		if to-from != ShiftMinutes {
			return iam.Invalid(fmt.Sprintf("Mỗi ca dạy %d phút: giờ kết thúc phải sau giờ bắt đầu đúng %d phút.", ShiftMinutes, ShiftMinutes))
		}
	}
	if in.AltStartTime != "" && in.AltStartTime == in.StartTime {
		return iam.Invalid("Giờ lệch trùng giờ chuẩn — để trống nếu khung không có giờ lệch.")
	}
	return nil
}

func (in ShiftInput) label() string {
	return dayLabel(in.DayType) + " · " + in.Name + " " + in.StartTime + "–" + in.EndTime
}

func (s *Service) CreateShift(ctx context.Context, actor *iam.Access, in ShiftInput) (uuid.UUID, string, error) {
	if err := in.validate(); err != nil {
		return uuid.Nil, "", err
	}
	var id uuid.UUID
	err := s.audited(ctx, actor, func(q *catalogsdb.Queries) (audit.Entry, error) {
		var err error
		id, err = q.CreateShift(ctx, catalogsdb.CreateShiftParams{
			DayType: in.DayType, Name: in.Name, StartTime: in.StartTime, EndTime: in.EndTime,
			AltStartTime: optText(in.AltStartTime), AltEndTime: optText(in.AltEndTime), Note: optText(in.Note), CreatedBy: &actor.UserID,
		})
		return shiftEntry(id, in.label(), audit.EventCreated, "teaching_shift.create", "Thêm khung giờ ca dạy: "+in.label(),
			audit.Diff(nil, shiftSnapshot(in))), err
	})
	if err != nil {
		return uuid.Nil, "", err
	}
	s.log.Info("teaching shift created", "shift_id", id, "by", actor.UserID)
	return id, fmt.Sprintf("Đã thêm khung %s.", in.label()), nil
}

func (s *Service) UpdateShift(ctx context.Context, actor *iam.Access, id uuid.UUID, in ShiftInput) (string, error) {
	if err := in.validate(); err != nil {
		return "", err
	}
	old, err := s.shiftByID(ctx, id)
	if err != nil {
		return "", err
	}
	err = s.audited(ctx, actor, func(q *catalogsdb.Queries) (audit.Entry, error) {
		n, err := q.UpdateShift(ctx, catalogsdb.UpdateShiftParams{
			ID: id, DayType: in.DayType, Name: in.Name, StartTime: in.StartTime, EndTime: in.EndTime,
			AltStartTime: optText(in.AltStartTime), AltEndTime: optText(in.AltEndTime), Note: optText(in.Note),
		})
		if err != nil {
			return audit.Entry{}, err
		}
		if n == 0 {
			return audit.Entry{}, errShiftNotFound
		}
		return shiftEntry(id, in.label(), audit.EventUpdated, "teaching_shift.update", "Sửa khung giờ ca dạy: "+in.label(),
			audit.Diff(shiftSnapshot(old.input()), shiftSnapshot(in))), nil
	})
	if err != nil {
		return "", err
	}
	s.log.Info("teaching shift updated", "shift_id", id, "by", actor.UserID)
	return fmt.Sprintf("Đã lưu khung %s. Lịch đã xếp không đổi — chỉ áp khi xếp / sửa TKB.", in.label()), nil
}

func (s *Service) shiftByID(ctx context.Context, id uuid.UUID) (Shift, error) {
	list, err := s.q.ListShifts(ctx)
	if err != nil {
		return Shift{}, err
	}
	for _, r := range list {
		if r.ID == id {
			return toShift(r), nil
		}
	}
	return Shift{}, errShiftNotFound
}

// ToggleShift: Ngừng dùng ↔ Dùng lại.
func (s *Service) ToggleShift(ctx context.Context, actor *iam.Access, id uuid.UUID) (string, error) {
	sh, err := s.shiftByID(ctx, id)
	if err != nil {
		return "", err
	}
	sh.IsActive = !sh.IsActive
	err = s.audited(ctx, actor, func(q *catalogsdb.Queries) (audit.Entry, error) {
		if n, err := q.ToggleShift(ctx, id); err != nil {
			return audit.Entry{}, err
		} else if n == 0 {
			return audit.Entry{}, errShiftNotFound
		}
		verb := "Ngừng dùng"
		if sh.IsActive {
			verb = "Dùng lại"
		}
		return shiftEntry(id, sh.label(), audit.EventUpdated, "teaching_shift.toggle", verb+" khung giờ ca dạy: "+sh.label(),
			audit.Changes{"is_active": {Old: !sh.IsActive, New: sh.IsActive}}), nil
	})
	if err != nil {
		return "", err
	}
	s.log.Info("teaching shift toggled", "shift_id", id, "active", sh.IsActive, "by", actor.UserID)
	if sh.IsActive {
		return fmt.Sprintf("Đã dùng lại khung %s.", sh.label()), nil
	}
	return fmt.Sprintf("Đã ngừng dùng khung %s.", sh.label()), nil
}

func (s *Service) DeleteShift(ctx context.Context, actor *iam.Access, id uuid.UUID) (string, error) {
	sh, err := s.shiftByID(ctx, id)
	if err != nil {
		return "", err
	}
	err = s.audited(ctx, actor, func(q *catalogsdb.Queries) (audit.Entry, error) {
		_, err := q.DeleteShift(ctx, id)
		return shiftEntry(id, sh.label(), audit.EventDeleted, "teaching_shift.delete", "Xóa khung giờ ca dạy: "+sh.label(),
			audit.Diff(shiftSnapshot(sh.input()), nil)), err
	})
	if err != nil {
		return "", err
	}
	s.log.Info("teaching shift deleted", "shift_id", id, "by", actor.UserID)
	return fmt.Sprintf("Đã xóa khung %s.", sh.label()), nil
}
