<?php

use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Support\PermissionCatalog;
use App\Support\Rbac;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    /**
     * RBAC linh hoạt toàn hệ thống (docs/rbac.md).
     *
     * 1. Tạo các permission mới của danh mục (config/permission_catalog.php): năng lực thay cho kiểm tra cứng theo vai
     *    trò (lead.stage_forward / stage_back, staff_report.*, dashboard.*, activity_log.undo, violation.decide_*…),
     *    quyền đối tượng (portal.*, class.teach / assist, lead.be_assigned, entrance_test.examine), phạm vi dữ liệu
     *    "<module>.scope_<level>" và quyền gán vai trò "user.assign_role.<vai trò>".
     * 2. Cấp mặc định CHO QUYỀN MỚI theo config/access.php (vai trò hệ thống) — tái hiện đúng hành vi cũ; không đụng tới
     *    quyền Admin đã chỉnh trước đó.
     * 3. Thay tuition.all_branches / finance.all_branches bằng tuition.scope_all / finance.scope_all (chuyển cả gán theo
     *    vai trò lẫn phân quyền cá nhân). Người đang có tuition.all_branches cũng được attendance_staff.scope_all (trước
     *    đây quyền này quyết định lớp được chấm công tay).
     * 4. Vai trò tự tạo / quyền cá nhân: suy ra phạm vi dữ liệu từ quyền đang có như code cũ (vd. có class.update →
     *    thấy mọi lớp; có work_task.approve → mọi công việc) để không ai mất / thêm dữ liệu sau khi nâng cấp.
     *
     * Cài mới (chưa có vai trò nào): chỉ dọn quyền cũ — PermissionSeeder / RoleSeeder tạo toàn bộ. Chạy lại không tạo trùng.
     */
    private const RENAMED = [
        'tuition.all_branches' => 'tuition.scope_all',
        'finance.all_branches' => 'finance.scope_all',
    ];

    /** Phạm vi "Toàn hệ thống" mà code cũ suy ra từ quyền thao tác (áp dụng cho vai trò tự tạo & quyền cá nhân). */
    private const IMPLIED_ALL_SCOPE = [
        'class.scope_all' => ['class.create', 'class.update'],
        'attendance_staff.scope_all' => ['class.create', 'class.update'],
        'work_task.scope_all' => ['work_task.approve'],
        'support_ticket.scope_all' => ['support_ticket.update', 'support_ticket.assign', 'support_ticket.close'],
        'kpi.scope_all' => ['kpi.view'],
        'activity_log.scope_all' => ['activity_log.view'],
        'payroll.scope_all' => ['payroll.view'],
    ];

    /** Các suy luận trên bị giới hạn chi nhánh với Quản lý cơ sở trong code cũ (managedBranchIds). */
    private const MANAGER_LIMITED = ['class.scope_all', 'attendance_staff.scope_all', 'work_task.scope_all'];

    public function up(): void
    {
        Rbac::flushCache();
        $installed = Role::query()->where('guard_name', 'web')->exists();

        $created = [];
        if ($installed) {
            $roleNames = Role::query()->where('guard_name', 'web')->pluck('name');
            $existing = Permission::query()->where('guard_name', 'web')->pluck('name')->all();
            foreach (PermissionCatalog::allPermissions($roleNames) as $name) {
                if (! in_array($name, $existing, true)) {
                    Permission::findOrCreate($name, 'web');
                    $created[] = $name;
                }
            }
        }

        $this->replaceAllBranchesPermissions($installed);

        if (! $installed) {
            Rbac::flushCache();

            return;
        }

        // 2. Mặc định cho quyền mới — vai trò hệ thống theo config/access.php.
        $all = Permission::query()->where('guard_name', 'web')->pluck('name');
        foreach (config('access.roles', []) as $roleName => $patterns) {
            $role = Role::query()->where('guard_name', 'web')->where('name', $roleName)->first();
            if (! $role) {
                continue;
            }
            $grant = RoleSeeder::resolvePatterns($patterns, $all)->intersect($created)->values()->all();
            if ($grant !== []) {
                $role->givePermissionTo($grant);
            }
        }

        // 4a. Vai trò tự tạo (không có trong config): phạm vi như code cũ.
        $customRoles = Role::query()->where('guard_name', 'web')->whereNotIn('name', array_keys(config('access.roles', [])))->with('permissions')->get();
        foreach ($customRoles as $role) {
            foreach (self::IMPLIED_ALL_SCOPE as $scope => $triggers) {
                if (in_array($scope, $created, true) && $role->permissions->pluck('name')->intersect($triggers)->isNotEmpty()) {
                    $role->givePermissionTo($scope);
                }
            }
        }

        // 4b. Phân quyền cá nhân "cho phép" (override toàn hệ thống / quyền trực tiếp) từng mở rộng dữ liệu.
        $this->grantImpliedPersonalScopes($created);

        Rbac::flushCache();
    }

    public function down(): void
    {
        Rbac::flushCache();

        foreach (self::RENAMED as $old => $new) {
            $newPermission = Permission::query()->where('guard_name', 'web')->where('name', $new)->first();
            $oldPermission = Permission::findOrCreate($old, 'web');
            if ($newPermission) {
                foreach ($newPermission->roles()->get() as $role) {
                    $role->givePermissionTo($oldPermission);
                }
            }
            [$module, $action] = explode('.', $new, 2);
            [, $oldAction] = explode('.', $old, 2);
            UserPermissionOverride::query()->where('module', $module)->where('action', $action)->update(['action' => $oldAction]);
        }

        $keep = array_keys(self::RENAMED);
        $legacy = collect(config('access.roles', []))->keys();
        $new = collect(PermissionCatalog::allPermissions($legacy))
            ->filter(fn (string $name) => PermissionCatalog::isScope($name) || PermissionCatalog::isAudience($name)
                || str_starts_with($name, Rbac::ASSIGN_ROLE_PREFIX)
                || in_array($name, [
                    'lead.stage_forward', 'lead.stage_back', 'lead.trial_feedback', 'attendance_student.record_any', 'homework.grade',
                    'violation.decide_academic', 'violation.decide_operations', 'staff_report.submit', 'staff_report.view_all',
                    'notification.view_system', 'dashboard.operations', 'dashboard.academic', 'activity_log.undo',
                ], true))
            ->reject(fn (string $name) => in_array($name, $keep, true));

        Permission::query()->where('guard_name', 'web')->whereIn('name', $new->all())->delete();
        Permission::query()->where('guard_name', 'web')->where('name', 'like', Rbac::ASSIGN_ROLE_PREFIX.'%')->delete();

        Rbac::flushCache();
    }

    /** tuition.all_branches → tuition.scope_all, finance.all_branches → finance.scope_all (vai trò + cá nhân). */
    private function replaceAllBranchesPermissions(bool $installed): void
    {
        foreach (self::RENAMED as $old => $new) {
            $oldPermission = Permission::query()->where('guard_name', 'web')->where('name', $old)->first();
            if (! $oldPermission) {
                continue;
            }

            if ($installed) {
                $newPermission = Permission::findOrCreate($new, 'web');
                // Trước đây tuition.all_branches (theo vai trò hay trực tiếp) cũng quyết định lớp được chấm công tay.
                $grants = $old === 'tuition.all_branches'
                    ? [$newPermission, Permission::findOrCreate('attendance_staff.scope_all', 'web')]
                    : [$newPermission];
                foreach ($oldPermission->roles()->get() as $role) {
                    $role->givePermissionTo($grants);
                }
                foreach ($oldPermission->users()->get() as $user) {
                    $user->givePermissionTo($grants);
                }
            }

            [$module, $oldAction] = explode('.', $old, 2);
            [, $newAction] = explode('.', $new, 2);
            if (DB::getSchemaBuilder()->hasTable('user_permission_overrides')) {
                $overrides = UserPermissionOverride::query()->where('module', $module)->where('action', $oldAction)->get();
                foreach ($overrides as $override) {
                    $duplicate = UserPermissionOverride::query()->where('user_id', $override->user_id)->where('module', $module)
                        ->where('action', $newAction)->where('scope_type', $override->scope_type)->where('scope_id', $override->scope_id)->exists();
                    if ($duplicate) {
                        $override->delete();

                        continue;
                    }
                    // Kế toán tổng: trước đây tuition.all_branches cũng quyết định lớp được chấm công tay (mọi lớp).
                    if ($old === 'tuition.all_branches' && $override->allow && $installed) {
                        UserPermissionOverride::query()->firstOrCreate(
                            ['user_id' => $override->user_id, 'module' => 'attendance_staff', 'action' => 'scope_all', 'scope_type' => UserPermissionOverride::SCOPE_ALL, 'scope_id' => null],
                            ['allow' => true, 'created_by' => $override->created_by],
                        );
                    }
                    $override->update(['action' => $newAction]);
                }
            }

            $oldPermission->delete();
        }
    }

    /** @param  list<string>  $created */
    private function grantImpliedPersonalScopes(array $created): void
    {
        $overrides = UserPermissionOverride::query()->where('scope_type', UserPermissionOverride::SCOPE_ALL)->where('allow', true)->get()
            ->groupBy('user_id');
        $direct = DB::table(config('permission.table_names.model_has_permissions', 'model_has_permissions'))
            ->join(config('permission.table_names.permissions', 'permissions'), 'permissions.id', '=', 'model_has_permissions.permission_id')
            ->where('model_type', (new User)->getMorphClass())
            ->get(['model_id', 'permissions.name'])
            ->groupBy('model_id');

        $userIds = $overrides->keys()->merge($direct->keys())->unique();
        foreach ($userIds as $userId) {
            $user = User::withTrashed()->find($userId);
            if (! $user) {
                continue;
            }
            $granted = collect($overrides->get($userId, []))->map(fn ($o) => "{$o->module}.{$o->action}")
                ->merge(collect($direct->get($userId, []))->pluck('name'));
            $isManager = $user->roles()->where('name', 'manager')->exists();

            foreach (self::IMPLIED_ALL_SCOPE as $scope => $triggers) {
                if (! in_array($scope, $created, true) || $granted->intersect($triggers)->isEmpty()) {
                    continue;
                }
                if ($isManager && in_array($scope, self::MANAGER_LIMITED, true)) {
                    continue;
                }
                if (Rbac::scopeUsersWithPermission(User::query()->whereKey($user->id), $scope)->exists()) {
                    continue;
                }
                [$module, $action] = explode('.', $scope, 2);
                UserPermissionOverride::query()->firstOrCreate(
                    ['user_id' => $user->id, 'module' => $module, 'action' => $action, 'scope_type' => UserPermissionOverride::SCOPE_ALL, 'scope_id' => null],
                    ['allow' => true],
                );
            }
        }
    }
};
