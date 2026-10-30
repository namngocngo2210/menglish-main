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
use App\Models\TuitionRefundRequest;
use App\Models\User;
use App\Services\Kpi\KpiSheetService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\AttendsWorkdays;
use Tests\TestCase;

/**
 * Lương đứng lớp Học thuật: ca lớp hợp lệ × % giá niêm yết theo buổi của HS giữ cuối kỳ, kể cả khi chưa bật cờ.
 * KPI giữ HS trên phiếu lương giữ cách tính cũ; phiếu KPI giảng dạy riêng vẫn phụ thuộc cờ kiêm nhiệm.
 */
class AcademicLeadTeachingPayrollTest extends TestCase
{
    use AttendsWorkdays;
    use RefreshDatabase;

    private Branch $branch;

    private User $admin;

    private User $lead;

    private ClassModel $class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 9, 30)->setTime(12, 0));
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

        // HS1 đủ học phí, HS2 giảm 400k (vẫn tính giá niêm yết), HS3 chưa có khoản học phí (giá niêm yết), HS4 vào lớp ngày 15/09.
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
        return TeacherTimesheet::create(array_replace([
            'user_id' => $this->lead->id, 'class_id' => $this->class->id, 'teaching_date' => $date,
            'scheduled_time' => '18:00-19:30', 'hours' => 1.5, 'type' => 'regular', 'status' => 'valid',
        ], $extra));
    }

    private function calculate(): PayrollRecord
    {
        $period = PayrollPeriod::create([
            'code' => 'PR-2026-09', 'title' => 'Bảng lương Tháng 9/2026', 'month' => 9, 'year' => 2026,
            'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'status' => 'draft',
        ]);
        // Lương cơ bản trả theo ngày công (TK-0012): Học thuật đi làm đủ tháng.
        $this->attendFullMonth($this->lead, 9);
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

        // Buổi 05 và 10 có 3 HS; buổi 20 có 4 HS. Ưu đãi HS2 không giảm giá tính lương.
        // 40% × (3 + 3 + 4) × (1.600.000 ÷ 12) = 533.333đ sau làm tròn.
        $this->assertTrue($record->teaching_concurrent);
        $this->assertSame(PayrollRecord::TYPE_FULLTIME, $record->employee_type);
        $this->assertEquals(533333, (float) $record->teaching_salary);
        $this->assertSame(3, $record->teaching_sessions);
        $this->assertEquals(15000000, (float) $record->base_salary);

        $row = $record->calculation_details['teaching_share']['classes'][0];
        $this->assertSame(4, $row['students']);
        $this->assertEqualsWithDelta(1600000 / 12 * 4, $row['session_value'], 0.01);
        $this->assertSame(3, $row['sessions']);
        $this->assertEquals(40, $row['percent']);
        $this->assertSame(1, $record->calculation_details['teaching_share']['skipped']);

        // BHXH / Công đoàn chỉ trên lương cơ bản; lương đứng lớp cộng vào thực lĩnh.
        // BHXH / Công đoàn trên mức lương đóng BHXH 5.400.000 (TK-0009), đủ công.
        $this->assertEquals(567000, (float) $record->insurance_deduction);
        $this->assertEquals(15000000 + 533333 - 567000 - 27000, (float) $record->net_salary);

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
        $this->teach('2026-09-11', ['early_leave_minutes' => 20]);
        $this->teach('2026-09-20');

        $record = $this->calculate();

        // 05/09: 40% × 400.000 = 160.000; 20/09: 50% × 533.333,33 = 266.666,67.
        $this->assertEquals(426667, (float) $record->teaching_salary);
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

    public function test_without_option_or_timesheets_academic_lead_payslip_is_unchanged(): void
    {
        $this->lead->update(['academic_teaching' => false]);

        $record = $this->calculate();

        $this->assertFalse($record->teaching_concurrent);
        $this->assertEquals(0, (float) $record->teaching_salary);
        $this->assertEquals(0, (float) $record->teaching_kpi_bonus);
        $this->assertArrayNotHasKey('teaching_share', $record->calculation_details);
        $this->assertNotContains('teaching_salary', collect($record->earningLines())->pluck('key')->all());
    }

    public function test_worked_example_ten_students_eight_sessions_pays_sixteen_million(): void
    {
        $this->class = $this->class->replicate();
        $this->class->fill(['code' => 'VD10', 'name' => 'Lớp ví dụ 10 HS'])->save();
        for ($i = 1; $i <= 10; $i++) {
            $student = $this->student('VD'.$i, '2026-08-20', 12000000, 0);
            StudentTuition::where('student_id', $student->id)->update(['session_count' => 24]);
        }
        foreach ([3, 5, 8, 10, 15, 17, 22, 24] as $day) {
            $this->teach('2026-09-'.str_pad((string) $day, 2, '0', STR_PAD_LEFT));
        }
        $record = $this->calculate();
        $this->assertEquals(16000000, (float) $record->teaching_salary);
        $row = $record->calculation_details['teaching_share']['classes'][0];
        $this->assertSame(8, $row['sessions']);
        $this->assertSame(10, $row['students']);
        $this->assertEquals(5000000, $row['session_value']);
        $this->assertEquals(40000000, $row['tuition']);
        $this->assertEquals(16000000, $row['amount']);
        $this->actingAs($this->admin)->get(route('payroll.records.show', $record->id))->assertOk()
            ->assertSee('HS còn giữ cuối tháng')->assertSee('Giá trị 1 buổi')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('teachingShare.classes.0.session_value', 5000000));
    }

    public function test_reserved_and_dropped_students_at_period_end_never_contribute(): void
    {
        $students = Student::orderBy('id')->get();
        // Bảo lưu từ đúng ngày cuối kỳ; trạng thái vẫn đang học để kiểm tra riêng đơn đã duyệt.
        $this->defer($students[0], '2026-09-30', '2026-10-10');
        $students[1]->update(['status' => 'deferred']);
        $this->travelTo(now()->setDate(2026, 9, 18));
        $students[2]->update(['status' => Student::STATUS_DROPPED]);
        $this->teach('2026-09-05');
        $this->teach('2026-09-20');
        $record = $this->calculate();
        // Chỉ HS4 còn giữ; ngày 05 chưa vào lớp nên chỉ tính ngày 20.
        $this->assertEquals(53333, (float) $record->teaching_salary);
        $row = $record->calculation_details['teaching_share']['classes'][0];
        $this->assertSame(1, $row['students']);
        $this->assertSame(2, $row['sessions']);
    }

    public function test_enrollment_drop_or_transfer_by_period_end_excludes_all_sessions(): void
    {
        $students = Student::orderBy('id')->get();
        ClassEnrollment::where('student_id', $students[0]->id)->update(['status' => Student::ENROLLMENT_DROPPED, 'left_at' => '2026-09-30']);
        ClassEnrollment::where('student_id', $students[1]->id)->update(['status' => Student::ENROLLMENT_TRANSFERRED, 'left_at' => '2026-09-20']);
        // Dữ liệu cũ không có left_at: lấy ngày đổi trạng thái.
        ClassEnrollment::where('student_id', $students[2]->id)->update(['status' => Student::ENROLLMENT_DROPPED, 'updated_at' => '2026-09-20 12:00:00']);
        $this->teach('2026-09-05');
        $this->assertEquals(0, (float) $this->calculate()->teaching_salary);
    }

    public function test_kept_students_still_obey_join_dates_and_daily_deferral_windows(): void
    {
        $student = Student::where('code', 'HS1')->firstOrFail();
        $this->defer($student, '2026-09-05', '2026-09-10');
        $this->teach('2026-09-05');
        $this->teach('2026-09-10');
        $this->teach('2026-09-20');
        $record = $this->calculate();
        $this->assertEquals(426667, (float) $record->teaching_salary);
        $this->assertSame(4, $record->calculation_details['teaching_share']['classes'][0]['students']);
    }

    public function test_listed_tuition_ignores_discount_debt_and_payment_status(): void
    {
        StudentTuition::query()->update(['total_amount' => 12000000, 'discount_amount' => 6000000,
            'session_count' => 24, 'final_amount' => 6000000, 'paid_amount' => 0, 'debt_amount' => 6000000, 'status' => 'unpaid']);
        $this->teach('2026-09-05');
        // 2 khoản nợ: mỗi khoản vẫn 500.000đ/buổi; HS3 dùng giá lớp 133.333,33đ.
        $this->assertEquals(453333, (float) $this->calculate()->teaching_salary);
    }

    public function test_substitute_session_in_another_teachers_class_is_paid(): void
    {
        $other = $this->class->replicate();
        $other->fill(['code' => 'THAY', 'teacher_id' => $this->admin->id])->save();
        $student = $this->student('THAY1', '2026-08-20', 12000000, 0);
        $student->update(['current_class_id' => $other->id]);
        ClassEnrollment::where('student_id', $student->id)->update(['class_id' => $other->id]);
        StudentTuition::where('student_id', $student->id)->update(['class_id' => $other->id, 'session_count' => 24]);
        $this->teach('2026-09-05', ['class_id' => $other->id, 'type' => 'sub']);
        $record = $this->calculate();
        $this->assertEquals(200000, (float) $record->teaching_salary);
        $this->assertSame($other->id, $record->calculation_details['teaching_share']['classes'][0]['class_id']);
    }

    public function test_unflagged_academic_lead_with_valid_timesheets_receives_teaching_pay_and_retention(): void
    {
        $this->lead->update(['academic_teaching' => false]);
        $this->teach('2026-09-05');
        $record = $this->calculate();
        $this->assertTrue($record->teaching_concurrent);
        $this->assertEquals(160000, (float) $record->teaching_salary);
        $this->assertSame(3, $record->retention_students);
        $this->assertSame(['main'], KpiSheetService::tracksFor($this->lead->fresh()));
    }

    public function test_flagged_lead_without_timesheets_keeps_zero_teaching_record(): void
    {
        $record = $this->calculate();
        $this->assertTrue($record->teaching_concurrent);
        $this->assertEquals(0, (float) $record->teaching_salary);
        $this->assertSame([], $record->calculation_details['teaching_share']['classes']);
    }

    public function test_unflagged_lead_with_only_invalid_or_non_class_timesheets_keeps_old_payslip(): void
    {
        $this->lead->update(['academic_teaching' => false]);
        $this->teach('2026-09-05', ['status' => 'invalid']);
        $this->teach('2026-09-10', ['status' => 'pending_review']);
        $this->teach('2026-09-12', ['type' => '1on1']);
        $this->teach('2026-09-15', ['class_id' => null]);
        $this->teach('2026-08-31');
        $this->assertFalse($this->calculate()->teaching_concurrent);
    }

    public function test_newest_class_linked_tuition_wins_over_newer_unlinked_tuition(): void
    {
        $student = Student::where('code', 'HS1')->firstOrFail();
        $tuition = StudentTuition::where('student_id', $student->id)->firstOrFail()->replicate();
        $tuition->fill(['total_amount' => 12000000, 'session_count' => 24])->save();
        $newer = $tuition->replicate();
        $newer->fill(['class_id' => null, 'total_amount' => 24000000])->save();
        $this->teach('2026-09-05');
        // HS1 lấy khoản gắn lớp mới nhất 500.000đ, HS2 + HS3 mỗi người 133.333,33đ.
        $this->assertEquals(306667, (float) $this->calculate()->teaching_salary);
    }

    public function test_missing_linked_tuition_uses_newest_positive_session_tuition_then_class_price(): void
    {
        $student = Student::where('code', 'HS1')->firstOrFail();
        $tuition = StudentTuition::where('student_id', $student->id)->firstOrFail();
        $tuition->update(['class_id' => null]);
        $newer = $tuition->replicate();
        $newer->fill(['total_amount' => 12000000, 'session_count' => 24])->save();
        $invalid = $newer->replicate();
        $invalid->fill(['total_amount' => 24000000])->save();
        // Dữ liệu cũ: bỏ qua hook mặc định số buổi khi lưu model.
        StudentTuition::whereKey($invalid->id)->update(['session_count' => 0]);
        $this->teach('2026-09-05');
        // Bỏ khoản 0 buổi; HS1 dùng khoản gần nhất 500.000đ, HS3 chưa có học phí dùng giá lớp.
        $this->assertEquals(306667, (float) $this->calculate()->teaching_salary);
    }

    public function test_legacy_members_and_future_enrollment_boundaries(): void
    {
        $legacy = $this->student('CU', '2026-08-20', 1200000, 0);
        ClassEnrollment::where('student_id', $legacy->id)->delete();
        $legacy->forceFill(['created_at' => '2026-08-20 10:00:00'])->save();
        $future = $this->student('SAU', '2026-10-01', 12000000, 0);
        // Không tính HS vào sau cuối kỳ, kể cả current_class_id đã trỏ tới lớp.
        $this->assertNotNull($future->current_class_id);
        ClassEnrollment::where('student_id', Student::where('code', 'HS1')->value('id'))
            ->update(['status' => Student::ENROLLMENT_TRANSFERRED, 'left_at' => '2026-10-01']);
        $this->teach('2026-09-05');
        $record = $this->calculate();
        // HS1 rời sau cuối kỳ vẫn tính; thêm hồ sơ cũ 100.000đ/buổi, HS4 chưa vào ngày 05.
        $this->assertEquals(200000, (float) $record->teaching_salary);
        $this->assertSame(5, $record->calculation_details['teaching_share']['classes'][0]['students']);
    }

    public function test_personal_percent_replaces_the_default_for_every_eligible_session(): void
    {
        TeacherHourlyRate::create(['user_id' => $this->lead->id, 'hourly_rate' => 50,
            'rate_unit' => TeacherHourlyRate::UNIT_TUITION, 'teacher_type' => 'academic', 'effective_from' => '2026-09-01']);
        $this->teach('2026-09-05');
        $record = $this->calculate();
        $this->assertEquals(200000, (float) $record->teaching_salary);
        $this->assertEquals(50, $record->calculation_details['teaching_share']['classes'][0]['percent']);
    }

    public function test_unapproved_deferral_does_not_exclude_a_student(): void
    {
        $student = Student::where('code', 'HS1')->firstOrFail();
        $this->defer($student, '2026-09-01', '2026-09-30');
        TuitionRefundRequest::where('student_id', $student->id)->update(['status' => 'pending']);
        $this->teach('2026-09-05');
        $this->assertEquals(160000, (float) $this->calculate()->teaching_salary);
    }

    public function test_student_dropped_after_period_end_still_contributes_to_that_period(): void
    {
        $student = Student::where('code', 'HS1')->firstOrFail();
        $this->travelTo(now()->setDate(2026, 10, 3));
        $student->update(['status' => Student::STATUS_DROPPED]);
        $this->teach('2026-09-05');
        $this->assertEquals(160000, (float) $this->calculate()->teaching_salary);
    }

    public function test_historical_deferred_status_is_excluded_even_after_student_resumes(): void
    {
        $student = Student::where('code', 'HS1')->firstOrFail();
        $student->update(['status' => 'deferred']);
        $this->travelTo(now()->setDate(2026, 10, 3));
        $student->update(['status' => 'studying']);
        $this->teach('2026-09-05');
        $this->assertEquals(106667, (float) $this->calculate()->teaching_salary);
    }

    private function defer(Student $student, string $from, string $to): void
    {
        TuitionRefundRequest::create(['student_id' => $student->id, 'type' => TuitionRefundRequest::TYPE_DEFERRAL,
            'status' => 'approved', 'defer_from' => $from, 'defer_to' => $to,
            'requester_id' => $this->admin->id, 'reason' => 'Bảo lưu để kiểm tra lương đứng lớp']);
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
        $this->lead->update(['academic_teaching' => false]);
        $this->assertSame('academic', TeacherHourlyRate::defaultTeacherType($this->lead));
        $this->actingAs($this->admin)->get(route('payroll.config.teacher-rates', ['teacher_id' => $this->lead->id]))
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('selectedType', 'academic'));
        $this->actingAs($this->admin)->post(route('payroll.config.teacher-rates.personal.store'), [
            'user_id' => $this->lead->id, 'hourly_rate' => 40, 'rate_unit' => 'tuition', 'effective_from' => '2026-09-01',
        ])->assertSessionHasNoErrors();

        $this->assertSame('academic', TeacherHourlyRate::where('user_id', $this->lead->id)->firstOrFail()->teacher_type);
        $this->assertEquals(40.0, TeacherHourlyRate::tuitionPercentFor($this->lead->id, '2026-09-10'));
        // % học phí không bị hiểu nhầm là đơn giá giờ.
        $this->assertNull(TeacherHourlyRate::rateFor($this->lead->id, '2026-09-10'));

        $this->actingAs($this->admin)->post(route('payroll.config.teacher-rates.personal.store'), [
            'user_id' => $this->lead->id, 'hourly_rate' => 140, 'rate_unit' => 'tuition', 'effective_from' => '2026-09-02',
        ])->assertSessionHasErrors('hourly_rate');
    }
}
