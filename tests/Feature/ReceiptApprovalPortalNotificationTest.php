<?php

namespace Tests\Feature;

use App\Models\AcademicRecord;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\Course;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\TuitionReceipt;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** TK-2026-0065: thông báo cổng học viên khi duyệt phiếu thu có tên khóa / lý do phụ phí. */
class ReceiptApprovalPortalNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private StudentTuition $tuition;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $branch = Branch::create(['name' => 'CN Cầu Giấy', 'code' => 'CG', 'is_active' => true]);
        $this->admin = User::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);
        $this->admin->assignRole('admin');

        $student = Student::create(['code' => 'HV-65', 'name' => 'Nguyễn Sáu Lăm', 'phone' => '0900000065', 'branch_id' => $branch->id, 'status' => 'studying']);
        $course = Course::create(['code' => 'K65', 'name' => 'Giao tiếp Cơ bản A1', 'total_lessons' => 24, 'is_active' => true]);
        $class = ClassModel::create(['code' => 'L65', 'name' => 'Lớp 65', 'course_id' => $course->id, 'branch_id' => $branch->id, 'status' => 'active']);
        $this->tuition = StudentTuition::create([
            'student_id' => $student->id, 'branch_id' => $branch->id, 'class_id' => $class->id,
            'total_amount' => 6000000, 'final_amount' => 6000000, 'paid_amount' => 0, 'debt_amount' => 6000000,
            'due_date' => now()->addDays(10),
        ]);
    }

    private function approve(float $amount, array $extra = []): AcademicRecord
    {
        $receipt = TuitionReceipt::create(array_merge([
            'receipt_number' => TuitionReceipt::generateReceiptNumber(),
            'student_tuition_id' => $this->tuition->id,
            'student_id' => $this->tuition->student_id,
            'amount' => $amount,
            'tuition_amount' => $amount,
            'payment_method' => 'cash',
            'paper_invoice_number' => 'HDG-0065',
            'payment_date' => now(),
            'creator_id' => $this->admin->id,
            'status' => 'pending',
        ], $extra));

        $this->actingAs($this->admin)->post(route('tuition.receipts.approve.action', $receipt->id))->assertSessionHasNoErrors();

        $records = AcademicRecord::where('record_code', $receipt->receipt_number)->get();
        $this->assertCount(1, $records);

        return $records->first();
    }

    public function test_tuition_receipt_notification_has_course_name_and_amount(): void
    {
        $content = $this->approve(2000000)->data['content'];

        $this->assertStringContainsString('khóa Giao tiếp Cơ bản A1', $content);
        $this->assertStringContainsString('2.000.000', $content);
        $this->assertStringNotContainsString('phụ phí', $content);
    }

    public function test_surcharge_only_receipt_shows_reason_without_course_name(): void
    {
        $content = $this->approve(300000, ['surcharge_amount' => 300000, 'surcharge_reason' => 'Phí làm lại thẻ'])->data['content'];

        $this->assertStringContainsString('phụ phí: Phí làm lại thẻ', $content);
        $this->assertStringNotContainsString('Giao tiếp Cơ bản A1', $content);
    }

    public function test_tuition_plus_surcharge_receipt_shows_both(): void
    {
        $content = $this->approve(2300000, ['surcharge_amount' => 300000, 'surcharge_reason' => 'Phí học liệu'])->data['content'];

        $this->assertStringContainsString('khóa Giao tiếp Cơ bản A1, phụ phí: Phí học liệu', $content);
    }
}
