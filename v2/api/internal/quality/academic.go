package quality

import (
	"context"
	"math"
	"strings"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/quality/qualitydb"
)

// Đánh giá dự giờ học thuật (ObservationController::academic / saveAcademic): mỗi lớp có học trong tháng một đánh giá —
// % chuyên cần (gợi ý từ điểm danh của lớp trong tháng), % đạt yêu cầu, đã dự giờ hay chưa, 6 tiêu chí nhận xét.

// Criteria: 6 tiêu chí đánh giá theo thứ tự mockup (AcademicObservation::CRITERIA).
var Criteria = []Field{
	{"teaching_quality", "Chất lượng giảng dạy"},
	{"lesson_content", "Nội dung bài học"},
	{"interaction", "Kỹ năng tương tác"},
	{"attitude", "Thái độ"},
	{"effectiveness", "Hiệu quả lớp học"},
	{"overall", "Kết quả tổng kết"},
}

const academicPerPage = 20

type Evaluation struct {
	Observed       bool               `json:"observed"`
	ObservedOn     *string            `json:"observed_on"`
	ObserverID     *uuid.UUID         `json:"observer_id"`
	Observer       *string            `json:"observer"`
	AttendanceRate *float64           `json:"attendance_rate"`
	PassRate       *float64           `json:"pass_rate"`
	ActionNotes    *string            `json:"action_notes"`
	Criteria       map[string]*string `json:"criteria"`
}

type AcademicClass struct {
	ID                  uuid.UUID   `json:"id"`
	Code                string      `json:"code"`
	Name                string      `json:"name"`
	Teacher             *string     `json:"teacher"`
	Course              *string     `json:"course"`
	SuggestedAttendance *float64    `json:"suggested_attendance"`
	Evaluation          *Evaluation `json:"evaluation"`
}

type AcademicStats struct {
	Total    int64 `json:"total"`
	Observed int64 `json:"observed"`
}

type AcademicPage struct {
	Month         string          `json:"month"`
	Months        []Option        `json:"months"`
	Classes       []AcademicClass `json:"classes"`
	Meta          Meta            `json:"meta"`
	Stats         AcademicStats   `json:"stats"`
	Criteria      []Field         `json:"criteria"`
	Observers     []Option        `json:"observers"`
	CanRecord     bool            `json:"can_record"`
	CurrentUserID uuid.UUID       `json:"current_user_id"`
}

type AcademicFilter struct {
	Month  string
	Status string // observed | pending
	Search string
	Page   int
}

type AcademicInput struct {
	ClassID         string   `json:"class_id"`
	Month           string   `json:"month"`
	AttendanceRate  *float64 `json:"attendance_rate"`
	PassRate        *float64 `json:"pass_rate"`
	Observed        bool     `json:"observed"`
	ObservedOn      *string  `json:"observed_on"`
	ObserverID      *string  `json:"observer_id"`
	ActionNotes     *string  `json:"action_notes"`
	TeachingQuality *string  `json:"teaching_quality"`
	LessonContent   *string  `json:"lesson_content"`
	Interaction     *string  `json:"interaction"`
	Attitude        *string  `json:"attitude"`
	Effectiveness   *string  `json:"effectiveness"`
	Overall         *string  `json:"overall"`
}

func round1(v float64) float64 { return math.Round(v*10) / 10 }

// attendanceRates: % chuyên cần theo lớp trong khoảng ngày: (có mặt + đi muộn) / tổng lượt điểm danh.
func (s *Service) attendanceRates(ctx context.Context, ids []uuid.UUID, month string) (map[uuid.UUID]float64, error) {
	out := map[uuid.UUID]float64{}
	if len(ids) == 0 {
		return out, nil
	}
	from, to := monthRange(month)
	rows, err := s.q.AttendanceRates(ctx, qualitydb.AttendanceRatesParams{ClassIds: ids, FromDate: from, ToDate: to})
	if err != nil {
		return nil, err
	}
	for _, r := range rows {
		if r.Total > 0 {
			out[r.ClassID] = round1(float64(r.Attended) * 100 / float64(r.Total))
		}
	}
	return out, nil
}

