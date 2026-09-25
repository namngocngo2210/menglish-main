<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CrmCustomer;
use App\Models\Student;
use App\Models\User;
use App\Models\WorkTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaginationRecordOptionTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $this->seed(\Database\Seeders\RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Chi nhánh Cầu Giấy', 'code' => 'CG', 'is_active' => true]);

        $this->admin = User::create([
            'name' => 'Admin MEnglish',
            'email' => 'admin.pagination@menglish.edu.vn',
            'password' => bcrypt('password'),
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);
        $this->admin->syncRoles(['admin']);
    }

    public function test_crm_customers_pagination_options_10_20_50_100_all(): void
    {
        // Create 25 customers
        for ($i = 1; $i <= 25; $i++) {
            CrmCustomer::create([
                'code' => 'KH-' . str_pad($i, 5, '0', STR_PAD_LEFT),
                'name' => 'Khách hàng Test ' . $i,
                'phone' => '0988000' . str_pad($i, 3, '0', STR_PAD_LEFT),
                'stage' => 'new',
                'branch_id' => $this->branch->id,
            ]);
        }

        // 1. Default per page (15) -> Page 1 contains 15, Page 2 contains 10
        $responseDefault = $this->actingAs($this->admin)->get(route('crm.customers.index'));
        $responseDefault->assertStatus(200);
        $responseDefault->assertSee('Hiển thị:');
        $responseDefault->assertSee('10');
        $responseDefault->assertSee('20');
        $responseDefault->assertSee('50');
        $responseDefault->assertSee('100');
        $responseDefault->assertSee('Tất cả');

        // 2. per_page = 10 -> exactly 10 records on page 1
        $response10 = $this->actingAs($this->admin)->get(route('crm.customers.index', ['per_page' => 10]));
        $response10->assertStatus(200);
        $this->assertCount(10, $response10->viewData('customers'));

        // 3. per_page = 20 -> exactly 20 records on page 1
        $response20 = $this->actingAs($this->admin)->get(route('crm.customers.index', ['per_page' => 20]));
        $response20->assertStatus(200);
        $this->assertCount(20, $response20->viewData('customers'));

        // 4. per_page = all -> all 25 records on page 1
        $responseAll = $this->actingAs($this->admin)->get(route('crm.customers.index', ['per_page' => 'all']));
        $responseAll->assertStatus(200);
        $this->assertCount(25, $responseAll->viewData('customers'));
    }

    public function test_tasks_pagination_options_support(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            WorkTask::create([
                'title' => 'Nhiệm vụ Test ' . $i,
                'task_type' => 'teaching_assistant',
                'creator_id' => $this->admin->id,
                'assignee_id' => $this->admin->id,
                'status' => 'new',
                'due_date' => now()->addDays(2),
            ]);
        }

        // Test per_page = 10
        $response10 = $this->actingAs($this->admin)->get(route('tasks.index', ['per_page' => 10]));
        $response10->assertStatus(200);
        $this->assertCount(10, $response10->viewData('tasks'));

        // Test per_page = all -> returns all 30
        $responseAll = $this->actingAs($this->admin)->get(route('tasks.index', ['per_page' => 'all']));
        $responseAll->assertStatus(200);
        $this->assertCount(30, $responseAll->viewData('tasks'));
    }
}
