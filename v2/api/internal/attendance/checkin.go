package attendance

import (
	"context"
	"errors"
	"fmt"
	"math"
	"slices"
	"strings"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/attendance/attendancedb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/files"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/filestore"
)

// Chấm công của tôi (MyAttendanceController::home / punch / history, StaffAttendanceService::punch / recalculate /
// syncLatePenalty / summary).

const (
	KindIn  = "in"
	KindOut = "out"

	SourceMobile  = "mobile"
	SourceRequest = "request"
)

// PhotoPolicy: ảnh khuôn mặt (jpeg, jpg, png, webp; tối đa 6 MB).
var PhotoPolicy = filestore.Policy{
	Types:    []string{"jpg", "jpeg", "png", "webp"},
	MaxBytes: photoMaxBytes,
	TooLarge: "Ảnh quá lớn (tối đa 6 MB), hãy chụp lại.",
	Required: "Hãy chụp ảnh khuôn mặt trước khi chấm công.",
}

// ───────────── Một ngày công ─────────────

// Day: dữ liệu một ngày công cho màn Chấm công / Lịch sử công (attendanceData).
type Day struct {
	ID              uuid.UUID   `json:"id"`
	Date            string      `json:"date"`
	CheckIn         *string     `json:"check_in"`
	CheckOut        *string     `json:"check_out"`
	CheckInPhoto    *string     `json:"check_in_photo"`
	CheckOutPhoto   *string     `json:"check_out_photo"`
	CheckInDistance *int32      `json:"check_in_distance"`
	ExpectedStart   *string     `json:"expected_start"`
	ExpectedEnd     *string     `json:"expected_end"`
	LateMinutes     int         `json:"late_minutes"`
	EarlyMinutes    int         `json:"early_minutes"`
	LateExcused     bool        `json:"late_excused"`
	StatusLabel     string      `json:"status_label"`
	StatusTone      string      `json:"status_tone"`
	Source          string      `json:"source"`
	Penalty         *PenaltyRef `json:"penalty"`
}

// isLate: đi muộn tính lỗi (quá ngưỡng cho phép và không có đơn được duyệt).
func isLate(a attendancedb.HrStaffAttendance) bool { return a.LateMinutes > 0 && !a.LateExcused }

// statusLabel: StaffAttendance::statusLabel.
func statusLabel(a attendancedb.HrStaffAttendance) string {
	switch {
	case a.CheckInAt == nil:
		return "Chưa vào"
	case a.LateMinutes > 0 && a.LateExcused:
		return "Muộn có phép"
	case a.LateMinutes > 0:
		return fmt.Sprintf("Đi muộn %d phút", a.LateMinutes)
	case a.CheckOutAt == nil:
		return "Chưa ra"
	case a.EarlyMinutes > 0:
		return fmt.Sprintf("Về sớm %d phút", a.EarlyMinutes)
	}
	return "Đúng giờ"
}

// statusTone: StaffAttendance::statusTone.
func statusTone(a attendancedb.HrStaffAttendance) string {
	switch {
	case a.CheckInAt == nil:
		return "neutral"
	case isLate(a):
		return "error"
	case a.LateMinutes > 0, a.EarlyMinutes > 0, a.CheckOutAt == nil:
		return "warning"
	}
	return "success"
}

func photoURL(id *uuid.UUID) *string {
	if id == nil {
		return nil
	}
	return ptr(files.URL(*id))
}

func clockPtr(t *time.Time) *string {
	if t == nil {
		return nil
	}
	return ptr(clock(*t))
}

