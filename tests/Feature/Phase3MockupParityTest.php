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
}
