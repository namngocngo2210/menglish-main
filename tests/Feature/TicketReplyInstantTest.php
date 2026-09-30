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
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\InteractsWithInertia;
use Tests\TestCase;

/**
 * Ô trả lời ticket (SupportTickets/ReplyForm.vue): bình luận hiện ngay ở khung "Đang gửi…" phía trình duyệt, lưu ở nền bằng
 * Inertia (quay lại trang đang mở, hội thoại tải lại); lỗi validate vào error bag `ticketReply` (hiện trên khung tạm).
 * Thông báo + email chạy sau khi đã trả phản hồi (không làm chậm lúc gửi).
 */
class TicketReplyInstantTest extends TestCase
{
    use InteractsWithInertia;
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

    public function test_reply_form_and_conversation_render_with_internal_option_by_permission(): void
    {
        $this->actingAs($this->admin)->get(route('tickets.show', $this->ticket->id))
            ->assertOk()
            ->assertSee('id="ticket-reply-form"', false)
            ->assertSee('data-ticket-messages', false)
            ->assertSee('Chỉ hiển thị nội bộ giữa các phòng ban')
            ->assertInertia(fn (AssertableInertia $page) => $page->component('SupportTickets/Show')->where('canPostInternal', true));

        // Người không được ghi chú nội bộ không có lựa chọn đó.
        $this->actingAs($this->staff)->get(route('tickets.show', $this->ticket->id))
            ->assertOk()
            ->assertSee('id="ticket-reply-form"', false)
            ->assertDontSee('Chỉ hiển thị nội bộ giữa các phòng ban')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canPostInternal', false));
    }

    public function test_reply_is_saved_and_conversation_reloads_with_updated_status(): void
    {
        $show = route('tickets.show', $this->ticket->id);
        $this->actingAs($this->admin)->from($show)->post(route('tickets.messages.store', $this->ticket->id), [
            'message' => "Đã nhận, đang kiểm tra máy in.\nSẽ báo lại <sớm>.",
        ])->assertRedirect($show)->assertSessionHas('status', 'Đã gửi phản hồi thành công!');

        $msg = TicketMessage::where('user_id', $this->admin->id)->sole();
        $html = $this->actingAs($this->admin)->get($show)->assertOk()
            ->assertSee('data-message-id="'.$msg->id.'"', false)
            ->assertSee('Đỗ Quản Trị')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('ticket.status', 'in_progress'))
            ->getContent();
        $this->assertStringContainsString("Đã nhận, đang kiểm tra máy in.\nSẽ báo lại &lt;sớm&gt;.", $html);

        // Admin phản hồi ticket "open" → "Đang xử lý".
        $this->assertSame('in_progress', $this->ticket->fresh()->status);
    }

    public function test_reply_from_modal_returns_to_the_page_behind(): void
    {
        $this->ticket->update(['status' => 'in_progress']);

        $this->actingAs($this->staff)->from(route('tickets.index'))
            ->post(route('tickets.messages.store', $this->ticket->id), ['message' => 'Em gửi thêm ảnh.'], self::MODAL)
            ->assertRedirect(route('tickets.index'));

        $this->assertSame('in_progress', $this->ticket->fresh()->status);
        $this->assertDatabaseHas('ticket_messages', ['message' => 'Em gửi thêm ảnh.', 'user_id' => $this->staff->id]);
    }

    public function test_reply_validation_error_saves_nothing(): void
    {
        $show = route('tickets.show', $this->ticket->id);
        $this->actingAs($this->admin)->from($show)
            ->post(route('tickets.messages.store', $this->ticket->id), ['message' => '   '])
            ->assertRedirect($show)
            ->assertSessionHasErrors('message');

        $this->assertSame(1, TicketMessage::count());
    }

    public function test_notifications_are_sent_after_the_response(): void
    {
        $notificationsWhenResponded = null;
        Event::listen(RequestHandled::class, function () use (&$notificationsWhenResponded) {
            $notificationsWhenResponded = AdminNotification::where('type', 'ticket_message')->count();
        });

        $this->actingAs($this->admin)->post(route('tickets.messages.store', $this->ticket->id), ['message' => 'Đã xử lý xong.'])
            ->assertRedirect();

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

    public function test_reopen_button_moves_finished_ticket_back_to_in_progress(): void
    {
        $this->ticket->update(['status' => 'resolved', 'resolved_at' => now()]);

        // Người tạo (không có quyền đổi trạng thái) thấy nút Mở lại.
        $this->actingAs($this->staff)->get(route('tickets.show', $this->ticket->id))
            ->assertOk()->assertSee('action="'.route('tickets.reopen', $this->ticket->id, false).'"', false)->assertSee('Mở lại');

        $this->actingAs($this->staff)->from(route('tickets.show', $this->ticket->id))
            ->post(route('tickets.reopen', $this->ticket->id))
            ->assertRedirect(route('tickets.show', $this->ticket->id))
            ->assertSessionHas('status');

        $ticket = $this->ticket->fresh();
        $this->assertSame('in_progress', $ticket->status);
        $this->assertNull($ticket->resolved_at);
        $this->assertDatabaseHas('ticket_messages', [
            'support_ticket_id' => $ticket->id,
            'user_id' => $this->staff->id,
            'message' => 'Đã mở lại ticket, chuyển sang Đang xử lý.',
        ]);

        // Ticket đang mở: không hiện nút, gọi thẳng cũng không đổi gì.
        $this->actingAs($this->admin)->get(route('tickets.show', $ticket->id))
            ->assertDontSee(route('tickets.reopen', $ticket->id, false), false);
        $this->actingAs($this->admin)->post(route('tickets.reopen', $ticket->id))->assertSessionHasErrors('status');
        $this->assertSame(2, TicketMessage::count());
    }

    public function test_admin_reopens_closed_ticket_in_modal_and_creator_is_notified(): void
    {
        $this->ticket->update(['status' => 'closed', 'resolved_at' => now()]);

        // Trong modal (X-Remote-Modal): quay lại trang nền; modal tải lại thấy dòng "Đã mở lại ticket".
        $this->actingAs($this->admin)->from(route('tickets.index'))
            ->post(route('tickets.reopen', $this->ticket->id), [], self::MODAL)
            ->assertRedirect(route('tickets.index'))->assertSessionHas('status', 'Đã mở lại ticket.');
        $this->actingAs($this->admin)->get(route('tickets.show', $this->ticket->id), self::MODAL)->assertOk()
            ->assertSee('data-testid="ticket-conversation"', false)->assertSee('Đã mở lại ticket');

        $this->assertSame('in_progress', $this->ticket->fresh()->status);
        $this->assertDatabaseHas('admin_notifications', ['user_id' => $this->staff->id, 'type' => 'ticket_status']);
    }

    public function test_unrelated_user_cannot_reopen(): void
    {
        $this->ticket->update(['status' => 'resolved']);
        $outsider = User::factory()->create(['is_active' => true]);
        $outsider->assignRole('sales_consultant');

        $this->actingAs($outsider)->post(route('tickets.reopen', $this->ticket->id))->assertForbidden();
        $this->assertSame('resolved', $this->ticket->fresh()->status);
    }
}
