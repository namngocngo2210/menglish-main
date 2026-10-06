<?php

namespace Tests\Feature;

use App\Models\AdminNotification;
use App\Models\BigTest;
use App\Models\BigTestOrder;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\CrmCustomer;
use App\Models\Penalty;
use App\Models\PayrollPeriod;
use App\Models\SlaSetting;
use App\Models\Student;
use App\Models\TuitionReceipt;
use App\Models\User;
use App\Services\BigTestSlaService;
use App\Services\Sla\Sla;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Mọi mốc SLA (thời hạn, nhắc, phạt) đọc từ trang Cấu hình SLA thay vì hằng số trong code:
 * Big Test, cửa sổ chấm công / điểm danh của GV, hạn nộp tiền mặt, hạn nộp phạt, chốt công, liên hệ khách, order học liệu…
 */
class SlaConfigTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
    }

    private function saveSla(string $key, array $data)
    {
        return $this->actingAs($this->admin)->put(route('system-config.sla.update', $key), $data);
    }

    public function test_page_lists_every_rule_grouped_with_units_and_penalty_flags(): void
    {
        $this->actingAs($this->admin)->get(route('system-config.sla'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('SystemConfig/Sla')
                ->has('rules', count(config('sla.rules')))
                ->where('rules', fn ($rules) => collect($rules)->contains(fn ($r) => $r['key'] === 'big_test.results_late'
                        && $r['group'] === 'Big Test' && $r['has_penalty'] && $r['amount'] == 50000 && $r['default_label'] === '7 ngày')
                    && collect($rules)->contains(fn ($r) => $r['key'] === 'tuition.cash_deposit' && $r['value'] === '19:00' && ! $r['has_penalty'])
                    && collect($rules)->contains(fn ($r) => $r['key'] === 'gv.checkin_window' && ! $r['switchable'])));
    }

    public function test_time_rule_saves_hh_mm_and_drives_cash_deposit_cutoff(): void
    {
        $this->assertSame('19:00', TuitionReceipt::depositCutoff());

        $this->saveSla('tuition.cash_deposit', ['value' => '18:30', 'enabled' => 1])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(18 * 60 + 30, SlaSetting::where('rule_key', 'tuition.cash_deposit')->value('value'));
        $this->assertSame('18:30', TuitionReceipt::depositCutoff());
        $this->saveSla('tuition.cash_deposit', ['value' => '25:00', 'enabled' => 1])->assertSessionHasErrors('value');
    }

    public function test_bounds_follow_unit_and_rules_without_penalty_ignore_penalty_fields(): void
    {
        $this->saveSla('material.monthly_deadline', ['value' => 29])->assertSessionHasErrors('value');
        $this->saveSla('payroll.close_after', ['value' => 3, 'enabled' => 0, 'penalty' => 1, 'amount' => 90000])->assertSessionHasNoErrors();

        $setting = SlaSetting::where('rule_key', 'payroll.close_after')->sole();
        $this->assertNull($setting->penalty);
        $this->assertNull($setting->enabled, 'SLA luôn áp dụng: không lưu bật tắt');
        $rule = Sla::rule('payroll.close_after');
        $this->assertTrue($rule['enabled']);
        $this->assertNull($rule['penalty']);

        $period = new PayrollPeriod(['start_date' => '2026-10-01', 'end_date' => '2026-10-31']);
        $this->assertSame('2026-11-03', $period->attendanceCloseAt()->toDateString());
    }

    public function test_teacher_windows_and_penalty_deadlines_follow_settings(): void
    {
        $session = new ClassSession(['date' => '2026-10-10', 'start_time' => '18:00', 'end_time' => '19:30']);
        $this->assertSame('2026-10-11 18:00', $session->checkinDeadline()->format('Y-m-d H:i'));

        $this->saveSla('gv.checkin_window', ['value' => 6])->assertSessionHasNoErrors();
        $this->saveSla('gv.attendance_window', ['value' => 2])->assertSessionHasNoErrors();
        $this->saveSla('penalty.payment_due', ['value' => 5])->assertSessionHasNoErrors();

        $this->assertSame('2026-10-11 00:00', $session->checkinDeadline()->format('Y-m-d H:i'));
        $this->assertTrue($session->withinTeacherAttendanceWindow(Carbon::parse('2026-10-10 16:30')));
        $this->assertFalse($session->withinTeacherAttendanceWindow(Carbon::parse('2026-10-10 15:30')));
        $this->assertSame(5, Penalty::paymentDueDays());
    }

    public function test_crm_contact_clock_uses_first_contact_and_follow_up_settings(): void
    {
        $this->saveSla('crm.first_contact', ['value' => 8, 'enabled' => 1, 'penalty' => 1, 'amount' => 0])->assertSessionHasNoErrors();
        $this->saveSla('crm.follow_up', ['value' => 5, 'enabled' => 1])->assertSessionHasNoErrors();

        $lead = CrmCustomer::create(['code' => 'KH-SLA-1', 'name' => 'Khách SLA', 'phone' => '0911000111', 'stage' => 'new']);
        $sla = $lead->contactSla();
        $this->assertSame($lead->created_at->copy()->addHours(8)->toDateTimeString(), $sla['deadline']->toDateTimeString());
        $this->assertSame(120, CrmCustomer::followUpSlaHours());
    }

    public function test_big_test_deadlines_and_fine_follow_settings(): void
    {
        $branch = Branch::create(['name' => 'CN BT', 'code' => 'CNBT', 'is_active' => true]);
        $teacher = User::factory()->create(['is_active' => true]);
        $teacher->assignRole('teacher');
        $class = ClassModel::create(['code' => 'BT-CFG', 'name' => 'Lớp BT', 'branch_id' => $branch->id, 'teacher_id' => $teacher->id, 'status' => 'active']);
        Student::create(['code' => 'HV-BT-CFG', 'name' => 'HV', 'phone' => '0911222333', 'current_class_id' => $class->id, 'status' => 'studying']);

        $this->saveSla('big_test.results_late', ['value' => 5, 'enabled' => 1, 'penalty' => 1, 'amount' => 100000])->assertSessionHasNoErrors();
        $this->saveSla('big_test.paper_approval', ['value' => 4])->assertSessionHasNoErrors();

        $test = BigTest::create(['code' => 'BT-CFG-1', 'title' => 'BT cấu hình', 'class_id' => $class->id, 'test_type' => 'stage_end',
            'scheduled_at' => now()->subDays(8)->setTime(9, 0), 'room' => 'P1', 'passcode' => 'X', 'is_distributed' => true, 'status' => 'distributed']);

        $this->assertSame(now()->subDays(3)->toDateString(), $test->resultsDueAt()->toDateString());
        $this->assertSame(4, BigTestOrder::leadDays());

        app(BigTestSlaService::class)->enforceLateResults();
        $penalty = Penalty::where('big_test_id', $test->id)->sole();
        $this->assertSame($teacher->id, $penalty->user_id);
        $this->assertEquals(300000, $penalty->amount, '3 ngày trễ × 100.000đ');
    }

    public function test_big_test_paper_missing_without_penalty_only_notifies(): void
    {
        $branch = Branch::create(['name' => 'CN BT2', 'code' => 'CNBT2', 'is_active' => true]);
        $academic = User::factory()->create(['is_active' => true]);
        $academic->assignRole('academic_lead');
        $class = ClassModel::create(['code' => 'BT-CFG2', 'name' => 'Lớp BT2', 'branch_id' => $branch->id, 'status' => 'active']);
        $test = BigTest::create(['code' => 'BT-CFG-2', 'title' => 'BT chưa đề', 'class_id' => $class->id, 'test_type' => 'stage_end',
            'scheduled_at' => now()->addHours(30), 'room' => 'P1', 'passcode' => 'X', 'is_distributed' => false, 'status' => 'draft']);

        $this->assertSame(0, app(BigTestSlaService::class)->enforcePaperMissing(), 'mặc định 24h: còn 30h chưa tới mốc');

        $this->saveSla('big_test.paper_missing', ['value' => 48, 'enabled' => 1, 'penalty' => 0])->assertSessionHasNoErrors();
        app(BigTestSlaService::class)->enforcePaperMissing();
        app(BigTestSlaService::class)->enforcePaperMissing();

        $this->assertSame(0, Penalty::where('big_test_id', $test->id)->count());
        // Chỉ Admin duyệt & phân phối đề (06/10/2026): nhắc Admin, Học thuật không còn nhận.
        $this->assertSame(1, AdminNotification::where('user_id', $this->admin->id)->where('type', 'sla_breach')->where('data->big_test_id', $test->id)->count());
        $this->assertSame(0, AdminNotification::where('user_id', $academic->id)->where('type', 'sla_breach')->where('data->big_test_id', $test->id)->count());

        $this->saveSla('big_test.paper_missing', ['value' => 48, 'enabled' => 0, 'penalty' => 1])->assertSessionHasNoErrors();
        $this->assertSame(0, app(BigTestSlaService::class)->enforcePaperMissing(), 'SLA tắt → không quét');
    }
}
