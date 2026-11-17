<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CrmCustomer;
use App\Models\SystemCategory;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** TK-2026-0130: nguồn khách "Ngừng sử dụng" không được chọn cho khách mới; khách cũ giữ nguyên nhãn. */
class CrmInactiveLeadSourceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Cơ sở A', 'code' => 'CSA', 'is_active' => true]);
        $this->admin = User::factory()->create(['branch_id' => null, 'is_active' => true]);
        $this->admin->assignRole('admin');

        SystemCategory::create(['type' => 'lead_source', 'code' => 'SRC_01', 'name' => 'Facebook Ads', 'sort_order' => 1, 'is_active' => true]);
        SystemCategory::create(['type' => 'lead_source', 'code' => 'SRC_02', 'name' => 'Giới thiệu', 'sort_order' => 2, 'is_active' => false]);
    }

    private function old(): CrmCustomer
    {
        return CrmCustomer::create([
            'code' => 'KH-OLD', 'name' => 'Khách cũ', 'phone' => '0912000001',
            'branch_id' => $this->branch->id, 'source' => 'Giới thiệu', 'stage' => 'new',
        ]);
    }

    private function payload(string $source): array
    {
        return ['name' => 'Khách mới', 'phone' => '0912 555 111', 'source' => $source, 'branch_id' => $this->branch->id];
    }

    public function test_create_form_lists_only_active_sources(): void
    {
        $sources = $this->actingAs($this->admin)->get(route('crm.customers.create'))->assertOk()->inertiaProps('leadSources');

        $this->assertSame(['Facebook Ads'], $sources);
    }

    public function test_old_customer_keeps_inactive_source_label_and_edit_option(): void
    {
        $lead = $this->old();

        $props = $this->actingAs($this->admin)->get(route('crm.customers.show', $lead->id))->assertOk()->assertSee('Giới thiệu');

        $options = $props->inertiaProps('editForm')['leadSources'];
        $this->assertContains('Giới thiệu', array_column($options, 'value'));
        $this->assertContains('Facebook Ads', array_column($options, 'value'));
    }

    public function test_kanban_filter_hides_inactive_source(): void
    {
        $this->old();
        CrmCustomer::create([
            'code' => 'KH-NEW', 'name' => 'Khách FB', 'phone' => '0912000002',
            'branch_id' => $this->branch->id, 'source' => 'Facebook Ads', 'stage' => 'new',
        ]);

        $filters = $this->actingAs($this->admin)->get(route('crm.pipeline'))->assertOk()->inertiaProps('filterSources');

        $this->assertSame(['Facebook Ads'], array_values($filters));
    }

    public function test_create_with_inactive_source_is_rejected(): void
    {
        $this->actingAs($this->admin)->post(route('crm.customers.store'), $this->payload('Giới thiệu'))
            ->assertSessionHasErrors('source');
        $this->assertDatabaseMissing('crm_customers', ['name' => 'Khách mới']);
    }

    public function test_create_with_active_source_works(): void
    {
        $this->actingAs($this->admin)->post(route('crm.customers.store'), $this->payload('Facebook Ads'))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('crm_customers', ['name' => 'Khách mới', 'source' => 'Facebook Ads']);
    }
}
