<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeployHookTest extends TestCase
{
    use RefreshDatabase;

    public function test_hook_is_hidden_without_configured_token(): void
    {
        config(['app.deploy_hook_token' => null]);

        $this->post('/_deploy/hook', [], ['X-Deploy-Token' => 'anything'])->assertNotFound();
    }

    public function test_hook_rejects_wrong_or_short_token(): void
    {
        config(['app.deploy_hook_token' => str_repeat('a', 64)]);
        $this->post('/_deploy/hook', [], ['X-Deploy-Token' => str_repeat('b', 64)])->assertNotFound();

        config(['app.deploy_hook_token' => 'short']);
        $this->post('/_deploy/hook', [], ['X-Deploy-Token' => 'short'])->assertNotFound();
    }

    public function test_hook_runs_migrations_with_valid_token(): void
    {
        $token = str_repeat('c', 64);
        config(['app.deploy_hook_token' => $token]);

        $response = $this->post('/_deploy/hook', [], ['X-Deploy-Token' => $token]);

        $response->assertJsonPath('steps.0.command', 'optimize:clear');
        $this->assertContains('migrate', collect($response->json('steps'))->pluck('command')->all());
    }

    public function test_seed_runs_only_when_requested_and_never_in_production(): void
    {
        $token = str_repeat('d', 64);
        config(['app.deploy_hook_token' => $token]);

        $commands = fn ($response) => collect($response->json('steps'))->pluck('command')->all();

        $this->assertNotContains('db:seed', $commands($this->post('/_deploy/hook', [], ['X-Deploy-Token' => $token])));

        $this->app['env'] = 'production';
        $blocked = $this->post('/_deploy/hook', ['seed' => 'demo'], ['X-Deploy-Token' => $token]);
        $step = collect($blocked->json('steps'))->firstWhere('command', 'db:seed');
        $this->assertNotNull($step);
        $this->assertSame(1, $step['exit']);
        $this->assertStringContainsString('production', $step['output']);
    }

    public function test_bootstrap_seed_creates_single_admin_only_on_empty_database(): void
    {
        $token = str_repeat('e', 64);
        config(['app.deploy_hook_token' => $token]);
        putenv('INITIAL_ADMIN_EMAIL=owner@meducation.vn');
        putenv('INITIAL_ADMIN_PASSWORD=Very-Strong-Pass-123');
        $this->app['env'] = 'production';

        $this->post('/_deploy/hook', ['seed' => 'bootstrap'], ['X-Deploy-Token' => $token])->assertOk();

        $this->assertSame(1, \App\Models\User::count());
        $admin = \App\Models\User::first();
        $this->assertSame('owner@meducation.vn', $admin->email);
        $this->assertTrue($admin->hasRole('admin'));
        $this->assertTrue((bool) $admin->must_change_password);
        $this->assertSame(0, \App\Models\Branch::count());

        // RBAC khởi tạo đầy đủ theo danh mục (docs/rbac.md): Super Admin toàn quyền, vai trò mặc định có phạm vi dữ liệu,
        // quyền cũ *.all_branches đã thay bằng *.scope_all.
        $this->assertTrue($admin->isSuperAdmin());
        $this->assertTrue($admin->can('tuition.scope_all'));
        $this->assertTrue($admin->can('role.assign_permission'));
        $this->assertSame('all', \App\Support\DataScope::level($admin, 'lead'));
        $this->assertFalse(\Spatie\Permission\Models\Permission::where('name', 'tuition.all_branches')->exists());
        foreach (array_keys(config('access.roles')) as $role) {
            $this->assertTrue(\Spatie\Permission\Models\Role::where('name', $role)->exists(), "Thiếu vai trò {$role}");
        }
        $academicStaff = \Spatie\Permission\Models\Role::findByName('academic_staff', 'web');
        $this->assertTrue($academicStaff->hasPermissionTo('lead.update'));
        $this->assertFalse($academicStaff->hasPermissionTo('lead.delete'));
        $this->assertFalse($academicStaff->hasPermissionTo('lead.stage_back'));

        // Chạy lại: không khởi tạo lại khi đã có người dùng.
        $again = $this->post('/_deploy/hook', ['seed' => 'bootstrap'], ['X-Deploy-Token' => $token]);
        $this->assertStringContainsString('đã có người dùng', collect($again->json('steps'))->firstWhere('command', 'db:seed')['output']);
        $this->assertSame(1, \App\Models\User::count());

        putenv('INITIAL_ADMIN_EMAIL');
        putenv('INITIAL_ADMIN_PASSWORD');
    }
}
