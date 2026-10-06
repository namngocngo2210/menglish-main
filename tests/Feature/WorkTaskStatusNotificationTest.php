<?php

namespace Tests\Feature;

use App\Models\AdminNotification;
use App\Models\Branch;
use App\Models\User;
use App\Models\WorkTask;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Đổi trạng thái công việc phải báo đúng người: Bị chặn (SOS) → người giao + mọi Admin;
 * gỡ chặn / chờ xác nhận → người giao; hoàn thành / trả về → người làm; hủy → người làm + người giao.
 */
class WorkTaskStatusNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $admin;

    private User $otherAdmin;

    private User $creator;

    private User $assignee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Chi nhánh Cầu Giấy', 'code' => 'CG', 'is_active' => true]);
        $this->admin = $this->makeUser('Admin Một', 'admin');
        $this->otherAdmin = $this->makeUser('Admin Hai', 'admin');
        $this->creator = $this->makeUser('Học vụ Giao', 'academic_staff');
        $this->assignee = $this->makeUser('Học vụ Làm', 'academic_staff');
    }

    private function makeUser(string $name, string $role, bool $active = true): User
    {
        $user = User::create([
            'name' => $name,
            'email' => str($name)->slug().'@menglish.edu.vn',
            'password' => bcrypt('password'),
            'branch_id' => $this->branch->id,
            'is_active' => $active,
        ]);
        $user->syncRoles([$role]);

        return $user;
    }

    private function task(string $status = 'new', ?User $creator = null): WorkTask
    {
        return WorkTask::create([
            'title' => 'Task 1',
            'creator_id' => ($creator ?? $this->creator)->id,
            'assignee_id' => $this->assignee->id,
            'branch_id' => $this->branch->id,
            'status' => $status,
            'task_type' => 'one_time',
            'due_date' => now()->addDays(2)->toDateString(),
        ]);
    }

    /** @return array<int, string> user_id => title */
    private function notificationsFor(WorkTask $task, string $type): array
    {
        return AdminNotification::where('type', $type)->where('data->task_id', $task->id)->pluck('title', 'user_id')->all();
    }

    public function test_blocked_sos_notifies_creator_and_every_admin_with_reason(): void
    {
        $inactiveAdmin = $this->makeUser('Admin Nghỉ', 'admin', active: false);
        $task = $this->task();

        $this->actingAs($this->assignee)
            ->post(route('tasks.status.update', $task->id), ['status' => 'blocked', 'reason' => 'SOS'])
            ->assertSessionHasNoErrors();

        $this->assertSame('blocked', $task->fresh()->status);
        $sent = AdminNotification::where('type', 'task_blocked')->get()->keyBy('user_id');
        $this->assertEqualsCanonicalizing([$this->creator->id, $this->admin->id, $this->otherAdmin->id], $sent->keys()->all());
        $this->assertArrayNotHasKey($inactiveAdmin->id, $sent);
        $this->assertArrayNotHasKey($this->assignee->id, $sent);

        $notif = $sent[$this->admin->id];
        $this->assertSame('SOS – Việc bị chặn: Task 1', $notif->title);
        $this->assertStringContainsString('Học vụ Làm', $notif->message);
        $this->assertStringContainsString('Lý do: SOS', $notif->message);
        $this->assertSame(route('tasks.show', $task->id), $notif->data['link']);
        $this->assertSame('SOS · Việc bị chặn', $notif->type_label);
        $this->assertStringContainsString('text-error', $notif->badge_color);

        // Admin mở được link chi tiết việc từ thông báo.
        $this->actingAs($this->admin)->get($notif->data['link'])->assertOk();
    }

    public function test_blocked_task_created_by_admin_notifies_that_admin_once(): void
    {
        $task = $this->task(creator: $this->admin);

        $this->actingAs($this->assignee)
            ->post(route('tasks.status.update', $task->id), ['status' => 'blocked', 'reason' => 'SOS']);

        $this->assertSame(1, AdminNotification::where('type', 'task_blocked')->where('user_id', $this->admin->id)->count());
        $this->assertSame(2, AdminNotification::where('type', 'task_blocked')->count());
    }

    public function test_unblocking_notifies_creator(): void
    {
        $task = $this->task('blocked');

        $this->actingAs($this->assignee)
            ->post(route('tasks.status.update', $task->id), ['status' => 'in_progress'])
            ->assertSessionHasNoErrors();

        $this->assertSame([$this->creator->id => 'Việc đã gỡ chặn: Task 1'], $this->notificationsFor($task, 'task_assigned'));
    }

    public function test_pending_confirmation_still_notifies_creator(): void
    {
        $task = $this->task('in_progress');

        $this->actingAs($this->assignee)
            ->post(route('tasks.status.update', $task->id), ['status' => 'pending_confirmation', 'reason' => 'Đã xong']);

        $this->assertSame([$this->creator->id => 'Việc chờ xác nhận: Task 1'], $this->notificationsFor($task, 'task_assigned'));
    }

    public function test_completed_from_status_modal_notifies_assignee(): void
    {
        $task = $this->task('pending_confirmation');

        $this->actingAs($this->creator)
            ->post(route('tasks.status.update', $task->id), ['status' => 'completed'])
            ->assertSessionHasNoErrors();

        $this->assertSame([$this->assignee->id => 'Việc đã được xác nhận hoàn thành: Task 1'], $this->notificationsFor($task, 'task_assigned'));
    }

    public function test_approve_from_approvals_inbox_notifies_assignee(): void
    {
        $task = $this->task('pending_confirmation');

        $this->actingAs($this->creator)->post(route('tasks.approve', $task->id))->assertRedirect();

        $this->assertSame('completed', $task->fresh()->status);
        $this->assertSame([$this->assignee->id => 'Việc đã được xác nhận hoàn thành: Task 1'], $this->notificationsFor($task, 'task_assigned'));
    }

    public function test_returned_from_status_modal_notifies_assignee_with_note(): void
    {
        $task = $this->task('pending_confirmation');

        $this->actingAs($this->creator)
            ->post(route('tasks.status.update', $task->id), ['status' => 'in_progress', 'reason' => 'Thiếu ảnh']);

        $notif = AdminNotification::where('data->task_id', $task->id)->sole();
        $this->assertSame($this->assignee->id, $notif->user_id);
        $this->assertSame('Công việc bị trả về: Task 1', $notif->title);
        $this->assertStringContainsString('Thiếu ảnh', $notif->message);
    }

    public function test_cancel_by_admin_notifies_assignee_and_creator(): void
    {
        $task = $this->task('in_progress');

        $this->actingAs($this->admin)
            ->post(route('tasks.status.update', $task->id), ['status' => 'canceled', 'reason' => 'Không cần nữa'])
            ->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing(
            [$this->assignee->id, $this->creator->id],
            array_keys($this->notificationsFor($task, 'task_assigned'))
        );
    }

    public function test_starting_work_sends_nothing(): void
    {
        $task = $this->task();

        $this->actingAs($this->assignee)
            ->post(route('tasks.status.update', $task->id), ['status' => 'in_progress']);

        $this->assertSame(0, AdminNotification::count());
    }
}
