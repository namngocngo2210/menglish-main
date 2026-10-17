// Package surveys: khảo sát chất lượng đào tạo (SurveyController + màn #6 "Khảo sát 5 sao" của Cổng PH/HS).
// Học vụ (survey.manage) tạo / sửa / đóng / xóa đợt khảo sát; học viên / phụ huynh (portal.student) chấm sao và góp ý
// cho đợt đang mở, xem và xóa bài đã nộp của mình.
package surveys

import (
	"context"
	"errors"
	"log/slog"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgxpool"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/surveys/surveysdb"
)

const (
	PermManage = "survey.manage"
	PermPortal = "portal.student"
)

var (
	ErrNotFound         = &iam.Error{Status: 404, Code: "survey_not_found", Message: "Không tìm thấy đợt khảo sát."}
	ErrResponseNotFound = &iam.Error{Status: 404, Code: "survey_response_not_found", Message: "Không tìm thấy bài khảo sát."}
	ErrStudentForbidden = &iam.Error{Status: 403, Code: "forbidden", Message: "Bạn không được phép truy cập hồ sơ học viên này."}
	ErrNotLinked        = &iam.Error{Status: 403, Code: "forbidden", Message: "Tài khoản chưa được liên kết với hồ sơ học viên."}
)

// vnZone: ngày nghiệp vụ theo giờ Việt Nam.
var vnZone = time.FixedZone("Asia/Ho_Chi_Minh", 7*3600)

// PortalStudent: học viên tài khoản được thao tác ở cổng.
type PortalStudent struct {
	ID   uuid.UUID `json:"id"`
	Name string    `json:"name"`
	Code string    `json:"code"`
}

// Students: bộ chọn học viên của Cổng PH/HS (getActiveStudent). Mặc định: học viên liên kết tài khoản (chính học viên
// hoặc con của phụ huynh). TODO(gộp): phân hệ Cổng PH/HS cung cấp bộ chọn chung (kể cả nhân sự student.update xem thử
// học viên trong phạm vi) thì truyền vào WithStudents.
type Students interface {
	Portal(ctx context.Context, a *iam.Access) ([]PortalStudent, error)
}

type linkedStudents struct{ q *surveysdb.Queries }

func (l linkedStudents) Portal(ctx context.Context, a *iam.Access) ([]PortalStudent, error) {
	rows, err := l.q.PortalStudents(ctx, a.UserID)
	if err != nil {
		return nil, err
	}
	out := make([]PortalStudent, len(rows))
	for i, r := range rows {
		out[i] = PortalStudent{ID: r.ID, Name: r.FullName, Code: r.Code}
	}
	return out, nil
}

type Service struct {
	pool     *pgxpool.Pool
	q        *surveysdb.Queries
	log      *slog.Logger
	students Students
	now      func() time.Time
}

func NewService(pool *pgxpool.Pool, log *slog.Logger) *Service {
	q := surveysdb.New(pool)
	return &Service{pool: pool, q: q, log: log, students: linkedStudents{q}, now: time.Now}
}

// WithStudents thay bộ chọn học viên của cổng (nil giữ mặc định).
func (s *Service) WithStudents(st Students) *Service {
	if st != nil {
		s.students = st
	}
	return s
}

func (s *Service) tx(ctx context.Context, fn func(tx pgx.Tx, q *surveysdb.Queries) error) error {
	return pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error { return fn(tx, s.q.WithTx(tx)) })
}

func (s *Service) today() time.Time {
	t := s.now().In(vnZone)
	return time.Date(t.Year(), t.Month(), t.Day(), 0, 0, 0, 0, time.UTC)
}

// ───────────── Quản lý đợt khảo sát ─────────────

type Survey struct {
	ID            uuid.UUID `json:"id"`
	Title         string    `json:"title"`
	Description   *string   `json:"description"`
	Deadline      *string   `json:"deadline"`       // YYYY-MM-DD
	DeadlineLabel *string   `json:"deadline_label"` // dd/mm/yyyy
	DeadlineToday bool      `json:"deadline_today"`
	IsActive      bool      `json:"is_active"`
	CreatorName   *string   `json:"creator_name"`
	ResponseCount int64     `json:"response_count"`
}

