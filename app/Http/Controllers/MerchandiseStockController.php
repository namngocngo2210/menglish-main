<?php

namespace App\Http\Controllers;

use App\Http\Concerns\RendersModals;
use App\Http\Concerns\ScopesMerchandiseBranches;
use App\Models\Branch;
use App\Models\MerchandiseItem;
use App\Models\MerchandiseStockMovement;
use App\Services\Merchandise\StockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Response as InertiaResponse;

/**
 * Tồn kho sách / hàng hóa theo chi nhánh: bảng tồn nằm ở trang Hàng hóa & tồn kho (MerchandiseItemController::index),
 * controller này giữ modal Nhập kho / Kiểm kê / Nhật ký (trang Vue Merchandise/Stock*).
 * - Xuất kho tự động khi phiếu thu được duyệt, hoàn kho khi hủy hóa đơn (App\Services\Merchandise\StockService).
 * - Nhập kho / kiểm kê: modal, chỉ chi nhánh trong phạm vi dữ liệu `merchandise_stock`.
 * - "Tồn cũ chưa phân chi nhánh": số tồn chung trước khi có kho theo chi nhánh, phân bổ dần khi nhập kho.
 */
class MerchandiseStockController extends Controller
{
    use RendersModals, ScopesMerchandiseBranches;

    public function __construct(private readonly StockService $stock) {}

    /** Trang cũ "Tồn kho theo chi nhánh" đã gộp vào trang Hàng hóa & tồn kho: giữ link cũ (thông báo, bookmark). */
    public function index(Request $request): RedirectResponse
    {
        return redirect()->route('merchandise.index', $request->only(['branch_id', 'q', 'category', 'level']));
    }

