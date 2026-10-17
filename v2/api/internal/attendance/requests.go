package attendance

import (
	"context"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/attendance/attendancedb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify"
)

// Đơn chấm công & nghỉ (MyAttendanceController::requests / storeRequest / cancelRequest, StaffAttendanceService::submit /
// cancel / reviewQuery / approve / reject, StaffAttendanceRequestApprovalSource).

const (
	TypeCorrection = "correction"
	TypeLateEarly  = "late_early"
	TypeLeave      = "leave"

	StatusPending   = "pending"
	StatusApproved  = "approved"
	StatusRejected  = "rejected"
	StatusCancelled = "cancelled"
)

// RequestTypes: loại đơn theo thứ tự hiển thị.
var RequestTypes = []Option{
	{TypeCorrection, "Bổ sung công"},
	{TypeLateEarly, "Xin đi muộn / về sớm"},
	{TypeLeave, "Xin nghỉ"},
}

var requestStatuses = map[string]string{
	StatusPending: "Chờ duyệt", StatusApproved: "Đã duyệt", StatusRejected: "Từ chối", StatusCancelled: "Đã rút",
}

func typeLabel(t string) string {
	for _, o := range RequestTypes {
		if o.Value == t {
			return o.Label
		}
	}
	return t
}

func requestTone(status string) string {
	switch status {
	case StatusApproved:
		return "success"
	case StatusRejected:
		return "error"
	case StatusPending:
		return "warning"
	}
	return "neutral"
}

// periodLabel: "dd/mm/yyyy" hoặc "dd/mm/yyyy → dd/mm/yyyy (n ngày)", kèm giờ khai của đơn bổ sung công.
func periodLabel(typ string, from, to time.Time, in, out *string) string {
	f, t := dateOnly(from), dateOnly(to)
	label := fmtDate(f)
	if !f.Equal(t) {
		label += " → " + fmtDate(t) + " (" + itoa(overlapDays(f, t, f, t)) + " ngày)"
	}
	if typ == TypeCorrection {
		var parts []string
		if in != nil {
			parts = append(parts, "vào "+*in)
		}
		if out != nil {
			parts = append(parts, "ra "+*out)
		}
		if len(parts) > 0 {
			label += " · " + strings.Join(parts, ", ")
		}
	}
	return label
}

func itoa(n int) string { return thousands(n) }

func requestPeriod(r attendancedb.HrStaffAttendanceRequest) string {
	return periodLabel(r.Type, r.DateFrom, r.DateTo, hm(r.CheckInTime), hm(r.CheckOutTime))
}

// ───────────── Đơn của tôi ─────────────

// MyRequest: một đơn trong danh sách "Đơn của tôi".
type MyRequest struct {
	ID              uuid.UUID `json:"id"`
	Type            string    `json:"type"`
	TypeLabel       string    `json:"type_label"`
	Period          string    `json:"period"`
	Reason          string    `json:"reason"`
	Status          string    `json:"status"`
	StatusLabel     string    `json:"status_label"`
	StatusTone      string    `json:"status_tone"`
	Reviewer        *string   `json:"reviewer"`
	RejectionReason *string   `json:"rejection_reason"`
	CreatedAt       time.Time `json:"created_at"`
}

type RequestsPage struct {
	Types    []Option    `json:"types"`
	Today    string      `json:"today"`
	Requests []MyRequest `json:"requests"`
}

// MyRequests: 30 đơn mới nhất của chính mình.
func (s *Service) MyRequests(ctx context.Context, a *iam.Access) (*RequestsPage, error) {
	rows, err := s.q.MyRequests(ctx, a.UserID)
	if err != nil {
		return nil, err
	}
	out := &RequestsPage{Types: RequestTypes, Today: isoDate(dayOf(s.now())), Requests: []MyRequest{}}
	for _, r := range rows {
		req := attendancedb.HrStaffAttendanceRequest{Type: r.Type, DateFrom: r.DateFrom, DateTo: r.DateTo,
			CheckInTime: r.CheckInTime, CheckOutTime: r.CheckOutTime}
		out.Requests = append(out.Requests, MyRequest{
			ID: r.ID, Type: r.Type, TypeLabel: typeLabel(r.Type), Period: requestPeriod(req), Reason: r.Reason,
			Status: r.Status, StatusLabel: requestStatuses[r.Status], StatusTone: requestTone(r.Status),
			Reviewer: r.ReviewerName, RejectionReason: r.RejectionReason, CreatedAt: r.CreatedAt,
		})
	}
	return out, nil
}

