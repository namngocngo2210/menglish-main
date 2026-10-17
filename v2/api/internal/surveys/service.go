// Package surveys: khảo sát chất lượng đào tạo (SurveyController + dữ liệu màn #6 "Khảo sát 5 sao" của Cổng PH/HS).
// Học vụ (survey.manage) tạo / sửa / đóng / xóa đợt khảo sát; học viên / phụ huynh chấm sao và góp ý cho đợt đang mở
// qua API của Cổng PH/HS (/portal/survey, adapter portal.Surveys ở cmd/menglish gọi portal.go).
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

const PermManage = "survey.manage"

var (
	ErrNotFound = &iam.Error{Status: 404, Code: "survey_not_found", Message: "Không tìm thấy đợt khảo sát."}
)

// vnZone: ngày nghiệp vụ theo giờ Việt Nam.
var vnZone = time.FixedZone("Asia/Ho_Chi_Minh", 7*3600)

type Service struct {
	pool *pgxpool.Pool
	q    *surveysdb.Queries
	log  *slog.Logger
	now  func() time.Time
}

func NewService(pool *pgxpool.Pool, log *slog.Logger) *Service {
	return &Service{pool: pool, q: surveysdb.New(pool), log: log, now: time.Now}
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
