<?php

namespace App\Http\Controllers;

use App\Http\Concerns\RendersModals;
use App\Http\Requests\HolidayRequest;
use App\Models\Branch;
use App\Models\Holiday;
use App\Services\DocumentCodeGenerator;
use App\Services\HolidayRescheduleService;
use Illuminate\Support\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class HolidayController extends Controller
{
    use RendersModals;

    public function __construct(private readonly HolidayRescheduleService $reschedule) {}

    /**
     * Mockup "Cấu hình ngày nghỉ": danh sách (tìm kiếm, phân trang). Thêm/Sửa mở modal (htmx);
     * mở thẳng URL create/edit → trang danh sách + form bên phải như cũ.
     */
    public function index(Request $request): View
    {
        return view('holidays.index', $this->listData($request));
    }

    public function create(Request $request): Response
    {
        return $this->formView($request, new Holiday);
    }

    public function store(HolidayRequest $request, DocumentCodeGenerator $codes): Response|RedirectResponse
    {
        $branchIds = $request->validated('branch_ids', []);
        $holiday = Holiday::create([
            ...$request->safe()->except(['branch_ids', 'is_system_wide', 'code']),
            'code' => filled($request->validated('code')) ? $request->validated('code') : $codes->holidayCode(Carbon::parse($request->validated('start_date'))->year),
            // Không chọn chi nhánh nào = áp dụng toàn hệ thống (mockup: "Để trống nếu muốn áp dụng cho tất cả chi nhánh").
            'is_system_wide' => $request->boolean('is_system_wide') || empty($branchIds),
        ]);

        if (! $holiday->is_system_wide) {
            $holiday->branches()->sync($request->validated('branch_ids', []));
        }

        activity('holiday')->causedBy(auth()->user())->performedOn($holiday)->log('Tạo ngày nghỉ mới');

        $summary = $this->reschedule->apply($holiday->fresh('branches'));

        return $this->modalSaved('Đã thêm ngày nghỉ.'.$this->summaryText($summary), 'holidays-changed', route('holidays.index'));
    }

    public function edit(Request $request, Holiday $holiday): Response
    {
        return $this->formView($request, $holiday->load('branches'));
    }

    public function update(HolidayRequest $request, Holiday $holiday): Response|RedirectResponse
    {
        $branchIds = $request->validated('branch_ids', []);
        $holiday->update([
            ...$request->safe()->except(['branch_ids', 'is_system_wide', 'code']),
            'code' => filled($request->validated('code')) ? $request->validated('code') : $holiday->code,
            'is_system_wide' => $request->boolean('is_system_wide') || empty($branchIds),
        ]);

        $holiday->branches()->sync($holiday->is_system_wide ? [] : $request->validated('branch_ids', []));

        activity('holiday')->causedBy(auth()->user())->performedOn($holiday)->log('Cập nhật ngày nghỉ');

        $summary = $this->reschedule->apply($holiday->fresh('branches'));

        return $this->modalSaved('Đã cập nhật ngày nghỉ.'.$this->summaryText($summary), 'holidays-changed', route('holidays.index'));
    }

    public function destroy(Holiday $holiday): Response|RedirectResponse
    {
        $holiday->delete();
        $restored = $this->reschedule->release($holiday);

        activity('holiday')->causedBy(auth()->user())->withProperties(['name' => $holiday->name])->log('Xóa ngày nghỉ');

        return $this->modalSaved('Đã xóa ngày nghỉ.'
            .($restored ? " Đã khôi phục {$restored} buổi học bị hủy do ngày nghỉ này (buổi bù tương ứng đã được gỡ)." : ''),
            'holidays-changed', route('holidays.index'));
    }

    /**
     * Form Thêm/Sửa: modal chỉ cần dữ liệu form; trang đầy đủ thêm danh sách bên trái.
     */
    private function formView(Request $request, Holiday $holiday): Response
    {
        $data = [
            'holiday' => $holiday,
            'branches' => Branch::query()->active()->orderBy('name')->get(),
            'selectedBranchIds' => $holiday->exists ? $holiday->branches->pluck('id')->all() : [],
        ];

        return $this->modalView('holidays.form', $this->isModalRequest() ? $data : [...$this->listData($request), ...$data]);
    }

    /**
     * @return array{holidays: \Illuminate\Contracts\Pagination\LengthAwarePaginator}
     */
    private function listData(Request $request): array
    {
        $search = trim((string) $request->query('search', ''));

        return [
            'holidays' => Holiday::query()
                ->with('branches')
                ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%")))
                ->orderByDesc('start_date')
                ->paginate($request->perPage(15))
                ->withQueryString(),
        ];
    }

    /**
     * @param  array{cancelled: int, rescheduled: int, unscheduled: int, flagged: int, restored: int}  $summary
     */
    private function summaryText(array $summary): string
    {
        $parts = [];
        if ($summary['cancelled']) {
            $parts[] = "Đã hủy {$summary['cancelled']} buổi học trùng ngày nghỉ, xếp bù {$summary['rescheduled']} buổi vào cuối lịch";
        }
        if ($summary['unscheduled']) {
            $parts[] = "{$summary['unscheduled']} buổi chưa tìm được ca bù (cần xếp tay trong TKB)";
        }
        if ($summary['flagged']) {
            $parts[] = "{$summary['flagged']} buổi đã có điểm danh/chấm công nên giữ nguyên, cần kiểm tra";
        }
        if ($summary['restored']) {
            $parts[] = "khôi phục {$summary['restored']} buổi không còn nằm trong ngày nghỉ";
        }

        return $parts ? ' '.implode('; ', $parts).'.' : '';
    }
}
