<?php

namespace App\Support\Approvals;

use App\Models\User;
use App\Support\Roles;

/**
 * Yêu cầu 06/10/2026: chỉ Admin được duyệt / từ chối, ở mọi màn (hộp "Cần duyệt" và màn nghiệp vụ gốc).
 *
 *  - PERMISSIONS: quyền chỉ dùng để duyệt → vai trò khác bị thu hồi ở Gate::before (AppServiceProvider), kể cả khi vai trò
 *    hoặc override cá nhân đang cấp; Rbac::scopeUsersWithPermission chỉ trả Admin (người nhận thông báo "chờ duyệt").
 *  - ABILITY: nút duyệt của các nghiệp vụ mà quyền gốc còn dùng cho việc khác (giáo trình, Big Test, hoàn thành công
 *    việc, báo cáo trực lớp) — route duyệt / từ chối gắn `can:`.ABILITY, màn hiển thị nút theo ability này.
 */
final class AdminOnlyApprovals
{
    public const ABILITY = 'approve-as-admin';

    /** @var list<string> */
    public const PERMISSIONS = [
        'tuition.approve',
        'tuition.reject',
        'invoice.approve_cancel',
        'refund_transfer.approve',
        'refund_transfer.approve_refund',
        'refund_transfer.approve_transfer',
        'refund_transfer.reject',
        'staff_checkin.approve',
        'lead.approve_transfer',
    ];

    public static function allows(?User $user): bool
    {
        return $user !== null && $user->hasRole(Roles::ADMIN);
    }

    public static function covers(string $permission): bool
    {
        return in_array($permission, self::PERMISSIONS, true);
    }
}
