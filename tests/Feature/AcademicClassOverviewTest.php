<?php

namespace Tests\Feature;

use App\Models\AcademicObservation;
use App\Models\BigTest;
use App\Models\Branch;
use App\Models\ClassEnrollment;
use App\Models\ClassLessonProgress;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\SyllabusAssignment;
use App\Models\SyllabusCurriculum;
use App\Models\SyllabusLesson;
use App\Models\SyllabusStage;
use App\Models\SyllabusUnit;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Support\Navigation\SidebarMenu;
use App\Support\Roles;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AcademicClassOverviewTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $academic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::create(['name' => 'Cơ sở A', 'code' => 'OV-A', 'is_active' => true]);
        $this->academic = $this->user(Roles::ACADEMIC_LEAD);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function classroom(string $code, array $attributes = []): ClassModel
    {
        return ClassModel::create(array_merge([
            'code' => $code, 'name' => $code, 'branch_id' => $this->branch->id,
            'status' => 'active', 'program' => 'IELTS', 'level' => 'B1',
            'max_capacity' => 10, 'min_students' => 2,
        ], $attributes));
    }

    public function test_academic_lead_sees_read_only_blocks_and_enrollment_badges(): void
    {
        $full = $this->classroom('OV-1', ['max_capacity' => 1]);
        $this->classroom('OV-2', ['status' => 'pending_schedule']);
        $normal = $this->classroom('OV-3', ['min_students' => 1]);
        $this->classroom('OV-CLOSED', ['status' => 'completed']);
        foreach ([$full, $normal] as $class) {
            $student = Student::create(['code' => 'HV-'.$class->id, 'name' => 'Học viên '.$class->id, 'phone' => '0900000000', 'status' => 'studying']);
            ClassEnrollment::create(['class_id' => $class->id, 'student_id' => $student->id, 'status' => 'completed']);
        }
        $dropped = Student::create(['code' => 'HV-DROP', 'name' => 'Đã nghỉ', 'phone' => '0900000001', 'status' => 'dropped']);
        ClassEnrollment::create(['class_id' => $full->id, 'student_id' => $dropped->id, 'status' => 'completed']);

        $this->actingAs($this->academic)->get(route('academic.class-overview'))->assertOk()
            ->assertSee('Tổng quan lớp')->assertSee('Đủ sĩ số')->assertSee('Cần tuyển')->assertSee('Dự giờ')
            ->assertInertia(fn (Assert $page) => $page->component('Academic/ClassOverview')
                ->has('classes.data', 3)->where('groups.branches.0.count', 3)
                ->where('groups.programs.0.count', 3)->where('groups.levels.0.count', 3)
                ->where('classes.data.0.enrollment_badge', 'Đủ sĩ số')->where('classes.data.0.enrolled', 1)
                ->where('classes.data.1.enrollment_badge', 'Cần tuyển')->where('classes.data.2.enrollment_badge', null)
                ->has('classes.data.0.students', 1)->where('classes.data.0.students.0.code', 'HV-'.$full->id)
                ->where('classes.data.0.students.0', fn ($student) => array_keys($student->all()) === ['id', 'name', 'code']));
    }

    public function test_other_roles_without_permission_are_forbidden_and_super_admin_passes(): void
    {
        foreach ([Roles::TEACHER_FULLTIME, Roles::ACCOUNTANT, Roles::SALES_CONSULTANT] as $role) {
            $this->actingAs($this->user($role))->get('/academic/class-overview')->assertForbidden();
        }
        $this->actingAs($this->user(Roles::ADMIN))->get('/academic/class-overview')->assertOk();
    }

    public function test_personal_branch_restriction_applies_to_rows_groups_and_foreign_filters(): void
    {
        $visible = $this->classroom('VISIBLE');
        $foreign = Branch::create(['name' => 'FOREIGN BRANCH', 'code' => 'OV-B', 'is_active' => true]);
        $this->classroom('HIDDEN', ['branch_id' => $foreign->id, 'program' => 'FOREIGN PROGRAM', 'level' => 'FOREIGN LEVEL']);
        UserPermissionOverride::create(['user_id' => $this->academic->id, 'module' => 'class', 'action' => 'view', 'scope_type' => 'branch', 'scope_id' => $this->branch->id, 'allow' => true]);

        $this->actingAs($this->academic->fresh())->get('/academic/class-overview')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('classes.data', 1)->where('classes.data.0.id', $visible->id)
                ->has('groups.branches', 1)->where('groups.branches.0.value', $this->branch->id)->where('groups.branches.0.count', 1)
                ->has('groups.programs', 1)->where('groups.programs.0.value', 'IELTS')
                ->has('groups.levels', 1)->where('groups.levels.0.value', 'B1'));
        foreach ([['branch_id' => $foreign->id], ['program' => 'FOREIGN PROGRAM'], ['level' => 'FOREIGN LEVEL']] as $filter) {
            $this->get(route('academic.class-overview', $filter))->assertOk()
                ->assertInertia(fn (Assert $page) => $page->has('classes.data', 0)->where('classes.total', 0)
                    ->where('groups.branches.0.count', 1)->has('groups.branches', 1));
        }
    }

    public function test_filters_are_validated_and_intersected(): void
    {
        $class = $this->classroom('MATCH');
        $this->classroom('OTHER', ['program' => 'Kids', 'level' => 'A1']);
        $this->actingAs($this->academic)->get(route('academic.class-overview', ['branch_id' => $this->branch->id, 'program' => 'IELTS', 'level' => 'B1']))
            ->assertInertia(fn (Assert $page) => $page->has('classes.data', 1)->where('classes.data.0.id', $class->id));
        $this->getJson('/academic/class-overview?branch_id=invalid&program[]=x&level[]=x')->assertUnprocessable()
            ->assertJsonValidationErrors(['branch_id', 'program', 'level']);
    }

    public function test_open_stage_current_unit_expected_test_and_latest_observation(): void
    {
        $this->travelTo(now()->startOfDay());
        $class = $this->classroom('PROGRESS', ['teacher_id' => $this->academic->id]);
        $curriculum = SyllabusCurriculum::create(['code' => 'OV-SYL', 'title' => 'Overview syllabus', 'version' => 'v1']);
        $stage = SyllabusStage::create(['curriculum_id' => $curriculum->id, 'position' => 1, 'name' => 'Foundation']);
        $lessons = [];
        foreach ([1, 2] as $number) {
            $unit = SyllabusUnit::create(['curriculum_id' => $curriculum->id, 'stage_id' => $stage->id, 'unit_number' => $number, 'title' => 'Unit '.$number]);
            $lessons[] = SyllabusLesson::create(['unit_id' => $unit->id, 'session_no' => $number, 'title' => 'Lesson '.$number]);
        }
        $assignment = SyllabusAssignment::create(['user_id' => $this->academic->id, 'assigned_chapters' => 'Unit 1-2', 'class_id' => $class->id, 'curriculum_id' => $curriculum->id, 'stage_id' => $stage->id, 'stage_name' => 'Foundation', 'status' => SyllabusAssignment::STATUS_OPEN, 'start_date' => today()->subDay()]);
        SyllabusAssignment::create(['user_id' => $this->academic->id, 'assigned_chapters' => 'Old stage', 'class_id' => $class->id,
            'curriculum_id' => $curriculum->id, 'stage_id' => $curriculum->stages()->first()->id, 'stage_name' => 'Closed stage', 'status' => SyllabusAssignment::STATUS_CLOSED]);
        $past = ClassSession::create(['start_time' => '18:00', 'end_time' => '19:00', 'class_id' => $class->id, 'date' => today()->subDay(), 'status' => 'completed', 'start_time' => '18:00', 'end_time' => '19:00']);
        ClassLessonProgress::create(['class_id' => $class->id, 'syllabus_assignment_id' => $assignment->id, 'syllabus_lesson_id' => $lessons[0]->id, 'class_session_id' => $past->id, 'kind' => 'lesson', 'taught_at' => now()->subDay(), 'taught_by' => $this->academic->id]);
        ClassSession::create(['start_time' => '18:00', 'end_time' => '19:00', 'class_id' => $class->id, 'date' => today(), 'status' => 'cancelled']);
        ClassSession::create(['start_time' => '18:00', 'end_time' => '19:00', 'class_id' => $class->id, 'date' => today(), 'type' => 'support', 'status' => 'scheduled']);
        ClassSession::create(['start_time' => '18:00', 'end_time' => '19:00', 'class_id' => $class->id, 'date' => today()->addDays(3), 'status' => 'scheduled']);
        AcademicObservation::create(['class_id' => $class->id, 'month' => today()->subMonth()->format('Y-m'), 'observed' => false]);
        AcademicObservation::create(['class_id' => $class->id, 'month' => today()->format('Y-m'), 'observed' => true, 'observed_on' => today()]);

        $this->actingAs($this->academic)->get('/academic/class-overview')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('classes.data.0.progress.stage', 'Chặng 1: Foundation')
                ->where('classes.data.0.progress.unit', 'Unit 2')->where('classes.data.0.teacher', $this->academic->name)
                ->where('classes.data.0.expected_big_test_date', today()->addDays(3)->toDateString())
                ->where('classes.data.0.scheduled_big_test_date', null)
                ->where('classes.data.0.observation.observed', true)->where('classes.data.0.observation.observed_on', today()->toDateString())
                ->where('classes.data.0.observation.month', today()->format('Y-m')));
        BigTest::create(['code' => 'OV-BT', 'title' => 'Big Test', 'class_id' => $class->id, 'syllabus_stage_id' => $stage->id, 'scheduled_at' => today()->addDays(5)]);
        $this->get('/academic/class-overview')->assertInertia(fn (Assert $page) => $page
            ->where('classes.data.0.expected_big_test_date', today()->addDays(3)->toDateString())
            ->where('classes.data.0.scheduled_big_test_date', today()->addDays(5)->toDateString()));
    }

    public function test_student_preview_is_capped_and_counts_deduplicate_roster_sources(): void
    {
        $class = $this->classroom('LARGE', ['max_capacity' => 51]);
        $rows = [];
        foreach (range(1, 51) as $number) {
            $rows[] = ['code' => sprintf('OV-HV-%03d', $number), 'name' => sprintf('Học viên %03d', $number),
                'phone' => '0900000000', 'status' => 'studying', 'current_class_id' => $class->id];
        }
        Student::insert($rows);
        $student = Student::where('code', 'OV-HV-001')->sole();
        ClassEnrollment::create(['class_id' => $class->id, 'student_id' => $student->id, 'status' => 'pending']);
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        $this->actingAs($this->academic)->get('/academic/class-overview')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('classes.data.0.enrolled', 51)
                ->where('classes.data.0.enrollment_badge', 'Đủ sĩ số')->has('classes.data.0.students', 50)
                ->where('classes.data.0.students.49.code', 'OV-HV-050'));
        $this->assertCount(1, array_filter($queries, fn ($sql) => str_contains($sql, 'roster_position')));
    }

    public function test_menu_follows_permission_and_personal_denial(): void
    {
        $routesFor = fn (User $user) => collect(app(SidebarMenu::class)->groupsFor($user))
            ->flatMap(fn ($group) => collect($group['items'])->pluck('route'))->all();
        $this->assertContains('academic.class-overview', $routesFor($this->academic));
        $this->assertNotContains('academic.class-overview', $routesFor($this->user(Roles::TEACHER_FULLTIME)));
        UserPermissionOverride::create(['user_id' => $this->academic->id, 'module' => 'class', 'action' => 'overview',
            'scope_type' => 'all', 'scope_id' => null, 'allow' => false]);
        $this->actingAs($this->academic->fresh())->get('/academic/class-overview')->assertForbidden();
        $this->assertNotContains('academic.class-overview', $routesFor($this->academic->fresh()));
    }

    public function test_route_has_no_mutating_methods(): void
    {
        $routes = collect(Route::getRoutes())->filter(fn ($route) => str_starts_with($route->uri(), 'academic/class-overview'));
        $this->assertCount(1, $routes);
        $this->assertSame(['GET', 'HEAD'], $routes->sole()->methods());
    }

    public function test_seeders_and_migration_grant_permission_to_existing_academic_role(): void
    {
        $this->runSeederForReal(PermissionSeeder::class)->runSeederForReal(RoleSeeder::class);
        $role = Role::findByName(Roles::ACADEMIC_LEAD);
        $this->assertTrue($role->hasPermissionTo('class.overview'));
        Permission::findByName('class.overview')->delete();
        $migration = require database_path('migrations/2026_12_19_090000_add_class_overview_permission.php');
        $migration->up();
        $migration->up();
        $this->assertTrue($role->fresh()->hasPermissionTo('class.overview'));
        $this->assertFalse(Role::findByName(Roles::TEACHER_FULLTIME)->hasPermissionTo('class.overview'));
        $migration->down();
        $this->assertDatabaseMissing('permissions', ['name' => 'class.overview']);
    }
}
