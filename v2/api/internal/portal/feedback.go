package portal

import (
	"context"
	"errors"
	"strings"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/portal/portaldb"
)

// ───── Khảo sát 5 sao ─────

type SurveyItem struct {
	OpenSurvey
	IsUrgent   bool   `json:"is_urgent"`
	StatusText string `json:"status_text"`
}

type SurveyPage struct {
	Context
	Surveys     []SurveyItem     `json:"surveys"`
	PastSurveys []SurveyResponse `json:"past_surveys"`
	Ready       bool             `json:"ready"`
}

// Surveys: đợt khảo sát đang mở và khảo sát đã gửi của học viên (studentSurvey).
func (s *Service) Surveys(ctx context.Context, actor *iam.Access, studentID *uuid.UUID) (*SurveyPage, error) {
	a, err := s.resolve(ctx, actor, studentID)
	if err != nil {
		return nil, err
	}
	out := &SurveyPage{Context: a.Context, Surveys: []SurveyItem{}, PastSurveys: []SurveyResponse{}, Ready: s.surveys.Ready()}
	open, err := s.surveys.Open(ctx)
	if err != nil {
		return nil, err
	}
	today := s.today()
	for _, o := range open {
		it := SurveyItem{OpenSurvey: o, StatusText: "Hạn: Chưa đặt hạn"}
		if o.Deadline != nil {
			d := o.Deadline.In(time.UTC)
			it.IsUrgent = d.Year() == today.Year() && d.YearDay() == today.YearDay()
			if it.IsUrgent {
				it.StatusText = "Hạn: Hôm nay"
			} else {
				it.StatusText = "Hạn: " + d.Format("02/01/2006")
			}
		}
		out.Surveys = append(out.Surveys, it)
	}
	if a.Student != nil {
		past, err := s.surveys.Responses(ctx, a.id())
		if err != nil {
			return nil, err
		}
		out.PastSurveys = append(out.PastSurveys, past...)
	}
	return out, nil
}

type SurveyAnswer struct {
	StudentID *uuid.UUID `json:"student_id"`
	SurveyID  *uuid.UUID `json:"survey_id"`
	Rating    *int       `json:"rating"`
	Feedback  string     `json:"feedback"`
}

// SubmitSurvey: gửi ý kiến khảo sát (submitSurvey): ý kiến tối thiểu 5 ký tự, 1–5 sao (mặc định 5).
func (s *Service) SubmitSurvey(ctx context.Context, actor *iam.Access, in SurveyAnswer) error {
	in.Feedback = strings.TrimSpace(in.Feedback)
	switch {
	case in.StudentID == nil:
		return errNoStudent
	case in.SurveyID == nil:
		return iam.Invalid("Vui lòng chọn khảo sát.")
	case in.Feedback == "":
		return iam.Invalid("Vui lòng nhập ý kiến của bạn.")
	case runes(in.Feedback) < 5:
		return iam.Invalid("Trường ý kiến phải có tối thiểu 5 ký tự.")
	case runes(in.Feedback) > 5000:
		return iam.Invalid("Trường ý kiến không được vượt quá 5000 ký tự.")
	case in.Rating != nil && (*in.Rating < 1 || *in.Rating > 5):
		return iam.Invalid("Mức độ hài lòng phải từ 1 đến 5 sao.")
	}
	rating := 5
	if in.Rating != nil {
		rating = *in.Rating
	}
	st, err := s.writable(ctx, actor, *in.StudentID)
	if err != nil {
		return err
	}
	if !s.surveys.Ready() {
		return errPending("Khảo sát")
	}
	return pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
		if err := s.surveys.Respond(ctx, tx, SurveyInput{SurveyID: *in.SurveyID, StudentID: st.ID, StudentName: st.FullName,
			Rating: rating, Feedback: in.Feedback, By: actor.UserID}); err != nil {
			return err
		}
		return audit.Record(ctx, tx, audit.Entry{ActorID: &actor.UserID, Module: audit.ModuleTests, Event: audit.EventCreated,
			Action: "portal.survey_submit", SubjectType: "student", SubjectID: st.ID.String(), SubjectLabel: st.FullName,
			BranchID: st.BranchID, Description: "Gửi khảo sát chất lượng - " + st.FullName})
	})
}

