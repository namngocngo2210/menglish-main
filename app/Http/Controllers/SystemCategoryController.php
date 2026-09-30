<?php

namespace App\Http\Controllers;

use App\Http\Concerns\RendersModals;
use App\Http\Requests\SystemCategoryRequest;
use App\Models\SystemCategory;
use App\Support\Audit;
use App\Support\Ui;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Quản lý Danh mục hệ thống (mockup epic-5/quan-ly-danh-muc-he-thong): 4 tab, bảng + panel
 * "Thêm giá trị mới / Sửa" bên phải trên cùng trang, ngừng dùng / kích hoạt lại.
 * Thêm/Sửa từ danh sách mở modal; mở thẳng URL → trang thêm riêng / modal sửa mở sẵn trên danh sách (?edit=).
 */
class SystemCategoryController extends Controller
{
    use RendersModals;

    public function index(Request $request): InertiaResponse
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

        return Inertia::render('SystemCategories/Index', [
            'categories' => $categories->through(fn (SystemCategory $category) => $this->categoryData($category)),
            'type' => $type,
            'types' => Ui::options(collect(SystemCategory::TYPES)->mapWithKeys(fn (string $t) => [$t => SystemCategory::TYPE_LABELS[$t] ?? $t])),
            'editing' => $editing ? $this->categoryData($editing) : null,
            'search' => $search,
            'canManage' => $request->user()->can('system_category.manage'),
        ]);
    }

    public function create(): InertiaResponse
    {
        $type = $this->validType(request('type'));

        return $this->modalPage('SystemCategories/Form', [
            'category' => ['type' => $type, 'code' => SystemCategory::suggestCode($type)],
            'typeLabels' => Ui::options(SystemCategory::TYPE_LABELS),
            'nextOrder' => (int) SystemCategory::query()->ofType($type)->max('sort_order') + 1,
        ]);
    }

    public function store(SystemCategoryRequest $request): Response|RedirectResponse
    {
        Audit::describe('Tạo danh mục hệ thống');
        $category = SystemCategory::create([
            ...$request->validated(),
            'sort_order' => $request->validated('sort_order') ?? ((int) SystemCategory::query()->ofType($request->validated('type'))->max('sort_order') + 1),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return $this->modalSaved("Đã thêm danh mục \"{$category->name}\".",
            route('system-categories.index', ['type' => $category->type]));
    }

    public function edit(SystemCategory $systemCategory): InertiaResponse|RedirectResponse
    {
        if ($this->isModalRequest()) {
            return $this->modalPage('SystemCategories/Form', [
                'category' => $this->categoryData($systemCategory),
                'typeLabels' => Ui::options(SystemCategory::TYPE_LABELS),
            ]);
        }

        // Mở thẳng URL: trang danh sách với modal sửa mở sẵn (?edit=).
        return redirect()->route('system-categories.index', ['type' => $systemCategory->type, 'edit' => $systemCategory->id]);
    }

    public function update(SystemCategoryRequest $request, SystemCategory $systemCategory): Response|RedirectResponse
    {
        Audit::describe('Cập nhật danh mục hệ thống');
        $systemCategory->update([
            ...$request->validated(),
            'sort_order' => $request->validated('sort_order') ?? $systemCategory->sort_order,
            'is_active' => $request->boolean('is_active'),
        ]);

        return $this->modalSaved('Đã cập nhật danh mục.',
            route('system-categories.index', ['type' => $systemCategory->type]));
    }

    public function destroy(SystemCategory $systemCategory): Response|RedirectResponse
    {
        $type = $systemCategory->type;
        Audit::describe('Ngừng sử dụng danh mục hệ thống');
        $systemCategory->update(['is_active' => false]);

        return $this->modalSaved("Đã ngừng sử dụng \"{$systemCategory->name}\".",
            route('system-categories.index', ['type' => $type]));
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

    /** @return array<string, mixed> */
    private function categoryData(SystemCategory $category): array
    {
        return [
            ...$category->only(['id', 'type', 'code', 'name', 'sort_order']),
            'is_active' => (bool) $category->is_active,
        ];
    }

    private function validType(mixed $type): string
    {
        return in_array($type, SystemCategory::TYPES, true) ? $type : SystemCategory::TYPE_LEAD_SOURCE;
    }
}
