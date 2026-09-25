<?php

namespace App\Http\Controllers;

use App\Models\CourseLevel;
use App\Models\SyllabusCurriculum;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CourseLevelController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,inactive'],
            'group' => ['nullable', 'string', 'max:50'],
        ]);

        $levels = CourseLevel::query()
            ->with('syllabus:id,code,title,version')
            ->withCount('courses')
            ->withClassesCount()
            ->when($validated['search'] ?? null, fn ($q, $search) => $q->where(fn ($q) => $q
                ->where('code', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%")
                ->orWhere('target', 'like', "%{$search}%")
                ->orWhere('level_group', 'like', "%{$search}%")))
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('is_active', $status === 'active'))
            ->when($validated['group'] ?? null, fn ($q, $group) => $q->where('level_group', $group))
            ->orderBy('level_group')
            ->orderBy('code')
            ->paginate($request->perPage(15))
            ->withQueryString();

        $stats = [
            'total' => CourseLevel::count(),
            'active' => CourseLevel::where('is_active', true)->count(),
            'syllabus' => CourseLevel::where('is_active', true)->whereNotNull('syllabus_curriculum_id')->distinct()->count('syllabus_curriculum_id'),
            'groups' => CourseLevel::whereNotNull('level_group')->distinct()->count('level_group'),
        ];
        $groups = CourseLevel::whereNotNull('level_group')->distinct()->orderBy('level_group')->pluck('level_group');
        $curriculums = SyllabusCurriculum::orderBy('code')->get(['id', 'code', 'title', 'version']);

        return view('course-levels.index', compact('levels', 'stats', 'groups', 'curriculums'));
    }

    public function storeLevel(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|unique:course_levels,code|max:20',
            'name' => 'required|string|max:255',
            'level_group' => 'nullable|string|max:50',
            'target' => 'required|string|max:255',
            'duration' => 'nullable|string|max:100',
            'lessons_count' => 'required|integer|min:1',
            'syllabus_curriculum_id' => 'nullable|integer|exists:syllabus_curriculums,id',
        ]);
        $validated['level_group'] = isset($validated['level_group']) ? mb_strtoupper(trim($validated['level_group'])) : null;

        CourseLevel::create($validated + ['is_active' => true]);

        return redirect()->route('course-levels.index')
            ->with('status', "Đã thêm khung trình độ {$validated['name']} ({$validated['code']}) thành công!");
    }

    public function updateLevel(Request $request, $id)
    {
        $level = CourseLevel::findOrFail($id);

        // Nút bật/tắt trạng thái chỉ gửi toggle_status.
        if ($request->boolean('toggle_status')) {
            $level->update(['is_active' => ! $level->is_active]);

            return redirect()->back()
                ->with('status', "Đã chuyển trình độ {$level->name} sang ".($level->is_active ? 'Hoạt động' : 'Ngừng hoạt động').'.');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'level_group' => 'nullable|string|max:50',
            'target' => 'required|string|max:255',
            'duration' => 'nullable|string|max:100',
            'lessons_count' => 'required|integer|min:1',
            'syllabus_curriculum_id' => ['nullable', 'integer', Rule::exists('syllabus_curriculums', 'id')],
            'is_active' => 'nullable|boolean',
        ]);
        $validated['level_group'] = isset($validated['level_group']) ? mb_strtoupper(trim($validated['level_group'])) : null;
        $validated['syllabus_curriculum_id'] ??= null;
        $validated['is_active'] = $request->has('is_active') ? $request->boolean('is_active') : $level->is_active;

        $level->update($validated);

        return redirect()->route('course-levels.index')
            ->with('status', "Cập nhật khung trình độ {$level->name} thành công!");
    }

    public function destroyLevel($id)
    {
        $level = CourseLevel::withCount('courses')->withClassesCount()->findOrFail($id);

        // Trình độ đang được khóa học hoặc lớp học sử dụng không được xóa — chuyển "Ngừng hoạt động" thay thế.
        if ($level->courses_count > 0 || $level->classes_count > 0) {
            return redirect()->route('course-levels.index')->withErrors([
                'level' => "Không thể xóa trình độ {$level->name}: đang gắn với {$level->courses_count} khóa học và {$level->classes_count} lớp học. Hãy chuyển sang \"Ngừng hoạt động\".",
            ]);
        }

        $name = $level->name;
        $level->delete();

        return redirect()->route('course-levels.index')
            ->with('status', "Đã xóa trình độ {$name}!");
    }
}
