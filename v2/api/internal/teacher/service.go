// Package teacher: Cổng giáo viên (TeacherPortalController của Laravel) — lịch dạy hôm nay / tuần, check-in nhiều ca,
// buổi chưa điểm danh (điểm danh bù), giao bài tập về nhà, nhập điểm mini test, nhận xét buổi học, báo cáo giảng dạy
// tháng; chấm bài nộp của lớp (StudentPortalController::teacherSubmissions / markSubmission).
//
// Điểm danh học viên nằm ở phân hệ Lớp học (/class-attendance, màn /classes/<id>/attendance); khách học thử ở phân hệ
// Test đầu vào & học thử. Cổng học viên đọc / nộp bài qua StudentPortal (portal.go).
package teacher

import (
	"context"
	"errors"
	"log/slog"
	"slices"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgxpool"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/catalogs"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/files"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/teacher/teacherdb"
)

func init() {
	audit.RegisterSubject("homework", "Bài tập về nhà")
	audit.RegisterSubject("homework_submission", "Bài nộp")
	audit.RegisterSubject("mini_test_score", "Điểm mini test")
	audit.RegisterSubject("remark_sheet", "Nhận xét buổi học")
	audit.RegisterPath("/teacher", audit.ModuleStudents)
}

var (
	ErrClassNotFound      = &iam.Error{Status: 404, Code: "class_not_found", Message: "Không tìm thấy lớp học."}
	ErrSessionNotFound    = &iam.Error{Status: 404, Code: "session_not_found", Message: "Không tìm thấy buổi học."}
	ErrHomeworkNotFound   = &iam.Error{Status: 404, Code: "not_found", Message: "Không tìm thấy bài tập."}
	ErrSubmissionNotFound = &iam.Error{Status: 404, Code: "not_found", Message: "Không tìm thấy bài nộp."}
	errNotTeacher         = forbidden("Chỉ giáo viên / trợ giảng mới truy cập được cổng này.")
	errNotAssigned        = forbidden("Bạn không được phân công phụ trách lớp này.")
	errNotGrader          = forbidden("Bạn không phụ trách lớp này.")
)

func forbidden(msg string) *iam.Error {
	return &iam.Error{Status: 403, Code: "forbidden", Message: msg}
}

// ───────────── Phân hệ khác ─────────────

// Classes: phạm vi Lớp học của người dùng (classes.TeacherPortal) — Học vụ / Quản lý làm thay giáo viên cho lớp trong phạm vi.
type Classes interface {
	CanSee(ctx context.Context, a *iam.Access, classID uuid.UUID) (bool, error)
}

// SLA: luật SLA (catalogs.Service): khung điểm danh (gv.attendance_window), hạn check-in (gv.checkin_window).
type SLA interface {
	SlaRule(ctx context.Context, key string) (catalogs.SlaRule, error)
}

// Trials: khách học thử theo buổi (phân hệ Test đầu vào & học thử, adapter ở cmd/menglish).
type Trials interface {
	SessionGuests(ctx context.Context, sessionIDs []uuid.UUID) (map[uuid.UUID][]TrialGuest, error)
}

// TrialGuest: khách học thử của một buổi.
type TrialGuest struct {
	ID          uuid.UUID `json:"id"`
	Name        string    `json:"name"`
	TestScore   string    `json:"test_score"`
	StatusLabel string    `json:"status_label"`
	Notes       string    `json:"notes"`
}

type noTrials struct{}

func (noTrials) SessionGuests(context.Context, []uuid.UUID) (map[uuid.UUID][]TrialGuest, error) {
	return map[uuid.UUID][]TrialGuest{}, nil
}

// Service: phân hệ Cổng giáo viên.
type Service struct {
	pool    *pgxpool.Pool
	q       *teacherdb.Queries
	log     *slog.Logger
	now     func() time.Time
	classes Classes
	sla     SLA
	files   *files.Service
	trials  Trials
	work    Work
	syl     Syllabus
	support SupportList
	pron    Pronunciation
	// portalReader: cổng học viên mở file bài nộp / file nghe của chính học viên (StudentPortal.SetFileAccess).
	portalReader FileAccess
}

