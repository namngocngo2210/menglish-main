<?php

namespace App\Http\Controllers;

use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tab "Danh mục loại phòng" của màn Phòng học: danh mục dùng chung toàn hệ thống. Thêm / sửa ngay trên dòng,
 * Ngừng dùng / Dùng lại, Xóa (chặn khi loại đang có phòng dùng — chỉ Ngừng dùng được).
 */
class RoomTypeController extends Controller
{
    public function index(Request $request, RoomController $rooms): Response
    {
        $used = Room::query()->distinct()->pluck('room_type_id')->flip();

        return Inertia::render('Rooms/Types', $rooms->shared($request->user()) + [
            'roomTypes' => RoomType::query()->orderByDesc('is_active')->orderBy('name')->get()
                ->map(fn (RoomType $type) => [
                    'id' => $type->id,
                    'name' => $type->name,
                    'is_active' => $type->is_active,
                    'in_use' => $used->has($type->id),
                ])->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $type = RoomType::create($this->validated($request, null) + ['is_active' => true]);

        return back()->with('success', "Đã thêm loại phòng {$type->name}.");
    }

    public function update(Request $request, RoomType $roomType): RedirectResponse
    {
        $roomType->update($this->validated($request, $roomType));

        return back()->with('success', "Đã lưu loại phòng {$roomType->name}.");
    }

    /** Ngừng dùng ↔ Dùng lại: loại ngừng dùng không chọn được cho phòng mới, phòng cũ giữ nguyên loại. */
    public function toggle(RoomType $roomType): RedirectResponse
    {
        $roomType->update(['is_active' => ! $roomType->is_active]);

        return back()->with('success', $roomType->is_active ? "Đã dùng lại loại phòng {$roomType->name}." : "Đã ngừng dùng loại phòng {$roomType->name}.");
    }

    public function destroy(RoomType $roomType): RedirectResponse
    {
        if (Room::where('room_type_id', $roomType->id)->exists()) {
            return back()->with('error', 'Loại phòng đang được sử dụng — chỉ có thể Ngừng dùng');
        }

        $roomType->delete();

        return back()->with('success', "Đã xóa loại phòng {$roomType->name}.");
    }

    /** @return array{name: string} */
    private function validated(Request $request, ?RoomType $type): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('room_types', 'name')->whereNull('deleted_at')->ignore($type?->id)],
        ], [
            'name.required' => 'Vui lòng nhập tên loại phòng.',
            'name.max' => 'Tên loại phòng tối đa 100 ký tự.',
            'name.unique' => 'Loại phòng này đã có trong danh mục.',
        ]);

        return ['name' => trim($data['name'])];
    }
}
