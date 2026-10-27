<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\User;
use App\Support\Navigation\SidebarMenu;
use App\Support\Roles;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Mỗi vai trò bấm vào menu / nút "Nhiệm vụ", "Cổng…" phải tới màn của chính vai trò đó (rà soát 26/10/2026,
 * /mnt/project-files/ra-soat-man-hinh-sai-vai-tro/danh-sach-sai-lech.md).
 */
class RoleScreenRoutingTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    /** @var array<string, User> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-05 08:00:00'));
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->branch = Branch::create(['name' => 'Cầu Giấy', 'code' => 'CG-RS', 'is_active' => true]);
    }

    private function user(string $role): User
    {
        return $this->users[$role] ??= tap(User::factory()->create(['is_active' => true, 'branch_id' => $this->branch->id]))
            ->assignRole($role);
    }

    /** @return array<string, list<string>> label workspace => label tab */
    private function sidebar(User $user): array
    {
        return collect(app(SidebarMenu::class)->groupsFor($user))
            ->mapWithKeys(fn (array $group) => [$group['label'] => collect($group['items'])->pluck('label')->all()])
            ->all();
    }

    public function test_teacher_menu_no_longer_opens_assistant_task_screen(): void
    {
        $menu = $this->sidebar($this->user(Roles::TEACHER_PARTTIME));

        $this->assertArrayHasKey('Cổng Giáo viên', $menu);
        $this->assertNotContains('Nhiệm vụ hôm nay', $menu['Cổng Giáo viên']);
        $this->assertNotContains('Nhiệm vụ hôm nay của TA', $menu['Cổng Giáo viên']);
        $this->assertContains('Khách học thử', $menu['Cổng Giáo viên']);
        $this->assertContains('Chặng đang dạy & Order Test', $menu['Giáo trình']);
    }

    public function test_assistant_gets_own_portal_without_teacher_only_screens(): void
    {
        $menu = $this->sidebar($this->user(Roles::ASSISTANT));

        $this->assertArrayNotHasKey('Cổng Giáo viên', $menu);
        $this->assertSame('Nhiệm vụ hôm nay', $menu['Cổng Trợ giảng'][0]);
        $this->assertNotContains('Khách học thử', $menu['Cổng Trợ giảng']);
        $this->assertNotContains('Chặng đang dạy & Order Test', $menu['Giáo trình'] ?? []);
        $this->assertSame(['Đơn chấm công & nghỉ'], $menu['Xin duyệt']);
        $this->assertContains('Giờ trợ giảng của tôi', $menu['Chấm công']);
        $this->assertContains('Bảng công trợ giảng', $menu['Báo cáo của tôi']);
    }

    public function test_managers_do_not_see_teacher_side_screens_or_assistant_report_form(): void
    {
        foreach ([Roles::MANAGER, Roles::ACADEMIC_LEAD, Roles::ACADEMIC_STAFF] as $role) {
            $user = $this->user($role);
            $menu = $this->sidebar($user);

            $this->assertSame(['Đơn chấm công & nghỉ'], $menu['Xin duyệt'], $role);
            $this->assertNotContains('Chặng đang dạy & Order Test', $menu['Giáo trình'], $role);
            $this->assertNotContains('Giờ trợ giảng của tôi', $menu['Chấm công'], $role);

            $tasks = collect(app(SidebarMenu::class)->groupsFor($user))->firstWhere('id', 'tasks');
            $this->assertNotContains('tasks.class-reports.create', collect($tasks['actions'])->pluck('route')->all(), $role);
        }
    }

    public function test_student_sidebar_has_only_student_portal(): void
    {
        $this->assertSame(['Cổng Phụ huynh & Học sinh'], array_keys($this->sidebar($this->user(Roles::STUDENT))));
    }

    public function test_my_tasks_links_open_own_task_list_except_for_assistant(): void
    {
        $mine = route('tasks.index', ['tab' => 'mine']);

        foreach ([Roles::TEACHER_FULLTIME, Roles::SALES_CONSULTANT, Roles::ACADEMIC_STAFF] as $role) {
            $user = $this->user($role);
            $this->actingAs($user)->get(route('dashboard'))->assertInertia(fn (AssertableInertia $page) => $page
                ->where('welcome.tasksUrl', $mine)->where('welcome.tasksLabel', 'Việc của tôi'));
            $this->actingAs($user)->get(route('profile.edit'))->assertInertia(fn (AssertableInertia $page) => $page
                ->where('tasksUrl', $mine));
        }

        $assistant = $this->user(Roles::ASSISTANT);
        $this->actingAs($assistant)->get(route('profile.edit'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tasksUrl', route('portal.ta-tasks')));
        $this->actingAs($assistant)->get(route('dashboard'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('welcome.tasksUrl', route('portal.ta-tasks'))->where('welcome.tasksLabel', 'Nhiệm vụ hôm nay'));
    }

    public function test_assistant_of_class_only_takes_attendance(): void
    {
        $teacher = $this->user(Roles::TEACHER_FULLTIME);
        $assistant = $this->user(Roles::ASSISTANT);
        $course = Course::create(['name' => 'IELTS RS', 'code' => 'IELTS-RS', 'total_lessons' => 24, 'is_active' => true]);
        $class = ClassModel::create([
            'name' => 'Lớp RS-01', 'code' => 'RS-01', 'course_id' => $course->id, 'program' => $course->name, 'level' => 'B1',
            'branch_id' => $this->branch->id, 'room' => 'P101', 'status' => 'active', 'max_capacity' => 10,
            'start_date' => '2026-09-01', 'end_date' => '2026-12-31',
            'teacher_id' => $teacher->id, 'assistant_id' => $assistant->id,
        ]);
        $session = ClassSession::create([
            'class_id' => $class->id, 'branch_id' => $class->branch_id, 'date' => '2026-10-05', 'shift_name' => 'Slot 1',
            'type' => ClassSession::TYPE_REGULAR, 'start_time' => '18:00', 'end_time' => '19:30', 'room' => 'P101',
            'teacher_id' => $teacher->id, 'assistant_id' => $assistant->id, 'status' => 'scheduled',
        ]);

        $this->actingAs($assistant)->get(route('teacher.attendance', ['classId' => $class->id, 'session' => $session->id]))->assertOk();
        // Nhận xét: màn cũ chuyển về Báo cáo buổi học; trợ giảng vẫn bị chặn.
        $this->actingAs($assistant)->get(route('teacher.remarks', ['classId' => $class->id, 'session' => $session->id]))->assertForbidden();
        $this->actingAs($teacher)->get(route('teacher.remarks', ['classId' => $class->id, 'session' => $session->id]))
            ->assertRedirect(route('classes.session-report', ['id' => $class->id, 'session' => $session->id]));
        foreach ([
            route('teacher.homework', ['classId' => $class->id]),
            route('teacher.scores', $class->id),
            route('teacher.order-test', $class->id),
        ] as $url) {
            $this->actingAs($assistant)->get($url)->assertForbidden();
            $this->actingAs($teacher)->get($url)->assertOk();
        }

        $this->actingAs($assistant)->get(route('teacher.home'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('shifts.0.can_teach', false));
        $this->actingAs($teacher)->get(route('teacher.home'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('shifts.0.can_teach', true));
    }
}
