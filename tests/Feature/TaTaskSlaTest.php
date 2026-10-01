<?php

namespace Tests\Feature;

use App\Models\AdminNotification;
use App\Models\Branch;
use App\Models\User;
use App\Models\WorkTask;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Giao nhiệm vụ cho trợ giảng: gửi sau 15:30 (không chặn) → báo MỌI Admin và người giao, không trùng;
 * nhiệm vụ hằng ngày của TA (CV-05) không có hạn nên không bị quét quá hạn.
 */
class TaTaskSlaTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $ta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->branch = Branch::create(['name' => 'Cơ sở TA', 'code' => 'TA1', 'is_active' => true]);
        $this->ta = $this->makeUser('assistant');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeUser(string $role): User
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function assign(User $by, ?string $date = null): void
    {
        $this->actingAs($by)->post(route('tasks.ta-assign.store'), [
            'assistant_id' => $this->ta->id, 'assign_date' => $date ?? today()->toDateString(), 'branch_id' => $this->branch->id,
            'tasks' => [['category' => 'before', 'content' => 'Chuẩn bị phòng']],
        ])->assertSessionHasNoErrors();
    }

    private function lateNotices(User $user): int
    {
        return AdminNotification::where('user_id', $user->id)->where('title', 'Giao việc trợ giảng sau 15:30')->count();
    }

    public function test_late_assignment_notifies_every_admin_and_the_assigner_once(): void
    {
        $adminA = $this->makeUser('admin');
        $adminB = $this->makeUser('admin');
        $academic = $this->makeUser('academic_staff');

        Carbon::setTestNow(today()->setTime(15, 30));
        $this->assign($academic);
        $this->assertSame(0, $this->lateNotices($academic), '15:30 đúng chưa tính là trễ.');

        Carbon::setTestNow(today()->setTime(15, 31));
        $this->assign($academic);
        foreach ([$adminA, $adminB, $academic] as $user) {
            $this->assertSame(1, $this->lateNotices($user));
        }
        $this->assertStringStartsWith('Bạn đã giao', AdminNotification::where('user_id', $academic->id)->where('title', 'Giao việc trợ giảng sau 15:30')->value('message'));
        $this->assertStringContainsString($academic->name.' giao', AdminNotification::where('user_id', $adminA->id)->where('title', 'Giao việc trợ giảng sau 15:30')->value('message'));
        $this->assertSame(0, $this->lateNotices($this->ta));
    }

    public function test_admin_assigner_gets_one_notification_not_two_and_past_date_counts_as_late(): void
    {
        $admin = $this->makeUser('admin');
        $other = $this->makeUser('admin');

        Carbon::setTestNow(today()->setTime(9, 0));
        $this->assign($admin);
        $this->assertSame(0, $this->lateNotices($admin), 'Trước 15:30 không báo.');

        $this->assign($admin, today()->subDay()->toDateString()); // ngày đã qua = trễ, nhưng vẫn lưu
        $this->assertSame(1, $this->lateNotices($admin));
        $this->assertSame(1, $this->lateNotices($other));
        $this->assertSame(2, WorkTask::where('assignee_id', $this->ta->id)->count());
    }

    public function test_ta_daily_tasks_are_marked_and_never_overdue_but_normal_tasks_still_are(): void
    {
        $academic = $this->makeUser('academic_staff');
        Carbon::setTestNow(today()->setTime(8, 0));
        $this->assign($academic);

        $daily = WorkTask::where('assignee_id', $this->ta->id)->sole();
        $this->assertSame(WorkTask::KIND_TA_DAILY, $daily->kind);
        $this->assertSame(today()->toDateString(), $daily->due_date->toDateString());

        $normal = WorkTask::create([
            'title' => 'Việc thường', 'creator_id' => $academic->id, 'assignee_id' => $this->ta->id, 'task_type' => 'one_time',
            'due_date' => today(), 'due_time' => '10:00', 'status' => 'new',
        ]);

        Carbon::setTestNow(today()->addDays(2)->setTime(12, 0));
        $this->artisan('tasks:mark-overdue')->assertSuccessful();

        $this->assertSame('new', $daily->fresh()->status);
        $this->assertSame(0, $daily->fresh()->lateHours());
        $this->assertSame('overdue', $normal->fresh()->status);
        $this->assertSame([$normal->id], WorkTask::withDeadline()->where('assignee_id', $this->ta->id)->pluck('id')->all());

        // Portal TA không đếm / hiển thị việc hằng ngày là quá hạn.
        $this->actingAs($this->ta)->get(route('portal.ta-tasks'))->assertOk();
    }
}
