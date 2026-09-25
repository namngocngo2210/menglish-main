<?php

namespace Tests\Feature;

use App\Models\AdminNotification;
use App\Models\BigTest;
use App\Models\BigTestOrder;
use App\Models\BigTestResult;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassReportStudentSupport;
use App\Models\CrmCustomer;
use App\Models\Student;
use App\Models\SyllabusAdjustmentRequest;
use App\Models\SyllabusAssignment;
use App\Models\SyllabusChangeProposal;
use App\Models\SyllabusCurriculum;
use App\Models\User;
use App\Services\SupportListService;
use App\Services\SyllabusProgressionService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 2 — hoàn thiện: quyền duyệt chỉ cho Học thuật, SĐT phụ huynh trên hồ sơ HV, duyệt order tự tạo đợt Big Test,
 * lưu nháp kết quả, giãn tiến độ cần chặng mở, nhắc lịch theo ngày dự kiến, GV chỉ xem phần Speaking.
 */
class Phase2FinalGapsTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $lead;

    private User $staff;

    private User $teacher;

    private User $assistant;

    private ClassModel $class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        config(['services.zalo.mode' => 'sandbox']);

        $this->branch = Branch::create(['name' => 'Cơ sở Hoàn thiện', 'code' => 'P2F', 'is_active' => true]);
        $this->lead = $this->user('academic_lead');
        $this->staff = $this->user('academic_staff');
        $this->teacher = $this->user('teacher');
        $this->assistant = $this->user('assistant');
        $this->class = ClassModel::create([
            'code' => 'P2F-01', 'name' => 'Lớp Hoàn thiện', 'branch_id' => $this->branch->id, 'teacher_id' => $this->teacher->id,
            'assistant_id' => $this->assistant->id, 'room' => 'P401', 'status' => 'active',
        ]);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['is_active' => true, 'branch_id' => $this->branch->id]);
        $user->assignRole($role);

        return $user;
    }

    private function openStage(): SyllabusAssignment
    {
        $curriculum = SyllabusCurriculum::create(['code' => 'CUR-P2F', 'title' => 'GT hoàn thiện', 'version' => 'v1', 'stage_name' => 'Nền tảng']);

        return app(SyllabusProgressionService::class)->open($this->class, $curriculum->stages()->firstOrFail(), $this->teacher->id, $this->lead);
    }

    private function student(string $code, ?string $parentPhone = '0987000000'): Student
    {
        return Student::create(['code' => $code, 'name' => 'HV '.$code, 'phone' => '0900000000', 'parent_phone' => $parentPhone,
            'branch_id' => $this->branch->id, 'current_class_id' => $this->class->id, 'status' => 'studying']);
    }

    // ── 1. Quyền duyệt ──────────────────────────────────────────────────────

    public function test_only_academic_lead_approves_proposals_adjustments_and_big_test_orders(): void
    {
        $assignment = $this->openStage();
        $this->assertFalse($this->staff->can('syllabus.approve_adjustment'));
        $this->assertFalse($this->staff->can('big_test.approve'));
        $this->assertTrue($this->staff->can('syllabus.manage'), 'Học vụ vẫn soạn giáo trình / tạo đợt thi.');

        $proposal = SyllabusChangeProposal::create(['curriculum_id' => $assignment->curriculum_id, 'user_id' => $this->teacher->id,
            'proposal_type' => 'Khác', 'new_content' => 'Mới', 'reason' => 'Lý do', 'status' => 'pending']);
        $request = SyllabusAdjustmentRequest::create(['class_id' => $this->class->id, 'syllabus_assignment_id' => $assignment->id,
            'user_id' => $this->teacher->id, 'request_type' => 'Giãn', 'reason' => 'Chậm', 'status' => 'pending']);
        $order = BigTestOrder::create(['code' => 'ORDTEST-P2F', 'class_id' => $this->class->id, 'teacher_id' => $this->teacher->id,
            'stage_name' => 'Chặng 1', 'test_type' => 'big', 'status' => 'pending']);

        foreach ([$this->staff, $this->user('manager')] as $user) {
            $this->actingAs($user)->post(route('syllabus.proposals.approve', $proposal->id), ['review_note' => 'OK'])->assertForbidden();
            $this->actingAs($user)->post(route('syllabus.adjustment-requests.approve', $request->id))->assertForbidden();
            $this->actingAs($user)->post(route('syllabus.big-tests.orders.approve', $order->id), ['test_link' => 'https://x.test'])->assertForbidden();
            $this->actingAs($user)->post(route('syllabus.big-tests.orders.reject', $order->id), ['rejection_reason' => 'x'])->assertForbidden();
            $this->actingAs($user)->get(route('syllabus.big-tests.distribution', ['order' => $order->id]))->assertOk()
                ->assertDontSee(route('syllabus.big-tests.orders.approve', $order->id));
        }

        $this->actingAs($this->lead)->post(route('syllabus.proposals.approve', $proposal->id), ['review_note' => 'OK'])->assertRedirect();
        $this->assertSame('approved', $proposal->fresh()->status);
        $this->actingAs($this->lead)->post(route('syllabus.adjustment-requests.approve', $request->id))->assertRedirect();
        $this->assertSame('approved', $request->fresh()->status);
    }

    // ── 2. SĐT phụ huynh trên hồ sơ học viên ─────────────────────────────────

    public function test_parent_contact_is_editable_and_copied_from_crm_on_conversion_backfill(): void
    {
        $student = $this->student('HV-P2F-1', null);
        $this->actingAs($this->staff)->get(route('students.show', $student->id))->assertOk()->assertSee('SĐT phụ huynh');
        $this->actingAs($this->staff)->put(route('students.update', $student->id), [
            'name' => $student->name, 'phone' => $student->phone, 'parent_name' => 'Nguyễn Thị Hoa', 'parent_phone' => 'abc',
        ])->assertSessionHasErrors('parent_phone');
        $this->actingAs($this->staff)->put(route('students.update', $student->id), [
            'name' => $student->name, 'phone' => $student->phone, 'parent_name' => 'Nguyễn Thị Hoa', 'parent_phone' => '0987 654 321',
        ])->assertSessionHasNoErrors();
        $this->assertSame('Nguyễn Thị Hoa', $student->fresh()->parent_name);
        $this->assertSame('0987 654 321', $student->fresh()->parentContactPhone());

        // Backfill: migration chép SĐT phụ huynh của khách CRM đã chốt sang hồ sơ học viên.
        $other = $this->student('HV-P2F-2', null);
        CrmCustomer::create(['code' => 'KH-P2F', 'name' => $other->name, 'phone' => '0900000001', 'phone_normalized' => '0900000001',
            'parent_name' => 'PH P2F', 'parent_phone' => '0912000111', 'stage' => 'won', 'converted_student_id' => $other->id]);
        $migration = require database_path('migrations/2026_10_01_100100_add_parent_contact_to_students.php');
        $migration->down();
        $migration->up();
        $this->assertSame('0912000111', $other->fresh()->parent_phone);
        $this->assertSame('PH P2F', $other->fresh()->parent_name);
    }

    // ── 3 + 8. Duyệt order tự tạo đợt Big Test; GV chỉ xem phần Speaking ─────

    public function test_approving_order_creates_distributed_big_test_on_open_stage_and_teacher_sees_only_speaking(): void
    {
        $assignment = $this->openStage();
        $examDate = today()->addDays(8);
        $this->actingAs($this->teacher)->post(route('teacher.order-test.submit', $this->class->id), [
            'test_type' => 'big', 'exam_date' => $examDate->toDateString(), 'note' => 'Đề cuối chặng',
        ])->assertSessionHasNoErrors();
        $order = BigTestOrder::firstOrFail();

        $this->actingAs($this->lead)->get(route('syllabus.big-tests.distribution', ['order' => $order->id]))->assertOk()
            ->assertSee('Tạo đợt thi mới')->assertSee('Link phần Speaking')->assertSee($examDate->format('Y-m-d').'T08:00');

        $this->actingAs($this->lead)->post(route('syllabus.big-tests.orders.approve', $order->id), [
            'test_link' => 'https://drive.test/full-exam', 'speaking_link' => 'https://drive.test/speaking-only',
            'scheduled_at' => $examDate->format('Y-m-d').' 17:30', 'room' => 'P402',
        ])->assertSessionHasNoErrors();

        $test = BigTest::sole();
        $this->assertSame((int) $order->fresh()->big_test_id, $test->id);
        $this->assertSame($assignment->stage_id, (int) $test->syllabus_stage_id, 'Đợt thi gắn chặng đang mở.');
        $this->assertTrue($test->is_distributed);
        $this->assertSame('distributed', $test->status);
        $this->assertSame($examDate->format('Y-m-d').' 17:30', $test->scheduled_at->format('Y-m-d H:i'));
        $this->assertSame('P402', $test->room);
        $this->assertStringStartsWith('BT-', $test->code);
        $this->assertTrue(AdminNotification::where('user_id', $this->teacher->id)->where('title', 'Order đề đã được duyệt')
            ->where('message', 'like', '%'.$test->code.'%')->exists());

        // GV nhập được kết quả ngay (đề đã phân phối).
        $s = $this->student('HV-P2F-3');
        $this->actingAs($this->teacher)->post(route('syllabus.big-tests.results.store', $test->id), ['results' => [
            ['student_id' => $s->id, 'listening_score' => 8, 'reading_score' => 8, 'writing_score' => 8, 'speaking_score' => 8],
        ]])->assertSessionHasNoErrors();
        $this->assertSame('pending_review', BigTestResult::sole()->status);

        // GV chỉ thấy phần Speaking, trợ giảng không thấy; Học thuật thấy cả link đề đầy đủ.
        foreach ([route('syllabus.big-tests.distribution'), route('teacher.order-test', $this->class->id)] as $url) {
            $this->actingAs($this->teacher)->get($url)->assertOk()->assertSee('https://drive.test/speaking-only')->assertDontSee('https://drive.test/full-exam');
        }
        $this->actingAs($this->assistant)->get(route('syllabus.big-tests.distribution'))->assertOk()->assertDontSee('https://drive.test/speaking-only');
        $this->actingAs($this->lead)->get(route('syllabus.big-tests.distribution'))->assertOk()
            ->assertSee('https://drive.test/full-exam')->assertSee('https://drive.test/speaking-only');
    }

    public function test_order_approval_can_still_link_existing_big_test_and_mini_orders_need_no_exam(): void
    {
        $this->openStage();
        $existing = BigTest::create(['code' => 'BT-P2F-EX', 'title' => 'Đợt có sẵn', 'class_id' => $this->class->id, 'test_type' => 'midterm',
            'scheduled_at' => now()->addDays(5), 'room' => 'Lab', 'is_distributed' => false, 'status' => 'draft']);
        $big = BigTestOrder::create(['code' => 'ORDTEST-EX', 'class_id' => $this->class->id, 'teacher_id' => $this->teacher->id,
            'stage_name' => 'Chặng 1', 'test_type' => 'big', 'status' => 'pending']);
        $mini = BigTestOrder::create(['code' => 'ORDTEST-MINI', 'class_id' => $this->class->id, 'teacher_id' => $this->teacher->id,
            'stage_name' => 'Chặng 1', 'test_type' => 'mini', 'status' => 'pending']);

        $this->actingAs($this->lead)->post(route('syllabus.big-tests.orders.approve', $big->id), [
            'test_link' => 'https://drive.test/ex', 'big_test_id' => $existing->id,
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, BigTest::count(), 'Gắn đợt thi có sẵn thì không tạo đợt mới.');
        $this->assertTrue($existing->fresh()->is_distributed);

        $this->actingAs($this->lead)->post(route('syllabus.big-tests.orders.approve', $mini->id), ['test_link' => 'https://drive.test/mini'])
            ->assertSessionHasNoErrors();
        $this->assertSame('approved', $mini->fresh()->status);
        $this->assertSame(1, BigTest::count());
    }

    // ── 4. Lưu nháp kết quả ──────────────────────────────────────────────────

    public function test_teacher_saves_results_as_draft_then_submits_for_review(): void
    {
        $test = BigTest::create(['code' => 'BT-P2F-D', 'title' => 'Big Test nháp', 'class_id' => $this->class->id, 'test_type' => 'midterm',
            'scheduled_at' => now()->subDay(), 'room' => 'Lab', 'is_distributed' => true, 'status' => 'distributed']);
        $a = $this->student('HV-D1');
        $b = $this->student('HV-D2');
        $c = $this->student('HV-D3');

        // Lưu nháp: cho phép nhập dở, giữ cờ vắng thi, không vào hàng chờ duyệt, không vào danh sách bổ trợ.
        $this->actingAs($this->teacher)->post(route('syllabus.big-tests.results.store', $test->id), ['action' => 'draft', 'results' => [
            ['student_id' => $a->id, 'listening_score' => 5, 'reading_score' => 5, 'writing_score' => 5, 'speaking_score' => 5],
            ['student_id' => $b->id, 'is_absent' => 1],
            ['student_id' => $c->id, 'listening_score' => 9],
        ]])->assertSessionHasNoErrors()->assertSessionHas('status', fn ($m) => str_contains($m, 'nháp'));
        $this->assertSame(3, BigTestResult::where('status', 'draft')->count());
        $this->assertTrue(BigTestResult::where('student_id', $b->id)->value('is_absent'));
        $this->assertNull(BigTestResult::where('student_id', $c->id)->value('overall_score'));
        $this->assertFalse(ClassReportStudentSupport::where('source', SupportListService::SOURCE_BIG_TEST)->exists());

        // Học thuật: bấm duyệt không duyệt bản nháp, không có link "Xem & duyệt", duyệt & gửi 1 HV bị từ chối.
        $this->actingAs($this->lead)->post(route('syllabus.big-tests.results.approve', $test->id))->assertRedirect();
        $this->assertSame(0, BigTestResult::where('status', 'approved')->count());
        $draftA = BigTestResult::where('student_id', $a->id)->firstOrFail();
        $this->actingAs($this->lead)->get(route('syllabus.big-tests.results', $test->id))->assertOk()
            ->assertSee('Nháp (GV chưa gửi duyệt)')->assertDontSee(route('syllabus.big-tests.results', ['id' => $test->id, 'result' => $draftA->id]));
        $this->actingAs($this->lead)->post(route('syllabus.big-tests.results.approve-send', $draftA->id))->assertSessionHas('error');
        $this->assertSame('draft', $draftA->fresh()->status);

        // Bản nháp sửa tiếp được; GV thấy nút Lưu nháp / Gửi duyệt.
        $this->actingAs($this->teacher)->get(route('syllabus.big-tests.results', $test->id))->assertOk()->assertSee('Lưu nháp')->assertSee('Gửi duyệt');
        $this->actingAs($this->teacher)->post(route('syllabus.big-tests.results.store', $test->id), ['action' => 'draft', 'results' => [
            ['student_id' => $a->id, 'listening_score' => 6, 'reading_score' => 6, 'writing_score' => 6, 'speaking_score' => 6],
        ]])->assertSessionHasNoErrors();
        $this->assertEquals(6.0, (float) $draftA->fresh()->overall_score);

        // Gửi duyệt: dòng thiếu kỹ năng bị chặn; gửi đủ thì cả bản nháp đủ điểm còn lại (vắng thi) cũng chờ duyệt.
        $this->actingAs($this->teacher)->post(route('syllabus.big-tests.results.store', $test->id), ['action' => 'submit', 'results' => [
            ['student_id' => $c->id, 'listening_score' => 9],
        ]])->assertSessionHasErrors('results.0.reading_score');
        $this->actingAs($this->teacher)->post(route('syllabus.big-tests.results.store', $test->id), ['action' => 'submit', 'results' => [
            ['student_id' => $a->id, 'listening_score' => 6, 'reading_score' => 6, 'writing_score' => 6, 'speaking_score' => 6],
        ]])->assertSessionHasNoErrors()->assertSessionHas('status', fn ($m) => str_contains($m, 'Còn 1 bản nháp'));
        $this->assertSame('pending_review', $draftA->fresh()->status);
        $this->assertSame('pending_review', BigTestResult::where('student_id', $b->id)->value('status'));
        $this->assertTrue(BigTestResult::where('student_id', $b->id)->value('is_absent'));
        $this->assertSame('draft', BigTestResult::where('student_id', $c->id)->value('status'));
        $this->assertTrue(ClassReportStudentSupport::where('source', SupportListService::SOURCE_BIG_TEST)->where('student_id', $a->id)->exists());

        $this->actingAs($this->lead)->post(route('syllabus.big-tests.results.approve', $test->id))->assertRedirect();
        $this->assertSame(2, BigTestResult::where('status', 'approved')->count());
    }

    // ── 5. Giãn tiến độ cần chặng đang mở ────────────────────────────────────

    public function test_adjustment_request_rejected_without_open_stage(): void
    {
        $this->actingAs($this->teacher)->post(route('syllabus.adjustment-requests.store'), [
            'class_id' => $this->class->id, 'reason' => 'Chậm', 'extra_sessions' => 1,
        ])->assertSessionHasErrors('class_id');
        $this->assertSame(0, SyllabusAdjustmentRequest::count());

        $assignment = $this->openStage();
        $this->actingAs($this->teacher)->post(route('syllabus.adjustment-requests.store'), [
            'class_id' => $this->class->id, 'reason' => 'Chậm', 'extra_sessions' => 1,
        ])->assertSessionHasNoErrors();
        $this->assertSame($assignment->id, (int) SyllabusAdjustmentRequest::sole()->syllabus_assignment_id);
    }

    // ── 6. Nhắc lịch theo ngày dự kiến Big Test ──────────────────────────────

    public function test_reminder_uses_expected_big_test_date_when_no_big_test_exists(): void
    {
        $assignment = $this->openStage();
        $assignment->update(['expected_big_test_date' => today()->addDays(10)]);
        $this->artisan('bigtests:remind-upcoming')->assertSuccessful();
        $this->assertSame(0, AdminNotification::where('type', 'big_test_upcoming')->count(), 'Ngoài 7 ngày thì chưa nhắc.');

        $assignment->update(['expected_big_test_date' => today()->addDays(6)]);
        $this->artisan('bigtests:remind-upcoming')->assertSuccessful();
        $this->artisan('bigtests:remind-upcoming')->assertSuccessful();
        foreach ([$this->teacher, $this->assistant] as $user) {
            $this->assertSame(1, AdminNotification::where('user_id', $user->id)->where('type', 'big_test_upcoming')->count());
        }
        $this->assertSame(1, AdminNotification::whereNull('user_id')->where('type', 'big_test_upcoming')->count(), 'Báo Học thuật 1 lần.');
        $this->assertSame(today()->addDays(6)->toDateString(), $assignment->fresh()->big_test_reminded_for->toDateString());

        // GV đổi ngày dự kiến → nhắc lại theo ngày mới.
        $assignment->update(['expected_big_test_date' => today()->addDays(4)]);
        $this->artisan('bigtests:remind-upcoming')->assertSuccessful();
        $this->assertSame(2, AdminNotification::where('user_id', $this->teacher->id)->where('type', 'big_test_upcoming')->count());

        // Chặng đã có đợt Big Test → không nhắc theo ngày dự kiến nữa (đợt thi được nhắc theo luồng cũ).
        $assignment->update(['expected_big_test_date' => today()->addDays(3)]);
        BigTest::create(['code' => 'BT-P2F-R', 'title' => 'Đợt thi', 'class_id' => $this->class->id, 'syllabus_stage_id' => $assignment->stage_id,
            'test_type' => 'stage_end', 'scheduled_at' => today()->addDays(20), 'room' => 'Lab', 'is_distributed' => false, 'status' => 'draft']);
        $this->artisan('bigtests:remind-upcoming')->assertSuccessful();
        $this->assertSame(2, AdminNotification::where('user_id', $this->teacher->id)->where('type', 'big_test_upcoming')->count());
    }
}
