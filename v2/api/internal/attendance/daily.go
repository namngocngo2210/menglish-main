package attendance

import (
	"context"
	"fmt"
	"strings"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/attendance/attendancedb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// Chấm công hằng ngày (StaffAttendanceController::index / show): quản lý xem theo ngày ai đã vào / ra, đi muộn, chưa
// chấm, nghỉ phép; tổng hợp theo tháng (đối soát lương). Phạm vi: staff_checkin (branch / all).

// DailyStatuses: bộ lọc trạng thái màn theo ngày (StaffAttendanceController::STATUSES).
var DailyStatuses = []Option{
	{"checked_in", "Đã chấm vào"},
	{"late", "Đi muộn"},
	{"missing", "Chưa chấm công"},
	{"leave", "Nghỉ có phép"},
}

const dailyPerPage = 25

// Meta: phân trang.
type Meta struct {
	Page    int   `json:"page"`
	PerPage int   `json:"per_page"`
	Total   int64 `json:"total"`
}

func pageArgs(page, per, def int) (int, int, int32, int32) {
	if per <= 0 {
		per = def
	}
	per = min(per, 100)
	page = max(page, 1)
	return page, per, int32(per), int32((page - 1) * per)
}

// DailyFilter: bộ lọc màn Chấm công hằng ngày.
type DailyFilter struct {
	View     string // day | month
	Date     string
	Month    string
	BranchID string
	Status   string
	Search   string
	UserID   string
	Page     int
	PerPage  int
}

// StaffRef: nhân sự trên dòng.
type StaffRef struct {
	ID     uuid.UUID `json:"id"`
	Name   string    `json:"name"`
	Code   string    `json:"code"`
	Branch *string   `json:"branch"`
}

// DailyPunch: lượt chấm của nhân sự trong ngày (màn theo ngày).
type DailyPunch struct {
	ID              uuid.UUID `json:"id"`
	CheckIn         *string   `json:"check_in"`
	CheckOut        *string   `json:"check_out"`
	CheckInPhoto    *string   `json:"check_in_photo"`
	CheckInDistance *int32    `json:"check_in_distance"`
	Expected        string    `json:"expected"`
	StatusLabel     string    `json:"status_label"`
	StatusTone      string    `json:"status_tone"`
	Source          string    `json:"source"`
	Penalty         *string   `json:"penalty"`
}

// DailyRow: một nhân sự (màn theo ngày: lượt chấm + đơn nghỉ; màn tháng: tổng hợp).
type DailyRow struct {
	User       StaffRef    `json:"user"`
	Attendance *DailyPunch `json:"attendance,omitempty"`
	Leave      *string     `json:"leave,omitempty"`
	Summary    *Summary    `json:"summary,omitempty"`
}

// DayStats: thẻ số liệu màn theo ngày.
type DayStats struct {
	Staff     int `json:"staff"`
	CheckedIn int `json:"checked_in"`
	Late      int `json:"late"`
	Leave     int `json:"leave"`
}

type DailyPage struct {
	View         string     `json:"view"`
	Date         string     `json:"date"`
	Month        string     `json:"month"`
	Rows         []DailyRow `json:"rows"`
	Meta         Meta       `json:"meta"`
	Branches     []Option   `json:"branches"`
	Statuses     []Option   `json:"statuses"`
	Stats        *DayStats  `json:"stats"`
	Unconfigured []string   `json:"unconfigured"`
}

func optUUID(s string) (*uuid.UUID, error) {
	s = strings.TrimSpace(s)
	if s == "" {
		return nil, nil
	}
	id, err := uuid.Parse(s)
	if err != nil {
		return nil, iam.Invalid("Bộ lọc không hợp lệ.")
	}
	return &id, nil
}

// expectedRange: "08:00 – 17:00" (bỏ phần trống).
func expectedRange(start, end *string) string {
	return strings.Trim(deref(start)+" – "+deref(end), " –")
}

// Daily: danh sách nhân sự trong phạm vi theo ngày hoặc tổng hợp tháng.
func (s *Service) Daily(ctx context.Context, a *iam.Access, f DailyFilter) (*DailyPage, error) {
	view := f.View
	if view != "month" {
		view = "day"
	}
	now := s.now()
	day := dayOf(now)
	if strings.TrimSpace(f.Date) != "" {
		d, ok := parseDate(f.Date)
		if !ok {
			return nil, iam.Invalid("Ngày không hợp lệ.")
		}
		day = d
	}
	if strings.TrimSpace(f.Month) != "" {
		if _, ok := parseDate(f.Month + "-01"); !ok {
			return nil, iam.Invalid("Tháng không hợp lệ.")
		}
	}
	month := parseMonth(f.Month, now)
	status := strPtr(f.Status)
	if status != nil && typeLabelOf(DailyStatuses, *status) == "" {
		return nil, iam.Invalid("Trạng thái không hợp lệ.")
	}
	if view == "month" {
		status = nil
	}
	if len([]rune(strings.TrimSpace(f.Search))) > 100 {
		return nil, iam.Invalid("Từ khóa tối đa 100 ký tự.")
	}
	branchID, err := optUUID(f.BranchID)
	if err != nil {
		return nil, err
	}
	userID, err := optUUID(f.UserID)
	if err != nil {
		return nil, err
	}
	staff, err := s.staffIDs(ctx)
	if err != nil {
		return nil, err
	}
	all, branches := branchScope(a, scopeCheckin)
	page, per, lim, off := pageArgs(f.Page, f.PerPage, dailyPerPage)
	rows, err := s.q.StaffPage(ctx, attendancedb.StaffPageParams{StaffIds: staff, AllBranches: all, BranchIds: branches,
		BranchID: branchID, UserID: userID, Q: likePattern(f.Search), Status: status, Day: sqlDate(day), Lim: lim, Off: off})
	if err != nil {
		return nil, err
	}
	out := &DailyPage{View: view, Date: isoDate(day), Month: monthKey(month), Rows: []DailyRow{},
		Meta: Meta{Page: page, PerPage: per}, Branches: []Option{}, Statuses: DailyStatuses, Unconfigured: []string{}}
	ids := make([]uuid.UUID, 0, len(rows))
	for _, r := range rows {
		out.Meta.Total = r.Total
		ids = append(ids, r.ID)
	}
	if view == "month" {
		sums, err := s.summaries(ctx, ids, month, monthEnd(month))
		if err != nil {
			return nil, err
		}
		for _, r := range rows {
			sm := sums[r.ID]
			out.Rows = append(out.Rows, DailyRow{User: staffRef(r), Summary: &sm})
		}
	} else {
		recs, err := s.q.RangeAttendances(ctx, attendancedb.RangeAttendancesParams{UserIds: ids, FromDate: sqlDate(day), ToDate: sqlDate(day)})
		if err != nil {
			return nil, err
		}
		pen, err := s.penaltyRefs(ctx, recs)
		if err != nil {
			return nil, err
		}
		byUser := map[uuid.UUID]attendancedb.HrStaffAttendance{}
		for _, r := range recs {
			byUser[r.UserID] = r
		}
		leaves, err := s.q.ApprovedLeaves(ctx, attendancedb.ApprovedLeavesParams{UserIds: ids, FromDate: sqlDate(day), ToDate: sqlDate(day)})
		if err != nil {
			return nil, err
		}
		leaveOf := map[uuid.UUID]string{}
		for _, l := range leaves {
			if _, ok := leaveOf[l.UserID]; !ok {
				leaveOf[l.UserID] = l.Reason
			}
		}
		for _, r := range rows {
			row := DailyRow{User: staffRef(r)}
			if rec, ok := byUser[r.ID]; ok {
				p := &DailyPunch{ID: rec.ID, CheckIn: clockPtr(rec.CheckInAt), CheckOut: clockPtr(rec.CheckOutAt),
					CheckInPhoto: photoURL(rec.CheckInPhotoID), CheckInDistance: rec.CheckInDistance,
					Expected: expectedRange(hm(rec.ExpectedStart), hm(rec.ExpectedEnd)), StatusLabel: statusLabel(rec),
					StatusTone: statusTone(rec), Source: rec.Source}
				if rec.PenaltyID != nil {
					if ref, ok := pen[*rec.PenaltyID]; ok && ref.Code != "" {
						p.Penalty = ptr(ref.Code)
					}
				}
				row.Attendance = p
			}
			if reason, ok := leaveOf[r.ID]; ok {
				row.Leave = ptr(reason)
			}
			out.Rows = append(out.Rows, row)
		}
		st, err := s.q.DayStats(ctx, attendancedb.DayStatsParams{Day: sqlDate(day), StaffIds: staff, AllBranches: all,
			BranchIds: branches, BranchID: branchID})
		if err != nil {
			return nil, err
		}
		out.Stats = &DayStats{Staff: int(st.Staff), CheckedIn: int(st.CheckedIn), Late: int(st.Late), Leave: int(st.OnLeave)}
	}
	bs, err := s.q.ScopeBranches(ctx, attendancedb.ScopeBranchesParams{AllBranches: all, BranchIds: branches})
	if err != nil {
		return nil, err
	}
	for _, b := range bs {
		out.Branches = append(out.Branches, Option{b.ID.String(), b.Name})
		if !b.Configured {
			out.Unconfigured = append(out.Unconfigured, b.Name)
		}
	}
	return out, nil
}

func typeLabelOf(opts []Option, v string) string {
	for _, o := range opts {
		if o.Value == v {
			return o.Label
		}
	}
	return ""
}

func staffRef(r attendancedb.StaffPageRow) StaffRef {
	return StaffRef{ID: r.ID, Name: r.FullName, Code: r.Code, Branch: r.BranchName}
}

// PunchDetail: một lượt vào / ra trong chi tiết ngày công.
type PunchDetail struct {
	Time     string  `json:"time"`
	Photo    *string `json:"photo"`
	Distance *int32  `json:"distance"`
	Accuracy *int32  `json:"accuracy"`
	Map      *string `json:"map"`
}

// PenaltyLink: biên bản đi muộn (mở màn Biên bản theo mã).
type PenaltyLink struct {
	Code string `json:"code"`
	URL  string `json:"url"`
}

// AttendanceDetail: chi tiết một ngày công (ảnh vào / ra, vị trí, khoảng cách, giờ phải có mặt, biên bản).
type AttendanceDetail struct {
	ID            uuid.UUID    `json:"id"`
	User          string       `json:"user"`
	Code          string       `json:"code"`
	Branch        *string      `json:"branch"`
	Radius        *int32       `json:"radius"`
	Date          string       `json:"date"`
	Source        string       `json:"source"`
	ExpectedStart *string      `json:"expected_start"`
	ExpectedEnd   *string      `json:"expected_end"`
	In            *PunchDetail `json:"in"`
	Out           *PunchDetail `json:"out"`
	StatusLabel   string       `json:"status_label"`
	StatusTone    string       `json:"status_tone"`
	Note          *string      `json:"note"`
	Penalty       *PenaltyLink `json:"penalty"`
}

// Detail: chi tiết ngày công (người có staff_checkin.view trong phạm vi chi nhánh).
func (s *Service) Detail(ctx context.Context, a *iam.Access, id uuid.UUID) (*AttendanceDetail, error) {
	r, err := s.q.GetAttendance(ctx, id)
	if isNoRows(err) {
		return nil, ErrNotFound
	}
	if err != nil {
		return nil, err
	}
	if !a.Can(PermCheckinView) || !canSee(a, r.UserID, r.BranchID) {
		return nil, iam.ErrForbidden
	}
	row := attendancedb.HrStaffAttendance{CheckInAt: r.CheckInAt, CheckOutAt: r.CheckOutAt, LateMinutes: r.LateMinutes,
		EarlyMinutes: r.EarlyMinutes, LateExcused: r.LateExcused}
	punch := func(at *time.Time, photo *uuid.UUID, lat, lng *float64, dist, acc *int32) *PunchDetail {
		if at == nil {
			return nil
		}
		p := &PunchDetail{Time: at.In(vnZone).Format("15:04:05"), Photo: photoURL(photo), Distance: dist, Accuracy: acc}
		if lat != nil && lng != nil {
			p.Map = ptr(fmt.Sprintf("https://www.google.com/maps/search/?api=1&query=%v,%v", *lat, *lng))
		}
		return p
	}
	out := &AttendanceDetail{ID: r.ID, User: r.UserName, Code: r.UserCode, Branch: r.BranchName, Radius: r.BranchRadius,
		Date: isoDate(r.WorkDate), Source: r.Source, ExpectedStart: hm(r.ExpectedStart), ExpectedEnd: hm(r.ExpectedEnd),
		In:          punch(r.CheckInAt, r.CheckInPhotoID, r.CheckInLat, r.CheckInLng, r.CheckInDistance, r.CheckInAccuracy),
		Out:         punch(r.CheckOutAt, r.CheckOutPhotoID, r.CheckOutLat, r.CheckOutLng, r.CheckOutDistance, r.CheckOutAccuracy),
		StatusLabel: statusLabel(row), StatusTone: statusTone(row), Note: r.Note}
	if r.PenaltyID != nil {
		refs, err := s.penalties.Refs(ctx, []uuid.UUID{*r.PenaltyID})
		if err != nil {
			return nil, err
		}
		if ref, ok := refs[*r.PenaltyID]; ok && ref.Code != "" {
			out.Penalty = &PenaltyLink{Code: ref.Code, URL: "/penalties?search=" + ref.Code}
		}
	}
	return out, nil
}