// Deps: phụ thuộc của phân hệ; trường để nil dùng mặc định "chưa có phân hệ".
type Deps struct {
	Classes Classes
	SLA     SLA
	Files   *files.Service
	Trials  Trials
	// TODO(gộp): các phân hệ đang làm song song — chấm công giờ dạy / lương / vi phạm (Work), giáo trình (Syllabus),
	// danh sách bổ trợ (SupportList). Bài phát âm của cổng học viên (Pronunciation): cmd/menglish gắn bằng WithPronunciation
	// (cổng học viên tạo sau phân hệ này).
	Work          Work
	Syllabus      Syllabus
	SupportList   SupportList
	Pronunciation Pronunciation
}

func NewService(pool *pgxpool.Pool, log *slog.Logger, d Deps) *Service {
	s := &Service{pool: pool, q: teacherdb.New(pool), log: log, now: time.Now, classes: d.Classes, sla: d.SLA, files: d.Files,
		trials: d.Trials, work: d.Work, syl: d.Syllabus, support: d.SupportList, pron: d.Pronunciation}
	if s.trials == nil {
		s.trials = noTrials{}
	}
	if s.work == nil {
		s.work = NoWork{}
	}
	if s.syl == nil {
		s.syl = NoSyllabus{}
	}
	if s.support == nil {
		s.support = NoSupportList{}
	}
	if s.pron == nil {
		s.pron = NoPronunciation{}
	}
	if s.files != nil {
		s.files.RegisterReader(audioSubject, s.readAudio)
		s.files.RegisterReader(submissionSubject, s.readSubmissionFile)
	}
	return s
}

// WithPronunciation: gắn bản ghi âm của cổng học viên (tab "Phát âm" của màn chấm bài).
func (s *Service) WithPronunciation(p Pronunciation) *Service {
	if p != nil {
		s.pron = p
	}
	return s
}

// ───────────── Tiện ích ─────────────

var vnZone = time.FixedZone("ICT", 7*3600)

// dateOf: ngày (giờ Việt Nam) của t, lúc 00:00 UTC — cùng cách pgx đọc cột date.
func dateOf(t time.Time) time.Time {
	y, m, d := t.In(vnZone).Date()
	return time.Date(y, m, d, 0, 0, 0, 0, time.UTC)
}

func (s *Service) today() time.Time { return dateOf(s.now()) }

func (s *Service) tx(ctx context.Context, fn func(tx pgx.Tx, q *teacherdb.Queries) error) error {
	return pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error { return fn(tx, s.q.WithTx(tx)) })
}

func isNoRows(err error) bool { return errors.Is(err, pgx.ErrNoRows) }

func ptr[T any](v T) *T { return &v }

func deref[T any](p *T) T {
	var zero T
	if p == nil {
		return zero
	}
	return *p
}

func optText(s string) *string {
	s = strings.TrimSpace(s)
	if s == "" {
		return nil
	}
	return &s
}

func tooLong(s string, n int) bool { return utf8.RuneCountInString(s) > n }

func hasID(ids []*uuid.UUID, id uuid.UUID) bool {
	return slices.ContainsFunc(ids, func(p *uuid.UUID) bool { return p != nil && *p == id })
}

// at: ngày + giờ "HH:MM" theo giờ Việt Nam.
func at(day time.Time, hm string) time.Time {
	h, m := 0, 0
	if len(hm) >= 5 {
		h = int(hm[0]-'0')*10 + int(hm[1]-'0')
		m = int(hm[3]-'0')*10 + int(hm[4]-'0')
	}
	return time.Date(day.Year(), day.Month(), day.Day(), h, m, 0, 0, vnZone)
}

// parseDay: "YYYY-MM-DD" → ngày (UTC 00:00); sai định dạng → false.
func parseDay(v string) (time.Time, bool) {
	t, err := time.Parse(time.DateOnly, strings.TrimSpace(v))
	return t, err == nil
}