// RequestInput: đơn gửi từ màn Đơn chấm công & nghỉ.
type RequestInput struct {
	Type         string `json:"type"`
	DateFrom     string `json:"date_from"`
	DateTo       string `json:"date_to"`
	CheckInTime  string `json:"check_in_time"`
	CheckOutTime string `json:"check_out_time"`
	Reason       string `json:"reason"`
}

// Submit: gửi đơn của chính mình (chờ Admin duyệt).
func (s *Service) Submit(ctx context.Context, a *iam.Access, in RequestInput) (string, error) {
	in.Type = strings.TrimSpace(in.Type)
	if in.Type == "" {
		return "", iam.Invalid("Chọn loại đơn.")
	}
	if typeLabel(in.Type) == in.Type {
		return "", iam.Invalid("Loại đơn không hợp lệ.")
	}
	if strings.TrimSpace(in.DateFrom) == "" {
		return "", iam.Invalid("Chọn ngày.")
	}
	from, ok := parseDate(in.DateFrom)
	if !ok {
		return "", iam.Invalid("Ngày không hợp lệ.")
	}
	to := from
	if strings.TrimSpace(in.DateTo) != "" {
		t, ok := parseDate(in.DateTo)
		if !ok {
			return "", iam.Invalid("Ngày kết thúc không hợp lệ.")
		}
		if in.Type == TypeLeave {
			to = t
		}
	}
	checkIn, checkOut := strPtr(in.CheckInTime), strPtr(in.CheckOutTime)
	for _, v := range []struct {
		val *string
		msg string
	}{{checkIn, "Giờ vào không hợp lệ (định dạng HH:MM)."}, {checkOut, "Giờ ra không hợp lệ (định dạng HH:MM)."}} {
		if v.val != nil {
			if _, ok := parseHM(*v.val); !ok {
				return "", iam.Invalid(v.msg)
			}
		}
	}
	reason := strings.TrimSpace(in.Reason)
	if reason == "" {
		return "", iam.Invalid("Nhập lý do.")
	}
	if utf8.RuneCountInString(reason) > 1000 {
		return "", iam.Invalid("Lý do tối đa 1000 ký tự.")
	}
	if to.Before(from) {
		return "", iam.Invalid("Ngày kết thúc phải từ ngày bắt đầu trở đi.")
	}
	if overlapDays(from, to, from, to) > MaxLeaveDays {
		return "", iam.Invalid("Mỗi đơn xin nghỉ tối đa " + itoa(MaxLeaveDays) + " ngày.")
	}
	if in.Type == TypeCorrection {
		if from.After(dayOf(s.now())) {
			return "", iam.Invalid("Chỉ bổ sung công cho hôm nay hoặc ngày đã qua.")
		}
		if checkIn == nil && checkOut == nil {
			return "", iam.Invalid("Nhập giờ vào hoặc giờ ra cần bổ sung.")
		}
		if checkIn != nil && checkOut != nil && *checkOut <= *checkIn {
			return "", iam.Invalid("Giờ ra phải sau giờ vào.")
		}
	} else {
		checkIn, checkOut = nil, nil
	}
	if err := s.ensureOpen(ctx, from, to); err != nil {
		return "", err
	}
	c, err := s.q.StaffContext(ctx, a.UserID)
	if err != nil {
		return "", err
	}
	label := typeLabel(in.Type)
	err = s.inTx(ctx, func(tx pgx.Tx, q *attendancedb.Queries) error {
		dup, err := q.PendingDuplicate(ctx, attendancedb.PendingDuplicateParams{UserID: a.UserID, Type: in.Type,
			DateFrom: sqlDate(from), DateTo: sqlDate(to)})
		if err != nil {
			return err
		}
		if dup {
			return iam.Invalid(`Bạn đã có đơn "` + label + `" đang chờ duyệt cho ngày này.`)
		}
		r, err := q.InsertRequest(ctx, attendancedb.InsertRequestParams{UserID: a.UserID, BranchID: c.BranchID, Type: in.Type,
			DateFrom: sqlDate(from), DateTo: sqlDate(to), CheckInTime: checkIn, CheckOutTime: checkOut, Reason: reason})
		if err != nil {
			return err
		}
		return s.record(ctx, tx, audit.Entry{ActorID: &a.UserID, Event: audit.EventCreated, Action: "staff_attendance_request.create",
			SubjectType: "staff_attendance_request", SubjectID: r.ID.String(), SubjectLabel: label + " " + requestPeriod(r),
			BranchID: c.BranchID, Description: "Gửi đơn " + label + " ngày " + requestPeriod(r)})
	})
	if err != nil {
		return "", err
	}
	s.log.Info("attendance request submitted", "type", in.Type, "by", a.UserID)
	return "Đã gửi đơn " + label + ", chờ Admin duyệt.", nil
}

