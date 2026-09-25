<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\MerchandiseItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MerchandiseManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'system_category.manage', 'guard_name' => 'web']);
        $role->givePermissionTo('system_category.manage');

        $this->adminUser = User::factory()->create([
            'is_active' => true,
        ]);
        $this->adminUser->assignRole($role);
    }

    public function test_admin_can_view_merchandise_index_and_metrics(): void
    {
        MerchandiseItem::create([
            'code' => 'BOOK-TEST-01',
            'name' => 'Sách Test Tiếng Anh',
            'category' => 'book',
            'unit' => 'Cuốn',
            'price' => 200000,
            'stock_quantity' => 50,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->adminUser)->get(route('merchandise.index'));

        $response->assertOk();
        $response->assertSee('Danh mục Hàng hóa &amp; Vật phẩm', false);
        $response->assertSee('BOOK-TEST-01');
        $response->assertSee('Sách Test Tiếng Anh');
        $response->assertSee('200.000 đ');
    }

    public function test_admin_can_create_new_merchandise_item(): void
    {
        $response = $this->actingAs($this->adminUser)->post(route('merchandise.store'), [
            'code' => 'UNI-TEST-XL',
            'name' => 'Đồng phục Áo Polo Size XL',
            'category' => 'uniform',
            'unit' => 'Chiếc',
            'price' => 220000,
            'cost_price' => 140000,
            'stock_quantity' => 30,
            'is_active' => '1',
            'description' => 'Áo polo đồng phục form lớn',
        ]);

        $response->assertRedirect(route('merchandise.index'));

        $this->assertDatabaseHas('merchandise_items', [
            'code' => 'UNI-TEST-XL',
            'name' => 'Đồng phục Áo Polo Size XL',
            'price' => 220000,
            'stock_quantity' => 30,
            'is_active' => 1,
        ]);
    }

    public function test_merchandise_creation_fails_on_duplicate_code(): void
    {
        MerchandiseItem::create([
            'code' => 'DUP-CODE-01',
            'name' => 'Mặt hàng gốc',
            'category' => 'book',
            'unit' => 'Bộ',
            'price' => 100000,
            'stock_quantity' => 10,
        ]);

        $response = $this->actingAs($this->adminUser)->post(route('merchandise.store'), [
            'code' => 'DUP-CODE-01',
            'name' => 'Mặt hàng trùng mã',
            'category' => 'book',
            'unit' => 'Bộ',
            'price' => 120000,
            'stock_quantity' => 5,
        ]);

        $response->assertSessionHasErrors(['code']);
    }

    public function test_admin_can_update_merchandise_item(): void
    {
        $item = MerchandiseItem::create([
            'code' => 'UPDATE-ITEM',
            'name' => 'Tên cũ',
            'category' => 'book',
            'unit' => 'Bộ',
            'price' => 150000,
            'stock_quantity' => 10,
        ]);

        $response = $this->actingAs($this->adminUser)->put(route('merchandise.update', $item), [
            'code' => 'UPDATE-ITEM',
            'name' => 'Tên mới cập nhật',
            'category' => 'workbook',
            'unit' => 'Cuốn',
            'price' => 180000,
            'stock_quantity' => 25,
            'is_active' => '1',
        ]);

        $response->assertRedirect(route('merchandise.index'));
        $item->refresh();
        $this->assertEquals('Tên mới cập nhật', $item->name);
        $this->assertEquals(180000, (float) $item->price);
        $this->assertEquals('workbook', $item->category);
    }

    public function test_admin_can_toggle_and_delete_merchandise_item(): void
    {
        $item = MerchandiseItem::create([
            'code' => 'TOGGLE-DEL-ITEM',
            'name' => 'Mặt hàng toggle',
            'category' => 'gift',
            'unit' => 'Cái',
            'price' => 50000,
            'stock_quantity' => 100,
            'is_active' => true,
        ]);

        // 1. Toggle status
        $responseToggle = $this->actingAs($this->adminUser)->post(route('merchandise.toggle', $item));
        $responseToggle->assertRedirect();
        $item->refresh();
        $this->assertFalse($item->is_active);

        // 2. Delete item (Soft delete)
        $responseDel = $this->actingAs($this->adminUser)->delete(route('merchandise.destroy', $item));
        $responseDel->assertRedirect(route('merchandise.index'));
        $this->assertSoftDeleted('merchandise_items', ['id' => $item->id]);
    }
}
