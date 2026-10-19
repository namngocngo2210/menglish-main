<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\SepayTransaction;
use App\Models\TeacherHourlyRate;
use App\Models\User;
use App\Support\Rbac;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Rò rỉ dữ liệu liên chi nhánh ở module Lương / KPI / SePay:
 * - BXH KPI: Quản lý cơ sở chỉ thấy nhân sự chi nhánh mình, không thấy số tiền (KPI đ, doanh thu, hoa hồng) — chỉ Admin.
 * - Đơn giá riêng GV: Quản lý cơ sở chỉ xem / tạo đơn giá cho GV chi nhánh mình.
 * - Chấm công GV ?user_id= của chi nhánh khác không lộ thẻ GV.
 * - API giao dịch SePay gần đây: chỉ người quản lý tài khoản ngân hàng (Admin).
 */
class PayrollKpiScopeLeaksTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branchA;

    private Branch $branchB;

    private User $admin;

    private User $managerA;

    private User $teacherA;

    private User $teacherB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        // Đặt rõ phạm vi KPI của Quản lý cơ sở là "chi nhánh" (không phụ thuộc mặc định trong config/access.php).
        $manager = Role::findByName('manager', 'web');
        $manager->revokePermissionTo(array_values(array_filter(['kpi.scope_all', 'kpi.scope_own'], fn ($p) => $manager->hasPermissionTo($p))));
        $manager->givePermissionTo('kpi.scope_branch');
        Rbac::flushCache();

        $this->branchA = Branch::create(['name' => 'Branch Alpha', 'code' => 'BRA', 'is_active' => true]);
        $this->branchB = Branch::create(['name' => 'Branch Bravo', 'code' => 'BRB', 'is_active' => true]);

        $this->admin = $this->makeUser('admin', $this->branchA, 'Admin Root', 'AD-001');
        $this->managerA = $this->makeUser('manager', $this->branchA, 'Manager Alpha', 'QL-A01');
        $this->teacherA = $this->makeUser('teacher_parttime', $this->branchA, 'Teacher Alpha', 'GV-A01');
        $this->teacherB = $this->makeUser('teacher_parttime', $this->branchB, 'Teacher Bravo', 'GV-B99');
    }

    private function makeUser(string $role, Branch $branch, string $name, string $code): User
    {
        $user = User::factory()->create(['name' => $name, 'employee_code' => $code, 'branch_id' => $branch->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function retentionRecord(PayrollPeriod $period, User $teacher, float $bonus): void
    {
        PayrollRecord::forceCreate([
            'payroll_period_id' => $period->id,
            'user_id' => $teacher->id,
            'kpi_source' => PayrollRecord::KPI_RETENTION,
            'retention_students' => 7,
            'retention_base_students' => 8,
            'retention_tier' => 50000,
            'kpi_bonus' => $bonus,
        ]);
    }

    public function test_kpi_leaderboard_limits_manager_to_own_branch_and_hides_money(): void
    {
        $period = PayrollPeriod::create([
            'code' => 'PR-2026-09', 'title' => 'Bảng lương Tháng 9/2026', 'month' => 9, 'year' => 2026,
            'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'status' => 'draft',
        ]);
        $this->retentionRecord($period, $this->teacherA, 350000);
        $this->retentionRecord($period, $this->teacherB, 420000);
        $saleA = $this->makeUser('sales_consultant', $this->branchA, 'Sale Alpha', 'SA-A01');
        $saleB = $this->makeUser('sales_consultant', $this->branchB, 'Sale Bravo', 'SA-B01');

        // Quản lý cơ sở A: chỉ nhân sự chi nhánh A, không có cột tiền; ?branch_id= chi nhánh B bị bỏ qua.
        foreach ([[], ['branch_id' => $this->branchB->id]] as $extra) {
            $this->actingAs($this->managerA)->get(route('payroll.kpi-leaderboard', ['period' => '2026-09'] + $extra))
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->where('retentionPage.data', fn ($rows) => collect($rows)->pluck('user_id')->all() === [$this->teacherA->id]
                        && collect($rows)->every(fn ($r) => $r['kpi_bonus'] === null && $r['retention_tier'] === null))
                    ->where('salesPage.data', fn ($rows) => ! collect($rows)->pluck('id')->contains($saleB->id)
                        && collect($rows)->pluck('id')->contains($saleA->id)
                        && collect($rows)->every(fn ($r) => $r['revenue'] === null && $r['base'] === null && $r['commission'] === null))
                    ->where('branches', fn ($options) => collect($options)->pluck('value')->map(fn ($v) => (int) $v)->all() === [$this->branchA->id])
                    ->where('branchId', null)
                    ->etc());
        }

        // Admin: mọi chi nhánh, có số tiền.
        $this->actingAs($this->admin)->get(route('payroll.kpi-leaderboard', ['period' => '2026-09']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('retentionPage.data', fn ($rows) => collect($rows)->pluck('user_id')->sort()->values()->all() === collect([$this->teacherA->id, $this->teacherB->id])->sort()->values()->all()
                    && collect($rows)->firstWhere('user_id', $this->teacherB->id)['kpi_bonus'] == 420000)
                ->where('salesPage.data', fn ($rows) => collect($rows)->pluck('id')->contains($saleB->id)
                    && collect($rows)->every(fn ($r) => $r['commission'] !== null && $r['revenue'] !== null))
                ->where('branches', fn ($options) => count($options) === 2)
                ->etc());
    }

    public function test_teacher_rates_are_limited_to_manager_branch(): void
    {
        TeacherHourlyRate::create(['user_id' => $this->teacherA->id, 'hourly_rate' => 210000, 'rate_unit' => 'session', 'effective_from' => '2026-01-01']);
        TeacherHourlyRate::create(['user_id' => $this->teacherB->id, 'hourly_rate' => 390000, 'rate_unit' => 'session', 'effective_from' => '2026-01-01']);

        $this->actingAs($this->managerA)->get(route('payroll.config.teacher-rates'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('teachers', fn ($rows) => collect($rows)->pluck('id')->contains($this->teacherA->id)
                    && ! collect($rows)->pluck('id')->contains($this->teacherB->id))
                ->where('history.data', fn ($rows) => collect($rows)->pluck('user_id')->unique()->values()->all() === [$this->teacherA->id])
                ->etc());
        $this->actingAs($this->managerA)->get(route('payroll.config.teacher-rates', ['teacher_id' => $this->teacherB->id]))->assertNotFound();

        $this->actingAs($this->managerA)->post(route('payroll.config.teacher-rates.personal.store'), [
            'user_id' => $this->teacherB->id, 'hourly_rate' => 500000, 'rate_unit' => 'session', 'effective_from' => '2026-12-01',
        ])->assertNotFound();
        $this->assertSame(1, TeacherHourlyRate::where('user_id', $this->teacherB->id)->count());

        // Trong chi nhánh vẫn tạo được; Admin thấy và tạo cho mọi chi nhánh.
        $this->actingAs($this->managerA)->post(route('payroll.config.teacher-rates.personal.store'), [
            'user_id' => $this->teacherA->id, 'hourly_rate' => 250000, 'rate_unit' => 'session', 'effective_from' => '2026-12-01',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(2, TeacherHourlyRate::where('user_id', $this->teacherA->id)->count());

        $this->actingAs($this->admin)->get(route('payroll.config.teacher-rates', ['teacher_id' => $this->teacherB->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('teachers', fn ($rows) => collect($rows)->pluck('id')->contains($this->teacherB->id))
                ->where('selectedTeacher.id', $this->teacherB->id)
                ->etc());
        $this->actingAs($this->admin)->post(route('payroll.config.teacher-rates.personal.store'), [
            'user_id' => $this->teacherB->id, 'hourly_rate' => 500000, 'rate_unit' => 'session', 'effective_from' => '2026-12-01',
        ])->assertSessionHasNoErrors();
        $this->assertSame(2, TeacherHourlyRate::where('user_id', $this->teacherB->id)->count());
    }

    public function test_teacher_timesheets_do_not_reveal_other_branch_teacher(): void
    {
        $this->actingAs($this->managerA)->get(route('payroll.timesheets.teachers', ['user_id' => $this->teacherB->id]))
            ->assertOk()
            ->assertDontSee('Teacher Bravo')
            ->assertDontSee('GV-B99')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('teacher', null)
                ->etc());

        $this->actingAs($this->managerA)->get(route('payroll.timesheets.teachers', ['user_id' => $this->teacherA->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('teacher.id', $this->teacherA->id)->etc());

        $this->actingAs($this->managerA)->get(route('payroll.timesheets.manual'))
            ->assertOk()
            ->assertDontSee('Teacher Bravo')
            ->assertSee('Teacher Alpha');

        $this->actingAs($this->admin)->get(route('payroll.timesheets.teachers', ['user_id' => $this->teacherB->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('teacher.id', $this->teacherB->id)->etc());
    }

    public function test_sepay_recent_transactions_are_admin_only(): void
    {
        config(['services.sepay.webhook_enabled' => true]);
        SepayTransaction::forceCreate([
            'sepay_id' => 'SP-LEAK-1', 'gateway' => 'MBBank', 'account_number' => '0123456789', 'transfer_type' => 'in',
            'transfer_amount' => 1000000, 'accumulated' => 987654321, 'content' => 'HV001 dong hoc phi',
            'raw_payload' => ['secret' => 'payload'], 'status' => 'unmatched', 'transaction_date' => now(),
        ]);

        $this->actingAs($this->managerA)->getJson(route('sepay.transactions.recent'))
            ->assertForbidden()
            ->assertDontSee('987654321')
            ->assertDontSee('raw_payload');

        $this->actingAs($this->admin)->getJson(route('sepay.transactions.recent'))
            ->assertOk()
            ->assertJsonPath('data.0.sepay_id', 'SP-LEAK-1');
    }
}
