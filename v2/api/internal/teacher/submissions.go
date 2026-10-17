package teacher

import (
	"context"
	"strings"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/files"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/teacher/teacherdb"
)

// Chấm bài nộp của lớp (StudentPortalController::teacherSubmissions / markSubmission): quyền homework.grade; lớp mình
// dạy / trợ giảng / GVNN, phạm vi Lớp học "Toàn hệ thống" → mọi lớp. Tab theo hạng mục bài tập + tab "Phát âm".

const pronunciationTab = "pronunciation"

// SubmissionTabs: thứ tự tab của màn chấm bài.
var SubmissionTabs = []Option{
	{"video", "Quay video"}, {"vocabulary", "Viết từ vựng"}, {"workbook", "Workbook"}, {"extra_book", "Sách bổ trợ"},
	{"bgd_book", "Sách bộ giáo dục"}, {"quiz", "Quiz"}, {pronunciationTab, "Phát âm"},
}

// Submission: một bài nộp trên màn chấm bài.
type Submission struct {
	ID             uuid.UUID `json:"id"`
	Status         string    `json:"status"` // submitted | reviewed
	StudentName    string    `json:"student_name"`
	SubmittedAt    string    `json:"submitted_at"` // dd/mm/YYYY HH:MM (như submitted_at Laravel ghi lúc nộp)
	Notes          string    `json:"notes"`
	AudioURL       string    `json:"audio_url"`  // bài phát âm
	UnitTitle      string    `json:"unit_title"` // bài phát âm
	AttachmentURL  string    `json:"attachment_url"`
	AttachmentName string    `json:"attachment_name"`
	HomeworkLabel  string    `json:"homework_label"`
	Score          string    `json:"score"`
	Feedback       string    `json:"feedback"`
}

type GradableClass struct {
	ID   uuid.UUID `json:"id"`
	Name string    `json:"name"`
	Code string    `json:"code"`
}

type SubmissionsPage struct {
	CurrentClass *GradableClass  `json:"current_class"`
	Classes      []GradableClass `json:"classes"`
	ActiveTab    string          `json:"active_tab"`
	Tabs         []Option        `json:"tabs"`
	Submissions  []Submission    `json:"submissions"`
	// PronunciationPending: tab "Phát âm" chờ phân hệ Cổng học viên.
	PronunciationPending bool `json:"pronunciation_pending"`
}

// SubmittedAt: giờ nộp hiển thị trên màn chấm bài.
func SubmittedAt(t time.Time) string { return t.In(vnZone).Format("02/01/2006 15:04") }

func guardGrader(a *iam.Access) error {
	if !a.Can("homework.grade") {
		return iam.ErrForbidden
	}
	return nil
}

func allClasses(a *iam.Access) bool { return a.Level("class") == iam.ScopeAll }

// Submissions: màn chấm bài (classID nil = lớp đầu tiên; typ lạ = "video").
func (s *Service) Submissions(ctx context.Context, a *iam.Access, classID *uuid.UUID, typ string) (*SubmissionsPage, error) {
	if err := guardGrader(a); err != nil {
		return nil, err
	}
	rows, err := s.q.GradableClasses(ctx, teacherdb.GradableClassesParams{AllClasses: allClasses(a), UserID: &a.UserID})
	if err != nil {
		return nil, err
	}
	out := &SubmissionsPage{Classes: make([]GradableClass, len(rows)), Tabs: SubmissionTabs, Submissions: []Submission{},
		ActiveTab: "video", PronunciationPending: !s.pron.Available()}
	for i, r := range rows {
		out.Classes[i] = GradableClass{r.ID, r.Name, r.Code}
		if classID != nil && r.ID == *classID {
			out.CurrentClass = &out.Classes[i]
		}
	}
	if classID != nil && out.CurrentClass == nil {
		return nil, errNotGrader
	}
	if out.CurrentClass == nil && len(out.Classes) > 0 {
		out.CurrentClass = &out.Classes[0]
	}
	for _, t := range SubmissionTabs {
		if t.Value == typ {
			out.ActiveTab = typ
		}
	}
	if out.CurrentClass == nil {
		return out, nil
	}
	cid := out.CurrentClass.ID
	if out.ActiveTab == pronunciationTab {
		subs, err := s.pron.ClassSubmissions(ctx, cid)
		if err != nil {
			return nil, err
		}
		if subs != nil {
			out.Submissions = subs
		}
		return out, nil
	}
	list, err := s.q.ClassSubmissions(ctx, teacherdb.ClassSubmissionsParams{ClassID: &cid, HomeworkType: out.ActiveTab})
	if err != nil {
		return nil, err
	}
	for _, r := range list {
		sub := Submission{ID: r.ID, Status: r.Status, StudentName: r.StudentName, SubmittedAt: SubmittedAt(r.CreatedAt),
			Notes: deref(r.Notes), AttachmentName: deref(r.AttachmentName), HomeworkLabel: submissionLabel(r.HomeworkType),
			Score: deref(r.Score), Feedback: deref(r.Feedback)}
		if r.HomeworkTitle != nil {
			sub.HomeworkLabel = *r.HomeworkTitle
		}
		if r.AttachmentFileID != nil {
			sub.AttachmentURL = files.URL(*r.AttachmentFileID)
		}
		out.Submissions = append(out.Submissions, sub)
	}
	return out, nil
}

