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
        $blocked = $this->post('/_deploy/hook', ['seed' => 1], ['X-Deploy-Token' => $token]);
        $step = collect($blocked->json('steps'))->firstWhere('command', 'db:seed');
        $this->assertNotNull($step);
        $this->assertSame(1, $step['exit']);
        $this->assertStringContainsString('production', $step['output']);
    }
}
