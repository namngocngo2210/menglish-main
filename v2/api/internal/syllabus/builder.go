package syllabus

import (
	"context"
	"fmt"
	"math/rand/v2"
	"slices"
	"strings"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/syllabus/syllabusdb"
)

// Soạn syllabus: Giáo trình → Chặng → Unit → Buổi (SyllabusController::builder, store/update/destroy*, moveStage).

type Lesson struct {
	ID              uuid.UUID `json:"id"`
	SessionNo       int32     `json:"session_no"`
	Title           string    `json:"title"`
	UnitID          uuid.UUID `json:"unit_id"`
	Objectives      *string   `json:"objectives"`
	Content         *string   `json:"content"`
	HomeworkGuide   *string   `json:"homework_guide"`
	VocabularyFocus *string   `json:"vocabulary_focus"`
	GrammarFocus    *string   `json:"grammar_focus"`
}

type Unit struct {
	ID              uuid.UUID  `json:"id"`
	StageID         *uuid.UUID `json:"stage_id"`
	UnitNumber      int32      `json:"unit_number"`
	Title           string     `json:"title"`
	Objectives      *string    `json:"objectives"`
	VocabularyFocus *string    `json:"vocabulary_focus"`
	GrammarFocus    *string    `json:"grammar_focus"`
	HomeworkGuide   *string    `json:"homework_guide"`
	Lessons         []Lesson   `json:"lessons"`
}

type Stage struct {
	ID           uuid.UUID `json:"id"`
	Position     int16     `json:"position"`
	Label        string    `json:"label"`
	Name         string    `json:"name"`
	Description  *string   `json:"description"`
	OverviewLink *string   `json:"overview_link"`
	BigTestTitle *string   `json:"big_test_title"`
	BigTestNote  *string   `json:"big_test_note"`
	OpenClasses  string    `json:"open_classes"`
	Units        []Unit    `json:"units"`
}

type Curriculum struct {
	ID          uuid.UUID   `json:"id"`
	Title       string      `json:"title"`
	Code        string      `json:"code"`
	Version     string      `json:"version"`
	CourseID    *uuid.UUID  `json:"course_id"`
	Description *string     `json:"description"`
	LevelIDs    []uuid.UUID `json:"level_ids"`
}

type LevelRef struct {
	ID   uuid.UUID `json:"id"`
	Code string    `json:"code"`
	Name string    `json:"name"`
	// Trình độ đang gắn giáo trình khác
	Other bool `json:"other"`
}

type BuilderTotals struct {
	Units          int   `json:"units"`
	Lessons        int   `json:"lessons"`
	NextPosition   int16 `json:"next_position"`
	NextUnitNumber int32 `json:"next_unit_number"`
	NextSessionNo  int32 `json:"next_session_no"`
}

type BuilderPage struct {
	Curriculums []Option      `json:"curriculums"`
	Curriculum  *Curriculum   `json:"curriculum"`
	Stages      []Stage       `json:"stages"`
	Totals      BuilderTotals `json:"totals"`
	UnitOptions []Option      `json:"unit_options"`
	Courses     []Option      `json:"courses"`
	Levels      []LevelRef    `json:"levels"`
	NewCode     string        `json:"new_code"`
	CanManage   bool          `json:"can_manage"`
}

func newCurriculumCode() string {
	const letters = "ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789"
	b := make([]byte, 4)
	for i := range b {
		b[i] = letters[rand.IntN(len(letters))]
	}
	return "CUR-" + string(b)
}

// stageTree: chặng → unit → buổi của các giáo trình.
type stageTree struct {
	stages  []syllabusdb.ListStagesRow
	units   map[uuid.UUID][]syllabusdb.ListUnitsRow   // theo chặng
	lessons map[uuid.UUID][]syllabusdb.ListLessonsRow // theo unit
}

func (s *Service) loadTree(ctx context.Context, curriculumID uuid.UUID) (*stageTree, error) {
	stages, err := s.q.ListStages(ctx, []uuid.UUID{curriculumID})
	if err != nil {
		return nil, err
	}
	units, err := s.q.ListUnits(ctx, curriculumID)
	if err != nil {
		return nil, err
	}
	lessons, err := s.q.ListLessons(ctx, []uuid.UUID{curriculumID})
	if err != nil {
		return nil, err
	}
	t := &stageTree{stages: stages, units: map[uuid.UUID][]syllabusdb.ListUnitsRow{}, lessons: map[uuid.UUID][]syllabusdb.ListLessonsRow{}}
	for _, u := range units {
		if u.StageID != nil {
			t.units[*u.StageID] = append(t.units[*u.StageID], u)
		}
	}
	for _, l := range lessons {
		t.lessons[l.UnitID] = append(t.lessons[l.UnitID], l)
	}
	return t, nil
}