func toDay(a attendancedb.HrStaffAttendance, pen map[uuid.UUID]PenaltyRef) Day {
	d := Day{
		ID: a.ID, Date: isoDate(a.WorkDate), CheckIn: clockPtr(a.CheckInAt), CheckOut: clockPtr(a.CheckOutAt),
		CheckInPhoto: photoURL(a.CheckInPhotoID), CheckOutPhoto: photoURL(a.CheckOutPhotoID), CheckInDistance: a.CheckInDistance,
		ExpectedStart: hm(a.ExpectedStart), ExpectedEnd: hm(a.ExpectedEnd), LateMinutes: int(a.LateMinutes),
		EarlyMinutes: int(a.EarlyMinutes), LateExcused: a.LateExcused, StatusLabel: statusLabel(a), StatusTone: statusTone(a),
		Source: a.Source,
	}
	if a.PenaltyID != nil {
		if p, ok := pen[*a.PenaltyID]; ok && p.Code != "" {
			d.Penalty = &p
		}
	}
	return d
}

// penaltyRefs: mã biên bản của các dòng chấm công.
func (s *Service) penaltyRefs(ctx context.Context, rows []attendancedb.HrStaffAttendance) (map[uuid.UUID]PenaltyRef, error) {
	var ids []uuid.UUID
	for _, r := range rows {
		if r.PenaltyID != nil {
			ids = append(ids, *r.PenaltyID)
		}
	}
	if len(ids) == 0 {
		return map[uuid.UUID]PenaltyRef{}, nil
	}
	return s.penalties.Refs(ctx, ids)
}

// ───────────── Chi nhánh & giờ phải có mặt ─────────────

// BranchInfo: chi nhánh chính của nhân sự (màn Chấm công của tôi).
type BranchInfo struct {
	ID          uuid.UUID `json:"id"`
	Name        string    `json:"name"`
	Address     *string   `json:"address"`
	Latitude    *float64  `json:"latitude"`
	Longitude   *float64  `json:"longitude"`
	Radius      int       `json:"radius"`
	Grace       int       `json:"grace"`
	HasLocation bool      `json:"has_location"`
	workStart   *string
	workEnd     *string
}

func branchOf(c attendancedb.StaffContextRow) *BranchInfo {
	if c.BranchID == nil {
		return nil
	}
	b := &BranchInfo{ID: *c.BranchID, Name: deref(c.BranchName), Address: c.BranchAddress, Latitude: c.Latitude,
		Longitude: c.Longitude, Radius: DefaultRadius, workStart: hm(c.WorkStartTime), workEnd: hm(c.WorkEndTime)}
	if c.CheckinRadius != nil && *c.CheckinRadius > 0 {
		b.Radius = int(*c.CheckinRadius)
	}
	if c.LateGraceMinutes != nil {
		b.Grace = int(*c.LateGraceMinutes)
	}
	b.HasLocation = c.Latitude != nil && c.Longitude != nil
	return b
}

func (s *Service) branchByID(ctx context.Context, id *uuid.UUID) (*BranchInfo, error) {
	if id == nil {
		return nil, nil
	}
	r, err := s.q.BranchCheckin(ctx, *id)
	if isNoRows(err) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	return branchOf(attendancedb.StaffContextRow{BranchID: &r.ID, BranchName: &r.Name, Latitude: r.Latitude,
		Longitude: r.Longitude, CheckinRadius: &r.CheckinRadius, WorkStartTime: r.WorkStartTime, WorkEndTime: r.WorkEndTime,
		LateGraceMinutes: &r.LateGraceMinutes}), nil
}

// distanceMeters: khoảng cách đường chim bay (haversine), mét — App\Support\Geo.
func distanceMeters(lat1, lng1, lat2, lng2 float64) float64 {
	const earth = 6371000.0
	rad := func(d float64) float64 { return d * math.Pi / 180 }
	dLat, dLng := rad(lat2-lat1), rad(lng2-lng1)
	a := math.Pow(math.Sin(dLat/2), 2) + math.Cos(rad(lat1))*math.Cos(rad(lat2))*math.Pow(math.Sin(dLng/2), 2)
	return earth * 2 * math.Atan2(math.Sqrt(a), math.Sqrt(1-a))
}

// ExpectedSession: một buổi dạy trong ngày (giờ đối chiếu của GV / TA).
type ExpectedSession struct {
	Shift *string `json:"shift"`
	Time  string  `json:"time"`
	Class string  `json:"class"`
}

