<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Student;
use App\Models\User;
use App\Support\Guide\UserGuide;
use App\Support\Navigation\SidebarMenu;
use App\Support\Roles;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Trang "Hướng dẫn sử dụng" theo vai trò: ai cũng mở được, vai trò của mình xếp đầu, nội dung và ảnh đầy đủ. */
class UserGuideTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Chi nhánh Cầu Giấy', 'code' => 'CG', 'is_active' => true]);
    }

    public function test_every_role_opens_the_guide_with_its_own_guide_first(): void
    {
        foreach (UserGuide::GUIDES as $id => $roles) {
            foreach ($roles as $role) {
                $this->actingAs($this->makeUser($role))->get(route('guide.index'))
                    ->assertOk()
                    ->assertInertia(fn (AssertableInertia $page) => $page->component('Guide/Index')
                        ->where('selected', $id)
                        ->where('guides.0.id', $id)
                        ->where('guides.0.own', true));
            }
        }
    }

    public function test_staff_see_every_role_guide_but_students_only_their_own(): void
    {
        $staff = $this->actingAs($this->makeUser(Roles::ACADEMIC_STAFF))->get(route('guide.index'));
        $staff->assertInertia(fn (AssertableInertia $page) => $page->has('guides', count(UserGuide::GUIDES) + 1));

        $this->actingAs($this->makeUser(Roles::STUDENT))->get(route('guide.index', ['vai-tro' => 'admin']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('guides', 2)
                ->where('guides.0.id', 'hoc-vien')
                ->where('guides.1.id', UserGuide::COMMON)
                ->where('selected', 'hoc-vien'));
    }

    public function test_open_screen_button_only_for_screens_the_user_can_open(): void
    {
        $teacher = $this->makeUser(Roles::TEACHER_PARTTIME);
        $guides = collect(app(UserGuide::class)->for($teacher));
        $menu = app(SidebarMenu::class);

        foreach ($guides as $guide) {
            foreach ($guide['sections'] as $section) {
                if (! empty($section['url'])) {
                    $this->assertTrue($menu->canSee($teacher, ['route' => $section['route']]), "{$guide['id']}#{$section['id']}");
                }
            }
        }
        $this->assertNull(collect($guides->firstWhere('id', 'admin')['sections'])->firstWhere('url', route('roles.index')));
    }

    public function test_guide_link_is_in_the_menu_for_everyone(): void
    {
        foreach (Roles::ALL as $role) {
            $routes = collect(app(SidebarMenu::class)->groupsFor($this->makeUser($role)))->flatMap(fn (array $g) => collect($g['items'])->pluck('route'));
            // Học viên / phụ huynh mở hướng dẫn từ đầu trang cổng học viên (PortalTopHeader), menu chỉ còn cổng học viên.
            if ($role === Roles::STUDENT) {
                $this->assertNotContains('guide.index', $routes);
                $this->actingAs($this->makeUser($role))->get(route('guide.index'))->assertOk();

                continue;
            }
            $this->assertContains('guide.index', $routes, $role);
        }
    }

    public function test_guide_content_is_complete_and_images_exist(): void
    {
        foreach ([...array_keys(UserGuide::GUIDES), UserGuide::COMMON] as $id) {
            $guide = UserGuide::content($id);
            foreach (['label', 'icon', 'summary'] as $key) {
                $this->assertNotEmpty($guide[$key] ?? null, "{$id}: thiếu {$key}");
            }
            $this->assertNotEmpty($guide['sections'], "{$id}: chưa có mục nào");

            $ids = array_column($guide['sections'], 'id');
            $this->assertSame(array_unique($ids), $ids, "{$id}: trùng id mục");

            foreach ($guide['sections'] as $section) {
                $where = "{$id}#{$section['id']}";
                $this->assertNotEmpty($section['title'] ?? null, $where);
                $this->assertNotEmpty($section['steps'] ?? null, "{$where}: chưa có bước");
                $this->assertNotEmpty($section['images'] ?? null, "{$where}: chưa có ảnh");
                foreach ($section['images'] as $image) {
                    $this->assertFileExists(public_path('images/huong-dan/'.$image['src']), $where);
                }
                if (! empty($section['route'])) {
                    $this->assertTrue(Route::has($section['route']), "{$where}: route {$section['route']} không tồn tại");
                }
            }
        }
    }

    private function makeUser(string $role): User
    {
        static $n = 0;
        $n++;
        $user = User::create([
            'name' => 'User '.$role,
            'email' => "{$role}{$n}@menglish.test",
            'password' => bcrypt('password'),
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);
        $user->syncRoles([$role]);

        if ($role === Roles::STUDENT) {
            Student::create([
                'user_id' => $user->id,
                'name' => $user->name,
                'code' => 'HV-GUIDE-'.$n,
                'phone' => '09876543'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
                'status' => 'active',
                'branch_id' => $this->branch->id,
            ]);
        }

        return $user;
    }
}
