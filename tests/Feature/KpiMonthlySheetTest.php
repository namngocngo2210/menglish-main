<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CrmCustomer;
use App\Models\KpiCriterion;
use App\Models\KpiEvaluation;
use App\Models\Penalty;
use App\Models\SlaEvent;
use App\Models\StaffReport;
use App\Models\User;
use App\Services\Kpi\KpiSheetService;
use App\Services\PayrollFormulaService;
use App\Support\Navigation\SidebarMenu;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\InteractsWithInertia;
use Tests\TestCase;

/**
 * Phiếu KPI tháng: tự tạo phiếu đầu tháng, mỗi nhân sự một dòng (lọc kỳ lương / vai trò / cơ sở), tiêu chí tự động lấy số
 * từ dữ liệu hệ thống (lỗi đã phạt tiền không đếm), tiêu chí điền tay người chấm điền; Duyệt cần đủ số, Không duyệt cần lý do;
 * KPI của tôi.
 */
class KpiMonthlySheetTest extends TestCase
{
    use InteractsWithInertia, RefreshDatabase;

    private Branch $branch;

    private Branch $other;

    private User $admin;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-05 10:00:00'));
        $this->branch = Branch::create(['name' => 'Cơ sở Cầu Giấy', 'code' => 'CG', 'is_active' => true]);
        $this->other = Branch::create(['name' => 'Cơ sở Hà Đông', 'code' => 'HD', 'is_active' => true]);
        $this->admin = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->staff = $this->user(Roles::ACADEMIC_STAFF, $this->branch, 'Nguyễn Thu Hà');
    }

    private function user(string $role, Branch $branch, string $name): User
    {
        $user = User::factory()->create(['name' => $name, 'branch_id' => $branch->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function criterion(string $source): KpiCriterion
    {
        return KpiCriterion::forRole(Roles::ACADEMIC_STAFF)->where('auto_source', $source)->firstOrFail();
    }

    /** Số điền tay = 0 cho mọi tiêu chí điền tay của Học vụ. */
    private function manualZeros(): array
    {
        return KpiCriterion::forRole(Roles::ACADEMIC_STAFF)->active()->whereNull('auto_source')->pluck('id')->mapWithKeys(fn ($id) => [$id => '0'])->all();
    }

    private function crmLate(User $owner, string $at, ?Penalty $penalty = null): SlaEvent
    {
        $phone = '09'.random_int(10000000, 99999999);
        $customer = CrmCustomer::create([
            'code' => CrmCustomer::generateCode(), 'name' => 'Phạm Gia Bảo', 'phone' => $phone, 'phone_normalized' => $phone,
            'branch_id' => $this->branch->id, 'assigned_user_id' => $owner->id, 'source' => 'Facebook', 'stage' => 'new',
        ]);

        return SlaEvent::create([
            'rule_key' => 'crm.first_contact', 'subject_type' => 'crm_customer', 'subject_id' => $customer->id, 'user_id' => $owner->id,
            'triggered_at' => Carbon::parse($at)->subDay(), 'due_at' => Carbon::parse($at), 'breached_at' => Carbon::parse($at), 'penalty_id' => $penalty?->id,
        ]);
    }

    public function test_sheets_are_created_each_month_for_staff_whose_role_has_criteria(): void
    {
        $teacher = $this->user(Roles::TEACHER_FULLTIME, $this->branch, 'GV chưa có tiêu chí');
        $this->artisan('kpi:create-sheets')->assertSuccessful();

        $this->assertSame([$this->staff->id], KpiEvaluation::where('month', 10)->where('year', 2026)->pluck('user_id')->all());
        $this->assertSame(KpiEvaluation::STATUS_PENDING, KpiEvaluation::firstOrFail()->status);
        $this->artisan('kpi:create-sheets')->assertSuccessful();
        $this->assertSame(1, KpiEvaluation::count(), 'chạy lại không tạo trùng');

        // Vai trò có tiêu chí thì nhân sự vai trò đó có phiếu.
        KpiCriterion::create(['role' => Roles::TEACHER_FULLTIME, 'name' => 'Nhận xét trễ', 'weight' => 100, 'unit' => 'buoi', 'max_full' => 0, 'max_half' => 2, 'is_active' => true]);
        $this->assertSame(1, app(KpiSheetService::class)->ensureSheets(10, 2026));
        $this->assertTrue(KpiEvaluation::where('user_id', $teacher->id)->exists());
        $this->assertStringContainsString('kpi:create-sheets', file_get_contents(base_path('routes/console.php')));
    }

    public function test_monthly_list_has_one_row_per_staff_and_filters_by_role_and_branch(): void
    {
        $this->user(Roles::ACADEMIC_STAFF, $this->other, 'Trần Minh Anh');
        $this->user(Roles::TEACHER_FULLTIME, $this->branch, 'GV không có tiêu chí');

        $this->actingAs($this->admin)->get(route('kpi.monthly', ['period' => '2026-09']))->assertOk()
            ->assertSee('Phiếu KPI tháng')->assertSee('Chờ duyệt')->assertDontSee('GV không có tiêu chí')
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Kpi/Monthly')->where('staff.total', 2)
                ->where('staff.data.0.name', 'Nguyễn Thu Hà')->where('staff.data.0.branch', 'Cơ sở Cầu Giấy')
                ->where('staff.data.0.amount_label', '900.000 đ')   // chưa điền tay: chỉ tiêu chí tự động (45%) không có lỗi->where('filters.period', '2026-09')
                ->where('branchOptions', fn ($o) => collect($o)->pluck('label')->all() === ['Cơ sở Cầu Giấy', 'Cơ sở Hà Đông']));

        $this->actingAs($this->admin)->get(route('kpi.monthly', ['period' => '2026-09', 'branch_id' => $this->other->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('staff.total', 1)->where('staff.data.0.name', 'Trần Minh Anh'));
        $this->actingAs($this->admin)->get(route('kpi.monthly', ['period' => '2026-09', 'role' => Roles::TEACHER_FULLTIME]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('staff.total', 0));
    }

    public function test_auto_criteria_count_system_records_and_skip_ones_already_fined(): void
    {
        $this->crmLate($this->staff, '2026-09-03 09:00');
        $fined = Penalty::create(['code' => 'VP-0087', 'user_id' => $this->staff->id, 'violation_type' => 'Quá hạn SLA', 'violation_date' => '2026-09-06', 'amount' => 100000, 'status' => 'fined']);
        $this->crmLate($this->staff, '2026-09-06 09:00', $fined);
        $this->crmLate($this->staff, '2026-10-02 09:00');   // tháng khác
        $report = StaffReport::create(['user_id' => $this->staff->id, 'type' => 'daily', 'title' => 'BC', 'report_date' => '2026-09-02', 'status' => 'submitted']);
        $report->forceFill(['created_at' => Carbon::parse('2026-09-03 10:42')])->save();
        $onTime = StaffReport::create(['user_id' => $this->staff->id, 'type' => 'daily', 'title' => 'BC', 'report_date' => '2026-09-04', 'status' => 'submitted']);
        $onTime->forceFill(['created_at' => Carbon::parse('2026-09-05 08:30')])->save();

        $sheet = app(KpiSheetService::class)->sheet($this->staff, 9, 2026);
        $line = fn (string $source) => $sheet['lines']->first(fn ($l) => $l['criterion']->auto_source === $source);
        $this->assertSame(1, $line('crm_sla_late')['value']);
        $this->assertCount(2, $line('crm_sla_late')['evidence']);
        $this->assertSame('Liên hệ khách mới lần đầu trễ hạn: Phạm Gia Bảo', $line('crm_sla_late')['evidence'][0]['text']);
        $this->assertFalse($line('crm_sla_late')['evidence'][1]['counted']);
        $this->assertStringContainsString('VP-0087', $line('crm_sla_late')['evidence'][1]['note']);
        $this->assertSame(50, $line('crm_sla_late')['level']);   // ≤ 2 case → 50%
        $this->assertSame(1, $line('daily_report_late')['value']);
        $this->assertSame(100, $line('daily_report_late')['level']);
        $this->assertSame(0, $line('receipt_rejected')['value']);
        $this->assertSame(9, $sheet['missing'], '9 tiêu chí điền tay chưa có số');

        $this->actingAs($this->admin)->get(route('kpi.evaluate', ['userId' => $this->staff->id, 'period' => '2026-09']), self::MODAL)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Kpi/Evaluate')->where('status', 'draft')->where('canDecide', true)
                ->where('groups.2.items.0.auto', true)->where('groups.2.items.0.value', 1)->where('groups.0.items.2.auto', false)->where('groups.0.items.2.value', null));
    }

    public function test_approve_needs_every_value_and_freezes_the_sheet_for_payroll(): void
    {
        $this->crmLate($this->staff, '2026-09-03 09:00');
        $url = route('kpi.evaluate.store', $this->staff->id);
        $manual = $this->manualZeros();
        $missingOne = array_slice($manual, 1, null, true);

        $this->actingAs($this->admin)->from(route('kpi.monthly'))->post($url, ['month' => 9, 'year' => 2026, 'action' => 'approve', 'actual' => $missingOne])
            ->assertSessionHasErrors(['actual' => 'Còn 1 tiêu chí chưa có số liệu.']);
        $this->assertSame(KpiEvaluation::STATUS_PENDING, KpiEvaluation::firstOrFail()->status, 'số đã điền vẫn được lưu, phiếu chưa duyệt');

        $this->actingAs($this->admin)->post($url, ['month' => 9, 'year' => 2026, 'action' => 'approve', 'actual' => ['x' => '1'] + [array_key_first($manual) => '-1'] + $manual])
            ->assertSessionHasErrors('actual.'.array_key_first($manual));

        $this->actingAs($this->admin)->from(route('kpi.monthly'))->post($url, ['month' => 9, 'year' => 2026, 'action' => 'approve', 'actual' => $manual], self::MODAL)
            ->assertRedirect(route('kpi.monthly'))->assertSessionHasNoErrors();
        $evaluation = KpiEvaluation::firstOrFail();
        $this->assertSame(KpiEvaluation::STATUS_APPROVED, $evaluation->status);
        $this->assertSame($this->admin->id, $evaluation->evaluator_id);
        $this->assertEquals(95, (float) $evaluation->total_score);   // tuyển sinh 10% ở mức 50%
        $crm = $evaluation->items()->where('kpi_criterion_id', $this->criterion('crm_sla_late')->id)->firstOrFail();
        $this->assertSame(['1', 50.0], [$crm->actual, (float) $crm->score]);
        $this->assertCount(1, $crm->evidence);

        // Đã duyệt: số chốt lại, bản ghi mới phát sinh không đổi phiếu; bảng lương dùng phiếu đã duyệt.
        $this->crmLate($this->staff, '2026-09-20 09:00');
        $this->assertEquals(95, app(KpiSheetService::class)->sheet($this->staff, 9, 2026, $evaluation->fresh())['total']);
        $this->assertSame(1900000.0, (float) app(PayrollFormulaService::class)->academicKpiFor($this->staff, 9, 2026)['amount']);
        $this->actingAs($this->admin)->get(route('kpi.evaluate', ['userId' => $this->staff->id, 'period' => '2026-09']), self::MODAL)
            ->assertInertia(fn (AssertableInertia $page) => $page->where('status', 'confirmed')->where('canDecide', false));
        $this->actingAs($this->admin)->post($url, ['month' => 9, 'year' => 2026, 'action' => 'reject', 'reject_reason' => 'Sai'])->assertSessionHasErrors('month');
    }

    public function test_reject_needs_a_reason_and_the_staff_sees_it_on_my_kpi(): void
    {
        $url = route('kpi.evaluate.store', $this->staff->id);
        $this->actingAs($this->admin)->post($url, ['month' => 9, 'year' => 2026, 'action' => 'reject'])->assertSessionHasErrors(['reject_reason' => 'Cần ghi lý do không duyệt.']);
        $this->assertSame(0, KpiEvaluation::count());

        $this->actingAs($this->admin)->post($url, ['month' => 9, 'year' => 2026, 'action' => 'reject', 'reject_reason' => 'Thiếu số liệu feedback Big Test'])->assertSessionHasNoErrors();
        $evaluation = KpiEvaluation::firstOrFail();
        $this->assertSame([KpiEvaluation::STATUS_REJECTED, 'Thiếu số liệu feedback Big Test'], [$evaluation->status, $evaluation->reject_reason]);
        $this->assertNull(app(PayrollFormulaService::class)->academicKpiFor($this->staff, 9, 2026)['evaluation_id'], 'phiếu không duyệt không vào lương');

        $this->actingAs($this->admin)->get(route('kpi.monthly', ['period' => '2026-09']))->assertSee('Không duyệt');
        $this->actingAs($this->staff)->get(route('kpi.mine', ['period' => '2026-09']))->assertOk()
            ->assertSee('KPI của tôi')->assertSee('Thiếu số liệu feedback Big Test')->assertSee('Quy trình nhắc &amp; follow học phí đúng hạn', false)
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Kpi/Mine')->where('hasKpi', true)->where('status', 'rejected')->where('fund', 2000000));

        // Không duyệt được trả lại → điền lại và duyệt.
        $this->actingAs($this->admin)->post($url, ['month' => 9, 'year' => 2026, 'action' => 'approve', 'actual' => $this->manualZeros()])->assertSessionHasNoErrors();
        $this->assertSame([KpiEvaluation::STATUS_APPROVED, null], [$evaluation->fresh()->status, $evaluation->fresh()->reject_reason]);
    }

    public function test_approve_waits_for_the_last_day_and_nobody_scores_themselves(): void
    {
        $url = route('kpi.evaluate.store', $this->staff->id);
        $this->actingAs($this->admin)->post($url, ['month' => 10, 'year' => 2026, 'action' => 'approve', 'actual' => $this->manualZeros()])
            ->assertSessionHasErrors(['month' => 'Duyệt KPI từ ngày cuối tháng 31/10.']);
        $this->actingAs($this->admin)->post($url, ['month' => 10, 'year' => 2026, 'action' => 'reject', 'reject_reason' => 'Điền lại'])->assertSessionHasNoErrors();

        $this->staff->givePermissionTo('kpi.confirm', 'kpi.view');
        $this->actingAs($this->staff)->post($url, ['month' => 9, 'year' => 2026, 'action' => 'approve', 'actual' => $this->manualZeros()])->assertForbidden();
    }

    public function test_my_kpi_menu_shows_for_staff_with_criteria_only(): void
    {
        $menu = fn (User $u) => json_encode(app(SidebarMenu::class)->groupsFor($u), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('KPI của tôi', $menu($this->staff));
        $teacher = $this->user(Roles::TEACHER_FULLTIME, $this->branch, 'GV');
        $this->assertStringNotContainsString('KPI của tôi', $menu($teacher));
        $this->actingAs($teacher)->get(route('kpi.mine'))->assertForbidden();
        $this->actingAs($this->staff)->get(route('kpi.mine'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('period', '2026-10')->where('status', 'draft'));
    }
}