// Expected: giờ phải có mặt / được về trong ngày ("HH:MM", nil = không tính muộn / về sớm).
type Expected struct {
	Start    *string           `json:"start"`
	End      *string           `json:"end"`
	Basis    string            `json:"basis"`
	Sessions []ExpectedSession `json:"sessions"`
}

// shiftLabel: ClassSession::shiftLabel (tên "Slot…" cũ không hiện).
func shiftLabel(name *string) *string {
	n := strings.TrimSpace(deref(name))
	if n == "" || strings.HasPrefix(n, "Slot") {
		return nil
	}
	return &n
}

// expectedTimes: GV / TA (cổng giáo viên / trợ giảng) theo buổi dạy trong ngày, nhân sự khác theo giờ làm việc chi nhánh.
func (s *Service) expectedTimes(ctx context.Context, q *attendancedb.Queries, a *iam.Access, branch *BranchInfo, day time.Time) (Expected, error) {
	if a != nil && a.CanAny("portal.teacher", "portal.assistant") {
		rows, err := q.ExpectedSessions(ctx, attendancedb.ExpectedSessionsParams{Day: sqlDate(day), UserID: &a.UserID})
		if err != nil {
			return Expected{}, err
		}
		if len(rows) == 0 {
			return Expected{Basis: "Không có buổi dạy", Sessions: []ExpectedSession{}}, nil
		}
		e := Expected{Basis: "Theo giờ lớp được phân công", Sessions: []ExpectedSession{}}
		for _, r := range rows {
			start, end := hmText(r.StartTime), hmText(r.EndTime)
			if e.Start == nil || start < *e.Start {
				e.Start = ptr(start)
			}
			if e.End == nil || end > *e.End {
				e.End = ptr(end)
			}
			class := r.ClassCode
			if class == "" {
				class = r.ClassName
			}
			e.Sessions = append(e.Sessions, ExpectedSession{Shift: shiftLabel(r.ShiftName), Time: start + "–" + end, Class: class})
		}
		return e, nil
	}
	e := Expected{Basis: "Theo giờ làm việc cơ sở", Sessions: []ExpectedSession{}}
	if branch != nil {
		e.Start, e.End = branch.workStart, branch.workEnd
	}
	return e, nil
}

// recalculate: giờ phải có mặt, số phút muộn / về sớm và "muộn có phép" của dòng (chưa lưu).
func (s *Service) recalculate(ctx context.Context, q *attendancedb.Queries, a *iam.Access, branch *BranchInfo, row *attendancedb.HrStaffAttendance) error {
	day := dateOnly(row.WorkDate)
	exp, err := s.expectedTimes(ctx, q, a, branch, day)
	if err != nil {
		return err
	}
	row.ExpectedStart, row.ExpectedEnd = pgTime(exp.Start), pgTime(exp.End)
	late := 0
	if exp.Start != nil && row.CheckInAt != nil {
		minutes := int(math.Floor(row.CheckInAt.Sub(at(day, *exp.Start)).Minutes()))
		grace := 0
		if branch != nil {
			grace = branch.Grace
		}
		if minutes > grace {
			late = minutes
		}
	}
	early := 0
	if exp.End != nil && row.CheckOutAt != nil {
		early = max(0, int(math.Floor(at(day, *exp.End).Sub(*row.CheckOutAt).Minutes())))
	}
	row.LateMinutes, row.EarlyMinutes = int32(min(late, 65535)), int32(min(early, 65535))
	excused := false
	for _, t := range []string{TypeLateEarly, TypeLeave} {
		ok, err := q.HasApproved(ctx, attendancedb.HasApprovedParams{UserID: row.UserID, Type: t, Day: sqlDate(day)})
		if err != nil {
			return err
		}
		excused = excused || ok
	}
	row.LateExcused = excused
	return nil
}

