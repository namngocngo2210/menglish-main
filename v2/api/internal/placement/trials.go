package placement

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"slices"
	"strings"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgconn"
	"github.com/jackc/pgx/v5/pgtype"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/placement/placementdb"
)

// Học thử (Laravel CrmTrialBooking): khách (lead chưa chốt) học thử trong một buổi học thật của lớp. Không phải giai đoạn
// pipeline và không tạo ghi danh; nhận xét của giáo viên lưu theo khách.
const (
	// MaxTrialsPerLead: mỗi khách tối đa 2 lần học thử; xếp từng buổi một.
	MaxTrialsPerLead = 2
	// trialWindowDays: chỉ xếp vào buổi trong 14 ngày tới (TrialSlotFinder::WINDOW_DAYS).
	trialWindowDays = 14
)

// TrialStatuses: trạng thái buổi học thử → nhãn.
var TrialStatuses = []GradeGroup{{"scheduled", "Đã hẹn"}, {"attended", "Đã học thử"}, {"no_show", "Vắng mặt"}, {"cancelled", "Đã hủy"}}

// trialBookableStages: chỉ đặt học thử cho khách đang tư vấn (chưa chốt, chưa thất bại).
var trialBookableStages = []string{"consulting", "test_scheduled", "testing", "tested", "result_sent"}

// RemarkFields: đầu mục nhận xét giống nhận xét buổi học của học sinh chính thức.
var RemarkFields = []GradeGroup{{"grammar", "Thực hành ngữ pháp"}, {"attitude", "Tinh thần học tập"}, {"result", "Kết quả"}}

func trialStatusLabel(s string) string {
	for _, t := range TrialStatuses {
		if t.Value == s {
			return t.Label
		}
	}
	return s
}

func hhmm(t pgtype.Time) string {
	if !t.Valid {
		return ""
	}
	m := t.Microseconds / 60e6
	return fmt.Sprintf("%02d:%02d", m/60, m%60)
}

func parseHHMM(s string) pgtype.Time {
	t, err := time.Parse("15:04", s)
	if err != nil {
		return pgtype.Time{}
	}
	return pgtype.Time{Microseconds: int64(t.Hour()*3600+t.Minute()*60) * 1e6, Valid: true}
}

// sessionEnd: thời điểm buổi học thử kết thúc (ngày + giờ kết thúc; thiếu giờ thì hết ngày), giờ Việt Nam.
func sessionEnd(date time.Time, end pgtype.Time) time.Time {
	d := time.Date(date.Year(), date.Month(), date.Day(), 0, 0, 0, 0, vnZone)
	if !end.Valid {
		return d.Add(24*time.Hour - time.Second)
	}
	return d.Add(time.Duration(end.Microseconds) * time.Microsecond)
}

func remarksSummary(raw []byte) string {
	var m map[string]string
	_ = json.Unmarshal(raw, &m)
	var parts []string
	for _, f := range RemarkFields {
		if v := strings.TrimSpace(m[f.Value]); v != "" {
			parts = append(parts, f.Label+": "+v)
		}
	}
	return strings.Join(parts, " · ")
}

// TrialState: tình trạng học thử của khách — đã dùng bao nhiêu lượt, buổi đang chờ, còn xếp được không.
type TrialState struct {
	Used        int        `json:"used"`
	Remaining   int        `json:"remaining"`
	PendingID   *uuid.UUID `json:"pending_id"`
	PendingDate *time.Time `json:"pending_date"`
	Exhausted   bool       `json:"exhausted"`
}

func trialState(bs []placementdb.CrmTrialBooking, now time.Time) TrialState {
	var st TrialState
	for _, b := range bs {
		// Vắng mặt / đã hủy trả lại lượt.
		if b.Status == "scheduled" || b.Status == "attended" {
			st.Used++
		}
		if st.PendingID == nil && b.Status == "scheduled" && sessionEnd(b.SessionDate, b.EndTime).After(now) {
			st.PendingID, st.PendingDate = ptr(b.ID), ptr(b.SessionDate)
		}
	}
	st.Remaining = max(0, MaxTrialsPerLead-st.Used)
	st.Exhausted = st.Used >= MaxTrialsPerLead
	return st
}