func toLesson(l syllabusdb.ListLessonsRow) Lesson {
	return Lesson{ID: l.ID, SessionNo: l.SessionNo, Title: l.Title, UnitID: l.UnitID, Objectives: l.Objectives, Content: l.Content,
		HomeworkGuide: l.HomeworkGuide, VocabularyFocus: l.VocabularyFocus, GrammarFocus: l.GrammarFocus}
}

func (t *stageTree) unitsOf(stage uuid.UUID) []Unit {
	out := []Unit{}
	for _, u := range t.units[stage] {
		ls := []Lesson{}
		for _, l := range t.lessons[u.ID] {
			ls = append(ls, toLesson(l))
		}
		out = append(out, Unit{ID: u.ID, StageID: u.StageID, UnitNumber: u.UnitNumber, Title: u.Title, Objectives: u.Objectives,
			VocabularyFocus: u.VocabularyFocus, GrammarFocus: u.GrammarFocus, HomeworkGuide: u.HomeworkGuide, Lessons: ls})
	}
	return out
}

func (s *Service) Builder(ctx context.Context, actor *iam.Access, curriculumID *uuid.UUID) (*BuilderPage, error) {
	cs, err := s.q.ListCurricula(ctx)
	if err != nil {
		return nil, err
	}
	out := &BuilderPage{Curriculums: []Option{}, Stages: []Stage{}, UnitOptions: []Option{}, Courses: []Option{}, Levels: []LevelRef{},
		NewCode: newCurriculumCode(), CanManage: actor.Can("syllabus.manage")}
	for _, c := range cs {
		out.Curriculums = append(out.Curriculums, Option{c.ID.String(), c.Title + " (" + c.Code + " · " + c.Version + ")"})
	}
	var cur *syllabusdb.ListCurriculaRow
	if curriculumID != nil {
		i := slices.IndexFunc(cs, func(c syllabusdb.ListCurriculaRow) bool { return c.ID == *curriculumID })
		if i < 0 {
			return nil, ErrNotFound
		}
		cur = &cs[i]
	} else if len(cs) > 0 {
		cur = &cs[0]
	}
	courses, err := s.q.ListCourses(ctx)
	if err != nil {
		return nil, err
	}
	for _, c := range courses {
		out.Courses = append(out.Courses, Option{c.ID.String(), c.Name})
	}
	levels, err := s.q.ListLevels(ctx)
	if err != nil {
		return nil, err
	}
	if cur == nil {
		for _, l := range levels {
			out.Levels = append(out.Levels, LevelRef{ID: l.ID, Code: l.Code, Name: l.Name})
		}
		out.Totals = BuilderTotals{NextPosition: 1, NextUnitNumber: 1, NextSessionNo: 1}
		return out, nil
	}
	c := Curriculum{ID: cur.ID, Title: cur.Title, Code: cur.Code, Version: cur.Version, CourseID: cur.CourseID, Description: cur.Description, LevelIDs: []uuid.UUID{}}
	for _, l := range levels {
		if l.SyllabusCurriculumID != nil && *l.SyllabusCurriculumID == cur.ID {
			c.LevelIDs = append(c.LevelIDs, l.ID)
		}
		out.Levels = append(out.Levels, LevelRef{ID: l.ID, Code: l.Code, Name: l.Name,
			Other: l.SyllabusCurriculumID != nil && *l.SyllabusCurriculumID != cur.ID})
	}
	out.Curriculum = &c
	tree, err := s.loadTree(ctx, cur.ID)
	if err != nil {
		return nil, err
	}
	open, err := s.q.OpenClassesByStage(ctx, cur.ID)
	if err != nil {
		return nil, err
	}
	openBy := map[uuid.UUID][]string{}
	for _, o := range open {
		if o.StageID != nil {
			openBy[*o.StageID] = append(openBy[*o.StageID], o.ClassName)
		}
	}
	out.Totals = BuilderTotals{NextPosition: 1, NextUnitNumber: 1, NextSessionNo: 1}
	for _, st := range tree.stages {
		units := tree.unitsOf(st.ID)
		out.Stages = append(out.Stages, Stage{
			ID: st.ID, Position: st.Position, Label: stageLabel(st.Position, st.Name), Name: st.Name, Description: st.Description,
			OverviewLink: st.OverviewLink, BigTestTitle: st.BigTestTitle, BigTestNote: st.BigTestNote,
			OpenClasses: strings.Join(openBy[st.ID], ", "), Units: units,
		})
		out.Totals.NextPosition = max(out.Totals.NextPosition, st.Position+1)
		for _, u := range units {
			out.Totals.Units++
			out.Totals.NextUnitNumber = max(out.Totals.NextUnitNumber, u.UnitNumber+1)
			out.UnitOptions = append(out.UnitOptions, Option{u.ID.String(), fmt.Sprintf("Unit %d: %s", u.UnitNumber, u.Title)})
			for _, l := range u.Lessons {
				out.Totals.Lessons++
				out.Totals.NextSessionNo = max(out.Totals.NextSessionNo, l.SessionNo+1)
			}
		}
	}
	return out, nil
}

// ───────────── Giáo trình ─────────────

