<?php

namespace App\Services\Merchandise;

use App\Models\AdminNotification;
use App\Models\Branch;
use App\Models\MerchandiseItem;
use App\Models\MerchandiseStockMovement;
use App\Models\WorkTask;
use App\Services\BranchStaff;

/**
 * Hết sách vẫn cho thu tiền (kho được âm). Khi tồn một mặt hàng ở chi nhánh về 0 hoặc âm sau một lần xuất,
 * hệ thống tự giao Admin việc "Nhập bù sách" cho đúng chi nhánh + mặt hàng đó:
 * - Mỗi chi nhánh + mặt hàng chỉ có một việc đang mở; xuất tiếp thì cập nhật số cần nhập vào việc đó, không tạo trùng.
 * - Chi nhánh nhập kho / kiểm kê đưa tồn lên trên 0 → việc tự hoàn thành.
 */
class RestockTaskService
{
    /** Số ngày Admin có để nhập bù kể từ khi kho hết (SLA stock.restock_task). */
    public static function dueDays(): int
    {
        return \App\Services\Sla\Sla::value('stock.restock_task');
    }

    public function sync(MerchandiseStockMovement $movement): void
    {
        $balance = (int) $movement->balance_after;

        if ($balance > 0) {
            $this->closeOpenTask($movement, $balance);
        } elseif ($movement->quantity_change < 0) {
            $this->openOrUpdateTask($movement, $balance);
        }
    }

    private function openTask(int $itemId, int $branchId): ?WorkTask
    {
        return WorkTask::query()
            ->where('kind', WorkTask::KIND_MERCHANDISE_RESTOCK)
            ->where('merchandise_item_id', $itemId)
            ->where('branch_id', $branchId)
            ->whereIn('status', [...WorkTask::OPEN_STATUSES, 'overdue'])
            ->lockForUpdate()
            ->first();
    }

    private function openOrUpdateTask(MerchandiseStockMovement $movement, int $balance): void
    {
        $item = MerchandiseItem::withTrashed()->find($movement->merchandise_item_id);
        $branch = Branch::withTrashed()->find($movement->branch_id);
        if (! $item || ! $branch) {
            return;
        }

        $description = $this->description($item, $branch, $balance, $movement);
        $existing = $this->openTask($item->id, $branch->id);
        if ($existing) {
            $existing->update(['description' => $description]);

            return;
        }

        $admins = BranchStaff::admins();
        $assignee = $admins->first();
        if (! $assignee) {
            return;
        }

        $due = now()->addDays(self::dueDays());
        $title = "Nhập bù sách: {$item->name} — {$branch->name}";
        $task = WorkTask::create([
            'title' => $title,
            'description' => $description,
            'creator_id' => $assignee->id,
            'assignee_id' => $assignee->id,
            'branch_id' => $branch->id,
            'merchandise_item_id' => $item->id,
            'task_type' => 'one_time',
            'kind' => WorkTask::KIND_MERCHANDISE_RESTOCK,
            'due_date' => $due->toDateString(),
            'due_time' => '17:00',
            'status' => 'new',
        ]);

        foreach ($admins as $admin) {
            AdminNotification::create([
                'user_id' => $admin->id,
                'type' => 'task_assigned',
                'title' => 'Việc mới: '.$title,
                'message' => ($balance < 0 ? 'Kho âm '.abs($balance) : 'Kho hết')." {$item->unit} tại {$branch->name}. Hạn ".$task->dueAt()?->format('H:i d/m/Y').'.',
                'data' => ['task_id' => $task->id, 'link' => route('merchandise.stock.index', ['branch_id' => $branch->id])],
                'is_read' => false,
            ]);
        }
    }

    private function closeOpenTask(MerchandiseStockMovement $movement, int $balance): void
    {
        $task = $this->openTask($movement->merchandise_item_id, $movement->branch_id);
        $task?->update([
            'status' => 'completed',
            'completed_at' => now(),
            'completion_note' => "Tự hoàn thành: chi nhánh đã nhập kho, tồn hiện tại {$balance}.",
        ]);
    }

    private function description(MerchandiseItem $item, Branch $branch, int $balance, MerchandiseStockMovement $movement): string
    {
        $state = $balance < 0
            ? 'đang âm '.abs($balance)." {$item->unit} (đã giao cho học viên nhiều hơn số có trong kho)"
            : 'đã hết (còn 0)';
        $source = $movement->receipt?->receipt_number ? " sau khi duyệt phiếu thu {$movement->receipt->receipt_number}" : '';

        return "Tồn kho [{$item->code}] {$item->name} tại {$branch->name} {$state}{$source}. "
            .'Nhập sách bù cho chi nhánh ở Tồn kho theo chi nhánh → Nhập kho; việc tự hoàn thành khi tồn lên trên 0.';
    }
}
