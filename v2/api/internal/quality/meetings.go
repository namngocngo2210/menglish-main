package quality

import (
	"context"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/quality/qualitydb"
)

// Báo cáo họp giáo viên theo tuần (TeacherMeetingReportController): Học thuật ghi nội dung họp với từng giáo viên (ghi chú
// syllabus, bảng điểm, tình hình lớp, order học thuật, đề xuất) và tình trạng xử lý. Báo cáo gắn lớp chỉ hiện khi lớp nằm
// trong phạm vi Lớp học (TeacherMeetingReport::visibleTo).

// MeetingStatuses: tình trạng xử lý (TeacherMeetingReport::STATUSES).
var MeetingStatuses = []Field{
	{"handled", "Đã xử lý"},
	{"in_progress", "Đang xử lý"},
	{"sketchy", "GV báo cáo sơ sài"},
	{"not_reported", "GV chưa báo cáo"},
	{"duplicate", "Báo cáo trùng lặp"},
}

var MeetingStatusColors = map[string]string{
	"handled": "success", "in_progress": "primary", "sketchy": "warning", "not_reported": "error", "duplicate": "secondary",
}

const meetingsPerPage = 15

var errMeetingNotFound = notFound("Không tìm thấy báo cáo họp giáo viên.")

type Meeting struct {
	ID             uuid.UUID  `json:"id"`
	WeekStart      string     `json:"week_start"`
	WeekEnd        string     `json:"week_end"`
	TeacherID      uuid.UUID  `json:"teacher_id"`
	Teacher        *string    `json:"teacher"`
	ClassID        *uuid.UUID `json:"class_id"`
	Class          *string    `json:"class"`
	Course         *string    `json:"course"`
	Author         *string    `json:"author"`
	SyllabusNote   *string    `json:"syllabus_note"`
	ScoresNote     *string    `json:"scores_note"`
	ClassNote      string     `json:"class_note"`
	AcademicOrder  *string    `json:"academic_order"`
	Recommendation *string    `json:"recommendation"`
	Status         string     `json:"status"`
	StatusLabel    string     `json:"status_label"`
	StatusColor    string     `json:"status_color"`
}

type MeetingsPage struct {
	Reports     []Meeting   `json:"reports"`
	Meta        Meta        `json:"meta"`
	Teachers    []Option    `json:"teachers"`
	Classes     []FormClass `json:"classes"`
	Statuses    []Option    `json:"statuses"`
	CanManage   bool        `json:"can_manage"`
	CurrentWeek string      `json:"current_week"`
}

type MeetingsFilter struct {
	Week      string
	TeacherID string
	Status    string
	Page      int
}

type MeetingInput struct {
	WeekStart      string  `json:"week_start"`
	TeacherID      string  `json:"teacher_id"`
	ClassID        *string `json:"class_id"`
	SyllabusNote   *string `json:"syllabus_note"`
	ScoresNote     *string `json:"scores_note"`
	ClassNote      *string `json:"class_note"`
	AcademicOrder  *string `json:"academic_order"`
	Recommendation *string `json:"recommendation"`
	Status         string  `json:"status"`
}

