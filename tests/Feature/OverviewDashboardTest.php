<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\CrmCustomer;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Tổng quan thiết kế lại (28/10/2026): bố cục theo vai trò, "Cần bạn xử lý" gộp (quá hạn lên đầu), lớp học hôm nay,
 * so sánh cơ sở, phễu khách, ca dạy tiếp theo của giáo viên.
 */
class OverviewDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Branch $otherBranch;

    private User $teacher;

    private ClassSession $todaySession;

    private ClassSession $pastSession;

    protected function setUp(): void
    {
        parent::setUp();
        // Thứ 2, 17:00: buổi 17:30 hôm nay còn 30 phút.
        Carbon::setTestNow('2026-10-05 17:00:00');
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Cơ sở A', 'code' => 'CSA', 'is_active' => true]);
        $this->otherBranch = Branch::create(['name' => 'Cơ sở B', 'code' => 'CSB', 'is_active' => true]);
        $this->teacher = $this->userWithRole('teacher_fulltime', $this->branch);

        $course = Course::create(['code' => 'STA', 'name' => 'Starters', 'tuition_fee' => 1, 'is_active' => true]);
        $class = ClassModel::create(['code' => 'ST1', 'name' => 'Lớp ST1', 'course_id' => $course->id, 'branch_id' => $this->branch->id, 'teacher_id' => $this->teacher->id, 'max_capacity' => 10, 'status' => 'active']);
        $session = fn (string $date) => ClassSession::create(['class_id' => $class->id, 'branch_id' => $this->branch->id, 'date' => $date, 'shift_name' => 'Ca 1', 'start_time' => '17:30', 'end_time' => '19:00', 'teacher_id' => $this->teacher->id, 'status' => 'scheduled']);
        $this->todaySession = $session('2026-10-05');
        $this->pastSession = $session('2026-10-03');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_teacher_sees_next_shift_and_unattended_session_in_todo(): void
    {
        $overview = $this->actingAs($this->teacher)->get(route('dashboard'))->assertOk()->inertiaProps('overview');

        $this->assertSame('teaching', $overview['kind']);
        $this->assertSame('Chào buổi chiều, '.$this->teacher->name, $overview['greeting']['title']);
        $this->assertSame($this->todaySession->id, $overview['shifts']['next']['id']);
        $this->assertSame(30, $overview['shifts']['next']['minutesToStart']);
        $this->assertFalse($overview['shifts']['next']['checkedIn']);
        // Chưa dạy giờ nào trong tháng: lương tạm tính 0 đ, không phải "Chưa có đơn giá".
        $this->assertSame(Money::format(0), $overview['shifts']['salary']['amount']);

        $first = $overview['todo']['items'][0];
        $this->assertSame('overdue', $first['tone']);
        $this->assertStringStartsWith('Điểm danh Lớp ST1', $first['title']);
        $this->assertStringContainsString('session='.$this->pastSession->id, $first['href']);
    }

    public function test_academic_staff_gets_overdue_calls_first_and_branch_sessions(): void
    {
        $staff = $this->userWithRole('academic_staff', $this->branch);
        $this->lead(['name' => 'Gọi Tối Nay', 'stage' => 'consulting', 'next_follow_up_at' => '2026-10-05 20:00']);
        $this->lead(['name' => 'Gọi Hôm Qua', 'stage' => 'consulting', 'next_follow_up_at' => '2026-10-04 10:00']);

        $overview = $this->actingAs($staff)->get(route('dashboard'))->assertOk()->inertiaProps('overview');

        $this->assertSame('personal', $overview['kind']);
        $this->assertNull($overview['funnel']);
        $calls = collect($overview['todo']['items'])->filter(fn ($i) => str_starts_with($i['title'], 'Gọi lại'))->values();
        $this->assertSame(['Gọi lại: Gọi Hôm Qua', 'Gọi lại: Gọi Tối Nay'], $calls->pluck('title')->all());
        $this->assertSame(['overdue', 'today'], $calls->pluck('tone')->all());
        $this->assertSame('overdue', $overview['todo']['items'][0]['tone']);

        $this->assertSame(1, $overview['todaySessions']['total']);
        $this->assertSame('Sắp bắt đầu', $overview['todaySessions']['items'][0]['status']);
    }

    public function test_sales_sees_funnel_of_own_month_customers(): void
    {
        $sales = $this->userWithRole('sales_consultant', $this->branch);
        $this->lead(['name' => 'Mới', 'stage' => 'new', 'assigned_user_id' => $sales->id]);
        $this->lead(['name' => 'Đã test', 'stage' => 'tested', 'assigned_user_id' => $sales->id]);
        $this->lead(['name' => 'Đã chốt', 'stage' => 'won', 'assigned_user_id' => $sales->id]);
        $this->lead(['name' => 'Của người khác', 'stage' => 'won']);

        $overview = $this->actingAs($sales)->get(route('dashboard'))->assertOk()->inertiaProps('overview');

        $this->assertSame('sales', $overview['kind']);
        $this->assertSame(
            ['Khách mới' => 3, 'Đã tư vấn' => 2, 'Hẹn test' => 2, 'Đã test' => 2, 'Đã chốt' => 1],
            collect($overview['funnel']['rows'])->pluck('value', 'label')->all(),
        );
    }

    public function test_admin_compares_branches_and_manager_sees_today_sessions(): void
    {
        $this->lead(['name' => 'Lead A']);
        $this->lead(['name' => 'Lead B', 'branch_id' => $this->otherBranch->id]);

        $page = $this->actingAs($this->userWithRole('admin', $this->branch))->get(route('dashboard'))->assertOk()
            // Thiết kế lại 31/10: cảnh báo dạng ô mức độ + khối thu gọn trên mobile vẫn có trong HTML.
            ->assertSee('Cảnh báo vận hành')
            ->assertSee('So sánh cơ sở trong tháng')
            ->assertSee('Doanh thu theo tuần')
            ->assertSee('data-overview-alerts', false);
        $overview = $page->inertiaProps('overview');
        $this->assertSame('admin', $overview['kind']);
        $this->assertCount(4, $overview['kpis']);
        $this->assertSame('2', $overview['kpis'][2]['value']);
        $this->assertSame([1, 1], collect($overview['branches'])->pluck('leads')->all());
        $this->assertCount(5, $overview['revenueWeeks']);
        $this->assertNull($overview['todaySessions']);

        $overview = $this->actingAs($this->userWithRole('manager', $this->branch))->get(route('dashboard'))->assertOk()->inertiaProps('overview');
        $this->assertSame('manager', $overview['kind']);
        $this->assertNull($overview['branches']);
        $this->assertSame([$this->todaySession->id], collect($overview['todaySessions']['items'])->pluck('id')->all());
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
