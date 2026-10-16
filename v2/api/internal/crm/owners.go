package crm

import (
	"context"
	"slices"
	"strings"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/crm/crmdb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// owner: người phụ trách khách (Laravel LeadOwners). Học vụ gắn với cơ sở của mình; người phạm vi CRM toàn hệ thống
// (Admin) phụ trách được khách mọi cơ sở. Giao khách cho người ở cơ sở khác = chuyển cơ sở.
type owner struct {
	ID        uuid.UUID
	Name      string
	Email     string
	Active    bool
	Candidate bool // đang hoạt động và có quyền đối tượng lead.be_assigned
	CoversAll bool // phạm vi CRM toàn hệ thống
	Branches  []iam.UserBranch
}

// BranchIDs: cơ sở của người phụ trách (chính + cấp thêm).
func (o *owner) BranchIDs() []uuid.UUID {
	ids := make([]uuid.UUID, 0, len(o.Branches))
	for _, b := range o.Branches {
		ids = append(ids, b.ID)
	}
	return ids
}

// belongsToBranch: người phụ trách nhận được khách của cơ sở này.
func (o *owner) belongsToBranch(branch *uuid.UUID) bool {
	return o.CoversAll || (branch != nil && slices.Contains(o.BranchIDs(), *branch))
}

// homeBranch: cơ sở khách chuyển sang khi giao cho người này (cơ sở chính, không có thì cơ sở cấp thêm đầu tiên).
func (o *owner) homeBranch() *iam.UserBranch {
	for i := range o.Branches {
		if o.Branches[i].Primary {
			return &o.Branches[i]
		}
	}
	if len(o.Branches) > 0 {
		return &o.Branches[0]
	}
	return nil
}

// Label: "Tên — Cơ sở" (Admin: "Tên — Admin"); người không còn nhận phụ trách: "Tên (người phụ trách cũ)".
func (o *owner) Label() string {
	switch {
	case !o.Candidate:
		return o.Name + " (người phụ trách cũ)"
	case o.CoversAll:
		return o.Name + " — Admin"
	}
	if b := o.homeBranch(); b != nil && b.Primary {
		return o.Name + " — " + b.Name
	}
	return o.Name
}

const ownerInvalidMsg = "Người phụ trách phải là Học vụ hoặc Admin đang hoạt động."

// loadOwners: thông tin người phụ trách theo id (bỏ qua id không tồn tại), giữ thứ tự theo tên.
func (s *Service) loadOwners(ctx context.Context, q *crmdb.Queries, ids []uuid.UUID) ([]*owner, error) {
	if len(ids) == 0 {
		return nil, nil
	}
	rows, err := q.ListUsersBrief(ctx, ids)
	if err != nil {
		return nil, err
	}
	out := make([]*owner, 0, len(rows))
	for _, r := range rows {
		o := &owner{ID: r.ID, Name: r.FullName, Email: r.Email, Active: r.Status == "active" && r.Kind == "staff"}
		acc, err := s.access.LoadAccess(ctx, r.ID)
		if err != nil {
			return nil, err
		}
		o.Branches = acc.Branches
		o.Candidate = o.Active && acc.Can("lead.be_assigned")
		o.CoversAll = acc.Level("lead") == iam.ScopeAll
		out = append(out, o)
	}
	return out, nil
}

func (s *Service) loadOwner(ctx context.Context, q *crmdb.Queries, id uuid.UUID) (*owner, error) {
	list, err := s.loadOwners(ctx, q, []uuid.UUID{id})
	if err != nil || len(list) == 0 {
		return nil, err
	}
	return list[0], nil
}

// candidates: người được chọn làm người phụ trách, người toàn hệ thống lên đầu. branches nil = mọi cơ sở;
// ngược lại chỉ người thuộc các cơ sở này (người toàn hệ thống luôn có).
func (s *Service) candidates(ctx context.Context, q *crmdb.Queries, branches []uuid.UUID) ([]*owner, error) {
	ids, err := q.ListOwnerCandidateIDs(ctx)
	if err != nil {
		return nil, err
	}
	all, err := s.loadOwners(ctx, q, ids)
	if err != nil {
		return nil, err
	}
	var first, rest []*owner
	for _, o := range all {
		if !o.Candidate {
			continue
		}
		if o.CoversAll {
			first = append(first, o)
			continue
		}
		if branches == nil || slices.ContainsFunc(o.BranchIDs(), func(b uuid.UUID) bool { return slices.Contains(branches, b) }) {
			rest = append(rest, o)
		}
	}
	return append(first, rest...), nil
}

// matchOwner: tìm người phụ trách theo email hoặc họ tên (không phân biệt hoa thường; tên phải duy nhất) — nhập Excel.
func matchOwner(list []*owner, value string) *owner {
	needle := strings.ToLower(strings.TrimSpace(value))
	if needle == "" {
		return nil
	}
	for _, o := range list {
		if strings.ToLower(o.Email) == needle {
			return o
		}
	}
	var found *owner
	for _, o := range list {
		if strings.ToLower(strings.TrimSpace(o.Name)) == needle {
			if found != nil {
				return nil
			}
			found = o
		}
	}
	return found
}

// Option là một lựa chọn {value, label} cho ô chọn trên giao diện.
type Option struct {
	Value string `json:"value"`
	Label string `json:"label"`
}

// OwnerOption: người phụ trách kèm cơ sở để giao diện lọc theo cơ sở đang chọn.
type OwnerOption struct {
	Value       uuid.UUID   `json:"value"`
	Label       string      `json:"label"`
	BranchIDs   []uuid.UUID `json:"branch_ids"`
	AllBranches bool        `json:"all_branches"`
}

func ownerOptions(list []*owner) []OwnerOption {
	out := make([]OwnerOption, 0, len(list))
	for _, o := range list {
		ids := o.BranchIDs()
		if ids == nil {
			ids = []uuid.UUID{}
		}
		out = append(out, OwnerOption{Value: o.ID, Label: o.Label(), BranchIDs: ids, AllBranches: o.CoversAll})
	}
	return out
}