// TrialRow: một buổi học thử trên lịch / màn giáo viên.
type TrialRow struct {
	ID             uuid.UUID         `json:"id"`
	Status         string            `json:"status"`
	StatusLabel    string            `json:"status_label"`
	ClassID        uuid.UUID         `json:"class_id"`
	ClassSessionID uuid.UUID         `json:"class_session_id"`
	ClassName      string            `json:"class_name"`
	CourseName     string            `json:"course_name"`
	BranchName     string            `json:"branch_name"`
	Date           string            `json:"date"` // YYYY-MM-DD
	Start          string            `json:"start"`
	End            string            `json:"end"`
	LeadID         uuid.UUID         `json:"lead_id"`
	CustomerName   string            `json:"customer_name"`
	ParentName     string            `json:"parent_name"`
	StageLabel     string            `json:"stage_label"`
	TestScore      string            `json:"test_score"`
	AssignedName   string            `json:"assigned_name"`
	BookedBy       string            `json:"booked_by"`
	Notes          string            `json:"notes"`
	Rating         *int16            `json:"rating"`
	Remarks        map[string]string `json:"remarks"`
	RemarksSummary string            `json:"remarks_summary"`
	Feedback       string            `json:"feedback"`
	FeedbackBy     string            `json:"feedback_by"`
	FeedbackAt     *time.Time        `json:"feedback_at"`
	Started        bool              `json:"started"`
	// Chỉ có khi vừa xếp lịch: kết quả gửi xác nhận cho phụ huynh (chưa gửi email được thì hiện popup sao chép Zalo)
	Confirmation *Confirmation `json:"confirmation,omitempty"`
}

// TrialPage: một trang danh sách buổi học thử.
type TrialPage struct {
	Bookings []TrialRow `json:"bookings"`
	Total    int32      `json:"total"`
	Page     int        `json:"page"`
	PerPage  int        `json:"per_page"`
}

func (s *Service) trialRows(ctx context.Context, bs []placementdb.CrmTrialBooking, extra func(i int, r *TrialRow)) ([]TrialRow, error) {
	var leadIDs, sessionIDs []uuid.UUID
	for _, b := range bs {
		leadIDs = append(leadIDs, b.LeadID)
		sessionIDs = append(sessionIDs, b.ClassSessionID)
	}
	leads, sessions := map[uuid.UUID]Lead{}, map[uuid.UUID]Session{}
	var err error
	if len(bs) > 0 {
		if leads, err = s.leads.Lookup(ctx, leadIDs); err != nil {
			return nil, err
		}
		if sessions, err = s.sessions.Lookup(ctx, sessionIDs); err != nil {
			return nil, err
		}
	}
	today := s.today()
	out := make([]TrialRow, 0, len(bs))
	for i, b := range bs {
		l, ss := leads[b.LeadID], sessions[b.ClassSessionID]
		r := TrialRow{
			ID: b.ID, Status: b.Status, StatusLabel: trialStatusLabel(b.Status), ClassID: b.ClassID, ClassSessionID: b.ClassSessionID,
			ClassName: ss.ClassName, CourseName: ss.CourseName, BranchName: ss.BranchName, Date: b.SessionDate.Format("2006-01-02"),
			Start: hhmm(b.StartTime), End: hhmm(b.EndTime), LeadID: b.LeadID, CustomerName: l.Name, ParentName: l.ParentName,
			StageLabel: l.StageLabel, TestScore: l.TestScore, AssignedName: l.AssignedName, Notes: deref(b.Notes), Rating: b.Rating,
			Remarks: map[string]string{}, Feedback: deref(b.Feedback), FeedbackAt: b.FeedbackAt, Started: !b.SessionDate.After(today),
		}
		_ = json.Unmarshal(b.Remarks, &r.Remarks)
		if b.FeedbackAt != nil {
			r.RemarksSummary = remarksSummary(b.Remarks)
		}
		if extra != nil {
			extra(i, &r)
		}
		out = append(out, r)
	}
	return out, nil
}

