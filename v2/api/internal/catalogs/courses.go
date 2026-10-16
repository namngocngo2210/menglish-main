package catalogs

import (
	"context"
	"fmt"
	"strconv"
	"strings"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/catalogs/catalogsdb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// Khóa học & Bảng giá học phí (CourseController). Mã khóa do hệ thống cấp CS0001.

const coursesPageSize = 20

var errCourseNotFound = notFound("Không tìm thấy khóa học.")

type LevelRef struct {
	Code   string `json:"code"`
	Name   string `json:"name"`
	Target string `json:"target"`
}

type Course struct {
	ID            uuid.UUID  `json:"id"`
	Code          string     `json:"code"`
	Name          string     `json:"name"`
	Description   *string    `json:"description"`
	CourseLevelID *uuid.UUID `json:"course_level_id"`
	TuitionFee    int64      `json:"tuition_fee"`
	TotalLessons  int32      `json:"total_lessons"`
	ClassesCount  int64      `json:"classes_count"`
	IsActive      bool       `json:"is_active"`
	Level         *LevelRef  `json:"level"`
}

type CourseFilter struct {
	Search  string
	LevelID *uuid.UUID
	Status  string // active | inactive
	Page    int
}

type CourseStats struct {
	TotalCourses  int64 `json:"total_courses"`
	ActiveCourses int64 `json:"active_courses"`
	AvgTuition    int64 `json:"avg_tuition"`
	MaxTuition    int64 `json:"max_tuition"`
}

type LevelOption struct {
	Value uuid.UUID `json:"value"`
	Label string    `json:"label"`
}

type CourseList struct {
	Page[Course]
	Stats  CourseStats   `json:"stats"`
	Levels []LevelOption `json:"levels"`
}

func statusFilter(status string) *bool {
	switch status {
	case "active":
		v := true
		return &v
	case "inactive":
		v := false
		return &v
	}
	return nil
}

func (s *Service) ListCourses(ctx context.Context, f CourseFilter) (CourseList, error) {
	page, lim, off := limits(f.Page, coursesPageSize)
	rows, err := s.q.ListCourses(ctx, catalogsdb.ListCoursesParams{
		Search: likeArg(f.Search), LevelID: f.LevelID, Active: statusFilter(f.Status), Lim: lim, Off: off,
	})
	if err != nil {
		return CourseList{}, err
	}
	var total int64
	items := make([]Course, 0, len(rows))
	for _, r := range rows {
		total = r.Total
		c := Course{
			ID: r.ID, Code: r.Code, Name: r.Name, Description: r.Description, CourseLevelID: r.CourseLevelID,
			TuitionFee: r.TuitionFee, TotalLessons: r.TotalLessons, IsActive: r.IsActive,
		}
		if r.LevelCode != nil {
			c.Level = &LevelRef{Code: *r.LevelCode, Name: deref(r.LevelName), Target: deref(r.LevelTarget)}
		}
		items = append(items, c)
	}
	if s.Classes != nil && len(items) > 0 {
		ids := make([]uuid.UUID, 0, len(items))
		for _, c := range items {
			ids = append(ids, c.ID)
		}
		counts, err := s.Classes.CourseClassCounts(ctx, ids)
		if err != nil {
			return CourseList{}, err
		}
		for i := range items {
			items[i].ClassesCount = counts[items[i].ID]
		}
	}
	st, err := s.q.CourseStats(ctx)
	if err != nil {
		return CourseList{}, err
	}
	levels, err := s.levelOptions(ctx)
	if err != nil {
		return CourseList{}, err
	}
	return CourseList{
		Page:   newPage(items, total, page, coursesPageSize),
		Stats:  CourseStats{TotalCourses: st.Total, ActiveCourses: st.Active, AvgTuition: st.AvgTuition, MaxTuition: st.MaxTuition},
		Levels: levels,
	}, nil
}

// levelOptions: trình độ đang hoạt động cho ô chọn "Khung trình độ" ("Tên (Mã)").
func (s *Service) levelOptions(ctx context.Context) ([]LevelOption, error) {
	rows, err := s.q.ActiveLevels(ctx)
	if err != nil {
		return nil, err
	}
	out := make([]LevelOption, 0, len(rows))
	for _, r := range rows {
		out = append(out, LevelOption{Value: r.ID, Label: r.Name + " (" + r.Code + ")"})
	}
	return out, nil
}

type CourseInput struct {
	Name          string     `json:"name"`
	CourseLevelID *uuid.UUID `json:"course_level_id"`
	TuitionFee    *int64     `json:"tuition_fee"`
	TotalLessons  *int32     `json:"total_lessons"`
	Description   string     `json:"description"`
	IsActive      *bool      `json:"is_active"`
}

func (s *Service) validateCourse(ctx context.Context, in *CourseInput) error {
	in.Name = strings.TrimSpace(in.Name)
	switch {
	case in.Name == "":
		return iam.Invalid("Vui lòng nhập tên khóa học.")
	case tooLong(in.Name, 255):
		return iam.Invalid("Tên khóa học không được vượt quá 255 ký tự.")
	case in.TuitionFee == nil:
		return iam.Invalid("Vui lòng nhập giá học phí.")
	case *in.TuitionFee < 0:
		return iam.Invalid("Giá học phí phải là số hợp lệ, không âm.")
	case in.TotalLessons == nil:
		return iam.Invalid("Vui lòng nhập số buổi học.")
	case *in.TotalLessons < 1:
		return iam.Invalid("Số buổi học phải từ 1 trở lên.")
	case tooLong(strings.TrimSpace(in.Description), 1000):
		return iam.Invalid("Mô tả không được vượt quá 1000 ký tự.")
	}
	if in.CourseLevelID != nil {
		if _, err := s.q.GetLevel(ctx, *in.CourseLevelID); isNoRows(err) {
			return iam.Invalid("Khung trình độ đã chọn không tồn tại trong hệ thống.")
		} else if err != nil {
			return err
		}
	}
	return nil
}

// CreateCourse: thêm khóa học & giá; mã CS0001 cấp tự động (khóa bảng để hai người tạo cùng lúc không trùng mã).
func (s *Service) CreateCourse(ctx context.Context, actor *iam.Access, in CourseInput) (catalogsdb.AcademicCourse, string, error) {
	if err := s.validateCourse(ctx, &in); err != nil {
		return catalogsdb.AcademicCourse{}, "", err
	}
	active := in.IsActive == nil || *in.IsActive
	var c catalogsdb.AcademicCourse
	err := s.tx(ctx, func(q *catalogsdb.Queries) error {
		if err := q.LockKey(ctx, "academic.course_code"); err != nil {
			return err
		}
		n, err := q.NextCourseNumber(ctx)
		if err != nil {
			return err
		}
		c, err = q.CreateCourse(ctx, catalogsdb.CreateCourseParams{
			Code: fmt.Sprintf("CS%04d", n), Name: in.Name, CourseLevelID: in.CourseLevelID, TuitionFee: *in.TuitionFee,
			TotalLessons: *in.TotalLessons, Description: optText(in.Description), IsActive: active, CreatedBy: &actor.UserID,
		})
		return err
	})
	if err != nil {
		return c, "", err
	}
	s.log.Info("course created", "course_id", c.ID, "code", c.Code, "by", actor.UserID)
	return c, fmt.Sprintf("Đã thêm khóa học '%s' (%s) với học phí %s thành công!", c.Name, c.Code, formatMoney(c.TuitionFee)), nil
}

func (s *Service) UpdateCourse(ctx context.Context, actor *iam.Access, id uuid.UUID, in CourseInput) (catalogsdb.AcademicCourse, string, error) {
	old, err := s.q.GetCourse(ctx, id)
	if isNoRows(err) {
		return old, "", errCourseNotFound
	}
	if err != nil {
		return old, "", err
	}
	if err := s.validateCourse(ctx, &in); err != nil {
		return old, "", err
	}
	active := old.IsActive
	if in.IsActive != nil {
		active = *in.IsActive
	}
	c, err := s.q.UpdateCourse(ctx, catalogsdb.UpdateCourseParams{
		ID: id, Name: in.Name, CourseLevelID: in.CourseLevelID, TuitionFee: *in.TuitionFee, TotalLessons: *in.TotalLessons,
		Description: optText(in.Description), IsActive: active,
	})
	if isNoRows(err) {
		return c, "", errCourseNotFound
	}
	if err != nil {
		return c, "", err
	}
	s.log.Info("course updated", "course_id", id, "old_fee", old.TuitionFee, "fee", c.TuitionFee, "by", actor.UserID)
	msg := fmt.Sprintf("Cập nhật khóa học '%s' thành công!", c.Name)
	if old.TuitionFee != c.TuitionFee {
		msg += fmt.Sprintf(" (Đã cập nhật giá từ %s thành %s)", formatMoney(old.TuitionFee), formatMoney(c.TuitionFee))
	}
	return c, msg, nil
}

// ToggleCourse: bật / tắt mở bán.
func (s *Service) ToggleCourse(ctx context.Context, actor *iam.Access, id uuid.UUID) (catalogsdb.AcademicCourse, string, error) {
	c, err := s.q.ToggleCourse(ctx, id)
	if isNoRows(err) {
		return c, "", errCourseNotFound
	}
	if err != nil {
		return c, "", err
	}
	s.log.Info("course toggled", "course_id", id, "active", c.IsActive, "by", actor.UserID)
	state := "Tạm ngưng mở bán"
	if c.IsActive {
		state = "Kích hoạt mở bán"
	}
	return c, fmt.Sprintf("Đã %s cho khóa học '%s'!", state, c.Name), nil
}

// DeleteCourse: xóa mềm; khóa đang có lớp thì chặn.
func (s *Service) DeleteCourse(ctx context.Context, actor *iam.Access, id uuid.UUID) (string, error) {
	c, err := s.q.GetCourse(ctx, id)
	if isNoRows(err) {
		return "", errCourseNotFound
	}
	if err != nil {
		return "", err
	}
	if s.Classes != nil {
		counts, err := s.Classes.CourseClassCounts(ctx, []uuid.UUID{id})
		if err != nil {
			return "", err
		}
		if n := counts[id]; n > 0 {
			return "", conflict(fmt.Sprintf("Không thể xóa khóa học '%s' vì đang có %d lớp học liên kết!", c.Name, n))
		}
	}
	if err := s.q.DeleteCourse(ctx, id); err != nil {
		return "", err
	}
	s.log.Info("course deleted", "course_id", id, "by", actor.UserID)
	return fmt.Sprintf("Đã xóa khóa học '%s' khỏi hệ thống!", c.Name), nil
}

// formatMoney: "1.500.000 đ" như Money::format của Laravel.
func formatMoney(v int64) string {
	neg := v < 0
	if neg {
		v = -v
	}
	s := strconv.FormatInt(v, 10)
	var b strings.Builder
	for i, ch := range s {
		if i > 0 && (len(s)-i)%3 == 0 {
			b.WriteByte('.')
		}
		b.WriteRune(ch)
	}
	out := b.String() + " đ"
	if neg {
		out = "-" + out
	}
	return out
}
