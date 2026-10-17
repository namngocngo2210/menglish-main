package classes

import (
	"context"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/approvals"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/classes/classesdb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// ApprovalSource: "Xác nhận nhập học" của hộp "Việc cần duyệt" (EnrollmentApprovalSource): lượt xếp lớp chưa bàn giao
// xong. Hoàn tất cần tick checklist thật (giáo trình, nhóm Zalo) nên chỉ link sang màn Tiếp nhận & Xếp lớp.
func (s *Service) ApprovalSource() approvals.Source { return enrollmentSource{s: s} }

type enrollmentSource struct {
	approvals.LinkOnly
	s *Service
}

func (enrollmentSource) Key() string      { return "enrollment" }
func (enrollmentSource) Label() string    { return "Xác nhận nhập học" }
func (enrollmentSource) Group() string    { return approvals.GroupAcademic }
func (enrollmentSource) IndexURL() string { return "/students/enrollments" }
func (enrollmentSource) CanView(a *iam.Access) bool {
	return a.Can("student.view") && a.Can("student.assign_class")
}

func (x enrollmentSource) Count(ctx context.Context, _ *iam.Access) (int64, error) {
	return x.s.q.ApprovalEnrollmentCount(ctx)
}

func check(done bool, label string) string {
	if done {
		return "✓ " + label
	}
	return "✗ " + label
}

func yesNo(b bool) string {
	if b {
		return "Rồi"
	}
	return "Chưa"
}

func (x enrollmentSource) list(ctx context.Context, id *uuid.UUID, limit int) ([]approvals.Item, error) {
	rows, err := x.s.q.ApprovalEnrollments(ctx, classesdb.ApprovalEnrollmentsParams{ID: id, Lim: int32(limit)})
	out := make([]approvals.Item, 0, len(rows))
	for _, r := range rows {
		out = append(out, approvals.Item{Source: x.Key(), ID: r.ID, Title: r.StudentName + " → " + r.ClassName,
			Subtitle: ptr(check(r.CurriculumDelivered, "Giáo trình") + " · " + check(r.ZaloGroupAdded, "Nhóm Zalo")),
			URL:      "/students/enrollments", CreatedAt: &r.CreatedAt,
			Meta: approvals.MetaOf("Học viên", r.StudentName+" ("+r.StudentCode+")", "Lớp", r.ClassName,
				"Ngày xếp lớp", r.EnrolledAt.Format("02/01/2006"), "Đã nhận giáo trình", yesNo(r.CurriculumDelivered),
				"Đã vào nhóm Zalo", yesNo(r.ZaloGroupAdded))})
	}
	return out, err
}

func (x enrollmentSource) Pending(ctx context.Context, _ *iam.Access, limit int) ([]approvals.Item, error) {
	return x.list(ctx, nil, limit)
}

func (x enrollmentSource) Find(ctx context.Context, _ *iam.Access, id uuid.UUID) (*approvals.Item, error) {
	items, err := x.list(ctx, &id, 1)
	if err != nil || len(items) == 0 {
		return nil, err
	}
	return &items[0], nil
}
