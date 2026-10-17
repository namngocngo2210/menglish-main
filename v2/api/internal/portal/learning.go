package portal

import (
	"context"
	"errors"
	"fmt"
	"strings"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/files"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/filestore"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/portal/portaldb"
)

const (
	fileSubjectHomework      = "homework_submission"
	fileSubjectPronunciation = "pronunciation_record"
	// Hạn "gấp" của bài GV giao: tô cam mục chưa nộp đầu tiên khi hạn ≤ 2 ngày nữa.
	urgentDays = 2
)

const mb = 1 << 20

// HomeworkPolicy: file bài nộp (ảnh / tài liệu / video / âm thanh, tối đa 100 MB như Laravel).
var HomeworkPolicy = filestore.Policy{
	Types:    concat(filestore.Images, filestore.Documents, filestore.Video, filestore.Audio),
	MaxBytes: 100 * mb,
	TooLarge: "Dung lượng tập tin bài làm không được vượt quá 102400 kB.",
}

// PronunciationPolicy: bản ghi âm từ micro (Chrome / Edge ra webm, Safari ra mp4 / m4a), tối đa 50 MB.
var PronunciationPolicy = filestore.Policy{
	Types:    concat(filestore.Audio, []string{"mp4"}),
	MaxBytes: 50 * mb,
	TooLarge: "Dung lượng tập tin ghi âm không được vượt quá 51200 kB.",
	Required: "Chưa có bản ghi âm. Hãy chạm micro để thu âm trước khi nộp.",
}

func concat(lists ...[]string) []string {
	var out []string
	for _, l := range lists {
		for _, x := range l {
			found := false
			for _, y := range out {
				found = found || x == y
			}
			if !found {
				out = append(out, x)
			}
		}
	}
	return out
}

// ───── Học tập & Nộp bài tập ─────

type HomeworkCard struct {
	Key   string      `json:"key"`
	Title string      `json:"title"`
	Sub   *Submission `json:"submission"`
}

type Homework struct {
	Context
	Cards          []HomeworkCard    `json:"cards"`
	CompletedCount int               `json:"completed_count"`
	Remarks        []Remark          `json:"remarks"`
	MiniTests      []MiniTest        `json:"mini_tests"`
	BigTests       []BigTestResult   `json:"big_test_results"`
	Latest         *AssignedHomework `json:"latest_homework"`
	Urgent         bool              `json:"homework_urgent"`
	// Ready: phân hệ Cổng giáo viên đã gắn (nộp bài được)
	Ready bool `json:"ready"`
}

// Homework: màn "Học tập của tôi" (studentHomework): 6 hạng mục bài nộp, nhận xét buổi học, bảng điểm.
func (s *Service) Homework(ctx context.Context, actor *iam.Access, studentID *uuid.UUID) (*Homework, error) {
	a, err := s.resolve(ctx, actor, studentID)
	if err != nil {
		return nil, err
	}
	out := &Homework{Context: a.Context, Cards: []HomeworkCard{}, Remarks: []Remark{}, MiniTests: []MiniTest{},
		BigTests: []BigTestResult{}, Ready: s.teacher.Ready()}
	var l Learning
	if a.Student != nil {
		if l, err = s.teacher.Learning(ctx, a.id(), a.classes); err != nil {
			return nil, err
		}
		bt, err := s.syllabus.BigTestResults(ctx, a.id(), 5)
		if err != nil {
			return nil, err
		}
		out.BigTests = append(out.BigTests, bt...)
	}
	// Bài mới nhất của từng loại (nộp lại không tính thêm hạng mục).
	latest := map[string]*Submission{}
	for i := range l.Submissions {
		sub := &l.Submissions[i]
		if _, ok := latest[sub.HomeworkType]; !ok {
			latest[sub.HomeworkType] = sub
		}
	}
	for _, t := range HomeworkTypes {
		c := HomeworkCard{Key: t.Key, Title: t.Label, Sub: latest[t.Key]}
		if c.Sub != nil {
			out.CompletedCount++
		}
		out.Cards = append(out.Cards, c)
	}
	out.Remarks = append(out.Remarks, l.Remarks...)
	out.MiniTests = append(out.MiniTests, l.MiniTests...)
	out.Latest = l.Latest
	if l.Latest != nil && l.Latest.DueDate != nil {
		out.Urgent = !l.Latest.DueDate.After(s.today().AddDate(0, 0, urgentDays))
	}
	return out, nil
}

