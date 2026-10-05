<?php

use App\Models\User;
use App\Support\Roles;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Cố định bộ 9 vai trò của hệ thống (App\Support\Roles):
 *  - Vai trò đã bỏ ("Kế toán & Thu ngân", "Giáo viên giảng dạy") còn trên hệ thống → chuyển người đang giữ sang vai trò
 *    thay thế (Roles::RETIRED) rồi xóa vai trò. Hệ thống đã xóa tay hai vai trò này thì không làm gì.
 *  - Vai trò cố định bị thiếu (trên hệ thống đang chạy) → tạo lại với quyền mặc định ở config/access.php, để code dùng
 *    User::role(Roles::X) không gặp RoleDoesNotExist. Cài mới (chưa có vai trò nào) thì để RoleSeeder lo.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (Roles::RETIRED as $retired => $replacement) {
            $role = Role::query()->where('name', $retired)->where('guard_name', 'web')->first();
            if (! $role) {
                continue;
            }
            Role::findOrCreate($replacement, 'web');
            $moved = User::withTrashed()->role($retired)->get();
            foreach ($moved as $user) {
                $user->assignRole($replacement);
                $user->removeRole($retired);
            }
            if ($moved->isNotEmpty()) {
                Log::info("Vai trò {$retired} bị bỏ: chuyển {$moved->count()} người sang {$replacement}", ['user_ids' => $moved->pluck('id')->all()]);
            }
            $role->delete();
        }

        // Chỉ hệ thống đang chạy (đã có vai trò). Cài mới / database test: các migration trước đã tạo lẻ vài quyền
        // (vd sla.configure) nên không dựa vào "đã có quyền" — tạo vai trò lúc này sẽ chỉ nhận vài quyền đó, rồi RoleSeeder
        // (chỉ thêm vai trò còn thiếu) bỏ qua → vai trò mặc định thiếu gần hết quyền.
        if (Role::query()->where('guard_name', 'web')->exists()) {
            $all = Permission::query()->where('guard_name', 'web')->pluck('name');
            foreach (Roles::ALL as $name) {
                if (Role::query()->where('name', $name)->where('guard_name', 'web')->exists()) {
                    continue;
                }
                $role = Role::create(['name' => $name, 'guard_name' => 'web']);
                $role->syncPermissions(RoleSeeder::resolvePatterns((array) config("access.roles.{$name}", []), $all)->all());
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Không hoàn tác: vai trò đã bỏ không được tạo lại.
    }
};
