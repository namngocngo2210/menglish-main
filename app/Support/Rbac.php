<?php

namespace App\Support;

use App\Models\User;
use App\Models\UserPermissionOverride;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Tiện ích RBAC dùng chung (xem docs/rbac.md):
 *  - Super Admin (vai trò `admin`): luôn có mọi quyền thao tác (Gate::before), vai trò bất biến, chỉ Super Admin gán
 *    được vai trò này.
 *  - Quyền gán vai trò theo phân cấp: "user.assign_role.<vai trò>" (thay cho danh sách vai trò cứng trong code).
 *  - Truy vấn "người dùng có quyền X" (danh sách người phụ trách, người chấm, giáo viên…) có tính phân quyền cá nhân.
 */
final class Rbac
{
    /** Vai trò Super Admin (bất biến, luôn toàn quyền). */
    public const SUPER_ADMIN = 'admin';

    public const ASSIGN_ROLE_PREFIX = 'user.assign_role.';

    public static function assignRolePermission(string $role): string
    {
        return self::ASSIGN_ROLE_PREFIX.$role;
    }

    /** Xóa cache quyền của Spatie + nhãn vai trò trong request. */
    public static function flushCache(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        if (app()->bound('rbac.role_labels')) {
            app()->forgetInstance('rbac.role_labels');
        }
    }

    /**
     * Lọc người dùng đang có quyền $permission (qua vai trò, quyền trực tiếp hoặc phân quyền cá nhân "Toàn hệ thống";
     * override "chặn" thắng quyền theo vai trò). Super Admin được tính với quyền thao tác, không tính với quyền đối tượng.
     */
    public static function scopeUsersWithPermission(Builder $query, string $permission): Builder
    {
        [$module, $action] = array_pad(explode('.', $permission, 2), 2, '');
        $override = fn (bool $allow) => fn (Builder $o) => $o->where('module', $module)->where('action', $action)
            ->where('scope_type', UserPermissionOverride::SCOPE_ALL)->where('allow', $allow);

        return $query->where(function (Builder $q) use ($permission, $override) {
            $q->where(fn (Builder $granted) => $granted
                ->where(fn (Builder $via) => $via
                    ->whereHas('roles.permissions', fn (Builder $p) => $p->where('name', $permission))
                    ->orWhereHas('permissions', fn (Builder $p) => $p->where('name', $permission)))
                ->whereDoesntHave('permissionOverrides', $override(false)))
                ->orWhereHas('permissionOverrides', $override(true));

            if (! PermissionCatalog::isAudience($permission)) {
                $q->orWhereHas('roles', fn (Builder $r) => $r->where('name', self::SUPER_ADMIN));
            }
        });
    }

    /**
     * Vai trò người dùng được tạo / gán cho người khác. Super Admin: mọi vai trò; người khác: vai trò có quyền
     * "user.assign_role.<vai trò>" (không bao giờ gồm Super Admin).
     *
     * @return list<string>
     */
    public static function assignableRoles(?User $actor): array
    {
        if (! $actor) {
            return [];
        }

        return Role::query()->where('guard_name', 'web')->orderBy('name')->pluck('name')
            ->filter(fn (string $role) => self::canAssignRole($actor, $role))
            ->values()->all();
    }

    public static function canAssignRole(?User $actor, string $role): bool
    {
        if (! $actor) {
            return false;
        }
        if ($role === self::SUPER_ADMIN) {
            return $actor->isSuperAdmin();
        }

        return $actor->can(self::assignRolePermission($role));
    }

    /**
     * Vai trò mới: tạo quyền "user.assign_role.<vai trò>" và cấp cho các vai trò đang gán được MỌI vai trò khác
     * (trừ Super Admin) — giữ đúng phân cấp cũ "Quản lý cơ sở gán được mọi vai trò trừ Admin".
     */
    public static function registerRole(Role $role): void
    {
        if ($role->name === self::SUPER_ADMIN) {
            return;
        }

        $others = Role::query()->where('guard_name', 'web')
            ->whereNotIn('name', [self::SUPER_ADMIN, $role->name])
            ->pluck('name')
            ->map(fn (string $name) => self::assignRolePermission($name))
            ->all();

        $permission = Permission::findOrCreate(self::assignRolePermission($role->name), 'web');

        if ($others !== []) {
            Role::query()->where('guard_name', 'web')->where('name', '!=', self::SUPER_ADMIN)->with('permissions')->get()
                ->filter(fn (Role $candidate) => $candidate->id !== $role->id
                    && collect($others)->every(fn (string $name) => $candidate->permissions->contains('name', $name)))
                ->each(fn (Role $candidate) => $candidate->givePermissionTo($permission));
        }

        self::superAdminRole()?->givePermissionTo($permission);
        self::flushCache();
    }