type CurriculumInput struct {
	Code        string     `json:"code"`
	Title       string     `json:"title"`
	CourseID    *uuid.UUID `json:"course_id"`
	Version     string     `json:"version"`
	Description *string    `json:"description"`
	// nil = không đổi trình độ áp dụng; [] = gỡ giáo trình khỏi mọi trình độ
	LevelIDs *[]uuid.UUID `json:"level_ids"`
	// Tên chặng đầu tiên khi tạo (trống = "Chặng 1")
	StageName *string `json:"stage_name"`
}

func (s *Service) validateCurriculum(ctx context.Context, in *CurriculumInput, exclude *uuid.UUID) error {
	in.Code, in.Title, in.Version = strings.TrimSpace(in.Code), strings.TrimSpace(in.Title), strings.TrimSpace(in.Version)
	in.Description = optText(in.Description)
	switch {
	case in.Code == "":
		return iam.Invalid("Vui lòng nhập mã giáo trình.")
	case runeLen(in.Code) > 50:
		return iam.Invalid("Mã giáo trình tối đa 50 ký tự.")
	case in.Title == "":
		return iam.Invalid("Vui lòng nhập tên giáo trình.")
	case runeLen(in.Title) > 255:
		return iam.Invalid("Tên giáo trình tối đa 255 ký tự.")
	case in.Version == "":
		return iam.Invalid("Vui lòng nhập phiên bản.")
	case runeLen(in.Version) > 20:
		return iam.Invalid("Phiên bản tối đa 20 ký tự.")
	case tooLong(in.Description, 5000):
		return iam.Invalid("Mô tả tối đa 5000 ký tự.")
	case tooLong(in.StageName, 255):
		return iam.Invalid("Tên chặng tối đa 255 ký tự.")
	}
	taken, err := s.q.CurriculumCodeTaken(ctx, syllabusdb.CurriculumCodeTakenParams{Code: in.Code, Exclude: exclude})
	if err != nil {
		return err
	}
	if taken {
		return iam.Invalid("Mã giáo trình đã được sử dụng.")
	}
	if in.CourseID != nil {
		okc, err := s.q.CourseExists(ctx, *in.CourseID)
		if err != nil {
			return err
		}
		if !okc {
			return iam.Invalid("Khóa học đã chọn không tồn tại.")
		}
	}
	if in.LevelIDs != nil && len(*in.LevelIDs) > 0 {
		ids := dedupe(*in.LevelIDs)
		n, err := s.q.CountLevels(ctx, ids)
		if err != nil {
			return err
		}
		if int(n) != len(ids) {
			return iam.Invalid("Trình độ đã chọn không tồn tại.")
		}
		*in.LevelIDs = ids
	}
	return nil
}

// syncLevels gắn giáo trình cho Trình độ (course_levels.syllabus_curriculum_id). nil = không đổi.
func syncLevels(ctx context.Context, q *syllabusdb.Queries, id uuid.UUID, levels *[]uuid.UUID) error {
	if levels == nil {
		return nil
	}
	keep := *levels
	if keep == nil {
		keep = []uuid.UUID{}
	}
	if err := q.DetachLevels(ctx, syllabusdb.DetachLevelsParams{CurriculumID: &id, Keep: keep}); err != nil {
		return err
	}
	if len(keep) == 0 {
		return nil
	}
	return q.AttachLevels(ctx, syllabusdb.AttachLevelsParams{CurriculumID: &id, Ids: keep})
}

func (s *Service) CreateCurriculum(ctx context.Context, actor *iam.Access, in CurriculumInput) (*Message, error) {
	if err := s.validateCurriculum(ctx, &in, nil); err != nil {
		return nil, err
	}
	var id uuid.UUID
	err := s.inTx(ctx, func(tx pgx.Tx, q *syllabusdb.Queries) error {
		var err error
		id, err = q.CreateCurriculum(ctx, syllabusdb.CreateCurriculumParams{
			Code: in.Code, Title: in.Title, CourseID: in.CourseID, Version: in.Version, Description: in.Description, CreatedBy: &actor.UserID,
		})
		if isUnique(err) {
			return iam.Invalid("Mã giáo trình đã được sử dụng.")
		}
		if err != nil {
			return err
		}
		// Giáo trình mới tự có "Chặng 1" (tên lấy từ ô Chặng học nếu có).
		name := "Chặng 1"
		if n := optText(in.StageName); n != nil {
			name = *n
		}
		if _, err := q.CreateStage(ctx, syllabusdb.CreateStageParams{CurriculumID: id, Position: 1, Name: name}); err != nil {
			return err
		}
		if err := syncLevels(ctx, q, id, in.LevelIDs); err != nil {
			return err
		}
		return record(ctx, tx, &actor.UserID, audit.EventCreated, "syllabus_curriculum.create", "syllabus_curriculum", id, in.Title,
			"Tạo giáo trình "+in.Title+" ("+in.Code+")", nil)
	})
	if err != nil {
		return nil, err
	}
	return okID("Đã tạo giáo trình "+in.Title+" (kèm Chặng 1).", id), nil
}