// TrialFilter: lọc lịch học thử.
type TrialFilter struct {
	Past    bool
	Status  string
	Page    int
	PerPage int
}

func (f *TrialFilter) norm() {
	if f.PerPage <= 0 || f.PerPage > 100 {
		f.PerPage = 20
	}
	f.Page = max(1, f.Page)
	if !slices.ContainsFunc(TrialStatuses, func(t GradeGroup) bool { return t.Value == f.Status }) {
		f.Status = ""
	}
}

// Schedule: "Lịch học thử" — buổi học thử của khách trong phạm vi CRM người xem.
func (s *Service) Schedule(ctx context.Context, a *iam.Access, f TrialFilter) (*TrialPage, error) {
	// Học viên (Cổng học viên) không xem lịch học thử (Laravel ensureCanBrowseClasses).
	if a.Can("portal.student") && a.Level("class") != iam.ScopeAll {
		return nil, iam.ErrForbidden
	}
	f.norm()
	ids, all, err := s.leadScope(ctx, a)
	if err != nil {
		return nil, err
	}
	status := strOrNil(f.Status)
	today := s.today()
	total, err := s.q.CountTrialSchedule(ctx, placementdb.CountTrialScheduleParams{AllLeads: all, LeadIds: ids, Status: status, Past: f.Past, Today: today})
	if err != nil {
		return nil, err
	}
	rows, err := s.q.ListTrialSchedule(ctx, placementdb.ListTrialScheduleParams{
		AllLeads: all, LeadIds: ids, Status: status, Past: f.Past, Today: today,
		Lim: int32(f.PerPage), Off: int32((f.Page - 1) * f.PerPage),
	})
	if err != nil {
		return nil, err
	}
	bs := make([]placementdb.CrmTrialBooking, len(rows))
	for i, r := range rows {
		bs[i] = placementdb.CrmTrialBooking{ID: r.ID, LeadID: r.LeadID, ClassID: r.ClassID, ClassSessionID: r.ClassSessionID,
			BranchID: r.BranchID, SessionDate: r.SessionDate, StartTime: r.StartTime, EndTime: r.EndTime, Status: r.Status,
			Notes: r.Notes, Rating: r.Rating, Remarks: r.Remarks, Feedback: r.Feedback, FeedbackBy: r.FeedbackBy, FeedbackAt: r.FeedbackAt}
	}
	out, err := s.trialRows(ctx, bs, func(i int, t *TrialRow) {
		t.BookedBy, t.FeedbackBy = deref(rows[i].BookedByName), deref(rows[i].FeedbackByName)
		if t.BranchName == "" {
			t.BranchName = deref(rows[i].BranchName)
		}
	})
	if err != nil {
		return nil, err
	}
	return &TrialPage{Bookings: out, Total: total, Page: f.Page, PerPage: f.PerPage}, nil
}

// teachingScope: phạm vi CRM "Toàn hệ thống" thấy mọi khách học thử; còn lại chỉ buổi mình dạy / lớp mình phụ trách.
func (s *Service) teachingScope(ctx context.Context, a *iam.Access) (all bool, sessionIDs, classIDs []uuid.UUID, err error) {
	if a.Level("lead") == iam.ScopeAll {
		return true, []uuid.UUID{}, []uuid.UUID{}, nil
	}
	sessionIDs, classIDs, err = s.sessions.TeachingScope(ctx, a.UserID)
	if sessionIDs == nil {
		sessionIDs = []uuid.UUID{}
	}
	if classIDs == nil {
		classIDs = []uuid.UUID{}
	}
	return false, sessionIDs, classIDs, err
}

