<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\SupportTicket;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SupportTicketTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $staff;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create([
            'name' => 'Cơ sở Cầu Giấy',
            'code' => 'CG',
            'address' => 'Hà Nội',
            'phone' => '0900000000',
            'is_active' => true,
        ]);

        $this->admin = User::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Quản trị viên IT',
            'is_active' => true,
        ]);
        $this->admin->assignRole('admin');

        $this->staff = User::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Nhân viên Tuyển sinh',
            'is_active' => true,
        ]);
        $this->staff->assignRole('sales_consultant');
    }

    public function test_can_create_support_ticket_and_send_messages(): void
    {
        // 1. Staff creates a ticket
        $ticketPayload = [
            'title' => 'Lỗi không gửi được tin nhắn Zalo nhắc lịch thi',
            'category' => 'technical_issue',
            'priority' => 'high',
            'description' => 'Khi bấm nút gửi Zalo cho phụ huynh tại lớp IELTS-6.5 hệ thống báo timeout API.',
        ];

        $responseCreate = $this->actingAs($this->staff)->post(route('tickets.store'), $ticketPayload);

        $ticket = SupportTicket::where('title', 'Lỗi không gửi được tin nhắn Zalo nhắc lịch thi')->first();
        $this->assertNotNull($ticket);
        $this->assertMatchesRegularExpression('/^TK-\d{4}-\d{4}$/', $ticket->code);
        $this->assertEquals('open', $ticket->status);
        $this->assertEquals($this->staff->id, $ticket->creator_id);
        $this->assertNull($ticket->assignee_id);
        $this->assertEquals('Lỗi Hệ Thống / IT', $ticket->category_label);

        $responseCreate->assertRedirect(route('tickets.show', $ticket->id));

        // 2. Initial description message automatically saved
        $this->assertDatabaseHas('ticket_messages', [
            'support_ticket_id' => $ticket->id,
            'user_id' => $this->staff->id,
            'message' => $ticketPayload['description'],
        ]);

        // 3. Admin replies on the ticket
        $responseReply = $this->actingAs($this->admin)->post(route('tickets.messages.store', $ticket->id), [
            'message' => 'Đã tiếp nhận yêu cầu, đang kiểm tra kết nối webhook với Zalo ZNS.',
            'is_internal_note' => 0,
        ]);
        $responseReply->assertRedirect();

        // Ticket status changes from open to in_progress upon reply
        $ticket->refresh();
        $this->assertEquals('in_progress', $ticket->status);
        $this->assertEquals('Đang xử lý', $ticket->status_label);

        // 4. Admin updates status to resolved
        $responseStatus = $this->actingAs($this->admin)->post(route('tickets.status.update', $ticket->id), [
            'status' => 'resolved',
        ]);
        $responseStatus->assertRedirect();

        $ticket->refresh();
        $this->assertEquals('resolved', $ticket->status);
        $this->assertEquals('Đã giải quyết', $ticket->status_label);
        $this->assertNotNull($ticket->resolved_at);

        // 5. Reassign ticket to another user
        $newStaff = User::factory()->create(['name' => 'Kỹ sư IT Support']);
        $responseAssign = $this->actingAs($this->admin)->post(route('tickets.assign', $ticket->id), [
            'assignee_id' => $newStaff->id,
        ]);
        $responseAssign->assertRedirect();

        $ticket->refresh();
        $this->assertEquals($newStaff->id, $ticket->assignee_id);
    }

    public function test_can_create_ticket_with_uploaded_images_stored_in_dated_folders(): void
    {
        Storage::fake('public');

        $fakeImage = UploadedFile::fake()->image('screenshot_error.png', 600, 400);

        $ticketPayload = [
            'title' => 'Ảnh màn hình lỗi xuất hóa đơn',
            'category' => 'tuition',
            'priority' => 'medium',
            'description' => 'Màn hình lỗi hiển thị như ảnh đính kèm.',
            'attachments' => [$fakeImage],
        ];

        $response = $this->actingAs($this->staff)->post(route('tickets.store'), $ticketPayload);
        $response->assertRedirect();

        $ticket = SupportTicket::where('title', 'Ảnh màn hình lỗi xuất hóa đơn')->firstOrFail();
        $this->assertNotNull($ticket->attachment_path);

        $year = date('Y');
        $month = date('m');
        $day = date('d');
        $expectedPrefix = "uploads/{$year}/{$month}/{$day}/";

        $this->assertStringStartsWith($expectedPrefix, $ticket->attachment_list[0]);
        $this->assertFileExists(public_path($ticket->attachment_list[0]));

        // Cleanup test file
        if (file_exists(public_path($ticket->attachment_list[0]))) {
            unlink(public_path($ticket->attachment_list[0]));
        }
    }
}
