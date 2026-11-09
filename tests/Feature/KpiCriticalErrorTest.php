<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\KpiCriterion;
use App\Models\KpiEvaluation;
use App\Models\KpiEvaluationItem;
use App\Models\PayrollPeriod;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Kpi\KpiSheetService;
use App\Services\PayrollFormulaService;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithInertia;
use Tests\TestCase;

class KpiCriticalErrorTest extends TestCase
{
    use InteractsWithInertia, RefreshDatabase;

    private User $admin;

    private User $staff;

    private KpiCriterion $criterion;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-05 10:00:00'));
        SystemSetting::set('payroll_academic_kpi_fund', 2000000);
        $branch = Branch::create(['name' => 'Cơ sở KPI', 'code' => 'KCR', 'is_active' => true]);
        $this->admin = User::factory()->create(['branch_id' => $branch->id]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->staff = User::factory()->create([
            'branch_id' => $branch->id, 'base_salary' => 8000000,
            'contract_start_date' => '2026-08-01', 'created_at' => '2026-08-01 08:00:00',
        ]);
        $this->staff->assignRole(Roles::ACADEMIC_STAFF);
        // Keep the fixture independent of automatic source data.
        KpiCriterion::forRole(Roles::ACADEMIC_STAFF)->update(['auto_source' => null]);
        $this->criterion = KpiCriterion::forRole(Roles::ACADEMIC_STAFF)->where('code', '3.1')->firstOrFail();
    }

