<?php

namespace Tests\Feature;

use App\Models\AdminNotification;
use App\Models\BigTestOrder;
use App\Models\ClassLessonProgress;
use App\Models\ClassSession;
use App\Models\SyllabusAssignment;
use App\Models\SyllabusLesson;
use App\Models\User;
use App\Models\WorkTask;
use App\Services\Syllabus\SyllabusNotifier;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsSyllabusWorkflow;
use Tests\TestCase;

/**
 * TK-2026-0003: order đề (mini test / Big Test) GV gửi qua nút ORDER phải hiện ở Danh sách đầu việc của Học thuật nhận chặng
 * lớp (Người nhận = Học thuật), như nhau với GV full-time và part-time; việc tự đóng khi Admin duyệt / từ chối order.
 */
class SyllabusTestOrderTaskTest extends TestCase
{
    use BuildsSyllabusWorkflow, RefreshDatabase;

    private SyllabusAssignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSyllabusWorkflow();
        Cache::flush();
        $this->assignment = $this->openStage($this->stage1, ['start_date' => today()->subDays(10), 'opened_at' => today()->subDays(10), 'confirmed_at' => now()]);
        $this->lesson(2)->update(['notice_milestone' => 'order_mini_test']);
        $this->lesson(3)->update(['lesson_type' => 'mini_test']);
        $this->lesson(4)->update(['notice_milestone' => 'order_big_test']);
        $this->lesson(6)->update(['lesson_type' => 'big_test']);
        foreach (range(1, 6) as $d) {
            ClassSession::create(['class_id' => $this->class->id, 'branch_id' => $this->branch->id, 'date' => today()->addDays($d * 2)->toDateString(),
                'start_time' => '18:00', 'end_time' => '19:30', 'type' => 'regular', 'status' => 'scheduled', 'teacher_id' => $this->teacher->id]);
        }
        // Đã dạy buổi 1 → buổi hiện tại là buổi 2 (mốc Order mini test đến hạn).
        $this->markLessons(1, 1);
    }

    public function test_fulltime_teacher_test_order_appears_in_academic_task_list_with_academic_recipient(): void
    {
        $this->assertTrue($this->teacher->hasRole(Roles::TEACHER_FULLTIME));
        $this->markLessons(2, 3);

        $this->actingAs($this->teacher)->post(route('teacher.orders.store', $this->class->id), ['lesson_id' => $this->lesson(4)->id, 'note' => 'Đề có Speaking'])
            ->assertSessionHasNoErrors();

        $order = BigTestOrder::sole();
        $task = WorkTask::sole();
        $this->assertSame([WorkTask::KIND_TEST_ORDER, $order->id, $this->lead->id, $this->teacher->id, $this->class->id, $this->assignment->id, 'new'],
            [$task->kind, $task->big_test_order_id, $task->assignee_id, $task->creator_id, $task->class_id, $task->syllabus_assignment_id, $task->status]);
        $this->assertSame($order->due_date->toDateString(), $task->due_date->toDateString(), 'Hạn việc = hạn xử lý của order.');
        $this->assertSame("Chuẩn bị đề Big Test Chặng 1: Làm quen cho lớp Lớp QT 01 (order {$order->code})", $task->title);
        $this->assertStringContainsString('Đề có Speaking', $task->description);

        $notification = AdminNotification::where('user_id', $this->lead->id)->where('type', SyllabusNotifier::TYPE)->sole();
        $this->assertSame(['label' => 'NHẬN VIỆC', 'url' => route('tasks.accept', $task->id), 'method' => 'post'], $notification->action);

        // Màn Danh sách đầu việc của Học thuật (tab "Của tôi"): có việc, Người nhận là Học thuật.
        $this->actingAs($this->lead)->get(route('tasks.index', ['tab' => 'mine']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Tasks/Index')
                ->where('tasks.data.0.id', $task->id)
                ->where('tasks.data.0.assignee', 'Học thuật')
                ->where('tasks.data.0.creator', 'GV Chính')
                ->etc());
    }

    public function test_parttime_teacher_order_creates_the_same_academic_task(): void
    {
        $this->assertTrue($this->foreignTeacher->hasRole(Roles::TEACHER_PARTTIME));

        $this->actingAs($this->foreignTeacher)->post(route('teacher.orders.store', $this->class->id), ['lesson_id' => $this->lesson(2)->id])
            ->assertSessionHasNoErrors();

        $task = WorkTask::sole();
        $this->assertSame([WorkTask::KIND_TEST_ORDER, $this->lead->id, $this->foreignTeacher->id],
            [$task->kind, $task->assignee_id, $task->creator_id]);
        $this->assertStringStartsWith('Chuẩn bị đề Mini Test Chặng 1: Làm quen', $task->title);
    }

    public function test_academic_completes_without_proof_and_admin_decision_closes_open_task(): void
    {
        $url = route('teacher.orders.store', $this->class->id);
        $this->actingAs($this->teacher)->post($url, ['lesson_id' => $this->lesson(2)->id])->assertSessionHasNoErrors();
        // Buổi 2 dạy hôm nay → Mini test (buổi 3) chưa qua; tới buổi 4 thì mốc Order Big Test đến hạn.
        $this->markLessons(2, 3, today());
        $this->actingAs($this->teacher)->post($url, ['lesson_id' => $this->lesson(4)->id])->assertSessionHasNoErrors();
        [$mini, $big] = [BigTestOrder::where('test_type', 'mini')->sole(), BigTestOrder::where('test_type', 'big')->sole()];
        $miniTask = WorkTask::where('big_test_order_id', $mini->id)->sole();
        $bigTask = WorkTask::where('big_test_order_id', $big->id)->sole();

        // Học thuật NHẬN VIỆC rồi HOÀN THÀNH là xong, không cần ảnh / xác nhận.
        $this->actingAs($this->lead)->post(route('tasks.accept', $miniTask->id))->assertRedirect();
        $this->assertSame('in_progress', $miniTask->fresh()->status);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        $this->actingAs($admin)->post(route('syllabus.big-tests.orders.approve', $mini->id), ['test_link' => 'https://drive.example.com/mini'])
            ->assertSessionHasNoErrors();
        $this->assertSame('completed', $miniTask->fresh()->status);
        $this->assertNotNull($miniTask->fresh()->completed_at);

        $this->actingAs($admin)->post(route('syllabus.big-tests.orders.reject', $big->id), ['rejection_reason' => 'Dùng đề chung'])
            ->assertSessionHasNoErrors();
        $this->assertSame('canceled', $bigTask->fresh()->status);
        $this->assertStringContainsString('Dùng đề chung', $bigTask->fresh()->rejection_reason);
    }

    public function test_assignee_can_mark_test_order_task_completed_directly(): void
    {
        $this->markLessons(2, 3);
        $this->actingAs($this->teacher)->post(route('teacher.orders.store', $this->class->id), ['lesson_id' => $this->lesson(4)->id])->assertSessionHasNoErrors();
        $task = WorkTask::sole();
        $this->actingAs($this->lead)->post(route('tasks.accept', $task->id))->assertRedirect();

        $this->actingAs($this->lead)->post(route('tasks.complete', $task->id), ['note' => 'Đã soạn đề'])->assertSessionHasNoErrors();
        $this->assertSame('completed', $task->fresh()->status);
    }

    private function lesson(int $no): SyllabusLesson
    {
        return SyllabusLesson::where('curriculum_id', $this->curriculum->id)->where('session_no', $no)->firstOrFail();
    }

    private function markLessons(int $from, int $to, ?Carbon $at = null): void
    {
        foreach (range($from, $to) as $no) {
            ClassLessonProgress::create(['syllabus_assignment_id' => $this->assignment->id, 'class_id' => $this->class->id, 'syllabus_lesson_id' => $this->lesson($no)->id,
                'kind' => 'lesson', 'taught_by' => $this->teacher->id, 'taught_at' => $at ?? today()->subDays(10 - $no)]);
        }
    }
}
