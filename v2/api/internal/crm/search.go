package crm

import (
	"context"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/crm/crmdb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/search"
)

// Searcher: nhóm "Khách hàng (CRM)" của tìm kiếm chung, cùng phạm vi danh sách khách (lead.view; phụ trách / chi nhánh /
// toàn hệ thống). Đăng ký ở cmd/menglish/main.go: search.Builtin(...).Add(crm.Searcher(crmSvc)).
func Searcher(s *Service) search.Searcher {
	return search.Searcher{
		Key: "customers", Label: "Khách hàng (CRM)", Icon: "person_search", Perms: []string{"lead.view"},
		Search: func(ctx context.Context, a *iam.Access, q search.Query) ([]search.Hit, error) {
			sc := scopeOf(a)
			rows, err := s.q.SearchLeads(ctx, crmdb.SearchLeadsParams{ScopeOwner: sc.owner, ScopeBranches: sc.branches,
				Term: q.Like, Phone: q.Phone, Lim: int32(q.Limit)})
			if err != nil {
				return nil, err
			}
			hits := make([]search.Hit, 0, len(rows))
			for _, l := range rows {
				meta := []string{StageLabel(l.Stage)}
				if l.BranchName != nil {
					meta = append(meta, *l.BranchName)
				}
				hits = append(hits, search.Hit{ID: l.ID.String(), Title: l.Name, Code: ShortCode(l.Code), Subtitle: l.Phone,
					Meta: meta, Link: leadLink(l.ID)})
			}
			return hits, nil
		},
	}
}