// TeacherTrials: "Nhận xét học thử" (Cổng Giáo viên).
func (s *Service) TeacherTrials(ctx context.Context, a *iam.Access, f TrialFilter) (*TrialPage, error) {
	f.norm()
	all, sids, cids, err := s.teachingScope(ctx, a)
	if err != nil {
		return nil, err
	}
	today := s.today()
	total, err := s.q.CountTeacherTrials(ctx, placementdb.CountTeacherTrialsParams{AllTrials: all, SessionIds: sids, ClassIds: cids, Past: f.Past, Today: today})
	if err != nil {
		return nil, err
	}
	rows, err := s.q.ListTeacherTrials(ctx, placementdb.ListTeacherTrialsParams{
		AllTrials: all, SessionIds: sids, ClassIds: cids, Past: f.Past, Today: today, Lim: int32(f.PerPage), Off: int32((f.Page - 1) * f.PerPage),
	})
	if err != nil {
		return nil, err
	}
	bs := make([]placementdb.CrmTrialBooking, len(rows))
	for i, r := range rows {
		bs[i] = placementdb.CrmTrialBooking{ID: r.ID, LeadID: r.LeadID, ClassID: r.ClassID, ClassSessionID: r.ClassSessionID,
			BranchID: r.BranchID, SessionDate: r.SessionDate, StartTime: r.StartTime, EndTime: r.EndTime, Status: r.Status,
			Notes: r.Notes, Rating: r.Rating, Remarks: r.Remarks, Feedback: r.Feedback, FeedbackBy: r.FeedbackBy, FeedbackAt: r.FeedbackAt}
	}
	out, err := s.trialRows(ctx, bs, func(i int, t *TrialRow) { t.FeedbackBy = deref(rows[i].FeedbackByName) })
	if err != nil {
		return nil, err
	}
	return &TrialPage{Bookings: out, Total: total, Page: f.Page, PerPage: f.PerPage}, nil
}

// SessionGuests: khách học thử (chưa hủy, khách còn hồ sơ) theo buổi — Cổng giáo viên (số khách trên ca dạy, khối
// "Khách học thử buổi này" ở trang nhận xét / điểm danh). Không lọc phạm vi: người gọi đã kiểm quyền với buổi.
func (s *Service) SessionGuests(ctx context.Context, sessionIDs []uuid.UUID) (map[uuid.UUID][]TrialRow, error) {
	out := map[uuid.UUID][]TrialRow{}
	if len(sessionIDs) == 0 {
		return out, nil
	}
	bs, err := s.q.SessionTrialBookings(ctx, sessionIDs)
	if err != nil {
		return nil, err
	}
	rows, err := s.trialRows(ctx, bs, nil)
	if err != nil {
		return nil, err
	}
	for _, r := range rows {
		if r.CustomerName == "" {
			continue
		}
		out[r.ClassSessionID] = append(out[r.ClassSessionID], r)
	}
	return out, nil
}

// LeadTrials: buổi học thử và tình trạng lượt học thử của một khách (hồ sơ khách CRM).
type LeadTrials struct {
	Bookings []TrialRow `json:"bookings"`
	State    TrialState `json:"state"`
}

var errLeadNotFound = &iam.Error{Status: http.StatusNotFound, Code: "not_found", Message: "Không tìm thấy khách hàng."}

func (s *Service) visibleLead(ctx context.Context, a *iam.Access, id uuid.UUID) (*Lead, error) {
	l, err := s.leads.Get(ctx, id)
	if err != nil {
		return nil, err
	}
	if l == nil {
		return nil, errLeadNotFound
	}
	ok, err := s.leadVisible(ctx, a, id)
	if err != nil {
		return nil, err
	}
	if !ok {
		return nil, errLeadNotFound
	}
	return l, nil
}

// GetLeadTrials: học thử của khách.
func (s *Service) GetLeadTrials(ctx context.Context, a *iam.Access, leadID uuid.UUID) (*LeadTrials, error) {
	if _, err := s.visibleLead(ctx, a, leadID); err != nil {
		return nil, err
	}
	bs, err := s.q.LeadTrialBookings(ctx, leadID)
	if err != nil {
		return nil, err
	}
	rows, err := s.trialRows(ctx, bs, nil)
	if err != nil {
		return nil, err
	}
	return &LeadTrials{Bookings: rows, State: trialState(bs, s.now())}, nil
}

// BookTrialInput: xếp học thử.
type BookTrialInput struct {
	LeadID         uuid.UUID  `json:"lead_id"`
	ClassSessionID *uuid.UUID `json:"class_session_id"`
	Notes          string     `json:"notes"`
}