func (s *Service) UpdateCurriculum(ctx context.Context, actor *iam.Access, id uuid.UUID, in CurriculumInput) (*Message, error) {
	if _, err := s.q.GetCurriculum(ctx, id); isNoRows(err) {
		return nil, ErrNotFound
	} else if err != nil {
		return nil, err
	}
	if err := s.validateCurriculum(ctx, &in, &id); err != nil {
		return nil, err
	}
	err := s.inTx(ctx, func(tx pgx.Tx, q *syllabusdb.Queries) error {
		err := q.UpdateCurriculum(ctx, syllabusdb.UpdateCurriculumParams{
			ID: id, Code: in.Code, Title: in.Title, CourseID: in.CourseID, Version: in.Version, Description: in.Description,
		})
		if isUnique(err) {
			return iam.Invalid("Mã giáo trình đã được sử dụng.")
		}
		if err != nil {
			return err
		}
		if err := syncLevels(ctx, q, id, in.LevelIDs); err != nil {
			return err
		}
		return record(ctx, tx, &actor.UserID, audit.EventUpdated, "syllabus_curriculum.update", "syllabus_curriculum", id, in.Title,
			"Cập nhật giáo trình "+in.Title, nil)
	})
	if err != nil {
		return nil, err
	}
	return okID("Đã lưu thông tin giáo trình "+in.Title+".", id), nil
}

// DeleteCurriculum: xóa mềm dây chuyền (tài liệu, buổi, unit, chặng); giữ file tài liệu để có thể khôi phục.
func (s *Service) DeleteCurriculum(ctx context.Context, actor *iam.Access, id uuid.UUID) (*Message, error) {
	c, err := s.q.GetCurriculum(ctx, id)
	if isNoRows(err) {
		return nil, ErrNotFound
	}
	if err != nil {
		return nil, err
	}
	open, err := s.q.OpenAssignmentsOfCurriculum(ctx, id)
	if err != nil {
		return nil, err
	}
	if open > 0 {
		return nil, iam.Invalid("Giáo trình " + c.Title + " đang được lớp học, không thể xóa.")
	}
	err = s.inTx(ctx, func(tx pgx.Tx, q *syllabusdb.Queries) error {
		if err := q.SoftDeleteCurriculum(ctx, id); err != nil {
			return err
		}
		return record(ctx, tx, &actor.UserID, audit.EventDeleted, "syllabus_curriculum.delete", "syllabus_curriculum", id, c.Title,
			"Xóa giáo trình "+c.Title, nil)
	})
	if err != nil {
		return nil, err
	}
	return ok("Đã xóa giáo trình " + c.Title + "."), nil
}

// ───────────── Chặng ─────────────

type StageInput struct {
	CurriculumID uuid.UUID `json:"curriculum_id"`
	Name         string    `json:"name"`
	Description  *string   `json:"description"`
	OverviewLink *string   `json:"overview_link"`
	BigTestTitle *string   `json:"big_test_title"`
	BigTestNote  *string   `json:"big_test_note"`
}

func validateStage(in *StageInput) error {
	in.Name = strings.TrimSpace(in.Name)
	in.Description, in.OverviewLink = optText(in.Description), optText(in.OverviewLink)
	in.BigTestTitle, in.BigTestNote = optText(in.BigTestTitle), optText(in.BigTestNote)
	switch {
	case in.Name == "":
		return iam.Invalid("Vui lòng nhập tên chặng.")
	case runeLen(in.Name) > 255:
		return iam.Invalid("Tên chặng tối đa 255 ký tự.")
	case tooLong(in.Description, 5000):
		return iam.Invalid("Mô tả chặng tối đa 5000 ký tự.")
	case in.OverviewLink != nil && (!validURL(*in.OverviewLink) || runeLen(*in.OverviewLink) > 500):
		return iam.Invalid("Link tổng quan phải là đường dẫn http(s) hợp lệ, tối đa 500 ký tự.")
	case tooLong(in.BigTestTitle, 255):
		return iam.Invalid("Tên Big Test tối đa 255 ký tự.")
	case tooLong(in.BigTestNote, 5000):
		return iam.Invalid("Ghi chú Big Test tối đa 5000 ký tự.")
	}
	return nil
}

