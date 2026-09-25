<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\Course;
use App\Models\CrmCustomer;
use App\Models\Promotion;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\TuitionReceipt;
use App\Models\User;
use App\Models\WorkTask;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Luật chốt (BA 2026-09-25): không bắt buộc đóng phí (tạo task nhắc thu), lớp tùy chọn
 * (Xếp lớp sau → Chờ xếp lớp → Học vụ gán lớp → Đã chốt), học phí khi chưa có lớp tính theo khóa.
 */
class ClosingWizardRulesTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $sales;

    private User $academic;

    private Course $course;

    private ClassModel $classModel;

    private BankAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Cơ sở chốt', 'code' => 'CHOT', 'is_active' => true]);
        $this->sales = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $this->sales->assignRole('sales_consultant');
        $this->academic = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $this->academic->assignRole('academic_staff');

        $this->course = Course::create(['code' => 'FAM1', 'name' => 'Starters FAM 1', 'tuition_fee' => 10000000, 'total_lessons' => 48, 'is_active' => true]);
        $this->classModel = ClassModel::create([
            'code' => 'FAM1-A', 'name' => 'FAM 1 A', 'course_id' => $this->course->id,
            'branch_id' => $this->branch->id, 'max_capacity' => 2, 'status' => 'active', 'tuition_fee' => 12000000,
        ]);
        $this->bank = BankAccount::create([
            'bank_code' => 'VCB', 'bank_name' => 'Vietcombank', 'account_number' => '123456',
            'account_holder' => 'MENGLISH', 'branch_id' => $this->branch->id, 'is_default_vietqr' => true, 'is_active' => true,
        ]);
    }

    public function test_closing_with_class_and_paid_fee_goes_straight_to_won(): void
    {
        $lead = $this->lead('consulting');

        $this->actingAs($this->sales)->post(route('crm.closing-wizard.store'), $this->payload($lead, [
            'class_id' => $this->classModel->id,
            'fee_paid_at_closing' => 1,
            'paid_amount' => 12000000,
        ]))->assertRedirect(route('crm.customers.won'))->assertSessionHasNoErrors();

        $lead->refresh();
        $student = Student::findOrFail($lead->converted_student_id);
        $this->assertSame('won', $lead->stage);
        $this->assertTrue((bool) $lead->fee_paid_at_closing);
        $this->assertStringStartsWith('HV-', $student->code);
        // Hồ sơ học viên chỉ tạo khi chốt, khởi tạo "Chờ khai giảng" (không có trạng thái Học thử).
        $this->assertSame(Student::INITIAL_STATUS, $student->status);
        $this->assertSame($this->classModel->id, $student->current_class_id);
        $this->assertDatabaseHas('class_enrollments', ['student_id' => $student->id, 'class_id' => $this->classModel->id]);
        $this->assertEquals(12000000, (float) StudentTuition::where('student_id', $student->id)->value('final_amount'));
        $this->assertSame('pending', TuitionReceipt::where('student_id', $student->id)->value('status'));
        $this->assertSame(0, WorkTask::count());
    }

    public function test_closing_without_class_and_unpaid_creates_student_waiting_and_fee_reminder_task(): void
    {
        $promotion = Promotion::create([
            'code' => 'UDFAM', 'name' => 'Giảm 1 triệu', 'type' => 'fixed', 'value' => 1000000,
            'course_id' => $this->course->id, 'is_active' => true,
        ]);
        $lead = $this->lead('tested');

        $this->actingAs($this->sales)->post(route('crm.closing-wizard.store'), $this->payload($lead, [
            'class_id' => null,
            'course_id' => $this->course->id,
            'promotion_id' => $promotion->id,
            'fee_paid_at_closing' => 0,
            'paid_amount' => 0,
        ]))->assertRedirect(route('crm.customers.won'))->assertSessionHasNoErrors();

        $lead->refresh();
        $student = Student::findOrFail($lead->converted_student_id);
        $tuition = StudentTuition::where('student_id', $student->id)->firstOrFail();

        $this->assertSame('waiting_class', $lead->stage);
        $this->assertFalse((bool) $lead->fee_paid_at_closing);
        $this->assertSame($this->course->id, $lead->waiting_course_id);
        $this->assertNull($student->current_class_id);
        $this->assertNotNull($student->user_id);
        $this->assertSame(0, ClassEnrollment::count());
        $this->assertNull($tuition->class_id);
        $this->assertEquals(10000000, (float) $tuition->total_amount);
        $this->assertEquals(1000000, (float) $tuition->discount_amount);
        $this->assertEquals(9000000, (float) $tuition->final_amount);
        $this->assertSame(0, TuitionReceipt::count());

        $task = WorkTask::firstOrFail();
        $this->assertStringContainsString('Nhắc thu học phí', $task->title);
        $this->assertStringContainsString($student->code, $task->title);
        $this->assertSame($this->sales->id, $task->assignee_id);
        $this->assertSame('new', $task->status);
        $this->assertTrue($task->due_date->isSameDay(today()->addDays(3)));
        $this->assertStringContainsString($lead->code, $task->description);
    }

    public function test_closing_with_class_but_unpaid_goes_won_and_creates_fee_reminder_task(): void
    {
        // Q6: không có khái niệm "cọc" — chốt không bắt buộc đóng phí, chưa đóng thì tạo task nhắc thu.
        $lead = $this->lead('result_sent');

        $this->actingAs($this->sales)->post(route('crm.closing-wizard.store'), $this->payload($lead, [
            'class_id' => $this->classModel->id,
            'fee_paid_at_closing' => 0,
            'paid_amount' => 0,
        ]))->assertRedirect(route('crm.customers.won'))->assertSessionHasNoErrors();

        $lead->refresh();
        $student = Student::findOrFail($lead->converted_student_id);
        $this->assertSame('won', $lead->stage);
        $this->assertSame(Student::INITIAL_STATUS, $student->status);
        $this->assertFalse((bool) $lead->fee_paid_at_closing);
        $this->assertSame(0, TuitionReceipt::count());
        $task = WorkTask::firstOrFail();
        $this->assertStringContainsString('Nhắc thu học phí', $task->title);
        $this->assertSame($lead->assigned_user_id, $task->assignee_id);
    }

    public function test_closing_is_allowed_only_from_consulting_tested_or_result_sent(): void
    {
        foreach (['new', 'test_scheduled', 'testing', 'lost'] as $stage) {
            $lead = $this->lead($stage);
            $this->actingAs($this->sales)->post(route('crm.closing-wizard.store'), $this->payload($lead))
                ->assertSessionHasErrors('customer_id');
            $this->assertSame($stage, $lead->fresh()->stage);
        }

        $lead = $this->lead('result_sent');
        $this->actingAs($this->sales)->post(route('crm.closing-wizard.store'), $this->payload($lead))
            ->assertSessionHasNoErrors();
        $this->assertSame('won', $lead->fresh()->stage);
        $this->assertSame(1, Student::count());
    }

    public function test_closing_without_class_requires_a_course(): void
    {
        $lead = $this->lead('consulting');

        $this->actingAs($this->sales)->post(route('crm.closing-wizard.store'), $this->payload($lead, [
            'class_id' => null, 'course_id' => null,
        ]))->assertSessionHasErrors('course_id');

        $this->assertSame(0, Student::count());
    }

    public function test_marking_fee_paid_requires_an_amount(): void
    {
        $lead = $this->lead('consulting');

        $this->actingAs($this->sales)->post(route('crm.closing-wizard.store'), $this->payload($lead, [
            'fee_paid_at_closing' => 1, 'paid_amount' => 0,
        ]))->assertSessionHasErrors('paid_amount');

        $this->assertSame(0, Student::count());
    }

    public function test_won_screen_lists_waiting_students_for_class_assignment(): void
    {
        $lead = $this->closeWithoutClass();

        $this->actingAs($this->academic)->get(route('crm.customers.won'))
            ->assertOk()
            ->assertSee('Chờ xếp lớp (Cần xử lý gấp)')
            ->assertSee($lead->name)
            ->assertSee('Gán lớp')
            ->assertSee($this->classModel->name);
    }

    public function test_academic_assigns_class_to_waiting_student_and_lead_becomes_won(): void
    {
        $lead = $this->closeWithoutClass();
        $student = Student::findOrFail($lead->converted_student_id);

        $this->actingAs($this->academic)->post(route('crm.customers.assign-class', $lead), [
            'class_id' => $this->classModel->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('won', $lead->fresh()->stage);
        $this->assertSame($this->classModel->id, $student->fresh()->current_class_id);
        $this->assertDatabaseHas('class_enrollments', [
            'student_id' => $student->id, 'class_id' => $this->classModel->id, 'customer_id' => $lead->id,
        ]);
        $this->assertSame($this->classModel->id, StudentTuition::where('student_id', $student->id)->value('class_id'));
        $this->assertDatabaseHas('crm_customer_histories', [
            'customer_id' => $lead->id, 'type' => 'stage_change', 'from_stage' => 'waiting_class', 'to_stage' => 'won',
        ]);
    }

    public function test_sales_cannot_assign_class(): void
    {
        $lead = $this->closeWithoutClass();

        $this->actingAs($this->sales)->post(route('crm.customers.assign-class', $lead), [
            'class_id' => $this->classModel->id,
        ])->assertForbidden();

        $this->assertSame('waiting_class', $lead->fresh()->stage);
    }

    public function test_assign_class_checks_course_capacity_and_branch(): void
    {
        $lead = $this->closeWithoutClass();

        $otherCourse = Course::create(['code' => 'MOV', 'name' => 'Movers', 'tuition_fee' => 9000000, 'is_active' => true]);
        $wrongCourse = ClassModel::create([
            'code' => 'MOV-A', 'name' => 'Movers A', 'course_id' => $otherCourse->id,
            'branch_id' => $this->branch->id, 'max_capacity' => 5, 'status' => 'active',
        ]);
        $otherBranch = Branch::create(['name' => 'Cơ sở khác', 'code' => 'KHAC', 'is_active' => true]);
        $wrongBranch = ClassModel::create([
            'code' => 'FAM1-B', 'name' => 'FAM 1 B', 'course_id' => $this->course->id,
            'branch_id' => $otherBranch->id, 'max_capacity' => 5, 'status' => 'active',
        ]);
        $full = ClassModel::create([
            'code' => 'FAM1-FULL', 'name' => 'FAM 1 Full', 'course_id' => $this->course->id,
            'branch_id' => $this->branch->id, 'max_capacity' => 1, 'status' => 'active',
        ]);
        $otherStudent = Student::create(['code' => 'HV-OTHER', 'name' => 'HV khác', 'phone' => '0900000001', 'branch_id' => $this->branch->id, 'status' => 'studying']);
        ClassEnrollment::create(['student_id' => $otherStudent->id, 'class_id' => $full->id, 'enrolled_at' => now(), 'status' => 'pending']);

        foreach ([$wrongCourse, $wrongBranch, $full] as $class) {
            $this->actingAs($this->academic)->post(route('crm.customers.assign-class', $lead), ['class_id' => $class->id])
                ->assertSessionHasErrors('class_id');
        }

        $this->assertSame('waiting_class', $lead->fresh()->stage);
        $this->assertSame(1, ClassEnrollment::count());
    }

    public function test_closing_wizard_offers_no_test_consulting_leads(): void
    {
        $lead = $this->lead('consulting');

        $response = $this->actingAs($this->sales)->get(route('crm.closing-wizard', ['customer_id' => $lead->id]))->assertOk();

        $this->assertSame($lead->id, $response->viewData('customers')->first()->id);
        $response->assertSee('Xếp lớp sau')->assertSee('Đã đóng học phí đăng ký');
    }

    private function closeWithoutClass(): CrmCustomer
    {
        $lead = $this->lead('consulting');
        $this->actingAs($this->sales)->post(route('crm.closing-wizard.store'), $this->payload($lead, [
            'class_id' => null, 'course_id' => $this->course->id, 'fee_paid_at_closing' => 0, 'paid_amount' => 0,
        ]))->assertSessionHasNoErrors();

        return $lead->fresh();
    }

    private function payload(CrmCustomer $lead, array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $lead->id,
            'class_id' => $this->classModel->id,
            'paid_amount' => 12000000,
            'payment_method' => 'transfer',
            'bank_account_id' => $this->bank->id,
        ], $overrides);
    }

    private function lead(string $stage): CrmCustomer
    {
        return CrmCustomer::create([
            'code' => CrmCustomer::generateCode(),
            'name' => 'Khách chốt '.$stage,
            'phone' => '09'.random_int(10000000, 99999999),
            'branch_id' => $this->branch->id,
            'assigned_user_id' => $this->sales->id,
            'stage' => $stage,
        ]);
    }
}
