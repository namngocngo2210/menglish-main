<?php

namespace Tests\Feature;

use App\Models\AdminNotification;
use App\Models\BigTestOrder;
use App\Models\ClassLessonProgress;
use App\Models\ClassSession;
use App\Models\MaterialOrder;
use App\Models\SyllabusAssignment;
use App\Models\SyllabusLesson;
use App\Services\Syllabus\SyllabusOrders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsSyllabusWorkflow;
use Tests\TestCase;

/** Nút ORDER theo mốc syllabus (docs/kho-syllabus.md §9.3; Bước 7, Sheet 4 câu 5). */
class SyllabusOrderTest extends TestCase
{
    use BuildsSyllabusWorkflow, RefreshDatabase;

    private SyllabusAssignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSyllabusWorkflow();
        Cache::flush();
        $this->assignment = $this->openStage($this->stage1, ['start_date' => today()->subDays(10), 'opened_at' => today()->subDays(10), 'confirmed_at' => now()]);
        // Buổi 1: Order in ấn; buổi 2: Order mini test (Mini test ở buổi 3); buổi 4: Order Big Test (Big test ở buổi 6).
        $this->lesson(1)->update(['notice_milestone' => 'order_print']);
        $this->lesson(2)->update(['notice_milestone' => 'order_mini_test']);
        $this->lesson(3)->update(['lesson_type' => 'mini_test']);
        $this->lesson(4)->update(['notice_milestone' => 'order_big_test']);
        $this->lesson(6)->update(['lesson_type' => 'big_test']);
        foreach (range(1, 6) as $d) {
            ClassSession::create(['class_id' => $this->class->id, 'branch_id' => $this->branch->id, 'date' => today()->addDays($d * 2)->toDateString(),
                'start_time' => '18:00', 'end_time' => '19:30', 'type' => 'regular', 'status' => 'scheduled', 'teacher_id' => $this->teacher->id]);
        }
    }

    public function test_order_button_counts_due_milestones_for_teacher(): void
    {
        $this->assertSame(1, app(SyllabusOrders::class)->dueCount($this->teacher), 'Buổi hiện tại (buổi 1) có mốc Order in ấn.');

        $this->markLessons(1);
        Cache::flush();
        $this->actingAs($this->teacher)->get(route('teacher.orders'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('shell.order.count', 2)->where('classId', $this->class->id)->has('milestones', 3)->etc());
        $this->actingAs($this->staff)->get(route('dashboard'))->assertInertia(fn (AssertableInertia $page) => $page->where('shell.order', null)->etc());
    }

    public function test_print_order_goes_to_branch_operations_with_use_date_of_next_test(): void
    {
        $this->actingAs($this->teacher)->post(route('teacher.orders.store', $this->class->id), ['lesson_id' => $this->lesson(1)->id, 'quantity' => 12, 'note' => 'In workbook'])
            ->assertSessionHasNoErrors();

        $order = MaterialOrder::sole();
        $this->assertSame([MaterialOrder::CATEGORY_PRINTING, $this->branch->id, $this->class->id, $this->assignment->id, $this->lesson(1)->id, 12],
            [$order->category, $order->branch_id, $order->class_id, $order->syllabus_assignment_id, $order->syllabus_lesson_id, $order->quantity]);
        $this->assertSame(today()->addDays(6)->toDateString(), $order->use_date->toDateString(), 'Ngày dùng = buổi Mini test kế tiếp (buổi 3).');
        $this->assertSame(0, app(SyllabusOrders::class)->dueCount($this->teacher));
    }

    public function test_test_order_goes_to_academic_with_due_three_sessions_before_test(): void
    {
        $this->markLessons(3);

        $this->actingAs($this->teacher)->post(route('teacher.orders.store', $this->class->id), ['lesson_id' => $this->lesson(4)->id])->assertSessionHasNoErrors();

        $order = BigTestOrder::sole();
        $this->assertSame(['big', $this->lesson(4)->id, 'pending'], [$order->test_type, $order->syllabus_lesson_id, $order->status]);
        // Đã dạy 3 buổi: buổi 4,5,6 dự kiến vào 3 buổi học tới (+2, +4, +6 ngày) → thi buổi 6; hạn = buổi thứ 3 trước đó (đã dạy).
        $this->assertSame(today()->addDays(6)->toDateString(), $order->exam_date->toDateString());
        $this->assertTrue($order->due_date->lte($order->exam_date->copy()->subDays(1)));
    }

    public function test_same_milestone_cannot_be_ordered_twice_and_not_before_due(): void
    {
        $url = route('teacher.orders.store', $this->class->id);
        $this->actingAs($this->teacher)->post($url, ['lesson_id' => $this->lesson(4)->id])->assertSessionHasErrors('lesson_id');
        $this->actingAs($this->teacher)->post($url, ['lesson_id' => $this->lesson(1)->id])->assertSessionHasNoErrors();
        $this->actingAs($this->foreignTeacher)->post($url, ['lesson_id' => $this->lesson(1)->id])->assertSessionHasErrors('lesson_id');

        $this->assertSame(1, MaterialOrder::count());
        $this->actingAs($this->staff)->post($url, ['lesson_id' => $this->lesson(1)->id])->assertNotFound();
    }

    public function test_due_alert_sent_once_per_milestone(): void
    {
        $this->artisan('syllabus:order-due-alerts')->assertSuccessful();
        $this->artisan('syllabus:order-due-alerts')->assertSuccessful();

        $notification = AdminNotification::where('user_id', $this->teacher->id)->sole();
        $this->assertSame('Đến hạn Order in ấn lớp Lớp QT 01.', $notification->title);
        $this->assertSame(['label' => 'ORDER', 'url' => route('teacher.orders', ['class' => $this->class->id]), 'method' => 'get'], $notification->action);
    }

    private function lesson(int $no): SyllabusLesson
    {
        return SyllabusLesson::where('curriculum_id', $this->curriculum->id)->where('session_no', $no)->firstOrFail();
    }

    private function markLessons(int $count): void
    {
        foreach (range(1, $count) as $no) {
            ClassLessonProgress::create(['syllabus_assignment_id' => $this->assignment->id, 'class_id' => $this->class->id, 'syllabus_lesson_id' => $this->lesson($no)->id,
                'kind' => 'lesson', 'taught_by' => $this->teacher->id, 'taught_at' => today()->subDays(10 - $no)]);
        }
    }
}