type ListPage struct {
	Today   string   `json:"today"`
	Surveys []Survey `json:"surveys"`
}

func (s *Service) deadline(d *time.Time) (iso, label *string, today bool) {
	if d == nil {
		return nil, nil, false
	}
	a, b := d.Format("2006-01-02"), d.Format("02/01/2006")
	return &a, &b, d.Equal(s.today())
}

func (s *Service) List(ctx context.Context, a *iam.Access) (*ListPage, error) {
	rows, err := s.q.ListSurveys(ctx)
	if err != nil {
		return nil, err
	}
	out := make([]Survey, len(rows))
	for i, r := range rows {
		iso, label, today := s.deadline(r.Deadline)
		out[i] = Survey{ID: r.ID, Title: r.Title, Description: r.Description, Deadline: iso, DeadlineLabel: label,
			DeadlineToday: today, IsActive: r.IsActive, CreatorName: r.CreatorName, ResponseCount: r.ResponseCount}
	}
	return &ListPage{Today: s.today().Format("2006-01-02"), Surveys: out}, nil
}

// Input: tạo / sửa đợt khảo sát. IsActive chỉ dùng khi sửa (bỏ trống = đóng, như $request->boolean).
type Input struct {
	Title       string  `json:"title"`
	Description *string `json:"description"`
	Deadline    *string `json:"deadline"`
	IsActive    *bool   `json:"is_active"`
}

type Result struct {
	ID      uuid.UUID `json:"id"`
	Message string    `json:"message"`
}

func runes(v string) int { return utf8.RuneCountInString(v) }

func trimmed(p *string) *string {
	if p == nil {
		return nil
	}
	v := strings.TrimSpace(*p)
	if v == "" {
		return nil
	}
	return &v
}

// normalize kiểm như validate() của SurveyController; create thêm deadline >= hôm nay.
func (s *Service) normalize(in *Input, create bool) (*time.Time, error) {
	in.Title = strings.TrimSpace(in.Title)
	in.Description = trimmed(in.Description)
	if in.Title == "" {
		return nil, iam.Invalid("Vui lòng nhập Tiêu đề.")
	}
	if runes(in.Title) > 255 {
		return nil, iam.Invalid("Trường Tiêu đề không được vượt quá 255 ký tự.")
	}
	if in.Description != nil && runes(*in.Description) > 2000 {
		return nil, iam.Invalid("Trường Mô tả chi tiết không được vượt quá 2000 ký tự.")
	}
	d := trimmed(in.Deadline)
	if d == nil {
		return nil, nil
	}
	t, err := time.Parse("2006-01-02", *d)
	if err != nil {
		return nil, iam.Invalid("Trường Hạn hoàn thành không phải là ngày hợp lệ.")
	}
	if create && t.Before(s.today()) {
		return nil, iam.Invalid("Trường Hạn hoàn thành phải là thời gian bắt đầu sau hoặc đúng bằng hôm nay.")
	}
	return &t, nil
}

func entry(a *iam.Access, event, action string, sv surveysdb.OpsSurvey, desc string, props map[string]any) audit.Entry {
	return audit.Entry{ActorID: &a.UserID, Module: audit.ModuleTests, Event: event, Action: action, SubjectType: "survey",
		SubjectID: sv.ID.String(), SubjectLabel: sv.Title, Description: desc, Properties: props}
}

func (s *Service) Create(ctx context.Context, a *iam.Access, in Input) (*Result, error) {
	d, err := s.normalize(&in, true)
	if err != nil {
		return nil, err
	}
	var out *Result
	err = s.tx(ctx, func(tx pgx.Tx, q *surveysdb.Queries) error {
		sv, err := q.InsertSurvey(ctx, surveysdb.InsertSurveyParams{Title: in.Title, Description: in.Description, Deadline: d, CreatedBy: &a.UserID})
		if err != nil {
			return err
		}
		out = &Result{ID: sv.ID, Message: "Đã tạo đợt khảo sát mới."}
		return audit.Record(ctx, tx, entry(a, audit.EventCreated, "survey.create", sv, "Tạo đợt khảo sát: "+sv.Title,
			map[string]any{"deadline": dateText(sv.Deadline)}))
	})
	return out, err
}

