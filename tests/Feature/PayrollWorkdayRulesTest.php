<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\Course;
use App\Models\Holiday;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\Penalty;
use App\Models\StaffAttendance;
use App\Models\StaffAttendanceRequest;
use App\Models\SystemSetting;
use App\Models\TeacherHourlyRate;
use App\Models\TeacherTimesheet;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\AttendsWorkdays;
use Tests\TestCase;

/**
 * Sửa logic lương theo ticket TK-0008, TK-0009…0015 (30/10/2026). Kỳ mẫu: tháng 9/2026 (30 ngày, 4 Chủ nhật 6/13/20/27,
 * 4 Thứ 7 5/12/19/26) → nghỉ CN: công chuẩn 26; nghỉ T7 + CN: công chuẩn 22.
 */
class PayrollWorkdayRulesTest extends TestCase
{
    use AttendsWorkdays;
    use RefreshDatabase;

    private Branch $branch;

    private User $admin;

    private User $accountant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->branch = Branch::create(['name' => 'Cơ sở WD', 'code' => 'WD', 'is_active' => true]);
        $this->admin = $this->staff('admin');
        $this->accountant = $this->staff('accountant');
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function staff(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes + ['branch_id' => $this->branch->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function fulltime(string $role, float $base, array $attributes = []): User
    {
        return $this->staff($role, $attributes + ['base_salary' => $base, 'contract_type' => 'Toàn thời gian', 'weekly_days_off' => [7]]);
    }

    private function period(int $month, string $status = 'draft'): PayrollPeriod
    {
        $start = Carbon::create(2026, $month, 1);

        return PayrollPeriod::create([
            'code' => sprintf('PR-WD-%02d', $month), 'title' => "Bảng lương Tháng {$month}/2026", 'month' => $month, 'year' => 2026,
            'status' => $status, 'start_date' => $start->toDateString(), 'end_date' => $start->copy()->endOfMonth()->toDateString(),
        ]);
    }

    private function calculate(PayrollPeriod $period): PayrollPeriod
    {
        $period->calculatePayrollForPeriod();

        return $period->fresh();
    }

    private function record(PayrollPeriod $period, User $user): PayrollRecord
    {
        return PayrollRecord::where('payroll_period_id', $period->id)->where('user_id', $user->id)->firstOrFail();
    }

    private function correction(User $user, string $date, ?string $in, ?string $out): StaffAttendanceRequest
    {
        return StaffAttendanceRequest::create([
            'user_id' => $user->id, 'branch_id' => $this->branch->id, 'type' => StaffAttendanceRequest::TYPE_CORRECTION,
            'date_from' => $date, 'date_to' => $date, 'check_in_time' => $in, 'check_out_time' => $out, 'reason' => 'Quên chấm',
            'status' => StaffAttendanceRequest::STATUS_APPROVED, 'reviewed_by' => $this->admin->id, 'reviewed_at' => '2026-09-30 10:00:00',
        ]);
    }

    private function line(array $lines, string $key): ?array
    {
        return collect($lines)->firstWhere('key', $key);
    }

    // ───────────── R1 BHXH / Công đoàn (TK-0009) ─────────────

    public function test_insurance_uses_contribution_base_for_full_month_and_is_not_income(): void
    {
        $teacher = $this->fulltime('teacher_fulltime', 15000000);
        $this->assertSame(26, $this->attendFullMonth($teacher, 9));

        $this->travelTo(Carbon::parse('2026-10-02 09:00:00'));
        $record = $this->record($this->calculate($this->period(9)), $teacher);

        $this->assertEquals(567000, $record->insurance_deduction);
        $this->assertEquals(27000, $record->union_deduction);
        $this->assertEquals(15000000, $record->base_salary);
        $this->assertEquals(15000000, $record->base_salary_paid);
        // Mức đóng 5.400.000 không cộng vào thu nhập.
        $this->assertEquals(15000000, $record->gross_income);
        $this->assertEquals(15000000 - 567000 - 27000, $record->net_salary);
        $this->assertStringContainsString('mức lương đóng BHXH', $this->line($record->deductionLines(), 'insurance_deduction')['label']);
    }

    public function test_insurance_is_prorated_by_workdays_and_only_for_teacher_academic_roles(): void
    {
        $teacher = $this->fulltime('teacher_fulltime', 15000000);
        $academic = $this->fulltime('academic_staff', 8000000);
        $lead = $this->fulltime('academic_lead', 12000000);
        $sales = $this->fulltime('sales_consultant', 10000000);
        $manager = $this->fulltime('manager', 20000000);
        $workdays = collect(range(1, 30))->map(fn ($d) => Carbon::create(2026, 9, $d))->reject->isSunday()->values();
        foreach ($workdays->slice(20) as $day) {
            $skip[] = $day->toDateString();
        }
        $this->assertSame(20, $this->attendFullMonth($teacher, 9, skip: $skip));
        foreach ([$academic, $lead, $sales, $manager] as $user) {
            $this->attendFullMonth($user, 9);
        }

        $this->travelTo(Carbon::parse('2026-10-02 09:00:00'));
        $period = $this->calculate($this->period(9));

        $record = $this->record($period, $teacher);
        // 5.400.000 × 20/26 × 10,5% / 0,5%
        $this->assertEquals(436154, $record->insurance_deduction);
        $this->assertEquals(20769, $record->union_deduction);
        $this->assertEquals(20, data_get($record->calculation_details, 'workdays.paid_days'));
        $this->assertSame(26, data_get($record->calculation_details, 'workdays.standard_days'));

        foreach ([$academic, $lead] as $user) {
            $this->assertEquals(567000, $this->record($period, $user)->insurance_deduction);
            $this->assertEquals(27000, $this->record($period, $user)->union_deduction);
        }
        foreach ([$sales, $manager] as $user) {
            $this->assertEquals(0, $this->record($period, $user)->insurance_deduction);
            $this->assertEquals(0, $this->record($period, $user)->union_deduction);
        }
    }

    public function test_changing_contribution_base_marks_period_changed_and_recalculation_applies_it(): void
    {
        $teacher = $this->fulltime('teacher_fulltime', 15000000);
        $this->travelTo(Carbon::parse('2026-10-02 09:00:00'));
        $this->attendFullMonth($teacher, 9);
        $period = $this->calculate($this->period(9));
        $this->assertFalse($period->hasChangesSinceCalculation());

        $this->travelTo(Carbon::parse('2026-10-02 10:00:00'));
        $this->actingAs($this->admin)->post(route('payroll.config.settings.store'), [
            'insurance_salary_base' => 6000000, 'missing_punch_fine' => 40000,
            'insurance_rate_percent' => 10.5, 'union_rate_percent' => 0.5, 'academic_kpi_fund' => 2000000,
        ])->assertSessionHasNoErrors();
        $this->assertEquals(6000000, PayrollPeriod::payrollSettings()['insurance_salary_base']);
        $this->assertEquals(40000, PayrollPeriod::payrollSettings()['missing_punch_fine']);
        $this->assertTrue($period->fresh()->hasChangesSinceCalculation());

        $record = $this->record($this->calculate($period), $teacher);
        $this->assertEquals(630000, $record->insurance_deduction);
        $this->assertEquals(30000, $record->union_deduction);

        $this->actingAs($this->admin)->get(route('payroll.config.settings'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('settings.insurance_salary_base', 6000000)
                ->where('settings.missing_punch_fine', 40000));
    }

    // ───────────── R2 Trợ giảng (TK-0010, TK-0011) ─────────────

    private function taWithTimesheets(?float $hourlyRate, string $unit = TeacherHourlyRate::UNIT_HOUR): User
    {
        $ta = $this->staff('assistant', ['contract_type' => 'Bán thời gian', 'base_salary' => 0]);
        if ($hourlyRate !== null) {
            TeacherHourlyRate::create(['user_id' => $ta->id, 'hourly_rate' => $hourlyRate, 'rate_unit' => $unit,
                'teacher_type' => 'assistant', 'effective_from' => '2026-08-01']);
        }
        $course = Course::create(['code' => 'WD-C', 'name' => 'Flyers', 'tuition_fee' => 5000000, 'is_active' => true]);
        $class = ClassModel::create(['code' => 'WD-1', 'name' => 'Lớp WD-1', 'course_id' => $course->id, 'branch_id' => $this->branch->id,
            'status' => 'active', 'assistant_id' => $ta->id]);
        foreach ([['2026-09-08', 2], ['2026-09-15', 1.5]] as [$date, $hours]) {
            TeacherTimesheet::create(['user_id' => $ta->id, 'class_id' => $class->id, 'teaching_date' => $date,
                'hours' => $hours, 'type' => 'regular', 'status' => 'valid']);
        }

        return $ta;
    }

    public function test_assistant_is_paid_hours_times_hourly_tier_without_kpi_allowance_or_renewal(): void
    {
        $ta = $this->taWithTimesheets(35000);
        Penalty::create(['code' => 'BB-WD-TA', 'user_id' => $ta->id, 'violation_type' => 'Quên điểm danh', 'violation_date' => '2026-09-10',
            'amount' => 50000, 'status' => 'fined']);

        $this->travelTo(Carbon::parse('2026-10-02 09:00:00'));
        $period = $this->calculate($this->period(9));
        $record = $this->record($period, $ta);

        $this->assertSame('assistant', $record->salary_role);
        $this->assertSame('assistant', $record->department);
        $this->assertSame('Trợ giảng', $record->salary_role_label);
        $this->assertEquals(3.5, $record->actual_hours);
        $this->assertEquals(122500, $record->teaching_salary); // 3,5 giờ × 35.000
        $this->assertEquals(0, $record->kpi_bonus);
        $this->assertNull($record->kpi_source);
        $this->assertEquals(0, $record->renew_bonus);
        $this->assertEquals(0, $record->insurance_deduction);
        $this->assertEquals(50000, $record->penalty_deduction);
        $this->assertEquals(72500, $record->net_salary);

        // Phụ cấp nhập tay không được tính cho TA.
        $record->update(['manual_lines' => [['kind' => 'earning', 'label' => 'Hỗ trợ', 'amount' => 200000]]]);
        $record->applyManualInputs();
        $this->assertEquals(0, $record->allowance);
        $this->assertEquals(72500, $record->net_salary);

        // Nhóm "Trợ giảng" riêng trên bảng lương kỳ (lọc + thẻ tổng) và file xuất.
        $this->actingAs($this->admin)->get(route('payroll.periods.show', ['id' => $period->id, 'type' => 'assistant']))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('stats.records', 1)
                ->where('typeOptions', fn ($options) => collect($options)->contains('label', 'Trợ giảng')));
        $this->actingAs($this->admin)->get(route('payroll.periods.export', ['id' => $period->id, 'type' => 'assistant', 'format' => 'csv']))->assertOk();
    }

    public function test_assistant_without_hourly_rate_gets_zero_and_warning(): void
    {
        // Đơn giá cũ theo buổi không quy đổi.
        $ta = $this->taWithTimesheets(110000, TeacherHourlyRate::UNIT_SESSION);

        $this->travelTo(Carbon::parse('2026-10-02 09:00:00'));
        $period = $this->calculate($this->period(9));
        $record = $this->record($period, $ta);

        $this->assertEquals(0, $record->teaching_salary);
        $this->assertTrue($record->assistantRateMissing());
        $this->assertStringContainsString('cần nhập lại', $this->line($record->earningLines(), 'teaching_salary')['hint']);

        $this->actingAs($this->accountant)->get(route('payroll.records.show', $record->id))->assertOk()
            ->assertSee('Chưa có đơn giá giờ – cần nhập lại');
        $this->actingAs($this->admin)->get(route('payroll.periods.show', $period->id))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('assistantRateMissing', [$ta->name]));
    }

    public function test_assistant_rate_screen_only_accepts_hourly_tiers(): void
    {
        $ta = $this->staff('assistant', ['contract_type' => 'Bán thời gian']);

        $this->actingAs($this->admin)->post(route('payroll.config.teacher-rates.personal.store'), [
            'user_id' => $ta->id, 'hourly_rate' => 37000, 'rate_unit' => 'hour', 'teacher_type' => 'assistant', 'effective_from' => '2026-11-01',
        ])->assertSessionHasErrors('hourly_rate');
        // Gửi đơn vị buổi / loại khác vẫn lưu theo GIỜ, loại Trợ giảng.
        $this->actingAs($this->admin)->post(route('payroll.config.teacher-rates.personal.store'), [
            'user_id' => $ta->id, 'hourly_rate' => 40000, 'rate_unit' => 'session', 'teacher_type' => 'parttime', 'effective_from' => '2026-11-01',
        ])->assertSessionHasNoErrors();
        $rate = TeacherHourlyRate::where('user_id', $ta->id)->sole();
        $this->assertSame(TeacherHourlyRate::UNIT_HOUR, $rate->rate_unit);
        $this->assertSame('assistant', $rate->teacher_type);
        $this->assertEquals(40000, $rate->hourly_rate);

        $this->actingAs($this->admin)->get(route('payroll.config.teacher-rates'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('assistantTiers.0.value', '30000')->where('assistantTiers.2.value', '40000'));
    }

    public function test_payroll_record_never_saved_without_salary_role_and_recalculation_fixes_old_rows(): void
    {
        $ta = $this->taWithTimesheets(30000);
        $this->travelTo(Carbon::parse('2026-10-02 09:00:00'));
        $period = $this->calculate($this->period(9));
        $record = $this->record($period, $ta);

        // Guard: lưu thiếu vai trò lương → suy từ hồ sơ nhân sự.
        $record->salary_role = null;
        $record->save();
        $this->assertSame('assistant', $record->fresh()->salary_role);

        $orphan = new PayrollRecord(['payroll_period_id' => $period->id, 'employee_type' => 'parttime']);
        try {
            $orphan->save();
            $this->fail('Phiếu lương không có nhân sự / vai trò lương phải bị chặn.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('salary_role', $e->getMessage());
        }

        // Dữ liệu cũ (vd. T8) còn salary_role NULL: kỳ mở báo cần tính lại, tính lại sửa vai trò.
        DB::table('payroll_records')->where('id', $record->id)->update(['salary_role' => null]);
        $this->assertTrue($period->fresh()->hasChangesSinceCalculation());
        $this->calculate($period);
        $this->assertSame('assistant', $record->fresh()->salary_role);
        $this->assertSame(0, PayrollRecord::whereNull('salary_role')->count());
    }

    // ───────────── R3 Lương cơ bản theo ngày công (TK-0012) ─────────────

    public function test_base_salary_and_allowance_prorated_by_paid_days_with_makeup_day(): void
    {
        $staff = $this->fulltime('academic_staff', 13000000, ['monthly_allowance' => 520000]);
        // 24 ngày có công (trong đó 28/09 được chấm công bù — đơn Bổ sung công đã duyệt), vắng 29 và 30/09.
        $this->assertSame(24, $this->attendFullMonth($staff, 9, skip: ['2026-09-29', '2026-09-30']));
        $this->correction($staff, '2026-09-28', '08:00', '17:00');

        $this->travelTo(Carbon::parse('2026-10-02 09:00:00'));
        $record = $this->record($this->calculate($this->period(9)), $staff);
        $workdays = data_get($record->calculation_details, 'workdays');

        $this->assertSame(26, $workdays['standard_days']);
        $this->assertEquals(24, $workdays['paid_days']);
        $this->assertSame(1, $workdays['makeup_days']);
        $this->assertEquals(13000000, $record->base_salary);
        $this->assertEquals(12000000, $record->base_salary_paid); // 13.000.000 ÷ 26 × 24
        $this->assertEquals(480000, $record->contract_allowance); // 520.000 ÷ 26 × 24
        $this->assertStringContainsString('× 24/26 công', $record->notes);
        $this->assertStringContainsString('26 công chuẩn × 24 công', $this->line($record->earningLines(), 'base_salary')['hint']);
    }

    public function test_spec_example_24_attended_plus_one_makeup_is_25_of_26(): void
    {
        $staff = $this->fulltime('teacher_fulltime', 13000000);
        $this->attendFullMonth($staff, 9, skip: ['2026-09-30']);
        // 25 ngày có công: 24 ngày chấm bình thường + 29/09 được chấm công bù.
        $this->correction($staff, '2026-09-29', '08:00', '17:00');

        $this->travelTo(Carbon::parse('2026-10-02 09:00:00'));
        $record = $this->record($this->calculate($this->period(9)), $staff);

        $this->assertEquals(25, data_get($record->calculation_details, 'workdays.paid_days'));
        $this->assertEquals(12500000, $record->base_salary_paid); // 13.000.000 × 25/26
    }

    public function test_saturday_sunday_schedule_and_user_form_options(): void
    {
        $staff = $this->fulltime('academic_lead', 11000000, ['weekly_days_off' => [6, 7]]);
        $this->assertSame(22, $this->attendFullMonth($staff, 9));

        $this->travelTo(Carbon::parse('2026-10-02 09:00:00'));
        $record = $this->record($this->calculate($this->period(9)), $staff);
        $this->assertSame(22, data_get($record->calculation_details, 'workdays.standard_days'));
        $this->assertEquals(11000000, $record->base_salary_paid);

        // Form hồ sơ: chỉ nghỉ 1 ngày/tuần hoặc T7 + CN.
        $payload = ['name' => $staff->name, 'email' => $staff->email, 'phone' => $staff->phone, 'branch_id' => $this->branch->id,
            'role' => 'academic_lead', 'weekly_days_off_present' => 1];
        $this->actingAs($this->admin)->put(route('users.update', $staff), $payload + ['weekly_days_off' => [3, 5]])
            ->assertSessionHasErrors('weekly_days_off');
        $this->actingAs($this->admin)->put(route('users.update', $staff), $payload + ['weekly_days_off' => [3], 'monthly_allowance' => 300000])
            ->assertSessionHasNoErrors();
        $this->assertSame([3], $staff->fresh()->weekly_days_off);
        $this->assertEquals(300000, $staff->fresh()->monthly_allowance);
        $this->actingAs($this->admin)->put(route('users.update', $staff), $payload + ['weekly_days_off' => [6, 7]])->assertSessionHasNoErrors();
        $this->assertSame([6, 7], $staff->fresh()->weekly_days_off);
        // Lịch nghỉ đổi sau lần tính → kỳ cần tính lại.
        $this->actingAs($this->admin)->put(route('users.update', $staff), $payload + ['weekly_days_off' => [7]])->assertSessionHasNoErrors();
        $this->assertTrue(PayrollPeriod::where('month', 9)->sole()->hasChangesSinceCalculation());
    }

    public function test_unset_schedule_defaults_to_sunday_off(): void
    {
        $staff = $this->fulltime('teacher_fulltime', 13000000, ['weekly_days_off' => null]);
        $this->assertSame([7], $staff->payrollDaysOff());
        $this->assertFalse($staff->isWeeklyDayOff(Carbon::parse('2026-09-06')));
        $this->attendFullMonth($staff, 9);

        $this->travelTo(Carbon::parse('2026-10-02 09:00:00'));
        $record = $this->record($this->calculate($this->period(9)), $staff);
        $this->assertSame(26, data_get($record->calculation_details, 'workdays.standard_days'));
        $this->assertEquals(13000000, $record->base_salary_paid);
    }

    public function test_holiday_stays_in_denominator_and_is_paid_and_approved_leave_is_paid(): void
    {
        $staff = $this->fulltime('teacher_fulltime', 13000000);
        Holiday::create(['code' => 'HOL-WD-1', 'name' => 'Quốc khánh', 'start_date' => '2026-09-02', 'end_date' => '2026-09-02', 'is_system_wide' => true]);
        $this->attendFullMonth($staff, 9, skip: ['2026-09-02', '2026-09-10']);
        StaffAttendanceRequest::create(['user_id' => $staff->id, 'branch_id' => $this->branch->id, 'type' => StaffAttendanceRequest::TYPE_LEAVE,
            'date_from' => '2026-09-10', 'date_to' => '2026-09-10', 'reason' => 'Việc gia đình', 'status' => StaffAttendanceRequest::STATUS_APPROVED,
            'reviewed_by' => $this->admin->id, 'reviewed_at' => '2026-09-09 10:00:00']);

        $this->travelTo(Carbon::parse('2026-10-02 09:00:00'));
        $record = $this->record($this->calculate($this->period(9)), $staff);
        $workdays = data_get($record->calculation_details, 'workdays');

        $this->assertSame(26, $workdays['standard_days']); // ngày lễ không trừ khỏi mẫu số
        $this->assertSame(1, $workdays['holiday_days']);
        $this->assertSame(1, $workdays['leave_days']);
        $this->assertEquals(26, $workdays['paid_days']);
        $this->assertEquals(13000000, $record->base_salary_paid);
    }

    public function test_contract_starting_mid_month_pays_only_days_after_start(): void
    {
        $staff = $this->fulltime('teacher_fulltime', 13000000, ['contract_start_date' => '2026-09-16']);
        // Từ 16/09: 15 ngày, trừ CN 20 và 27 → 13 ngày làm việc.
        foreach (range(16, 30) as $d) {
            $date = Carbon::create(2026, 9, $d);
            if (! $date->isSunday()) {
                $this->attendDay($staff, $date->toDateString());
            }
        }

        $this->travelTo(Carbon::parse('2026-10-02 09:00:00'));
        $record = $this->record($this->calculate($this->period(9)), $staff);

        $this->assertSame(26, data_get($record->calculation_details, 'workdays.standard_days'));
        $this->assertEquals(13, data_get($record->calculation_details, 'workdays.paid_days'));
        $this->assertEquals(6500000, $record->base_salary_paid);
        // BHXH cũng theo ngày công: 567.000 × 13/26.
        $this->assertEquals(283500, $record->insurance_deduction);
    }

    // ───────────── R4 Đi muộn / quên chấm công (TK-0013) ─────────────

    public function test_daily_late_minutes_summed_times_hourly_wage_without_per_day_penalty(): void
    {
        $staff = $this->fulltime('academic_staff', 15600000);
        $this->attendFullMonth($staff, 9);
        $this->attendDay($staff, '2026-09-03', '08:20', late: 20);
        $this->attendDay($staff, '2026-09-04', '08:40', late: 40);
        $this->attendDay($staff, '2026-09-07', '09:00', late: 60);
        $this->attendDay($staff, '2026-09-08', '08:30', late: 30, excused: true);
        // Biên bản tự động đi muộn cũ (đã quyết phạt) của ngày công không trừ thêm (không phạt 2 lần).
        $old = Penalty::create(['code' => 'BB-WD-LATE', 'user_id' => $staff->id, 'violation_type' => 'Đi muộn 20 phút (chấm công ngày 03/09/2026)',
            'violation_date' => '2026-09-03', 'amount' => 50000, 'status' => 'fined']);
        StaffAttendance::where('user_id', $staff->id)->whereDate('work_date', '2026-09-03')->update(['penalty_id' => $old->id]);

        $this->travelTo(Carbon::parse('2026-10-02 09:00:00'));
        $record = $this->record($this->calculate($this->period(9)), $staff);

        // 120 phút = 2 giờ × (15.600.000 ÷ 26 ÷ 7)
        $this->assertSame(120, data_get($record->calculation_details, 'daily_late.minutes'));
        $this->assertEquals(171429, $record->late_deduction);
        $this->assertEquals(0, $record->penalty_deduction);
        $this->assertEquals(15600000, $record->base_salary_paid); // đi muộn vẫn đủ công
        $late = $this->line($record->deductionLines(), 'late_deduction');
        $this->assertSame('Đi muộn: 2 giờ 0 phút', $late['label']);
        $this->assertEquals(171429, $late['amount']);
        $this->assertEquals(round(15600000 - 567000 - 27000 - 171429), $record->net_salary);
    }

    public function test_missing_check_out_is_half_day_and_fined_per_occurrence_unless_fixed(): void
    {
        $staff = $this->fulltime('teacher_fulltime', 13000000);
        $this->attendFullMonth($staff, 9);
        // 2 ngày quên chấm ra, không bù → 0,5 công + 30.000đ mỗi lần.
        $this->attendDay($staff, '2026-09-03', '08:00', null);
        $this->attendDay($staff, '2026-09-04', '08:00', null);
        // 1 ngày quên chấm ra đã được chấm công bù → đủ công, vẫn phạt 30.000đ.
        $this->attendDay($staff, '2026-09-07', '08:00', '17:00');
        $this->correction($staff, '2026-09-07', null, '17:00');

        $this->travelTo(Carbon::parse('2026-10-02 09:00:00'));
        $record = $this->record($this->calculate($this->period(9)), $staff);
        $workdays = data_get($record->calculation_details, 'workdays');

        $this->assertSame(2, $workdays['half_days']);
        $this->assertEquals(25, $workdays['paid_days']); // 24 + 2 × 0,5
        $this->assertEquals(12500000, $record->base_salary_paid);
        $this->assertSame(3, data_get($record->calculation_details, 'missing_punch.count'));
        $this->assertEquals(90000, $record->missing_punch_deduction);
        $this->assertSame('Quên chấm công (3 lần)', $this->line($record->deductionLines(), 'missing_punch_deduction')['label']);
    }

    public function test_two_missing_check_outs_cost_sixty_thousand(): void
    {
        $staff = $this->fulltime('sales_consultant', 10000000);
        $this->attendFullMonth($staff, 9);
        $this->attendDay($staff, '2026-09-10', '08:00', null);
        $this->attendDay($staff, '2026-09-11', '08:00', null);

        $this->travelTo(Carbon::parse('2026-10-02 09:00:00'));
        $this->assertEquals(60000, $this->record($this->calculate($this->period(9)), $staff)->missing_punch_deduction);
    }

    public function test_migration_cancels_pending_auto_late_penalties(): void
    {
        $staff = $this->fulltime('sales_consultant', 10000000);
        $pending = Penalty::create(['code' => 'BB-WD-P', 'user_id' => $staff->id, 'violation_type' => 'Đi muộn 15 phút', 'violation_date' => '2026-10-05', 'amount' => 0, 'status' => 'pending']);
        $fined = Penalty::create(['code' => 'BB-WD-F', 'user_id' => $staff->id, 'violation_type' => 'Đi muộn 25 phút', 'violation_date' => '2026-10-06', 'amount' => 30000, 'status' => 'fined']);
        $this->attendDay($staff, '2026-10-05', '08:15', late: 15)->update(['penalty_id' => $pending->id]);
        $this->attendDay($staff, '2026-10-06', '08:25', late: 25)->update(['penalty_id' => $fined->id]);

        (require database_path('migrations/2026_12_02_090000_add_workday_pay_to_payroll_records.php'))->up();

        $this->assertSame('cancelled', $pending->fresh()->status);
        $this->assertStringContainsString('cộng dồn cả tháng', $pending->fresh()->decision_note);
        $this->assertSame('fined', $fined->fresh()->status);
        // Biên bản đã quyết phạt của ngày công vẫn không bị trừ lương.
        $this->assertFalse(Penalty::whereKey($fined->id)->deductibleFor($this->period(10))->exists());
    }

    // ───────────── R5 Phạt thuộc kỳ của ngày vi phạm (TK-0014) ─────────────

    public function test_fined_penalty_is_deducted_in_its_violation_period_and_paid_cash_is_not(): void
    {
        $staff = $this->fulltime('sales_consultant', 10000000);
        $this->attendFullMonth($staff, 9);
        $this->attendFullMonth($staff, 10);
        $sep = $this->period(9);
        $oct = $this->period(10);
        // Vi phạm 30/09, quyết phạt 01/10 (hạn nộp chưa qua) → trừ ở T9.
        $late = Penalty::create(['code' => 'BB-WD-30', 'user_id' => $staff->id, 'violation_type' => 'Vi phạm nội quy', 'violation_date' => '2026-09-30',
            'amount' => 100000, 'status' => 'fined', 'decided_at' => '2026-10-01 09:00:00', 'due_date' => '2026-10-03']);
        // Đã nộp tiền mặt → không trừ lại.
        Penalty::create(['code' => 'BB-WD-PAID', 'user_id' => $staff->id, 'violation_type' => 'Vi phạm khác', 'violation_date' => '2026-09-12',
            'amount' => 70000, 'status' => 'paid', 'paid_at' => '2026-09-14 10:00:00']);

        $this->travelTo(Carbon::parse('2026-10-02 09:00:00'));
        $this->calculate($sep);
        $this->assertEquals(100000, $this->record($sep->fresh(), $staff)->penalty_deduction);
        $this->assertSame($this->record($sep->fresh(), $staff)->id, $late->fresh()->payroll_record_id);

        $this->travelTo(Carbon::parse('2026-11-02 09:00:00'));
        $this->calculate($oct);
        $this->assertEquals(0, $this->record($oct->fresh(), $staff)->penalty_deduction);
    }

    public function test_penalty_of_locked_period_goes_to_first_open_period(): void
    {
        $staff = $this->fulltime('sales_consultant', 10000000);
        $this->attendFullMonth($staff, 10);
        $this->period(9, 'paid');
        $oct = $this->period(10);
        $late = Penalty::create(['code' => 'BB-WD-L', 'user_id' => $staff->id, 'violation_type' => 'Vi phạm nội quy', 'violation_date' => '2026-09-30',
            'amount' => 100000, 'status' => 'fined', 'decided_at' => '2026-10-01 09:00:00']);

        $this->travelTo(Carbon::parse('2026-11-02 09:00:00'));
        $this->calculate($oct);
        $this->assertEquals(100000, $this->record($oct->fresh(), $staff)->penalty_deduction);
        $this->assertSame($this->record($oct->fresh(), $staff)->id, $late->fresh()->payroll_record_id);
    }

    // ───────────── R6 Thưởng tái tục chỉ Học vụ (TK-0015) ─────────────

    public function test_renewal_bonus_only_for_academic_staff(): void
    {
        $formula = app(\App\Services\PayrollFormulaService::class);
        $this->assertTrue($formula->getsRenewalBonus('academic_staff'));
        foreach (['teacher_fulltime', 'teacher_parttime', 'academic_lead', 'assistant', 'sales', 'staff'] as $role) {
            $this->assertFalse($formula->getsRenewalBonus($role), $role);
        }
    }

    // ───────────── A6 Kỳ đã khóa không đổi ─────────────

    public function test_locked_period_is_never_recalculated(): void
    {
        $staff = $this->fulltime('teacher_fulltime', 13000000);
        $this->attendFullMonth($staff, 9);
        $this->travelTo(Carbon::parse('2026-10-02 09:00:00'));
        $period = $this->calculate($this->period(9));
        $before = $this->record($period, $staff)->only(['base_salary_paid', 'insurance_deduction', 'net_salary']);
        $period->update(['status' => 'approved']);

        SystemSetting::set('payroll_insurance_salary_base', 9000000, 'test');
        try {
            $period->fresh()->calculatePayrollForPeriod();
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
        }
        $this->assertSame($before, $this->record($period, $staff)->only(['base_salary_paid', 'insurance_deduction', 'net_salary']));
    }
}
