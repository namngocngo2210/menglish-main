<?php

namespace App\Http\Controllers;

use App\Http\Concerns\RendersModals;
use App\Http\Concerns\ScopesMerchandiseBranches;
use App\Models\Branch;
use App\Models\MerchandiseItem;
use App\Models\MerchandiseStock;
use App\Models\MerchandiseStockMovement;
use App\Services\Merchandise\StockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Danh mục Hàng hóa & Vật phẩm (trang Vue Merchandise/*). Thêm/Sửa từ danh sách mở modal, Xóa qua modal xác nhận;
 * mở thẳng URL create/edit → trang form đầy đủ như cũ.
 * Tồn kho quản lý theo chi nhánh (MerchandiseStockController): form thêm mới chỉ nhận tồn ban đầu của một chi nhánh,
 * form sửa không đổi số tồn. stock_quantity của mặt hàng = tồn cũ chưa phân chi nhánh.
 */
class MerchandiseItemController extends Controller
{
    use RendersModals, ScopesMerchandiseBranches;

    private const LEVEL_FILTERS = ['negative', 'out', 'low'];

    private const PER_PAGE = 15;

    public function __construct(private readonly StockService $stock) {}

    /**
     * Trang Hàng hóa & tồn kho (gộp danh mục + tồn kho theo chi nhánh, 27/10/2026).
     * - Admin (system_category.manage): thêm / sửa / xóa / bật tắt mặt hàng, thấy cả tổng tồn mọi chi nhánh.
     * - Học vụ / Quản lý cơ sở / Kế toán (merchandise_stock.view): xem giá, tồn chi nhánh mình, nhập kho / kiểm kê.
     */
    public function index(Request $request): InertiaResponse
    {
        $user = $request->user();
        $canCatalog = $user->can('system_category.manage');
        $canViewStock = $user->can('merchandise_stock.view');
        abort_unless($canCatalog || $canViewStock, 403);

        $search = trim((string) $request->query('q', ''));
        $category = $request->query('category');
        $status = $canCatalog && in_array($request->query('status'), ['active', 'inactive'], true) ? $request->query('status') : null;
        $level = in_array($request->query('level'), self::LEVEL_FILTERS, true) ? $request->query('level') : null;

        $branches = $canViewStock ? $this->branches($request) : collect();
        $branch = $this->pickBranch($request, $branches);

        $items = MerchandiseItem::query()
            ->search($search)
            ->category($category)
            ->when($status, fn ($q) => $q->where('is_active', $status === 'active'))
            ->orderBy('category')
            ->orderBy('name')
            ->get();
        $quantities = $branch ? $this->stock->quantities($items->pluck('id'), $branch->id) : [];
        $branchStock = $canCatalog
            ? MerchandiseStock::query()->selectRaw('merchandise_item_id, SUM(quantity) as total')->groupBy('merchandise_item_id')->pluck('total', 'merchandise_item_id')
            : collect();

        $rows = $items
            // Người không quản lý danh mục: mặt hàng ngừng bán chỉ hiện khi chi nhánh còn tồn (khác 0) để không mất dấu số tồn.
            ->filter(fn (MerchandiseItem $item) => $canCatalog || $item->is_active || ($quantities[$item->id] ?? 0) !== 0)
            ->map(function (MerchandiseItem $item) use ($quantities, $branch, $branchStock) {
                $quantity = $quantities[$item->id] ?? 0;

                return [
                    'id' => $item->id,
                    'code' => $item->code,
                    'name' => $item->name,
                    'description' => $item->description,
                    'category_label' => $item->category_meta['label'],
                    'category_icon' => $item->category_meta['icon'],
                    'unit' => $item->unit,
                    'price' => (float) $item->price,
                    'quantity' => $quantity,
                    'level' => $branch ? MerchandiseStock::level($quantity) : null,
                    'legacy' => max(0, (int) $item->stock_quantity),
                    // Tổng tồn các chi nhánh + tồn cũ chưa phân chi nhánh (chỉ Admin).
                    'total_stock' => (int) ($branchStock[$item->id] ?? 0) + max(0, (int) $item->stock_quantity),
                    'is_active' => (bool) $item->is_active,
                ];
            })
            ->values();

        $counts = collect(self::LEVEL_FILTERS)->mapWithKeys(fn (string $l) => [$l => $rows->where('level', $l)->count()])->all();
        $filtered = $level ? $rows->where('level', $level)->values() : $rows;
        $page = max(1, $request->integer('page', 1));
        $paginator = new LengthAwarePaginator($filtered->forPage($page, self::PER_PAGE)->values(), $filtered->count(), self::PER_PAGE, $page, [
            'path' => $request->url(),
            'query' => $request->query(),
        ]);

        $movements = $branch
            ? MerchandiseStockMovement::with(['item', 'receipt', 'user'])->where('branch_id', $branch->id)->latest('id')->limit(15)->get()
            : collect();

        return Inertia::render('Merchandise/Index', [
            'items' => $paginator,
            'metrics' => [
                'total' => MerchandiseItem::count(),
                'active' => MerchandiseItem::where('is_active', true)->count(),
                'books' => MerchandiseItem::whereIn('category', [MerchandiseItem::CATEGORY_BOOK, MerchandiseItem::CATEGORY_WORKBOOK])->count(),
                'uniforms' => MerchandiseItem::whereIn('category', [MerchandiseItem::CATEGORY_UNIFORM, MerchandiseItem::CATEGORY_BACKPACK])->count(),
                'branch_stock' => (int) $rows->sum('quantity'),
                'need_restock' => array_sum($counts),
            ],
            'counts' => $counts,
            'legacyTotal' => (int) $rows->sum('legacy'),
            'branches' => $branches->map(fn (Branch $b) => ['value' => (string) $b->id, 'label' => $b->name])->values()->all(),
            'branch' => $branch ? ['id' => $branch->id, 'name' => $branch->name] : null,
            'movements' => MerchandiseStockController::movementRows($movements),
            'categories' => $this->categoryOptions(),
            'filters' => ['q' => $search, 'category' => $category, 'status' => $status, 'level' => $level],
            'lowThreshold' => MerchandiseStock::LOW_THRESHOLD,
            'canCatalog' => $canCatalog,
            'canManageStock' => $canViewStock && $user->can('merchandise_stock.manage'),
        ]);
    }

    public function create(): InertiaResponse
    {
        return $this->formPage(new MerchandiseItem([
            'category' => MerchandiseItem::CATEGORY_BOOK,
            'unit' => 'Bộ',
            'price' => 0,
            'stock_quantity' => 0,
            'is_active' => true,
        ]));
    }

    public function store(Request $request): Response|RedirectResponse
    {
        $validated = $request->validate([
            'code' => 'required|string|max:50|unique:merchandise_items,code',
            'name' => 'required|string|max:255',
            'category' => 'required|string|in:'.implode(',', array_keys(MerchandiseItem::CATEGORIES)),
            'unit' => 'required|string|max:50',
            'price' => 'required|numeric|min:0',
            'cost_price' => 'nullable|numeric|min:0',
            'stock_quantity' => 'nullable|integer|min:0',
            'stock_branch_id' => 'nullable|exists:branches,id',
            'is_active' => 'nullable|boolean',
            'description' => 'nullable|string|max:1000',
        ], [
            'code.required' => 'Mã hàng hóa không được để trống.',
            'code.unique' => 'Mã hàng hóa này đã tồn tại trong hệ thống.',
            'name.required' => 'Tên hàng hóa không được để trống.',
            'price.required' => 'Đơn giá niêm yết là bắt buộc.',
        ]);

        $validated['is_active'] = $request->boolean('is_active', true);
        $initialStock = (int) ($validated['stock_quantity'] ?? 0);
        $stockBranchId = isset($validated['stock_branch_id']) ? (int) $validated['stock_branch_id'] : null;
        unset($validated['stock_branch_id']);
        // Có chọn chi nhánh → tồn ban đầu nhập vào kho chi nhánh đó; không chọn → tồn chưa phân chi nhánh.
        $validated['stock_quantity'] = $stockBranchId ? 0 : $initialStock;

        $item = MerchandiseItem::create($validated);
        if ($stockBranchId && $initialStock > 0) {
            $this->stock->adjust($item->id, $stockBranchId, $initialStock, MerchandiseStockMovement::TYPE_OPENING, ['note' => 'Tồn ban đầu khi thêm mặt hàng']);
        }

        if (function_exists('activity')) {
            activity('merchandise_item')->causedBy(auth()->user())->performedOn($item)->log('Tạo mới hàng hóa: '.$item->name);
        }

        return $this->modalSaved("Đã thêm thành công mặt hàng [{$item->code}] {$item->name}!", route('merchandise.index'));
    }

    public function edit(MerchandiseItem $merchandise): InertiaResponse
    {
        return $this->formPage($merchandise);
    }

    /** Form Thêm/Sửa: mở từ danh sách → modal; mở thẳng URL → trang form đầy đủ. */
    private function formPage(MerchandiseItem $item): InertiaResponse
    {
        return $this->modalPage('Merchandise/Form', [
            'item' => [
                'id' => $item->id,
                'code' => $item->code,
                'name' => $item->name,
                'category' => $item->category,
                'unit' => $item->unit,
                'price' => (int) $item->price,
                'cost_price' => $item->cost_price ? (int) $item->cost_price : null,
                'stock_quantity' => (int) ($item->stock_quantity ?? 0),
                'is_active' => (bool) ($item->is_active ?? true),
                'description' => $item->description,
            ],
            'categories' => $this->categoryOptions(),
            'branches' => Branch::orderBy('name')->get(['id', 'name'])->map(fn (Branch $b) => ['value' => (string) $b->id, 'label' => $b->name])->values()->all(),
            'isEdit' => $item->exists,
        ]);
    }

    /**
     * @return list<array{value: string, label: string, icon: string}>
     */
    private function categoryOptions(): array
    {
        return collect(MerchandiseItem::CATEGORIES)
            ->map(fn (array $cat, string $key) => ['value' => $key, 'label' => $cat['label'], 'icon' => $cat['icon']])
            ->values()
            ->all();
    }

    public function update(Request $request, MerchandiseItem $merchandise): Response|RedirectResponse
    {
        $validated = $request->validate([
            'code' => 'required|string|max:50|unique:merchandise_items,code,'.$merchandise->id,
            'name' => 'required|string|max:255',
            'category' => 'required|string|in:'.implode(',', array_keys(MerchandiseItem::CATEGORIES)),
            'unit' => 'required|string|max:50',
            'price' => 'required|numeric|min:0',
            'cost_price' => 'nullable|numeric|min:0',
            'is_active' => 'nullable|boolean',
            'description' => 'nullable|string|max:1000',
        ], [
            'code.required' => 'Mã hàng hóa không được để trống.',
            'code.unique' => 'Mã hàng hóa này đã tồn tại.',
            'name.required' => 'Tên hàng hóa không được để trống.',
            'price.required' => 'Đơn giá niêm yết là bắt buộc.',
        ]);

        // Số tồn đổi qua nút Nhập kho / Kiểm kê của trang Hàng hóa & tồn kho, không sửa ở đây.
        $validated['is_active'] = $request->boolean('is_active', true);

        $merchandise->update($validated);

        if (function_exists('activity')) {
            activity('merchandise_item')->causedBy(auth()->user())->performedOn($merchandise)->log('Cập nhật hàng hóa: '.$merchandise->name);
        }

        return $this->modalSaved("Đã cập nhật thông tin mặt hàng [{$merchandise->code}] {$merchandise->name}!", route('merchandise.index'));
    }

    public function toggleStatus(MerchandiseItem $merchandise): RedirectResponse
    {
        $merchandise->is_active = ! $merchandise->is_active;
        $merchandise->save();

        $statusText = $merchandise->is_active ? 'Kích hoạt kinh doanh' : 'Tạm ngừng kinh doanh';

        return back()->with('status', "Đã {$statusText} mặt hàng {$merchandise->name}!");
    }

    public function destroy(MerchandiseItem $merchandise): Response|RedirectResponse
    {
        $name = $merchandise->name;
        $merchandise->delete();

        return $this->modalSaved("Đã xóa mặt hàng {$name} vào thùng rác.", route('merchandise.index'));
    }

    public function apiList()
    {
        $items = MerchandiseItem::active()
            ->orderBy('category')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'category', 'unit', 'price', 'stock_quantity']);

        return response()->json([
            'success' => true,
            'items' => $items,
        ]);
    }
}
