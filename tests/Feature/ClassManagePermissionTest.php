<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\Course;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** TK-2026-0148: only Học vụ (academic_staff) + Admin create/edit classes; Học thuật, GV, GVNN are view-only. */
class ClassManagePermissionTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private ClassModel $class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->branch = Branch::create(['name' => 'Đội Cấn', 'code' => 'DC', 'is_active' => true]);
        $course = Course::create(['name' => 'Kids', 'code' => 'KID', 'is_active' => true]);
        $this->class = ClassModel::create([
            'code' => 'K1', 'name' => 'Kids 1', 'branch_id' => $this->branch->id, 'course_id' => $course->id,
            'status' => 'active', 'max_students' => 10,
        ]);
    }

    private function as(string $role): User
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    /** @return array<string, array{0: string}> */
    public static function deniedRoles(): array
    {
        return ['academic_lead' => ['academic_lead'], 'teacher_fulltime' => ['teacher_fulltime'], 'foreign_teacher' => ['foreign_teacher']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('deniedRoles')]
    public function test_view_only_roles_get_403_on_every_class_mutation_route(string $role): void
    {
        $user = $this->as($role);
        $id = $this->class->id;

        $this->actingAs($user)->get(route('classes.create'))->assertForbidden();
        $this->actingAs($user)->post(route('classes.store'), [])->assertForbidden();
        $this->actingAs($user)->get(route('classes.edit', $id))->assertForbidden();
        $this->actingAs($user)->put(route('classes.update', $id), [])->assertForbidden();
        $this->actingAs($user)->post(route('classes.foreign-teacher', $id), [])->assertForbidden();
        $this->actingAs($user)->delete(route('classes.destroy', $id))->assertForbidden();
        $this->actingAs($user)->postJson(route('classes.check-availability'), [
            'branch_id' => $this->branch->id, 'date' => '2026-12-20', 'start_time' => '18:00', 'end_time' => '19:00',
        ])->assertForbidden();
    }

    public function test_academic_lead_loses_create_edit_delete_but_keeps_view_and_dashboards(): void
    {
        $lead = $this->as('academic_lead');

        $this->assertTrue($lead->can('class.view'));
        $this->assertTrue($lead->can('class.update')); // still gates academic dashboards / reports
        $this->assertFalse($lead->can('class.create'));
        $this->assertFalse($lead->can('class.edit'));
        $this->assertFalse($lead->can('class.delete'));
        $this->actingAs($lead)->get(route('classes.index'))->assertOk();
        $this->actingAs($lead)->get(route('academic.reports'))->assertOk();
    }

    public function test_academic_lead_ui_props_hide_create_and_edit(): void
    {
        $lead = $this->as('academic_lead');

        $this->actingAs($lead)->get(route('classes.show', $this->class->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canManage', false));
        $this->actingAs($lead)->get(route('classes.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->missing('can.class.create')->missing('can.class.edit'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('allowedRoles')]
    public function test_hoc_vu_and_admin_can_create_edit_and_see_manage_props(string $role): void
    {
        $user = $this->as($role);

        $this->actingAs($user)->get(route('classes.create'))->assertOk();
        $this->actingAs($user)->get(route('classes.edit', $this->class->id))->assertOk();
        $this->actingAs($user)->get(route('classes.show', $this->class->id))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canManage', true));
    }

    /** @return array<string, array{0: string}> */
    public static function allowedRoles(): array
    {
        return ['academic_staff' => ['academic_staff'], 'admin' => ['admin']];
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function scheduleConfigRoles(): array
    {
        return [
            'academic_lead' => ['academic_lead', false],
            'teacher_fulltime' => ['teacher_fulltime', false],
            'academic_staff' => ['academic_staff', true],
            'admin' => ['admin', true],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('scheduleConfigRoles')]
    public function test_schedule_config_update_requires_class_edit(string $role, bool $allowed): void
    {
        $user = $this->as($role);
        $payload = [
            'class_id' => $this->class->id,
            'start_date' => '2026-12-21', 'end_date' => '2026-12-28',
            'slot1_day' => 'Thứ 2', 'slot1_start' => '18:00', 'slot1_end' => '19:30',
        ];

        $response = $this->actingAs($user)->post(route('tasks.schedule-config.update'), $payload);

        if ($allowed) {
            $this->assertNotEquals(403, $response->getStatusCode());
        } else {
            $response->assertForbidden();
            $this->assertDatabaseCount('class_sessions', 0);
        }
    }
}
