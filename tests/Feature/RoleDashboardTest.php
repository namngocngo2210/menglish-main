<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\CrmCustomer;
use App\Models\CrmTrialBooking;
use App\Models\User;
use App\Models\WorkTask;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Tổng quan theo vai trò (chủ dự án 03/10/2026): Admin / Quản lý giữ bảng điều hành; vai trò khác thấy "Việc của bạn":
 * lịch hẹn 7 ngày tới, đầu việc cần xử lý, việc của tôi trong tuần — theo phạm vi dữ liệu của mình.
 */
class RoleDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Branch $otherBranch;

    private User $staff;

    private User $sales;

    protected function setUp(): void
    {
        parent::setUp();
        // Thứ 7: tuần (đến Chủ nhật) còn 2 ngày, lịch 7 ngày tới kéo sang tuần sau.
        Carbon::setTestNow('2026-10-03 08:00:00');
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Cơ sở A', 'code' => 'CSA', 'is_active' => true]);
        $this->otherBranch = Branch::create(['name' => 'Cơ sở B', 'code' => 'CSB', 'is_active' => true]);
        $this->staff = $this->userWithRole('academic_staff', $this->branch);
        $this->sales = $this->userWithRole('sales_consultant', $this->branch);

        $this->lead(['name' => 'Test Sáng Nay', 'stage' => 'test_scheduled', 'appointment_at' => '2026-10-03 09:00', 'assigned_user_id' => $this->sales->id]);
        $this->lead(['name' => 'Gọi Lại Quá Hạn', 'stage' => 'consulting', 'next_follow_up_at' => '2026-10-02 10:00']);
        $this->lead(['name' => 'Gọi Lại Thứ Ba', 'stage' => 'consulting', 'next_follow_up_at' => '2026-10-06 14:00']);
        $this->lead(['name' => 'Test Tuần Sau', 'stage' => 'test_scheduled', 'appointment_at' => '2026-10-12 09:00']);
        $this->lead(['name' => 'Cơ Sở B Ngày Mai', 'stage' => 'test_scheduled', 'appointment_at' => '2026-10-04 09:00', 'branch_id' => $this->otherBranch->id]);

        $trialLead = $this->lead(['name' => 'Học Thử Thứ Hai', 'stage' => 'tested']);
        $teacher = $this->userWithRole('teacher', $this->branch);
        $course = Course::create(['code' => 'STA', 'name' => 'Starters', 'tuition_fee' => 1, 'is_active' => true]);
        $class = ClassModel::create(['code' => 'ST1', 'name' => 'Lớp ST1', 'course_id' => $course->id, 'branch_id' => $this->branch->id, 'teacher_id' => $teacher->id, 'max_capacity' => 10, 'status' => 'active']);
        $session = ClassSession::create(['class_id' => $class->id, 'branch_id' => $this->branch->id, 'date' => '2026-10-05', 'shift_name' => 'Ca', 'start_time' => '18:00', 'end_time' => '19:30', 'teacher_id' => $teacher->id, 'status' => 'scheduled']);
        CrmTrialBooking::create(['customer_id' => $trialLead->id, 'class_id' => $class->id, 'class_session_id' => $session->id, 'booked_by' => $this->staff->id, 'status' => 'scheduled']);

        $task = fn (string $title, ?string $due, string $status) => WorkTask::create(['title' => $title, 'creator_id' => $this->sales->id, 'assignee_id' => $this->staff->id, 'branch_id' => $this->branch->id, 'due_date' => $due, 'task_type' => 'one_time', 'status' => $status]);
        $task('Việc hôm nay', '2026-10-03', 'new');
        $task('Việc quá hạn', '2026-09-28', 'overdue');
        $task('Việc tháng sau', '2026-11-10', 'new');
        $task('Việc đã xong', '2026-10-03', 'completed');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_academic_staff_sees_branch_agenda_queues_and_own_tasks(): void
    {
        $board = $this->actingAs($this->staff)->get(route('dashboard'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('roleDashboard.type', 'personal')->missing('kpis'))
            ->inertiaProps('roleDashboard');

        $agenda = collect($board['agenda']['days'])->flatMap(fn ($day) => collect($day['items'])->map(fn ($item) => $day['label'].' | '.$item['time'].' | '.$item['kindLabel'].' | '.$item['title']));
        $this->assertSame([
            'Hôm nay · 03/10 | 09:00 | Test đầu vào | Test Sáng Nay',
            'Thứ 2 · 05/10 | 18:00 | Học thử | Học Thử Thứ Hai',
            'Thứ 3 · 06/10 | 14:00 | Gọi lại | Gọi Lại Thứ Ba',
        ], $agenda->all());

        $stats = collect($board['stats'])->keyBy('label');
        $this->assertSame('1', $stats['Hẹn test hôm nay']['value']);
        $this->assertSame('1', $stats['Học thử 7 ngày tới']['value']);
        $this->assertSame('1', $stats['Cần gọi lại hôm nay']['value']);
        $this->assertSame('1 khách đã quá hạn', $stats['Cần gọi lại hôm nay']['hint']);
        $this->assertSame('2', $stats['Việc của tôi đến hạn tuần này']['value']);
        $this->assertSame('1 việc đã quá hạn', $stats['Việc của tôi đến hạn tuần này']['hint']);

        // Việc của tôi: chưa xong, hạn trong tuần hoặc quá hạn — quá hạn lên đầu.
        $this->assertSame(['Việc quá hạn', 'Việc hôm nay'], collect($board['myTasks']['items'])->pluck('title')->all());
        $this->assertTrue($board['myTasks']['items'][0]['overdue']);

        $this->assertContains('Khách chưa liên hệ >24h', collect($board['queues'])->pluck('label')->all());
    }

    public function test_sales_only_sees_own_customers(): void
    {
        $board = $this->actingAs($this->sales)->get(route('dashboard'))->assertOk()->inertiaProps('roleDashboard');

        $this->assertSame('Khách và việc bạn phụ trách', $board['scope']);
        $titles = collect($board['agenda']['days'])->flatMap(fn ($day) => collect($day['items'])->pluck('title'))->all();
        $this->assertSame(['Test Sáng Nay'], $titles);
    }

    public function test_follow_up_chip_lists_due_customers_by_deadline(): void
    {
        $this->actingAs($this->staff)->get(route('crm.customers.index', ['follow_up' => 1]))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('chipCounts.follow_up', 1)
                ->has('customers.data', 1)
                ->where('customers.data.0.name', 'Gọi Lại Quá Hạn')
                ->where('customers.data.0.follow_up_overdue', true));
    }

    public function test_admin_keeps_operations_board_and_academic_lead_gets_agenda(): void
    {
        $this->actingAs($this->userWithRole('admin', $this->branch))->get(route('dashboard'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('roleDashboard.type', 'admin'));

        $board = $this->actingAs($this->userWithRole('academic_lead', $this->branch))->get(route('dashboard'))->assertOk()
            ->assertSee('Tổng quan học thuật')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('roleDashboard.type', 'academic')->has('roleDashboard.myTasks'))
            ->inertiaProps('roleDashboard');
        // Học thuật: không kèm học thử / gọi lại khách của CRM.
        $kinds = collect($board['agenda']['days'])->flatMap(fn ($day) => collect($day['items'])->pluck('kind'))->unique()->values()->all();
        $this->assertEmpty(array_intersect($kinds, ['trial', 'call']));
    }

    public function test_teacher_gets_personal_board_without_crm_blocks(): void
    {
        $board = $this->actingAs($this->userWithRole('teacher', $this->branch))->get(route('dashboard'))->assertOk()->inertiaProps('roleDashboard');

        $this->assertSame('personal', $board['type']);
        $this->assertNotContains('Hẹn test hôm nay', collect($board['stats'])->pluck('label')->all());
        // Không có nguồn lịch hẹn → không có khối lịch.
        $this->assertNull($board['agenda']);
    }

    private function userWithRole(string $role, Branch $branch): User
    {
        $user = User::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function lead(array $attributes): CrmCustomer
    {
        $phone = '09'.random_int(10000000, 99999999);

        return CrmCustomer::create(array_merge([
            'code' => CrmCustomer::generateCode(),
            'phone' => $phone,
            'phone_normalized' => $phone,
            'branch_id' => $this->branch->id,
            'source' => 'Facebook Ads',
            'appointment_type' => 'offline',
        ], $attributes));
    }
}
