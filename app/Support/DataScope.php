<?php

namespace App\Support;

use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Phạm vi dữ liệu theo module (docs/rbac.md, mục "Phạm vi dữ liệu").
 *
 * Mức: own (của tôi) < branch (chi nhánh chính + chi nhánh được cấp thêm của tôi) < all (toàn hệ thống).
 * Lưu bằng permission "<module>.scope_<level>" nên cấu hình được theo vai trò (màn Vai trò) và theo người (Phân quyền
 * cá nhân, cho phép / chặn). Mức cao nhất được cấp thắng; không được cấp mức nào → mức thấp nhất module hỗ trợ.
 * Super Admin luôn "all" (Gate::before). "Của tôi" nghĩa là gì do từng module định nghĩa (closure $own).
 */
final class DataScope
{
    public const OWN = 'own';

    public const BRANCH = 'branch';

    public const ALL = 'all';

    /** Mức phạm vi của người dùng trên module; null user → own. */
    public static function level(?User $user, string $module): string
    {
        $levels = PermissionCatalog::scopeLevels($module);
        if ($levels === []) {
            throw new \InvalidArgumentException("Module [{$module}] không khai báo phạm vi dữ liệu trong permission_catalog.");
        }
        if (! $user) {
            return $levels[0];
        }

        foreach (array_reverse($levels) as $level) {
            if ($level !== $levels[0] && $user->can(PermissionCatalog::scopePermission($module, $level))) {
                return $level;
            }
        }

        return $levels[0];
    }

    public static function isAll(?User $user, string $module): bool
    {
        return self::level($user, $module) === self::ALL;
    }

    /**
     * Chi nhánh giới hạn dữ liệu của người dùng trên module: null = không giới hạn (all), mảng id = mức branch
     * (có thể rỗng khi chưa gán chi nhánh). Mức own trả về chi nhánh của người dùng (dùng cho kiểm tra chi nhánh
     * khi tạo mới).
     *
     * @return list<int>|null
     */
    public static function branchIds(?User $user, string $module): ?array
    {
        if (! $user || self::level($user, $module) === self::ALL) {
            return $user ? null : [];
        }

        return $user->branchIds();
    }

    /** Có module nào người dùng đang ở mức "Chi nhánh" (dữ liệu phụ thuộc việc gán chi nhánh) không. */
    public static function dependsOnBranch(?User $user): bool
    {
        if (! $user) {
            return false;
        }
        foreach (array_keys(PermissionCatalog::scopedModules()) as $module) {
            if (self::level($user, $module) === self::BRANCH) {
                return true;
            }
        }

        return false;
    }

    /** Người dùng có thấy dữ liệu thuộc chi nhánh này không (null = dữ liệu chưa gắn chi nhánh → chỉ mức all). */
    public static function coversBranch(?User $user, string $module, ?int $branchId): bool
    {
        $ids = self::branchIds($user, $module);

        return $ids === null || ($branchId !== null && in_array($branchId, $ids, true));
    }

    /**
     * Lọc truy vấn theo phạm vi.
     *
     * @param  Closure(Builder): mixed|null  $own  điều kiện "của tôi" (null = mức own không thấy gì)
     * @param  Closure(Builder, list<int>): mixed|null  $branch  điều kiện "thuộc các chi nhánh" (null = dùng $own)
     * @param  bool  $branchIncludesOwn  mức branch thấy thêm dữ liệu "của tôi" ngoài chi nhánh
     */
    public static function apply(Builder|Relation $query, ?User $user, string $module, ?Closure $own, ?Closure $branch = null, bool $branchIncludesOwn = false): Builder|Relation
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        $level = self::level($user, $module);
        if ($level === self::ALL) {
            return $query;
        }

        if ($level === self::BRANCH && $branch !== null) {
            $ids = $user->branchIds();
            if ($ids === []) {
                return $branchIncludesOwn && $own ? $query->where(fn (Builder $q) => $own($q)) : $query->whereRaw('1 = 0');
            }

            return $query->where(function (Builder $q) use ($branch, $ids, $own, $branchIncludesOwn) {
                $q->where(fn (Builder $b) => $branch($b, $ids));
                if ($branchIncludesOwn && $own) {
                    $q->orWhere(fn (Builder $o) => $own($o));
                }
            });
        }

        return $own ? $query->where(fn (Builder $q) => $own($q)) : $query->whereRaw('1 = 0');
    }
}
