package catalogs

import (
	"context"
	"fmt"
	"slices"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// ClassHooks là phần việc của phân hệ Lớp học mà danh mục cần: đếm lớp theo khóa / trình độ, lớp đang dùng phòng,
// xếp lại lịch học khi ngày nghỉ lễ thay đổi, buổi học lệch khung giờ ca dạy. Gắn ở cmd/menglish (Service.Classes);
// chưa gắn thì coi như chưa có lớp nào.
type ClassHooks interface {
	// CourseClassCounts: số lớp (chưa xóa) của từng khóa học.
	CourseClassCounts(ctx context.Context, courseIDs []uuid.UUID) (map[uuid.UUID]int64, error)
	// LevelUsage: số lớp dùng mã trình độ và số học viên (chưa thôi học) có lớp chính dùng mã đó.
	LevelUsage(ctx context.Context, codes []string) (classes, students map[string]int64, err error)
	// RoomClasses: lớp còn mở (chưa kết thúc / hủy) đang gắn từng phòng, kèm ca học tuần (RoomService::usage).
	RoomClasses(ctx context.Context, roomIDs []uuid.UUID) (map[uuid.UUID][]RoomClass, error)
	// RoomRenamed: chép tên phòng mới sang lớp đang gắn phòng và buổi sắp tới còn dùng tên cũ (RoomService::syncName).
	RoomRenamed(ctx context.Context, tx pgx.Tx, roomID, branchID uuid.UUID, oldName, newName string) error
	// RoomDeleting: phòng có lớp đang học thì trả lỗi chặn xóa; lớp chưa bắt đầu học thì gỡ phòng khỏi lớp,
	// trả tên các lớp đã gỡ.
	RoomDeleting(ctx context.Context, tx pgx.Tx, roomID uuid.UUID, name string) ([]string, error)
	// HolidayApplied: ngày nghỉ được thêm / sửa → hủy buổi trùng ngày nghỉ, xếp bù cuối lịch, khôi phục buổi không
	// còn nằm trong ngày nghỉ (HolidayRescheduleService::apply).
	HolidayApplied(ctx context.Context, h HolidayScope) (HolidaySummary, error)
	// HolidayReleased: ngày nghỉ bị xóa → khôi phục mọi buổi đã hủy vì nó, gỡ buổi bù chưa diễn ra; trả số buổi khôi phục.
	HolidayReleased(ctx context.Context, holidayID uuid.UUID) (int, error)
	// RegularSessions: buổi chính khóa (chưa hủy) trong khoảng ngày của các lớp người dùng được xem.
	RegularSessions(ctx context.Context, actor *iam.Access, from, to time.Time) ([]RegularSession, error)
}

// RoomClass: một lớp đang gắn phòng.
type RoomClass struct {
	ID       uuid.UUID `json:"id"`
	Name     string    `json:"name"`
	Code     string    `json:"code"`
	Studying bool      `json:"studying"` // Đang học (đã khai giảng): giữ chặt phòng
	Slots    []string  `json:"slots"`    // "Thứ 2 18:00–19:30"; chưa cấu hình TKB thì mô tả lịch cũ
}

// HolidayScope: ngày nghỉ (sau khi lưu) để xếp lại lịch học.
type HolidayScope struct {
	ID         uuid.UUID
	Name       string
	StartDate  time.Time
	EndDate    time.Time
	SystemWide bool
	BranchIDs  []uuid.UUID
}

// HolidaySummary: kết quả xếp lại lịch học theo ngày nghỉ.
type HolidaySummary struct {
	Cancelled   int `json:"cancelled"`
	Rescheduled int `json:"rescheduled"`
	Unscheduled int `json:"unscheduled"`
	Flagged     int `json:"flagged"`
	Restored    int `json:"restored"`
}

// Text: phần nối vào thông báo lưu ngày nghỉ (HolidayController::summaryText), rỗng nếu không có gì thay đổi.
func (s HolidaySummary) Text() string {
	var parts []string
	if s.Cancelled > 0 {
		parts = append(parts, fmt.Sprintf("Đã hủy %d buổi học trùng ngày nghỉ, xếp bù %d buổi vào cuối lịch", s.Cancelled, s.Rescheduled))
	}
	if s.Unscheduled > 0 {
		parts = append(parts, fmt.Sprintf("%d buổi chưa tìm được ca bù (cần xếp tay trong TKB)", s.Unscheduled))
	}
	if s.Flagged > 0 {
		parts = append(parts, fmt.Sprintf("%d buổi đã có điểm danh/chấm công nên giữ nguyên, cần kiểm tra", s.Flagged))
	}
	if s.Restored > 0 {
		parts = append(parts, fmt.Sprintf("khôi phục %d buổi không còn nằm trong ngày nghỉ", s.Restored))
	}
	if len(parts) == 0 {
		return ""
	}
	out := " " + parts[0]
	for _, p := range parts[1:] {
		out += "; " + p
	}
	return out + "."
}

// RegularSession: một buổi chính khóa (để tìm buổi lệch khung giờ ca dạy).
type RegularSession struct {
	ClassID   uuid.UUID
	ClassName string
	ClassCode string
	Branch    string
	Date      time.Time
	Start     string // HH:MM
	End       string
}

// OffScheduleClass: lớp có buổi chính khóa sắp tới lệch khung giờ ca dạy (để Học vụ xếp lại TKB).
type OffScheduleClass struct {
	ClassID   uuid.UUID `json:"class_id"`
	ClassName string    `json:"class_name"`
	ClassCode string    `json:"class_code"`
	Branch    string    `json:"branch"`
	Slots     []string  `json:"slots"`
	Sessions  int       `json:"sessions"`
}

// ───────────────────────── Quy tắc khung giờ ca dạy ─────────────────────────

// WeekDays: thứ trong tuần theo ISO (1 = Thứ 2 … 7 = Chủ nhật), đúng nhãn của Laravel (SessionScheduleService::DAY_MAP).
var WeekDays = []string{"Thứ 2", "Thứ 3", "Thứ 4", "Thứ 5", "Thứ 6", "Thứ 7", "Chủ nhật"}

// WeekdayOf: "Thứ 2" → 1 … "Chủ nhật" → 7; 0 nếu không hợp lệ.
func WeekdayOf(day string) int {
	return slices.Index(WeekDays, day) + 1
}

// DayName: 1 → "Thứ 2" … 7 → "Chủ nhật".
func DayName(weekday int) string {
	if weekday < 1 || weekday > 7 {
		return ""
	}
	return WeekDays[weekday-1]
}

// DayTypeOf: loại ngày của khung giờ theo thứ.
func DayTypeOf(day string) string {
	switch day {
	case "Thứ 7":
		return "saturday"
	case "Chủ nhật":
		return "sunday"
	}
	return "weekday"
}

// WeekendCustomName: tên ca cuối tuần khi giờ của lớp không trùng khung nào.
const WeekendCustomName = "Ca theo TKB"

type shiftVariant struct {
	name, start, end, variant string
}

// ShiftRules: quy tắc khung giờ ca dạy (TeachingShiftRules): Thứ 2–6 chỉ có các ca trong danh mục (giờ chuẩn hoặc
// giờ lệch), cuối tuần lấy giờ thực tế của lớp; mỗi ca dạy 90 phút. Loại ngày chưa có khung đang dùng thì chỉ kiểm 90 phút.
type ShiftRules struct {
	frames map[string][]Shift // khung đang dùng theo loại ngày
}

// ShiftRules: nạp khung giờ đang dùng.
func (s *Service) ShiftRules(ctx context.Context) (*ShiftRules, error) {
	rows, err := s.q.ListShifts(ctx)
	if err != nil {
		return nil, err
	}
	r := &ShiftRules{frames: map[string][]Shift{}}
	for _, row := range rows {
		sh := toShift(row)
		if sh.IsActive {
			r.frames[sh.DayType] = append(r.frames[sh.DayType], sh)
		}
	}
	return r, nil
}

func (r *ShiftRules) variants(dayType string) []shiftVariant {
	var out []shiftVariant
	for _, sh := range r.frames[dayType] {
		out = append(out, shiftVariant{sh.Name, sh.StartTime, sh.EndTime, "standard"})
		if sh.AltStartTime != nil {
			out = append(out, shiftVariant{sh.Name, *sh.AltStartTime, *sh.AltEndTime, "alt"})
		}
	}
	return out
}

func (r *ShiftRules) match(dayType, start, end string) *shiftVariant {
	for _, v := range r.variants(dayType) {
		if v.start == start && v.end == end {
			return &v
		}
	}
	return nil
}

// Violation: lỗi của một ca học lặp tuần ("" = hợp lệ); day là "Thứ 2" … "Chủ nhật", giờ "HH:MM".
func (r *ShiftRules) Violation(day, start, end string) string {
	from, ok1 := parseHM(start)
	to, ok2 := parseHM(end)
	if !ok1 || !ok2 || to-from != ShiftMinutes {
		return fmt.Sprintf("%s: mỗi ca dạy %d phút — ca bắt đầu %s phải kết thúc lúc %s.", day, ShiftMinutes, start, addMinutes(start, ShiftMinutes))
	}
	dt := DayTypeOf(day)
	if dt == "weekday" && len(r.frames[dt]) > 0 && r.match(dt, start, end) == nil {
		return fmt.Sprintf("%s chỉ có %s. Chọn lại ca theo khung giờ.", day, r.WeekdayFramesText())
	}
	return ""
}

// NameFor: tên ca theo khung: Thứ 2–6 lấy tên khung (không khớp thì "Ca HH:MM"), cuối tuần không khớp thì "Ca theo TKB".
func (r *ShiftRules) NameFor(day, start, end string) string {
	dt := DayTypeOf(day)
	if m := r.match(dt, start, end); m != nil {
		return m.name
	}
	if dt == "weekday" {
		return "Ca " + start
	}
	return WeekendCustomName
}

// WeekdayFramesText: "Ca 1 (18:00–19:30 hoặc 18:10–19:40) và Ca 2 (…)".
func (r *ShiftRules) WeekdayFramesText() string {
	var parts []string
	for _, sh := range r.frames["weekday"] {
		p := sh.Name + " (" + sh.Standard
		if sh.Alt != nil {
			p += " hoặc " + *sh.Alt
		}
		parts = append(parts, p+")")
	}
	return joinVi(parts, "ca theo danh mục")
}

// ShiftOption: một lựa chọn ca cho form TKB.
type ShiftOption struct {
	Value   string `json:"value"` // "18:00-19:30"
	Label   string `json:"label"`
	Name    string `json:"name"`
	Start   string `json:"start"`
	End     string `json:"end"`
	Variant string `json:"variant"` // standard | alt
}

// Options: lựa chọn ca theo loại ngày (weekday / saturday / sunday).
func (r *ShiftRules) Options() map[string][]ShiftOption {
	out := map[string][]ShiftOption{}
	for _, d := range DayTypes {
		list := []ShiftOption{}
		for _, v := range r.variants(d.Value) {
			value := v.start + "-" + v.end
			if slices.ContainsFunc(list, func(o ShiftOption) bool { return o.Value == value }) {
				continue
			}
			label := v.name + " · " + v.start + "–" + v.end
			if v.variant == "alt" {
				label += " (giờ lệch)"
			}
			list = append(list, ShiftOption{Value: value, Label: label, Name: v.name, Start: v.start, End: v.end, Variant: v.variant})
		}
		out[d.Value] = list
	}
	return out
}

// addMinutes: "18:00" + 90 → "19:30" (không vượt 23:59).
func addMinutes(hm string, minutes int) string {
	m, ok := parseHM(hm)
	if !ok {
		var h, mm int
		if _, err := fmt.Sscanf(hm, "%d:%d", &h, &mm); err != nil {
			return hm
		}
		m = h*60 + mm
	}
	total := min(23*60+59, m+minutes)
	return fmt.Sprintf("%02d:%02d", total/60, total%60)
}

// offSchedule: nhóm buổi chính khóa lệch khung giờ theo lớp (TeachingShiftRules::offScheduleClasses).
func (r *ShiftRules) offSchedule(sessions []RegularSession) []OffScheduleClass {
	out := []OffScheduleClass{}
	idx := map[uuid.UUID]int{}
	for _, se := range sessions {
		wd := int(se.Date.Weekday())
		if wd == 0 {
			wd = 7
		}
		day := DayName(wd)
		if r.Violation(day, se.Start, se.End) == "" {
			continue
		}
		i, ok := idx[se.ClassID]
		if !ok {
			i = len(out)
			idx[se.ClassID] = i
			out = append(out, OffScheduleClass{ClassID: se.ClassID, ClassName: se.ClassName, ClassCode: se.ClassCode, Branch: se.Branch, Slots: []string{}})
		}
		slot := day + " " + se.Start + "–" + se.End
		if !slices.Contains(out[i].Slots, slot) {
			out[i].Slots = append(out[i].Slots, slot)
		}
		out[i].Sessions++
	}
	slices.SortStableFunc(out, func(a, b OffScheduleClass) int {
		switch {
		case a.ClassName < b.ClassName:
			return -1
		case a.ClassName > b.ClassName:
			return 1
		}
		return 0
	})
	return out
}
