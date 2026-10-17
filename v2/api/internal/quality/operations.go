package quality

import (
	"context"
	"fmt"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/quality/qualitydb"
)

// Dự giờ vận hành (ObservationController::operations / storeOperations / updateOperations / destroyOperations): mỗi lượt
// = một buổi quan sát một giáo viên ở một lớp đang học, xếp loại 4 mức.

// Ratings: xếp loại (QaObservation::RATINGS).
var Ratings = []Field{
	{"excellent", "Xuất sắc"},
	{"good", "Tốt"},
	{"pass", "Đạt"},
	{"needs_improvement", "Cần cải thiện"},
}

// RatingColors: màu badge theo xếp loại.
var RatingColors = map[string]string{"excellent": "success", "good": "primary", "pass": "secondary", "needs_improvement": "warning"}

// NoteFields: các mục nội dung quan sát (tùy chọn) theo thứ tự mockup.
var NoteFields = []Field{
	{"lesson_content", "Nội dung bài học"},
	{"attitude", "Tác phong, thái độ"},
	{"preparation", "Chuẩn bị bài giảng"},
	{"technique", "Kỹ thuật giảng dạy (tương tác)"},
	{"suggestions", "Góp ý / Cải thiện"},
}

const operationsPerPage = 15

var errOperationNotFound = notFound("Không tìm thấy lượt dự giờ.")

type Operation struct {
	ID          uuid.UUID          `json:"id"`
	ObservedOn  string             `json:"observed_on"`
	TeacherID   *uuid.UUID         `json:"teacher_id"`
	Teacher     *string            `json:"teacher"`
	TeacherCode string             `json:"teacher_code"`
	ClassID     uuid.UUID          `json:"class_id"`
	Class       string             `json:"class"`
	Branch      *string            `json:"branch"`
	Observer    *string            `json:"observer"`
	Rating      string             `json:"rating"`
	RatingLabel string             `json:"rating_label"`
	RatingColor string             `json:"rating_color"`
	Notes       map[string]*string `json:"notes"`
}

// FormClass: lớp đang hoạt động cho ô chọn (lọc theo giáo viên: GV chính hoặc GVNN của lớp).
type FormClass struct {
	Value      string   `json:"value"`
	Label      string   `json:"label"`
	Course     *string  `json:"course,omitempty"`
	TeacherIDs []string `json:"teacher_ids"`
}

type OperationsPage struct {
	Observations   []Operation `json:"observations"`
	Meta           Meta        `json:"meta"`
	Classes        []FormClass `json:"classes"`
	Teachers       []Option    `json:"teachers"`
	FilterTeachers []Option    `json:"filter_teachers"`
	Ratings        []Option    `json:"ratings"`
	NoteFields     []Field     `json:"note_fields"`
	CanRecord      bool        `json:"can_record"`
	Today          string      `json:"today"`
}

type OperationsFilter struct {
	TeacherID string
	Rating    string
	Page      int
}

type OperationInput struct {
	ObservedOn    string  `json:"observed_on"`
	TeacherID     string  `json:"teacher_id"`
	ClassID       string  `json:"class_id"`
	Rating        string  `json:"rating"`
	LessonContent *string `json:"lesson_content"`
	Attitude      *string `json:"attitude"`
	Preparation   *string `json:"preparation"`
	Technique     *string `json:"technique"`
	Suggestions   *string `json:"suggestions"`
}

func classLabel(code, name string) string { return code + " · " + name }

func teacherIDs(ids ...*uuid.UUID) []string {
	out := []string{}
	for _, id := range ids {
		if id != nil {
			out = append(out, id.String())
		}
	}
	return out
}