type HomeworkInput struct {
	StudentID    *uuid.UUID
	HomeworkType string
	Notes        *string
}

// SubmitHomework: học viên nộp bài một hạng mục (submitHomework). File (tùy chọn) lưu kho file trong cùng transaction.
func (s *Service) SubmitHomework(ctx context.Context, actor *iam.Access, in HomeworkInput, up *filestore.Upload) (uuid.UUID, error) {
	if in.StudentID == nil {
		return uuid.Nil, errNoStudent
	}
	label, ok := homeworkLabel(strings.TrimSpace(in.HomeworkType))
	if !ok {
		return uuid.Nil, iam.Invalid("Loại bài tập không hợp lệ.")
	}
	if up != nil {
		if err := up.Check(HomeworkPolicy); err != nil {
			return uuid.Nil, iam.Invalid(err.Error())
		}
	}
	st, err := s.writable(ctx, actor, *in.StudentID)
	if err != nil {
		return uuid.Nil, err
	}
	if !s.teacher.Ready() {
		return uuid.Nil, errPending("Cổng giáo viên")
	}
	notes := trimPtr(in.Notes)
	if notes == nil {
		notes = ptr("Con đã hoàn thành bài tập đầy đủ theo hướng dẫn của cô.")
	}
	var id uuid.UUID
	err = pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
		sub := SubmissionInput{StudentID: st.ID, StudentName: st.FullName, HomeworkType: in.HomeworkType, HomeworkLabel: label,
			Notes: notes, By: actor.UserID}
		if up != nil {
			f, err := s.saveFile(ctx, tx, up, fileSubjectHomework, st, actor, "Tải bài nộp: "+label)
			if err != nil {
				return err
			}
			sub.FileID, sub.AttachmentName = &f.ID, &f.Name
		}
		var err error
		if id, err = s.teacher.Submit(ctx, tx, sub); err != nil {
			return err
		}
		return audit.Record(ctx, tx, audit.Entry{ActorID: &actor.UserID, Module: audit.ModuleStudents, Event: audit.EventCreated,
			Action: "portal.homework_submit", SubjectType: "homework_submission", SubjectID: id.String(),
			SubjectLabel: st.FullName, BranchID: st.BranchID, Description: "Bài nộp: " + label + " - " + st.FullName})
	})
	if err != nil {
		return uuid.Nil, err
	}
	s.log.Info("portal homework submitted", "submission", id, "student", st.ID, "by", actor.UserID)
	return id, nil
}

// saveFile: lưu file của học viên vào kho file (subject = học viên; quyền tải theo readStudentFile).
func (s *Service) saveFile(ctx context.Context, tx pgx.Tx, up *filestore.Upload, subject string, st portaldb.GetStudentRow,
	actor *iam.Access, desc string) (files.File, error) {
	if s.files == nil {
		return files.File{}, &iam.Error{Status: 503, Code: "files_unavailable", Message: "Kho file chưa được cấu hình."}
	}
	return s.files.Save(ctx, tx, up, files.SaveInput{
		Owner: files.Owner{Module: "portal", SubjectType: subject, SubjectID: &st.ID, BranchID: st.BranchID},
		By:    &actor.UserID,
		Audit: audit.Entry{Module: audit.ModuleStudents, SubjectType: "student", SubjectID: st.ID.String(),
			SubjectLabel: fmt.Sprintf("%s (%s)", st.FullName, st.Code), Description: desc},
	})
}

// submissionStudent: học viên của bài nộp; người xem phải ghi được trên hồ sơ đó.
func (s *Service) submissionStudent(ctx context.Context, actor *iam.Access, id uuid.UUID) (portaldb.GetStudentRow, error) {
	owner, err := s.teacher.SubmissionOwner(ctx, id)
	if errors.Is(err, pgx.ErrNoRows) {
		return portaldb.GetStudentRow{}, ErrNotFound
	}
	if err != nil {
		return portaldb.GetStudentRow{}, err
	}
	return s.writable(ctx, actor, owner)
}

