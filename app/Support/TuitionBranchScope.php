<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\TuitionReceipt;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Phạm vi chi nhánh cho các màn Học phí / Báo cáo thu chi — theo phạm vi dữ liệu (DataScope) của module
 * `tuition` / `finance` (docs/rbac.md):
 * - "tuition.scope_all" / "finance.scope_all" (Toàn hệ thống): mọi chi nhánh. Admin luôn có (Gate::before); kế toán
 *   tổng được Admin cấp theo vai trò hoặc theo người (thay cho `*.all_branches` cũ — migration 2026_10_07_100100).
 * - Mức "Chi nhánh" (mặc định): chỉ các chi nhánh của mình (users.branch_id + user_branches); chưa gán chi nhánh thì
 *   không thấy khoản nào.
 *
 * Chi nhánh của khoản học phí = student_tuitions.branch_id, thiếu thì theo học viên; của học viên =
 * students.branch_id, thiếu thì theo lớp đang học (như Student::scopeVisibleTo).
 */
class TuitionBranchScope
{
    /** Module phạm vi dữ liệu màn Học phí. */
    public const TUITION = 'tuition';

    /** Module phạm vi dữ liệu báo cáo thu chi / sổ khoản chi. */
    public const FINANCE = 'finance';

    /**
     * @return array<int>|null null = không giới hạn
     */
    public static function branchIds(?User $user, string $module = self::TUITION): ?array
    {
        if (! $user) {
            return null;
        }

        return DataScope::branchIds($user, $module);
    }

    /** Người dùng có thấy dữ liệu học phí của chi nhánh này không (null = khoản chưa gắn chi nhánh). */
    public static function coversBranch(?User $user, ?int $branchId, string $module = self::TUITION): bool
    {
        $ids = self::branchIds($user, $module);

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

    /**
     * Yêu cầu hủy hóa đơn: theo phiếu thu gắn kèm, không có phiếu thì theo học viên. Yêu cầu không gắn phiếu lẫn
     * học viên (dữ liệu cũ) không thuộc chi nhánh nào: vẫn hiện để xử lý (duyệt sẽ bị chặn vì không có phiếu để hoàn
     * tác công nợ).
     */
    public static function cancellations(Builder $query, ?array $ids): Builder
    {
        if ($ids === null) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q->whereHas('receipt', fn (Builder $r) => self::receipts($r, $ids))
            ->orWhere(fn (Builder $q) => $q->whereNull('tuition_receipt_id')
                ->whereHas('student', fn (Builder $s) => self::students($s, $ids)))
            ->orWhere(fn (Builder $q) => $q->whereNull('tuition_receipt_id')->whereNull('student_id')));
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
