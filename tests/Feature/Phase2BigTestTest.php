<?php

namespace Tests\Feature;

use App\Models\AdminNotification;
use App\Models\BigTest;
use App\Models\BigTestOrder;
use App\Models\BigTestResult;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 2 — Big Test: order đề của GV tới màn duyệt đề, nhắc lịch trước 7 ngày,
 * kết quả có "Vắng thi", link video, gửi PH từng học viên, cổng HV thấy kết quả đã gửi.
 */
class Phase2BigTestTest extends TestCase
{
    use RefreshDatabase;

    private User $academic;

    private User $teacherA;

    private User $teacherB;

    private ClassModel $classA;

    private ClassModel $classB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        config(['services.zalo.mode' => 'sandbox']);

        $branch = Branch::create(['name' => 'Cơ sở BT', 'code' => 'BT2', 'is_active' => true]);
        $this->academic = User::factory()->create(['is_active' => true]);
        $this->academic->assignRole('academic_lead');
        $this->teacherA = User::factory()->create(['is_active' => true]);
        $this->teacherA->assignRole('teacher');
        $this->teacherB = User::factory()->create(['is_active' => true]);
        $this->teacherB->assignRole('teacher');

        $this->classA = ClassModel::create(['code' => 'BT2-A', 'name' => 'Lớp BT A', 'branch_id' => $branch->id, 'teacher_id' => $this->teacherA->id, 'status' => 'active']);
        $this->classB = ClassModel::create(['code' => 'BT2-B', 'name' => 'Lớp BT B', 'branch_id' => $branch->id, 'teacher_id' => $this->teacherB->id, 'status' => 'active']);
    }

    private function makeTest(ClassModel $class, string $code, $when, bool $distributed = true): BigTest
    {
        return BigTest::create([
            'code' => $code, 'title' => 'Big Test '.$code, 'class_id' => $class->id, 'test_type' => 'midterm',
            'scheduled_at' => $when, 'room' => 'Lab', 'passcode' => 'P-'.$code,
            'is_distributed' => $distributed, 'status' => $distributed ? 'distributed' : 'draft',
        ]);
    }

    // ---- 7. Order đề ----

    public function test_teacher_order_reaches_approval_screen_with_deadline(): void
    {
        $examDate = now()->addDays(10)->toDateString();
        $this->actingAs($this->teacherA)->post(route('teacher.order-test.submit', $this->classA->id), [
            'stage_name' => 'Chặng 2: Present Simple', 'test_type' => 'big', 'exam_date' => $examDate, 'note' => 'Tập trung Speaking',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $order = BigTestOrder::firstOrFail();
        $this->assertSame('pending', $order->status);
        $this->assertSame($this->teacherA->id, $order->teacher_id);
        $this->assertSame(now()->addDays(7)->toDateString(), $order->due_date->toDateString());
        $this->assertStringStartsWith('ORDTEST-', $order->code);

        $this->actingAs($this->academic)->get(route('syllabus.big-tests.distribution'))
            ->assertOk()
            ->assertSee('Chặng 2: Present Simple')
            ->assertSee('Tập trung Speaking')
            ->assertSee($order->due_date->format('d/m/Y'))
            ->assertSee(route('syllabus.big-tests.orders.approve', $order->id))
            ->assertSee(route('syllabus.big-tests.orders.reject', $order->id));

        // Lịch sử order ở Cổng GV đọc cùng bảng
        $this->actingAs($this->teacherA)->get(route('teacher.order-test', $this->classA->id))->assertOk()->assertSee('Chờ duyệt');
    }

    public function test_order_approval_requires_link_and_rejection_requires_reason(): void
    {
        $order = BigTestOrder::create([
            'code' => 'ORDTEST-A1', 'class_id' => $this->classA->id, 'teacher_id' => $this->teacherA->id,
            'stage_name' => 'Chặng 1', 'test_type' => 'big', 'due_date' => now()->addDays(3), 'status' => 'pending',
        ]);
        $other = BigTestOrder::create([
            'code' => 'ORDTEST-A2', 'class_id' => $this->classA->id, 'teacher_id' => $this->teacherA->id,
            'stage_name' => 'Chặng 2', 'test_type' => 'mini', 'status' => 'pending',
        ]);

        $this->actingAs($this->teacherA)->post(route('syllabus.big-tests.orders.approve', $order->id), ['test_link' => 'https://x.test'])->assertForbidden();
        $this->actingAs($this->academic)->post(route('syllabus.big-tests.orders.approve', $order->id))->assertSessionHasErrors('test_link');

        $this->actingAs($this->academic)->post(route('syllabus.big-tests.orders.approve', $order->id), [
            'test_link' => 'https://drive.example.com/de-big-test',
        ])->assertRedirect();
        $this->assertDatabaseHas('big_test_orders', [
            'id' => $order->id, 'status' => 'approved', 'test_link' => 'https://drive.example.com/de-big-test', 'reviewed_by' => $this->academic->id,
        ]);

        $this->actingAs($this->academic)->post(route('syllabus.big-tests.orders.reject', $other->id))->assertSessionHasErrors('rejection_reason');
        $this->actingAs($this->academic)->post(route('syllabus.big-tests.orders.reject', $other->id), ['rejection_reason' => 'Đã có đề chung'])->assertRedirect();
        $this->assertDatabaseHas('big_test_orders', ['id' => $other->id, 'status' => 'rejected', 'rejection_reason' => 'Đã có đề chung']);

        // Giáo viên được thông báo và thấy link đề / lý do ở Cổng GV
        $this->assertTrue(AdminNotification::where('user_id', $this->teacherA->id)->exists());
        $this->actingAs($this->teacherA)->get(route('teacher.order-test', $this->classA->id))
            ->assertOk()->assertSee('https://drive.example.com/de-big-test')->assertSee('Đã có đề chung');
    }

    public function test_distribution_and_schedules_are_scoped_to_teacher_classes(): void
    {
        $this->makeTest($this->classA, 'BT-SCOPE-A', now()->addDays(3));
        $this->makeTest($this->classB, 'BT-SCOPE-B', now()->addDays(3));
        BigTestOrder::create(['code' => 'ORDTEST-B1', 'class_id' => $this->classB->id, 'teacher_id' => $this->teacherB->id, 'stage_name' => 'Chặng bí mật B', 'test_type' => 'big', 'status' => 'pending']);

        foreach (['syllabus.big-tests.distribution', 'syllabus.big-tests.schedules'] as $route) {
            $this->actingAs($this->teacherA)->get(route($route))->assertOk()
                ->assertSee('BT-SCOPE-A')->assertDontSee('BT-SCOPE-B')->assertDontSee('Chặng bí mật B');
            $this->actingAs($this->academic)->get(route($route))->assertOk()
                ->assertSee('BT-SCOPE-A')->assertSee('BT-SCOPE-B');
        }
        $this->actingAs($this->teacherA)->get(route('syllabus.big-tests.distribution', ['order' => BigTestOrder::first()->id]))->assertNotFound();
    }

    // ---- 8. Nhắc lịch trước 7 ngày ----

    public function test_reminder_command_notifies_teachers_once_for_tests_within_seven_days(): void
    {
        $soon = $this->makeTest($this->classA, 'BT-SOON', now()->addDays(5), false);
        $far = $this->makeTest($this->classB, 'BT-FAR', now()->addDays(12));
        $past = $this->makeTest($this->classB, 'BT-PAST', now()->subDay());

        $this->artisan('bigtests:remind-upcoming')->assertSuccessful();

        $this->assertSame(1, AdminNotification::where('user_id', $this->teacherA->id)->where('type', 'big_test_upcoming')->count());
        $this->assertSame(0, AdminNotification::where('user_id', $this->teacherB->id)->count());
        // Đề chưa duyệt → báo thêm Học thuật (thông báo chung)
        $this->assertSame(1, AdminNotification::whereNull('user_id')->where('type', 'big_test_upcoming')->count());
        $this->assertNotNull($soon->fresh()->teacher_reminded_at);
        $this->assertNull($far->fresh()->teacher_reminded_at);
        $this->assertNull($past->fresh()->teacher_reminded_at);

        // Chạy lại không gửi trùng
        $this->artisan('bigtests:remind-upcoming')->assertSuccessful();
        $this->assertSame(2, AdminNotification::where('type', 'big_test_upcoming')->count());
    }

    public function test_reminder_command_is_scheduled_daily(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->filter(fn ($e) => str_contains((string) $e->command, 'bigtests:remind-upcoming'));
        $this->assertCount(1, $events);
    }

    public function test_manual_reminder_still_works(): void
    {
        $test = $this->makeTest($this->classA, 'BT-MANUAL', now()->addDays(2));
        Student::create(['code' => 'HV-M1', 'name' => 'HV Nhắc', 'phone' => '0906', 'current_class_id' => $this->classA->id, 'status' => 'studying']);

        $this->actingAs($this->academic)->post(route('syllabus.big-tests.remind', $test->id))->assertRedirect()->assertSessionHas('status');
    }

    // ---- 9. Kết quả Big Test ----

    public function test_absent_marker_and_video_link_are_saved_without_zero_scores(): void
    {
        $test = $this->makeTest($this->classA, 'BT-RES', now()->subDay());
        $present = Student::create(['code' => 'HV-R1', 'name' => 'HV Dự Thi', 'phone' => '0901', 'current_class_id' => $this->classA->id, 'status' => 'studying']);
        $absent = Student::create(['code' => 'HV-R2', 'name' => 'HV Vắng Thi', 'phone' => '0902', 'current_class_id' => $this->classA->id, 'status' => 'studying']);

        $this->actingAs($this->teacherA)->post(route('syllabus.big-tests.results.store', $test->id), [
            'results' => [
                ['student_id' => $present->id, 'listening_score' => 8, 'reading_score' => 7, 'writing_score' => 6, 'speaking_score' => 7,
                    'video_url' => 'https://video.example.com/hv-r1.mp4', 'progress_note' => 'Tốt'],
                ['student_id' => $absent->id, 'is_absent' => '1', 'listening_score' => '', 'reading_score' => '', 'writing_score' => '', 'speaking_score' => ''],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $presentResult = BigTestResult::where('student_id', $present->id)->firstOrFail();
        $this->assertSame('https://video.example.com/hv-r1.mp4', $presentResult->video_url);
        $this->assertEquals(7.0, (float) $presentResult->overall_score);
        $this->assertFalse($presentResult->is_absent);

        $absentResult = BigTestResult::where('student_id', $absent->id)->firstOrFail();
        $this->assertTrue($absentResult->is_absent);
        $this->assertNull($absentResult->overall_score);
        $this->assertNull($absentResult->listening_score);

        // Vắng thi mà vẫn nhập điểm thì bị chặn
        $this->actingAs($this->teacherA)->post(route('syllabus.big-tests.results.store', $test->id), [
            'results' => [['student_id' => $absent->id, 'is_absent' => '1', 'listening_score' => 5, 'reading_score' => 5, 'writing_score' => 5, 'speaking_score' => 5]],
        ])->assertSessionHasErrors('results');

        $this->actingAs($this->academic)->get(route('syllabus.big-tests.results', $test->id))
            ->assertOk()->assertSee('Vắng thi')->assertSee('https://video.example.com/hv-r1.mp4', false);
    }

    public function test_results_page_has_per_student_send_button_and_sent_column(): void
    {
        $test = $this->makeTest($this->classA, 'BT-SEND', now()->subDay());
        $s1 = Student::create(['code' => 'HV-S1', 'name' => 'HV Chờ Gửi', 'phone' => '0903', 'current_class_id' => $this->classA->id, 'status' => 'studying']);
        $s2 = Student::create(['code' => 'HV-S2', 'name' => 'HV Đã Gửi', 'phone' => '0904', 'current_class_id' => $this->classA->id, 'status' => 'studying']);
        $pending = BigTestResult::create(['big_test_id' => $test->id, 'student_id' => $s1->id, 'listening_score' => 7, 'reading_score' => 7, 'writing_score' => 7, 'speaking_score' => 7, 'overall_score' => 7, 'status' => 'approved']);
        BigTestResult::create(['big_test_id' => $test->id, 'student_id' => $s2->id, 'listening_score' => 8, 'reading_score' => 8, 'writing_score' => 8, 'speaking_score' => 8, 'overall_score' => 8, 'status' => 'sent', 'parent_notified' => true, 'notified_at' => now()]);

        $page = $this->actingAs($this->academic)->get(route('syllabus.big-tests.results', $test->id));
        $page->assertOk()
            ->assertSee('Đã gửi PH')
            ->assertSee(route('syllabus.big-tests.send-single-zalo', $pending->id))
            ->assertSee('Gửi PH');

        // Giáo viên không có nút gửi
        $this->actingAs($this->teacherA)->get(route('syllabus.big-tests.results', $test->id))
            ->assertOk()->assertDontSee(route('syllabus.big-tests.send-single-zalo', $pending->id));

        $this->actingAs($this->academic)->post(route('syllabus.big-tests.send-single-zalo', $pending->id))->assertRedirect()->assertSessionHas('status');
        $this->assertTrue($pending->fresh()->parent_notified);
        $this->assertSame('sent', $pending->fresh()->status);
    }

    public function test_absent_result_is_not_sent_to_parents(): void
    {
        $test = $this->makeTest($this->classA, 'BT-ABS', now()->subDay());
        $s = Student::create(['code' => 'HV-A1', 'name' => 'HV Vắng', 'phone' => '0905', 'current_class_id' => $this->classA->id, 'status' => 'studying']);
        $res = BigTestResult::create(['big_test_id' => $test->id, 'student_id' => $s->id, 'is_absent' => true, 'status' => 'approved']);

        $this->actingAs($this->academic)->post(route('syllabus.big-tests.send-single-zalo', $res->id))->assertSessionHas('error');
        $this->actingAs($this->academic)->post(route('syllabus.big-tests.send-zalo', $test->id))->assertSessionHas('error');
        $this->assertFalse($res->fresh()->parent_notified);
    }

    public function test_student_portal_shows_sent_results(): void
    {
        $parent = User::factory()->create();
        $student = Student::create(['user_id' => $parent->id, 'code' => 'HV-P1', 'name' => 'HV Cổng', 'phone' => '0907', 'current_class_id' => $this->classA->id, 'status' => 'studying']);
        $test = $this->makeTest($this->classA, 'BT-PORTAL', now()->subDays(2));
        BigTestResult::create([
            'big_test_id' => $test->id, 'student_id' => $student->id, 'listening_score' => 7, 'reading_score' => 7, 'writing_score' => 8,
            'speaking_score' => 7.2, 'overall_score' => 7.3, 'status' => 'sent', 'parent_notified' => true, 'approved_at' => now(),
        ]);

        $response = $this->actingAs($parent)->get(route('portal.student.home', ['studentId' => $student->id]));
        $response->assertOk();
        $this->assertEquals(7.3, (float) $response->viewData('learningProgress')['latest_big_test']?->overall_score);
    }
}
