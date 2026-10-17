package projects

import (
	"context"
	"fmt"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/kpi/kpisrc"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/projects/projectsdb"
)

// KpiSources: nguồn KPI tự động của dự án học thuật (KpiAutoCounter::deliverableRows).
func (s *Service) KpiSources() kpisrc.Sources {
	return kpisrc.Sources{"academic_deliverable_on_time": s.kpiDeliverables}
}

// kpiDeliverables: mốc hạn trong kỳ người này nhận (hoặc mốc chưa giao của dự án người này phụ trách), hệ số theo số
// ngày trễ (hoàn thành so với ngày hạn; chưa xong thì tính đến hôm nay). Chưa xong mà chưa qua ngày hạn thì chưa tính.
func (s *Service) kpiDeliverables(ctx context.Context, userID uuid.UUID, from, to time.Time) (kpisrc.Result, error) {
	rows, err := s.q.DeliverableMilestones(ctx, projectsdb.DeliverableMilestonesParams{FromDate: dateOf(from), ToDate: dateOf(to), UserID: userID})
	if err != nil {
		return kpisrc.Result{}, err
	}
	today := dateOf(s.now())
	out := make([]kpisrc.Record, 0, len(rows))
	for _, m := range rows {
		due := m.DueDate
		var doneOn *time.Time
		if m.CompletedAt != nil {
			d := dateOf(*m.CompletedAt)
			doneOn = &d
		}
		if doneOn == nil && !due.Before(today) {
			continue
		}
		end := today
		if doneOn != nil {
			end = *doneOn
		}
		late := max(0, int(end.Sub(due).Hours()/24))
		state := "xong đúng hạn"
		switch {
		case doneOn == nil:
			state = fmt.Sprintf("chưa xong, đã trễ %d ngày", late)
		case late > 0:
			state = fmt.Sprintf("xong trễ %d ngày", late)
		}
		out = append(out, kpisrc.Record{
			At:     time.Date(due.Year(), due.Month(), due.Day(), 0, 0, 0, 0, vnZone),
			Text:   `Mốc "` + m.Title + `" (` + m.ProjectName + "): hạn " + due.Format("02/01") + ", " + state,
			Factor: kpisrc.F(kpisrc.DayFactor(late)),
		})
	}
	return kpisrc.Result{Records: out}, nil
}
