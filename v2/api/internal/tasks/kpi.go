package tasks

import (
	"context"
	"strconv"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/kpi/kpisrc"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/tasks/tasksdb"
)

// Phần việc phân hệ Công việc làm cho KPI (KpiAutoCounter, KpiBoardService của Laravel): mốc chăm sóc tháng đầu quá hạn,
// việc được giao đúng hạn (hệ số trễ theo giờ) và số việc hoàn thành của bảng KPI tự động.

// careShortLabels: nhãn ngắn của mốc chăm sóc (FirstMonthCareService::MILESTONE_SHORT_LABELS).
var careShortLabels = map[int32]string{1: "Buổi 1", 4: "Buổi 4–5", 30: "Đủ 30 ngày"}

func careShortLabel(m int32) string {
	if l, ok := careShortLabels[m]; ok {
		return l
	}
	return "Mốc " + strconv.Itoa(int(m))
}

// KpiSources: nguồn care_overdue, task_on_time.
func (s *Service) KpiSources() kpisrc.Sources {
	return kpisrc.Sources{"care_overdue": s.kpiCareOverdue, "task_on_time": s.kpiTaskRows}
}

func (s *Service) kpiCareOverdue(ctx context.Context, userID uuid.UUID, from, to time.Time) (kpisrc.Result, error) {
	rows, err := s.q.KpiCareOverdue(ctx, tasksdb.KpiCareOverdueParams{UserID: &userID, FromAt: from, ToAt: to})
	if err != nil {
		return kpisrc.Result{}, err
	}
	out := make([]kpisrc.Record, 0, len(rows))
	for _, r := range rows {
		who := r.StudentName
		if who == "" {
			who = r.Title
		}
		out = append(out, kpisrc.Record{At: r.BreachedAt, Text: "Quá hạn chăm sóc " + careShortLabel(r.Milestone) + ": " + who})
	}
	return kpisrc.Result{Records: out}, nil
}

// kpiTaskRows: việc có hạn trong khoảng, hoàn thành so với hạn (giờ hạn mặc định 23:59); chưa xong mà chưa tới hạn thì chưa tính.
func (s *Service) kpiTaskRows(ctx context.Context, userID uuid.UUID, from, to time.Time) (kpisrc.Result, error) {
	rows, err := s.q.KpiTasksWithDeadline(ctx, tasksdb.KpiTasksWithDeadlineParams{UserID: &userID, FromDate: from, ToDate: to})
	if err != nil {
		return kpisrc.Result{}, err
	}
	now := s.now()
	var out []kpisrc.Record
	for _, r := range rows {
		hm, err := time.Parse("15:04", r.DueTime)
		if err != nil {
			hm = time.Date(0, 1, 1, 23, 59, 0, 0, time.UTC)
		}
		due := time.Date(r.DueDate.Year(), r.DueDate.Month(), r.DueDate.Day(), hm.Hour(), hm.Minute(), 0, 0, vnZone)
		done := r.CompletedAt
		if done == nil && (r.Status == "completed" || r.Status == "pending_confirmation") {
			done = &r.UpdatedAt
		}
		if done == nil && !now.After(due) {
			continue
		}
		hours := 0.0
		if done == nil || done.After(due) {
			end := now
			if done != nil {
				end = *done
			}
			hours = float64(int(end.Sub(due).Minutes())) / 60
		}
		state := "chưa xong, " + kpisrc.LateText(hours)
		if done != nil {
			state = "xong " + kpisrc.LateText(hours)
		}
		out = append(out, kpisrc.Record{At: due, Text: `"` + r.Title + `": hạn ` + due.Format("15:04 02/01") + ", " + state,
			Factor: kpisrc.F(kpisrc.HourFactor(hours))})
	}
	return kpisrc.Result{Records: out}, nil
}

// KpiBoard: số việc có hạn trong kỳ / đã hoàn thành theo người (bảng KPI tự động).
type KpiBoard struct{ s *Service }

func (s *Service) KpiBoard() KpiBoard { return KpiBoard{s} }

func (b KpiBoard) KpiBoardTasks(ctx context.Context, userIDs []uuid.UUID, from, to time.Time) (map[uuid.UUID]kpisrc.TaskStats, error) {
	rows, err := b.s.q.KpiBoardTasks(ctx, tasksdb.KpiBoardTasksParams{UserIds: userIDs, FromDate: from, ToDate: to})
	if err != nil {
		return nil, err
	}
	out := map[uuid.UUID]kpisrc.TaskStats{}
	for _, r := range rows {
		out[r.UserID] = kpisrc.TaskStats{Total: int(r.Total), Done: int(r.Done)}
	}
	return out, nil
}