// Operations: danh sách lượt dự giờ (mới nhất trước) và dữ liệu form.
func (s *Service) Operations(ctx context.Context, actor *iam.Access, f OperationsFilter) (*OperationsPage, error) {
	if f.Rating != "" && !hasKey(Ratings, f.Rating) {
		return nil, iam.Invalid("Xếp loại không hợp lệ.")
	}
	sc, err := s.visible(ctx, actor)
	if err != nil {
		return nil, err
	}
	var rating *string
	if f.Rating != "" {
		rating = &f.Rating
	}
	teacher := parseUUID(f.TeacherID)
	total, err := s.q.CountOperations(ctx, qualitydb.CountOperationsParams{AllClasses: sc.all, Ids: sc.ids, TeacherID: teacher, Rating: rating})
	if err != nil {
		return nil, err
	}
	meta, off := pageOf(f.Page, operationsPerPage, total)
	rows, err := s.q.ListOperations(ctx, qualitydb.ListOperationsParams{
		AllClasses: sc.all, Ids: sc.ids, TeacherID: teacher, Rating: rating, Lim: operationsPerPage, Off: off,
	})
	if err != nil {
		return nil, err
	}
	out := &OperationsPage{
		Observations: make([]Operation, 0, len(rows)), Meta: meta, Ratings: optionsOf(Ratings), NoteFields: NoteFields,
		CanRecord: actor.Can(PermObserveOperations), Today: s.today().Format("2006-01-02"),
	}
	for _, r := range rows {
		rt := deref(r.Rating)
		out.Observations = append(out.Observations, Operation{
			ID: r.ID, ObservedOn: deref(ymd(r.ObservedOn)), TeacherID: r.TeacherID, Teacher: r.TeacherName, TeacherCode: r.TeacherCode,
			ClassID: r.ClassID, Class: classLabel(r.ClassCode, r.ClassName), Branch: r.BranchName, Observer: r.ObserverName,
			Rating: rt, RatingLabel: labelOf(Ratings, rt), RatingColor: colorOr(RatingColors, rt),
			Notes: map[string]*string{
				"lesson_content": r.LessonContent, "attitude": r.Attitude, "preparation": r.Preparation,
				"technique": r.Technique, "suggestions": r.Suggestions,
			},
		})
	}

	// Form: chỉ giáo viên đang có lớp hoạt động; lớp lọc theo giáo viên đã chọn (GV chính hoặc GVNN của lớp).
	classes, err := s.q.ActiveClasses(ctx, qualitydb.ActiveClassesParams{AllClasses: sc.all, Ids: sc.ids})
	if err != nil {
		return nil, err
	}
	count := map[uuid.UUID]int{}
	var teachers []uuid.UUID
	out.Classes = make([]FormClass, 0, len(classes))
	for _, c := range classes {
		out.Classes = append(out.Classes, FormClass{Value: c.ID.String(), Label: classLabel(c.Code, c.Name), TeacherIDs: teacherIDs(c.TeacherID, c.ForeignTeacherID)})
		for _, id := range []*uuid.UUID{c.TeacherID, c.ForeignTeacherID} {
			if id == nil {
				continue
			}
			if count[*id] == 0 {
				teachers = append(teachers, *id)
			}
			count[*id]++
		}
	}
	names, err := s.q.UserNames(ctx, nonNil(teachers))
	if err != nil {
		return nil, err
	}
	sortByName(names)
	out.Teachers = make([]Option, 0, len(names))
	for _, u := range names {
		out.Teachers = append(out.Teachers, Option{Value: u.ID.String(), Label: fmt.Sprintf("%s (%d lớp đang dạy)", u.FullName, count[u.ID])})
	}
	observed, err := s.q.ObservedTeachers(ctx)
	if err != nil {
		return nil, err
	}
	out.FilterTeachers = make([]Option, 0, len(observed))
	for _, u := range observed {
		out.FilterTeachers = append(out.FilterTeachers, Option{Value: u.ID.String(), Label: u.FullName})
	}
	return out, nil
}

