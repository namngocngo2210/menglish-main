<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use App\Support\Roles;
use App\Support\RoleSwitcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    /** Cookie phiên (đã mã hoá) trong response, để gửi lại ở request sau như trình duyệt thật. */
    private function sessionCookie($response): \Symfony\Component\HttpFoundation\Cookie
    {
        return $response->getCookie(config('session.cookie'), false);
    }

    /** Request kế tiếp với cookie phiên cũ sau khi "đồng hồ" chạy; dựng lại driver phiên như một request mới. */
    private function requestLater(\Symfony\Component\HttpFoundation\Cookie $session, int $minutes, bool $unlocked)
    {
        $this->travel($minutes)->minutes();
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        \Illuminate\Support\Facades\Auth::forgetGuards();
        $client = $this->withUnencryptedCookie($session->getName(), $session->getValue());

        return ($unlocked ? $client->unlocked() : $client)->get(route('login'));
    }

    public function test_unlock_cookie_lasts_a_year(): void
    {
        $cookie = $this->post(route('role-switcher.unlock'), ['code' => self::CODE])->getCookie(RoleSwitcher::COOKIE, false);

        $this->assertEqualsWithDelta(now()->addYear()->getTimestamp(), $cookie->getExpiresTime(), 120);
    }

    public function test_unlock_cookie_is_refreshed_on_every_request_when_unlocked(): void
    {
        $this->unlocked()->get(route('login'))->assertCookie(RoleSwitcher::COOKIE);
    }

    public function test_switched_session_survives_beyond_the_normal_lifetime(): void
    {
        config(['session.driver' => 'database', 'session.lifetime' => 120]);
        $teacher = $this->makeUser(Roles::TEACHER_PARTTIME, 'GV Một');

        $response = $this->unlocked()->post(route('role-switcher.switch', $teacher));
        $session = $this->sessionCookie($response);
        $this->assertEqualsWithDelta(now()->addMinutes(RoleSwitcher::SESSION_MINUTES)->getTimestamp(), $session->getExpiresTime(), 120);

        config(['session.lifetime' => 120]);
        $this->requestLater($session, 60 * 5, unlocked: true);
        $this->assertAuthenticatedAs($teacher);
    }

    public function test_normal_login_still_expires_at_session_lifetime(): void
    {
        config(['session.driver' => 'database', 'session.lifetime' => 120]);
        $user = User::factory()->create();
        $user->assignRole(Roles::TEACHER_PARTTIME);

        $response = $this->post(route('login'), ['email' => $user->email, 'password' => 'password']);
        $session = $this->sessionCookie($response);
        $this->assertEqualsWithDelta(now()->addMinutes(120)->getTimestamp(), $session->getExpiresTime(), 120);

        $this->requestLater($session, 60 * 5, unlocked: false);
        $this->assertGuest();
    }

    public function test_gc_from_any_request_keeps_switched_session_idle_for_hours(): void
    {
        config(['session.driver' => 'database', 'session.lifetime' => 120, 'session.lottery' => [100, 100]]);
        $teacher = $this->makeUser(Roles::TEACHER_PARTTIME, 'GV Một');
        $session = $this->sessionCookie($this->unlocked()->post(route('role-switcher.switch', $teacher)));
        DB::table('sessions')->where('user_id', $teacher->id)->update(['last_activity' => now()->subHours(5)->getTimestamp()]);
        $rowId = DB::table('sessions')->where('user_id', $teacher->id)->value('id');

        // A locked browser's request runs GC with the 120-minute lifetime.
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        \Illuminate\Support\Facades\Auth::forgetGuards();
        $this->defaultCookies = []; // locked browser: no unlock cookie
        config(['session.lifetime' => 120]); // the unlocked request raised it in-process; a real locked request starts at 120
        $this->get(route('login'));
        $this->assertDatabaseHas('sessions', ['id' => $rowId]);

        $this->requestLater($session, 0, unlocked: true);
        $this->assertAuthenticatedAs($teacher);
    }

    public function test_gc_on_production_still_deletes_sessions_older_than_the_lifetime(): void
    {
        app()->detectEnvironment(fn () => 'production');
        config(['session.driver' => 'database', 'session.lifetime' => 120, 'session.lottery' => [100, 100]]);
        DB::table('sessions')->insert(['id' => 'old', 'payload' => '', 'last_activity' => now()->subHours(5)->getTimestamp()]);

        $this->get(route('login'))->assertOk();

        $this->assertDatabaseMissing('sessions', ['id' => 'old']);
    }

    public function test_production_never_gets_long_session_or_cookie(): void
    {
        app()->detectEnvironment(fn () => 'production');
        config(['session.lifetime' => 120]);

        $response = $this->unlocked()->get(route('login'));

        $response->assertCookieMissing(RoleSwitcher::COOKIE);
        $this->assertSame(120, config('session.lifetime'));
        $this->assertEqualsWithDelta(now()->addMinutes(120)->getTimestamp(), $this->sessionCookie($response)->getExpiresTime(), 120);
    }

    public function test_expired_csrf_on_unlock_and_switch_redirects_back_instead_of_error_page(): void
    {
        $teacher = $this->makeUser(Roles::TEACHER_PARTTIME, 'GV Một');
        app()->detectEnvironment(fn () => 'local'); // CSRF check is skipped only when the env is "testing"
        $this->withHeader('Referer', route('login'));

        $this->post(route('role-switcher.unlock'), ['code' => self::CODE])
            ->assertRedirect(route('login'))->assertSessionHasErrors('code');
        $this->unlocked()->post(route('role-switcher.switch', $teacher))
            ->assertRedirect(route('login'))->assertSessionHasErrors('code');
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
