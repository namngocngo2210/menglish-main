package crm

import (
	"context"
	"slices"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/crm/crmdb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/reports"
)

// ReportSource: số liệu tuyển sinh cho báo cáo ngày / tuần (reports.LeadSource), truyền vào reports.NewMetrics ở
// cmd/menglish/main.go. Phạm vi là khách người viết được xem như danh sách khách CRM (CrmCustomer::visibleTo); bài test
// đầu vào chỉ tính bài đã gắn khách (bài gắn học viên chờ phân hệ học viên cung cấp phạm vi).
func (s *Service) ReportSource() reports.LeadSource { return reportSource{s} }

type reportSource struct{ s *Service }

func (r reportSource) scope(ctx context.Context, w reports.Writer) (scope, error) {
	a, err := r.s.access.LoadAccess(ctx, w.ID)
	if err != nil {
		return scope{}, err
	}
	return scopeOf(a), nil
}

// onlyBranches: chi nhánh của người viết (rỗng = không lọc thêm, như Laravel reportBranchIds).
func onlyBranches(w reports.Writer) []uuid.UUID {
	if len(w.BranchIDs) == 0 {
		return nil
	}
	return w.BranchIDs
}

func (r reportSource) NewLeads(ctx context.Context, w reports.Writer, from, to time.Time) ([]reports.LeadBrief, error) {
	sc, err := r.scope(ctx, w)
	if err != nil {
		return nil, err
	}
	rows, err := r.s.q.ReportNewLeads(ctx, crmdb.ReportNewLeadsParams{Since: from, Until: to, ScopeOwner: sc.owner,
		ScopeBranches: sc.branches, OnlyBranches: onlyBranches(w)})
	out := make([]reports.LeadBrief, 0, len(rows))
	for _, l := range rows {
		out = append(out, reports.LeadBrief{ID: l.ID, Name: l.Name, Source: l.Source, BranchID: l.BranchID, Contacted: l.Contacted, At: l.CreatedAt})
	}
	return out, err
}

func (r reportSource) Appointments(ctx context.Context, w reports.Writer, from, to time.Time) (int, error) {
	sc, err := r.scope(ctx, w)
	if err != nil {
		return 0, err
	}
	n, err := r.s.q.ReportAppointments(ctx, crmdb.ReportAppointmentsParams{Since: from, Until: to, ScopeOwner: sc.owner, ScopeBranches: sc.branches})
	return int(n), err
}

func (r reportSource) Closed(ctx context.Context, w reports.Writer, from, to time.Time) ([]reports.LeadBrief, error) {
	sc, err := r.scope(ctx, w)
	if err != nil {
		return nil, err
	}
	rows, err := r.s.q.ReportClosedLeads(ctx, crmdb.ReportClosedLeadsParams{Since: from, Until: to, ScopeOwner: sc.owner,
		ScopeBranches: sc.branches, OnlyBranches: onlyBranches(w)})
	out := make([]reports.LeadBrief, 0, len(rows))
	for _, l := range rows {
		out = append(out, reports.LeadBrief{ID: l.ID, Name: l.Name, At: l.ClosedAt})
	}
	slices.SortStableFunc(out, func(a, b reports.LeadBrief) int { return a.At.Compare(b.At) })
	return out, err
}

func (r reportSource) Tests(ctx context.Context, w reports.Writer, from, to time.Time) ([]reports.TestTaken, error) {
	sc, err := r.scope(ctx, w)
	if err != nil {
		return nil, err
	}
	rows, err := r.s.q.ReportTests(ctx, crmdb.ReportTestsParams{Since: from, Until: to, ScopeOwner: sc.owner, ScopeBranches: sc.branches})
	out := make([]reports.TestTaken, 0, len(rows))
	for _, t := range rows {
		out = append(out, reports.TestTaken{LeadID: t.LeadID, Name: t.Name, Score: t.OverallScore, Level: t.CefrLevel, Graded: t.Status == "graded"})
	}
	return out, err
}

func (r reportSource) BranchNames(ctx context.Context, ids []uuid.UUID) (map[uuid.UUID]string, error) {
	rows, err := r.s.q.ListBranchesBrief(ctx, crmdb.ListBranchesBriefParams{Ids: ids})
	out := make(map[uuid.UUID]string, len(rows))
	for _, b := range rows {
		out[b.ID] = b.Name
	}
	return out, err
}
