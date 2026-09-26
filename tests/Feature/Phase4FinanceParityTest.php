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
}