func dateText(d *time.Time) any {
	if d == nil {
		return nil
	}
	return d.Format("2006-01-02")
}

func (s *Service) Update(ctx context.Context, a *iam.Access, id uuid.UUID, in Input) (*Result, error) {
	d, err := s.normalize(&in, false)
	if err != nil {
		return nil, err
	}
	active := in.IsActive != nil && *in.IsActive
	var out *Result
	err = s.tx(ctx, func(tx pgx.Tx, q *surveysdb.Queries) error {
		old, err := q.GetSurvey(ctx, id)
		if errors.Is(err, pgx.ErrNoRows) {
			return ErrNotFound
		} else if err != nil {
			return err
		}
		sv, err := q.UpdateSurvey(ctx, surveysdb.UpdateSurveyParams{ID: id, Title: in.Title, Description: in.Description, Deadline: d, IsActive: active})
		if err != nil {
			return err
		}
		// Thông báo dùng tên mới ($survey sau update của Laravel).
		out = &Result{ID: sv.ID, Message: "Đã cập nhật khảo sát \"" + sv.Title + "\"."}
		return audit.Record(ctx, tx, entry(a, audit.EventUpdated, "survey.update", sv, "Cập nhật đợt khảo sát: "+sv.Title,
			map[string]any{"old": map[string]any{"title": old.Title, "deadline": dateText(old.Deadline), "is_active": old.IsActive},
				"new": map[string]any{"title": sv.Title, "deadline": dateText(sv.Deadline), "is_active": sv.IsActive}}))
	})
	return out, err
}

// Delete: xóa mềm; bài học viên đã nộp vẫn giữ (survey_title chụp lúc nộp).
func (s *Service) Delete(ctx context.Context, a *iam.Access, id uuid.UUID) (*Result, error) {
	var out *Result
	err := s.tx(ctx, func(tx pgx.Tx, q *surveysdb.Queries) error {
		sv, err := q.SoftDeleteSurvey(ctx, id)
		if errors.Is(err, pgx.ErrNoRows) {
			return ErrNotFound
		} else if err != nil {
			return err
		}
		out = &Result{ID: sv.ID, Message: "Đã xóa khảo sát \"" + sv.Title + "\"."}
		return audit.Record(ctx, tx, entry(a, audit.EventDeleted, "survey.delete", sv, "Xóa đợt khảo sát: "+sv.Title, nil))
	})
	return out, err
}

// Response: bài đã nộp của một đợt (màn quản lý; bổ sung so với Laravel — bản cũ chỉ xem qua bảng academic_records).
type Response struct {
	ID          uuid.UUID `json:"id"`
	StudentID   uuid.UUID `json:"student_id"`
	StudentName string    `json:"student_name"`
	StudentCode string    `json:"student_code"`
	Submitter   *string   `json:"submitter"`
	Rating      int16     `json:"rating"`
	Feedback    string    `json:"feedback"`
	CreatedAt   time.Time `json:"created_at"`
}

type ResponsesPage struct {
	Survey    Survey     `json:"survey"`
	Responses []Response `json:"responses"`
	Average   *float64   `json:"average"`
}

func (s *Service) Responses(ctx context.Context, a *iam.Access, id uuid.UUID) (*ResponsesPage, error) {
	sv, err := s.q.GetSurvey(ctx, id)
	if errors.Is(err, pgx.ErrNoRows) {
		return nil, ErrNotFound
	} else if err != nil {
		return nil, err
	}
	rows, err := s.q.SurveyResponses(ctx, &id)
	if err != nil {
		return nil, err
	}
	iso, label, today := s.deadline(sv.Deadline)
	page := &ResponsesPage{Survey: Survey{ID: sv.ID, Title: sv.Title, Description: sv.Description, Deadline: iso,
		DeadlineLabel: label, DeadlineToday: today, IsActive: sv.IsActive, ResponseCount: int64(len(rows))},
		Responses: make([]Response, len(rows))}
	sum := 0
	for i, r := range rows {
		page.Responses[i] = Response{ID: r.ID, StudentID: r.StudentID, StudentName: r.StudentName, StudentCode: r.StudentCode,
			Submitter: r.SubmitterName, Rating: r.Rating, Feedback: r.Feedback, CreatedAt: r.CreatedAt}
		sum += int(r.Rating)
	}
	if len(rows) > 0 {
		avg := float64(sum) / float64(len(rows))
		page.Average = &avg
	}
	return page, nil
}

