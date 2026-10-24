<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Quyền Ngân hàng câu hỏi & Luyện tập (docs/luyen-tap-ngan-hang-cau-hoi.md §4). */
class QuestionBankPermissionTest extends TestCase
{
    use RefreshDatabase;

    private const QUESTION_BANK = ['question_bank.view', 'question_bank.manage'];

    private function role(string $name): Role
    {
        return Role::query()->where('name', $name)->where('guard_name', 'web')->firstOrFail();
    }

    public function test_academic_lead_has_all_three_permissions(): void
    {
        $role = $this->role(Roles::ACADEMIC_LEAD);

        foreach ([...self::QUESTION_BANK, 'practice.view_results'] as $name) {
            $this->assertTrue($role->hasPermissionTo($name), $name);
        }
    }

    public function test_academic_staff_and_manager_have_no_question_bank_permissions(): void
    {
        foreach ([Roles::ACADEMIC_STAFF, Roles::MANAGER] as $roleName) {
            foreach (self::QUESTION_BANK as $name) {
                $this->assertFalse($this->role($roleName)->hasPermissionTo($name), "$roleName $name");
            }
        }
    }

    public function test_teachers_only_view_practice_results(): void
    {
        foreach (Roles::TEACHERS as $roleName) {
            $role = $this->role($roleName);
            $this->assertTrue($role->hasPermissionTo('practice.view_results'), $roleName);
            foreach (self::QUESTION_BANK as $name) {
                $this->assertFalse($role->hasPermissionTo($name), "$roleName $name");
            }
        }
    }

    public function test_admin_can_manage_question_bank_via_gate(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);

        $this->assertTrue($admin->can('question_bank.manage'));
        $this->assertTrue($admin->can('question_bank.view'));
        $this->assertTrue($admin->can('practice.view_results'));
    }

    public function test_grant_migration_down_removes_the_permissions(): void
    {
        $migration = require database_path('migrations/2026_11_21_090100_grant_question_bank_and_practice_permissions.php');

        $migration->down();
        foreach ([...self::QUESTION_BANK, 'practice.view_results'] as $name) {
            $this->assertNull(Permission::query()->where('name', $name)->first(), $name);
        }
    }

    public function test_grant_migration_up_restores_grants(): void
    {
        $migration = require database_path('migrations/2026_11_21_090100_grant_question_bank_and_practice_permissions.php');
        $migration->down();
        $migration->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertTrue($this->role(Roles::ACADEMIC_LEAD)->hasPermissionTo('question_bank.manage'));
        $this->assertTrue($this->role(Roles::TEACHER_PARTTIME)->hasPermissionTo('practice.view_results'));
        $this->assertFalse($this->role(Roles::TEACHER_PARTTIME)->hasPermissionTo('question_bank.view'));
    }
}
