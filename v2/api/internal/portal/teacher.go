package portal

import (
	"context"
	"errors"
	"strings"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/portal/portaldb"
)

// Tab "Phát âm" của màn "Chấm bài nộp của lớp" (StudentPortalController::teacherSubmissions / markSubmission). Màn và
// quyền (homework.grade, lớp phụ trách) thuộc phân hệ Cổng giáo viên (/teacher/submissions); phân hệ này chỉ cung cấp bản
// ghi âm của học viên trong lớp và ghi kết quả chấm (cmd/menglish bọc thành teacher.Pronunciation).

// ClassPronunciations: bản ghi âm của học viên trong danh sách lớp (gồm học viên liên kết), mới nhất trước.
func (s *Service) ClassPronunciations(ctx context.Context, classID uuid.UUID) ([]PronunciationItem, error) {
	ids, err := s.q.ClassRoster(ctx, classID)
	if err != nil || len(ids) == 0 {
		return []PronunciationItem{}, err
	}
	rows, err := s.q.ClassPronunciations(ctx, ids)
	if err != nil {
		return nil, err
	}
	out := make([]PronunciationItem, len(rows))
	for i, r := range rows {
		out[i] = pronunciationItem(portaldb.AcademicPronunciationRecord{ID: r.ID, Code: r.Code, StudentID: r.StudentID,
			UnitTitle: r.UnitTitle, Duration: r.Duration, AudioFileID: r.AudioFileID, Status: r.Status, Score: r.Score,
			Feedback: r.Feedback, CreatedAt: r.CreatedAt}, r.StudentName)
		out[i].StudentName = r.StudentName
	}
	return out, nil
}

// MarkPronunciation: giáo viên chấm bản ghi âm (điểm đã kiểm bắt buộc / ≤ 20 ký tự ở màn chấm bài). ok = false khi không
// có bản ghi hoặc gradable từ chối học viên (ngoài lớp phụ trách → 404 như Laravel). Học viên / phụ huynh nhận thông báo
// kết quả ở hộp thư cổng.
func (s *Service) MarkPronunciation(ctx context.Context, id, by uuid.UUID, score, feedback string,
	gradable func(studentID uuid.UUID) (bool, error)) (bool, error) {
	r, err := s.q.GetPronunciation(ctx, id)
	if errors.Is(err, pgx.ErrNoRows) {
		return false, nil
	}
	if err != nil {
		return false, err
	}
	if ok, err := gradable(r.StudentID); err != nil || !ok {
		return false, err
	}
	sc, fb := strings.TrimSpace(score), trimPtr(&feedback)
	err = pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
		q := s.q.WithTx(tx)
		if err := q.ReviewPronunciation(ctx, portaldb.ReviewPronunciationParams{Score: &sc, Feedback: fb, ReviewedBy: &by,
			ID: id}); err != nil {
			return err
		}
		body := "Giáo viên đã chấm bản ghi âm \"" + r.UnitTitle + "\": " + sc + "."
		if fb != nil {
			body += " Nhận xét: " + *fb
		}
		if err := s.sendPortal(ctx, tx, r.StudentID, notify.Notification{Type: "pronunciation_reviewed",
			Title: "Bài luyện phát âm đã được chấm", Body: body, Link: "/portal/pronunciation", CreatedBy: &by,
			Data: map[string]any{"record_id": id.String()}}); err != nil {
			return err
		}
		return audit.Record(ctx, tx, audit.Entry{ActorID: &by, Module: audit.ModuleStudents, Event: audit.EventUpdated,
			Action: "portal.pronunciation_review", SubjectType: "pronunciation_record", SubjectID: id.String(), SubjectLabel: r.Code,
			Description: "Chấm bản ghi âm: " + r.UnitTitle,
			Changes: audit.Diff(map[string]any{"status": r.Status, "score": r.Score, "feedback": r.Feedback},
				map[string]any{"status": "reviewed", "score": sc, "feedback": fb})})
	})
	if err != nil {
		return false, err
	}
	s.log.Info("portal pronunciation reviewed", "record", id, "student", r.StudentID, "by", by)
	return true, nil
}
