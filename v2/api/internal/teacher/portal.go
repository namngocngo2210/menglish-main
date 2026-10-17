package teacher

import (
	"context"
	"slices"
	"strings"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/files"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/filestore"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/teacher/teacherdb"
)

// StudentPortal: API cho phân hệ Cổng học viên / phụ huynh (StudentPortalController::submitHomework / updateHomework /
// deleteHomework, bài tập / nhận xét / điểm mini test của học viên). Bảng bài tập + bài nộp thuộc phân hệ này; cổng học
// viên tự kiểm người dùng được thao tác cho học viên nào (authorizeStudent) rồi gọi các hàm dưới với studentID đã kiểm.
//
// TODO(gộp): phân hệ Cổng học viên lấy qua teacherSvc.StudentPortal() ở cmd/menglish và gọi SetFileAccess.
type StudentPortal struct{ s *Service }

func (s *Service) StudentPortal() *StudentPortal { return &StudentPortal{s} }

// SubmissionPolicy: file bài nộp (ảnh, tài liệu, video, audio; tối đa 100 MB như Laravel max:102400).
var SubmissionPolicy = filestore.Policy{
	Types:    slices.Concat(filestore.Images, filestore.Documents, filestore.Video, filestore.Audio),
	MaxBytes: 102400 << 10, TooLarge: "File bài nộp tối đa 100 MB.",
}

// SetFileAccess: cổng học viên cho học viên / phụ huynh mở file bài nộp của mình và file nghe bài tập lớp mình.
func (p *StudentPortal) SetFileAccess(fn FileAccess) { p.s.portalReader = fn }

// StudentSubmission: bài nộp của học viên.
type StudentSubmission struct {
	ID             uuid.UUID  `json:"id"`
	StudentID      uuid.UUID  `json:"student_id"`
	HomeworkID     *uuid.UUID `json:"homework_id"`
	HomeworkType   string     `json:"homework_type"`
	HomeworkLabel  string     `json:"homework_label"`
	Notes          string     `json:"notes"`
	AttachmentID   *uuid.UUID `json:"attachment_id"`
	AttachmentURL  string     `json:"attachment_url"`
	AttachmentName string     `json:"attachment_name"`
	Status         string     `json:"status"`
	Score          string     `json:"score"`
	Feedback       string     `json:"feedback"`
	ReviewedAt     *time.Time `json:"reviewed_at"`
	SubmittedAt    time.Time  `json:"submitted_at"`
	UpdatedAt      time.Time  `json:"updated_at"`
}

func toStudentSubmission(r teacherdb.GetStudentSubmissionRow) StudentSubmission {
	out := StudentSubmission{ID: r.ID, StudentID: r.StudentID, HomeworkID: r.HomeworkID, HomeworkType: r.HomeworkType,
		HomeworkLabel: categoryLabel(r.HomeworkType), Notes: deref(r.Notes), AttachmentID: r.AttachmentFileID,
		AttachmentName: deref(r.AttachmentName), Status: r.Status, Score: deref(r.Score), Feedback: deref(r.Feedback),
		ReviewedAt: r.ReviewedAt, SubmittedAt: r.CreatedAt, UpdatedAt: r.UpdatedAt}
	if r.AttachmentFileID != nil {
		out.AttachmentURL = files.URL(*r.AttachmentFileID)
	}
	return out
}

// SubmitInput: nộp bài (Upload đã qua Check(SubmissionPolicy), nil = không đính kèm).
type SubmitInput struct {
	StudentID    uuid.UUID
	HomeworkID   *uuid.UUID
	HomeworkType string
	Notes        string
	Upload       *filestore.Upload
}

var errSubmissionChanged = &iam.Error{Status: 409, Code: "conflict", Message: "Bài nộp đã được giáo viên chấm, không sửa / xóa được."}

func (p *StudentPortal) saveAttachment(ctx context.Context, tx pgx.Tx, by *uuid.UUID, subID uuid.UUID, up *filestore.Upload) (*uuid.UUID, *string, error) {
	if up == nil {
		return nil, nil, nil
	}
	if p.s.files == nil {
		return nil, nil, iam.Invalid("Kho file chưa được cấu hình.")
	}
	f, err := p.s.files.Save(ctx, tx, up, files.SaveInput{
		Owner: files.Owner{Module: "teacher", SubjectType: submissionSubject, SubjectID: &subID},
		By:    by,
		Audit: audit.Entry{Module: audit.ModuleStudents, SubjectType: "homework_submission", SubjectID: subID.String(),
			Description: "Tải file bài nộp"},
	})
	if err != nil {
		return nil, nil, err
	}
	return &f.ID, &f.Name, nil
}

