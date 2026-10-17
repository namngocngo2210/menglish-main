package teacher

import (
	"context"
	"encoding/json"
	"math"
	"strconv"
	"strings"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/teacher/teacherdb"
)

// Nhập điểm mini test (TeacherPortalController::scores / scoresStore): mỗi lần một học sinh, 4 kỹ năng; điểm tổng = trung
// bình 4 kỹ năng. Bài theo Unit của giáo trình lớp (tên "Unit n: …"), lớp chưa gắn giáo trình thì nhập tên bài tự do.

// Skill: kỹ năng mini test (MiniTestScore::SKILLS).
type Skill struct {
	Key   string `json:"key"`
	Label string `json:"label"`
}

var Skills = []Skill{{"listening", "Nghe"}, {"speaking", "Nói"}, {"reading", "Đọc"}, {"writing", "Viết"}}

func fmtScore(v *float64) string {
	if v == nil {
		return "—"
	}
	return trimNumber(*v)
}

func unitTestName(u Unit) string { return "Unit " + strconv.Itoa(u.Number) + ": " + u.Title }

func parseSkills(raw []byte) map[string]*float64 {
	m := map[string]*float64{}
	if len(raw) > 0 {
		_ = json.Unmarshal(raw, &m)
	}
	return m
}

// isLow: dưới 7/10.
func isLow(score *float64, max float64) bool { return score != nil && max > 0 && *score*10 < max*7 }

type ScoreStudent struct {
	ID       uuid.UUID         `json:"id"`
	Name     string            `json:"name"`
	HasScore bool              `json:"has_score"`
	Skills   map[string]string `json:"skills"`
	Total    *string           `json:"total"`
	Low      bool              `json:"low"`
}

type ScoreCurrent struct {
	MaxScore string            `json:"max_score"`
	Skills   map[string]string `json:"skills"`
	Note     string            `json:"note"`
}

type ScoresPage struct {
	Classroom         ClassRef       `json:"classroom"`
	Skills            []Skill        `json:"skills"`
	Units             []Option       `json:"units"`
	UnitID            *uuid.UUID     `json:"unit_id"`
	TestName          *string        `json:"test_name"`
	TestDate          string         `json:"test_date"`
	SelectedStudentID *uuid.UUID     `json:"selected_student_id"`
	Current           ScoreCurrent   `json:"current"`
	Students          []ScoreStudent `json:"students"`
	ScoredCount       int            `json:"scored_count"`
}

// ScoresQuery: tham số trang nhập điểm.
type ScoresQuery struct {
	UnitID    *uuid.UUID
	Name      *string // nil = không gửi (lớp chưa có giáo trình → "Mini Test")
	TestDate  string
	StudentID *uuid.UUID
}

