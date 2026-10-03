<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CrmCustomer;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * "Lịch hẹn test hôm nay": chip lọc nhanh ở CRM + ô số trên Tổng quan (Admin / Quản lý cơ sở / Học vụ),
 * đếm theo phạm vi khách CRM của người xem (chi nhánh), bỏ khách Thất bại.
 */
class TestAppointmentsTodayTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Branch $otherBranch;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-03 08:00:00');
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Cơ sở A', 'code' => 'CSA', 'is_active' => true]);
        $this->otherBranch = Branch::create(['name' => 'Cơ sở B', 'code' => 'CSB', 'is_active' => true]);

        $this->lead('test_scheduled', '2026-10-03 09:00', ['name' => 'Hẹn Sáng Nay']);
        $this->lead('tested', '2026-10-03 07:30', ['name' => 'Đã Làm Sáng Nay', 'appointment_type' => 'online']);
        $this->lead('test_scheduled', '2026-10-04 09:00', ['name' => 'Hẹn Ngày Mai']);
        $this->lead('test_scheduled', '2026-10-02 15:00', ['name' => 'Hẹn Hôm Qua']);
        $this->lead('lost', '2026-10-03 10:00', ['name' => 'Thất Bại Hôm Nay']);
        $this->lead('test_scheduled', '2026-10-03 14:00', ['name' => 'Cơ Sở Khác', 'branch_id' => $this->otherBranch->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_crm_chip_counts_today_within_branch_scope_and_filters_list_by_time(): void
    {
        $staff = $this->userWithRole('academic_staff');

        $this->actingAs($staff)->get(route('crm.customers.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('chipCounts.test_today', 2));

        $this->actingAs($staff)->get(route('crm.customers.index', ['test_today' => 1]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('customers.data', 2)
                // Xếp theo giờ hẹn: 07:30 trước 09:00.
                ->where('customers.data.0.name', 'Đã Làm Sáng Nay')
                ->where('customers.data.0.test_today_at', '07:30')
                ->where('customers.data.0.test_today_type', 'Online')
                ->where('customers.data.1.name', 'Hẹn Sáng Nay')
                ->where('customers.data.1.test_today_type', 'Tại cơ sở'));

        // Admin thấy mọi chi nhánh.
        $this->actingAs($this->userWithRole('admin'))->get(route('crm.customers.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('chipCounts.test_today', 3));
    }

    public function test_dashboard_shows_today_count_with_link_for_academic_staff_and_operations(): void
    {
        $link = route('crm.customers.index', ['test_today' => 1]);

        $this->actingAs($this->userWithRole('academic_staff'))->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('kpis.testToday.count', 2)
                ->where('kpis.testToday.pending', 1)
                ->where('kpis.testToday.url', $link));

        $this->actingAs($this->userWithRole('admin'))->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('roleDashboard.queues', fn ($queues) => collect($queues)->contains(fn ($queue) => $queue['label'] === 'Lịch hẹn test hôm nay'
                    && $queue['value'] === 3 && $queue['href'] === $link && $queue['hint'] === '2 chưa làm bài')));
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function lead(string $stage, string $appointmentAt, array $attributes = []): CrmCustomer
    {
        $phone = '09'.random_int(10000000, 99999999);

        return CrmCustomer::create(array_merge([
            'code' => CrmCustomer::generateCode(),
            'name' => 'Lead '.$stage,
            'phone' => $phone,
            'phone_normalized' => $phone,
            'branch_id' => $this->branch->id,
            'source' => 'Facebook Ads',
            'stage' => $stage,
            'appointment_at' => $appointmentAt,
            'appointment_type' => 'offline',
        ], $attributes));
    }
}
