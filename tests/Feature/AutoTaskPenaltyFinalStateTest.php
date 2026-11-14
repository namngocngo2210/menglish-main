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

class AutoTaskPenaltyFinalStateTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
        $this->travelTo(now()->setDate(2026, 11, 14)->setTime(12, 0));
        $branch = Branch::create(['code' => 'FINAL-AUTO', 'name' => 'Final auto', 'is_active' => true]);
        $this->admin = User::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->staff = User::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);
        $this->staff->assignRole(Roles::ASSISTANT);
    }

    private function penalty(array $attributes = []): Penalty
    {
        return Penalty::create($attributes + [
            'code' => Penalty::generateCode(), 'user_id' => $this->staff->id,
            'auto_source' => 'task_overdue', 'violation_type' => 'Overdue task',
            'error_category' => 'operations', 'violation_date' => today(),
            'status' => 'fined', 'amount' => 80000,
        ]);
    }

    public function test_index_exposes_final_auto_actions_and_preserves_manual_fined_flags(): void
    {
        $auto = $this->penalty();
        $manual = $this->penalty(['auto_source' => null, 'due_date' => '2026-11-16']);

        $this->actingAs($this->admin)->get(route('penalties.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Penalties/Index')
                ->has('penalties.data', 2)
                ->where('penalties.data', function ($rows) use ($auto, $manual) {
                    $rows = collect($rows)->keyBy('id');
                    $autoRow = $rows->get($auto->id);
                    $manualRow = $rows->get($manual->id);

                    return $autoRow['final_auto'] === true
                        && $autoRow['can_defer'] === false
                        && $autoRow['can_cancel'] === true
                        && $autoRow['due_date'] === null
                        && $autoRow['status_label'] === 'Đã chốt phạt — trừ vào lương'
                        && $autoRow['can_decide'] === false
                        && $autoRow['can_explain'] === false
                        && $manualRow['final_auto'] === false
                        && $manualRow['can_defer'] === true
                        && $manualRow['can_cancel'] === false
                        && $manualRow['due_date'] === '16/11/2026'
                        && $manualRow['overdue'] === false
                        && $manualRow['step'] === 'fined';
                }));
    }

    public function test_final_auto_rejects_payment_deferral_remedy_and_resolution(): void
    {
        foreach (['fined', 'paid', 'deducted'] as $status) {
            $penalty = $this->penalty(['status' => $status]);
            foreach (['mark-paid', 'defer', 'remedy', 'resolve'] as $action) {
                $this->actingAs($this->admin)->postJson(route('penalties.'.$action, $penalty->id), [
                    'reason' => 'Move to next period', 'remedy_note' => 'Remedied',
                ])->assertUnprocessable()->assertJsonPath('message', 'Biên bản tự động chỉ có thể hủy.');
                $penalty->refresh();
                $this->assertSame($status, $penalty->status);
                $this->assertNull($penalty->paid_at);
                $this->assertNull($penalty->deduct_from_date);
                $this->assertNull($penalty->remedied_at);
            }
        }
    }

    public function test_final_auto_hides_payment_actions_and_due_date_while_manual_fined_keeps_them(): void
    {
        $auto = $this->penalty();
        $manual = $this->penalty(['auto_source' => null, 'due_date' => '2026-11-16']);

        $this->actingAs($this->admin)->get(route('penalties.index', ['search' => $auto->code]))
            ->assertOk()->assertDontSee('Đánh dấu đã nộp')->assertDontSee('Chuyển sang kỳ sau')
            ->assertDontSee('Ghi nhận khắc phục')->assertDontSee('Hạn nộp');
        $this->get(route('penalties.index', ['search' => $manual->code]))
            ->assertOk()->assertSee('Đánh dấu đã nộp')->assertSee('Chuyển sang kỳ sau')
            ->assertSee('Hạn nộp 16/11/2026');
    }

    public function test_manual_and_other_auto_sources_keep_payment_remedy_and_resolution(): void
    {
        foreach ([null, 'crm.test_result', 'gv.attendance_window'] as $source) {
            $penalty = $this->penalty(['auto_source' => $source, 'due_date' => today()->addDay()]);
            $this->actingAs($this->admin)->post(route('penalties.mark-paid', $penalty->id))
                ->assertRedirect()->assertSessionHasNoErrors();
            $this->assertSame('paid', $penalty->fresh()->status);
            $this->assertNotNull($penalty->fresh()->paid_at);

            $this->post(route('penalties.remedy', $penalty->id), ['remedy_note' => 'Remedied'])
                ->assertRedirect()->assertSessionHasNoErrors();
            $this->assertNotNull($penalty->fresh()->remedied_at);

            $unpaid = $this->penalty(['auto_source' => $source, 'due_date' => today()->addDay()]);
            $this->post(route('penalties.resolve', $unpaid->id))->assertRedirect()->assertSessionHasNoErrors();
            $this->assertSame('resolved', $unpaid->fresh()->status);
        }
    }

    public function test_unattached_final_auto_can_be_cancelled_but_manual_fined_cannot(): void
    {
        $auto = $this->penalty();
        $this->actingAs($this->admin)->post(route('penalties.cancel', $auto->id))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $auto->fresh()->status);
        $this->assertNull($auto->fresh()->payroll_record_id);

        $manual = $this->penalty(['auto_source' => null]);
        $this->post(route('penalties.cancel', $manual->id))->assertUnprocessable();
        $this->assertSame('fined', $manual->fresh()->status);
    }

    public function test_final_auto_cannot_be_cancelled_outside_fined_status(): void
    {
        foreach (['pending', 'explained', 'confirmed', 'paid', 'deducted', 'resolved', 'cancelled'] as $status) {
            $penalty = $this->penalty(['status' => $status]);
            $this->actingAs($this->admin)->post(route('penalties.cancel', $penalty->id))->assertUnprocessable();
            $this->assertSame($status, $penalty->fresh()->status);
        }
    }

    public function test_manual_penalties_can_still_be_cancelled_before_fining(): void
    {
        foreach (['pending', 'explained', 'confirmed'] as $status) {
            $penalty = $this->penalty(['auto_source' => null, 'status' => $status]);
            $this->actingAs($this->admin)->post(route('penalties.cancel', $penalty->id))
                ->assertRedirect()->assertSessionHasNoErrors();
            $this->assertSame('cancelled', $penalty->fresh()->status);
        }
    }

    public function test_cancelling_final_auto_still_requires_cancel_permission(): void
    {
        $penalty = $this->penalty();
        $this->actingAs($this->staff)->post(route('penalties.cancel', $penalty->id))->assertForbidden();
        $this->assertSame('fined', $penalty->fresh()->status);
    }
}
