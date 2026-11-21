<?php

namespace App\Services\Finance;

use App\Models\TuitionReceipt;
use App\Support\TuitionBranchScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Doanh thu CHÍNH THỨC (US Đối soát chuyển khoản, DS R-06, BA OP-HP-13) — nguồn duy nhất của báo cáo.
 *
 * Ánh xạ trạng thái:
 *  - "Đã duyệt"   = status 'approved' (đã hủy hóa đơn = 'cancelled' bị loại; draft/pending/rejected cũng loại).
 *  - "Chính thức" = đã duyệt VÀ có số hóa đơn (invoice_number) — đúng nhãn "Chính thức" ở màn Lịch sử thu.
 *  - "Tạm thu"    = chuyển khoản chưa duyệt / chưa có số hóa đơn → không tính.
 *  - Tiền mặt: chỉ cần "Đã duyệt". Mọi hình thức không phải tiền mặt (transfer, vietqr, pos) cần "Chính thức".
 */
class OfficialRevenueQuery
{
    public const CASH_METHODS = ['cash', 'tien_mat'];

    public static function receipts(string $startDate, string $endDate, int|string|null $branchId = null): Builder
    {
        $query = TuitionReceipt::query()
            ->whereBetween('payment_date', [$startDate, $endDate])
            ->where('status', TuitionReceipt::STATUS_APPROVED)
            ->where(function (Builder $q) {
                $q->whereIn('payment_method', self::CASH_METHODS)
                    ->orWhere(fn (Builder $b) => $b->whereNotNull('invoice_number')->where('invoice_number', '!=', ''));
            });

        if ($branchId !== null && $branchId !== 'all' && is_numeric($branchId)) {
            TuitionBranchScope::receipts($query, [(int) $branchId]);
        }

        return $query;
    }
}
