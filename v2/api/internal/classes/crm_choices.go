package classes

import (
	"cmp"
	"context"
	"slices"
	"strconv"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/classes/classesdb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/crm"
)

// Lựa chọn lớp / khóa cho hộp Chốt & Xếp lớp và Gán lớp bên CRM (crm.ClosingChoiceSource).

var _ crm.ClosingChoiceSource = (*CRMStudents)(nil)

func classSeatsLeft(c classesdb.ClosingClassChoicesRow) bool {
	return c.MaxCapacity <= 0 || c.RosterCount < int64(c.MaxCapacity)
}

// choiceLabel (CrmController::waitingList "matches"): "Tên (sắp khai giảng) · trình độ · còn N chỗ · cần thêm K HV để
// khai giảng".
func choiceLabel(c classesdb.ClosingClassChoicesRow) string {
	label := c.Name + " (" + c.Code + ")"
	if c.Status == StatusUpcoming {
		label += " (sắp khai giảng)"
	}
	if lv := cmp.Or(ptrStr(c.ClassLevelName), ptrStr(c.CourseLevelName)); lv != "" {
		label += " · " + lv
	}
	if c.MaxCapacity > 0 {
		label += " · còn " + strconv.FormatInt(max(0, int64(c.MaxCapacity)-c.RosterCount), 10) + " chỗ"
	} else {
		label += " · còn ∞ chỗ"
	}
	if c.Status == StatusUpcoming && c.RosterCount < int64(c.MinStudents) {
		label += " · cần thêm " + strconv.FormatInt(int64(c.MinStudents)-c.RosterCount, 10) + " HV để khai giảng"
	}
	return label
}

func ptrStr(p *string) string {
	if p == nil {
		return ""
	}
	return *p
}

func (b *CRMStudents) choices(ctx context.Context, branch *uuid.UUID) ([]classesdb.ClosingClassChoicesRow, error) {
	rows, err := b.cls.q.ClosingClassChoices(ctx, classesdb.ClosingClassChoicesParams{BranchID: branch, Today: b.cls.today()})
	if err != nil {
		return nil, err
	}
	return slices.DeleteFunc(rows, func(c classesdb.ClosingClassChoicesRow) bool { return !classSeatsLeft(c) }), nil
}

func toChoice(c classesdb.ClosingClassChoicesRow) crm.ClassChoice {
	return crm.ClassChoice{ID: c.ID, Label: choiceLabel(c), CourseID: c.CourseID}
}

// ClosingClasses: lớp nhận ghi danh cùng cơ sở với khách, còn chỗ.
func (b *CRMStudents) ClosingClasses(ctx context.Context, l crm.LeadSnapshot) ([]crm.ClassChoice, error) {
	rows, err := b.choices(ctx, l.BranchID)
	if err != nil {
		return nil, err
	}
	out := make([]crm.ClassChoice, 0, len(rows))
	for _, c := range rows {
		out = append(out, toChoice(c))
	}
	return out, nil
}

// ClosingCourses: khóa học đang mở, kèm học phí niêm yết.
func (b *CRMStudents) ClosingCourses(ctx context.Context) ([]crm.CourseChoice, error) {
	rows, err := b.cls.q.ClosingCourses(ctx)
	if err != nil {
		return nil, err
	}
	out := make([]crm.CourseChoice, 0, len(rows))
	for _, c := range rows {
		out = append(out, crm.CourseChoice{ID: c.ID, Label: c.Name, Fee: c.TuitionFee})
	}
	return out, nil
}

// WaitingClasses (waitingClassData): cơ sở của học viên (hoặc của khách), còn chỗ, đúng cơ sở đã chốt, đúng khóa đã chốt
// hoặc cùng trình độ với khóa đó; lớp đúng khóa lên đầu.
func (b *CRMStudents) WaitingClasses(ctx context.Context, l crm.LeadSnapshot, studentID uuid.UUID) ([]crm.ClassChoice, error) {
	branch, err := b.cls.q.StudentBranchID(ctx, studentID)
	if err != nil && !isNoRows(err) {
		return nil, err
	}
	if branch == nil {
		branch = l.BranchID
	}
	rows, err := b.choices(ctx, branch)
	if err != nil {
		return nil, err
	}
	var level *uuid.UUID
	if l.WaitingCourseID != nil {
		co, err := b.cls.q.CourseForClosing(ctx, *l.WaitingCourseID)
		if err != nil && !isNoRows(err) {
			return nil, err
		}
		level = co.CourseLevelID
	}
	sameCourse := func(c classesdb.ClosingClassChoicesRow) bool {
		return l.WaitingCourseID != nil && c.CourseID != nil && *c.CourseID == *l.WaitingCourseID
	}
	matched := []classesdb.ClosingClassChoicesRow{}
	for _, c := range rows {
		if l.WaitingBranchID != nil && c.BranchID != *l.WaitingBranchID {
			continue
		}
		lv := c.ClassLevelID
		if lv == nil {
			lv = c.CourseLevelID
		}
		if l.WaitingCourseID == nil || sameCourse(c) || (level != nil && lv != nil && *lv == *level) {
			matched = append(matched, c)
		}
	}
	slices.SortStableFunc(matched, func(x, y classesdb.ClosingClassChoicesRow) int {
		rank := func(c classesdb.ClosingClassChoicesRow) int {
			if sameCourse(c) {
				return 0
			}
			return 1
		}
		return rank(x) - rank(y)
	})
	out := make([]crm.ClassChoice, 0, len(matched))
	for _, c := range matched {
		out = append(out, toChoice(c))
	}
	return out, nil
}
