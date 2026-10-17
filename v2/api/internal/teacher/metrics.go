package teacher

import (
	"context"
	"math"
	"strconv"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/dashboard"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/kpi/kpisrc"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/teacher/teacherdb"
)

// Số liệu cho KPI và Tổng quan: tỷ lệ làm bài về nhà theo lớp (TeachingQuality::homeworkRates), điểm mini test trung bình,
// cột Bài tập của bảng KPI tự động (KpiBoardService::homework).

// homeworkDefaultDays: bài không có hạn nộp tính hạn = ngày giao + 7 ngày.
const homeworkDefaultDays = 7

func round1(v float64) float64 { return math.Round(v*10) / 10 }

// homeworkRates: % lượt (học viên × bài giao trong kỳ) có bài nộp trong khoảng [giao bài, hạn nộp]; lớp không giao bài
// trong kỳ hoặc không có học viên thì không có trong map.
func (s *Service) homeworkRates(ctx context.Context, classIDs []uuid.UUID, from, to time.Time) (map[uuid.UUID]float64, error) {
	out := map[uuid.UUID]float64{}
	if len(classIDs) == 0 {
		return out, nil
	}
	hws, err := s.q.HomeworksCreatedIn(ctx, teacherdb.HomeworksCreatedInParams{ClassIds: classIDs, FromAt: from, ToAt: to})
	if err != nil || len(hws) == 0 {
		return out, err
	}
	byClass := map[uuid.UUID][]teacherdb.HomeworksCreatedInRow{}
	var withHw []uuid.UUID
	for _, h := range hws {
		if _, ok := byClass[h.ClassID]; !ok {
			withHw = append(withHw, h.ClassID)
		}
		byClass[h.ClassID] = append(byClass[h.ClassID], h)
	}
	roster, err := s.q.RosterOfClasses(ctx, withHw)
	if err != nil {
		return nil, err
	}
	students := map[uuid.UUID]map[uuid.UUID]bool{}
	var all []uuid.UUID
	seen := map[uuid.UUID]bool{}
	for _, r := range roster {
		if r.ClassID == nil {
			continue
		}
		if students[*r.ClassID] == nil {
			students[*r.ClassID] = map[uuid.UUID]bool{}
		}
		students[*r.ClassID][r.StudentID] = true
		if !seen[r.StudentID] {
			seen[r.StudentID] = true
			all = append(all, r.StudentID)
		}
	}
	if len(all) == 0 {
		return out, nil
	}
	subs, err := s.q.SubmissionTimes(ctx, teacherdb.SubmissionTimesParams{StudentIds: all, FromAt: from,
		ToAt: to.AddDate(0, 0, homeworkDefaultDays+31)})
	if err != nil {
		return nil, err
	}
	times := map[uuid.UUID][]time.Time{}
	for _, sb := range subs {
		times[sb.StudentID] = append(times[sb.StudentID], sb.CreatedAt)
	}
	for cid, items := range byClass {
		st := students[cid]
		if len(st) == 0 {
			continue
		}
		done := 0
		for _, h := range items {
			deadline := h.CreatedAt.AddDate(0, 0, homeworkDefaultDays)
			switch {
			case h.DueAt != nil:
				deadline = *h.DueAt
			case h.DueDate != nil:
				deadline = at(*h.DueDate, "23:59").Add(59*time.Second + 999*time.Millisecond)
			}
			for sid := range st {
				for _, t := range times[sid] {
					if !t.Before(h.CreatedAt) && !t.After(deadline) {
						done++
						break
					}
				}
			}
		}
		out[cid] = round1(float64(done) / float64(len(items)*len(st)) * 100)
	}
	return out, nil
}

// KpiSources: nguồn homework_rate (tỷ lệ nộp bài về nhà các lớp giáo viên giữ trong kỳ, trung bình theo sĩ số).
func (s *Service) KpiSources() kpisrc.Sources {
	return kpisrc.Sources{"homework_rate": s.kpiHomeworkRate}
}

