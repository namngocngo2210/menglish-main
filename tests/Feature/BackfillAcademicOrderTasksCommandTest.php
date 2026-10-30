<?php

namespace Tests\Feature;

use App\Models\AdminNotification;
use App\Models\BigTestOrder;
use App\Models\SyllabusAssignment;
use App\Models\WorkTask;
use App\Services\Syllabus\SyllabusNotifier;
use App\Services\Syllabus\TestOrderTasks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\BuildsSyllabusWorkflow;
use Tests\TestCase;

class BackfillAcademicOrderTasksCommandTest extends TestCase
{
    use BuildsSyllabusWorkflow, RefreshDatabase;

    private SyllabusAssignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSyllabusWorkflow();
        Cache::flush();
        $this->assignment = $this->openStage($this->stage1);
    }

    public function test_pending_orders_create_academic_tasks_once(): void
    {
        $orders = [];
        foreach (['mini', 'big'] as $type) {
            $orders[] = $this->order(['test_type' => $type]);
        }

        $this->artisan('academic-orders:backfill-tasks')
            ->expectsOutputToContain('Đã tạo 2 việc Học thuật, bỏ qua 0 order.')
            ->assertExitCode(0);

        $this->assertDatabaseCount('work_tasks', 2);
        foreach ($orders as $order) {
            $task = WorkTask::where('big_test_order_id', $order->id)->sole();
            $this->assertSame(WorkTask::KIND_TEST_ORDER, $task->kind);
            $this->assertSame($this->lead->id, $task->assignee_id);
            $this->assertSame($this->teacher->id, $task->creator_id);
            $this->assertSame($this->class->id, $task->class_id);
            $this->assertSame($this->assignment->id, $task->syllabus_assignment_id);
            $this->assertSame($order->due_date->toDateString(), $task->due_date->toDateString());
        }
        $this->assertSame(2, AdminNotification::where('user_id', $this->lead->id)->where('type', SyllabusNotifier::TYPE)->count());
        $notifications = AdminNotification::count();

        $this->artisan('academic-orders:backfill-tasks')
            ->expectsOutputToContain('Đã tạo 0 việc Học thuật, bỏ qua 0 order.')
            ->assertExitCode(0);

        $this->assertDatabaseCount('work_tasks', 2);
        $this->assertDatabaseCount('admin_notifications', $notifications);
    }

    public function test_existing_task_is_untouched(): void
    {
        $order = $this->order();
        $task = app(TestOrderTasks::class)->open($order, $this->assignment, $this->teacher);
        $task->update(['title' => 'Đề đã chuẩn bị', 'status' => 'in_progress', 'due_date' => today()->addDays(20)]);
        $attributes = $task->fresh()->getAttributes();
        $notifications = AdminNotification::count();

        $this->artisan('academic-orders:backfill-tasks')
            ->expectsOutputToContain('Đã tạo 0 việc Học thuật, bỏ qua 0 order.')
            ->assertExitCode(0);

        $this->assertDatabaseCount('work_tasks', 1);
        $this->assertSame($attributes, $task->fresh()->getAttributes());
        $this->assertDatabaseCount('admin_notifications', $notifications);
    }

    public function test_soft_deleted_task_is_not_recreated(): void
    {
        $order = $this->order();
        $task = app(TestOrderTasks::class)->open($order, $this->assignment, $this->teacher);
        $task->delete();
        $attributes = $task->fresh()->getAttributes();
        $notifications = AdminNotification::count();

        $this->artisan('academic-orders:backfill-tasks')
            ->expectsOutputToContain('Đã tạo 0 việc Học thuật, bỏ qua 0 order.')
            ->assertExitCode(0);

        $this->assertDatabaseCount('work_tasks', 1);
        $this->assertSame($attributes, $task->fresh()->getAttributes());
        $this->assertDatabaseCount('admin_notifications', $notifications);
    }

    public function test_dry_run_previews_order_class_and_assignee_without_writes(): void
    {
        $order = $this->order();
        $attributes = $order->fresh()->getAttributes();
        $this->assertDatabaseCount('admin_notifications', 0);

        $this->artisan('academic-orders:backfill-tasks', ['--dry-run' => true])
            ->expectsOutputToContain("#{$order->id} / {$order->code} | {$this->class->name} | sẽ tạo — {$this->lead->name}")
            ->expectsOutputToContain('Chạy thử: sẽ tạo 1 việc Học thuật, bỏ qua 0 order.')
            ->assertExitCode(0);

        $this->assertDatabaseCount('work_tasks', 0);
        $this->assertDatabaseCount('admin_notifications', 0);
        $this->assertSame($attributes, $order->fresh()->getAttributes());
    }

    public function test_legacy_order_uses_open_assignment_matching_class_and_stage(): void
    {
        $order = $this->order(['syllabus_assignment_id' => null]);
        $this->openStage($this->stage1, ['status' => SyllabusAssignment::STATUS_CLOSED]);

        $this->artisan('academic-orders:backfill-tasks')
            ->expectsOutputToContain($order->code)
            ->assertExitCode(0);

        $this->assertSame($this->assignment->id, WorkTask::sole()->syllabus_assignment_id);
        $this->assertNull($order->fresh()->syllabus_assignment_id);
    }

    public function test_explicit_assignment_is_used_even_when_a_newer_assignment_exists(): void
    {
        $order = $this->order();
        $this->assignment->update(['status' => SyllabusAssignment::STATUS_CLOSED]);
        $this->openStage($this->stage1);

        $this->artisan('academic-orders:backfill-tasks')
            ->expectsOutputToContain($order->code)
            ->assertExitCode(0);

        $this->assertSame($this->assignment->id, WorkTask::sole()->syllabus_assignment_id);
    }

    public function test_legacy_order_without_stage_id_uses_latest_assignment_matching_stage_name(): void
    {
        $this->assignment->update(['status' => SyllabusAssignment::STATUS_CLOSED]);
        $latest = $this->openStage($this->stage1, ['status' => SyllabusAssignment::STATUS_CLOSED]);
        $this->openStage($this->stage2);
        $order = $this->order(['syllabus_assignment_id' => null, 'syllabus_stage_id' => null]);

        $this->artisan('academic-orders:backfill-tasks')
            ->expectsOutputToContain($order->code)
            ->assertExitCode(0);

        $this->assertSame($latest->id, WorkTask::sole()->syllabus_assignment_id);
    }

    public function test_approved_and_rejected_orders_are_ignored(): void
    {
        $this->order(['status' => 'approved']);
        $this->order(['status' => 'rejected']);

        $this->artisan('academic-orders:backfill-tasks')
            ->expectsOutputToContain('Đã tạo 0 việc Học thuật, bỏ qua 0 order.')
            ->assertExitCode(0);

        $this->assertDatabaseCount('work_tasks', 0);
        $this->assertDatabaseCount('admin_notifications', 0);
    }

    public function test_orders_without_teacher_or_matching_assignment_are_skipped(): void
    {
        $withoutTeacher = $this->order(['teacher_id' => null]);
        $withoutAssignment = $this->order(['syllabus_assignment_id' => null, 'syllabus_stage_id' => $this->stage2->id]);

        $this->artisan('academic-orders:backfill-tasks')
            ->expectsOutputToContain("{$withoutTeacher->code} | {$this->class->name} | bỏ qua: không tìm thấy giáo viên")
            ->expectsOutputToContain("{$withoutAssignment->code} | {$this->class->name} | bỏ qua: không tìm thấy chặng được giao")
            ->expectsOutputToContain('Đã tạo 0 việc Học thuật, bỏ qua 2 order.')
            ->assertExitCode(0);

        $this->assertDatabaseCount('work_tasks', 0);
        $this->assertDatabaseCount('admin_notifications', 0);
    }

    public function test_order_without_academic_assignee_is_skipped_in_real_and_dry_runs(): void
    {
        $order = $this->order();
        $this->lead->update(['is_active' => false]);

        foreach ([[], ['--dry-run' => true]] as $options) {
            $this->artisan('academic-orders:backfill-tasks', $options)
                ->expectsOutputToContain("{$order->code} | {$this->class->name} | bỏ qua: không có người nhận Học thuật hoặc lớp học")
                ->assertExitCode(0);
        }

        $this->assertDatabaseCount('work_tasks', 0);
        $this->assertDatabaseCount('admin_notifications', 0);
    }

    public function test_order_without_class_is_skipped_in_real_and_dry_runs(): void
    {
        $order = $this->order();
        $this->class->delete();

        foreach ([[], ['--dry-run' => true]] as $options) {
            $this->artisan('academic-orders:backfill-tasks', $options)
                ->expectsOutputToContain("{$order->code} | Không có lớp | bỏ qua: không có người nhận Học thuật hoặc lớp học")
                ->assertExitCode(0);
        }

        $this->assertDatabaseCount('work_tasks', 0);
        $this->assertDatabaseCount('admin_notifications', 0);
    }

    private function order(array $attributes = []): BigTestOrder
    {
        return BigTestOrder::create($attributes + [
            'code' => 'ORDTEST-CU-'.(BigTestOrder::count() + 1),
            'class_id' => $this->class->id,
            'teacher_id' => $this->teacher->id,
            'syllabus_assignment_id' => $this->assignment->id,
            'syllabus_stage_id' => $this->stage1->id,
            'stage_name' => $this->stage1->label,
            'test_type' => 'mini',
            'due_date' => today()->addDays(3)->toDateString(),
            'status' => 'pending',
        ]);
    }
}