func (s *Service) Meetings(ctx context.Context, actor *iam.Access, f MeetingsFilter) (*MeetingsPage, error) {
	if f.Status != "" && !hasKey(MeetingStatuses, f.Status) {
		return nil, iam.Invalid("Tình trạng xử lý không hợp lệ.")
	}
	var week *time.Time
	if f.Week != "" {
		d, ok := parseDate(f.Week)
		if !ok {
			return nil, iam.Invalid("Tuần không hợp lệ.")
		}
		w := weekStart(d)
		week = &w
	}
	var status *string
	if f.Status != "" {
		status = &f.Status
	}
	teacher := parseUUID(f.TeacherID)
	sc, err := s.visible(ctx, actor)
	if err != nil {
		return nil, err
	}
	branches := meetingBranches(actor)
	total, err := s.q.CountMeetings(ctx, qualitydb.CountMeetingsParams{AllClasses: sc.all, Ids: sc.ids, Me: actor.UserID, Branches: branches,
		Week: week, TeacherID: teacher, Status: status})
	if err != nil {
		return nil, err
	}
	meta, off := pageOf(f.Page, meetingsPerPage, total)
	rows, err := s.q.ListMeetings(ctx, qualitydb.ListMeetingsParams{
		AllClasses: sc.all, Ids: sc.ids, Me: actor.UserID, Branches: branches, Week: week, TeacherID: teacher, Status: status,
		Lim: meetingsPerPage, Off: off,
	})
	if err != nil {
		return nil, err
	}
	out := &MeetingsPage{
		Reports: make([]Meeting, 0, len(rows)), Meta: meta, Statuses: optionsOf(MeetingStatuses),
		CanManage: actor.Can(PermTeacherMeeting), CurrentWeek: weekStart(s.today()).Format("2006-01-02"),
	}
	for _, r := range rows {
		m := Meeting{
			ID: r.ID, WeekStart: r.WeekStart.Format("2006-01-02"), WeekEnd: r.WeekStart.AddDate(0, 0, 6).Format("2006-01-02"),
			TeacherID: r.TeacherID, Teacher: r.TeacherName, ClassID: r.ClassID, Course: r.CourseName, Author: r.AuthorName,
			SyllabusNote: r.SyllabusNote, ScoresNote: r.ScoresNote, ClassNote: r.ClassNote, AcademicOrder: r.AcademicOrder,
			Recommendation: r.Recommendation, Status: r.Status, StatusLabel: labelOf(MeetingStatuses, r.Status),
			StatusColor: colorOr(MeetingStatusColors, r.Status),
		}
		if r.ClassCode != nil && r.ClassName != nil {
			l := classLabel(*r.ClassCode, *r.ClassName)
			m.Class = &l
		}
		out.Reports = append(out.Reports, m)
	}
	// Ô chọn giáo viên: chỉ nhân sự thuộc chi nhánh trong phạm vi Lớp học của người xem (User::scopePickableBy).
	if out.Teachers, _, err = s.usersWithPermission(ctx, "class.teach"); err != nil {
		return nil, err
	}
	pickable, err := iam.PickableUsers(ctx, s.pool, actor, "class")
	if err != nil {
		return nil, err
	}
	out.Teachers = iam.FilterUsers(pickable, out.Teachers, func(o Option) uuid.UUID { return uuid.MustParse(o.Value) })
	classes, err := s.q.ActiveClasses(ctx, qualitydb.ActiveClassesParams{AllClasses: sc.all, Ids: sc.ids})
	if err != nil {
		return nil, err
	}
	out.Classes = make([]FormClass, 0, len(classes))
	for _, c := range classes {
		out.Classes = append(out.Classes, FormClass{Value: c.ID.String(), Label: classLabel(c.Code, c.Name), Course: c.CourseName, TeacherIDs: teacherIDs(c.TeacherID, c.ForeignTeacherID)})
	}
	return out, nil
}

