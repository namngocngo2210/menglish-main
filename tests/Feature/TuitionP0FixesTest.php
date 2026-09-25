<?php

namespace Tests\Feature;

use App\Models\AcademicRecord;
use App\Models\Branch;
use App\Models\DebtReminderRule;
use App\Models\InvoiceCancellation;
use App\Models\OperatingExpense;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\TuitionReceipt;
use App\Models\TuitionRefundRequest;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TuitionP0FixesTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $admin;

    private User $accountant;

    private User $accountant2;

    private User $staff;

    private Student $student;

    private StudentTuition $tuition;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'CN A', 'code' => 'CNA', 'is_active' => true]);

        $this->admin = $this->makeUser('admin');
        $this->accountant = $this->makeUser('accountant');
        $this->accountant2 = $this->makeUser('accountant');
        $this->staff = $this->makeUser('academic_staff');

        $this->student = Student::create([
            'code' => 'HV-P0-001',
            'name' => 'Nguyễn P Không',
            'phone' => '0900000001',
            'branch_id' => $this->branch->id,
            'status' => 'studying',
        ]);

        $this->tuition = $this->makeTuition($this->student, 5000000);
    }

    private function makeUser(string $role): User
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function makeTuition(Student $student, float $final, ?int $branchId = null): StudentTuition
    {
        return StudentTuition::create([
            'student_id' => $student->id,
            'branch_id' => $branchId ?? $this->branch->id,
            'total_amount' => $final,
            'final_amount' => $final,
            'paid_amount' => 0,
            'debt_amount' => $final,
            'due_date' => now()->addDays(10),
            'status' => 'unpaid',
        ]);
    }

    private function approvedReceipt(StudentTuition $tuition, float $amount, array $extra = []): TuitionReceipt
    {
        $receipt = TuitionReceipt::create(array_merge([
            'receipt_number' => TuitionReceipt::generateReceiptNumber(),
            'invoice_number' => 'C26MEN-'.random_int(100000, 999999),
            'student_tuition_id' => $tuition->id,
            'student_id' => $tuition->student_id,
            'amount' => $amount,
            'payment_method' => 'cash',
            'payment_date' => now(),
            'creator_id' => $this->accountant->id,
            'approver_id' => $this->accountant2->id,
            'status' => 'approved',
        ], $extra));
        $tuition->recalculateDebt();

        return $receipt;
    }

    // ---------------------------------------------------------------------
    // 1. Discount / surcharge
    // ---------------------------------------------------------------------

    public function test_receipt_discount_and_surcharge_settle_debt_to_zero(): void
    {
        $this->actingAs($this->staff)->post(route('tuition.receipts.store'), [
            'student_tuition_id' => $this->tuition->id,
            'tuition_amount' => 4500000,
            'discount_amount' => 500000,
            'surcharge_amount' => 150000,
            'surcharge_reason' => 'Giáo trình',
            'amount' => 4650000,
            'payment_method' => 'cash',
            'submit_action' => 'submit',
        ])->assertSessionHasNoErrors();

        $receipt = TuitionReceipt::where('student_tuition_id', $this->tuition->id)->firstOrFail();
        $this->assertSame('pending', $receipt->status);
        $this->assertEquals(4500000, (float) $receipt->tuition_amount);

        $this->actingAs($this->accountant)
            ->post(route('tuition.receipts.approve.action', $receipt->id))
            ->assertSessionHasNoErrors();

        $this->tuition->refresh();
        $this->assertEquals(4500000, (float) $this->tuition->paid_amount);
        $this->assertEquals(0, (float) $this->tuition->debt_amount);
        $this->assertSame('paid', $this->tuition->status);
    }

    public function test_surcharge_only_receipt_does_not_reduce_tuition_debt(): void
    {
        $receipt = TuitionReceipt::create([
            'receipt_number' => 'PT-SUR-ONLY',
            'student_tuition_id' => $this->tuition->id,
            'student_id' => $this->student->id,
            'amount' => 150000,
            'surcharge_amount' => 150000,
            'surcharge_reason' => 'Thẻ học viên',
            'payment_method' => 'cash',
            'payment_date' => now(),
            'creator_id' => $this->staff->id,
            'status' => 'pending',
        ]);

        $this->actingAs($this->accountant)
            ->post(route('tuition.receipts.approve.action', $receipt->id))
            ->assertSessionHasNoErrors();

        $this->assertSame('approved', $receipt->fresh()->status);
        $this->tuition->refresh();
        $this->assertEquals(0, (float) $this->tuition->paid_amount);
        $this->assertEquals(5000000, (float) $this->tuition->debt_amount);
        $this->assertSame('unpaid', $this->tuition->status);
    }

    public function test_surcharge_cannot_exceed_receipt_amount(): void
    {
        $this->actingAs($this->staff)->post(route('tuition.receipts.store'), [
            'student_tuition_id' => $this->tuition->id,
            'surcharge_amount' => 300000,
            'surcharge_reason' => 'Giáo trình',
            'amount' => 150000,
            'payment_method' => 'cash',
            'submit_action' => 'submit',
        ])->assertSessionHasErrors('surcharge_amount');

        $this->assertSame(0, TuitionReceipt::count());
    }

    // ---------------------------------------------------------------------
    // 2. Receipts never skip approval
    // ---------------------------------------------------------------------

    public function test_receipt_without_submit_action_is_pending_without_invoice(): void
    {
        $this->actingAs($this->staff)->post(route('tuition.receipts.store'), [
            'student_tuition_id' => $this->tuition->id,
            'amount' => 2000000,
            'payment_method' => 'transfer',
            // Phase 4: chuyển khoản / VietQR bắt buộc minh chứng khi gửi duyệt.
            'proof_image_preview' => '/uploads/tuition/receipts/test-proof.png',
        ])->assertSessionHasNoErrors();

        $receipt = TuitionReceipt::firstOrFail();
        $this->assertSame('pending', $receipt->status);
        $this->assertNull($receipt->approver_id);
        $this->assertNull($receipt->invoice_number);
        $this->assertNull($receipt->transaction_code);

        $this->tuition->refresh();
        $this->assertEquals(0, (float) $this->tuition->paid_amount);
        $this->assertEquals(5000000, (float) $this->tuition->debt_amount);
    }

    public function test_academic_staff_cannot_approve_receipt(): void
    {
        $receipt = TuitionReceipt::create([
            'receipt_number' => 'PT-STAFF',
            'student_tuition_id' => $this->tuition->id,
            'amount' => 1000000,
            'payment_method' => 'cash',
            'creator_id' => $this->accountant->id,
            'status' => 'pending',
        ]);

        $this->actingAs($this->staff)
            ->post(route('tuition.receipts.approve.action', $receipt->id))
            ->assertForbidden();

        $this->assertSame('pending', $receipt->fresh()->status);
    }

    public function test_creator_cannot_approve_own_receipt_unless_admin(): void
    {
        $own = TuitionReceipt::create([
            'receipt_number' => 'PT-OWN',
            'student_tuition_id' => $this->tuition->id,
            'amount' => 1000000,
            'payment_method' => 'cash',
            'creator_id' => $this->accountant->id,
            'status' => 'pending',
        ]);

        $this->actingAs($this->accountant)
            ->post(route('tuition.receipts.approve.action', $own->id))
            ->assertSessionHasErrors('receipt');
        $this->assertSame('pending', $own->fresh()->status);
        $this->assertNull($own->fresh()->invoice_number);

        $adminOwn = TuitionReceipt::create([
            'receipt_number' => 'PT-ADMIN-OWN',
            'student_tuition_id' => $this->tuition->id,
            'amount' => 1000000,
            'payment_method' => 'cash',
            'creator_id' => $this->admin->id,
            'status' => 'pending',
        ]);

        $this->actingAs($this->admin)
            ->post(route('tuition.receipts.approve.action', $adminOwn->id))
            ->assertSessionHasNoErrors();
        $this->assertSame('approved', $adminOwn->fresh()->status);
        $this->assertEquals(4000000, (float) $this->tuition->fresh()->debt_amount);
    }

    // ---------------------------------------------------------------------
    // 3. Draft status + edit / resubmit
    // ---------------------------------------------------------------------

    public function test_draft_is_stored_as_draft_and_creator_can_resubmit(): void
    {
        $this->actingAs($this->staff)->post(route('tuition.receipts.store'), [
            'student_tuition_id' => $this->tuition->id,
            'amount' => 1000000,
            'payment_method' => 'cash',
            'submit_action' => 'draft',
        ])->assertSessionHasNoErrors();

        $receipt = TuitionReceipt::firstOrFail();
        $this->assertSame('draft', $receipt->status);

        // Người khác không sửa được phiếu của người lập
        $this->actingAs($this->accountant)
            ->put(route('tuition.receipts.update', $receipt->id), [
                'amount' => 3000000,
                'payment_method' => 'cash',
                'submit_action' => 'submit',
            ])->assertForbidden();

        $this->actingAs($this->staff)
            ->put(route('tuition.receipts.update', $receipt->id), [
                'amount' => 3000000,
                'payment_method' => 'transfer',
                'notes' => 'Sửa lại số tiền',
                'submit_action' => 'submit',
                // Phase 4: chuyển khoản / VietQR bắt buộc minh chứng khi gửi duyệt.
                'proof_image_preview' => '/uploads/tuition/receipts/test-proof.png',
            ])->assertSessionHasNoErrors();

        $receipt->refresh();
        $this->assertSame('pending', $receipt->status);
        $this->assertEquals(3000000, (float) $receipt->amount);
        $this->assertEquals(3000000, (float) $receipt->tuition_amount);
        $this->assertSame('transfer', $receipt->payment_method);
    }

    public function test_rejected_receipt_can_be_resubmitted_but_pending_cannot_be_edited(): void
    {
        $receipt = TuitionReceipt::create([
            'receipt_number' => 'PT-REJ',
            'student_tuition_id' => $this->tuition->id,
            'amount' => 1000000,
            'payment_method' => 'cash',
            'creator_id' => $this->staff->id,
            'status' => 'rejected',
            'rejection_reason' => 'Thiếu minh chứng',
        ]);

        $this->actingAs($this->staff)
            ->put(route('tuition.receipts.update', $receipt->id), [
                'amount' => 1000000,
                'payment_method' => 'cash',
                'submit_action' => 'submit',
            ])->assertSessionHasNoErrors();
        $this->assertSame('pending', $receipt->fresh()->status);
        $this->assertNull($receipt->fresh()->rejection_reason);

        $this->actingAs($this->staff)
            ->put(route('tuition.receipts.update', $receipt->id), [
                'amount' => 2000000,
                'payment_method' => 'cash',
                'submit_action' => 'submit',
            ])->assertSessionHasErrors('receipt');
        $this->assertEquals(1000000, (float) $receipt->fresh()->amount);
    }

    public function test_rejected_today_metric_excludes_drafts(): void
    {
        TuitionReceipt::create([
            'receipt_number' => 'PT-DRAFT-M', 'student_tuition_id' => $this->tuition->id,
            'amount' => 1000000, 'payment_method' => 'cash', 'creator_id' => $this->staff->id, 'status' => 'draft',
        ]);

        $response = $this->actingAs($this->accountant)->get(route('tuition.receipts.approve'));
        $response->assertOk();
        $this->assertSame(0, $response->viewData('rejectedTodayCount'));
    }

    // ---------------------------------------------------------------------
    // 4. Invoice cancellation
    // ---------------------------------------------------------------------

    public function test_invoice_cancellation_resolves_receipt_and_reverts_debt(): void
    {
        $receipt = $this->approvedReceipt($this->tuition, 5000000, ['invoice_number' => 'C26MEN-0001001']);
        $this->assertEquals(0, (float) $this->tuition->fresh()->debt_amount);

        $this->actingAs($this->accountant)->post(route('tuition.invoices.cancellations.store'), [
            'invoice_number' => 'C26MEN-0001001',
            'amount' => 5000000,
            'reason' => 'Sai MST',
        ])->assertSessionHasNoErrors();

        $cancellation = InvoiceCancellation::firstOrFail();
        $this->assertSame($receipt->id, $cancellation->tuition_receipt_id);
        $this->assertSame($this->student->id, $cancellation->student_id);

        $this->actingAs($this->accountant2)
            ->post(route('tuition.invoices.cancellations.approve', $cancellation->id))
            ->assertSessionHasNoErrors();

        $receipt->refresh();
        $this->assertSame('cancelled', $receipt->status);
        $this->assertSame('C26MEN-0001001', $receipt->invoice_number);

        $this->tuition->refresh();
        $this->assertEquals(0, (float) $this->tuition->paid_amount);
        $this->assertEquals(5000000, (float) $this->tuition->debt_amount);
        $this->assertSame('unpaid', $this->tuition->status);
    }

    public function test_invoice_cancellation_rejects_unknown_invoice_wrong_amount_and_duplicates(): void
    {
        $this->approvedReceipt($this->tuition, 5000000, ['invoice_number' => 'C26MEN-0002002']);
        TuitionReceipt::create([
            'receipt_number' => 'PT-PENDING-INV', 'invoice_number' => 'C26MEN-0003003',
            'student_tuition_id' => $this->tuition->id, 'amount' => 100000,
            'payment_method' => 'cash', 'status' => 'pending',
        ]);

        $this->actingAs($this->accountant)->post(route('tuition.invoices.cancellations.store'), [
            'invoice_number' => 'KHONG-TON-TAI', 'amount' => 5000000, 'reason' => 'x',
        ])->assertSessionHasErrors('invoice_number');

        $this->actingAs($this->accountant)->post(route('tuition.invoices.cancellations.store'), [
            'invoice_number' => 'C26MEN-0003003', 'amount' => 100000, 'reason' => 'x',
        ])->assertSessionHasErrors('invoice_number');

        $this->actingAs($this->accountant)->post(route('tuition.invoices.cancellations.store'), [
            'invoice_number' => 'C26MEN-0002002', 'amount' => 4000000, 'reason' => 'x',
        ])->assertSessionHasErrors('amount');

        $this->actingAs($this->accountant)->post(route('tuition.invoices.cancellations.store'), [
            'invoice_number' => 'C26MEN-0002002', 'amount' => 5000000, 'reason' => 'x',
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->accountant)->post(route('tuition.invoices.cancellations.store'), [
            'invoice_number' => 'C26MEN-0002002', 'amount' => 5000000, 'reason' => 'lần 2',
        ])->assertSessionHasErrors('invoice_number');

        $this->assertSame(1, InvoiceCancellation::count());
    }

    // ---------------------------------------------------------------------
    // 5. Refund / transfer
    // ---------------------------------------------------------------------

    public function test_refund_keeps_debt_zero_for_fully_paid_student(): void
    {
        $tuition = $this->tuition;
        $tuition->update(['final_amount' => 12000000, 'total_amount' => 12000000]);
        $this->approvedReceipt($tuition, 12000000);
        $this->assertEquals(0, (float) $tuition->fresh()->debt_amount);

        $refund = TuitionRefundRequest::create([
            'student_id' => $this->student->id, 'type' => 'refund', 'refund_amount' => 6000000,
            'reason' => 'Du học', 'requester_id' => $this->accountant->id, 'status' => 'pending',
        ]);

        $this->actingAs($this->accountant2)
            ->post(route('tuition.refunds.approve', $refund->id))
            ->assertSessionHasNoErrors();

        $tuition->refresh();
        $this->assertSame('approved', $refund->fresh()->status);
        $this->assertEquals(6000000, (float) $tuition->paid_amount);
        $this->assertEquals(6000000, (float) $tuition->final_amount);
        $this->assertEquals(0, (float) $tuition->debt_amount);
        $this->assertSame('paid', $tuition->status);
        $this->assertStringContainsString('6.000.000', (string) $tuition->notes);
    }

    public function test_refund_above_paid_is_rejected(): void
    {
        $this->approvedReceipt($this->tuition, 2000000);

        $refund = TuitionRefundRequest::create([
            'student_id' => $this->student->id, 'type' => 'refund', 'refund_amount' => 3000000,
            'reason' => 'x', 'requester_id' => $this->accountant->id, 'status' => 'pending',
        ]);

        $this->actingAs($this->accountant2)
            ->post(route('tuition.refunds.approve', $refund->id))
            ->assertSessionHasErrors('refund');

        $this->assertSame('pending', $refund->fresh()->status);
        $this->assertEquals(3000000, (float) $this->tuition->fresh()->debt_amount);
    }

    public function test_transfer_balances_both_sides(): void
    {
        $this->approvedReceipt($this->tuition, 5000000);
        $target = Student::create(['code' => 'HV-P0-T', 'name' => 'Người nhận', 'phone' => '0900000002', 'branch_id' => $this->branch->id]);
        $targetTuition = $this->makeTuition($target, 8000000);

        $transfer = TuitionRefundRequest::create([
            'student_id' => $this->student->id, 'target_student_id' => $target->id, 'type' => 'transfer',
            'refund_amount' => 3000000, 'reason' => 'Chuyển em', 'requester_id' => $this->accountant->id, 'status' => 'pending',
        ]);

        $this->actingAs($this->accountant2)
            ->post(route('tuition.refunds.approve', $transfer->id))
            ->assertSessionHasNoErrors();

        $this->tuition->refresh();
        $this->assertEquals(2000000, (float) $this->tuition->paid_amount);
        $this->assertEquals(2000000, (float) $this->tuition->final_amount);
        $this->assertEquals(0, (float) $this->tuition->debt_amount);

        $targetTuition->refresh();
        $this->assertEquals(3000000, (float) $targetTuition->paid_amount);
        $this->assertEquals(5000000, (float) $targetTuition->debt_amount);
    }

    public function test_transfer_larger_than_target_debt_is_rejected(): void
    {
        $this->approvedReceipt($this->tuition, 5000000);
        $target = Student::create(['code' => 'HV-P0-T2', 'name' => 'Người nhận 2', 'phone' => '0900000003', 'branch_id' => $this->branch->id]);
        $targetTuition = $this->makeTuition($target, 1000000);

        $transfer = TuitionRefundRequest::create([
            'student_id' => $this->student->id, 'target_student_id' => $target->id, 'type' => 'transfer',
            'refund_amount' => 3000000, 'reason' => 'x', 'requester_id' => $this->accountant->id, 'status' => 'pending',
        ]);

        $this->actingAs($this->accountant2)
            ->post(route('tuition.refunds.approve', $transfer->id))
            ->assertSessionHasErrors('refund');

        $this->assertSame('pending', $transfer->fresh()->status);
        $this->assertEquals(5000000, (float) $this->tuition->fresh()->paid_amount);
        $this->assertEquals(1000000, (float) $targetTuition->fresh()->debt_amount);
        $this->assertSame(1, TuitionReceipt::count());
    }

    // ---------------------------------------------------------------------
    // 6. Revenue report
    // ---------------------------------------------------------------------

    public function test_revenue_report_counts_only_approved_and_attributes_branch_once(): void
    {
        $branchB = Branch::create(['name' => 'CN B', 'code' => 'CNB', 'is_active' => true]);
        // Học viên thuộc CN B nhưng hợp đồng thuộc CN A -> doanh thu ghi cho CN A
        $studentB = Student::create(['code' => 'HV-P0-B', 'name' => 'HV B', 'phone' => '0900000004', 'branch_id' => $branchB->id]);
        $tuitionA = $this->makeTuition($studentB, 10000000, $this->branch->id);

        $this->approvedReceipt($tuitionA, 3000000);
        TuitionReceipt::create([
            'receipt_number' => 'PT-REV-PENDING', 'student_tuition_id' => $tuitionA->id,
            'amount' => 2000000, 'payment_method' => 'cash', 'payment_date' => now(), 'status' => 'pending',
        ]);
        // Phiếu không gắn hợp đồng -> fallback chi nhánh học viên (CN B)
        TuitionReceipt::create([
            'receipt_number' => 'PT-REV-NO-TUITION', 'student_id' => $studentB->id,
            'amount' => 150000, 'surcharge_amount' => 150000, 'payment_method' => 'cash',
            'payment_date' => now(), 'status' => 'approved',
        ]);

        $response = $this->actingAs($this->admin)->get(route('finance.reports.revenue', ['month' => now()->format('Y-m')]));
        $response->assertOk();

        $this->assertEquals(3150000, (float) $response->viewData('totalRevenue'));
        $matrix = collect($response->viewData('branchMatrix'))->keyBy(fn ($row) => $row['branch']->id);
        $this->assertEquals(3000000, (float) $matrix[$this->branch->id]['revenue']);
        $this->assertEquals(150000, (float) $matrix[$branchB->id]['revenue']);
        $this->assertEquals(3150000, (float) $response->viewData('totalMatrixRevenue'));

        $responseB = $this->actingAs($this->admin)->get(route('finance.reports.revenue', [
            'month' => now()->format('Y-m'), 'branch_id' => $branchB->id,
        ]));
        $this->assertEquals(150000, (float) $responseB->viewData('totalRevenue'));
    }

    public function test_revenue_export_uses_same_status_thresholds_as_screen(): void
    {
        $this->approvedReceipt($this->tuition, 1000000);
        OperatingExpense::create([
            'expense_date' => now()->toDateString(), 'title' => 'Thuê nhà', 'amount' => 2000000,
            'branch_id' => $this->branch->id, 'category' => 'mat_bang_tien_ich',
        ]);

        $response = $this->actingAs($this->admin)->get(route('finance.reports.revenue.export', ['month' => now()->format('Y-m')]));
        $response->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString('Cần tối ưu', $csv);
        $this->assertStringContainsString('1.000.000', $csv);
    }

    // ---------------------------------------------------------------------
    // 7. Debt reminder command
    // ---------------------------------------------------------------------

    public function test_debt_reminder_command_sends_for_due_milestone(): void
    {
        $this->tuition->update(['due_date' => now()->addDays(3)->toDateString()]);

        $this->artisan('tuition:send-debt-reminders')->assertSuccessful();

        $this->assertSame(1, AcademicRecord::where('record_code', 'like', 'DEBTREMIND-T-3-'.$this->tuition->id.'-%')->count());
    }

    public function test_debt_reminder_dry_run_does_not_send(): void
    {
        $this->tuition->update(['due_date' => now()->toDateString()]);

        $this->artisan('tuition:send-debt-reminders', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, AcademicRecord::where('record_code', 'like', 'DEBTREMIND-%')->count());
    }

    public function test_debt_reminder_template_supports_uppercase_placeholders(): void
    {
        $this->tuition->update(['due_date' => now()->toDateString()]);
        DebtReminderRule::create([
            'milestone_key' => 'T0', 'title' => 'Đến hạn', 'is_enabled' => true,
            'template_content' => 'HV {TEN_HOC_VIEN} lop {TEN_LOP} no {SO_TIEN} han {HAN_NOP} / {ten_hoc_vien}',
        ]);

        $this->artisan('tuition:send-debt-reminders')->assertSuccessful();

        $record = AcademicRecord::where('record_code', 'like', 'DEBTREMIND-T0-%')->firstOrFail();
        $content = $record->data['content'];
        $this->assertStringNotContainsString('{', $content);
        $this->assertStringContainsString('HV Nguyễn P Không', $content);
        $this->assertStringContainsString('5.000.000 VNĐ', $content);
        $this->assertStringContainsString(now()->format('d/m/Y'), $content);
    }

    // ---------------------------------------------------------------------
    // 8. Overpayment guard
    // ---------------------------------------------------------------------

    public function test_approving_receipt_above_remaining_debt_is_rejected(): void
    {
        $this->approvedReceipt($this->tuition, 4000000);

        $receipt = TuitionReceipt::create([
            'receipt_number' => 'PT-OVER', 'student_tuition_id' => $this->tuition->id,
            'amount' => 1500000, 'payment_method' => 'cash', 'creator_id' => $this->staff->id, 'status' => 'pending',
        ]);

        $this->actingAs($this->accountant)
            ->post(route('tuition.receipts.approve.action', $receipt->id))
            ->assertSessionHasErrors('receipt');

        $receipt->refresh();
        $this->assertSame('pending', $receipt->status);
        $this->assertNull($receipt->invoice_number);
        $this->assertEquals(1000000, (float) $this->tuition->fresh()->debt_amount);
    }

    // ---------------------------------------------------------------------
    // 9 & 10. No fake bank / proof / import
    // ---------------------------------------------------------------------

    public function test_create_receipt_page_has_no_hardcoded_bank_fallback(): void
    {
        $response = $this->actingAs($this->staff)->get(route('tuition.receipts.create', ['tuition_id' => $this->tuition->id]));
        $response->assertOk();
        $response->assertDontSee('1029384756');
        $response->assertSee('Chưa cấu hình tài khoản ngân hàng');
    }

    public function test_approve_page_does_not_fake_bank_proof(): void
    {
        TuitionReceipt::create([
            'receipt_number' => 'PT-NO-PROOF', 'student_tuition_id' => $this->tuition->id,
            'amount' => 1000000, 'payment_method' => 'transfer', 'creator_id' => $this->staff->id, 'status' => 'pending',
        ]);

        $response = $this->actingAs($this->accountant)->get(route('tuition.receipts.approve'));
        $response->assertOk();
        $response->assertSee('Chưa có minh chứng');
        $response->assertDontSee('1029384756');
        $response->assertDontSee('Khớp số tiền');
        $response->assertDontSee('FT232981354789');
    }

    public function test_import_does_not_fake_success(): void
    {
        $this->actingAs($this->accountant)
            ->post(route('tuition.import.store'))
            ->assertSessionHasErrors('import')
            ->assertSessionMissing('status');
    }
}
