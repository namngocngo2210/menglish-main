<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\Penalty;
use App\Models\StaffAttendance;
use App\Models\StaffAttendanceRequest;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Giao diện điện thoại cho nhân sự: chấm công bằng ảnh khuôn mặt + GPS trong bán kính cơ sở (giờ máy chủ),
 * đi muộn → biên bản chờ giải trình, đơn bổ sung công / xin đi muộn / xin nghỉ duyệt qua hộp Việc cần duyệt,
 * cài đặt toạ độ + giờ làm việc theo cơ sở, màn quản lý và tổng hợp trên phiếu lương.
 */
class StaffMobileAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private const LAT = 21.0285;

    private const LNG = 105.8542;

    private Branch $branch;

    private Branch $otherBranch;

    private User $admin;

    private User $manager;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        Storage::fake('local');
        // Thứ 2, 05/10/2026 — trước 08:00 (giờ vào cơ sở).
        $this->travelTo(Carbon::parse('2026-10-05 07:55:00'));

        $this->branch = Branch::create([
            'name' => 'CN Cầu Giấy', 'code' => 'CG-ATT', 'address' => '1 Cầu Giấy', 'is_active' => true,
            'latitude' => self::LAT, 'longitude' => self::LNG, 'checkin_radius' => 100,
            'work_start_time' => '08:00', 'work_end_time' => '17:30', 'late_grace_minutes' => 5,
        ]);
        $this->otherBranch = Branch::create(['name' => 'CN Hà Đông', 'code' => 'HD-ATT', 'address' => '2 Hà Đông', 'is_active' => true]);
        $this->admin = $this->user('admin', null);
        $this->manager = $this->user('manager', $this->branch);
        $this->staff = $this->user('sales_consultant', $this->branch);
    }

    private function user(string $role, ?Branch $branch, array $attributes = []): User
    {
        $user = User::factory()->create($attributes + ['is_active' => true, 'branch_id' => $branch?->id]);
        $user->assignRole($role);

        return $user;
    }

    /** @return array<string, mixed> */
    private function punchData(string $kind = 'in', float $latOffset = 0.0002): array
    {
        return [
            'kind' => $kind,
            'latitude' => self::LAT + $latOffset,
            'longitude' => self::LNG,
            'accuracy' => 12,
            'photo' => UploadedFile::fake()->image('face.jpg', 480, 640),
        ];
    }

    private function punch(User $user, string $kind = 'in', float $latOffset = 0.0002)
    {
        return $this->actingAs($user)->from(route('mobile.home'))->post(route('mobile.punch'), $this->punchData($kind, $latOffset));
    }

    public function test_admin_sets_branch_coordinates_radius_and_working_hours(): void
    {
        $this->actingAs($this->admin)->put(route('branches.attendance', $this->otherBranch->id), [
            'latitude' => '20.9710', 'longitude' => '105.7788', 'checkin_radius' => 80,
            'work_start_time' => '08:30', 'work_end_time' => '18:00', 'late_grace_minutes' => 10,
        ])->assertRedirect(route('branches.index'))->assertSessionHasNoErrors();

        $branch = $this->otherBranch->fresh();
        $this->assertTrue($branch->hasCheckinLocation());
        $this->assertSame(80, $branch->checkin_radius);
        $this->assertSame('08:30', $branch->workStart());
        $this->assertSame(10, $branch->late_grace_minutes);

        $this->actingAs($this->admin)->put(route('branches.attendance', $this->otherBranch->id), [
            'latitude' => '20.97', 'longitude' => '105.77', 'checkin_radius' => 5,
            'work_start_time' => '18:00', 'work_end_time' => '08:00', 'late_grace_minutes' => 0,
        ])->assertSessionHasErrors(['checkin_radius', 'work_end_time']);

        $this->actingAs($this->staff)->put(route('branches.attendance', $this->branch->id), [])->assertForbidden();

        $this->actingAs($this->admin)->get(route('branches.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Branches/Index')
                ->where('branches.0.checkin.configured', true)
                ->where('branches.0.checkin.work_start', '08:00'));
    }

    public function test_staff_checks_in_inside_radius_with_photo_and_server_time(): void
    {
        $this->actingAs($this->staff)->get(route('mobile.home'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Mobile/CheckIn')
                ->where('branch.has_location', true)
                ->where('expected.start', '08:00')
                ->where('today', null));

        $this->punch($this->staff)->assertRedirect(route('mobile.home'))->assertSessionHas('success', 'Đã chấm công vào lúc 07:55.');

        $row = StaffAttendance::sole();
        $this->assertSame($this->branch->id, $row->branch_id);
        $this->assertSame('2026-10-05 07:55:00', $row->check_in_at->format('Y-m-d H:i:s'));
        $this->assertEqualsWithDelta(22, $row->check_in_distance, 2);
        $this->assertSame(12, $row->check_in_accuracy);
        $this->assertSame(0, $row->late_minutes);
        $this->assertNull($row->penalty_id);
        Storage::disk('local')->assertExists($row->check_in_photo);

        // Đã chấm vào thì không chấm vào lần nữa.
        $this->punch($this->staff)->assertSessionHasErrors('kind');

        // Chấm ra sớm hơn giờ ra của cơ sở → ghi số phút về sớm.
        $this->travelTo(Carbon::parse('2026-10-05 17:00:00'));
        $this->punch($this->staff, 'out')->assertSessionHas('success');
        $row->refresh();
        $this->assertSame('17:00', $row->check_out_at->format('H:i'));
        $this->assertSame(30, $row->early_minutes);
        $this->assertSame('Về sớm 30 phút', $row->statusLabel());
    }

    public function test_check_in_outside_radius_or_without_branch_location_is_blocked(): void
    {
        $this->punch($this->staff, 'in', 0.01)->assertSessionHasErrors('location');
        $this->assertStringContainsString('chỉ chấm công được trong bán kính 100 m', session('errors')->first('location'));

        $elsewhere = $this->user('sales_consultant', $this->otherBranch);
        $this->punch($elsewhere)->assertSessionHasErrors('location');
        $this->assertStringContainsString('chưa cài toạ độ chấm công', session('errors')->first('location'));

        $noBranch = $this->user('sales_consultant', null);
        $this->punch($noBranch)->assertSessionHasErrors('location');

        // Đúng toạ độ cơ sở khác cũng không được: chỉ tính cơ sở mình làm việc.
        $this->otherBranch->update(['latitude' => 20.9710, 'longitude' => 105.7788]);
        $this->actingAs($elsewhere)->post(route('mobile.punch'), ['latitude' => self::LAT, 'longitude' => self::LNG] + $this->punchData())
            ->assertSessionHasErrors('location');

        $this->punch($this->staff, 'out')->assertSessionHasErrors('kind');
        $this->assertSame(0, StaffAttendance::count());
    }

    public function test_locked_payroll_period_blocks_check_in(): void
    {
        PayrollPeriod::create([
            'code' => 'PR-LOCK', 'title' => 'Kỳ đã chốt', 'month' => 10, 'year' => 2026,
            'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'status' => 'approved',
        ]);

        $this->punch($this->staff)->assertSessionHasErrors('kind');
        $this->assertSame(0, StaffAttendance::count());
    }

    public function test_late_check_in_opens_pending_penalty_and_approved_late_request_cancels_it(): void
    {
        $this->travelTo(Carbon::parse('2026-10-05 08:20:00'));
        $this->punch($this->staff)->assertSessionHas('warning');

        $row = StaffAttendance::sole();
        $this->assertSame(20, $row->late_minutes);
        $penalty = Penalty::findOrFail($row->penalty_id);
        $this->assertSame('pending', $penalty->status);
        $this->assertEquals(0, $penalty->amount);
        $this->assertSame($this->staff->id, $penalty->user_id);
        $this->assertStringContainsString('Đi muộn 20 phút', $penalty->violation_type);

        $this->actingAs($this->staff)->post(route('mobile.requests.store'), [
            'type' => StaffAttendanceRequest::TYPE_LATE_EARLY, 'date_from' => '2026-10-05', 'reason' => 'Kẹt xe do mưa lớn',
        ])->assertRedirect(route('mobile.requests'))->assertSessionHasNoErrors();
        $request = StaffAttendanceRequest::sole();

        $this->actingAs($this->manager)->post(route('approvals.bulk'), [
            'action' => 'approve', 'items' => ['staff_attendance_request:'.$request->id],
        ])->assertRedirect();

        $this->assertSame(StaffAttendanceRequest::STATUS_APPROVED, $request->fresh()->status);
        $this->assertTrue($row->fresh()->late_excused);
        $this->assertSame('cancelled', $penalty->fresh()->status);
        $this->assertSame('Muộn có phép', $row->fresh()->statusLabel());
    }

    public function test_within_grace_minutes_is_not_late(): void
    {
        $this->travelTo(Carbon::parse('2026-10-05 08:05:00'));
        $this->punch($this->staff)->assertSessionHas('success');

        $this->assertSame(0, StaffAttendance::sole()->late_minutes);
        $this->assertSame(0, Penalty::count());
    }

    public function test_teaching_staff_late_is_measured_against_first_session(): void
    {
        $teacher = $this->user('teacher', $this->branch);
        $this->travelTo(Carbon::parse('2026-10-05 10:00:00'));
        $this->punch($teacher)->assertSessionHas('success');
        $this->assertSame(0, StaffAttendance::sole()->late_minutes);
        StaffAttendance::query()->delete();

        $course = Course::create(['name' => 'IELTS ATT', 'code' => 'IELTS-ATT', 'total_lessons' => 24, 'is_active' => true]);
        $class = ClassModel::create([
            'name' => 'Lớp ATT', 'code' => 'ATT-1', 'course_id' => $course->id, 'program' => $course->name, 'level' => 'B1',
            'branch_id' => $this->branch->id, 'status' => 'active', 'max_capacity' => 12, 'start_date' => '2026-09-01',
        ]);
        ClassSession::create([
            'class_id' => $class->id, 'branch_id' => $this->branch->id, 'date' => '2026-10-05',
            'start_time' => '18:00', 'end_time' => '19:30', 'teacher_id' => $teacher->id, 'status' => 'scheduled',
        ]);

        $this->travelTo(Carbon::parse('2026-10-05 18:12:00'));
        $this->punch($teacher)->assertSessionHas('warning');
        $row = StaffAttendance::sole();
        $this->assertSame('18:00', substr((string) $row->expected_start, 0, 5));
        $this->assertSame(12, $row->late_minutes);
    }

    public function test_correction_request_goes_to_branch_manager_and_creates_attendance_when_approved(): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 09:00:00'));
        $this->actingAs($this->staff)->post(route('mobile.requests.store'), [
            'type' => StaffAttendanceRequest::TYPE_CORRECTION, 'date_from' => '2026-10-05',
            'check_in_time' => '07:58', 'check_out_time' => '17:35', 'reason' => 'Điện thoại hết pin',
        ])->assertSessionHasNoErrors();
        $request = StaffAttendanceRequest::sole();
        $this->assertSame($this->branch->id, $request->branch_id);

        // Không bổ sung công cho ngày tương lai; giờ ra phải sau giờ vào.
        $this->actingAs($this->staff)->post(route('mobile.requests.store'), [
            'type' => StaffAttendanceRequest::TYPE_CORRECTION, 'date_from' => '2026-10-08', 'check_in_time' => '08:00', 'reason' => 'x',
        ])->assertSessionHasErrors('date_from');
        $this->actingAs($this->staff)->post(route('mobile.requests.store'), [
            'type' => StaffAttendanceRequest::TYPE_CORRECTION, 'date_from' => '2026-10-04', 'check_in_time' => '09:00', 'check_out_time' => '08:00', 'reason' => 'x',
        ])->assertSessionHasErrors('check_out_time');

        // Quản lý cơ sở khác không thấy, quản lý cùng cơ sở thấy trong hộp Việc cần duyệt.
        $otherManager = $this->user('manager', $this->otherBranch);
        $this->actingAs($otherManager)->get(route('mobile.approvals'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('sections', []));
        $this->actingAs($otherManager)->post(route('approvals.bulk'), [
            'action' => 'approve', 'items' => ['staff_attendance_request:'.$request->id],
        ]);
        $this->assertSame(StaffAttendanceRequest::STATUS_PENDING, $request->fresh()->status);

        $this->actingAs($this->manager)->get(route('mobile.approvals'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Mobile/Approvals')
                ->where('sections.0.key', 'staff_attendance_request')
                ->where('mobileNav.approvals', 1));
        $this->actingAs($this->manager)->get(route('approvals.show', ['staff_attendance_request', $request->id]))->assertOk()
            ->assertSee('Điện thoại hết pin');

        $this->actingAs($this->manager)->post(route('approvals.bulk'), [
            'action' => 'approve', 'items' => ['staff_attendance_request:'.$request->id],
        ])->assertRedirect();

        $row = StaffAttendance::sole();
        $this->assertSame(StaffAttendance::SOURCE_REQUEST, $row->source);
        $this->assertSame('2026-10-05 07:58', $row->check_in_at->format('Y-m-d H:i'));
        $this->assertSame('17:35', $row->check_out_at->format('H:i'));
        $this->assertSame(0, $row->late_minutes);
        $this->assertNull($row->check_in_photo);
    }

    public function test_reviewer_cannot_approve_own_request_and_requester_can_withdraw(): void
    {
        $this->actingAs($this->manager)->post(route('mobile.requests.store'), [
            'type' => StaffAttendanceRequest::TYPE_LEAVE, 'date_from' => '2026-10-07', 'date_to' => '2026-10-08', 'reason' => 'Việc gia đình',
        ])->assertSessionHasNoErrors();
        $own = StaffAttendanceRequest::sole();

        $this->actingAs($this->manager)->post(route('approvals.bulk'), ['action' => 'approve', 'items' => ['staff_attendance_request:'.$own->id]]);
        $this->assertSame(StaffAttendanceRequest::STATUS_PENDING, $own->fresh()->status);

        // Trùng đơn đang chờ cùng ngày → chặn.
        $this->actingAs($this->manager)->post(route('mobile.requests.store'), [
            'type' => StaffAttendanceRequest::TYPE_LEAVE, 'date_from' => '2026-10-08', 'reason' => 'Trùng',
        ])->assertSessionHasErrors('date_from');

        // Người khác không rút được đơn của mình.
        $this->actingAs($this->staff)->post(route('mobile.requests.cancel', $own->id))->assertSessionHasErrors('request');
        $this->actingAs($this->manager)->post(route('mobile.requests.cancel', $own->id))->assertRedirect(route('mobile.requests'));
        $this->assertSame(StaffAttendanceRequest::STATUS_CANCELLED, $own->fresh()->status);
    }

    public function test_reject_request_notifies_requester_with_reason(): void
    {
        $this->actingAs($this->staff)->post(route('mobile.requests.store'), [
            'type' => StaffAttendanceRequest::TYPE_LEAVE, 'date_from' => '2026-10-07', 'reason' => 'Nghỉ ốm',
        ]);
        $request = StaffAttendanceRequest::sole();

        $this->actingAs($this->manager)->post(route('approvals.bulk'), [
            'action' => 'reject', 'items' => ['staff_attendance_request:'.$request->id], 'reason' => 'Thiếu giấy khám',
        ])->assertRedirect();

        $request->refresh();
        $this->assertSame(StaffAttendanceRequest::STATUS_REJECTED, $request->status);
        $this->assertSame('Thiếu giấy khám', $request->rejection_reason);
        $this->assertDatabaseHas('admin_notifications', ['user_id' => $this->staff->id, 'type' => 'staff_attendance_request']);

        $this->actingAs($this->staff)->get(route('mobile.requests'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Mobile/Requests')
                ->where('requests.0.status', 'rejected')
                ->where('requests.0.rejection_reason', 'Thiếu giấy khám'));
    }

    public function test_photo_is_private_to_owner_and_branch_viewers(): void
    {
        $this->punch($this->staff);
        $row = StaffAttendance::sole();
        $url = route('staff-attendance.photo', [$row->id, 'in']);

        $this->actingAs($this->staff)->get($url)->assertOk();
        $this->actingAs($this->manager)->get($url)->assertOk();
        $this->actingAs($this->user('sales_consultant', $this->branch))->get($url)->assertForbidden();
        $this->actingAs($this->user('manager', $this->otherBranch))->get($url)->assertForbidden();
        $this->actingAs($this->staff)->get(route('staff-attendance.photo', [$row->id, 'out']))->assertNotFound();
    }

    public function test_manager_daily_board_shows_branch_staff_only(): void
    {
        $this->punch($this->staff);
        $elsewhere = $this->user('sales_consultant', $this->otherBranch, ['name' => 'Nhân sự Hà Đông']);

        $this->actingAs($this->manager)->get(route('staff-attendance.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('StaffAttendance/Index')
                ->where('stats.checked_in', 1)
                ->where('rows.data', fn ($rows) => collect($rows)->pluck('user.id')->contains($this->staff->id)
                    && ! collect($rows)->pluck('user.id')->contains($elsewhere->id)));

        $this->actingAs($this->manager)->get(route('staff-attendance.index', ['status' => 'missing']))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('rows.data', fn ($rows) => ! collect($rows)->pluck('user.id')->contains($this->staff->id)));

        $row = StaffAttendance::sole();
        $this->actingAs($this->manager)->get(route('staff-attendance.show', $row->id))->assertOk()->assertSee('Xem vị trí trên bản đồ');

        $this->actingAs($this->admin)->get(route('staff-attendance.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('rows.data', fn ($rows) => collect($rows)->pluck('user.id')->contains($elsewhere->id))
                ->where('unconfigured', ['CN Hà Đông']));

        $this->actingAs($this->staff)->get(route('staff-attendance.index'))->assertForbidden();
    }

    public function test_leave_and_attendance_are_summarised_on_payslip(): void
    {
        $this->manager->update(['base_salary' => 8000000]);
        $this->travelTo(Carbon::parse('2026-10-05 08:30:00'));
        $this->punch($this->manager);
        $this->actingAs($this->manager)->post(route('mobile.requests.store'), [
            'type' => StaffAttendanceRequest::TYPE_LEAVE, 'date_from' => '2026-10-07', 'date_to' => '2026-10-09', 'reason' => 'Nghỉ phép năm',
        ]);
        $this->actingAs($this->admin)->post(route('approvals.bulk'), [
            'action' => 'approve', 'items' => ['staff_attendance_request:'.StaffAttendanceRequest::sole()->id],
        ]);

        $period = PayrollPeriod::create([
            'code' => 'PR-ATT', 'title' => 'Tháng 10', 'month' => 10, 'year' => 2026,
            'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'status' => 'draft',
        ]);
        $period->calculatePayrollForPeriod();
        $record = PayrollRecord::where('payroll_period_id', $period->id)->where('user_id', $this->manager->id)->firstOrFail();

        $this->assertSame(1, data_get($record->calculation_details, 'attendance.days'));
        $this->assertSame(1, data_get($record->calculation_details, 'attendance.late_count'));
        $this->assertSame(30, data_get($record->calculation_details, 'attendance.late_minutes'));
        $this->assertSame(3, data_get($record->calculation_details, 'attendance.leave_days'));
        $this->assertFalse($period->fresh()->hasChangesSinceCalculation());

        $this->travelTo(Carbon::parse('2026-10-05 17:40:00'));
        $this->punch($this->manager, 'out');
        $this->assertTrue($period->fresh()->hasChangesSinceCalculation());

        $this->actingAs($this->admin)->get(route('payroll.records.show', $record->id))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('dailyAttendance.leave_days', 3));
    }

    public function test_mobile_pages_render_for_staff_with_role_based_links(): void
    {
        $teacher = $this->user('teacher', $this->branch);
        $this->actingAs($teacher)->get(route('mobile.requests'))->assertOk()
            ->assertSee('Bổ sung công')
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Mobile/Requests')
                ->where('roleRequests', fn ($links) => collect($links)->pluck('label')->contains('Đề xuất sửa giáo trình')
                    && ! collect($links)->pluck('label')->contains('Hoàn tiền & khất nợ học phí')));
        // Không duyệt được nguồn nào → không có tab "Cần duyệt".
        $this->actingAs($this->staff)->get(route('mobile.history'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('mobileNav.approvals', null));
        $this->actingAs($this->staff)->get(route('mobile.approvals'))->assertForbidden();
        $this->actingAs($teacher)->get(route('mobile.history'))->assertOk()->assertSee('Tháng 10/2026');
        $this->actingAs($teacher)->get(route('mobile.home'))->assertOk()->assertSee('Chấm công vào');

        $student = $this->user('student', $this->branch);
        $this->actingAs($student)->get(route('mobile.home'))->assertForbidden();
    }

    /** "Của tôi" trên sidebar: điện thoại mở Chấm công /m, máy tính mở Thông báo (không chuyển sang /m); tab Chấm công ẩn trên máy tính. */
    public function test_personal_menu_opens_check_in_only_on_phone(): void
    {
        $this->actingAs($this->staff)->get(route('notifications.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('shell.sidebar.groups', fn ($groups) => ($personal = collect($groups)->firstWhere('id', 'personal'))
                    && $personal['url'] === route('mobile.home')
                    && $personal['desktop_url'] === route('notifications.index'))
                ->where('shell.workspace.tabs', fn ($tabs) => collect($tabs)->firstWhere('route', 'mobile.home')['mobile_only'] === true
                    && collect($tabs)->firstWhere('route', 'notifications.index')['mobile_only'] === false));

        // Khu không có mục chỉ dành cho điện thoại: không có link riêng cho máy tính.
        $this->actingAs($this->staff)->get(route('notifications.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('shell.sidebar.groups', fn ($groups) => collect($groups)
                ->reject(fn ($group) => $group['id'] === 'personal')->every(fn ($group) => $group['desktop_url'] === null)));
    }
}
