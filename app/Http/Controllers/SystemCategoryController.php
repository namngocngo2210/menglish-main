<?php

namespace App\Http\Controllers;

use App\Http\Requests\SystemCategoryRequest;
use App\Models\SystemCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class SystemCategoryController extends Controller
{
    public function index(): View
    {
        $type = request('type', SystemCategory::TYPE_LEAD_SOURCE);

        $categories = SystemCategory::query()->ofType($type)->orderBy('sort_order')->paginate(request()->perPage(20))->withQueryString();

        return view('system-categories.index', [
            'categories' => $categories,
            'type' => $type,
            'types' => SystemCategory::TYPES,
        ]);
    }

    public function create(): View
    {
        return view('system-categories.form', [
            'category' => new SystemCategory(['type' => request('type', SystemCategory::TYPE_LEAD_SOURCE)]),
            'types' => SystemCategory::TYPES,
        ]);
    }

    public function store(SystemCategoryRequest $request): RedirectResponse
    {
        $category = SystemCategory::create([
            ...$request->validated(),
            'is_active' => $request->boolean('is_active', true),
        ]);

        activity('system_category')->causedBy(auth()->user())->performedOn($category)->log('Tạo danh mục hệ thống');

        return redirect()->route('system-categories.index', ['type' => $category->type])->with('status', 'Đã thêm danh mục.');
    }

    public function edit(SystemCategory $systemCategory): View
    {
        return view('system-categories.form', [
            'category' => $systemCategory,
            'types' => SystemCategory::TYPES,
        ]);
    }

    public function update(SystemCategoryRequest $request, SystemCategory $systemCategory): RedirectResponse
    {
        $systemCategory->update([
            ...$request->validated(),
            'is_active' => $request->boolean('is_active'),
        ]);

        activity('system_category')->causedBy(auth()->user())->performedOn($systemCategory)->log('Cập nhật danh mục hệ thống');

        return redirect()->route('system-categories.index', ['type' => $systemCategory->type])->with('status', 'Đã cập nhật danh mục.');
    }

    public function destroy(SystemCategory $systemCategory): RedirectResponse
    {
        $type = $systemCategory->type;
        $systemCategory->update(['is_active' => false]);

        activity('system_category')->causedBy(auth()->user())->performedOn($systemCategory)->log('Ngừng sử dụng danh mục hệ thống');

        return redirect()->route('system-categories.index', ['type' => $type])->with('status', 'Đã ngừng sử dụng danh mục.');
    }
}
