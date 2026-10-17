package main

import (
	"context"
	"errors"
	"strings"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/files"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/portal"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/teacher"
)

// Nối Cổng Phụ huynh & Học sinh (internal/portal) với Cổng Giáo viên (internal/teacher). Hai gói không import nhau:
//   - portal.TeacherPortal: học viên xem bài GV giao, nộp / sửa / hủy bài nộp, xem nhận xét buổi học và điểm mini test
//     (bảng bài tập / bài nộp / nhận xét / mini test thuộc phân hệ Cổng giáo viên).
//   - teacher.Pronunciation: tab "Phát âm" của màn Chấm bài nộp đọc / chấm bản ghi âm của cổng học viên.
//   - teacher.FileAccess: học viên / phụ huynh mở file bài nộp của mình và file nghe bài tập lớp mình.

// Số dòng như StudentPortalController::studentHomework / studentPronunciation.
const (
	portalRemarks   = 3
	portalMiniTests = 10
	portalPractice  = 20
)

type portalLearning struct{ p *teacher.StudentPortal }

var _ portal.TeacherPortal = portalLearning{}

func (portalLearning) Ready() bool { return true }

func optStr(s string) *string {
	if strings.TrimSpace(s) == "" {
		return nil
	}
	return &s
}

func toPortalSubmission(s teacher.StudentSubmission) portal.Submission {
	return portal.Submission{ID: s.ID, HomeworkType: s.HomeworkType, Status: s.Status, SubmittedAt: s.SubmittedAt,
		Score: optStr(s.Score), Feedback: optStr(s.Feedback), Notes: optStr(s.Notes), AttachmentURL: optStr(s.AttachmentURL),
		AttachmentName: optStr(s.AttachmentName)}
}

// remarkMonsters: Monsters (Nhóm) / (Thưởng) gộp một dòng như StudentPortalController::normalizeRemark.
func remarkMonsters(m map[string]string) *string {
	var parts []string
	if v := strings.TrimSpace(m["monsters_group"]); v != "" {
		parts = append(parts, "Nhóm "+v)
	}
	if v := strings.TrimSpace(m["monsters_bonus"]); v != "" {
		parts = append(parts, "Thưởng "+v)
	}
	if len(parts) == 0 {
		return nil
	}
	s := strings.Join(parts, " · ")
	return &s
}

func (a portalLearning) Learning(ctx context.Context, studentID uuid.UUID, classIDs []uuid.UUID) (portal.Learning, error) {
	var out portal.Learning
	subs, err := a.p.Submissions(ctx, studentID)
	if err != nil {
		return out, err
	}
	for _, s := range subs {
		out.Submissions = append(out.Submissions, toPortalSubmission(s))
	}
	if len(classIDs) == 0 {
		return out, nil
	}
	hw, err := a.p.Homeworks(ctx, classIDs, 1)
	if err != nil {
		return out, err
	}
	if len(hw) > 0 {
		h := hw[0]
		out.Latest = &portal.AssignedHomework{Title: h.Title, Description: optStr(h.Description), ClassName: optStr(h.ClassName),
			DueDate: h.DueDate}
	}
	remarks, err := a.p.Remarks(ctx, studentID, classIDs, portalRemarks)
	if err != nil {
		return out, err
	}
	for _, r := range remarks {
		m := r.Remark
		out.Remarks = append(out.Remarks, portal.Remark{Date: r.Date, Monsters: remarkMonsters(m), Grammar: optStr(m["grammar"]),
			Attitude: optStr(m["attitude"]), Result: optStr(m["result"]), Comment: optStr(m["comment"])})
	}
	tests, err := a.p.MiniTests(ctx, studentID, portalMiniTests)
	if err != nil {
		return out, err
	}
	for _, t := range tests {
		d := t.TestDate
		out.MiniTests = append(out.MiniTests, portal.MiniTest{ID: t.ID, Name: t.Name, TestDate: &d, Score: t.Score, MaxScore: t.MaxScore})
	}
	return out, nil
}

func (a portalLearning) HomeworkStats(ctx context.Context, studentID uuid.UUID, classIDs []uuid.UUID) (portal.HomeworkStats, error) {
	var out portal.HomeworkStats
	subs, err := a.p.Submissions(ctx, studentID)
	if err != nil {
		return out, err
	}
	types := map[string]bool{}
	for _, s := range subs {
		types[s.HomeworkType] = true
	}
	out.Submitted, out.SubmittedTypes = len(subs), len(types)
	if len(classIDs) > 0 {
		if out.Assigned, err = a.p.HomeworkCount(ctx, classIDs); err != nil {
			return out, err
		}
	}
	return out, nil
}

