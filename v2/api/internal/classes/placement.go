package classes

import (
	"context"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/classes/classesdb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/placement"
)

// PlacementSessions: buổi học thật cho học thử của phân hệ test đầu vào (placement.Sessions, gắn ở cmd/menglish).
type PlacementSessions struct{ cls *Service }

func (s *Service) PlacementSessions() PlacementSessions { return PlacementSessions{cls: s} }

var _ placement.Sessions = PlacementSessions{}

func toPlacementSession(r classesdb.SessionsByIDsRow) placement.Session {
	branch := r.BranchID
	return placement.Session{
		ID: r.ID, ClassID: r.ClassID, ClassName: r.ClassName, CourseName: r.CourseName, ClassStatus: r.ClassStatus,
		Status: r.Status, BranchID: &branch, BranchName: r.BranchName, Date: r.Date, Start: r.StartTime, End: r.EndTime,
		TeacherName: deref(r.TeacherName),
	}
}

// Get: buổi theo id (nil nếu không có / lớp đã xóa).
func (p PlacementSessions) Get(ctx context.Context, id uuid.UUID) (*placement.Session, error) {
	m, err := p.Lookup(ctx, []uuid.UUID{id})
	if err != nil {
		return nil, err
	}
	if se, ok := m[id]; ok {
		return &se, nil
	}
	return nil, nil
}

func (p PlacementSessions) Lookup(ctx context.Context, ids []uuid.UUID) (map[uuid.UUID]placement.Session, error) {
	out := map[uuid.UUID]placement.Session{}
	if len(ids) == 0 {
		return out, nil
	}
	rows, err := p.cls.q.SessionsByIDs(ctx, ids)
	if err != nil {
		return nil, err
	}
	for _, r := range rows {
		out[r.ID] = toPlacementSession(r)
	}
	return out, nil
}

// TeachingScope: buổi người dùng dạy / dạy GVNN / trợ giảng và lớp người dùng phụ trách.
func (p PlacementSessions) TeachingScope(ctx context.Context, userID uuid.UUID) ([]uuid.UUID, []uuid.UUID, error) {
	sids, err := p.cls.q.TeachingSessionIDs(ctx, &userID)
	if err != nil {
		return nil, nil, err
	}
	cids, err := p.cls.q.ResponsibleClassIDs(ctx, &userID)
	return nonNil(sids), nonNil(cids), err
}