// ───────────── Cổng PH/HS: Khảo sát 5 sao ─────────────

type OpenSurvey struct {
	ID          uuid.UUID `json:"id"`
	Title       string    `json:"title"`
	Description *string   `json:"description"`
	Deadline    string    `json:"deadline"` // dd/mm/yyyy hoặc "Chưa đặt hạn"
	IsUrgent    bool      `json:"is_urgent"`
	StatusText  string    `json:"status_text"`
}

type PastResponse struct {
	ID          uuid.UUID  `json:"id"`
	SurveyID    *uuid.UUID `json:"survey_id"`
	Title       string     `json:"title"` // "Khảo sát: <đợt> - <học viên>" như AcademicRecord.title
	SurveyTitle string     `json:"survey_title"`
	Rating      int16      `json:"rating"`
	Feedback    string     `json:"feedback"`
	SubmittedAt string     `json:"submitted_at"` // dd/mm/yyyy HH:ii
}

type PortalPage struct {
	Student  *PortalStudent  `json:"student"`
	Students []PortalStudent `json:"students"`
	Surveys  []OpenSurvey    `json:"surveys"`
	Past     []PastResponse  `json:"past_surveys"`
}

// active: học viên đang chọn (studentID nil = học viên đầu), như getActiveStudent.
func (s *Service) active(ctx context.Context, a *iam.Access, studentID *uuid.UUID) ([]PortalStudent, *PortalStudent, error) {
	list, err := s.students.Portal(ctx, a)
	if err != nil {
		return nil, nil, err
	}
	if studentID != nil {
		for i := range list {
			if list[i].ID == *studentID {
				return list, &list[i], nil
			}
		}
		return nil, nil, ErrStudentForbidden
	}
	if len(list) == 0 {
		return nil, nil, ErrNotLinked
	}
	return list, &list[0], nil
}

func (s *Service) Portal(ctx context.Context, a *iam.Access, studentID *uuid.UUID) (*PortalPage, error) {
	list, st, err := s.active(ctx, a, studentID)
	if err != nil {
		return nil, err
	}
	open, err := s.q.OpenSurveys(ctx)
	if err != nil {
		return nil, err
	}
	page := &PortalPage{Student: st, Students: list, Surveys: make([]OpenSurvey, len(open)), Past: []PastResponse{}}
	for i, sv := range open {
		text, urgent := "Chưa đặt hạn", false
		if sv.Deadline != nil {
			text, urgent = sv.Deadline.Format("02/01/2006"), sv.Deadline.Equal(s.today())
		}
		status := "Hạn: " + text
		if urgent {
			status = "Hạn: Hôm nay"
		}
		page.Surveys[i] = OpenSurvey{ID: sv.ID, Title: sv.Title, Description: sv.Description, Deadline: text, IsUrgent: urgent, StatusText: status}
	}
	past, err := s.q.StudentResponses(ctx, st.ID)
	if err != nil {
		return nil, err
	}
	for _, r := range past {
		page.Past = append(page.Past, PastResponse{ID: r.ID, SurveyID: r.SurveyID, Title: "Khảo sát: " + r.SurveyTitle + " - " + st.Name,
			SurveyTitle: r.SurveyTitle, Rating: r.Rating, Feedback: r.Feedback, SubmittedAt: r.CreatedAt.In(vnZone).Format("02/01/2006 15:04")})
	}
	return page, nil
}

// SubmitInput: bài khảo sát (submitSurvey). v2 gửi survey_id thay cho survey_title (đợt phải đang mở).
type SubmitInput struct {
	StudentID string `json:"student_id"`
	SurveyID  string `json:"survey_id"`
	Rating    *int   `json:"rating"`
	Feedback  string `json:"feedback"`
}

