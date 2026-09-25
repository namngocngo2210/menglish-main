<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\TuitionReceipt;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Phạm vi chi nhánh cho các màn Học phí (A6 Q7: Quản lý cơ sở chỉ thấy chi nhánh mình).
 *
 * - Admin: toàn hệ thống.
 * - Quản lý cơ sở / Học vụ / Học thuật: chỉ chi nhánh của mình (users.branch_id + user_branches);
 *   tài khoản chưa gán chi nhánh không thấy khoản nào.
 * - Kế toán: kế toán tổng (không gán chi nhánh nào) thấy toàn hệ thống — khớp cách gửi thông báo
 *   phiếu chờ duyệt ("kế toán chi nhánh + kế toán không gán chi nhánh"); kế toán có gán chi nhánh chỉ
 *   thấy các chi nhánh đó. Chưa có cờ "đa chi nhánh" riêng: cấp thêm chi nhánh qua user_branches.
 * - Vai trò khác có quyền tuition.view qua phân quyền cá nhân: toàn hệ thống (UI phân quyền chỉ cấp
 *   phạm vi "Toàn hệ thống" cho module Học phí).
 *
 * Chi nhánh của khoản học phí = student_tuitions.branch_id, thiếu thì theo học viên; của học viên =
 * students.branch_id, thiếu thì theo lớp đang học (như Student::scopeVisibleTo).
 */
class TuitionBranchScope
{
    public const BRANCH_ROLES = ['manager', 'academic_staff', 'academic_lead'];

    /**
     * @return array<int>|null null = không giới hạn
     */
    public static function branchIds(?User $user): ?array
    {
        if (! $user || $user->hasRole('admin')) {
            return null;
        }

        if ($user->hasAnyRole(self::BRANCH_ROLES)) {
            return Student::branchIdsFor($user);
        }

        if ($user->hasRole('accountant')) {
            $ids = Student::branchIdsFor($user);

            return $ids === [] ? null : $ids;
        }

        return null;
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
