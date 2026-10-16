package iam

import (
	"context"
	"fmt"
	"slices"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam/iamdb"
)

// Nhật ký vận hành cho các thao tác quản trị (mô tả giữ như Audit::describe / activity() của Laravel).

// recordTx ghi nhật ký trong transaction của thao tác: lỗi thì cả thao tác rollback.
func recordTx(ctx context.Context, db audit.DB, actor *Access, e audit.Entry) error {
	e.ActorID = &actor.UserID
	return audit.Record(ctx, db, e)
}

// record ghi nhật ký sau thao tác không có transaction: lỗi chỉ ghi log, không làm hỏng thao tác đã xong.
func (s *Service) record(ctx context.Context, actor *Access, e audit.Entry) {
	e.ActorID = &actor.UserID
	if err := audit.Record(ctx, s.pool, e); err != nil {
		s.log.Error("audit record", "err", err, "action", e.Action)
	}
}

func branchSnapshot(b iamdb.CoreBranch) map[string]any {
	return map[string]any{"code": b.Code, "name": b.Name, "address": b.Address, "phone": b.Phone, "is_active": b.IsActive}
}

func checkinSnapshot(b iamdb.CoreBranch) map[string]any {
	c := toBranch(b).Checkin
	return map[string]any{
		"latitude": c.Latitude, "longitude": c.Longitude, "checkin_radius": c.Radius,
		"work_start_time": c.WorkStart, "work_end_time": c.WorkEnd, "late_grace_minutes": c.Grace,
	}
}

func branchEntry(b iamdb.CoreBranch, action, desc string, changes audit.Changes) audit.Entry {
	event := audit.EventUpdated
	if action == "branch.create" {
		event = audit.EventCreated
	}
	return audit.Entry{
		Module: audit.ModuleSystem, Event: event, Action: action, SubjectType: "branch", SubjectID: b.ID.String(),
		SubjectLabel: fmt.Sprintf("%s (%s)", b.Name, b.Code), BranchID: &b.ID, Description: desc, Changes: changes,
	}
}

func userLabel(u iamdb.IamUser) string {
	switch {
	case u.Code != nil:
		return fmt.Sprintf("%s (%s)", u.FullName, *u.Code)
	case u.Email != nil:
		return fmt.Sprintf("%s (%s)", u.FullName, *u.Email)
	}
	return u.FullName
}

func userSnapshot(t *target) map[string]any {
	branches := make([]string, 0, len(t.branches))
	for _, b := range t.branches {
		branches = append(branches, b.Code)
	}
	return map[string]any{
		"code": t.user.Code, "email": t.user.Email, "phone": t.user.Phone, "full_name": t.user.FullName,
		"branches": branches,
	}
}

func userEntry(t *target, event, action, desc string, changes audit.Changes) audit.Entry {
	return audit.Entry{
		Module: audit.ModuleUsers, Event: event, Action: action, SubjectType: "user", SubjectID: t.user.ID.String(),
		SubjectLabel: userLabel(t.user), BranchID: t.primaryBranch(), Description: desc, Changes: changes,
	}
}

// overrideList: phân quyền cá nhân dạng "quyền=cho phép|chặn" để so trước / sau.
func overrideList(m map[string]bool) []string {
	out := []string{}
	for p, allow := range m {
		v := "chặn"
		if allow {
			v = "cho phép"
		}
		out = append(out, p+"="+v)
	}
	slices.Sort(out)
	return out
}

// added / removed: phần tử có ở after mà không có ở before và ngược lại.
func setDiff(before, after []string) (added, removed []string) {
	added, removed = []string{}, []string{}
	for _, p := range after {
		if !slices.Contains(before, p) {
			added = append(added, p)
		}
	}
	for _, p := range before {
		if !slices.Contains(after, p) {
			removed = append(removed, p)
		}
	}
	return added, removed
}
