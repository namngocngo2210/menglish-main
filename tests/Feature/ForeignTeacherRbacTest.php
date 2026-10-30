<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Models\User;
use App\Services\KpiBoardService;
use App\Support\DataScope;
use App\Support\Rbac;
use App\Support\Roles;
use App\Support\StaffType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ForeignTeacherRbacTest extends TestCase
{
    use RefreshDatabase;

    public function test_foreign_teacher_is_teaching_staff_with_monthly_reports_but_without_kpi(): void
    {
        $teacher = User::factory()->create(['branch_id' => null]);
        $teacher->assignRole(Roles::FOREIGN_TEACHER);

        $this->assertTrue(Roles::isFixed(Roles::FOREIGN_TEACHER));
        foreach ([Roles::TEACHERS, Roles::TEACHING, Roles::ACADEMIC, Roles::BRANCH_OPTIONAL] as $group) {
            $this->assertContains(Roles::FOREIGN_TEACHER, $group);
        }
        $this->assertNotContains(Roles::FOREIGN_TEACHER, Roles::KPI_ROLES);
        $this->assertNotContains(Roles::FOREIGN_TEACHER, KpiBoardService::ROLES);
        $this->assertFalse(app(KpiBoardService::class)->staffQuery()->whereKey($teacher->id)->exists());
        $this->assertTrue(StaffType::isForeignTeacher($teacher));
        $this->assertSame(route('teacher.home', absolute: false), AuthenticatedSessionController::homeUrl($teacher));
        $this->assertFalse(StaffType::isAssistantOnly($teacher));
        $this->assertSame('monthly', StaffType::reportCadence($teacher));
        $this->assertSame(['teacher_monthly'], StaffType::structuredReports($teacher));
        $teacher->assignRole(Roles::ASSISTANT);
        $this->assertFalse(StaffType::isAssistantOnly($teacher->fresh()));
    }

    public function test_default_permissions_match_parttime_teachers_with_own_data_scopes(): void
    {
        $foreign = Role::findByName(Roles::FOREIGN_TEACHER);
        $parttime = Role::findByName(Roles::TEACHER_PARTTIME);
        $this->assertEqualsCanonicalizing($parttime->permissions->pluck('name')->all(), $foreign->permissions->pluck('name')->all());
        $teacher = User::factory()->create();
        $teacher->assignRole($foreign);
        foreach (['portal.teacher', 'class.teach', 'payroll.view_own', 'staff_report.submit'] as $permission) {
            $this->assertTrue($teacher->can($permission));
        }
        foreach (['class', 'student', 'payroll', 'work_task', 'support_ticket'] as $module) {
            $this->assertSame(DataScope::OWN, DataScope::level($teacher, $module));
        }
        foreach (['payroll.view', 'payroll.approve', 'role.assign_permission', 'user.assign_role', 'class.update', 'question_bank.view', 'question_bank.manage'] as $permission) {
            $this->assertFalse($teacher->can($permission));
        }
        $this->assertSame([], Rbac::assignableRoles($teacher));
    }

    public function test_every_default_role_that_assigns_parttime_can_also_assign_foreign_teachers(): void
    {
        foreach (Roles::ALL as $role) {
            $actor = User::factory()->create();
            $actor->assignRole($role);
            $this->assertSame(Rbac::canAssignRole($actor, Roles::TEACHER_PARTTIME), Rbac::canAssignRole($actor, Roles::FOREIGN_TEACHER), $role);
        }
    }
}
