package iam

import (
	"context"
	"fmt"

	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgxpool"
	"github.com/redis/go-redis/v9"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam/iamdb"
)

// SyncCatalog đồng bộ danh mục quyền và 10 vai trò xuống DB (chạy sau mỗi lần migrate):
//   - thêm / cập nhật quyền có trong danh mục, xóa quyền đã bỏ (kéo theo gán quyền và phân quyền cá nhân);
//   - tạo vai trò còn thiếu và cấp quyền mặc định CHỈ cho vai trò vừa tạo, không ghi đè cấu hình Admin đã chỉnh;
//   - vai trò Admin luôn giữ mọi quyền thao tác, phạm vi và gán vai trò (kể cả quyền mới thêm).
func SyncCatalog(ctx context.Context, pool *pgxpool.Pool) (SyncReport, error) {
	c := GetCatalog()
	var rep SyncReport
	err := pgx.BeginFunc(ctx, pool, func(tx pgx.Tx) error {
		// Một tiến trình đồng bộ tại một thời điểm.
		if _, err := tx.Exec(ctx, "SELECT pg_advisory_xact_lock(hashtext('iam.sync_catalog'))"); err != nil {
			return err
		}
		q := iamdb.New(tx)
		want := map[string]bool{}
		for _, p := range c.All() {
			want[p.Name] = true
			if err := q.UpsertPermission(ctx, iamdb.UpsertPermissionParams{Name: p.Name, Module: p.Module, Kind: p.Kind}); err != nil {
				return err
			}
		}
		have, err := q.ListPermissionNames(ctx)
		if err != nil {
			return err
		}
		for _, name := range have {
			if !want[name] {
				if err := q.DeletePermission(ctx, name); err != nil {
					return err
				}
				rep.RemovedPermissions = append(rep.RemovedPermissions, name)
			}
		}
		for i, r := range c.Roles {
			n, err := q.InsertRoleIfMissing(ctx, iamdb.InsertRoleIfMissingParams{
				Name: r.Name, Label: r.Label, ShortLabel: r.ShortLabel, Sort: int32(i),
			})
			if err != nil {
				return err
			}
			if err := q.SetRoleSort(ctx, iamdb.SetRoleSortParams{Name: r.Name, Sort: int32(i)}); err != nil {
				return err
			}
			var grant []string
			switch {
			case n == 1:
				rep.CreatedRoles = append(rep.CreatedRoles, r.Name)
				if grant, err = c.Expand(r.Name, r.Defaults); err != nil {
					return err
				}
			case r.Name == RoleAdmin:
				grant, _ = c.Expand(RoleAdmin, []string{"*"})
			}
			for _, p := range grant {
				if err := q.GrantRolePermission(ctx, iamdb.GrantRolePermissionParams{Role: r.Name, Permission: p}); err != nil {
					return fmt.Errorf("cấp %s cho %s: %w", p, r.Name, err)
				}
			}
		}
		rep.Permissions = len(want)
		return nil
	})
	return rep, err
}

// SyncReport tóm tắt một lần đồng bộ (in ra khi chạy migrate).
type SyncReport struct {
	Permissions        int
	CreatedRoles       []string
	RemovedPermissions []string
}

// InvalidateAllAccess buộc tính lại quyền của mọi người (sau khi đồng bộ danh mục hoặc đổi quyền vai trò).
func InvalidateAllAccess(ctx context.Context, rdb *redis.Client) error {
	return rdb.Incr(ctx, authzVersionKey).Err()
}
