<?php

namespace Tests\Feature;

use App\Models\Penalty;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PenaltyModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_and_confirm_penalty(): void
    {
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('manager');

        $response = $this->actingAs($user)->post('/penalties', [
            'user_id' => $user->id,
            'violation_type' => 'Đến muộn > 15 phút',
            'violation_date' => '2026-08-17',
            'amount' => 200000,
            'notes' => 'Vi phạm lần 1',
        ]);

        $response->assertRedirect(route('penalties.index'));
        $this->assertDatabaseHas('penalties', [
            'user_id' => $user->id,
            'amount' => 200000,
            'status' => 'pending',
        ]);

        $penalty = Penalty::where('user_id', $user->id)->first();
        $this->actingAs($user)->post("/penalties/{$penalty->id}/confirm");
        $penalty->refresh();
        $this->assertEquals('confirmed', $penalty->status);
    }
}
