<?php

namespace App\Http\Controllers;

use App\Models\Survey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

/**
 * Quản lý đợt khảo sát chất lượng đào tạo: học vụ tạo khảo sát + hạn,
 * học viên/phụ huynh thấy và nộp tại Cổng PH/HS (MH #6 Khảo sát 5 sao).
 */
class SurveyController extends Controller
{
    public function index()
    {
        $surveys = Survey::with('creator')->latest()->get();

        return Inertia::render('Surveys/Index', [
            'today' => now()->toDateString(),
            'surveys' => $surveys->map(fn (Survey $sv) => [
                'id' => $sv->id,
                'title' => $sv->title,
                'description' => $sv->description,
                'deadline' => $sv->deadline?->format('Y-m-d'),
                'deadline_label' => $sv->deadline?->format('d/m/Y'),
                'deadline_today' => (bool) $sv->deadline?->isToday(),
                'is_active' => (bool) $sv->is_active,
                'creator_name' => $sv->creator?->name,
            ])->values()->all(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'deadline' => 'nullable|date|after_or_equal:today',
        ]);

        Survey::create($validated + [
            'is_active' => true,
            'created_by' => Auth::id(),
        ]);

        return redirect()->route('surveys.index')->with('status', 'Đã tạo đợt khảo sát mới.');
    }

    public function update(Request $request, Survey $survey)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'deadline' => 'nullable|date',
            'is_active' => 'nullable|boolean',
        ]);
        $validated['is_active'] = $request->boolean('is_active');

        $survey->update($validated);

        return redirect()->route('surveys.index')->with('status', "Đã cập nhật khảo sát \"{$survey->title}\".");
    }

    public function destroy(Survey $survey)
    {
        $title = $survey->title;
        $survey->delete();

        return redirect()->route('surveys.index')->with('status', "Đã xóa khảo sát \"{$title}\".");
    }
}