// MarkInput: điểm (chữ, tối đa 20 ký tự; bài phát âm bắt buộc) + phản hồi (tối đa 1000 ký tự).
type MarkInput struct {
	Score    string `json:"score"`
	Feedback string `json:"feedback"`
}

const markedMsg = "Đã chấm điểm và gửi phản hồi bài nộp thành công!"

func (s *Service) gradable(ctx context.Context, a *iam.Access) func(uuid.UUID) (bool, error) {
	return func(studentID uuid.UUID) (bool, error) {
		return s.q.StudentGradable(ctx, teacherdb.StudentGradableParams{AllClasses: allClasses(a), UserID: &a.UserID, StudentID: studentID})
	}
}

// Mark: chấm bài nộp (hoặc bài phát âm của cổng học viên): trạng thái "đã chấm", lưu đúng điểm / phản hồi GV nhập.
func (s *Service) Mark(ctx context.Context, a *iam.Access, id uuid.UUID, in MarkInput) (string, error) {
	if err := guardGrader(a); err != nil {
		return "", err
	}
	score, feedback := strings.TrimSpace(in.Score), strings.TrimSpace(in.Feedback)
	if tooLong(score, 20) {
		return "", iam.Invalid("Điểm không được vượt quá 20 ký tự.")
	}
	if tooLong(feedback, 1000) {
		return "", iam.Invalid("Phản hồi không được vượt quá 1000 ký tự.")
	}
	sub, err := s.q.GetSubmission(ctx, id)
	if isNoRows(err) {
		// Không phải bài nộp bài tập → thử bài phát âm (cổng học viên).
		if !s.pron.Available() {
			return "", ErrSubmissionNotFound
		}
		if score == "" {
			return "", iam.Invalid("Vui lòng nhập điểm bài phát âm.")
		}
		ok, err := s.pron.Mark(ctx, id, a.UserID, score, feedback, s.gradable(ctx, a))
		if err != nil {
			return "", err
		}
		if !ok {
			return "", ErrSubmissionNotFound
		}
		return markedMsg, nil
	}
	if err != nil {
		return "", err
	}
	ok, err := s.gradable(ctx, a)(sub.StudentID)
	if err != nil {
		return "", err
	}
	if !ok {
		return "", ErrSubmissionNotFound
	}
	err = s.tx(ctx, func(tx pgx.Tx, q *teacherdb.Queries) error {
		if err := q.MarkSubmission(ctx, teacherdb.MarkSubmissionParams{ID: id, Score: optText(score), Feedback: optText(feedback),
			ReviewedBy: &a.UserID}); err != nil {
			return err
		}
		desc := "Chấm bài nộp " + categoryLabel(sub.HomeworkType) + " của " + sub.StudentName
		if score != "" {
			desc += " — điểm " + score
		}
		return audit.Record(ctx, tx, audit.Entry{ActorID: &a.UserID, Module: audit.ModuleStudents, Event: audit.EventUpdated,
			Action: "homework_submission.mark", SubjectType: "homework_submission", SubjectID: id.String(),
			SubjectLabel: sub.StudentName + " · " + categoryLabel(sub.HomeworkType), Description: desc})
	})
	if err != nil {
		return "", err
	}
	return markedMsg, nil
}

// readSubmissionFile: file bài nộp mở được bởi người chấm lớp của học viên, người tải lên, hoặc học viên / phụ huynh qua
// cổng học viên.
func (s *Service) readSubmissionFile(ctx context.Context, a *iam.Access, f files.File) (string, error) {
	if f.UploadedBy != nil && *f.UploadedBy == a.UserID {
		return "", nil
	}
	if a.Can("homework.grade") {
		sub, err := s.q.SubmissionByFile(ctx, &f.ID)
		if err != nil && !isNoRows(err) {
			return "", err
		}
		if err == nil {
			ok, err := s.gradable(ctx, a)(sub.StudentID)
			if err != nil {
				return "", err
			}
			if ok {
				return "", nil
			}
		}
	}
	if s.portalReader != nil {
		if ok, err := s.portalReader(ctx, a, f); err != nil || ok {
			return "", err
		}
	}
	return "", iam.ErrForbidden
}
