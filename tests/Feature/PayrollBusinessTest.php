<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\CommissionTier;
use App\Models\Course;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\Penalty;
use App\Models\TeacherTimesheet;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollBusinessTest extends TestCase
{
    use RefreshDatabase;

    private User $hrManager;

    private User $teacherUser;

    /** Chỉ Admin được tạo/duyệt/chi trả kỳ lương (flow §15). */
    private User $payrollAdmin;

    private Branch $branch;

    private ClassModel $classModel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create([
            'name' => 'Chi nhánh Ba Đình',
            'code' => 'BD',
            'address' => 'Số 100 Liễu Giai, Ba Đình, Hà Nội',
            'phone' => '02488887777',
            'is_active' => true,
        ]);

        $this->hrManager = User::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Trưởng phòng Nhân sự MEnglish',
            'is_active' => true,
        ]);
        $this->hrManager->assignRole('manager');

        $this->payrollAdmin = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $this->payrollAdmin->assignRole('admin');

        $this->teacherUser = User::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Giáo viên IELTS Senior',
            'is_active' => true,
        ]);
        $this->teacherUser->assignRole('teacher');

        $course = Course::create([
            'code' => 'IELTS-INT',
            'name' => 'IELTS Intensive',
            'tuition_fee' => 12000000,
            'duration_months' => 3,
            'is_active' => true,
        ]);

        $this->classModel = ClassModel::create([
            'code' => 'IE-BD-01',
            'name' => 'Lớp IELTS Intensive BD01',
            'course_id' => $course->id,
            'branch_id' => $this->branch->id,
            'teacher_id' => $this->teacherUser->id,
            'status' => 'active',
        ]);
    }

    // =========================================================================
    // a. Teacher rates and commission tier config
    // =========================================================================

    public function test_can_create_and_configure_teacher_rates(): void
    {
        $payload = [
            'rank_title' => 'Senior Teacher Band 8.0+',
            'criteria' => 'IELTS >= 8.0, 3+ năm kinh nghiệm giảng dạy',
            'communication_rate' => 350000,
            'ielts_rate' => 500000,
        ];

        $response = $this->actingAs($this->hrManager)->post(route('payroll.config.teacher-rates.store'), $payload);
        $response->assertRedirect();

        $this->assertDatabaseHas('teacher_rates', [
            'rank_title' => 'Senior Teacher Band 8.0+',
            'criteria' => 'IELTS >= 8.0, 3+ năm kinh nghiệm giảng dạy',
            'communication_rate' => 350000,
            'ielts_rate' => 500000,
        ]);
    }

    public function test_can_create_and_configure_commission_tiers(): void
    {
        $payload = [
            'tier_name' => 'Diamond Tối Thượng (>= 150M)',
            'min_revenue' => 150000000,
            'new_sale_percent' => 8.5,
            'renew_percent' => 4.0,
        ];

        $response = $this->actingAs($this->hrManager)->post(route('payroll.config.commission-tiers.store'), $payload);
        $response->assertRedirect();

        $this->assertDatabaseHas('commission_tiers', [
            'tier_name' => 'Diamond Tối Thượng (>= 150M)',
            'min_revenue' => 150000000,
            'new_sale_percent' => 8.5,
            'renew_percent' => 4.0,
        ]);
    }

    public function test_rate_and_commission_validation_failures(): void
    {
        // Teacher rate missing required fields
        $responseRate = $this->actingAs($this->hrManager)->post(route('payroll.config.teacher-rates.store'), [
            'rank_title' => '',
            'communication_rate' => '',
            'ielts_rate' => '',
        ]);
        $responseRate->assertSessionHasErrors(['rank_title', 'communication_rate', 'ielts_rate']);

        // Commission tier missing required fields
        $responseComm = $this->actingAs($this->hrManager)->post(route('payroll.config.commission-tiers.store'), [
            'tier_name' => '',
            'min_revenue' => 'invalid_number',
            'new_sale_percent' => '',
            'renew_percent' => '',
        ]);
        $responseComm->assertSessionHasErrors(['tier_name', 'min_revenue', 'new_sale_percent', 'renew_percent']);
    }

    public function test_can_update_and_delete_commission_tier_and_preserve_past_settled_records(): void
    {
        $tier = CommissionTier::create([
            'tier_name' => 'Bậc Vàng Cũ',
            'min_revenue' => 50000000,
            'new_sale_percent' => 5.0,
            'renew_percent' => 3.0,
            'bonus_amount' => 1000000,
        ]);

        // 1. Create a closed/approved payroll record with the old commission
        $period = PayrollPeriod::create([
            'title' => 'Kỳ tính lương T01/2026',
            'code' => 'KY-01-PAST',
            'month' => 1,
            'year' => 2026,
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-31',
            'status' => 'approved',
        ]);

        $pastRecord = PayrollRecord::create([
            'payroll_period_id' => $period->id,
            'user_id' => $this->teacherUser->id,
            'renew_bonus' => 2500000,
            'net_salary' => 15000000,
        ]);

        // 2. Update commission tier with new higher percentage
        $updateResponse = $this->actingAs($this->hrManager)->put(route('payroll.config.commission-tiers.update', $tier), [
            'tier_name' => 'Bậc Vàng Mới (Điều chỉnh tăng %)',
            'min_revenue' => 60000000,
            'new_sale_percent' => 8.0,
            'renew_percent' => 5.0,
            'bonus_amount' => 2000000,
        ]);

        $updateResponse->assertRedirect();
        $this->assertDatabaseHas('commission_tiers', [
            'id' => $tier->id,
            'tier_name' => 'Bậc Vàng Mới (Điều chỉnh tăng %)',
            'new_sale_percent' => 8.0,
            'renew_percent' => 5.0,
        ]);

        // 3. Verify past record is NEVER touched or modified
        $this->assertEquals(2500000, $pastRecord->fresh()->renew_bonus);
        $this->assertEquals(15000000, $pastRecord->fresh()->net_salary);

        // 4. Delete commission tier
        $deleteResponse = $this->actingAs($this->hrManager)->delete(route('payroll.config.commission-tiers.destroy', $tier));
        $deleteResponse->assertRedirect();
        $this->assertDatabaseMissing('commission_tiers', ['id' => $tier->id]);
    }

    // =========================================================================
    // b. Payroll period creation, approval, and department breakdown
    // =========================================================================

    public function test_can_create_and_approve_payroll_period(): void
    {
        $payload = [
            'month' => 8,
            'year' => 2026,
        ];

        $response = $this->actingAs($this->payrollAdmin)->post(route('payroll.periods.store'), $payload);

        $period = PayrollPeriod::where('month', 8)->where('year', 2026)->first();
        $this->assertNotNull($period);

        $response->assertRedirect(route('payroll.periods.show', $period->id));

        $this->assertEquals('PR-2026-08', $period->code);
        $this->assertEquals('Bảng lương Tháng 8/2026', $period->title);
        $this->assertEquals('2026-08-01', $period->start_date->format('Y-m-d'));
        $this->assertEquals('2026-08-31', $period->end_date->format('Y-m-d'));
        $this->assertEquals('reviewing', $period->status);

        // Approve period
        $responseApprove = $this->actingAs($this->payrollAdmin)->post(route('payroll.periods.approve', $period->id));
        $responseApprove->assertRedirect();

        $period->refresh();
        $this->assertEquals('approved', $period->status);
    }

    public function test_can_view_department_breakdown_and_personal_salary_portal(): void
    {
        $period = PayrollPeriod::create([
            'code' => 'PR-2026-09',
            'title' => 'Bảng lương Tháng 9/2026',
            'month' => 9,
            'year' => 2026,
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-30',
            'status' => 'approved',
            'total_staff' => 3,
            'total_hours' => 150,
            'total_amount' => 60000000,
        ]);

        PayrollRecord::create([
            'payroll_period_id' => $period->id,
            'user_id' => $this->teacherUser->id,
            'department' => 'academic',
            'base_salary' => 15000000,
            'teaching_salary' => 8000000,
            'net_salary' => 23000000,
            'status' => 'approved',
        ]);

        $responseAcademic = $this->actingAs($this->hrManager)->get(route('payroll.periods.academic', $period->id));
        $responseAcademic->assertOk();
        $responseAcademic->assertSee($this->teacherUser->name);

        $responseFulltime = $this->actingAs($this->hrManager)->get(route('payroll.periods.fulltime', $period->id));
        $responseFulltime->assertOk();

        $responseOperations = $this->actingAs($this->hrManager)->get(route('payroll.periods.operations', $period->id));
        $responseOperations->assertOk();

        // Personal salary view
        $responseMySalary = $this->actingAs($this->teacherUser)->get(route('portal.my-salary'));
        $responseMySalary->assertOk();
        $responseMySalary->assertSee($this->teacherUser->name);
        $responseMySalary->assertSee('Lương Của Tôi');
    }

    // =========================================================================
    // c. Teacher timesheet logging and hourly pay computation
    // =========================================================================

    public function test_can_log_manual_teacher_timesheet_and_verify_pay_rate(): void
    {
        $payload = [
            'user_id' => $this->teacherUser->id,
            'class_id' => $this->classModel->id,
            'teaching_date' => '2026-08-15',
            'time_in' => '18:00',
            'time_out' => '20:30',
            'hourly_rate' => 450000,
            'type' => 'regular',
            'notes' => 'Ca dạy IELTS Writing Task 2 chuyên sâu',
        ];

        $response = $this->actingAs($this->hrManager)->post(route('payroll.timesheets.manual.store'), $payload);

        $response->assertRedirect(route('payroll.timesheets.teachers'));

        $this->assertDatabaseHas('teacher_timesheets', [
            'user_id' => $this->teacherUser->id,
            'class_id' => $this->classModel->id,
            'hours' => 2.5,
            'hourly_rate' => 450000,
            'type' => 'regular',
            'status' => 'pending_review',
            'notes' => 'Ca dạy IELTS Writing Task 2 chuyên sâu',
        ]);

        $timesheet = TeacherTimesheet::where('user_id', $this->teacherUser->id)->first();
        $this->assertNotNull($timesheet);
        $this->assertEquals(1125000, $timesheet->hours * $timesheet->hourly_rate);
    }

    public function test_timesheet_validation_fails_on_small_hours_or_low_rate(): void
    {
        $response = $this->actingAs($this->hrManager)->post(route('payroll.timesheets.manual.store'), [
            'user_id' => $this->teacherUser->id,
            'class_id' => $this->classModel->id,
            'teaching_date' => '2026-08-15',
            'time_in' => '18:00',
            'time_out' => '17:00', // giờ ra trước giờ vào
            'hourly_rate' => 500, // < 1000 min
            'type' => '',
        ]);

        // Phase 3: số giờ tính từ giờ vào/ra, lý do chấm tay bắt buộc
        $response->assertSessionHasErrors(['time_out', 'hourly_rate', 'type', 'notes']);
    }

    // =========================================================================
    // d. Penalty creation, confirmation, and cancellation
    // =========================================================================

    public function test_penalty_lifecycle_management(): void
    {
        // 1. Create penalty record
        $payload = [
            'user_id' => $this->teacherUser->id,
            'class_id' => $this->classModel->id,
            'violation_type' => 'Đi muộn ca dạy quá 15 phút không báo trước',
            'violation_date' => '2026-08-16',
            'amount' => 200000,
            'notes' => 'Biên bản lập do phụ huynh lớp phản ánh',
        ];

        $responseStore = $this->actingAs($this->hrManager)->post(route('penalties.store'), $payload);
        $responseStore->assertRedirect(route('penalties.index'));

        $this->assertDatabaseHas('penalties', [
            'user_id' => $this->teacherUser->id,
            'class_id' => $this->classModel->id,
            'violation_type' => 'Đi muộn ca dạy quá 15 phút không báo trước',
            'amount' => 200000,
            'reporter_id' => $this->hrManager->id,
            'status' => 'pending',
        ]);

        $penalty = Penalty::where('user_id', $this->teacherUser->id)->first();
        $this->assertNotNull($penalty);
        $this->assertStringStartsWith('BB-', $penalty->code);
        $this->assertEquals('Chờ giải trình', $penalty->status_label); // Phase 3: bước đầu là nhân sự giải trình
        $this->assertStringContainsString('bg-amber-50', $penalty->status_badge);

        // 2. Confirm penalty
        $responseConfirm = $this->actingAs($this->hrManager)->post(route('penalties.confirm', $penalty->id));
        $responseConfirm->assertRedirect();

        $penalty->refresh();
        $this->assertEquals('confirmed', $penalty->status);
        $this->assertEquals('Đã xác nhận lỗi', $penalty->status_label);
        $this->assertStringContainsString('bg-rose-50', $penalty->status_badge);

        // 3. Cancel another penalty
        $penalty2 = Penalty::create([
            'code' => 'BB-2026-999',
            'user_id' => $this->teacherUser->id,
            'violation_type' => 'Lỗi lập nhầm',
            'violation_date' => '2026-08-16',
            'amount' => 100000,
            'reporter_id' => $this->hrManager->id,
            'status' => 'pending',
        ]);

        $responseCancel = $this->actingAs($this->hrManager)->post(route('penalties.cancel', $penalty2->id));
        $responseCancel->assertRedirect();

        $penalty2->refresh();
        $this->assertEquals('cancelled', $penalty2->status);
        $this->assertEquals('Đã hủy biên bản', $penalty2->status_label);
    }

    public function test_employee_salary_setup_timesheet_and_payroll_auto_calculation(): void
    {
        // 1. Setup Employee salary & hourly rate
        $teacher = User::factory()->create([
            'name' => 'ThS. Nguyễn Văn A',
            'base_salary' => 12000000,
            'hourly_rate' => 300000,
            'department' => 'academic',
            'is_active' => true,
        ]);

        // 2. Add timesheets in August 2026
        TeacherTimesheet::create([
            'user_id' => $teacher->id,
            'class_id' => $this->classModel->id,
            'teaching_date' => '2026-08-05',
            'hours' => 10,
            'hourly_rate' => 300000,
            'type' => 'regular',
            'status' => 'valid',
        ]);
        TeacherTimesheet::create([
            'user_id' => $teacher->id,
            'class_id' => $this->classModel->id,
            'teaching_date' => '2026-08-15',
            'hours' => 10,
            'hourly_rate' => 300000,
            'type' => 'regular',
            'status' => 'valid',
        ]);

        // 3. Add a confirmed penalty in August 2026
        Penalty::create([
            'code' => 'BB-2026-001',
            'user_id' => $teacher->id,
            'violation_type' => 'Đi muộn',
            'violation_date' => '2026-08-10',
            'amount' => 200000,
            'status' => 'fined',
        ]);

        // 4. Create payroll period for August 2026
        $period = PayrollPeriod::create([
            'code' => 'PR-2026-08',
            'title' => 'Bảng lương Tháng 08/2026',
            'month' => 8,
            'year' => 2026,
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
            'status' => 'draft',
            'total_staff' => 0,
            'total_hours' => 0,
            'total_amount' => 0,
        ]);

        // 5. Trigger calculation
        $period->calculatePayrollForPeriod();

        // 6. Verify PayrollRecord for teacher
        $record = PayrollRecord::where('payroll_period_id', $period->id)->where('user_id', $teacher->id)->first();
        $this->assertNotNull($record);
        $this->assertEquals(20.0, $record->actual_hours);
        $this->assertEquals(6000000, $record->teaching_salary); // 20h * 300.000 = 6.000.000đ
        $this->assertEquals(12000000, $record->base_salary);
        $this->assertEquals(200000, $record->penalty_deduction);

        // Gross = 12M (base) + 6M (teaching) + 500k (allowance) = 18.5M
        // Deductions = 200k (penalty) + 1.26M (BH 10.5%) = 1.46M
        // Net = 18.5M - 1.46M = 17.04M
        $this->assertEquals(17040000, $record->net_salary);
    }

    public function test_payroll_settings_override_calculation_constants(): void
    {
        \App\Models\SystemSetting::set('payroll_allowance_amount', 1000000);
        \App\Models\SystemSetting::set('payroll_kpi_bonus_amount', 2000000);
        \App\Models\SystemSetting::set('payroll_kpi_bonus_hours_threshold', 10);
        \App\Models\SystemSetting::set('payroll_insurance_rate_percent', 21);

        $teacher = User::factory()->create([
            'name' => 'GV Tham Số Mới', 'base_salary' => 10000000,
            'department' => 'academic', 'is_active' => true,
        ]);
        TeacherTimesheet::create([
            'user_id' => $teacher->id, 'class_id' => $this->classModel->id,
            'teaching_date' => '2026-09-05', 'hours' => 12, 'hourly_rate' => 300000,
            'type' => 'regular', 'status' => 'valid',
        ]);

        $period = PayrollPeriod::create([
            'code' => 'PR-2026-09', 'title' => 'Bảng lương Tháng 09/2026',
            'month' => 9, 'year' => 2026, 'start_date' => '2026-09-01', 'end_date' => '2026-09-30',
            'status' => 'draft', 'total_staff' => 0, 'total_hours' => 0, 'total_amount' => 0,
        ]);
        $period->calculatePayrollForPeriod();

        $record = PayrollRecord::where('payroll_period_id', $period->id)->where('user_id', $teacher->id)->firstOrFail();
        $this->assertEquals(1000000, $record->allowance);   // theo cấu hình mới, không phải 500k mặc định
        $this->assertEquals(2000000, $record->kpi_bonus);   // 12h >= ngưỡng 10h mới
        $this->assertEquals(2100000, $record->insurance_deduction); // 21% của 10M

        // Gross = 10M + 3.6M (12h x 300k) + 2M KPI + 1M phụ cấp = 16.6M; Net = 16.6M - 2.1M = 14.5M
        $this->assertEquals(14500000, $record->net_salary);
    }

    public function test_payroll_settings_ui_is_gated_and_persists_values(): void
    {
        $staff = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $staff->assignRole('academic_staff');

        // academic_staff không có teacher_rate.manage → bị chặn cả xem lẫn lưu
        $this->actingAs($staff)->get(route('payroll.config.settings'))->assertForbidden();
        $this->actingAs($staff)->post(route('payroll.config.settings.store'), ['allowance_amount' => 1])->assertForbidden();

        $this->actingAs($this->hrManager)->get(route('payroll.config.settings'))->assertOk();
        $this->actingAs($this->hrManager)->post(route('payroll.config.settings.store'), [
            'allowance_amount' => 700000,
            'kpi_bonus_amount' => 1500000,
            'kpi_bonus_hours_threshold' => 30,
            'insurance_rate_percent' => 11.5,
            'foreign_teacher_deduction_rate' => 60000,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $settings = PayrollPeriod::payrollSettings();
        $this->assertSame(700000.0, $settings['allowance_amount']);
        $this->assertSame(1500000.0, $settings['kpi_bonus_amount']);
        $this->assertSame(30.0, $settings['kpi_bonus_hours_threshold']);
        $this->assertSame(11.5, $settings['insurance_rate_percent']);
        $this->assertSame(60000.0, $settings['foreign_teacher_deduction_rate']);
    }

    public function test_mark_paid_locks_period_and_records(): void
    {
        // Tạo dữ liệu chấm công hợp lệ trong kỳ để kỳ lương có bản ghi lương
        TeacherTimesheet::create([
            'user_id' => $this->teacherUser->id,
            'class_id' => $this->classModel->id,
            'teaching_date' => '2027-03-10',
            'hours' => 2,
            'hourly_rate' => 300000,
            'type' => 'regular',
            'status' => 'valid',
        ]);

        $this->actingAs($this->payrollAdmin)->post(route('payroll.periods.store'), ['month' => 3, 'year' => 2027]);
        $period = PayrollPeriod::where('month', 3)->where('year', 2027)->firstOrFail();
        $this->assertSame('reviewing', $period->status);

        // Chưa duyệt không được đánh dấu đã chi trả
        $this->actingAs($this->payrollAdmin)->post(route('payroll.periods.mark-paid', $period->id))
            ->assertStatus(422);
        $this->assertSame('reviewing', $period->fresh()->status);

        $this->actingAs($this->payrollAdmin)->post(route('payroll.periods.approve', $period->id))->assertRedirect();
        $this->actingAs($this->payrollAdmin)->post(route('payroll.periods.mark-paid', $period->id))->assertRedirect();

        $period->refresh();
        $this->assertSame('paid', $period->status);
        $this->assertSame('paid', $period->records()->first()->status);

        // Kỳ đã chi trả bị khóa hoàn toàn
        $this->actingAs($this->payrollAdmin)->post(route('payroll.periods.calculate', $period->id))
            ->assertStatus(422);
        $this->actingAs($this->payrollAdmin)->post(route('payroll.periods.approve', $period->id))
            ->assertStatus(422);
    }

    public function test_duplicate_payroll_period_is_rejected(): void
    {
        $this->actingAs($this->payrollAdmin)->post(route('payroll.periods.store'), ['month' => 5, 'year' => 2027])->assertRedirect();
        $this->actingAs($this->payrollAdmin)->post(route('payroll.periods.store'), ['month' => 5, 'year' => 2027])
            ->assertSessionHasErrors('month');

        $this->assertSame(1, PayrollPeriod::where('month', 5)->where('year', 2027)->count());
    }

    public function test_manual_timesheet_rejects_unknown_type(): void
    {
        $this->actingAs($this->hrManager)->post(route('payroll.timesheets.manual.store'), [
            'user_id' => $this->teacherUser->id,
            'class_id' => $this->classModel->id,
            'teaching_date' => now()->toDateString(),
            'hours' => 2,
            'hourly_rate' => 300000,
            'type' => 'khong-biet-gi',
        ])->assertSessionHasErrors('type');

        $this->actingAs($this->hrManager)->post(route('payroll.timesheets.manual.store'), [
            'user_id' => $this->teacherUser->id,
            'class_id' => $this->classModel->id,
            'teaching_date' => now()->toDateString(),
            'time_in' => '08:00',
            'time_out' => '10:00',
            'hourly_rate' => 300000,
            'type' => 'regular',
            'notes' => 'Chấm công tay do GV quên check-in',
        ])->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_foreign_teacher_deduction_uses_configured_rate_and_skips_the_foreign_teacher(): void
    {
        \App\Models\SystemSetting::set('payroll_foreign_teacher_deduction_rate', 90000);

        $foreignTeacher = User::factory()->create([
            'branch_id' => $this->branch->id, 'name' => 'GVNN David', 'is_active' => true,
        ]);
        $foreignTeacher->assignRole('teacher');
        $this->classModel->update(['foreign_teacher_id' => $foreignTeacher->id]);

        foreach (['2026-10-05', '2026-10-12'] as $date) {
            TeacherTimesheet::create([
                'user_id' => $this->teacherUser->id, 'class_id' => $this->classModel->id,
                'teaching_date' => $date, 'hours' => 2, 'hourly_rate' => 300000,
                'type' => 'regular', 'status' => 'valid',
            ]);
            TeacherTimesheet::create([
                'user_id' => $foreignTeacher->id, 'class_id' => $this->classModel->id,
                'teaching_date' => $date, 'hours' => 2, 'hourly_rate' => 300000,
                'type' => 'regular', 'status' => 'valid',
            ]);
        }

        $period = PayrollPeriod::create([
            'code' => 'PR-2026-10', 'title' => 'Bảng lương Tháng 10/2026',
            'month' => 10, 'year' => 2026, 'start_date' => '2026-10-01', 'end_date' => '2026-10-31',
            'status' => 'draft', 'total_staff' => 0, 'total_hours' => 0, 'total_amount' => 0,
        ]);
        $period->calculatePayrollForPeriod();

        $mainRecord = PayrollRecord::where('payroll_period_id', $period->id)->where('user_id', $this->teacherUser->id)->firstOrFail();
        $this->assertSame(2, $mainRecord->foreign_teacher_sessions_count);
        $this->assertEquals(180000, $mainRecord->foreign_teacher_deduction); // 2 buổi × 90.000đ cấu hình mới

        // Chính GVNN không bị trừ theo chính mình.
        $foreignRecord = PayrollRecord::where('payroll_period_id', $period->id)->where('user_id', $foreignTeacher->id)->firstOrFail();
        $this->assertSame(0, $foreignRecord->foreign_teacher_sessions_count);
        $this->assertEquals(0, $foreignRecord->foreign_teacher_deduction);
    }

    public function test_kpi_leaderboard_lists_sales_only_with_commission_owner_attribution(): void
    {
        $sales = User::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Sales Chốt Đơn', 'is_active' => true]);
        $sales->assignRole('sales_consultant');
        $student = User::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Học Viên Không Rank', 'is_active' => true]);
        $student->assignRole('student');

        \App\Models\CrmCustomer::create([
            'code' => 'KH-KPI-01', 'name' => 'Lead Won KPI', 'phone' => '0987000111',
            'stage' => 'won', 'deal_value' => 20000000, 'branch_id' => $this->branch->id,
            'assigned_user_id' => $sales->id, 'commission_user_id' => $sales->id,
        ]);

        // Trước đây controller dùng User::all() — học viên cũng xuất hiện trên bảng xếp hạng.
        $this->actingAs($this->hrManager)->get(route('payroll.kpi-leaderboard'))
            ->assertOk()
            ->assertSee('Sales Chốt Đơn')
            ->assertSee('20,000,000')
            ->assertDontSee('Học Viên Không Rank');
    }
}
