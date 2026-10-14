<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Roles;
use App\Support\RoleSwitcher;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Đổi vai trò nhanh trên bản thử nghiệm (dungthu): chỉ khi bật ROLE_SWITCHER_ENABLED, không bao giờ trên production. */
class RoleSwitcherTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teacher;

    private User $assistant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->user('admin@menglish.edu.vn', Roles::ADMIN);
        $this->teacher = $this->user('gv.cohuu2@menglish.edu.vn', Roles::TEACHER_FULLTIME);
        $this->assistant = $this->user('ta.hai@menglish.edu.vn', Roles::ASSISTANT);
    }

    public function test_switcher_is_off_by_default(): void
    {
        $this->login($this->admin);

        $this->get(route('dashboard'))
            ->assertDontSee('data-role-switcher', false)
            ->assertInertia(fn (AssertableInertia $page) => $page->where('roleSwitcher', null));
        $this->post(route('role-switcher.switch', $this->teacher))->assertNotFound();
        $this->assertAuthenticatedAs($this->admin);
    }

    public function test_switcher_never_works_in_production_even_if_enabled(): void
    {
        config(['role_switcher.enabled' => true]);
        $this->login($this->admin);
        $this->app['env'] = 'production';
        // Ngoài môi trường testing Laravel kiểm tra CSRF thật: bỏ qua để thấy đúng phản hồi của route.
        $this->withoutMiddleware(PreventRequestForgery::class);

        $this->get(route('dashboard'))->assertInertia(fn (AssertableInertia $page) => $page->where('roleSwitcher', null));
        $this->post(route('role-switcher.switch', $this->teacher))->assertNotFound();
        $this->assertAuthenticatedAs($this->admin);
    }

    public function test_admin_switches_between_demo_accounts_and_back(): void
    {
        config(['role_switcher.enabled' => true]);
        $this->login($this->admin);

        $this->get(route('dashboard'))->assertSee('data-role-switcher', false)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('roleSwitcher.origin', null)
            ->where('roleSwitcher.groups.0.role', Roles::ADMIN)
            ->where('roleSwitcher.groups.0.accounts.0.current', true)
            ->where('roleSwitcher.groups.1.role', Roles::TEACHER_FULLTIME)
            ->where('roleSwitcher.groups.1.accounts.0.id', $this->teacher->id)
            ->where('roleSwitcher.groups.1.accounts.0.note', 'KPI tốt'));

        // Vào thẳng màn hằng ngày của giáo viên, đăng nhập hẳn bằng tài khoản giáo viên.
        $this->post(route('role-switcher.switch', $this->teacher))->assertRedirect(route('teacher.home', absolute: false));
        $this->assertAuthenticatedAs($this->teacher);
        $this->assertSame($this->admin->id, session(RoleSwitcher::SESSION_KEY));

        // Phiên vẫn hợp lệ ở request sau (hash mật khẩu của tài khoản mới) và menu vẫn hiện để chuyển tiếp / quay về.
        $this->get(route('teacher.home'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('roleSwitcher.origin.name', $this->admin->name)
            ->where('roleSwitcher.current_role', 'Giáo viên Full-time'));
        $this->assertAuthenticatedAs($this->teacher);

        $this->post(route('role-switcher.switch', $this->assistant))->assertRedirect(route('portal.ta-tasks', absolute: false));
        $this->assertAuthenticatedAs($this->assistant);
        $this->get(route('portal.ta-tasks'))->assertOk();

        $this->post(route('role-switcher.switch', $this->admin))->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($this->admin);
        $this->assertNull(session(RoleSwitcher::SESSION_KEY));
    }

    public function test_only_super_admin_can_start_switching(): void
    {
        config(['role_switcher.enabled' => true]);
        $this->login($this->teacher);

        $this->get(route('teacher.home'))->assertInertia(fn (AssertableInertia $page) => $page->where('roleSwitcher', null));
        $this->post(route('role-switcher.switch', $this->admin))->assertNotFound();
        $this->assertAuthenticatedAs($this->teacher);
    }

    public function test_cannot_switch_to_account_outside_the_menu(): void
    {
        config(['role_switcher.enabled' => true]);
        // Vai trò Giáo viên Full-time đã có tài khoản mẫu → giáo viên khác (người thật) không có trong menu.
        $realTeacher = $this->user('gv.that@example.com', Roles::TEACHER_FULLTIME);
        $this->login($this->admin);

        $this->post(route('role-switcher.switch', $realTeacher))->assertForbidden();
        $this->assertAuthenticatedAs($this->admin);
    }

    public function test_role_without_demo_account_falls_back_to_its_first_active_users(): void
    {
        config(['role_switcher.enabled' => true]);
        $sales = $this->user('tuvan.that@example.com', Roles::SALES_CONSULTANT);
        $this->user('tuvan.khoa@example.com', Roles::SALES_CONSULTANT)->update(['is_active' => false]);
        $this->login($this->admin);

        $this->get(route('dashboard'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('roleSwitcher.groups.1.role', Roles::SALES_CONSULTANT)
            ->has('roleSwitcher.groups.1.accounts', 1)
            ->where('roleSwitcher.groups.1.accounts.0.id', $sales->id));

        $this->post(route('role-switcher.switch', $sales))->assertRedirect();
        $this->assertAuthenticatedAs($sales);
    }

    public function test_demo_account_forced_to_change_password_can_still_switch_away(): void
    {
        config(['role_switcher.enabled' => true]);
        $newManager = $this->user('ketoan.moi@menglish.edu.vn', Roles::MANAGER);
        $newManager->update(['must_change_password' => true]);
        $this->login($this->admin);

        $this->post(route('role-switcher.switch', $newManager));
        $this->assertAuthenticatedAs($newManager);
        $this->get(route('dashboard'))->assertRedirect(route('profile.edit', ['force_password' => 1]));

        $this->post(route('role-switcher.switch', $this->teacher))->assertRedirect(route('teacher.home', absolute: false));
        $this->assertAuthenticatedAs($this->teacher);
    }

    private function user(string $email, string $role): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->assignRole($role);

        return $user;
    }

    private function login(User $user): void
    {
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->assertAuthenticatedAs($user);
    }
}