    public static function renameRole(string $old, string $new): void
    {
        Permission::query()->where('guard_name', 'web')->where('name', self::assignRolePermission($old))
            ->update(['name' => self::assignRolePermission($new)]);
        UserPermissionOverride::query()->where('module', 'user')->where('action', 'assign_role.'.$old)
            ->update(['action' => 'assign_role.'.$new]);
        self::flushCache();
    }

    public static function forgetRole(string $name): void
    {
        Permission::query()->where('guard_name', 'web')->where('name', self::assignRolePermission($name))->delete();
        UserPermissionOverride::query()->where('module', 'user')->where('action', 'assign_role.'.$name)->delete();
        self::flushCache();
    }

    /**
     * Quyền quản trị phân quyền: người đang có không được tự làm mất (đổi vai trò của chính mình, sửa quyền của vai trò
     * mình đang giữ) — tránh tự khóa mình khỏi màn phân quyền.
     */
    public const ACCESS_MANAGEMENT = ['role.assign_permission', 'permission.override', 'user.assign_role'];

    /**
     * Chặn thay đổi làm $user mất quyền quản trị phân quyền đang có.
     *
     * @param  list<string>  $roleNames  tập vai trò của $user sau thay đổi
     * @param  array<string, list<string>>  $rolePermissionChanges  vai trò => tập quyền mới (khi sửa quyền vai trò)
     */
    public static function ensureKeepsAccessManagement(User $user, array $roleNames, array $rolePermissionChanges = []): void
    {
        $lost = self::lostAccessManagement($user, $roleNames, $rolePermissionChanges);
        if ($lost !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'roles' => 'Không thể tự làm mất quyền quản trị phân quyền của chính mình ('
                    .collect($lost)->map(fn (string $p) => PermissionCatalog::label($p))->implode(', ')
                    .'). Nhờ một quản trị viên khác thực hiện.',
            ]);
        }
    }

    /**
     * @param  list<string>  $roleNames
     * @param  array<string, list<string>>  $rolePermissionChanges
     * @return list<string>
     */
    public static function lostAccessManagement(User $user, array $roleNames, array $rolePermissionChanges = []): array
    {
        $had = collect(self::ACCESS_MANAGEMENT)->filter(fn (string $p) => $user->can($p))->values();
        if ($had->isEmpty()) {
            return [];
        }
        if ($user->isSuperAdmin()) {
            // Super Admin giữ mọi quyền qua Gate::before — chỉ mất khi tự bỏ vai trò Super Admin.
            return in_array(self::SUPER_ADMIN, $roleNames, true) ? [] : $had->all();
        }

        $rolePermissions = Role::query()->where('guard_name', 'web')->whereIn('name', $roleNames)->with('permissions')->get()
            ->flatMap(fn (Role $role) => $rolePermissionChanges[$role->name] ?? $role->permissions->pluck('name')->all());
        $direct = $user->getDirectPermissions()->pluck('name');
        $overrides = $user->permissionOverrides()->where('scope_type', UserPermissionOverride::SCOPE_ALL)->get();

        return $had->reject(function (string $permission) use ($rolePermissions, $direct, $overrides) {
            [$module, $action] = explode('.', $permission, 2);
            $override = $overrides->first(fn (UserPermissionOverride $o) => $o->module === $module && $o->action === $action);

            return $override ? (bool) $override->allow : ($rolePermissions->contains($permission) || $direct->contains($permission));
        })->values()->all();
    }

    public static function superAdminRole(): ?Role
    {
        return Role::query()->where('guard_name', 'web')->where('name', self::SUPER_ADMIN)->first();
    }

    /**
     * Quyền mà vai trò Super Admin luôn giữ (mọi quyền thao tác + phạm vi; không gồm quyền đối tượng).
     *
     * @return list<string>
     */
    public static function superAdminPermissions(): array
    {
        return Permission::query()->where('guard_name', 'web')->pluck('name')
            ->reject(fn (string $name) => PermissionCatalog::isAudience($name))
            ->values()->all();
    }
}
