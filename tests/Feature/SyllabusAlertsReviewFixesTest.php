<?php

namespace Tests\Feature;

use App\Models\BigTestOrder;
use App\Models\Branch;
use App\Models\ClassLessonProgress;
use App\Models\ClassSession;
use App\Models\StaffReport;
use App\Models\SyllabusAssignment;
use App\Models\SyllabusLesson;
use App\Models\WorkTask;
use App\Services\Syllabus\SyllabusOrders;
use App\Support\Rbac;
use App\Support\ReportPeriod;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsSyllabusWorkflow;
use Tests\TestCase;

/** Sửa sau review giai đoạn 4 (docs/kho-syllabus.md §9): mỗi test tái hiện một lỗi review tìm ra. */
class SyllabusAlertsReviewFixesTest extends TestCase
{
    use BuildsSyllabusWorkflow, RefreshDatabase;

    private SyllabusAssignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSyllabusWorkflow();
        Cache::flush();
        $this->assignment = $this->openStage($this->stage1, ['start_date' => today()->subDays(10), 'opened_at' => today()->subDays(10), 'confirmed_at' => now()]);
    }

    public function test_assignee_completes_stage_end_task_from_status_modal(): void
    {
        $task = $this->stageEndTask();
        $this->actingAs($this->lead)->post(route('tasks.accept', $task->id));

        $this->actingAs($this->lead)->post(route('tasks.status.update', $task->id), ['status' => 'completed', 'note' => 'Đã chuẩn bị xong'])
            ->assertSessionHasNoErrors();

        $this->assertSame('completed', $task->fresh()->status);
        $this->assertNotNull($task->fresh()->completed_at);
    }

    public function test_accept_works_after_task_became_overdue(): void
    {
        $task = $this->stageEndTask();
        $task->update(['status' => 'overdue']);

        $this->actingAs($this->lead)->post(route('tasks.accept', $task->id))->assertSessionHasNoErrors();

        $this->assertSame('in_progress', $task->fresh()->status);
    }

    public function test_fallback_assignee_is_branch_academic_lead_not_super_admin(): void
    {
        $otherBranch = Branch::create(['name' => 'Cơ sở khác', 'code' => 'QK', 'is_active' => true]);
        $this->userWithRole(Roles::ACADEMIC_LEAD, 'Học thuật cơ sở khác')->update(['branch_id' => $otherBranch->id]);
        $this->userWithRole(Rbac::SUPER_ADMIN, 'Super Admin')->update(['branch_id' => null]);
        $branchLead = $this->userWithRole(Roles::ACADEMIC_LEAD, 'Học thuật cơ sở QT');
        $this->lead->update(['is_active' => false]);

        $this->assertSame($branchLead->id, $this->stageEndTask()->assignee_id);
    }

    public function test_stage_end_alerts_skip_while_another_run_holds_the_lock(): void
    {
        $this->addSessions([1, 3]);
        $this->markLessons(4);
        $lock = Cache::lock('syllabus:stage-end-alerts', 600);
        $lock->get();

        $this->artisan('syllabus:stage-end-alerts')->assertSuccessful();
        $this->assertSame(0, WorkTask::count());

        $lock->release();
        $this->artisan('syllabus:stage-end-alerts')->assertSuccessful();
        $this->assertSame(1, WorkTask::count());
    }

    public function test_milestones_whose_test_has_passed_are_no_longer_due(): void
    {
        $this->orderMilestones();
        $this->addSessions([2, 4, 6]);
        $this->markLessons(3);

        $due = app(SyllabusOrders::class)->milestones($this->assignment)->filter(fn ($m) => $m['due'] && ! $m['ordered']);

        $this->assertSame([4], $due->map(fn ($m) => $m['lesson']->session_no)->values()->all(), 'Mốc in ấn / mini test của buổi test đã qua không còn đến hạn.');
    }

    public function test_old_flow_test_order_counts_and_rejected_order_does_not(): void
    {
        $this->orderMilestones();
        $this->addSessions([2, 4, 6]);
        $this->markLessons(3);
        $order = BigTestOrder::create(['code' => 'ORDTEST-OLD001', 'class_id' => $this->class->id, 'syllabus_stage_id' => $this->stage1->id, 'teacher_id' => $this->teacher->id,
            'stage_name' => $this->stage1->label, 'test_type' => 'big', 'due_date' => today()->addDay(), 'status' => 'rejected']);

        $this->assertSame(1, app(SyllabusOrders::class)->dueCount($this->teacher), 'Order bị từ chối: GV order lại được.');

        $order->update(['status' => 'pending']);
        Cache::flush();
        $this->assertSame(0, app(SyllabusOrders::class)->dueCount($this->teacher), 'Order đề từ màn cũ cùng chặng / loại đã tính là đã order.');
        $this->actingAs($this->teacher)->post(route('teacher.orders.store', $this->class->id), ['lesson_id' => $this->lesson(4)->id])->assertSessionHasErrors('lesson_id');
    }

    public function test_order_count_refreshes_after_marking_taught(): void
    {
        $this->orderMilestones();
        $today = ClassSession::create(['class_id' => $this->class->id, 'branch_id' => $this->branch->id, 'date' => today()->toDateString(),
            'start_time' => '00:00', 'end_time' => '00:30', 'type' => 'regular', 'status' => 'scheduled', 'teacher_id' => $this->teacher->id]);
        $this->addSessions([2, 4, 6, 8, 10]);
        $this->assertSame(1, app(SyllabusOrders::class)->dueCount($this->teacher));

        $this->actingAs($this->teacher)->post(route('teacher.lesson-today.taught', $this->class->id), ['class_session_id' => $today->id])->assertSessionHasNoErrors();

        $this->assertSame(2, app(SyllabusOrders::class)->dueCount($this->teacher), 'Bấm Đã dạy tới buổi có mốc → nút ORDER cập nhật ngay.');
    }

    public function test_orders_page_falls_back_when_linked_class_is_gone(): void
    {
        $this->actingAs($this->teacher)->get(route('teacher.orders', ['class' => 999999]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('classId', $this->class->id)->etc());
    }

    public function test_notes_not_accepted_on_draft_report(): void
    {
        $report = StaffReport::create(['user_id' => $this->lead->id, 'kind' => 'academic_weekly', 'type' => 'weekly', 'period_key' => ReportPeriod::weekKey(now()->subWeek()),
            'title' => 'Báo cáo tuần nháp', 'report_date' => today()->subWeek(), 'status' => 'draft', 'data' => ['rows' => []]]);

        $this->actingAs($this->lead)->post(route('reports.notes.store', $report->id), ['note' => 'Ghi chú nháp'])->assertForbidden();
        $this->assertSame(0, $report->notes()->count());
    }

    private function stageEndTask(): WorkTask
    {
        $this->addSessions([1, 3]);
        $this->markLessons(4);
        $this->artisan('syllabus:stage-end-alerts')->assertSuccessful();

        return WorkTask::sole();
    }

    /** Buổi 1: Order in ấn; buổi 2: Order mini test (Mini test buổi 3); buổi 4: Order Big Test (Big test buổi 6). */
    private function orderMilestones(): void
    {
        $this->lesson(1)->update(['notice_milestone' => 'order_print']);
        $this->lesson(2)->update(['notice_milestone' => 'order_mini_test']);
        $this->lesson(3)->update(['lesson_type' => 'mini_test']);
        $this->lesson(4)->update(['notice_milestone' => 'order_big_test']);
        $this->lesson(6)->update(['lesson_type' => 'big_test']);
    }

    private function lesson(int $no): SyllabusLesson
    {
        return SyllabusLesson::where('curriculum_id', $this->curriculum->id)->where('session_no', $no)->firstOrFail();
    }

    private function markLessons(int $count): void
    {
        foreach (range(1, $count) as $no) {
            ClassLessonProgress::firstOrCreate(['syllabus_assignment_id' => $this->assignment->id, 'syllabus_lesson_id' => $this->lesson($no)->id],
                ['class_id' => $this->class->id, 'kind' => 'lesson', 'taught_by' => $this->teacher->id, 'taught_at' => today()->subDays(10 - $no)]);
        }
    }

    private function addSessions(array $days): void
    {
        foreach ($days as $d) {
            ClassSession::create(['class_id' => $this->class->id, 'branch_id' => $this->branch->id, 'date' => today()->addDays($d)->toDateString(),
                'start_time' => '18:00', 'end_time' => '19:30', 'type' => 'regular', 'status' => 'scheduled', 'teacher_id' => $this->teacher->id]);
        }
    }
}
