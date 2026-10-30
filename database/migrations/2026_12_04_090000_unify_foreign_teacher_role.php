<?php

use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Support\Rbac;
use App\Support\Roles;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/** Chuẩn hóa mã GVNN; giữ quyền Admin đã chỉnh, chỉ bổ sung mặc định khi vai trò chưa có quyền. */
return new class extends Migration
{
    private const LEGACY = 'foreigner_teacher';

    public function up(): void
    {
        // Cài mới để PermissionSeeder / RoleSeeder nạp đầy đủ danh mục trước khi tạo vai trò.
        if (! Role::query()->where('guard_name', 'web')->exists()) {
            return;
        }

        Rbac::flushCache();
        DB::transaction(function (): void {
            $legacy = Role::query()->where('guard_name', 'web')->where('name', self::LEGACY)->first();
            $target = Role::query()->where('guard_name', 'web')->where('name', Roles::FOREIGN_TEACHER)->first();

            if ($legacy && ! $target) {
                $legacy->name = Roles::FOREIGN_TEACHER;
                $legacy->save();
                $target = $legacy;
                $this->renameAssignmentPermission();
                $this->reconcileAssignmentOverrides();
                Rbac::renameRole(self::LEGACY, Roles::FOREIGN_TEACHER);
            } elseif ($legacy) {
                foreach (User::withTrashed()->role(self::LEGACY)->get() as $user) {
                    $user->assignRole($target);
                    $user->removeRole($legacy);
                }
                $legacy->delete();
                Rbac::forgetRole(self::LEGACY);
            }

            $target ??= Role::create(['name' => Roles::FOREIGN_TEACHER, 'guard_name' => 'web']);
            if (! $target->permissions()->exists()) {
                $all = Permission::query()->where('guard_name', 'web')->pluck('name');
                $defaults = RoleSeeder::resolvePatterns((array) config('access.roles.'.Roles::FOREIGN_TEACHER, []), $all, Roles::FOREIGN_TEACHER);
                $target->givePermissionTo($defaults->all());
            }
            if (Schema::hasColumn('roles', 'label') && $target->label === null) {
                $target->forceFill(['label' => Roles::LABELS[Roles::FOREIGN_TEACHER]])->save();
            }

            Rbac::registerRole($target);
            $permission = Permission::findOrCreate(Rbac::assignRolePermission(Roles::FOREIGN_TEACHER), 'web');
            Role::query()->where('guard_name', 'web')
                ->whereHas('permissions', fn ($query) => $query->where('name', Rbac::assignRolePermission(Roles::TEACHER_PARTTIME)))
                ->get()->each(fn (Role $role) => $role->givePermissionTo($permission));
        });
        Rbac::flushCache();
    }

    /** Giữ ghi đè mã chuẩn khi trùng người / phạm vi; tránh mất lệnh chặn và trùng khóa khi đổi mã. */
    private function reconcileAssignmentOverrides(): void
    {
        $action = 'assign_role.'.Roles::FOREIGN_TEACHER;
        $overrides = UserPermissionOverride::query()->where('module', 'user')->where('action', 'assign_role.'.self::LEGACY)->get();
        foreach ($overrides as $override) {
            $duplicate = UserPermissionOverride::query()->where('user_id', $override->user_id)->where('module', 'user')
                ->where('action', $action)->where('scope_type', $override->scope_type)->where('scope_id', $override->scope_id)->exists();
            if ($duplicate) {
                $override->delete();

                continue;
            }
            $override->update(['action' => $action]);
        }
    }

    /** Quyền đích có thể đã được seeder tạo: gộp các gán quyền vào ID cũ trước khi đổi tên. */
    private function renameAssignmentPermission(): void
    {
        $old = Permission::query()->where('guard_name', 'web')->where('name', Rbac::assignRolePermission(self::LEGACY))->first();
        $new = Permission::query()->where('guard_name', 'web')->where('name', Rbac::assignRolePermission(Roles::FOREIGN_TEACHER))->first();
        if (! $old || ! $new) {
            return;
        }

        $column = config('permission.column_names.permission_pivot_key') ?: 'permission_id';
        foreach (['role_has_permissions', 'model_has_permissions'] as $pivot) {
            $table = config('permission.table_names.'.$pivot, $pivot);
            foreach (DB::table($table)->where($column, $new->id)->get() as $row) {
                DB::table($table)->insertOrIgnore(array_replace((array) $row, [$column => $old->id]));
            }
        }
        $new->delete();
    }

    public function down(): void
    {
        // Không hoàn tác: không thể xác định người và quyền nào vốn thuộc mã GVNN cũ.
    }
};
