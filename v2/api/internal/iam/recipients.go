package iam

import (
	"context"
	"fmt"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam/iamdb"
)

// Tìm người nhận thông báo theo quyền (Laravel: NotificationService::userIdsWithPermission / superAdminIds).
// Dùng cùng notify.Send, vd.:
//
//	ids, err := iam.UserIDsWithPermission(ctx, tx, "tuition.approve", &branchID)
//	notify.Send(ctx, tx, ids, notify.Notification{...})

// UserIDsWithPermission trả id người dùng đang hoạt động có quyền perm (Admin có mọi quyền trừ quyền đối tượng).
// branchID khác nil thì chỉ lấy người thuộc chi nhánh đó. Phân quyền cá nhân theo chi nhánh / lớp không được tính.
func UserIDsWithPermission(ctx context.Context, db iamdb.DBTX, perm string, branchID *uuid.UUID) ([]uuid.UUID, error) {
	c := GetCatalog()
	kind := c.Kind(perm)
	if kind == "" {
		return nil, fmt.Errorf("iam: quyền %s không có trong danh mục", perm)
	}
	return iamdb.New(db).ListUserIDsWithPermission(ctx, iamdb.ListUserIDsWithPermissionParams{
		BranchID:    branchID,
		AdminAll:    kind != KindAudience,
		AdminRole:   RoleAdmin,
		RoleAllowed: !c.IsAdminOnly(perm),
		Perm:        perm,
	})
}

// AdminIDs trả id các Admin đang hoạt động: người nhận mặc định của thông báo vận hành không gắn với ai cụ thể.
func AdminIDs(ctx context.Context, db iamdb.DBTX) ([]uuid.UUID, error) {
	return iamdb.New(db).ListUserIDsWithPermission(ctx, iamdb.ListUserIDsWithPermissionParams{
		AdminAll: true, AdminRole: RoleAdmin,
	})
}