// validateOperation: kiểm tra form như validatedOperations (thông báo tiếng Việt của Laravel).
func (s *Service) validateOperation(ctx context.Context, actor *iam.Access, in OperationInput, current *qualitydb.GetOperationRow) (qualitydb.CreateOperationParams, qualitydb.GetClassRow, error) {
	var p qualitydb.CreateOperationParams
	var cls qualitydb.GetClassRow
	if in.ObservedOn == "" {
		return p, cls, iam.Invalid("Bắt buộc chọn ngày dự giờ.")
	}
	day, ok := parseDate(in.ObservedOn)
	if !ok {
		return p, cls, iam.Invalid("Ngày dự giờ không hợp lệ.")
	}
	if day.After(s.today()) {
		return p, cls, iam.Invalid("Ngày dự giờ không được ở tương lai.")
	}
	if in.TeacherID == "" {
		return p, cls, iam.Invalid("Bắt buộc chọn giáo viên.")
	}
	teacher := parseUUID(in.TeacherID)
	if teacher == nil {
		return p, cls, iam.Invalid("Giáo viên không hợp lệ.")
	}
	if in.ClassID == "" {
		return p, cls, iam.Invalid("Bắt buộc chọn lớp.")
	}
	classID := parseUUID(in.ClassID)
	if classID == nil {
		return p, cls, iam.Invalid("Lớp không hợp lệ.")
	}
	if in.Rating == "" {
		return p, cls, iam.Invalid("Bắt buộc đánh giá xếp loại.")
	}
	if !hasKey(Ratings, in.Rating) {
		return p, cls, iam.Invalid("Xếp loại không hợp lệ.")
	}
	notes := []*string{text(in.LessonContent), text(in.Attitude), text(in.Preparation), text(in.Technique), text(in.Suggestions)}
	for i, n := range notes {
		if tooLong(n, 5000) {
			return p, cls, iam.Invalid(NoteFields[i].Label + " không được dài quá 5000 ký tự.")
		}
	}

	// Lớp đang hoạt động trong phạm vi; khi sửa vẫn giữ được lớp cũ (có thể đã kết thúc).
	sc, err := s.visible(ctx, actor)
	if err != nil {
		return p, cls, err
	}
	cls, err = s.q.GetClass(ctx, *classID)
	if err != nil && !isNoRows(err) {
		return p, cls, err
	}
	keep := current != nil && current.ClassID == *classID
	if isNoRows(err) || cls.Deleted || !sc.has(cls.ID) || (cls.Status != "active" && !keep) {
		return p, cls, iam.Invalid("Lớp không còn hoạt động hoặc nằm ngoài phạm vi bạn được xem.")
	}
	if !sameID(cls.TeacherID, teacher) && !sameID(cls.ForeignTeacherID, teacher) {
		return p, cls, iam.Invalid("Giáo viên đã chọn không dạy lớp này.")
	}
	rating := in.Rating
	p = qualitydb.CreateOperationParams{
		ClassID: cls.ID, TeacherID: teacher, ObserverID: &actor.UserID, ObservedOn: &day, Rating: &rating,
		LessonContent: notes[0], Attitude: notes[1], Preparation: notes[2], Technique: notes[3], Suggestions: notes[4],
	}
	return p, cls, nil
}

func sameID(a, b *uuid.UUID) bool { return a != nil && b != nil && *a == *b }

func (s *Service) CreateOperation(ctx context.Context, actor *iam.Access, in OperationInput) (string, error) {
	p, cls, err := s.validateOperation(ctx, actor, in, nil)
	if err != nil {
		return "", err
	}
	err = s.tx(ctx, func(tx pgx.Tx, q *qualitydb.Queries) error {
		id, err := q.CreateOperation(ctx, p)
		if err != nil {
			return err
		}
		return audit.Record(ctx, tx, audit.Entry{
			ActorID: &actor.UserID, Module: audit.ModuleStudents, Event: audit.EventCreated, Action: "class_quality.observe_operations",
			SubjectType: "qa_observation", SubjectID: id.String(), SubjectLabel: cls.Code + " · " + p.ObservedOn.Format("02/01/2006"),
			BranchID: &cls.BranchID, Description: "Ghi nhận lượt dự giờ vận hành lớp " + cls.Code,
		})
	})
	if err != nil {
		return "", err
	}
	s.log.Info("ghi nhận dự giờ vận hành", "class", cls.ID, "by", actor.UserID)
	return "Đã ghi nhận lượt dự giờ.", nil
}

