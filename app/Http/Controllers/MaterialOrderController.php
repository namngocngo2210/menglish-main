<?php

namespace App\Http\Controllers;

use App\Http\Concerns\RendersModals;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\MaterialOrder;
use App\Models\User;
use App\Services\MaterialOrderService;
use App\Support\DataScope;
use App\Support\Ui;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Order học liệu: giáo viên tạo; Học vụ (đạo cụ / in ấn / GVNN, theo chi nhánh) và Trưởng Học thuật (học liệu học thuật)
 * nhận xử lý / hoàn thành / từ chối. Giáo viên chỉ thấy order của mình. Quy tắc hạn: config/material_orders.php.
 */
class MaterialOrderController extends Controller
{
    use RendersModals;

    public function __construct(private readonly MaterialOrderService $service) {}

    public function index(Request $request): InertiaResponse
    {
        $user = $request->user();
        $this->authorizeAccess($user);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(array_keys(MaterialOrder::STATUSES))],
            'category' => ['nullable', Rule::in(array_keys(MaterialOrder::CATEGORIES))],
            'branch_id' => ['nullable', 'integer'],
        ]);

        $orders = MaterialOrder::query()
            ->visibleTo($user)
            ->with(['branch:id,name', 'requester:id,name', 'classRoom:id,name'])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['category'] ?? null, fn ($q, $v) => $q->where('category', $v))
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where('branch_id', $v))
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where(fn ($s) => $s
                ->where('title', 'like', "%{$v}%")
                ->orWhere('code', 'like', "%{$v}%")))
            ->orderByRaw("case when status in ('overdue') then 0 when status in ('pending','processing') then 1 else 2 end")
            ->orderBy('due_at')
            ->orderByDesc('id')
            ->paginate($request->perPage(15))
            ->withQueryString()
            ->through(fn (MaterialOrder $order) => $this->row($order));

        return Inertia::render('MaterialOrders/Index', [
            'orders' => $orders,
            'statuses' => Ui::options(MaterialOrder::STATUSES),
            'categories' => Ui::options(MaterialOrder::CATEGORIES),
            'branches' => $this->filterBranches($user),
            'canCreate' => $user->can('material_order.create'),
        ]);
    }

    public function create(Request $request): InertiaResponse
    {
        $user = $request->user();
        abort_unless($user->can('material_order.create'), 403);

        $branches = $this->requesterBranches($user);

        return $this->modalPage('MaterialOrders/Create', [
            'categories' => Ui::options(MaterialOrder::CATEGORIES),
            'branches' => Ui::options($branches, 'name'),
            'defaultBranchId' => $branches->contains('id', $user->branch_id) ? $user->branch_id : $branches->first()?->id,
            'classes' => ClassModel::query()->visibleTo($user)->orderBy('name')->get(['id', 'name', 'branch_id'])
                ->map(fn (ClassModel $c) => ['value' => $c->id, 'label' => $c->name, 'branch_id' => $c->branch_id])->values(),
            // Cho form báo hạn ngay khi chọn loại + ngày sử dụng.
            'startOfMonthDay' => (int) config('material_orders.start_of_month_day', 5),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('material_order.create'), 403);

        $branchIds = $this->requesterBranches($user)->pluck('id')->all();
        $data = $request->validate([
            'category' => ['required', Rule::in(array_keys(MaterialOrder::CATEGORIES))],
            'branch_id' => ['required', 'integer', Rule::in($branchIds)],
            'class_id' => ['nullable', 'integer', Rule::exists('classes', 'id')->whereNull('deleted_at')],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'use_date' => ['required', 'date'],
        ]);

        $order = $this->service->create($user, $data);

        $message = "Đã tạo order {$order->code}.";
        if ($order->created_late) {
            // Vẫn nhận order trễ hạn, chỉ cảnh báo.
            return $this->modalSaved($message, route('material-orders.index'))
                ->with('warning', 'Order được tạo trễ: đã quá hạn xử lý ('.$order->due_at->format('H:i d/m/Y').'). Học vụ vẫn tiếp nhận nhưng có thể không kịp.');
        }

        return $this->modalSaved($message, route('material-orders.index'));
    }

    public function show(Request $request, MaterialOrder $materialOrder): InertiaResponse
    {
        $user = $request->user();
        $this->authorizeAccess($user);
        abort_unless(MaterialOrder::query()->visibleTo($user)->whereKey($materialOrder->id)->exists(), 403);

        $materialOrder->load(['branch:id,name', 'requester:id,name', 'classRoom:id,name', 'processor:id,name']);
        $canProcess = $materialOrder->canBeProcessedBy($user);

        return $this->modalPage('MaterialOrders/Show', [
            'order' => [
                ...$this->row($materialOrder),
                'description' => $materialOrder->description,
                'processor_note' => $materialOrder->processor_note,
                'reject_reason' => $materialOrder->reject_reason,
                'processed_by' => $materialOrder->processor?->name,
                'processed_at' => $materialOrder->processed_at?->toIso8601String(),
                'processed_late' => $materialOrder->processed_late,
                'created_at' => $materialOrder->created_at?->toIso8601String(),
            ],
            'actions' => [
                'claim' => $canProcess && in_array($materialOrder->status, [MaterialOrder::STATUS_PENDING, MaterialOrder::STATUS_OVERDUE], true),
                'complete' => $canProcess && $materialOrder->isOpen(),
                'reject' => $canProcess && $materialOrder->isOpen(),
            ],
        ]);
    }

    /** Nhận xử lý. */
    public function claim(Request $request, MaterialOrder $materialOrder): RedirectResponse
    {
        $this->authorizeProcess($request->user(), $materialOrder);
        if (! in_array($materialOrder->status, [MaterialOrder::STATUS_PENDING, MaterialOrder::STATUS_OVERDUE], true)) {
            return $this->modalFailed('Order không còn ở trạng thái chờ xử lý.', 'status');
        }

        $this->service->claim($materialOrder, $request->user());

        return $this->modalSaved('Đã nhận xử lý order.', route('material-orders.index'), 'success');
    }

    /** Hoàn thành (kể cả order đã Quá hạn — ghi nhận xử lý trễ). */
    public function complete(Request $request, MaterialOrder $materialOrder): RedirectResponse
    {
        $this->authorizeProcess($request->user(), $materialOrder);
        if (! $materialOrder->isOpen()) {
            return $this->modalFailed('Order đã được xử lý xong.', 'status');
        }
        $data = $request->validate(['processor_note' => ['nullable', 'string', 'max:2000']]);

        $order = $this->service->finish($materialOrder, $request->user(), MaterialOrder::STATUS_DONE, $data['processor_note'] ?? null);

        return $this->modalSaved('Đã hoàn thành order'.($order->processed_late ? ' (xử lý trễ hạn).' : '.'), route('material-orders.index'), 'success');
    }

    /** Từ chối, bắt buộc có lý do. */
    public function reject(Request $request, MaterialOrder $materialOrder): RedirectResponse
    {
        $this->authorizeProcess($request->user(), $materialOrder);
        if (! $materialOrder->isOpen()) {
            return $this->modalFailed('Order đã được xử lý xong.', 'status');
        }
        $data = $request->validate(['reject_reason' => ['required', 'string', 'max:2000']]);

        $this->service->finish($materialOrder, $request->user(), MaterialOrder::STATUS_REJECTED, null, $data['reject_reason']);

        return $this->modalSaved('Đã từ chối order.', route('material-orders.index'), 'success');
    }

    private function authorizeAccess(User $user): void
    {
        abort_unless(
            $user->canAny(['material_order.create', 'material_order.view_all', 'material_order.process_ops', 'material_order.process_academic']),
            403,
        );
    }

    private function authorizeProcess(User $user, MaterialOrder $order): void
    {
        abort_unless($order->canBeProcessedBy($user), 403);
    }

    /** Chi nhánh người lập được chọn: chi nhánh của mình (Admin: mọi chi nhánh đang hoạt động). */
    private function requesterBranches(User $user)
    {
        $query = Branch::active()->orderBy('name');
        if (! DataScope::isAll($user, 'material_order')) {
            $query->whereIn('id', $user->branchIds());
        }

        return $query->get(['id', 'name']);
    }

    /** Bộ lọc chi nhánh: chỉ chi nhánh người dùng xem được (rỗng khi chỉ thấy order của mình). */
    private function filterBranches(User $user): array
    {
        if (! $user->can('material_order.view_all')) {
            return [];
        }
        $branchIds = DataScope::branchIds($user, 'material_order');

        return Ui::options(Branch::active()->when($branchIds !== null, fn ($q) => $q->whereIn('id', $branchIds))->orderBy('name')->get(['id', 'name']), 'name');
    }

    /** @return array<string, mixed> */
    private function row(MaterialOrder $order): array
    {
        return [
            'id' => $order->id,
            'code' => $order->code,
            'title' => $order->title,
            'category' => $order->category,
            'category_label' => MaterialOrder::CATEGORIES[$order->category] ?? $order->category,
            'quantity' => $order->quantity,
            'branch' => $order->branch?->name,
            'class_name' => $order->classRoom?->name,
            'requester' => $order->requester?->name,
            'use_date' => $order->use_date?->toDateString(),
            'due_at' => $order->due_at?->toIso8601String(),
            'status' => $order->status,
            'status_label' => MaterialOrder::STATUSES[$order->status] ?? $order->status,
            // ok | soon | overdue | null (đã xong / từ chối) — badge cột Hạn xử lý.
            'deadline_state' => $order->deadlineState(),
            'created_late' => $order->created_late,
        ];
    }
}
