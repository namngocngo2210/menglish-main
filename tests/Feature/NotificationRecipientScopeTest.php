<?php

namespace Tests\Feature;

use App\Models\AdminNotification;
use App\Models\Branch;
use App\Models\CrmCustomer;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\NotificationService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Chuông thông báo (số chưa đọc, dropdown, trang Thông báo) chỉ hiện thông báo của chính người đăng nhập:
 * Quản lý cơ sở không thấy ticket Admin tự tạo / tự trả lời, không thấy thông báo chung dành cho Admin.
 */
class NotificationRecipientScopeTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $admin;

    private User $manager;

    private User $otherManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'ME Đội Cấn', 'code' => 'DC', 'is_active' => true]);
        $this->admin = $this->makeUser('admin');
        $this->manager = $this->makeUser('manager');
        $this->otherManager = $this->makeUser('manager');
    }

    private function makeUser(string $role): User
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function unreadFor(User $user): int
    {
        return app(NotificationService::class)->getUnreadCount($user);
    }

    public function test_ticket_admin_creates_and_replies_does_not_reach_branch_manager(): void
    {
        $this->actingAs($this->admin)->post(route('tickets.store'), [
            'title' => '[Bảng lương] Chỉ nhìn thấy bảng lương của mình',
            'category' => 'technical_issue',
            'priority' => 'medium',
            'description' => 'Nếu ko phải Admin, chỉ nhìn thấy bảng lương của mình',
        ])->assertRedirect();
        $ticket = SupportTicket::sole();

        $this->actingAs($this->admin)->post(route('tickets.messages.store', $ticket->id), [
            'message' => 'Bổ sung: áp dụng cả trang Lương của tôi',
        ])->assertRedirect();

        $this->assertSame(0, AdminNotification::count(), 'Admin tự tạo / tự trả lời ticket của mình: không báo ai.');
        $this->assertSame(0, $this->unreadFor($this->manager));
        $this->assertSame(0, $this->unreadFor($this->admin));
    }

    public function test_ticket_branch_manager_creates_goes_to_admin_not_to_peer_managers(): void
    {
        $this->actingAs($this->manager)->post(route('tickets.store'), [
            'title' => 'Máy chiếu phòng 101 hỏng',
            'category' => 'technical_issue',
            'priority' => 'high',
            'description' => 'Cần thay bóng',
        ])->assertRedirect();

        $this->assertDatabaseHas('admin_notifications', ['user_id' => $this->admin->id, 'type' => 'ticket_new']);
        $this->assertSame(0, $this->unreadFor($this->otherManager), 'Quản lý cơ sở khác không liên quan ticket này.');
        $this->assertSame(0, $this->unreadFor($this->manager));
    }

    public function test_ticket_from_teacher_still_reaches_branch_dispatchers(): void
    {
        $teacher = $this->makeUser('teacher_fulltime');

        $this->actingAs($teacher)->post(route('tickets.store'), [
            'title' => 'Loa phòng 202 rè',
            'category' => 'technical_issue',
            'priority' => 'high',
            'description' => 'Loa rè',
        ])->assertRedirect();

        foreach ([$this->manager, $this->otherManager, $this->admin] as $dispatcher) {
            $this->assertDatabaseHas('admin_notifications', ['user_id' => $dispatcher->id, 'type' => 'ticket_new']);
        }
    }

    public function test_branch_manager_bell_hides_system_notifications_meant_for_admin(): void
    {
        // Dữ liệu cũ phát chung (user_id NULL) trước khi chuyển sang gửi riêng từng người.
        AdminNotification::create(['type' => 'syllabus_proposal', 'title' => 'Đề xuất sửa giáo trình mới', 'message' => 'GV đề xuất', 'is_read' => false]);
        AdminNotification::create(['type' => 'warning', 'title' => 'SePay: Giao dịch cần đối soát', 'message' => 'Chưa khớp', 'is_read' => false]);
        // Lead tồn đọng của chi nhánh mình: Quản lý cơ sở vẫn chịu trách nhiệm.
        $lead = CrmCustomer::create(['code' => 'KH-DC1', 'name' => 'Khách Đội Cấn', 'phone' => '0911000009', 'stage' => 'new', 'branch_id' => $this->branch->id]);
        AdminNotification::create(['type' => 'stale_lead_24h', 'title' => 'Lead sót', 'message' => 'Quá 24h', 'data' => ['customer_id' => $lead->id], 'is_read' => false]);
        // Thông báo riêng của người khác.
        AdminNotification::notifyUser($this->otherManager->id, 'task_assigned', 'Việc của QLCS khác', 'x');
        AdminNotification::notifyUser($this->manager->id, 'task_assigned', 'Giao việc trợ giảng sau 15:30', 'Bạn đã giao 2 nhiệm vụ');

        $this->assertSame(2, $this->unreadFor($this->manager));
        $this->assertEqualsCanonicalizing(
            ['Giao việc trợ giảng sau 15:30', 'Lead sót'],
            app(NotificationService::class)->getUserNotifications($this->manager, 50)->pluck('title')->all(),
        );
        $this->actingAs($this->manager)->getJson(route('notifications.dropdown'))->assertOk()
            ->assertJsonPath('unread_count', 2)
            ->assertDontSee('SePay')->assertDontSee('Đề xuất sửa giáo trình')->assertDontSee('Việc của QLCS khác');
        $this->actingAs($this->manager)->get(route('notifications.index'))->assertOk()
            ->assertDontSee('SePay')->assertDontSee('Đề xuất sửa giáo trình')->assertDontSee('Việc của QLCS khác');

        // Admin vẫn thấy mọi thông báo chung (không thấy thông báo riêng của người khác).
        $this->assertSame(3, $this->unreadFor($this->admin));

        // "Đã đọc tất cả" của Quản lý cơ sở không còn đánh dấu đọc hộ thông báo chung dành cho Admin (chỉ lead tồn đọng dùng chung).
        $this->actingAs($this->manager)->postJson(route('notifications.read-all'))->assertOk();
        $this->assertSame(0, $this->unreadFor($this->manager));
        $this->assertSame(2, $this->unreadFor($this->admin));
    }
}
