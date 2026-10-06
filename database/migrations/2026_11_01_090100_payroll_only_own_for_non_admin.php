<?php

use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Support\Rbac;
use App\Support\Roles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Bảng lương (06/10/2026): ngoài Admin, mọi người chỉ xem phiếu lương của chính mình ("Lương của tôi").
 * Trước đây Quản lý cơ sở có payroll.view + payroll.scope_all nên mở "Bảng lương theo kỳ" thấy phiếu lương của mọi nhân sự.
 *
 * Thu hồi payroll.view (màn kỳ lương, xuất file, lương cơ bản / CCCD trên hồ sơ nhân sự) và phạm vi chi nhánh / toàn hệ thống
 * khỏi mọi vai trò khác Admin, khỏi quyền cấp trực tiếp và Phân quyền cá nhân; ai mất payroll.view được cấp "Lương của tôi".
 */
return new class extends Migration
{
    private const REVOKED = ['payroll.view', 'payroll.scope_branch', 'payroll.scope_all'];

    private const OWN = ['payroll.view_own', 'payroll.scope_own'];

    public function up(): void
    {
        if (! Role::query()->where('guard_name', 'web')->exists()) {
            return; // Cài mới: RoleSeeder cấp theo config/access.php.
        }

        $revoked = Permission::query()->where('guard_name', 'web')->whereIn('name', self::REVOKED)->get();
        $own = collect(self::OWN)->map(fn (string $name) => Permission::findOrCreate($name, 'web'));
        $view = $revoked->firstWhere('name', 'payroll.view');

        Role::query()->where('guard_name', 'web')->where('name', '!=', Rbac::SUPER_ADMIN)->get()
            ->each(function (Role $role) use ($revoked, $own, $view) {
                if ($view && $role->hasPermissionTo($view)) {
                    $role->givePermissionTo($own);
                }
                $role->revokePermissionTo($revoked->filter(fn (Permission $p) => $role->hasPermissionTo($p))->all());
            });

        foreach ($revoked as $permission) {
            $permission->users()->get()
                ->reject(fn (User $user) => $user->hasRole(Rbac::SUPER_ADMIN))
                ->each(fn (User $user) => $user->revokePermissionTo($permission));
        }

        if (Schema::hasTable('user_permission_overrides')) {
            UserPermissionOverride::query()->where('module', 'payroll')->where('allow', true)
                ->whereIn('action', ['view', 'scope_branch', 'scope_all'])
                ->whereDoesntHave('user', fn ($q) => $q->role(Rbac::SUPER_ADMIN))
                ->delete();
        }

        Rbac::flushCache();
    }

    public function down(): void
    {
        $manager = Role::query()->where('guard_name', 'web')->where('name', Roles::MANAGER)->first();
        if ($manager) {
            $manager->revokePermissionTo(Permission::findOrCreate('payroll.scope_own', 'web'));
            $manager->givePermissionTo([Permission::findOrCreate('payroll.view', 'web'), Permission::findOrCreate('payroll.scope_all', 'web')]);
        }

        Rbac::flushCache();
    }
};