// save: ghi dòng chấm công (thêm mới hoặc cập nhật theo người + ngày).
func save(ctx context.Context, q *attendancedb.Queries, r attendancedb.HrStaffAttendance) (attendancedb.HrStaffAttendance, error) {
	return q.UpsertAttendance(ctx, attendancedb.UpsertAttendanceParams{
		UserID: r.UserID, BranchID: r.BranchID, WorkDate: sqlDate(dateOnly(r.WorkDate)), Source: r.Source,
		ExpectedStart: hm(r.ExpectedStart), ExpectedEnd: hm(r.ExpectedEnd),
		CheckInAt: r.CheckInAt, CheckInPhotoID: r.CheckInPhotoID, CheckInLat: r.CheckInLat, CheckInLng: r.CheckInLng,
		CheckInDistance: r.CheckInDistance, CheckInAccuracy: r.CheckInAccuracy,
		CheckOutAt: r.CheckOutAt, CheckOutPhotoID: r.CheckOutPhotoID, CheckOutLat: r.CheckOutLat, CheckOutLng: r.CheckOutLng,
		CheckOutDistance: r.CheckOutDistance, CheckOutAccuracy: r.CheckOutAccuracy,
		LateMinutes: r.LateMinutes, EarlyMinutes: r.EarlyMinutes, LateExcused: r.LateExcused, PenaltyID: r.PenaltyID, Note: r.Note,
	})
}

// syncLatePenalty: biên bản tự động khi đi muộn — lập mới / cập nhật khi còn chờ giải trình; không còn muộn thì tự hủy.
func (s *Service) syncLatePenalty(ctx context.Context, tx pgx.Tx, q *attendancedb.Queries, row *attendancedb.HrStaffAttendance) error {
	day := dateOnly(row.WorkDate)
	if isLate(*row) {
		in := LatePenalty{
			AttendanceID: row.ID, UserID: row.UserID, BranchID: row.BranchID, WorkDate: day, LateMinutes: int(row.LateMinutes),
			Violation: fmt.Sprintf("Đi muộn %d phút (chấm công ngày %s)", row.LateMinutes, fmtDate(day)),
			Notes: "Tự động từ chấm công: giờ vào " + deref(clockPtr(row.CheckInAt)) + ", giờ phải có mặt " +
				hmText(row.ExpectedStart) + ".",
		}
		ref, err := s.penalties.Late(ctx, tx, row.PenaltyID, in)
		if err != nil {
			return err
		}
		var id *uuid.UUID
		if ref != nil {
			id = &ref.ID
		}
		if !slices.Equal(uuidList(id), uuidList(row.PenaltyID)) {
			if err := q.SetAttendancePenalty(ctx, attendancedb.SetAttendancePenaltyParams{ID: row.ID, PenaltyID: id}); err != nil {
				return err
			}
			row.PenaltyID = id
		}
		if ref != nil && ref.Created {
			_, err := notify.Send(ctx, tx, []uuid.UUID{row.UserID}, notify.Notification{
				Type:  notifyPenalty,
				Title: "Biên bản " + ref.Code + ": " + in.Violation,
				Body:  `Nếu có lý do, hãy gửi giải trình hoặc đơn "Xin đi muộn / về sớm" cho ngày này.`,
				Link:  "/penalties?search=" + ref.Code,
			})
			return err
		}
		return nil
	}
	if row.PenaltyID == nil {
		return nil
	}
	note := "Tự hủy: giờ chấm công đã sửa, không còn đi muộn."
	if row.LateExcused {
		note = "Tự hủy: đã duyệt đơn xin đi muộn / nghỉ cho ngày này."
	}
	return s.penalties.Excused(ctx, tx, *row.PenaltyID, note)
}

func uuidList(id *uuid.UUID) []uuid.UUID {
	if id == nil {
		return nil
	}
	return []uuid.UUID{*id}
}

// ───────────── Màn Chấm công của tôi ─────────────

// HomePage: chấm công hôm nay.
type HomePage struct {
	Branch     *BranchInfo `json:"branch"`
	Expected   Expected    `json:"expected"`
	Today      *Day        `json:"today"`
	ServerTime string      `json:"server_time"`
	Locked     *string     `json:"locked"`
}

