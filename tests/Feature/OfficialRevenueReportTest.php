<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\TuitionReceipt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** TK-2026-0169: Doanh thu chính thức — luật tính ở App\Services\Finance\OfficialRevenueQuery. */
class OfficialRevenueReportTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branchA;

    private Branch $branchB;

    private User $admin;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branchA = Branch::create(['name' => 'CN A', 'code' => 'CNA', 'is_active' => true]);
        $this->branchB = Branch::create(['name' => 'CN B', 'code' => 'CNB', 'is_active' => true]);
        $this->admin = User::factory()->create(['branch_id' => $this->branchA->id, 'is_active' => true]);
        $this->admin->assignRole('admin');
    }

    private function receipt(Branch $branch, float $amount, string $method, string $status, ?string $invoice, array $extra = []): TuitionReceipt
    {
        $n = ++$this->seq;
        $student = Student::create(['code' => "HV-OR-$n", 'name' => "HV $n", 'phone' => '09000000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT), 'branch_id' => $branch->id]);
        $tuition = StudentTuition::create([
            'student_id' => $student->id, 'branch_id' => $branch->id, 'total_amount' => 9000000, 'final_amount' => 9000000,
            'paid_amount' => 0, 'debt_amount' => 9000000, 'due_date' => now()->addDays(10), 'status' => 'unpaid',
        ]);

        return TuitionReceipt::create(array_merge([
            'receipt_number' => "PT-OR-$n", 'invoice_number' => $invoice, 'student_tuition_id' => $tuition->id,
            'student_id' => $student->id, 'amount' => $amount, 'payment_method' => $method,
            'payment_date' => now(), 'status' => $status,
        ], $extra));
    }

    private function total(array $query = [], ?User $as = null): float
    {
        $response = $this->actingAs($as ?? $this->admin)->get(route('finance.reports.official-revenue', ['month' => now()->format('Y-m')] + $query));
        $response->assertOk();
        $total = null;
        $response->assertInertia(function (AssertableInertia $page) use (&$total) {
            $total = $page->toArray()['props']['totalRevenue'];

            return $page;
        });

        return (float) $total;
    }

    public function test_transfer_counts_only_when_approved_and_official(): void
    {
        $this->receipt($this->branchA, 1000000, 'transfer', 'approved', 'C26MEN-000001');   // Chính thức -> tính
        $this->receipt($this->branchA, 2000000, 'vietqr', 'approved', 'C26MEN-000002');     // Chính thức -> tính
        $this->receipt($this->branchA, 4000000, 'transfer', 'approved', null);              // Tạm thu (chưa HĐ) -> không
        $this->receipt($this->branchA, 8000000, 'transfer', 'pending', 'C26MEN-000003');    // Chính thức nhưng chưa duyệt -> không
        $this->receipt($this->branchA, 16000000, 'transfer', 'pending', null);              // Tạm thu -> không

        $this->assertSame(3000000.0, $this->total());
    }

    public function test_cash_counts_when_approved_only(): void
    {
        $this->receipt($this->branchA, 500000, 'cash', 'approved', null);
        $this->receipt($this->branchA, 700000, 'cash', 'pending', null);
        $this->receipt($this->branchA, 900000, 'cash', 'draft', null);
        $this->receipt($this->branchA, 1100000, 'cash', 'rejected', null);

        $this->assertSame(500000.0, $this->total());
    }

    public function test_receipts_of_cancelled_invoice_are_not_counted(): void
    {
        $this->receipt($this->branchA, 600000, 'cash', 'approved', 'C26MEN-000010');
        $this->receipt($this->branchA, 3000000, 'cash', 'cancelled', 'C26MEN-000011');
        $this->receipt($this->branchA, 5000000, 'transfer', 'cancelled', 'C26MEN-000012');

        $this->assertSame(600000.0, $this->total());
    }

    public function test_period_filter_excludes_other_months(): void
    {
        $this->receipt($this->branchA, 100000, 'cash', 'approved', null);
        $this->receipt($this->branchA, 900000, 'cash', 'approved', null, ['payment_date' => now()->subMonthNoOverflow()]);

        $this->assertSame(100000.0, $this->total());
    }

    public function test_branch_filter_scopes_revenue_and_scoped_user_cannot_widen(): void
    {
        $this->receipt($this->branchA, 100000, 'cash', 'approved', null);
        $this->receipt($this->branchB, 300000, 'cash', 'approved', null);

        $this->assertSame(400000.0, $this->total());
        $this->assertSame(300000.0, $this->total(['branch_id' => $this->branchB->id]));

        $role = Role::findOrCreate('custom_or_'.uniqid(), 'web');
        $role->givePermissionTo(['finance.view_revenue_report']);
        $scoped = User::factory()->create(['branch_id' => $this->branchA->id, 'is_active' => true]);
        $scoped->assignRole($role);
        $this->actingAs($scoped);

        // Người bị giới hạn chi nhánh A: xin chi nhánh B hoặc "all" vẫn chỉ thấy A.
        $this->assertSame(100000.0, $this->total(['branch_id' => $this->branchB->id], $scoped));
        $this->assertSame(100000.0, $this->total(['branch_id' => 'all'], $scoped));
    }

    public function test_permission_and_export(): void
    {
        $this->receipt($this->branchA, 1234000, 'cash', 'approved', null);
        $this->receipt($this->branchA, 999000, 'transfer', 'approved', null);

        $noPerm = User::factory()->create(['branch_id' => $this->branchA->id, 'is_active' => true]);
        $role = Role::findOrCreate('custom_or_none', 'web');
        $role->givePermissionTo(['finance.view']);
        $noPerm->assignRole($role);
        $this->actingAs($noPerm)->get(route('finance.reports.official-revenue'))->assertForbidden();
        $this->actingAs($noPerm)->get(route('finance.reports.official-revenue.export'))->assertForbidden();

        $csv = $this->actingAs($this->admin)->get(route('finance.reports.official-revenue.export', ['month' => now()->format('Y-m')]))
            ->assertOk()->streamedContent();
        $this->assertStringContainsString('DOANH THU CHÍNH THỨC', $csv);
        $this->assertStringContainsString('1.234.000', $csv);
        $this->assertStringNotContainsString('999.000', $csv);
    }

    public function test_provisional_report_is_unchanged_and_menu_lists_both(): void
    {
        $this->receipt($this->branchA, 2000000, 'transfer', 'approved', null); // Tạm thu: chính thức loại, tạm tính vẫn tính (đã duyệt)

        $response = $this->actingAs($this->admin)->get(route('finance.reports.revenue', ['month' => now()->format('Y-m')]));
        $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Finance/Reports/ProvisionalRevenue')
            ->where('totalRevenue', fn ($v) => (float) $v === 2000000.0));
        $this->assertSame(0.0, $this->total());

        $this->actingAs($this->admin)->get(route('finance.reports.revenue'))
            ->assertSee('Doanh thu tạm tính')->assertSee('Doanh thu chính thức');
    }
}
