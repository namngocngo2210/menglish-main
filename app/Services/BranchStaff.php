<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Tìm nhân sự theo vai trò trong một chi nhánh (chi nhánh chính users.branch_id hoặc chi nhánh
 * được cấp thêm user_branches). Chỉ tài khoản đang hoạt động, chưa bị khóa.
 */
class BranchStaff
{
    /**
     * @param  array<string>|string  $roles
     * @return Collection<int, User>
     */
    public static function withRoles(array|string $roles, ?int $branchId): Collection
    {
        // Vai trò chưa được tạo (vd. môi trường chưa seed) thì coi như không có ai.
        $roles = \Spatie\Permission\Models\Role::whereIn('name', (array) $roles)->pluck('name')->all();
        if (! $branchId || $roles === []) {
            return collect();
        }

        return User::role($roles)
            ->where('is_active', true)
            ->whereNull('locked_at')
            ->where(fn (Builder $q) => $q->where('branch_id', $branchId)
                ->orWhereHas('branches', fn (Builder $b) => $b->where('branches.id', $branchId)))
            ->orderBy('id')
            ->get();
    }

    /** Admin đang hoạt động (không phụ thuộc chi nhánh). */
    public static function admins(): Collection
    {
        if (! \Spatie\Permission\Models\Role::where('name', 'admin')->exists()) {
            return collect();
        }

        return User::role('admin')->where('is_active', true)->whereNull('locked_at')->orderBy('id')->get();
    }

    /** Học vụ (academic_staff) của chi nhánh. */
    public static function academicStaff(?int $branchId): Collection
    {
        return static::withRoles('academic_staff', $branchId);
    }
}
