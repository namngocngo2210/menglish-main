<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\TuitionReceipt;
use App\Models\TuitionRefundRequest;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 4 — Đối chiếu mockup nhóm Học phí / hóa đơn / thu chi + luật hoàn phí A6 (25/09/2026).
 * docs/audit-report-and-roadmap.md, Phần D "Phase 4 — Đối chiếu mockup nhóm Học phí".
 */
class Phase4FinanceParityTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Branch $branch2;

    private User $admin;

    private User $accountant;

    private User $manager;

    private Student $student;

    private StudentTuition $tuition;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        Storage::fake('local');

        $this->branch = Branch::create(['name' => 'CN Cầu Giấy', 'code' => 'CG', 'is_active' => true]);
        $this->branch2 = Branch::create(['name' => 'CN Đống Đa', 'code' => 'DD', 'is_active' => true]);

        $this->admin = $this->makeUser('admin');
        $this->accountant = $this->makeUser('accountant');
        $this->manager = $this->makeUser('manager');

        $this->student = $this->makeStudent('HV-PAR-001', 'Nguyễn Hoàn', $this->branch);
        $this->tuition = $this->makeTuition($this->student, 6000000, paid: 3000000);
    }

    private function makeUser(string $role, ?Branch $branch = null, bool $withBranch = true): User
    {
        $user = User::factory()->create(['branch_id' => $withBranch ? ($branch ?? $this->branch)->id : null, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function makeStudent(string $code, string $name, Branch $branch): Student
    {
        return Student::create(['code' => $code, 'name' => $name, 'phone' => '09'.random_int(10000000, 99999999), 'branch_id' => $branch->id, 'status' => 'studying']);
    }

    private function makeTuition(Student $student, float $final, float $paid = 0, array $extra = []): StudentTuition
    {
        $tuition = StudentTuition::create(array_merge([
            'student_id' => $student->id,
            'branch_id' => $student->branch_id,
            'total_amount' => $final,
            'final_amount' => $final,
            'paid_amount' => 0,
            'debt_amount' => $final,
            'due_date' => now()->addDays(10),
            'status' => 'unpaid',
        ], $extra));

        if ($paid > 0) {
            $this->approvedReceipt($tuition, $paid);
        }

        return $tuition->fresh();
    }

    private function approvedReceipt(StudentTuition $tuition, float $amount, array $extra = []): TuitionReceipt
    {
        $receipt = TuitionReceipt::create(array_merge([
            'receipt_number' => TuitionReceipt::generateReceiptNumber(),
            'student_tuition_id' => $tuition->id,
            'student_id' => $tuition->student_id,
            'amount' => $amount,
            'tuition_amount' => $amount,
            'payment_method' => 'cash',
            'payment_date' => now(),
            'creator_id' => $this->accountant->id,
            'approver_id' => $this->accountant->id,
            'approved_at' => now(),
            'status' => 'approved',
        ], $extra));
        $tuition->recalculateDebt();

        return $receipt;
    }

    private function refundRequest(array $extra = []): TuitionRefundRequest
    {
        return TuitionRefundRequest::create(array_merge([
            'student_id' => $this->student->id,
            'type' => 'refund',
            'total_paid' => 3000000,
            'refund_amount' => 1000000,
            'reason' => 'Gia đình chuyển vào Nam',
            'no_transfer_reason' => 'Không có học viên nhận buổi dư',
            'requester_id' => $this->accountant->id,
            'status' => 'pending',
        ], $extra));
    }

    // ---------------------------------------------------------------------
    // Luật hoàn phí (A6 "Hoàn phí" 25/09/2026)
    // ---------------------------------------------------------------------

    public function test_processing_deadline_is_one_week_capped_at_end_of_month(): void
    {
        $this->assertSame('2026-09-17', TuitionRefundRequest::deadlineFor(Carbon::parse('2026-09-10 09:00'))->toDateString());
        // Lập 26/09: +7 ngày = 03/10 → cắt về cuối tháng 30/09 (khớp sổ trong tháng).
        $this->assertSame('2026-09-30', TuitionRefundRequest::deadlineFor(Carbon::parse('2026-09-26 09:00'))->toDateString());
        $this->assertSame('2026-10-31', TuitionRefundRequest::deadlineFor(Carbon::parse('2026-10-31 08:00'))->toDateString());

        // Khất nợ / bảo lưu không có hạn xử lý của A6.
        $this->travelTo(Carbon::parse('2026-09-01 09:00'));
        $extension = $this->refundRequest(['type' => 'extension', 'refund_amount' => 0, 'extended_due_date' => '2026-10-10']);
        $this->assertNull($extension->processing_deadline);
        $this->travelTo(Carbon::parse('2026-09-20 09:00'));
        $this->assertFalse($extension->fresh()->isProcessingOverdue());
    }

    public function test_refund_request_requires_reason_for_not_transferring(): void
    {
        $payload = [
            'student_id' => $this->student->id, 'type' => 'refund', 'refund_amount' => 500000,
            'reason' => 'Chuyển nơi ở',
        ];
        $this->actingAs($this->accountant)->post(route('tuition.refunds.store'), $payload)
            ->assertSessionHasErrors('no_transfer_reason');
        $this->assertSame(0, TuitionRefundRequest::count());

        $this->actingAs($this->accountant)->post(route('tuition.refunds.store'), $payload + ['no_transfer_reason' => 'Không có học viên nhận buổi dư'])
            ->assertSessionHasNoErrors()->assertSessionHas('status', fn ($msg) => str_contains($msg, 'Admin') && str_contains($msg, 'Hạn xử lý'));
        $this->assertSame('Không có học viên nhận buổi dư', TuitionRefundRequest::firstOrFail()->no_transfer_reason);

        // Chuyển nhượng không cần lý do "không chuyển nhượng".
        $target = $this->makeStudent('HV-PAR-002', 'Trần Nhận', $this->branch);
        $this->makeTuition($target, 5000000);
        $this->actingAs($this->accountant)->post(route('tuition.refunds.store'), [
            'student_id' => $this->student->id, 'type' => 'transfer', 'refund_amount' => 500000,
            'target_student_id' => $target->id, 'reason' => 'Chuyển cho em',
        ])->assertSessionHasNoErrors();
        $this->assertNull(TuitionRefundRequest::where('type', 'transfer')->firstOrFail()->no_transfer_reason);
    }

    public function test_create_screen_offers_transfer_first(): void
    {
        $this->actingAs($this->accountant)->get(route('tuition.refunds'))
            ->assertOk()
            ->assertSeeInOrder(['Chuyển nhượng', 'Hoàn tiền', 'Bảo lưu'])
            ->assertSee('Ưu tiên')
            ->assertSee('Phương án cuối')
            ->assertSee('Chuyển sang chuyển nhượng')
            ->assertSee('Lý do không chuyển nhượng (Bắt buộc)')
            ->assertSee('name="no_transfer_reason"', false);
    }

    public function test_only_admin_approves_refunds_and_proof_image_is_required(): void
    {
        $refund = $this->refundRequest();

        // Kế toán / Quản lý có refund_transfer.approve nhưng không duyệt được HOÀN TIỀN.
        $this->actingAs($this->accountant)->post(route('tuition.refunds.approve', $refund->id), [
            'proof_image' => UploadedFile::fake()->image('unc.jpg'),
        ])->assertForbidden();
        $this->actingAs($this->manager)->post(route('tuition.refunds.approve', $refund->id), [
            'proof_image' => UploadedFile::fake()->image('unc.jpg'),
        ])->assertForbidden();
        $this->assertSame('pending', $refund->fresh()->status);

        // Admin: thiếu ảnh bằng chứng → chặn.
        $this->actingAs($this->admin)->post(route('tuition.refunds.approve', $refund->id), ['clawback_commission' => 0])
            ->assertSessionHasErrors('proof_image');
        $this->assertSame('pending', $refund->fresh()->status);

        // File không phải ảnh (PDF) → chặn.
        $this->actingAs($this->admin)->post(route('tuition.refunds.approve', $refund->id), [
            'clawback_commission' => 0,
            'proof_image' => UploadedFile::fake()->create('unc.pdf', 10, 'application/pdf'),
        ])->assertSessionHasErrors('proof_image');

        $this->actingAs($this->admin)->post(route('tuition.refunds.approve', $refund->id), [
            'clawback_commission' => 0,
            'proof_image' => UploadedFile::fake()->image('unc.png'),
        ])->assertSessionHasNoErrors();

        $refund->refresh();
        $this->assertSame('approved', $refund->status);
        $this->assertNotNull($refund->proof_path);
        $this->assertStringStartsWith('tuition/refund-proofs/', $refund->proof_path);
        Storage::disk('local')->assertExists($refund->proof_path);
        $this->assertSame(2000000.0, (float) $this->tuition->fresh()->paid_amount);

        // Ảnh bằng chứng xem qua route kiểm tra phạm vi.
        $this->actingAs($this->accountant)->get(route('tuition.refunds.proof', $refund->id))->assertOk();
        $otherManager = $this->makeUser('manager', $this->branch2);
        $this->actingAs($otherManager)->get(route('tuition.refunds.proof', $refund->id))->assertForbidden();
    }

    public function test_transfer_approval_stays_with_finance_staff_and_needs_no_proof(): void
    {
        $target = $this->makeStudent('HV-PAR-003', 'Lê Nhận', $this->branch);
        $targetTuition = $this->makeTuition($target, 5000000);
        $transfer = $this->refundRequest(['type' => 'transfer', 'target_student_id' => $target->id, 'refund_amount' => 800000, 'no_transfer_reason' => null]);

        $this->actingAs($this->accountant)->post(route('tuition.refunds.approve', $transfer->id))->assertSessionHasNoErrors();
        $this->assertSame('approved', $transfer->fresh()->status);
        $this->assertFalse((bool) $transfer->fresh()->clawback_commission);
        $this->assertSame(4200000.0, (float) $targetTuition->fresh()->debt_amount);
    }

    public function test_overdue_requests_are_flagged_but_approval_is_not_blocked(): void
    {
        $this->travelTo(Carbon::parse('2026-09-10 09:00'));
        $refund = $this->refundRequest();
        $this->assertFalse($refund->isProcessingOverdue());

        // 18/09 > hạn 17/09 → Quá hạn xử lý
        $this->travelTo(Carbon::parse('2026-09-18 09:00'));
        $this->assertTrue($refund->fresh()->isProcessingOverdue());
        $this->assertSame(1, $refund->fresh()->processingOverdueDays());

        $this->actingAs($this->admin)->get(route('tuition.refunds'))
            ->assertOk()
            ->assertViewHas('overdueCount', 1)
            ->assertSee('1 QUÁ HẠN')
            ->assertSee('Quá hạn xử lý');
        $this->actingAs($this->admin)->get(route('tuition.refunds', ['status' => 'overdue']))
            ->assertViewHas('historyRequests', fn ($rows) => $rows->count() === 1);

        $this->actingAs($this->admin)->post(route('tuition.refunds.approve', $refund->id), [
            'clawback_commission' => 0,
            'proof_image' => UploadedFile::fake()->image('unc.jpg'),
        ])->assertSessionHasNoErrors()->assertSessionHas('status', fn ($msg) => str_contains($msg, 'Quá hạn xử lý'));

        $refund->refresh();
        $this->assertSame('approved', $refund->status);
        // Đã xử lý sau hạn → vẫn giữ cờ (hiện "Xử lý trễ hạn").
        $this->assertTrue($refund->isProcessingOverdue());
        $this->actingAs($this->admin)->get(route('tuition.refunds'))->assertSee('Xử lý trễ hạn');
    }

    public function test_commission_clawback_choice_still_applies_on_admin_refund_approval(): void
    {
        $refund = $this->refundRequest();

        $this->actingAs($this->admin)->post(route('tuition.refunds.approve', $refund->id), [
            'clawback_commission' => '1', 'clawback_amount' => 30000,
            'proof_image' => UploadedFile::fake()->image('unc.jpg'),
        ])->assertSessionHasNoErrors();

        $refund->refresh();
        $this->assertTrue((bool) $refund->clawback_commission);
        $this->assertSame(30000.0, (float) $refund->clawback_amount);
        $this->assertNotNull($refund->approved_at);
    }

    public function test_reject_stores_reason(): void
    {
        $refund = $this->refundRequest();
        $this->actingAs($this->accountant)->post(route('tuition.refunds.reject', $refund->id), ['rejection_reason' => 'Đề nghị chuyển nhượng'])
            ->assertSessionHasNoErrors();
        $this->assertSame('rejected', $refund->fresh()->status);
        $this->assertSame('Đề nghị chuyển nhượng', $refund->fresh()->rejection_reason);
    }

    // ---------------------------------------------------------------------
    // Màn hình (mockup)
    // ---------------------------------------------------------------------

    public function test_ds_thu_phi_groups_due_tuitions_and_scopes_stats(): void
    {
        $this->tuition->update(['due_date' => now()->subDays(10)]);
        $this->tuition->recalculateDebt();
        $soon = $this->makeStudent('HV-PAR-SOON', 'Sắp Đến Hạn', $this->branch);
        $this->makeTuition($soon, 2000000, extra: ['due_date' => now()->addDays(5)]);
        $other = $this->makeStudent('HV-PAR-OTHER', 'Chi Nhánh Khác', $this->branch2);
        $this->makeTuition($other, 9000000, extra: ['due_date' => now()->subDays(2)]);

        $this->actingAs($this->manager)->get(route('tuition.students'))
            ->assertOk()
            ->assertSee('Danh sách học viên đến hạn thu phí')
            ->assertSee('Nhóm "Quá hạn"', false)
            ->assertSee('Quá hạn nghiêm trọng')
            ->assertSee('Nhóm "Sắp đến hạn"', false)
            ->assertSee('Khoản thu')
            ->assertSee('Xác nhận đã liên hệ')
            ->assertSee('HV-PAR-SOON')
            ->assertDontSee('HV-PAR-OTHER')
            // Thẻ thống kê chỉ cộng chi nhánh của Quản lý (6tr + 2tr), không cộng 9tr của chi nhánh khác.
            ->assertViewHas('stats', fn ($stats) => $stats['final'] === 8000000.0);
    }

    public function test_lich_su_thu_filters_details_and_exports(): void
    {
        $receipt = TuitionReceipt::where('student_tuition_id', $this->tuition->id)->firstOrFail();
        $receipt->update(['invoice_number' => 'C26CG-0000007', 'payment_method' => 'transfer', 'transaction_code' => 'FTPAR001']);
        \App\Models\InvoiceConfiguration::create(['branch_id' => $this->branch->id, 'template_code' => '1/002', 'series_code' => 'C26CG', 'start_number' => 1, 'current_number' => 8, 'is_active' => true]);
        $other = $this->makeStudent('HV-PAR-H2', 'Học Viên Hai', $this->branch);
        $this->makeTuition($other, 1000000, paid: 500000);

        $this->actingAs($this->accountant)->get(route('tuition.history', ['student_id' => $this->student->id]))
            ->assertOk()
            ->assertSee('Lịch sử thu học phí')
            ->assertSee('Học viên: Nguyễn Hoàn')
            ->assertSee('C26CG-0000007')
            ->assertSee('Chính thức')
            ->assertSee('Chi tiết Phiếu thu')
            ->assertSee('Tải lên biên lai mới')
            ->assertViewHas('templates', fn ($t) => $t['C26CG'] === '1/002')
            ->assertDontSee('HV-PAR-H2')
            ->assertDontSee('01GTKT0/001');

        $this->actingAs($this->accountant)->get(route('tuition.history', ['method' => 'cash']))
            ->assertOk()->assertSee('HV-PAR-H2')->assertDontSee('C26CG-0000007');

        // Chỉ khoản tái tục: khoản học phí đầu tiên của học viên bị loại.
        $renewal = StudentTuition::create(['student_id' => $this->student->id, 'branch_id' => $this->branch->id, 'total_amount' => 4000000, 'final_amount' => 4000000, 'paid_amount' => 0, 'debt_amount' => 4000000, 'status' => 'unpaid']);
        $this->approvedReceipt($renewal, 1000000, ['receipt_number' => 'PT-RENEWAL-1']);
        $this->actingAs($this->accountant)->get(route('tuition.history', ['kind' => 'renewal']))
            ->assertOk()->assertSee('PT-RENEWAL-1')->assertDontSee('C26CG-0000007')->assertSee('khoản thu tái tục');

        $csv = $this->actingAs($this->accountant)->get(route('tuition.history.export', ['student_id' => $this->student->id]))
            ->assertOk()->streamedContent();
        $this->assertStringContainsString('C26CG-0000007', $csv);
        $this->assertStringContainsString('PT-RENEWAL-1', $csv);
        $this->assertStringNotContainsString('HV-PAR-H2', $csv);
    }

    public function test_duyet_huy_hoa_don_is_admin_only_exports_and_shows_no_fake_proof(): void
    {
        $receipt = TuitionReceipt::where('student_tuition_id', $this->tuition->id)->firstOrFail();
        $receipt->update(['invoice_number' => 'C26CG-0000042']);
        $cancellation = \App\Models\InvoiceCancellation::create([
            'invoice_number' => 'C26CG-0000042', 'tuition_receipt_id' => $receipt->id, 'student_id' => $this->student->id,
            'amount' => 3000000, 'reason' => 'Viết sai tên phụ huynh', 'requester_id' => $this->accountant->id, 'status' => 'pending',
        ]);

        $this->actingAs($this->accountant)->get(route('tuition.invoices.cancellations'))
            ->assertOk()
            ->assertSee('Chỉ Admin phê duyệt')
            ->assertSee('Xuất danh sách')
            ->assertSee('Yêu cầu đang chờ Admin phê duyệt.')
            ->assertDontSee('hoadon_gachcheo_huy.jpg')
            ->assertDontSee('Đã gạch chéo 3 liên');
        $this->actingAs($this->accountant)->post(route('tuition.invoices.cancellations.approve', $cancellation->id))->assertForbidden();
        $this->actingAs($this->manager)->post(route('tuition.invoices.cancellations.reject', $cancellation->id), ['rejection_reason' => 'x'])->assertForbidden();

        $csv = $this->actingAs($this->accountant)->get(route('tuition.invoices.cancellations.export', ['status' => 'all']))->assertOk()->streamedContent();
        $this->assertStringContainsString('C26CG-0000042', $csv);
        $this->assertStringContainsString('Viết sai tên phụ huynh', $csv);

        $this->actingAs($this->admin)->get(route('tuition.invoices.cancellations'))->assertOk()->assertSee('Duyệt hủy hóa đơn');
        $this->actingAs($this->admin)->post(route('tuition.invoices.cancellations.approve', $cancellation->id))->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $receipt->fresh()->status);
        $this->assertSame(6000000.0, (float) $this->tuition->fresh()->debt_amount);
    }

    public function test_dai_so_hoa_don_is_branch_scoped_and_notifies_branch(): void
    {
        $other = \App\Models\InvoiceConfiguration::create(['branch_id' => $this->branch2->id, 'template_code' => '1/001', 'series_code' => 'C26DD', 'start_number' => 1, 'end_number' => 100, 'current_number' => 1, 'is_active' => true]);
        $branchAccountant2 = $this->makeUser('accountant');
        $manager = $this->manager;

        // Kế toán chi nhánh CG: không thấy / không sửa dải của chi nhánh ĐĐ, không sửa dải mặc định.
        $this->actingAs($this->accountant)->get(route('tuition.config'))->assertOk()->assertDontSee('C26DD')->assertSee('Chính sách cấp số hóa đơn');
        $this->actingAs($this->accountant)->post(route('tuition.config.ranges.toggle', $other->id))->assertForbidden();

        $this->actingAs($this->accountant)->post(route('tuition.config.ranges.store'), [
            'branch_id' => $this->branch->id, 'template_code' => '1/001', 'series_code' => 'C26CG', 'start_number' => 1, 'end_number' => 500,
        ])->assertSessionHasNoErrors();

        // Thông báo tới Kế toán + Quản lý cơ sở của chi nhánh (không gửi cho người thao tác).
        $this->assertDatabaseHas('admin_notifications', ['user_id' => $branchAccountant2->id, 'type' => 'invoice_range_changed']);
        $this->assertDatabaseHas('admin_notifications', ['user_id' => $manager->id, 'type' => 'invoice_range_changed']);
        $this->assertDatabaseMissing('admin_notifications', ['user_id' => $this->accountant->id, 'type' => 'invoice_range_changed']);

        $this->actingAs($this->admin)->get(route('tuition.config'))->assertOk()->assertSee('C26DD')->assertSee('C26CG');
    }

    public function test_tai_khoan_ngan_hang_has_type_search_and_active_flag(): void
    {
        $this->actingAs($this->accountant)->post(route('system-config.bank-accounts.store'), [
            'account_type' => 'company', 'bank_code' => 'vcb', 'bank_name' => 'Vietcombank', 'account_number' => '0071001234567',
            'account_holder' => 'Cong ty CP Giao duc Menglish', 'is_default_vietqr' => 1,
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->accountant)->post(route('system-config.bank-accounts.store'), [
            'account_type' => 'other', 'bank_code' => 'TCB', 'bank_name' => 'Techcombank', 'account_number' => '190345678910',
            'account_holder' => 'Nguyen Van Dai Dien', 'branch_id' => $this->branch->id,
        ])->assertSessionHasNoErrors();

        $default = \App\Models\BankAccount::where('account_number', '0071001234567')->firstOrFail();
        $other = \App\Models\BankAccount::where('account_number', '190345678910')->firstOrFail();
        $this->assertSame('company', $default->account_type);
        $this->assertSame('other', $other->account_type);

        $this->actingAs($this->accountant)->get(route('system-config.bank-accounts'))
            ->assertOk()
            ->assertSee('Cấu hình Tài khoản ngân hàng thu tiền')
            ->assertSee('Danh sách tài khoản')
            ->assertSee('CÔNG TY')->assertSee('KHÁC')
            ->assertSee('Tích hợp VietQR')
            ->assertSee('Hiển thị 2 trên 2 tài khoản');
        $this->actingAs($this->accountant)->get(route('system-config.bank-accounts', ['q' => 'Techcom']))
            ->assertOk()->assertSee('190345678910')->assertSee('Hiển thị 1 trên 2 tài khoản')
            ->assertViewHas('accounts', fn ($accounts) => $accounts->pluck('account_number')->all() === ['190345678910']);

        // Ngừng dùng tài khoản thường được; tài khoản mặc định thì không.
        $payload = fn ($acc) => ['account_type' => $acc->account_type, 'bank_code' => $acc->bank_code, 'bank_name' => $acc->bank_name, 'account_number' => $acc->account_number, 'account_holder' => $acc->account_holder, 'is_active' => 0];
        $this->actingAs($this->accountant)->put(route('system-config.bank-accounts.update', $other->id), $payload($other))->assertSessionHasNoErrors();
        $this->assertFalse($other->fresh()->is_active);
        $this->actingAs($this->accountant)->put(route('system-config.bank-accounts.update', $default->id), $payload($default))->assertSessionHasErrors('is_active');
        $this->assertTrue($default->fresh()->is_active);
    }

    public function test_nhac_no_quick_settings_sync_reminder_rules(): void
    {
        \App\Models\DebtReminderRule::create(['milestone_key' => 'T-3', 'offset_days' => -3, 'title' => 'Nhắc cũ 3 ngày', 'template_content' => 'Nhắc {ten_hoc_vien}', 'is_enabled' => false]);

        $this->actingAs($this->accountant)->get(route('system-config.debt-reminders'))
            ->assertOk()
            ->assertSee('Mốc nhắc nợ trước hạn')
            ->assertSee('Mốc nhắc lại')
            ->assertSee('Mốc nhắc lại bắt buộc phải nằm trong khoảng từ 1 đến 3 ngày trước hạn.')
            ->assertSee('Mốc quá hạn bắt buộc liên hệ')
            ->assertSee('Chuông thông báo in-app');

        // Mốc nhắc lại ngoài 1–3 ngày bị chặn.
        $this->actingAs($this->accountant)->post(route('system-config.debt-reminders.settings'), ['first_days' => 7, 'repeat_days' => 4, 'must_contact_days' => 7])
            ->assertSessionHasErrors('repeat_days');

        $this->actingAs($this->accountant)->post(route('system-config.debt-reminders.settings'), ['first_days' => 7, 'repeat_days' => 3, 'must_contact_days' => 10])
            ->assertSessionHasNoErrors();

        $first = \App\Models\DebtReminderRule::where('milestone_key', 'NHAC-TRUOC')->firstOrFail();
        $this->assertSame(-7, $first->offset_days);
        $this->assertTrue($first->is_enabled);
        // Đã có mốc T-3 → bật lại mốc đó, không tạo mốc trùng ngày.
        $this->assertTrue(\App\Models\DebtReminderRule::where('milestone_key', 'T-3')->firstOrFail()->is_enabled);
        $this->assertNull(\App\Models\DebtReminderRule::where('milestone_key', 'NHAC-LAI')->first());
        $this->assertSame(10, (int) \App\Models\SystemSetting::get('debt_reminder.must_contact_days'));

        $this->actingAs($this->accountant)->post(route('system-config.debt-reminders.settings'), ['first_days' => 10, 'repeat_days' => 2, 'must_contact_days' => 10])
            ->assertSessionHasNoErrors();
        $this->assertSame(-2, \App\Models\DebtReminderRule::where('milestone_key', 'NHAC-LAI')->firstOrFail()->offset_days);
        $this->assertSame(-10, $first->fresh()->offset_days);
    }

    public function test_bao_cao_doanh_thu_scopes_branch_accountant_and_exports_filter(): void
    {
        // Phiếu đã duyệt 3tr ở CG (setUp); thêm 5tr ở ĐĐ.
        $other = $this->makeStudent('HV-PAR-DD', 'Học Viên ĐĐ', $this->branch2);
        $this->makeTuition($other, 5000000, paid: 5000000);
        $headAccountant = $this->makeUser('accountant', withBranch: false);

        // Kế toán chi nhánh CG: chỉ thấy CG (trước đây thấy toàn hệ thống).
        $this->actingAs($this->accountant)->get(route('finance.reports.revenue'))
            ->assertOk()
            ->assertViewHas('totalRevenue', 3000000.0)
            ->assertViewHas('branchMatrix', fn ($m) => count($m) === 1 && $m[0]['branch']->id === $this->branch->id)
            ->assertDontSee('Gồm khóa IELTS Foundation')
            ->assertDontSee('"Chờ duyệt", "Đã duyệt" hoặc "Tạm thu"', false);
        $this->actingAs($this->accountant)->get(route('finance.reports.revenue', ['branch_id' => $this->branch2->id]))
            ->assertOk()->assertViewHas('branchId', $this->branch->id);

        // Kế toán tổng + Admin: toàn hệ thống.
        $this->actingAs($headAccountant)->get(route('finance.reports.revenue'))->assertOk()->assertViewHas('totalRevenue', 8000000.0);
        $this->actingAs($this->admin)->get(route('finance.reports.revenue'))->assertOk()->assertViewHas('totalRevenue', 8000000.0)->assertSee('Chi khác');

        // Học vụ (không có finance.view) không vào được.
        $this->actingAs($this->makeUser('academic_staff'))->get(route('finance.reports.revenue'))->assertForbidden();

        // Xuất báo cáo theo bộ lọc chi nhánh.
        $csv = $this->actingAs($this->admin)->get(route('finance.reports.revenue.export', ['branch_id' => $this->branch2->id]))->assertOk()->streamedContent();
        $this->assertStringContainsString('CN Đống Đa', $csv);
        $this->assertStringNotContainsString('CN Cầu Giấy', $csv);
        $csv = $this->actingAs($this->accountant)->get(route('finance.reports.revenue.export'))->assertOk()->streamedContent();
        $this->assertStringNotContainsString('CN Đống Đa', $csv);
    }

    public function test_khoan_chi_scoped_for_branch_accountant_and_export_uses_search(): void
    {
        \App\Models\OperatingExpense::create(['expense_date' => now()->toDateString(), 'title' => 'Tiền điện CG', 'amount' => 1500000, 'payment_method' => 'chuyen_khoan', 'branch_id' => $this->branch->id, 'category' => 'mat_bang_tien_ich', 'creator_id' => $this->admin->id]);
        \App\Models\OperatingExpense::create(['expense_date' => now()->toDateString(), 'title' => 'Văn phòng phẩm CG', 'amount' => 200000, 'payment_method' => 'tien_mat', 'branch_id' => $this->branch->id, 'category' => 'khac', 'creator_id' => $this->admin->id]);
        $dd = \App\Models\OperatingExpense::create(['expense_date' => now()->toDateString(), 'title' => 'Tiền nhà ĐĐ', 'amount' => 9000000, 'payment_method' => 'chuyen_khoan', 'branch_id' => $this->branch2->id, 'category' => 'mat_bang_tien_ich', 'creator_id' => $this->admin->id]);

        $this->actingAs($this->accountant)->get(route('finance.expenses.index'))
            ->assertOk()->assertSee('Sổ khoản chi vận hành')->assertSee('Tiền điện CG')->assertDontSee('Tiền nhà ĐĐ')->assertDontSee('Epic 7');
        $this->actingAs($this->accountant)->delete(route('finance.expenses.destroy', $dd->id))->assertForbidden();
        $this->actingAs($this->accountant)->post(route('finance.expenses.store'), [
            'expense_date' => now()->toDateString(), 'title' => 'Chi hộ ĐĐ', 'amount' => 100000, 'payment_method' => 'tien_mat', 'branch_id' => $this->branch2->id,
        ])->assertForbidden();

        $csv = $this->actingAs($this->admin)->get(route('finance.expenses.export', ['search' => 'điện']))->assertOk()->streamedContent();
        $this->assertStringContainsString('Tiền điện CG', $csv);
        $this->assertStringNotContainsString('Văn phòng phẩm CG', $csv);
    }

    public function test_tuition_import_is_limited_to_own_branch(): void
    {
        $this->actingAs($this->accountant)->get(route('tuition.import'))
            ->assertOk()->assertViewHas('branches', fn ($b) => $b->pluck('id')->all() === [$this->branch->id]);

        $file = UploadedFile::fake()->createWithContent('hoc-phi.csv', "Mã học viên,Mã lớp,Học phí niêm yết\nHV-PAR-001,,1000000");
        $this->actingAs($this->accountant)->post(route('tuition.import.store'), ['branch_id' => $this->branch2->id, 'excel_file' => $file])
            ->assertForbidden();
    }

    public function test_lap_and_duyet_phieu_thu_show_real_data(): void
    {
        $this->actingAs($this->accountant)->get(route('tuition.receipts.create', ['tuition_id' => $this->tuition->id]))
            ->assertOk()
            ->assertSee('Lập phiếu thu học phí')->assertSee('Mã phiếu:')->assertSee('Bản nháp')
            ->assertDontSee('Phụ huynh nộp thanh toán học phí & phụ thu qua cổng MEnglish.')
            ->assertDontSee('Phụ thu giáo trình & học liệu bổ sung');

        $pending = TuitionReceipt::create([
            'receipt_number' => 'PT-PAR-PENDING', 'student_tuition_id' => $this->tuition->id, 'student_id' => $this->student->id,
            'amount' => 1000000, 'tuition_amount' => 1000000, 'payment_method' => 'transfer', 'transaction_code' => 'FTPAR999',
            'payment_date' => now(), 'creator_id' => $this->manager->id, 'status' => 'pending',
        ]);
        $this->actingAs($this->accountant)->get(route('tuition.receipts.approve', ['selected_id' => $pending->id]))
            ->assertOk()->assertSee('Cần đối chiếu thủ công')->assertSee('Không có ghi chú.')->assertSee('Tự động làm mới')
            ->assertDontSee('Phụ huynh nộp thanh toán đúng số tiền');

        \App\Models\SepayTransaction::create(['sepay_id' => 'FTPAR999', 'gateway' => 'VCB', 'transaction_date' => now(), 'account_number' => '0071001234567', 'transfer_type' => 'in', 'transfer_amount' => 1000000, 'content' => 'HV-PAR-001', 'status' => 'unmatched']);
        $this->actingAs($this->accountant)->get(route('tuition.receipts.approve', ['selected_id' => $pending->id]))
            ->assertOk()->assertSee('Khớp số tiền &amp; mã giao dịch', false);
    }
}
