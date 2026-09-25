<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\InvoiceConfiguration;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\TuitionReceipt;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 4 — Học phí & Chuyển khoản (docs/audit-report-and-roadmap.md, Phần C Phase 4).
 */
class Phase4FinanceTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Branch $branch2;

    private User $admin;

    private User $accountant;

    private User $staff;

    private Student $student;

    private StudentTuition $tuition;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'CN Cầu Giấy', 'code' => 'CG', 'is_active' => true]);
        $this->branch2 = Branch::create(['name' => 'CN Đống Đa', 'code' => 'DD', 'is_active' => true]);

        $this->admin = $this->makeUser('admin');
        $this->accountant = $this->makeUser('accountant');
        $this->staff = $this->makeUser('academic_staff');

        $this->student = Student::create([
            'code' => 'HV-P4-001',
            'name' => 'Phạm Bốn',
            'phone' => '0900000004',
            'branch_id' => $this->branch->id,
            'status' => 'studying',
        ]);
        $this->tuition = $this->makeTuition($this->student, 6000000);
    }

    private function makeUser(string $role, ?Branch $branch = null): User
    {
        $user = User::factory()->create(['branch_id' => ($branch ?? $this->branch)->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function makeTuition(Student $student, float $final, ?int $branchId = null, array $extra = []): StudentTuition
    {
        return StudentTuition::create(array_merge([
            'student_id' => $student->id,
            'branch_id' => $branchId ?? $student->branch_id,
            'total_amount' => $final,
            'final_amount' => $final,
            'paid_amount' => 0,
            'debt_amount' => $final,
            'due_date' => now()->addDays(10),
            'status' => 'unpaid',
        ], $extra));
    }

    private function pendingReceipt(StudentTuition $tuition, float $amount, array $extra = []): TuitionReceipt
    {
        return TuitionReceipt::create(array_merge([
            'receipt_number' => TuitionReceipt::generateReceiptNumber(),
            'student_tuition_id' => $tuition->id,
            'student_id' => $tuition->student_id,
            'amount' => $amount,
            'tuition_amount' => $amount,
            'payment_method' => 'cash',
            'payment_date' => now(),
            'creator_id' => $this->staff->id,
            'status' => 'pending',
        ], $extra));
    }

    // ---------------------------------------------------------------------
    // 1. Dải số hóa đơn theo chi nhánh
    // ---------------------------------------------------------------------

    public function test_invoice_number_comes_from_branch_range_then_falls_back_to_default(): void
    {
        InvoiceConfiguration::create([
            'template_code' => '1/001', 'series_code' => 'C26MEN', 'start_number' => 1, 'current_number' => 5000,
            'provider' => 'vnpt', 'auto_issue' => true,
        ]);
        InvoiceConfiguration::create([
            'branch_id' => $this->branch->id, 'template_code' => '1/001', 'series_code' => 'C26CG',
            'start_number' => 1, 'end_number' => 2, 'current_number' => 1, 'provider' => 'vnpt', 'auto_issue' => true,
        ]);

        $this->assertSame('C26CG-0000001', InvoiceConfiguration::consumeNextInvoiceNumber($this->branch->id));
        $this->assertSame('C26CG-0000002', InvoiceConfiguration::consumeNextInvoiceNumber($this->branch->id));
        // Dải chi nhánh hết số -> dùng dải mặc định
        $this->assertSame('C26MEN-0005000', InvoiceConfiguration::consumeNextInvoiceNumber($this->branch->id));
        // Chi nhánh chưa có dải riêng -> dải mặc định
        $this->assertSame('C26MEN-0005001', InvoiceConfiguration::consumeNextInvoiceNumber($this->branch2->id));
    }

    public function test_approving_receipt_uses_branch_range_and_never_reuses_numbers(): void
    {
        InvoiceConfiguration::create([
            'branch_id' => $this->branch->id, 'template_code' => '1/001', 'series_code' => 'C26CG',
            'start_number' => 1, 'end_number' => 1000, 'current_number' => 10, 'provider' => 'vnpt', 'auto_issue' => true,
        ]);
        // Số 10 đã tồn tại (dữ liệu cũ) -> phải bỏ qua, cấp số 11.
        $this->pendingReceipt($this->tuition, 1000000, ['invoice_number' => 'C26CG-0000010', 'status' => 'cancelled']);

        $receipt = $this->pendingReceipt($this->tuition, 2000000);
        $this->actingAs($this->accountant)
            ->post(route('tuition.receipts.approve.action', $receipt->id))
            ->assertSessionHasNoErrors();

        $this->assertSame('C26CG-0000011', $receipt->fresh()->invoice_number);
    }

    public function test_invoice_number_is_unique_in_database(): void
    {
        $this->pendingReceipt($this->tuition, 1000000, ['invoice_number' => 'C26MEN-0000001']);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        $this->pendingReceipt($this->tuition, 1000000, ['invoice_number' => 'C26MEN-0000001']);
    }

    public function test_current_number_cannot_go_back_below_issued_number(): void
    {
        $range = InvoiceConfiguration::create([
            'branch_id' => $this->branch->id, 'template_code' => '1/001', 'series_code' => 'C26CG',
            'start_number' => 1, 'end_number' => 1000, 'current_number' => 51, 'provider' => 'vnpt', 'auto_issue' => true,
        ]);
        $this->pendingReceipt($this->tuition, 1000000, ['invoice_number' => 'C26CG-0000050', 'status' => 'approved']);

        $payload = [
            'config_id' => $range->id, 'template_code' => '1/001', 'series_code' => 'C26CG',
            'start_number' => 1, 'end_number' => 1000,
        ];

        $this->actingAs($this->accountant)->post(route('tuition.config.update'), $payload + ['current_number' => 20])
            ->assertSessionHasErrors('current_number');
        $this->assertSame(51, $range->fresh()->current_number);

        $this->actingAs($this->accountant)->post(route('tuition.config.update'), $payload + ['current_number' => 60])
            ->assertSessionHasNoErrors();
        $this->assertSame(60, $range->fresh()->current_number);
    }

    public function test_new_range_cannot_overlap_existing_range_of_same_series(): void
    {
        InvoiceConfiguration::create([
            'branch_id' => $this->branch->id, 'template_code' => '1/001', 'series_code' => 'C26MEN',
            'start_number' => 1, 'end_number' => 1000, 'current_number' => 1, 'provider' => 'vnpt', 'auto_issue' => true,
        ]);

        $this->actingAs($this->accountant)->post(route('tuition.config.ranges.store'), [
            'branch_id' => $this->branch2->id, 'template_code' => '1/001', 'series_code' => 'C26MEN',
            'start_number' => 900, 'end_number' => 1500,
        ])->assertSessionHasErrors('start_number');

        $this->actingAs($this->accountant)->post(route('tuition.config.ranges.store'), [
            'branch_id' => $this->branch2->id, 'template_code' => '1/001', 'series_code' => 'C26MEN',
            'start_number' => 2001, 'end_number' => 3000,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('invoice_configurations', [
            'branch_id' => $this->branch2->id, 'start_number' => 2001, 'end_number' => 3000, 'current_number' => 2001,
        ]);

        $this->actingAs($this->accountant)->get(route('tuition.config'))
            ->assertOk()
            ->assertSee('CN Đống Đa')
            ->assertSee('Đang hiệu lực');
    }

    // ---------------------------------------------------------------------
    // 4. Quyền báo cáo thu chi
    // ---------------------------------------------------------------------

    public function test_sales_consultant_cannot_open_finance_reports_but_keeps_report_view(): void
    {
        $sales = $this->makeUser('sales_consultant');
        $this->assertTrue($sales->can('report.view'));
        $this->assertFalse($sales->can('finance.view'));

        $this->actingAs($sales)->get(route('finance.reports.revenue'))->assertForbidden();
        $this->actingAs($sales)->get(route('finance.expenses.index'))->assertForbidden();
        $this->actingAs($sales)->get(route('finance.reports.revenue.export'))->assertForbidden();

        $this->actingAs($this->accountant)->get(route('finance.reports.revenue'))->assertOk();
        $this->actingAs($this->admin)->get(route('finance.expenses.index'))->assertOk();
    }

    public function test_manager_only_sees_own_branch_in_finance_reports(): void
    {
        $manager = $this->makeUser('manager');
        $otherStudent = Student::create([
            'code' => 'HV-P4-DD', 'name' => 'Học viên Đống Đa', 'phone' => '0900000099',
            'branch_id' => $this->branch2->id, 'status' => 'studying',
        ]);
        $otherTuition = $this->makeTuition($otherStudent, 9000000);

        $this->pendingReceipt($this->tuition, 1234000, ['status' => 'approved', 'invoice_number' => 'C26MEN-0000001']);
        $this->pendingReceipt($otherTuition, 7777000, ['status' => 'approved', 'invoice_number' => 'C26MEN-0000002']);

        $response = $this->actingAs($manager)->get(route('finance.reports.revenue', ['branch_id' => 'all']));
        $response->assertOk();
        $response->assertSee('1.234.000');
        $response->assertDontSee('7.777.000');
        $response->assertDontSee('CN Đống Đa');

        // Cố ý chọn chi nhánh khác -> vẫn bị ép về chi nhánh của mình.
        $this->actingAs($manager)->get(route('finance.reports.revenue', ['branch_id' => $this->branch2->id]))
            ->assertOk()
            ->assertDontSee('7.777.000');

        $csv = $this->actingAs($manager)->get(route('finance.reports.revenue.export'))->streamedContent();
        $this->assertStringNotContainsString('CN Đống Đa', $csv);

        // Không được ghi khoản chi cho chi nhánh khác.
        $this->actingAs($manager)->post(route('finance.expenses.store'), [
            'expense_date' => now()->toDateString(), 'title' => 'Tiền điện', 'amount' => 500000,
            'payment_method' => 'tien_mat', 'branch_id' => $this->branch2->id,
        ])->assertForbidden();

        // Admin vẫn thấy toàn hệ thống.
        $this->actingAs($this->admin)->get(route('finance.reports.revenue'))->assertOk()->assertSee('7.777.000');
    }

    // ---------------------------------------------------------------------
    // 5. Luồng phiếu thu: minh chứng, sửa phiếu bị trả về, số buổi
    // ---------------------------------------------------------------------

    public function test_transfer_receipt_requires_proof_on_submit_but_cash_and_draft_do_not(): void
    {
        $base = ['student_tuition_id' => $this->tuition->id, 'amount' => 1000000];

        $this->actingAs($this->staff)->post(route('tuition.receipts.store'), $base + ['payment_method' => 'transfer'])
            ->assertSessionHasErrors('proof_image');
        $this->actingAs($this->staff)->post(route('tuition.receipts.store'), $base + ['payment_method' => 'pos', 'submit_action' => 'submit'])
            ->assertSessionHasErrors('proof_image');
        $this->assertSame(0, TuitionReceipt::count());

        $this->actingAs($this->staff)->post(route('tuition.receipts.store'), $base + ['payment_method' => 'transfer', 'submit_action' => 'draft'])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->staff)->post(route('tuition.receipts.store'), $base + ['payment_method' => 'cash', 'submit_action' => 'submit'])
            ->assertSessionHasNoErrors();

        $this->assertSame(['cash' => 'pending', 'transfer' => 'draft'], TuitionReceipt::pluck('status', 'payment_method')->sortKeys()->all());
    }

    public function test_returned_receipt_has_edit_screen_and_can_be_resubmitted_with_proof(): void
    {
        $receipt = $this->pendingReceipt($this->tuition, 1500000, [
            'payment_method' => 'transfer', 'transaction_code' => 'FT-EDIT-01',
            'status' => 'rejected', 'rejection_reason' => 'Thiếu ủy nhiệm chi',
        ]);

        $this->actingAs($this->accountant)->get(route('tuition.receipts.edit', $receipt->id))->assertForbidden();

        $this->actingAs($this->staff)->get(route('tuition.receipts.edit', $receipt->id))
            ->assertOk()
            ->assertSee('Sửa phiếu thu học phí')
            ->assertSee('Thiếu ủy nhiệm chi')
            ->assertSee(route('tuition.receipts.update', $receipt->id), false)
            ->assertSee('FT-EDIT-01');

        // Gửi lại mà vẫn thiếu minh chứng -> chặn.
        $payload = ['amount' => 1500000, 'tuition_amount' => 1500000, 'payment_method' => 'transfer', 'transaction_code' => 'FT-EDIT-01', 'submit_action' => 'submit'];
        $this->actingAs($this->staff)->put(route('tuition.receipts.update', $receipt->id), $payload)->assertSessionHasErrors('proof_image');
        $this->assertSame('rejected', $receipt->fresh()->status);

        $this->actingAs($this->staff)->put(route('tuition.receipts.update', $receipt->id), $payload + [
            'proof_image_preview' => '/uploads/tuition/receipts/unc.png',
        ])->assertSessionHasNoErrors();

        $receipt->refresh();
        $this->assertSame('pending', $receipt->status);
        $this->assertNull($receipt->rejection_reason);
        $this->assertSame('/uploads/tuition/receipts/unc.png', $receipt->proof_image);
        $this->assertSame('FT-EDIT-01', $receipt->transfer_reference);

        // Phiếu đang chờ duyệt không còn màn sửa.
        $this->actingAs($this->staff)->get(route('tuition.receipts.edit', $receipt->id))->assertRedirect();
    }

    public function test_create_receipt_shows_real_session_counts_and_branch_bank_account(): void
    {
        $course = \App\Models\Course::create(['code' => 'P4-C', 'name' => 'Khóa P4', 'total_lessons' => 24, 'is_active' => true]);
        $class = \App\Models\ClassModel::create([
            'code' => 'P4-CLASS', 'name' => 'Lớp P4', 'course_id' => $course->id, 'branch_id' => $this->branch->id, 'status' => 'active',
        ]);
        $this->tuition->update(['class_id' => $class->id]);
        foreach (['present', 'late', 'absent'] as $i => $status) {
            \App\Models\StudentAttendance::create([
                'class_id' => $class->id, 'student_id' => $this->student->id,
                'session_date' => now()->subDays(10 - $i)->toDateString(), 'status' => $status,
            ]);
        }

        \App\Models\BankAccount::create([
            'bank_code' => 'VCB', 'bank_name' => 'Vietcombank', 'account_number' => '1111222233',
            'account_holder' => 'MENGLISH HQ', 'is_default_vietqr' => true, 'is_active' => true,
        ]);
        \App\Models\BankAccount::create([
            'bank_code' => 'TCB', 'bank_name' => 'Techcombank', 'account_number' => '9999888877',
            'account_holder' => 'MENGLISH CAU GIAY', 'branch_id' => $this->branch->id, 'is_active' => true,
        ]);

        $this->assertSame(['total' => 24, 'attended' => 2, 'remaining' => 22, 'source' => 'course'], $this->tuition->fresh()->sessionStats());
        $this->assertSame('9999888877', $this->tuition->fresh()->resolveBankAccount()->account_number);

        $response = $this->actingAs($this->staff)->get(route('tuition.receipts.create', ['tuition_id' => $this->tuition->id]));
        $response->assertOk();
        $meta = $response->viewData('tuitionMeta')[$this->tuition->id];
        $this->assertSame(24, $meta['sessions']['total']);
        $this->assertSame(2, $meta['sessions']['attended']);
        $this->assertSame('9999888877', $meta['bank']['account_number']);
        $this->assertSame('9999888877', $response->viewData('defaultBank')->account_number);

        // Học viên chi nhánh chưa có tài khoản riêng -> tài khoản mặc định.
        $other = Student::create(['code' => 'HV-P4-DD2', 'name' => 'HV DD', 'phone' => '0900000077', 'branch_id' => $this->branch2->id, 'status' => 'studying']);
        $this->assertSame('1111222233', $this->makeTuition($other, 1000000)->resolveBankAccount()->account_number);

        // Tài khoản gắn trên hợp đồng được ưu tiên.
        $contractAccount = \App\Models\BankAccount::create([
            'bank_code' => 'ACB', 'bank_name' => 'ACB', 'account_number' => '5555666677',
            'account_holder' => 'MENGLISH HD', 'is_active' => true,
        ]);
        $this->tuition->update(['bank_account_id' => $contractAccount->id]);
        $this->assertSame('5555666677', $this->tuition->fresh()->resolveBankAccount()->account_number);
    }

    public function test_session_block_is_hidden_without_data(): void
    {
        $this->assertNull($this->tuition->sessionStats());

        $this->actingAs($this->staff)->get(route('tuition.receipts.create', ['tuition_id' => $this->tuition->id]))
            ->assertOk()
            ->assertViewHas('tuitionMeta', fn ($meta) => $meta[$this->tuition->id]['sessions'] === null);
    }

    // ---------------------------------------------------------------------
    // 6. Thông báo kế toán chi nhánh
    // ---------------------------------------------------------------------

    public function test_new_receipt_notifies_branch_accountants_personally(): void
    {
        $otherBranchAccountant = $this->makeUser('accountant', $this->branch2);
        $hqAccountant = User::factory()->create(['branch_id' => null, 'is_active' => true]);
        $hqAccountant->assignRole('accountant');

        $this->actingAs($this->staff)->post(route('tuition.receipts.store'), [
            'student_tuition_id' => $this->tuition->id, 'amount' => 1000000, 'payment_method' => 'cash',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('admin_notifications', ['user_id' => null, 'type' => 'receipt_pending']);
        $this->assertDatabaseHas('admin_notifications', ['user_id' => $this->accountant->id, 'type' => 'receipt_pending']);
        $this->assertDatabaseHas('admin_notifications', ['user_id' => $hqAccountant->id, 'type' => 'receipt_pending']);
        $this->assertDatabaseMissing('admin_notifications', ['user_id' => $otherBranchAccountant->id]);

        $this->assertSame(1, app(\App\Services\NotificationService::class)->getUnreadCount($this->accountant));
        $this->assertSame(0, app(\App\Services\NotificationService::class)->getUnreadCount($otherBranchAccountant));
    }

    // ---------------------------------------------------------------------
    // 10. Chống ghi nhận chuyển khoản 2 lần
    // ---------------------------------------------------------------------

    public function test_same_transfer_code_cannot_be_recorded_twice(): void
    {
        $this->pendingReceipt($this->tuition, 1000000, ['payment_method' => 'transfer', 'transaction_code' => 'FT-DUP-9']);

        $this->actingAs($this->staff)->post(route('tuition.receipts.store'), [
            'student_tuition_id' => $this->tuition->id, 'amount' => 1000000, 'payment_method' => 'transfer',
            'transaction_code' => ' ft-dup-9 ', 'proof_image_preview' => '/uploads/tuition/receipts/a.png',
        ])->assertSessionHasErrors('transaction_code');

        $this->assertSame(1, TuitionReceipt::count());

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        $this->pendingReceipt($this->tuition, 1000000, ['payment_method' => 'vietqr', 'transaction_code' => 'FT-DUP-9']);
    }

    public function test_approval_is_blocked_when_sepay_already_applied_same_transaction(): void
    {
        $sepayReceipt = $this->pendingReceipt($this->tuition, 2000000, [
            'payment_method' => 'transfer', 'transaction_code' => 'SEPAY-777', 'status' => 'approved', 'invoice_number' => 'C26MEN-0000100',
        ]);
        \App\Models\SepayTransaction::create([
            'sepay_id' => 'SEPAY-777', 'reference_code' => 'FT-BANK-777', 'transfer_type' => 'in', 'transfer_amount' => 2000000,
            'transaction_date' => now(), 'status' => 'matched', 'matched_student_id' => $this->student->id,
            'matched_tuition_id' => $this->tuition->id, 'matched_receipt_id' => $sepayReceipt->id,
        ]);
        $this->tuition->recalculateDebt();

        // Mã tham chiếu ngân hàng trùng giao dịch SePay -> chặn ngay lúc gửi duyệt.
        $this->actingAs($this->staff)->post(route('tuition.receipts.store'), [
            'student_tuition_id' => $this->tuition->id, 'amount' => 2000000, 'payment_method' => 'transfer',
            'transaction_code' => 'FT-BANK-777', 'proof_image_preview' => '/uploads/tuition/receipts/a.png',
        ])->assertSessionHasErrors('transaction_code');

        // Phiếu tay đã lỡ tạo trước đó (dữ liệu cũ) -> chặn lúc duyệt.
        $manual = $this->pendingReceipt($this->tuition, 2000000, ['payment_method' => 'transfer']);
        $manual->forceFill(['transaction_code' => 'FT-BANK-777'])->saveQuietly();

        $this->actingAs($this->accountant)->post(route('tuition.receipts.approve.action', $manual->id))
            ->assertSessionHasErrors('receipt');
        $this->assertSame('pending', $manual->fresh()->status);
    }

    public function test_approval_warns_on_similar_sepay_transfer_until_confirmed(): void
    {
        $sepayReceipt = $this->pendingReceipt($this->tuition, 2000000, [
            'payment_method' => 'transfer', 'transaction_code' => 'SEPAY-888', 'status' => 'approved', 'invoice_number' => 'C26MEN-0000200',
        ]);
        \App\Models\SepayTransaction::create([
            'sepay_id' => 'SEPAY-888', 'transfer_type' => 'in', 'transfer_amount' => 2000000,
            'transaction_date' => now()->subDay(), 'status' => 'matched', 'matched_student_id' => $this->student->id,
            'matched_tuition_id' => $this->tuition->id, 'matched_receipt_id' => $sepayReceipt->id,
        ]);
        $this->tuition->recalculateDebt();

        $manual = $this->pendingReceipt($this->tuition, 2000000, ['payment_method' => 'transfer', 'transaction_code' => 'FT-OTHER-1']);

        $this->actingAs($this->accountant)->get(route('tuition.receipts.approve', ['selected_id' => $manual->id]))
            ->assertOk()
            ->assertSee('Có thể trùng giao dịch SePay')
            ->assertSee('confirm_not_duplicate', false);

        $this->actingAs($this->accountant)->post(route('tuition.receipts.approve.action', $manual->id))
            ->assertSessionHasErrors('receipt');
        $this->assertSame('pending', $manual->fresh()->status);

        $this->actingAs($this->accountant)->post(route('tuition.receipts.approve.action', $manual->id), ['confirm_not_duplicate' => 1])
            ->assertSessionHasNoErrors();
        $this->assertSame('approved', $manual->fresh()->status);

        // Tiền mặt không bị đối chiếu với SePay.
        $cash = $this->pendingReceipt($this->tuition, 2000000);
        $this->actingAs($this->accountant)->post(route('tuition.receipts.approve.action', $cash->id))->assertSessionHasNoErrors();
    }

    public function test_deactivated_branch_range_is_skipped(): void
    {
        $range = InvoiceConfiguration::create([
            'branch_id' => $this->branch->id, 'template_code' => '1/001', 'series_code' => 'C26CG',
            'start_number' => 1, 'end_number' => 1000, 'current_number' => 1, 'provider' => 'vnpt', 'auto_issue' => true,
        ]);

        $this->actingAs($this->accountant)->post(route('tuition.config.ranges.toggle', $range->id))->assertRedirect();
        $this->assertFalse($range->fresh()->is_active);

        $this->assertStringStartsWith('C26MEN-', InvoiceConfiguration::consumeNextInvoiceNumber($this->branch->id));
    }
}