func (s *Service) Academic(ctx context.Context, actor *iam.Access, f AcademicFilter) (*AcademicPage, error) {
	now := s.now()
	month := pickMonth(f.Month, now)
	from, to := monthRange(month)
	sc, err := s.visible(ctx, actor)
	if err != nil {
		return nil, err
	}
	var observed *bool
	switch f.Status {
	case "observed":
		v := true
		observed = &v
	case "pending":
		v := false
		observed = &v
	}
	search := likeArg(f.Search)
	total, err := s.q.CountRunningClasses(ctx, qualitydb.CountRunningClassesParams{
		AllClasses: sc.all, Ids: sc.ids, FromDate: from, ToDate: to, Search: search, Observed: observed, Month: month,
	})
	if err != nil {
		return nil, err
	}
	meta, off := pageOf(f.Page, academicPerPage, total)
	rows, err := s.q.RunningClasses(ctx, qualitydb.RunningClassesParams{
		AllClasses: sc.all, Ids: sc.ids, FromDate: from, ToDate: to, Search: search, Observed: observed, Month: month,
		Lim: academicPerPage, Off: off,
	})
	if err != nil {
		return nil, err
	}
	ids := make([]uuid.UUID, 0, len(rows))
	for _, r := range rows {
		ids = append(ids, r.ID)
	}
	evals, err := s.q.AcademicEvaluations(ctx, qualitydb.AcademicEvaluationsParams{Month: month, ClassIds: ids})
	if err != nil {
		return nil, err
	}
	byClass := map[uuid.UUID]qualitydb.AcademicEvaluationsRow{}
	for _, e := range evals {
		byClass[e.ClassID] = e
	}
	rates, err := s.attendanceRates(ctx, ids, month)
	if err != nil {
		return nil, err
	}
	out := &AcademicPage{
		Month: month, Months: monthOptions(now, 6, 0), Classes: make([]AcademicClass, 0, len(rows)), Meta: meta,
		Criteria: Criteria, CanRecord: actor.Can(PermObserveAcademic), CurrentUserID: actor.UserID,
	}
	for _, r := range rows {
		c := AcademicClass{ID: r.ID, Code: r.Code, Name: r.Name, Teacher: r.TeacherName, Course: r.CourseName}
		if v, ok := rates[r.ID]; ok {
			c.SuggestedAttendance = &v
		}
		if e, ok := byClass[r.ID]; ok {
			c.Evaluation = &Evaluation{
				Observed: e.Observed, ObservedOn: ymd(e.ObservedOn), ObserverID: e.ObserverID, Observer: e.ObserverName,
				AttendanceRate: e.AttendanceRate, PassRate: e.PassRate, ActionNotes: e.ActionNotes,
				Criteria: map[string]*string{
					"teaching_quality": e.TeachingQuality, "lesson_content": e.LessonContent, "interaction": e.Interaction,
					"attitude": e.Attitude, "effectiveness": e.Effectiveness, "overall": e.Overall,
				},
			}
		}
		out.Classes = append(out.Classes, c)
	}
	if out.Stats.Total, err = s.q.CountRunningClasses(ctx, qualitydb.CountRunningClassesParams{
		AllClasses: sc.all, Ids: sc.ids, FromDate: from, ToDate: to, Month: month,
	}); err != nil {
		return nil, err
	}
	if out.Stats.Observed, err = s.q.CountObservedAcademic(ctx, qualitydb.CountObservedAcademicParams{
		Month: month, AllClasses: sc.all, Ids: sc.ids, FromDate: from, ToDate: to,
	}); err != nil {
		return nil, err
	}
	if out.Observers, _, err = s.usersWithPermission(ctx, PermObserveAcademic); err != nil {
		return nil, err
	}
	return out, nil
}

