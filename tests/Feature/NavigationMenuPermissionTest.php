<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Student;
use App\Models\User;
use App\Support\Navigation\SidebarMenu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Đảm bảo menu sidebar / "Tạo mới" không hiển thị link mà user bấm vào lại bị 403,
 * và layout không phụ thuộc Google Fonts.
 */
class NavigationMenuPermissionTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $this->seed(\Database\Seeders\RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Chi nhánh Cầu Giấy', 'code' => 'CG', 'is_active' => true]);
    }

    public function test_every_visible_sidebar_link_is_accessible_for_each_seeded_role(): void
    {
        $failures = [];

        foreach (array_keys(config('access.roles')) as $role) {
            $user = $this->makeUser($role);

            $dashboard = $this->actingAs($user)->get(route('dashboard'));
            $dashboard->assertOk();

            $links = $this->sidebarLinks($dashboard->getContent());
            $this->assertNotEmpty($links, "Sidebar của role {$role} phải có ít nhất link Tổng quan.");

            foreach ($links as $url) {
                $status = $this->actingAs($user)->get($url)->getStatusCode();
                if ($status === 403) {
                    $failures[] = "{$role}: {$url} => 403";
                }
            }
        }

        $this->assertSame([], $failures, "Menu hiển thị link bị chặn quyền:\n".implode("\n", $failures));
    }

    public function test_quick_create_links_are_accessible_for_each_seeded_role(): void
    {
        $menu = app(SidebarMenu::class);
        $failures = [];

        foreach (array_keys(config('access.roles')) as $role) {
            $user = $this->makeUser($role);
            foreach ($menu->quickCreateFor($user) as $item) {
                $status = $this->actingAs($user)->get($item['url'])->getStatusCode();
                if ($status === 403) {
                    $failures[] = "{$role}: {$item['url']} => 403";
                }
            }
        }

        $this->assertSame([], $failures, implode("\n", $failures));
    }

    public function test_menu_item_ability_is_derived_from_route_middleware(): void
    {
        $menu = app(SidebarMenu::class);

        $this->assertSame(['kpi.view'], $menu->routeAbilities('kpi.monthly'));
        $this->assertSame(['placement_test.view'], $menu->routeAbilities('placement-tests.index'));
        $this->assertSame(['notification.view'], $menu->routeAbilities('notifications.index'));
        $this->assertContains('class.view', $menu->routeAbilities('classes.create'));
        $this->assertContains('class.create', $menu->routeAbilities('classes.create'));
    }

    public function test_menu_has_no_duplicate_targets_or_dev_numbering(): void
    {
        $menu = app(SidebarMenu::class);
        $routes = [];

        foreach ($menu->definition() as $group) {
            foreach ($group['items'] as $item) {
                $this->assertDoesNotMatchRegularExpression('/\(#\d+\)|#\d+\)/', $item['label']);
                $routes[] = $item['route'];
            }
        }

        $duplicates = array_keys(array_filter(array_count_values($routes), fn ($n) => $n > 1));
        $this->assertSame([], $duplicates, 'Route xuất hiện nhiều lần trong menu: '.implode(', ', $duplicates));
    }

    public function test_group_is_hidden_when_user_has_no_visible_item(): void
    {
        // Sales chỉ có quyền CRM: không được thấy nhóm Phân quyền/Học phí.
        $sales = $this->makeUser('sales_consultant');
        $groups = collect(app(SidebarMenu::class)->groupsFor($sales))->pluck('id');

        $this->assertContains('crm', $groups);
        $this->assertNotContains('permissions', $groups);
        $this->assertNotContains('tuition', $groups);
        foreach (app(SidebarMenu::class)->groupsFor($sales) as $group) {
            $this->assertNotEmpty($group['items']);
        }
    }

    public function test_layout_does_not_load_google_fonts(): void
    {
        $admin = $this->makeUser('admin');

        $html = $this->actingAs($admin)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringNotContainsString('fonts.googleapis.com', $html);
        $this->assertStringNotContainsString('fonts.gstatic.com', $html);
    }

    public function test_guest_layout_does_not_load_google_fonts(): void
    {
        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertStringNotContainsString('fonts.googleapis.com', $html);
    }

    private function makeUser(string $role): User
    {
        $user = User::create([
            'name' => 'User '.$role,
            'email' => $role.'@menglish.test',
            'password' => bcrypt('password'),
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);
        $user->syncRoles([$role]);

        if ($role === 'student') {
            // Cổng học viên yêu cầu tài khoản liên kết hồ sơ học viên.
            Student::create([
                'user_id' => $user->id,
                'name' => $user->name,
                'code' => 'HV-NAV-01',
                'phone' => '0987654321',
                'status' => 'active',
                'branch_id' => $this->branch->id,
            ]);
        }

        return $user;
    }

    /**
     * Lấy toàn bộ href nội bộ trong <aside data-sidebar> (menu sidebar).
     *
     * @return list<string>
     */
    private function sidebarLinks(string $html): array
    {
        $this->assertMatchesRegularExpression('/<aside[^>]*data-sidebar/', $html);
        $start = strpos($html, 'data-sidebar');
        $end = strpos($html, '</aside>', $start);
        $aside = substr($html, $start, $end - $start);

        preg_match_all('/<a\s[^>]*href="([^"]+)"/', $aside, $matches);
        $base = rtrim(url('/'), '/');

        return collect($matches[1])
            ->map(fn ($href) => html_entity_decode($href))
            ->filter(fn ($href) => str_starts_with($href, $base))
            ->unique()
            ->values()
            ->all();
    }
}
