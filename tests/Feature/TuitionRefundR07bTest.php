<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\Course;
use App\Models\InvoiceConfiguration;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\TuitionReceipt;
use App\Models\TuitionRefundRequest;
use App\Models\User;
use App\Support\TuitionPaymentHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class TuitionRefundR07bTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $accountant;

    private Student $student;

    private StudentTuition $tuition;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $this->seed(\Database\Seeders\RoleSeeder::class);
        Storage::fake(TuitionRefundRequest::PROOF_DISK);

        $branch = Branch::create(['name' => 'R07b Branch', 'code' => 'R07B', 'is_active' => true]);
        $this->admin = User::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);
        $this->admin->assignRole('admin');
        $this->accountant = User::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);
        $this->accountant->assignRole('accountant');
        foreach ([null, $branch->id] as $branchId) {
            InvoiceConfiguration::create(['branch_id' => $branchId, 'template_code' => '1/001',
                'series_code' => $branchId ? 'C26R07B' : 'C26DEFAULT', 'start_number' => 1, 'current_number' => 500, 'is_active' => true]);
        }

        $course = Course::create(['code' => 'R07B-C', 'name' => 'R07b Course', 'tuition_fee' => 6000000, 'total_lessons' => 33, 'is_active' => true]);
        $class = ClassModel::create(['code' => 'R07B-A', 'name' => 'R07b Class', 'course_id' => $course->id,
            'branch_id' => $branch->id, 'max_capacity' => 10, 'tuition_fee' => 6000000, 'status' => 'active']);
        $this->student = Student::create(['code' => 'HV-R07B-1', 'name' => 'Refund Student', 'phone' => '0912345678',
            'branch_id' => $branch->id, 'status' => 'studying', 'current_class_id' => $class->id]);
        $this->tuition = StudentTuition::create(['student_id' => $this->student->id, 'branch_id' => $branch->id,
            'class_id' => $class->id, 'session_count' => 33, 'total_amount' => 6000000, 'final_amount' => 6000000,
            'paid_amount' => 0, 'debt_amount' => 6000000, 'due_date' => now()->addDays(10), 'status' => 'unpaid']);
        TuitionReceipt::create(['receipt_number' => TuitionReceipt::generateReceiptNumber(), 'student_tuition_id' => $this->tuition->id,
            'student_id' => $this->student->id, 'amount' => 5000000, 'tuition_amount' => 5000000, 'payment_method' => 'cash',
            'payment_date' => now(), 'creator_id' => $this->accountant->id, 'approver_id' => $this->admin->id,
            'approved_at' => now(), 'status' => 'approved', 'invoice_number' => 'OLD-R07B-1']);
        $this->tuition->recalculateDebt();
    }

    private function refund(array $extra = []): TuitionRefundRequest
    {
        return TuitionRefundRequest::create($extra + ['student_id' => $this->student->id, 'type' => 'refund',
            'refund_amount' => 1000000, 'reason' => 'Family moved away', 'no_transfer_reason' => 'No recipient available',
            'requester_id' => $this->accountant->id, 'status' => 'pending']);
    }

    private function approve(TuitionRefundRequest $refund): void
    {
        $this->actingAs($this->admin)->post(route('tuition.refunds.approve', $refund->id), [
            'proof_image' => UploadedFile::fake()->image('refund-proof.png'),
        ])->assertSessionHasNoErrors();
    }

    public function test_suggested_refund_is_the_entire_remaining_value_without_a_fee(): void
    {
        $this->tuition->update(['total_amount' => 725695, 'final_amount' => 725695]);
        $this->tuition->receipts()->sole()->update(['amount' => 725695, 'tuition_amount' => 725695]);
        $this->tuition->recalculateDebt();

        // The displayed unit price rounds to 21,991; all 33 sessions remain unused.
        $this->actingAs($this->accountant)->get(route('tuition.refunds'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Tuition/Refunds')
                ->missing('adminFeePercent')
                ->missing('studentFinance.'.$this->student->id.'.admin_fee')
                ->where('studentFinance.'.$this->student->id.'.remaining_sessions', 33)
                ->where('studentFinance.'.$this->student->id.'.unit_price', 21990.76)
                ->where('studentFinance.'.$this->student->id.'.remaining_value', 725695)
                ->where('studentFinance.'.$this->student->id.'.suggested_refund', 725695));
    }

    public function test_creation_ignores_admin_fee_and_preserves_a_hand_edited_refund_amount(): void
    {
        $this->actingAs($this->accountant)->post(route('tuition.refunds.store'), [
            'student_id' => $this->student->id, 'type' => 'refund', 'refund_amount' => 123456,
            'admin_fee' => 99999, 'reason' => 'Family moved away', 'no_transfer_reason' => 'No recipient available',
        ])->assertSessionHasNoErrors();

        $refund = TuitionRefundRequest::sole();
        $this->assertEquals(0, (float) $refund->admin_fee);
        $this->assertEquals(123456, (float) $refund->refund_amount);
    }

    public function test_approval_adjusts_balances_without_a_receipt_or_invoice_and_writes_an_audit_log(): void
    {
        $this->freezeTime();
        $refund = $this->refund();
        $receiptsBefore = TuitionReceipt::count();
        $invoiceNumbersBefore = InvoiceConfiguration::orderBy('id')->pluck('current_number', 'id')->all();

        $this->approve($refund);

        $this->assertSame('approved', $refund->fresh()->status);
        $this->assertTrue($refund->fresh()->balance_adjusted);
        $this->assertSame($receiptsBefore, TuitionReceipt::count());
        $this->assertSame(0, TuitionReceipt::where('transaction_code', 'like', 'REFUND-%')->count());
        $this->assertSame($invoiceNumbersBefore, InvoiceConfiguration::orderBy('id')->pluck('current_number', 'id')->all());
        $this->tuition->refresh();
        $this->assertEquals(4000000, (float) $this->tuition->paid_amount);
        $this->assertEquals(5000000, (float) $this->tuition->final_amount);
        $this->assertEquals(1000000, (float) $this->tuition->debt_amount);
        $this->assertStringContainsString('1.000.000', $this->tuition->notes);
        $this->tuition->recalculateDebt();
        $this->assertEquals(4000000, (float) $this->tuition->paid_amount);
        $this->assertEquals(1000000, (float) $this->tuition->debt_amount);

        $log = Activity::where('log_name', 'tuition_refund')->sole();
        $this->assertTrue($log->subject->is($refund));
        $this->assertTrue($log->causer->is($this->admin));
        $this->assertSame(false, $log->properties['duyet_tre_han']);
        $this->assertEquals(1000000, $log->properties['amount']);
        $this->assertEquals(5000000, $log->properties['before']['paid_amount']);
        $this->assertEquals(6000000, $log->properties['before']['final_amount']);
        $this->assertEquals(1000000, $log->properties['before']['debt_amount']);
        $this->assertEquals(4000000, $log->properties['after']['paid_amount']);
        $this->assertEquals(5000000, $log->properties['after']['final_amount']);
        $this->assertEquals(1000000, $log->properties['after']['debt_amount']);
        $this->assertStringContainsString('no receipt, no invoice number', $log->description);

        foreach ([false, true] as $forStaff) {
            $history = TuitionPaymentHistory::forStudent($this->student, $forStaff);
            $this->assertCount(1, $history['payments']);
            $this->assertSame('payment', $history['payments'][0]['kind']);
            $this->assertEquals(4000000, $history['summary']['paid_amount']);
            $this->assertEquals(1000000, $history['summary']['debt_amount']);
        }

        $this->actingAs($this->admin)->post(route('tuition.refunds.approve', $refund->id))->assertSessionHasErrors('refund');
        $this->assertSame(1, Activity::where('log_name', 'tuition_refund')->count());
        $this->assertEquals(4000000, (float) $this->tuition->fresh()->paid_amount);
    }

    public function test_approval_logs_overdue_refund_after_a_week_without_changing_amounts(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-15 12:00:00'));
        $refund = $this->refund();
        TuitionRefundRequest::whereKey($refund->id)->update(['created_at' => now()->subDays(10)]);
        $refund = $refund->fresh();

        $this->approve($refund);

        $this->assertSame('approved', $refund->fresh()->status);
        $this->assertEquals(1000000, (float) $refund->fresh()->refund_amount);
        $this->assertEquals(4000000, (float) $this->tuition->fresh()->paid_amount);
        $this->assertEquals(5000000, (float) $this->tuition->fresh()->final_amount);
        $this->assertEquals(1000000, (float) $this->tuition->fresh()->debt_amount);
        $log = Activity::where('log_name', 'tuition_refund')->sole();
        $this->assertSame(true, $log->properties['duyet_tre_han']);
        $this->assertEquals(1000000, $log->properties['amount']);
    }

    public function test_approval_logs_overdue_refund_from_previous_month(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-02 12:00:00'));
        $refund = $this->refund();
        TuitionRefundRequest::whereKey($refund->id)->update(['created_at' => now()->subDays(3)]);
        $refund = $refund->fresh();

        $this->approve($refund);

        $this->assertSame('approved', $refund->fresh()->status);
        $log = Activity::where('log_name', 'tuition_refund')->sole();
        $this->assertSame(true, $log->properties['duyet_tre_han']);
    }

    public function test_balance_adjustment_ignores_unapproved_refunds_and_other_students(): void
    {
        $other = Student::create(['code' => 'HV-R07B-2', 'name' => 'Other Student', 'phone' => '0987654321',
            'branch_id' => $this->student->branch_id, 'status' => 'studying']);
        $this->refund(['balance_adjusted' => true]);
        $this->refund(['status' => 'rejected', 'balance_adjusted' => true]);
        $this->refund(['status' => 'approved', 'balance_adjusted' => true, 'student_id' => $other->id,
            'target_student_id' => $this->student->id]);
        $this->approve($this->refund());

        $this->tuition->refresh()->recalculateDebt();
        $this->assertEquals(4000000, (float) $this->tuition->paid_amount);
        $this->assertEquals(1000000, (float) $this->tuition->debt_amount);
    }

    public function test_legacy_refund_receipts_still_count_once_alongside_new_refunds(): void
    {
        $legacy = $this->refund(['status' => 'approved', 'balance_adjusted' => false, 'admin_fee' => 100000]);
        $receipt = TuitionReceipt::create(['receipt_number' => TuitionReceipt::generateReceiptNumber(),
            'student_tuition_id' => $this->tuition->id, 'student_id' => $this->student->id,
            'amount' => -1000000, 'tuition_amount' => -1000000, 'payment_method' => 'transfer',
            'transaction_code' => 'REFUND-'.$legacy->id, 'payment_date' => now(), 'status' => 'approved',
            'creator_id' => $this->accountant->id, 'approver_id' => $this->admin->id, 'invoice_number' => 'OLD-REFUND-1']);
        $this->tuition->final_amount = 5000000;
        $this->tuition->recalculateDebt();
        $this->assertEquals(4000000, (float) $this->tuition->paid_amount);
        $this->assertEquals(1000000, (float) $this->tuition->debt_amount);

        $this->approve($this->refund(['refund_amount' => 500000]));
        $this->tuition->refresh()->recalculateDebt();
        $this->assertEquals(3500000, (float) $this->tuition->paid_amount);
        $this->assertEquals(4500000, (float) $this->tuition->final_amount);
        $this->assertEquals(1000000, (float) $this->tuition->debt_amount);
        $this->assertFalse($legacy->fresh()->balance_adjusted);
        $this->assertEquals(100000, (float) $legacy->fresh()->admin_fee);
        $this->assertEquals(-1000000, (float) $receipt->fresh()->amount);
        $this->assertSame('OLD-REFUND-1', $receipt->fresh()->invoice_number);

        foreach ([false, true] as $forStaff) {
            $history = TuitionPaymentHistory::forStudent($this->student, $forStaff);
            $this->assertCount(2, $history['payments']);
            $refundRows = collect($history['payments'])->where('kind', 'refund');
            $this->assertCount(1, $refundRows);
            $this->assertSame($receipt->id, $refundRows->sole()['id']);
            $this->assertSame('Hoàn học phí', $refundRows->sole()['title']);
        }
    }
}