// roomLabel: "Phòng P101"; tên đã có chữ "Phòng" giữ nguyên; trống → "".
func roomLabel(room string) string {
	room = strings.TrimSpace(room)
	switch {
	case room == "":
		return ""
	case strings.HasPrefix(strings.ToLower(room), "phòng"):
		return room
	}
	return "Phòng " + room
}

// shiftLabel: tên ca (Ca 1, Ca chiều…); tên tạm cũ "Slot n" không hiển thị.
func shiftLabel(name *string) string {
	n := strings.TrimSpace(deref(name))
	if strings.HasPrefix(n, "Slot") {
		return ""
	}
	return n
}

// typeLabel: nhãn loại buổi (buổi thường không có nhãn).
func typeLabel(t string) string {
	switch t {
	case "makeup":
		return "Học bù"
	case "support":
		return "Phụ đạo"
	}
	return ""
}

// slaHours: số giờ của luật SLA (thiếu / lỗi → mặc định).
func (s *Service) slaHours(ctx context.Context, key string, def int) int {
	if s.sla == nil {
		return def
	}
	r, err := s.sla.SlaRule(ctx, key)
	if err != nil || r.Value <= 0 {
		return def
	}
	return int(r.Value)
}

const defaultWindowHours = 24

// attendanceWindowHours: khung điểm danh ±N giờ của GV (SLA gv.attendance_window).
func (s *Service) attendanceWindowHours(ctx context.Context) int {
	return s.slaHours(ctx, "gv.attendance_window", defaultWindowHours)
}

// checkinWindowHours: hạn check-in = giờ bắt đầu + N giờ (SLA gv.checkin_window).
func (s *Service) checkinWindowHours(ctx context.Context) int {
	return s.slaHours(ctx, "gv.checkin_window", defaultWindowHours)
}

// ───────────── Quyền ─────────────

// guardTeacher: cổng giáo viên mở cho người điểm danh / nhập liệu lớp mình hoặc làm thay giáo viên.
func guardTeacher(a *iam.Access) error {
	if !a.CanAny("attendance_student.record", "attendance_student.record_any") {
		return errNotTeacher
	}
	return nil
}

type classInfo = teacherdb.GetClassRow

type sessionInfo = teacherdb.GetSessionRow

// isAssigned: người dùng là GV / GVNN / trợ giảng của lớp hoặc của buổi.
func isAssigned(uid uuid.UUID, c classInfo, se *sessionInfo) bool {
	ids := []*uuid.UUID{c.TeacherID, c.AssistantID, c.ForeignTeacherID}
	if se != nil {
		ids = append(ids, se.TeacherID, se.AssistantID, se.ForeignTeacherID)
	}
	return hasID(ids, uid)
}

// canActOnBehalf: làm thay giáo viên (giao bài, nhận xét, nhập điểm): attendance_student.record_any và lớp trong phạm vi.
func (s *Service) canActOnBehalf(ctx context.Context, a *iam.Access, classID uuid.UUID) (bool, error) {
	if !a.Can("attendance_student.record_any") || s.classes == nil {
		return false, nil
	}
	return s.classes.CanSee(ctx, a, classID)
}

func (s *Service) authorizeClass(ctx context.Context, a *iam.Access, c classInfo, se *sessionInfo) error {
	if isAssigned(a.UserID, c, se) {
		return nil
	}
	ok, err := s.canActOnBehalf(ctx, a, c.ID)
	if err != nil {
		return err
	}
	if !ok {
		return errNotAssigned
	}
	return nil
}

// loadClass: lớp (chưa xóa) + kiểm cổng giáo viên.
func (s *Service) loadClass(ctx context.Context, a *iam.Access, classID uuid.UUID) (classInfo, error) {
	if err := guardTeacher(a); err != nil {
		return classInfo{}, err
	}
	c, err := s.q.GetClass(ctx, classID)
	if isNoRows(err) {
		return c, ErrClassNotFound
	}
	return c, err
}

// classFor: lớp + kiểm phân công / làm thay (không theo buổi).
func (s *Service) classFor(ctx context.Context, a *iam.Access, classID uuid.UUID) (classInfo, error) {
	c, err := s.loadClass(ctx, a, classID)
	if err != nil {
		return c, err
	}
	return c, s.authorizeClass(ctx, a, c, nil)
}

