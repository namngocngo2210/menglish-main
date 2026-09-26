<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\CourseLevel;
use Illuminate\Http\Request;

class CourseController extends Controller
{
    /**
     * Danh sách khóa học & Bảng giá học phí
     */
    public function index(Request $request)
    {
        $query = Course::with('level')->withCount('classes')->latest();

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($levelId = $request->input('course_level_id')) {
            $query->where('course_level_id', $levelId);
        }

        if ($status = $request->input('status')) {
            if ($status === 'active') {
                $query->where('is_active', true);
            } elseif ($status === 'inactive') {
                $query->where('is_active', false);
            }
        }

        $courses = $query->paginate($request->perPage(20))->withQueryString();
        $levels = CourseLevel::where('is_active', true)->get();

        $stats = [
            'total_courses' => Course::count(),
            'active_courses' => Course::where('is_active', true)->count(),
            'avg_tuition' => Course::where('is_active', true)->avg('tuition_fee') ?: 0,
            'max_tuition' => Course::where('is_active', true)->max('tuition_fee') ?: 0,
        ];

        return view('courses.index', compact('courses', 'levels', 'stats'));
    }

    /**
     * Thêm khóa học & thiết lập giá mới
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|unique:courses,code|max:30',
            'name' => 'required|string|max:255',
            'course_level_id' => 'nullable|exists:course_levels,id,deleted_at,NULL',
            'tuition_fee' => 'required|numeric|min:0',
            'total_lessons' => 'required|integer|min:1',
            'description' => 'nullable|string|max:1000',
            'is_active' => 'nullable|boolean',
        ], [
            'code.required' => 'Vui lòng nhập mã khóa học.',
            'code.unique' => 'Mã khóa học này đã tồn tại trong hệ thống.',
            'name.required' => 'Vui lòng nhập tên khóa học.',
            'tuition_fee.required' => 'Vui lòng nhập giá học phí.',
            'tuition_fee.numeric' => 'Giá học phí phải là số hợp lệ.',
            'total_lessons.required' => 'Vui lòng nhập số buổi học.',
        ]);

        $validated['is_active'] = $request->has('is_active') ? (bool)$request->is_active : true;

        $course = Course::create($validated);

        return redirect()->route('courses.index')
            ->with('status', "Đã thêm khóa học '{$course->name}' ({$course->code}) với học phí " . number_format($course->tuition_fee) . "đ thành công!");
    }

    /**
     * Cập nhật thông tin & Chỉnh sửa giá học phí
     */
    public function update(Request $request, $id)
    {
        $course = Course::findOrFail($id);

        $validated = $request->validate([
            'code' => 'required|string|max:30|unique:courses,code,' . $course->id,
            'name' => 'required|string|max:255',
            'course_level_id' => 'nullable|exists:course_levels,id,deleted_at,NULL',
            'tuition_fee' => 'required|numeric|min:0',
            'total_lessons' => 'required|integer|min:1',
            'description' => 'nullable|string|max:1000',
            'is_active' => 'nullable|boolean',
        ], [
            'code.required' => 'Vui lòng nhập mã khóa học.',
            'name.required' => 'Vui lòng nhập tên khóa học.',
            'tuition_fee.required' => 'Vui lòng nhập giá học phí.',
            'tuition_fee.numeric' => 'Giá học phí phải là số hợp lệ.',
            'total_lessons.required' => 'Vui lòng nhập số buổi học.',
        ]);

        $validated['is_active'] = $request->has('is_active') ? (bool)$request->is_active : false;

        $oldFee = $course->tuition_fee;
        $course->update($validated);

        $feeMsg = ($oldFee != $course->tuition_fee)
            ? " (Đã cập nhật giá từ " . number_format($oldFee) . "đ thành " . number_format($course->tuition_fee) . "đ)"
            : "";

        return redirect()->route('courses.index')
            ->with('status', "Cập nhật khóa học '{$course->name}' thành công!{$feeMsg}");
    }

    /**
     * Bật / Tắt trạng thái áp dụng khóa học
     */
    public function toggleStatus($id)
    {
        $course = Course::findOrFail($id);
        $course->is_active = !$course->is_active;
        $course->save();

        $statusText = $course->is_active ? 'Kích hoạt mở bán' : 'Tạm ngưng mở bán';

        return redirect()->back()
            ->with('status', "Đã {$statusText} cho khóa học '{$course->name}'!");
    }

    /**
     * Xóa khóa học
     */
    public function destroy($id)
    {
        $course = Course::withCount('classes')->findOrFail($id);

        if ($course->classes_count > 0) {
            return redirect()->back()
                ->with('error', "Không thể xóa khóa học '{$course->name}' vì đang có {$course->classes_count} lớp học liên kết!");
        }

        $name = $course->name;
        $course->delete();

        return redirect()->route('courses.index')
            ->with('status', "Đã xóa khóa học '{$name}' khỏi hệ thống!");
    }
}