// validateMeeting: kiểm tra form (TeacherMeetingReportController::validated).
func (s *Service) validateMeeting(ctx context.Context, actor *iam.Access, in MeetingInput, current *qualitydb.GetMeetingRow) (qualitydb.CreateMeetingParams, *qualitydb.GetClassRow, error) {
	var p qualitydb.CreateMeetingParams
	if in.WeekStart == "" {
		return p, nil, iam.Invalid("Chọn tuần họp.")
	}
	day, ok := parseDate(in.WeekStart)
	if !ok {
		return p, nil, iam.Invalid("Tuần họp không hợp lệ.")
	}
	if in.TeacherID == "" {
		return p, nil, iam.Invalid("Chọn giáo viên.")
	}
	teacher := parseUUID(in.TeacherID)
	if teacher == nil {
		return p, nil, iam.Invalid("Người được chọn không phải giáo viên.")
	}
	classNote := text(in.ClassNote)
	if classNote == nil {
		return p, nil, iam.Invalid("Nhập ghi chú tình hình lớp / học sinh.")
	}
	if in.Status == "" {
		return p, nil, iam.Invalid("Chọn tình trạng xử lý.")
	}
	if !hasKey(MeetingStatuses, in.Status) {
		return p, nil, iam.Invalid("Tình trạng xử lý không hợp lệ.")
	}
	notes := []struct {
		v     *string
		label string
	}{
		{text(in.SyllabusNote), "Ghi chú Syllabus"}, {text(in.ScoresNote), "Ghi chú Bảng điểm"},
		{classNote, "Ghi chú tình hình lớp / học sinh"}, {text(in.AcademicOrder), "Order học thuật"},
		{text(in.Recommendation), "Nhận xét / đề xuất của Học thuật"},
	}
	for _, n := range notes {
		if tooLong(n.v, 5000) {
			return p, nil, iam.Invalid(n.label + " không được dài quá 5000 ký tự.")
		}
	}
	_, teachers, err := s.usersWithPermission(ctx, "class.teach")
	if err != nil {
		return p, nil, err
	}
	if !teachers[*teacher] {
		return p, nil, iam.Invalid("Người được chọn không phải giáo viên.")
	}
	var cls *qualitydb.GetClassRow
	if cid := text(in.ClassID); cid != nil {
		classID := parseUUID(*cid)
		if classID == nil {
			return p, nil, iam.Invalid("Lớp không hợp lệ.")
		}
		sc, err := s.visible(ctx, actor)
		if err != nil {
			return p, nil, err
		}
		c, err := s.q.GetClass(ctx, *classID)
		if err != nil && !isNoRows(err) {
			return p, nil, err
		}
		keep := current != nil && current.ClassID != nil && *current.ClassID == *classID
		if isNoRows(err) || c.Deleted || !sc.has(c.ID) || (c.Status != "active" && !keep) {
			return p, nil, iam.Invalid("Lớp không còn hoạt động hoặc nằm ngoài phạm vi bạn được xem.")
		}
		if !sameID(c.TeacherID, teacher) && !sameID(c.ForeignTeacherID, teacher) {
			return p, nil, iam.Invalid("Giáo viên đã chọn không dạy lớp này.")
		}
		cls = &c
		p.ClassID = &c.ID
	}
	p.WeekStart, p.TeacherID, p.AuthorID, p.Status = weekStart(day), *teacher, actor.UserID, in.Status
	p.SyllabusNote, p.ScoresNote, p.ClassNote, p.AcademicOrder, p.Recommendation = notes[0].v, notes[1].v, *classNote, notes[3].v, notes[4].v
	return p, cls, nil
}

func meetingLabel(teacher *string, week time.Time) string {
	return deref(teacher) + " · tuần " + week.Format("02/01/2006")
}

func (s *Service) teacherName(ctx context.Context, id uuid.UUID) *string {
	rows, err := s.q.UserNames(ctx, []uuid.UUID{id})
	if err != nil || len(rows) == 0 {
		return nil
	}
	return &rows[0].FullName
}

func (s *Service) CreateMeeting(ctx context.Context, actor *iam.Access, in MeetingInput) (string, error) {
	p, cls, err := s.validateMeeting(ctx, actor, in, nil)
	if err != nil {
		return "", err
	}
	name := s.teacherName(ctx, p.TeacherID)
	err = s.tx(ctx, func(tx pgx.Tx, q *qualitydb.Queries) error {
		id, err := q.CreateMeeting(ctx, p)
		if err != nil {
			return err
		}
		e := audit.Entry{
			ActorID: &actor.UserID, Module: audit.ModuleStudents, Event: audit.EventCreated, Action: "class_quality.teacher_meeting",
			SubjectType: "teacher_meeting_report", SubjectID: id.String(), SubjectLabel: meetingLabel(name, p.WeekStart),
			Description: "Ghi báo cáo họp giáo viên " + deref(name) + " tuần " + p.WeekStart.Format("02/01/2006"),
		}
		if cls != nil {
			e.BranchID = &cls.BranchID
		}
		return audit.Record(ctx, tx, e)
	})
	if err != nil {
		return "", err
	}
	s.log.Info("ghi báo cáo họp giáo viên", "teacher", p.TeacherID, "by", actor.UserID)
	return "Đã lưu báo cáo họp giáo viên.", nil
}

// meetingBranches: chi nhánh xét cho báo cáo không gắn lớp — chỉ mức Chi nhánh của Lớp học (TeacherMeetingReport::
// constrainToViewer); mức Của tôi chỉ thấy báo cáo mình ghi.
func meetingBranches(actor *iam.Access) []uuid.UUID {
	if actor.Level("class") == iam.ScopeBranch {
		return actor.BranchIDs()
	}
	return []uuid.UUID{}
}

