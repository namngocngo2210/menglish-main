<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use App\Support\Navigation\SidebarMenu;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * IX-4 — Bố cục theo chức năng: sidebar 1 mục / workspace, tab workspace, trang Cài đặt, tìm theo tên màn.
 */
class WorkspaceNavigationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Route GET của sidebar TRƯỚC IX-4 (menu ~90 mục + "Tạo mới"). Tất cả phải còn tới được
     * từ sidebar, tab workspace, nút hành động workspace, menu con Cài đặt hoặc "Tạo mới".
     */
    private const OLD_MENU_ROUTES = [
        'crm.pipeline', 'crm.customers.index', 'notifications.index', 'crm.waiting-list', 'classes.trial-booking', 'crm.closing-wizard',
        'crm.customers.won', 'crm.lost-deals', 'placement-tests.index', 'placement-tests.rubric-guide', 'crm.reports',
        // Hồ sơ lớp / Sơ đồ khối / Danh sách lớp chi tiết gộp vào Danh sách lớp + Trang lớp (route cũ chuyển hướng).
        'students.index', 'students.enrollments', 'classes.index',
        // Nhật ký sự vụ lớp là tab thứ hai của "Báo cáo & sự vụ" (tab trong trang, không còn mục menu riêng).
        'tasks.classes-dashboard', 'academic.dashboards.reports', 'surveys.index',
        'syllabus.documents', 'syllabus.builder', 'syllabus.assignments', 'syllabus.versions', 'syllabus.adjustment-requests',
        'syllabus.big-tests.distribution', 'syllabus.big-tests.schedules', 'syllabus.big-tests.results', 'syllabus.teaching-stages',
        'syllabus.teacher-view', 'syllabus.teacher-propose', 'syllabus.teacher-adjust',
        'tuition.students', 'tuition.import', 'tuition.receipts.create', 'tuition.receipts.approve', 'tuition.history',
        'tuition.invoices.cancellations', 'tuition.refunds', 'tuition.overdue',
        'finance.reports.revenue', 'finance.expenses.index', 'merchandise.index',
        'users.index', 'reports.journal', 'kpi.monthly', 'kpi.attendance-review', 'tasks.kpi-dashboard', 'payroll.kpi-leaderboard',
        'tasks.index', 'tasks.ta-assign', 'tasks.class-reports.create', 'tasks.manual-approvals', 'tickets.index',
        'payroll.timesheets.manual', 'payroll.timesheets.teachers', 'payroll.timesheets.appsheet', 'payroll.timesheets.sync-history',
        'payroll.periods.index', 'penalties.index',
        'branches.index', 'courses.index', 'course-levels.index', 'holidays.index', 'system-categories.index', 'media.index',
        'tasks.schedule-config', 'kpi.criteria', 'payroll.config.settings', 'payroll.config.teacher-rates', 'payroll.config.commission-tiers',
        'system-config.debt-reminders', 'tuition.config', 'system-config.bank-accounts', 'system-config.ticket-emails',
        'roles.index', 'permissions.index', 'activity-logs.index', 'reports.all', 'system-config.hosting',
        'reports.my', 'portal.my-salary',
        'teacher.home', 'portal.teacher.submissions', 'teacher.trial-guests', 'portal.ta-tasks',
        'portal.student.home', 'portal.student.homework', 'portal.student.pronunciation', 'portal.student.notifications',
        'portal.student.survey', 'portal.student.feedback', 'portal.app-shell',
        'crm.customers.create', 'classes.create', 'tasks.create', 'tickets.create',
    ];

    /** Route chỉ dành cho "đối tượng" (cổng giáo viên / học viên) — Admin không tự có. */
    private const AUDIENCE_ROUTES = [
        'teacher.home', 'portal.teacher.submissions', 'teacher.trial-guests', 'portal.ta-tasks',
        'portal.student.home', 'portal.student.homework', 'portal.student.pronunciation', 'portal.student.notifications',
        'portal.student.survey', 'portal.student.feedback', 'portal.app-shell', 'payroll.timesheets.teachers',
    ];

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Chi nhánh Cầu Giấy', 'code' => 'CG', 'is_active' => true]);
    }

    public function test_every_old_menu_route_is_still_reachable(): void
    {
        $menu = app(SidebarMenu::class);
        $declared = collect($menu->definition())
            ->flatMap(fn (array $group) => [...$group['items'], ...($group['actions'] ?? [])])
            ->merge(collect($menu->settingsDefinition())->flatMap(fn (array $section) => $section['items']))
            ->merge($menu->quickCreateDefinition())
            ->pluck('route')->unique();

        $this->assertSame([], array_values(array_diff(self::OLD_MENU_ROUTES, $declared->all())), 'Route menu cũ không còn chỗ nào trỏ tới.');

        // Admin thực sự thấy mọi màn cũ (trừ màn "đối tượng" của cổng GV / học viên).
        $admin = $this->makeUser('admin');
        $visible = collect($menu->groupsFor($admin))
            ->flatMap(fn (array $group) => [...$group['items'], ...$group['actions']])
            ->merge(collect($menu->settingsFor($admin))->flatMap(fn (array $section) => $section['items']))
            ->merge($menu->quickCreateFor($admin))
            ->pluck('route')->unique();

        $expected = array_diff(self::OLD_MENU_ROUTES, self::AUDIENCE_ROUTES);
        $this->assertSame([], array_values(array_diff($expected, $visible->all())));
    }

    public function test_each_route_belongs_to_exactly_one_place(): void
    {
        $menu = app(SidebarMenu::class);
        // Chip lọc theo query (vd. Danh sách ?sla=1) dùng lại route của tab cha, không phải màn riêng.
        $routes = collect($menu->definition())->flatMap(fn (array $group) => collect($group['items'])->filter(fn (array $item) => empty($item['query']))->pluck('route'))
            ->merge(collect($menu->settingsDefinition())->flatMap(fn (array $section) => collect($section['items'])->pluck('route')));

        $this->assertSame([], $routes->countBy()->filter(fn ($n) => $n > 1)->keys()->all());
    }

    public function test_admin_sidebar_has_at_most_25_top_level_entries(): void
    {
        $html = $this->actingAs($this->makeUser('admin'))->get(route('dashboard'))->assertOk()->getContent();
        $aside = substr($html, strpos($html, 'data-sidebar'), strpos($html, '</aside>') - strpos($html, 'data-sidebar'));

        $count = preg_match_all('/data-menu-item="/', $aside);
        $this->assertGreaterThan(10, $count);
        $this->assertLessThanOrEqual(25, $count);
        // Hành động không còn nằm trên menu.
        $this->assertStringNotContainsString('>Nhập danh sách từ Excel<', $aside);
        $this->assertStringNotContainsString(route('tuition.import'), $aside);
        $this->assertStringNotContainsString(route('tasks.ta-assign'), $aside);
    }

    public function test_sidebar_entry_is_active_for_any_tab_of_its_workspace(): void
    {
        $html = $this->actingAs($this->makeUser('admin'))->get(route('tuition.history'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<a href="[^"]+"\s+aria-current="page"[^>]*data-menu-item="tuition"/', $html);
        $this->assertDoesNotMatchRegularExpression('/aria-current="page"[^>]*data-menu-item="crm"/', $html);
    }

    public function test_workspace_tabs_render_with_aria_current_and_actions(): void
    {
        $html = $this->actingAs($this->makeUser('admin'))->get(route('tuition.history'))->assertOk()->getContent();

        $this->assertStringContainsString('data-workspace-tabs="tuition"', $html);
        $this->assertMatchesRegularExpression('#<a href="'.preg_quote(route('tuition.history'), '#').'"\s+aria-current="page"#', $html);
        $this->assertStringContainsString('href="'.route('tuition.overdue').'"', $html);
        // Nút hành động của workspace: Nhập Excel mở modal, Lập phiếu thu mở trang.
        $this->assertMatchesRegularExpression('#href="'.preg_quote(route('tuition.import'), '#').'"[^>]*hx-get=#', $html);
        $this->assertStringContainsString('href="'.route('tuition.receipts.create').'"', $html);
    }

    public function test_workspace_tabs_hide_unauthorized_tabs(): void
    {
        // Học vụ: được xem + lập phiếu thu nhưng không xử lý kế toán → không thấy tab Học phí, vẫn có nút lập phiếu.
        $html = $this->actingAs($this->makeUser('academic_staff'))->get(route('tuition.students'))->assertOk()->getContent();
        $this->assertStringNotContainsString('href="'.route('tuition.receipts.approve').'"', $html);
        $this->assertStringContainsString('href="'.route('tuition.receipts.create').'"', $html);

        // Sales: chip "Đã xóa" (dưới tab Danh sách) cần lead.delete.
        $html = $this->actingAs($this->makeUser('sales_consultant'))->get(route('crm.customers.index'))->assertOk()->getContent();
        $this->assertStringContainsString('data-workspace-tabs="crm"', $html);
        $this->assertStringNotContainsString('href="'.route('crm.customers.deleted').'"', $html);
        $this->assertStringContainsString('href="'.route('crm.lost-deals').'"', $html);
    }

    public function test_workspace_tabs_render_once_and_keep_query_on_same_route_only(): void
    {
        $admin = $this->makeUser('admin');
        $html = $this->actingAs($admin)->get(route('crm.customers.index', ['search' => 'abc']))->assertOk()->getContent();

        // Header CRM tự đặt tab → layout không chèn thêm.
        $this->assertSame(1, substr_count($html, 'data-workspace-tabs="crm"'));
        $this->assertStringContainsString('href="'.e(route('crm.customers.index', ['search' => 'abc'])).'"', $html);
        $this->assertStringContainsString('href="'.route('crm.pipeline').'"', $html);
        $this->assertStringNotContainsString(e(route('crm.pipeline', ['search' => 'abc'])), $html);
    }

    public function test_layout_injects_tabs_on_pages_without_own_header(): void
    {
        $this->actingAs($this->makeUser('admin'))->get(route('students.enrollments'))->assertOk()
            ->assertSee('data-workspace-tabs="students"', false)
            ->assertSee('href="'.route('students.index').'"', false);
    }

    public function test_settings_redirects_to_first_accessible_item(): void
    {
        $this->actingAs($this->makeUser('admin'))->get(route('settings.index'))->assertRedirect(route('branches.index'));

        // Học vụ không có quyền cơ sở / ngày nghỉ… → mục đầu tiên là Khóa học & Bảng giá.
        $this->actingAs($this->makeUser('academic_staff'))->get(route('settings.index'))->assertRedirect(route('courses.index'));
    }

    public function test_settings_is_forbidden_without_any_item(): void
    {
        $sales = $this->makeUser('sales_consultant');

        $this->actingAs($sales)->get(route('settings.index'))->assertForbidden();
        $this->actingAs($sales)->get(route('dashboard'))->assertOk()->assertDontSee('data-menu-item="settings"', false);
    }

    public function test_settings_pages_show_sub_navigation(): void
    {
        $html = $this->actingAs($this->makeUser('admin'))->get(route('holidays.index'))->assertOk()->getContent();

        $this->assertStringContainsString('data-settings-nav', $html);
        $this->assertMatchesRegularExpression('#<a href="'.preg_quote(route('holidays.index'), '#').'"\s+aria-current="page"#', $html);
        $this->assertStringContainsString('href="'.route('roles.index').'"', $html);
        $this->assertMatchesRegularExpression('/aria-current="page"[^>]*data-menu-item="settings"/', $html);

        // Trang nghiệp vụ không có menu con Cài đặt.
        $this->actingAs($this->makeUser('admin'))->get(route('tuition.history'))->assertOk()->assertDontSee('data-settings-nav', false);
    }

    public function test_global_search_matches_screen_names(): void
    {
        $admin = $this->makeUser('admin');

        // Không phân biệt dấu / hoa thường.
        $this->actingAs($admin)->get(route('search', ['q' => 'ngay nghi']))->assertOk()
            ->assertSee('data-search-screens', false)
            ->assertSee('Cài đặt › Ngày nghỉ lễ')
            ->assertSee('href="'.route('holidays.index').'"', false);

        $this->actingAs($admin)->get(route('search', ['q' => 'Học phí']))->assertOk()
            ->assertSee('Học phí › Lịch sử thu');

        // Chỉ màn user được mở.
        $this->actingAs($this->makeUser('sales_consultant'))->get(route('search', ['q' => 'vai tro']))->assertOk()
            ->assertDontSee('data-search-screens', false);
    }

    public function test_menu_permission_resolution_does_not_query_per_item(): void
    {
        $menu = app(SidebarMenu::class);
        $user = $this->makeUser('accountant')->fresh();
        $request = Request::create(route('tuition.history'));
        // Nạp sẵn vai trò / quyền / override của user (1 lần / request, việc của Gate).
        $user->can('tuition.view');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $menu->groupsFor($user, $request);
        $menu->settingsFor($user, $request);
        $menu->workspaceFor($user, $request, 'tuition');
        $menu->quickCreateFor($user);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // ~100 mục menu nhưng không phát sinh truy vấn nào sau khi quyền đã nạp.
        $this->assertSame(0, $queries);
    }

    /** @var array<string, User> */
    private array $users = [];

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

    public function test_crm_tabs_are_compact_with_quick_filters_and_menu(): void
    {
        $html = $this->actingAs($this->makeUser('admin'))->get(route('crm.customers.index'))->assertOk()->getContent();
        $bar = substr($html, strpos($html, 'data-workspace-tabs="crm"'));

        // Chỉ 2 tab; các màn khác là chip lọc nhanh / menu "Xếp lớp".
        $tabs = substr($bar, 0, strpos($bar, 'data-workspace-chips'));
        $this->assertStringContainsString('Kanban', $tabs);
        $this->assertStringNotContainsString('>Chờ xếp lớp<', $tabs);
        $this->assertStringContainsString('data-workspace-chips', $bar);
        $this->assertStringContainsString(route('crm.customers.index', ['sla' => 1]), $bar);
        $this->assertStringContainsString(route('crm.waiting-list'), $bar);
        $this->assertStringContainsString('data-workspace-menu="Xếp lớp"', $bar);
        $this->assertStringContainsString(route('crm.confirmations'), $bar);
        $this->assertStringContainsString(route('crm.closing-wizard'), $bar);
        $this->assertStringNotContainsString(route('crm.reports'), $bar);

        // Trang con (chip) vẫn thuộc workspace CRM, tab Danh sách đang mở.
        $this->get(route('crm.lost-deals'))->assertOk()->assertSee('data-workspace-chips', false);
    }

    public function test_crm_sla_quick_filter_lists_only_stale_new_leads(): void
    {
        $admin = $this->makeUser('admin');
        $make = fn (string $name) => \App\Models\CrmCustomer::create([
            'code' => \App\Models\CrmCustomer::generateCode(), 'name' => $name,
            'phone' => '09'.random_int(10000000, 99999999), 'stage' => 'new',
        ]);
        $make('Khách Quá Hạn SLA')->forceFill(['created_at' => now()->subHours(30)])->saveQuietly();
        $make('Khách Mới Toanh');

        $this->actingAs($admin)->get(route('crm.customers.index', ['sla' => 1]))->assertOk()
            ->assertSee('Khách Quá Hạn SLA')->assertDontSee('Khách Mới Toanh');
    }
}