// DeleteSurvey: xóa khảo sát đã gửi để làm lại (deleteSurvey).
func (s *Service) DeleteSurvey(ctx context.Context, actor *iam.Access, id uuid.UUID) error {
	if !s.surveys.Ready() {
		return errPending("Khảo sát")
	}
	owner, err := s.surveys.ResponseOwner(ctx, id)
	if errors.Is(err, pgx.ErrNoRows) {
		return ErrNotFound
	}
	if err != nil {
		return err
	}
	st, err := s.writable(ctx, actor, owner)
	if err != nil {
		return err
	}
	return pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
		if err := s.surveys.DeleteResponse(ctx, tx, id); err != nil {
			return err
		}
		return audit.Record(ctx, tx, audit.Entry{ActorID: &actor.UserID, Module: audit.ModuleTests, Event: audit.EventDeleted,
			Action: "portal.survey_delete", SubjectType: "student", SubjectID: st.ID.String(), SubjectLabel: st.FullName,
			BranchID: st.BranchID, Description: "Xóa khảo sát đã gửi - " + st.FullName})
	})
}

// ───── Góp ý chặng học ─────

// GeneralStage: chưa có chặng đang học thì là góp ý chung.
const GeneralStage = "Góp ý chung"

type StageFeedback struct {
	ID         uuid.UUID `json:"id"`
	Code       string    `json:"code"`
	StageName  string    `json:"stage_name"`
	ClassName  *string   `json:"class_name"`
	Rating     int       `json:"rating"`
	FbAcademic bool      `json:"fb_hoc_thuat"`
	FbTeacher  bool      `json:"fb_giao_vien"`
	FbOther    bool      `json:"fb_khac"`
	Content    string    `json:"content"`
	UpdatedAt  time.Time `json:"submitted_at"`
}

func stageFeedback(f portaldb.AcademicStageFeedback) StageFeedback {
	return StageFeedback{ID: f.ID, Code: f.Code, StageName: f.StageName, ClassName: f.ClassName, Rating: int(f.Rating),
		FbAcademic: f.FbAcademic, FbTeacher: f.FbTeacher, FbOther: f.FbOther, Content: f.Content, UpdatedAt: f.UpdatedAt}
}

type FeedbackPage struct {
	Context
	StageName string          `json:"stage_name"`
	ClassName string          `json:"class_name"`
	Last      *StageFeedback  `json:"last_feedback"`
	History   []StageFeedback `json:"history"`
}

// stage: chặng đang học của lớp chính (giao chặng in_progress), không có thì "Góp ý chung".
func (s *Service) stage(ctx context.Context, classID *uuid.UUID) (string, error) {
	if classID == nil {
		return GeneralStage, nil
	}
	name, err := s.syllabus.CurrentStage(ctx, *classID)
	if err != nil {
		return "", err
	}
	if name == nil || strings.TrimSpace(*name) == "" {
		return GeneralStage, nil
	}
	return strings.TrimSpace(*name), nil
}

// Feedback: màn góp ý chặng học (studentFeedback): chặng đang mở, góp ý đã gửi gần nhất.
func (s *Service) Feedback(ctx context.Context, actor *iam.Access, studentID *uuid.UUID) (*FeedbackPage, error) {
	a, err := s.resolve(ctx, actor, studentID)
	if err != nil {
		return nil, err
	}
	out := &FeedbackPage{Context: a.Context, StageName: GeneralStage, ClassName: "Chưa xếp lớp", History: []StageFeedback{}}
	if a.Student == nil {
		return out, nil
	}
	if a.row.ClassName != nil {
		out.ClassName = *a.row.ClassName
	}
	if out.StageName, err = s.stage(ctx, a.row.CurrentClassID); err != nil {
		return nil, err
	}
	rows, err := s.q.StudentFeedbacks(ctx, a.id())
	if err != nil {
		return nil, err
	}
	for _, r := range rows {
		out.History = append(out.History, stageFeedback(r))
	}
	if len(out.History) > 0 {
		out.Last = &out.History[0]
	}
	return out, nil
}

type FeedbackInput struct {
	StudentID  *uuid.UUID `json:"student_id"`
	Rating     int        `json:"rating"`
	FbAcademic bool       `json:"fb_hoc_thuat"`
	FbTeacher  bool       `json:"fb_giao_vien"`
	FbOther    bool       `json:"fb_khac"`
	Content    string     `json:"content"`
}