// operationFor: lượt dự giờ (chưa xóa) của lớp trong phạm vi.
func (s *Service) operationFor(ctx context.Context, actor *iam.Access, id uuid.UUID) (qualitydb.GetOperationRow, error) {
	o, err := s.q.GetOperation(ctx, id)
	if isNoRows(err) {
		return o, errOperationNotFound
	}
	if err != nil {
		return o, err
	}
	sc, err := s.visible(ctx, actor)
	if err != nil {
		return o, err
	}
	if !sc.has(o.ClassID) {
		return o, errOperationNotFound
	}
	return o, nil
}

func (s *Service) UpdateOperation(ctx context.Context, actor *iam.Access, id uuid.UUID, in OperationInput) (string, error) {
	cur, err := s.operationFor(ctx, actor, id)
	if err != nil {
		return "", err
	}
	p, cls, err := s.validateOperation(ctx, actor, in, &cur)
	if err != nil {
		return "", err
	}
	before := map[string]any{"observed_on": deref(ymd(cur.ObservedOn)), "rating": deref(cur.Rating), "class": cur.ClassCode}
	after := map[string]any{"observed_on": p.ObservedOn.Format("2006-01-02"), "rating": deref(p.Rating), "class": cls.Code}
	err = s.tx(ctx, func(tx pgx.Tx, q *qualitydb.Queries) error {
		if err := q.UpdateOperation(ctx, qualitydb.UpdateOperationParams{
			ID: id, ClassID: p.ClassID, TeacherID: p.TeacherID, ObservedOn: p.ObservedOn, Rating: p.Rating,
			LessonContent: p.LessonContent, Attitude: p.Attitude, Preparation: p.Preparation, Technique: p.Technique,
			Suggestions: p.Suggestions, UpdatedBy: &actor.UserID,
		}); err != nil {
			return err
		}
		return audit.Record(ctx, tx, audit.Entry{
			ActorID: &actor.UserID, Module: audit.ModuleStudents, Event: audit.EventUpdated, Action: "class_quality.observe_operations",
			SubjectType: "qa_observation", SubjectID: id.String(), SubjectLabel: cls.Code + " · " + p.ObservedOn.Format("02/01/2006"),
			BranchID: &cls.BranchID, Description: "Cập nhật lượt dự giờ vận hành lớp " + cls.Code, Changes: audit.Diff(before, after),
		})
	})
	if err != nil {
		return "", err
	}
	s.log.Info("cập nhật dự giờ vận hành", "id", id, "by", actor.UserID)
	return "Đã cập nhật lượt dự giờ.", nil
}

func (s *Service) DeleteOperation(ctx context.Context, actor *iam.Access, id uuid.UUID) (string, error) {
	cur, err := s.operationFor(ctx, actor, id)
	if err != nil {
		return "", err
	}
	err = s.tx(ctx, func(tx pgx.Tx, q *qualitydb.Queries) error {
		if err := q.DeleteOperation(ctx, id); err != nil {
			return err
		}
		return audit.Record(ctx, tx, audit.Entry{
			ActorID: &actor.UserID, Module: audit.ModuleStudents, Event: audit.EventDeleted, Action: "class_quality.observe_operations",
			SubjectType: "qa_observation", SubjectID: id.String(), SubjectLabel: cur.ClassCode + " · " + deref(ymd(cur.ObservedOn)),
			Description: "Xóa lượt dự giờ vận hành lớp " + cur.ClassCode + " (GV " + deref(cur.TeacherName) + ")",
		})
	})
	if err != nil {
		return "", err
	}
	s.log.Info("xóa dự giờ vận hành", "id", id, "by", actor.UserID)
	return "Đã xóa lượt dự giờ.", nil
}

func deref[T any](p *T) T {
	var zero T
	if p == nil {
		return zero
	}
	return *p
}

func colorOr(m map[string]string, key string) string {
	if c, ok := m[key]; ok {
		return c
	}
	return "neutral"
}

func nonNil[T any](v []T) []T {
	if v == nil {
		return []T{}
	}
	return v
}
