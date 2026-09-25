<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\CommissionAdjustment;
use App\Models\CommissionItem;
use App\Models\CommissionTier;
use App\Models\Course;
use App\Models\CrmCustomer;
use App\Models\KpiCriterion;
use App\Models\KpiEvaluation;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\Penalty;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\TeacherHourlyRate;
use App\Models\TeacherTimesheet;
use App\Models\TuitionReceipt;
use App\Models\User;
use App\Services\PayrollFormulaService;
use App\Services\SalesCommissionService;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 3 — Công thức lương theo BA (A6 25/09/2026): Part-time / Full-time, KPI giữ HS, KPI Học vụ,
 * hoa hồng theo bậc số HS chốt + gate kép (hoãn, trả kỳ sau), thưởng tái tục, BHXH / Công đoàn / TNCN,
 * khoản nhập tay giữ khi tính lại. Mỗi test là một ví dụ số cụ thể.
 */
class Phase3FormulaTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $admin;

    private User $accountant;

    private Course $course;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Cơ sở CT', 'code' => 'CT', 'is_active' => true]);
        $this->admin = $this->userWithRole('admin');
        $this->accountant = $this->userWithRole('accountant');
        $this->course = Course::create(['code' => 'CT-C', 'name' => 'Movers', 'tuition_fee' => 6000000, 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    // ───────────── helpers ─────────────

    private function userWithRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes + ['branch_id' => $this->branch->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
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

    private function calculate(PayrollPeriod $period): PayrollPeriod
    {
        $period->calculatePayrollForPeriod();

        return $period->fresh();
    }

    private function record(PayrollPeriod $period, User $user): PayrollRecord
    {
        return PayrollRecord::where('payroll_period_id', $period->id)->where('user_id', $user->id)->firstOrFail();
    }

    private function classFor(?User $teacher, string $code): ClassModel
    {
        return ClassModel::create([
            'code' => $code, 'name' => "Lớp {$code}", 'course_id' => $this->course->id,
            'branch_id' => $this->branch->id, 'status' => 'active', 'teacher_id' => $teacher?->id,
        ]);
    }

    /** Học viên đang học trong lớp từ 20/08/2026. */
    private function enrolledStudent(ClassModel $class, string $enrolledAt = '2026-08-20'): Student
    {
        $this->seq++;
        $student = Student::create([
            'code' => 'HV-CT-'.$this->seq, 'name' => 'Học viên '.$this->seq, 'phone' => '09'.str_pad((string) (10000000 + $this->seq), 8, '0', STR_PAD_LEFT),
            'branch_id' => $this->branch->id, 'status' => 'studying', 'current_class_id' => $class->id,
        ]);
        ClassEnrollment::create(['student_id' => $student->id, 'class_id' => $class->id, 'enrolled_at' => $enrolledAt, 'status' => 'completed']);

        return $student;
    }

    private function dropOn(Student $student, string $date): void
    {
        $this->travelTo(Carbon::parse($date.' 10:00:00'));
        $student->update(['status' => Student::STATUS_DROPPED]);
        $this->travelBack();
    }

    private function timesheet(User $teacher, ClassModel $class, string $date, float $hours = 1.5): TeacherTimesheet
    {
        return TeacherTimesheet::create([
            'user_id' => $teacher->id, 'class_id' => $class->id, 'teaching_date' => $date,
            'hours' => $hours, 'type' => 'regular', 'status' => 'valid',
        ]);
    }

    private function tuition(Student $student, ?ClassModel $class = null, float $amount = 6000000): StudentTuition
    {
        return StudentTuition::create([
            'student_id' => $student->id, 'branch_id' => $this->branch->id, 'class_id' => $class?->id,
            'total_amount' => $amount, 'final_amount' => $amount, 'paid_amount' => 0, 'debt_amount' => $amount, 'status' => 'unpaid',
        ]);
    }

    private function approvedReceipt(StudentTuition $tuition, float $amount, string $approvedAt): TuitionReceipt
    {
        $this->travelTo(Carbon::parse($approvedAt));
        $this->seq++;
        $receipt = TuitionReceipt::create([
            'receipt_number' => 'PT-CT-'.$this->seq, 'student_tuition_id' => $tuition->id, 'student_id' => $tuition->student_id,
            'amount' => $amount, 'tuition_amount' => $amount, 'payment_method' => 'cash', 'payment_date' => $approvedAt,
            'creator_id' => $this->accountant->id, 'status' => 'pending',
        ]);
        $receipt->update(['status' => 'approved', 'approver_id' => $this->admin->id]);
        $this->travelBack();

        return $receipt;
    }

    /** Khách CRM do $sale chốt ngày $closedAt → học viên + khoản học phí đầu tiên + phiếu thu duyệt. */
    private function closedCustomer(User $sale, string $closedAt, float $paid, string $paidAt): array
    {
        $this->seq++;
        $student = Student::create([
            'code' => 'HV-HH-'.$this->seq, 'name' => 'Khách mới '.$this->seq, 'phone' => '08'.str_pad((string) (10000000 + $this->seq), 8, '0', STR_PAD_LEFT),
            'branch_id' => $this->branch->id, 'status' => 'studying',
        ]);
        $customer = CrmCustomer::create([
            'code' => 'KH-HH-'.$this->seq, 'name' => 'Khách '.$this->seq, 'phone' => $student->phone, 'stage' => 'won',
            'deal_value' => 99000000, 'branch_id' => $this->branch->id,
            'assigned_user_id' => $sale->id, 'commission_user_id' => $sale->id,
            'converted_student_id' => $student->id, 'converted_at' => $closedAt,
        ]);
        $receipt = $this->approvedReceipt($this->tuition($student), $paid, $paidAt);

        return [$customer, $student, $receipt];
    }

    private function tickMilestones(CrmCustomer $customer, int $count): void
    {
        $state = [];
        foreach (array_slice(['session_1', 'session_4_5', 'day_30'], 0, $count) as $item) {
            $state[$item] = ['done_at' => now()->toDateTimeString(), 'by' => 'CM'];
        }
        $customer->update(['care_checklist' => $state]);
    }

    // ───────────── A. Loại nhân sự ─────────────

    public function test_employee_type_is_derived_from_role_and_contract(): void
    {
        $formula = app(PayrollFormulaService::class);

        $this->assertSame(['parttime', 'teacher_parttime', 'teacher'], array_values($formula->profile($this->userWithRole('teacher_parttime', ['base_salary' => 9000000]))));
        $this->assertSame(['fulltime', 'teacher_fulltime', 'fulltime'], array_values($formula->profile($this->userWithRole('teacher_fulltime'))));
        $this->assertSame(['fulltime', 'academic_staff', 'operations'], array_values($formula->profile($this->userWithRole('academic_staff'))));
        $this->assertSame(['fulltime', 'academic_lead', 'academic'], array_values($formula->profile($this->userWithRole('academic_lead'))));
        // GV "teacher": theo loại hợp đồng; chưa rõ thì có lương cơ bản → Full-time
        $this->assertSame('parttime', $formula->profile($this->userWithRole('teacher', ['contract_type' => 'Bán thời gian', 'base_salary' => 5000000]))['employee_type']);
        $this->assertSame('fulltime', $formula->profile($this->userWithRole('teacher', ['contract_type' => 'Toàn thời gian']))['employee_type']);
        $this->assertSame('fulltime', $formula->profile($this->userWithRole('teacher', ['base_salary' => 8000000]))['employee_type']);
        $this->assertSame('parttime', $formula->profile($this->userWithRole('teacher'))['employee_type']);
        $this->assertSame(['fulltime', 'sales'], array_slice(array_values($formula->profile($this->userWithRole('sales_consultant'))), 0, 2));
    }

    // ───────────── B. Part-time ─────────────

    public function test_part_time_teacher_is_paid_per_session_plus_retention_kpi_foreign_line_and_free_lines(): void
    {
        // Lương cơ bản trên hồ sơ KHÔNG áp dụng cho Part-time
        $teacher = $this->userWithRole('teacher_parttime', ['name' => 'GV Part-time Lan', 'base_salary' => 5000000, 'hourly_rate' => 400000]);
        // Đơn giá riêng: theo giờ tới 31/08, theo BUỔI 250.000đ từ 01/09 (giữ lịch sử)
        TeacherHourlyRate::create(['user_id' => $teacher->id, 'hourly_rate' => 200000, 'rate_unit' => 'hour', 'effective_from' => '2026-01-01']);
        TeacherHourlyRate::create(['user_id' => $teacher->id, 'hourly_rate' => 250000, 'rate_unit' => 'session', 'effective_from' => '2026-09-01']);

        $class = $this->classFor($teacher, 'PT-01');
        $students = collect(range(1, 10))->map(fn () => $this->enrolledStudent($class));
        $this->enrolledStudent($class, '2026-09-10');      // vào lớp giữa kỳ: không thuộc mẫu số
        $this->dropOn($students[0], '2026-09-15');         // nghỉ trong kỳ
        foreach (range(1, 8) as $day) {
            $this->timesheet($teacher, $class, sprintf('2026-09-%02d', $day * 3), 1.5);
        }
        // Buổi tháng 8 tính theo giờ: không thuộc kỳ tháng 9
        $this->timesheet($teacher, $class, '2026-08-28', 2);
        // Phạt quá hạn (biên bản cũ không có hạn nộp, vi phạm trong kỳ)
        Penalty::create(['code' => 'BB-CT-1', 'user_id' => $teacher->id, 'violation_type' => 'Đi muộn', 'violation_date' => '2026-09-12', 'amount' => 100000, 'status' => 'fined']);

        $this->travelTo(Carbon::parse('2026-10-02 09:00:00'));
        $period = $this->calculate($this->period(9));
        $record = $this->record($period, $teacher);

        $this->assertSame('parttime', $record->employee_type);
        $this->assertSame('teacher', $record->department);
        $this->assertSame(8, $record->teaching_sessions);
        $this->assertEquals(2000000, $record->teaching_salary);     // 8 buổi × 250.000đ (không phải 12h × giá giờ)
        $this->assertEquals(0, $record->base_salary);
        $this->assertSame(10, $record->retention_base_students);
        $this->assertSame(9, $record->retention_students);
        $this->assertEquals(0, $record->kpi_bonus);                  // chưa chọn bậc
        $this->assertEquals(0, $record->insurance_deduction);
        $this->assertEquals(0, $record->union_deduction);
        $this->assertEquals(1900000, $record->net_salary);           // 2.000.000 − 100.000 phạt

        // Admin chọn bậc 20.000đ/HS + buổi có GVNN (chờ BA) + phụ cấp tự do có tên + khoản trừ tự do
        $this->actingAs($this->admin)->post(route('payroll.records.adjust', $record->id), [
            'retention_tier' => 20000,
            'foreign_session_pay' => 300000,
            'tax_deduction' => 999999, // Part-time: bị bỏ qua
            'lines' => [
                ['kind' => 'earning', 'label' => 'Gửi xe', 'amount' => 100000],
                ['kind' => 'earning', 'label' => 'Hỗ trợ thỏa thuận', 'amount' => 200000],
                ['kind' => 'deduction', 'label' => 'Tạm ứng', 'amount' => 50000],
                ['kind' => 'earning', 'label' => '', 'amount' => 0], // dòng trống bị bỏ
            ],
            'adjustment_notes' => 'Chốt bậc KPI tháng 9',
        ])->assertSessionHasNoErrors()->assertRedirect(route('payroll.records.show', $record->id));

        $record->refresh();
        $this->assertEquals(180000, $record->kpi_bonus);             // 9 HS × 20.000đ
        $this->assertEquals(300000, $record->foreign_session_pay);
        $this->assertEquals(300000, $record->allowance);
        $this->assertEquals(50000, $record->other_deduction);
        $this->assertEquals(0, $record->tax_deduction);
        // 2.000.000 + 180.000 + 300.000 + 300.000 − 50.000 − 100.000
        $this->assertEquals(2630000, $record->net_salary);

        // Bậc không hợp lệ bị từ chối; dòng tự do có tiền phải có tên
        $this->actingAs($this->admin)->post(route('payroll.records.adjust', $record->id), ['retention_tier' => 30000])
            ->assertSessionHasErrors('retention_tier');
        $this->actingAs($this->admin)->post(route('payroll.records.adjust', $record->id), ['retention_tier' => 20000, 'lines' => [['kind' => 'earning', 'label' => '', 'amount' => 5000]]])
            ->assertSessionHasErrors('lines');

        // Tính lại: khoản nhập tay được giữ
        $period = $this->calculate($period);
        $record = $this->record($period, $teacher);
        $this->assertEquals(20000, $record->retention_tier);
        $this->assertEquals(180000, $record->kpi_bonus);
        $this->assertEquals(300000, $record->foreign_session_pay);
        $this->assertCount(3, $record->manualLines());
        $this->assertEquals(2630000, $record->net_salary);
        $this->assertEquals(2630000, $period->total_amount);

        // Phiếu lương hiện đúng các dòng Part-time, không có BHXH
        $this->actingAs($this->accountant)->get(route('payroll.records.show', $record->id))
            ->assertOk()
            ->assertSee('Lương buổi dạy (8 buổi)')
            ->assertSee('KPI giữ học sinh')
            ->assertSee('9/10 HS giữ được')
            ->assertSee('Buổi có GVNN (chờ BA chốt)')
            ->assertSee('Gửi xe')
            ->assertSee('Tạm ứng')
            ->assertSee('2.630.000')
            ->assertDontSee('Công đoàn (');
        $this->actingAs($this->admin)->get(route('payroll.periods.show', $period->id))
            ->assertOk()->assertSee('Part-time')->assertSee('9 HS × 20,000');
    }

    public function test_foreign_session_line_is_manual_and_no_longer_deducts(): void
    {
        $teacher = $this->userWithRole('teacher_parttime', ['name' => 'GV Việt']);
        $foreign = $this->userWithRole('teacher_fulltime', ['name' => 'GVNN John', 'base_salary' => 20000000]);
        TeacherHourlyRate::create(['user_id' => $teacher->id, 'hourly_rate' => 300000, 'rate_unit' => 'session', 'effective_from' => '2026-01-01']);
        $class = $this->classFor(null, 'NN-01');
        $class->update(['foreign_teacher_id' => $foreign->id]);
        foreach (['2026-09-05', '2026-09-12'] as $date) {
            $this->timesheet($teacher, $class, $date);
            $this->timesheet($foreign, $class, $date);
        }

        $period = $this->calculate($this->period(9));
        $record = $this->record($period, $teacher);
        $this->assertSame(2, $record->foreign_teacher_sessions_count);   // gợi ý cho Kế toán
        $this->assertEquals(0, $record->foreign_teacher_deduction);       // bỏ quy tắc trừ 50k/buổi
        $this->assertEquals(600000, $record->net_salary);

        $this->actingAs($this->accountant)->post(route('payroll.records.update', $record->id), ['foreign_session_pay' => 150000, 'notes' => 'Tạm tính chờ BA'])
            ->assertSessionHasNoErrors();
        $this->assertEquals(750000, $record->fresh()->net_salary);
        $this->assertEquals(750000, $this->calculate($period)->records()->where('user_id', $teacher->id)->value('net_salary'));
    }

    // ───────────── C. Full-time + BHXH / Công đoàn / TNCN ─────────────

    public function test_full_time_teacher_gets_base_plus_manual_kpi_minus_insurance_union_tax_and_penalty(): void
    {
        $teacher = $this->userWithRole('teacher_fulltime', ['name' => 'GV Full-time Minh', 'base_salary' => 10000000, 'hourly_rate' => 300000]);
        $class = $this->classFor(null, 'FT-01');
        $this->timesheet($teacher, $class, '2026-09-03', 2);   // buổi dạy chỉ để đối soát
        Penalty::create(['code' => 'BB-CT-2', 'user_id' => $teacher->id, 'violation_type' => 'Quên điểm danh', 'violation_date' => '2026-09-10', 'amount' => 200000, 'status' => 'fined']);

        $this->travelTo(Carbon::parse('2026-10-02 09:00:00'));
        $period = $this->calculate($this->period(9));
        $record = $this->record($period, $teacher);

        $this->assertSame('fulltime', $record->employee_type);
        $this->assertSame('fulltime', $record->department);
        $this->assertEquals(0, $record->teaching_salary);
        $this->assertEquals(1050000, $record->insurance_deduction);  // 10,5% × 10.000.000
        $this->assertEquals(50000, $record->union_deduction);        // 0,5% × 10.000.000
        $this->assertEquals(0, $record->allowance);                  // bỏ phụ cấp cố định 500k
        $this->assertEquals(8700000, $record->net_salary);           // 10M − 1,05M − 50k − 200k

        $this->actingAs($this->accountant)->post(route('payroll.records.adjust', $record->id), [
            'kpi_manual_amount' => 1500000,
            'tax_deduction' => 250000,
            'retention_tier' => 25000, // Full-time: bỏ qua
            'lines' => [['kind' => 'earning', 'label' => 'Phụ cấp trách nhiệm', 'amount' => 500000]],
        ])->assertSessionHasNoErrors();

        $record->refresh();
        $this->assertEquals(1500000, $record->kpi_bonus);
        $this->assertNull($record->retention_tier);
        // 10.000.000 + 1.500.000 + 500.000 − 1.050.000 − 50.000 − 250.000 − 200.000
        $this->assertEquals(10450000, $record->net_salary);

        // Tỉ lệ cấu hình được: Công đoàn 1% → tính lại
        $this->actingAs($this->admin)->post(route('payroll.config.settings.store'), [
            'insurance_rate_percent' => 10.5, 'union_rate_percent' => 1, 'academic_kpi_fund' => 2000000,
        ])->assertSessionHasNoErrors();
        $period = $this->calculate($period);
        $record = $this->record($period, $teacher);
        $this->assertEquals(100000, $record->union_deduction);
        $this->assertEquals(1500000, $record->kpi_bonus);            // KPI tay giữ nguyên
        $this->assertEquals(250000, $record->tax_deduction);         // TNCN tay giữ nguyên
        $this->assertEquals(10400000, $record->net_salary);

        $this->actingAs($this->accountant)->get(route('payroll.records.show', $record->id))
            ->assertOk()
            ->assertSee('BHXH (10,5% lương cơ bản)')
            ->assertSee('Công đoàn (1% lương cơ bản)')
            ->assertSee('Thuế TNCN')
            ->assertSee('Phụ cấp trách nhiệm')
            ->assertSee('10.400.000');
        $this->actingAs($this->admin)->get(route('payroll.periods.fulltime', $period->id))
            ->assertOk()->assertSee('GV Full-time Minh')->assertSee('Công đoàn');
    }

    // ───────────── D. KPI Học vụ tự động ─────────────

    public function test_academic_staff_kpi_is_fund_times_weighted_score_of_15_items(): void
    {
        $this->assertSame(15, KpiCriterion::whereNotNull('code')->count());
        $this->assertSame(6, KpiCriterion::whereNotNull('code')->distinct()->count('group_name'));
        $this->assertEquals(100, (float) KpiCriterion::active()->sum('weight'));

        $staff = $this->userWithRole('academic_staff', ['name' => 'Học vụ Phượng', 'base_salary' => 8000000]);
        $lead = $this->userWithRole('manager');
        $criteria = KpiCriterion::active()->ordered()->get();
        $scores = $criteria->mapWithKeys(fn (KpiCriterion $c) => [$c->id => match ($c->code) {
            '1.2' => 50,   // Thu học phí (15%) đạt ngưỡng 50%
            '2.4' => 0,    // Feedback Big Test (10%) không đạt
            default => 100,
        }])->all();

        // Không tự chấm KPI của mình
        $this->actingAs($staff)->post(route('kpi.evaluate.store', $staff->id), ['month' => 9, 'year' => 2026, 'score' => $scores])->assertForbidden();

        $this->actingAs($lead)->post(route('kpi.evaluate.store', $staff->id), ['month' => 9, 'year' => 2026, 'score' => $scores])
            ->assertSessionHasNoErrors();
        $this->assertEquals(82.5, (float) KpiEvaluation::where('user_id', $staff->id)->value('total_score')); // 100 − 7,5 − 10

        $period = $this->calculate($this->period(9));
        $record = $this->record($period, $staff);
        $this->assertSame('academic_kpi', $record->kpi_source);
        $this->assertSame('operations', $record->department);
        $this->assertEquals(82.5, $record->kpi_score);
        $this->assertEquals(1650000, $record->kpi_bonus);            // 2.000.000 × 82,5%
        // 8.000.000 + 1.650.000 − 840.000 BHXH − 40.000 Công đoàn
        $this->assertEquals(8770000, $record->net_salary);

        // KPI Học vụ không nhập tay được
        $this->actingAs($this->admin)->post(route('payroll.records.adjust', $record->id), ['kpi_manual_amount' => 5000000])->assertSessionHasNoErrors();
        $this->assertEquals(1650000, $record->fresh()->kpi_bonus);

        // Sửa đánh giá sau khi tính → phải tính lại trước khi duyệt
        $this->travel(1)->minutes();
        $this->actingAs($lead)->post(route('kpi.evaluate.store', $staff->id), ['month' => 9, 'year' => 2026, 'score' => array_map(fn () => 100, $scores)]);
        $this->assertTrue($period->fresh()->hasChangesSinceCalculation());
        $this->assertEquals(2000000, $this->record($this->calculate($period), $staff)->kpi_bonus);

        $this->actingAs($this->admin)->get(route('payroll.periods.operations', $period->id))->assertOk()->assertSee('Học vụ Phượng')->assertSee('100% × quỹ');
        $this->actingAs($this->admin)->get(route('kpi.criteria'))->assertOk()->assertSee('Chăm sóc học viên')->assertSee('Thu học phí')->assertSee('300.000đ');
    }

    // ───────────── E. Hoa hồng: bậc theo số HS chốt + gate kép ─────────────

    public function test_commission_tier_is_selected_by_number_of_students_closed(): void
    {
        $service = app(SalesCommissionService::class);
        // Bậc mặc định (migration): 0–5 → 3%, 6–10 → 4%, từ 11 → 5%
        $this->assertEquals(3, $service->commissionFor(10000000, 5, '2026-09-30')['percent']);
        $this->assertEquals(4, $service->commissionFor(10000000, 6, '2026-09-30')['percent']);
        $this->assertEquals(400000, $service->commissionFor(10000000, 10, '2026-09-30')['amount']);
        $this->assertEquals(5, $service->commissionFor(10000000, 11, '2026-09-30')['percent']);

        // Admin cấu hình bậc theo số HS (phiên bản mới)
        $this->actingAs($this->admin)->post(route('payroll.config.commission-tiers.store'), [
            'tier_name' => 'Bậc 4 (từ 20 HS)', 'min_students' => 20, 'new_sale_percent' => 6, 'effective_from' => '2026-01-01',
        ])->assertSessionHasNoErrors();
        $this->assertEquals(6, $service->commissionFor(10000000, 25, '2026-09-30')['percent']);
        $this->actingAs($this->admin)->post(route('payroll.config.commission-tiers.store'), ['tier_name' => 'Thiếu ngưỡng', 'new_sale_percent' => 2])
            ->assertSessionHasErrors('min_students');
        $this->actingAs($this->admin)->get(route('payroll.config.commission-tiers'))->assertOk()->assertSee('Số HS chốt trong kỳ')->assertSee('Từ 20 HS');
    }

    public function test_commission_is_deferred_until_30_days_and_3_care_milestones_then_paid_later_with_clawback(): void
    {
        $sale = $this->userWithRole('sales_consultant', ['name' => 'Sale Hoãn']);
        // 6 khách chốt ngày 05/09 → bậc 4%; mỗi khách đóng 5.000.000đ ngày 10/09
        $closed = collect(range(1, 6))->map(fn () => $this->closedCustomer($sale, '2026-09-05 09:00:00', 5000000, '2026-09-10 10:00:00'));

        // Tháng 9: chưa đủ 30 ngày → hoãn toàn bộ, không mất
        $this->travelTo(Carbon::parse('2026-10-01 08:00:00'));
        $september = $this->calculate($this->period(9));
        $record = $this->record($september, $sale);
        $this->assertSame(6, $record->commission_closed_count);
        $this->assertEquals(4, $record->commission_percent);
        $this->assertEquals(0, $record->commission_bonus);
        $this->assertEquals(1200000, $record->commission_deferred);  // 4% × 30.000.000
        $this->assertSame(6, CommissionItem::where('status', CommissionItem::STATUS_DEFERRED)->count());
        $this->assertStringContainsString('chưa đủ 30 ngày', CommissionItem::first()->deferred_reason);
        $this->actingAs($this->admin)->post(route('payroll.periods.approve', $september->id))->assertSessionHasNoErrors();
        $this->assertSame(0, CommissionItem::whereNotNull('settled_at')->count());

        // Tháng 10: đủ 30 ngày; 4 khách đủ 3/3 mốc, 1 khách 2/3, 1 khách chưa tick
        $this->travelTo(Carbon::parse('2026-10-20 09:00:00'));
        foreach ($closed->take(4) as [$customer]) {
            $this->tickMilestones($customer, 3);
        }
        $this->tickMilestones($closed[4][0], 2);
        $this->travelTo(Carbon::parse('2026-11-01 08:00:00'));
        $october = $this->calculate($this->period(10));
        $record = $this->record($october, $sale);
        $this->assertSame(0, $record->commission_closed_count);
        $this->assertEquals(800000, $record->commission_bonus);      // 4 × 4% × 5.000.000 (giữ % kỳ phát sinh)
        $this->assertEquals(20000000, $record->commission_base);
        $this->assertEquals(400000, $record->commission_deferred);
        $this->assertEquals(800000, $record->net_salary);
        $this->assertStringContainsString('2/3 mốc', CommissionItem::where('crm_customer_id', $closed[4][0]->id)->value('deferred_reason'));

        $this->actingAs($this->accountant)->get(route('payroll.records.show', $record->id))
            ->assertOk()->assertSee('Trả trong kỳ')->assertSee('Hoãn 200.000đ')->assertSee('chăm sóc tháng đầu mới 2/3 mốc');
        $this->actingAs($this->admin)->post(route('payroll.periods.approve', $october->id))->assertSessionHasNoErrors();
        $this->assertSame(4, CommissionItem::where('status', CommissionItem::STATUS_PAID)->whereNotNull('settled_at')->count());

        // Hủy hóa đơn phiếu ĐÃ trả hoa hồng → thu hồi đúng 200.000đ; phiếu còn hoãn → hủy khoản, không thu hồi
        $service = app(SalesCommissionService::class);
        $paidReceipt = $closed[0][2];
        $deferredReceipt = $closed[5][2];
        $adjustment = $service->recordCancellationClawback($paidReceipt, $this->admin, 'C26-0001');
        $paidReceipt->update(['status' => TuitionReceipt::STATUS_CANCELLED]);
        $this->assertEquals(-200000, (float) $adjustment->amount);
        $this->assertNull($service->recordCancellationClawback($deferredReceipt, $this->admin, 'C26-0002'));
        $deferredReceipt->update(['status' => TuitionReceipt::STATUS_CANCELLED]);
        $this->assertSame(CommissionItem::STATUS_VOID, CommissionItem::where('tuition_receipt_id', $deferredReceipt->id)->value('status'));

        // Tháng 11: khách còn lại đủ mốc → trả 200.000đ; trừ 200.000đ thu hồi
        $this->travelTo(Carbon::parse('2026-11-10 09:00:00'));
        $this->tickMilestones($closed[4][0], 3);
        $this->travelTo(Carbon::parse('2026-12-01 08:00:00'));
        $november = $this->calculate($this->period(11));
        $record = $this->record($november, $sale);
        $this->assertEquals(200000, $record->commission_bonus);
        $this->assertEquals(0, $record->commission_deferred);
        $this->assertEquals(200000, $record->commission_clawback);
        $this->assertEquals(0, $record->net_salary);
        $this->assertSame(1, CommissionAdjustment::count());

        // Kỳ đã duyệt không tính lại, khoản đã trả không trả lại
        $this->assertSame(4, CommissionItem::where('status', CommissionItem::STATUS_PAID)->count());
    }

    public function test_refund_with_clawback_voids_deferred_commission_of_the_student(): void
    {
        $sale = $this->userWithRole('sales_consultant');
        [, $student] = $this->closedCustomer($sale, '2026-09-20 09:00:00', 6000000, '2026-09-21 10:00:00');

        $this->travelTo(Carbon::parse('2026-10-01 08:00:00'));
        $september = $this->calculate($this->period(9));
        $this->assertEquals(180000, $this->record($september, $sale)->commission_deferred); // 3% (1 HS chốt) × 6M

        $refund = \App\Models\TuitionRefundRequest::create([
            'student_id' => $student->id, 'type' => 'refund', 'total_paid' => 6000000, 'refund_amount' => 6000000,
            'reason' => 'Chuyển nhà', 'requester_id' => $this->accountant->id, 'status' => 'pending',
        ]);
        $this->assertEquals(0, app(SalesCommissionService::class)->suggestedClawbackAmount($refund)); // chưa trả gì
        app(SalesCommissionService::class)->recordRefundDecision($refund, true, null, $this->admin);

        $this->assertSame(CommissionItem::STATUS_VOID, CommissionItem::value('status'));
        $this->assertSame(0, CommissionAdjustment::count());
        // Tính lại: khoản đã hủy không mở lại → sale không còn khoản nào trong kỳ
        $september = $this->calculate($september);
        $this->assertFalse($september->records()->where('user_id', $sale->id)->exists());
    }

    // ───────────── F. Thưởng tái tục ─────────────

    public function test_renewal_bonus_uses_quit_count_table_times_class_revenue(): void
    {
        $teacher = $this->userWithRole('teacher_fulltime', ['name' => 'GV Chủ Nhiệm', 'base_salary' => 9000000]);
        $classA = $this->classFor($teacher, 'TT-A'); // giữ đủ → 1%
        $classB = $this->classFor($teacher, 'TT-B'); // nghỉ 1 → 0,7%
        $classC = $this->classFor($teacher, 'TT-C'); // nghỉ 2 → chờ BA (0%)

        $a = collect(range(1, 5))->map(fn () => $this->enrolledStudent($classA));
        $b = collect(range(1, 4))->map(fn () => $this->enrolledStudent($classB));
        $c = collect(range(1, 3))->map(fn () => $this->enrolledStudent($classC));
        $this->dropOn($b[0], '2026-09-12');
        $this->dropOn($c[0], '2026-09-05');
        $this->dropOn($c[1], '2026-09-25');
        $this->dropOn($a[4], '2026-10-03'); // nghỉ sau kỳ: không tính vào tháng 9

        // Doanh thu lớp tháng 9 (phiếu duyệt trong kỳ của khoản học phí gắn lớp)
        $this->approvedReceipt($this->tuition($a[0], $classA), 12000000, '2026-09-08 10:00:00');
        $this->approvedReceipt($this->tuition($a[1], $classA), 8000000, '2026-09-18 10:00:00');
        $this->approvedReceipt($this->tuition($b[1], $classB), 10000000, '2026-09-09 10:00:00');
        $this->approvedReceipt($this->tuition($c[2], $classC), 5000000, '2026-09-10 10:00:00');
        $this->approvedReceipt($this->tuition($a[2], $classA), 7000000, '2026-10-02 10:00:00'); // kỳ sau

        $this->travelTo(Carbon::parse('2026-10-05 09:00:00'));
        $period = $this->calculate($this->period(9));
        $record = $this->record($period, $teacher);

        // 1% × 20.000.000 + 0,7% × 10.000.000 + 0% × 5.000.000
        $this->assertEquals(270000, $record->renew_bonus);
        $rows = collect(data_get($record->calculation_details, 'renewal.classes'))->keyBy('class_id');
        $this->assertSame(0, $rows[$classA->id]['quits']);
        $this->assertSame(1, $rows[$classB->id]['quits']);
        $this->assertSame(2, $rows[$classC->id]['quits']);
        $this->assertTrue($rows[$classC->id]['pending']);
        // 9.000.000 + 270.000 − 945.000 − 45.000
        $this->assertEquals(8280000, $record->net_salary);

        $this->actingAs($this->accountant)->get(route('payroll.records.show', $record->id))
            ->assertOk()->assertSee('Thưởng tái tục theo lớp')->assertSee('chờ BA')->assertSee('270.000');

        // Bảng % cấu hình được (Admin): mốc 2 HS nghỉ → 0,5%
        $this->actingAs($this->admin)->get(route('payroll.config.settings'))->assertOk()->assertSee('chờ BA');
        $this->actingAs($this->admin)->post(route('payroll.config.settings.store'), [
            'insurance_rate_percent' => 10.5, 'union_rate_percent' => 0.5, 'academic_kpi_fund' => 2000000,
            'renewal' => [
                ['quits' => 0, 'percent' => 1, 'pending' => 0],
                ['quits' => 1, 'percent' => 0.7, 'pending' => 0],
                ['quits' => 2, 'percent' => 0.5, 'pending' => 0],
            ],
            'renewal_beyond_percent' => 0,
        ])->assertSessionHasNoErrors();
        $this->assertEquals(295000, $this->record($this->calculate($period), $teacher)->renew_bonus); // + 0,5% × 5.000.000
    }

    // ───────────── G. Duyệt kỳ vẫn chỉ Admin ─────────────

    public function test_only_admin_approves_and_locked_period_rejects_manual_inputs(): void
    {
        $teacher = $this->userWithRole('teacher_parttime');
        TeacherHourlyRate::create(['user_id' => $teacher->id, 'hourly_rate' => 200000, 'rate_unit' => 'session', 'effective_from' => '2026-01-01']);
        $this->timesheet($teacher, $this->classFor(null, 'LK-01'), '2026-09-05');
        $period = $this->calculate($this->period(9));
        $record = $this->record($period, $teacher);

        $this->actingAs($this->accountant)->post(route('payroll.periods.approve', $period->id))->assertForbidden();
        $this->actingAs($this->admin)->post(route('payroll.periods.approve', $period->id))->assertSessionHasNoErrors();
        $this->assertSame('approved', $period->fresh()->status);

        $this->actingAs($this->admin)->post(route('payroll.records.adjust', $record->id), ['retention_tier' => 15000])->assertStatus(422);
        $this->actingAs($this->admin)->post(route('payroll.records.update', $record->id), ['foreign_session_pay' => 1])->assertStatus(422);
        $this->actingAs($this->admin)->post(route('payroll.periods.calculate', $period->id))->assertStatus(422);
    }

    public function test_personal_rate_screen_records_per_session_versions(): void
    {
        $manager = $this->userWithRole('manager');
        $teacher = $this->userWithRole('teacher_parttime', ['name' => 'GV Đơn Giá Buổi']);

        $this->actingAs($manager)->post(route('payroll.config.teacher-rates.personal.store'), [
            'user_id' => $teacher->id, 'hourly_rate' => 280000, 'rate_unit' => 'session', 'effective_from' => '2026-09-01',
        ])->assertSessionHasNoErrors();
        $this->actingAs($manager)->post(route('payroll.config.teacher-rates.personal.store'), [
            'user_id' => $teacher->id, 'hourly_rate' => 320000, 'rate_unit' => 'session', 'effective_from' => '2026-10-01',
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, TeacherHourlyRate::where('user_id', $teacher->id)->where('rate_unit', 'session')->count());
        $class = $this->classFor(null, 'DG-01');
        $this->assertEquals(280000, $this->timesheet($teacher, $class, '2026-09-20', 2)->sessionPay()['amount']);
        $this->assertEquals(320000, $this->timesheet($teacher, $class, '2026-10-02', 2)->sessionPay()['amount']);

        $this->actingAs($manager)->get(route('payroll.config.teacher-rates', ['teacher_id' => $teacher->id]))
            ->assertOk()->assertSee('đ/buổi')->assertSee('320.000');
    }
}
