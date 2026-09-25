<?php

namespace Tests\Feature;

use App\Models\AdminNotification;
use App\Models\Branch;
use App\Models\CrmCustomer;
use App\Models\User;
use App\Services\NotificationService;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create([
            'name' => 'Cơ sở Cầu Giấy',
            'code' => 'CG',
            'address' => 'Hà Nội',
            'phone' => '0900000000',
            'is_active' => true,
        ]);

        $this->admin = User::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Quản trị viên Hệ thống',
            'is_active' => true,
        ]);
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->admin->assignRole('admin');
    }

    public function test_stale_lead_older_than_24h_triggers_admin_notification(): void
    {
        // 1. Create a fresh lead (created 2 hours ago) -> should NOT trigger notification
        $freshLead = CrmCustomer::create([
            'code' => 'KH-FRESH-01',
            'name' => 'Nguyễn Thu Hà',
            'phone' => '0988111222',
            'stage' => 'new',
            'branch_id' => $this->branch->id,
        ]);
        CrmCustomer::where('id', $freshLead->id)->update(['created_at' => Carbon::now()->subHours(2)]);

        // 2. Create a stale lead (created 30 hours ago, still in 'new' stage) -> SHOULD trigger notification
        $staleLead = CrmCustomer::create([
            'code' => 'KH-STALE-01',
            'name' => 'Trần Văn Mạnh',
            'phone' => '0977333444',
            'stage' => 'new',
            'branch_id' => $this->branch->id,
        ]);
        CrmCustomer::where('id', $staleLead->id)->update(['created_at' => Carbon::now()->subHours(30)]);

        // 3. Create an active lead created 40h ago but already moved to 'consulting' -> should NOT trigger stale alert
        $consultingLead = CrmCustomer::create([
            'code' => 'KH-CONSULT-01',
            'name' => 'Lê Hoàng Yến',
            'phone' => '0966555666',
            'stage' => 'consulting',
            'branch_id' => $this->branch->id,
        ]);
        CrmCustomer::where('id', $consultingLead->id)->update(['created_at' => Carbon::now()->subHours(40)]);

        // 4. Run scanner service
        $notificationService = app(NotificationService::class);
        $count = $notificationService->scanAndSyncStaleLeads();

        $this->assertEquals(1, $count);

        // Verify notification saved in database
        $notification = AdminNotification::where('type', 'stale_lead_24h')->first();
        $this->assertNotNull($notification);
        $this->assertStringContainsString('KH-STALE-01', $notification->title);
        $this->assertStringContainsString('30', $notification->title);
        $this->assertFalse($notification->is_read);
        $this->assertEquals($staleLead->id, $notification->data['customer_id']);

        // 5. Test Artisan Command execution
        $this->artisan('crm:scan-stale-leads')->assertSuccessful();

        // 6. Test Web Notification Center
        $responseIndex = $this->actingAs($this->admin)->get(route('notifications.index'));
        $responseIndex->assertStatus(200);
        $responseIndex->assertSee('Trần Văn Mạnh');
        $responseIndex->assertSee('Lead bị sót', false);

        // 7. Test Dropdown JSON endpoint
        $responseDropdown = $this->actingAs($this->admin)->get(route('notifications.dropdown'));
        $responseDropdown->assertStatus(200);
        $responseDropdown->assertJsonFragment(['unread_count' => 1]);

        // 8. Test Mark as Read
        $responseRead = $this->actingAs($this->admin)->post(route('notifications.read', $notification->id));
        $responseRead->assertRedirect();

        $notification->refresh();
        $this->assertTrue($notification->is_read);
        $this->assertNotNull($notification->read_at);
    }

    public function test_stale_lead_notification_is_not_regenerated_after_being_read(): void
    {
        $staleLead = CrmCustomer::create([
            'code' => 'KH-STALE-02',
            'name' => 'Phạm Thị Lan',
            'phone' => '0955444333',
            'stage' => 'new',
            'branch_id' => $this->branch->id,
        ]);
        CrmCustomer::where('id', $staleLead->id)->update(['created_at' => Carbon::now()->subHours(30)]);

        $service = app(NotificationService::class);
        $this->assertSame(1, $service->scanAndSyncStaleLeads());

        $notification = AdminNotification::where('type', 'stale_lead_24h')->firstOrFail();
        $notification->update(['is_read' => true]);

        // Quét lại (đúng kịch bản command định kỳ) và mở dropdown: không được
        // tái tạo thông báo hay gửi lại email cho lead đã được cảnh báo.
        $this->assertSame(0, $service->scanAndSyncStaleLeads());
        $this->actingAs($this->admin)->get(route('notifications.dropdown'))->assertOk();

        $this->assertSame(1, AdminNotification::where('type', 'stale_lead_24h')->count());
    }
}
