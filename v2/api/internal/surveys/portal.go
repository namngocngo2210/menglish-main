package surveys

import (
	"context"
	"errors"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/surveys/surveysdb"
)

// Phần dữ liệu cho màn "Khảo sát 5 sao" của Cổng PH/HS (StudentPortalController::studentSurvey / submitSurvey /
// deleteSurvey). Cổng (internal/portal) lo bộ chọn học viên, kiểm dữ liệu nhập và nhật ký; adapter portal.Surveys ở
// cmd/menglish gọi các hàm dưới đây.

// ErrClosed: đợt khảo sát đã đóng / đã xóa / không tồn tại khi học viên gửi bài.
var ErrClosed = iam.Invalid("Đợt khảo sát đã đóng hoặc không còn tồn tại.")

// Open: đợt khảo sát đang mở (Survey::active), hạn tăng dần, đợt chưa đặt hạn lên đầu.
type Open struct {
	ID          uuid.UUID
	Title       string
	Description *string
	Deadline    *time.Time // ngày (00:00 UTC)
}

func (s *Service) OpenSurveys(ctx context.Context) ([]Open, error) {
	rows, err := s.q.OpenSurveys(ctx)
	if err != nil {
		return nil, err
	}
	out := make([]Open, 0, len(rows))
	for _, r := range rows {
		out = append(out, Open{ID: r.ID, Title: r.Title, Description: r.Description, Deadline: r.Deadline})
	}
	return out, nil
}

// Submitted: bài khảo sát học viên đã gửi. Title như AcademicRecord.title: "Khảo sát: <đợt> - <học viên>".
type Submitted struct {
	ID          uuid.UUID
	SurveyID    *uuid.UUID
	Title       string
	Rating      int
	Feedback    string
	SubmittedAt time.Time
}

// StudentResponses: bài đã gửi của học viên, mới nhất trước.
func (s *Service) StudentResponses(ctx context.Context, studentID uuid.UUID) ([]Submitted, error) {
	rows, err := s.q.StudentResponses(ctx, studentID)
	if err != nil {
		return nil, err
	}
	out := make([]Submitted, 0, len(rows))
	for _, r := range rows {
		out = append(out, Submitted{ID: r.ID, SurveyID: r.SurveyID, Title: "Khảo sát: " + r.SurveyTitle + " - " + r.StudentName,
			Rating: int(r.Rating), Feedback: r.Feedback, SubmittedAt: r.CreatedAt})
	}
	return out, nil
}

// Answer: bài khảo sát đã qua kiểm của cổng (1–5 sao, góp ý 5–5000 ký tự, học viên trong phạm vi người gửi).
type Answer struct {
	SurveyID  uuid.UUID
	StudentID uuid.UUID
	By        uuid.UUID
	Rating    int
	Feedback  string
}

// Respond: lưu bài khảo sát trong tx của cổng; đợt phải còn mở (lưu kèm tên đợt lúc gửi).
func (s *Service) Respond(ctx context.Context, tx pgx.Tx, in Answer) error {
	q := s.q.WithTx(tx)
	sv, err := q.GetSurvey(ctx, in.SurveyID)
	if errors.Is(err, pgx.ErrNoRows) || (err == nil && !sv.IsActive) {
		return ErrClosed
	}
	if err != nil {
		return err
	}
	_, err = q.InsertResponse(ctx, surveysdb.InsertResponseParams{SurveyID: &sv.ID, SurveyTitle: sv.Title, StudentID: in.StudentID,
		UserID: &in.By, Rating: int16(in.Rating), Feedback: in.Feedback})
	return err
}

// ResponseStudent: học viên của bài khảo sát (pgx.ErrNoRows khi không có).
func (s *Service) ResponseStudent(ctx context.Context, id uuid.UUID) (uuid.UUID, error) {
	r, err := s.q.GetResponse(ctx, id)
	if err != nil {
		return uuid.Nil, err
	}
	return r.StudentID, nil
}

// RemoveResponse: xóa bài khảo sát (deleteSurvey) để học viên làm lại.
func (s *Service) RemoveResponse(ctx context.Context, tx pgx.Tx, id uuid.UUID) error {
	return s.q.WithTx(tx).DeleteResponse(ctx, id)
}
