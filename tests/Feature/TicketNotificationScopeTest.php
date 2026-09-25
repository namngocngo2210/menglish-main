<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\NotificationService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketNotificationScopeTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $teacherA;

    protected User $teacherB;

    protected User $teacherC;

    protected Branch $branch;

    protected NotificationService $notifService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Chi nhánh Cầu Giấy', 'code' => 'CG', 'is_active' => true]);

        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin.ticket@menglish.edu.vn',
            'password' => bcrypt('password'),
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);
        $this->admin->syncRoles(['admin']);

        $this->teacherA = User::create([
            'name' => 'Teacher A (Creator)',
            'email' => 'teacherA@menglish.edu.vn',
            'password' => bcrypt('password'),
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);
        $this->teacherA->syncRoles(['teacher']);

        $this->teacherB = User::create([
            'name' => 'Teacher B (Assignee)',
            'email' => 'teacherB@menglish.edu.vn',
            'password' => bcrypt('password'),
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);
        $this->teacherB->syncRoles(['teacher']);

        $this->teacherC = User::create([
            'name' => 'Teacher C (Unrelated)',
            'email' => 'teacherC@menglish.edu.vn',
            'password' => bcrypt('password'),
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);
        $this->teacherC->syncRoles(['teacher']);

        $this->notifService = app(NotificationService::class);
    }

    public function test_ticket_creation_notifies_assignee_only_and_not_unrelated_users(): void
    {
        $this->teacherA->givePermissionTo('support_ticket.assign');

        // Admin creates ticket assigning Teacher B (assignment requires support_ticket.assign).
        $response = $this->actingAs($this->teacherA)->post(route('tickets.store'), [
            'title' => 'Lỗi phòng học 101 hỏng máy chiếu',
            'category' => 'technical_issue',
            'priority' => 'high',
            'description' => 'Máy chiếu phòng 101 bị chập màn hình',
            'assignee_id' => $this->teacherB->id,
        ]);

        $response->assertRedirect();

        // Check unread count:
        // Teacher B should have 1 unread notification
        $this->assertEquals(1, $this->notifService->getUnreadCount($this->teacherB));

        // Teacher C (unrelated) should have 0 unread notifications
        $this->assertEquals(0, $this->notifService->getUnreadCount($this->teacherC));

        // Creator shouldn't notify themselves of creation.
        $this->assertEquals(0, $this->notifService->getUnreadCount($this->teacherA));
    }

    public function test_ticket_message_notifies_creator_and_not_unrelated_users(): void
    {
        // 1. Create ticket
        $ticket = SupportTicket::create([
            'code' => 'TK-2026-001',
            'title' => 'Lỗi giáo trình Unit 4',
            'category' => 'curriculum',
            'priority' => 'medium',
            'description' => 'Bài tập Unit 4 thiếu audio số 3',
            'creator_id' => $this->teacherA->id,
            'assignee_id' => $this->teacherB->id,
            'status' => 'open',
        ]);

        // 2. Teacher B replies
        $response = $this->actingAs($this->teacherB)->post(route('tickets.messages.store', $ticket->id), [
            'message' => 'Đã tải lên file audio 3 thay thế nhé!',
        ]);

        $response->assertRedirect();

        // 3. Check notifications:
        // Teacher A (creator) receives notification of reply
        $this->assertEquals(1, $this->notifService->getUnreadCount($this->teacherA));

        // Teacher B (sender) does not receive notification of their own reply
        $this->assertEquals(0, $this->notifService->getUnreadCount($this->teacherB));

        // Teacher C (uninvolved) has 0 notifications
        $this->assertEquals(0, $this->notifService->getUnreadCount($this->teacherC));
    }

    public function test_ticket_status_change_notifies_involved_members(): void
    {
        $ticket = SupportTicket::create([
            'code' => 'TK-2026-002',
            'title' => 'Lỗi âm thanh loa phòng 202',
            'category' => 'technical_issue',
            'priority' => 'urgent',
            'description' => 'Loa rè',
            'creator_id' => $this->teacherA->id,
            'assignee_id' => $this->teacherB->id,
            'status' => 'in_progress',
        ]);

        // Admin updates status to resolved
        $response = $this->actingAs($this->admin)->post(route('tickets.status.update', $ticket->id), [
            'status' => 'resolved',
        ]);

        $response->assertRedirect();

        // Teacher A & Teacher B (involved in workflow) receive status change notification
        $this->assertEquals(1, $this->notifService->getUnreadCount($this->teacherA));
        $this->assertEquals(1, $this->notifService->getUnreadCount($this->teacherB));

        // Teacher C (unrelated) receives nothing
        $this->assertEquals(0, $this->notifService->getUnreadCount($this->teacherC));
    }
}
