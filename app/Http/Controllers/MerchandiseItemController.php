<?php

namespace App\Http\Controllers;

use App\Http\Concerns\RendersModals;
use App\Models\MerchandiseItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Danh mục Hàng hóa & Vật phẩm (trang Vue Merchandise/*). Thêm/Sửa từ danh sách mở modal, Xóa qua modal xác nhận;
 * mở thẳng URL create/edit → trang form đầy đủ như cũ.
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

        $metrics = [
            'total' => MerchandiseItem::count(),
            'active' => MerchandiseItem::where('is_active', true)->count(),
            'total_stock' => MerchandiseItem::sum('stock_quantity'),
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
                'stock_quantity' => (int) $item->stock_quantity,
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
            'stock_quantity' => 'required|integer|min:0',
            'is_active' => 'nullable|boolean',
            'description' => 'nullable|string|max:1000',
        ], [
            'code.required' => 'Mã hàng hóa không được để trống.',
            'code.unique' => 'Mã hàng hóa này đã tồn tại trong hệ thống.',
            'name.required' => 'Tên hàng hóa không được để trống.',
            'price.required' => 'Đơn giá niêm yết là bắt buộc.',
            'stock_quantity.required' => 'Số lượng tồn kho là bắt buộc.',
        ]);

        $validated['is_active'] = $request->boolean('is_active', true);

        $item = MerchandiseItem::create($validated);

        if (function_exists('activity')) {
            activity('merchandise_item')->causedBy(auth()->user())->performedOn($item)->log('Tạo mới hàng hóa: '.$item->name);
        }

        return $this->modalSaved("Đã thêm thành công mặt hàng [{$item->code}] {$item->name}!", 'merchandise-changed', route('merchandise.index'));
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
            'stock_quantity' => 'required|integer|min:0',
            'is_active' => 'nullable|boolean',
            'description' => 'nullable|string|max:1000',
        ], [
            'code.required' => 'Mã hàng hóa không được để trống.',
            'code.unique' => 'Mã hàng hóa này đã tồn tại.',
            'name.required' => 'Tên hàng hóa không được để trống.',
            'price.required' => 'Đơn giá niêm yết là bắt buộc.',
        ]);

        $validated['is_active'] = $request->boolean('is_active', true);

        $merchandise->update($validated);

        if (function_exists('activity')) {
            activity('merchandise_item')->causedBy(auth()->user())->performedOn($merchandise)->log('Cập nhật hàng hóa: '.$merchandise->name);
        }

        return $this->modalSaved("Đã cập nhật thông tin mặt hàng [{$merchandise->code}] {$merchandise->name}!", 'merchandise-changed', route('merchandise.index'));
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

        return $this->modalSaved("Đã xóa mặt hàng {$name} vào thùng rác.", 'merchandise-changed', route('merchandise.index'));
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