// Submit: nộp bài mới cho học viên (by = tài khoản nộp).
func (p *StudentPortal) Submit(ctx context.Context, by *uuid.UUID, in SubmitInput) (*StudentSubmission, error) {
	if !validCategory(in.HomeworkType) {
		return nil, iam.Invalid("Loại bài tập không hợp lệ.")
	}
	if tooLong(strings.TrimSpace(in.Notes), 5000) {
		return nil, iam.Invalid("Ghi chú không được vượt quá 5000 ký tự.")
	}
	var id uuid.UUID
	err := p.s.tx(ctx, func(tx pgx.Tx, q *teacherdb.Queries) error {
		name, err := q.StudentName(ctx, in.StudentID)
		if isNoRows(err) {
			return &iam.Error{Status: 404, Code: "not_found", Message: "Không tìm thấy học viên."}
		}
		if err != nil {
			return err
		}
		if in.HomeworkID != nil {
			// Bài phải thuộc lớp của học viên.
			ok, err := q.HomeworkOfStudent(ctx, teacherdb.HomeworkOfStudentParams{ID: *in.HomeworkID, StudentID: in.StudentID})
			if err != nil {
				return err
			}
			if !ok {
				return iam.Invalid("Bài tập không thuộc lớp của học viên.")
			}
		}
		row, err := q.InsertSubmission(ctx, teacherdb.InsertSubmissionParams{StudentID: in.StudentID, HomeworkID: in.HomeworkID,
			HomeworkType: in.HomeworkType, Notes: optText(in.Notes), SubmittedBy: by})
		if err != nil {
			return err
		}
		id = row.ID
		fileID, fileName, err := p.saveAttachment(ctx, tx, by, id, in.Upload)
		if err != nil {
			return err
		}
		if fileID != nil {
			if err := q.UpdateSubmission(ctx, teacherdb.UpdateSubmissionParams{ID: id, AttachmentFileID: fileID, AttachmentName: fileName}); err != nil {
				return err
			}
		}
		return audit.Record(ctx, tx, audit.Entry{ActorID: by, Module: audit.ModuleStudents, Event: audit.EventCreated,
			Action: "homework_submission.create", SubjectType: "homework_submission", SubjectID: id.String(),
			SubjectLabel: name + " · " + categoryLabel(in.HomeworkType), Description: "Nộp bài " + categoryLabel(in.HomeworkType) + " — " + name})
	})
	if err != nil {
		return nil, err
	}
	return p.Submission(ctx, in.StudentID, id)
}

// Submission: một bài nộp của học viên (khác học viên → 404).
func (p *StudentPortal) Submission(ctx context.Context, studentID, id uuid.UUID) (*StudentSubmission, error) {
	r, err := p.s.q.GetStudentSubmission(ctx, id)
	if isNoRows(err) || (err == nil && r.StudentID != studentID) {
		return nil, ErrSubmissionNotFound
	}
	if err != nil {
		return nil, err
	}
	out := toStudentSubmission(r)
	return &out, nil
}

