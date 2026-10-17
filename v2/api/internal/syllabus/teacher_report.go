package syllabus

import (
	"context"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/syllabus/syllabusdb"
)

// Cổng giáo viên: bảng điểm Big Test các lớp giáo viên phụ trách — GV chính hoặc GVNN (TeacherPortalController::bigTestReport,
// /teacher/big-test-report).

const teacherReportPageSize = 20

type TeacherBigTestRow struct {
	ID             uuid.UUID `json:"id"`
	StudentName    *string   `json:"student_name"`
	TestTitle      string    `json:"test_title"`
	ClassName      string    `json:"class_name"`
	ListeningScore *float64  `json:"listening_score"`
	ReadingScore   *float64  `json:"reading_score"`
	WritingScore   *float64  `json:"writing_score"`
	SpeakingScore  *float64  `json:"speaking_score"`
	OverallScore   *float64  `json:"overall_score"`
	Approved       bool      `json:"approved"`
}

type TeacherBigTestReport struct {
	Results []TeacherBigTestRow `json:"results"`
	Page    Page                `json:"page"`
}

func (s *Service) TeacherBigTestReport(ctx context.Context, actor *iam.Access, page int) (*TeacherBigTestReport, error) {
	lim, off, page := pageOf(page, teacherReportPageSize)
	rows, err := s.q.TeacherBigTestResults(ctx, syllabusdb.TeacherBigTestResultsParams{UserID: &actor.UserID, Lim: lim, Off: off})
	if err != nil {
		return nil, err
	}
	total, err := s.q.CountTeacherBigTestResults(ctx, &actor.UserID)
	if err != nil {
		return nil, err
	}
	out := &TeacherBigTestReport{Results: make([]TeacherBigTestRow, 0, len(rows)),
		Page: Page{Total: total, Page: page, PageSize: teacherReportPageSize}}
	for _, r := range rows {
		out.Results = append(out.Results, TeacherBigTestRow{ID: r.ID, StudentName: r.StudentName, TestTitle: r.TestTitle,
			ClassName: r.ClassName, ListeningScore: r.ListeningScore, ReadingScore: r.ReadingScore, WritingScore: r.WritingScore,
			SpeakingScore: r.SpeakingScore, OverallScore: r.OverallScore, Approved: r.Status == "approved"})
	}
	return out, nil
}
