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

        // Sales đề xuất được việc (cho Admin / người duyệt công việc) như GV / TA — mọi nhân sự giao được việc cho Admin.
        $groups = collect($menu->groupsFor($this->makeUser('sales_consultant')))->keyBy('id');
        $this->assertArrayHasKey('tasks', $groups->all());
        $this->assertSame(route('tasks.create'), $groups['task_create']['url']);

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

    /** Mọi nhân sự giao được việc cho Admin, kể cả Quản lý cơ sở (chỉ giao trong chi nhánh) và Sales / GV / TA (chỉ đề xuất). */
    public function test_every_staff_role_can_assign_a_task_to_admin(): void
    {
        $otherBranch = Branch::create(['name' => 'Chi nhánh Hà Đông', 'code' => 'HD', 'is_active' => true]);
        $admin = User::create(['name' => 'Admin Hà Đông', 'email' => 'admin.hd@menglish.test', 'password' => bcrypt('password'), 'branch_id' => $otherBranch->id, 'is_active' => true]);
        $admin->syncRoles(['admin']);
        $teacherB = User::create(['name' => 'GV Hà Đông', 'email' => 'gv.hd@menglish.test', 'password' => bcrypt('password'), 'branch_id' => $otherBranch->id, 'is_active' => true]);
        $teacherB->syncRoles(['teacher_fulltime']);

        foreach (['manager', 'academic_lead', 'academic_staff', 'sales_consultant', 'teacher_fulltime', 'teacher_parttime', 'assistant'] as $role) {
            $staff = $this->makeUser($role);
            $page = $this->actingAs($staff)->get(route('tasks.create'))->assertOk()->viewData('page');
            $this->assertContains($admin->id, collect($page['props']['users'])->pluck('value')->all(), $role);

            $this->actingAs($staff)->post(route('tasks.store'), [
                'taskTitle' => 'Việc cho Admin từ '.$role, 'assignee' => $admin->id,
                'dueDate' => now()->addDay()->toDateString(), 'taskType' => 'one-time',
            ])->assertSessionHasNoErrors();
            $this->assertDatabaseHas('work_tasks', ['title' => 'Việc cho Admin từ '.$role, 'creator_id' => $staff->id, 'assignee_id' => $admin->id]);
        }

        // Giới hạn cũ vẫn giữ: Quản lý cơ sở không giao cho nhân sự chi nhánh khác, Sales không giao cho giáo viên.
        foreach (['manager', 'sales_consultant'] as $role) {
            $this->actingAs($this->makeUser($role))->post(route('tasks.store'), [
                'taskTitle' => 'Việc ngoài phạm vi', 'assignee' => $teacherB->id,
                'dueDate' => now()->addDay()->toDateString(), 'taskType' => 'one-time',
            ])->assertSessionHasErrors('assignee');
        }
        $this->assertDatabaseMissing('work_tasks', ['title' => 'Việc ngoài phạm vi']);
    }

    /** Danh sách đầu việc chỉ có "Của tôi" và "Tôi giao" — kể cả Admin, không còn tab "Tất cả" việc của người khác. */
    public function test_task_list_only_shows_my_tasks_and_tasks_i_assigned(): void
    {
        $admin = $this->makeUser('admin');
        $academic = $this->makeUser('academic_staff');
        $teacher = $this->makeUser('teacher');
        $ta = $this->makeUser('assistant');

        $make = fn (string $title, User $creator, User $assignee, string $status = 'new') => WorkTask::create([
            'title' => $title, 'creator_id' => $creator->id, 'assignee_id' => $assignee->id, 'branch_id' => $this->branch->id,
            'task_type' => 'one_time', 'due_date' => now()->addDay()->toDateString(), 'status' => $status,
        ]);
        $make('Việc giao cho Admin', $teacher, $admin);
        $make('Việc Admin giao', $admin, $ta, 'overdue');
        $make('Việc người khác', $academic, $teacher, 'overdue');
        $make('Việc người khác chờ duyệt', $academic, $ta, 'pending_confirmation');

        $props = fn (array $query = []) => $this->actingAs($admin)->get(route('tasks.index', $query))->assertOk()->viewData('page')['props'];
        $titles = fn (array $props) => collect($props['tasks']['data'])->pluck('title')->all();

        $page = $props();
        $this->assertSame('mine', $page['tab']);
        $this->assertSame(['Việc giao cho Admin'], $titles($page));
        $this->assertSame(['Việc Admin giao'], $titles($props(['tab' => 'assigned'])));
        // Tab "Tất cả" cũ quay về "Của tôi".
        $this->assertSame(['Việc giao cho Admin'], $titles($props(['tab' => 'all'])));

        $this->assertArrayNotHasKey('all', $page['counts']);
        $this->assertSame(1, $page['counts']['mine']);
        $this->assertSame(1, $page['counts']['assigned']);
        $this->assertSame(1, $page['counts']['overdue']);
        $this->assertSame(0, $page['counts']['pending']);
        // Nút "Chờ xác nhận" vẫn đếm việc Admin cần duyệt.
        $this->assertSame(1, $page['counts']['approvals']);
        $this->assertEqualsCanonicalizing([$admin->id, $ta->id], collect($page['assignees'])->pluck('value')->all());
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
