<?php

namespace Tests\Feature;

use App\Models\AcademicRecord;
use App\Models\AdminNotification;
use App\Models\BigTest;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\Course;
use App\Models\DebtReminderRule;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\Penalty;
use App\Models\PlacementTest;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\Survey;
use App\Models\TeacherTimesheet;
use App\Models\User;
use App\Services\NotificationService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModuleCompletionTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private ClassModel $classModel;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'CN Module', 'code' => 'MOD', 'is_active' => true]);
        $course = Course::create(['code' => 'MOD-COURSE', 'name' => 'Khóa Module', 'is_active' => true]);
        $this->classModel = ClassModel::create([
            'code' => 'MOD-CLASS', 'name' => 'Lớp Module', 'course_id' => $course->id,
            'branch_id' => $this->branch->id, 'status' => 'active',
        ]);
        $this->student = Student::create([
            'name' => 'Học viên Module', 'code' => 'HV-MOD01', 'phone' => '0912000111',
            'current_class_id' => $this->classModel->id, 'branch_id' => $this->branch->id, 'status' => 'studying',
        ]);
    }

    private function manager(): User
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $user->assignRole('manager');

        return $user;
    }

    // ─────────────────── P1: Penalty full lifecycle ───────────────────

    public function test_penalty_full_lifecycle_and_payroll_deduction(): void
    {
        $manager = $this->manager();
        $teacher = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $teacher->assignRole('teacher');

        $penalty = Penalty::create([
            'code' => 'BB-2026-001',
            'user_id' => $teacher->id,
            'violation_type' => 'Đến muộn',
            'violation_date' => now()->toDateString(),
            'amount' => 200000,
            'reporter_id' => $manager->id,
            'status' => 'pending',
        ]);

        // confirm_error: xác nhận lỗi, chưa phạt tiền
        $this->actingAs($manager)->post(route('penalties.confirm', $penalty->id), ['decision' => 'error'])->assertRedirect();
        $this->assertSame('confirmed', $penalty->fresh()->status);

        // confirm_fine: quyết phạt — lương sẽ trừ
        $this->actingAs($manager)->post(route('penalties.confirm', $penalty->id), ['decision' => 'fine'])->assertRedirect();
        $this->assertSame('fined', $penalty->fresh()->status);

        // Kỳ lương tính toán phải trừ đúng mức phạt "fined"
        TeacherTimesheet::create([
            'user_id' => $teacher->id, 'class_id' => $this->classModel->id,
            'teaching_date' => now()->toDateString(), 'hours' => 2, 'hourly_rate' => 300000,
            'type' => 'regular', 'status' => 'valid',
        ]);
        $period = PayrollPeriod::create([
            'code' => 'PR-MOD', 'title' => 'Bảng lương Module', 'month' => now()->month, 'year' => now()->year,
            'start_date' => now()->copy()->startOfMonth(), 'end_date' => now()->copy()->endOfMonth(), 'status' => 'draft',
        ]);
        $period->calculatePayrollForPeriod();
        $record = PayrollRecord::where('payroll_period_id', $period->id)->where('user_id', $teacher->id)->firstOrFail();
        $this->assertEquals(200000, $record->penalty_deduction);

        // Duyệt kỳ lương: phạt bị đóng dấu "deducted" để không trừ lần nữa
        $admin = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $admin->assignRole('admin');
        $this->actingAs($admin)->post(route('payroll.periods.approve', $period->id))->assertRedirect();
        $this->assertSame('deducted', $penalty->fresh()->status);
    }

    public function test_penalty_mark_paid_excludes_from_payroll(): void
    {
        $manager = $this->manager();
        $teacher = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $teacher->assignRole('teacher');

        $paidPenalty = Penalty::create([
            'code' => 'BB-2026-002', 'user_id' => $teacher->id, 'violation_type' => 'Chậm nộp',
            'violation_date' => now()->toDateString(), 'amount' => 300000,
            'reporter_id' => $manager->id, 'status' => 'fined',
        ]);
        $this->actingAs($manager)->post(route('penalties.mark-paid', $paidPenalty->id))->assertRedirect();
        $this->assertSame('paid', $paidPenalty->fresh()->status);

        // resolved cũng không trừ lương
        $resolvedPenalty = Penalty::create([
            'code' => 'BB-2026-003', 'user_id' => $teacher->id, 'violation_type' => 'Nhắc nhở',
            'violation_date' => now()->toDateString(), 'amount' => 100000,
            'reporter_id' => $manager->id, 'status' => 'pending',
        ]);
        $this->actingAs($manager)->post(route('penalties.resolve', $resolvedPenalty->id))->assertRedirect();
        $this->assertSame('resolved', $resolvedPenalty->fresh()->status);

        TeacherTimesheet::create([
            'user_id' => $teacher->id, 'class_id' => $this->classModel->id,
            'teaching_date' => now()->toDateString(), 'hours' => 2, 'hourly_rate' => 300000,
            'type' => 'regular', 'status' => 'valid',
        ]);
        $period = PayrollPeriod::create([
            'code' => 'PR-MOD2', 'title' => 'Bảng lương Module 2', 'month' => now()->month, 'year' => now()->year,
            'start_date' => now()->copy()->startOfMonth(), 'end_date' => now()->copy()->endOfMonth(), 'status' => 'draft',
        ]);
        $period->calculatePayrollForPeriod();
        $record = PayrollRecord::where('payroll_period_id', $period->id)->where('user_id', $teacher->id)->firstOrFail();
        $this->assertEquals(0, $record->penalty_deduction, 'Phạt đã nộp trực tiếp / đã đóng vụ không được trừ lương nữa');
    }

    public function test_penalty_actions_are_permission_gated(): void
    {
        $teacher = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $teacher->assignRole('teacher');
        $penalty = Penalty::create([
            'code' => 'BB-2026-004', 'user_id' => $teacher->id, 'violation_type' => 'Test',
            'violation_date' => now()->toDateString(), 'amount' => 50000,
            'status' => 'pending',
        ]);

        // Teacher không có violation.* -> mọi thao tác bị chặn
        $this->actingAs($teacher)->post(route('penalties.confirm', $penalty->id), ['decision' => 'fine'])->assertForbidden();
        $this->actingAs($teacher)->post(route('penalties.mark-paid', $penalty->id))->assertForbidden();
        $this->actingAs($teacher)->post(route('penalties.resolve', $penalty->id))->assertForbidden();
        $this->actingAs($teacher)->post(route('penalties.cancel', $penalty->id))->assertForbidden();
    }

    // ─────────────────── P3: Big Test reminder ───────────────────

    public function test_big_test_reminder_notifies_class_students(): void
    {
        $manager = $this->manager();
        $test = BigTest::create([
            'code' => 'BT-MOD', 'title' => 'Big Test Module', 'class_id' => $this->classModel->id,
            'test_type' => 'midterm', 'scheduled_at' => now()->addDays(2), 'room' => 'P101',
            'is_distributed' => true, 'status' => 'distributed',
        ]);
        $this->classModel->enrollments()->create([
            'student_id' => $this->student->id, 'status' => 'pending', 'enrolled_at' => now(),
        ]);

        $this->actingAs($manager)->post(route('syllabus.big-tests.remind', $test->id))->assertRedirect();

        $this->assertSame(
            1,
            AcademicRecord::where('record_code', 'BIGTEST-REMIND-'.$test->id.'-'.$this->student->id)->count(),
            'Học viên phải nhận được thông báo nhắc lịch trên Cổng PH/HS'
        );

        // Gửi lại không sinh thông báo trùng
        $this->actingAs($manager)->post(route('syllabus.big-tests.remind', $test->id))->assertRedirect();
        $this->assertSame(1, AcademicRecord::where('record_code', 'BIGTEST-REMIND-'.$test->id.'-'.$this->student->id)->count());
    }

    // ─────────────────── P4: Survey admin + portal ───────────────────

    public function test_survey_crud_and_portal_reads_from_database(): void
    {
        $manager = $this->manager();

        $this->actingAs($manager)->post(route('surveys.store'), [
            'title' => 'Khảo sát cơ sở vật chất',
            'description' => 'Đánh giá phòng học và thiết bị',
            'deadline' => now()->addDays(7)->toDateString(),
        ])->assertRedirect();
        $survey = Survey::firstOrFail();

        // Portal hiển thị đợt khảo sát từ DB
        $response = $this->actingAs($this->student->user ?? $manager)->get(route('portal.student.survey'));
        $response->assertOk();
        $this->assertNotEmpty($response->viewData('surveys'), 'Đợt khảo sát đang mở phải hiển thị trên portal');

        // Đóng khảo sát -> biến mất khỏi danh sách đang mở của portal
        $this->actingAs($manager)->put(route('surveys.update', $survey->id), [
            'title' => $survey->title,
            'is_active' => 0,
        ])->assertRedirect();
        $this->flushSession();
        $closed = $this->actingAs($this->student->user ?? $manager)->get(route('portal.student.survey'));
        $closed->assertOk();
        $this->assertEmpty($closed->viewData('surveys'), 'Khảo sát đã đóng không được hiển thị');

        // Gate quyền: teacher không có survey.manage
        $teacher = User::factory()->create(['is_active' => true]);
        $teacher->assignRole('teacher');
        $this->actingAs($teacher)->get(route('surveys.index'))->assertForbidden();
    }

    // ─────────────────── P5: Debt reminder engine ───────────────────

    public function test_debt_reminder_engine_uses_configured_rules(): void
    {
        DebtReminderRule::create([
            'milestone_key' => 'T+3',
            'title' => 'Cảnh báo quá hạn 3 ngày',
            'template_content' => 'Học viên {ten_hoc_vien} lớp {lop_hoc} còn nợ {so_tien}, hạn {han_dong}.',
            'is_enabled' => true,
        ]);

        $tuition = StudentTuition::create([
            'student_id' => $this->student->id,
            'class_id' => $this->classModel->id,
            'branch_id' => $this->branch->id,
            'total_amount' => 5000000, 'final_amount' => 5000000,
            'paid_amount' => 0, 'debt_amount' => 5000000,
            'due_date' => now()->subDays(3)->toDateString(), 'status' => 'overdue',
        ]);

        $result = app(NotificationService::class)->notifyDebtReminderByMilestone($tuition);
        $this->assertTrue($result['sent'], 'Mốc T+3 đã bật phải gửi được');
        $this->assertSame('T+3', $result['milestone']);

        $record = AcademicRecord::where('record_code', 'DEBTREMIND-T+3-'.$tuition->id.'-'.now()->toDateString())->firstOrFail();
        $this->assertStringContainsString($this->student->name, $record->data['content']);
        $this->assertStringContainsString('5.000.000', $record->data['content'], 'Template phải render được số tiền');

        // Tắt rule -> không gửi nữa
        DebtReminderRule::where('milestone_key', 'T+3')->update(['is_enabled' => false]);
        $tuition2 = StudentTuition::create([
            'student_id' => $this->student->id, 'class_id' => $this->classModel->id, 'branch_id' => $this->branch->id,
            'total_amount' => 1000000, 'final_amount' => 1000000, 'paid_amount' => 0, 'debt_amount' => 1000000,
            'due_date' => now()->subDays(5)->toDateString(), 'status' => 'overdue',
        ]);
        $result2 = app(NotificationService::class)->notifyDebtReminderByMilestone($tuition2);
        $this->assertFalse($result2['sent']);
    }

    // ─────────────────── P2: placement test distribute + student status ───────────────────

    public function test_placement_test_distribute_generates_portal_link(): void
    {
        $lead = User::factory()->create(['is_active' => true]);
        $lead->assignRole('academic_lead');

        $test = PlacementTest::create(['code' => 'PT-DIST-01', 'title' => 'Đề phát hành', 'is_active' => false]);

        $this->actingAs($lead)->post(route('placement-tests.distribute', $test->id))->assertRedirect();
        $this->assertTrue($test->fresh()->is_active);
        $this->assertSame(1, AdminNotification::where('title', 'like', '%Phát hành đề%')->count());
    }

    public function test_student_status_change_is_gated_and_whitelisted(): void
    {
        $manager = $this->manager();

        $this->actingAs($manager)->put(route('students.status.update', $this->student->id), [
            'status' => 'deferred',
        ])->assertRedirect();
        $this->assertSame('deferred', $this->student->fresh()->status);

        $this->actingAs($manager)->put(route('students.status.update', $this->student->id), [
            'status' => 'vo-tan',
        ])->assertSessionHasErrors('status');

        // sales không có student.change_status
        $sales = User::factory()->create(['is_active' => true]);
        $sales->assignRole('sales_consultant');
        $this->actingAs($sales)->put(route('students.status.update', $this->student->id), [
            'status' => 'studying',
        ])->assertForbidden();
    }
}
