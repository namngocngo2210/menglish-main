<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CrmCustomer;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrmKanbanStageTest extends TestCase
{
    use RefreshDatabase;

    protected User $salesUser;

    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create([
            'name' => 'Cơ sở Cầu Giấy',
            'code' => 'CG',
            'is_active' => true,
        ]);

        $this->salesUser = User::create([
            'name' => 'Chuyên viên Sales',
            'email' => 'sales.kanban@menglish.edu.vn',
            'password' => bcrypt('password'),
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);
        $this->salesUser->syncRoles(['sales_consultant']);
    }

    public function test_can_update_stage_forward(): void
    {
        $customer = CrmCustomer::create([
            'code' => 'KH-KB-001',
            'name' => 'Trần Thu Trang',
            'phone' => '0988111222',
            'stage' => 'new',
            'branch_id' => $this->branch->id,
            'assigned_user_id' => $this->salesUser->id,
        ]);

        // Move forward from 'new' (0) to 'consulting' (1)
        $response = $this->actingAs($this->salesUser)->json('POST', route('crm.customers.stage', $customer->id), [
            'stage' => 'consulting',
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['success' => true]);

        $customer->refresh();
        $this->assertEquals('consulting', $customer->stage);

        // Check history log
        $this->assertDatabaseHas('crm_customer_histories', [
            'customer_id' => $customer->id,
            'type' => 'stage_change',
        ]);
    }

    public function test_cannot_update_stage_backward(): void
    {
        $customer = CrmCustomer::create([
            'code' => 'KH-KB-002',
            'name' => 'Lê Quốc Bảo',
            'phone' => '0977333444',
            'stage' => 'test_scheduled',
            'branch_id' => $this->branch->id,
            'assigned_user_id' => $this->salesUser->id,
        ]);

        // Attempt to move backward from 'test_scheduled' (2) to 'new' (0) -> should be rejected!
        $response = $this->actingAs($this->salesUser)->json('POST', route('crm.customers.stage', $customer->id), [
            'stage' => 'new',
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['success' => false]);

        // Verify stage was NOT changed
        $customer->refresh();
        $this->assertEquals('test_scheduled', $customer->stage);
    }

    public function test_next_stage_button_moves_to_immediate_next_stage(): void
    {
        $customer = CrmCustomer::create([
            'code' => 'KH-KB-003',
            'name' => 'Phạm Minh Đức',
            'phone' => '0966555666',
            'stage' => 'new',
            'branch_id' => $this->branch->id,
            'assigned_user_id' => $this->salesUser->id,
        ]);

        // 1. From 'new' -> 'consulting'
        $res1 = $this->actingAs($this->salesUser)->json('POST', route('crm.customers.next-stage', $customer->id));
        $res1->assertStatus(200);
        $customer->refresh();
        $this->assertEquals('consulting', $customer->stage);

        // 2. Consulting requires a concrete branch action (test/trial/waiting)
        $res2 = $this->actingAs($this->salesUser)->json('POST', route('crm.customers.next-stage', $customer->id));
        $res2->assertStatus(422);
        $customer->refresh();
        $this->assertEquals('consulting', $customer->stage);

        // 3. Set to 'won' and test next-stage boundary
        $customer->update(['stage' => 'won']);
        $resFinal = $this->actingAs($this->salesUser)->json('POST', route('crm.customers.next-stage', $customer->id));
        $resFinal->assertStatus(422);
        $this->assertEquals('won', $customer->stage);
    }
}
