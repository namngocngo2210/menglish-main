<?php

namespace Tests\Feature;

use App\Models\AdminNotification;
use App\Models\Branch;
use App\Models\SupportTicket;
use App\Models\TicketMessage;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Ô trả lời ticket gửi ở nền (ticket-reply.js): bình luận hiện ngay, server trả JSON gồm bình luận đã render;
 * thông báo + email chạy sau khi đã trả phản hồi (không làm chậm lúc gửi).
 */
class TicketReplyInstantTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $staff;

    private SupportTicket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $branch = Branch::create(['name' => 'Cơ sở Cầu Giấy', 'code' => 'CG', 'is_active' => true]);

        $this->admin = User::factory()->create(['branch_id' => $branch->id, 'name' => 'Đỗ Quản Trị', 'is_active' => true]);
        $this->admin->assignRole('admin');

        $this->staff = User::factory()->create(['branch_id' => $branch->id, 'name' => 'Nhân viên Tuyển sinh', 'is_active' => true]);
        $this->staff->assignRole('sales_consultant');

        $this->ticket = SupportTicket::create([
            'code' => SupportTicket::generateCode(),
            'title' => 'Không in được phiếu thu',
            'category' => 'technical_issue',
            'priority' => 'medium',
            'description' => 'Bấm in thì trắng trang.',
            'creator_id' => $this->staff->id,
            'status' => 'open',
        ]);
        TicketMessage::create([
            'support_ticket_id' => $this->ticket->id,
            'user_id' => $this->staff->id,
            'message' => 'Bấm in thì trắng trang.',
            'is_internal_note' => false,
        ]);
    }

    public function test_reply_form_renders_pending_templates_for_instant_display(): void
    {
        $this->actingAs($this->admin)->get(route('tickets.show', $this->ticket->id))
            ->assertOk()
            ->assertSee('x-data="ticketReply"', false)
            ->assertSee('data-pending-message="public"', false)
            ->assertSee('data-pending-message="internal"', false)
            ->assertSee('data-ticket-messages', false)
            ->assertSee('data-ticket-part="status"', false);

        // Người không được ghi chú nội bộ chỉ có khung mẫu bình luận thường.
        $this->actingAs($this->staff)->get(route('tickets.show', $this->ticket->id))
            ->assertOk()
            ->assertSee('data-pending-message="public"', false)
            ->assertDontSee('data-pending-message="internal"', false);
    }

    public function test_background_reply_returns_rendered_message_and_updated_status_parts(): void
    {
        $response = $this->actingAs($this->admin)->postJson(route('tickets.messages.store', $this->ticket->id), [
            'message' => "Đã nhận, đang kiểm tra máy in.\nSẽ báo lại <sớm>.",
            'as_modal' => 1,
        ]);

        $msg = TicketMessage::where('user_id', $this->admin->id)->sole();
        $response->assertCreated()
            ->assertJsonPath('message', 'Đã gửi phản hồi.');
        $html = $response->json('html');
        $this->assertStringContainsString('data-message-id="'.$msg->id.'"', $html);
        $this->assertStringContainsString("Đã nhận, đang kiểm tra máy in.\nSẽ báo lại &lt;sớm&gt;.", $html);
        $this->assertStringContainsString('Đỗ Quản Trị', $html);
        $this->assertStringNotContainsString('data-message-pending', $html);

        // Admin phản hồi ticket "open" → "Đang xử lý": trả lại vùng trạng thái để cập nhật tại chỗ.
        $this->assertSame('in_progress', $this->ticket->fresh()->status);
        $this->assertStringContainsString('value="in_progress" selected', $response->json('parts.status'));
        $this->assertStringContainsString('Đang xử lý', $response->json('parts.info'));
    }

    public function test_background_reply_without_status_change_returns_no_parts(): void
    {
        $this->ticket->update(['status' => 'in_progress']);

        $this->actingAs($this->staff)->postJson(route('tickets.messages.store', $this->ticket->id), ['message' => 'Em gửi thêm ảnh.'])
            ->assertCreated()
            ->assertJsonPath('parts', [])
            ->assertJsonPath('html', fn (string $html) => str_contains($html, 'Em gửi thêm ảnh.'));
    }

    public function test_background_reply_validation_error_is_json(): void
    {
        $this->actingAs($this->admin)->postJson(route('tickets.messages.store', $this->ticket->id), ['message' => '   '])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('message');

        $this->assertSame(1, TicketMessage::count());
    }

    public function test_notifications_are_sent_after_the_response(): void
    {
        $notificationsWhenResponded = null;
        Event::listen(RequestHandled::class, function () use (&$notificationsWhenResponded) {
            $notificationsWhenResponded = AdminNotification::where('type', 'ticket_message')->count();
        });

        $this->actingAs($this->admin)->postJson(route('tickets.messages.store', $this->ticket->id), ['message' => 'Đã xử lý xong.'])
            ->assertCreated();

        // Lúc trả phản hồi chưa gửi gì; sau đó (terminate) người tạo ticket vẫn nhận thông báo.
        $this->assertSame(0, $notificationsWhenResponded);
        $this->assertDatabaseHas('admin_notifications', ['user_id' => $this->staff->id, 'type' => 'ticket_message']);
    }

    public function test_plain_form_post_still_redirects_back(): void
    {
        $this->actingAs($this->admin)
            ->from(route('tickets.show', $this->ticket->id))
            ->post(route('tickets.messages.store', $this->ticket->id), ['message' => 'Gửi không cần JS.'])
            ->assertRedirect(route('tickets.show', $this->ticket->id));

        $this->assertDatabaseHas('ticket_messages', ['message' => 'Gửi không cần JS.']);
        $this->assertDatabaseHas('admin_notifications', ['user_id' => $this->staff->id, 'type' => 'ticket_message']);
    }
}
