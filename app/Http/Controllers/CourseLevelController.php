<?php

namespace App\Http\Controllers;

use App\Models\CourseLevel;
use Illuminate\Http\Request;

class CourseLevelController extends Controller
{
    public function index()
    {
        $levels = CourseLevel::withCount('courses')->get();
        return view('course-levels.index', compact('levels'));
    }

    public function storeLevel(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|unique:course_levels,code|max:20',
            'name' => 'required|string|max:255',
            'target' => 'required|string|max:255',
            'duration' => 'nullable|string|max:100',
            'lessons_count' => 'required|integer|min:1',
        ]);

        CourseLevel::create($validated + ['is_active' => true]);

        return redirect()->route('course-levels.index')
            ->with('status', "Đã thêm khung trình độ {$validated['name']} ({$validated['code']}) thành công!");
    }

    public function updateLevel(Request $request, $id)
    {
        $level = CourseLevel::findOrFail($id);
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'target' => 'required|string|max:255',
            'duration' => 'nullable|string|max:100',
            'lessons_count' => 'required|integer|min:1',
        ]);

        $level->update($validated);

        return redirect()->route('course-levels.index')
            ->with('status', "Cập nhật khung trình độ {$level->name} thành công!");
    }

    public function destroyLevel($id)
    {
        $level = CourseLevel::findOrFail($id);
        $name = $level->name;
        $level->delete();

        return redirect()->route('course-levels.index')
            ->with('status', "Đã xóa trình độ {$name}!");
    }
}