// ───────────── Chọn buổi ─────────────

// resolveSession: buổi theo id (phải thuộc lớp), hoặc theo ngày (mặc định hôm nay): ưu tiên buổi người dùng được phân
// công (không phải phụ đạo), rồi buổi không phải phụ đạo, rồi buổi đầu tiên. nil = lớp không có buổi trong ngày.
func (s *Service) resolveSession(ctx context.Context, uid, classID uuid.UUID, sessionID *uuid.UUID, day *time.Time) (*sessionInfo, error) {
	if sessionID != nil {
		se, err := s.q.GetSession(ctx, *sessionID)
		if isNoRows(err) || (err == nil && se.ClassID != classID) {
			return nil, ErrSessionNotFound
		}
		if err != nil {
			return nil, err
		}
		return &se, nil
	}
	d := s.today()
	if day != nil {
		d = *day
	}
	rows, err := s.q.SessionsOnDate(ctx, teacherdb.SessionsOnDateParams{ClassID: classID, Day: d})
	if err != nil || len(rows) == 0 {
		return nil, err
	}
	pick := rows[0].ID
	found := false
	for _, r := range rows {
		if r.Type != "support" && hasID([]*uuid.UUID{r.TeacherID, r.AssistantID, r.ForeignTeacherID}, uid) {
			pick, found = r.ID, true
			break
		}
	}
	if !found {
		for _, r := range rows {
			if r.Type != "support" {
				pick = r.ID
				break
			}
		}
	}
	se, err := s.q.GetSession(ctx, pick)
	if err != nil {
		return nil, err
	}
	return &se, nil
}

// blockReason: buổi đã hủy; buổi chưa tới ngày (nhận xét).
func (s *Service) blockReason(se *sessionInfo) string {
	if se.Status == "cancelled" {
		if se.HolidayID != nil {
			return "Buổi học này đã hủy (nghỉ lễ) — không điểm danh."
		}
		return "Buổi học này đã hủy — không điểm danh."
	}
	if se.Date.After(s.today()) {
		return "Buổi học ngày " + se.Date.Format("02/01/2006") + " chưa diễn ra — chưa điểm danh được."
	}
	return ""
}

// SessionChoice: một dòng ô chọn buổi.
type SessionChoice struct {
	ID       uuid.UUID `json:"id"`
	Label    string    `json:"label"`
	Disabled bool      `json:"disabled"`
}

// sessionChoiceLabel: "dd/mm/YYYY · HH:MM-HH:MM · Học bù/Phụ đạo" (+ "· Đã hủy").
func sessionChoiceLabel(date time.Time, start, end, typ, status string) string {
	label := date.Format("02/01/2006") + " · " + start + "-" + end
	if t := typeLabel(typ); t != "" {
		label += " · " + t
	}
	if status == "cancelled" {
		label += " · Đã hủy"
	}
	return label
}

// ClassRef: lớp trên đầu trang.
type ClassRef struct {
	ID         uuid.UUID `json:"id"`
	Name       string    `json:"name"`
	Code       string    `json:"code"`
	BranchName string    `json:"branch_name"`
}

func classRef(c classInfo) ClassRef { return ClassRef{c.ID, c.Name, c.Code, c.BranchName} }

// guestsOf: khách học thử của một buổi (khối "Khách học thử buổi này").
type TrialGuests struct {
	// past: buổi đã qua (nhãn "đã học thử"); upcoming: sắp tới
	Scope string       `json:"scope"`
	Items []TrialGuest `json:"items"`
}

func (s *Service) guestsOf(ctx context.Context, se *sessionInfo) (TrialGuests, error) {
	out := TrialGuests{Scope: "upcoming", Items: []TrialGuest{}}
	if se == nil {
		return out, nil
	}
	m, err := s.trials.SessionGuests(ctx, []uuid.UUID{se.ID})
	if err != nil {
		return out, err
	}
	if g := m[se.ID]; len(g) > 0 {
		out.Items = g
	}
	if se.Date.Before(s.today()) {
		out.Scope = "past"
	}
	return out, nil
}