func (s *Service) kpiHomeworkRate(ctx context.Context, userID uuid.UUID, from, to time.Time) (kpisrc.Result, error) {
	classes, err := s.q.KpiTeacherClasses(ctx, teacherdb.KpiTeacherClassesParams{UserID: &userID, FromDate: from, ToDate: to})
	if err != nil {
		return kpisrc.Result{}, err
	}
	ids := make([]uuid.UUID, len(classes))
	for i, c := range classes {
		ids[i] = c.ID
	}
	rates, err := s.homeworkRates(ctx, ids, from, to)
	if err != nil {
		return kpisrc.Result{}, err
	}
	var res kpisrc.Result
	sum, weight := 0.0, 0.0
	for _, c := range classes {
		r, ok := rates[c.ID]
		if !ok {
			continue
		}
		w := float64(max(1, c.Students))
		sum += r * w
		weight += w
		name := c.Code
		if name == "" {
			name = c.Name
		}
		res.Records = append(res.Records, kpisrc.Record{Text: "Lớp " + name + ": " + kpisrc.Pct(r) + " (" + strconv.Itoa(int(c.Students)) + " học sinh)"})
	}
	if weight > 0 {
		res.Value = kpisrc.F(round1(sum / weight))
	}
	return res, nil
}

// KpiBoard: cột Bài tập của bảng KPI tự động (kpi.BoardHomework).
type KpiBoard struct{ s *Service }

func (s *Service) KpiBoard() KpiBoard { return KpiBoard{s} }

// KpiBoardHomework: theo nhân sự (GV / trợ giảng / GVNN của lớp): bài nộp của học viên lớp chính trong kỳ / (số bài giao
// trong kỳ × số học viên giữ chỗ có lớp chính là lớp đó).
func (b KpiBoard) KpiBoardHomework(ctx context.Context, userIDs []uuid.UUID, from, to time.Time) (map[uuid.UUID]kpisrc.HomeworkStats, error) {
	out := map[uuid.UUID]kpisrc.HomeworkStats{}
	if len(userIDs) == 0 {
		return out, nil
	}
	q := b.s.q
	classes, err := q.BoardClasses(ctx, userIDs)
	if err != nil {
		return nil, err
	}
	var classIDs []uuid.UUID
	for _, c := range classes {
		classIDs = append(classIDs, c.ID)
	}
	if len(classIDs) == 0 {
		return out, nil
	}
	counts, err := q.BoardHomeworkCounts(ctx, teacherdb.BoardHomeworkCountsParams{ClassIds: classIDs, FromDate: from, ToDate: to})
	if err != nil {
		return nil, err
	}
	total := map[uuid.UUID]int{}
	var withHw []uuid.UUID
	for _, c := range counts {
		total[c.ClassID] = int(c.Total)
		withHw = append(withHw, c.ClassID)
	}
	if len(withHw) == 0 {
		return out, nil
	}
	mains, err := q.BoardMainStudents(ctx, withHw)
	if err != nil {
		return nil, err
	}
	studentsOf := map[uuid.UUID][]uuid.UUID{}
	for _, m := range mains {
		if m.CurrentClassID != nil {
			studentsOf[*m.CurrentClassID] = append(studentsOf[*m.CurrentClassID], m.ID)
		}
	}
	for _, uid := range userIDs {
		var st kpisrc.HomeworkStats
		var students []uuid.UUID
		for _, c := range classes {
			if !hasID([]*uuid.UUID{c.TeacherID, c.AssistantID, c.ForeignTeacherID}, uid) || total[c.ID] == 0 {
				continue
			}
			st.Expected += total[c.ID] * len(studentsOf[c.ID])
			students = append(students, studentsOf[c.ID]...)
		}
		if len(students) > 0 {
			n, err := q.BoardSubmissionCount(ctx, teacherdb.BoardSubmissionCountParams{StudentIds: students, FromDate: from, ToDate: to})
			if err != nil {
				return nil, err
			}
			st.Submitted = int(n)
		}
		out[uid] = st
	}
	return out, nil
}

// Dashboard: bài về nhà + điểm mini test theo lớp cho Tổng quan (dashboard.ClassHomework).
type Dashboard struct{ s *Service }

func (s *Service) Dashboard() Dashboard { return Dashboard{s} }

func (d Dashboard) ClassQuality(ctx context.Context, classIDs []uuid.UUID, from, to time.Time) (map[uuid.UUID]dashboard.ClassQuality, error) {
	out := map[uuid.UUID]dashboard.ClassQuality{}
	rates, err := d.s.homeworkRates(ctx, classIDs, from, to)
	if err != nil {
		return nil, err
	}
	for id, r := range rates {
		out[id] = dashboard.ClassQuality{Homework: ptr(r)}
	}
	avgs, err := d.s.q.ClassMiniAverages(ctx, teacherdb.ClassMiniAveragesParams{ClassIds: classIDs, FromDate: from, ToDate: to})
	if err != nil {
		return nil, err
	}
	for _, a := range avgs {
		q := out[a.ClassID]
		q.Score = ptr(round1(a.AvgScore))
		out[a.ClassID] = q
	}
	return out, nil
}
