<?php

namespace App\Services\Merchandise;

use App\Models\MerchandiseItem;
use App\Models\MerchandiseStock;
use App\Models\MerchandiseStockMovement;
use App\Models\StudentTuition;
use App\Models\TuitionReceipt;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Tồn kho hàng hóa theo chi nhánh.
 *
 * Xuất kho khi phiếu thu được DUYỆT (bản nháp / chờ duyệt chưa trừ), tại chi nhánh ghi nhận phiếu:
 * - Sách / hàng hóa trong hợp đồng học phí (chọn lúc Chốt & Xếp lớp): xuất 1 lần cho mỗi hợp đồng, cùng phiếu thu
 *   đầu tiên của hợp đồng được duyệt (thu cùng học phí).
 * - Hàng hóa chọn ở phần Phụ thu của phiếu (kể cả phiếu chỉ thu phụ thu, không kèm học phí): xuất theo phiếu đó.
 * Hủy hóa đơn của phiếu đã xuất → hoàn lại đúng số đã xuất. Kho được phép âm (đã giao sách nhưng chưa nhập kho):
 * màn lập phiếu / duyệt phiếu / tồn kho cảnh báo thay vì chặn thu tiền.
 *
 * Mọi hàm ghi đều idempotent (tính theo nhật ký đã ghi), gọi lại không trừ 2 lần.
 */
class StockService
{
    /**
     * Cộng / trừ tồn của một mặt hàng tại chi nhánh và ghi nhật ký.
     *
     * @param  array<string, mixed>  $attributes  source, tuition_receipt_id, student_tuition_id, user_id, note
     */
    public function adjust(int $itemId, int $branchId, int $change, string $type, array $attributes = []): MerchandiseStockMovement
    {
        return DB::transaction(function () use ($itemId, $branchId, $change, $type, $attributes) {
            $stock = $this->lockedStock($itemId, $branchId);
            $stock->quantity = (int) $stock->quantity + $change;
            $stock->save();

            return MerchandiseStockMovement::create([
                'merchandise_item_id' => $itemId,
                'branch_id' => $branchId,
                'type' => $type,
                'quantity_change' => $change,
                'balance_after' => (int) $stock->quantity,
                'user_id' => array_key_exists('user_id', $attributes) ? $attributes['user_id'] : Auth::id(),
                ...array_intersect_key($attributes, array_flip(['source', 'tuition_receipt_id', 'student_tuition_id', 'note'])),
            ]);
        });
    }

    /** Kiểm kê: đặt số tồn thực tế, ghi phần chênh lệch (không ghi gì khi khớp). */
    public function count(int $itemId, int $branchId, int $actualQuantity, ?string $note = null): ?MerchandiseStockMovement
    {
        return DB::transaction(function () use ($itemId, $branchId, $actualQuantity, $note) {
            $current = (int) $this->lockedStock($itemId, $branchId)->quantity;
            if ($current === $actualQuantity) {
                return null;
            }

            return $this->adjust($itemId, $branchId, $actualQuantity - $current, MerchandiseStockMovement::TYPE_COUNT, ['note' => $note]);
        });
    }

    /**
     * Đồng bộ kho theo trạng thái phiếu: đã duyệt → xuất phần chưa xuất; đã hủy hóa đơn → hoàn phần đã xuất.
     * Gọi tự động khi phiếu đổi trạng thái (TuitionReceipt::booted).
     */
    public function syncReceipt(TuitionReceipt $receipt): void
    {
        if ($receipt->status === TuitionReceipt::STATUS_APPROVED && (float) $receipt->amount > 0) {
            $this->issueForReceipt($receipt);
        } elseif ($receipt->status === TuitionReceipt::STATUS_CANCELLED) {
            $this->restoreForReceipt($receipt);
        }
    }

