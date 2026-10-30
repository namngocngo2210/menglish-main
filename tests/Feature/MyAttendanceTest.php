<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\Holiday;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\Penalty;
use App\Models\StaffAttendance;
use App\Models\StaffAttendanceRequest;
use App\Models\TeacherTimesheet;
use App\Models\User;
use App\Support\Approvals\ApprovalInboxService;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Chấm công của nhân sự (/cham-cong, trước là giao diện điện thoại /m): chấm công bằng ảnh khuôn mặt + GPS trong bán kính cơ sở (giờ máy chủ),
 * đi muộn → biên bản chờ giải trình, đơn bổ sung công / xin đi muộn / xin nghỉ duyệt qua hộp Việc cần duyệt,
 * cài đặt toạ độ + giờ làm việc theo cơ sở, màn quản lý và tổng hợp trên phiếu lương.
 */
class MyAttendanceTest extends TestCase
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
        return $this->actingAs($user)->from(route('my-attendance.home'))->post(route('my-attendance.punch'), $this->punchData($kind, $latOffset));
    }

    /** Buổi học ở cơ sở Cầu Giấy ngày $date (người dạy / trợ giảng / loại buổi qua $attributes). */
    private function sessionFor(string $date, array $attributes = []): ClassSession
    {
        $course = Course::firstOrCreate(['code' => 'SCH-ATT'], ['name' => 'Lịch ATT', 'total_lessons' => 24, 'is_active' => true]);
        $class = ClassModel::firstOrCreate(['code' => 'SCH-1'], [
            'name' => 'Lớp lịch', 'course_id' => $course->id, 'program' => $course->name, 'level' => 'B1',
            'branch_id' => $this->branch->id, 'status' => 'active', 'max_capacity' => 12, 'start_date' => '2026-09-01',
        ]);

        return ClassSession::create($attributes + [
            'class_id' => $class->id, 'branch_id' => $this->branch->id, 'date' => $date,
            'start_time' => '18:00', 'end_time' => '19:30', 'status' => 'scheduled',
        ]);
    }

    public function test_part_time_teacher_can_only_check_in_on_days_with_sessions(): void
    {
        $teacher = $this->user('teacher_parttime', $this->branch);
        $this->punch($teacher)->assertSessionHasErrors(['kind' => 'Hôm nay bạn không có buổi dạy / dạy thay / học bù nên không cần chấm công.']);

        // Buổi đã hủy không tính.
        $cancelled = $this->sessionFor('2026-10-05', ['teacher_id' => $teacher->id, 'status' => 'cancelled']);
        $this->punch($teacher)->assertSessionHasErrors('kind');
        $this->assertSame(0, StaffAttendance::count());

        // Dạy thay: GV của buổi là người này (lớp của người khác) → chấm được.
        $cancelled->update(['status' => 'scheduled']);
        $this->punch($teacher)->assertSessionHasNoErrors();
        $this->assertSame(1, StaffAttendance::count());

        // Trợ giảng của buổi học bù cũng chấm được.
        $assistant = $this->user('assistant', $this->branch);
        $this->punch($assistant)->assertSessionHasErrors('kind');
        $this->sessionFor('2026-10-05', ['assistant_id' => $assistant->id, 'type' => ClassSession::TYPE_MAKEUP]);
        $this->punch($assistant)->assertSessionHasNoErrors();
    }

    public function test_full_time_staff_cannot_check_in_on_holidays(): void
    {
        $holiday = Holiday::create(['code' => 'HOL-ATT-1', 'name' => 'Nghỉ bù', 'start_date' => '2026-10-05', 'end_date' => '2026-10-05', 'is_system_wide' => true]);
        $this->punch($this->staff)->assertSessionHasErrors(['kind' => 'Hôm nay là ngày nghỉ (Nghỉ bù), không chấm công.']);

        // Nghỉ riêng của cơ sở khác: cơ sở mình vẫn làm việc.
        $holiday->update(['is_system_wide' => false]);
        $holiday->branches()->sync([$this->otherBranch->id]);
        $this->punch($this->staff)->assertSessionHasNoErrors();

        $holiday->branches()->sync([$this->branch->id]);
        $this->actingAs($this->staff)->get(route('my-attendance.home'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('blocked', 'Hôm nay là ngày nghỉ (Nghỉ bù), không chấm công.'));
    }

    public function test_check_in_page_shows_why_punching_is_blocked(): void
    {
        $teacher = $this->user('teacher_parttime', $this->branch);
        $this->actingAs($teacher)->get(route('my-attendance.home'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('blocked', 'Hôm nay bạn không có buổi dạy / dạy thay / học bù nên không cần chấm công.'));
        $this->actingAs($this->staff)->get(route('my-attendance.home'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('blocked', null));
    }

    public function test_correction_request_is_blocked_on_days_without_work(): void
    {
        $teacher = $this->user('teacher_parttime', $this->branch);
        $this->travelTo(Carbon::parse('2026-10-06 09:00:00'));
        $payload = ['type' => StaffAttendanceRequest::TYPE_CORRECTION, 'date_from' => '2026-10-05', 'check_in_time' => '17:50', 'reason' => 'Quên chấm'];

        $this->actingAs($teacher)->post(route('my-attendance.requests.store'), $payload)->assertSessionHasErrors('date_from');
        $this->sessionFor('2026-10-05', ['teacher_id' => $teacher->id]);
        $this->actingAs($teacher)->post(route('my-attendance.requests.store'), $payload)->assertSessionHasNoErrors();
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
        $this->actingAs($this->staff)->get(route('my-attendance.home'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('MyAttendance/CheckIn')
                ->where('branch.has_location', true)
                ->where('expected.start', '08:00')
                ->where('today', null));

        $this->punch($this->staff)->assertRedirect(route('my-attendance.home'))->assertSessionHas('success', 'Đã chấm công vào lúc 07:55.');

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
        $this->actingAs($elsewhere)->post(route('my-attendance.punch'), ['latitude' => self::LAT, 'longitude' => self::LNG] + $this->punchData())
            ->assertSessionHasErrors('location');

        $this->punch($this->staff, 'out')->assertSessionHasErrors('kind');
        $this->assertSame(0, StaffAttendance::count());
    }

    public function test_staff_working_at_several_branches_checks_in_at_the_nearest_one(): void
    {
        // CN Hà Đông cách Cầu Giấy ~8 km, giờ vào 07:30 (đến 07:55 là muộn).
        $this->otherBranch->update([
            'latitude' => 20.9710, 'longitude' => 105.7788, 'checkin_radius' => 100,
            'work_start_time' => '07:30', 'work_end_time' => '17:00', 'late_grace_minutes' => 5,
        ]);
        $this->staff->branches()->sync([$this->otherBranch->id]);

        $this->actingAs($this->staff)->get(route('my-attendance.home'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('branch.name', 'CN Cầu Giấy')
                ->where('branches', fn ($branches) => collect($branches)->pluck('name')->all() === ['CN Cầu Giấy', 'CN Hà Đông']));

        $this->actingAs($this->staff)->post(route('my-attendance.punch'), ['latitude' => 20.9711, 'longitude' => 105.7788] + $this->punchData())
            ->assertSessionHasNoErrors();
        $row = StaffAttendance::sole();
        $this->assertSame($this->otherBranch->id, $row->branch_id);
        $this->assertSame(25, $row->late_minutes);

        // Chấm ra ở cơ sở khác: bản ghi giữ cơ sở chấm vào.
        $this->travelTo(Carbon::parse('2026-10-05 17:30:00'));
        $this->punch($this->staff, 'out')->assertSessionHasNoErrors();
        $this->assertSame($this->otherBranch->id, $row->fresh()->branch_id);

        // Cơ sở làm thêm hiện trên bảng chấm công của quản lý cơ sở đó.
        $managerHd = $this->user('manager', $this->otherBranch);
        $this->actingAs($managerHd)->get(route('staff-attendance.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('rows.data', fn ($rows) => collect($rows)->pluck('user.id')->contains($this->staff->id)));
    }

    public function test_teacher_without_branch_checks_in_at_any_branch(): void
    {
        $teacher = $this->user('teacher_parttime', null);
        $this->sessionFor('2026-10-05', ['teacher_id' => $teacher->id]); // part-time chỉ chấm công ngày có buổi dạy

        $this->actingAs($teacher)->get(route('my-attendance.home'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('branch', null)
                ->where('branches', fn ($branches) => collect($branches)->pluck('name')->all() === ['CN Cầu Giấy', 'CN Hà Đông']));

        $this->punch($teacher)->assertSessionHasNoErrors();
        $this->assertSame($this->branch->id, StaffAttendance::sole()->branch_id);
    }

    public function test_locked_payroll_period_blocks_check_in(): void
    {
        PayrollPeriod::create([
            'code' => 'PR-LOCK', 'title' => 'Kỳ đã chốt', 'month' => 10, 'year' => 2026,
            'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'status' => PayrollPeriod::STATUS_PUBLISHED,
        ]);

        $this->punch($this->staff)->assertSessionHasErrors('kind');
        $this->assertSame(0, StaffAttendance::count());
    }

    /** TK-0013: đi muộn không lập biên bản từng lần — bảng lương cộng dồn phút muộn cả tháng. */
    public function test_late_check_in_records_minutes_without_penalty_and_approved_late_request_excuses_it(): void
    {
        $this->travelTo(Carbon::parse('2026-10-05 08:20:00'));
        $this->punch($this->staff)->assertSessionHas('warning');

        $row = StaffAttendance::sole();
        $this->assertSame(20, $row->late_minutes);
        $this->assertTrue($row->isLate());
        $this->assertNull($row->penalty_id);
        $this->assertSame(0, Penalty::count());

        $this->actingAs($this->staff)->post(route('my-attendance.requests.store'), [
            'type' => StaffAttendanceRequest::TYPE_LATE_EARLY, 'date_from' => '2026-10-05', 'reason' => 'Kẹt xe do mưa lớn',
        ])->assertRedirect(route('my-attendance.requests'))->assertSessionHasNoErrors();
        $request = StaffAttendanceRequest::sole();

        $this->actingAs($this->admin)->post(route('approvals.bulk'), [
            'action' => 'approve', 'items' => ['staff_attendance_request:'.$request->id],
        ])->assertRedirect();

        $this->assertSame(StaffAttendanceRequest::STATUS_APPROVED, $request->fresh()->status);
        $this->assertTrue($row->fresh()->late_excused);
        $this->assertFalse($row->fresh()->isLate());
        $this->assertSame(0, Penalty::count());
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

    /**
     * Lỗi 10/10/2026: hosting chạy giờ UTC (.env thiếu APP_TIMEZONE) → bấm chấm công 18:20 bị ghi 11:20, trước giờ ca,
     * không tính muộn. Giờ ứng dụng cố định giờ Việt Nam, không đọc APP_TIMEZONE.
     */
    public function test_check_in_time_is_vietnam_time_even_when_env_says_utc(): void
    {
        $previous = getenv('APP_TIMEZONE');
        putenv('APP_TIMEZONE=UTC');
        $_ENV['APP_TIMEZONE'] = $_SERVER['APP_TIMEZONE'] = 'UTC';
        try {
            $this->assertSame('Asia/Ho_Chi_Minh', (require config_path('app.php'))['timezone']);
        } finally {
            $previous === false ? putenv('APP_TIMEZONE') : putenv('APP_TIMEZONE='.$previous);
            unset($_ENV['APP_TIMEZONE'], $_SERVER['APP_TIMEZONE']);
        }

        $teacher = $this->user('teacher', $this->branch);
        $course = Course::create(['name' => 'IELTS TZ', 'code' => 'IELTS-TZ', 'total_lessons' => 24, 'is_active' => true]);
        $class = ClassModel::create([
            'name' => 'Lớp TZ', 'code' => 'TZ-1', 'course_id' => $course->id, 'program' => $course->name, 'level' => 'B1',
            'branch_id' => $this->branch->id, 'status' => 'active', 'max_capacity' => 12, 'start_date' => '2026-09-01',
        ]);
        $session = ClassSession::create([
            'class_id' => $class->id, 'branch_id' => $this->branch->id, 'date' => '2026-10-05',
            'start_time' => '18:00', 'end_time' => '19:30', 'teacher_id' => $teacher->id, 'status' => 'scheduled',
        ]);

        // Đăng nhập trước giờ ca, 20 phút sau giờ ca mới chấm (11:20 UTC = 18:20 giờ Việt Nam).
        $this->travelTo(Carbon::parse('2026-10-05 10:50:00', 'UTC'));
        $this->actingAs($teacher)->get(route('my-attendance.home'))->assertOk();
        $this->travelTo(Carbon::parse('2026-10-05 11:20:00', 'UTC'));

        $this->punch($teacher)->assertSessionHas('warning', fn (string $message) => str_contains($message, 'lúc 18:20'));
        $row = StaffAttendance::sole();
        $this->assertSame('2026-10-05 18:20', $row->check_in_at->format('Y-m-d H:i'));
        $this->assertSame(20, $row->late_minutes);
        // GV: đi muộn tính trên check-in ca dạy, chấm công không lập thêm biên bản (không phạt 2 lần).
        $this->assertNull($row->penalty_id);

        // Check-in ca dạy trên Cổng giáo viên cũng ghi 18:20, muộn 20 phút.
        $this->actingAs($teacher)->post(route('teacher.checkin'), ['session_ids' => [$session->id]])->assertSessionHasNoErrors();
        $timesheet = TeacherTimesheet::where('class_session_id', $session->id)->sole();
        $this->assertSame('18:20', substr((string) $timesheet->checkin_time, 0, 5));
        $this->assertSame(20, $timesheet->late_minutes);
    }

    public function test_correction_request_goes_to_admin_and_creates_attendance_when_approved(): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 09:00:00'));
        $this->actingAs($this->staff)->post(route('my-attendance.requests.store'), [
            'type' => StaffAttendanceRequest::TYPE_CORRECTION, 'date_from' => '2026-10-05',
            'check_in_time' => '07:58', 'check_out_time' => '17:35', 'reason' => 'Điện thoại hết pin',
        ])->assertSessionHasNoErrors();
        $request = StaffAttendanceRequest::sole();
        $this->assertSame($this->branch->id, $request->branch_id);

        // Không bổ sung công cho ngày tương lai; giờ ra phải sau giờ vào.
        $this->actingAs($this->staff)->post(route('my-attendance.requests.store'), [
            'type' => StaffAttendanceRequest::TYPE_CORRECTION, 'date_from' => '2026-10-08', 'check_in_time' => '08:00', 'reason' => 'x',
        ])->assertSessionHasErrors('date_from');
        $this->actingAs($this->staff)->post(route('my-attendance.requests.store'), [
            'type' => StaffAttendanceRequest::TYPE_CORRECTION, 'date_from' => '2026-10-04', 'check_in_time' => '09:00', 'check_out_time' => '08:00', 'reason' => 'x',
        ])->assertSessionHasErrors('check_out_time');

        // Chỉ Admin duyệt (06/10/2026): quản lý cơ sở không có nguồn đơn chấm công, không có tab Cần duyệt trên
        // điện thoại và bị 403 khi duyệt.
        $otherManager = $this->user('manager', $this->otherBranch);
        $inbox = app(ApprovalInboxService::class);
        $this->assertSame([], $inbox->counts($otherManager));
        $this->assertSame([], $inbox->counts($this->manager));
        $this->assertFalse($this->manager->can('staff_checkin.approve'));
        foreach ([$otherManager, $this->manager] as $manager) {
            $this->actingAs($manager)->get(route('approvals.index'))->assertForbidden();
            $this->actingAs($manager)->post(route('approvals.bulk'), [
                'action' => 'approve', 'items' => ['staff_attendance_request:'.$request->id],
            ])->assertForbidden();
        }
        $this->assertSame(StaffAttendanceRequest::STATUS_PENDING, $request->fresh()->status);

        $this->actingAs($this->admin)->get(route('approvals.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('shell.sidebar.groups', fn ($groups) => collect($groups)->firstWhere('id', 'approvals')['badge'] === 1));
        $this->actingAs($this->admin)->get(route('approvals.show', ['staff_attendance_request', $request->id]))->assertOk()
            ->assertSee('Điện thoại hết pin');

        $this->actingAs($this->admin)->post(route('approvals.bulk'), [
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
        $this->actingAs($this->manager)->post(route('my-attendance.requests.store'), [
            'type' => StaffAttendanceRequest::TYPE_LEAVE, 'date_from' => '2026-10-07', 'date_to' => '2026-10-08', 'reason' => 'Việc gia đình',
        ])->assertSessionHasNoErrors();
        $own = StaffAttendanceRequest::sole();

        // Luật của nguồn: người duyệt không tự duyệt đơn của mình (tầng service, dùng chung với hộp Cần duyệt).
        $this->assertFalse(app(ApprovalInboxService::class)->process($this->manager, 'approve', ['staff_attendance_request:'.$own->id])[0]['ok']);
        $this->assertSame(StaffAttendanceRequest::STATUS_PENDING, $own->fresh()->status);

        // Trùng đơn đang chờ cùng ngày → chặn.
        $this->actingAs($this->manager)->post(route('my-attendance.requests.store'), [
            'type' => StaffAttendanceRequest::TYPE_LEAVE, 'date_from' => '2026-10-08', 'reason' => 'Trùng',
        ])->assertSessionHasErrors('date_from');

        // Người khác không rút được đơn của mình.
        $this->actingAs($this->staff)->post(route('my-attendance.requests.cancel', $own->id))->assertSessionHasErrors('request');
        $this->actingAs($this->manager)->post(route('my-attendance.requests.cancel', $own->id))->assertRedirect(route('my-attendance.requests'));
        $this->assertSame(StaffAttendanceRequest::STATUS_CANCELLED, $own->fresh()->status);
    }

    public function test_reject_request_notifies_requester_with_reason(): void
    {
        $this->actingAs($this->staff)->post(route('my-attendance.requests.store'), [
            'type' => StaffAttendanceRequest::TYPE_LEAVE, 'date_from' => '2026-10-07', 'reason' => 'Nghỉ ốm',
        ]);
        $request = StaffAttendanceRequest::sole();

        $this->actingAs($this->admin)->post(route('approvals.bulk'), [
            'action' => 'reject', 'items' => ['staff_attendance_request:'.$request->id], 'reason' => 'Thiếu giấy khám',
        ])->assertRedirect();

        $request->refresh();
        $this->assertSame(StaffAttendanceRequest::STATUS_REJECTED, $request->status);
        $this->assertSame('Thiếu giấy khám', $request->rejection_reason);
        $this->assertDatabaseHas('admin_notifications', ['user_id' => $this->staff->id, 'type' => 'staff_attendance_request']);

        $this->actingAs($this->staff)->get(route('my-attendance.requests'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('MyAttendance/Requests')
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
        $this->actingAs($this->manager)->post(route('my-attendance.requests.store'), [
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

    public function test_attendance_pages_render_for_staff_with_role_based_links(): void
    {
        $teacher = $this->user('teacher', $this->branch);
        $this->actingAs($teacher)->get(route('my-attendance.requests'))->assertOk()
            ->assertSee('Bổ sung công')
            ->assertInertia(fn (AssertableInertia $page) => $page->component('MyAttendance/Requests')
                ->where('roleRequests', fn ($links) => collect($links)->pluck('label')->contains('Đề xuất sửa giáo trình')
                    && ! collect($links)->pluck('label')->contains('Hoàn tiền & khất nợ học phí')));
        $this->actingAs($teacher)->get(route('my-attendance.history'))->assertOk()->assertSee('Tháng 10/2026');
        $this->actingAs($teacher)->get(route('my-attendance.home'))->assertOk()->assertSee('Chấm công vào');

        $student = $this->user('student', $this->branch);
        $this->actingAs($student)->get(route('my-attendance.home'))->assertForbidden();
    }

    /** Chấm công là tab đầu của khu "Chấm công" (máy tính lẫn điện thoại) và có trong menu tài khoản; "Của tôi" không còn mục Chấm công. */
    public function test_check_in_lives_in_attendance_workspace_and_account_menu(): void
    {
        $this->actingAs($this->staff)->get(route('my-attendance.home'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('shell.checkin_url', route('my-attendance.home'))
                ->where('shell.workspace.id', 'timesheets')
                ->where('shell.workspace.tabs.0.route', 'my-attendance.home')
                ->where('shell.workspace.tabs.0.active', true)
                ->where('shell.workspace.tabs.1.route', 'my-attendance.history')
                ->where('shell.sidebar.groups', fn ($groups) => collect($groups)->firstWhere('id', 'timesheets')['url'] === route('my-attendance.home')
                    && collect($groups)->firstWhere('id', 'personal')['url'] === route('notifications.index')
                    && collect($groups)->firstWhere('id', 'approval_requests')['url'] === route('my-attendance.requests')));

        // Học viên không chấm công: không có mục Chấm công trong menu tài khoản.
        $student = $this->user('student', $this->branch);
        $this->actingAs($student)->get(route('profile.edit'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('shell.checkin_url', null));
    }

    /** Link /m cũ (đã lưu, ghim màn hình chính) chuyển sang trang mới. */
    public function test_old_mobile_links_redirect_to_new_pages(): void
    {
        $this->actingAs($this->staff);
        $this->get('/m')->assertRedirect('/cham-cong');
        $this->get('/m/lich-su')->assertRedirect('/cham-cong/lich-su');
        $this->get('/m/xin-duyet')->assertRedirect('/cham-cong/don');
        $this->get('/m/can-duyet')->assertRedirect('/approvals');
        $this->assertSame(url('/cham-cong'), route('my-attendance.home'));
    }
    public function test_admin_adjusts_attendance_with_reason_and_recalculates_lateness(): void
    {
        $this->travelTo(Carbon::parse('2026-10-05 08:30:00'));
        $this->punch($this->staff);
        $row = StaffAttendance::sole();
        $this->assertTrue($row->isLate());
        $this->assertNull($row->penalty_id);

        // Không ghi lý do → chặn; tự sửa công của mình → chặn.
        $this->actingAs($this->admin)->post(route('staff-attendance.adjust'), [
            'user_id' => $this->staff->id, 'work_date' => '2026-10-05', 'check_in_time' => '07:58',
        ])->assertSessionHasErrors('reason');
        $this->actingAs($this->admin)->post(route('staff-attendance.adjust'), [
            'user_id' => $this->admin->id, 'work_date' => '2026-10-05', 'check_in_time' => '07:58', 'reason' => 'x',
        ])->assertSessionHasErrors('user_id');

        $this->actingAs($this->admin)->post(route('staff-attendance.adjust'), [
            'user_id' => $this->staff->id, 'work_date' => '2026-10-05', 'check_in_time' => '07:58', 'check_out_time' => '17:35', 'reason' => 'Máy chủ lỗi, đối chiếu camera',
        ])->assertSessionHasNoErrors();
        $row->refresh();
        $this->assertSame('07:58', $row->check_in_at->format('H:i'));
        $this->assertSame('17:35', $row->check_out_at->format('H:i'));
        $this->assertFalse($row->isLate());
        $this->assertStringContainsString('Máy chủ lỗi', $row->note);
        $this->assertSame(0, Penalty::count());

        // Ngày chưa có lượt chấm: quản lý tạo ngày công (nhân sự quên chấm cả ngày).
        $this->actingAs($this->admin)->post(route('staff-attendance.adjust'), [
            'user_id' => $this->staff->id, 'work_date' => '2026-10-02', 'check_in_time' => '08:00', 'reason' => 'Quên chấm',
        ])->assertSessionHasNoErrors();
        $this->assertSame(StaffAttendance::SOURCE_MANUAL, StaffAttendance::forDay($this->staff->id, '2026-10-02')->source);

        $this->actingAs($this->admin)->get(route('staff-attendance.edit', ['user_id' => $this->staff->id, 'date' => '2026-10-05']))
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('StaffAttendance/Edit')->where('attendance.check_in', '07:58'));
    }

    public function test_admin_revokes_approved_leave_and_late_is_counted_again(): void
    {
        $this->actingAs($this->staff)->post(route('my-attendance.requests.store'), [
            'type' => StaffAttendanceRequest::TYPE_LATE_EARLY, 'date_from' => '2026-10-05', 'reason' => 'Đưa con đi khám',
        ])->assertSessionHasNoErrors();
        $request = StaffAttendanceRequest::sole();
        $this->actingAs($this->admin)->post(route('approvals.bulk'), ['action' => 'approve', 'items' => ['staff_attendance_request:'.$request->id]]);

        $this->travelTo(Carbon::parse('2026-10-05 08:30:00'));
        $this->punch($this->staff);
        $this->assertFalse(StaffAttendance::sole()->isLate());
        $this->assertNull(StaffAttendance::sole()->penalty_id);

        $this->actingAs($this->admin)->post(route('staff-attendance.requests.revoke', $request->id), ['reason' => 'Duyệt nhầm'])
            ->assertSessionHasNoErrors();
        $this->assertSame(StaffAttendanceRequest::STATUS_REVOKED, $request->fresh()->status);
        $row = StaffAttendance::sole();
        $this->assertTrue($row->isLate());
        // Đi muộn lại bị tính trên bảng lương (cộng dồn phút), không lập biên bản (TK-0013).
        $this->assertNull($row->penalty_id);
        $this->assertDatabaseHas('admin_notifications', ['user_id' => $this->staff->id, 'type' => 'staff_attendance_request', 'title' => 'Thu hồi đơn Xin đi muộn / về sớm ngày 05/10/2026']);

        // Đơn đã thu hồi không thu hồi lại được.
        $this->actingAs($this->admin)->post(route('staff-attendance.requests.revoke', $request->id), ['reason' => 'Lần 2'])->assertSessionHasErrors('request');
    }

    public function test_personal_schedule_overrides_branch_hours_and_weekly_day_off_blocks_punch(): void
    {
        // Ca chiều 14:00–21:00, nghỉ Chủ nhật.
        $this->staff->update(['work_start_time' => '14:00', 'work_end_time' => '21:00', 'weekly_days_off' => [7]]);

        $this->travelTo(Carbon::parse('2026-10-05 13:55:00'));
        $this->punch($this->staff)->assertSessionHas('success');
        $row = StaffAttendance::sole();
        $this->assertSame('14:00', substr((string) $row->expected_start, 0, 5));
        $this->assertSame(0, $row->late_minutes);

        $this->travelTo(Carbon::parse('2026-10-11 09:00:00')); // Chủ nhật
        $this->punch($this->staff)->assertSessionHasErrors('kind');
        $this->actingAs($this->manager)->get(route('staff-attendance.index', ['date' => '2026-10-11']))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('rows.data', fn ($rows) => collect($rows)
                ->firstWhere('user.id', $this->staff->id)['off'] === 'Hôm nay là ngày nghỉ cố định trong tuần của bạn, không chấm công.'));
    }

    public function test_check_out_after_midnight_closes_yesterday_and_reminder_is_sent_once(): void
    {
        $this->travelTo(Carbon::parse('2026-10-05 07:55:00'));
        $this->punch($this->staff);

        $this->travelTo(Carbon::parse('2026-10-05 21:45:00'));
        $this->artisan('staff-attendance:remind-checkout')->assertSuccessful();
        $this->artisan('staff-attendance:remind-checkout')->assertSuccessful();
        $this->assertSame(1, \App\Models\AdminNotification::where('user_id', $this->staff->id)->where('type', 'staff_checkout_reminder')->count());

        $this->travelTo(Carbon::parse('2026-10-06 00:40:00'));
        $this->punch($this->staff, 'out')->assertSessionHasNoErrors();
        $row = StaffAttendance::sole();
        $this->assertSame('2026-10-05', $row->work_date->toDateString());
        $this->assertSame('2026-10-06 00:40', $row->check_out_at->format('Y-m-d H:i'));
    }

    public function test_teacher_request_without_branch_is_filed_under_teaching_branch(): void
    {
        $teacher = $this->user('teacher', null);
        $this->sessionFor('2026-10-07', ['teacher_id' => $teacher->id]);
        $this->actingAs($teacher)->post(route('my-attendance.requests.store'), [
            'type' => StaffAttendanceRequest::TYPE_LEAVE, 'date_from' => '2026-10-07', 'reason' => 'Ốm',
        ])->assertSessionHasNoErrors();
        $request = StaffAttendanceRequest::sole();
        $this->assertSame($this->branch->id, (int) $request->branch_id);

        $this->actingAs($this->admin)->post(route('approvals.bulk'), ['action' => 'approve', 'items' => ['staff_attendance_request:'.$request->id]]);
        $this->assertSame(StaffAttendanceRequest::STATUS_APPROVED, $request->fresh()->status);
    }

    public function test_leave_days_skip_holidays_weekly_days_off_and_days_worked(): void
    {
        $this->staff->update(['weekly_days_off' => [7]]);
        $holiday = Holiday::create(['code' => 'HOL-ATT-9', 'name' => 'Nghỉ bù', 'start_date' => '2026-10-07', 'end_date' => '2026-10-07', 'is_system_wide' => true]);
        StaffAttendanceRequest::create([
            'user_id' => $this->staff->id, 'branch_id' => $this->branch->id, 'type' => StaffAttendanceRequest::TYPE_LEAVE,
            'date_from' => '2026-10-05', 'date_to' => '2026-10-11', 'reason' => 'Nghỉ phép', 'status' => StaffAttendanceRequest::STATUS_APPROVED,
        ]);
        $this->punch($this->staff); // 05/10 vẫn đi làm

        // 05→11/10 = 7 ngày, trừ ngày đi làm (05), ngày lễ (07), Chủ nhật (11) → 4 ngày nghỉ phép.
        $summary = app(\App\Services\StaffAttendance\StaffAttendanceService::class)->summary($this->staff->id, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));
        $this->assertSame(4, $summary['leave_days']);
        $this->assertNotNull($holiday);
    }
}