func dmy(t time.Time) string { return t.Format("02/01/2006") }

// BookTrial: Học vụ / QL xếp khách học thử vào MỘT buổi học thật của lớp (luồng trước Chốt). Mỗi lần xếp một buổi;
// buổi 2 xếp sau khi buổi 1 kết thúc; đủ 2 lần thì khóa. Không đổi giai đoạn khách, không tạo ghi danh.
func (s *Service) BookTrial(ctx context.Context, a *iam.Access, in BookTrialInput) (*TrialRow, error) {
	lead, err := s.visibleLead(ctx, a, in.LeadID)
	if err != nil {
		return nil, err
	}
	switch {
	case in.ClassSessionID == nil:
		return nil, errInvalid("Vui lòng chọn một buổi học thử.")
	case runeLen(in.Notes) > 1000:
		return nil, errInvalid("Ghi chú không được vượt quá 1000 ký tự.")
	case !slices.Contains(trialBookableStages, lead.Stage):
		return nil, errInvalid("Chỉ đặt học thử cho khách đang tư vấn (chưa chốt, chưa thất bại).")
	}
	var b placementdb.CrmTrialBooking
	var sess *Session
	var conf *Confirmation
	var confLog string
	err = pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
		q := s.q.WithTx(tx)
		if err := q.LockLeadTrials(ctx, lead.ID); err != nil {
			return err
		}
		existing, err := q.LeadTrialBookings(ctx, lead.ID)
		if err != nil {
			return err
		}
		st := trialState(existing, s.now())
		if st.Exhausted {
			return errInvalid(fmt.Sprintf("Khách đã học thử đủ %d buổi — không xếp thêm học thử.", MaxTrialsPerLead))
		}
		if st.PendingID != nil {
			return errInvalid(fmt.Sprintf("Khách đang có buổi học thử chưa diễn ra (%s). Xếp buổi tiếp theo sau khi buổi này kết thúc.", dmy(*st.PendingDate)))
		}
		if sess, err = s.sessions.Get(ctx, *in.ClassSessionID); err != nil {
			return err
		}
		unavailable := errInvalid("Buổi học đã chọn không còn khả dụng.")
		if sess == nil {
			return unavailable
		}
		end := parseHHMM(sess.End)
		date := time.Date(sess.Date.Year(), sess.Date.Month(), sess.Date.Day(), 0, 0, 0, 0, time.UTC)
		if sess.Status != "scheduled" || !sessionEnd(date, end).After(s.now()) || date.After(s.today().AddDate(0, 0, trialWindowDays)) ||
			(sess.ClassStatus != "active" && sess.ClassStatus != "upcoming") {
			return unavailable
		}
		if lead.BranchID != nil && sess.BranchID != nil && *lead.BranchID != *sess.BranchID {
			return errInvalid("Buổi học thử phải thuộc chi nhánh của khách.")
		}
		b, err = q.CreateTrialBooking(ctx, placementdb.CreateTrialBookingParams{
			LeadID: lead.ID, ClassID: sess.ClassID, ClassSessionID: sess.ID, BranchID: sess.BranchID, SessionDate: date,
			StartTime: parseHHMM(sess.Start), EndTime: end, Notes: strOrNil(in.Notes), BookedBy: &a.UserID,
		})
		var pe *pgconn.PgError
		if errors.As(err, &pe) && pe.Code == "23505" {
			return errInvalid("Khách đã được xếp vào buổi học này.")
		}
		if err != nil {
			return err
		}
		// Xác nhận lịch học thử cho phụ huynh (email hoặc nhắc người phụ trách gửi Zalo), cùng transaction.
		branchID := sess.BranchID
		if branchID == nil {
			branchID = lead.BranchID
		}
		br, err := s.branchInfo(ctx, tx, branchID)
		if err != nil {
			return err
		}
		conf, confLog, err = s.deliverConfirmation(ctx, tx, a, lead, trialConfirmation(lead, sess, br), "trial_booking", b.ID)
		return err
	})
	if err != nil {
		return nil, err
	}
	s.log.Info("placement: xếp học thử", "booking", b.ID, "lead", lead.ID, "session", b.ClassSessionID, "by", a.UserID)
	content := fmt.Sprintf("Đặt lịch học thử: %s (%s %s)", sess.ClassName, dmy(b.SessionDate), hhmm(b.StartTime))
	if n := strings.TrimSpace(in.Notes); n != "" {
		content += ". Ghi chú cho giáo viên: " + n
	} else {
		content += "."
	}
	s.record(ctx, LeadEvent{LeadID: lead.ID, ActorID: &a.UserID, Type: "trial", Content: content})
	s.notifyTrialBooked(ctx, a, b.ID, lead, sess, hhmm(b.StartTime), strings.TrimSpace(in.Notes))
	s.record(ctx, LeadEvent{LeadID: lead.ID, ActorID: &a.UserID, Type: "trial", Content: confLog})
	rows, err := s.trialRows(ctx, []placementdb.CrmTrialBooking{b}, nil)
	if err != nil {
		return nil, err
	}
	rows[0].Confirmation = conf
	return &rows[0], nil
}