    /**
     * Hàng sẽ xuất kho khi duyệt phiếu (để cảnh báo trước ở màn duyệt): mỗi dòng gồm mặt hàng, số lượng, nguồn,
     * tồn hiện tại và tồn sau khi xuất tại chi nhánh của phiếu.
     *
     * @return list<array{item_id: int, name: string, unit: string, quantity: int, source: string, stock: int, stock_after: int}>
     */
    public function previewForReceipt(TuitionReceipt $receipt): array
    {
        $branchId = $receipt->resolveBranchId();
        if (! $branchId) {
            return [];
        }

        $lines = $this->pendingLines($receipt, $branchId);
        if ($lines === []) {
            return [];
        }

        $items = MerchandiseItem::withTrashed()->whereIn('id', array_column($lines, 'item_id'))->get()->keyBy('id');
        $stocks = $this->quantities(array_column($lines, 'item_id'), $branchId);
        $running = [];

        return array_map(function (array $line) use ($items, $stocks, &$running) {
            $before = $running[$line['item_id']] ?? ($stocks[$line['item_id']] ?? 0);
            $running[$line['item_id']] = $before - $line['quantity'];

            return [
                'item_id' => $line['item_id'],
                'name' => $items[$line['item_id']]->name ?? $line['name'],
                'unit' => $items[$line['item_id']]->unit ?? '',
                'quantity' => $line['quantity'],
                'source' => $line['source'],
                'stock' => $before,
                'stock_after' => $running[$line['item_id']],
            ];
        }, $lines);
    }

    /**
     * Số tồn tại một chi nhánh theo mặt hàng (mặt hàng chưa có dòng tồn = 0).
     *
     * @param  iterable<int>  $itemIds
     * @return array<int, int>
     */
    public function quantities(iterable $itemIds, int $branchId): array
    {
        $ids = collect($itemIds)->map(fn ($id) => (int) $id)->unique()->values();

        $found = MerchandiseStock::query()
            ->where('branch_id', $branchId)
            ->whereIn('merchandise_item_id', $ids)
            ->pluck('quantity', 'merchandise_item_id')
            ->map(fn ($qty) => (int) $qty);

        return $ids->mapWithKeys(fn (int $id) => [$id => (int) ($found[$id] ?? 0)])->all();
    }

    /**
     * Tồn của mọi mặt hàng tại các chi nhánh: [branch_id => [item_id => số tồn]] (chỉ các dòng đã có).
     *
     * @param  array<int>  $branchIds
     * @return array<int, array<int, int>>
     */
    public function quantitiesByBranch(array $branchIds): array
    {
        return MerchandiseStock::query()
            ->whereIn('branch_id', $branchIds)
            ->get(['branch_id', 'merchandise_item_id', 'quantity'])
            ->groupBy('branch_id')
            ->map(fn ($rows) => $rows->mapWithKeys(fn (MerchandiseStock $s) => [(int) $s->merchandise_item_id => (int) $s->quantity])->all())
            ->all();
    }

    private function issueForReceipt(TuitionReceipt $receipt): void
    {
        $branchId = $receipt->resolveBranchId();
        if (! $branchId) {
            return;
        }

        foreach ($this->pendingLines($receipt, $branchId) as $line) {
            $this->adjust($line['item_id'], $branchId, -$line['quantity'], MerchandiseStockMovement::TYPE_SALE, [
                'source' => $line['source'],
                'tuition_receipt_id' => $receipt->id,
                'student_tuition_id' => $receipt->student_tuition_id,
                'user_id' => $receipt->approver_id ?? Auth::id(),
                'note' => ($line['source'] === MerchandiseStockMovement::SOURCE_CONTRACT ? 'Sách / hàng hóa trong hợp đồng' : 'Phụ thu')
                    .' — phiếu '.$receipt->receipt_number,
            ]);
        }
    }

    private function restoreForReceipt(TuitionReceipt $receipt): void
    {
        $issued = MerchandiseStockMovement::query()
            ->where('tuition_receipt_id', $receipt->id)
            ->selectRaw('merchandise_item_id, branch_id, source, SUM(quantity_change) as net')
            ->groupBy('merchandise_item_id', 'branch_id', 'source')
            ->get();

        foreach ($issued as $row) {
            if ((int) $row->net >= 0) {
                continue;
            }
            $this->adjust((int) $row->merchandise_item_id, (int) $row->branch_id, -(int) $row->net, MerchandiseStockMovement::TYPE_RETURN, [
                'source' => $row->source,
                'tuition_receipt_id' => $receipt->id,
                'student_tuition_id' => $receipt->student_tuition_id,
                'note' => 'Hủy hóa đơn '.($receipt->invoice_number ?? $receipt->receipt_number),
            ]);
        }
    }

