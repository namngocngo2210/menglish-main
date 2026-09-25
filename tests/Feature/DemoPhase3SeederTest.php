<?php

namespace Tests\Feature;

use App\Models\CommissionAdjustment;
use App\Models\CommissionItem;
use App\Models\KpiEvaluation;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\Penalty;
use App\Models\TeacherHourlyRate;
use App\Models\TeacherTimesheet;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoPhase3Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Dữ liệu demo Phase 3: đủ trạng thái chấm công / phạt / hoa hồng / KPI, kỳ tháng trước đã duyệt + tháng này đang soát, chạy lại không nhân bản. */
class DemoPhase3SeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seed_builds_a_coherent_phase3_dataset_and_is_idempotent(): void
    {
        $this->seed(DatabaseSeeder::class);

        $last = PayrollPeriod::where('year', now()->subMonthNoOverflow()->year)->where('month', now()->subMonthNoOverflow()->month)->firstOrFail();
        $current = PayrollPeriod::where('year', now()->year)->where('month', now()->month)->firstOrFail();
        $this->assertSame('approved', $last->status);
        $this->assertSame('reviewing', $current->status);
        $this->assertNotNull($current->calculated_at);
        $this->assertTrue($last->records()->where('status', 'confirmed')->exists());

        // Đơn giá buổi riêng có phiên bản; chấm công chỉ tính buổi thật; chấm tay có lý do + giờ vào/ra; ca không có buổi bị từ chối.
        $teacher = User::where('email', 'nguyenvanan@menglish.edu.vn')->firstOrFail();
        $this->assertSame(2, TeacherHourlyRate::where('user_id', $teacher->id)->where('rate_unit', 'session')->count());
        $this->assertSame(0, TeacherTimesheet::where('status', 'valid')->whereIn('source', ['checkin', 'manual'])->whereNull('class_session_id')->count());
        $manual = TeacherTimesheet::where('source', 'manual')->where('status', 'valid')->firstOrFail();
        $this->assertNotNull($manual->checkin_time);
        $this->assertNotNull($manual->checkout_time);
        $this->assertNotEmpty($manual->notes);
        $this->assertTrue(TeacherTimesheet::where('status', 'invalid')->whereNull('class_session_id')->whereNotNull('rejection_reason')->exists());
        $this->assertSame(0, TeacherTimesheet::where('status', 'pending_review')->whereDate('teaching_date', '<', today())->count());

        // Kỷ luật đủ các bước; biên bản quá hạn đã trừ ở kỳ tháng trước.
        foreach (['pending', 'explained', 'confirmed', 'fined', 'paid', 'deducted', 'cancelled'] as $status) {
            $this->assertTrue(Penalty::where('status', $status)->exists(), "Thiếu biên bản {$status}.");
        }
        $deducted = Penalty::where('status', 'deducted')->firstOrFail();
        $this->assertSame($last->id, $deducted->payrollRecord->payroll_period_id);

        // KPI Học vụ, hoa hồng trả / hoãn, thu hồi khi hoàn phí.
        $this->assertSame(4, KpiEvaluation::where('status', 'confirmed')->count());
        $this->assertTrue(PayrollRecord::where('kpi_source', 'academic_kpi')->where('kpi_bonus', '>', 0)->exists());
        $this->assertTrue(CommissionItem::where('status', CommissionItem::STATUS_PAID)->whereNotNull('settled_at')->exists());
        $this->assertTrue(CommissionItem::where('status', CommissionItem::STATUS_DEFERRED)->whereNotNull('deferred_reason')->exists());
        $this->assertTrue(CommissionItem::where('status', CommissionItem::STATUS_PAYABLE)->where('payroll_record_id', '>', 0)->exists());
        $this->assertLessThan(0, (float) CommissionAdjustment::sum('amount'));
        $sale = User::where('email', 'tranmaia@menglish.edu.vn')->firstOrFail();
        $this->assertGreaterThan(0, (float) PayrollRecord::where('payroll_period_id', $current->id)->where('user_id', $sale->id)->value('commission_clawback'));

        // Nhập tay của Kế toán giữ sau khi tính lại: bậc KPI giữ HS, TNCN, dòng tự do; Full-time có BHXH / Công đoàn.
        $pt = PayrollRecord::where('payroll_period_id', $current->id)->where('user_id', $teacher->id)->firstOrFail();
        $this->assertSame('parttime', $pt->employee_type);
        $this->assertEquals(20000, $pt->retention_tier);
        $this->assertCount(1, $pt->manualLines('earning'));
        $ft = PayrollRecord::where('payroll_period_id', $current->id)->where('employee_type', 'fulltime')->where('tax_deduction', '>', 0)->firstOrFail();
        $this->assertEquals(round((float) $ft->base_salary * 0.105), (float) $ft->insurance_deduction);
        $this->assertEquals(round((float) $ft->base_salary * 0.005), (float) $ft->union_deduction);

        // Chạy lại không tạo trùng.
        $tables = ['teacher_hourly_rates', 'teacher_timesheets', 'penalties', 'kpi_evaluations', 'kpi_evaluation_items', 'commission_items', 'commission_adjustments',
            'tuition_refund_requests', 'tuition_receipts', 'payroll_periods', 'payroll_records', 'crm_customers', 'students', 'users'];
        $before = collect($tables)->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()]);
        $totals = PayrollPeriod::orderBy('id')->pluck('total_amount', 'code')->all();
        $this->seed(DemoPhase3Seeder::class);
        $this->seed(DatabaseSeeder::class);
        $this->assertSame($before->all(), collect($tables)->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])->all());
        $this->assertEquals($totals, PayrollPeriod::orderBy('id')->pluck('total_amount', 'code')->all());
        $this->assertSame('approved', $last->fresh()->status);

        // Màn hình Phase 3 mở được bằng tài khoản demo; nhân viên chỉ thấy kỳ đã duyệt.
        $accountant = User::where('email', 'ketoan2@menglish.edu.vn')->firstOrFail();
        $admin = User::where('email', 'admin@menglish.edu.vn')->firstOrFail();
        $this->actingAs($accountant)->get(route('payroll.periods.index'))->assertOk();
        $this->actingAs($accountant)->get(route('payroll.periods.show', $current->id))->assertOk();
        $this->actingAs($accountant)->get(route('payroll.records.show', $pt->id))->assertOk();
        $this->actingAs($admin)->get(route('payroll.periods.show', $last->id))->assertOk();
        $this->actingAs($admin)->get(route('penalties.index'))->assertOk();
        $this->actingAs($admin)->get(route('payroll.timesheets.teachers'))->assertOk();
        $this->actingAs($admin)->get(route('payroll.config.teacher-rates'))->assertOk();
        $this->actingAs($admin)->get(route('payroll.config.commission-tiers'))->assertOk();
        $this->actingAs($admin)->get(route('kpi.monthly'))->assertOk();
        $this->actingAs($teacher)->get(route('portal.my-salary'))->assertOk()
            ->assertViewHas('record', fn (?PayrollRecord $r) => $r?->payroll_period_id === $last->id);
        $this->actingAs($teacher)->get(route('portal.my-salary', ['period_id' => $current->id]))->assertNotFound();
    }
}
