<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\TuitionReceipt;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Phạm vi chi nhánh cho các màn Học phí / Báo cáo thu chi (A6 Q7: Quản lý cơ sở chỉ thấy chi nhánh mình).
 *
 * BA 26/09/2026 "Phần kế toán cho Admin phân quyền linh hoạt": phạm vi KHÔNG còn suy ra từ vai trò hay từ việc
 * "kế toán không gán chi nhánh = kế toán tổng". Quy tắc duy nhất:
 * - Có quyền `tuition.all_branches` (màn Học phí) / `finance.all_branches` (báo cáo thu chi): toàn hệ thống.
 *   Admin luôn có (Gate::before). Kế toán tổng được Admin cấp qua màn Vai trò hoặc Phân quyền cá nhân
 *   (migration 2026_10_06_100000 cấp sẵn cho kế toán đang không gán chi nhánh để giữ hành vi cũ).
 * - Không có: chỉ các chi nhánh của mình (users.branch_id + user_branches); chưa gán chi nhánh thì không thấy khoản nào.
 *
 * Chi nhánh của khoản học phí = student_tuitions.branch_id, thiếu thì theo học viên; của học viên =
 * students.branch_id, thiếu thì theo lớp đang học (như Student::scopeVisibleTo).
 */
class TuitionBranchScope
{
    /** Quyền xem & xử lý học phí mọi chi nhánh. */
    public const ALL_BRANCHES = 'tuition.all_branches';

    /** Quyền xem báo cáo thu chi / sổ khoản chi mọi chi nhánh. */
    public const FINANCE_ALL_BRANCHES = 'finance.all_branches';

    /**
     * @return array<int>|null null = không giới hạn
     */
    public static function branchIds(?User $user, string $allBranchesAbility = self::ALL_BRANCHES): ?array
    {
        if (! $user) {
            return null;
        }

        if ($user->can($allBranchesAbility)) {
            return null;
        }

        return Student::branchIdsFor($user);
    }

    /** Người dùng có thấy dữ liệu học phí của chi nhánh này không (null = khoản chưa gắn chi nhánh). */
    public static function coversBranch(?User $user, ?int $branchId, string $allBranchesAbility = self::ALL_BRANCHES): bool
    {
        $ids = self::branchIds($user, $allBranchesAbility);

        return $ids === null || ($branchId !== null && in_array($branchId, $ids, true));
    }

    public static function students(Builder $query, ?array $ids): Builder
    {
        if ($ids === null) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q->whereIn('branch_id', $ids)
            ->orWhere(fn (Builder $q) => $q->whereNull('branch_id')
                ->whereHas('currentClass', fn (Builder $c) => $c->whereIn('branch_id', $ids))));
    }

    public static function tuitions(Builder $query, ?array $ids): Builder
    {
        if ($ids === null) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q->whereIn('branch_id', $ids)
            ->orWhere(fn (Builder $q) => $q->whereNull('branch_id')
                ->whereHas('student', fn (Builder $s) => self::students($s, $ids))));
    }

    public static function receipts(Builder $query, ?array $ids): Builder
    {
        if ($ids === null) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q->whereHas('tuition', fn (Builder $t) => self::tuitions($t, $ids))
            ->orWhere(fn (Builder $q) => $q->whereNull('student_tuition_id')
                ->whereHas('student', fn (Builder $s) => self::students($s, $ids))));
    }

    public static function branches(?array $ids): Builder
    {
        return Branch::query()->when($ids !== null, fn (Builder $q) => $q->whereIn('id', $ids));
    }

    public static function allowsStudent(?Student $student, ?array $ids): bool
    {
        if ($ids === null) {
            return true;
        }
        if (! $student) {
            return false;
        }
        $branchId = $student->branch_id ?? $student->currentClass?->branch_id;

        return $branchId !== null && in_array((int) $branchId, $ids, true);
    }

    public static function allowsTuition(?StudentTuition $tuition, ?array $ids): bool
    {
        if ($ids === null) {
            return true;
        }
        if (! $tuition) {
            return false;
        }

        return $tuition->branch_id !== null
            ? in_array((int) $tuition->branch_id, $ids, true)
            : self::allowsStudent($tuition->student, $ids);
    }

    public static function allowsReceipt(?TuitionReceipt $receipt, ?array $ids): bool
    {
        if ($ids === null) {
            return true;
        }
        if (! $receipt) {
            return false;
        }

        return $receipt->student_tuition_id
            ? self::allowsTuition($receipt->tuition, $ids)
            : self::allowsStudent($receipt->student, $ids);
    }
}
