package students

import (
	"context"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/search"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/students/studentsdb"
)

// Searcher: nhóm "Học viên" của tìm kiếm chung, cùng phạm vi danh sách học viên (student.view; scopeOf). Đăng ký ở
// cmd/menglish/main.go: search.Builtin(...).Add(students.Searcher(studentSvc)).
// TODO(classes): thêm tên lớp đang học vào dòng kết quả khi phân hệ lớp học có ở v2 (Laravel hiện currentClass).
func Searcher(s *Service) search.Searcher {
	return search.Searcher{
		Key: "students", Label: "Học viên", Icon: "school", Perms: []string{"student.view"},
		Search: func(ctx context.Context, a *iam.Access, q search.Query) ([]search.Hit, error) {
			sc, err := s.scopeOf(ctx, a)
			if err != nil {
				return nil, err
			}
			rows, err := s.q.SearchStudents(ctx, studentsdb.SearchStudentsParams{
				LimitBranches: sc.limitBranches, BranchIds: nonNil(sc.branches), LimitClasses: sc.limitClasses, ClassIds: nonNil(sc.classes),
				Term: q.Like, Phone: q.Phone, Lim: int32(q.Limit),
			})
			if err != nil {
				return nil, err
			}
			hits := make([]search.Hit, 0, len(rows))
			for _, r := range rows {
				meta := []string{StatusLabel(r.Status)}
				if r.BranchName != nil {
					meta = append(meta, *r.BranchName)
				}
				hits = append(hits, search.Hit{ID: r.ID.String(), Title: r.FullName, Code: r.Code, Subtitle: r.Phone,
					Meta: meta, Link: "/students/" + r.ID.String()})
			}
			return hits, nil
		},
	}
}

func nonNil[T any](s []T) []T {
	if s == nil {
		return []T{}
	}
	return s
}
