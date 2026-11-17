<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class AuditSkipsForbiddenRequestsTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $role): User
    {
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_forbidden_permission_update_writes_no_activity_log(): void
    {
        $actor = $this->userWithRole('teacher');
        $target = User::factory()->create();
        Activity::query()->delete();

        $this->actingAs($actor)->put("/users/{$target->id}/permissions", ['overrides' => []])->assertForbidden();

        $this->assertSame(0, Activity::query()->count());
    }

    public function test_forbidden_request_on_other_route_writes_no_activity_log(): void
    {
        $actor = $this->userWithRole('teacher');
        $target = User::factory()->create();
        Activity::query()->delete();

        $this->actingAs($actor)->delete("/users/{$target->id}")->assertForbidden();

        $this->assertSame(0, Activity::query()->count());
    }

    public function test_successful_permission_update_is_still_logged(): void
    {
        $admin = $this->userWithRole('admin');
        $target = User::factory()->create();
        Activity::query()->delete();

        $this->actingAs($admin)->put("/users/{$target->id}/permissions", ['overrides' => []])->assertStatus(302);

        $this->assertGreaterThan(0, Activity::query()->where('causer_id', $admin->id)->count());
    }
}
