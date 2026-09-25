<?php

namespace App\Http\Controllers;

use App\Http\Requests\HolidayRequest;
use App\Models\Branch;
use App\Models\Holiday;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HolidayController extends Controller
{
    public function index(Request $request): View
    {
        $holidays = Holiday::query()
            ->with('branches')
            ->orderByDesc('start_date')
            ->paginate($request->perPage(15))
            ->withQueryString();

        return view('holidays.index', compact('holidays'));
    }

    public function create(): View
    {
        return view('holidays.form', [
            'holiday' => new Holiday,
            'branches' => Branch::query()->active()->orderBy('name')->get(),
            'selectedBranchIds' => [],
        ]);
    }

    public function store(HolidayRequest $request): RedirectResponse
    {
        $holiday = Holiday::create([
            ...$request->safe()->except(['branch_ids', 'is_system_wide']),
            'is_system_wide' => $request->boolean('is_system_wide'),
        ]);

        if (! $holiday->is_system_wide) {
            $holiday->branches()->sync($request->validated('branch_ids', []));
        }

        activity('holiday')->causedBy(auth()->user())->performedOn($holiday)->log('Tạo ngày nghỉ mới');

        return redirect()->route('holidays.index')->with('status', 'Đã thêm ngày nghỉ.');
    }

    public function edit(Holiday $holiday): View
    {
        return view('holidays.form', [
            'holiday' => $holiday,
            'branches' => Branch::query()->active()->orderBy('name')->get(),
            'selectedBranchIds' => $holiday->branches->pluck('id')->all(),
        ]);
    }

    public function update(HolidayRequest $request, Holiday $holiday): RedirectResponse
    {
        $holiday->update([
            ...$request->safe()->except(['branch_ids', 'is_system_wide']),
            'is_system_wide' => $request->boolean('is_system_wide'),
        ]);

        $holiday->branches()->sync($holiday->is_system_wide ? [] : $request->validated('branch_ids', []));

        activity('holiday')->causedBy(auth()->user())->performedOn($holiday)->log('Cập nhật ngày nghỉ');

        return redirect()->route('holidays.index')->with('status', 'Đã cập nhật ngày nghỉ.');
    }

    public function destroy(Holiday $holiday): RedirectResponse
    {
        $holiday->delete();

        activity('holiday')->causedBy(auth()->user())->withProperties(['name' => $holiday->name])->log('Xóa ngày nghỉ');

        return redirect()->route('holidays.index')->with('status', 'Đã xóa ngày nghỉ.');
    }
}