func (s *Service) CreateStage(ctx context.Context, actor *iam.Access, in StageInput) (*Message, error) {
	if err := validateStage(&in); err != nil {
		return nil, err
	}
	if _, err := s.q.GetCurriculum(ctx, in.CurriculumID); isNoRows(err) {
		return nil, iam.Invalid("Giáo trình đã chọn không tồn tại.")
	} else if err != nil {
		return nil, err
	}
	var id uuid.UUID
	var label string
	err := s.inTx(ctx, func(tx pgx.Tx, q *syllabusdb.Queries) error {
		if err := q.LockKey(ctx, "syllabus.stages:"+in.CurriculumID.String()); err != nil {
			return err
		}
		pos, err := q.MaxStagePosition(ctx, in.CurriculumID)
		if err != nil {
			return err
		}
		id, err = q.CreateStage(ctx, syllabusdb.CreateStageParams{
			CurriculumID: in.CurriculumID, Position: int16(pos + 1), Name: in.Name, Description: in.Description,
			OverviewLink: in.OverviewLink, BigTestTitle: in.BigTestTitle, BigTestNote: in.BigTestNote,
		})
		if err != nil {
			return err
		}
		label = stageLabel(int16(pos+1), in.Name)
		return record(ctx, tx, &actor.UserID, audit.EventCreated, "syllabus_stage.create", "syllabus_stage", id, label, "Thêm "+label, nil)
	})
	if err != nil {
		return nil, err
	}
	return okID("Đã thêm "+label+".", id), nil
}

func (s *Service) UpdateStage(ctx context.Context, actor *iam.Access, id uuid.UUID, in StageInput) (*Message, error) {
	st, err := s.q.GetStage(ctx, id)
	if isNoRows(err) {
		return nil, ErrNotFound
	}
	if err != nil {
		return nil, err
	}
	if err := validateStage(&in); err != nil {
		return nil, err
	}
	label := stageLabel(st.Position, in.Name)
	err = s.inTx(ctx, func(tx pgx.Tx, q *syllabusdb.Queries) error {
		if err := q.UpdateStage(ctx, syllabusdb.UpdateStageParams{
			ID: id, Name: in.Name, Description: in.Description, OverviewLink: in.OverviewLink, BigTestTitle: in.BigTestTitle, BigTestNote: in.BigTestNote,
		}); err != nil {
			return err
		}
		return record(ctx, tx, &actor.UserID, audit.EventUpdated, "syllabus_stage.update", "syllabus_stage", id, label, "Cập nhật "+label, nil)
	})
	if err != nil {
		return nil, err
	}
	return okID("Đã lưu thông tin "+label+".", id), nil
}

// renumberStages: đánh số lại position 1..n theo thứ tự hiện tại.
func renumberStages(ctx context.Context, q *syllabusdb.Queries, curriculumID uuid.UUID) ([]syllabusdb.ListStagesRow, error) {
	stages, err := q.ListStages(ctx, []uuid.UUID{curriculumID})
	if err != nil {
		return nil, err
	}
	for i := range stages {
		if want := int16(i + 1); stages[i].Position != want {
			if err := q.SetStagePosition(ctx, syllabusdb.SetStagePositionParams{ID: stages[i].ID, Position: want}); err != nil {
				return nil, err
			}
			stages[i].Position = want
		}
	}
	return stages, nil
}

func (s *Service) DeleteStage(ctx context.Context, actor *iam.Access, id uuid.UUID) (*Message, error) {
	st, err := s.q.GetStage(ctx, id)
	if isNoRows(err) {
		return nil, ErrNotFound
	}
	if err != nil {
		return nil, err
	}
	label := stageLabel(st.Position, st.Name)
	use, err := s.q.StageUsage(ctx, id)
	if err != nil {
		return nil, err
	}
	switch {
	case use.Stages <= 1:
		return nil, iam.Invalid("Giáo trình phải có ít nhất 1 chặng.")
	case use.Units > 0:
		return nil, iam.Invalid(fmt.Sprintf("%s còn %d unit — hãy xóa hoặc chuyển unit sang chặng khác trước.", label, use.Units))
	case use.Assignments > 0 || use.BigTests > 0:
		return nil, iam.Invalid(label + " đã được giao cho lớp / gắn Big Test, không thể xóa.")
	}
	err = s.inTx(ctx, func(tx pgx.Tx, q *syllabusdb.Queries) error {
		if err := q.LockKey(ctx, "syllabus.stages:"+st.CurriculumID.String()); err != nil {
			return err
		}
		if err := q.SoftDeleteStage(ctx, id); err != nil {
			return err
		}
		if _, err := renumberStages(ctx, q, st.CurriculumID); err != nil {
			return err
		}
		return record(ctx, tx, &actor.UserID, audit.EventDeleted, "syllabus_stage.delete", "syllabus_stage", id, label, "Xóa "+label, nil)
	})
	if err != nil {
		return nil, err
	}
	return ok("Đã xóa " + label + "."), nil
}

