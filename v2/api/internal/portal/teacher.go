package portal

import (
	"context"
	"errors"
	"slices"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/files"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/portal/portaldb"
)

// Màn "Chấm bài nộp của lớp" (teacherSubmissions / markSubmission): giáo viên, trợ giảng (homework.grade) xem bài nộp
// của học viên trong lớp mình phụ trách theo từng loại, tab "Phát âm" là bản ghi âm học viên nộp ở màn Luyện phát âm.

// TabPronunciation: tab bản ghi âm của màn chấm bài.
const TabPronunciation = "pronunciation"

var errNotYourClass = &iam.Error{Status: 403, Code: "forbidden", Message: "Bạn không phụ trách lớp này."}

type TeacherClass struct {
	ID   uuid.UUID `json:"id"`
	Code string    `json:"code"`
	Name string    `json:"name"`
}

// TeacherItem: một bài nộp / bản ghi âm trên màn chấm bài.
type TeacherItem struct {
	ID             uuid.UUID `json:"id"`
	Status         string    `json:"status"`
	StudentName    string    `json:"student_name"`
	SubmittedAt    time.Time `json:"submitted_at"`
	Notes          *string   `json:"notes"`
	AudioURL       *string   `json:"audio_url"`
	UnitTitle      *string   `json:"unit_title"`
	Duration       *string   `json:"duration"`
	AttachmentURL  *string   `json:"attachment_url"`
	AttachmentName *string   `json:"attachment_name"`
	HomeworkLabel  *string   `json:"homework_label"`
	Score          *string   `json:"score"`
	Feedback       *string   `json:"feedback"`
}

type TeacherSubmissions struct {
	Classes      []TeacherClass `json:"classes"`
	CurrentClass *TeacherClass  `json:"current_class"`
	ActiveTab    string         `json:"active_tab"`
	Submissions  []TeacherItem  `json:"submissions"`
	// Ready: chấm bài nộp (không phải phát âm) cần phân hệ Cổng giáo viên
	Ready bool `json:"ready"`
}

func teacherTab(t string) string {
	if t == TabPronunciation {
		return t
	}
	if _, ok := homeworkLabel(t); ok {
		return t
	}
	return HomeworkTypes[0].Key
}

func (s *Service) teacherParams(actor *iam.Access) (bool, *uuid.UUID) {
	uid := actor.UserID
	return actor.Level("class") == iam.ScopeAll, &uid
}

// TeacherSubmissions: lớp giáo viên phụ trách và bài nộp của lớp đang chọn theo tab.
func (s *Service) TeacherSubmissions(ctx context.Context, actor *iam.Access, classID *uuid.UUID, tab string) (*TeacherSubmissions, error) {
	all, uid := s.teacherParams(actor)
	rows, err := s.q.TeacherClasses(ctx, portaldb.TeacherClassesParams{AllClasses: all, UserID: uid})
	if err != nil {
		return nil, err
	}
	out := &TeacherSubmissions{Classes: make([]TeacherClass, 0, len(rows)), ActiveTab: teacherTab(tab),
		Submissions: []TeacherItem{}, Ready: s.teacher.Ready()}
	for _, r := range rows {
		out.Classes = append(out.Classes, TeacherClass{ID: r.ID, Code: r.Code, Name: r.Name})
	}
	if classID != nil {
		i := slices.IndexFunc(out.Classes, func(c TeacherClass) bool { return c.ID == *classID })
		if i < 0 {
			return nil, errNotYourClass
		}
		out.CurrentClass = &out.Classes[i]
	} else if len(out.Classes) > 0 {
		out.CurrentClass = &out.Classes[0]
	}
	if out.CurrentClass == nil {
		return out, nil
	}
	ids, err := s.q.ClassRoster(ctx, out.CurrentClass.ID)
	if err != nil {
		return nil, err
	}
	if len(ids) == 0 {
		return out, nil
	}
	if out.ActiveTab == TabPronunciation {
		recs, err := s.q.ClassPronunciations(ctx, ids)
		if err != nil {
			return nil, err
		}
		for _, r := range recs {
			it := TeacherItem{ID: r.ID, Status: r.Status, StudentName: r.StudentName, SubmittedAt: r.CreatedAt,
				UnitTitle: &r.UnitTitle, Duration: r.Duration, Score: r.Score, Feedback: r.Feedback}
			if r.AudioFileID != nil {
				it.AudioURL = ptr(files.URL(*r.AudioFileID))
			}
			out.Submissions = append(out.Submissions, it)
		}
		return out, nil
	}
	subs, err := s.teacher.ClassSubmissions(ctx, ids, out.ActiveTab)
	if err != nil {
		return nil, err
	}
	label, _ := homeworkLabel(out.ActiveTab)
	for _, sub := range subs {
		out.Submissions = append(out.Submissions, TeacherItem{ID: sub.ID, Status: sub.Status, StudentName: sub.StudentName,
			SubmittedAt: sub.SubmittedAt, Notes: sub.Notes, AttachmentURL: sub.AttachmentURL, AttachmentName: sub.AttachmentName,
			HomeworkLabel: &label, Score: sub.Score, Feedback: sub.Feedback})
	}
	return out, nil
}