// Cancel: người gửi rút đơn còn chờ duyệt.
func (s *Service) Cancel(ctx context.Context, a *iam.Access, id uuid.UUID) (string, error) {
	err := s.inTx(ctx, func(tx pgx.Tx, q *attendancedb.Queries) error {
		r, err := q.GetRequestLock(ctx, id)
		if isNoRows(err) {
			return ErrRequestNotFound
		}
		if err != nil {
			return err
		}
		if r.UserID != a.UserID || r.Status != StatusPending {
			return iam.Invalid("Chỉ rút được đơn của bạn đang chờ duyệt.")
		}
		if err := q.CancelRequest(ctx, id); err != nil {
			return err
		}
		return s.record(ctx, tx, audit.Entry{ActorID: &a.UserID, Event: "cancelled", Action: "staff_attendance_request.cancel",
			SubjectType: "staff_attendance_request", SubjectID: id.String(), BranchID: r.BranchID,
			Description: "Rút đơn " + typeLabel(r.Type) + " ngày " + requestPeriod(r)})
	})
	if err != nil {
		return "", err
	}
	return "Đã rút đơn.", nil
}

// ───────────── Duyệt đơn ─────────────

// PendingItem: một đơn chờ duyệt (ApprovalItem của StaffAttendanceRequestApprovalSource).
type PendingItem struct {
	ID           uuid.UUID `json:"id"`
	UserName     string    `json:"user_name"`
	UserCode     string    `json:"user_code"`
	Branch       *string   `json:"branch"`
	Type         string    `json:"type"`
	TypeLabel    string    `json:"type_label"`
	Period       string    `json:"period"`
	Date         string    `json:"date"`
	Reason       string    `json:"reason"`
	CurrentPunch *string   `json:"current_punch"`
	CreatedAt    time.Time `json:"created_at"`
}

// Pending: đơn chờ duyệt trong phạm vi chi nhánh của người duyệt, trừ đơn của chính mình (reviewQuery).
func (s *Service) Pending(ctx context.Context, a *iam.Access) ([]PendingItem, error) {
	out := []PendingItem{}
	if !a.Can(PermCheckinApprove) {
		return out, nil
	}
	all, branches := branchScope(a, scopeCheckin)
	rows, err := s.q.PendingRequests(ctx, attendancedb.PendingRequestsParams{ReviewerID: a.UserID, AllBranches: all, BranchIds: branches})
	if err != nil {
		return nil, err
	}
	for _, r := range rows {
		req := attendancedb.HrStaffAttendanceRequest{Type: r.Type, DateFrom: r.DateFrom, DateTo: r.DateTo,
			CheckInTime: r.CheckInTime, CheckOutTime: r.CheckOutTime}
		item := PendingItem{ID: r.ID, UserName: r.UserName, UserCode: r.UserCode, Branch: r.BranchName, Type: r.Type,
			TypeLabel: typeLabel(r.Type), Period: requestPeriod(req), Date: isoDate(r.DateFrom), Reason: r.Reason, CreatedAt: r.CreatedAt}
		if r.Type != TypeLeave {
			item.CurrentPunch = ptr(currentPunch(r))
		}
		out = append(out, item)
	}
	return out, nil
}

