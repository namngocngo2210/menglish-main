package syllabus

import (
	"context"
	"strconv"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/syllabus/syllabusdb"
)

// Dữ liệu giáo trình cho Cổng Giáo viên / Cổng PH-HS (SessionLessonService + StudentPortalController của Laravel):
// adapter teacher.Syllabus (Units, Lessons) và portal.Syllabus (BigTestResults, CurrentStage) ở cmd/menglish/wiring.go.

// UnitRef: Unit của giáo trình lớp (ô "Chọn Unit" khi nhập điểm mini test).
type UnitRef struct {
	ID     uuid.UUID
	Number int
	Title  string
}

// LessonRef: bài học của buổi chính khóa thứ N (Unit dạng "Unit 3: …").
type LessonRef struct {
	Unit  string
	Title string
}

// StudentResult: kết quả Big Test đã duyệt / đã gửi phụ huynh.
type StudentResult struct {
	ID          uuid.UUID  `json:"id"`
	Title       string     `json:"title"`
	ScheduledAt *time.Time `json:"scheduled_at"`
	IsAbsent    bool       `json:"is_absent"`
	Overall     *float64   `json:"overall_score"`
	Listening   *float64   `json:"listening_score"`
	Reading     *float64   `json:"reading_score"`
	Writing     *float64   `json:"writing_score"`
	Speaking    *float64   `json:"speaking_score"`
	Note        *string    `json:"progress_note"`
}

// ClassCurriculum: giáo trình lớp đang học = chặng đang mở, không có thì giáo trình gắn trình độ của lớp; nil = chưa gắn.
func (s *Service) ClassCurriculum(ctx context.Context, classID uuid.UUID) (*uuid.UUID, error) {
	a, err := s.q.OpenAssignmentOfClass(ctx, &classID)
	if err == nil {
		return &a.CurriculumID, nil
	}
	if !isNoRows(err) {
		return nil, err
	}
	c, err := s.q.ClassInfo(ctx, classID)
	if isNoRows(err) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	return s.curriculumOfClass(ctx, s.q, c)
}

// ClassUnits: Unit của giáo trình lớp theo số Unit; rỗng = lớp chưa gắn giáo trình.
func (s *Service) ClassUnits(ctx context.Context, classID uuid.UUID) ([]UnitRef, error) {
	cur, err := s.ClassCurriculum(ctx, classID)
	if err != nil || cur == nil {
		return []UnitRef{}, err
	}
	rows, err := s.q.ListUnits(ctx, *cur)
	if err != nil {
		return nil, err
	}
	out := make([]UnitRef, 0, len(rows))
	for _, u := range rows {
		out = append(out, UnitRef{ID: u.ID, Number: int(u.UnitNumber), Title: u.Title})
	}
	return out, nil
}

// ClassLessons: bài học theo số buổi chính khóa (session_no) của giáo trình lớp; buổi không có bài thì không có khóa.
func (s *Service) ClassLessons(ctx context.Context, classID uuid.UUID, numbers []int) (map[int]LessonRef, error) {
	out := map[int]LessonRef{}
	cur, err := s.ClassCurriculum(ctx, classID)
	if err != nil || cur == nil || len(numbers) == 0 {
		return out, err
	}
	want := map[int]bool{}
	for _, n := range numbers {
		want[n] = true
	}
	units, err := s.q.ListUnits(ctx, *cur)
	if err != nil {
		return nil, err
	}
	unitLabel := map[uuid.UUID]string{}
	for _, u := range units {
		unitLabel[u.ID] = unitTitle(u.UnitNumber, u.Title)
	}
	lessons, err := s.q.ListLessons(ctx, []uuid.UUID{*cur})
	if err != nil {
		return nil, err
	}
	for _, l := range lessons {
		if n := int(l.SessionNo); want[n] {
			title := l.Title
			if title == "" && l.GrammarFocus != nil {
				title = *l.GrammarFocus
			}
			out[n] = LessonRef{Unit: unitLabel[l.UnitID], Title: title}
		}
	}
	return out, nil
}

func unitTitle(no int32, title string) string {
	return "Unit " + strconv.Itoa(int(no)) + ": " + title
}

// StudentBigTestResults: kết quả đã duyệt / đã gửi phụ huynh của học viên, mới nhất trước (limit ≤ 0 = 5).
func (s *Service) StudentBigTestResults(ctx context.Context, studentID uuid.UUID, limit int) ([]StudentResult, error) {
	if limit <= 0 {
		limit = 5
	}
	rows, err := s.q.StudentBigTestResults(ctx, syllabusdb.StudentBigTestResultsParams{StudentID: studentID, MaxRows: int32(min(limit, 100))})
	if err != nil {
		return nil, err
	}
	out := make([]StudentResult, 0, len(rows))
	for _, r := range rows {
		out = append(out, StudentResult{ID: r.ID, Title: r.Title, ScheduledAt: r.ScheduledAt, IsAbsent: r.IsAbsent,
			Overall: r.OverallScore, Listening: r.ListeningScore, Reading: r.ReadingScore, Writing: r.WritingScore,
			Speaking: r.SpeakingScore, Note: r.ProgressNote})
	}
	return out, nil
}

// CurrentStage: tên chặng đang học (lượt giao chặng đang mở) của lớp; nil khi chưa có.
func (s *Service) CurrentStage(ctx context.Context, classID uuid.UUID) (*string, error) {
	a, err := s.q.OpenAssignmentOfClass(ctx, &classID)
	if isNoRows(err) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	label := stageOr(a.StagePosition, a.StageTitle, deref(a.StageName))
	if label == "" {
		return nil, nil
	}
	return &label, nil
}