// meetingFor: báo cáo (chưa xóa) người xem được thấy (TeacherMeetingReport::visibleTo): Toàn hệ thống → mọi báo cáo;
// còn lại → báo cáo gắn lớp trong phạm vi, hoặc báo cáo không gắn lớp do mình ghi / (mức Chi nhánh) về giáo viên thuộc
// chi nhánh của mình.
func (s *Service) meetingFor(ctx context.Context, actor *iam.Access, id uuid.UUID) (qualitydb.GetMeetingRow, error) {
	m, err := s.q.GetMeeting(ctx, id)
	if isNoRows(err) {
		return m, errMeetingNotFound
	}
	if err != nil {
		return m, err
	}
	sc, err := s.visible(ctx, actor)
	if err != nil {
		return m, err
	}
	if sc.all {
		return m, nil
	}
	if m.ClassID != nil {
		if !sc.has(*m.ClassID) {
			return m, errMeetingNotFound
		}
		return m, nil
	}
	if m.AuthorID == actor.UserID {
		return m, nil
	}
	if branches := meetingBranches(actor); len(branches) > 0 {
		inBranch, err := iam.StaffInBranches(ctx, s.pool, actor, "class")
		if err != nil {
			return m, err
		}
		if inBranch.Has(m.TeacherID) {
			return m, nil
		}
	}
	return m, errMeetingNotFound
}

func (s *Service) UpdateMeeting(ctx context.Context, actor *iam.Access, id uuid.UUID, in MeetingInput) (string, error) {
	cur, err := s.meetingFor(ctx, actor, id)
	if err != nil {
		return "", err
	}
	p, cls, err := s.validateMeeting(ctx, actor, in, &cur)
	if err != nil {
		return "", err
	}
	name := s.teacherName(ctx, p.TeacherID)
	before := map[string]any{"week_start": cur.WeekStart.Format("2006-01-02"), "status": labelOf(MeetingStatuses, cur.Status)}
	after := map[string]any{"week_start": p.WeekStart.Format("2006-01-02"), "status": labelOf(MeetingStatuses, p.Status)}
	err = s.tx(ctx, func(tx pgx.Tx, q *qualitydb.Queries) error {
		if err := q.UpdateMeeting(ctx, qualitydb.UpdateMeetingParams{
			ID: id, WeekStart: p.WeekStart, TeacherID: p.TeacherID, ClassID: p.ClassID, SyllabusNote: p.SyllabusNote,
			ScoresNote: p.ScoresNote, ClassNote: p.ClassNote, AcademicOrder: p.AcademicOrder, Recommendation: p.Recommendation,
			Status: p.Status,
		}); err != nil {
			return err
		}
		e := audit.Entry{
			ActorID: &actor.UserID, Module: audit.ModuleStudents, Event: audit.EventUpdated, Action: "class_quality.teacher_meeting",
			SubjectType: "teacher_meeting_report", SubjectID: id.String(), SubjectLabel: meetingLabel(name, p.WeekStart),
			Description: "Cập nhật báo cáo họp giáo viên " + deref(name), Changes: audit.Diff(before, after),
		}
		if cls != nil {
			e.BranchID = &cls.BranchID
		}
		return audit.Record(ctx, tx, e)
	})
	if err != nil {
		return "", err
	}
	s.log.Info("cập nhật báo cáo họp giáo viên", "id", id, "by", actor.UserID)
	return "Đã cập nhật báo cáo họp giáo viên.", nil
}

func (s *Service) DeleteMeeting(ctx context.Context, actor *iam.Access, id uuid.UUID) (string, error) {
	cur, err := s.meetingFor(ctx, actor, id)
	if err != nil {
		return "", err
	}
	err = s.tx(ctx, func(tx pgx.Tx, q *qualitydb.Queries) error {
		if err := q.DeleteMeeting(ctx, id); err != nil {
			return err
		}
		return audit.Record(ctx, tx, audit.Entry{
			ActorID: &actor.UserID, Module: audit.ModuleStudents, Event: audit.EventDeleted, Action: "class_quality.teacher_meeting",
			SubjectType: "teacher_meeting_report", SubjectID: id.String(), SubjectLabel: meetingLabel(cur.TeacherName, cur.WeekStart),
			Description: "Xóa báo cáo họp giáo viên " + deref(cur.TeacherName) + " tuần " + cur.WeekStart.Format("02/01/2006"),
		})
	})
	if err != nil {
		return "", err
	}
	s.log.Info("xóa báo cáo họp giáo viên", "id", id, "by", actor.UserID)
	return "Đã xóa báo cáo họp giáo viên.", nil
}