// Update: sửa ghi chú (notes nil = giữ) và / hoặc thay file bài nộp chưa chấm.
func (p *StudentPortal) Update(ctx context.Context, by *uuid.UUID, studentID, id uuid.UUID, notes *string, up *filestore.Upload) (*StudentSubmission, error) {
	if notes != nil && tooLong(strings.TrimSpace(*notes), 5000) {
		return nil, iam.Invalid("Ghi chú không được vượt quá 5000 ký tự.")
	}
	cur, err := p.Submission(ctx, studentID, id)
	if err != nil {
		return nil, err
	}
	if cur.Status == "reviewed" {
		return nil, errSubmissionChanged
	}
	err = p.s.tx(ctx, func(tx pgx.Tx, q *teacherdb.Queries) error {
		fileID, fileName, err := p.saveAttachment(ctx, tx, by, id, up)
		if err != nil {
			return err
		}
		if fileID != nil && cur.AttachmentID != nil && p.s.files != nil {
			if _, err := p.s.files.Delete(ctx, tx, *cur.AttachmentID, by, audit.Entry{Module: audit.ModuleStudents,
				SubjectType: "homework_submission", SubjectID: id.String(), Description: "Thay file bài nộp"}); err != nil && err != files.ErrNotFound {
				return err
			}
		}
		prm := teacherdb.UpdateSubmissionParams{ID: id, AttachmentFileID: fileID, AttachmentName: fileName}
		if notes != nil {
			prm.SetNotes, prm.Notes = true, optText(*notes)
		}
		if err := q.UpdateSubmission(ctx, prm); err != nil {
			return err
		}
		return audit.Record(ctx, tx, audit.Entry{ActorID: by, Module: audit.ModuleStudents, Event: audit.EventUpdated,
			Action: "homework_submission.update", SubjectType: "homework_submission", SubjectID: id.String(),
			SubjectLabel: cur.HomeworkLabel, Description: "Cập nhật bài nộp " + cur.HomeworkLabel})
	})
	if err != nil {
		return nil, err
	}
	return p.Submission(ctx, studentID, id)
}

// Delete: hủy bài nộp chưa chấm (file xóa mềm).
func (p *StudentPortal) Delete(ctx context.Context, by *uuid.UUID, studentID, id uuid.UUID) error {
	cur, err := p.Submission(ctx, studentID, id)
	if err != nil {
		return err
	}
	if cur.Status == "reviewed" {
		return errSubmissionChanged
	}
	return p.s.tx(ctx, func(tx pgx.Tx, q *teacherdb.Queries) error {
		if cur.AttachmentID != nil && p.s.files != nil {
			if _, err := p.s.files.Delete(ctx, tx, *cur.AttachmentID, by, audit.Entry{Module: audit.ModuleStudents,
				SubjectType: "homework_submission", SubjectID: id.String(), Description: "Xóa bài nộp"}); err != nil && err != files.ErrNotFound {
				return err
			}
		}
		if err := q.DeleteSubmission(ctx, id); err != nil {
			return err
		}
		return audit.Record(ctx, tx, audit.Entry{ActorID: by, Module: audit.ModuleStudents, Event: audit.EventDeleted,
			Action: "homework_submission.delete", SubjectType: "homework_submission", SubjectID: id.String(),
			SubjectLabel: cur.HomeworkLabel, Description: "Xóa bài nộp " + cur.HomeworkLabel})
	})
}

// Submissions: bài nộp của học viên, mới nhất trước.
func (p *StudentPortal) Submissions(ctx context.Context, studentID uuid.UUID) ([]StudentSubmission, error) {
	rows, err := p.s.q.StudentSubmissions(ctx, studentID)
	if err != nil {
		return nil, err
	}
	out := make([]StudentSubmission, len(rows))
	for i, r := range rows {
		out[i] = toStudentSubmission(teacherdb.GetStudentSubmissionRow(r))
	}
	return out, nil
}

// ClassHomework: bài tập của lớp (cổng học viên).
type ClassHomework struct {
	ID          uuid.UUID      `json:"id"`
	ClassID     uuid.UUID      `json:"class_id"`
	ClassName   string         `json:"class_name"`
	SessionID   *uuid.UUID     `json:"session_id"`
	Title       string         `json:"title"`
	Description string         `json:"description"`
	ClassNote   string         `json:"class_note"`
	YoutubeURL  string         `json:"youtube_url"`
	QuizizzURL  string         `json:"quizizz_url"`
	AudioURL    string         `json:"audio_url"`
	Items       []HomeworkItem `json:"items"`
	DueAt       *time.Time     `json:"due_at"`
	DueLabel    string         `json:"due_label"`
	CreatedAt   time.Time      `json:"created_at"`
}

