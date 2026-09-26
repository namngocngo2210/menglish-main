<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Phân loại nhân sự theo CHỨC DANH (vai trò = chức danh) cho các quy tắc nghiệp vụ KHÔNG phải phân quyền:
 * loại đơn giá dạy, công thức KPI Học vụ, kỳ báo cáo định kỳ. Cùng nguyên tắc với PayrollFormulaService::profile()
 * (loại nhân sự trên phiếu lương). Mọi quyết định "được làm gì / thấy gì" dùng permission + DataScope, không dùng lớp này.
 */
final class StaffType
{
    public const TEACHER_ROLES = ['teacher', 'teacher_fulltime', 'teacher_parttime'];

    /** @return Collection<int, string> */
    private static function roles(User $user): Collection
    {
        return $user->relationLoaded('roles') ? $user->roles->pluck('name') : $user->getRoleNames();
    }

    /** Giáo viên nước ngoài (chức danh foreign_teacher) — đơn giá GVNN. */
    public static function isForeignTeacher(User $user): bool
    {
        return self::roles($user)->contains('foreign_teacher');
    }

    /** Chỉ là trợ giảng (không kiêm giáo viên) — đơn giá trợ giảng. */
    public static function isAssistantOnly(User $user): bool
    {
        $roles = self::roles($user);

        return $roles->contains('assistant') && $roles->intersect(self::TEACHER_ROLES)->isEmpty();
    }

    /** Học vụ: KPI tự động 6 nhóm / 15 mục, quỹ 2 triệu (A6 Q3). */
    public static function usesAcademicStaffKpi(User $user): bool
    {
        return self::roles($user)->contains('academic_staff');
    }

    /** Kỳ báo cáo định kỳ chính: Học vụ ngày, Học thuật tuần, giáo viên / trợ giảng tháng. */
    public static function reportCadence(User $user): string
    {
        $roles = self::roles($user);

        return match (true) {
            $roles->contains('academic_staff') => 'daily',
            $roles->contains('academic_lead') => 'weekly',
            $roles->intersect([...self::TEACHER_ROLES, 'assistant'])->isNotEmpty() => 'monthly',
            default => 'daily',
        };
    }
}
