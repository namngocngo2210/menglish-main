<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_teachers_and_assistants_land_on_their_daily_screen_after_login(): void
    {
        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $this->seed(\Database\Seeders\RoleSeeder::class);

        foreach (['teacher_fulltime' => route('teacher.home', absolute: false), 'assistant' => route('portal.ta-tasks', absolute: false), 'manager' => route('dashboard', absolute: false)] as $role => $target) {
            $user = User::factory()->create();
            $user->assignRole($role);
            $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect($target);
            $this->post('/logout');
        }
    }

    public function test_login_screen_is_in_vietnamese(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Mật khẩu')
            ->assertSee('Ghi nhớ đăng nhập')
            ->assertSee('Quên mật khẩu?')
            ->assertSee('Hiện mật khẩu')
            ->assertDontSee('Remember me')
            ->assertDontSee('Forgot your password?');
    }

    public function test_users_can_authenticate_with_their_account_code_case_insensitively(): void
    {
        $user = User::factory()->create(['employee_code' => 'HV-00103']);

        $this->post('/login', ['email' => 'hv-00103', 'password' => 'password']);

        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_credentials_show_a_vietnamese_message(): void
    {
        User::factory()->create(['employee_code' => 'HV-00104']);

        $this->post('/login', ['email' => 'HV-99999', 'password' => 'password'])
            ->assertSessionHasErrors(['email' => 'Email hoặc mật khẩu không đúng.']);

        $this->assertGuest();
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }
}
