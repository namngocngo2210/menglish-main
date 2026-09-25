<?php

namespace Tests\Feature;

use App\Models\AcademicRecord;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\Course;
use App\Models\DebtReminderRule;
use App\Models\InvoiceConfiguration;
use App\Models\SepayTransaction;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\StudentTuition;
use App\Models\SystemSetting;
use App\Models\TuitionReceipt;
use App\Models\TuitionRefundRequest;
use App\Models\User;
use App\Services\NotificationService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
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

        $this->expectException(UniqueConstraintViolationException::class);
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
        $course = Course::create(['code' => 'P4-C', 'name' => 'Khóa P4', 'total_lessons' => 24, 'is_active' => true]);
        $class = ClassModel::create([
            'code' => 'P4-CLASS', 'name' => 'Lớp P4', 'course_id' => $course->id, 'branch_id' => $this->branch->id, 'status' => 'active',
        ]);
        $this->tuition->update(['class_id' => $class->id]);
        foreach (['present', 'late', 'absent'] as $i => $status) {
            StudentAttendance::create([
                'class_id' => $class->id, 'student_id' => $this->student->id,
                'session_date' => now()->subDays(10 - $i)->toDateString(), 'status' => $status,
            ]);
        }

        BankAccount::create([
            'bank_code' => 'VCB', 'bank_name' => 'Vietcombank', 'account_number' => '1111222233',
            'account_holder' => 'MENGLISH HQ', 'is_default_vietqr' => true, 'is_active' => true,
        ]);
        BankAccount::create([
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
        $contractAccount = BankAccount::create([
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

        $this->assertSame(1, app(NotificationService::class)->getUnreadCount($this->accountant));
        $this->assertSame(0, app(NotificationService::class)->getUnreadCount($otherBranchAccountant));
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

        $this->expectException(UniqueConstraintViolationException::class);
        $this->pendingReceipt($this->tuition, 1000000, ['payment_method' => 'vietqr', 'transaction_code' => 'FT-DUP-9']);
    }

    public function test_approval_is_blocked_when_sepay_already_applied_same_transaction(): void
    {
        $sepayReceipt = $this->pendingReceipt($this->tuition, 2000000, [
            'payment_method' => 'transfer', 'transaction_code' => 'SEPAY-777', 'status' => 'approved', 'invoice_number' => 'C26MEN-0000100',
        ]);
        SepayTransaction::create([
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
        SepayTransaction::create([
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

    // ---------------------------------------------------------------------
    // 2. Khất nợ & bảo lưu có tác dụng thật
    // ---------------------------------------------------------------------

    public function test_approved_extension_moves_due_date_and_pauses_reminders(): void
    {
        $this->tuition->update(['due_date' => now()->subDays(2)->toDateString()]);
        $this->tuition->recalculateDebt();
        $this->assertSame('overdue', $this->tuition->fresh()->status);

        $newDue = now()->addDays(10)->toDateString();
        $this->actingAs($this->accountant)->post(route('tuition.refunds.store'), [
            'student_id' => $this->student->id, 'type' => 'extension',
            'extended_due_date' => $newDue, 'reason' => 'Phụ huynh xin lùi 10 ngày',
        ])->assertSessionHasNoErrors();

        $request = TuitionRefundRequest::firstOrFail();
        $this->assertSame($newDue, $request->extended_due_date->toDateString());

        $this->actingAs($this->admin)->post(route('tuition.refunds.approve', $request->id))->assertSessionHasNoErrors();

        $tuition = $this->tuition->fresh();
        $this->assertSame($newDue, $tuition->due_date->toDateString());
        $this->assertSame($newDue, $tuition->reminder_paused_until->toDateString());
        $this->assertSame('unpaid', $tuition->status);
        $this->assertTrue($tuition->remindersPausedOn());
        $this->assertSame('approved', $request->fresh()->status);

        // Mốc T-3 của hạn mới rơi vào thời gian tạm dừng -> lệnh nhắc nợ bỏ qua.
        $this->travelTo(now()->addDays(7));
        $this->artisan('tuition:send-debt-reminders')->assertSuccessful();
        $this->assertSame(0, AcademicRecord::where('record_code', 'like', 'DEBTREMIND-%')->count());

        // Tới đúng hạn mới -> mốc T0 được gửi.
        $this->travelTo(now()->addDays(3));
        $this->artisan('tuition:send-debt-reminders')->assertSuccessful();
        $this->assertSame(1, AcademicRecord::where('record_code', 'like', 'DEBTREMIND-T0-'.$this->tuition->id.'-%')->count());
    }

    public function test_extension_requires_new_due_date_after_current(): void
    {
        $this->actingAs($this->accountant)->post(route('tuition.refunds.store'), [
            'student_id' => $this->student->id, 'type' => 'extension', 'reason' => 'Thiếu hạn mới',
        ])->assertSessionHasErrors('extended_due_date');

        $this->actingAs($this->accountant)->post(route('tuition.refunds.store'), [
            'student_id' => $this->student->id, 'type' => 'extension', 'reason' => 'Hạn mới sớm hơn hạn cũ',
            'extended_due_date' => now()->addDays(5)->toDateString(),
        ])->assertSessionHasErrors('extended_due_date');
    }

    public function test_approved_deferral_sets_student_reserved_and_freezes_sessions_and_debt(): void
    {
        $course = Course::create(['code' => 'P4-D', 'name' => 'Khóa bảo lưu', 'total_lessons' => 20, 'is_active' => true]);
        $class = ClassModel::create([
            'code' => 'P4-DEF', 'name' => 'Lớp bảo lưu', 'course_id' => $course->id, 'branch_id' => $this->branch->id, 'status' => 'active',
        ]);
        $this->tuition->update(['class_id' => $class->id, 'due_date' => now()->addDays(3)->toDateString()]);
        foreach (range(1, 5) as $i) {
            StudentAttendance::create([
                'class_id' => $class->id, 'student_id' => $this->student->id,
                'session_date' => now()->subDays(30 - $i)->toDateString(), 'status' => 'present',
            ]);
        }

        $from = now()->toDateString();
        $to = now()->addMonth()->toDateString();
        $this->actingAs($this->accountant)->post(route('tuition.refunds.store'), [
            'student_id' => $this->student->id, 'type' => 'deferral', 'defer_from' => $from, 'defer_to' => $to,
            'reason' => 'Đi du lịch hè',
        ])->assertSessionHasNoErrors();

        $request = TuitionRefundRequest::where('type', 'deferral')->firstOrFail();
        $this->actingAs($this->admin)->post(route('tuition.refunds.approve', $request->id))->assertSessionHasNoErrors();

        $this->assertSame('deferred', $this->student->fresh()->status);
        $this->assertSame('Bảo lưu', $this->student->fresh()->status_label);

        $tuition = $this->tuition->fresh();
        $this->assertSame($from, $tuition->deferred_from->toDateString());
        $this->assertSame($to, $tuition->deferred_until->toDateString());
        $this->assertSame(15, $tuition->frozen_remaining_sessions);
        $this->assertEquals(6000000, (float) $tuition->frozen_debt_amount);
        $this->assertTrue($tuition->isDeferredOn());
        $this->assertTrue($tuition->remindersPausedOn(now()->addDays(20)));
        // Hạn đóng rơi vào thời gian bảo lưu -> dời sang ngày học lại.
        $this->assertSame(now()->addMonth()->addDay()->toDateString(), $tuition->due_date->toDateString());

        $this->artisan('tuition:send-debt-reminders')->assertSuccessful();
        $this->assertSame(0, AcademicRecord::where('record_code', 'like', 'DEBTREMIND-%')->count());

        $this->actingAs($this->accountant)->get(route('tuition.overdue'))->assertOk()->assertSee('Bảo lưu tới');
    }

    // ---------------------------------------------------------------------
    // 9. Cấu hình nhắc nợ
    // ---------------------------------------------------------------------

    public function test_debt_reminder_config_saves_schedule_channels_and_validates_variables(): void
    {
        $this->actingAs($this->accountant)->get(route('system-config.debt-reminders'))
            ->assertOk()->assertSee('Cấu hình Nhắc nợ')->assertSee('{ten_hoc_vien}');

        $this->actingAs($this->accountant)->post(route('system-config.debt-reminders.store'), [
            'title' => 'Nhắc trước 7 ngày', 'timing' => 'before', 'days' => 7, 'channels' => ['portal'],
            'template_content' => 'Chào {TEN_HOC_VIEN}, học phí {so_tien} đến hạn {han_dong}. Mã {KHONG_TON_TAI}',
        ])->assertSessionHasErrors('template_content');

        $this->actingAs($this->accountant)->post(route('system-config.debt-reminders.store'), [
            'title' => 'Nhắc trước 7 ngày', 'timing' => 'before', 'days' => 7, 'channels' => ['portal'],
            'template_content' => 'Chào {TEN_HOC_VIEN}, học phí lớp {ten_lop} ({so_tien}) đến hạn {han_dong}.',
        ])->assertSessionHasNoErrors();

        $rule = DebtReminderRule::where('milestone_key', 'T-7')->firstOrFail();
        $this->assertSame(-7, $rule->offset_days);
        $this->assertSame(['portal'], $rule->channels);

        $this->actingAs($this->accountant)->post(route('system-config.debt-reminders.settings'), ['must_contact_days' => 0])
            ->assertSessionHasErrors('must_contact_days');
        $this->actingAs($this->accountant)->post(route('system-config.debt-reminders.settings'), ['must_contact_days' => 10])
            ->assertSessionHasNoErrors();
        $this->assertEquals(10, SystemSetting::get('debt_reminder.must_contact_days'));

        // Lệnh nhắc nợ chạy theo mốc đã cấu hình, chỉ kênh in-app, không còn biến chưa thay.
        $this->tuition->update(['due_date' => now()->addDays(7)->toDateString()]);
        $this->artisan('tuition:send-debt-reminders')->assertSuccessful();
        $record = AcademicRecord::where('record_code', 'like', 'DEBTREMIND-T-7-%')->firstOrFail();
        $this->assertSame('04_Cong_Phu_Huynh_Hoc_Sinh/05_danh_sach_thong_bao', $record->screen_key);
        $this->assertStringNotContainsString('{', $record->data['content']);
        $this->assertStringContainsString('Phạm Bốn', $record->data['content']);
    }

    // ---------------------------------------------------------------------
    // 7. Danh sách quá hạn
    // ---------------------------------------------------------------------

    public function test_overdue_list_groups_by_days_and_logs_contact_and_admin_report(): void
    {
        $serious = $this->tuition;
        $serious->update(['due_date' => now()->subDays(12)->toDateString()]);
        $freshStudent = Student::create(['code' => 'HV-P4-NEW', 'name' => 'Trần Mới Quá', 'phone' => '0900000055', 'branch_id' => $this->branch->id, 'status' => 'studying']);
        $this->makeTuition($freshStudent, 2000000, null, ['due_date' => now()->subDays(3)->toDateString()]);
        $soonStudent = Student::create(['code' => 'HV-P4-SOON', 'name' => 'Lê Sắp Tới', 'phone' => '0900000056', 'branch_id' => $this->branch->id, 'status' => 'studying']);
        $this->makeTuition($soonStudent, 2000000, null, ['due_date' => now()->addDays(5)->toDateString()]);

        $response = $this->actingAs($this->accountant)->get(route('tuition.overdue'));
        $response->assertOk()
            ->assertSee('Quá hạn nghiêm trọng (≥ 7 ngày)')
            ->assertSee('Mới quá hạn (1–6 ngày)')
            ->assertSee('Quá hạn 12 ngày')
            ->assertSee('Quá hạn 3 ngày')
            ->assertSee('Lê Sắp Tới');
        $this->assertSame([$serious->id], $response->viewData('seriousOverdue')->pluck('id')->all());
        $this->assertSame(['HV-P4-NEW'], $response->viewData('newOverdue')->pluck('student.code')->all());
        $this->assertSame(['HV-P4-SOON'], $response->viewData('upcoming')->pluck('student.code')->all());

        $this->actingAs($this->accountant)->post(route('tuition.overdue.contacted', $serious->id), [
            'note' => 'Phụ huynh hẹn chuyển khoản thứ 6',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('tuition_contact_logs', [
            'student_tuition_id' => $serious->id, 'user_id' => $this->accountant->id, 'action' => 'contacted', 'note' => 'Phụ huynh hẹn chuyển khoản thứ 6',
        ]);

        $this->actingAs($this->accountant)->post(route('tuition.overdue.report-admin', $serious->id), ['note' => 'Không liên lạc được'])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('admin_notifications', ['user_id' => $this->admin->id, 'type' => 'overdue_report']);
        $this->assertDatabaseHas('tuition_contact_logs', ['student_tuition_id' => $serious->id, 'action' => 'reported_admin']);

        $this->actingAs($this->accountant)->get(route('tuition.overdue'))
            ->assertSee('Đã liên hệ — chờ thu')
            ->assertSee('Đã báo cáo Admin — '.now()->format('d/m/Y'));

        // Không có quyền -> 403.
        $sales = $this->makeUser('sales_consultant');
        $this->actingAs($sales)->post(route('tuition.overdue.contacted', $serious->id))->assertForbidden();
        $teacher = $this->makeUser('teacher');
        $this->actingAs($teacher)->post(route('tuition.overdue.report-admin', $serious->id))->assertForbidden();
    }

    // ---------------------------------------------------------------------
    // 8. Màn hoàn phí dùng số liệu thật
    // ---------------------------------------------------------------------

    public function test_refund_screen_uses_real_tuition_and_attendance_data(): void
    {
        $course = Course::create(['code' => 'P4-R', 'name' => 'Khóa hoàn', 'total_lessons' => 20, 'is_active' => true]);
        $class = ClassModel::create([
            'code' => 'P4-REF', 'name' => 'Lớp hoàn', 'course_id' => $course->id, 'branch_id' => $this->branch->id, 'status' => 'active',
        ]);
        $this->tuition->update(['class_id' => $class->id]);
        $this->pendingReceipt($this->tuition, 3000000, ['status' => 'approved', 'invoice_number' => 'C26MEN-0000301']);
        $this->tuition->recalculateDebt();
        foreach (range(1, 4) as $i) {
            StudentAttendance::create([
                'class_id' => $class->id, 'student_id' => $this->student->id,
                'session_date' => now()->subDays(10 - $i)->toDateString(), 'status' => 'present',
            ]);
        }

        $response = $this->actingAs($this->accountant)->get(route('tuition.refunds'));
        $response->assertOk()
            ->assertSee('Đánh dấu khất nợ')
            ->assertSee('Xác nhận khất nợ')
            ->assertDontSee('12500000');

        $basis = $response->viewData('studentFinance')[$this->student->id];
        // Hợp đồng 6.000.000 / 20 buổi = 300.000đ/buổi; đã nộp 3.000.000, đã học 4 buổi = 1.200.000
        $this->assertEquals(3000000, $basis['paid']);
        $this->assertSame(20, $basis['total_sessions']);
        $this->assertSame(4, $basis['attended_sessions']);
        $this->assertEquals(300000, $basis['unit_price']);
        $this->assertEquals(1800000, $basis['remaining_value']);
        $this->assertEquals(180000, $basis['admin_fee']);
        $this->assertEquals(1620000, $basis['suggested_refund']);
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
