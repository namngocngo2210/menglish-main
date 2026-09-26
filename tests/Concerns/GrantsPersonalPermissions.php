<?php

namespace Tests\Concerns;

use App\Models\User;
use App\Models\UserPermissionOverride;

/**
 * BA 26/09/2026 "Phần kế toán cho Admin phân quyền linh hoạt": quyền kế toán (vd. kế toán tổng xem mọi chi nhánh)
 * do Admin cấp theo người ở màn Phân quyền cá nhân. Test cấp đúng cách đó: override "cho phép", phạm vi toàn hệ thống.
 */
trait GrantsPersonalPermissions
{
    protected function grantPersonal(User $user, string ...$permissions): User
    {
        foreach ($permissions as $permission) {
            [$module, $action] = explode('.', $permission, 2);
            UserPermissionOverride::updateOrCreate(
                ['user_id' => $user->id, 'module' => $module, 'action' => $action, 'scope_type' => UserPermissionOverride::SCOPE_ALL, 'scope_id' => null],
                ['allow' => true],
            );
        }
        $user->unsetRelation('permissionOverrides');

        return $user;
    }

    /**
     * Kế toán tổng: phạm vi dữ liệu "Toàn hệ thống" cho học phí, báo cáo thu chi, chấm công (RBAC — thay cho
     * tuition.all_branches / finance.all_branches cũ) + cấu hình dải số mặc định.
     */
    protected function grantHeadOffice(User $user): User
    {
        return $this->grantPersonal($user, 'tuition.scope_all', 'finance.scope_all', 'attendance_staff.scope_all', 'invoice_range.manage_default');
    }
}
