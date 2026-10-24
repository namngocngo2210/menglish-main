<?php

use App\Support\Roles;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Ngân hàng câu hỏi & Luyện tập (docs/luyen-tap-ngan-hang-cau-hoi.md §4): cấp quyền cho vai trò đang có.
 * Học thuật: quản lý ngân hàng + xem kết quả; giáo viên: xem kết quả luyện tập; Admin qua Gate::before.
 */
return new class extends Migration
{
    private const ROLES_BY_PERMISSION = [
        'question_bank.view' => [Roles::ACADEMIC_LEAD],
        'question_bank.manage' => [Roles::ACADEMIC_LEAD],
        'practice.view_results' => [Roles::ACADEMIC_LEAD, Roles::TEACHER_FULLTIME, Roles::TEACHER_PARTTIME],
    ];

    public function up(): void
    {
        foreach (self::ROLES_BY_PERMISSION as $name => $roleNames) {
            $permission = Permission::findOrCreate($name, 'web');
            foreach ($roleNames as $roleName) {
                $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
                if ($role && ! $role->hasPermissionTo($permission)) {
                    $role->givePermissionTo($permission);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        foreach (self::ROLES_BY_PERMISSION as $name => $roleNames) {
            foreach ($roleNames as $roleName) {
                Role::query()->where('name', $roleName)->where('guard_name', 'web')->first()?->revokePermissionTo($name);
            }
            Permission::query()->where('name', $name)->where('guard_name', 'web')->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
