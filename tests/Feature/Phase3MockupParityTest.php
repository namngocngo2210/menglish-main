<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\PayrollPeriod;
use App\Models\TeacherTimesheet;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 3 — Đối chiếu mockup nhóm Chấm công, Phạt, Lương, KPI (1 test / màn).
 * Mockup: ui-full-tinh-nang-menglish/epic-7/*, epic-8-danh-sach-phat, roundcuoi-kieulien 01_Web_Admin/09–11,
 * 02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu/01–04, 11, 03_Cong_Giao_Vien/15–16.
 */
class Phase3MockupParityTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $admin;

    private User $academicStaff;

    private User $teacher;

    private ClassModel $classModel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Cơ sở Mockup P3', 'code' => 'MP3', 'is_active' => true]);
        $this->admin = $this->userWithRole('admin', ['name' => 'Admin P3']);
        $this->academicStaff = $this->userWithRole('academic_staff', ['name' => 'Học vụ P3']);
        $this->teacher = $this->userWithRole('teacher_parttime', ['name' => 'GV Mockup P3', 'employee_code' => 'GV-0492']);

        $course = Course::create(['code' => 'MP3-C', 'name' => 'IELTS MP3', 'tuition_fee' => 1000000, 'is_active' => true]);
        $this->classModel = ClassModel::create([
            'code' => 'MP3-01', 'name' => 'Lớp Mockup P3', 'course_id' => $course->id,
            'branch_id' => $this->branch->id, 'teacher_id' => $this->teacher->id, 'status' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function userWithRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes + ['branch_id' => $this->branch->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function timesheet(array $attributes = []): TeacherTimesheet
    {
        return TeacherTimesheet::create($attributes + [
            'user_id' => $this->teacher->id, 'class_id' => $this->classModel->id, 'teaching_date' => '2026-08-10',
            'checkin_time' => '18:00', 'checkout_time' => '20:00', 'hours' => 2, 'type' => 'regular',
            'source' => TeacherTimesheet::SOURCE_MANUAL, 'status' => 'pending_review', 'notes' => 'Mất mạng chi nhánh',
        ]);
    }

    /** Màn "Chấm công thủ công" (epic-7/cham-cong-thu-cong). */
    public function test_manual_timesheet_screen_matches_mockup(): void
    {
        PayrollPeriod::create([
            'code' => 'PR-2026-07', 'title' => 'Bảng lương Tháng 7/2026', 'month' => 7, 'year' => 2026,
            'start_date' => '2026-07-01', 'end_date' => '2026-07-31', 'status' => 'approved',
        ]);

        $html = $this->actingAs($this->academicStaff)
            ->get(route('payroll.timesheets.manual', ['user_id' => $this->teacher->id, 'teaching_date' => '2026-08-12', 'time_in' => '18:00']))
            ->assertOk()
            ->assertSee('Chấm công thủ công')
            ->assertSee('Ghi nhận chấm công thay hệ thống khi gặp sự cố hạ tầng')
            ->assertSee('Mỗi lần lưu hệ thống chỉ tạo đúng')
            ->assertSee('Nhập tên hoặc mã nhân viên')
            ->assertSee('Chi nhánh')
            ->assertSee('Cơ sở Mockup P3')
            ->assertSee('Ngày làm việc')
            ->assertSee('Kỳ lương của ngày này đã bị khóa')
            ->assertSee('Lý do điều chỉnh')
            ->assertSee('Ví dụ: Mất mạng chi nhánh, quên quẹt thẻ...')
            ->assertSee('GV-0492')
            ->assertSee('2026-07-01') // khoảng ngày kỳ đã khóa gửi xuống để cảnh báo ngay khi chọn ngày
            ->getContent();
        $this->assertStringContainsString('value="2026-08-12"', $html);

        // Lớp không thuộc chi nhánh đã chọn → báo lỗi.
        $other = Branch::create(['name' => 'Cơ sở khác', 'code' => 'OTH', 'is_active' => true]);
        $this->actingAs($this->admin)->post(route('payroll.timesheets.manual.store'), [
            'user_id' => $this->teacher->id, 'branch_id' => $other->id, 'class_id' => $this->classModel->id,
            'teaching_date' => '2026-08-12', 'time_in' => '18:00', 'time_out' => '19:30', 'type' => 'regular',
            'notes' => 'Mất mạng chi nhánh',
        ])->assertSessionHasErrors('class_id');

        // Ngày thuộc kỳ đã khóa → chặn phía server.
        $this->actingAs($this->admin)->post(route('payroll.timesheets.manual.store'), [
            'user_id' => $this->teacher->id, 'branch_id' => $this->branch->id, 'class_id' => $this->classModel->id,
            'teaching_date' => '2026-07-15', 'time_in' => '18:00', 'time_out' => '19:30', 'type' => 'regular',
            'notes' => 'Mất mạng chi nhánh',
        ])->assertSessionHasErrors('teaching_date');
        $this->assertDatabaseCount('teacher_timesheets', 0);
    }

    /** Màn "Chi tiết chấm công GV" (epic-7/chi-tiet-cham-cong-theo-gv + 01_Web_Admin/10 đối soát). */
    public function test_teacher_timesheet_detail_screen_matches_mockup(): void
    {
        Carbon::setTestNow('2026-08-25 10:00:00');
        $full = $this->timesheet();
        $missingIn = $this->timesheet(['teaching_date' => '2026-08-12', 'checkin_time' => null, 'checkout_time' => '11:10']);
        // Buổi được phân công đã qua nhưng chưa chấm công → "thiếu chấm công".
        ClassSession::create([
            'class_id' => $this->classModel->id, 'branch_id' => $this->branch->id, 'date' => '2026-08-15',
            'start_time' => '14:00', 'end_time' => '16:00', 'teacher_id' => $this->teacher->id, 'status' => 'scheduled',
        ]);

        $this->actingAs($this->admin)
            ->get(route('payroll.timesheets.teachers', ['month' => '2026-08', 'user_id' => $this->teacher->id]))
            ->assertOk()
            ->assertSee('Chi tiết chấm công giáo viên')
            ->assertSee('Kỳ lương: Tháng 08/2026')
            ->assertSee('Mã GV: GV-0492')
            ->assertSee('Cơ sở: Cơ sở Mockup P3')
            ->assertSee('Số lần thiếu chấm công trong tháng')
            ->assertSee('1 lần')
            ->assertSee('Đầy đủ')
            ->assertSee('Thiếu vào')
            ->assertSee('Chốt bảng công')
            ->assertSee('Chỉnh tay bổ sung')
            ->assertSee('Lý do điều chỉnh')
            ->assertSee('Chấm công tay');

        // Chỉnh tay: bắt buộc lý do, ca về "Chờ đối soát", đánh dấu "Chỉnh tay".
        $full->update(['status' => 'valid']);
        $this->actingAs($this->academicStaff)->put(route('payroll.timesheets.adjust', $full->id), [
            'time_in' => '17:45', 'time_out' => '20:15',
        ])->assertSessionHasErrors('adjustment_reason');
        $this->actingAs($this->academicStaff)->put(route('payroll.timesheets.adjust', $full->id), [
            'time_in' => '17:45', 'time_out' => '20:15', 'adjustment_reason' => 'Máy chấm công lệch giờ',
        ])->assertSessionHasNoErrors();
        $full->refresh();
        $this->assertSame('adjusted', $full->punch_state);
        $this->assertSame('pending_review', $full->status);
        $this->assertEquals(2.5, (float) $full->hours);

        // Chốt bảng công hàng loạt.
        $this->actingAs($this->admin)->post(route('payroll.timesheets.bulk-review'), ['ids' => [$full->id, $missingIn->id]])
            ->assertSessionHasNoErrors();
        $this->assertSame(2, TeacherTimesheet::where('status', 'valid')->count());

        // Từ chối bắt buộc lý do (không còn lý do ghi cứng).
        $third = $this->timesheet(['teaching_date' => '2026-08-20']);
        $this->actingAs($this->admin)->post(route('payroll.timesheets.review', $third->id), ['decision' => 'invalid'])
            ->assertSessionHasErrors('rejection_reason');

        // Kỳ đã chốt → không sửa được.
        PayrollPeriod::create([
            'code' => 'PR-2026-08', 'title' => 'Bảng lương Tháng 8/2026', 'month' => 8, 'year' => 2026,
            'start_date' => '2026-08-01', 'end_date' => '2026-08-31', 'status' => 'approved',
        ]);
        $this->actingAs($this->admin)->get(route('payroll.timesheets.teachers', ['month' => '2026-08']))
            ->assertSee('Kỳ lương đã chốt, không thể sửa');
        $this->actingAs($this->academicStaff)->put(route('payroll.timesheets.adjust', $third->id), [
            'time_in' => '18:00', 'time_out' => '20:00', 'adjustment_reason' => 'Sửa sau khi chốt',
        ])->assertSessionHasErrors('time_in');

        // Giáo viên chỉ thấy ca của mình.
        $this->actingAs($this->teacher)->get(route('payroll.timesheets.teachers', ['month' => '2026-08']))
            ->assertOk()->assertSee('Chấm công của tôi')->assertDontSee('Chốt bảng công');
    }

    /** Màn "Lịch sử đồng bộ" (epic-7/lich-su-dong-bo-cham-cong). */
    public function test_sync_history_screen_matches_mockup(): void
    {
        $this->actingAs($this->admin)->get(route('payroll.timesheets.sync-history'))
            ->assertOk()
            ->assertSee('Lịch sử đồng bộ chấm công')
            ->assertSee('Chưa kết nối nguồn đồng bộ')
            ->assertSee('Đồng bộ ngay')
            ->assertSee('Lọc dữ liệu');

        $partial = \App\Models\TimesheetSyncLog::create([
            'device_name' => 'AppSheet', 'records_count' => 200, 'matched_count' => 180, 'failed_count' => 10, 'skipped_count' => 10,
            'status' => 'partial', 'error_rows' => [
                ['employee_code' => 'NV0125', 'employee_name' => 'Nguyễn Văn A', 'code' => 'DATA_MISMATCH', 'message' => 'Thiếu dữ liệu chi nhánh'],
            ],
        ]);
        $failed = \App\Models\TimesheetSyncLog::create([
            'device_name' => 'AppSheet', 'records_count' => 100, 'matched_count' => 0, 'failed_count' => 100,
            'status' => 'failed', 'error_code' => 'API_TIMEOUT', 'error_message' => 'Không thể kết nối đến máy chủ AppSheet sau 30 giây.',
        ]);

        $this->actingAs($this->admin)->get(route('payroll.timesheets.sync-history'))
            ->assertOk()
            ->assertSee('Tổng số dòng')->assertSee('Bỏ qua')
            ->assertSee('Hệ thống bỏ qua không ghi đè dữ liệu của các nhân sự đã chốt kỳ lương.')
            ->assertSee('Lỗi một phần')->assertSee('10 dòng lỗi')
            ->assertSee('Lỗi toàn bộ')->assertSee('API_TIMEOUT')
            ->assertSee('Xem chi tiết lỗi')->assertSee('DATA_MISMATCH')
            ->assertSee('Xuất file Excel lỗi');

        $this->actingAs($this->admin)->get(route('payroll.timesheets.sync-history', ['status' => 'failed']))
            ->assertOk()->assertSee('API_TIMEOUT')->assertDontSee('DATA_MISMATCH');

        $this->actingAs($this->admin)->get(route('payroll.timesheets.sync-history.errors', $partial->id))->assertOk();
        $this->assertNotNull($failed->id);
    }

    /** Màn "Danh sách vi phạm" + luồng tạo / giải trình / chốt (epic-8-danh-sach-phat). */
    public function test_violation_list_screen_and_flow_match_mockup(): void
    {
        $manager = $this->userWithRole('manager', ['name' => 'Quản lý P3']);
        $base = ['user_id' => $this->teacher->id, 'error_category' => 'operations', 'violation_type' => 'Đi muộn', 'reporter_id' => $manager->id];
        $recorded = \App\Models\Penalty::create($base + ['code' => 'BB-M01', 'violation_date' => '2026-09-10', 'status' => 'pending']);
        $confirmed = \App\Models\Penalty::create($base + ['code' => 'BB-M02', 'violation_date' => '2026-09-11', 'status' => 'confirmed', 'explanation' => 'Kẹt xe do mưa lớn']);
        $fined = \App\Models\Penalty::create($base + ['code' => 'BB-M03', 'violation_date' => '2026-09-12', 'status' => 'fined', 'amount' => 200000, 'due_date' => now()->addDays(2)]);
        $paid = \App\Models\Penalty::create($base + ['code' => 'BB-M04', 'violation_date' => '2026-09-13', 'status' => 'paid', 'amount' => 500000, 'paid_at' => now()]);
        \App\Models\Penalty::create(array_merge($base, ['code' => 'BB-M05', 'violation_date' => '2026-09-14', 'status' => 'resolved', 'reporter_id' => null]));

        $this->actingAs($manager)->get(route('penalties.index'))
            ->assertOk()
            ->assertSee('Danh sách vi phạm')
            ->assertSee('Ghi nhận vi phạm mới')
            ->assertSee('Nhập tên hoặc mã nhân viên...')
            ->assertSee('Lọc theo bước')
            ->assertSee('Đã chốt lỗi')->assertSee('Đã chốt phạt')->assertSee('Đã khắc phục')->assertSee('Đóng - không phạt')
            ->assertSee('Bộ lọc nâng cao')
            ->assertSee('Bước hiện tại')->assertSee('Trạng thái GV')->assertSee('Nguồn')
            ->assertSee('ID: GV-0492')
            ->assertSee('Thủ công')->assertSee('Tự động')
            ->assertSee('Chờ xác nhận')->assertSee('Đã xác nhận')
            ->assertSee('Chốt lỗi')->assertSee('Chốt mức phạt')->assertSee('Hủy vi phạm')
            ->assertSee('Đánh dấu đã nộp')->assertSee('Ghi nhận khắc phục');

        // Tìm theo mã nhân viên + lọc theo bước.
        $this->actingAs($manager)->get(route('penalties.index', ['search' => 'GV-0492', 'step' => 'fined']))
            ->assertOk()->assertViewHas('penalties', fn ($p) => $p->total() === 1 && $p->first()->is($fined));
        $this->actingAs($manager)->get(route('penalties.index', ['step' => 'recorded']))
            ->assertViewHas('penalties', fn ($p) => $p->total() === 1 && $p->first()->is($recorded));

        // Ghi nhận khắc phục sau khi đã nộp → bước "Đã khắc phục".
        $this->actingAs($manager)->post(route('penalties.remedy', $paid->id), ['remedy_note' => 'Đã cam kết không tái phạm'])
            ->assertSessionHasNoErrors();
        $this->assertSame('remedied', $paid->fresh()->step);
        $this->actingAs($manager)->get(route('penalties.index', ['step' => 'remedied']))
            ->assertViewHas('penalties', fn ($p) => $p->total() === 1);

        // Kỳ lương đã khóa → "Chốt mức phạt" bị chặn với thông báo theo mockup.
        PayrollPeriod::create([
            'code' => 'PR-2026-09', 'title' => 'Bảng lương Tháng 9/2026', 'month' => 9, 'year' => 2026,
            'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'status' => 'approved',
        ]);
        $this->actingAs($manager)->from(route('penalties.index'))
            ->post(route('penalties.confirm', $confirmed->id), ['decision' => 'fine', 'amount' => 100000])
            ->assertSessionHasErrors('violation_date')
            ->assertSessionHas('locked_penalty', fn ($m) => str_contains($m, 'Kỳ lương hiện tại của nhân viên GV Mockup P3 đã khóa. Không thể thực hiện chốt mức phạt.'));
        $this->assertSame('confirmed', $confirmed->fresh()->status);
    }

    /** Màn "Đơn giá GV" (epic-7/cau-hinh-don-gia-giao-vien): theo từng GV, loại GV, hiệu lực, lịch sử có "Đến ngày". */
    public function test_teacher_rate_screen_matches_mockup(): void
    {
        Carbon::setTestNow('2026-09-20 09:00:00');
        $this->actingAs($this->admin)->post(route('payroll.config.teacher-rates.personal.store'), [
            'user_id' => $this->teacher->id, 'teacher_type' => 'parttime', 'rate_unit' => 'session',
            'hourly_rate' => 250000, 'effective_from' => '2026-01-01', 'note' => 'Đơn giá khởi điểm',
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('payroll.config.teacher-rates.personal.store'), [
            'user_id' => $this->teacher->id, 'rate_unit' => 'session',
            'hourly_rate' => 300000, 'effective_from' => '2026-06-15', 'note' => 'Tăng bậc',
        ])->assertSessionHasNoErrors();
        $this->assertSame('parttime', \App\Models\TeacherHourlyRate::latest('id')->first()->teacher_type); // mặc định theo vai trò

        $this->actingAs($this->admin)->get(route('payroll.config.teacher-rates', ['teacher_id' => $this->teacher->id]))
            ->assertOk()
            ->assertSee('1. Chọn giáo viên')
            ->assertSee('Tìm tên hoặc mã nhân viên...')
            ->assertSee('Mã NV: GV-0492')
            ->assertSee('Đang giảng dạy')
            ->assertSee('Đơn giá hiện hành')
            ->assertSee('Mức lương đang áp dụng')
            ->assertSee('300.000 VNĐ / buổi')
            ->assertSee('Hiệu lực từ: 15/06/2026')
            ->assertSee('2. Cập nhật đơn giá mới')
            ->assertSee('Loại giáo viên')->assertSee('Giáo viên nước ngoài')->assertSee('Trợ giảng')
            ->assertSee('Ghi chú / Lý do thay đổi')
            ->assertSee('Cập nhật đơn giá mới')
            ->assertSee('Lịch sử thay đổi đơn giá')
            ->assertSeeInOrder(['Loại GV', 'Đơn giá', 'Đơn vị tính', 'Hiệu lực từ', 'Đến ngày', 'Trạng thái'])
            ->assertSee('14/06/2026')   // phiên bản cũ kết thúc trước phiên bản mới 1 ngày
            ->assertSee('Hiện tại')
            ->assertSee('Đang áp dụng')
            ->assertSee('Đã hết hạn');
    }

    /** Màn "Mốc hoa hồng & thưởng tái tục" (epic-7/cau-hinh-moc-hoa-hong-thuong-tai-tuc). */
    public function test_commission_and_renewal_config_screen_matches_mockup(): void
    {
        // Mockup không có ô tên bậc: tên tự đặt theo ngưỡng.
        $this->actingAs($this->admin)->post(route('payroll.config.commission-tiers.store'), [
            'min_students' => 51, 'new_sale_percent' => 6, 'effective_from' => now()->toDateString(),
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('commission_tiers', ['tier_name' => 'Bậc 51+ HS', 'min_students' => 51, 'max_students' => null]);

        $this->actingAs($this->admin)->get(route('payroll.config.commission-tiers'))
            ->assertOk()
            ->assertSee('Cấu hình mốc hoa hồng &amp; thưởng tái tục', false)
            ->assertSee('Hoa hồng tuyển sinh')->assertSee('Thưởng tái tục')
            ->assertSee('Thêm mốc mới')
            ->assertSee('Thay đổi cấu hình sẽ được áp dụng cho các kỳ tính lương tiếp theo kể từ ngày hiệu lực.')
            ->assertSeeInOrder(['Ngưỡng từ (HV)', 'Ngưỡng đến (HV)', 'Tỷ lệ (%)', 'Ngày hiệu lực từ'])
            ->assertSee('Không giới hạn')
            ->assertSee('Thêm mốc cấu hình mới')
            ->assertSee('Từ (số học viên)')->assertSee('Để trống = Max')
            ->assertSee('Lưu cấu hình');

        // Tab thưởng tái tục: bảng % theo số HS nghỉ (A6), lưu riêng.
        $this->actingAs($this->admin)->get(route('payroll.config.commission-tiers', ['tab' => 'renewal']))
            ->assertOk()
            ->assertSee('Thưởng tái tục — % doanh thu lớp theo số HS nghỉ trong kỳ')
            ->assertSee('Số HS nghỉ trong lớp')
            ->assertSee('chờ BA');
        $this->actingAs($this->admin)->post(route('payroll.config.renewal.store'), [
            'renewal' => [
                ['quits' => 0, 'percent' => 1, 'pending' => 0],
                ['quits' => 1, 'percent' => 0.7, 'pending' => 0],
                ['quits' => 2, 'percent' => 0.5, 'pending' => 1],
            ],
            'renewal_beyond_percent' => 0,
        ])->assertRedirect(route('payroll.config.commission-tiers', ['tab' => 'renewal']));
        $table = PayrollPeriod::payrollSettings()['renewal_table'];
        $this->assertEquals(0.5, $table[2]['percent']);
        $this->assertTrue($table[2]['pending']);
    }

    private function period(int $month = 8, string $status = 'draft'): PayrollPeriod
    {
        return PayrollPeriod::create([
            'code' => sprintf('PR-2026-%02d', $month), 'title' => "Bảng lương Tháng {$month}/2026", 'month' => $month, 'year' => 2026,
            'start_date' => sprintf('2026-%02d-01', $month), 'end_date' => Carbon::create(2026, $month, 1)->endOfMonth()->toDateString(),
            'status' => $status, 'calculated_at' => now(),
        ]);
    }

    /** Màn "Danh sách bảng lương theo kỳ" (epic-7/danh-sach-bang-luong-theo-ky) + Excel đủ các dòng Q3. */
    public function test_period_payroll_list_screen_matches_mockup(): void
    {
        $period = $this->period();
        $this->period(7, 'paid');
        \App\Models\PayrollRecord::create([
            'payroll_period_id' => $period->id, 'user_id' => $this->teacher->id, 'department' => 'teacher', 'employee_type' => 'parttime',
            'salary_role' => 'teacher_parttime', 'kpi_source' => 'retention', 'retention_base_students' => 10, 'retention_students' => 9,
            'teaching_sessions' => 8, 'teaching_salary' => 2000000, 'net_salary' => 2000000,
            'manual_lines' => [['kind' => 'earning', 'label' => 'Gửi xe', 'amount' => 100000]],
        ]);
        \App\Models\PayrollRecord::create([
            'payroll_period_id' => $period->id, 'user_id' => $this->academicStaff->id, 'department' => 'operations', 'employee_type' => 'fulltime',
            'salary_role' => 'academic_staff', 'kpi_source' => 'academic_kpi', 'kpi_score' => 82.5, 'base_salary' => 8000000, 'net_salary' => 8000000,
        ]);

        $this->actingAs($this->admin)->get(route('payroll.periods.show', $period->id))
            ->assertOk()
            ->assertSee('Danh sách bảng lương theo kỳ')
            ->assertSee('Chốt bảng lương')->assertSee('Đánh dấu đã trả')
            ->assertSee('Kỳ lương')->assertSee('Tháng 07/2026')
            ->assertSee('Tìm giáo viên / nhân sự...')
            ->assertSee('Chưa thể chốt bảng lương kỳ 08/2026 do: Còn 1 nhân sự chưa chốt KPI')
            ->assertSeeInOrder(['Tên giáo viên / nhân sự', 'Trạng thái bảng lương', 'Trạng thái KPI'])
            ->assertSee('Thực nhận')
            ->assertSee('Chưa chốt KPI')->assertSee('Đã chốt KPI')
            ->assertSee('Đang tính')
            ->assertSee('Chi tiết');

        $this->actingAs($this->admin)->get(route('payroll.periods.show', [$period->id, 'search' => 'GV-0492']))
            ->assertViewHas('records', fn ($p) => $p->total() === 1);
        $this->actingAs($this->admin)->get(route('payroll.periods.show', [$period->id, 'kpi' => 'done']))
            ->assertViewHas('records', fn ($p) => $p->total() === 1 && $p->first()->user_id === $this->academicStaff->id);

        $this->actingAs($this->admin)->get(route('payroll.periods.index'))
            ->assertOk()->assertSee('Danh sách bảng lương theo kỳ')->assertSee('2 kỳ lương')->assertSee('Đã trả');

        // Xuất Excel có đủ các dòng mới của phiếu lương Q3.
        $response = $this->actingAs($this->admin)->get(route('payroll.periods.export', [$period->id, 'format' => 'csv']));
        $csv = file_get_contents($response->baseResponse->getFile()->getPathname());
        foreach (['Mã NV', 'Trạng thái KPI', 'HS giữ được / đầu kỳ', 'Bậc KPI giữ HS', 'Điểm KPI Học vụ', 'Buổi có GVNN', '% hoa hồng',
            'Hoa hồng hoãn', 'Thưởng tái tục', 'Chi tiết cộng tự do', 'Công đoàn', 'Thuế TNCN', 'Thu hồi hoa hồng', 'Tổng khấu trừ', 'Thực lĩnh'] as $heading) {
            $this->assertStringContainsString($heading, $csv);
        }
        $this->assertStringContainsString('GV-0492', $csv);
        $this->assertStringContainsString('9/10', $csv);
        $this->assertStringContainsString('Gửi xe: 100.000', $csv);
    }

    /** 4 màn "Chi tiết lương" (epic-7/chi-tiet-bang-luong*, roundcuoi 02/11): phiếu lương theo loại nhân sự + bản in. */
    public function test_four_payslip_variants_match_mockups(): void
    {
        $period = $this->period();
        $lead = $this->userWithRole('academic_lead', ['name' => 'Học thuật P3', 'base_salary' => 15000000]);
        $ftTeacher = $this->userWithRole('teacher_fulltime', ['name' => 'GV Fulltime P3', 'base_salary' => 15000000]);
        \App\Models\TeacherHourlyRate::create(['user_id' => $this->teacher->id, 'hourly_rate' => 150000, 'rate_unit' => 'session', 'effective_from' => '2026-01-01']);
        $this->timesheet(['status' => 'valid', 'teaching_date' => '2026-08-05', 'type' => 'sub', 'scheduled_time' => '17:30-19:00']);

        $pt = \App\Models\PayrollRecord::create([
            'payroll_period_id' => $period->id, 'user_id' => $this->teacher->id, 'department' => 'teacher', 'employee_type' => 'parttime',
            'salary_role' => 'teacher_parttime', 'kpi_source' => 'retention', 'retention_base_students' => 10, 'retention_students' => 9,
            'teaching_sessions' => 1, 'teaching_salary' => 150000, 'net_salary' => 150000,
        ]);
        $ft = \App\Models\PayrollRecord::create([
            'payroll_period_id' => $period->id, 'user_id' => $ftTeacher->id, 'department' => 'fulltime', 'employee_type' => 'fulltime',
            'salary_role' => 'teacher_fulltime', 'kpi_source' => 'manual', 'base_salary' => 15000000, 'insurance_deduction' => 1575000,
            'union_deduction' => 75000, 'net_salary' => 13350000,
        ]);
        $hv = \App\Models\PayrollRecord::create([
            'payroll_period_id' => $period->id, 'user_id' => $this->academicStaff->id, 'department' => 'operations', 'employee_type' => 'fulltime',
            'salary_role' => 'academic_staff', 'kpi_source' => 'academic_kpi', 'kpi_score' => 87.5, 'kpi_bonus' => 1750000, 'base_salary' => 6500000,
            'calculation_details' => ['kpi' => ['fund' => 2000000, 'items' => [
                ['code' => '1.1', 'name' => 'Tỷ lệ chuyên cần', 'group' => 'Chăm sóc học viên', 'weight' => 50, 'score' => 100, 'amount' => 1000000],
                ['code' => '2.1', 'name' => 'Dự giờ', 'group' => 'Chất lượng giảng dạy', 'weight' => 50, 'score' => 75, 'amount' => 750000],
            ]]],
            'net_salary' => 8250000,
        ]);
        $ht = \App\Models\PayrollRecord::create([
            'payroll_period_id' => $period->id, 'user_id' => $lead->id, 'department' => 'academic', 'employee_type' => 'fulltime',
            'salary_role' => 'academic_lead', 'kpi_source' => 'manual', 'base_salary' => 15000000, 'net_salary' => 15000000,
        ]);

        // GV Part-time
        $this->actingAs($this->admin)->get(route('payroll.records.show', $pt->id))
            ->assertOk()
            ->assertSee('Chi tiết bảng lương GV Part-time')
            ->assertSee('GV-0492')->assertSee('Kỳ lương 08/2026')->assertSee('Giáo viên (Part-time)')->assertSee('Đang tính')
            ->assertSee('Chốt bảng lương')
            ->assertSee('Số buổi dạy')->assertSee('Đơn giá cơ bản')->assertSee('150.000đ')->assertSee('Thành tiền')
            ->assertSee('Bậc KPI giữ học sinh')->assertSee('Đơn giá KPI (đ/hs/tháng)')->assertSee('Gợi ý: 15.000 / 20.000 / 25.000đ/hs/tháng')
            ->assertSee('Số học sinh duy trì')->assertSee('9 / 10 HS')->assertSee('Chốt KPI')
            ->assertSee('Phụ cấp mở rộng')->assertSee('Lớp GVNN đan xen')->assertSee('Hỗ trợ thỏa thuận')->assertSee('Phụ cấp gửi xe')->assertSee('Thưởng khác')
            ->assertSee('Chi tiết buổi dạy')->assertSee('Ca 17:30-19:00')->assertSee('Dạy thay')
            ->assertSee('Các khoản trừ')->assertSee('Thêm khoản trừ')
            ->assertSee('Tổng kết thực nhận')->assertSee('Thực nhận')
            ->assertSee('In phiếu lương / Xuất PDF')
            ->assertSee('PHIẾU LƯƠNG THÁNG 08/2026')->assertSee('BẢN TẠM TÍNH (chưa duyệt)')->assertSee('Người nhận')
            ->assertDontSee('Công đoàn (');

        // "Chốt KPI" = chọn bậc trên cùng form phiếu lương.
        $this->actingAs($this->admin)->post(route('payroll.records.adjust', $pt->id), ['retention_tier' => 20000, 'intent' => 'kpi', 'lines' => []])
            ->assertSessionHasNoErrors();
        $this->assertSame('done', $pt->fresh()->kpi_state[0]);
        $this->assertEquals(180000, (float) $pt->fresh()->kpi_bonus);

        // GV Full-time
        $this->actingAs($this->admin)->get(route('payroll.records.show', $ft->id))
            ->assertOk()
            ->assertSee('Chi tiết bảng lương GV Full-time')
            ->assertSee('Thu nhập chính')->assertSee('Lương cơ bản (VNĐ)')->assertSee('Lương KPI (VNĐ)')
            ->assertSee('Phụ cấp mở rộng')->assertSee('Thêm phụ cấp mới')
            ->assertSee('Trừ vi phạm')->assertSee('Thêm khoản trừ')
            ->assertSee('Khấu trừ bắt buộc')->assertSee('BHXH (10,5% lương CB)')->assertSee('Phí Công đoàn (0,5% lương CB)')->assertSee('Thuế TNCN (VNĐ)')
            ->assertSee('Tổng kết thực nhận')->assertSee('13.350.000');

        // Học vụ: KPI tự động 6 nhóm / 15 mục, chỉ đọc
        $this->actingAs($this->admin)->get(route('payroll.records.show', $hv->id))
            ->assertOk()
            ->assertSee('Chi tiết bảng lương Học vụ')
            ->assertSee('Thu nhập cố định &amp; KPI', false)
            ->assertSee('Lương KPI (tự động theo 6 nhóm / 15 mục)')
            ->assertSee('87,5% điểm KPI')
            ->assertSee('Xem bảng kê chi tiết 6 nhóm / 15 mục')
            ->assertSee('1. Chăm sóc học viên (trọng số 50%)')
            ->assertDontSee('name="kpi_manual_amount"', false);

        // Học thuật
        $this->actingAs($this->admin)->get(route('payroll.records.show', $ht->id))
            ->assertOk()
            ->assertSee('Chi tiết bảng lương Học thuật')
            ->assertSee('Thành phần Học thuật')->assertSee('Lương cứng (VNĐ)')->assertSee('Lương giảng dạy')->assertSee('Hỗ trợ')
            ->assertSee('Thuế TNCN (VNĐ)');

        // Chốt bảng lương → thông báo cho nhân sự; phiếu khóa, bản in không còn "tạm tính".
        $period->update(['calculated_at' => now()->addMinute()]);
        $this->actingAs($this->admin)->post(route('payroll.periods.approve', $period->id))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('admin_notifications', ['user_id' => $this->teacher->id, 'type' => 'payroll_approved']);
        $this->actingAs($this->admin)->get(route('payroll.records.show', $pt->id))
            ->assertOk()->assertSee('Đã chốt')->assertDontSee('BẢN TẠM TÍNH')->assertDontSee('Lưu điều chỉnh');
    }

    /** Màn "BXH KPI" (epic-7/bang-kpi-cong-khai) + KPI Học vụ 6 nhóm / 15 mục (roundcuoi 02/01–04). */
    public function test_kpi_leaderboard_and_academic_kpi_evaluation_match_mockups(): void
    {
        $period = $this->period(9);
        $other = Branch::create(['name' => 'Cơ sở Khác P3', 'code' => 'OT3', 'is_active' => true]);
        $teacherB = $this->userWithRole('teacher_parttime', ['name' => 'GV Hạng Hai', 'branch_id' => $other->id]);
        foreach ([[$this->teacher, 45, 20000], [$teacherB, 30, 15000]] as [$user, $kept, $tier]) {
            \App\Models\PayrollRecord::create([
                'payroll_period_id' => $period->id, 'user_id' => $user->id, 'department' => 'teacher', 'employee_type' => 'parttime',
                'salary_role' => 'teacher_parttime', 'kpi_source' => 'retention', 'retention_base_students' => $kept, 'retention_students' => $kept,
                'retention_tier' => $tier, 'kpi_bonus' => $kept * $tier, 'base_salary' => 0, 'net_salary' => 9999000,
            ]);
        }

        $this->actingAs($this->admin)->get(route('payroll.kpi-leaderboard', ['period' => '2026-09']))
            ->assertOk()
            ->assertSee('Bảng xếp hạng KPI &amp; Hoa hồng', false)
            ->assertSee('Thông tin không bao gồm lương cơ bản, các khoản khấu trừ và thực nhận cá nhân.')
            ->assertSee('Kỳ lương')->assertSee('Chi nhánh')
            ->assertSeeInOrder(['Hạng', 'Nhân viên', 'Chi nhánh', 'Số HS Giữ', 'Đơn giá (VNĐ/hs)', 'Tổng KPI'])
            ->assertSeeInOrder(['GV Mockup P3', 'GV Hạng Hai'])
            ->assertSee('900,000')
            ->assertSee('Số liệu tạm tính — kỳ chưa chốt')
            ->assertDontSee('9,999,000'); // không lộ thực nhận

        $this->actingAs($this->admin)->get(route('payroll.kpi-leaderboard', ['period' => '2026-09', 'branch_id' => $other->id]))
            ->assertOk()->assertViewHas('retentionPage', fn ($p) => $p->total() === 1 && $p->first()->user_id === $teacherB->id);

        // KPI Học vụ: bảng 6 nhóm / 15 mục, Lỗi nghiêm trọng → 0%, lưu nháp không dùng cho lương, chốt thì dùng.
        $lead = $this->userWithRole('academic_lead', ['name' => 'Học thuật Chấm']);
        $criteria = \App\Models\KpiCriterion::active()->ordered()->get();
        $this->assertCount(15, $criteria);
        $scores = $criteria->mapWithKeys(fn ($c) => [$c->id => 100])->all();
        $first = $criteria->first();

        $this->actingAs($lead)->get(route('kpi.evaluate', ['userId' => $this->academicStaff->id, 'period' => '2026-09']))
            ->assertOk()
            ->assertSee('KPI tháng — Học vụ P3')
            ->assertSeeInOrder(['Mã', 'Tiêu chí', 'Quỹ (VNĐ)', 'Ngưỡng 100', 'Ngưỡng 50', 'Thực tế', '% Đạt', 'Tiền KPI'])
            ->assertSee('1. Chăm sóc học viên')
            ->assertSee('Lỗi nghiêm trọng')
            ->assertSee('Tổng tiền KPI dự tính:')
            ->assertSee('Chi tiết điểm KPI theo nhóm')->assertSee('Xếp loại tháng')->assertSee('Cảnh báo hiệu suất')
            ->assertSee('Điểm tốt')->assertSee('Điểm cần cải thiện')->assertSee('Hành động tháng sau')
            ->assertSee('Lưu nháp')->assertSee('Chốt KPI tháng');

        $this->actingAs($lead)->post(route('kpi.evaluate.store', $this->academicStaff->id), [
            'month' => 9, 'year' => 2026, 'score' => $scores, 'critical' => [$first->id => 1], 'actual' => [$first->id => '60%'],
            'strengths' => 'Chăm sóc tốt', 'action' => 'draft',
        ])->assertRedirect();
        $evaluation = \App\Models\KpiEvaluation::where('user_id', $this->academicStaff->id)->firstOrFail();
        $this->assertSame('draft', $evaluation->status);
        $item = $evaluation->items()->where('kpi_criterion_id', $first->id)->first();
        $this->assertEquals(0, (float) $item->score);
        $this->assertTrue($item->critical_error);
        $this->assertSame('60%', $item->actual);
        $this->assertLessThan(100, (float) $evaluation->total_score);

        $this->actingAs($this->admin)->get(route('kpi.monthly', ['period' => '2026-09']))
            ->assertOk()->assertSee('Tổng hợp KPI &amp; Đánh giá tháng 09/2026', false)->assertSee('Bản nháp')->assertSee('Xếp loại');

        $this->actingAs($lead)->post(route('kpi.evaluate.store', $this->academicStaff->id), [
            'month' => 9, 'year' => 2026, 'score' => $scores, 'action' => 'confirm',
        ])->assertRedirect();
        $this->assertSame('confirmed', $evaluation->fresh()->status);
        $this->assertEquals(100, (float) $evaluation->fresh()->total_score);
        [$grade] = \App\Models\KpiEvaluation::gradeFor(100);
        $this->assertSame('A', $grade);
    }
}
