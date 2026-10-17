package teacher

import (
	"context"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/placement"
)

// PlacementTrials: khách học thử theo buổi từ phân hệ Test đầu vào & học thử.
func PlacementTrials(p *placement.Service) Trials { return placementTrials{p} }

type placementTrials struct{ p *placement.Service }

func (t placementTrials) SessionGuests(ctx context.Context, ids []uuid.UUID) (map[uuid.UUID][]TrialGuest, error) {
	m, err := t.p.SessionGuests(ctx, ids)
	if err != nil {
		return nil, err
	}
	out := make(map[uuid.UUID][]TrialGuest, len(m))
	for sid, rows := range m {
		for _, r := range rows {
			out[sid] = append(out[sid], TrialGuest{ID: r.ID, Name: r.CustomerName, TestScore: r.TestScore, StatusLabel: r.StatusLabel, Notes: r.Notes})
		}
	}
	return out, nil
}
