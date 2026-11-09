<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Penalty;
use App\Models\User;
use App\Support\Roles;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * TK-2026-0030/0031 (BR-11, HIGH RISK money): lần vi phạm mức nhắc nhở (bậc phạt = 0đ) không được quyết phạt tiền;
 * mức theo cấu hình luôn hiển thị / điền sẵn; chỉ trạng thái fined mới bị trừ lương.
 * Bậc phạt 'gv.remarks_late' mặc định: lần 1 = 0đ (nhắc nhở), lần 2 = 30.000đ, lần 3+ = 60.000đ.
 */
class PenaltyReminderTierTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
        $branch = Branch::create(['code' => 'REMIND', 'name' => 'Cơ sở nhắc nhở', 'is_active' => true]);
        $this->admin = User::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->teacher = User::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);
        $this->teacher->assignRole(Roles::TEACHER_PARTTIME);
    }

    private function autoPenalty(int $n, string $status = 'pending', float $amount = 0): Penalty
    {
        return Penalty::create([
            'code' => "BB-REMIND-{$n}", 'user_id' => $this->teacher->id, 'auto_source' => 'gv.remarks_late',
            'violation_type' => 'Gửi nhận xét sau buổi học trễ', 'error_category' => 'academic',
            'violation_date' => today()->toDateString(), 'amount' => $amount, 'status' => $status,
        ]);
    }

    public function test_configured_amount_follows_ladder_by_occurrence(): void
    {
        $first = $this->autoPenalty(1);
        $second = $this->autoPenalty(2);
        $third = $this->autoPenalty(3);

        $this->assertSame(0.0, $first->configuredAmount());
        $this->assertTrue($first->isReminderTier());
        $this->assertSame(30000.0, $second->configuredAmount());
        $this->assertFalse($second->isReminderTier());
        $this->assertSame(60000.0, $third->configuredAmount());

        // Lập tay: không có cấu hình → không bao giờ bị coi là nhắc nhở.
        $manual = Penalty::create(['code' => 'BB-REMIND-M', 'user_id' => $this->teacher->id, 'violation_type' => 'Vi phạm khác',
            'violation_date' => today()->toDateString(), 'amount' => 0, 'status' => 'pending']);
        $this->assertNull($manual->configuredAmount());
        $this->assertFalse($manual->isReminderTier());
    }

    public function test_fining_a_reminder_tier_penalty_is_rejected_server_side(): void
    {
        $first = $this->autoPenalty(1);

        $this->actingAs($this->admin)->post(route('penalties.confirm', $first->id), ['decision' => 'fine', 'amount' => 30000])
            ->assertSessionHasErrors('amount');

        $first->refresh();
        $this->assertSame('pending', $first->status);
        $this->assertEquals(0, $first->amount);
        $this->assertNull($first->due_date);
    }

    public function test_reminder_tier_can_still_be_confirmed_as_error(): void
    {
        $first = $this->autoPenalty(1);

        $this->actingAs($this->admin)->post(route('penalties.confirm', $first->id), ['decision' => 'error'])
            ->assertSessionHasNoErrors();

        $this->assertSame('confirmed', $first->fresh()->status);
    }

    public function test_second_occurrence_can_be_fined(): void
    {
        $this->autoPenalty(1);
        $second = $this->autoPenalty(2, 'confirmed', 30000);

        $this->actingAs($this->admin)->post(route('penalties.confirm', $second->id), ['decision' => 'fine', 'amount' => 30000])
            ->assertSessionHasNoErrors();

        $this->assertSame('fined', $second->fresh()->status);
    }

    public function test_index_exposes_configured_amount_at_every_step(): void
    {
        $this->autoPenalty(1);
        $this->autoPenalty(2, 'confirmed', 0);

        $this->actingAs($this->admin)->get(route('penalties.index'))->assertInertia(fn (Assert $page) => $page
            ->where('penalties.data', fn ($rows) => collect($rows)->pluck('configured_amount', 'code')->all() == [
                'BB-REMIND-1' => 0, 'BB-REMIND-2' => 30000,
            ]));
    }

    public function test_only_fined_status_is_deductible_from_payroll(): void
    {
        $this->assertSame(['fined'], Penalty::payableStatuses());

        $period = \App\Models\PayrollPeriod::create([
            'code' => 'PR-REMIND', 'title' => 'Kỳ nhắc nhở', 'month' => (int) today()->format('n'), 'year' => (int) today()->format('Y'),
            'start_date' => today()->startOfMonth()->toDateString(), 'end_date' => today()->endOfMonth()->toDateString(), 'status' => 'draft',
        ]);
        $ids = [];
        foreach (['pending', 'explained', 'confirmed', 'resolved', 'cancelled', 'fined'] as $i => $status) {
            $ids[$status] = $this->autoPenalty(10 + $i, $status, 30000)->id;
        }
        $this->assertSame([$ids['fined']], Penalty::deductibleFor($period)->pluck('id')->all());
    }
}