func (s *Service) ScoresPage(ctx context.Context, a *iam.Access, classID uuid.UUID, p ScoresQuery) (*ScoresPage, error) {
	c, err := s.classFor(ctx, a, classID)
	if err != nil {
		return nil, err
	}
	roster, err := s.q.RosterStudents(ctx, &classID)
	if err != nil {
		return nil, err
	}
	units, err := s.syl.Units(ctx, classID)
	if err != nil {
		return nil, err
	}
	out := &ScoresPage{Classroom: classRef(c), Skills: Skills, Units: []Option{}, Students: []ScoreStudent{},
		SelectedStudentID: p.StudentID, TestDate: p.TestDate}
	if _, ok := parseDay(out.TestDate); !ok {
		out.TestDate = s.today().Format(time.DateOnly)
	}
	for _, u := range units {
		out.Units = append(out.Units, Option{u.ID.String(), "Unit " + strconv.Itoa(u.Number) + ": " + u.Title})
		if p.UnitID != nil && *p.UnitID == u.ID {
			out.UnitID = ptr(u.ID)
			out.TestName = ptr(unitTestName(u))
		}
	}
	if out.TestName == nil {
		switch {
		case p.Name != nil:
			out.TestName = optText(*p.Name)
		case len(units) == 0:
			out.TestName = ptr("Mini Test")
		}
	}
	existing := map[uuid.UUID]teacherdb.ScoresByNameRow{}
	if out.TestName != nil {
		rows, err := s.q.ScoresByName(ctx, teacherdb.ScoresByNameParams{ClassID: classID, Name: *out.TestName})
		if err != nil {
			return nil, err
		}
		for _, r := range rows {
			existing[r.StudentID] = r // bài mới nhất ghi đè (như keyBy)
		}
	}
	out.ScoredCount = len(existing)
	out.Current = ScoreCurrent{MaxScore: "10", Skills: map[string]string{}}
	for _, sk := range Skills {
		out.Current.Skills[sk.Key] = ""
	}
	if p.StudentID != nil {
		if cur, ok := existing[*p.StudentID]; ok {
			if cur.MaxScore > 0 {
				out.Current.MaxScore = trimNumber(cur.MaxScore)
			}
			sk := parseSkills(cur.SkillScores)
			for _, k := range Skills {
				if v := sk[k.Key]; v != nil {
					out.Current.Skills[k.Key] = trimNumber(*v)
				}
			}
			out.Current.Note = deref(cur.Note)
		}
	}
	for _, st := range roster {
		row := ScoreStudent{ID: st.ID, Name: st.FullName, Skills: map[string]string{}}
		sc, ok := existing[st.ID]
		sk := map[string]*float64{}
		if ok {
			sk = parseSkills(sc.SkillScores)
			row.HasScore = true
			row.Total = ptr(fmtScore(sc.Score) + "/" + trimNumber(sc.MaxScore))
			row.Low = isLow(sc.Score, sc.MaxScore)
		}
		for _, k := range Skills {
			row.Skills[k.Key] = fmtScore(sk[k.Key])
		}
		out.Students = append(out.Students, row)
	}
	return out, nil
}

// ScoreInput: điểm một học sinh (Skills: chuỗi số, "" = chưa nhập).
type ScoreInput struct {
	UnitID    *uuid.UUID        `json:"unit_id"`
	Name      string            `json:"name"`
	StudentID *uuid.UUID        `json:"student_id"`
	TestDate  string            `json:"test_date"`
	MaxScore  string            `json:"max_score"`
	Skills    map[string]string `json:"skills"`
	Note      string            `json:"note"`
}

// ScoreSaved: kết quả lưu điểm (UnitID / Name để mở lại đúng bài).
type ScoreSaved struct {
	Message string     `json:"message"`
	UnitID  *uuid.UUID `json:"unit_id"`
	Name    *string    `json:"name"`
}

func parseNumber(v string) (float64, bool) {
	f, err := strconv.ParseFloat(strings.ReplaceAll(strings.TrimSpace(v), ",", "."), 64)
	return f, err == nil && !math.IsNaN(f) && !math.IsInf(f, 0)
}

