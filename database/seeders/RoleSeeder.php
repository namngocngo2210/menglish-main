<?php

namespace Database\Seeders;

use App\Support\PermissionCatalog;
use App\Support\Rbac;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * Tạo vai trò mặc định (config/access.php) — CHỈ THÊM, an toàn chạy lại kể cả trên production:
     *  - Vai trò chưa có → tạo và gán tập quyền mặc định.
     *  - Vai trò đã có → KHÔNG đụng tới (giữ nguyên cấu hình Admin đã chỉnh ở màn Vai trò / Phân quyền cá nhân).
     *    Quyền mới cho hệ thống đang chạy được cấp bằng migration.
     *  - Super Admin (bất biến): luôn được bổ sung mọi quyền thao tác + phạm vi còn thiếu (không bao giờ thu hồi).
     */
    public function run(): void
    {
        $allPermissionNames = Permission::query()->where('guard_name', 'web')->pluck('name');

        foreach (config('access.roles', []) as $roleName => $patterns) {
            $existing = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();

            if ($existing && $roleName !== Rbac::SUPER_ADMIN) {
                continue;
            }

            $role = $existing ?? Role::create(['name' => $roleName, 'guard_name' => 'web']);
            $resolved = self::resolvePatterns($patterns, $allPermissionNames);

            if ($existing) {
                $missing = $resolved->diff($role->permissions()->pluck('name'));
                if ($missing->isNotEmpty()) {
                    $role->givePermissionTo($missing->all());
                }
            } else {
                $role->syncPermissions($resolved->all());
            }
        }

        Rbac::flushCache();
    }

    /**
     * @param  list<string>  $patterns
     * @param  Collection<int, string>  $all
     * @return Collection<int, string>
     */
    public static function resolvePatterns(array $patterns, Collection $all): Collection
    {
        $actions = $all->filter(fn (string $name) => in_array(PermissionCatalog::kind($name), [PermissionCatalog::KIND_ACTION, null], true));

        return collect($patterns)->flatMap(function (string $pattern) use ($all, $actions) {
            if ($pattern === '*') {
                // Super Admin: mọi quyền thao tác + phạm vi + gán vai trò (không gồm quyền đối tượng).
                return $all->reject(fn (string $name) => PermissionCatalog::isAudience($name));
            }

            if ($pattern === Rbac::ASSIGN_ROLE_PREFIX.'*') {
                return $all->filter(fn (string $name) => str_starts_with($name, Rbac::ASSIGN_ROLE_PREFIX)
                    && $name !== Rbac::assignRolePermission(Rbac::SUPER_ADMIN));
            }

            if (str_ends_with($pattern, '.*')) {
                $module = substr($pattern, 0, -2);

                return $actions->filter(fn (string $name) => PermissionCatalog::moduleOf($name) === $module
                    && ! str_starts_with($name, Rbac::ASSIGN_ROLE_PREFIX));
            }

            return $all->contains($pattern) ? [$pattern] : [];
        })->unique()->values();
    }
}
