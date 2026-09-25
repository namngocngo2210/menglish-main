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