// MoveStage: đổi thứ tự chặng lên / xuống 1 bậc. Thứ tự quyết định chặng nào tự mở sau Big Test.
func (s *Service) MoveStage(ctx context.Context, actor *iam.Access, id uuid.UUID, direction string) (*Message, error) {
	if direction != "up" && direction != "down" {
		return nil, iam.Invalid("Hướng di chuyển không hợp lệ.")
	}
	st, err := s.q.GetStage(ctx, id)
	if isNoRows(err) {
		return nil, ErrNotFound
	}
	if err != nil {
		return nil, err
	}
	err = s.inTx(ctx, func(tx pgx.Tx, q *syllabusdb.Queries) error {
		if err := q.LockKey(ctx, "syllabus.stages:"+st.CurriculumID.String()); err != nil {
			return err
		}
		ordered, err := renumberStages(ctx, q, st.CurriculumID)
		if err != nil {
			return err
		}
		i := slices.IndexFunc(ordered, func(x syllabusdb.ListStagesRow) bool { return x.ID == id })
		j := i - 1
		if direction == "down" {
			j = i + 1
		}
		if i < 0 || j < 0 || j >= len(ordered) {
			return nil
		}
		a, b := ordered[i], ordered[j]
		if err := q.SetStagePosition(ctx, syllabusdb.SetStagePositionParams{ID: a.ID, Position: b.Position}); err != nil {
			return err
		}
		if err := q.SetStagePosition(ctx, syllabusdb.SetStagePositionParams{ID: b.ID, Position: a.Position}); err != nil {
			return err
		}
		return record(ctx, tx, &actor.UserID, audit.EventUpdated, "syllabus_stage.move", "syllabus_stage", id, stageLabel(a.Position, a.Name),
			"Đổi thứ tự chặng "+stageLabel(a.Position, a.Name), nil)
	})
	if err != nil {
		return nil, err
	}
	return ok("Đã đổi thứ tự chặng."), nil
}

// ───────────── Unit ─────────────

type UnitInput struct {
	CurriculumID    uuid.UUID  `json:"curriculum_id"`
	StageID         *uuid.UUID `json:"stage_id"`
	UnitNumber      int32      `json:"unit_number"`
	Title           string     `json:"title"`
	Objectives      *string    `json:"objectives"`
	VocabularyFocus *string    `json:"vocabulary_focus"`
	GrammarFocus    *string    `json:"grammar_focus"`
	HomeworkGuide   *string    `json:"homework_guide"`
}

func (in *UnitInput) clean() error {
	in.Title = strings.TrimSpace(in.Title)
	in.Objectives, in.VocabularyFocus = optText(in.Objectives), optText(in.VocabularyFocus)
	in.GrammarFocus, in.HomeworkGuide = optText(in.GrammarFocus), optText(in.HomeworkGuide)
	switch {
	case in.UnitNumber < 1 || in.UnitNumber > 500:
		return iam.Invalid("Số unit phải từ 1 đến 500.")
	case in.Title == "":
		return iam.Invalid("Vui lòng nhập tên unit.")
	case runeLen(in.Title) > 255:
		return iam.Invalid("Tên unit tối đa 255 ký tự.")
	}
	return nil
}

func (s *Service) uniqueUnit(ctx context.Context, curriculumID uuid.UUID, n int32, exclude *uuid.UUID) error {
	taken, err := s.q.UnitNumberTaken(ctx, syllabusdb.UnitNumberTakenParams{CurriculumID: curriculumID, UnitNumber: n, Exclude: exclude})
	if err != nil {
		return err
	}
	if taken {
		return iam.Invalid(fmt.Sprintf("Unit %d đã có trong giáo trình này.", n))
	}
	return nil
}

func (s *Service) stageIn(ctx context.Context, stage *uuid.UUID, curriculumID uuid.UUID, msg string) error {
	if stage == nil {
		return nil
	}
	okc, err := s.q.StageInCurriculum(ctx, syllabusdb.StageInCurriculumParams{ID: *stage, CurriculumID: curriculumID})
	if err != nil {
		return err
	}
	if !okc {
		return iam.Invalid(msg)
	}
	return nil
}

func (s *Service) CreateUnit(ctx context.Context, actor *iam.Access, in UnitInput) (*Message, error) {
	if err := in.clean(); err != nil {
		return nil, err
	}
	if _, err := s.q.GetCurriculum(ctx, in.CurriculumID); isNoRows(err) {
		return nil, iam.Invalid("Giáo trình đã chọn không tồn tại.")
	} else if err != nil {
		return nil, err
	}
	if err := s.stageIn(ctx, in.StageID, in.CurriculumID, "Chặng không thuộc giáo trình đã chọn."); err != nil {
		return nil, err
	}
	if err := s.uniqueUnit(ctx, in.CurriculumID, in.UnitNumber, nil); err != nil {
		return nil, err
	}
	// Không chọn chặng → đưa vào chặng cuối của giáo trình.
	if in.StageID == nil {
		last, err := s.q.LastStageID(ctx, in.CurriculumID)
		if err != nil && !isNoRows(err) {
			return nil, err
		}
		if err == nil {
			in.StageID = &last
		}
	}
	var id uuid.UUID
	label := fmt.Sprintf("Unit %d: %s", in.UnitNumber, in.Title)
	err := s.inTx(ctx, func(tx pgx.Tx, q *syllabusdb.Queries) error {
		var err error
		id, err = q.CreateUnit(ctx, syllabusdb.CreateUnitParams{
			CurriculumID: in.CurriculumID, StageID: in.StageID, UnitNumber: in.UnitNumber, Title: in.Title, Objectives: in.Objectives,
			VocabularyFocus: in.VocabularyFocus, GrammarFocus: in.GrammarFocus, HomeworkGuide: in.HomeworkGuide,
		})
		if isUnique(err) {
			return iam.Invalid(fmt.Sprintf("Unit %d đã có trong giáo trình này.", in.UnitNumber))
		}
		if err != nil {
			return err
		}
		return record(ctx, tx, &actor.UserID, audit.EventCreated, "syllabus_unit.create", "syllabus_unit", id, label, "Thêm "+label, nil)
	})
	if err != nil {
		return nil, err
	}
	return okID("Đã lưu "+label+".", id), nil
}