// ReviewInput: điểm (bắt buộc với bài phát âm) và nhận xét của giáo viên.
type ReviewInput struct {
	Score    *string `json:"score"`
	Feedback *string `json:"feedback"`
}

func (in *ReviewInput) validate(scoreRequired bool) error {
	in.Score, in.Feedback = trimPtr(in.Score), trimPtr(in.Feedback)
	if scoreRequired && in.Score == nil {
		return iam.Invalid("Vui lòng nhập điểm.")
	}
	if err := maxLen(in.Score, 20, "Trường điểm không được vượt quá 20 ký tự."); err != nil {
		return err
	}
	return maxLen(in.Feedback, 1000, "Trường nhận xét không được vượt quá 1000 ký tự.")
}

// covers: giáo viên phụ trách (một) lớp đang học của học viên.
func (s *Service) covers(ctx context.Context, actor *iam.Access, studentID uuid.UUID) (bool, error) {
	all, uid := s.teacherParams(actor)
	return s.q.TeacherCoversStudent(ctx, portaldb.TeacherCoversStudentParams{AllClasses: all, UserID: uid, StudentID: studentID})
}

// ReviewPronunciation: chấm bản ghi âm (điểm bắt buộc). Ngoài lớp phụ trách → 404 như Laravel. Học viên nhận thông
// báo kết quả ở hộp thư cổng.
func (s *Service) ReviewPronunciation(ctx context.Context, actor *iam.Access, id uuid.UUID, in ReviewInput) error {
	r, err := s.q.GetPronunciation(ctx, id)
	if errors.Is(err, pgx.ErrNoRows) {
		return ErrNotFound
	}
	if err != nil {
		return err
	}
	ok, err := s.covers(ctx, actor, r.StudentID)
	if err != nil {
		return err
	}
	if !ok {
		return ErrNotFound
	}
	if err := in.validate(true); err != nil {
		return err
	}
	uid := actor.UserID
	return pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
		q := s.q.WithTx(tx)
		if err := q.ReviewPronunciation(ctx, portaldb.ReviewPronunciationParams{Score: in.Score, Feedback: in.Feedback,
			ReviewedBy: &uid, ID: id}); err != nil {
			return err
		}
		body := "Giáo viên đã chấm bản ghi âm \"" + r.UnitTitle + "\": " + *in.Score + "."
		if in.Feedback != nil {
			body += " Nhận xét: " + *in.Feedback
		}
		if err := s.sendPortal(ctx, tx, r.StudentID, notify.Notification{Type: "pronunciation_reviewed",
			Title: "Bài luyện phát âm đã được chấm", Body: body, Link: "/portal/pronunciation", CreatedBy: &uid,
			Data: map[string]any{"record_id": id.String()}}); err != nil {
			return err
		}
		return audit.Record(ctx, tx, audit.Entry{ActorID: &uid, Module: audit.ModuleStudents, Event: audit.EventUpdated,
			Action: "portal.pronunciation_review", SubjectType: "pronunciation_record", SubjectID: id.String(), SubjectLabel: r.Code,
			Description: "Chấm bản ghi âm: " + r.UnitTitle,
			Changes: audit.Diff(map[string]any{"status": r.Status, "score": r.Score, "feedback": r.Feedback},
				map[string]any{"status": "reviewed", "score": in.Score, "feedback": in.Feedback})})
	})
}

// ReviewSubmission: chấm bài nộp (điểm tùy chọn) qua phân hệ Cổng giáo viên.
func (s *Service) ReviewSubmission(ctx context.Context, actor *iam.Access, id uuid.UUID, in ReviewInput) error {
	if !s.teacher.Ready() {
		return errPending("Cổng giáo viên")
	}
	owner, err := s.teacher.SubmissionOwner(ctx, id)
	if errors.Is(err, pgx.ErrNoRows) {
		return ErrNotFound
	}
	if err != nil {
		return err
	}
	ok, err := s.covers(ctx, actor, owner)
	if err != nil {
		return err
	}
	if !ok {
		return ErrNotFound
	}
	if err := in.validate(false); err != nil {
		return err
	}
	uid := actor.UserID
	desc := "Chấm bài nộp"
	if in.Score != nil {
		desc += ": " + *in.Score
	}
	return pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
		if err := s.teacher.ReviewSubmission(ctx, tx, id, uid, in.Score, in.Feedback); err != nil {
			return err
		}
		return audit.Record(ctx, tx, audit.Entry{ActorID: &uid, Module: audit.ModuleStudents, Event: audit.EventUpdated,
			Action: "portal.submission_review", SubjectType: "homework_submission", SubjectID: id.String(),
			Description: desc})
	})
}