// UpdateHomework: sửa ghi chú / nộp lại file (updateHomework). setNotes = có gửi trường notes (rỗng = xóa ghi chú).
func (s *Service) UpdateHomework(ctx context.Context, actor *iam.Access, id uuid.UUID, notes *string, setNotes bool, up *filestore.Upload) error {
	if up != nil {
		if err := up.Check(HomeworkPolicy); err != nil {
			return iam.Invalid(err.Error())
		}
	}
	if !s.teacher.Ready() {
		return errPending("Cổng giáo viên")
	}
	st, err := s.submissionStudent(ctx, actor, id)
	if err != nil {
		return err
	}
	return pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
		in := SubmissionUpdate{Notes: trimPtr(notes), SetNotes: setNotes, By: actor.UserID}
		if up != nil {
			f, err := s.saveFile(ctx, tx, up, fileSubjectHomework, st, actor, "Nộp lại bài làm")
			if err != nil {
				return err
			}
			in.FileID, in.AttachmentName = &f.ID, &f.Name
		}
		if err := s.teacher.UpdateSubmission(ctx, tx, id, in); err != nil {
			return err
		}
		return audit.Record(ctx, tx, audit.Entry{ActorID: &actor.UserID, Module: audit.ModuleStudents, Event: audit.EventUpdated,
			Action: "portal.homework_update", SubjectType: "homework_submission", SubjectID: id.String(),
			SubjectLabel: st.FullName, BranchID: st.BranchID, Description: "Cập nhật bài nộp - " + st.FullName})
	})
}

// DeleteHomework: hủy bài nộp để nộp lại (deleteHomework).
func (s *Service) DeleteHomework(ctx context.Context, actor *iam.Access, id uuid.UUID) error {
	if !s.teacher.Ready() {
		return errPending("Cổng giáo viên")
	}
	st, err := s.submissionStudent(ctx, actor, id)
	if err != nil {
		return err
	}
	return pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
		if err := s.teacher.DeleteSubmission(ctx, tx, id); err != nil {
			return err
		}
		return audit.Record(ctx, tx, audit.Entry{ActorID: &actor.UserID, Module: audit.ModuleStudents, Event: audit.EventDeleted,
			Action: "portal.homework_delete", SubjectType: "homework_submission", SubjectID: id.String(),
			SubjectLabel: st.FullName, BranchID: st.BranchID, Description: "Hủy bài nộp - " + st.FullName})
	})
}

// ───── Luyện phát âm ─────

type PronunciationItem struct {
	ID          uuid.UUID `json:"id"`
	Code        string    `json:"code"`
	Title       string    `json:"title"`
	UnitTitle   string    `json:"unit_title"`
	Status      string    `json:"status"`
	SubmittedAt time.Time `json:"submitted_at"`
	Duration    *string   `json:"duration"`
	Score       *string   `json:"score"`
	Feedback    *string   `json:"feedback"`
	AudioURL    *string   `json:"audio_url"`
	StudentName string    `json:"student_name,omitempty"`
}

type Pronunciation struct {
	Context
	History  []PronunciationItem `json:"history"`
	Practice []PracticeItem      `json:"practice_items"`
}

func pronunciationItem(r portaldb.AcademicPronunciationRecord, student string) PronunciationItem {
	it := PronunciationItem{ID: r.ID, Code: r.Code, UnitTitle: r.UnitTitle, Title: "Bản ghi âm: " + r.UnitTitle,
		Status: r.Status, SubmittedAt: r.CreatedAt, Duration: r.Duration, Score: r.Score, Feedback: r.Feedback}
	if student != "" {
		it.Title += " - " + student
	}
	if r.AudioFileID != nil {
		it.AudioURL = ptr(files.URL(*r.AudioFileID))
	}
	return it
}

// Pronunciation: màn luyện phát âm (studentPronunciation): bài nghe của lớp, lịch sử bản ghi âm.
func (s *Service) Pronunciation(ctx context.Context, actor *iam.Access, studentID *uuid.UUID) (*Pronunciation, error) {
	a, err := s.resolve(ctx, actor, studentID)
	if err != nil {
		return nil, err
	}
	out := &Pronunciation{Context: a.Context, History: []PronunciationItem{}, Practice: []PracticeItem{}}
	if a.Student == nil {
		return out, nil
	}
	rows, err := s.q.StudentPronunciations(ctx, a.id())
	if err != nil {
		return nil, err
	}
	for _, r := range rows {
		out.History = append(out.History, pronunciationItem(r, a.row.FullName))
	}
	items, err := s.teacher.PracticeAudio(ctx, a.classes)
	if err != nil {
		return nil, err
	}
	out.Practice = append(out.Practice, items...)
	return out, nil
}

