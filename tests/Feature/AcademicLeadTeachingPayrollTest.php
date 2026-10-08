<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\Course;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\TeacherHourlyRate;
use App\Models\TeacherTimesheet;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Học thuật kiêm nhiệm giảng dạy: option trên hồ sơ, lương đứng lớp = buổi dạy hợp lệ × % học phí theo buổi của HS trong lớp,
 * KPI kiêm nhiệm = HS giữ được × bậc. Không kiêm nhiệm → phiếu Học thuật như cũ.
 */
class AcademicLeadTeachingPayrollTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $admin;

    private User $lead;

    private ClassModel $class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Cơ sở 1', 'code' => 'CS1', 'is_active' => true]);
        $this->admin = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $this->admin->assignRole('admin');

        $this->lead = User::factory()->create([
            'branch_id' => $this->branch->id, 'is_active' => true, 'name' => 'Học thuật Lan',
            'base_salary' => 15000000, 'academic_teaching' => true,
        ]);
        $this->lead->assignRole('academic_lead');

        // Khóa 12 buổi, 1.600.000đ → giá niêm yết 133.333,33đ/buổi.
        $course = Course::create(['code' => 'CLC', 'name' => 'Lớp CLC', 'tuition_fee' => 1600000, 'total_lessons' => 12, 'is_active' => true]);
        $this->class = ClassModel::create([
            'code' => '9CLC', 'name' => 'Lớp 9CLC', 'course_id' => $course->id, 'branch_id' => $this->branch->id,
            'teacher_id' => $this->lead->id, 'status' => 'active',
        ]);

        // HS1 đủ học phí, HS2 giảm 400k (100.000đ/buổi), HS3 chưa có khoản học phí (giá niêm yết), HS4 vào lớp ngày 15/09.
        $this->student('HS1', '2026-08-20', 1600000, 0);
        $this->student('HS2', '2026-08-20', 1600000, 400000);
        $this->student('HS3', '2026-08-20', null, 0);
        $this->student('HS4', '2026-09-15', 1600000, 0);
    }

    private function student(string $code, string $enrolledAt, ?float $total, float $discount): Student
    {
        $student = Student::create(['code' => $code, 'name' => 'Học sinh '.$code, 'phone' => '0900'.str_pad((string) crc32($code) % 1000000, 6, '0', STR_PAD_LEFT), 'branch_id' => $this->branch->id, 'status' => 'studying', 'current_class_id' => $this->class->id]);
        ClassEnrollment::create(['student_id' => $student->id, 'class_id' => $this->class->id, 'enrolled_at' => $enrolledAt, 'status' => 'completed']);
        if ($total !== null) {
            StudentTuition::create([
                'student_id' => $student->id, 'class_id' => $this->class->id, 'branch_id' => $this->branch->id,
                'total_amount' => $total, 'discount_amount' => $discount, 'session_count' => 12,
                'final_amount' => $total - $discount, 'paid_amount' => $total - $discount, 'debt_amount' => 0, 'status' => 'paid',
            ]);
        }

        return $student;
    }

    private function teach(string $date, array $extra = []): TeacherTimesheet
    {
        return TeacherTimesheet::create([
            'user_id' => $this->lead->id, 'class_id' => $this->class->id, 'teaching_date' => $date,
            'scheduled_time' => '18:00-19:30', 'hours' => 1.5, 'type' => 'regular', 'status' => 'valid',
        ] + $extra);
    }

    private function calculate(): PayrollRecord
    {
        $period = PayrollPeriod::create([
            'code' => 'PR-2026-09', 'title' => 'Bảng lương Tháng 9/2026', 'month' => 9, 'year' => 2026,
            'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'status' => 'draft',
        ]);
        $period->calculatePayrollForPeriod();

        return PayrollRecord::where('payroll_period_id', $period->id)->where('user_id', $this->lead->id)->firstOrFail();
    }

    public function test_teaching_share_is_percent_of_each_students_session_tuition(): void
    {
        $this->teach('2026-09-05');
        $this->teach('2026-09-10');
        $this->teach('2026-09-20');
        // Kèm 1-1 không có học phí lớp → không tính % học phí.
        TeacherTimesheet::create(['user_id' => $this->lead->id, 'teaching_date' => '2026-09-12', 'hours' => 1, 'type' => '1on1', 'status' => 'valid']);

        $record = $this->calculate();

        // Buổi 05 và 10: HS1 + HS2 + HS3 = 133.333,33 + 100.000 + 133.333,33 = 366.666,66 × 40% = 146.666,66.
        // Buổi 20: thêm HS4 → 499.999,99 × 40% = 200.000. Tổng 493.333.
        $this->assertTrue($record->teaching_concurrent);
        $this->assertSame(PayrollRecord::TYPE_FULLTIME, $record->employee_type);
        $this->assertEquals(493333, (float) $record->teaching_salary);
        $this->assertSame(3, $record->teaching_sessions);
        $this->assertEquals(15000000, (float) $record->base_salary);

        $row = $record->calculation_details['teaching_share']['classes'][0];
        $this->assertSame(4, $row['students']);
        $this->assertSame(1, $row['partial_students']);
        $this->assertSame(3, $row['sessions']);
        $this->assertEquals(40, $row['percent']);
        $this->assertSame(1, $record->calculation_details['teaching_share']['skipped']);

        // BHXH / Công đoàn chỉ trên lương cơ bản; lương đứng lớp cộng vào thực lĩnh.
        $this->assertEquals(round(15000000 * 0.105), (float) $record->insurance_deduction);
        $this->assertEquals(15000000 + 493333 - round(15000000 * 0.105) - round(15000000 * 0.005), (float) $record->net_salary);

        $keys = collect($record->earningLines())->pluck('key')->all();
        $this->assertContains('teaching_salary', $keys);
        $this->assertContains('teaching_kpi_bonus', $keys);
    }

    public function test_personal_tuition_percent_and_late_rules_apply_like_parttime(): void
    {
        TeacherHourlyRate::create(['user_id' => $this->lead->id, 'hourly_rate' => 50, 'rate_unit' => TeacherHourlyRate::UNIT_TUITION, 'teacher_type' => 'academic', 'effective_from' => '2026-09-15']);
        $this->teach('2026-09-05');
        // Đi muộn 20 phút không báo trước → không tính buổi.
        $this->teach('2026-09-10', ['late_minutes' => 20]);
        $this->teach('2026-09-20');

        $record = $this->calculate();

        // 05/09: 40% mặc định = 146.666,66; 20/09: 50% riêng × 499.999,99 = 250.000.
        $this->assertEquals(396667, (float) $record->teaching_salary);
        $this->assertSame(2, $record->teaching_sessions);
        $this->assertSame('void', $record->calculation_details['late']['lines'][0]['rule']);
        $this->assertNull($record->calculation_details['teaching_share']['classes'][0]['percent'], 'Hai mức % trong kỳ');
    }

    public function test_retention_kpi_for_teaching_is_chosen_on_payslip(): void
    {
        $this->teach('2026-09-05');
        $record = $this->calculate();

        // HS đầu kỳ (xếp lớp trước 01/09): HS1–HS3, không ai nghỉ.
        $this->assertSame(3, $record->retention_base_students);
        $this->assertSame(3, $record->retention_students);
        $this->assertSame('pending', $record->kpi_state[0]);

        $this->actingAs($this->admin)->post(route('payroll.records.adjust', $record->id), [
            'retention_tier' => 20000, 'kpi_manual_amount' => 500000,
        ])->assertSessionHasNoErrors();

        $record->refresh();
        $this->assertEquals(60000, (float) $record->teaching_kpi_bonus);
        $this->assertEquals(500000, (float) $record->kpi_bonus);
        $this->assertSame('done', $record->kpi_state[0]);

        // Tính lại giữ bậc đã chọn.
        $record->period->calculatePayrollForPeriod();
        $this->assertEquals(60000, (float) $record->fresh()->teaching_kpi_bonus);
    }

    public function test_without_option_academic_lead_payslip_is_unchanged(): void
    {
        $this->lead->update(['academic_teaching' => false]);
        $this->teach('2026-09-05');

        $record = $this->calculate();

        $this->assertFalse($record->teaching_concurrent);
        $this->assertEquals(0, (float) $record->teaching_salary);
        $this->assertEquals(0, (float) $record->teaching_kpi_bonus);
        $this->assertArrayNotHasKey('teaching_share', $record->calculation_details);
        $this->assertNotContains('teaching_salary', collect($record->earningLines())->pluck('key')->all());
    }

    public function test_user_form_saves_option_only_for_academic_lead(): void
    {
        $payload = fn (string $role, ?string $teaching) => array_filter([
            'name' => $this->lead->name, 'email' => $this->lead->email, 'branch_id' => $this->branch->id,
            'role' => $role, 'academic_teaching' => $teaching,
        ], fn ($v) => $v !== null);

        $this->lead->update(['academic_teaching' => false]);
        $this->actingAs($this->admin)->put(route('users.update', $this->lead->id), $payload('academic_lead', '1'))->assertSessionHasNoErrors();
        $this->assertTrue($this->lead->fresh()->academic_teaching);

        // Đổi sang vai trò khác → tắt option.
        $this->actingAs($this->admin)->put(route('users.update', $this->lead->id), $payload('teacher_fulltime', '1'))->assertSessionHasNoErrors();
        $this->assertFalse($this->lead->fresh()->academic_teaching);
    }

    public function test_tuition_percent_rate_can_be_configured(): void
    {
        $this->actingAs($this->admin)->post(route('payroll.config.teacher-rates.personal.store'), [
            'user_id' => $this->lead->id, 'hourly_rate' => 40, 'rate_unit' => 'tuition', 'teacher_type' => 'academic', 'effective_from' => '2026-09-01',
        ])->assertSessionHasNoErrors();

        $this->assertEquals(40.0, TeacherHourlyRate::tuitionPercentFor($this->lead->id, '2026-09-10'));
        // % học phí không bị hiểu nhầm là đơn giá giờ.
        $this->assertNull(TeacherHourlyRate::rateFor($this->lead->id, '2026-09-10'));

        $this->actingAs($this->admin)->post(route('payroll.config.teacher-rates.personal.store'), [
            'user_id' => $this->lead->id, 'hourly_rate' => 140, 'rate_unit' => 'tuition', 'effective_from' => '2026-09-02',
        ])->assertSessionHasErrors('hourly_rate');
    }
}
