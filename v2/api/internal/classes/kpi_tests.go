package classes

import (
	"context"
	"math"
	"strconv"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/classes/classesdb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/kpi/kpisrc"
)

// Nguồn KPI tự động từ điểm test của học sinh (KpiAutoCounter::testResult / testProgress / studentPassRate): Big Test của
// phân hệ Giáo trình (đã duyệt / đã gửi, không vắng) và mini test của Cổng giáo viên, quy về thang 10.

// PassScore: điểm test đạt chuẩn (thang 10) — KpiAutoCounter::PASS_SCORE.
const PassScore = 7.0

// PassRateMonths: đầu ra học sinh tính cuốn chiếu chừng này tháng (gồm tháng chấm) — KpiAutoCounter::PASS_RATE_MONTHS.
const PassRateMonths = 3

// KpiTestSources: test_result, test_progress (lớp giáo viên giữ trong kỳ như class_attendance_rate) và student_pass_rate
// (lớp trong phạm vi Lớp học của người được chấm; access = iam.Service.LoadAccess).
func (s *Service) KpiTestSources(access AccessLoader) kpisrc.Sources {
	return kpisrc.Sources{
		"test_result":   s.kpiTestResult,
		"test_progress": s.kpiTestProgress,
		"student_pass_rate": func(ctx context.Context, userID uuid.UUID, _, to time.Time) (kpisrc.Result, error) {
			return s.kpiPassRate(ctx, access, userID, to)
		},
	}
}

// testClass: điểm của một lớp theo thứ tự ngày.
type testClass struct {
	label  string
	scores []classesdb.KpiTestScoresRow
}

func (s *Service) testScores(ctx context.Context, ids []uuid.UUID, from, to time.Time) ([]testClass, int, error) {
	if len(ids) == 0 {
		return nil, 0, nil
	}
	rows, err := s.q.KpiTestScores(ctx, classesdb.KpiTestScoresParams{ClassIds: ids, FromAt: from, ToAt: to})
	if err != nil {
		return nil, 0, err
	}
	var out []testClass
	for _, r := range rows {
		if len(out) == 0 || out[len(out)-1].scores[0].ClassID != r.ClassID {
			label := r.Code
			if label == "" {
				label = r.Name
			}
			out = append(out, testClass{label: "Lớp " + label})
		}
		out[len(out)-1].scores = append(out[len(out)-1].scores, r)
	}
	return out, len(rows), nil
}

func (s *Service) teacherClassIDs(ctx context.Context, userID uuid.UUID, from, to time.Time) ([]uuid.UUID, error) {
	rows, err := s.q.KpiTeacherAttendance(ctx, classesdb.KpiTeacherAttendanceParams{FromDate: from, ToDate: to, UserID: &userID})
	if err != nil {
		return nil, err
	}
	ids := make([]uuid.UUID, 0, len(rows))
	for _, r := range rows {
		ids = append(ids, r.ID)
	}
	return ids, nil
}

func avgScore(rows []classesdb.KpiTestScoresRow) float64 {
	sum := 0.0
	for _, r := range rows {
		sum += r.Score
	}
	return sum / float64(len(rows))
}

func passed(rows []classesdb.KpiTestScoresRow) int {
	n := 0
	for _, r := range rows {
		if r.Score >= PassScore {
			n++
		}
	}
	return n
}

func round1(v float64) float64 { return math.Round(v*10) / 10 }

// kpiTestResult: điểm TB (thang 10) ÷ 10 × 50% + tỷ lệ bài đạt chuẩn (≥ 7) × 50%.
func (s *Service) kpiTestResult(ctx context.Context, userID uuid.UUID, from, to time.Time) (kpisrc.Result, error) {
	ids, err := s.teacherClassIDs(ctx, userID, from, to)
	if err != nil {
		return kpisrc.Result{}, err
	}
	classes, total, err := s.testScores(ctx, ids, from, to)
	if err != nil || total == 0 {
		return kpisrc.Result{}, err
	}
	var all []classesdb.KpiTestScoresRow
	recs := make([]kpisrc.Record, 0, len(classes))
	for _, c := range classes {
		all = append(all, c.scores...)
		recs = append(recs, kpisrc.Record{Text: c.label + ": " + strconv.Itoa(len(c.scores)) + " bài, điểm TB " +
			kpisrc.Num(avgScore(c.scores), 1) + ", đạt chuẩn " + kpisrc.Pct(float64(passed(c.scores))/float64(len(c.scores))*100)})
	}
	v := round1(avgScore(all)/10*50 + float64(passed(all))/float64(len(all))*50)
	return kpisrc.Result{Value: kpisrc.F(v), Records: recs}, nil
}

