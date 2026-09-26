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
}