// Homeworks: bài tập của các lớp (lớp chính + liên kết của học viên), mới nhất trước.
func (p *StudentPortal) Homeworks(ctx context.Context, classIDs []uuid.UUID, limit int) ([]ClassHomework, error) {
	rows, err := p.s.q.ClassesHomeworks(ctx, teacherdb.ClassesHomeworksParams{ClassIds: classIDs, Lim: int32(limit)})
	if err != nil {
		return nil, err
	}
	out := make([]ClassHomework, len(rows))
	for i, r := range rows {
		items := parseItems(r.Items)
		h := ClassHomework{ID: r.ID, ClassID: r.ClassID, ClassName: r.ClassName, SessionID: r.SessionID, Title: r.Title,
			Description: deref(r.Description), ClassNote: deref(r.ClassNote), YoutubeURL: deref(r.YoutubeUrl), QuizizzURL: deref(r.QuizizzUrl),
			Items: []HomeworkItem{}, DueAt: r.DueAt, DueLabel: dueLabel(r.DueAt, r.DueDate), CreatedAt: r.CreatedAt}
		if r.AudioFileID != nil {
			h.AudioURL = files.URL(*r.AudioFileID)
		}
		for _, k := range itemKeys(items) {
			h.Items = append(h.Items, HomeworkItem{Key: k, Label: categoryLabel(k), Text: items[k]})
		}
		out[i] = h
	}
	return out, nil
}

// HomeworkClass: lớp của bài tập gắn file nghe (cổng học viên kiểm học viên có học lớp này không).
func (p *StudentPortal) HomeworkClass(ctx context.Context, audioFileID uuid.UUID) (uuid.UUID, bool, error) {
	id, err := p.s.q.HomeworkAudioOwner(ctx, &audioFileID)
	if isNoRows(err) {
		return uuid.Nil, false, nil
	}
	return id, err == nil, err
}

// SubmissionStudent: học viên của bài nộp gắn file (cổng học viên kiểm quyền mở file).
func (p *StudentPortal) SubmissionStudent(ctx context.Context, fileID uuid.UUID) (uuid.UUID, bool, error) {
	r, err := p.s.q.SubmissionByFile(ctx, &fileID)
	if isNoRows(err) {
		return uuid.Nil, false, nil
	}
	return r.StudentID, err == nil, err
}

// StudentRemark: nhận xét đã lưu (không phải nháp) của một buổi.
type StudentRemark struct {
	Date   time.Time         `json:"date"`
	Remark map[string]string `json:"remark"`
}

func (p *StudentPortal) Remarks(ctx context.Context, studentID uuid.UUID, classIDs []uuid.UUID, limit int) ([]StudentRemark, error) {
	rows, err := p.s.q.StudentRemarks(ctx, teacherdb.StudentRemarksParams{StudentID: studentID, ClassIds: classIDs, Lim: int32(limit)})
	if err != nil {
		return nil, err
	}
	out := make([]StudentRemark, len(rows))
	for i, r := range rows {
		out[i] = StudentRemark{Date: r.Date, Remark: remarkMap(&teacherdb.SheetRemarksRow{MonstersGroup: r.MonstersGroup,
			MonstersBonus: r.MonstersBonus, Grammar: r.Grammar, Attitude: r.Attitude, Result: r.Result, Comment: r.Comment})}
	}
	return out, nil
}

// MiniTest: điểm mini test của học viên.
type MiniTest struct {
	ID       uuid.UUID          `json:"id"`
	ClassID  uuid.UUID          `json:"class_id"`
	Name     string             `json:"name"`
	TestDate time.Time          `json:"test_date"`
	Score    *float64           `json:"score"`
	MaxScore float64            `json:"max_score"`
	Skills   map[string]float64 `json:"skills"`
	Note     string             `json:"note"`
	Low      bool               `json:"low"`
}

func (p *StudentPortal) MiniTests(ctx context.Context, studentID uuid.UUID, limit int) ([]MiniTest, error) {
	rows, err := p.s.q.StudentMiniTests(ctx, teacherdb.StudentMiniTestsParams{StudentID: studentID, Lim: int32(limit)})
	if err != nil {
		return nil, err
	}
	out := make([]MiniTest, len(rows))
	for i, r := range rows {
		m := MiniTest{ID: r.ID, ClassID: r.ClassID, Name: r.Name, TestDate: r.TestDate, Score: r.Score, MaxScore: r.MaxScore,
			Skills: map[string]float64{}, Note: deref(r.Note), Low: isLow(r.Score, r.MaxScore)}
		for k, v := range parseSkills(r.SkillScores) {
			if v != nil {
				m.Skills[k] = *v
			}
		}
		out[i] = m
	}
	return out, nil
}
