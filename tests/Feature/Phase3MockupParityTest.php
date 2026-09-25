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
}
