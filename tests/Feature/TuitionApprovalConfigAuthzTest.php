<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** TK-2026-0081/0082: màn duyệt phiếu thu và cấu hình dải số hóa đơn không mở cho Học vụ / CM. */
class TuitionApprovalConfigAuthzTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->branch = Branch::create(['name' => 'CN Authz', 'code' => 'AZ', 'is_active' => true]);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    public function test_receipt_approval_screen_and_apis_are_admin_and_accountant_only(): void
    {
        // Mở màn duyệt: Admin + Quản lý cơ sở + Kế toán. Duyệt / từ chối (POST): Admin + Kế toán (TK-2026-0113), vai trò khác 403.
        foreach (['admin', 'accountant', 'manager'] as $role) {
            $this->actingAs($this->user($role))->get(route('tuition.receipts.approve'))->assertOk();
        }
        foreach (['academic_staff', 'sales_consultant'] as $role) {
            $this->actingAs($this->user($role))->get(route('tuition.receipts.approve'))->assertForbidden();
        }
        foreach (['academic_staff', 'manager', 'sales_consultant', 'finance_staff'] as $role) {
            $u = $this->user($role);
            $this->actingAs($u)->post(route('tuition.receipts.approve.action', 999999))->assertForbidden();
            $this->actingAs($u)->post(route('tuition.receipts.reject.action', 999999), ['rejection_reason' => 'x'])->assertForbidden();
        }
    }

    public function test_invoice_range_config_is_limited_to_range_managers(): void
    {
        foreach (['admin', 'finance_staff'] as $role) {
            $this->actingAs($this->user($role))->get(route('tuition.config'))->assertOk();
        }

        $payload = ['template_code' => '1/001', 'series_code' => 'C26PQ', 'current_number' => 5];
        foreach (['academic_staff', 'manager', 'sales_consultant'] as $role) {
            $u = $this->user($role);
            $this->actingAs($u)->get(route('tuition.config'))->assertForbidden();
            $this->actingAs($u)->post(route('tuition.config.update'), $payload)->assertForbidden();
            $this->actingAs($u)->post(route('tuition.config.ranges.store'), $payload)->assertForbidden();
            $this->actingAs($u)->post(route('tuition.config.ranges.toggle', 999999))->assertForbidden();
        }
    }

    public function test_receipt_create_page_with_suggested_number_still_works_for_academic(): void
    {
        // Sales không có tuition.view nên không vào được nhóm route Học phí (không đổi trong TK này).
        foreach (['academic_staff', 'manager'] as $role) {
            $this->actingAs($this->user($role))->get(route('tuition.receipts.create'))->assertOk();
        }
    }
}
