<?php

namespace App\Http\Controllers;

use App\Models\CourseLevel;
use App\Models\Student;
use App\Models\SyllabusCurriculum;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            ->ordered()
            ->paginate($request->perPage(15))
            ->withQueryString();

        // Số học viên đang học theo trình độ (lớp chính của học viên dùng mã trình độ) — cho cảnh báo "Không thể xóa".
        $studentCounts = Student::query()
            ->join('classes', 'classes.id', '=', 'students.current_class_id')
            ->whereIn('classes.level', $levels->pluck('code'))
            ->whereNull('classes.deleted_at')
            ->where('students.status', '!=', Student::STATUS_DROPPED)
            ->groupBy('classes.level')
            ->selectRaw('classes.level as level_code, count(*) as total')
            ->pluck('total', 'level_code');

        $stats = [
            'total' => CourseLevel::count(),
            'active' => CourseLevel::where('is_active', true)->count(),
            'syllabus' => CourseLevel::where('is_active', true)->whereNotNull('syllabus_curriculum_id')->distinct()->count('syllabus_curriculum_id'),
            'groups' => CourseLevel::whereNotNull('level_group')->distinct()->count('level_group'),
        ];
        $groups = CourseLevel::whereNotNull('level_group')->distinct()->orderBy('level_group')->pluck('level_group');
        $curriculums = SyllabusCurriculum::orderBy('code')->get(['id', 'code', 'title', 'version', 'updated_at']);

        $canReorder = empty(array_filter($validated));

        return view('course-levels.index', compact('levels', 'stats', 'groups', 'curriculums', 'studentCounts', 'canReorder'));
    }

    public function storeLevel(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|unique:course_levels,code|max:20',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'level_group' => 'nullable|string|max:50',
            'target' => 'required|string|max:255',
            'duration' => 'nullable|string|max:100',
            'lessons_count' => 'required|integer|min:1',
            'syllabus_curriculum_id' => 'nullable|integer|exists:syllabus_curriculums,id,deleted_at,NULL',
            'is_active' => 'nullable|boolean',
        ]);
        $validated['level_group'] = isset($validated['level_group']) ? mb_strtoupper(trim($validated['level_group'])) : null;
        // Mặc định "Hoạt động"; form có công tắc "Trạng thái hoạt động" để tạo sẵn ở trạng thái ngừng.
        $validated['is_active'] = $request->has('is_active') ? $request->boolean('is_active') : true;
        $validated['sort_order'] = (int) CourseLevel::max('sort_order') + 1;

        CourseLevel::create($validated);

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
            'description' => 'nullable|string|max:1000',
            'level_group' => 'nullable|string|max:50',
            'target' => 'required|string|max:255',
            'duration' => 'nullable|string|max:100',
            'lessons_count' => 'required|integer|min:1',
            'syllabus_curriculum_id' => ['nullable', 'integer', Rule::exists('syllabus_curriculums', 'id')->whereNull('deleted_at')],
            'is_active' => 'nullable|boolean',
        ]);
        $validated['level_group'] = isset($validated['level_group']) ? mb_strtoupper(trim($validated['level_group'])) : null;
        $validated['syllabus_curriculum_id'] ??= null;
        $validated['is_active'] = $request->has('is_active') ? $request->boolean('is_active') : $level->is_active;

        $level->update($validated);

        return redirect()->route('course-levels.index')
            ->with('status', "Cập nhật khung trình độ {$level->name} thành công!");
    }

    /**
     * Kéo thả sắp xếp: nhận id các trình độ theo thứ tự mới (một trang của danh sách), giữ nguyên
     * các vị trí mà nhóm id này đang chiếm trong thứ tự toàn bộ rồi xếp lại trong các vị trí đó.
     */
    public function reorder(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1|max:200',
            'ids.*' => 'integer|distinct|exists:course_levels,id,deleted_at,NULL',
        ]);
        $ids = array_map('intval', $validated['ids']);

        DB::transaction(function () use ($ids) {
            $all = CourseLevel::query()->ordered()->lockForUpdate()->pluck('id')->map(fn ($id) => (int) $id)->all();
            $slots = array_keys(array_filter($all, fn ($id) => in_array($id, $ids, true)));
            foreach ($slots as $i => $slot) {
                $all[$slot] = $ids[$i];
            }
            foreach ($all as $position => $id) {
                CourseLevel::whereKey($id)->where('sort_order', '!=', $position + 1)->update(['sort_order' => $position + 1]);
            }
        });

        return $request->expectsJson()
            ? response()->json(['message' => 'Đã lưu thứ tự trình độ.'])
            : redirect()->route('course-levels.index')->with('status', 'Đã lưu thứ tự trình độ.');
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