// CancelTrial: hủy buổi học thử đang chờ (bắt buộc lý do).
func (s *Service) CancelTrial(ctx context.Context, a *iam.Access, id uuid.UUID, reason string) (*TrialRow, error) {
	b, err := s.q.GetTrialBooking(ctx, id)
	if err != nil {
		return nil, notFound(err, errNotFound)
	}
	if _, err := s.visibleLead(ctx, a, b.LeadID); err != nil {
		return nil, notFound(err, errNotFound)
	}
	reason = strings.TrimSpace(reason)
	switch {
	case reason == "":
		return nil, errInvalid("Vui lòng nhập lý do hủy.")
	case runeLen(reason) > 1000:
		return nil, errInvalid("Lý do không được vượt quá 1000 ký tự.")
	case b.Status != "scheduled":
		return nil, errInvalid("Chỉ hủy được buổi học thử đang chờ.")
	}
	notes := "Hủy: " + reason
	if b.Notes != nil && *b.Notes != "" {
		notes = *b.Notes + "\n" + notes
	}
	b, err = s.q.CancelTrialBooking(ctx, placementdb.CancelTrialBookingParams{ID: id, Notes: ptr(truncate(notes, 3000))})
	if err != nil {
		return nil, notFound(err, errInvalid("Chỉ hủy được buổi học thử đang chờ."))
	}
	s.log.Info("placement: hủy học thử", "booking", id, "by", a.UserID)
	s.record(ctx, LeadEvent{LeadID: b.LeadID, ActorID: &a.UserID, Type: "trial",
		Content: fmt.Sprintf("Hủy buổi học thử ngày %s. Lý do: %s", dmy(b.SessionDate), reason)})
	rows, err := s.trialRows(ctx, []placementdb.CrmTrialBooking{b}, nil)
	if err != nil {
		return nil, err
	}
	return &rows[0], nil
}

// FeedbackInput: điểm danh + nhận xét khách học thử.
type FeedbackInput struct {
	Status   string            `json:"status"` // attended | no_show
	Rating   *int              `json:"rating"`
	Remarks  map[string]string `json:"remarks"`
	Feedback string            `json:"feedback"`
}

// canGiveFeedback: quyền nhận xét học thử theo khách (khách trong phạm vi CRM), hoặc giáo viên dạy buổi / phụ trách lớp.
func (s *Service) canGiveFeedback(ctx context.Context, a *iam.Access, b placementdb.CrmTrialBooking) (bool, error) {
	if a.Can("lead.trial_feedback") {
		if ok, err := s.leadVisible(ctx, a, b.LeadID); err != nil || ok {
			return ok, err
		}
	}
	all, sids, cids, err := s.teachingScope(ctx, a)
	return all || slices.Contains(sids, b.ClassSessionID) || slices.Contains(cids, b.ClassID), err
}

