<?php

namespace Tests\Feature;

use App\Models\AdminNotification;
use App\Models\BigTest;
use App\Models\BigTestOrder;
use App\Models\BigTestResult;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\Student;
use App\Models\SyllabusCurriculum;
use App\Models\User;
use App\Services\SyllabusProgressionService;
use App\Support\Roles;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Scheduling\Schedule;
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

    /** Người duyệt order đề / đề Big Test (chỉ Admin duyệt từ 06/10/2026). */
    private User $admin;

    private User $teacherA;

    private User $teacherB;

    private ClassModel $classA;

    private ClassModel $classB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $branch = Branch::create(['name' => 'Cơ sở BT', 'code' => 'BT2', 'is_active' => true]);
        $this->academic = User::factory()->create(['is_active' => true]);
        $this->academic->assignRole(Roles::ACADEMIC_LEAD);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
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
        // Order đề gửi ở nút ORDER theo mốc syllabus (SyllabusOrderTest kiểm tra chặng, hạn, chặn trùng, thông báo Admin);
        // ở đây tạo sẵn order chờ duyệt của chặng đang mở.
        $curriculum = SyllabusCurriculum::create(['code' => 'CUR-BT2', 'title' => 'GT BT2', 'version' => 'v1', 'stage_name' => 'Present Simple']);
        app(SyllabusProgressionService::class)->open($this->classA, $curriculum->stages()->firstOrFail(), $this->teacherA->id, $this->academic);
        BigTestOrder::create([
            'code' => 'ORDTEST-BT21', 'class_id' => $this->classA->id, 'syllabus_stage_id' => $curriculum->stages()->first()->id, 'teacher_id' => $this->teacherA->id,
            'stage_name' => 'Chặng 1: Present Simple', 'test_type' => 'big', 'exam_date' => $examDate, 'due_date' => now()->addDays(7)->toDateString(),
            'note' => 'Tập trung Speaking', 'status' => 'pending',
        ]);

        $order = BigTestOrder::firstOrFail();

        // Học thuật vẫn xem order đề nhưng không còn nút duyệt / từ chối (chỉ Admin duyệt, 06/10/2026).
        $this->actingAs($this->academic)->get(route('syllabus.big-tests.distribution', ['order' => $order->id]))
            ->assertOk()
            ->assertSee('Chặng 1: Present Simple')
            ->assertSee('Tập trung Speaking')
            ->assertSee($order->due_date->format('d/m/Y'))
            ->assertDontSee(route('syllabus.big-tests.orders.approve', $order->id, absolute: false))
            ->assertDontSee(route('syllabus.big-tests.orders.reject', $order->id, absolute: false));
        $this->actingAs($this->admin)->get(route('syllabus.big-tests.distribution', ['order' => $order->id]))
            ->assertOk()
            ->assertSee('Chặng 1: Present Simple')
            ->assertSee(route('syllabus.big-tests.orders.approve', $order->id, absolute: false))
            ->assertSee(route('syllabus.big-tests.orders.reject', $order->id, absolute: false));

        // Lịch sử order ở Cổng GV đọc cùng bảng (nhãn mockup "Đã order - Chờ HT duyệt")
        $this->actingAs($this->teacherA)->get(route('teacher.order-test', $this->classA->id))->assertOk()->assertSee('Đã order - Chờ HT duyệt');
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
        // Chỉ Admin duyệt / từ chối order đề (06/10/2026): Học thuật bị chặn.
        $this->actingAs($this->academic)->post(route('syllabus.big-tests.orders.approve', $order->id), ['test_link' => 'https://x.test'])->assertForbidden();
        $this->actingAs($this->academic)->post(route('syllabus.big-tests.orders.reject', $other->id), ['rejection_reason' => 'Lý do'])->assertForbidden();
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame('pending', $other->fresh()->status);
        $this->actingAs($this->admin)->post(route('syllabus.big-tests.orders.approve', $order->id))->assertSessionHasErrors('test_link');

        // Order Big Test không gắn đợt thi có sẵn → phải nhập ngày giờ thi + phòng để hệ thống tạo đợt thi.
        $this->actingAs($this->admin)->post(route('syllabus.big-tests.orders.approve', $order->id), [
            'test_link' => 'https://drive.example.com/de-big-test',
        ])->assertSessionHasErrors(['scheduled_at', 'room']);
        $this->actingAs($this->admin)->post(route('syllabus.big-tests.orders.approve', $order->id), [
            'test_link' => 'https://drive.example.com/de-big-test', 'speaking_link' => 'https://drive.example.com/speaking',
            'scheduled_at' => now()->addDays(4)->format('Y-m-d').' 08:00', 'room' => 'P301',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('big_test_orders', [
            'id' => $order->id, 'status' => 'approved', 'test_link' => 'https://drive.example.com/de-big-test', 'reviewed_by' => $this->admin->id,
        ]);
        $created = BigTest::findOrFail($order->fresh()->big_test_id);
        $this->assertTrue($created->is_distributed);
        $this->assertSame('P301', $created->room);
        $this->assertSame('https://drive.example.com/de-big-test', $created->content_url);
        $this->assertSame('https://drive.example.com/speaking', $created->speaking_url);

        $this->actingAs($this->admin)->post(route('syllabus.big-tests.orders.reject', $other->id))->assertSessionHasErrors('rejection_reason');
        $this->actingAs($this->admin)->post(route('syllabus.big-tests.orders.reject', $other->id), ['rejection_reason' => 'Đã có đề chung'])->assertRedirect();
        $this->assertDatabaseHas('big_test_orders', ['id' => $other->id, 'status' => 'rejected', 'rejection_reason' => 'Đã có đề chung']);

        // Giáo viên được thông báo và thấy link đề / lý do ở Cổng GV
        $this->assertTrue(AdminNotification::where('user_id', $this->teacherA->id)->exists());
        // GV chỉ xem phần Speaking của đề sau phân phối; link đề đầy đủ chỉ Học thuật xem.
        $this->actingAs($this->teacherA)->get(route('teacher.order-test', $this->classA->id))
            ->assertOk()->assertSee('https://drive.example.com/speaking')->assertDontSee('https://drive.example.com/de-big-test')->assertSee('Đã có đề chung');
        $this->actingAs($this->teacherA)->get(route('syllabus.big-tests.distribution'))
            ->assertOk()->assertSee('https://drive.example.com/speaking')->assertDontSee('https://drive.example.com/de-big-test');
        $this->actingAs($this->teacherB)->get(route('syllabus.big-tests.distribution'))
            ->assertOk()->assertDontSee('https://drive.example.com/speaking');
        $this->actingAs($this->academic)->get(route('syllabus.big-tests.distribution', ['order' => $order->id, 'order_status' => 'approved']))
            ->assertOk()->assertSee('https://drive.example.com/de-big-test')->assertSee('https://drive.example.com/speaking');
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
        // Đề chưa duyệt → báo thêm riêng người duyệt đề (chỉ Admin, 06/10/2026), không phát thông báo chung; Học thuật không còn nhận.
        $this->assertSame(1, AdminNotification::where('user_id', $this->admin->id)->where('type', 'big_test_upcoming')->count());
        $this->assertSame(0, AdminNotification::where('user_id', $this->academic->id)->where('type', 'big_test_upcoming')->count());
        $this->assertSame(0, AdminNotification::whereNull('user_id')->count());
        // Admin nhận nhắc cá nhân mỗi ngày (đề chưa duyệt trong 7 ngày tới).
        $this->assertSame(1, AdminNotification::where('user_id', $this->admin->id)->where('type', 'big_test_paper_due')->count());
        $this->assertSame(0, AdminNotification::where('user_id', $this->academic->id)->where('type', 'big_test_paper_due')->count());
        $this->assertNotNull($soon->fresh()->teacher_reminded_at);
        $this->assertNull($far->fresh()->teacher_reminded_at);
        $this->assertNull($past->fresh()->teacher_reminded_at);

        // Chạy lại không gửi trùng
        $this->artisan('bigtests:remind-upcoming')->assertSuccessful();
        $this->assertSame(2, AdminNotification::where('type', 'big_test_upcoming')->count());
        $this->assertSame(1, AdminNotification::where('user_id', $this->admin->id)->where('type', 'big_test_paper_due')->count(), 'Nhắc Admin 1 lần / ngày.');
    }

    public function test_reminder_command_is_scheduled_daily(): void
    {
        $events = collect(app(Schedule::class)->events())
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
                ['student_id' => $present->id, 'components' => [['name' => 'Nghe', 'score' => 8], ['name' => 'Viết', 'score' => 6]],
                    'video_url' => 'https://video.example.com/hv-r1.mp4', 'progress_note' => 'Tốt'],
                ['student_id' => $absent->id, 'is_absent' => '1', 'components' => [['name' => '', 'score' => '']]],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $presentResult = BigTestResult::where('student_id', $present->id)->firstOrFail();
        $this->assertSame('https://video.example.com/hv-r1.mp4', $presentResult->video_url);
        $this->assertNull($presentResult->overall_score);
        $this->assertFalse($presentResult->is_absent);

        $absentResult = BigTestResult::where('student_id', $absent->id)->firstOrFail();
        $this->assertTrue($absentResult->is_absent);
        $this->assertNull($absentResult->overall_score);
        $this->assertSame([], $absentResult->scoreComponents());

        // Vắng thi mà vẫn nhập điểm thì bị chặn
        $this->actingAs($this->teacherA)->post(route('syllabus.big-tests.results.store', $test->id), [
            'results' => [['student_id' => $absent->id, 'is_absent' => '1', 'components' => [['name' => 'Nghe', 'score' => 5]]]],
        ])->assertSessionHasErrors('results');

        $this->actingAs($this->academic)->get(route('syllabus.big-tests.results', $test->id))
            ->assertOk()->assertSee('Vắng thi')->assertSee('https://video.example.com/hv-r1.mp4', false);
    }

    /** TC GV-BG-02 (US R-09): GV tự đặt thành phần điểm, không giới hạn thang, không tự tính Overall/xếp loại. */
    public function test_gv_bg_02_teacher_enters_free_components_without_max_or_overall(): void
    {
        $test = $this->makeTest($this->classA, 'BT-FREE', now()->subDay());
        $s1 = Student::create(['code' => 'HV-F1', 'name' => 'HV Tự Do', 'phone' => '0911', 'current_class_id' => $this->classA->id, 'status' => 'studying']);
        $s2 = Student::create(['code' => 'HV-F2', 'name' => 'HV Một Mục', 'phone' => '0912', 'current_class_id' => $this->classA->id, 'status' => 'studying']);
        $store = fn (array $rows) => $this->actingAs($this->teacherA)->post(route('syllabus.big-tests.results.store', $test->id), ['results' => $rows]);

        // Trang nhập điểm: thành phần động, không còn 4 cột kỹ năng / Overall / thang 10.
        $this->actingAs($this->teacherA)->get(route('syllabus.big-tests.results', $test->id))->assertOk()
            ->assertSee('Thành phần điểm')->assertSee('Thêm thành phần')
            ->assertDontSee('Overall')->assertDontSee('Listening')->assertDontSee('Điểm Trung Bình Cả Lớp');

        // Điểm 25 và 150 (> 10) hợp lệ; số lượng/tên thành phần khác nhau giữa học viên; dòng trống bị bỏ qua.
        $store([
            ['student_id' => $s1->id, 'components' => [['name' => 'Từ vựng', 'score' => 25], ['name' => 'Ngữ pháp', 'score' => 150], ['name' => '', 'score' => '']]],
            ['student_id' => $s2->id, 'components' => [['name' => 'Tổng', 'score' => 9]]],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $r1 = BigTestResult::where('student_id', $s1->id)->firstOrFail();
        $this->assertSame([['name' => 'Từ vựng', 'score' => 25.0], ['name' => 'Ngữ pháp', 'score' => 150.0]], $r1->scoreComponents());
        $this->assertNull($r1->overall_score);
        $this->assertNull($r1->listening_score);
        $this->assertSame('pending_review', $r1->status);

        // Thiếu tên hoặc thiếu điểm → từ chối, không ghi.
        $store([['student_id' => $s1->id, 'components' => [['name' => 'Nghe', 'score' => '']]]])->assertSessionHasErrors('results');
        $store([['student_id' => $s1->id, 'components' => [['name' => '', 'score' => 7]]]])->assertSessionHasErrors('results');
        $this->assertSame(2, BigTestResult::count());

        // Hiển thị lại thành phần đã lưu cho người duyệt (không có điểm tổng).
        $this->actingAs($this->academic)->get(route('syllabus.big-tests.results', ['id' => $test->id, 'result' => $r1->id]))
            ->assertOk()->assertSee('Từ vựng')->assertSee('Ngữ pháp')->assertSee('150');
    }

    public function test_legacy_four_skill_big_test_result_stays_readable(): void
    {
        $test = $this->makeTest($this->classA, 'BT-LEGACY', now()->subDay());
        $s = Student::create(['code' => 'HV-L1', 'name' => 'HV Điểm Cũ', 'phone' => '0913', 'current_class_id' => $this->classA->id, 'status' => 'studying']);
        $legacy = BigTestResult::create([
            'big_test_id' => $test->id, 'student_id' => $s->id, 'listening_score' => 8, 'reading_score' => 7,
            'writing_score' => 6.5, 'speaking_score' => 7.5, 'overall_score' => 7.3, 'status' => 'approved',
        ]);

        $this->assertSame(
            [['name' => 'Nghe', 'score' => 8.0], ['name' => 'Đọc', 'score' => 7.0], ['name' => 'Viết', 'score' => 6.5], ['name' => 'Nói', 'score' => 7.5]],
            $legacy->scoreComponents()
        );
        $this->actingAs($this->academic)->get(route('syllabus.big-tests.results', ['id' => $test->id, 'result' => $legacy->id]))
            ->assertOk()->assertSee('Nghe')->assertSee('Viết')->assertSee('6.5');
        // Dữ liệu cũ không bị đụng khi chỉ xem; cột cũ còn nguyên.
        $this->assertEquals(7.3, (float) $legacy->fresh()->overall_score);
    }

    public function test_results_page_has_per_student_send_button_and_sent_column(): void
    {
        $test = $this->makeTest($this->classA, 'BT-SEND', now()->subDay());
        $s1 = Student::create(['code' => 'HV-S1', 'name' => 'HV Chờ Gửi', 'phone' => '0903', 'parent_phone' => '0913', 'current_class_id' => $this->classA->id, 'status' => 'studying']);
        $s2 = Student::create(['code' => 'HV-S2', 'name' => 'HV Đã Gửi', 'phone' => '0904', 'current_class_id' => $this->classA->id, 'status' => 'studying']);
        $pending = BigTestResult::create(['big_test_id' => $test->id, 'student_id' => $s1->id, 'listening_score' => 7, 'reading_score' => 7, 'writing_score' => 7, 'speaking_score' => 7, 'overall_score' => 7, 'status' => 'approved']);
        BigTestResult::create(['big_test_id' => $test->id, 'student_id' => $s2->id, 'listening_score' => 8, 'reading_score' => 8, 'writing_score' => 8, 'speaking_score' => 8, 'overall_score' => 8, 'status' => 'sent', 'parent_notified' => true, 'notified_at' => now()]);

        $page = $this->actingAs($this->academic)->get(route('syllabus.big-tests.results', $test->id));
        $page->assertOk()
            ->assertSee('Đã báo PH')
            ->assertSee(route('syllabus.big-tests.mark-single-notified', $pending->id, absolute: false))
            ->assertSee('Đánh dấu đã báo phụ huynh');

        // Giáo viên không có nút gửi
        $this->actingAs($this->teacherA)->get(route('syllabus.big-tests.results', $test->id))
            ->assertOk()->assertDontSee(route('syllabus.big-tests.mark-single-notified', $pending->id, absolute: false));

        $this->actingAs($this->academic)->post(route('syllabus.big-tests.mark-single-notified', $pending->id))->assertRedirect()->assertSessionHas('status');
        $this->assertTrue($pending->fresh()->parent_notified);
        $this->assertSame('sent', $pending->fresh()->status);
    }

    public function test_absent_result_is_not_sent_to_parents(): void
    {
        $test = $this->makeTest($this->classA, 'BT-ABS', now()->subDay());
        $s = Student::create(['code' => 'HV-A1', 'name' => 'HV Vắng', 'phone' => '0905', 'current_class_id' => $this->classA->id, 'status' => 'studying']);
        $res = BigTestResult::create(['big_test_id' => $test->id, 'student_id' => $s->id, 'is_absent' => true, 'status' => 'approved']);

        $this->actingAs($this->academic)->post(route('syllabus.big-tests.mark-single-notified', $res->id))->assertSessionHas('error');
        $this->actingAs($this->academic)->post(route('syllabus.big-tests.mark-notified', $test->id))->assertSessionHas('error');
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
        // Props trang Vue: learningProgress.latest_big_test là điểm các thành phần của bài Big Test gần nhất (dữ liệu cũ 4 kỹ năng vẫn đọc được).
        $this->assertSame('Nghe 7 · Đọc 7 · Viết 8 · Nói 7.2', $response->viewData('page')['props']['learningProgress']['latest_big_test']);
    }
}
