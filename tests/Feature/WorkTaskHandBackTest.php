<?php

namespace Tests\Feature;

use App\Http\Controllers\WorkTaskController;
use App\Models\AdminNotification;
use App\Models\Branch;
use App\Models\User;
use App\Models\WorkTask;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ticket 53: "Hủy công việc" chỉ người giao / Admin thấy; việc bị chặn có thêm "Chuyển lại cho người giao".
 */
class WorkTaskHandBackTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $admin;

    private User $creator;

    private User $assignee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Chi nhánh Cầu Giấy', 'code' => 'CG', 'is_active' => true]);
        $this->admin = $this->makeUser('Admin Một', 'admin');
        $this->creator = $this->makeUser('Học vụ Giao', 'academic_staff');
        $this->assignee = $this->makeUser('Học vụ Làm', 'academic_staff');
    }

    private function makeUser(string $name, string $role): User
    {
        $user = User::create([
            'name' => $name,
            'email' => str($name)->slug().'@menglish.edu.vn',
            'password' => bcrypt('password'),
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);
        $user->syncRoles([$role]);

        return $user;
    }

    private function task(string $status = 'blocked', ?User $creator = null, ?User $assignee = null): WorkTask
    {
        return WorkTask::create([
            'title' => 'Gọi phụ huynh',
            'creator_id' => ($creator ?? $this->creator)->id,
            'assignee_id' => ($assignee ?? $this->assignee)->id,
            'branch_id' => $this->branch->id,
            'status' => $status,
            'blocked_reason' => $status === 'blocked' ? 'Không có số điện thoại' : null,
            'task_type' => 'one_time',
            'due_date' => now()->addDays(2)->toDateString(),
        ]);
    }

    public function test_cancel_is_only_for_creator_and_admin(): void
    {
        $task = $this->task('in_progress');

        $this->assertNotContains('canceled', WorkTaskController::allowedTransitions($task, $this->assignee));
        $this->assertContains('canceled', WorkTaskController::allowedTransitions($task, $this->creator));
        $this->assertContains('canceled', WorkTaskController::allowedTransitions($task, $this->admin));

        // Admin nhận việc (đề xuất từ nhân sự khác) vẫn hủy / từ chối được.
        $proposal = $this->task('new', creator: $this->assignee, assignee: $this->admin);
        $this->assertContains('canceled', WorkTaskController::allowedTransitions($proposal, $this->admin));

        $this->actingAs($this->assignee)
            ->post(route('tasks.status.update', $task->id), ['status' => 'canceled', 'reason' => 'Không làm'])
            ->assertSessionHasErrors('status');
        $this->assertSame('in_progress', $task->fresh()->status);
    }

    public function test_blocked_task_offers_hand_back_before_cancel(): void
    {
        $task = $this->task();

        $this->assertSame(['in_progress', WorkTaskController::HAND_BACK], WorkTaskController::allowedTransitions($task, $this->assignee));
        $this->assertSame([WorkTaskController::HAND_BACK, 'canceled'], WorkTaskController::allowedTransitions($task, $this->creator));
        $this->assertSame([WorkTaskController::HAND_BACK, 'canceled'], WorkTaskController::allowedTransitions($task, $this->admin));

        // Người làm thấy "Chuyển lại cho người giao", người giao thấy "Nhận lại việc".
        $this->actingAs($this->assignee)->get(route('tasks.show', $task->id))
            ->assertOk()->assertSee('Chuyển lại cho người giao')->assertDontSee('Hủy công việc</');
        $this->actingAs($this->creator)->get(route('tasks.show', $task->id))
            ->assertOk()->assertSee('Nhận lại việc')->assertSee('Hủy công việc');

        // Không bị chặn hoặc người giao chính là người làm → không có lựa chọn chuyển lại.
        $this->assertNotContains(WorkTaskController::HAND_BACK, WorkTaskController::allowedTransitions($this->task('in_progress'), $this->creator));
        $self = $this->task(creator: $this->assignee);
        $this->assertNotContains(WorkTaskController::HAND_BACK, WorkTaskController::allowedTransitions($self, $this->assignee));
    }

    public function test_assignee_hands_blocked_task_back_to_creator(): void
    {
        $task = $this->task();

        $this->actingAs($this->assignee)
            ->post(route('tasks.status.update', $task->id), ['status' => WorkTaskController::HAND_BACK, 'reason' => 'Chị gọi giúp em'])
            ->assertSessionHasNoErrors();

        $task->refresh();
        $this->assertSame($this->creator->id, $task->assignee_id);
        $this->assertSame('new', $task->status);
        $this->assertSame($this->assignee->id, $task->handed_back_from_id);
        $this->assertNotNull($task->handed_back_at);
        $this->assertStringContainsString('Không có số điện thoại', $task->blocked_reason);
        $this->assertStringContainsString('Chị gọi giúp em', $task->blocked_reason);

        $sent = AdminNotification::where('data->task_id', $task->id)->get();
        $this->assertSame([$this->creator->id], $sent->pluck('user_id')->all());
        $this->assertSame('Việc bị chặn chuyển lại cho bạn: Gọi phụ huynh', $sent->first()->title);
        $this->assertStringContainsString('Không có số điện thoại', $sent->first()->message);

        // Người làm cũ vẫn mở được chi tiết nhưng không còn thao tác; người giao giờ là người thực hiện.
        $this->actingAs($this->assignee)->get(route('tasks.show', $task->id))->assertOk();
        $this->assertSame([], WorkTaskController::allowedTransitions($task, $this->assignee));
        $this->assertContains('in_progress', WorkTaskController::allowedTransitions($task, $this->creator));
    }

    public function test_admin_takes_blocked_task_back_and_both_sides_are_notified(): void
    {
        $task = $this->task();

        $this->actingAs($this->admin)
            ->post(route('tasks.status.update', $task->id), ['status' => WorkTaskController::HAND_BACK])
            ->assertSessionHasNoErrors();

        $this->assertSame($this->creator->id, $task->fresh()->assignee_id);
        $this->assertEqualsCanonicalizing(
            [$this->creator->id, $this->assignee->id],
            AdminNotification::where('data->task_id', $task->id)->pluck('user_id')->all(),
        );
    }

    public function test_hand_back_is_refused_when_task_is_not_blocked(): void
    {
        $task = $this->task('in_progress');

        $this->actingAs($this->assignee)
            ->post(route('tasks.status.update', $task->id), ['status' => WorkTaskController::HAND_BACK])
            ->assertSessionHasErrors('status');

        $this->assertSame($this->assignee->id, $task->fresh()->assignee_id);
    }
}
