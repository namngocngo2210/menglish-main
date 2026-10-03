<?php

namespace App\Http\Controllers;

use App\Http\Concerns\RendersModals;
use App\Models\Branch;
use App\Models\Course;
use App\Models\Promotion;
use App\Support\Ui;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Danh mục ưu đãi học phí (CRM → Ưu đãi). Ưu tiên dùng lại ưu đãi có sẵn / mặc định khi chốt khách và khi lập phiếu thu;
 * ca đặc biệt tạo "ưu đãi riêng" ngay trong màn chốt (bắt buộc lý do, dùng 1 lần), xem lại ở tab "Ưu đãi riêng".
 */
class PromotionController extends Controller
{
    use RendersModals;

    public function index(Request $request): InertiaResponse
    {
        $tab = $request->query('tab') === 'special' ? 'special' : 'catalog';
        $status = $request->query('status');
        $search = trim((string) $request->query('q', ''));

        $promotions = Promotion::query()
            ->with(['branch:id,name', 'course:id,name', 'creator:id,name'])
            ->where('is_special', $tab === 'special')
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('name', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%")
                ->orWhere('reason', 'like', "%{$search}%")))
            ->when($status === 'active', fn (Builder $q) => $q->available())
            ->when($status === 'inactive', fn (Builder $q) => $q->where('is_active', false))
            ->when($status === 'default', fn (Builder $q) => $q->where('is_default', true))
            ->orderByDesc('is_active')
            ->orderByDesc('is_default')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Promotions/Index', [
            'promotions' => $promotions->through(fn (Promotion $p) => [
                'id' => $p->id,
                'code' => $p->code,
                'name' => $p->name,
                'value_label' => $p->value_label,
                'max_discount_amount' => $p->max_discount_amount !== null ? (float) $p->max_discount_amount : null,
                'scope_label' => collect([$p->branch?->name, $p->course?->name])->filter()->implode(' · ') ?: 'Mọi cơ sở, mọi khóa',
                'period_label' => $this->periodLabel($p),
                'usage_label' => $p->used_count.($p->usage_limit ? ' / '.$p->usage_limit : ''),
                'is_active' => (bool) $p->is_active,
                'is_default' => (bool) $p->is_default,
                'is_available' => $p->isApplicable($p->branch_id, $p->course_id),
                'description' => $p->description,
                'reason' => $p->reason,
                'creator_name' => $p->creator?->name,
                'created_at' => $p->created_at?->format('d/m/Y'),
            ]),
            'tab' => $tab,
            'counts' => [
                'catalog' => Promotion::catalog()->count(),
                'special' => Promotion::where('is_special', true)->count(),
            ],
            'filters' => ['q' => $search, 'status' => $status],
        ]);
    }

    public function create(): InertiaResponse
    {
        return $this->formPage(new Promotion(['type' => 'percent', 'value' => 0, 'is_active' => true]));
    }

    /**
     * Tạo ưu đãi: từ danh mục (modal) hoặc tạo nhanh trong màn Chốt & Xếp lớp (JSON, `is_special` = ca đặc biệt).
     */
    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $this->validatePromotion($request);
        $special = $request->boolean('is_special');

        // Mã ưu đãi: prefix theo tên + hậu tố ngẫu nhiên; promotions.code là UNIQUE nên phải kiểm tra trùng.
        $namePrefix = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', Str::ascii($validated['name'])), 0, 4));
        do {
            $code = 'UD'.$namePrefix.strtoupper(Str::random(3));
        } while (Promotion::where('code', $code)->exists());

        try {
            $promotion = Promotion::create([
                ...$validated,
                'code' => $code,
                // Ưu đãi riêng: chỉ cho 1 khách (1 lượt), không thành mặc định.
                'usage_limit' => $special ? 1 : ($validated['usage_limit'] ?? null),
                'is_default' => ! $special && $request->boolean('is_default'),
                'is_special' => $special,
                'is_active' => $request->has('is_active') ? $request->boolean('is_active') : true,
                'created_by' => Auth::id(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Hai phiên tạo ưu đãi trùng mã cùng lúc: ràng buộc UNIQUE ở DB là chốt chặn cuối.
            throw ValidationException::withMessages(['name' => 'Không tạo được ưu đãi do trùng mã, vui lòng thử lại.']);
        }

        $message = $special
            ? "Đã tạo ưu đãi riêng '{$promotion->name}' cho khách này."
            : "Đã tạo mới ưu đãi '{$promotion->name}' thành công!";

        if ($request->wantsJson() || ($request->ajax() && ! $request->hasHeader('X-Inertia'))) {
            return response()->json([
                'success' => true,
                'message' => $message,
                'promotion' => self::props($promotion),
            ]);
        }

        return $this->modalSaved($message, route('crm.promotions.index', $special ? ['tab' => 'special'] : []));
    }

    public function edit(Promotion $promotion): InertiaResponse
    {
        return $this->formPage($promotion);
    }

    public function update(Request $request, Promotion $promotion): RedirectResponse
    {
        $validated = $this->validatePromotion($request, $promotion);

        $promotion->update([
            ...$validated,
            // Ưu đãi riêng luôn chỉ 1 lượt (form không có ô số lượt cho loại này).
            'usage_limit' => $promotion->is_special ? 1 : $validated['usage_limit'],
            'is_default' => ! $promotion->is_special && $request->boolean('is_default'),
            'is_active' => $request->boolean('is_active'),
        ]);

        return $this->modalSaved("Đã cập nhật ưu đãi '{$promotion->name}'.", route('crm.promotions.index'));
    }

    /** Bật / ngừng áp dụng (không xóa: hợp đồng, phiếu thu cũ vẫn trỏ tới ưu đãi). */
    public function toggle(Promotion $promotion): RedirectResponse
    {
        $promotion->update(['is_active' => ! $promotion->is_active]);

        return back()->with('status', $promotion->is_active
            ? "Đã bật lại ưu đãi '{$promotion->name}'."
            : "Đã ngừng áp dụng ưu đãi '{$promotion->name}'. Hợp đồng đã dùng ưu đãi này không đổi.");
    }

    /**
     * Ưu đãi gửi sang màn Chốt & Xếp lớp / Lập phiếu thu (lọc theo cơ sở / khóa, tính giảm trừ phía trình duyệt).
     *
     * @return array<string, mixed>
     */
    public static function props(Promotion $promotion): array
    {
        return [
            'id' => $promotion->id,
            'name' => $promotion->name,
            'type' => $promotion->type,
            'value' => (float) $promotion->value,
            'max_discount_amount' => $promotion->max_discount_amount !== null ? (float) $promotion->max_discount_amount : null,
            'branch_id' => $promotion->branch_id,
            'course_id' => $promotion->course_id,
            'is_default' => (bool) $promotion->is_default,
            'is_special' => (bool) $promotion->is_special,
            'description' => $promotion->description,
        ];
    }

    /** @return array<string, mixed> */
    private function validatePromotion(Request $request, ?Promotion $promotion = null): array
    {
        $special = $promotion ? $promotion->is_special : $request->boolean('is_special');
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:fixed,percent',
            'value' => 'required|numeric|gt:0',
            'max_discount_amount' => 'nullable|numeric|min:0',
            'description' => 'nullable|string|max:1000',
            'reason' => ($special ? 'required' : 'nullable').'|string|max:1000',
            'branch_id' => 'nullable|exists:branches,id',
            'course_id' => 'nullable|exists:courses,id,deleted_at,NULL',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date',
            'usage_limit' => 'nullable|integer|min:1',
        ], [
            'value.gt' => 'Giá trị ưu đãi phải lớn hơn 0.',
            'reason.required' => 'Ưu đãi riêng cho ca đặc biệt bắt buộc ghi lý do.',
        ]);
        if ($validated['type'] === 'percent' && (float) $validated['value'] > 100) {
            throw ValidationException::withMessages(['value' => 'Ưu đãi phần trăm không được vượt quá 100%.']);
        }
        if (! empty($validated['starts_at']) && ! empty($validated['ends_at'])
            && Carbon::parse($validated['ends_at'])->lte(Carbon::parse($validated['starts_at']))) {
            throw ValidationException::withMessages(['ends_at' => 'Ngày kết thúc ưu đãi phải sau ngày bắt đầu.']);
        }
        if ($promotion && ($validated['usage_limit'] ?? null) && (int) $validated['usage_limit'] < $promotion->used_count) {
            throw ValidationException::withMessages(['usage_limit' => "Ưu đãi đã dùng {$promotion->used_count} lượt, giới hạn không được nhỏ hơn số này."]);
        }

        // Ô trống trong form gửi chuỗi rỗng → lưu null.
        foreach (['max_discount_amount', 'description', 'reason', 'branch_id', 'course_id', 'starts_at', 'ends_at', 'usage_limit'] as $field) {
            $validated[$field] = ($validated[$field] ?? null) === '' ? null : ($validated[$field] ?? null);
        }
        if ($validated['type'] !== 'percent') {
            $validated['max_discount_amount'] = null;
        }

        return $validated;
    }

    private function formPage(Promotion $promotion): InertiaResponse
    {
        return $this->modalPage('Promotions/Form', [
            'promotion' => [
                'id' => $promotion->id,
                'code' => $promotion->code,
                'name' => $promotion->name,
                'type' => $promotion->type,
                'value' => (float) $promotion->value,
                'max_discount_amount' => $promotion->max_discount_amount !== null ? (float) $promotion->max_discount_amount : null,
                'description' => $promotion->description,
                'reason' => $promotion->reason,
                'branch_id' => $promotion->branch_id,
                'course_id' => $promotion->course_id,
                'starts_at' => $promotion->starts_at?->format('Y-m-d\TH:i'),
                'ends_at' => $promotion->ends_at?->format('Y-m-d\TH:i'),
                'usage_limit' => $promotion->usage_limit,
                'used_count' => (int) $promotion->used_count,
                'is_active' => (bool) ($promotion->is_active ?? true),
                'is_default' => (bool) $promotion->is_default,
                'is_special' => (bool) $promotion->is_special,
            ],
            'branches' => Ui::options(Branch::where('is_active', true)->orderBy('name')->get(), 'name'),
            'courses' => Ui::options(Course::where('is_active', true)->orderBy('name')->get(), 'name'),
            'isEdit' => $promotion->exists,
        ]);
    }

    private function periodLabel(Promotion $promotion): string
    {
        $from = $promotion->starts_at?->format('d/m/Y');
        $to = $promotion->ends_at?->format('d/m/Y');

        return match (true) {
            $from && $to => "{$from} – {$to}",
            (bool) $from => "Từ {$from}",
            (bool) $to => "Đến {$to}",
            default => 'Không thời hạn',
        };
    }
}