type PronunciationInput struct {
	StudentID *uuid.UUID
	UnitTitle string
	Duration  *string
}

// SubmitPronunciation: nộp bản ghi âm (submitPronunciation). Chưa có dịch vụ chấm tự động: bài chờ giáo viên chấm.
func (s *Service) SubmitPronunciation(ctx context.Context, actor *iam.Access, in PronunciationInput, up *filestore.Upload) (*PronunciationItem, error) {
	in.UnitTitle = strings.TrimSpace(in.UnitTitle)
	in.Duration = trimPtr(in.Duration)
	switch {
	case in.StudentID == nil:
		return nil, errNoStudent
	case in.UnitTitle == "":
		return nil, iam.Invalid("Vui lòng nhập bài đang luyện.")
	case runes(in.UnitTitle) > 255:
		return nil, iam.Invalid("Trường bài đang luyện không được vượt quá 255 ký tự.")
	}
	if err := maxLen(in.Duration, 20, "Trường thời lượng không được vượt quá 20 ký tự."); err != nil {
		return nil, err
	}
	if err := up.Check(PronunciationPolicy); err != nil {
		return nil, iam.Invalid(err.Error())
	}
	st, err := s.writable(ctx, actor, *in.StudentID)
	if err != nil {
		return nil, err
	}
	var out PronunciationItem
	err = pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
		f, err := s.saveFile(ctx, tx, up, fileSubjectPronunciation, st, actor, "Tải bản ghi âm luyện phát âm")
		if err != nil {
			return err
		}
		r, err := s.q.WithTx(tx).InsertPronunciation(ctx, portaldb.InsertPronunciationParams{Code: randomCode("AUDIO-"),
			StudentID: st.ID, UnitTitle: in.UnitTitle, Duration: in.Duration, AudioFileID: &f.ID, CreatedBy: &actor.UserID})
		if err != nil {
			return err
		}
		out = pronunciationItem(r, st.FullName)
		return audit.Record(ctx, tx, audit.Entry{ActorID: &actor.UserID, Module: audit.ModuleStudents, Event: audit.EventCreated,
			Action: "portal.pronunciation_submit", SubjectType: "pronunciation_record", SubjectID: r.ID.String(),
			SubjectLabel: r.Code, BranchID: st.BranchID, Description: out.Title})
	})
	if err != nil {
		return nil, err
	}
	s.log.Info("portal pronunciation submitted", "record", out.ID, "student", st.ID, "by", actor.UserID)
	return &out, nil
}

// DeletePronunciation: xóa bản ghi âm (deletePronunciation); file ghi âm xóa mềm cùng lúc.
func (s *Service) DeletePronunciation(ctx context.Context, actor *iam.Access, id uuid.UUID) error {
	r, err := s.q.GetPronunciation(ctx, id)
	if errors.Is(err, pgx.ErrNoRows) {
		return ErrNotFound
	}
	if err != nil {
		return err
	}
	st, err := s.writable(ctx, actor, r.StudentID)
	if err != nil {
		return err
	}
	return pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
		if err := s.q.WithTx(tx).DeletePronunciation(ctx, id); err != nil {
			return err
		}
		if r.AudioFileID != nil && s.files != nil {
			if _, err := s.files.Delete(ctx, tx, *r.AudioFileID, &actor.UserID, audit.Entry{Module: audit.ModuleStudents,
				SubjectType: "pronunciation_record", SubjectID: id.String(), SubjectLabel: r.Code}); err != nil && !errors.Is(err, files.ErrNotFound) {
				return err
			}
		}
		return audit.Record(ctx, tx, audit.Entry{ActorID: &actor.UserID, Module: audit.ModuleStudents, Event: audit.EventDeleted,
			Action: "portal.pronunciation_delete", SubjectType: "pronunciation_record", SubjectID: id.String(), SubjectLabel: r.Code,
			BranchID: st.BranchID, Description: "Xóa bản ghi âm: " + r.UnitTitle + " - " + st.FullName})
	})
}