func (s *Service) SaveScore(ctx context.Context, a *iam.Access, classID uuid.UUID, in ScoreInput) (*ScoreSaved, error) {
	c, err := s.classFor(ctx, a, classID)
	if err != nil {
		return nil, err
	}
	if in.StudentID == nil {
		return nil, iam.Invalid("Vui lòng chọn học sinh.")
	}
	if tooLong(strings.TrimSpace(in.Name), 255) {
		return nil, iam.Invalid("Tên bài không được vượt quá 255 ký tự.")
	}
	if tooLong(strings.TrimSpace(in.Note), 1000) {
		return nil, iam.Invalid("Ghi chú không được vượt quá 1000 ký tự.")
	}
	max := 10.0
	if strings.TrimSpace(in.MaxScore) != "" {
		v, ok := parseNumber(in.MaxScore)
		if !ok || v < 1 || v > 100 {
			return nil, iam.Invalid("Điểm tối đa phải từ 1 đến 100.")
		}
		max = v
	}
	testDate := s.today()
	if strings.TrimSpace(in.TestDate) != "" {
		d, ok := parseDay(in.TestDate)
		if !ok {
			return nil, iam.Invalid("Ngày kiểm tra không hợp lệ.")
		}
		testDate = d
	}
	skills := map[string]float64{}
	var sum float64
	for _, k := range Skills {
		raw := strings.TrimSpace(in.Skills[k.Key])
		if raw == "" {
			return nil, iam.Invalid("Cần nhập đủ điểm 4 kỹ năng.")
		}
		v, ok := parseNumber(raw)
		if !ok || v < 0 {
			return nil, iam.Invalid("Điểm kỹ năng " + k.Label + " không hợp lệ.")
		}
		skills[k.Key] = v
		sum += v
	}
	for _, k := range Skills {
		if skills[k.Key] > max {
			return nil, iam.Invalid("Điểm kỹ năng không được vượt quá điểm tối đa (" + trimNumber(max) + ").")
		}
	}
	var unit *Unit
	if in.UnitID != nil {
		units, err := s.syl.Units(ctx, classID)
		if err != nil {
			return nil, err
		}
		for i := range units {
			if units[i].ID == *in.UnitID {
				unit = &units[i]
			}
		}
		if unit == nil {
			return nil, iam.Invalid("Unit không thuộc giáo trình của lớp.")
		}
	}
	name := strings.TrimSpace(in.Name)
	if unit != nil {
		name = unitTestName(*unit)
	}
	if name == "" {
		return nil, iam.Invalid("Vui lòng chọn Unit bài học.")
	}
	on, err := s.q.OnRoster(ctx, teacherdb.OnRosterParams{ClassID: &classID, StudentID: *in.StudentID})
	if err != nil {
		return nil, err
	}
	if !on {
		return nil, iam.Invalid("Học viên không thuộc lớp này.")
	}
	score := math.Round(sum/float64(len(Skills))*100) / 100
	skillsRaw, _ := json.Marshal(skills)
	var unitID *uuid.UUID
	if unit != nil {
		unitID = &unit.ID
	}
	var studentName string
	err = s.tx(ctx, func(tx pgx.Tx, q *teacherdb.Queries) error {
		id, err := q.FindScore(ctx, teacherdb.FindScoreParams{ClassID: classID, StudentID: *in.StudentID, Name: name})
		event, action := audit.EventUpdated, "mini_test_score.update"
		switch {
		case isNoRows(err):
			id, err = q.InsertScore(ctx, teacherdb.InsertScoreParams{ClassID: classID, StudentID: *in.StudentID, TeacherID: &a.UserID,
				SyllabusUnitID: unitID, Name: name, Score: &score, MaxScore: max, SkillScores: skillsRaw, TestDate: testDate,
				Note: optText(in.Note)})
			if err != nil {
				return err
			}
			event, action = audit.EventCreated, "mini_test_score.create"
		case err != nil:
			return err
		default:
			if err := q.UpdateScore(ctx, teacherdb.UpdateScoreParams{ID: id, TeacherID: &a.UserID, SyllabusUnitID: unitID, Score: &score,
				MaxScore: max, SkillScores: skillsRaw, TestDate: testDate, Note: optText(in.Note)}); err != nil {
				return err
			}
		}
		if studentName, err = q.StudentName(ctx, *in.StudentID); err != nil {
			return err
		}
		if err := s.support.SyncMiniTest(ctx, tx, MiniTestRef{ID: id, ClassID: classID, StudentID: *in.StudentID, Name: name,
			Score: score, MaxScore: max, TestDate: testDate}); err != nil {
			return err
		}
		return audit.Record(ctx, tx, audit.Entry{ActorID: &a.UserID, Module: audit.ModuleStudents, Event: event, Action: action,
			SubjectType: "mini_test_score", SubjectID: id.String(), SubjectLabel: name + " · " + studentName, BranchID: &c.BranchID,
			Description: "Nhập điểm " + name + " lớp " + c.Name + ": " + studentName + " " + trimNumber(score) + "/" + trimNumber(max)})
	})
	if err != nil {
		return nil, err
	}
	out := &ScoreSaved{Message: "Đã lưu điểm " + name + " cho " + studentName + ".", UnitID: unitID}
	if unit == nil {
		out.Name = &name
	}
	return out, nil
}
