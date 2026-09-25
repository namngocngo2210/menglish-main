<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CrmCustomer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrmSalesDataScopeTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $saleAlice;
    protected User $saleBob;
    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $this->seed(\Database\Seeders\RoleSeeder::class);

        $this->branch = Branch::create([
            'name' => 'Cơ sở Hoàn Kiếm',
            'code' => 'HK',
            'is_active' => true,
        ]);

        $this->adminUser = User::create([
            'name' => 'Admin Boss',
            'email' => 'admin.scope@menglish.edu.vn',
            'password' => bcrypt('password'),
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);
        $this->adminUser->syncRoles(['admin']);

        $this->saleAlice = User::create([
            'name' => 'Sale Alice',
            'email' => 'alice@menglish.edu.vn',
            'password' => bcrypt('password'),
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);
        $this->saleAlice->syncRoles(['sales_consultant']);

        $this->saleBob = User::create([
            'name' => 'Sale Bob',
            'email' => 'bob@menglish.edu.vn',
            'password' => bcrypt('password'),
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);
        $this->saleBob->syncRoles(['sales_consultant']);
    }

    public function test_sale_only_sees_their_assigned_leads_in_pipeline(): void
    {
        $leadAlice = CrmCustomer::create([
            'code' => 'KH-ALICE-1',
            'name' => 'Khách của Alice',
            'phone' => '0911111111',
            'stage' => 'new',
            'deal_value' => 10000000,
            'branch_id' => $this->branch->id,
            'assigned_user_id' => $this->saleAlice->id,
        ]);

        $leadBob = CrmCustomer::create([
            'code' => 'KH-BOB-1',
            'name' => 'Khách của Bob',
            'phone' => '0922222222',
            'stage' => 'new',
            'deal_value' => 20000000,
            'branch_id' => $this->branch->id,
            'assigned_user_id' => $this->saleBob->id,
        ]);

        // 1. Sale Alice views pipeline: should see Lead Alice and NOT Lead Bob
        $resAlice = $this->actingAs($this->saleAlice)->get(route('crm.pipeline'));
        $resAlice->assertStatus(200);
        $resAlice->assertSee('Khách của Alice');
        $resAlice->assertSee('Phụ trách:');
        $resAlice->assertSee('Sale Alice');
        $resAlice->assertDontSee('Khách của Bob');

        // 2. Sale Bob views pipeline: should see Lead Bob and NOT Lead Alice
        $resBob = $this->actingAs($this->saleBob)->get(route('crm.pipeline'));
        $resBob->assertStatus(200);
        $resBob->assertSee('Khách của Bob');
        $resBob->assertSee('Phụ trách:');
        $resBob->assertSee('Sale Bob');
        $resBob->assertDontSee('Khách của Alice');

        // 3. Admin views pipeline: should see BOTH
        $resAdmin = $this->actingAs($this->adminUser)->get(route('crm.pipeline'));
        $resAdmin->assertStatus(200);
        $resAdmin->assertSee('Khách của Alice');
        $resAdmin->assertSee('Khách của Bob');
    }

    public function test_sale_only_sees_their_assigned_leads_in_customers_list(): void
    {
        $leadAlice = CrmCustomer::create([
            'code' => 'KH-ALICE-2',
            'name' => 'Nguyễn Thị Hương (Alice)',
            'phone' => '0933333333',
            'stage' => 'consulting',
            'branch_id' => $this->branch->id,
            'assigned_user_id' => $this->saleAlice->id,
        ]);

        $leadBob = CrmCustomer::create([
            'code' => 'KH-BOB-2',
            'name' => 'Phạm Văn Nam (Bob)',
            'phone' => '0944444444',
            'stage' => 'consulting',
            'branch_id' => $this->branch->id,
            'assigned_user_id' => $this->saleBob->id,
        ]);

        // Alice visits /crm/customers
        $resAlice = $this->actingAs($this->saleAlice)->get(route('crm.customers.index'));
        $resAlice->assertStatus(200);
        $resAlice->assertSee('Nguyễn Thị Hương (Alice)');
        $resAlice->assertDontSee('Phạm Văn Nam (Bob)');

        // Bob visits /crm/customers
        $resBob = $this->actingAs($this->saleBob)->get(route('crm.customers.index'));
        $resBob->assertStatus(200);
        $resBob->assertSee('Phạm Văn Nam (Bob)');
        $resBob->assertDontSee('Nguyễn Thị Hương (Alice)');
    }

    public function test_sale_cannot_view_or_access_lead_assigned_to_another_sale(): void
    {
        $leadBob = CrmCustomer::create([
            'code' => 'KH-BOB-SECRET',
            'name' => 'Khách VIP của Bob',
            'phone' => '0955555555',
            'stage' => 'result_sent',
            'branch_id' => $this->branch->id,
            'assigned_user_id' => $this->saleBob->id,
        ]);

        // Alice tries to directly view Bob's customer details -> should return 404
        $resAlice = $this->actingAs($this->saleAlice)->get(route('crm.customers.show', $leadBob->id));
        $resAlice->assertStatus(404);

        // Bob can view his own customer
        $resBob = $this->actingAs($this->saleBob)->get(route('crm.customers.show', $leadBob->id));
        $resBob->assertStatus(200);
        $resBob->assertSee('Khách VIP của Bob');
    }
}
