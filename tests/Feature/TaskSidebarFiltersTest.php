<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use App\Models\WorkTask;
use App\Support\Navigation\SidebarMenu;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Khu "Giao việc" trên sidebar (Tạo đầu việc + Danh sách đầu việc) và bộ lọc ngày / nhân viên / trạng thái. */
class TaskSidebarFiltersTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    /** @var array<string, User> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Chi nhánh Cầu Giấy', 'code' => 'CG', 'is_active' => true]);
    }

    public function test_sidebar_has_task_section_with_create_and_list_entries(): void
    {
        $menu = app(SidebarMenu::class);

        foreach (['academic_staff', 'teacher', 'assistant'] as $role) {
            $groups = collect($menu->groupsFor($this->makeUser($role)))->keyBy('id');

            $this->assertSame('Giao việc', $groups['task_create']['section'] ?? null, $role);
            $this->assertSame('2xl', $groups['task_create']['modal'], $role);
            $this->assertSame(route('tasks.create'), $groups['task_create']['url'], $role);
            $this->assertSame('Giao việc', $groups['tasks']['section'], $role);
            $this->assertSame(route('tasks.index'), $groups['tasks']['url'], $role);
        }

        // Sales / Kế toán xem được việc của mình nhưng không giao việc.
        $groups = collect($menu->groupsFor($this->makeUser('sales_consultant')))->keyBy('id');
        $this->assertArrayHasKey('tasks', $groups->all());
        $this->assertArrayNotHasKey('task_create', $groups->all());

        $html = $this->actingAs($this->makeUser('academic_staff'))->get(route('tasks.index'))->assertOk()->getContent();
        $this->assertStringContainsString('data-menu-section data-sidebar-text>Giao việc</div>', $html);
        $this->assertMatchesRegularExpression('/<a(?=[^>]*data-menu-item="tasks")[^>]*aria-current="page"/', $html);
    }

    public function test_task_list_filters_by_due_date_assignee_and_status(): void
    {
        $manager = $this->makeUser('academic_staff');
        $ta = $this->makeUser('assistant');
        $teacher = $this->makeUser('teacher');

        $make = fn (string $title, User $assignee, string $due, string $status = 'new') => WorkTask::create([
            'title' => $title, 'creator_id' => $manager->id, 'assignee_id' => $assignee->id, 'branch_id' => $this->branch->id,
            'task_type' => 'one_time', 'due_date' => $due, 'due_time' => '18:00', 'status' => $status,
        ]);
        $make('Việc TA hôm nay', $ta, '2026-10-05');
        $make('Việc TA tuần sau', $ta, '2026-10-12');
        $make('Việc GV đã xong', $teacher, '2026-10-05', 'completed');

        $titles = function (array $query) use ($manager) {
            $page = $this->actingAs($manager)->get(route('tasks.index', ['tab' => 'assigned', ...$query]))->assertOk()->viewData('page');

            return collect($page['props']['tasks']['data'])->pluck('title')->sort()->values()->all();
        };

        $this->assertSame(['Việc GV đã xong', 'Việc TA hôm nay'], $titles(['date_from' => '2026-10-05', 'date_to' => '2026-10-05']));
        $this->assertSame(['Việc TA hôm nay', 'Việc TA tuần sau'], $titles(['assignee_id' => $ta->id]));
        $this->assertSame(['Việc GV đã xong'], $titles(['status' => 'completed']));
        $this->assertSame(['Việc TA tuần sau'], $titles(['assignee_id' => $ta->id, 'date_from' => '2026-10-06']));

        // Ô "Nhân viên" liệt kê người nhận của các việc trong phạm vi.
        $page = $this->actingAs($manager)->get(route('tasks.index'))->viewData('page');
        $this->assertEqualsCanonicalizing([$ta->id, $teacher->id], collect($page['props']['assignees'])->pluck('value')->all());

        // Khoảng ngày ngược → báo lỗi, không lọc sai.
        $this->actingAs($manager)->get(route('tasks.index', ['date_from' => '2026-10-10', 'date_to' => '2026-10-01']))
            ->assertSessionHasErrors('date_to');
    }

    /** Quản lý cơ sở được giao việc: menu "Thao tác" có bước chuyển và làm được như người nhận việc ở vai trò khác. */
    public function test_branch_manager_assignee_can_work_the_task(): void
    {
        $admin = $this->makeUser('admin');
        $manager = $this->makeUser('manager');
        $task = WorkTask::create([
            'title' => 'Task 1', 'description' => 'test', 'creator_id' => $admin->id, 'assignee_id' => $manager->id,
            'branch_id' => $this->branch->id, 'task_type' => 'recurring', 'frequency' => 'daily',
            'due_date' => now()->addDays(2)->toDateString(), 'due_time' => '18:00', 'status' => 'new',
        ]);

        $page = $this->actingAs($manager)->get(route('tasks.index'))->assertOk()->viewData('page');
        $row = collect($page['props']['tasks']['data'])->firstWhere('id', $task->id);
        $this->assertSame(['in_progress', 'blocked', 'pending_confirmation'], $row['allowed']);

        $this->actingAs($manager)->post(route('tasks.status.update', $task->id), ['status' => 'in_progress'])->assertSessionHasNoErrors();
        $this->assertSame('in_progress', $task->fresh()->status);

        $this->actingAs($manager)->post(route('tasks.status.update', $task->id), ['status' => 'pending_confirmation', 'reason' => 'Đã xong'])->assertSessionHasNoErrors();
        $this->assertSame('pending_confirmation', $task->fresh()->status);

        $this->actingAs($admin)->post(route('tasks.status.update', $task->id), ['status' => 'completed'])->assertSessionHasNoErrors();
        $this->assertSame('completed', $task->fresh()->status);
    }

    /** Quản lý cơ sở chỉ thấy việc mình nhận / mình giao, không thấy việc người khác giao cho nhân sự cùng chi nhánh. */
    public function test_branch_manager_only_sees_own_tasks(): void
    {
        $admin = $this->makeUser('admin');
        $manager = $this->makeUser('manager');
        $staff = $this->makeUser('academic_staff');
        $ta = $this->makeUser('assistant');

        $make = fn (string $title, User $creator, User $assignee, string $status = 'new') => WorkTask::create([
            'title' => $title, 'creator_id' => $creator->id, 'assignee_id' => $assignee->id, 'branch_id' => $this->branch->id,
            'task_type' => 'one_time', 'due_date' => now()->addDay()->toDateString(), 'status' => $status,
        ]);
        $mine = $make('Việc QLCS nhận', $admin, $manager);
        $given = $make('Việc QLCS giao', $manager, $ta, 'pending_confirmation');
        $other = $make('Việc Admin giao Học vụ', $admin, $staff, 'pending_confirmation');

        $page = $this->actingAs($manager)->get(route('tasks.index', ['tab' => 'all']))->assertOk()->viewData('page');
        $this->assertEqualsCanonicalizing([$mine->id, $given->id], collect($page['props']['tasks']['data'])->pluck('id')->all());
        $this->assertSame(2, $page['props']['counts']['all']);
        $this->assertSame(1, $page['props']['counts']['pending']);
        $this->assertEqualsCanonicalizing([$manager->id, $ta->id], collect($page['props']['assignees'])->pluck('value')->all());

        $this->actingAs($manager)->get(route('tasks.show', $other->id))->assertNotFound();
        $this->actingAs($manager)->post(route('tasks.status.update', $other->id), ['status' => 'completed'])->assertForbidden();
        $this->assertSame([$given->id], WorkTask::query()->awaitingConfirmationBy($manager)->pluck('id')->all());

        // Việc mình giao vẫn duyệt được; Admin vẫn thấy mọi việc.
        $this->actingAs($manager)->post(route('tasks.status.update', $given->id), ['status' => 'completed'])->assertSessionHasNoErrors();
        $this->assertSame('completed', $given->fresh()->status);
        $page = $this->actingAs($admin)->get(route('tasks.index', ['tab' => 'all']))->viewData('page');
        $this->assertSame(3, $page['props']['counts']['all']);
    }

    public function test_teacher_assistant_and_accountant_can_open_their_notifications(): void
    {
        foreach (['teacher', 'teacher_fulltime', 'teacher_parttime', 'assistant', 'accountant'] as $role) {
            $this->actingAs($this->makeUser($role))->get(route('notifications.index'))->assertOk();
        }
    }

    private function makeUser(string $role): User
    {
        if (isset($this->users[$role])) {
            return $this->users[$role];
        }

        $user = User::create([
            'name' => 'User '.$role,
            'email' => $role.'@menglish.test',
            'password' => bcrypt('password'),
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);
        $user->syncRoles([$role]);

        return $this->users[$role] = $user;
    }
}
