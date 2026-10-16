package catalogs

import (
	"context"
	"fmt"
	"slices"
	"strings"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/catalogs/catalogsdb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// Trình độ (CEFR) (CourseLevelController): danh sách có tìm kiếm / lọc, kéo thả sắp xếp, bật / tắt, xóa khi chưa dùng.

const levelsPageSize = 15

var errLevelNotFound = notFound("Không tìm thấy trình độ.")

// GradeLevels: cấp độ test đầu vào (PlacementTest::GRADE_LEVELS), đúng thứ tự.
var GradeLevels = []Option{
	{"mau_giao", "Mẫu giáo"}, {"lop_1", "Lớp 1"}, {"lop_2", "Lớp 2"}, {"lop_3", "Lớp 3"}, {"lop_4", "Lớp 4"},
	{"lop_5", "Lớp 5"}, {"lop_6", "Lớp 6"}, {"lop_7", "Lớp 7"}, {"lop_8", "Lớp 8"}, {"lop_9", "Lớp 9"},
}

type Option struct {
	Value string `json:"value"`
	Label string `json:"label"`
}

func gradeLabel(v string) string {
	for _, g := range GradeLevels {
		if g.Value == v {
			return g.Label
		}
	}
	return ""
}

type Level struct {
	ID                   uuid.UUID  `json:"id"`
	Code                 string     `json:"code"`
	Name                 string     `json:"name"`
	Description          *string    `json:"description"`
	LevelGroup           *string    `json:"level_group"`
	GradeLevels          []string   `json:"grade_levels"`
	GradeLabels          []string   `json:"grade_labels"`
	Target               string     `json:"target"`
	LessonsCount         int32      `json:"lessons_count"`
	SyllabusCurriculumID *uuid.UUID `json:"syllabus_curriculum_id"`
	IsActive             bool       `json:"is_active"`
	CoursesCount         int64      `json:"courses_count"`
	ClassesCount         int64      `json:"classes_count"`
	StudentsUsing        int64      `json:"students_using"`
}

type LevelFilter struct {
	Search string
	Status string // active | inactive
	Group  string
	Page   int
}

type LevelStats struct {
	Total    int64 `json:"total"`
	Active   int64 `json:"active"`
	Syllabus int64 `json:"syllabus"`
	Groups   int64 `json:"groups"`
}

type LevelList struct {
	Page[Level]
	Stats       LevelStats `json:"stats"`
	Groups      []string   `json:"groups"`
	GradeLevels []Option   `json:"grade_levels"`
	// Chỉ kéo thả sắp xếp khi không lọc (giữ đúng thứ tự toàn bộ)
	CanReorder bool `json:"can_reorder"`
}

func (s *Service) ListLevels(ctx context.Context, f LevelFilter) (LevelList, error) {
	if f.Status != "" && f.Status != "active" && f.Status != "inactive" {
		return LevelList{}, iam.Invalid("Trạng thái lọc không hợp lệ.")
	}
	if tooLong(f.Group, 50) {
		return LevelList{}, iam.Invalid("Nhóm trình độ không được vượt quá 50 ký tự.")
	}
	page, lim, off := limits(f.Page, levelsPageSize)
	rows, err := s.q.ListLevels(ctx, catalogsdb.ListLevelsParams{
		Search: likeArg(f.Search), Active: statusFilter(f.Status), LevelGroup: optText(f.Group), Lim: lim, Off: off,
	})
	if err != nil {
		return LevelList{}, err
	}
	var total int64
	items := make([]Level, 0, len(rows))
	for _, r := range rows {
		total = r.Total
		labels := []string{}
		for _, g := range r.GradeLevels {
			if l := gradeLabel(g); l != "" {
				labels = append(labels, l)
			}
		}
		items = append(items, Level{
			ID: r.ID, Code: r.Code, Name: r.Name, Description: r.Description, LevelGroup: r.LevelGroup,
			GradeLevels: nonNil(r.GradeLevels), GradeLabels: labels, Target: r.Target, LessonsCount: r.LessonsCount,
			SyllabusCurriculumID: r.SyllabusCurriculumID, IsActive: r.IsActive, CoursesCount: r.CoursesCount,
			// TODO(classes): số lớp dùng trình độ và số học viên đang học khi có academic.classes / students.
		})
	}
	st, err := s.q.LevelStats(ctx)
	if err != nil {
		return LevelList{}, err
	}
	groups, err := s.q.LevelGroups(ctx)
	if err != nil {
		return LevelList{}, err
	}
	return LevelList{
		Page:        newPage(items, total, page, levelsPageSize),
		Stats:       LevelStats{Total: st.Total, Active: st.Active, Syllabus: st.Syllabus, Groups: st.Groups},
		Groups:      nonNil(groups),
		GradeLevels: GradeLevels,
		CanReorder:  strings.TrimSpace(f.Search) == "" && f.Status == "" && f.Group == "",
	}, nil
}

func nonNil[T any](s []T) []T {
	if s == nil {
		return []T{}
	}
	return s
}

type LevelInput struct {
	Code                 string     `json:"code"` // chỉ dùng khi thêm
	Name                 string     `json:"name"`
	Description          string     `json:"description"`
	LevelGroup           string     `json:"level_group"`
	GradeLevels          []string   `json:"grade_levels"`
	Target               string     `json:"target"`
	LessonsCount         *int32     `json:"lessons_count"`
	SyllabusCurriculumID *uuid.UUID `json:"syllabus_curriculum_id"`
	IsActive             *bool      `json:"is_active"`
}

type levelData struct {
	name, target string
	description  *string
	group        *string
	grades       []string
	lessons      int32
}

func (in *LevelInput) validate() (levelData, error) {
	d := levelData{name: strings.TrimSpace(in.Name), target: strings.TrimSpace(in.Target), description: optText(in.Description)}
	switch {
	case d.name == "":
		return d, iam.Invalid("Vui lòng nhập tên trình độ.")
	case tooLong(d.name, 255):
		return d, iam.Invalid("Tên trình độ không được vượt quá 255 ký tự.")
	case d.target == "":
		return d, iam.Invalid("Vui lòng nhập mục tiêu đầu ra.")
	case tooLong(d.target, 255):
		return d, iam.Invalid("Mục tiêu đầu ra không được vượt quá 255 ký tự.")
	case in.LessonsCount == nil:
		return d, iam.Invalid("Vui lòng nhập số buổi học.")
	case *in.LessonsCount < 1:
		return d, iam.Invalid("Số buổi học phải từ 1 trở lên.")
	case d.description != nil && tooLong(*d.description, 1000):
		return d, iam.Invalid("Mô tả không được vượt quá 1000 ký tự.")
	case tooLong(strings.TrimSpace(in.LevelGroup), 50):
		return d, iam.Invalid("Nhóm trình độ không được vượt quá 50 ký tự.")
	case in.SyllabusCurriculumID != nil:
		// Giáo trình (syllabus.curricula) chưa chuyển sang v2: chưa gắn được.
		return d, iam.Invalid("Chưa gắn được giáo trình: phân hệ giáo trình chưa có trên hệ thống mới.")
	}
	d.lessons = *in.LessonsCount
	if g := strings.ToUpper(strings.TrimSpace(in.LevelGroup)); g != "" {
		d.group = &g
	}
	// Cấp độ test theo thứ tự danh sách, bỏ trùng.
	d.grades = []string{}
	for _, g := range GradeLevels {
		if slices.Contains(in.GradeLevels, g.Value) {
			d.grades = append(d.grades, g.Value)
		}
	}
	for _, g := range in.GradeLevels {
		if gradeLabel(g) == "" {
			return d, iam.Invalid("Cấp độ test đầu vào không hợp lệ.")
		}
	}
	return d, nil
}

func (s *Service) CreateLevel(ctx context.Context, actor *iam.Access, in LevelInput) (catalogsdb.AcademicCourseLevel, string, error) {
	var zero catalogsdb.AcademicCourseLevel
	code := strings.TrimSpace(in.Code)
	switch {
	case code == "":
		return zero, "", iam.Invalid("Vui lòng nhập mã trình độ.")
	case tooLong(code, 20):
		return zero, "", iam.Invalid("Mã trình độ không được vượt quá 20 ký tự.")
	}
	d, err := in.validate()
	if err != nil {
		return zero, "", err
	}
	if taken, err := s.q.LevelCodeTaken(ctx, code); err != nil {
		return zero, "", err
	} else if taken {
		return zero, "", iam.Invalid("Mã trình độ đã tồn tại trên hệ thống, vui lòng chọn mã khác.")
	}
	active := in.IsActive == nil || *in.IsActive
	var lv catalogsdb.AcademicCourseLevel
	err = s.tx(ctx, func(q *catalogsdb.Queries) error {
		if err := q.LockKey(ctx, "academic.course_levels.sort"); err != nil {
			return err
		}
		lv, err = q.CreateLevel(ctx, catalogsdb.CreateLevelParams{
			Code: code, Name: d.name, Description: d.description, LevelGroup: d.group, GradeLevels: d.grades, Target: d.target,
			LessonsCount: d.lessons, IsActive: active, CreatedBy: &actor.UserID,
		})
		return err
	})
	if isUnique(err, "") {
		return zero, "", iam.Invalid("Mã trình độ đã tồn tại trên hệ thống, vui lòng chọn mã khác.")
	}
	if err != nil {
		return zero, "", err
	}
	s.log.Info("course level created", "level_id", lv.ID, "code", lv.Code, "by", actor.UserID)
	return lv, fmt.Sprintf("Đã thêm khung trình độ %s (%s) thành công!", lv.Name, lv.Code), nil
}

func (s *Service) UpdateLevel(ctx context.Context, actor *iam.Access, id uuid.UUID, in LevelInput) (catalogsdb.AcademicCourseLevel, string, error) {
	old, err := s.q.GetLevel(ctx, id)
	if isNoRows(err) {
		return old, "", errLevelNotFound
	}
	if err != nil {
		return old, "", err
	}
	d, err := in.validate()
	if err != nil {
		return old, "", err
	}
	active := old.IsActive
	if in.IsActive != nil {
		active = *in.IsActive
	}
	lv, err := s.q.UpdateLevel(ctx, catalogsdb.UpdateLevelParams{
		ID: id, Name: d.name, Description: d.description, LevelGroup: d.group, GradeLevels: d.grades, Target: d.target,
		LessonsCount: d.lessons, SyllabusCurriculumID: nil, IsActive: active,
	})
	if isNoRows(err) {
		return lv, "", errLevelNotFound
	}
	if err != nil {
		return lv, "", err
	}
	s.log.Info("course level updated", "level_id", id, "by", actor.UserID)
	return lv, fmt.Sprintf("Cập nhật khung trình độ %s thành công!", old.Name), nil
}

func (s *Service) ToggleLevel(ctx context.Context, actor *iam.Access, id uuid.UUID) (catalogsdb.AcademicCourseLevel, string, error) {
	lv, err := s.q.ToggleLevel(ctx, id)
	if isNoRows(err) {
		return lv, "", errLevelNotFound
	}
	if err != nil {
		return lv, "", err
	}
	s.log.Info("course level toggled", "level_id", id, "active", lv.IsActive, "by", actor.UserID)
	state := "Ngừng hoạt động"
	if lv.IsActive {
		state = "Hoạt động"
	}
	return lv, fmt.Sprintf("Đã chuyển trình độ %s sang %s.", lv.Name, state), nil
}

// DeleteLevel: trình độ đang gắn khóa học (hoặc lớp) không xóa được, chỉ chuyển "Ngừng hoạt động".
func (s *Service) DeleteLevel(ctx context.Context, actor *iam.Access, id uuid.UUID) (string, error) {
	lv, err := s.q.GetLevel(ctx, id)
	if isNoRows(err) {
		return "", errLevelNotFound
	}
	if err != nil {
		return "", err
	}
	courses, err := s.q.LevelCourseCount(ctx, &id)
	if err != nil {
		return "", err
	}
	// TODO(classes): cộng số lớp đang dùng trình độ khi có academic.classes.
	var classes int64
	if courses > 0 || classes > 0 {
		return "", conflict(fmt.Sprintf("Không thể xóa trình độ %s: đang gắn với %d khóa học và %d lớp học. Hãy chuyển sang \"Ngừng hoạt động\".", lv.Name, courses, classes))
	}
	if err := s.q.DeleteLevel(ctx, id); err != nil {
		return "", err
	}
	s.log.Info("course level deleted", "level_id", id, "by", actor.UserID)
	return fmt.Sprintf("Đã xóa trình độ %s!", lv.Name), nil
}

// ReorderLevels: nhận id các trình độ theo thứ tự mới (một trang của danh sách); giữ nguyên các vị trí nhóm id này
// đang chiếm trong thứ tự toàn bộ rồi xếp lại trong các vị trí đó.
func (s *Service) ReorderLevels(ctx context.Context, actor *iam.Access, ids []uuid.UUID) error {
	if len(ids) == 0 || len(ids) > 200 {
		return iam.Invalid("Danh sách trình độ cần sắp xếp phải có từ 1 đến 200 mục.")
	}
	seen := map[uuid.UUID]bool{}
	for _, id := range ids {
		if seen[id] {
			return iam.Invalid("Danh sách trình độ bị trùng.")
		}
		seen[id] = true
	}
	err := s.tx(ctx, func(q *catalogsdb.Queries) error {
		all, err := q.LockLevelOrder(ctx)
		if err != nil {
			return err
		}
		order, err := reorderSlots(all, ids)
		if err != nil {
			return err
		}
		for i, id := range order {
			if err := q.SetLevelSort(ctx, catalogsdb.SetLevelSortParams{ID: id, SortOrder: int32(i + 1)}); err != nil {
				return err
			}
		}
		return nil
	})
	if err != nil {
		return err
	}
	s.log.Info("course levels reordered", "count", len(ids), "by", actor.UserID)
	return nil
}

// reorderSlots đặt ids (thứ tự mới) vào đúng các vị trí chúng đang chiếm trong all.
func reorderSlots(all, ids []uuid.UUID) ([]uuid.UUID, error) {
	want := map[uuid.UUID]bool{}
	for _, id := range ids {
		want[id] = true
	}
	out := slices.Clone(all)
	next := 0
	for i, id := range out {
		if want[id] {
			out[i] = ids[next]
			next++
		}
	}
	if next != len(ids) {
		return nil, iam.Invalid("Có trình độ không tồn tại hoặc đã bị xóa.")
	}
	return out, nil
}
