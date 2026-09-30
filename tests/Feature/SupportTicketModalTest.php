<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\SupportTicket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\InteractsWithInertia;
use Tests\TestCase;

/**
 * Ticket hỗ trợ trong modal (Inertia): danh sách mở "Tạo Ticket Mới" / "Trao đổi" thành modal; cùng route mở thẳng
 * → trang đầy đủ. Tạo ticket từ modal → quay lại danh sách kèm thông báo; gửi phản hồi từ modal → quay lại, modal giữ mở
 * (UiForm stay) và tải lại hội thoại có phản hồi mới. Phân quyền người trong luồng ticket giữ nguyên.
 */
class SupportTicketModalTest extends TestCase
{
    use InteractsWithInertia;
    use RefreshDatabase;

    private User $admin;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-07 09:00:00'));

        $this->branch = Branch::create(['name' => 'Cầu Giấy', 'code' => 'CG-TK', 'is_active' => true]);
        $this->admin = User::factory()->create(['name' => 'Quản trị viên', 'is_active' => true, 'branch_id' => $this->branch->id]);
        $this->admin->assignRole('admin');
    }

    public function test_list_page_opens_create_and_detail_in_modal(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->admin)->get(route('tickets.index'))->assertOk()
            ->assertSee('Máy chiếu hỏng')
            ->assertSee('href="'.route('tickets.create', absolute: false).'"', false)
            ->assertSee('href="'.route('tickets.show', $ticket->id, absolute: false).'"', false)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('SupportTickets/Index')
                ->where('tickets.data.0.code', $ticket->code)
                ->where('stats.open', 1));
    }

    public function test_create_returns_full_page_normally_and_modal_content_from_modal(): void
    {
        $this->actingAs($this->admin)->get(route('tickets.create'))->assertOk()
            ->assertSee('data-sidebar', false)
            ->assertSee('id="ticket-form"', false)
            ->assertDontSee('id="modal-ticket-form"', false)
            ->assertInertia(fn (AssertableInertia $page) => $page->component('SupportTickets/Create')->where('asModal', false));

        $this->actingAs($this->admin)->get(route('tickets.create'), self::MODAL)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('SupportTickets/Create')->where('asModal', true)->has('staffs'));
    }

    public function test_ticket_create_modal_flow(): void
    {
        // Thiếu mô tả → quay lại trang đang mở kèm lỗi, giữ dữ liệu đã nhập.
        $this->actingAs($this->admin)->from(route('tickets.index'))
            ->post(route('tickets.store'), ['title' => 'Lỗi in hóa đơn', 'category' => 'tuition', 'priority' => 'high'], self::MODAL)
            ->assertRedirect(route('tickets.index'))
            ->assertSessionHasErrors('description')
            ->assertSessionHasInput('title', 'Lỗi in hóa đơn')
            ->assertSessionHasInput('category', 'tuition');
        $this->assertSame(0, SupportTicket::count());

        $this->actingAs($this->admin)->from(route('tickets.index'))->post(route('tickets.store'), [
            'title' => 'Lỗi in hóa đơn', 'category' => 'tuition', 'priority' => 'high', 'description' => 'Bấm in bị trắng trang.',
        ], self::MODAL)->assertRedirect(route('tickets.index'))
            ->assertSessionHas('status', 'Đã tạo phiếu yêu cầu hỗ trợ / báo lỗi '.SupportTicket::firstOrFail()->code.' thành công!');

        // Trang tạo riêng (không phải modal) → sang chi tiết ticket vừa tạo như cũ.
        $this->actingAs($this->admin)->post(route('tickets.store'), [
            'title' => 'Lỗi mạng', 'category' => 'technical_issue', 'priority' => 'low', 'description' => 'Mất wifi tầng 3.',
        ])->assertRedirect(route('tickets.show', SupportTicket::where('title', 'Lỗi mạng')->firstOrFail()->id));
    }

    public function test_ticket_show_modal_with_reply_refreshing_conversation(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->admin)->get(route('tickets.show', $ticket->id))->assertOk()
            ->assertSee('data-sidebar', false)
            ->assertSee('id="ticket-reply-form"', false)
            ->assertSee('Máy chiếu phòng 2 không lên hình');

        $this->actingAs($this->admin)->get(route('tickets.show', $ticket->id), self::MODAL)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('SupportTickets/Show')
                ->where('asModal', true)
                ->where('ticket.code', $ticket->code)
                ->has('messages', 1)
                ->where('messages.0.message', 'Máy chiếu phòng 2 không lên hình'));

        // Gửi phản hồi trống → quay lại kèm lỗi, không tạo tin nhắn.
        $this->actingAs($this->admin)->from(route('tickets.index'))
            ->post(route('tickets.messages.store', $ticket->id), ['message' => ''], self::MODAL)
            ->assertRedirect(route('tickets.index'))
            ->assertSessionHasErrors(['message' => 'Vui lòng nhập Nội dung phản hồi.']);
        $this->assertSame(1, TicketMessage::count());

        // Gửi phản hồi từ modal → quay lại trang đang mở kèm thông báo (modal giữ mở, tải lại hội thoại).
        $this->actingAs($this->admin)->from(route('tickets.index'))
            ->post(route('tickets.messages.store', $ticket->id), ['message' => 'Đã thay dây HDMI.'], self::MODAL)
            ->assertRedirect(route('tickets.index'))
            ->assertSessionHas('status', 'Đã gửi phản hồi thành công!');
        $this->assertSame(2, TicketMessage::count());
        $this->assertSame('in_progress', $ticket->fresh()->status);

        // Hội thoại tải lại có phản hồi mới (mới nhất trước).
        $this->actingAs($this->admin)->get(route('tickets.show', $ticket->id), self::MODAL)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('messages', 2)
                ->where('messages.0.message', 'Đã thay dây HDMI.')
                ->where('ticket.status', 'in_progress'));

        // Request thường giữ redirect back.
        $this->actingAs($this->admin)->from(route('tickets.show', $ticket->id))
            ->post(route('tickets.messages.store', $ticket->id), ['message' => 'OK'])
            ->assertRedirect(route('tickets.show', $ticket->id))->assertSessionHas('status', 'Đã gửi phản hồi thành công!');

        // Người ngoài luồng ticket vẫn bị chặn.
        $outsider = User::factory()->create(['is_active' => true, 'branch_id' => $this->branch->id]);
        $outsider->syncRoles(['teacher']);
        $this->actingAs($outsider)->get(route('tickets.show', $ticket->id), self::MODAL)->assertForbidden();
        $this->actingAs($outsider)->post(route('tickets.messages.store', $ticket->id), ['message' => 'x'], self::MODAL)->assertForbidden();
    }

    private function ticket(): SupportTicket
    {
        $staff = User::factory()->create(['name' => 'Giáo viên Modal', 'is_active' => true, 'branch_id' => $this->branch->id]);
        $staff->assignRole('teacher');

        return tap(SupportTicket::create([
            'code' => SupportTicket::generateCode(), 'title' => 'Máy chiếu hỏng', 'category' => 'technical_issue', 'priority' => 'medium',
            'description' => 'Máy chiếu phòng 2 không lên hình', 'creator_id' => $staff->id, 'status' => 'open',
        ]), fn (SupportTicket $t) => TicketMessage::create([
            'support_ticket_id' => $t->id, 'user_id' => $t->creator_id, 'message' => $t->description, 'is_internal_note' => false,
        ]));
    }
}