    private function evaluation(string $status = KpiEvaluation::STATUS_APPROVED): KpiEvaluation
    {
        $evaluation = KpiEvaluation::create([
            'user_id' => $this->staff->id, 'month' => 9, 'year' => 2026, 'track' => KpiEvaluation::TRACK_MAIN,
            'period_months' => 1, 'status' => $status, 'total_score' => 85,
        ]);
        foreach (KpiCriterion::forRole(Roles::ACADEMIC_STAFF)->active()->get() as $criterion) {
            // Weights 10 + 5 at zero, all other items at 100: total = 85%.
            $zero = in_array($criterion->code, ['3.2', '5.2'], true);
            $evaluation->items()->create([
                'kpi_criterion_id' => $criterion->id, 'score' => $zero ? 0 : 100,
                'actual' => (string) ($zero ? $criterion->max_half + 1 : 0),
            ]);
        }

        return $evaluation;
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'month' => 9, 'year' => 2026, 'track' => KpiEvaluation::TRACK_MAIN,
            'criterion_id' => $this->criterion->id, 'critical' => true, 'note' => 'Bỏ sót hồ sơ nghiêm trọng.',
        ], $overrides);
    }

    private function item(KpiEvaluation $evaluation): KpiEvaluationItem
    {
        return $evaluation->items()->where('kpi_criterion_id', $this->criterion->id)->firstOrFail();
    }

    private function period(): PayrollPeriod
    {
        return PayrollPeriod::create([
            'code' => 'PR-KCR-2026-09', 'title' => 'Lương tháng 9/2026', 'month' => 9, 'year' => 2026,
            'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'status' => PayrollPeriod::STATUS_DRAFT,
        ]);
    }

    public function test_marking_reduces_85_percent_to_75_and_payroll_changes_only_after_recalculation(): void
    {
        $evaluation = $this->evaluation();
        $formula = app(PayrollFormulaService::class);
        $this->assertSame(85.0, $formula->academicKpiFor($this->staff, 9, 2026)['score']);
        $this->assertSame(1700000.0, $formula->academicKpiFor($this->staff, 9, 2026)['amount']);
        $period = $this->period();
        $period->calculatePayrollForPeriod();
        $record = $period->records()->where('user_id', $this->staff->id)->firstOrFail();
        $this->assertSame(1700000.0, (float) $record->kpi_bonus);

        // Approved metrics may have changed since approval; keep the saved scores.
        $evaluation->items()->where('kpi_criterion_id', '!=', $this->criterion->id)->update(['actual' => '0']);
        $this->actingAs($this->admin)->post(route('kpi.evaluate.critical', $this->staff->id), $this->payload())
            ->assertRedirect()->assertSessionHasNoErrors();

        $item = $this->item($evaluation);
        $this->assertTrue($item->critical_error);
        $this->assertSame(0.0, (float) $item->score);
        $this->assertSame(100.0, (float) $item->critical_prev_score);
        $this->assertSame($this->admin->id, $item->critical_marked_by);
        $this->assertSame('2026-10-05 10:00:00', $item->critical_marked_at->format('Y-m-d H:i:s'));
        $this->assertSame(75.0, (float) $evaluation->fresh()->total_score);
        $this->assertSame(75.0, $formula->academicKpiFor($this->staff, 9, 2026)['score']);
        $this->assertSame(1500000.0, $formula->academicKpiFor($this->staff, 9, 2026)['amount']);
        $this->assertSame(1500000.0, $formula->academicKpiStatement($this->staff, 9, 2026)['amount']);
        $this->assertSame(1700000.0, (float) $record->fresh()->kpi_bonus);

        $period->fresh()->calculatePayrollForPeriod();
        $this->assertSame(1500000.0, (float) $record->fresh()->kpi_bonus);
        $detail = collect(data_get($record->fresh()->calculation_details, 'kpi.items'))->firstWhere('code', '3.1');
        $this->assertTrue($detail['critical']);
        $this->assertSame('Bỏ sót hồ sơ nghiêm trọng.', $detail['critical_note']);
        $this->assertSame(0.0, (float) $detail['amount']);
        $this->actingAs($this->admin)->get(route('payroll.records.show', $record->id))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Payroll/Record')
                ->where('kpiGroups', function ($groups) {
                    $item = collect($groups)->flatMap(fn ($group) => $group['items'])->firstWhere('code', '3.1');

                    return $item['critical'] === true && $item['critical_note'] === 'Bỏ sót hồ sơ nghiêm trọng.';
                }));
    }

    public function test_repeated_marking_preserves_original_score_and_unmarking_restores_it(): void
    {
        $evaluation = $this->evaluation();
        $url = route('kpi.evaluate.critical', $this->staff->id);
        $this->actingAs($this->admin)->post($url, $this->payload())->assertSessionHasNoErrors();
        $this->post($url, $this->payload(['note' => 'Bổ sung bằng chứng.']))->assertSessionHasNoErrors();
        $this->assertSame(100.0, (float) $this->item($evaluation)->critical_prev_score);
        $this->post($url, $this->payload(['critical' => false, 'note' => null]))->assertSessionHasNoErrors();
        $this->post($url, $this->payload(['critical' => false, 'note' => null]))->assertSessionHasNoErrors();

        $item = $this->item($evaluation);
        $this->assertFalse($item->critical_error);
        $this->assertSame(100.0, (float) $item->score);
        foreach (['critical_note', 'critical_marked_by', 'critical_marked_at', 'critical_prev_score'] as $field) {
            $this->assertNull($item->$field);
        }
        $this->assertSame(85.0, (float) $evaluation->fresh()->total_score);
        $this->assertSame(1700000.0, app(PayrollFormulaService::class)->academicKpiFor($this->staff, 9, 2026)['amount']);
    }

    public function test_only_manage_permission_can_mark_and_self_marking_is_forbidden(): void
    {
        $evaluation = $this->evaluation();
        $confirmer = User::factory()->create(['branch_id' => $this->staff->branch_id]);
        $confirmer->givePermissionTo('kpi.confirm', 'kpi.view', 'kpi.scope_all');
        $url = route('kpi.evaluate.critical', $this->staff->id);
        $this->actingAs($confirmer)->post($url, $this->payload())->assertForbidden();
        $this->assertFalse($this->item($evaluation)->critical_error);
        $this->actingAs($this->admin)->post($url, $this->payload())->assertSessionHasNoErrors();
        $this->assertTrue($this->item($evaluation)->critical_error);
        $this->staff->givePermissionTo('kpi.manage');
        $this->actingAs($this->staff)->post($url, $this->payload(['critical' => false]))->assertForbidden();
        $this->assertTrue($this->item($evaluation)->critical_error);
    }

    public function test_staff_scope_is_enforced_even_with_manage_permission(): void
    {
        $evaluation = $this->evaluation();
        $outsider = User::factory()->create();
        $outsider->givePermissionTo('kpi.manage');
        $this->actingAs($outsider)->post(route('kpi.evaluate.critical', $this->staff->id), $this->payload())->assertNotFound();
        $this->assertFalse($this->item($evaluation)->critical_error);
    }

    public function test_legacy_score_submission_cannot_clear_an_admin_critical_mark(): void
    {
        $evaluation = $this->evaluation();
        $this->actingAs($this->admin)->post(route('kpi.evaluate.critical', $this->staff->id), $this->payload())->assertSessionHasNoErrors();
        $confirmer = User::factory()->create();
        $confirmer->givePermissionTo('kpi.confirm', 'kpi.scope_all');
        $scores = $evaluation->items->mapWithKeys(fn ($item) => [$item->kpi_criterion_id => $item->score])->all();
        $scores[$this->criterion->id] = 100;
        $this->actingAs($confirmer)->post(route('kpi.evaluate.store', $this->staff->id), [
            'month' => 9, 'year' => 2026, 'score' => $scores,
        ])->assertSessionHasNoErrors();
        $this->assertTrue($this->item($evaluation)->critical_error);
        $this->assertSame(0.0, (float) $this->item($evaluation)->score);
        $this->assertSame(100.0, (float) $this->item($evaluation)->critical_prev_score);
        $this->assertSame(75.0, (float) $evaluation->fresh()->total_score);
        $this->post(route('kpi.evaluate.store', $this->staff->id), [
            'month' => 9, 'year' => 2026, 'score' => $scores, 'critical' => [$this->criterion->id => true],
        ])->assertForbidden();
    }

    public function test_note_is_required_and_limited_to_500_characters(): void
    {
        $evaluation = $this->evaluation();
        $url = route('kpi.evaluate.critical', $this->staff->id);
        foreach ([null, '', '   ', str_repeat('a', 501)] as $note) {
            $this->actingAs($this->admin)->post($url, $this->payload(['note' => $note]))->assertSessionHasErrors('note');
            $this->assertFalse($this->item($evaluation)->critical_error);
            $this->assertSame(85.0, (float) $evaluation->fresh()->total_score);
        }
        $this->post($url, $this->payload(['note' => str_repeat('a', 500)]))->assertSessionHasNoErrors();
    }

    public static function frozenStatuses(): array
    {
        return [['closed'], ['published'], ['paid']];
    }

    #[DataProvider('frozenStatuses')]
    public function test_frozen_period_rejects_marking_and_unmarking_without_changes(string $status): void
    {
        $evaluation = $this->evaluation();
        $period = $this->period();
        $url = route('kpi.evaluate.critical', $this->staff->id);
        $period->update(['status' => $status]);
        $before = $this->item($evaluation)->getAttributes();
        $this->actingAs($this->admin)->post($url, $this->payload())->assertSessionHasErrors('month');
        $this->assertSame($before, $this->item($evaluation)->getAttributes());
        $this->assertSame(85.0, (float) $evaluation->fresh()->total_score);

        $period->update(['status' => PayrollPeriod::STATUS_DRAFT]);
        $this->post($url, $this->payload())->assertSessionHasNoErrors();
        $period->update(['status' => $status]);
        $before = $this->item($evaluation)->getAttributes();
        $this->post($url, $this->payload(['critical' => false, 'note' => null]))->assertSessionHasErrors('month');
        $this->assertSame($before, $this->item($evaluation)->getAttributes());
        $this->assertSame(75.0, (float) $evaluation->fresh()->total_score);
        $this->get(route('kpi.evaluate', ['userId' => $this->staff->id, 'month' => 9, 'year' => 2026]), self::MODAL)
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('locked', true)->where('canMarkCritical', false));
    }

    public function test_mark_and_unmark_write_audit_entries_with_actor_sheet_criterion_and_note(): void
    {
        $evaluation = $this->evaluation();
        $url = route('kpi.evaluate.critical', $this->staff->id);
        $this->actingAs($this->admin)->post($url, $this->payload())->assertSessionHasNoErrors();
        $this->post($url, $this->payload(['critical' => false, 'note' => null]))->assertSessionHasNoErrors();

        $logs = Activity::where('subject_type', $evaluation->getMorphClass())->where('subject_id', $evaluation->id)
            ->whereIn('event', ['critical_marked', 'critical_unmarked'])->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame(['critical_marked', 'critical_unmarked'], $logs->pluck('event')->all());
        foreach ($logs as $log) {
            $this->assertSame($this->admin->id, $log->causer_id);
            $this->assertSame($evaluation->id, $log->properties['evaluation_id']);
            $this->assertSame($this->criterion->id, $log->properties['criterion_id']);
            $this->assertSame('Bỏ sót hồ sơ nghiêm trọng.', $log->properties['note']);
        }
    }

    public function test_pending_mark_is_live_and_approval_persists_zero_then_unmark_restores_the_underlying_score(): void
    {
        $evaluation = $this->evaluation(KpiEvaluation::STATUS_PENDING);
        $url = route('kpi.evaluate.critical', $this->staff->id);
        $this->actingAs($this->admin)->post($url, $this->payload())->assertSessionHasNoErrors();
        $this->assertSame(100.0, (float) $this->item($evaluation)->score);
        $this->assertNull($this->item($evaluation)->critical_prev_score);
        $sheet = app(KpiSheetService::class)->sheet($this->staff, 9, 2026, $evaluation->fresh('items'));
        $line = $sheet['lines']->first(fn ($line) => $line['criterion']->id === $this->criterion->id);
        $this->assertSame(0.0, (float) $line['level']);
        $this->assertSame(75.0, $sheet['total']);

        $this->post(route('kpi.evaluate.store', $this->staff->id), ['month' => 9, 'year' => 2026, 'action' => 'approve'])
            ->assertSessionHasNoErrors();
        $this->assertSame(KpiEvaluation::STATUS_APPROVED, $evaluation->fresh()->status);
        $this->assertSame(0.0, (float) $this->item($evaluation)->score);
        $this->assertTrue($this->item($evaluation)->critical_error);
        $this->assertSame(75.0, (float) $evaluation->fresh()->total_score);
        $this->assertSame(1500000.0, app(PayrollFormulaService::class)->academicKpiFor($this->staff, 9, 2026)['amount']);
        $this->post($url, $this->payload(['critical' => false, 'note' => null]))->assertSessionHasNoErrors();
        $this->assertSame(100.0, (float) $this->item($evaluation)->score);
        $this->assertSame(85.0, (float) $evaluation->fresh()->total_score);
    }

    public function test_marking_creates_a_pending_sheet_and_item_and_pending_unmark_preserves_score(): void
    {
        $url = route('kpi.evaluate.critical', $this->staff->id);
        $this->actingAs($this->admin)->post($url, $this->payload())->assertSessionHasNoErrors();
        $evaluation = KpiSheetService::evaluationFor($this->staff, 9, 2026);
        $this->assertSame(KpiEvaluation::STATUS_PENDING, $evaluation->status);
        $item = $this->item($evaluation);
        $this->assertTrue($item->critical_error);
        $this->assertNull($item->critical_prev_score);
        $item->update(['actual' => '0', 'score' => 50]);
        $this->post($url, $this->payload(['critical' => false, 'note' => null]))->assertSessionHasNoErrors();
        $this->assertSame(50.0, (float) $this->item($evaluation)->score);
        $sheet = app(KpiSheetService::class)->sheet($this->staff, 9, 2026, $evaluation->fresh('items'));
        $this->assertSame(100.0, (float) $sheet['lines']->first(fn ($line) => $line['criterion']->id === $this->criterion->id)['level']);
    }

    public function test_legacy_percentage_item_keeps_its_restore_score_through_rejection_and_approval(): void
    {
        $evaluation = $this->evaluation(KpiEvaluation::STATUS_PENDING);
        $this->criterion->update(['max_full' => null, 'max_half' => null, 'tiers' => null]);
        $this->item($evaluation)->update(['actual' => null]);
        $url = route('kpi.evaluate.critical', $this->staff->id);
        $this->actingAs($this->admin)->post($url, $this->payload())->assertSessionHasNoErrors();
        $this->post(route('kpi.evaluate.store', $this->staff->id), [
            'month' => 9, 'year' => 2026, 'action' => 'reject', 'reject_reason' => 'Cần kiểm tra lại.',
        ])->assertSessionHasNoErrors();
        $this->post(route('kpi.evaluate.store', $this->staff->id), [
            'month' => 9, 'year' => 2026, 'action' => 'approve',
        ])->assertSessionHasNoErrors();
        $this->assertSame(0.0, (float) $this->item($evaluation)->score);
        $this->post($url, $this->payload(['critical' => false, 'note' => null]))->assertSessionHasNoErrors();
        $this->assertSame(100.0, (float) $this->item($evaluation)->score);
        $this->assertSame(85.0, (float) $evaluation->fresh()->total_score);
    }

    public function test_pending_critical_item_remains_zero_when_confirmed_through_the_legacy_form(): void
    {
        $evaluation = $this->evaluation(KpiEvaluation::STATUS_PENDING);
        $url = route('kpi.evaluate.critical', $this->staff->id);
        $this->actingAs($this->admin)->post($url, $this->payload())->assertSessionHasNoErrors();
        $scores = $evaluation->items->mapWithKeys(fn ($item) => [$item->kpi_criterion_id => $item->score])->all();
        $this->post(route('kpi.evaluate.store', $this->staff->id), [
            'month' => 9, 'year' => 2026, 'score' => $scores,
        ])->assertSessionHasNoErrors();
        $this->assertSame(75.0, (float) $evaluation->fresh()->total_score);
        $this->assertSame(0.0, (float) $this->item($evaluation)->score);
        $this->post($url, $this->payload(['critical' => false, 'note' => null]))->assertSessionHasNoErrors();
        $this->assertSame(85.0, (float) $evaluation->fresh()->total_score);
    }

    public static function pendingActions(): array
    {
        return [['draft'], ['reject']];
    }

    public function test_legacy_confirmation_snapshots_the_current_count_level_for_a_pending_critical_item(): void
    {
        $evaluation = $this->evaluation(KpiEvaluation::STATUS_PENDING);
        $url = route('kpi.evaluate.critical', $this->staff->id);
        $this->actingAs($this->admin)->post($url, $this->payload())->assertSessionHasNoErrors();
        $scores = $evaluation->items->mapWithKeys(fn ($item) => [$item->kpi_criterion_id => $item->score])->all();
        $actual = $evaluation->items->mapWithKeys(fn ($item) => [$item->kpi_criterion_id => $item->actual])->all();
        $this->post(route('kpi.evaluate.store', $this->staff->id), [
            'month' => 9, 'year' => 2026, 'action' => 'draft', 'score' => $scores, 'actual' => $actual,
        ])->assertSessionHasNoErrors();
        $actual[$this->criterion->id] = '1';
        $this->post(route('kpi.evaluate.store', $this->staff->id), [
            'month' => 9, 'year' => 2026, 'action' => 'confirm', 'score' => $scores, 'actual' => $actual,
        ])->assertSessionHasNoErrors();
        $this->assertSame(50.0, (float) $this->item($evaluation)->critical_prev_score);
        $this->assertSame(75.0, (float) $evaluation->fresh()->total_score);
        $this->post($url, $this->payload(['critical' => false, 'note' => null]))->assertSessionHasNoErrors();
        $this->assertSame(50.0, (float) $this->item($evaluation)->score);
        $this->assertSame(80.0, (float) $evaluation->fresh()->total_score);
        $this->assertSame(1600000.0, app(PayrollFormulaService::class)->academicKpiFor($this->staff, 9, 2026)['amount']);
    }

    #[DataProvider('pendingActions')]
    public function test_non_approved_saves_preserve_the_raw_percentage_score_for_unmarking(string $action): void
    {
        $evaluation = $this->evaluation(KpiEvaluation::STATUS_PENDING);
        $this->criterion->update(['max_full' => null, 'max_half' => null, 'tiers' => null]);
        $this->item($evaluation)->update(['actual' => null]);
        $url = route('kpi.evaluate.critical', $this->staff->id);
        $this->actingAs($this->admin)->post($url, $this->payload())->assertSessionHasNoErrors();
        $scores = $evaluation->items->mapWithKeys(fn ($item) => [$item->kpi_criterion_id => $item->score])->all();
        $scores[$this->criterion->id] = 0;
        $this->post(route('kpi.evaluate.store', $this->staff->id), [
            'month' => 9, 'year' => 2026, 'action' => $action, 'score' => $scores, 'reject_reason' => 'Cần kiểm tra lại.',
            'actual' => $evaluation->items->mapWithKeys(fn ($item) => [$item->kpi_criterion_id => $item->actual])->all(),
        ])->assertSessionHasNoErrors();
        $this->assertSame(100.0, (float) $this->item($evaluation)->score);
        $this->assertSame(75.0, (float) $evaluation->fresh()->total_score);
        $this->post($url, $this->payload(['critical' => false, 'note' => null]))->assertSessionHasNoErrors();
        $this->assertSame(100.0, (float) $this->item($evaluation)->score);
        $this->assertSame(85.0, (float) $evaluation->fresh()->total_score);
    }

    public function test_evaluate_and_mine_expose_critical_metadata_and_permission(): void
    {
        $this->evaluation();
        $pageUrl = route('kpi.evaluate', ['userId' => $this->staff->id, 'period' => '2026-09']);
        $this->actingAs($this->admin)->get($pageUrl, self::MODAL)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canMarkCritical', true)
                ->where('groups.0.items.0.critical', false)->where('groups.0.items.0.critical_note', null)
                ->where('groups.0.items.0.critical_by', null)->where('groups.0.items.0.critical_at', null));
        $this->post(route('kpi.evaluate.critical', $this->staff->id), $this->payload())->assertSessionHasNoErrors();
        $check = function ($groups) {
            $line = collect($groups)->flatMap(fn ($group) => $group['items'])->firstWhere('id', $this->criterion->id);

            return $line['critical'] === true && $line['critical_note'] === 'Bỏ sót hồ sơ nghiêm trọng.'
                && $line['critical_by'] === $this->admin->name && $line['critical_at'] === '05/10/2026 10:00';
        };
        $this->get($pageUrl, self::MODAL)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('groups', $check));
        $this->staff->givePermissionTo('kpi.manage');
        $this->actingAs($this->staff)->get($pageUrl, self::MODAL)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canMarkCritical', false));
        $this->get(route('kpi.mine', ['period' => '2026-09']))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('groups', $check));
    }

    public function test_criterion_must_belong_to_the_sheet_role_and_teaching_track_is_not_allowed(): void
    {
        $evaluation = $this->evaluation();
        $foreign = KpiCriterion::create(['role' => Roles::TEACHER_FULLTIME, 'name' => 'Tiêu chí GV', 'weight' => 100, 'is_active' => true]);
        $url = route('kpi.evaluate.critical', $this->staff->id);
        $this->actingAs($this->admin)->post($url, $this->payload(['criterion_id' => $foreign->id]))->assertSessionHasErrors('criterion_id');
        $this->post($url, $this->payload(['track' => KpiEvaluation::TRACK_TEACHING]))->assertNotFound();
        $this->assertSame(85.0, (float) $evaluation->fresh()->total_score);
        $this->assertFalse($this->item($evaluation)->critical_error);
        $teacher = User::factory()->create();
        $teacher->assignRole(Roles::TEACHER_FULLTIME);
        $this->post(route('kpi.evaluate.critical', $teacher->id), $this->payload(['criterion_id' => $foreign->id]))->assertForbidden();
        $this->get(route('kpi.evaluate', ['userId' => $teacher->id, 'month' => 9, 'year' => 2026]), self::MODAL)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canMarkCritical', false));
    }

    public function test_recomputed_total_excludes_not_applicable_items_and_preserves_knockout(): void
    {
        $evaluation = $this->evaluation();
        $na = KpiCriterion::forRole(Roles::ACADEMIC_STAFF)->where('code', '3.2')->firstOrFail();
        $evaluation->items()->where('kpi_criterion_id', $na->id)->update(['not_applicable' => true]);
        $url = route('kpi.evaluate.critical', $this->staff->id);
        $this->actingAs($this->admin)->post($url, $this->payload())->assertSessionHasNoErrors();
        // 75 weighted points / 90 applicable weight = 83.33%.
        $this->assertSame(83.33, (float) $evaluation->fresh()->total_score);
        $knockout = KpiCriterion::forRole(Roles::ACADEMIC_STAFF)->where('code', '5.2')->firstOrFail();
        $knockout->update(['knockout' => true]);
        $this->post($url, $this->payload(['critical' => false, 'note' => null]))->assertSessionHasNoErrors();
        $this->assertSame(0.0, (float) $evaluation->fresh()->total_score);
    }
}
