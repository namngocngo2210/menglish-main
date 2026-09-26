<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Khởi tạo production lần đầu (database trống): vai trò, quyền, danh mục hệ thống và MỘT tài khoản Admin
 * lấy từ .env (INITIAL_ADMIN_NAME / INITIAL_ADMIN_EMAIL / INITIAL_ADMIN_PASSWORD). Không tạo chi nhánh,
 * nhân sự, học viên hay dữ liệu demo — Admin tự tạo trong hệ thống. Admin bị bắt đổi mật khẩu lần đầu.
 */
class ProductionBootstrapSeeder extends Seeder
{
    public function run(): void
    {
        $email = trim((string) env('INITIAL_ADMIN_EMAIL', ''));
        $password = (string) env('INITIAL_ADMIN_PASSWORD', '');
        if ($email === '' || strlen($password) < 10) {
            throw new RuntimeException('Cần INITIAL_ADMIN_EMAIL và INITIAL_ADMIN_PASSWORD (tối thiểu 10 ký tự) trong .env.');
        }

        $this->call([
            PermissionSeeder::class,
            RoleSeeder::class,
            SystemCategorySeeder::class,
        ]);

        $admin = User::firstOrCreate(['email' => $email], [
            'name' => (string) env('INITIAL_ADMIN_NAME', 'Quản trị hệ thống'),
            'password' => Hash::make($password),
            'is_active' => true,
        ]);
        $admin->forceFill(['must_change_password' => true])->save();
        $admin->syncRoles(['admin']);
    }
}
