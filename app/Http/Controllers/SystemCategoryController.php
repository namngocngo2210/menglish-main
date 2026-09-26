<?php

namespace App\Http\Controllers;

use App\Http\Requests\SystemCategoryRequest;
use App\Models\SystemCategory;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Quản lý Danh mục hệ thống (mockup epic-5/quan-ly-danh-muc-he-thong): 4 tab, bảng + panel
 * "Thêm giá trị mới / Sửa" bên phải trên cùng trang, ngừng dùng / kích hoạt lại.
 */
class SystemCategoryController extends Controller
{
    public function index(Request $request): View
    {
        $type = $this->validType($request->query('type'));
        $search = trim((string) $request->query('q', ''));

        $categories = SystemCategory::query()->ofType($type)
            ->when($search !== '', fn ($q) => $q->where(fn ($s) => $s->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")))
            ->orderBy('sort_order')->orderBy('id')
            ->paginate($request->perPage(20))->withQueryString();

        $editing = $request->filled('edit')
            ? SystemCategory::query()->ofType($type)->find($request->integer('edit'))
            : null;

        return view('system-categories.index', [
            'categories' => $categories,
            'type' => $type,
            'types' => SystemCategory::TYPES,
            'typeLabels' => SystemCategory::TYPE_LABELS,
            'editing' => $editing,
            'suggestedCode' => SystemCategory::suggestCode($type),
            'nextOrder' => (int) SystemCategory::query()->ofType($type)->max('sort_order') + 1,
            'search' => $search,
        ]);
    }

    public function create(): View
    {
        $type = $this->validType(request('type'));

        return view('system-categories.form', [
            'category' => new SystemCategory(['type' => $type, 'code' => SystemCategory::suggestCode($type)]),
            'types' => SystemCategory::TYPES,
            'typeLabels' => SystemCategory::TYPE_LABELS,
        ]);
    }

    public function store(SystemCategoryRequest $request): RedirectResponse
    {
        Audit::describe('Tạo danh mục hệ thống');
        $category = SystemCategory::create([
            ...$request->validated(),
            'sort_order' => $request->validated('sort_order') ?? ((int) SystemCategory::query()->ofType($request->validated('type'))->max('sort_order') + 1),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return redirect()->route('system-categories.index', ['type' => $category->type])->with('status', "Đã thêm danh mục \"{$category->name}\".");
    }

    public function edit(SystemCategory $systemCategory): RedirectResponse
    {
        // Sửa trên panel bên phải của trang danh sách (mockup).
        return redirect()->route('system-categories.index', ['type' => $systemCategory->type, 'edit' => $systemCategory->id]);
    }

    public function update(SystemCategoryRequest $request, SystemCategory $systemCategory): RedirectResponse
    {
        Audit::describe('Cập nhật danh mục hệ thống');
        $systemCategory->update([
            ...$request->validated(),
            'sort_order' => $request->validated('sort_order') ?? $systemCategory->sort_order,
            'is_active' => $request->boolean('is_active'),
        ]);

        return redirect()->route('system-categories.index', ['type' => $systemCategory->type])->with('status', 'Đã cập nhật danh mục.');
    }

    public function destroy(SystemCategory $systemCategory): RedirectResponse
    {
        $type = $systemCategory->type;
        Audit::describe('Ngừng sử dụng danh mục hệ thống');
        $systemCategory->update(['is_active' => false]);

        return redirect()->route('system-categories.index', ['type' => $type])->with('status', "Đã ngừng sử dụng \"{$systemCategory->name}\".");
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

    private function validType(mixed $type): string
    {
        return in_array($type, SystemCategory::TYPES, true) ? $type : SystemCategory::TYPE_LEAD_SOURCE;
    }
}
