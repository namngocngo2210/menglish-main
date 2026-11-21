<?php

namespace Tests\Feature;

use App\Models\AcademicObservation;
use App\Models\Branch;
use App\Models\ClassChecklist;
use App\Models\ClassModel;
use App\Models\Course;
use App\Models\KpiCriterion;
use App\Models\QaObservation;
use App\Models\StaffReport;
use App\Models\TeacherMeetingReport;
use App\Models\User;
use App\Support\Navigation\SidebarMenu;
use App\Support\ReportPeriod;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Màn còn thiếu theo mockup Owen: dự giờ vận hành, đánh giá dự giờ học thuật, checklist học phí & feedback,
 * họp giáo viên, báo cáo tuần Học vụ, báo cáo tháng / quý Học thuật.
 */
class ClassQualityTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Branch $otherBranch;

    private Course $course;

    private User $teacher;

    private ClassModel $class;

    private ClassModel $otherClass;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Cơ sở A', 'code' => 'CA', 'is_active' => true]);
        $this->otherBranch = Branch::create(['name' => 'Cơ sở B', 'code' => 'CB', 'is_active' => true]);
        $this->course = Course::create(['name' => 'Starters', 'code' => 'ST', 'total_lessons' => 24, 'tuition_fee' => 3000000, 'is_active' => true]);
        $this->teacher = $this->makeUser('teacher', $this->branch, ['name' => 'Cô Lan']);
        $this->class = $this->makeClass($this->branch, ['teacher_id' => $this->teacher->id]);
        $this->otherClass = $this->makeClass($this->otherBranch, ['teacher_id' => $this->makeUser('teacher', $this->otherBranch)->id]);
    }

    private function makeUser(string $role, ?Branch $branch = null, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge(['branch_id' => $branch?->id, 'is_active' => true], $attributes));
        $user->assignRole($role);

        return $user;
    }

    private function makeClass(Branch $branch, array $attributes = []): ClassModel
    {
        static $n = 0;
        $n++;

        return ClassModel::create(array_merge([
            'name' => "Lớp chất lượng {$n}", 'code' => "CQ-{$n}", 'course_id' => $this->course->id, 'branch_id' => $branch->id,
            'start_date' => now()->subMonths(2), 'end_date' => now()->addMonths(2), 'max_capacity' => 12, 'status' => 'active',
        ], $attributes));
    }

    /** @return list<string> */
    private function routesOf(User $user): array
    {
        return collect(app(SidebarMenu::class)->groupsFor($user->fresh()))->flatMap(fn (array $g) => collect($g['items'])->pluck('route'))->all();
    }

    public function test_pages_follow_permissions(): void
    {
        $admin = $this->makeUser('admin', $this->branch);
        foreach (['operations', 'academic', 'checklist', 'teacher-meetings'] as $page) {
            $this->actingAs($admin)->get(route("class-quality.{$page}"))->assertOk();
        }

        $staff = $this->makeUser('academic_staff', $this->branch);
        $this->actingAs($staff)->get(route('class-quality.operations'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('ClassQuality/Operations')->where('canRecord', true));
        $this->actingAs($staff)->get(route('class-quality.checklist'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canEdit', true));
        $this->actingAs($staff)->get(route('class-quality.academic'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canRecord', false));
        $this->actingAs($staff)->get(route('class-quality.teacher-meetings'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canManage', false));
        $this->actingAs($staff)->post(route('class-quality.teacher-meetings.store'), [])->assertForbidden();

        $lead = $this->makeUser('academic_lead', $this->branch);
        $this->actingAs($lead)->get(route('class-quality.teacher-meetings'))->assertOk();
        $this->actingAs($lead)->get(route('class-quality.academic'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canRecord', true));
        $this->actingAs($lead)->post(route('class-quality.operations.store'), [])->assertForbidden();

        $this->actingAs($this->teacher)->get(route('class-quality.operations'))->assertForbidden();
        $this->actingAs($this->makeUser('student'))->get(route('class-quality.checklist'))->assertForbidden();

        // Đường dẫn cũ chuyển sang màn mới.
        $this->actingAs($admin)->get(route('classes.qa-observation'))->assertRedirect(route('class-quality.operations'));
        $this->actingAs($admin)->get(route('classes.evaluate-observation'))->assertRedirect(route('class-quality.academic'));
    }

    public function test_sidebar_shows_class_quality_by_permission(): void
    {
        $routes = fn (User $user) => $this->routesOf($user);

        $staff = $routes($this->makeUser('academic_staff', $this->branch));
        $this->assertContains('class-quality.operations', $staff);
        $this->assertContains('class-quality.checklist', $staff);
        $this->assertNotContains('class-quality.academic', $this->routesOf($this->makeUser('finance_staff', $this->branch)));

        $lead = $routes($this->makeUser('academic_lead', $this->branch));
        $this->assertContains('class-quality.teacher-meetings', $lead);
        $this->assertContains('class-quality.academic', $lead);

        $this->assertNotContains('class-quality.operations', $routes($this->teacher));
    }

    public function test_operations_observation_crud_and_validation(): void
    {
        $staff = $this->makeUser('academic_staff', $this->branch);

        $payload = [
            'observed_on' => today()->toDateString(), 'teacher_id' => $this->teacher->id, 'class_id' => $this->class->id,
            'rating' => 'good', 'attitude' => 'Học sinh hào hứng',
        ];
        $this->actingAs($staff)->post(route('class-quality.operations.store'), $payload)->assertSessionHasNoErrors();
        $observation = QaObservation::sole();
        $this->assertSame($staff->id, $observation->observer_id);
        $this->assertSame('Học sinh hào hứng', $observation->attitude);
        $this->actingAs($staff)->get(route('class-quality.operations'))->assertOk()->assertSee('Cô Lan')->assertSee($this->class->code)->assertSee('Cơ sở A');

        // Giáo viên không dạy lớp, ngày tương lai, thiếu xếp loại, lớp chi nhánh khác.
        $this->actingAs($staff)->post(route('class-quality.operations.store'), ['teacher_id' => $this->makeUser('teacher', $this->branch)->id] + $payload)
            ->assertSessionHasErrors('class_id');
        $this->actingAs($staff)->post(route('class-quality.operations.store'), ['observed_on' => today()->addDay()->toDateString()] + $payload)
            ->assertSessionHasErrors('observed_on');
        $this->actingAs($staff)->post(route('class-quality.operations.store'), ['rating' => null] + $payload)
            ->assertSessionHasErrors('rating');
        $ended = $this->makeClass($this->branch, ['teacher_id' => $this->teacher->id, 'status' => 'completed']);
        $this->actingAs($staff)->post(route('class-quality.operations.store'), ['class_id' => $ended->id] + $payload)
            ->assertSessionHasErrors('class_id');
        $this->assertSame(1, QaObservation::count());

        // Sửa được kể cả khi lớp đã kết thúc.
        $this->class->update(['status' => 'completed']);
        $this->actingAs($staff)->put(route('class-quality.operations.update', $observation->id), ['rating' => 'excellent'] + $payload)
            ->assertSessionHasNoErrors();
        $this->assertSame('excellent', $observation->fresh()->rating);

        $this->actingAs($staff)->delete(route('class-quality.operations.destroy', $observation->id))->assertSessionHasNoErrors();
        $this->assertSoftDeleted($observation);
    }

    public function test_academic_observation_saves_per_class_and_month(): void
    {
        $lead = $this->makeUser('academic_lead', $this->branch);
        $month = ReportPeriod::currentMonth();

        $base = ['class_id' => $this->class->id, 'month' => $month, 'attendance_rate' => 92.5, 'pass_rate' => 80];
        $this->actingAs($lead)->put(route('class-quality.academic.save'), $base + [
            'observed' => 1, 'observed_on' => today()->toDateString(), 'observer_id' => $lead->id, 'teaching_quality' => 'Tốt',
        ])->assertSessionHasNoErrors();

        $evaluation = AcademicObservation::sole();
        $this->assertTrue($evaluation->observed);
        $this->assertSame('Tốt', $evaluation->teaching_quality);
        $this->actingAs($lead)->get(route('class-quality.academic', ['status' => 'observed']))->assertOk()->assertSee($this->class->code)->assertSee('92,5%');

        // Bỏ "Đã dự giờ": giữ tỉ lệ, xóa phần đánh giá chi tiết; không tạo bản ghi thứ hai.
        $this->actingAs($lead)->put(route('class-quality.academic.save'), $base + ['observed' => 0, 'teaching_quality' => 'Bỏ qua'])
            ->assertSessionHasNoErrors();
        $evaluation->refresh();
        $this->assertSame(1, AcademicObservation::count());
        $this->assertFalse($evaluation->observed);
        $this->assertNull($evaluation->teaching_quality);
        $this->assertEquals(92.5, $evaluation->attendance_rate);

        // Đã dự giờ thì bắt buộc ngày (trong tháng) + người dự giờ; tỉ lệ 0–100.
        $this->actingAs($lead)->put(route('class-quality.academic.save'), $base + ['observed' => 1])
            ->assertSessionHasErrors(['observed_on', 'observer_id']);
        $this->actingAs($lead)->put(route('class-quality.academic.save'), $base + [
            'observed' => 1, 'observed_on' => now()->startOfMonth()->subDay()->toDateString(), 'observer_id' => $lead->id,
        ])->assertSessionHasErrors('observed_on');
        $this->actingAs($lead)->put(route('class-quality.academic.save'), ['pass_rate' => 120] + $base)->assertSessionHasErrors('pass_rate');
        $future = $this->makeClass($this->branch, ['status' => 'pending', 'start_date' => now()->addMonths(2)]);
        $this->actingAs($lead)->put(route('class-quality.academic.save'), ['class_id' => $future->id] + $base)->assertSessionHasErrors('class_id');

        $this->actingAs($this->makeUser('academic_staff', $this->branch))->put(route('class-quality.academic.save'), $base)->assertForbidden();
    }

    public function test_checklist_saves_rows_and_flags_gaps(): void
    {
        $staff = $this->makeUser('academic_staff', $this->branch);
        $idle = $this->makeClass($this->branch, ['teacher_id' => $this->teacher->id]);
        $month = ReportPeriod::currentMonth();

        $this->actingAs($staff)->put(route('class-quality.checklist.save'), ['month' => $month, 'rows' => [
            ['class_id' => $this->class->id, 'tuition_due' => 'yes', 'tuition_reminded' => 'no', 'tuition_collected' => 'no', 'tuition_note' => 'PH hẹn tuần sau'],
            ['class_id' => $idle->id],
        ]])->assertSessionHasNoErrors();

        $checklist = ClassChecklist::sole();
        $this->assertSame($this->class->id, $checklist->class_id);
        $this->assertSame(['tuition_reminded', 'tuition_collected'], $checklist->gaps());

        // Học vụ chỉ thấy lớp chi nhánh mình (class.scope_branch): 2 lớp Cơ sở A, không có lớp Cơ sở B.
        $this->actingAs($staff)->get(route('class-quality.checklist', ['month' => $month]))->assertOk()->assertSee('PH hẹn tuần sau')
            ->assertInertia(fn (AssertableInertia $page) => $page->component('ClassQuality/Checklist')->has('classes.data', 2));

        $this->actingAs($staff)->put(route('class-quality.checklist.save'), ['month' => $month, 'rows' => [
            ['class_id' => $this->makeClass($this->branch, ['status' => 'pending', 'start_date' => now()->addMonths(2)])->id, 'tuition_due' => 'yes'],
        ]])->assertSessionHasErrors('rows');
        $this->actingAs($staff)->put(route('class-quality.checklist.save'), ['month' => $month, 'rows' => [
            ['class_id' => $this->class->id, 'tuition_due' => 'maybe'],
        ]])->assertSessionHasErrors('rows.0.tuition_due');
    }

    public function test_teacher_meeting_reports(): void
    {
        $lead = $this->makeUser('academic_lead', $this->branch);
        $payload = [
            'week_start' => now()->startOfWeek()->addDays(2)->toDateString(), 'teacher_id' => $this->teacher->id,
            'class_id' => $this->class->id, 'class_note' => 'Lớp ổn định', 'status' => 'in_progress',
        ];

        $this->actingAs($lead)->post(route('class-quality.teacher-meetings.store'), $payload)->assertSessionHasNoErrors();
        $report = TeacherMeetingReport::sole();
        $this->assertSame(now()->startOfWeek()->toDateString(), $report->week_start->toDateString());
        $this->assertSame($lead->id, $report->author_id);
        $this->actingAs($lead)->get(route('class-quality.teacher-meetings'))->assertOk()->assertSee('Lớp ổn định')->assertSee('Đang xử lý');

        $this->actingAs($lead)->post(route('class-quality.teacher-meetings.store'), ['teacher_id' => $this->makeUser('finance_staff', $this->branch)->id] + $payload)
            ->assertSessionHasErrors('teacher_id');
        $this->actingAs($lead)->post(route('class-quality.teacher-meetings.store'), ['class_note' => ''] + $payload)
            ->assertSessionHasErrors('class_note');
        $this->actingAs($lead)->post(route('class-quality.teacher-meetings.store'), ['class_id' => $this->otherClass->id] + $payload) // GV không dạy lớp này
            ->assertSessionHasErrors('class_id');

        $this->actingAs($lead)->put(route('class-quality.teacher-meetings.update', $report->id), ['status' => 'handled'] + $payload)
            ->assertSessionHasNoErrors();
        $this->assertSame('handled', $report->fresh()->status);
        $this->assertSame(1, TeacherMeetingReport::count());

        $this->actingAs($lead)->delete(route('class-quality.teacher-meetings.destroy', $report->id))->assertSessionHasNoErrors();
        $this->assertSoftDeleted($report);
    }

    public function test_admin_is_read_only_on_teacher_meeting_reports(): void
    {
        $lead = $this->makeUser('academic_lead', $this->branch);
        $admin = $this->makeUser('admin');
        $staff = $this->makeUser('academic_staff', $this->branch);
        $payload = [
            'week_start' => now()->startOfWeek()->toDateString(), 'teacher_id' => $this->teacher->id,
            'class_id' => $this->class->id, 'class_note' => 'Lớp ổn định', 'status' => 'in_progress',
        ];
        $this->actingAs($lead)->post(route('class-quality.teacher-meetings.store'), $payload)->assertSessionHasNoErrors();
        $report = TeacherMeetingReport::sole();

        foreach ([$admin, $staff] as $viewer) {
            $this->actingAs($viewer)->get(route('class-quality.teacher-meetings'))->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page->component('ClassQuality/TeacherMeetings')->where('canManage', false));
            $this->actingAs($viewer)->post(route('class-quality.teacher-meetings.store'), $payload)->assertForbidden();
            $this->actingAs($viewer)->put(route('class-quality.teacher-meetings.update', $report->id), ['status' => 'handled'] + $payload)->assertForbidden();
            $this->actingAs($viewer)->delete(route('class-quality.teacher-meetings.destroy', $report->id))->assertForbidden();
        }
        $this->assertSame('in_progress', $report->fresh()->status);
        $this->assertNull($report->fresh()->deleted_at);

        // Học thuật vẫn ghi được.
        $this->actingAs($lead)->get(route('class-quality.teacher-meetings'))->assertInertia(fn (AssertableInertia $page) => $page->where('canManage', true));
        $this->actingAs($lead)->put(route('class-quality.teacher-meetings.update', $report->id), ['status' => 'handled'] + $payload)->assertSessionHasNoErrors();
        $this->assertSame('handled', $report->fresh()->status);

        // Guard: quyền ghi là quyền "đối tượng" nên Super Admin không tự có.
        $this->assertTrue(\App\Support\PermissionCatalog::isAudience('class_quality.teacher_meeting'));
        $this->assertFalse($admin->can('class_quality.teacher_meeting'));
        $this->assertTrue($lead->can('class_quality.teacher_meeting'));
    }

    public function test_admin_with_legacy_teacher_meeting_grant_is_detached_by_migration(): void
    {
        $admin = $this->makeUser('admin');
        $lead = $this->makeUser('academic_lead', $this->branch);
        $manager = $this->makeUser('manager', $this->branch);
        $payload = [
            'week_start' => now()->startOfWeek()->toDateString(), 'teacher_id' => $this->teacher->id,
            'class_id' => $this->class->id, 'class_note' => 'Lớp ổn định', 'status' => 'in_progress',
        ];
        $this->actingAs($lead)->post(route('class-quality.teacher-meetings.store'), $payload)->assertSessionHasNoErrors();
        $report = TeacherMeetingReport::sole();

        // Staging/production state before the fix: admin role still holds the row.
        Role::findByName('admin', 'web')->givePermissionTo('class_quality.teacher_meeting');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertTrue($admin->fresh()->can('class_quality.teacher_meeting'));

        (require database_path('migrations/2026_12_19_090000_detach_teacher_meeting_from_admin.php'))->up();

        $admin = $admin->fresh();
        $this->assertFalse($admin->can('class_quality.teacher_meeting'));
        $this->actingAs($admin)->get(route('class-quality.teacher-meetings'))->assertOk();
        $this->actingAs($admin)->post(route('class-quality.teacher-meetings.store'), $payload)->assertForbidden();
        $this->actingAs($admin)->put(route('class-quality.teacher-meetings.update', $report->id), ['status' => 'handled'] + $payload)->assertForbidden();
        $this->actingAs($admin)->delete(route('class-quality.teacher-meetings.destroy', $report->id))->assertForbidden();
        $this->assertNull($report->fresh()->deleted_at);

        // Only admin is detached; manager (seeded from config) and academic lead keep write access.
        $this->assertTrue($manager->can('class_quality.teacher_meeting'));
        $this->assertTrue($lead->can('class_quality.teacher_meeting'));
    }

    public function test_teacher_meeting_rejects_duplicate_for_the_normalized_week(): void
    {
        $lead = $this->makeUser('academic_lead', $this->branch);
        $payload = [
            'week_start' => '2026-11-16', 'teacher_id' => $this->teacher->id,
            'class_id' => $this->class->id, 'class_note' => 'Original report', 'status' => 'in_progress',
        ];

        $this->actingAs($lead)->post(route('class-quality.teacher-meetings.store'), $payload)->assertSessionHasNoErrors();
        $this->post(route('class-quality.teacher-meetings.store'), ['week_start' => '2026-11-22'] + $payload)
            ->assertSessionHasErrors(['week_start' => 'Đã có báo cáo họp cho lớp, giáo viên, tuần này; chỉ được sửa báo cáo hiện có']);

        $this->assertSame(1, TeacherMeetingReport::count());
    }

    public function test_teacher_meeting_rejects_update_colliding_with_another_report(): void
    {
        $lead = $this->makeUser('academic_lead', $this->branch);
        $payload = [
            'week_start' => '2026-11-16', 'teacher_id' => $this->teacher->id,
            'class_id' => $this->class->id, 'class_note' => 'Original report', 'status' => 'in_progress',
        ];
        TeacherMeetingReport::create($payload + ['author_id' => $lead->id]);
        $report = TeacherMeetingReport::create(['week_start' => '2026-11-23'] + $payload + ['author_id' => $lead->id]);

        $this->actingAs($lead)->put(route('class-quality.teacher-meetings.update', $report->id), ['week_start' => '2026-11-18'] + $payload)
            ->assertSessionHasErrors(['week_start' => 'Đã có báo cáo họp cho lớp, giáo viên, tuần này; chỉ được sửa báo cáo hiện có']);

        $this->assertSame('2026-11-23', $report->fresh()->week_start->toDateString());
        $this->assertSame(2, TeacherMeetingReport::count());
    }

    public function test_teacher_meeting_rejects_hidden_duplicate_without_a_class(): void
    {
        $staff = $this->makeUser('academic_staff', $this->branch);
        $staff->givePermissionTo('class_quality.teacher_meeting');
        $author = $this->makeUser('academic_lead', $this->otherBranch);
        $payload = [
            'week_start' => '2026-11-16', 'teacher_id' => $this->otherClass->teacher_id,
            'class_id' => null, 'class_note' => 'Hidden report', 'status' => 'in_progress',
        ];
        $report = TeacherMeetingReport::create($payload + ['author_id' => $author->id]);

        $this->assertFalse(TeacherMeetingReport::visibleTo($staff)->whereKey($report->id)->exists());
        $this->actingAs($staff)->post(route('class-quality.teacher-meetings.store'), ['week_start' => '2026-11-18'] + $payload)
            ->assertSessionHasErrors(['week_start' => 'Đã có báo cáo họp cho lớp, giáo viên, tuần này; chỉ được sửa báo cáo hiện có']);

        $this->assertSame(1, TeacherMeetingReport::withoutGlobalScopes()->count());
    }

    public function test_teacher_meeting_allows_different_weeks_teachers_and_classes(): void
    {
        $lead = $this->makeUser('academic_lead', $this->branch);
        $otherTeacher = $this->makeUser('teacher', $this->branch);
        $this->class->update(['foreign_teacher_id' => $otherTeacher->id]);
        $otherClass = $this->makeClass($this->branch, ['teacher_id' => $this->teacher->id]);
        $payload = [
            'week_start' => '2026-11-16', 'teacher_id' => $this->teacher->id,
            'class_id' => $this->class->id, 'class_note' => 'Class report', 'status' => 'in_progress',
        ];
        TeacherMeetingReport::create($payload + ['author_id' => $lead->id]);

        $this->actingAs($lead);
        foreach ([['week_start' => '2026-11-23'], ['teacher_id' => $otherTeacher->id], ['class_id' => $otherClass->id], ['class_id' => null]] as $change) {
            $this->post(route('class-quality.teacher-meetings.store'), $change + $payload)->assertSessionHasNoErrors();
        }

        $this->assertSame(5, TeacherMeetingReport::count());
    }

    public function test_teacher_meeting_ignores_soft_deleted_duplicates(): void
    {
        $lead = $this->makeUser('academic_lead', $this->branch);
        $payload = [
            'week_start' => '2026-11-16', 'teacher_id' => $this->teacher->id,
            'class_id' => $this->class->id, 'class_note' => 'Replacement report', 'status' => 'in_progress',
        ];
        $report = TeacherMeetingReport::create($payload + ['author_id' => $lead->id]);
        $report->delete();

        $this->actingAs($lead)->post(route('class-quality.teacher-meetings.store'), $payload)->assertSessionHasNoErrors();

        $this->assertSame(1, TeacherMeetingReport::count());
        $this->assertSame(2, TeacherMeetingReport::withTrashed()->count());
        $this->assertSoftDeleted($report);
    }

    public function test_weekly_kpi_report_for_academic_staff(): void
    {
        $staff = $this->makeUser('academic_staff', $this->branch);
        $criterion = KpiCriterion::create(['group_name' => 'Chăm sóc', 'code' => 'CS1', 'name' => 'Gọi điện phụ huynh', 'weight' => 10, 'is_active' => true]);
        $week = ReportPeriod::currentWeek();

        $this->actingAs($staff)->get(route('reports.periodic.weekly-kpi'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Reports/WeeklyKpi')->where('week', $week)->has('tabs', 3));

        // Số lần phát sinh do hệ thống đếm (Nhật ký Học vụ gắn mã KPI), số gửi từ form bị bỏ qua.
        StaffReport::create(['user_id' => $staff->id, 'type' => 'journal', 'title' => 'Sự vụ', 'content' => 'Gọi phụ huynh trễ', 'kpi_code' => 'CS1', 'report_date' => today()]);
        $this->actingAs($staff)->post(route('reports.periodic.weekly-kpi.store'), [
            'week' => $week, 'counts' => [$criterion->id => 9], 'metrics' => ['classes_running' => 12],
        ])->assertSessionHasNoErrors();
        // Lưu lại trong tuần là cập nhật, không tạo báo cáo mới.
        $this->actingAs($staff)->post(route('reports.periodic.weekly-kpi.store'), ['week' => $week, 'note' => 'Ổn'])->assertSessionHasNoErrors();

        $report = StaffReport::where('kind', 'weekly_kpi')->sole();
        $this->assertSame('weekly', $report->type);
        $this->assertSame($week, $report->period_key);
        $this->assertSame(1, $report->data['counts'][0]['count']);
        $this->assertSame(0, $report->data['metrics']['classes_running']);
        $this->assertStringContainsString('Gọi điện phụ huynh: 1 lần', $report->content);
        $this->actingAs($staff)->get(route('reports.periodic.weekly-kpi', ['week' => $week]))->assertOk()->assertSee('Gọi điện phụ huynh')->assertSee('Đã nộp tuần này');

        // Học vụ vào "Báo cáo của tôi" là vào thẳng màn báo cáo ngày có cấu trúc.
        $this->actingAs($staff)->get(route('reports.my'))->assertRedirect(route('reports.periodic.daily'));

        $this->actingAs($this->makeUser('academic_lead', $this->branch))->get(route('reports.periodic.weekly-kpi'))->assertRedirect(route('reports.periodic.academic-weekly'));
        $this->actingAs($this->makeUser('academic_lead', $this->branch))->post(route('reports.periodic.weekly-kpi.store'), ['week' => $week])->assertForbidden();
    }

    public function test_academic_monthly_and_quarterly_reports(): void
    {
        $lead = $this->makeUser('academic_lead', $this->branch);
        $month = ReportPeriod::currentMonth();
        $quarter = ReportPeriod::currentQuarter();

        StaffReport::create([
            'user_id' => $lead->id, 'type' => 'weekly', 'title' => 'Báo cáo tuần đầu tháng', 'report_date' => now()->startOfMonth(),
            'content' => 'Đã họp 3 giáo viên', 'status' => 'submitted',
        ]);
        TeacherMeetingReport::create([
            'week_start' => now()->startOfWeek(), 'teacher_id' => $this->teacher->id, 'author_id' => $lead->id,
            'class_note' => 'Ổn', 'status' => 'handled',
        ]);
        // Bảng tuần trong tháng (Tuần / Khoảng ngày / PIC) nhận cả báo cáo tuần dạng chữ cũ; bấm vào xem nội dung.
        $this->actingAs($lead)->get(route('reports.periodic.academic-monthly'))->assertOk()->assertSee('Cô Lan')
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Reports/AcademicMonthly')->has('tabs', 5)
                ->where('weeklyReports', fn ($weeks) => collect($weeks)->contains(fn ($w) => $w['title'] === 'Báo cáo tuần đầu tháng' && $w['pic'] === $lead->name)));
        $this->actingAs($lead)->post(route('reports.periodic.academic-monthly.store'), ['month' => $month, 'narrative' => []])
            ->assertSessionHasErrors();
        $this->actingAs($lead)->post(route('reports.periodic.academic-monthly.store'), [
            'month' => $month, 'narrative' => ['overall' => 'Tháng ổn định'],
        ])->assertSessionHasNoErrors();

        $monthly = StaffReport::where('type', 'monthly')->sole();
        // Báo cáo tuần dạng chữ cũ vẫn xem lại được ở màn báo cáo tuần mới.
        $this->actingAs($lead)->get(route('reports.my'))->assertRedirect(route('reports.periodic.academic-weekly'));
        $this->actingAs($lead)->get(route('reports.periodic.academic-weekly'))->assertOk()->assertSee('Báo cáo tuần đầu tháng');
        $this->assertSame($month, $monthly->period_key);
        $this->assertSame('Tháng ổn định', $monthly->data['narrative']['overall']);

        $this->actingAs($lead)->get(route('reports.periodic.academic-quarterly', ['quarter' => $quarter]))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Reports/AcademicQuarterly')
                ->where('monthlyReports', fn ($reports) => collect($reports)->firstWhere('month', $month)['submitted'] === true));
        $this->actingAs($lead)->post(route('reports.periodic.academic-quarterly.store'), [
            'quarter' => $quarter, 'narrative' => ['next_plan' => 'Mở 2 lớp mới'],
        ])->assertSessionHasNoErrors();
        $this->assertSame($quarter, StaffReport::where('type', 'quarterly')->sole()->period_key);
        $this->actingAs($lead)->get(route('reports.periodic.academic-quarterly', ['quarter' => $quarter]))->assertOk()->assertSee('Mở 2 lớp mới')->assertSee('Đã nộp');

        $staff = $this->makeUser('academic_staff', $this->branch);
        $this->actingAs($staff)->get(route('reports.periodic.academic-monthly'))->assertRedirect(route('reports.periodic.daily'));
        $this->actingAs($staff)->post(route('reports.periodic.academic-quarterly.store'), ['quarter' => $quarter, 'narrative' => ['next_plan' => 'x']])
            ->assertForbidden();
    }
}