func (s *Service) Home(ctx context.Context, a *iam.Access) (*HomePage, error) {
	now := s.now()
	c, err := s.q.StaffContext(ctx, a.UserID)
	if err != nil {
		return nil, err
	}
	out := &HomePage{Branch: branchOf(c), ServerTime: now.In(vnZone).Format(time.RFC3339)}
	if out.Expected, err = s.expectedTimes(ctx, s.q, a, out.Branch, now); err != nil {
		return nil, err
	}
	row, err := s.q.AttendanceForDay(ctx, attendancedb.AttendanceForDayParams{UserID: a.UserID, WorkDate: sqlDate(now)})
	if err != nil && !isNoRows(err) {
		return nil, err
	}
	if err == nil {
		pen, err := s.penaltyRefs(ctx, []attendancedb.HrStaffAttendance{row})
		if err != nil {
			return nil, err
		}
		d := toDay(row, pen)
		out.Today = &d
	}
	l, err := s.locks(ctx)
	if err != nil {
		return nil, err
	}
	if l.has(now) {
		out.Locked = ptr(lockedMessage(now))
	}
	return out, nil
}

// PunchInput: một lượt chấm công (giờ lấy theo máy chủ, không nhận giờ từ máy người dùng).
type PunchInput struct {
	Kind      string
	Latitude  *float64
	Longitude *float64
	Accuracy  *float64
	Photo     *filestore.Upload
}

// PunchResult: thông báo sau khi chấm (warning = đi muộn).
type PunchResult struct {
	Message string `json:"message"`
	Warning bool   `json:"warning"`
	Today   Day    `json:"today"`
}

func validatePunch(in PunchInput) error {
	if in.Kind != KindIn && in.Kind != KindOut {
		return iam.Invalid("Loại chấm công không hợp lệ.")
	}
	if in.Latitude == nil {
		return iam.Invalid("Chưa lấy được vị trí GPS của điện thoại. Hãy bật định vị và cho phép trình duyệt truy cập vị trí.")
	}
	if in.Longitude == nil {
		return iam.Invalid("Chưa lấy được vị trí GPS của điện thoại.")
	}
	if math.Abs(*in.Latitude) > 90 || math.Abs(*in.Longitude) > 180 || math.IsNaN(*in.Latitude) || math.IsNaN(*in.Longitude) {
		return iam.Invalid("Vị trí GPS không hợp lệ.")
	}
	if in.Accuracy != nil && (*in.Accuracy < 0 || math.IsNaN(*in.Accuracy)) {
		return iam.Invalid("Sai số GPS không hợp lệ.")
	}
	if in.Photo == nil {
		return iam.Invalid(PhotoPolicy.Required)
	}
	if err := in.Photo.Check(PhotoPolicy); err != nil {
		if !filestore.Allowed(in.Photo.Kind.Ext, PhotoPolicy.Types) && in.Photo.Size > 0 && in.Photo.Size <= PhotoPolicy.MaxBytes {
			return iam.Invalid("Ảnh chụp không hợp lệ, hãy chụp lại.")
		}
		return iam.Invalid(err.Error())
	}
	return nil
}

