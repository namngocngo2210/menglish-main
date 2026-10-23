<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use App\Support\Roles;
use App\Support\RoleSwitcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Đổi vai trò trên bản thử nghiệm (dungthu): mã truy cập chung → chọn vai trò / người, không cần mật khẩu. */
class RoleSwitcherTest extends TestCase
{
    use RefreshDatabase;

    private const CODE = 'thu-nghiem-123';

    protected function setUp(): void
    {
        parent::setUp();
        config(['role_switcher.enabled' => true, 'role_switcher.code' => self::CODE]);
        RateLimiter::clear('role-switcher:127.0.0.1');
    }

    private function makeUser(string $role, string $name, array $attributes = []): User
    {
        $user = User::factory()->create(['name' => $name, ...$attributes]);
        $user->assignRole($role);

        return $user;
    }

    private function unlocked(): static
    {
        return $this->withCookie(RoleSwitcher::COOKIE, RoleSwitcher::token());
    }

    public function test_disabled_by_default(): void
    {
        config(['role_switcher.enabled' => false]);
        $teacher = $this->makeUser(Roles::TEACHER_PARTTIME, 'GV Một');

        $this->get('/login')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('roleSwitcher', null));
        $this->post(route('role-switcher.unlock'), ['code' => self::CODE])->assertNotFound();
        $this->unlocked()->post(route('role-switcher.switch', $teacher))->assertNotFound();
        $this->assertGuest();
    }

    public function test_disabled_without_access_code(): void
    {
        config(['role_switcher.code' => '']);

        $this->assertFalse(RoleSwitcher::enabled());
        $this->post(route('role-switcher.unlock'), ['code' => ''])->assertNotFound();
    }

    public function test_never_runs_on_production_even_when_enabled(): void
    {
        $this->app['env'] = 'production';
        // APP_ENV khác testing thì CSRF bật lại (419) — bỏ middleware để chạm tới controller.
        $this->withoutMiddleware();
        $teacher = $this->makeUser(Roles::TEACHER_PARTTIME, 'GV Một');

        $this->assertFalse(RoleSwitcher::enabled());
        $this->post(route('role-switcher.unlock'), ['code' => self::CODE])->assertNotFound();
        $this->unlocked()->post(route('role-switcher.switch', $teacher))->assertNotFound();
        $this->assertGuest();
    }

    public function test_login_page_asks_for_the_code_without_listing_accounts(): void
    {
        $this->makeUser(Roles::ACADEMIC_STAFF, 'Học vụ Bí Mật');

        $this->get('/login')
            ->assertOk()
            ->assertSee('Xem thử theo vai trò')
            ->assertSee('Mã truy cập')
            ->assertDontSee('Học vụ Bí Mật')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('roleSwitcher.unlocked', false)->missing('roleSwitcher.groups'));
    }

    public function test_wrong_code_is_rejected_and_rate_limited(): void
    {
        $this->from('/login')->post(route('role-switcher.unlock'), ['code' => 'sai'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['code' => 'Mã truy cập không đúng.'])
            ->assertCookieMissing(RoleSwitcher::COOKIE);

        for ($i = 0; $i < 4; $i++) {
            $this->post(route('role-switcher.unlock'), ['code' => 'sai']);
        }

        // Quá 5 lần sai: mã đúng cũng bị chặn tạm thời.
        $this->post(route('role-switcher.unlock'), ['code' => self::CODE])
            ->assertSessionHasErrors('code')
            ->assertCookieMissing(RoleSwitcher::COOKIE);
    }

    public function test_correct_code_sets_cookie_and_lists_accounts_by_role(): void
    {
        $branch = Branch::create(['name' => 'Cơ sở Cầu Giấy', 'code' => 'CG', 'is_active' => true]);
        $this->makeUser(Roles::ACADEMIC_STAFF, 'Học vụ Hai', ['branch_id' => $branch->id]);
        $this->makeUser(Roles::ACADEMIC_STAFF, 'Học vụ Một');
        $this->makeUser(Roles::TEACHER_PARTTIME, 'GV Bị Khóa', ['is_active' => false]);

        $this->from('/login')->post(route('role-switcher.unlock'), ['code' => self::CODE])
            ->assertRedirect('/login')
            ->assertSessionHasNoErrors()
            ->assertCookie(RoleSwitcher::COOKIE, RoleSwitcher::token());

        $this->unlocked()->get('/login')
            ->assertOk()
            ->assertSee('Học vụ Một')
            ->assertDontSee('GV Bị Khóa')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('roleSwitcher.unlocked', true)
                ->where('roleSwitcher.current', null)
                ->where('roleSwitcher.groups.0.role', Roles::ACADEMIC_STAFF)
                ->where('roleSwitcher.groups.0.label', 'Học vụ')
                ->where('roleSwitcher.groups.0.accounts.0.name', 'Học vụ Hai')
                ->where('roleSwitcher.groups.0.accounts.0.branch', 'CG')
                ->where('roleSwitcher.groups.0.accounts.1.name', 'Học vụ Một')
                ->has('roleSwitcher.groups', 1));
    }

    public function test_cookie_from_an_old_code_no_longer_works(): void
    {
        $teacher = $this->makeUser(Roles::TEACHER_PARTTIME, 'GV Một');
        $oldToken = RoleSwitcher::token();
        config(['role_switcher.code' => 'ma-moi']);

        $this->withCookie(RoleSwitcher::COOKIE, $oldToken)->post(route('role-switcher.switch', $teacher))->assertForbidden();
        $this->assertGuest();
    }

    public function test_guest_switches_straight_into_a_role_home_page(): void
    {
        $teacher = $this->makeUser(Roles::TEACHER_FULLTIME, 'GV Cơ Hữu');

        $this->unlocked()->post(route('role-switcher.switch', $teacher))
            ->assertRedirect(route('teacher.home', absolute: false));
        $this->assertAuthenticatedAs($teacher);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'auth', 'causer_id' => $teacher->id, 'description' => 'Đổi vai trò thử nghiệm: khách → GV Cơ Hữu']);
    }

    public function test_switching_without_the_code_is_forbidden(): void
    {
        $teacher = $this->makeUser(Roles::TEACHER_PARTTIME, 'GV Một');

        $this->post(route('role-switcher.switch', $teacher))->assertForbidden();
        $this->assertGuest();
    }

    public function test_logged_in_user_switches_to_another_role_and_topbar_shows_current_role(): void
    {
        $staff = $this->makeUser(Roles::ACADEMIC_STAFF, 'Học vụ Một');
        $assistant = $this->makeUser(Roles::ASSISTANT, 'Trợ giảng Một');

        $this->actingAs($staff)->unlocked()->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Đổi vai trò')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('roleSwitcher.current.id', $staff->id)
                ->where('roleSwitcher.current.role', 'Học vụ'));

        $this->actingAs($staff)->unlocked()->post(route('role-switcher.switch', $assistant))
            ->assertRedirect(route('portal.ta-tasks', absolute: false));
        $this->assertAuthenticatedAs($assistant);
    }

    public function test_topbar_hides_switcher_without_the_code(): void
    {
        $staff = $this->makeUser(Roles::ACADEMIC_STAFF, 'Học vụ Một');

        $this->actingAs($staff)->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Đổi vai trò')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('roleSwitcher.unlocked', false));
    }

    public function test_cannot_switch_to_locked_or_roleless_accounts(): void
    {
        $locked = $this->makeUser(Roles::TEACHER_PARTTIME, 'GV Khóa', ['locked_at' => now()]);
        $inactive = $this->makeUser(Roles::TEACHER_PARTTIME, 'GV Nghỉ', ['is_active' => false]);
        $roleless = User::factory()->create(['name' => 'Không vai trò']);

        foreach ([$locked, $inactive, $roleless] as $target) {
            $this->unlocked()->post(route('role-switcher.switch', $target))->assertForbidden();
        }
        $this->assertGuest();
    }

    public function test_account_forced_to_change_password_can_still_switch_away(): void
    {
        $forced = $this->makeUser(Roles::ACADEMIC_STAFF, 'Học vụ Mới', ['must_change_password' => true]);
        $teacher = $this->makeUser(Roles::TEACHER_PARTTIME, 'GV Một');

        $this->actingAs($forced)->unlocked()->post(route('role-switcher.switch', $teacher))
            ->assertRedirect(route('teacher.home', absolute: false));
        $this->assertAuthenticatedAs($teacher);
    }
}