// SubmitFeedback: gửi / cập nhật góp ý chặng (submitFeedback): một bản mỗi học viên mỗi chặng, gửi lại thì cập nhật.
// Cần ít nhất một mục: số sao, lĩnh vực hoặc nội dung (R-04). Chặng lấy theo lớp hiện tại ở máy chủ.
func (s *Service) SubmitFeedback(ctx context.Context, actor *iam.Access, in FeedbackInput) (*StageFeedback, bool, error) {
	in.Content = strings.TrimSpace(in.Content)
	switch {
	case in.StudentID == nil:
		return nil, false, errNoStudent
	case in.Rating < 0 || in.Rating > 5:
		return nil, false, iam.Invalid("Mức độ hài lòng phải từ 0 đến 5 sao.")
	case in.Rating == 0 && !in.FbAcademic && !in.FbTeacher && !in.FbOther && in.Content == "":
		return nil, false, iam.Invalid("Vui lòng điền ít nhất 1 mục (Nội dung, Lĩnh vực góp ý hoặc Chọn mức hài lòng) để gửi feedback.")
	case runes(in.Content) > 5000:
		return nil, false, iam.Invalid("Trường nội dung feedback không được vượt quá 5000 ký tự.")
	}
	st, err := s.writable(ctx, actor, *in.StudentID)
	if err != nil {
		return nil, false, err
	}
	stage, err := s.stage(ctx, st.CurrentClassID)
	if err != nil {
		return nil, false, err
	}
	var out StageFeedback
	var created bool
	err = pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
		r, err := s.q.WithTx(tx).UpsertStageFeedback(ctx, portaldb.UpsertStageFeedbackParams{Code: randomCode("FB-"),
			StudentID: st.ID, StageName: stage, ClassID: st.CurrentClassID, ClassName: st.ClassName, Rating: int16(in.Rating),
			FbAcademic: in.FbAcademic, FbTeacher: in.FbTeacher, FbOther: in.FbOther, Content: in.Content, CreatedBy: &actor.UserID})
		if err != nil {
			return err
		}
		created = r.Inserted
		out = stageFeedback(portaldb.AcademicStageFeedback{ID: r.ID, Code: r.Code, StudentID: r.StudentID, StageName: r.StageName,
			ClassID: r.ClassID, ClassName: r.ClassName, Rating: r.Rating, FbAcademic: r.FbAcademic, FbTeacher: r.FbTeacher,
			FbOther: r.FbOther, Content: r.Content, CreatedBy: r.CreatedBy, CreatedAt: r.CreatedAt, UpdatedAt: r.UpdatedAt})
		event, desc := audit.EventUpdated, "Cập nhật feedback chặng: "
		if created {
			event, desc = audit.EventCreated, "Feedback chặng: "
		}
		return audit.Record(ctx, tx, audit.Entry{ActorID: &actor.UserID, Module: audit.ModuleStudents, Event: event,
			Action: "portal.feedback_submit", SubjectType: "stage_feedback", SubjectID: r.ID.String(), SubjectLabel: r.Code,
			BranchID: st.BranchID, Description: desc + stage + " - " + st.FullName})
	})
	if err != nil {
		return nil, false, err
	}
	return &out, created, nil
}

// DeleteFeedback: xóa góp ý đã lưu để nhập lại (deleteFeedback).
func (s *Service) DeleteFeedback(ctx context.Context, actor *iam.Access, id uuid.UUID) error {
	f, err := s.q.GetStageFeedback(ctx, id)
	if errors.Is(err, pgx.ErrNoRows) {
		return ErrNotFound
	}
	if err != nil {
		return err
	}
	st, err := s.writable(ctx, actor, f.StudentID)
	if err != nil {
		return err
	}
	return pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
		if err := s.q.WithTx(tx).DeleteStageFeedback(ctx, id); err != nil {
			return err
		}
		return audit.Record(ctx, tx, audit.Entry{ActorID: &actor.UserID, Module: audit.ModuleStudents, Event: audit.EventDeleted,
			Action: "portal.feedback_delete", SubjectType: "stage_feedback", SubjectID: id.String(), SubjectLabel: f.Code,
			BranchID: st.BranchID, Description: "Xóa feedback chặng: " + f.StageName + " - " + st.FullName})
	})
}
