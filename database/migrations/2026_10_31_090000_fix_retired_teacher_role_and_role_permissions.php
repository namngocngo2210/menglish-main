<?php

use App\Models\User;
use App\Services\PayrollFormulaService;
use App\Support\Rbac;
use App\Support\Roles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Sửa hậu quả của migration 9 vai trò (2026_10_30_090000):
 *  - Vai trò cũ "Giáo viên giảng dạy" (teacher) trước đây xếp Part-time / Full-time theo hợp đồng + lương cơ bản, nhưng
 *    migration chuyển hết sang teacher_fulltime → giáo viên part-time / CTV (lương cơ bản 0) mất lương theo buổi.
 *    Ai đang teacher_fulltime mà lương cơ bản = 0 và không có hợp đồng toàn thời gian → teacher_parttime
 *    (Full-time lương cơ bản 0 vốn nhận 0 đồng, nên không ai bị thiệt).
 *  - Vai trò tạo lại / vai trò bị bỏ chưa qua Rbac: thêm quyền "gán vai trò X" cho vai trò cố định còn thiếu,
 *    xóa quyền gán + ghi đè quyền của vai trò đã bỏ.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Role::query()->where('guard_name', 'web')->exists()) {
            return; // Cài mới: RoleSeeder lo.
        }

        $fulltime = Role::query()->where('name', Roles::TEACHER_FULLTIME)->where('guard_name', 'web')->first();
        if ($fulltime && Role::query()->where('name', Roles::TEACHER_PARTTIME)->where('guard_name', 'web')->exists()) {
            $moved = User::withTrashed()->role(Roles::TEACHER_FULLTIME)->get()
                ->filter(fn (User $user) => (float) $user->base_salary <= 0
                    && ! in_array(mb_strtolower(trim((string) $user->contract_type)), PayrollFormulaService::FULLTIME_CONTRACTS, true));
            foreach ($moved as $user) {
                $user->assignRole(Roles::TEACHER_PARTTIME);
                $user->removeRole(Roles::TEACHER_FULLTIME);
            }
            if ($moved->isNotEmpty()) {
                Log::info('Giáo viên full-time lương cơ bản 0 chuyển về teacher_parttime', ['user_ids' => $moved->pluck('id')->all()]);
            }
        }

        foreach (array_keys(Roles::RETIRED) as $retired) {
            if (! Role::query()->where('name', $retired)->where('guard_name', 'web')->exists()) {
                Rbac::forgetRole($retired);
            }
        }
        Role::query()->where('guard_name', 'web')->whereIn('name', Roles::ALL)->get()
            ->reject(fn (Role $role) => Permission::query()->where('guard_name', 'web')->where('name', Rbac::assignRolePermission($role->name))->exists())
            ->each(fn (Role $role) => Rbac::registerRole($role));

        Rbac::flushCache();
    }

    public function down(): void
    {
        // Không hoàn tác: không biết ai vốn là full-time.
    }
};
