<?php

namespace Tests\Feature;

use App\Models\AcademicRecord;
use App\Models\BigTest;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\Student;
use App\Models\TeacherMeetingReport;
use App\Models\User;
use App\Models\WorkTask;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Rò rỉ dữ liệu Lớp học giữa chi nhánh: Quản lý cơ sở chỉ thấy chi nhánh mình, GV chỉ thấy lớp mình, Admin thấy hết.
 */
class ClassDataScopeLeaksTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branchA;

    private Branch $branchB;

    private Course $course;

    private User $admin;

    private User $managerA;

    private User $teacherA;

    private User $teacherB;

    private ClassModel $classA;

    private ClassModel $classB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branchA = Branch::create(['name' => 'Co so A', 'code' => 'LKA', 'is_active' => true]);
        $this->branchB = Branch::create(['name' => 'Co so B', 'code' => 'LKB', 'is_active' => true]);
        $this->course = Course::create(['name' => 'Starters', 'code' => 'ST', 'total_lessons' => 24, 'tuition_fee' => 3000000, 'is_active' => true]);

        $this->admin = $this->makeUser('admin', $this->branchA, ['name' => 'Admin Leak']);
        $this->managerA = $this->makeUser('manager', $this->branchA, ['name' => 'Manager A']);
        $this->teacherA = $this->makeUser('teacher_fulltime', $this->branchA, ['name' => 'Teacher Alpha']);
        $this->teacherB = $this->makeUser('teacher_fulltime', $this->branchB, ['name' => 'Teacher Bravo']);

        $this->classA = $this->makeClass($this->branchA, ['name' => 'CLASS-ALPHA-ONE', 'code' => 'LK-A1', 'teacher_id' => $this->teacherA->id]);
        $this->classB = $this->makeClass($this->branchB, ['name' => 'CLASS-BRAVO-ONE', 'code' => 'LK-B1', 'teacher_id' => $this->teacherB->id]);
    }

    private function makeUser(string $role, ?Branch $branch = null, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge(['branch_id' => $branch?->id, 'is_active' => true], $attributes));
        $user->assignRole($role);

        return $user;
    }

    private function makeClass(Branch $branch, array $attributes = []): ClassModel
    {
        return ClassModel::create(array_merge([
            'course_id' => $this->course->id, 'branch_id' => $branch->id,
            'start_date' => now()->subMonth(), 'end_date' => now()->addMonths(2), 'max_capacity' => 12, 'status' => 'active',
        ], $attributes));
    }

    private function todaySession(ClassModel $class, User $teacher): ClassSession
    {
        return ClassSession::create([
            'class_id' => $class->id, 'branch_id' => $class->branch_id, 'date' => today()->toDateString(),
            'start_time' => '18:00', 'end_time' => '19:30', 'room' => 'R-'.$class->code, 'teacher_id' => $teacher->id, 'status' => 'scheduled',
        ]);
    }

    /** @return list<string> */
    private function dashboardClassNames(User $viewer, array $query = []): array
    {
        $names = [];
        $this->actingAs($viewer)->get(route('tasks.classes-dashboard', $query + ['date' => today()->toDateString()]))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$names) {
                $names = collect($page->toArray()['props']['dayRows'] ?? [])->pluck('class_name')->all();
            });

        return $names;
    }

    private function exportCsv(User $viewer, array $query = []): string
    {
        $response = $this->actingAs($viewer)->get(route('tasks.classes-dashboard', $query + ['export' => 1, 'format' => 'csv', 'date' => today()->toDateString()]));
        $response->assertOk();

        return file_get_contents($response->baseResponse->getFile()->getPathname());
    }

    public function test_classes_dashboard_is_scoped_by_branch_for_manager_and_own_for_teacher(): void
    {
        $this->todaySession($this->classA, $this->teacherA);
        $this->todaySession($this->classB, $this->teacherB);

        // Quản lý cơ sở A: chỉ buổi của A; lọc ?branch_id=B không lộ gì của B.
        $this->assertSame(['CLASS-ALPHA-ONE'], $this->dashboardClassNames($this->managerA));
        $this->assertNotContains('CLASS-BRAVO-ONE', $this->dashboardClassNames($this->managerA, ['branch_id' => $this->branchB->id]));
        $this->actingAs($this->managerA)->get(route('tasks.classes-dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('branches', fn ($branches) => collect($branches)->pluck('value')->map(fn ($v) => (int) $v)->all() === [$this->branchA->id]));

        $csv = $this->exportCsv($this->managerA);
        $this->assertStringContainsString('CLASS-ALPHA-ONE', $csv);
        $this->assertStringNotContainsString('CLASS-BRAVO-ONE', $csv);
        $this->assertStringNotContainsString('CLASS-BRAVO-ONE', $this->exportCsv($this->managerA, ['branch_id' => $this->branchB->id]));

        // Admin: cả hai chi nhánh.
        $adminNames = $this->dashboardClassNames($this->admin);
        $this->assertContains('CLASS-ALPHA-ONE', $adminNames);
        $this->assertContains('CLASS-BRAVO-ONE', $adminNames);
        $this->assertStringContainsString('CLASS-BRAVO-ONE', $this->exportCsv($this->admin));

        // Giáo viên: chỉ lớp mình.
        $this->assertSame(['CLASS-ALPHA-ONE'], $this->dashboardClassNames($this->teacherA));
    }

    public function test_classes_index_branch_filter_lists_only_branches_in_scope(): void
    {
        $this->actingAs($this->managerA)->get(route('classes.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('branches', fn ($branches) => collect($branches)->pluck('value')->all() === [$this->branchA->id]));
        $this->actingAs($this->admin)->get(route('classes.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('branches', fn ($branches) => count($branches) === 2));
    }

    public function test_staff_without_student_in_scope_does_not_see_other_students_portal_records(): void
    {
        $studentB = Student::create(['code' => 'HV-LKB', 'name' => 'Student Bravo', 'phone' => '0911000099', 'branch_id' => $this->branchB->id, 'status' => 'studying']);
        foreach ([
            '04_Cong_Phu_Huynh_Hoc_Sinh/03_hoc_tap_cua_toi_nop_bai_tap' => ['homework_type' => 'video', 'notes' => 'LEAK-HOMEWORK-NOTE'],
            '04_Cong_Phu_Huynh_Hoc_Sinh/06_khao_sat' => ['feedback' => 'LEAK-SURVEY-NOTE', 'rating' => 4],
            '04_Cong_Phu_Huynh_Hoc_Sinh/07_phu_huynh_gui_feedback' => ['noi_dung_feedback' => 'LEAK-FEEDBACK-NOTE'],
        ] as $screen => $data) {
            AcademicRecord::create([
                'screen_key' => $screen, 'module' => 'student_portal', 'record_code' => 'LEAK-'.md5($screen),
                'title' => 'Leak record', 'status' => 'submitted', 'data' => $data + ['student_id' => (string) $studentB->id],
            ]);
        }

        // Quản lý cơ sở A: chi nhánh A không có học viên → không học viên nào trong phạm vi.
        $this->actingAs($this->managerA)->get(route('portal.student.homework'))->assertOk()->assertDontSee('LEAK-HOMEWORK-NOTE');
        $this->actingAs($this->managerA)->get(route('portal.student.survey'))->assertOk()->assertDontSee('LEAK-SURVEY-NOTE');
        $this->actingAs($this->managerA)->get(route('portal.student.feedback'))->assertOk()->assertDontSee('LEAK-FEEDBACK-NOTE');

        // Admin vẫn thấy hồ sơ học viên (đối chứng: bản ghi hiện khi học viên nằm trong phạm vi).
        $this->actingAs($this->admin)->get(route('portal.student.homework', ['studentId' => $studentB->id]))->assertOk()->assertSee('LEAK-HOMEWORK-NOTE');
    }

    public function test_manager_cannot_open_class_report_for_other_branch_task(): void
    {
        $studentB = Student::create(['code' => 'HV-LKB2', 'name' => 'ROSTER-BRAVO-KID', 'phone' => '0911000098', 'branch_id' => $this->branchB->id,
            'status' => 'studying', 'current_class_id' => $this->classB->id]);
        $this->classB->enrollments()->create(['student_id' => $studentB->id, 'status' => 'active', 'enrolled_at' => now()]);
        $taskB = WorkTask::create([
            'title' => 'Truc lop B', 'creator_id' => $this->admin->id, 'assignee_id' => $this->teacherB->id, 'due_date' => today(),
            'task_type' => 'one_time', 'branch_id' => $this->branchB->id, 'class_id' => $this->classB->id, 'status' => 'new',
        ]);

        $this->actingAs($this->managerA)->get(route('tasks.class-reports.create', ['task_id' => $taskB->id]))->assertOk()
            ->assertDontSee('CLASS-BRAVO-ONE')->assertDontSee('ROSTER-BRAVO-KID');
        $this->actingAs($this->managerA)->post(route('tasks.complete', $taskB->id), ['note' => 'x'])->assertForbidden();
        $this->assertSame('new', $taskB->fresh()->status);

        // Báo cáo trực lớp cho lớp B: chặn.
        $this->actingAs($this->managerA)->post(route('tasks.class-reports.store'), [
            'class_id' => $this->classB->id, 'session_name' => 'Buoi 1', 'hom_nay_hoc_gi' => 'x',
        ])->assertForbidden();

        // Admin vẫn mở được.
        $this->actingAs($this->admin)->get(route('tasks.class-reports.create', ['task_id' => $taskB->id]))->assertOk()->assertSee('CLASS-BRAVO-ONE');
    }

    public function test_schedule_config_report_and_task_store_reject_other_branch(): void
    {
        $this->actingAs($this->managerA)->get(route('tasks.schedule-config', ['report_branch_id' => $this->branchB->id]))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('reportBranchId', $this->branchA->id));
        $this->actingAs($this->managerA)->post(route('tasks.hr-demand.save'), [
            'branch_id' => $this->branchB->id, 'demands' => [today()->toDateString() => 3],
        ])->assertForbidden();

        $this->actingAs($this->managerA)->post(route('tasks.store'), [
            'taskTitle' => 'Leak', 'assignee' => $this->teacherA->id, 'dueDate' => today()->toDateString(), 'taskType' => 'one-time',
            'class_id' => $this->classB->id,
        ])->assertSessionHasErrors('class_id');
        $this->actingAs($this->managerA)->post(route('tasks.store'), [
            'taskTitle' => 'Leak', 'assignee' => $this->teacherA->id, 'dueDate' => today()->toDateString(), 'taskType' => 'one-time',
            'branch_id' => $this->branchB->id,
        ])->assertSessionHasErrors('branch_id');
        $this->assertDatabaseMissing('work_tasks', ['title' => 'Leak']);
    }

    public function test_null_class_teacher_meeting_report_of_other_branch_teacher_is_hidden_from_manager(): void
    {
        $author = $this->makeUser('academic_lead', $this->branchB);
        $reportB = TeacherMeetingReport::create([
            'week_start' => now()->startOfWeek(), 'teacher_id' => $this->teacherB->id, 'author_id' => $author->id,
            'class_note' => 'MEETING-BRAVO-NOTE', 'status' => 'handled',
        ]);
        $reportA = TeacherMeetingReport::create([
            'week_start' => now()->startOfWeek(), 'teacher_id' => $this->teacherA->id, 'author_id' => $author->id,
            'class_note' => 'MEETING-ALPHA-NOTE', 'status' => 'handled',
        ]);

        $this->assertSame([$reportA->id], TeacherMeetingReport::visibleTo($this->managerA)->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$reportA->id, $reportB->id], TeacherMeetingReport::visibleTo($this->admin)->pluck('id')->all());
        // Người ghi luôn thấy báo cáo mình ghi.
        $this->assertContains($reportB->id, TeacherMeetingReport::visibleTo($author)->pluck('id')->all());

        $this->managerA->givePermissionTo('class_quality.view', 'class_quality.teacher_meeting');
        $this->actingAs($this->managerA)->get(route('class-quality.teacher-meetings'))->assertOk()
            ->assertSee('MEETING-ALPHA-NOTE')->assertDontSee('MEETING-BRAVO-NOTE');
        $this->actingAs($this->managerA)->delete(route('class-quality.teacher-meetings.destroy', $reportB->id))->assertNotFound();
        $this->assertNotNull($reportB->fresh());
    }

    public function test_big_test_reminder_for_other_branch_test_is_not_found(): void
    {
        // Phạm vi Big Test của quản lý đặt rõ là "Chi nhánh".
        $this->managerA->givePermissionTo('big_test.scope_branch');
        $testB = BigTest::create([
            'code' => 'BT-LKB', 'title' => 'Big Test B', 'class_id' => $this->classB->id, 'test_type' => 'midterm',
            'scheduled_at' => now()->addDays(2), 'room' => 'P1', 'is_distributed' => true, 'status' => 'distributed',
        ]);
        $testA = BigTest::create([
            'code' => 'BT-LKA', 'title' => 'Big Test A', 'class_id' => $this->classA->id, 'test_type' => 'midterm',
            'scheduled_at' => now()->addDays(2), 'room' => 'P1', 'is_distributed' => true, 'status' => 'distributed',
        ]);

        $this->actingAs($this->managerA)->post(route('syllabus.big-tests.remind', $testB->id))->assertNotFound();
        $this->actingAs($this->managerA)->post(route('syllabus.big-tests.remind', $testA->id))->assertRedirect();
    }

    public function test_check_availability_requires_class_write_and_branch_in_scope(): void
    {
        $payload = ['date' => today()->toDateString(), 'start_time' => '18:00', 'end_time' => '19:30'];

        $this->actingAs($this->teacherA)->postJson(route('classes.check-availability'), $payload)->assertForbidden();
        $this->actingAs($this->managerA)->postJson(route('classes.check-availability'), $payload + ['branch_id' => $this->branchB->id])->assertForbidden();
        $this->actingAs($this->managerA)->postJson(route('classes.check-availability'), $payload + ['branch_id' => $this->branchA->id])->assertOk();
    }
}
