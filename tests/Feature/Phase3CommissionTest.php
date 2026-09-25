<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\CommissionAdjustment;
use App\Models\CommissionTier;
use App\Models\Course;
use App\Models\CrmCustomer;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\TuitionReceipt;
use App\Models\TuitionRefundRequest;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 3 — Hoa hồng tuyển sinh theo A6: tiền thực thu (phiếu duyệt), chỉ khách mới
 * (khoản học phí đầu tiên), tính vào tháng duyệt phiếu, thu hồi khi hoàn phí do người
 * duyệt quyết định; mốc hoa hồng có phiên bản theo ngày hiệu lực.
 *
 * Q3 (bản sửa): bậc theo số HS chốt (ở đây một bậc 5% cho mọi số HS) và gate kép — khách
 * trong các test này chốt 01/08 và đã tick đủ 3/3 mốc nên hoa hồng trả ngay trong kỳ phát sinh.
 * Hoãn / trả kỳ sau: xem Phase3FormulaTest.
 */
class Phase3CommissionTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $admin;

    private User $accountant;

    private User $sales;

    private ClassModel $classModel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Cơ sở HH', 'code' => 'HH', 'is_active' => true]);
        $this->admin = $this->userWithRole('admin');
        $this->accountant = $this->userWithRole('accountant');
        $this->sales = $this->userWithRole('sales_consultant', ['name' => 'Sale Thực Thu']);

        $course = Course::create(['code' => 'HH-C', 'name' => 'IELTS HH', 'tuition_fee' => 10000000, 'is_active' => true]);
        $this->classModel = ClassModel::create([
            'code' => 'HH-01', 'name' => 'Lớp HH', 'course_id' => $course->id,
            'branch_id' => $this->branch->id, 'status' => 'active',
        ]);

        // Mốc: 5% cho mọi số HS chốt (thay 3 bậc mặc định)
        CommissionTier::query()->delete();
        CommissionTier::create(['tier_name' => 'Mức 5%', 'min_revenue' => 0, 'min_students' => 0, 'new_sale_percent' => 5, 'renew_percent' => 10, 'bonus_amount' => 0]);
    }

    private function userWithRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes + ['branch_id' => $this->branch->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    /** Khách CRM đã chốt → học viên + khoản học phí đầu tiên. */
    private function convertedStudent(string $code, ?User $sale = null, float $dealValue = 50000000): array
    {
        $student = Student::create([
            'code' => $code, 'name' => "Học viên {$code}", 'phone' => '09'.random_int(10000000, 99999999),
            'branch_id' => $this->branch->id, 'status' => 'studying',
        ]);
        CrmCustomer::create([
            'code' => 'KH-'.$code, 'name' => "Khách {$code}", 'phone' => $student->phone, 'stage' => 'won',
            'deal_value' => $dealValue, 'branch_id' => $this->branch->id,
            'assigned_user_id' => ($sale ?? $this->sales)->id, 'commission_user_id' => ($sale ?? $this->sales)->id,
            'converted_student_id' => $student->id, 'converted_at' => '2026-08-01 09:00:00',
            // Gate kép: đủ 3/3 mốc chăm sóc tháng đầu (đủ 30 ngày tính tới 31/08)
            'care_checklist' => ['session_1' => ['done_at' => '2026-08-03'], 'session_4_5' => ['done_at' => '2026-08-12'], 'day_30' => ['done_at' => '2026-08-31']],
        ]);

        return [$student, $this->tuition($student)];
    }

    private function tuition(Student $student, float $final = 12000000): StudentTuition
    {
        return StudentTuition::create([
            'student_id' => $student->id, 'branch_id' => $this->branch->id, 'class_id' => $this->classModel->id,
            'total_amount' => $final, 'final_amount' => $final, 'paid_amount' => 0, 'debt_amount' => $final,
            'status' => 'unpaid',
        ]);
    }

    private function approvedReceipt(StudentTuition $tuition, float $amount, string $approvedAt, float $surcharge = 0, ?string $code = null): TuitionReceipt
    {
        $this->travelTo(Carbon::parse($approvedAt));
        $receipt = TuitionReceipt::create([
            'receipt_number' => TuitionReceipt::generateReceiptNumber(),
            'student_tuition_id' => $tuition->id, 'student_id' => $tuition->student_id,
            'amount' => $amount, 'surcharge_amount' => $surcharge, 'tuition_amount' => $amount - $surcharge,
            'payment_method' => 'cash', 'payment_date' => $approvedAt, 'transaction_code' => $code,
            'creator_id' => $this->accountant->id, 'status' => 'pending',
        ]);
        $receipt->update(['status' => 'approved', 'approver_id' => $this->admin->id]);
        $this->travelBack();

        return $receipt;
    }

    private function period(int $month, int $year = 2026): PayrollPeriod
    {
        $start = Carbon::create($year, $month, 1);

        return PayrollPeriod::create([
            'code' => sprintf('PR-%d-%02d', $year, $month), 'title' => "Bảng lương Tháng {$month}/{$year}",
            'month' => $month, 'year' => $year, 'status' => 'draft',
            'start_date' => $start->toDateString(), 'end_date' => $start->copy()->endOfMonth()->toDateString(),
        ]);
    }

    private function salesRecord(PayrollPeriod $period, ?User $sale = null): ?PayrollRecord
    {
        return PayrollRecord::where('payroll_period_id', $period->id)->where('user_id', ($sale ?? $this->sales)->id)->first();
    }

    public function test_receipt_approval_stamps_approved_at(): void
    {
        [, $tuition] = $this->convertedStudent('HV-A1');
        $receipt = $this->approvedReceipt($tuition, 1000000, '2026-08-10 10:00:00');

        $this->assertSame('2026-08-10 10:00:00', $receipt->fresh()->approved_at->format('Y-m-d H:i:s'));
    }

    public function test_commission_uses_collected_money_including_materials_not_deal_value(): void
    {
        [, $tuition] = $this->convertedStudent('HV-C1', null, 50000000);
        // 6M học phí + 400k giáo trình (phụ thu) trên cùng phiếu, 2M đợt sau
        $this->approvedReceipt($tuition, 6400000, '2026-08-05 09:00:00', 400000);
        $this->approvedReceipt($tuition, 2000000, '2026-08-20 09:00:00');

        $period = $this->period(8);
        $period->calculatePayrollForPeriod();

        $record = $this->salesRecord($period);
        $this->assertEquals(8400000, $record->commission_base);
        $this->assertEquals(420000, $record->commission_bonus); // 5% × 8.4M, không phải 5% × 50M
        $this->assertEquals(420000, $record->net_salary);
    }

    public function test_commission_is_counted_in_month_receipt_was_approved(): void
    {
        [, $tuition] = $this->convertedStudent('HV-M1');
        $this->approvedReceipt($tuition, 4000000, '2026-08-31 16:00:00');
        $this->approvedReceipt($tuition, 3000000, '2026-09-01 08:00:00');

        $august = $this->period(8);
        $august->calculatePayrollForPeriod();
        $september = $this->period(9);
        $september->calculatePayrollForPeriod();

        $this->assertEquals(200000, $this->salesRecord($august)->commission_bonus);
        $this->assertEquals(150000, $this->salesRecord($september)->commission_bonus);
    }

    public function test_only_first_tuition_earns_commission_not_renewals(): void
    {
        [$student, $first] = $this->convertedStudent('HV-R1');
        $this->approvedReceipt($first, 5000000, '2026-08-05 09:00:00');

        // Tái tục: khoản học phí thứ hai của cùng học viên
        $renewal = $this->tuition($student, 9000000);
        $this->approvedReceipt($renewal, 9000000, '2026-08-25 09:00:00');

        // Học viên không đến từ CRM (không có sale) → không ai nhận hoa hồng
        $walkIn = Student::create(['code' => 'HV-WALK', 'name' => 'Walk-in', 'phone' => '0911000000', 'branch_id' => $this->branch->id, 'status' => 'studying']);
        $this->approvedReceipt($this->tuition($walkIn), 7000000, '2026-08-06 09:00:00');

        $period = $this->period(8);
        $period->calculatePayrollForPeriod();

        $this->assertEquals(5000000, $this->salesRecord($period)->commission_base);
        $this->assertEquals(250000, $this->salesRecord($period)->commission_bonus);
    }

    public function test_pending_rejected_and_transfer_in_receipts_are_excluded(): void
    {
        [, $tuition] = $this->convertedStudent('HV-X1');
        $this->approvedReceipt($tuition, 2000000, '2026-08-05 09:00:00');
        $this->approvedReceipt($tuition, 3000000, '2026-08-06 09:00:00', 0, 'XFER-IN-99');
        TuitionReceipt::create([
            'receipt_number' => 'PT-PENDING', 'student_tuition_id' => $tuition->id, 'student_id' => $tuition->student_id,
            'amount' => 9000000, 'payment_method' => 'cash', 'payment_date' => '2026-08-07', 'status' => 'pending',
        ]);

        $period = $this->period(8);
        $period->calculatePayrollForPeriod();

        $this->assertEquals(2000000, $this->salesRecord($period)->commission_base);
    }

    public function test_commission_goes_to_customers_assigned_sale_at_closing(): void
    {
        $other = $this->userWithRole('sales_consultant', ['name' => 'Sale Khác']);
        [, $tuitionA] = $this->convertedStudent('HV-S1', $this->sales);
        [, $tuitionB] = $this->convertedStudent('HV-S2', $other);
        $this->approvedReceipt($tuitionA, 1000000, '2026-08-05 09:00:00');
        $this->approvedReceipt($tuitionB, 3000000, '2026-08-05 09:00:00');

        $period = $this->period(8);
        $period->calculatePayrollForPeriod();

        $this->assertEquals(50000, $this->salesRecord($period)->commission_bonus);
        $this->assertEquals(150000, $this->salesRecord($period, $other)->commission_bonus);
    }

    public function test_commission_tier_effective_at_period_end_is_used(): void
    {
        CommissionTier::query()->update(['effective_to' => '2026-08-31']);
        CommissionTier::create([
            'tier_name' => 'Mức 8% từ T9', 'min_revenue' => 0, 'min_students' => 0, 'new_sale_percent' => 8, 'bonus_amount' => 0,
            'effective_from' => '2026-09-01',
        ]);

        [, $tuition] = $this->convertedStudent('HV-T1');
        $this->approvedReceipt($tuition, 1000000, '2026-08-10 09:00:00');
        $this->approvedReceipt($tuition, 1000000, '2026-09-10 09:00:00');

        $august = $this->period(8);
        $august->calculatePayrollForPeriod();
        $september = $this->period(9);
        $september->calculatePayrollForPeriod();

        $this->assertEquals(50000, $this->salesRecord($august)->commission_bonus);
        $this->assertEquals(80000, $this->salesRecord($september)->commission_bonus);
    }

    public function test_editing_commission_tier_creates_new_version_and_keeps_history(): void
    {
        $this->travelTo(Carbon::parse('2026-09-25 09:00:00'));
        $tier = CommissionTier::firstOrFail();

        $this->actingAs($this->admin)->put(route('payroll.config.commission-tiers.update', $tier), [
            'tier_name' => 'Mức 7%', 'min_students' => 0, 'new_sale_percent' => 7,
            'effective_from' => '2026-10-01',
        ])->assertSessionHasNoErrors();

        $tier->refresh();
        $this->assertEquals(5, $tier->new_sale_percent);
        $this->assertSame('2026-09-30', $tier->effective_to->toDateString());
        $new = CommissionTier::where('replaces_id', $tier->id)->firstOrFail();
        $this->assertEquals(7, $new->new_sale_percent);
        $this->assertSame('2026-10-01', $new->effective_from->toDateString());

        $this->assertSame($tier->id, CommissionTier::matchForStudents(3, '2026-09-30')->id);
        $this->assertSame($new->id, CommissionTier::matchForStudents(3, '2026-10-01')->id);

        // Ngày hiệu lực mới không được trước phiên bản hiện tại
        $this->actingAs($this->admin)->put(route('payroll.config.commission-tiers.update', $new), [
            'tier_name' => 'Mức 9%', 'min_students' => 0, 'new_sale_percent' => 9, 'effective_from' => '2026-09-15',
        ])->assertSessionHasErrors('effective_from');

        // Ngừng áp dụng giữ lại lịch sử, không xoá
        $this->actingAs($this->admin)->delete(route('payroll.config.commission-tiers.destroy', $tier))->assertRedirect();
        $this->assertDatabaseHas('commission_tiers', ['id' => $tier->id]);

        $this->actingAs($this->admin)->get(route('payroll.config.commission-tiers'))
            ->assertOk()
            ->assertSee('Lịch sử các phiên bản')
            ->assertSee('Mức 7%')
            ->assertDontSee('% Tái tục');
    }

    // ───────────── Hoàn phí & thu hồi hoa hồng ─────────────

    private function refundRequest(Student $student, float $amount, string $type = 'refund', ?Student $target = null): TuitionRefundRequest
    {
        return TuitionRefundRequest::create([
            'student_id' => $student->id, 'type' => $type, 'total_paid' => $amount, 'refund_amount' => $amount,
            'target_student_id' => $target?->id, 'reason' => 'Gia đình chuyển nơi ở', 'requester_id' => $this->accountant->id,
            'status' => 'pending',
        ]);
    }

    public function test_refund_within_first_month_suggests_and_applies_clawback_in_next_payroll(): void
    {
        [$student, $tuition] = $this->convertedStudent('HV-RF1');
        $this->approvedReceipt($tuition, 6000000, '2026-08-05 09:00:00');
        ClassEnrollment::create(['student_id' => $student->id, 'class_id' => $this->classModel->id, 'enrolled_at' => '2026-08-20', 'status' => 'active']);

        $august = $this->period(8);
        $august->calculatePayrollForPeriod();
        $this->assertEquals(300000, $this->salesRecord($august)->commission_bonus);
        $this->actingAs($this->admin)->post(route('payroll.periods.approve', $august->id))->assertSessionHasNoErrors();

        $this->travelTo(Carbon::parse('2026-09-05 10:00:00'));
        $refund = $this->refundRequest($student, 4000000);

        // Màn duyệt hoàn phí gợi ý "Có thu hồi" (học chưa tới 1 tháng) với số tiền 5% × 4M
        $this->actingAs($this->admin)->get(route('tuition.refunds'))
            ->assertOk()
            ->assertSee('Thu hồi hoa hồng')
            ->assertSee('value="200000"', false)
            ->assertViewHas('clawbackHints', fn ($hints) => $hints[$refund->id]['suggest'] === true);

        // Không gửi lựa chọn → áp dụng gợi ý
        $this->actingAs($this->admin)->post(route('tuition.refunds.approve', $refund->id))->assertSessionHasNoErrors();

        $refund->refresh();
        $this->assertTrue($refund->clawback_commission);
        $this->assertEquals(200000, $refund->clawback_amount);
        $this->assertSame($this->sales->id, $refund->clawback_user_id);

        // Kỳ tháng 9: không có thu mới, bị trừ 200k thu hồi hoa hồng (sale = Full-time theo Q3)
        $this->sales->update(['base_salary' => 5000000]);
        $this->travelTo(Carbon::parse('2026-09-30 10:00:00'));
        $september = $this->period(9);
        $september->calculatePayrollForPeriod();
        $record = $this->salesRecord($september);
        $this->assertEquals(0, $record->commission_bonus);
        $this->assertEquals(200000, $record->commission_clawback);
        // 5M − 525k BHXH − 25k Công đoàn − 200k thu hồi (Q3: bỏ phụ cấp cố định 500k)
        $this->assertEquals(4250000, $record->net_salary);

        $this->actingAs($this->admin)->post(route('payroll.periods.approve', $september->id))->assertSessionHasNoErrors();
        $this->assertNotNull(CommissionAdjustment::firstOrFail()->settled_at);

        // Kỳ sau không trừ lại
        $october = $this->period(10);
        $october->calculatePayrollForPeriod();
        $this->assertEquals(0, $this->salesRecord($october)->commission_clawback);
    }

    public function test_approver_can_decline_clawback_and_old_students_default_to_no(): void
    {
        [$student, $tuition] = $this->convertedStudent('HV-RF2');
        $this->approvedReceipt($tuition, 6000000, '2026-05-05 09:00:00');
        ClassEnrollment::create(['student_id' => $student->id, 'class_id' => $this->classModel->id, 'enrolled_at' => '2026-05-10', 'status' => 'active']);

        $this->travelTo(Carbon::parse('2026-09-05 10:00:00'));
        $refund = $this->refundRequest($student, 1000000);

        $this->actingAs($this->admin)->get(route('tuition.refunds'))
            ->assertViewHas('clawbackHints', fn ($hints) => $hints[$refund->id]['suggest'] === false);

        $this->actingAs($this->admin)->post(route('tuition.refunds.approve', $refund->id), ['clawback_commission' => '0'])
            ->assertSessionHasNoErrors();

        $this->assertFalse($refund->fresh()->clawback_commission);
        $this->assertDatabaseCount('commission_adjustments', 0);

        // Người duyệt vẫn có thể chủ động chọn thu hồi với số tiền tự nhập
        [$student2, $tuition2] = $this->convertedStudent('HV-RF3');
        $this->approvedReceipt($tuition2, 6000000, '2026-05-05 09:00:00');
        $this->travelTo(Carbon::parse('2026-09-06 10:00:00'));
        $refund2 = $this->refundRequest($student2, 1000000);
        $this->actingAs($this->admin)->post(route('tuition.refunds.approve', $refund2->id), ['clawback_commission' => '1', 'clawback_amount' => 30000])
            ->assertSessionHasNoErrors();
        $this->assertEquals(-30000, CommissionAdjustment::firstOrFail()->amount);
    }

    public function test_transfer_never_claws_back_and_transfer_in_money_is_not_commission(): void
    {
        [$source, $sourceTuition] = $this->convertedStudent('HV-TR1');
        [$target, $targetTuition] = $this->convertedStudent('HV-TR2');
        $this->approvedReceipt($sourceTuition, 6000000, '2026-09-02 09:00:00');

        $this->travelTo(Carbon::parse('2026-09-05 10:00:00'));
        $transfer = $this->refundRequest($source, 2000000, 'transfer', $target);
        $this->actingAs($this->admin)->post(route('tuition.refunds.approve', $transfer->id), ['clawback_commission' => '1', 'clawback_amount' => 99000])
            ->assertSessionHasNoErrors();

        $this->assertFalse($transfer->fresh()->clawback_commission);
        $this->assertDatabaseCount('commission_adjustments', 0);

        $this->travelTo(Carbon::parse('2026-09-30 10:00:00'));
        $september = $this->period(9);
        $september->calculatePayrollForPeriod();
        // Chỉ 6M thực thu của học viên nguồn; 2M nhận chuyển nhượng của học viên đích không tính
        $this->assertEquals(6000000, $this->salesRecord($september)->commission_base);
    }

    // ───────────── BXH KPI & báo cáo CRM cùng căn cứ ─────────────

    public function test_kpi_leaderboard_and_crm_report_use_collected_basis(): void
    {
        $this->travelTo(Carbon::parse('2026-09-20 10:00:00'));
        [, $tuition] = $this->convertedStudent('HV-K1', null, 99000000);
        $this->approvedReceipt($tuition, 7000000, '2026-09-10 09:00:00');
        $this->travelTo(Carbon::parse('2026-09-20 10:00:00'));

        $this->actingAs($this->admin)->get(route('payroll.kpi-leaderboard', ['month' => 9, 'year' => 2026]))
            ->assertOk()
            ->assertSee('Sale Thực Thu')
            ->assertSee('7,000,000')
            ->assertSee('350,000')
            ->assertDontSee('99,000,000');

        $this->actingAs($this->admin)->get(route('crm.reports', ['preset' => 'this_month']))
            ->assertOk()
            ->assertViewHas('repsData', function (array $reps) {
                $rep = collect($reps)->firstWhere('name', 'Sale Thực Thu');

                return $rep && (float) $rep['revenue'] === 7000000.0 && (float) $rep['commission_amount'] === 350000.0;
            });
    }
}
