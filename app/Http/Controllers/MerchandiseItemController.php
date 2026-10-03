<?php

namespace App\Http\Controllers;

use App\Http\Concerns\RendersModals;
use App\Models\Branch;
use App\Models\MerchandiseItem;
use App\Models\MerchandiseStock;
use App\Models\MerchandiseStockMovement;
use App\Services\Merchandise\StockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
    use RendersModals;

    public function index(Request $request): InertiaResponse
    {
        $search = $request->query('q');
        $category = $request->query('category');
        $status = $request->query('status');

        $query = MerchandiseItem::query()
            ->search($search)
            ->category($category);

        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }

        $items = $query->orderBy('category')->orderBy('name')->paginate(15)->withQueryString();
        $branchStock = MerchandiseStock::query()
            ->whereIn('merchandise_item_id', $items->getCollection()->pluck('id'))
            ->selectRaw('merchandise_item_id, SUM(quantity) as total')
            ->groupBy('merchandise_item_id')
            ->pluck('total', 'merchandise_item_id');

        $metrics = [
            'total' => MerchandiseItem::count(),
            'active' => MerchandiseItem::where('is_active', true)->count(),
            'total_stock' => (int) MerchandiseStock::sum('quantity') + (int) MerchandiseItem::where('stock_quantity', '>', 0)->sum('stock_quantity'),
            'books' => MerchandiseItem::whereIn('category', [MerchandiseItem::CATEGORY_BOOK, MerchandiseItem::CATEGORY_WORKBOOK])->count(),
            'uniforms' => MerchandiseItem::whereIn('category', [MerchandiseItem::CATEGORY_UNIFORM, MerchandiseItem::CATEGORY_BACKPACK])->count(),
        ];

        return Inertia::render('Merchandise/Index', [
            'items' => $items->through(fn (MerchandiseItem $item) => [
                'id' => $item->id,
                'code' => $item->code,
                'name' => $item->name,
                'description' => $item->description,
                'category_label' => $item->category_meta['label'],
                'category_icon' => $item->category_meta['icon'],
                'unit' => $item->unit,
                'price' => (float) $item->price,
                // Tổng tồn các chi nhánh + tồn cũ chưa phân chi nhánh.
                'stock_quantity' => (int) ($branchStock[$item->id] ?? 0) + max(0, (int) $item->stock_quantity),
                'is_active' => (bool) $item->is_active,
            ]),
            'metrics' => [
                'total' => (int) $metrics['total'],
                'active' => (int) $metrics['active'],
                'total_stock' => (int) $metrics['total_stock'],
                'books' => (int) $metrics['books'],
                'uniforms' => (int) $metrics['uniforms'],
            ],
            'categories' => $this->categoryOptions(),
            'selectedCategory' => $category,
            'selectedStatus' => $status,
            'search' => $search,
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
            app(StockService::class)->adjust($item->id, $stockBranchId, $initialStock, MerchandiseStockMovement::TYPE_OPENING, ['note' => 'Tồn ban đầu khi thêm mặt hàng']);
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

        // Số tồn đổi qua trang Tồn kho theo chi nhánh (nhập kho / kiểm kê), không sửa ở đây.
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