// kpiTestProgress: % điểm test tăng — mỗi lớp so điểm TB ngày test cuối với ngày test đầu trong kỳ; trung bình các lớp có
// ≥ 2 ngày test.
func (s *Service) kpiTestProgress(ctx context.Context, userID uuid.UUID, from, to time.Time) (kpisrc.Result, error) {
	ids, err := s.teacherClassIDs(ctx, userID, from, to)
	if err != nil {
		return kpisrc.Result{}, err
	}
	classes, _, err := s.testScores(ctx, ids, from, to)
	if err != nil {
		return kpisrc.Result{}, err
	}
	var recs []kpisrc.Record
	sum := 0.0
	for _, c := range classes {
		var days [][]classesdb.KpiTestScoresRow
		for _, r := range c.scores {
			if len(days) == 0 || !days[len(days)-1][0].Day.Equal(r.Day) {
				days = append(days, nil)
			}
			days[len(days)-1] = append(days[len(days)-1], r)
		}
		if len(days) < 2 {
			continue
		}
		first, last := avgScore(days[0]), avgScore(days[len(days)-1])
		if first <= 0 {
			continue
		}
		growth := (last - first) / first * 100
		sign := ""
		if growth >= 0 {
			sign = "+"
		}
		sum += growth
		recs = append(recs, kpisrc.Record{Text: c.label + ": " + kpisrc.Num(first, 1) + " → " + kpisrc.Num(last, 1) +
			" (" + sign + kpisrc.Pct(growth) + ")"})
	}
	if len(recs) == 0 {
		return kpisrc.Result{}, nil
	}
	return kpisrc.Result{Value: kpisrc.F(round1(sum / float64(len(recs)))), Records: recs}, nil
}

// kpiPassRate: % bài test đạt chuẩn ≥ 7 của các lớp trong phạm vi Lớp học của người này, từ đầu tháng (tháng chấm − 2).
func (s *Service) kpiPassRate(ctx context.Context, access AccessLoader, userID uuid.UUID, to time.Time) (kpisrc.Result, error) {
	from := time.Date(to.Year(), to.Month()-(PassRateMonths-1), 1, 0, 0, 0, 0, to.Location())
	a, err := access(ctx, userID)
	if err != nil {
		return kpisrc.Result{}, err
	}
	sc, err := s.visible(ctx, a)
	if err != nil {
		return kpisrc.Result{}, err
	}
	vis, err := s.q.KpiVisibleClasses(ctx, classesdb.KpiVisibleClassesParams{AllClasses: sc.all, Ids: nonNil(sc.ids)})
	if err != nil {
		return kpisrc.Result{}, err
	}
	ids := make([]uuid.UUID, 0, len(vis))
	for _, c := range vis {
		ids = append(ids, c.ID)
	}
	classes, total, err := s.testScores(ctx, ids, from, to)
	if err != nil || total == 0 {
		return kpisrc.Result{}, err
	}
	pass := 0
	recs := []kpisrc.Record{{}}
	for _, c := range classes {
		p := passed(c.scores)
		pass += p
		recs = append(recs, kpisrc.Record{Text: c.label + ": " + strconv.Itoa(p) + "/" + strconv.Itoa(len(c.scores)) +
			" bài đạt chuẩn (" + kpisrc.Pct(float64(p)/float64(len(c.scores))*100) + ")"})
	}
	recs[0].Text = "Từ " + from.Format("02/01/2006") + " đến " + to.Format("02/01/2006") + ": " + strconv.Itoa(pass) + "/" +
		strconv.Itoa(total) + " bài đạt chuẩn"
	return kpisrc.Result{Value: kpisrc.F(round1(float64(pass) / float64(total) * 100)), Records: recs}, nil
}
