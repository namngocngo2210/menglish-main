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
    public const TEACHER_ROLES = Roles::TEACHERS;

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

        return $roles->contains(Roles::ASSISTANT) && $roles->intersect(self::TEACHER_ROLES)->isEmpty();
    }

    /** Học thuật có bật "Kiêm nhiệm giảng dạy": lương đứng lớp theo % học phí + KPI kiêm nhiệm. */
    public static function isTeachingAcademicLead(User $user): bool
    {
        return (bool) $user->academic_teaching && self::roles($user)->contains(Roles::ACADEMIC_LEAD);
    }

    /** Học vụ: KPI tự động 6 nhóm / 15 mục, quỹ 2 triệu (A6 Q3). */
    public static function usesAcademicStaffKpi(User $user): bool
    {
        return self::roles($user)->contains(Roles::ACADEMIC_STAFF);
    }

    /** Kỳ báo cáo định kỳ chính: Học vụ ngày, Học thuật tuần, giáo viên / trợ giảng tháng. */
    public static function reportCadence(User $user): string
    {
        $roles = self::roles($user);

        return match (true) {
            $roles->contains(Roles::ACADEMIC_STAFF) => 'daily',
            $roles->contains(Roles::ACADEMIC_LEAD) => 'weekly',
            $roles->intersect(Roles::TEACHING)->isNotEmpty() => 'monthly',
            default => 'daily',
        };
    }

    /**
     * Báo cáo có cấu trúc ngoài báo cáo định kỳ chính: Học vụ nộp thêm báo cáo tuần theo mục KPI;
     * Học thuật nộp thêm báo cáo tháng và quý (tổng hợp báo cáo tuần, họp giáo viên); giáo viên nộp thêm báo cáo tháng
     * theo lớp (tiến độ, khó khăn, đề xuất + tình hình từng lớp).
     *
     * @return list<string> khóa trong StaffReportController::STRUCTURED
     */
    public static function structuredReports(User $user): array
    {
        $roles = self::roles($user);

        return array_values(array_filter([
            $roles->contains(Roles::ACADEMIC_STAFF) ? 'weekly_kpi' : null,
            $roles->contains(Roles::ACADEMIC_LEAD) ? 'academic_monthly' : null,
            $roles->contains(Roles::ACADEMIC_LEAD) ? 'academic_quarterly' : null,
            $roles->intersect(self::TEACHER_ROLES)->isNotEmpty() ? 'teacher_monthly' : null,
        ]));
    }
}