    /** Modal Nhập kho / Kiểm kê (type=import|count), chọn sẵn mặt hàng + chi nhánh từ dòng đang bấm. */
    public function create(Request $request): InertiaResponse
    {
        $branches = $this->branches($request);
        abort_if($branches->isEmpty(), 403, 'Tài khoản chưa được gán chi nhánh.');
        $items = MerchandiseItem::active()->orderBy('category')->orderBy('name')->get();

        return $this->modalPage('Merchandise/StockForm', [
            'type' => $request->query('type') === MerchandiseStockMovement::TYPE_COUNT ? MerchandiseStockMovement::TYPE_COUNT : MerchandiseStockMovement::TYPE_IMPORT,
            'itemId' => $request->integer('item') ?: null,
            'branchId' => $this->pickBranch($request, $branches)?->id,
            'items' => $items->map(fn (MerchandiseItem $item) => [
                'value' => (string) $item->id,
                'label' => "[{$item->code}] {$item->name}",
                'unit' => $item->unit,
                'legacy' => max(0, (int) $item->stock_quantity),
            ])->values()->all(),
            'branches' => $branches->map(fn (Branch $b) => ['value' => (string) $b->id, 'label' => $b->name])->values()->all(),
            'stockByBranch' => (object) $this->stock->quantitiesByBranch($branches->pluck('id')->all()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'type' => 'required|in:import,count',
            'merchandise_item_id' => 'required|exists:merchandise_items,id',
            'branch_id' => 'required|exists:branches,id',
            'quantity' => 'required|integer|min:0|max:100000',
            'from_legacy' => 'nullable|boolean',
            'note' => 'nullable|string|max:500',
        ], [
            'merchandise_item_id.required' => 'Chọn mặt hàng.',
            'branch_id.required' => 'Chọn chi nhánh.',
            'quantity.required' => 'Nhập số lượng.',
        ]);

        $branchId = (int) $validated['branch_id'];
        abort_unless($this->branches($request)->contains('id', $branchId), 403, 'Bạn chỉ được nhập kho chi nhánh của mình.');
        $itemId = (int) $validated['merchandise_item_id'];
        $quantity = (int) $validated['quantity'];
        $note = trim((string) ($validated['note'] ?? '')) ?: null;
        $branchName = Branch::whereKey($branchId)->value('name');

        if ($validated['type'] === MerchandiseStockMovement::TYPE_COUNT) {
            $movement = $this->stock->count($itemId, $branchId, $quantity, $note);
            $item = MerchandiseItem::withTrashed()->find($itemId);

            return $this->modalSaved($movement
                ? "Đã kiểm kê {$item->name} tại {$branchName}: tồn {$quantity} (".($movement->quantity_change > 0 ? '+' : '').$movement->quantity_change.').'
                : "Số tồn {$item->name} tại {$branchName} đã khớp ({$quantity}), không cần điều chỉnh.",
                route('merchandise.index', ['branch_id' => $branchId]));
        }

        if ($quantity < 1) {
            throw ValidationException::withMessages(['quantity' => 'Số lượng nhập phải lớn hơn 0.']);
        }

        $item = DB::transaction(function () use ($request, $itemId, $branchId, $quantity, $note) {
            $item = MerchandiseItem::withTrashed()->lockForUpdate()->findOrFail($itemId);
            $fromLegacy = $request->boolean('from_legacy');
            if ($fromLegacy) {
                if ($quantity > (int) $item->stock_quantity) {
                    throw ValidationException::withMessages(['quantity' => "Tồn cũ chưa phân chi nhánh của {$item->name} chỉ còn {$item->stock_quantity}."]);
                }
                $item->decrement('stock_quantity', $quantity);
            }

            $this->stock->adjust($itemId, $branchId, $quantity, MerchandiseStockMovement::TYPE_IMPORT, [
                'note' => $fromLegacy ? trim('Phân bổ từ tồn cũ chưa phân chi nhánh. '.$note) : $note,
            ]);

            return $item;
        });

        return $this->modalSaved("Đã nhập {$quantity} {$item->unit} {$item->name} vào kho {$branchName}.", route('merchandise.index', ['branch_id' => $branchId]));
    }

    /** Modal nhật ký xuất nhập của một mặt hàng tại chi nhánh (bấm dòng ở trang Hàng hóa & tồn kho). */
    public function history(Request $request, int $item): InertiaResponse
    {
        $branches = $this->branches($request);
        $branch = $this->pickBranch($request, $branches);
        abort_unless($branch, 403, 'Tài khoản chưa được gán chi nhánh.');
        $merchandise = MerchandiseItem::withTrashed()->findOrFail($item);

        $movements = MerchandiseStockMovement::with(['item', 'receipt', 'user'])
            ->where('branch_id', $branch->id)
            ->where('merchandise_item_id', $merchandise->id)
            ->latest('id')
            ->limit(100)
            ->get();

        return $this->modalPage('Merchandise/StockHistory', [
            'item' => ['id' => $merchandise->id, 'code' => $merchandise->code, 'name' => $merchandise->name, 'unit' => $merchandise->unit],
            'branch' => ['id' => $branch->id, 'name' => $branch->name],
            'quantity' => $this->stock->quantities([$merchandise->id], $branch->id)[$merchandise->id],
            'movements' => self::movementRows($movements),
        ]);
    }

    /** Dòng nhật ký xuất nhập cho bảng (dùng chung trang Hàng hóa & tồn kho). */
    public static function movementRows(Collection $movements): array
    {
        return $movements->map(fn (MerchandiseStockMovement $m) => [
            'id' => $m->id,
            'date' => $m->created_at?->format('H:i d/m/Y'),
            'item_name' => $m->item?->name,
            'type' => $m->type,
            'type_label' => $m->type_label,
            'change' => (int) $m->quantity_change,
            'balance' => (int) $m->balance_after,
            'receipt_number' => $m->receipt?->receipt_number,
            'receipt_id' => $m->tuition_receipt_id,
            'user_name' => $m->user?->name,
            'note' => $m->note,
        ])->values()->all();
    }
}
