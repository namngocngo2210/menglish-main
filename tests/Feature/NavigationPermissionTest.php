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
        $response->assertSee('CRM & Tuyển sinh');
        $response->assertSee('Học phí & Hoá đơn');
        $response->assertSee('Phân quyền & Nhật ký');
        $response->assertSee('Cấu hình nghiệp vụ');
        $response->assertSee('Nhân sự & KPI');
    }

    public function test_teacher_only_sees_permitted_navigation_modules(): void
    {
        $response = $this->actingAs($this->teacher)->get(route('dashboard'));

        $response->assertStatus(200);
        // Teacher MUST see permitted items
        $response->assertSee('Lớp học');
        $response->assertSee('Lịch dạy');
        $response->assertSee('Lương');
        $response->assertSee('Ticket');

        // Teacher MUST NOT see unpermitted modules
        $response->assertDontSee('CRM & Tuyển sinh');
        $response->assertDontSee('Học phí & Hoá đơn');
        $response->assertDontSee('Phân quyền & Hệ thống');
        $response->assertDontSee('Mockup Hub');
    }

    public function test_permission_override_dynamically_shows_module_for_teacher(): void
    {
        // Ban đầu teacher không thấy CRM
        $response = $this->actingAs($this->teacher)->get(route('dashboard'));
        $response->assertDontSee('CRM & Tuyển sinh');

        // Cấp quyền override cá nhân: cho phép lead.view
        UserPermissionOverride::create([
            'user_id' => $this->teacher->id,
            'module' => 'lead',
            'action' => 'view',
            'scope_type' => UserPermissionOverride::SCOPE_ALL,
            'allow' => true,
            'created_by' => $this->admin->id,
        ]);

        // Refresh user instance
        $this->teacher->refresh();

        // Bây giờ teacher đăng nhập lại => Thấy ngay CRM!
        $responseAfter = $this->actingAs($this->teacher)->get(route('dashboard'));
        $responseAfter->assertSee('CRM');
    }
}