// currentPunch: giờ đã chấm của ngày trong đơn, để người duyệt đối chiếu.
func currentPunch(r attendancedb.PendingRequestsRow) string {
	if r.PunchIn == nil && r.PunchOut == nil {
		return "Chưa chấm"
	}
	row := attendancedb.HrStaffAttendance{CheckInAt: r.PunchIn, CheckOutAt: r.PunchOut}
	if r.PunchLate != nil {
		row.LateMinutes = *r.PunchLate
	}
	if r.PunchEarly != nil {
		row.EarlyMinutes = *r.PunchEarly
	}
	if r.PunchExcused != nil {
		row.LateExcused = *r.PunchExcused
	}
	or := func(t *time.Time) string {
		if t == nil {
			return "—"
		}
		return clock(*t)
	}
	return "Vào " + or(r.PunchIn) + ", ra " + or(r.PunchOut) + " · " + statusLabel(row)
}

// reviewable: đơn còn chờ duyệt, trong phạm vi duyệt, không phải đơn của chính mình (ensureReviewable).
func reviewable(a *iam.Access, r attendancedb.HrStaffAttendanceRequest) error {
	if !a.Can(PermCheckinApprove) || r.Status != StatusPending || r.UserID == a.UserID || !a.CoversBranch(scopeCheckin, r.BranchID) {
		return iam.Invalid("Đơn không còn chờ duyệt hoặc ngoài phạm vi duyệt của bạn.")
	}
	return nil
}

// Approve: duyệt đơn và áp vào bảng công (bổ sung công sửa giờ, đơn muộn / nghỉ làm dòng muộn thành "có phép").
func (s *Service) Approve(ctx context.Context, a *iam.Access, id uuid.UUID) (string, error) {
	var msg string
	err := s.inTx(ctx, func(tx pgx.Tx, q *attendancedb.Queries) error {
		r, err := q.GetRequestLock(ctx, id)
		if isNoRows(err) {
			return iam.Invalid("Đơn không còn chờ duyệt.")
		}
		if err != nil {
			return err
		}
		if err := reviewable(a, r); err != nil {
			return err
		}
		if err := s.ensureOpen(ctx, dateOnly(r.DateFrom), dateOnly(r.DateTo)); err != nil {
			return err
		}
		if err := q.ReviewRequest(ctx, attendancedb.ReviewRequestParams{ID: id, Status: StatusApproved, ReviewedBy: &a.UserID}); err != nil {
			return err
		}
		r.Status = StatusApproved
		if err := s.applyApproved(ctx, tx, q, r); err != nil {
			return err
		}
		c, err := q.StaffContext(ctx, r.UserID)
		if err != nil {
			return err
		}
		msg = "Đã duyệt đơn " + typeLabel(r.Type) + " của " + c.FullName + "."
		if err := s.record(ctx, tx, audit.Entry{ActorID: &a.UserID, Event: "approved", Action: "staff_attendance_request.approve",
			SubjectType: "staff_attendance_request", SubjectID: id.String(), SubjectLabel: c.FullName, BranchID: r.BranchID,
			Description: "Duyệt đơn " + typeLabel(r.Type) + " ngày " + requestPeriod(r) + " của " + c.FullName}); err != nil {
			return err
		}
		return s.notifyRequester(ctx, tx, a, r, "Đã duyệt", "")
	})
	if err != nil {
		return "", err
	}
	s.log.Info("attendance request approved", "request", id, "by", a.UserID)
	return msg, nil
}

