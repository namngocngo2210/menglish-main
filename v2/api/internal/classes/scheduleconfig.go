package classes

import (
	"context"
	"fmt"
	"slices"
	"strings"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/catalogs"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/classes/classesdb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// Lịch & TKB lớp (WorkTaskController::scheduleConfig / updateScheduleConfig): chọn lớp, tối đa 2 ca học lặp tuần
// theo khung giờ ca dạy, khoảng ngày áp dụng và phòng học → sinh buổi học thực tế từ hôm nay (bỏ ngày nghỉ lễ, giữ buổi
// đã có dữ liệu thực tế), kiểm xung đột phòng / nhân sự; đổi trạng thái Đang học ↔ Đã kết thúc.

type ScheduleData struct {
	ClassID      string `json:"class_id,omitempty"`
	AcademicYear string `json:"academic_year"`
	StartDate    string `json:"start_date"`
	EndDate      string `json:"end_date"`
	Slot1Day     string `json:"slot1_day"`
	Slot1Start   string `json:"slot1_start"`
	Slot1End     string `json:"slot1_end"`
	Slot2Day     string `json:"slot2_day"`
	Slot2Start   string `json:"slot2_start"`
	Slot2End     string `json:"slot2_end"`
	Status       string `json:"status,omitempty"`
	BranchID     string `json:"branch_id,omitempty"`
	RoomID       string `json:"room_id"`
}

type HolidaySession struct {
	ID        uuid.UUID `json:"id"`
	ClassName string    `json:"class_name"`
	ClassCode string    `json:"class_code"`
	When      string    `json:"when"`
	Holiday   *string   `json:"holiday"`
	Makeup    *string   `json:"makeup"`
}

type ScheduleListClass struct {
	ID           uuid.UUID `json:"id"`
	Name         string    `json:"name"`
	Code         string    `json:"code"`
	ScheduleText *string   `json:"schedule_text"`
	Teacher      *string   `json:"teacher"`
	Status       string    `json:"status"`
	StatusLabel  string    `json:"status_label"`
	HasConfig    bool      `json:"has_config"`
	Room         *string   `json:"room"`
}

type ScheduleConfigPage struct {
	CanSchedule        bool                              `json:"can_schedule"`
	CanToggle          bool                              `json:"can_toggle"`
	Rooms              []RoomOption                      `json:"rooms"`
	NewClasses         []Option                          `json:"new_classes"`
	ScheduledClasses   []Option                          `json:"scheduled_classes"`
	AcademicYears      []Option                          `json:"academic_years"`
	ScheduleData       map[string]ScheduleData           `json:"schedule_data"`
	Initial            ScheduleData                      `json:"initial"`
	HolidaySessions    []HolidaySession                  `json:"holiday_sessions"`
	ClassSearch        string                            `json:"class_search"`
	ListClasses        []ScheduleListClass               `json:"list_classes"`
	Weekdays           []string                          `json:"weekdays"`
	ShiftOptions       map[string][]catalogs.ShiftOption `json:"shift_options"`
	WeekdayRule        string                            `json:"weekday_rule"`
	ShiftDuration      int                               `json:"shift_duration"`
	OffScheduleClasses []catalogs.OffScheduleClass       `json:"off_schedule_classes"`
	// TODO(hr): báo cáo phòng / nhân sự 7 ngày (nhu cầu nhân sự lưu ở hr) — màn "Báo cáo" của Laravel.
}

func dateStr(t *time.Time) string {
	if t == nil {
		return ""
	}
	return t.Format(time.DateOnly)
}

// ScheduleConfig: dữ liệu màn Lịch & TKB lớp (lớp người xem phụ trách).
func (s *Service) ScheduleConfig(ctx context.Context, actor *iam.Access, classID *uuid.UUID, search string) (*ScheduleConfigPage, error) {
	sc, err := s.visible(ctx, actor)
	if err != nil {
		return nil, err
	}
	classes, err := s.q.ScheduleClasses(ctx, classesdb.ScheduleClassesParams{AllClasses: sc.all, Ids: sc.ids})
	if err != nil {
		return nil, err
	}
	ids := make([]uuid.UUID, 0, len(classes))
	for _, c := range classes {
		ids = append(ids, c.ID)
	}
	slots, err := s.q.ListSlots(ctx, ids)
	if err != nil {
		return nil, err
	}
	slotsBy := map[uuid.UUID][]classesdb.ListSlotsRow{}
	for _, sl := range slots {
		slotsBy[sl.ClassID] = append(slotsBy[sl.ClassID], sl)
	}
	now := s.now().In(vnZone)
	page := &ScheduleConfigPage{
		CanSchedule: actor.Can("work_task.assign"), CanToggle: actor.Can("class.update"),
		ScheduleData: map[string]ScheduleData{}, NewClasses: []Option{}, ScheduledClasses: []Option{},
		HolidaySessions: []HolidaySession{}, ListClasses: []ScheduleListClass{}, ClassSearch: strings.TrimSpace(search),
		Weekdays: catalogs.WeekDays, ShiftDuration: catalogs.ShiftMinutes, OffScheduleClasses: []catalogs.OffScheduleClass{},
	}
	years := []string{}
	for y := now.Year() - 1; y <= now.Year()+1; y++ {
		years = append(years, fmt.Sprintf("%d - %d", y, y+1))
	}
	needle := strings.ToLower(page.ClassSearch)
	branchSet := []uuid.UUID{}
	for _, c := range classes {
		d := ScheduleData{
			AcademicYear: deref(c.AcademicYear), StartDate: dateStr(c.StartDate), EndDate: dateStr(c.EndDate),
			Status: c.Status, BranchID: c.BranchID.String(),
		}
		if c.RoomID != nil {
			d.RoomID = c.RoomID.String()
		}
		for _, sl := range slotsBy[c.ID] {
			day := catalogs.DayName(int(sl.Weekday))
			if sl.Position == 1 {
				d.Slot1Day, d.Slot1Start, d.Slot1End = day, sl.StartTime, sl.EndTime
			} else {
				d.Slot2Day, d.Slot2Start, d.Slot2End = day, sl.StartTime, sl.EndTime
			}
		}
		page.ScheduleData[c.ID.String()] = d
		if c.AcademicYear != nil && *c.AcademicYear != "" && !slices.Contains(years, *c.AcademicYear) {
			years = append(years, *c.AcademicYear)
		}
		if c.Status != StatusCancelled && c.Status != StatusCompleted {
			if c.HasSlots {
				page.ScheduledClasses = append(page.ScheduledClasses, Option{c.ID.String(), fmt.Sprintf("%s (%s) · %s", c.Name, c.Code, deref(c.ScheduleText))})
			} else {
				page.NewClasses = append(page.NewClasses, Option{c.ID.String(), fmt.Sprintf("%s (%s)", c.Name, c.Code)})
			}
			if !slices.Contains(branchSet, c.BranchID) {
				branchSet = append(branchSet, c.BranchID)
			}
		}
		if needle == "" || strings.Contains(strings.ToLower(c.Name+" "+c.Code), needle) {
			teacher := c.TeacherName
			if teacher == nil {
				teacher = c.ForeignTeacherName
			}
			page.ListClasses = append(page.ListClasses, ScheduleListClass{
				ID: c.ID, Name: c.Name, Code: c.Code, ScheduleText: c.ScheduleText, Teacher: teacher, Status: c.Status,
				StatusLabel: statusMeta(c.Status).Label, HasConfig: c.HasSlots, Room: c.Room,
			})
		}
	}
	slices.Sort(years)
	for _, y := range years {
		page.AcademicYears = append(page.AcademicYears, Option{y, "Năm học " + y})
	}
	if page.Rooms, err = s.roomOptions(ctx, branchSet, nil); err != nil {
		return nil, err
	}

	// Giá trị điền sẵn: lớp đang chọn (đã có TKB) hoặc mặc định.
	today := dateOf(now)
	page.Initial = ScheduleData{
		AcademicYear: fmt.Sprintf("%d - %d", now.Year(), now.Year()+1), StartDate: today.Format(time.DateOnly),
		EndDate: today.AddDate(0, 3, 0).Format(time.DateOnly), Slot1Day: "Thứ 2", Slot1Start: "18:00", Slot1End: "19:30",
		Slot2Start: "18:00", Slot2End: "19:30",
	}
	if classID != nil {
		if d, ok := page.ScheduleData[classID.String()]; ok {
			page.Initial.ClassID = classID.String()
			fill := func(dst *string, v string) {
				if v != "" {
					*dst = v
				}
			}
			fill(&page.Initial.AcademicYear, d.AcademicYear)
			fill(&page.Initial.StartDate, d.StartDate)
			fill(&page.Initial.EndDate, d.EndDate)
			fill(&page.Initial.Slot1Day, d.Slot1Day)
			fill(&page.Initial.Slot1Start, d.Slot1Start)
			fill(&page.Initial.Slot1End, d.Slot1End)
			fill(&page.Initial.Slot2Day, d.Slot2Day)
			fill(&page.Initial.Slot2Start, d.Slot2Start)
			fill(&page.Initial.Slot2End, d.Slot2End)
			page.Initial.RoomID, page.Initial.Status, page.Initial.BranchID = d.RoomID, d.Status, d.BranchID
		}
	}

	hs, err := s.q.HolidaySessionsUpcoming(ctx, classesdb.HolidaySessionsUpcomingParams{Today: today, AllClasses: sc.all, Ids: sc.ids})
	if err != nil {
		return nil, err
	}
	for _, h := range hs {
		row := HolidaySession{ID: h.ID, ClassName: h.ClassName, ClassCode: h.ClassCode, When: h.Date.Format("02/01/2006") + " " + h.StartTime, Holiday: h.HolidayName}
		if h.MakeupDate != nil {
			row.Makeup = ptr(h.MakeupDate.Format("02/01/2006") + " " + h.MakeupStart)
		}
		page.HolidaySessions = append(page.HolidaySessions, row)
	}

	rules, err := s.cat.ShiftRules(ctx)
	if err != nil {
		return nil, err
	}
	page.ShiftOptions, page.WeekdayRule = rules.Options(), rules.WeekdayFramesText()
	off, err := s.cat.ListShifts(ctx, actor)
	if err != nil {
		return nil, err
	}
	page.OffScheduleClasses = off.OffScheduleClasses
	return page, nil
}

type ScheduleInput struct {
	ClassID      *uuid.UUID `json:"class_id"`
	AcademicYear string     `json:"academic_year"`
	StartDate    string     `json:"start_date"`
	EndDate      string     `json:"end_date"`
	Slot1Day     string     `json:"slot1_day"`
	Slot1Start   string     `json:"slot1_start"`
	Slot1End     string     `json:"slot1_end"`
	Slot2Day     string     `json:"slot2_day"`
	Slot2Start   string     `json:"slot2_start"`
	Slot2End     string     `json:"slot2_end"`
	Activate     bool       `json:"activate"`      // lớp Sắp khai giảng: kích hoạt luôn khi lưu TKB
	RoomID       OptID      `json:"room_id"`       // vắng = giữ phòng hiện tại; rỗng = bỏ phòng
	TransferRoom bool       `json:"transfer_room"` // đã xác nhận chuyển phòng từ lớp chưa bắt đầu học
}

// slotsOf: ca có chọn ngày học (ca không chọn ngày coi như không có), kiểm khung giờ ca dạy và đặt tên ca.
func slotsOf(in ScheduleInput, rules *catalogs.ShiftRules) ([]slot, error) {
	raw := [][4]string{{in.Slot1Day, in.Slot1Start, in.Slot1End, "1"}, {in.Slot2Day, in.Slot2Start, in.Slot2End, "2"}}
	var out []slot
	for _, r := range raw {
		day := strings.TrimSpace(r[0])
		if day == "" {
			continue
		}
		wd := catalogs.WeekdayOf(day)
		if wd == 0 {
			return nil, iam.Invalid("Ngày học của ca " + r[3] + " không hợp lệ.")
		}
		start, end := strings.TrimSpace(r[1]), strings.TrimSpace(r[2])
		if start == "" {
			start = "18:00"
		}
		if end == "" {
			end = "19:30"
		}
		_, ok1 := hm(start)
		_, ok2 := hm(end)
		if !ok1 || !ok2 {
			return nil, iam.Invalid("Giờ của ca " + r[3] + " không hợp lệ (định dạng HH:MM).")
		}
		if end <= start {
			return nil, iam.Invalid("Ca " + r[3] + ": giờ kết thúc phải sau giờ bắt đầu.")
		}
		if v := rules.Violation(day, start, end); v != "" {
			return nil, iam.Invalid(v)
		}
		out = append(out, slot{Weekday: wd, Start: start, End: end, Name: rules.NameFor(day, start, end)})
	}
	if len(out) == 0 {
		return nil, iam.Invalid("Cần chọn ít nhất một ca học (ngày trong tuần).")
	}
	if len(out) == 2 && out[0].Weekday == out[1].Weekday && out[0].Start < out[1].End && out[1].Start < out[0].End {
		return nil, iam.Invalid(fmt.Sprintf("Hai ca học trùng ngày %s và chồng giờ (%s-%s và %s-%s).",
			catalogs.DayName(out[0].Weekday), out[0].Start, out[0].End, out[1].Start, out[1].End))
	}
	return out, nil
}

// roomSlotConflict: lớp khác đang gắn phòng có ca học tuần chồng giờ với ca của lớp này.
type roomSlotConflict struct {
	ClassID  uuid.UUID
	Name     string
	Slot     string
	Studying bool
}

func (s *Service) roomSlotConflicts(ctx context.Context, roomID, exclude uuid.UUID, ours []slot) ([]roomSlotConflict, error) {
	open, err := s.q.RoomOpenClasses(ctx, classesdb.RoomOpenClassesParams{RoomIds: []uuid.UUID{roomID}, Exclude: &exclude})
	if err != nil || len(open) == 0 {
		return nil, err
	}
	ids := make([]uuid.UUID, 0, len(open))
	for _, c := range open {
		ids = append(ids, c.ID)
	}
	rows, err := s.q.ListSlots(ctx, ids)
	if err != nil {
		return nil, err
	}
	today := s.today()
	var out []roomSlotConflict
	for _, c := range open {
	theirs:
		for _, r := range rows {
			if r.ClassID != c.ID {
				continue
			}
			for _, o := range ours {
				if int(r.Weekday) == o.Weekday && r.StartTime < o.End && r.EndTime > o.Start {
					t := slot{Weekday: int(r.Weekday), Start: r.StartTime, End: r.EndTime}
					out = append(out, roomSlotConflict{c.ID, c.Name, t.label(), isStudying(c.Status, c.StartDate, today)})
					break theirs
				}
			}
		}
	}
	return out, nil
}

// SaveSchedule: lưu ca học tuần và sinh buổi học thực tế.
func (s *Service) SaveSchedule(ctx context.Context, actor *iam.Access, in ScheduleInput) (string, error) {
	if in.ClassID == nil {
		return "", iam.Invalid("Vui lòng chọn lớp.")
	}
	c, err := s.q.GetClass(ctx, *in.ClassID)
	if isNoRows(err) {
		return "", iam.Invalid("Lớp đã chọn không tồn tại trong hệ thống.")
	}
	if err != nil {
		return "", err
	}
	sc, err := s.visible(ctx, actor)
	if err != nil {
		return "", err
	}
	if !sc.has(c.ID) {
		return "", forbidden("Bạn không phụ trách lớp này.")
	}
	in.AcademicYear = strings.TrimSpace(in.AcademicYear)
	if tooLong(in.AcademicYear, 30) {
		return "", iam.Invalid("Năm học không được vượt quá 30 ký tự.")
	}
	today := s.today()
	start := today
	if c.StartDate != nil {
		start = *c.StartDate
	}
	if d, ok, err := parseOptDate(&in.StartDate, "Ngày bắt đầu không hợp lệ."); err != nil {
		return "", err
	} else if ok {
		start = *d
	}
	end := start.AddDate(0, 3, 0)
	if c.EndDate != nil {
		end = *c.EndDate
	}
	if d, ok, err := parseOptDate(&in.EndDate, "Ngày kết thúc không hợp lệ."); err != nil {
		return "", err
	} else if ok {
		end = *d
	}
	if strings.TrimSpace(in.EndDate) != "" && end.Before(start) {
		return "", iam.Invalid("Ngày kết thúc phải sau hoặc bằng ngày bắt đầu.")
	}
	rules, err := s.cat.ShiftRules(ctx)
	if err != nil {
		return "", err
	}
	slots, err := slotsOf(in, rules)
	if err != nil {
		return "", err
	}

	// Không bao giờ sinh lại buổi quá khứ: chỉ xếp từ hôm nay. Buổi đã có dữ liệu thực tế được giữ nguyên, không sinh trùng.
	from := start
	if from.Before(today) {
		from = today
	}
	replaceable, err := s.q.ReplaceableSessionIDs(ctx, classesdb.ReplaceableSessionIDsParams{ClassID: c.ID, Today: today})
	if err != nil {
		return "", err
	}
	replaceable = nonNil(replaceable)
	kept, err := s.q.KeptSessionsFrom(ctx, classesdb.KeptSessionsFromParams{ClassID: c.ID, FromDate: from, Exclude: replaceable})
	if err != nil {
		return "", err
	}
	generated, err := s.generate(ctx, slots, from, end, c.BranchID)
	if err != nil {
		return "", err
	}
	sessions := slices.DeleteFunc(generated, func(p planned) bool {
		return slices.ContainsFunc(kept, func(k classesdb.KeptSessionsFromRow) bool {
			return k.Date.Equal(p.Date) && (deref(k.ShiftName) == p.Shift || (k.StartTime < p.End && k.EndTime > p.Start))
		})
	})
	if len(sessions) > 300 {
		return "", iam.Invalid(fmt.Sprintf("Thời khóa biểu quá dài (%d buổi). Giới hạn tối đa 300 buổi mỗi lần xếp lịch.", len(sessions)))
	}
	if len(sessions) == 0 && len(kept) == 0 {
		return "", iam.Invalid("Không tạo được buổi học nào. Kiểm tra lại khoảng thời gian (chỉ xếp được từ hôm nay trở đi), ngày học trong tuần và lịch nghỉ lễ.")
	}

	// Phòng học: gửi room_id (rỗng = bỏ phòng) thì đổi phòng của lớp; không gửi thì giữ phòng hiện tại.
	roomID, roomName := c.RoomID, c.Room
	var room *classesdb.GetRoomRow
	if in.RoomID.Set {
		if room, err = s.roomForBranch(ctx, in.RoomID.ID, c.BranchID, "Phòng học không thuộc chi nhánh của lớp, vui lòng chọn lại."); err != nil {
			return "", err
		}
		roomID, roomName = nil, nil
		if room != nil {
			roomID, roomName = &room.ID, &room.Name
		}
	}
	previousRoom := c.Room

	// Ca học tuần trùng với lớp khác đang gắn phòng: lớp đang học → chặn; lớp chưa bắt đầu học → hỏi chuyển phòng.
	var transfer []roomSlotConflict
	if room != nil {
		conflicts, err := s.roomSlotConflicts(ctx, room.ID, c.ID, slots)
		if err != nil {
			return "", err
		}
		for _, x := range conflicts {
			if x.Studying {
				return "", iam.Invalid(fmt.Sprintf("%s đã có lớp %s đang học vào %s — vui lòng chọn phòng khác.", room.Name, x.Name, x.Slot))
			}
		}
		transfer = conflicts
		if len(transfer) > 0 && !in.TransferRoom {
			names := make([]string, 0, len(transfer))
			lines := []string{}
			for _, x := range transfer {
				names = append(names, x.Name)
				lines = append(lines, fmt.Sprintf("%s — dự kiến %s: sẽ bị hủy gán phòng", x.Name, x.Slot))
			}
			msg := fmt.Sprintf("%s đang được gán cho lớp %s (chưa bắt đầu học). Chuyển phòng sang lớp này?", room.Name, strings.Join(names, ", "))
			return "", &iam.Error{Status: 409, Code: "room_transfer", Message: msg + "\n" + strings.Join(lines, "\n")}
		}
	}

	// Lớp chưa xếp lịch được kích hoạt khi có TKB; lớp sắp khai giảng chỉ kích hoạt khi chọn rõ.
	status := c.Status
	if c.Status == StatusPendingSchedule || (c.Status == StatusUpcoming && in.Activate) {
		status = StatusActive
	}
	year := in.AcademicYear
	if year == "" {
		year = fmt.Sprintf("%d - %d", start.Year(), end.Year())
	}
	var texts []string
	for _, sl := range slots {
		texts = append(texts, fmt.Sprintf("%s %s %s-%s", catalogs.DayName(sl.Weekday), sl.Name, sl.Start, sl.End))
	}
	scheduleText := strings.Join(texts, "; ")

	err = s.tx(ctx, func(_ pgx.Tx, q *classesdb.Queries) error {
		// Chuyển phòng đã xác nhận: gỡ phòng khỏi lớp chưa bắt đầu học trước khi kiểm tra trùng buổi.
		for _, x := range transfer {
			if err := detachRoom(ctx, q, x.ClassID, room.Name, today); err != nil {
				return err
			}
		}
		withRoom := make([]planned, len(sessions))
		for i, p := range sessions {
			p.Room = roomName
			withRoom[i] = p
		}
		hit, err := findConflict(ctx, q, withRoom, c.BranchID, []*uuid.UUID{c.TeacherID, c.AssistantID, c.ForeignTeacherID}, roomName, &c.ID)
		if err != nil {
			return err
		}
		if hit != nil {
			return iam.Invalid("Xung đột " + hit.where() + ".")
		}
		if err := q.DeleteSlots(ctx, c.ID); err != nil {
			return err
		}
		for i, sl := range slots {
			if err := q.InsertSlot(ctx, classesdb.InsertSlotParams{
				ClassID: c.ID, Position: int16(i + 1), Weekday: int16(sl.Weekday), StartTime: sl.Start, EndTime: sl.End, ShiftName: sl.Name,
			}); err != nil {
				return err
			}
		}
		if len(replaceable) > 0 {
			if err := q.DeleteSessions(ctx, replaceable); err != nil {
				return err
			}
		}
		// Buổi sắp tới được giữ lại (học bù…) đang dùng phòng cũ của lớp cũng chuyển sang phòng mới.
		if deref(roomName) != deref(previousRoom) {
			syncable, err := q.StaffSyncableSessions(ctx, classesdb.StaffSyncableSessionsParams{ClassID: c.ID, Today: today})
			if err != nil {
				return err
			}
			if len(syncable) > 0 {
				if err := q.SetSessionsRoom(ctx, classesdb.SetSessionsRoomParams{Room: roomName, Ids: sessionIDs(syncable), PreviousRoom: previousRoom}); err != nil {
					return err
				}
			}
		}
		for _, p := range sessions {
			if _, err := q.InsertSession(ctx, classesdb.InsertSessionParams{
				ClassID: c.ID, BranchID: c.BranchID, Date: p.Date, ShiftName: &p.Shift, Type: "regular", StartTime: p.Start, EndTime: p.End,
				Room: roomName, TeacherID: coalesceID(c.TeacherID, c.ForeignTeacherID), ForeignTeacherID: c.ForeignTeacherID,
				AssistantID: c.AssistantID, CreatedBy: &actor.UserID,
			}); err != nil {
				return err
			}
		}
		return q.SetClassSchedule(ctx, classesdb.SetClassScheduleParams{
			ID: c.ID, StartDate: &start, EndDate: &end, ScheduleText: &scheduleText, AcademicYear: &year, Status: status,
			RoomID: roomID, Room: roomName,
		})
	})
	if err != nil {
		return "", err
	}
	s.log.Info("class schedule saved", "class_id", c.ID, "sessions", len(sessions), "replaced", len(replaceable), "by", actor.UserID)
	return fmt.Sprintf("Đã lưu lịch và tạo %d buổi học thực tế.", len(sessions)), nil
}

// ToggleResult: thông báo + cảnh báo (lớp mở lại chưa có phòng).
type ToggleResult struct {
	Message string  `json:"message"`
	Warning *string `json:"warning,omitempty"`
}

// ToggleStatus: Đang học ↔ Đã kết thúc. Kết thúc lớp giải phóng phòng (buổi đã sinh giữ nguyên phòng).
func (s *Service) ToggleStatus(ctx context.Context, actor *iam.Access, classID uuid.UUID) (*ToggleResult, error) {
	if !actor.Can("class.update") {
		return nil, forbidden("Bạn không có quyền đổi trạng thái lớp học.")
	}
	c, err := s.classFor(ctx, actor, classID, "class.update")
	if err != nil {
		return nil, err
	}
	if c.Status != StatusActive && c.Status != StatusCompleted {
		return nil, iam.Invalid(fmt.Sprintf("Lớp %s đang ở trạng thái '%s', chỉ lớp Đang học/Đã kết thúc mới đổi trạng thái được tại đây.", c.Name, statusMeta(c.Status).Label))
	}
	completing := c.Status == StatusActive
	status, roomID, room := StatusActive, c.RoomID, c.Room
	if completing {
		status, roomID, room = StatusCompleted, nil, nil
	}
	if err := s.q.SetClassStatusRoom(ctx, classesdb.SetClassStatusRoomParams{ID: c.ID, Status: status, RoomID: roomID, Room: room}); err != nil {
		return nil, err
	}
	s.log.Info("class status toggled", "class_id", c.ID, "status", status, "by", actor.UserID)
	if completing {
		msg := fmt.Sprintf("Đã kết thúc lớp %s.", c.Name)
		if c.Room != nil && *c.Room != "" {
			msg += fmt.Sprintf(" %s đã được giải phóng khỏi lớp này.", *c.Room)
		}
		return &ToggleResult{Message: msg}, nil
	}
	out := &ToggleResult{Message: fmt.Sprintf("Đã mở lại lớp %s.", c.Name)}
	if c.Room == nil || *c.Room == "" {
		out.Warning = ptr("Lớp chưa có phòng học — vui lòng gán lại phòng ở Lịch & TKB lớp hoặc Sửa lớp.")
	}
	return out, nil
}
