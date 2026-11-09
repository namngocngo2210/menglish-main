<?php

namespace Tests\Feature;

use App\Models\PayrollPeriod;
use App\Models\Penalty;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** TK-2026-0071 (US R-14, AC-R20, GV-TC-07): widget gộp "Vi phạm / khoản trừ mới" trên Trang chủ GV. */
class TeacherPenaltyWidgetTest extends TestCase
{
    use RefreshDatabase;

    private User $an;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-08 09:00:00'));
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->an = User::factory()->create(['is_active' => true]);
        $this->an->assignRole('teacher_fulltime');
        PayrollPeriod::create(['code' => 'PR-W-11', 'title' => 'Bảng lương Tháng 11/2026', 'month' => 11, 'year' => 2026,
            'status' => 'draft', 'start_date' => '2026-11-01', 'end_date' => '2026-11-30']);
    }

    private function penalty(User $user, string $status, string $date, int $amount = 0): Penalty
    {
        static $n = 0;

        return Penalty::create(['code' => 'BB-W-'.++$n, 'user_id' => $user->id, 'violation_type' => 'Quên điểm danh',
            'violation_date' => $date, 'amount' => $amount, 'status' => $status]);
    }

    private function widget(User $user): array
    {
        return $this->actingAs($user)->get(route('teacher.home'))->assertOk()->inertiaProps('penaltyWidget');
    }

    public function test_widget_is_rendered_on_teacher_home_even_when_empty(): void
    {
        $this->actingAs($this->an)->get(route('teacher.home'))->assertOk()->assertSee('Vi phạm / khoản trừ mới');
        $this->assertSame(0, $this->widget($this->an)['total']);
    }

    public function test_counts_open_violations_and_new_deductions_in_one_number(): void
    {
        $this->penalty($this->an, 'pending', '2026-11-05');
        $this->penalty($this->an, 'explained', '2026-11-04');
        $this->penalty($this->an, 'fined', '2026-11-03', 50000);
        $this->penalty($this->an, 'fined', '2026-11-02', 100000);

        $w = $this->widget($this->an);

        $this->assertSame(2, $w['violations']);
        $this->assertSame(2, $w['deductions']);
        $this->assertEquals(150000, $w['deduction_amount']);
        $this->assertSame(4, $w['total']);
    }

    public function test_ignores_closed_cancelled_paid_and_other_period_deductions_and_other_users(): void
    {
        $other = User::factory()->create(['is_active' => true]);
        $other->assignRole('teacher');
        $this->penalty($this->an, 'cancelled', '2026-11-05');
        $this->penalty($this->an, 'resolved', '2026-11-05');
        $this->penalty($this->an, 'paid', '2026-11-05', 50000);
        $this->penalty($this->an, 'deducted', '2026-11-05', 50000);
        $this->penalty($other, 'pending', '2026-11-05');
        $this->penalty($other, 'fined', '2026-11-05', 70000);
        // Đã quyết phạt nhưng thuộc kỳ 10 đã khóa → trừ kỳ mở này, nên phải đếm; kỳ 12 chưa tới thì không.
        PayrollPeriod::create(['code' => 'PR-W-10', 'title' => 'Bảng lương Tháng 10/2026', 'month' => 10, 'year' => 2026,
            'status' => 'paid', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31']);
        $this->penalty($this->an, 'fined', '2026-10-20', 30000);
        $this->penalty($this->an, 'fined', '2026-12-05', 40000);

        $w = $this->widget($this->an);

        $this->assertSame(0, $w['violations']);
        $this->assertSame(1, $w['deductions']);
        $this->assertEquals(30000, $w['deduction_amount']);
    }

    public function test_deduction_count_matches_payroll_period_scope(): void
    {
        $this->penalty($this->an, 'fined', '2026-11-03', 50000);
        $this->penalty($this->an, 'fined', '2026-11-04', 20000);
        $period = PayrollPeriod::where('code', 'PR-W-11')->first();

        $expected = Penalty::withoutViewerScope()->where('user_id', $this->an->id)->deductibleFor($period)->count();

        $this->assertSame($expected, $this->widget($this->an)['deductions']);
    }

    public function test_falls_back_to_calendar_month_without_payroll_period(): void
    {
        PayrollPeriod::query()->delete();
        $this->penalty($this->an, 'fined', '2026-11-03', 50000);
        $this->penalty($this->an, 'fined', '2026-09-03', 50000);

        $this->assertSame(1, $this->widget($this->an)['deductions']);
    }
}
