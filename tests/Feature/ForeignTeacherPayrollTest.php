<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\Penalty;
use App\Models\Student;
use App\Models\TeacherHourlyRate;
use App\Models\TeacherTimesheet;
use App\Models\User;
use App\Services\PayrollFormulaService;
use App\Support\Roles;
use App\Support\StaffType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\AttendsWorkdays;
use Tests\TestCase;

/** TK-0019: GVNN trả theo phút lịch buổi, KPI giữ HS và các khoản phạt thủ công. */
class ForeignTeacherPayrollTest extends TestCase
{
    use AttendsWorkdays;
    use RefreshDatabase;

    private Branch $branch;

    private User $admin;

    private User $foreign;

    private ClassModel $class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 3)->setTime(10, 0));
        $this->branch = Branch::create(['name' => 'Cơ sở GVNN', 'code' => 'NN', 'is_active' => true]);
        $this->admin = $this->staff(Roles::ADMIN, 'Quản trị');
        $this->foreign = $this->staff(Roles::FOREIGN_TEACHER, 'Giáo viên nước ngoài');
        $course = Course::create(['code' => 'NN', 'name' => 'Khóa GVNN', 'tuition_fee' => 1200000, 'total_lessons' => 12, 'is_active' => true]);
        $this->class = ClassModel::create(['code' => 'L-NN', 'name' => 'Lớp GVNN', 'course_id' => $course->id,
            'branch_id' => $this->branch->id, 'foreign_teacher_id' => $this->foreign->id, 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function staff(string $role, string $name): User
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id, 'name' => $name, 'is_active' => true, 'base_salary' => 0]);
        $user->assignRole($role);

        return $user;
    }

    private function teach(User $user, string $date, array $extra = []): TeacherTimesheet
    {
        return TeacherTimesheet::create($extra + ['user_id' => $user->id, 'class_id' => $this->class->id,
            'teaching_date' => $date, 'scheduled_time' => '18:00-19:30', 'hours' => 1.5, 'type' => 'regular', 'status' => 'valid']);
    }

    private function rate(User $user, float $amount = 7500, string $from = '2026-09-01', string $unit = TeacherHourlyRate::UNIT_MINUTE): TeacherHourlyRate
    {
        return TeacherHourlyRate::create(['user_id' => $user->id, 'hourly_rate' => $amount, 'rate_unit' => $unit,
            'teacher_type' => StaffType::isForeignTeacher($user) ? 'foreign' : 'parttime', 'effective_from' => $from]);
    }

    private function period(): PayrollPeriod
    {
        return PayrollPeriod::create(['code' => 'PR-NN-2026-09', 'title' => 'Lương GVNN tháng 9', 'month' => 9, 'year' => 2026,
            'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'status' => PayrollPeriod::STATUS_DRAFT]);
    }

    private function record(PayrollPeriod $period, ?User $user = null): PayrollRecord
    {
        return $period->records()->where('user_id', ($user ?? $this->foreign)->id)->firstOrFail();
    }

    public function test_profile_detects_foreign_role_before_vietnamese_teacher_roles(): void
    {
        $this->foreign->assignRole([Roles::TEACHER_PARTTIME, Roles::TEACHER_FULLTIME]);
        $this->assertTrue(StaffType::isForeignTeacher($this->foreign->fresh()));
        $this->assertSame(['employee_type' => PayrollRecord::TYPE_PARTTIME, 'salary_role' => Roles::FOREIGN_TEACHER, 'department' => 'foreign'],
            app(PayrollFormulaService::class)->profile($this->foreign->fresh()));
        $vn = $this->staff(Roles::TEACHER_PARTTIME, 'Giáo viên Việt Nam');
        $this->assertFalse(StaffType::isForeignTeacher($vn));
        $this->assertSame(['employee_type' => PayrollRecord::TYPE_PARTTIME, 'salary_role' => Roles::TEACHER_PARTTIME, 'department' => 'teacher'],
            app(PayrollFormulaService::class)->profile($vn));
        $this->foreign->assignRole(Roles::ACADEMIC_LEAD);
        $this->assertSame(Roles::ACADEMIC_LEAD, app(PayrollFormulaService::class)->profile($this->foreign->fresh())['salary_role']);
    }

    public function test_scheduled_minutes_are_paid_in_full_even_when_late_and_other_rates_stay_unchanged(): void
    {
        $this->rate($this->foreign);
        $this->teach($this->foreign, '2026-09-05', ['late_minutes' => 25, 'late_notified' => false, 'hours' => 1]);
        $this->teach($this->foreign, '2026-09-12', ['scheduled_time' => '18:00-19:00', 'hours' => 1]);
        $this->teach($this->foreign, '2026-09-19', ['status' => 'invalid']);
        $vn = $this->staff(Roles::TEACHER_PARTTIME, 'Giáo viên theo buổi');
        $this->rate($vn, 300000, unit: TeacherHourlyRate::UNIT_SESSION);
        $this->teach($vn, '2026-09-05');
        $hourly = $this->staff(Roles::TEACHER_PARTTIME, 'Giáo viên theo giờ');
        $this->rate($hourly, 150000, unit: TeacherHourlyRate::UNIT_HOUR);
        $this->teach($hourly, '2026-09-05');
        $period = $this->period();
        $period->calculatePayrollForPeriod();
        $record = $this->record($period);
        $this->assertTrue($record->isForeignTeacher());
        $this->assertEquals(1125000, $record->teaching_salary);
        $this->assertEquals(2, $record->actual_hours);
        $this->assertSame(2, $record->teaching_sessions);
        $this->assertSame(150, data_get($record->calculation_details, 'foreign_teacher.minutes'));
        $this->assertEquals([7500], data_get($record->calculation_details, 'foreign_teacher.rates'));
        $this->assertSame([], data_get($record->calculation_details, 'late.lines'));
        $this->assertStringContainsString('150 phút dạy =', $record->notes);
        $this->assertSame('Lương phút dạy (150 phút, 2 buổi)', $record->earningLines()[0]['label']);
        $this->assertEquals(300000, $this->record($period, $vn)->teaching_salary);
        $this->assertEquals(225000, $this->record($period, $hourly)->teaching_salary);
    }

    public function test_effective_rate_version_and_missing_minute_rate_have_no_hourly_fallback(): void
    {
        $this->foreign->update(['hourly_rate' => 999999]);
        $this->rate($this->foreign, 450000, '2026-08-01', TeacherHourlyRate::UNIT_SESSION);
        $this->teach($this->foreign, '2026-09-02');
        $this->rate($this->foreign, 7500, '2026-09-10');
        $this->teach($this->foreign, '2026-09-12', ['scheduled_time' => '18:00-19:00', 'hours' => 1]);
        $this->rate($this->foreign, 8000, '2026-09-20');
        $this->teach($this->foreign, '2026-09-22', ['scheduled_time' => '18:00-19:00', 'hours' => 1]);
        $period = $this->period();
        $period->calculatePayrollForPeriod();
        $record = $this->record($period);
        $this->assertEquals(930000, $record->teaching_salary);
        $this->assertTrue($record->foreignRateMissing());
        $this->assertSame(1, data_get($record->calculation_details, 'foreign_teacher.missing_rate_sessions'));
        $this->assertEquals([7500, 8000], data_get($record->calculation_details, 'foreign_teacher.rates'));
        $this->assertStringContainsString('1 buổi chưa có đơn giá phút – cần nhập', $record->earningLines()[0]['hint']);
        $this->actingAs($this->admin)->get(route('payroll.periods.show', $period->id))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('foreignRateMissing.0', $this->foreign->name));
    }

    public function test_tax_foreign_session_pay_missing_punch_and_auto_penalties_are_excluded(): void
    {
        $this->rate($this->foreign);
        $this->teach($this->foreign, '2026-09-05', ['late_minutes' => 25, 'late_notified' => false]);
        $this->attendDay($this->foreign, '2026-09-05', '08:00', null);
        $auto = Penalty::create(['code' => 'BB-NN-A', 'user_id' => $this->foreign->id, 'violation_type' => 'Nhận xét trễ',
            'violation_date' => '2026-09-05', 'amount' => 60000, 'status' => 'fined', 'auto_source' => 'sla:gv.feedback']);
        $manual = Penalty::create(['code' => 'BB-NN-M', 'user_id' => $this->foreign->id, 'violation_type' => 'Vi phạm học thuật',
            'violation_date' => '2026-09-05', 'amount' => 30000, 'status' => 'fined']);
        $period = $this->period();
        $period->calculatePayrollForPeriod();
        $record = $this->record($period);
        $this->assertEquals(675000, $record->teaching_salary);
        $this->assertEquals(30000, $record->penalty_deduction);
        $this->assertEquals(0, $record->missing_punch_deduction);
        $this->assertEquals(0, $record->late_deduction);
        $this->assertNull($auto->fresh()->payroll_record_id);
        $this->assertSame($record->id, $manual->fresh()->payroll_record_id);
        $this->assertFalse($period->fresh()->hasChangesSinceCalculation());
        $this->travelTo(now()->addHour());
        $auto->update(['amount' => 90000]);
        $this->assertFalse($period->fresh()->hasChangesSinceCalculation());
        $record->fill(['insurance_deduction' => 200, 'union_deduction' => 300, 'tax_deduction' => 100000,
            'foreign_session_pay' => 500000, 'foreign_teacher_sessions_count' => 4]);
        $record->applyManualInputs();
        $record->save();
        $this->actingAs($this->admin)->post(route('payroll.records.adjust', $record->id), ['tax_deduction' => 100000, 'foreign_session_pay' => 500000])
            ->assertSessionHasNoErrors();
        $record->refresh();
        foreach (['insurance_deduction', 'union_deduction', 'tax_deduction', 'foreign_session_pay', 'foreign_teacher_sessions_count'] as $field) {
            $this->assertEquals(0, $record->$field, $field);
        }
        $this->assertNotContains('foreign_session_pay', collect($record->earningLines())->pluck('key')->all());
        $this->assertNotContains('tax_deduction', collect($record->deductionLines())->pluck('key')->all());
        $this->actingAs($this->admin)->get(route('payroll.records.show', $record->id))->assertOk()
            ->assertDontSee('name="tax_deduction"', false)->assertDontSee('name="foreign_session_pay"', false)
            ->assertInertia(fn (AssertableInertia $page) => $page->where('timesheets.0.unit', 'minute')->where('timesheets.0.minutes', 90));
    }

    public function test_foreign_teacher_retention_uses_assigned_foreign_classes_and_taught_classes_with_manual_tier(): void
    {
        config(['payroll.retention.class_scope' => 'main_teacher']);
        $this->rate($this->foreign);
        $taught = ClassModel::create(['code' => 'L-NN-THAY', 'name' => 'Lớp dạy thay', 'course_id' => $this->class->course_id,
            'branch_id' => $this->branch->id, 'status' => 'active']);
        $this->teach($this->foreign, '2026-09-05', ['class_id' => $taught->id]);
        // Lớp phân công GVNN chưa dạy trong kỳ + lớp dạy thay không phân công thường xuyên đều tính giữ HS.
        foreach ([$this->class, $taught] as $index => $class) {
            $student = Student::create(['code' => 'HS-NN-'.$index, 'name' => 'Học sinh GVNN '.$index, 'phone' => '090123456'.$index,
                'branch_id' => $this->branch->id, 'status' => 'studying', 'current_class_id' => $class->id]);
            ClassEnrollment::create(['student_id' => $student->id, 'class_id' => $class->id, 'enrolled_at' => '2026-08-20', 'status' => 'active']);
        }
        $period = $this->period();
        $period->calculatePayrollForPeriod();
        $record = $this->record($period);
        $this->assertSame(PayrollRecord::KPI_RETENTION, $record->kpi_source);
        $this->assertSame(2, $record->retention_students);
        $this->assertSame(['pending', 'Chưa chốt KPI'], $record->kpi_state);
        $this->actingAs($this->admin)->post(route('payroll.records.adjust', $record->id), ['retention_tier' => 15000])->assertSessionHasNoErrors();
        $this->assertEquals(30000, $record->fresh()->kpi_bonus);
        $this->assertSame('done', $record->fresh()->kpi_state[0]);
        $period->calculatePayrollForPeriod();
        $this->assertEquals(30000, $this->record($period)->kpi_bonus);
    }

    public function test_foreign_session_count_follows_session_assignment_and_legacy_date_fallback(): void
    {
        $vn = $this->staff(Roles::TEACHER_PARTTIME, 'Giáo viên Việt Nam');
        $other = $this->staff(Roles::FOREIGN_TEACHER, 'GVNN thay ca');
        $this->rate($vn, 300000, unit: TeacherHourlyRate::UNIT_SESSION);
        foreach (['2026-09-05' => $other->id, '2026-09-12' => null, '2026-09-19' => $other->id, '2026-09-26' => $other->id] as $date => $foreignId) {
            $session = ClassSession::create(['class_id' => $this->class->id, 'branch_id' => $this->branch->id, 'date' => $date,
                'start_time' => '18:00', 'end_time' => '19:30', 'teacher_id' => $vn->id, 'foreign_teacher_id' => $foreignId, 'status' => 'scheduled']);
            $this->teach($vn, $date, ['class_session_id' => $date === '2026-09-19' ? null : $session->id]);
            $this->teach($other, $date, ['status' => $date === '2026-09-26' ? 'invalid' : 'valid']);
        }
        // Không đếm 12/09 (buổi không có GVNN) hoặc 26/09 (GVNN không có chấm công hợp lệ).
        $period = $this->period();
        $period->calculatePayrollForPeriod();
        $this->assertSame(2, $this->record($period, $vn)->foreign_teacher_sessions_count);
        $this->assertSame(0, $this->record($period, $other)->foreign_teacher_sessions_count);
        $this->assertEquals(0, $this->record($period, $vn)->foreign_session_pay);
        // Bỏ mặc định lớp: phân công theo buổi vẫn giữ nguyên kết quả.
        $this->class->update(['foreign_teacher_id' => null]);
        $period->calculatePayrollForPeriod();
        $this->assertSame(2, $this->record($period, $vn)->foreign_teacher_sessions_count);
        ClassSession::whereDate('date', '2026-09-12')->firstOrFail()->update(['foreign_teacher_id' => $other->id]);
        $period->calculatePayrollForPeriod();
        $this->assertSame(3, $this->record($period, $vn)->foreign_teacher_sessions_count);
    }

    public function test_rate_form_forces_minute_foreign_type_and_validates_minimum(): void
    {
        $payload = ['user_id' => $this->foreign->id, 'hourly_rate' => 7500, 'rate_unit' => 'hour',
            'teacher_type' => 'assistant', 'effective_from' => '2026-09-01'];
        $this->actingAs($this->admin)->post(route('payroll.config.teacher-rates.personal.store'), $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('teacher_hourly_rates', ['user_id' => $this->foreign->id, 'hourly_rate' => 7500, 'rate_unit' => 'minute', 'teacher_type' => 'foreign']);
        $this->post(route('payroll.config.teacher-rates.personal.store'), array_replace($payload, ['hourly_rate' => 99, 'effective_from' => '2026-09-02']))
            ->assertSessionHasErrors('hourly_rate');
        $this->post(route('payroll.config.teacher-rates.personal.store'), array_replace($payload, ['hourly_rate' => 100, 'effective_from' => '2026-09-02']))
            ->assertSessionHasNoErrors();
        $vn = $this->staff(Roles::TEACHER_PARTTIME, 'Giáo viên VN theo buổi');
        $this->post(route('payroll.config.teacher-rates.personal.store'), array_replace($payload, ['user_id' => $vn->id, 'rate_unit' => 'minute', 'teacher_type' => 'parttime']))
            ->assertSessionHasErrors('rate_unit');
    }

    public function test_foreign_teacher_own_payslip_displays_minute_outcome(): void
    {
        $this->rate($this->foreign);
        $this->teach($this->foreign, '2026-09-05');
        $period = $this->period();
        $period->calculatePayrollForPeriod();
        $period->update(['status' => PayrollPeriod::STATUS_PUBLISHED]);

        $this->actingAs($this->foreign)->get(route('portal.my-salary'))
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('timesheets.0.unit', 'minute')
            ->where('timesheets.0.minutes', 90)->where('timesheets.0.amount', 675000)
            ->where('record.earning_lines.0.quantity', '90 phút'));
    }

    public function test_period_filter_export_and_missing_staff_have_foreign_label(): void
    {
        $this->teach($this->foreign, '2026-09-05');
        $vn = $this->staff(Roles::TEACHER_PARTTIME, 'Giáo viên VN riêng');
        $this->teach($vn, '2026-09-05');
        $missing = $this->staff(Roles::FOREIGN_TEACHER, 'GVNN chưa dạy');
        $missing->forceFill(['created_at' => '2026-08-01'])->save();
        $period = $this->period();
        $period->calculatePayrollForPeriod();
        $this->assertEquals(0, $this->record($period)->teaching_salary);
        $this->assertTrue($this->record($period)->foreignRateMissing());
        $this->actingAs($this->admin)->get(route('payroll.periods.show', ['id' => $period->id, 'type' => Roles::FOREIGN_TEACHER]))
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('records.data', 1)
            ->where('records.data.0.salary_role_label', 'GVNN')->where('stats.records', 1)
            ->where('missingStaff.items.0.reason', 'GVNN: chưa có buổi dạy hợp lệ trong kỳ'));
        foreach ([['type' => Roles::FOREIGN_TEACHER], ['department' => 'foreign']] as $filter) {
            $csv = $this->get(route('payroll.periods.export', ['id' => $period->id, 'format' => 'csv'] + $filter))->assertOk()->streamedContent();
            $this->assertStringContainsString('GVNN', $csv);
            $this->assertStringContainsString($this->foreign->name, $csv);
            $this->assertStringNotContainsString($vn->name, $csv);
        }
    }
}
