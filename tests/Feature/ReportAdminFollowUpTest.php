<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassReport;
use App\Models\Course;
use App\Models\StaffReport;
use App\Models\User;
use App\Models\WorkTask;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Báo cáo phía Admin (chủ dự án 17/10/2026): tạo task ngay trong từng khối khi xem chi tiết báo cáo (mặc định giao người viết) và
 * bảng "Việc follow-up từ báo cáo" ở màn Tổng hợp, gom theo người viết.
 */
class ReportAdminFollowUpTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $admin;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->travelTo(today()->setTime(10, 0));
        $this->branch = Branch::create(['name' => 'Cơ sở A', 'code' => 'CA', 'is_active' => true]);
        $this->admin = $this->makeUser('admin', ['name' => 'Admin Trung tâm', 'branch_id' => null]);
        $this->staff = $this->makeUser('academic_staff', ['name' => 'Học vụ Thư']);
    }

    private function makeUser(string $role, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge(['branch_id' => $this->branch->id, 'is_active' => true], $attributes));
        $user->assignRole($role);

        return $user;
    }

    private function dailyReport(): StaffReport
    {
        $date = today()->subDay()->toDateString();

        return StaffReport::create(['user_id' => $this->staff->id, 'type' => 'daily', 'kind' => 'daily_ops', 'period_key' => $date, 'report_date' => $date,
            'title' => 'Báo cáo ngày '.today()->subDay()->format('d/m/Y'), 'status' => 'submitted', 'content' => '',
            'data' => ['auto' => ['sections' => ['classes' => ['title' => 'Vận hành lớp học', 'icon' => 'groups', 'items' => []]], 'computed_at' => now()->toIso8601String()], 'notes' => []]]);
    }

    public function test_admin_adds_task_inside_a_report_section(): void
    {
        $report = $this->dailyReport();

        $this->actingAs($this->admin)->get(route('reports.detail', $report->id))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Reports/Detail')->where('canAddTasks', true)
                ->where('assignees.0.value', $this->staff->id)->where('assignees.0.label', 'Học vụ Thư (người viết)'));

        // Khối phải có trong báo cáo; tiêu đề và hạn bắt buộc.
        $this->actingAs($this->admin)->post(route('reports.tasks.store', $report->id), [
            'tasks' => [['title' => 'Việc', 'due_date' => today()->toDateString(), 'section' => 'khong_co']],
        ])->assertSessionHasErrors('tasks.0.section');
        $this->actingAs($this->admin)->post(route('reports.tasks.store', $report->id), ['tasks' => []])->assertSessionHasErrors('tasks');

        $this->actingAs($this->admin)->post(route('reports.tasks.store', $report->id), [
            'tasks' => [['title' => 'Rà lại lịch TA ca 17:30', 'content' => 'Hai lớp trùng TA', 'due_date' => today()->addDay()->toDateString(), 'section' => 'classes', 'assignee_id' => $this->staff->id]],
        ])->assertSessionHasNoErrors();

        $task = WorkTask::where('title', 'Rà lại lịch TA ca 17:30')->sole();
        $this->assertSame([$report->id, 'classes', $this->admin->id, $this->staff->id, $this->branch->id],
            [(int) $task->staff_report_id, $task->report_section, (int) $task->creator_id, (int) $task->assignee_id, (int) $task->branch_id]);
        $this->assertStringContainsString('của Học vụ Thư, Admin Trung tâm giao', $task->description);
        $this->assertDatabaseHas('admin_notifications', ['user_id' => $this->staff->id, 'type' => 'task_assigned']);

        // Chi tiết báo cáo và màn của người viết: task nằm đúng khối, ghi "giao bởi Admin".
        $this->actingAs($this->admin)->get(route('reports.detail', $report->id))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('sectionTasks.classes.0.title', 'Rà lại lịch TA ca 17:30')
                ->where('sectionTasks.classes.0.by', 'Admin Trung tâm'));
        $this->actingAs($this->staff)->get(route('reports.periodic.daily', ['date' => $report->period_key]))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('sectionTasks.classes.0.by', 'Admin Trung tâm'));
    }

    public function test_only_admin_adds_tasks_and_unsectioned_reports_are_refused(): void
    {
        $report = $this->dailyReport();
        $manager = $this->makeUser('manager', ['name' => 'Quản lý cơ sở']);
        $payload = ['tasks' => [['title' => 'Việc', 'due_date' => today()->toDateString(), 'section' => 'classes']]];

        $this->actingAs($manager)->post(route('reports.tasks.store', $report->id), $payload)->assertForbidden();

        $legacy = StaffReport::create(['user_id' => $this->staff->id, 'type' => 'daily', 'kind' => 'daily_ops', 'period_key' => '2026-10-01', 'report_date' => '2026-10-01',
            'title' => 'Báo cáo ngày 01/10/2026', 'status' => 'submitted', 'content' => 'cũ', 'data' => ['fields' => []]]);
        $this->actingAs($this->admin)->post(route('reports.tasks.store', $legacy->id), $payload)->assertSessionHasErrors('tasks');
        $this->actingAs($this->admin)->get(route('reports.detail', $legacy->id))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canAddTasks', false));
        $this->assertSame(0, WorkTask::count());
    }

    public function test_follow_up_summary_groups_tasks_by_report_writer(): void
    {
        $report = $this->dailyReport();
        $base = ['branch_id' => $this->branch->id, 'task_type' => 'one_time', 'staff_report_id' => $report->id, 'report_section' => 'classes'];
        WorkTask::create($base + ['title' => 'Đã xong', 'creator_id' => $this->staff->id, 'assignee_id' => $this->staff->id, 'due_date' => today(), 'status' => 'completed']);
        WorkTask::create($base + ['title' => 'Trễ hạn', 'creator_id' => $this->staff->id, 'assignee_id' => $this->staff->id, 'due_date' => today()->subDays(2), 'status' => 'in_progress']);
        WorkTask::create($base + ['title' => 'Admin giao', 'creator_id' => $this->admin->id, 'assignee_id' => $this->staff->id, 'due_date' => today()->addDay(), 'status' => 'new']);
        // Báo cáo trực lớp của trợ giảng.
        $ta = $this->makeUser('assistant', ['name' => 'Trợ giảng Mai']);
        $course = Course::create(['name' => 'Starters', 'code' => 'ST', 'total_lessons' => 24, 'tuition_fee' => 3000000, 'is_active' => true]);
        $class = ClassModel::create(['name' => 'Lớp ST1', 'code' => 'ST1', 'course_id' => $course->id, 'branch_id' => $this->branch->id, 'assistant_id' => $ta->id,
            'start_date' => now()->subMonth(), 'end_date' => now()->addMonth(), 'max_capacity' => 12, 'status' => 'active']);
        $classReport = ClassReport::create(['class_id' => $class->id, 'reporter_id' => $ta->id, 'session_name' => 'Buổi 5', 'session_date' => today()->subDay(),
            'topics_learned' => 'Unit 3', 'status' => 'approved']);
        WorkTask::create(['title' => 'Sửa máy chiếu', 'creator_id' => $ta->id, 'assignee_id' => $this->admin->id, 'branch_id' => $this->branch->id, 'class_id' => $class->id,
            'class_report_id' => $classReport->id, 'due_date' => today()->addDay(), 'task_type' => 'one_time', 'status' => 'new']);
        // Việc giao tay không từ báo cáo: không tính.
        WorkTask::create(['title' => 'Việc thường', 'creator_id' => $this->admin->id, 'assignee_id' => $this->staff->id, 'branch_id' => $this->branch->id,
            'due_date' => today(), 'task_type' => 'one_time', 'status' => 'new']);

        $this->actingAs($this->admin)->get(route('reports.all', ['view' => 'followups']))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Reports/All')->where('view', 'followups')
                ->where('followUps.rows', function ($rows) use ($ta) {
                    $rows = collect($rows)->keyBy('user_id');
                    $this->assertSame([$this->staff->id, $ta->id], $rows->keys()->all());
                    $thu = $rows[$this->staff->id];
                    $this->assertSame([3, 1, 2, 1], [$thu['total'], $thu['done'], $thu['open'], $thu['overdue']]);
                    $this->assertSame('Trễ hạn', $thu['tasks'][0]['title']);
                    $this->assertSame('Admin Trung tâm', collect($thu['tasks'])->firstWhere('title', 'Admin giao')['by']);
                    $this->assertStringContainsString('Báo cáo trực lớp Lớp ST1', $rows[$ta->id]['tasks'][0]['source']);

                    return true;
                }));

        // Lọc theo người viết.
        $this->actingAs($this->admin)->get(route('reports.all', ['view' => 'followups', 'user_id' => $ta->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('followUps.rows', 1)->where('followUps.rows.0.user', 'Trợ giảng Mai'));
    }
}