// Punch: chấm công vào / ra cho chính mình tại chi nhánh chính, GPS phải trong bán kính chi nhánh.
func (s *Service) Punch(ctx context.Context, a *iam.Access, in PunchInput) (*PunchResult, error) {
	if err := validatePunch(in); err != nil {
		return nil, err
	}
	if s.files == nil {
		return nil, errNoFiles
	}
	now := s.now()
	c, err := s.q.StaffContext(ctx, a.UserID)
	if err != nil {
		return nil, err
	}
	branch := branchOf(c)
	if branch == nil {
		return nil, iam.Invalid("Tài khoản của bạn chưa được gán cơ sở làm việc. Hãy báo Admin / Quản lý cơ sở.")
	}
	if !branch.HasLocation {
		return nil, iam.Invalid("Cơ sở " + branch.Name + " chưa cài toạ độ chấm công. Hãy báo Admin vào Cài đặt → Cơ sở & chi nhánh → Chấm công.")
	}
	if err := s.ensureOpen(ctx, now); err != nil {
		return nil, err
	}
	distance := int32(math.Round(distanceMeters(*branch.Latitude, *branch.Longitude, *in.Latitude, *in.Longitude)))
	if int(distance) > branch.Radius {
		return nil, iam.Invalid(fmt.Sprintf("Bạn đang cách %s khoảng %s m, chỉ chấm công được trong bán kính %d m quanh cơ sở.",
			branch.Name, thousands(int(distance)), branch.Radius))
	}
	var accuracy *int32
	if in.Accuracy != nil {
		accuracy = ptr(int32(math.Round(*in.Accuracy)))
	}
	var row attendancedb.HrStaffAttendance
	err = s.inTx(ctx, func(tx pgx.Tx, q *attendancedb.Queries) error {
		existing, err := q.AttendanceForDayLock(ctx, attendancedb.AttendanceForDayLockParams{UserID: a.UserID, WorkDate: sqlDate(now)})
		found := err == nil
		if err != nil && !isNoRows(err) {
			return err
		}
		if in.Kind == KindIn && found && existing.CheckInAt != nil {
			return iam.Invalid("Hôm nay bạn đã chấm công vào lúc " + clock(*existing.CheckInAt) + ".")
		}
		if in.Kind == KindOut && (!found || existing.CheckInAt == nil) {
			return iam.Invalid(`Hôm nay bạn chưa chấm công vào. Nếu quên chấm vào, hãy gửi đơn "Bổ sung công".`)
		}
		row = existing
		if !found {
			row = attendancedb.HrStaffAttendance{UserID: a.UserID, WorkDate: sqlDate(now), Source: SourceMobile}
		}
		row.BranchID = &branch.ID
		var oldPhoto *uuid.UUID
		if in.Kind == KindIn {
			row.CheckInAt, row.CheckInLat, row.CheckInLng = &now, in.Latitude, in.Longitude
			row.CheckInDistance, row.CheckInAccuracy = &distance, accuracy
		} else {
			oldPhoto = row.CheckOutPhotoID
			row.CheckOutAt, row.CheckOutLat, row.CheckOutLng = &now, in.Latitude, in.Longitude
			row.CheckOutDistance, row.CheckOutAccuracy = &distance, accuracy
		}
		if err := s.recalculate(ctx, q, a, branch, &row); err != nil {
			return err
		}
		if row, err = save(ctx, q, row); err != nil {
			return err
		}
		verb := "Chấm công vào lúc "
		if in.Kind == KindOut {
			verb = "Chấm công ra lúc "
		}
		f, err := s.files.Save(ctx, tx, in.Photo, files.SaveInput{
			Owner: files.Owner{Module: "attendance", SubjectType: PhotoSubject, SubjectID: &row.ID, BranchID: &branch.ID},
			By:    &a.UserID,
			Name:  fmt.Sprintf("cham-cong-%s-%s.%s", in.Kind, now.In(vnZone).Format("20060102-1504"), in.Photo.Kind.Ext),
			Audit: audit.Entry{Module: audit.ModulePayroll, Event: "punch", SubjectType: "staff_attendance",
				SubjectID: row.ID.String(), SubjectLabel: c.FullName + " · " + fmtDate(now), BranchID: &branch.ID,
				Description: verb + clock(now) + " ngày " + fmtDate(now),
				Properties:  map[string]any{"kind": in.Kind, "distance": distance, "late_minutes": row.LateMinutes}},
		})
		if err != nil {
			return err
		}
		if in.Kind == KindIn {
			row.CheckInPhotoID = &f.ID
		} else {
			row.CheckOutPhotoID = &f.ID
		}
		if err := q.SetAttendancePhotos(ctx, attendancedb.SetAttendancePhotosParams{ID: row.ID,
			CheckInPhotoID: row.CheckInPhotoID, CheckOutPhotoID: row.CheckOutPhotoID}); err != nil {
			return err
		}
		// Chấm ra lần nữa (về muộn hơn) thay ảnh cũ.
		if oldPhoto != nil && *oldPhoto != f.ID {
			if _, err := s.files.Delete(ctx, tx, *oldPhoto, &a.UserID, audit.Entry{Module: audit.ModulePayroll,
				SubjectType: "staff_attendance", SubjectID: row.ID.String(), Description: "Thay ảnh chấm công ra ngày " + fmtDate(now)}); err != nil && !errors.Is(err, files.ErrNotFound) {
				return err
			}
		}
		return s.syncLatePenalty(ctx, tx, q, &row)
	})
	if err != nil {
		return nil, err
	}
	s.log.Info("staff punch", "user", a.UserID, "kind", in.Kind, "distance", distance, "late", row.LateMinutes, "by", a.UserID)
	pen, err := s.penaltyRefs(ctx, []attendancedb.HrStaffAttendance{row})
	if err != nil {
		return nil, err
	}
	out := &PunchResult{Today: toDay(row, pen)}
	if in.Kind == KindIn {
		out.Message = "Đã chấm công vào lúc " + clock(now) + "."
		if isLate(row) {
			out.Warning = true
			if row.PenaltyID != nil {
				out.Message += fmt.Sprintf(" Bạn đi muộn %d phút, hệ thống đã lập biên bản chờ giải trình.", row.LateMinutes)
			} else {
				out.Message += fmt.Sprintf(" Bạn đi muộn %d phút.", row.LateMinutes)
			}
		}
	} else {
		out.Message = "Đã chấm công ra lúc " + clock(now) + "."
	}
	return out, nil
}

