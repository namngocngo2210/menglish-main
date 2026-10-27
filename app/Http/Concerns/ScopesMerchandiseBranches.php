<?php

namespace App\Http\Concerns;

use App\Models\Branch;
use App\Support\DataScope;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/** Chi nhánh xem / nhập kho hàng hóa theo phạm vi dữ liệu `merchandise_stock` (trang Hàng hóa & tồn kho). */
trait ScopesMerchandiseBranches
{
    /** Chi nhánh trong phạm vi tồn kho của người dùng. */
    private function branches(Request $request): Collection
    {
        $ids = DataScope::branchIds($request->user(), 'merchandise_stock');

        return Branch::query()->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->orderBy('name')->get(['id', 'name']);
    }

    /** Chi nhánh đang xem: theo ?branch_id (nếu trong phạm vi), mặc định chi nhánh chính của người dùng. */
    private function pickBranch(Request $request, Collection $branches): ?Branch
    {
        $requested = $request->integer('branch_id') ?: $request->integer('branch');

        return $branches->firstWhere('id', $requested)
            ?? $branches->firstWhere('id', (int) $request->user()->branch_id)
            ?? $branches->first();
    }
}