// SaveAcademic: lưu đánh giá của lớp trong tháng (một bản ghi / lớp / tháng). Chưa dự giờ: chỉ lưu tỉ lệ, xóa phần
// đánh giá chi tiết.
func (s *Service) SaveAcademic(ctx context.Context, actor *iam.Access, in AcademicInput) (string, error) {
	if strings.TrimSpace(in.ClassID) == "" {
		return "", iam.Invalid("Bắt buộc chọn lớp.")
	}
	classID := parseUUID(in.ClassID)
	if classID == nil {
		return "", iam.Invalid("Lớp không hợp lệ.")
	}
	if !validMonth(in.Month) {
		return "", iam.Invalid("Tháng đánh giá không hợp lệ.")
	}
	if in.AttendanceRate != nil && (*in.AttendanceRate < 0 || *in.AttendanceRate > 100) {
		return "", iam.Invalid("Tỉ lệ chuyên cần từ 0 đến 100%.")
	}
	if in.PassRate != nil && (*in.PassRate < 0 || *in.PassRate > 100) {
		return "", iam.Invalid("Tỉ lệ đạt yêu cầu từ 0 đến 100%.")
	}
	p := qualitydb.UpsertAcademicEvaluationParams{
		ClassID: *classID, Month: &in.Month, Observed: in.Observed, AttendanceRate: in.AttendanceRate, PassRate: in.PassRate,
		UpdatedBy: &actor.UserID,
	}
	if in.Observed {
		obsOn := text(in.ObservedOn)
		if obsOn == nil {
			return "", iam.Invalid("Chọn ngày dự giờ.")
		}
		day, ok := parseDate(*obsOn)
		if !ok {
			return "", iam.Invalid("Ngày dự giờ không hợp lệ.")
		}
		if day.After(s.today()) {
			return "", iam.Invalid("Ngày dự giờ không được ở tương lai.")
		}
		obsBy := text(in.ObserverID)
		if obsBy == nil {
			return "", iam.Invalid("Chọn người dự giờ.")
		}
		observer := parseUUID(*obsBy)
		if observer == nil {
			return "", iam.Invalid("Người dự giờ không hợp lệ.")
		}
		exists, err := s.q.UserExists(ctx, *observer)
		if err != nil {
			return "", err
		}
		if !exists {
			return "", iam.Invalid("Người dự giờ không hợp lệ.")
		}
		texts := []*string{text(in.TeachingQuality), text(in.LessonContent), text(in.Interaction), text(in.Attitude), text(in.Effectiveness), text(in.Overall)}
		for i, t := range texts {
			if tooLong(t, 5000) {
				return "", iam.Invalid(Criteria[i].Label + " không được dài quá 5000 ký tự.")
			}
		}
		notes := text(in.ActionNotes)
		if tooLong(notes, 5000) {
			return "", iam.Invalid("Ghi chú / Hành động không được dài quá 5000 ký tự.")
		}
		p.ObservedOn, p.ObserverID, p.ActionNotes = &day, observer, notes
		p.TeachingQuality, p.LessonContent, p.Interaction, p.Attitude, p.Effectiveness, p.Overall = texts[0], texts[1], texts[2], texts[3], texts[4], texts[5]
	}

	from, to := monthRange(in.Month)
	sc, err := s.visible(ctx, actor)
	if err != nil {
		return "", err
	}
	running, err := s.q.RunningClassIDs(ctx, qualitydb.RunningClassIDsParams{IdsIn: []uuid.UUID{*classID}, AllClasses: sc.all, Ids: sc.ids, FromDate: from, ToDate: to})
	if err != nil {
		return "", err
	}
	if len(running) == 0 {
		return "", iam.Invalid("Lớp không học trong tháng đã chọn hoặc nằm ngoài phạm vi bạn được xem.")
	}
	if in.Observed && !strings.HasPrefix(p.ObservedOn.Format("2006-01-02"), in.Month) {
		return "", iam.Invalid("Ngày dự giờ phải nằm trong tháng đánh giá.")
	}
	cls, err := s.q.GetClass(ctx, *classID)
	if err != nil {
		return "", err
	}
	err = s.tx(ctx, func(tx pgx.Tx, q *qualitydb.Queries) error {
		row, err := q.UpsertAcademicEvaluation(ctx, p)
		if err != nil {
			return err
		}
		event := audit.EventUpdated
		if row.Inserted {
			event = audit.EventCreated
		}
		desc := "Lưu đánh giá dự giờ học thuật lớp " + cls.Code + " " + monthLabel(in.Month)
		if !in.Observed {
			desc += " (chưa dự giờ)"
		}
		return audit.Record(ctx, tx, audit.Entry{
			ActorID: &actor.UserID, Module: audit.ModuleStudents, Event: event, Action: "class_quality.observe_academic",
			SubjectType: "academic_observation", SubjectID: row.ID.String(), SubjectLabel: cls.Code + " · " + in.Month,
			BranchID: &cls.BranchID, Description: desc,
		})
	})
	if err != nil {
		return "", err
	}
	s.log.Info("lưu đánh giá dự giờ học thuật", "class", cls.ID, "month", in.Month, "by", actor.UserID)
	return "Đã lưu đánh giá dự giờ lớp " + cls.Code + " " + monthLabel(in.Month) + ".", nil
}
