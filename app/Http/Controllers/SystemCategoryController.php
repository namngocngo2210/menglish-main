<?php

namespace App\Http\Controllers;

use App\Http\Requests\SystemCategoryRequest;
use App\Models\SystemCategory;
use App\Support\Audit;
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
        Audit::describe('Tạo danh mục hệ thống');
        $category = SystemCategory::create([
            ...$request->validated(),
            'is_active' => $request->boolean('is_active', true),
        ]);

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
        Audit::describe('Cập nhật danh mục hệ thống');
        $systemCategory->update([
            ...$request->validated(),
            'is_active' => $request->boolean('is_active'),
        ]);

        return redirect()->route('system-categories.index', ['type' => $systemCategory->type])->with('status', 'Đã cập nhật danh mục.');
    }

    public function destroy(SystemCategory $systemCategory): RedirectResponse
    {
        $type = $systemCategory->type;
        Audit::describe('Ngừng sử dụng danh mục hệ thống');
        $systemCategory->update(['is_active' => false]);

        return redirect()->route('system-categories.index', ['type' => $type])->with('status', 'Đã ngừng sử dụng danh mục.');
    }

    /**
     * "Kích hoạt lại" danh mục đã ngừng sử dụng.
     */
    public function reactivate(SystemCategory $systemCategory): RedirectResponse
    {
        if ($systemCategory->is_active) {
            return back()->with('status', 'Danh mục đang được sử dụng.');
        }

        Audit::describe('Kích hoạt lại danh mục hệ thống');
        $systemCategory->update(['is_active' => true]);

        return redirect()->route('system-categories.index', ['type' => $systemCategory->type])
            ->with('status', "Đã kích hoạt lại danh mục \"{$systemCategory->name}\".");
    }
}