// TrialFeedback: giáo viên buổi đó điểm danh và nhận xét khách như học sinh chính thức; lưu vào hồ sơ khách.
func (s *Service) TrialFeedback(ctx context.Context, a *iam.Access, id uuid.UUID, in FeedbackInput) (*TrialRow, string, error) {
	b, err := s.q.GetTrialBooking(ctx, id)
	if err != nil {
		return nil, "", notFound(err, errNotFound)
	}
	ok, err := s.canGiveFeedback(ctx, a, b)
	if err != nil {
		return nil, "", err
	}
	if !ok {
		return nil, "", &iam.Error{Status: http.StatusForbidden, Code: "forbidden", Message: "Bạn không dạy buổi học thử này."}
	}
	attended := in.Status == "attended"
	in.Feedback = strings.TrimSpace(in.Feedback)
	switch {
	case in.Status != "attended" && in.Status != "no_show":
		return nil, "", errInvalid("Trạng thái điểm danh không hợp lệ.")
	case attended && in.Rating == nil:
		return nil, "", errInvalid("Vui lòng chọn mức đánh giá.")
	case in.Rating != nil && (*in.Rating < 1 || *in.Rating > 5):
		return nil, "", errInvalid("Mức đánh giá từ 1 đến 5.")
	case attended && in.Feedback == "":
		return nil, "", errInvalid("Vui lòng nhập nhận xét chi tiết cho khách học thử.")
	case runeLen(in.Feedback) > 3000:
		return nil, "", errInvalid("Nhận xét không được vượt quá 3000 ký tự.")
	case b.Status == "cancelled":
		return nil, "", errInvalid("Buổi học thử đã bị hủy.")
	case b.SessionDate.After(s.today()):
		return nil, "", errInvalid("Buổi học thử chưa diễn ra — chỉ điểm danh / nhận xét từ ngày học.")
	}
	for _, v := range in.Remarks {
		if runeLen(v) > 255 {
			return nil, "", errInvalid("Mỗi đầu mục nhận xét không được vượt quá 255 ký tự.")
		}
	}
	var remarks []byte
	var rating *int16
	if attended {
		m := map[string]string{}
		for _, f := range RemarkFields {
			if v := strings.TrimSpace(in.Remarks[f.Value]); v != "" {
				m[f.Value] = v
			}
		}
		if len(m) > 0 {
			remarks, _ = json.Marshal(m)
		}
		rating = ptr(int16(*in.Rating))
	}
	b, err = s.q.SaveTrialFeedback(ctx, placementdb.SaveTrialFeedbackParams{
		ID: id, Status: in.Status, Rating: rating, Remarks: remarks, Feedback: strOrNil(in.Feedback), FeedbackBy: &a.UserID,
	})
	if err != nil {
		return nil, "", err
	}
	s.log.Info("placement: nhận xét học thử", "booking", id, "status", b.Status, "by", a.UserID)
	sessions, err := s.sessions.Lookup(ctx, []uuid.UUID{b.ClassSessionID})
	if err != nil {
		return nil, "", err
	}
	className := sessions[b.ClassSessionID].ClassName
	if className == "" {
		className = "lớp học"
	}
	label := className + " ngày " + dmy(b.SessionDate)
	var content string
	if attended {
		content = fmt.Sprintf("Nhận xét học thử (%d/5) buổi %s: ", *in.Rating, label)
		if sum := remarksSummary(remarks); sum != "" {
			content += sum + ". "
		}
		content += in.Feedback
	} else {
		content = "Khách vắng buổi học thử " + label + "."
		if in.Feedback != "" {
			content += " Ghi chú: " + in.Feedback
		}
	}
	s.record(ctx, LeadEvent{LeadID: b.LeadID, ActorID: &a.UserID, Type: "trial", Content: content})
	lead, err := s.leads.Get(ctx, b.LeadID)
	if err != nil {
		s.log.Error("placement: đọc khách học thử", "err", err, "lead", b.LeadID)
	}
	s.notifyTrialFeedback(ctx, a, b.ID, b.BookedBy, lead, label, attended, in.Rating, in.Feedback)
	rows, err := s.trialRows(ctx, []placementdb.CrmTrialBooking{b}, nil)
	if err != nil {
		return nil, "", err
	}
	return &rows[0], "Đã lưu nhận xét học thử vào hồ sơ khách — Học vụ đã được báo để chăm sóc khách.", nil
}
