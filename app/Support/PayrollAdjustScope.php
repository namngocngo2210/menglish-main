<?php

namespace App\Support;

use App\Models\User;

/**
 * Phạm vi điều chỉnh lương, khớp PayrollController::scopeRecords() khi kỳ lương chưa khóa:
 * Toàn hệ thống được sửa mọi phiếu; Chi nhánh xét chi nhánh chính / bổ sung của nhân sự;
 * ngoài mức Toàn hệ thống không được sửa phiếu của chính mình nên mức Của tôi không được sửa phiếu nào.
 */
final class PayrollAdjustScope
{
    public static function canAdjust(User $viewer, ?int $staffId): bool
    {
        return $staffId !== null && in_array($staffId, self::adjustableStaffIds($viewer, [$staffId]), true);
    }

    /**
     * @param  iterable<int|null>  $staffIds
     * @return list<int>
     */
    public static function adjustableStaffIds(User $viewer, iterable $staffIds): array
    {
        $ids = collect($staffIds)->filter(fn ($id) => $id !== null)->map(fn ($id) => (int) $id)->unique()->values()->all();
        if ($ids === [] || DataScope::isAll($viewer, 'payroll')) {
            return $ids;
        }

        if (DataScope::level($viewer, 'payroll') === DataScope::OWN) {
            return [];
        }

        $branchIds = $viewer->branchIds();
        if ($branchIds === []) {
            return [];
        }

        return User::query()->whereKey($ids)->where('id', '!=', $viewer->id)
            ->where(fn ($u) => $u->whereIn('branch_id', $branchIds)
                ->orWhereHas('branches', fn ($b) => $b->whereIn('branches.id', $branchIds)))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
