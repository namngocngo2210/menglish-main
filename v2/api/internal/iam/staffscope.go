package iam

import (
	"context"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam/iamdb"
)

// Lọc nhân sự theo phạm vi dữ liệu của người xem (Laravel User::scopePickableBy, PayrollController::staffInScope,
// Penalty::staffVisibleTo, DataScope::apply cho bảng nhân sự). Kết quả là UserSet: nil = không giới hạn.

// UserSet: tập người dùng; nil = mọi người.
type UserSet map[uuid.UUID]struct{}

// Has: người dùng có trong tập không (tập nil = có).
func (s UserSet) Has(id uuid.UUID) bool {
	if s == nil {
		return true
	}
	_, ok := s[id]
	return ok
}

// IDs: danh sách id (nil khi tập không giới hạn), để truyền vào truy vấn `sqlc.narg(...)::uuid[]`.
func (s UserSet) IDs() []uuid.UUID {
	if s == nil {
		return nil
	}
	out := make([]uuid.UUID, 0, len(s))
	for id := range s {
		out = append(out, id)
	}
	return out
}

func (s UserSet) add(ids ...uuid.UUID) {
	for _, id := range ids {
		if id != uuid.Nil {
			s[id] = struct{}{}
		}
	}
}

// FilterUsers: giữ các phần tử có id thuộc tập (tập nil giữ tất cả).
func FilterUsers[T any](set UserSet, items []T, id func(T) uuid.UUID) []T {
	if set == nil {
		return items
	}
	out := make([]T, 0, len(items))
	for _, it := range items {
		if set.Has(id(it)) {
			out = append(out, it)
		}
	}
	return out
}

func inBranches(ctx context.Context, db iamdb.DBTX, branches []uuid.UUID) (UserSet, error) {
	set := UserSet{}
	if len(branches) == 0 {
		return set, nil
	}
	ids, err := iamdb.New(db).UserIDsInBranches(ctx, branches)
	if err != nil {
		return nil, err
	}
	set.add(ids...)
	return set, nil
}

// PickableUsers: nhân sự hiện trong ô chọn (giáo viên, trợ giảng, thành viên…) của người xem — User::scopePickableBy:
// phạm vi module mức Toàn hệ thống → mọi người (nil); mức khác → nhân sự thuộc chi nhánh của người xem (chi nhánh chính
// hoặc được cấp thêm), nhân sự dùng chung chưa gắn chi nhánh và người đang được gán (keep).
func PickableUsers(ctx context.Context, db iamdb.DBTX, a *Access, module string, keep ...uuid.UUID) (UserSet, error) {
	limit := a.BranchLimit(module)
	if limit == nil {
		return nil, nil
	}
	set, err := inBranches(ctx, db, limit)
	if err != nil {
		return nil, err
	}
	shared, err := iamdb.New(db).UserIDsWithoutBranch(ctx)
	if err != nil {
		return nil, err
	}
	set.add(shared...)
	set.add(keep...)
	return set, nil
}

// StaffInScope: nhân sự người xem được thấy theo phạm vi module — PayrollController::staffInScope: Toàn hệ thống → mọi
// người (nil); mức khác (kể cả "Của tôi") → chính tôi + nhân sự thuộc chi nhánh của tôi.
func StaffInScope(ctx context.Context, db iamdb.DBTX, a *Access, module string) (UserSet, error) {
	limit := a.BranchLimit(module)
	if limit == nil {
		return nil, nil
	}
	set, err := inBranches(ctx, db, limit)
	if err != nil {
		return nil, err
	}
	set.add(a.UserID)
	return set, nil
}

// ScopedStaff: nhân sự theo mức phạm vi (DataScope::apply: "của tôi" = chính tôi, mức chi nhánh gồm cả của tôi) — Toàn
// hệ thống → mọi người (nil); Chi nhánh → chính tôi + nhân sự thuộc chi nhánh của tôi; Của tôi → chỉ chính tôi.
func ScopedStaff(ctx context.Context, db iamdb.DBTX, a *Access, module string) (UserSet, error) {
	switch a.Level(module) {
	case ScopeAll:
		return nil, nil
	case ScopeBranch:
		return StaffInScope(ctx, db, a, module)
	}
	return UserSet{a.UserID: {}}, nil
}

// StaffInBranches: nhân sự thuộc chi nhánh của người xem — Penalty::staffVisibleTo: Toàn hệ thống → mọi người (nil);
// mức khác → chỉ nhân sự có chi nhánh chính / được cấp thêm thuộc chi nhánh của tôi.
func StaffInBranches(ctx context.Context, db iamdb.DBTX, a *Access, module string) (UserSet, error) {
	limit := a.BranchLimit(module)
	if limit == nil {
		return nil, nil
	}
	return inBranches(ctx, db, limit)
}
