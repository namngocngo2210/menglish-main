package classes

import (
	"context"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/catalogs"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/classes/classesdb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/inventory"
)

// Phần việc phân hệ Lớp học làm cho phân hệ khác (gắn ở cmd/menglish): students.ClassHooks, catalogs.ClassHooks,
// inventory.ClassLookup.

// ClassStarted (students.ClassHooks): lớp đang hoạt động và đã tới ngày khai giảng (ClassStartActivation::classHasStarted).
func (s *Service) ClassStarted(ctx context.Context, classID uuid.UUID) (bool, error) {
	c, err := s.q.ClassStartInfo(ctx, classID)
	if isNoRows(err) {
		return false, nil
	}
	if err != nil {
		return false, err
	}
	return isStudying(c.Status, c.StartDate, s.today()), nil
}

// DropEnrollments (students.ClassHooks): thôi học → đóng các lượt xếp lớp còn hiệu lực, cùng transaction.
func (s *Service) DropEnrollments(ctx context.Context, tx pgx.Tx, studentID uuid.UUID) error {
	return s.q.WithTx(tx).DropEnrollments(ctx, classesdb.DropEnrollmentsParams{StudentID: studentID, Today: s.today()})
}

// CourseClassCounts (catalogs.ClassHooks).
func (s *Service) CourseClassCounts(ctx context.Context, courseIDs []uuid.UUID) (map[uuid.UUID]int64, error) {
	rows, err := s.q.CourseClassCounts(ctx, courseIDs)
	if err != nil {
		return nil, err
	}
	out := map[uuid.UUID]int64{}
	for _, r := range rows {
		if r.CourseID != nil {
			out[*r.CourseID] = r.Total
		}
	}
	return out, nil
}

// LevelUsage (catalogs.ClassHooks): lớp dùng mã trình độ (classes.level) và học viên có lớp chính dùng mã đó.
func (s *Service) LevelUsage(ctx context.Context, codes []string) (map[string]int64, map[string]int64, error) {
	classes, students := map[string]int64{}, map[string]int64{}
	rows, err := s.q.LevelClassCounts(ctx, codes)
	if err != nil {
		return nil, nil, err
	}
	for _, r := range rows {
		classes[r.Level] = r.Total
	}
	srows, err := s.q.LevelStudentCounts(ctx, codes)
	if err != nil {
		return nil, nil, err
	}
	for _, r := range srows {
		students[r.Level] = r.Total
	}
	return classes, students, nil
}

// RegularSessions (catalogs.ClassHooks): buổi chính khóa trong khoảng ngày của các lớp người dùng được xem.
func (s *Service) RegularSessions(ctx context.Context, actor *iam.Access, from, to time.Time) ([]catalogs.RegularSession, error) {
	sc, err := s.visible(ctx, actor)
	if err != nil {
		return nil, err
	}
	rows, err := s.q.UpcomingRegularSessions(ctx, classesdb.UpcomingRegularSessionsParams{
		FromDate: from, ToDate: to, AllClasses: sc.all, Ids: sc.ids,
	})
	if err != nil {
		return nil, err
	}
	out := make([]catalogs.RegularSession, 0, len(rows))
	for _, r := range rows {
		out = append(out, catalogs.RegularSession{
			ClassID: r.ClassID, ClassName: r.ClassName, ClassCode: r.ClassCode, Branch: r.BranchName,
			Date: r.Date, Start: r.StartTime, End: r.EndTime,
		})
	}
	return out, nil
}

// ClassOptions (inventory.ClassLookup): lớp người dùng được xem cho ô chọn lớp.
func (s *Service) ClassOptions(ctx context.Context, actor *iam.Access) ([]inventory.ClassOption, error) {
	sc, err := s.visible(ctx, actor)
	if err != nil {
		return nil, err
	}
	rows, err := s.q.ClassOptions(ctx, classesdb.ClassOptionsParams{AllClasses: sc.all, Ids: sc.ids})
	if err != nil {
		return nil, err
	}
	out := make([]inventory.ClassOption, 0, len(rows))
	for _, r := range rows {
		out = append(out, inventory.ClassOption{Value: r.ID, Label: r.Name, BranchID: r.BranchID})
	}
	return out, nil
}

// ClassExists (inventory.ClassLookup): lớp chưa xóa.
func (s *Service) ClassExists(ctx context.Context, id uuid.UUID) (bool, error) {
	return s.q.ClassExists(ctx, id)
}
