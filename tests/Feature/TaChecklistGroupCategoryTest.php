<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\SystemCategory;
use App\Models\User;
use App\Models\WorkTask;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaChecklistGroupCategoryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $ta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $branch = Branch::create(['name' => 'CN Cầu Giấy', 'code' => 'CG', 'is_active' => true]);
        $this->admin = User::create(['name' => 'Admin', 'email' => 'a@x.vn', 'password' => bcrypt('p'), 'branch_id' => $branch->id, 'is_active' => true]);
        $this->admin->syncRoles(['admin']);
        $this->ta = User::create(['name' => 'TA', 'email' => 't@x.vn', 'password' => bcrypt('p'), 'branch_id' => $branch->id, 'is_active' => true]);
        $this->ta->syncRoles(['assistant']);
    }

    private function group(string $code, string $name, bool $active = true): SystemCategory
    {
        return SystemCategory::create(['type' => SystemCategory::TYPE_TA_CHECKLIST_GROUP, 'code' => $code, 'name' => $name, 'sort_order' => 1, 'is_active' => $active]);
    }

    public function test_admin_can_create_item_with_new_type_and_suggested_code(): void
    {
        $this->actingAs($this->admin)->post(route('system-categories.store'), [
            'type' => 'nhom_dau_muc_tro_giang', 'code' => 'TAG_01', 'name' => 'Chuẩn bị lớp', 'is_active' => true,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('system_categories', ['type' => 'nhom_dau_muc_tro_giang', 'code' => 'TAG_01']);
        $this->assertSame('TAG_02', SystemCategory::suggestCode('nhom_dau_muc_tro_giang'));
        $this->actingAs($this->admin)->get(route('system-categories.index', ['type' => 'nhom_dau_muc_tro_giang']))
            ->assertOk()->assertSee('Nhóm đầu mục trợ giảng');
    }

    public function test_invalid_type_is_rejected(): void
    {
        $this->actingAs($this->admin)->post(route('system-categories.store'), [
            'type' => 'khong_ton_tai', 'code' => 'X_01', 'name' => 'X',
        ])->assertSessionHasErrors('type');
        $this->assertDatabaseMissing('system_categories', ['code' => 'X_01']);
    }

    public function test_assign_form_lists_only_active_groups(): void
    {
        $this->group('TAG_01', 'Nhóm hoạt động');
        $this->group('TAG_02', 'Nhóm đã ngừng', false);
        SystemCategory::create(['type' => SystemCategory::TYPE_POSITION, 'code' => 'POS_99', 'name' => 'Khác loại', 'sort_order' => 1, 'is_active' => true]);

        $response = $this->actingAs($this->admin)->get(route('tasks.ta-assign'))->assertOk();
        $response->assertSee('Nhóm hoạt động')->assertDontSee('Nhóm đã ngừng')->assertDontSee('Khác loại');
    }

    public function test_assign_store_accepts_active_group_and_rejects_inactive(): void
    {
        $active = $this->group('TAG_01', 'Chuẩn bị lớp');
        $inactive = $this->group('TAG_02', 'Đã ngừng', false);
        $payload = fn (int $groupId) => [
            'assistant_id' => $this->ta->id,
            'assign_date' => now()->toDateString(),
            'tasks' => [['category' => 'before', 'content' => 'Việc A', 'attach_class' => '0', 'checklist_group_id' => $groupId]],
        ];

        $this->actingAs($this->admin)->post(route('tasks.ta-assign.store'), $payload($inactive->id))
            ->assertSessionHasErrors('tasks.0.checklist_group_id');
        $this->assertDatabaseMissing('work_tasks', ['title' => 'Việc A']);

        $this->actingAs($this->admin)->post(route('tasks.ta-assign.store'), $payload($active->id))
            ->assertSessionHasNoErrors();
        $this->assertStringContainsString('Nhóm: Chuẩn bị lớp', WorkTask::where('title', 'Việc A')->value('description'));
    }
}