func (s *Service) UpdateUnit(ctx context.Context, actor *iam.Access, id uuid.UUID, in UnitInput) (*Message, error) {
	u, err := s.q.GetUnit(ctx, id)
	if isNoRows(err) {
		return nil, ErrNotFound
	}
	if err != nil {
		return nil, err
	}
	if err := in.clean(); err != nil {
		return nil, err
	}
	if err := s.stageIn(ctx, in.StageID, u.CurriculumID, "Chặng không thuộc giáo trình này."); err != nil {
		return nil, err
	}
	if err := s.uniqueUnit(ctx, u.CurriculumID, in.UnitNumber, &id); err != nil {
		return nil, err
	}
	label := fmt.Sprintf("Unit %d: %s", in.UnitNumber, in.Title)
	err = s.inTx(ctx, func(tx pgx.Tx, q *syllabusdb.Queries) error {
		err := q.UpdateUnit(ctx, syllabusdb.UpdateUnitParams{
			ID: id, StageID: in.StageID, UnitNumber: in.UnitNumber, Title: in.Title, Objectives: in.Objectives,
			VocabularyFocus: in.VocabularyFocus, GrammarFocus: in.GrammarFocus, HomeworkGuide: in.HomeworkGuide,
		})
		if isUnique(err) {
			return iam.Invalid(fmt.Sprintf("Unit %d đã có trong giáo trình này.", in.UnitNumber))
		}
		if err != nil {
			return err
		}
		return record(ctx, tx, &actor.UserID, audit.EventUpdated, "syllabus_unit.update", "syllabus_unit", id, label, "Cập nhật "+label, nil)
	})
	if err != nil {
		return nil, err
	}
	return okID("Đã cập nhật "+label+".", id), nil
}

func (s *Service) DeleteUnit(ctx context.Context, actor *iam.Access, id uuid.UUID) (*Message, error) {
	u, err := s.q.GetUnit(ctx, id)
	if isNoRows(err) {
		return nil, ErrNotFound
	}
	if err != nil {
		return nil, err
	}
	var lessons int32
	err = s.inTx(ctx, func(tx pgx.Tx, q *syllabusdb.Queries) error {
		var err error
		if lessons, err = q.SoftDeleteUnit(ctx, id); err != nil {
			return err
		}
		return record(ctx, tx, &actor.UserID, audit.EventDeleted, "syllabus_unit.delete", "syllabus_unit", id, u.Title, "Xóa Unit "+u.Title, nil)
	})
	if err != nil {
		return nil, err
	}
	if lessons > 0 {
		return ok(fmt.Sprintf("Đã xóa Unit %s cùng %d buổi.", u.Title, lessons)), nil
	}
	return ok("Đã xóa Unit " + u.Title + "."), nil
}

// ───────────── Buổi ─────────────

type LessonInput struct {
	UnitID          *uuid.UUID `json:"unit_id"`
	SessionNo       int32      `json:"session_no"`
	Title           string     `json:"title"`
	Objectives      *string    `json:"objectives"`
	Content         *string    `json:"content"`
	VocabularyFocus *string    `json:"vocabulary_focus"`
	GrammarFocus    *string    `json:"grammar_focus"`
	HomeworkGuide   *string    `json:"homework_guide"`
}

func (in *LessonInput) clean() error {
	in.Title = strings.TrimSpace(in.Title)
	in.Objectives, in.Content, in.VocabularyFocus = optText(in.Objectives), optText(in.Content), optText(in.VocabularyFocus)
	in.GrammarFocus, in.HomeworkGuide = optText(in.GrammarFocus), optText(in.HomeworkGuide)
	switch {
	case in.SessionNo < 1 || in.SessionNo > 1000:
		return iam.Invalid("Số buổi phải từ 1 đến 1000.")
	case in.Title == "":
		return iam.Invalid("Vui lòng nhập tên buổi học.")
	case runeLen(in.Title) > 255:
		return iam.Invalid("Tên buổi học tối đa 255 ký tự.")
	}
	return nil
}

func sessionTaken(n int32) error {
	return iam.Invalid(fmt.Sprintf("Buổi %d đã có trong giáo trình này (số buổi đánh liên tục trong cả giáo trình).", n))
}