// thousands: 1234 → "1.234" (number_format kiểu Việt Nam).
func thousands(n int) string {
	s := fmt.Sprint(n)
	neg := strings.HasPrefix(s, "-")
	s = strings.TrimPrefix(s, "-")
	var b strings.Builder
	for i, r := range s {
		if i > 0 && (len(s)-i)%3 == 0 {
			b.WriteByte('.')
		}
		b.WriteRune(r)
	}
	if neg {
		return "-" + b.String()
	}
	return b.String()
}

// ───────────── Tổng hợp ─────────────

// Summary: tổng hợp chấm công của một người trong khoảng ngày (phiếu lương, màn chấm công).
type Summary struct {
	Days        int `json:"days"`
	LateCount   int `json:"late_count"`
	LateMinutes int `json:"late_minutes"`
	ExcusedLate int `json:"excused_late"`
	EarlyCount  int `json:"early_count"`
	MissingOut  int `json:"missing_out"`
	LeaveDays   int `json:"leave_days"`
}

// overlapDays: số ngày của [from, to] nằm trong [start, end] (gồm 2 đầu).
func overlapDays(from, to, start, end time.Time) int {
	f, t := dateOnly(from), dateOnly(to)
	if f.Before(start) {
		f = start
	}
	if t.After(end) {
		t = end
	}
	if t.Before(f) {
		return 0
	}
	return int(t.Sub(f).Hours()/24+0.5) + 1
}

// summaries: Summary của nhiều người trong [start, end] (ngày giờ VN).
func (s *Service) summaries(ctx context.Context, ids []uuid.UUID, start, end time.Time) (map[uuid.UUID]Summary, error) {
	out := make(map[uuid.UUID]Summary, len(ids))
	for _, id := range ids {
		out[id] = Summary{}
	}
	if len(ids) == 0 {
		return out, nil
	}
	rows, err := s.q.RangeAttendances(ctx, attendancedb.RangeAttendancesParams{UserIds: ids, FromDate: sqlDate(start), ToDate: sqlDate(end)})
	if err != nil {
		return nil, err
	}
	today := dayOf(s.now())
	for _, r := range rows {
		sm := out[r.UserID]
		if r.CheckInAt != nil {
			sm.Days++
		}
		if isLate(r) {
			sm.LateCount++
			sm.LateMinutes += int(r.LateMinutes)
		}
		if r.LateMinutes > 0 && r.LateExcused {
			sm.ExcusedLate++
		}
		if r.EarlyMinutes > 0 {
			sm.EarlyCount++
		}
		if r.CheckInAt != nil && r.CheckOutAt == nil && dateOnly(r.WorkDate).Before(today) {
			sm.MissingOut++
		}
		out[r.UserID] = sm
	}
	leaves, err := s.q.ApprovedLeaves(ctx, attendancedb.ApprovedLeavesParams{UserIds: ids, FromDate: sqlDate(start), ToDate: sqlDate(end)})
	if err != nil {
		return nil, err
	}
	for _, l := range leaves {
		sm := out[l.UserID]
		sm.LeaveDays += overlapDays(l.DateFrom, l.DateTo, start, end)
		out[l.UserID] = sm
	}
	return out, nil
}