func (a portalLearning) PracticeAudio(ctx context.Context, classIDs []uuid.UUID) ([]portal.PracticeItem, error) {
	if len(classIDs) == 0 {
		return nil, nil
	}
	rows, err := a.p.PracticeAudio(ctx, classIDs, portalPractice)
	if err != nil {
		return nil, err
	}
	out := make([]portal.PracticeItem, len(rows))
	for i, r := range rows {
		out[i] = portal.PracticeItem{Title: r.Title, ClassName: optStr(r.ClassName), AudioURL: r.AudioURL}
	}
	return out, nil
}

// notFound: bài nộp không có → pgx.ErrNoRows để cổng học viên trả 404 của mình.
func notFound(err error) error {
	if errors.Is(err, teacher.ErrSubmissionNotFound) {
		return pgx.ErrNoRows
	}
	return err
}

func (a portalLearning) SubmissionOwner(ctx context.Context, id uuid.UUID) (uuid.UUID, error) {
	sid, err := a.p.SubmissionOwner(ctx, id)
	return sid, notFound(err)
}

func (a portalLearning) Submit(ctx context.Context, in portal.SubmissionInput) (uuid.UUID, error) {
	in2 := teacher.SubmitInput{StudentID: in.StudentID, HomeworkType: in.HomeworkType, Upload: in.Upload}
	if in.Notes != nil {
		in2.Notes = *in.Notes
	}
	sub, err := a.p.Submit(ctx, &in.By, in2)
	if err != nil {
		return uuid.Nil, err
	}
	return sub.ID, nil
}

func (a portalLearning) UpdateSubmission(ctx context.Context, studentID, id uuid.UUID, in portal.SubmissionUpdate) error {
	_, err := a.p.Update(ctx, &in.By, studentID, id, in.Notes, in.Upload)
	return err
}

func (a portalLearning) DeleteSubmission(ctx context.Context, studentID, id, by uuid.UUID) error {
	return a.p.Delete(ctx, &by, studentID, id)
}

// portalFiles: học viên / phụ huynh (hoặc nhân sự xem hộ) mở file bài nộp của học viên và file nghe bài tập của lớp.
func portalFiles(p *teacher.StudentPortal, ps *portal.Service) teacher.FileAccess {
	return func(ctx context.Context, a *iam.Access, f files.File) (bool, error) {
		if sid, ok, err := p.SubmissionStudent(ctx, f.ID); err != nil || ok {
			if err != nil {
				return false, err
			}
			return ps.StudentFileAccess(ctx, a, sid)
		}
		if cid, ok, err := p.HomeworkClass(ctx, f.ID); err != nil || ok {
			if err != nil {
				return false, err
			}
			return ps.ClassFileAccess(ctx, a, cid)
		}
		return false, nil
	}
}

// teacherPronunciation: tab "Phát âm" của màn Chấm bài nộp (bản ghi âm thuộc cổng học viên).
type teacherPronunciation struct{ ps *portal.Service }

var _ teacher.Pronunciation = teacherPronunciation{}

func (teacherPronunciation) Available() bool { return true }

func (t teacherPronunciation) ClassSubmissions(ctx context.Context, classID uuid.UUID) ([]teacher.Submission, error) {
	recs, err := t.ps.ClassPronunciations(ctx, classID)
	if err != nil {
		return nil, err
	}
	out := make([]teacher.Submission, len(recs))
	for i, r := range recs {
		s := teacher.Submission{ID: r.ID, Status: r.Status, StudentName: r.StudentName, SubmittedAt: teacher.SubmittedAt(r.SubmittedAt),
			UnitTitle: r.UnitTitle}
		if r.AudioURL != nil {
			s.AudioURL = *r.AudioURL
		}
		if r.Score != nil {
			s.Score = *r.Score
		}
		if r.Feedback != nil {
			s.Feedback = *r.Feedback
		}
		out[i] = s
	}
	return out, nil
}

func (t teacherPronunciation) Mark(ctx context.Context, id, by uuid.UUID, score, feedback string, gradable func(uuid.UUID) (bool, error)) (bool, error) {
	return t.ps.MarkPronunciation(ctx, id, by, score, feedback, gradable)
}

// wirePortalTeacher: gắn hai phân hệ vào nhau (gọi sau khi tạo cả hai service).
func wirePortalTeacher(ps *portal.Service, ts *teacher.Service) {
	sp := ts.StudentPortal()
	ps.WithTeacherPortal(portalLearning{sp})
	sp.SetFileAccess(portalFiles(sp, ps))
	ts.WithPronunciation(teacherPronunciation{ps})
}