func (s *Service) uniqueSession(ctx context.Context, curriculumID uuid.UUID, n int32, exclude *uuid.UUID) error {
	taken, err := s.q.SessionNoTaken(ctx, syllabusdb.SessionNoTakenParams{CurriculumID: curriculumID, SessionNo: n, Exclude: exclude})
	if err != nil {
		return err
	}
	if taken {
		return sessionTaken(n)
	}
	return nil
}

func (s *Service) CreateLesson(ctx context.Context, actor *iam.Access, in LessonInput) (*Message, error) {
	if in.UnitID == nil {
		return nil, iam.Invalid("Vui lòng chọn unit.")
	}
	u, err := s.q.GetUnit(ctx, *in.UnitID)
	if isNoRows(err) {
		return nil, iam.Invalid("Unit đã chọn không tồn tại.")
	}
	if err != nil {
		return nil, err
	}
	if err := in.clean(); err != nil {
		return nil, err
	}
	if err := s.uniqueSession(ctx, u.CurriculumID, in.SessionNo, nil); err != nil {
		return nil, err
	}
	var id uuid.UUID
	label := fmt.Sprintf("Buổi %d: %s", in.SessionNo, in.Title)
	err = s.inTx(ctx, func(tx pgx.Tx, q *syllabusdb.Queries) error {
		var err error
		id, err = q.CreateLesson(ctx, syllabusdb.CreateLessonParams{
			CurriculumID: u.CurriculumID, UnitID: u.ID, SessionNo: in.SessionNo, Title: in.Title, Objectives: in.Objectives,
			Content: in.Content, VocabularyFocus: in.VocabularyFocus, GrammarFocus: in.GrammarFocus, HomeworkGuide: in.HomeworkGuide,
		})
		if isUnique(err) {
			return sessionTaken(in.SessionNo)
		}
		if err != nil {
			return err
		}
		return record(ctx, tx, &actor.UserID, audit.EventCreated, "syllabus_lesson.create", "syllabus_lesson", id, label, "Thêm "+label, nil)
	})
	if err != nil {
		return nil, err
	}
	return okID(fmt.Sprintf("Đã lưu %s (Unit %d).", label, u.UnitNumber), id), nil
}

func (s *Service) UpdateLesson(ctx context.Context, actor *iam.Access, id uuid.UUID, in LessonInput) (*Message, error) {
	l, err := s.q.GetLesson(ctx, id)
	if isNoRows(err) {
		return nil, ErrNotFound
	}
	if err != nil {
		return nil, err
	}
	if err := in.clean(); err != nil {
		return nil, err
	}
	if in.UnitID != nil {
		okc, err := s.q.UnitInCurriculum(ctx, syllabusdb.UnitInCurriculumParams{ID: *in.UnitID, CurriculumID: l.CurriculumID})
		if err != nil {
			return nil, err
		}
		if !okc {
			return nil, iam.Invalid("Unit không thuộc giáo trình này.")
		}
	}
	if err := s.uniqueSession(ctx, l.CurriculumID, in.SessionNo, &id); err != nil {
		return nil, err
	}
	label := fmt.Sprintf("Buổi %d: %s", in.SessionNo, in.Title)
	err = s.inTx(ctx, func(tx pgx.Tx, q *syllabusdb.Queries) error {
		err := q.UpdateLesson(ctx, syllabusdb.UpdateLessonParams{
			ID: id, UnitID: in.UnitID, SessionNo: in.SessionNo, Title: in.Title, Objectives: in.Objectives, Content: in.Content,
			VocabularyFocus: in.VocabularyFocus, GrammarFocus: in.GrammarFocus, HomeworkGuide: in.HomeworkGuide,
		})
		if isUnique(err) {
			return sessionTaken(in.SessionNo)
		}
		if err != nil {
			return err
		}
		return record(ctx, tx, &actor.UserID, audit.EventUpdated, "syllabus_lesson.update", "syllabus_lesson", id, label, "Cập nhật "+label, nil)
	})
	if err != nil {
		return nil, err
	}
	return okID("Đã cập nhật "+label+".", id), nil
}

func (s *Service) DeleteLesson(ctx context.Context, actor *iam.Access, id uuid.UUID) (*Message, error) {
	l, err := s.q.GetLesson(ctx, id)
	if isNoRows(err) {
		return nil, ErrNotFound
	}
	if err != nil {
		return nil, err
	}
	label := fmt.Sprintf("Buổi %d: %s", l.SessionNo, l.Title)
	err = s.inTx(ctx, func(tx pgx.Tx, q *syllabusdb.Queries) error {
		if err := q.SoftDeleteLesson(ctx, id); err != nil {
			return err
		}
		return record(ctx, tx, &actor.UserID, audit.EventDeleted, "syllabus_lesson.delete", "syllabus_lesson", id, label, "Xóa "+label, nil)
	})
	if err != nil {
		return nil, err
	}
	return ok("Đã xóa " + label + "."), nil
}
