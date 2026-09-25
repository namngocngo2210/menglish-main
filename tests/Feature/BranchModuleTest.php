<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
    }

    public function test_can_view_branches_list(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        Branch::create([
            'code' => 'TEST-HN',
            'name' => 'Chi nhánh Thử Nghiệm Hà Nội',
            'address' => '123 Đường Cầu Giấy, Hà Nội',
            'phone' => '024 1234 5678',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->get(route('branches.index'));
        $response->assertOk();
        $response->assertSee('Quản Lý Cơ Sở &amp; Chi Nhánh Trung Tâm', false);
        $response->assertSee('TEST-HN');
        $response->assertSee('Chi nhánh Thử Nghiệm Hà Nội');
    }

    public function test_can_create_new_branch(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        $response = $this->actingAs($user)->post(route('branches.store'), [
            'code' => 'TX',
            'name' => 'Chi nhánh Thanh Xuân',
            'address' => '200 Nguyễn Trãi, Thanh Xuân, Hà Nội',
            'phone' => '024 9999 8888',
            'is_active' => 1,
        ]);

        $response->assertRedirect(route('branches.index'));
        $this->assertDatabaseHas('branches', [
            'code' => 'TX',
            'name' => 'Chi nhánh Thanh Xuân',
            'is_active' => true,
        ]);
    }

    public function test_can_update_branch(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        $branch = Branch::create([
            'code' => 'HK',
            'name' => 'Chi nhánh Hoàn Kiếm',
            'address' => 'Phố Huế, Hoàn Kiếm',
            'phone' => '024 1111 2222',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->put(route('branches.update', $branch->id), [
            'code' => 'HK',
            'name' => 'Chi nhánh Hoàn Kiếm Updated',
            'address' => 'Tràng Tiền, Hoàn Kiếm',
            'phone' => '024 3333 4444',
            'is_active' => 0,
        ]);

        $response->assertRedirect(route('branches.index'));
        $branch->refresh();
        $this->assertEquals('Chi nhánh Hoàn Kiếm Updated', $branch->name);
        $this->assertFalse($branch->is_active);
    }

    public function test_can_toggle_branch_status(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        $branch = Branch::create([
            'code' => 'BT',
            'name' => 'Chi nhánh Bình Thạnh',
            'address' => 'Bình Thạnh, TP.HCM',
            'phone' => '028 1111 2222',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->post(route('branches.toggle', $branch->id));
        $response->assertRedirect(route('branches.index'));
        $branch->refresh();
        $this->assertFalse($branch->is_active);
    }

    public function test_can_delete_unused_branch(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        $branch = Branch::create([
            'code' => 'DEL',
            'name' => 'Chi nhánh Cần Xóa',
            'address' => 'Địa chỉ tạm',
            'phone' => '024 0000 0000',
            'is_active' => false,
        ]);

        $response = $this->actingAs($user)->delete(route('branches.destroy', $branch->id));
        $response->assertRedirect(route('branches.index'));
        $this->assertSoftDeleted('branches', ['id' => $branch->id]);
    }
}