    /**
     * Phần hàng của phiếu chưa được xuất kho.
     *
     * @return list<array{item_id: int, name: string, quantity: int, source: string}>
     */
    private function pendingLines(TuitionReceipt $receipt, int $branchId): array
    {
        $lines = [];

        foreach (self::surchargeItems($receipt->collected_items) as $item) {
            $issued = $receipt->exists ? -(int) MerchandiseStockMovement::query()
                ->where('tuition_receipt_id', $receipt->id)
                ->where('source', MerchandiseStockMovement::SOURCE_SURCHARGE)
                ->where('merchandise_item_id', $item['id'])
                ->sum('quantity_change') : 0;
            if (($remaining = $item['quantity'] - $issued) > 0) {
                $lines[] = ['item_id' => $item['id'], 'name' => $item['name'], 'quantity' => $remaining, 'source' => MerchandiseStockMovement::SOURCE_SURCHARGE];
            }
        }

        $tuition = $receipt->student_tuition_id ? ($receipt->tuition ?? StudentTuition::find($receipt->student_tuition_id)) : null;
        foreach (self::contractItems($tuition?->fee_items) as $item) {
            $issued = -(int) MerchandiseStockMovement::query()
                ->where('student_tuition_id', $tuition->id)
                ->where('source', MerchandiseStockMovement::SOURCE_CONTRACT)
                ->where('merchandise_item_id', $item['id'])
                ->sum('quantity_change');
            if (($remaining = $item['quantity'] - $issued) > 0) {
                $lines[] = ['item_id' => $item['id'], 'name' => $item['name'], 'quantity' => $remaining, 'source' => MerchandiseStockMovement::SOURCE_CONTRACT];
            }
        }

        return $lines;
    }

    /**
     * Hàng hóa ở phần Phụ thu của phiếu (collected_items có source = surcharge).
     *
     * @return list<array{id: int, name: string, quantity: int}>
     */
    public static function surchargeItems(mixed $collectedItems): array
    {
        return self::stockLines($collectedItems, fn (array $line) => ($line['source'] ?? null) === MerchandiseStockMovement::SOURCE_SURCHARGE);
    }

    /**
     * Sách / hàng hóa trong hợp đồng có theo dõi kho (fee_items có stock_tracked — hợp đồng chốt trước khi có kho
     * theo chi nhánh đã trừ tồn chung lúc chốt nên không trừ lại).
     *
     * @return list<array{id: int, name: string, quantity: int}>
     */
    public static function contractItems(mixed $feeItems): array
    {
        return self::stockLines($feeItems, fn (array $line) => ! empty($line['stock_tracked']));
    }

    private static function stockLines(mixed $raw, callable $filter): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $lines = [];
        foreach ($raw as $line) {
            if (! is_array($line) || empty($line['id']) || ! $filter($line)) {
                continue;
            }
            $id = (int) $line['id'];
            $lines[$id] ??= ['id' => $id, 'name' => (string) ($line['name'] ?? ''), 'quantity' => 0];
            $lines[$id]['quantity'] += max(1, (int) ($line['quantity'] ?? 1));
        }

        return array_values($lines);
    }

    private function lockedStock(int $itemId, int $branchId): MerchandiseStock
    {
        $stock = MerchandiseStock::query()
            ->where('merchandise_item_id', $itemId)
            ->where('branch_id', $branchId)
            ->lockForUpdate()
            ->first();

        if ($stock) {
            return $stock;
        }

        MerchandiseStock::query()->insertOrIgnore([
            'merchandise_item_id' => $itemId,
            'branch_id' => $branchId,
            'quantity' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return MerchandiseStock::query()
            ->where('merchandise_item_id', $itemId)
            ->where('branch_id', $branchId)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
