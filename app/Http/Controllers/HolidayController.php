<?php

namespace App\Http\Controllers;

use App\Http\Requests\HolidayRequest;
use App\Models\Branch;
use App\Models\Holiday;
use App\Services\DocumentCodeGenerator;
use App\Services\HolidayRescheduleService;
use Illuminate\Support\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HolidayController extends Controller
{
    public function __construct(private readonly HolidayRescheduleService $reschedule) {}

    /**
     * Mockup "Cấu hình ngày nghỉ": danh sách (tìm kiếm, phân trang) và form Thêm/Sửa bên phải trên cùng một trang.
     */
    public function index(Request $request, ?Holiday $editing = null): View
    {
        $search = trim((string) $request->query('search', ''));
        $holidays = Holiday::query()
            ->with('branches')
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%")))
            ->orderByDesc('start_date')
            ->paginate($request->perPage(15))
            ->withQueryString();

        $holiday = $editing ?? new Holiday;

        return view('holidays.index', [
            'holidays' => $holidays,
            'holiday' => $holiday,
            'branches' => Branch::query()->active()->orderBy('name')->get(),
            'selectedBranchIds' => $holiday->exists ? $holiday->branches->pluck('id')->all() : [],
        ]);
    }

    public function create(Request $request): View
    {
        return $this->index($request);
    }

    public function store(HolidayRequest $request, DocumentCodeGenerator $codes): RedirectResponse
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

        return redirect()->route('holidays.index')->with('status', 'Đã thêm ngày nghỉ.'.$this->summaryText($summary));
    }

    public function edit(Request $request, Holiday $holiday): View
    {
        return $this->index($request, $holiday->load('branches'));
    }

    public function update(HolidayRequest $request, Holiday $holiday): RedirectResponse
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

        return redirect()->route('holidays.index')->with('status', 'Đã cập nhật ngày nghỉ.'.$this->summaryText($summary));
    }

    public function destroy(Holiday $holiday): RedirectResponse
    {
        $holiday->delete();
        $restored = $this->reschedule->release($holiday);

        activity('holiday')->causedBy(auth()->user())->withProperties(['name' => $holiday->name])->log('Xóa ngày nghỉ');

        return redirect()->route('holidays.index')->with('status', 'Đã xóa ngày nghỉ.'
            .($restored ? " Đã khôi phục {$restored} buổi học bị hủy do ngày nghỉ này (buổi bù tương ứng đã được gỡ)." : ''));
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
