package classes

import (
	"context"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// TeacherPortal: phần việc của phân hệ Lớp học cho Cổng giáo viên (teacher.Classes, gắn ở cmd/menglish).
type TeacherPortal struct{ cls *Service }

func (s *Service) TeacherPortal() TeacherPortal { return TeacherPortal{cls: s} }

// CanSee: lớp nằm trong phạm vi Lớp học của người dùng (ClassModel::visibleTo) — điều kiện để Học vụ / Quản lý làm
// thay giáo viên (attendance_student.record_any).
func (t TeacherPortal) CanSee(ctx context.Context, a *iam.Access, classID uuid.UUID) (bool, error) {
	sc, err := t.cls.visible(ctx, a)
	if err != nil {
		return false, err
	}
	return sc.has(classID), nil
}
