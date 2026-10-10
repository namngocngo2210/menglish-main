<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\SupportTicket;
use App\Models\TicketMessage;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Gửi lặp form tạo ticket (bấm lại sau lỗi mạng / gateway timeout) không được tạo ticket trùng. */
class SupportTicketDuplicateTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $branch = Branch::create(['name' => 'Cơ sở Cầu Giấy', 'code' => 'CG', 'address' => 'Hà Nội', 'phone' => '0900000000', 'is_active' => true]);
        $this->staff = User::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);
        $this->staff->assignRole('sales_consultant');
    }

    private function payload(array $overrides = []): array
    {
        return [
            'title' => 'Không in được phiếu thu',
            'category' => 'technical_issue',
            'priority' => 'high',
            'description' => 'Bấm In phiếu thu báo lỗi 500.',
            'submission_token' => 'tok-1',
            ...$overrides,
        ];
    }

    public function test_resubmitting_same_form_returns_existing_ticket(): void
    {
        $this->actingAs($this->staff)->post(route('tickets.store'), $this->payload());
        $ticket = SupportTicket::sole();

        // Lần gửi lại (cùng mã lần gửi) — kể cả khi người dùng đã sửa nội dung trước khi bấm lại.
        $this->actingAs($this->staff)
            ->post(route('tickets.store'), $this->payload(['description' => 'Bấm In phiếu thu báo lỗi 500 (gửi lại).']))
            ->assertRedirect(route('tickets.show', $ticket->id))
            ->assertSessionHas('status', fn ($message) => str_contains($message, $ticket->code) && str_contains($message, 'không tạo thêm'));

        $this->assertSame(1, SupportTicket::count());
        $this->assertSame(1, TicketMessage::count());
    }

    public function test_identical_ticket_from_new_form_within_window_is_not_duplicated(): void
    {
        $this->actingAs($this->staff)->post(route('tickets.store'), $this->payload());
        $this->actingAs($this->staff)->post(route('tickets.store'), $this->payload(['submission_token' => 'tok-2']));
        $this->actingAs($this->staff)->post(route('tickets.store'), $this->payload(['submission_token' => null]));

        $this->assertSame(1, SupportTicket::count());
    }

    public function test_identical_ticket_after_window_is_created(): void
    {
        $this->actingAs($this->staff)->post(route('tickets.store'), $this->payload());
        $this->travel(11)->minutes();
        $this->actingAs($this->staff)->post(route('tickets.store'), $this->payload(['submission_token' => 'tok-2']));

        $this->assertSame(2, SupportTicket::count());
    }

    public function test_different_tickets_and_other_users_are_still_created(): void
    {
        $this->actingAs($this->staff)->post(route('tickets.store'), $this->payload());
        $this->actingAs($this->staff)->post(route('tickets.store'), $this->payload(['submission_token' => 'tok-2', 'title' => 'Lỗi khác']));

        $other = User::factory()->create(['branch_id' => $this->staff->branch_id, 'is_active' => true]);
        $other->assignRole('sales_consultant');
        $this->actingAs($other)->post(route('tickets.store'), $this->payload());

        $this->assertSame(3, SupportTicket::count());
        $this->assertSame(2, SupportTicket::where('submission_token', 'tok-1')->count());
    }

    public function test_resubmit_does_not_store_attachments_again(): void
    {
        Storage::fake('local');

        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

        $this->actingAs($this->staff)->post(route('tickets.store'), $this->payload(['attachments' => [UploadedFile::fake()->createWithContent('loi.png', $png)]]));
        $this->assertCount(1, Storage::disk('local')->allFiles('tickets'));

        $this->actingAs($this->staff)->post(route('tickets.store'), $this->payload(['attachments' => [UploadedFile::fake()->createWithContent('loi.png', $png)]]));

        $this->assertSame(1, SupportTicket::count());
        $this->assertCount(1, Storage::disk('local')->allFiles('tickets'));
    }
}