func (s *Service) Submit(ctx context.Context, a *iam.Access, in SubmitInput) (*Result, error) {
	if strings.TrimSpace(in.StudentID) == "" {
		return nil, iam.Invalid("Vui lòng nhập Học viên.")
	}
	sid, err := uuid.Parse(strings.TrimSpace(in.StudentID))
	if err != nil {
		return nil, iam.Invalid("Giá trị đã chọn trong trường Học viên không tồn tại.")
	}
	if strings.TrimSpace(in.SurveyID) == "" {
		return nil, iam.Invalid("Vui lòng chọn đợt khảo sát.")
	}
	svID, err := uuid.Parse(strings.TrimSpace(in.SurveyID))
	if err != nil {
		return nil, iam.Invalid("Đợt khảo sát đã đóng hoặc không còn tồn tại.")
	}
	in.Feedback = strings.TrimSpace(in.Feedback)
	if in.Feedback == "" {
		return nil, iam.Invalid("Vui lòng nhập Góp ý.")
	}
	if runes(in.Feedback) < 5 {
		return nil, iam.Invalid("Trường Góp ý phải có tối thiểu 5 ký tự.")
	}
	if runes(in.Feedback) > 5000 {
		return nil, iam.Invalid("Trường Góp ý không được vượt quá 5000 ký tự.")
	}
	rating := 5
	if in.Rating != nil {
		rating = *in.Rating
	}
	if rating < 1 {
		return nil, iam.Invalid("Trường Số sao phải tối thiểu là 1.")
	}
	if rating > 5 {
		return nil, iam.Invalid("Trường Số sao không được lớn hơn 5.")
	}
	_, st, err := s.active(ctx, a, &sid)
	if err != nil {
		return nil, err
	}
	sv, err := s.q.GetSurvey(ctx, svID)
	if errors.Is(err, pgx.ErrNoRows) || (err == nil && !sv.IsActive) {
		return nil, iam.Invalid("Đợt khảo sát đã đóng hoặc không còn tồn tại.")
	} else if err != nil {
		return nil, err
	}
	var out *Result
	err = s.tx(ctx, func(tx pgx.Tx, q *surveysdb.Queries) error {
		r, err := q.InsertResponse(ctx, surveysdb.InsertResponseParams{SurveyID: &sv.ID, SurveyTitle: sv.Title, StudentID: st.ID,
			UserID: &a.UserID, Rating: int16(rating), Feedback: in.Feedback})
		if err != nil {
			return err
		}
		out = &Result{ID: r.ID, Message: "Cảm ơn bạn đã gửi khảo sát! Trung tâm sẽ tiếp nhận và cải thiện chất lượng dịch vụ."}
		return audit.Record(ctx, tx, audit.Entry{ActorID: &a.UserID, Module: audit.ModuleTests, Event: audit.EventCreated,
			Action: "survey.submit", SubjectType: "survey_response", SubjectID: r.ID.String(), SubjectLabel: st.Name,
			Description: "Gửi khảo sát: " + sv.Title + " - " + st.Name, Properties: map[string]any{"rating": rating, "survey_id": sv.ID}})
	})
	return out, err
}

// DeleteResponse: xóa bài của học viên mình được thao tác (deleteSurvey) để làm lại.
func (s *Service) DeleteResponse(ctx context.Context, a *iam.Access, id uuid.UUID) (*Result, error) {
	r, err := s.q.GetResponse(ctx, id)
	if errors.Is(err, pgx.ErrNoRows) {
		return nil, ErrResponseNotFound
	} else if err != nil {
		return nil, err
	}
	_, st, err := s.active(ctx, a, &r.StudentID)
	if err != nil {
		return nil, err
	}
	err = s.tx(ctx, func(tx pgx.Tx, q *surveysdb.Queries) error {
		if err := q.DeleteResponse(ctx, id); err != nil {
			return err
		}
		return audit.Record(ctx, tx, audit.Entry{ActorID: &a.UserID, Module: audit.ModuleTests, Event: audit.EventDeleted,
			Action: "survey.response_delete", SubjectType: "survey_response", SubjectID: id.String(), SubjectLabel: st.Name,
			Description: "Xóa bài khảo sát: " + r.SurveyTitle + " - " + st.Name})
	})
	if err != nil {
		return nil, err
	}
	return &Result{ID: id, Message: "Đã xóa bài khảo sát thành công! Bạn có thể thực hiện lại."}, nil
}
