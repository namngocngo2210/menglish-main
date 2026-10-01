<?php

namespace App\Services;

use App\Models\AdminNotification;
use App\Models\MaterialOrder;
use App\Models\User;
use App\Support\DataScope;
use App\Support\Rbac;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Order học liệu: tạo order (tính hạn, đánh dấu tạo trễ), báo người xử lý, chuyển "Quá hạn" (idempotent).
 */
class MaterialOrderService
{
    public const NOTIFY_NEW = 'material_order_new';

    public const NOTIFY_OVERDUE = 'material_order_overdue';

    public function __construct(private readonly DocumentCodeGenerator $codes) {}

    /**
     * @param  array{branch_id: int, class_id?: int|null, category: string, title: string, description?: string|null, quantity?: int|null, use_date: string}  $data
     */
    public function create(User $requester, array $data, ?Carbon $now = null): MaterialOrder
    {
        $now ??= now();
        $dueAt = MaterialOrder::computeDueAt($data['category'], $data['use_date']);

        $order = MaterialOrder::create([
            'code' => $this->codes->materialOrderCode(),
            'branch_id' => $data['branch_id'],
            'class_id' => $data['class_id'] ?? null,
            'requester_id' => $requester->id,
            'category' => $data['category'],
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'quantity' => $data['quantity'] ?? null,
            'use_date' => $data['use_date'],
            'due_at' => $dueAt,
            'status' => MaterialOrder::STATUS_PENDING,
            // Tạo khi đã quá hạn vẫn nhận, chỉ gắn cờ để cảnh báo / hiện badge "Tạo trễ".
            'created_late' => $dueAt->lt($now),
        ]);

        $this->notifyProcessors(
            $order,
            self::NOTIFY_NEW,
            "Order học liệu mới: {$order->title}",
            "{$order->code} · ".MaterialOrder::CATEGORIES[$order->category].' · hạn '.$order->due_at->format('H:i d/m/Y').($order->created_late ? ' (tạo trễ)' : '').'.',
        );

        return $order;
    }

    /**
     * Người xử lý order: Học vụ / Quản lý có quyền process_ops và phụ trách chi nhánh của order (đạo cụ, in ấn, GVNN);
     * người có quyền process_academic (học liệu học thuật). Super Admin không được báo (vẫn xử lý được khi cần).
     *
     * @return Collection<int, User>
     */
    public function processors(MaterialOrder $order): Collection
    {
        $permission = MaterialOrder::processPermission($order->category);
        $users = Rbac::scopeUsersWithPermission(User::query()->where('is_active', true), $permission)->get();

        return $users
            ->reject(fn (User $user) => $user->isSuperAdmin())
            ->filter(fn (User $user) => ! MaterialOrder::isOpsCategory($order->category)
                || DataScope::coversBranch($user, 'material_order', $order->branch_id))
            ->values();
    }

    private function notifyProcessors(MaterialOrder $order, string $type, string $title, string $message): int
    {
        $count = 0;
        foreach ($this->processors($order) as $user) {
            AdminNotification::create([
                'user_id' => $user->id,
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'data' => ['material_order_id' => $order->id, 'link' => route('material-orders.show', $order->id)],
                'is_read' => false,
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * Order Chờ xử lý / Đang xử lý đã qua hạn → "Quá hạn" + báo người xử lý một lần (chạy lại không đổi gì thêm vì chỉ
     * lấy order chưa quá hạn). Trả về số order được chuyển.
     */
    public function markOverdue(?Carbon $now = null): int
    {
        $now ??= now();
        $count = 0;

        MaterialOrder::query()
            ->whereIn('status', [MaterialOrder::STATUS_PENDING, MaterialOrder::STATUS_PROCESSING])
            ->where('due_at', '<', $now)
            ->orderBy('id')
            ->chunkById(200, function ($orders) use (&$count) {
                foreach ($orders as $order) {
                    $order->update(['status' => MaterialOrder::STATUS_OVERDUE]);
                    $count++;

                    $this->notifyProcessors(
                        $order,
                        self::NOTIFY_OVERDUE,
                        "Order học liệu quá hạn: {$order->title}",
                        "{$order->code} · ".MaterialOrder::CATEGORIES[$order->category].' · hạn '.$order->due_at->format('H:i d/m/Y').' — hãy xử lý sớm.',
                    );
                }
            });

        return $count;
    }

    /** Nhận xử lý: order chờ / quá hạn → Đang xử lý (order quá hạn vẫn nhận được, ghi nhận trễ khi hoàn thành). */
    public function claim(MaterialOrder $order, User $user, ?string $note = null): MaterialOrder
    {
        $order->update([
            'status' => MaterialOrder::STATUS_PROCESSING,
            'processed_by' => $user->id,
            'processor_note' => $note ?: $order->processor_note,
        ]);

        return $order;
    }

    /** Hoàn thành / từ chối: ghi người xử lý, giờ xử lý và cờ xử lý trễ (xử lý sau hạn, kể cả khi đã "Quá hạn"). */
    public function finish(MaterialOrder $order, User $user, string $status, ?string $note = null, ?string $rejectReason = null, ?Carbon $now = null): MaterialOrder
    {
        $now ??= now();
        $order->update([
            'status' => $status,
            'processed_by' => $user->id,
            'processed_at' => $now,
            'processed_late' => $order->due_at->lt($now),
            'processor_note' => $note ?: $order->processor_note,
            'reject_reason' => $status === MaterialOrder::STATUS_REJECTED ? $rejectReason : null,
        ]);

        return $order;
    }
}
