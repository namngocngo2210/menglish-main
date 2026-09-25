<?php

namespace App\Http\Controllers;

use App\Models\MerchandiseItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MerchandiseItemController extends Controller
{
    public function index(Request $request): View
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

        return view('merchandise.index', [
            'items' => $items,
            'metrics' => $metrics,
            'categories' => MerchandiseItem::CATEGORIES,
            'selectedCategory' => $category,
            'selectedStatus' => $status,
            'search' => $search,
        ]);
    }

    public function create(): View
    {
        return view('merchandise.form', [
            'item' => new MerchandiseItem([
                'category' => MerchandiseItem::CATEGORY_BOOK,
                'unit' => 'Bộ',
                'price' => 0,
                'stock_quantity' => 0,
                'is_active' => true,
            ]),
            'categories' => MerchandiseItem::CATEGORIES,
            'isEdit' => false,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => 'required|string|max:50|unique:merchandise_items,code',
            'name' => 'required|string|max:255',
            'category' => 'required|string|in:' . implode(',', array_keys(MerchandiseItem::CATEGORIES)),
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
            activity('merchandise_item')->causedBy(auth()->user())->performedOn($item)->log('Tạo mới hàng hóa: ' . $item->name);
        }

        return redirect()->route('merchandise.index')
            ->with('status', "Đã thêm thành công mặt hàng [{$item->code}] {$item->name}!");
    }

    public function edit(MerchandiseItem $merchandise): View
    {
        return view('merchandise.form', [
            'item' => $merchandise,
            'categories' => MerchandiseItem::CATEGORIES,
            'isEdit' => true,
        ]);
    }

    public function update(Request $request, MerchandiseItem $merchandise): RedirectResponse
    {
        $validated = $request->validate([
            'code' => 'required|string|max:50|unique:merchandise_items,code,' . $merchandise->id,
            'name' => 'required|string|max:255',
            'category' => 'required|string|in:' . implode(',', array_keys(MerchandiseItem::CATEGORIES)),
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
            activity('merchandise_item')->causedBy(auth()->user())->performedOn($merchandise)->log('Cập nhật hàng hóa: ' . $merchandise->name);
        }

        return redirect()->route('merchandise.index')
            ->with('status', "Đã cập nhật thông tin mặt hàng [{$merchandise->code}] {$merchandise->name}!");
    }

    public function toggleStatus(MerchandiseItem $merchandise): RedirectResponse
    {
        $merchandise->is_active = !$merchandise->is_active;
        $merchandise->save();

        $statusText = $merchandise->is_active ? 'Kích hoạt kinh doanh' : 'Tạm ngừng kinh doanh';

        return back()->with('status', "Đã {$statusText} mặt hàng {$merchandise->name}!");
    }

    public function destroy(MerchandiseItem $merchandise): RedirectResponse
    {
        $name = $merchandise->name;
        $merchandise->delete();

        return redirect()->route('merchandise.index')
            ->with('status', "Đã xóa mặt hàng {$name} vào thùng rác.");
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
