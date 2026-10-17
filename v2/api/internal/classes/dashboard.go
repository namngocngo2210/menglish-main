package classes

import (
	"context"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/classes/classesdb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/dashboard"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// DashboardClasses: số liệu lớp cho Tổng quan và Báo cáo & sự vụ lớp (dashboard.Classes); phạm vi xem như màn Lớp học
// (visible = ClassModel::visibleTo).
func (s *Service) DashboardClasses() dashboard.Classes { return dashboardClasses{s} }

type dashboardClasses struct{ cls *Service }

func (d dashboardClasses) RunningInBranches(ctx context.Context, branches []uuid.UUID) (int64, error) {
	return d.cls.q.DashboardRunningInBranches(ctx, branches)
}

func (d dashboardClasses) RunningVisible(ctx context.Context, a *iam.Access) (int64, error) {
	sc, err := d.cls.visible(ctx, a)
	if err != nil {
		return 0, err
	}
	return d.cls.q.DashboardRunningVisible(ctx, classesdb.DashboardRunningVisibleParams{AllClasses: sc.all, Ids: nonNil(sc.ids)})
}

// stats: số liệu từng lớp trong tháng [from, to].
func (d dashboardClasses) stats(ctx context.Context, ids []uuid.UUID, from, to time.Time) ([]dashboard.ClassStats, error) {
	if len(ids) == 0 {
		return []dashboard.ClassStats{}, nil
	}
	rows, err := d.cls.q.DashboardClassStats(ctx, classesdb.DashboardClassStatsParams{Ids: ids, DateFrom: from, DateTo: to, FromAt: from, ToAt: to})
	out := make([]dashboard.ClassStats, 0, len(rows))
	for _, r := range rows {
		out = append(out, dashboard.ClassStats{ID: r.ID, Code: r.Code, Name: r.Name, TeacherID: r.TeacherID, Students: int(r.Students),
			New: int(r.NewStudents), Dropped: int(r.Dropped), AttendanceTotal: int(r.AttendanceTotal), Attended: int(r.Attended)})
	}
	return out, err
}

func (d dashboardClasses) TeachingOf(ctx context.Context, uid uuid.UUID, from, to time.Time) ([]dashboard.ClassStats, error) {
	ids, err := d.cls.q.DashboardTeachingClassIDs(ctx, classesdb.DashboardTeachingClassIDsParams{UserID: uid, DateFrom: from, DateTo: to})
	if err != nil {
		return nil, err
	}
	return d.stats(ctx, ids, from, to)
}

func (d dashboardClasses) TeamClasses(ctx context.Context, a *iam.Access, from, to time.Time) ([]dashboard.ClassStats, map[uuid.UUID]string, error) {
	sc, err := d.cls.visible(ctx, a)
	if err != nil {
		return nil, nil, err
	}
	ids, err := d.cls.q.DashboardTeamClassIDs(ctx, classesdb.DashboardTeamClassIDsParams{AllClasses: sc.all, Ids: nonNil(sc.ids)})
	if err != nil {
		return nil, nil, err
	}
	stats, err := d.stats(ctx, ids, from, to)
	if err != nil {
		return nil, nil, err
	}
	var teachers []uuid.UUID
	for _, st := range stats {
		if st.TeacherID != nil {
			teachers = append(teachers, *st.TeacherID)
		}
	}
	names := map[uuid.UUID]string{}
	if len(teachers) > 0 {
		rows, err := d.cls.q.DashboardUserNames(ctx, teachers)
		if err != nil {
			return nil, nil, err
		}
		for _, r := range rows {
			names[r.ID] = r.FullName
		}
	}
	return stats, names, nil
}

func (d dashboardClasses) Names(ctx context.Context, ids []uuid.UUID) (map[uuid.UUID]string, error) {
	out := map[uuid.UUID]string{}
	if len(ids) == 0 {
		return out, nil
	}
	rows, err := d.cls.q.DashboardClassNames(ctx, ids)
	for _, r := range rows {
		out[r.ID] = r.Name
	}
	return out, err
}

func (d dashboardClasses) Overview(ctx context.Context, day time.Time) (dashboard.ClassOverview, error) {
	r, err := d.cls.q.DashboardClassOverview(ctx, day)
	return dashboard.ClassOverview{Total: r.Total, Active: r.Active, AttendanceTotal: r.AttendanceTotal, Attended: r.Attended}, err
}

func (d dashboardClasses) FilterOptions(ctx context.Context, a *iam.Access) ([]dashboard.Option, error) {
	sc, err := d.cls.visible(ctx, a)
	if err != nil {
		return nil, err
	}
	rows, err := d.cls.q.DashboardClassOptions(ctx, classesdb.DashboardClassOptionsParams{AllClasses: sc.all, Ids: nonNil(sc.ids)})
	out := make([]dashboard.Option, 0, len(rows))
	for _, r := range rows {
		out = append(out, dashboard.Option{Value: r.ID.String(), Label: r.Code})
	}
	return out, err
}