// ───────────── Lịch sử công ─────────────

// LeaveItem: một đơn nghỉ có phép trong tháng.
type LeaveItem struct {
	ID     uuid.UUID `json:"id"`
	Period string    `json:"period"`
	Reason string    `json:"reason"`
}

type HistoryPage struct {
	Month      string      `json:"month"`
	MonthLabel string      `json:"month_label"`
	PrevMonth  string      `json:"prev_month"`
	NextMonth  *string     `json:"next_month"`
	Summary    Summary     `json:"summary"`
	Rows       []Day       `json:"rows"`
	Leaves     []LeaveItem `json:"leaves"`
}

// History: lịch sử công theo tháng của chính mình.
func (s *Service) History(ctx context.Context, a *iam.Access, month string) (*HistoryPage, error) {
	now := s.now()
	start := parseMonth(month, now)
	end := monthEnd(start)
	thisMonth := parseMonth("", now)
	out := &HistoryPage{Month: monthKey(start), MonthLabel: monthLabel(start), PrevMonth: monthKey(start.AddDate(0, -1, 0)),
		Rows: []Day{}, Leaves: []LeaveItem{}}
	if next := start.AddDate(0, 1, 0); !next.After(thisMonth) {
		out.NextMonth = ptr(monthKey(next))
	}
	sums, err := s.summaries(ctx, []uuid.UUID{a.UserID}, start, end)
	if err != nil {
		return nil, err
	}
	out.Summary = sums[a.UserID]
	rows, err := s.q.UserAttendances(ctx, attendancedb.UserAttendancesParams{UserID: a.UserID, FromDate: sqlDate(start), ToDate: sqlDate(end)})
	if err != nil {
		return nil, err
	}
	pen, err := s.penaltyRefs(ctx, rows)
	if err != nil {
		return nil, err
	}
	for _, r := range rows {
		out.Rows = append(out.Rows, toDay(r, pen))
	}
	leaves, err := s.q.ApprovedLeaves(ctx, attendancedb.ApprovedLeavesParams{UserIds: []uuid.UUID{a.UserID}, FromDate: sqlDate(start), ToDate: sqlDate(end)})
	if err != nil {
		return nil, err
	}
	for _, l := range leaves {
		out.Leaves = append(out.Leaves, LeaveItem{ID: l.ID, Period: periodLabel(TypeLeave, l.DateFrom, l.DateTo, nil, nil), Reason: l.Reason})
	}
	return out, nil
}

// photoReader: ai mở được ảnh khuôn mặt — chính người chấm, hoặc người có staff_checkin.view trong phạm vi chi nhánh.
func (s *Service) photoReader(ctx context.Context, a *iam.Access, f files.File) (string, error) {
	r, err := s.q.AttendanceByPhoto(ctx, &f.ID)
	if isNoRows(err) {
		return "", files.ErrNotFound
	}
	if err != nil {
		return "", err
	}
	if !canSee(a, r.UserID, r.BranchID) {
		return "", iam.ErrForbidden
	}
	return "", nil
}

// canSee: StaffAttendanceController::canSee.
func canSee(a *iam.Access, userID uuid.UUID, branch *uuid.UUID) bool {
	return userID == a.UserID || (a.Can(PermCheckinView) && a.CoversBranch(scopeCheckin, branch))
}
