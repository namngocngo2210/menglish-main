<?php

use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Support\Rbac;
use App\Support\Roles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Biên bản vi phạm có phạm vi dữ liệu "violation.scope_*" (trước đây ai có violation.view thấy, chốt, hủy… biên bản của
 * mọi chi nhánh). Mặc định: Quản lý cơ sở, Học vụ → Chi nhánh; Học thuật → Toàn hệ thống (chốt lỗi chuyên môn mọi chi
 * nhánh). Vai trò tự tạo và người được cấp riêng violation.view (quyền trực tiếp / Phân quyền cá nhân) → Toàn hệ thống
 * để giữ hành vi cũ. Người không có violation.view vẫn chỉ xem biên bản của mình.
 *
 * Cài mới (chưa có vai trò nào): PermissionSeeder / RoleSeeder tạo theo danh mục và config/access.php. Chạy lại không cấp trùng.
 */
return new class extends Migration
{
    private const LEVELS = ['own', 'branch', 'all'];

    private const DEFAULTS = [
        Roles::MANAGER => 'branch',
        Roles::ACADEMIC_STAFF => 'branch',
        Roles::ACADEMIC_LEAD => 'all',
    ];

    public function up(): void
    {
        Rbac::flushCache();
        if (! Role::query()->where('guard_name', 'web')->exists()) {
            return;
        }

        $scopes = collect(self::LEVELS)->mapWithKeys(fn (string $level) => [$level => Permission::findOrCreate("violation.scope_{$level}", 'web')]);
        $view = Permission::query()->where('guard_name', 'web')->where('name', 'violation.view')->first();

        Role::query()->where('guard_name', 'web')->where('name', '!=', Rbac::SUPER_ADMIN)->with('permissions')->get()
            ->each(function (Role $role) use ($scopes, $view) {
                if ($role->permissions->contains(fn (Permission $p) => str_starts_with($p->name, 'violation.scope_'))) {
                    return; // Đã cấu hình phạm vi biên bản.
                }
                $level = self::DEFAULTS[$role->name]
                    ?? ($view && $role->permissions->contains('id', $view->id) ? 'all' : null);
                if ($level) {
                    $role->givePermissionTo($scopes[$level]);
                }
            });

        Rbac::flushCache();
        $this->keepPersonalViewers($view);
        Rbac::flushCache();
    }

    public function down(): void
    {
        $names = array_map(fn (string $level) => "violation.scope_{$level}", self::LEVELS);
        Permission::query()->where('guard_name', 'web')->whereIn('name', $names)->delete();
        if (Schema::hasTable('user_permission_overrides')) {
            UserPermissionOverride::query()->where('module', 'violation')->whereIn('action', ['scope_own', 'scope_branch', 'scope_all'])->delete();
        }

        Rbac::flushCache();
    }

    /** Người có violation.view nhờ quyền trực tiếp / Phân quyền cá nhân (không qua vai trò có phạm vi) → Toàn hệ thống. */
    private function keepPersonalViewers(?Permission $view): void
    {
        $userIds = collect();
        if ($view) {
            $userIds = $userIds->merge(DB::table(config('permission.table_names.model_has_permissions', 'model_has_permissions'))
                ->where('permission_id', $view->id)->where('model_type', (new User)->getMorphClass())->pluck('model_id'));
        }
        if (Schema::hasTable('user_permission_overrides')) {
            $userIds = $userIds->merge(UserPermissionOverride::query()->where('module', 'violation')->where('action', 'view')
                ->where('scope_type', UserPermissionOverride::SCOPE_ALL)->where('allow', true)->pluck('user_id'));
        }

        foreach ($userIds->unique() as $userId) {
            $user = User::query()->find($userId);
            if (! $user || $user->hasRole(Rbac::SUPER_ADMIN)) {
                continue;
            }
            $hasScope = collect(['violation.scope_branch', 'violation.scope_all'])
                ->contains(fn (string $scope) => Rbac::scopeUsersWithPermission(User::query()->whereKey($user->id), $scope)->exists());
            if ($hasScope) {
                continue;
            }
            UserPermissionOverride::query()->firstOrCreate(
                ['user_id' => $user->id, 'module' => 'violation', 'action' => 'scope_all', 'scope_type' => UserPermissionOverride::SCOPE_ALL, 'scope_id' => null],
                ['allow' => true],
            );
        }
    }
};
