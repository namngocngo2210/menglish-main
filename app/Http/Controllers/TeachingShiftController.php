<?php

namespace App\Http\Controllers;

use App\Models\ClassModel;
use App\Models\TeachingShift;
use App\Services\TeachingShifts\TeachingShiftRules;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cài đặt → Tổ chức & Đào tạo → Khung giờ ca dạy. Ai xếp lịch lớp (class.update) được xem; thêm / sửa / ngừng dùng /
 * xóa (xóa mềm) chỉ teaching_shift.manage (Admin). Quy tắc áp khi xếp TKB: TeachingShiftRules.
 */
class TeachingShiftController extends Controller
{
    public function index(Request $request, TeachingShiftRules $rules): Response
    {
        $user = $request->user();

        return Inertia::render('TeachingShifts/Index', [
            'dayTypes' => collect(TeachingShift::DAY_TYPES)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            'shifts' => TeachingShift::query()->ordered()->get()->map(fn (TeachingShift $s) => [
                'id' => $s->id,
                'day_type' => $s->day_type,
                'day_label' => TeachingShift::DAY_TYPES[$s->day_type] ?? $s->day_type,
                'name' => $s->name,
                'start_time' => TeachingShift::hm($s->start_time),
                'end_time' => TeachingShift::hm($s->end_time),
                'alt_start_time' => TeachingShift::hm($s->alt_start_time),
                'alt_end_time' => TeachingShift::hm($s->alt_end_time),
                'standard' => $s->standardRange(),
                'alt' => $s->altRange(),
                'note' => $s->note,
                'is_active' => $s->is_active,
            ])->values(),
            'weekdayRule' => $rules->weekdayFramesText(),
            'duration' => TeachingShift::DURATION_MINUTES,
            'offScheduleClasses' => $rules->offScheduleClasses(ClassModel::query()->visibleTo($user)->pluck('id')),
            'canManage' => $user->can('teaching_shift.manage'),
            'canSchedule' => $user->can('work_task.assign'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $shift = new TeachingShift($this->validated($request) + [
            'is_active' => true,
            'sort_order' => (int) TeachingShift::withTrashed()->max('sort_order') + 1,
        ]);
        Audit::describe("Thêm khung giờ ca dạy: {$this->label($shift)}");
        $shift->save();

        return back()->with('success', "Đã thêm khung {$this->label($shift)}.");
    }

    public function update(Request $request, TeachingShift $teachingShift): RedirectResponse
    {
        $teachingShift->fill($this->validated($request));
        Audit::describe("Sửa khung giờ ca dạy: {$this->label($teachingShift)}");
        $teachingShift->save();

        return back()->with('success', "Đã lưu khung {$this->label($teachingShift)}. Lịch đã xếp không đổi — chỉ áp khi xếp / sửa TKB.");
    }

    /** Ngừng dùng ↔ Dùng lại: khung ngừng dùng không chọn được khi xếp TKB, buổi đã sinh giữ nguyên. */
    public function toggle(TeachingShift $teachingShift): RedirectResponse
    {
        $teachingShift->is_active = ! $teachingShift->is_active;
        Audit::describe(($teachingShift->is_active ? 'Dùng lại' : 'Ngừng dùng')." khung giờ ca dạy: {$this->label($teachingShift)}");
        $teachingShift->save();

        return back()->with('success', ($teachingShift->is_active ? 'Đã dùng lại' : 'Đã ngừng dùng')." khung {$this->label($teachingShift)}.");
    }

    public function destroy(TeachingShift $teachingShift): RedirectResponse
    {
        $label = $this->label($teachingShift);
        Audit::describe("Xóa khung giờ ca dạy: {$label}");
        $teachingShift->delete();

        return back()->with('success', "Đã xóa khung {$label}.");
    }

    private function label(TeachingShift $shift): string
    {
        return (TeachingShift::DAY_TYPES[$shift->day_type] ?? $shift->day_type).' · '.$shift->name.' '.$shift->standardRange();
    }

    /** @throws ValidationException */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'day_type' => ['required', Rule::in(array_keys(TeachingShift::DAY_TYPES))],
            'name' => ['required', 'string', 'max:50'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'alt_start_time' => ['nullable', 'date_format:H:i', 'required_with:alt_end_time'],
            'alt_end_time' => ['nullable', 'date_format:H:i', 'required_with:alt_start_time'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [], [
            'day_type' => 'loại ngày',
            'name' => 'tên ca',
            'start_time' => 'giờ bắt đầu',
            'end_time' => 'giờ kết thúc',
            'alt_start_time' => 'giờ lệch bắt đầu',
            'alt_end_time' => 'giờ lệch kết thúc',
        ]);

        $duration = TeachingShift::DURATION_MINUTES;
        foreach ([['start_time', 'end_time'], ['alt_start_time', 'alt_end_time']] as [$from, $to]) {
            if (blank($data[$from] ?? null)) {
                continue;
            }
            [$sh, $sm] = array_map('intval', explode(':', $data[$from]));
            [$eh, $em] = array_map('intval', explode(':', $data[$to]));
            if (($eh * 60 + $em) - ($sh * 60 + $sm) !== $duration) {
                throw ValidationException::withMessages([$to => "Mỗi ca dạy {$duration} phút: giờ kết thúc phải sau giờ bắt đầu đúng {$duration} phút."]);
            }
        }
        if (filled($data['alt_start_time'] ?? null) && $data['alt_start_time'] === $data['start_time']) {
            throw ValidationException::withMessages(['alt_start_time' => 'Giờ lệch trùng giờ chuẩn — để trống nếu khung không có giờ lệch.']);
        }

        return [
            'day_type' => $data['day_type'],
            'name' => trim($data['name']),
            'start_time' => $data['start_time'].':00',
            'end_time' => $data['end_time'].':00',
            'alt_start_time' => filled($data['alt_start_time'] ?? null) ? $data['alt_start_time'].':00' : null,
            'alt_end_time' => filled($data['alt_end_time'] ?? null) ? $data['alt_end_time'].':00' : null,
            'note' => filled($data['note'] ?? null) ? trim($data['note']) : null,
        ];
    }
}
