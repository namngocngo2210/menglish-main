<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use App\Models\UserPermissionOverride;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class NavigationPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $teacher;
    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $this->seed(\Database\Seeders\RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Chi nhánh Cầu Giấy', 'code' => 'CG', 'is_active' => true]);

        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@menglish.edu.vn',
            'password' => bcrypt('password'),
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);
        $this->admin->syncRoles(['admin']);

        $this->teacher = User::create([
            'name' => 'Giáo viên Nguyễn Văn A',
            'email' => 'teacher.a@menglish.edu.vn',
            'password' => bcrypt('password'),
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);
        $this->teacher->syncRoles(['teacher']);
    }

    public function test_admin_can_see_all_navigation_modules(): void
    {
        $response = $this->actingAs($this->admin)->get(route('dashboard'));

        $response->assertStatus(200);
        // IX-4: 1 mục / workspace; cấu hình + phân quyền gom vào "Cài đặt".
        $response->assertSee('Khách hàng (CRM)');
        $response->assertSee('data-menu-item="tuition"', false);
        $response->assertSee('data-menu-item="hr"', false);
        $response->assertSee('data-menu-item="payroll"', false);
        $response->assertSee('data-menu-item="settings"', false);
    }

    public function test_teacher_only_sees_permitted_navigation_modules(): void
    {
        $response = $this->actingAs($this->teacher)->get(route('dashboard'));

        $response->assertStatus(200);
        // Teacher MUST see permitted items (lưới phím tắt cuối Tổng quan đã bỏ 04/10/2026 — kiểm tra trên menu trái).
        $response->assertSee('data-menu-item="teacher_portal"', false);
        $response->assertSee('Chất lượng giảng dạy');
        $response->assertSee('Lương');
        $response->assertSee('Ticket');

        // Teacher MUST NOT see unpermitted modules
        $response->assertDontSee('Khách hàng (CRM)');
        $response->assertDontSee('data-menu-item="tuition"', false);
        $response->assertDontSee('data-menu-item="hr"', false);
        $response->assertDontSee('Mockup Hub');
    }

    public function test_permission_override_dynamically_shows_module_for_teacher(): void
    {
        // Ban đầu teacher không thấy CRM
        $response = $this->actingAs($this->teacher)->get(route('dashboard'));
        $response->assertDontSee('Khách hàng (CRM)');

        // Cấp quyền override cá nhân: xem + tạo khách (lead.create là quyền "neo" của khu CRM trên menu trái)
        foreach (['view', 'create'] as $action) {
            UserPermissionOverride::create([
                'user_id' => $this->teacher->id,
                'module' => 'lead',
                'action' => $action,
                'scope_type' => UserPermissionOverride::SCOPE_ALL,
                'allow' => true,
                'created_by' => $this->admin->id,
            ]);
        }

        // Refresh user instance
        $this->teacher->refresh();

        // Bây giờ teacher đăng nhập lại => Thấy ngay CRM!
        $responseAfter = $this->actingAs($this->teacher)->get(route('dashboard'));
        $responseAfter->assertSee('Khách hàng (CRM)');
    }
}