// Reject: từ chối đơn (bắt buộc lý do).
func (s *Service) Reject(ctx context.Context, a *iam.Access, id uuid.UUID, reason string) (string, error) {
	reason = strings.TrimSpace(reason)
	if reason == "" {
		return "", iam.Invalid("Vui lòng nhập lý do từ chối.")
	}
	if utf8.RuneCountInString(reason) > 1000 {
		return "", iam.Invalid("Lý do từ chối tối đa 1000 ký tự.")
	}
	var msg string
	err := s.inTx(ctx, func(tx pgx.Tx, q *attendancedb.Queries) error {
		r, err := q.GetRequestLock(ctx, id)
		if isNoRows(err) {
			return iam.Invalid("Đơn không còn chờ duyệt.")
		}
		if err != nil {
			return err
		}
		if err := reviewable(a, r); err != nil {
			return err
		}
		if err := q.ReviewRequest(ctx, attendancedb.ReviewRequestParams{ID: id, Status: StatusRejected, ReviewedBy: &a.UserID,
			RejectionReason: &reason}); err != nil {
			return err
		}
		c, err := q.StaffContext(ctx, r.UserID)
		if err != nil {
			return err
		}
		msg = "Đã từ chối đơn " + typeLabel(r.Type) + " của " + c.FullName + "."
		if err := s.record(ctx, tx, audit.Entry{ActorID: &a.UserID, Event: "rejected", Action: "staff_attendance_request.reject",
			SubjectType: "staff_attendance_request", SubjectID: id.String(), SubjectLabel: c.FullName, BranchID: r.BranchID,
			Description: "Từ chối đơn " + typeLabel(r.Type) + " ngày " + requestPeriod(r) + " của " + c.FullName,
			Properties:  map[string]any{"reason": reason}}); err != nil {
			return err
		}
		return s.notifyRequester(ctx, tx, a, r, "Từ chối", reason)
	})
	if err != nil {
		return "", err
	}
	s.log.Info("attendance request rejected", "request", id, "by", a.UserID)
	return msg, nil
}

func (s *Service) notifyRequester(ctx context.Context, tx pgx.Tx, a *iam.Access, r attendancedb.HrStaffAttendanceRequest, verb, reason string) error {
	body := "Đơn của bạn đã được duyệt."
	if reason != "" {
		body = "Lý do: " + reason
	}
	_, err := notify.Send(ctx, tx, []uuid.UUID{r.UserID}, notify.Notification{
		Type: notifyRequest, Title: verb + " đơn " + typeLabel(r.Type) + " ngày " + requestPeriod(r), Body: body,
		Link: "/cham-cong/don", CreatedBy: &a.UserID,
	})
	return err
}

// applyApproved: áp đơn đã duyệt vào bảng công từng ngày (đơn xin nghỉ chỉ cần trạng thái đã duyệt; ngày đã chấm thì tính
// lại "muộn có phép").
func (s *Service) applyApproved(ctx context.Context, tx pgx.Tx, q *attendancedb.Queries, r attendancedb.HrStaffAttendanceRequest) error {
	who, err := s.access(ctx, r.UserID)
	if err != nil {
		return err
	}
	c, err := q.StaffContext(ctx, r.UserID)
	if err != nil {
		return err
	}
	for day := dateOnly(r.DateFrom); !day.After(dateOnly(r.DateTo)); day = day.AddDate(0, 0, 1) {
		row, err := q.AttendanceForDayLock(ctx, attendancedb.AttendanceForDayLockParams{UserID: r.UserID, WorkDate: sqlDate(day)})
		found := err == nil
		if err != nil && !isNoRows(err) {
			return err
		}
		if r.Type == TypeCorrection {
			if !found {
				branch := r.BranchID
				if branch == nil {
					branch = c.BranchID
				}
				row = attendancedb.HrStaffAttendance{UserID: r.UserID, WorkDate: sqlDate(day), Source: SourceRequest, BranchID: branch}
			}
			if v := hm(r.CheckInTime); v != nil {
				row.CheckInAt = ptr(at(day, *v))
			}
			if v := hm(r.CheckOutTime); v != nil {
				row.CheckOutAt = ptr(at(day, *v))
			}
			row.Note = ptr(truncate(strings.TrimSpace("Bổ sung công đã duyệt: "+r.Reason), 500))
		} else if !found {
			continue
		}
		branchID := row.BranchID
		if branchID == nil {
			branchID = c.BranchID
		}
		branch, err := s.branchByID(ctx, branchID)
		if err != nil {
			return err
		}
		if err := s.recalculate(ctx, q, who, branch, &row); err != nil {
			return err
		}
		if row, err = save(ctx, q, row); err != nil {
			return err
		}
		if err := s.syncLatePenalty(ctx, tx, q, &row); err != nil {
			return err
		}
	}
	return nil
}

func truncate(s string, n int) string {
	r := []rune(s)
	if len(r) <= n {
		return s
	}
	return string(r[:n])
}
