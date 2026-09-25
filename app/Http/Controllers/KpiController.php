<?php

namespace App\Http\Controllers;

use App\Models\ClassModel;
use App\Models\KpiCriterion;
use App\Models\KpiEvaluation;
use App\Models\KpiEvaluationItem;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * KPI Học vụ: cấu hình chỉ số trọng số, đánh giá KPI theo tháng cho từng nhân
 * sự, và rà soát điểm danh (admin học vụ).
 */
class KpiController extends Controller
{
    private const STAFF_ROLES = ['academic_staff', 'academic_lead', 'teacher', 'teacher_fulltime', 'teacher_parttime', 'assistant'];

    private function guard(string $permission = 'kpi.view'): void
    {
        abort_unless(Auth::user()?->can($permission), 403);
    }

    // ───────────────────── CẤU HÌNH KPI ─────────────────────
    public function criteria()
    {
        $this->guard();
        $criteria = KpiCriterion::orderByDesc('is_active')->orderBy('id')->get();
        $totalWeight = $criteria->where('is_active', true)->sum('weight');

        return view('kpi.criteria', compact('criteria', 'totalWeight'));
    }

    public function criteriaStore(Request $request)
    {
        $this->guard('kpi.manage');
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'weight' => 'required|numeric|min:0|max:100',
            'target' => 'nullable|string|max:255',
            'unit' => 'nullable|string|max:50',
            'description' => 'nullable|string',
        ]);
        $validated['is_active'] = true;
        KpiCriterion::create($validated);

        return back()->with('success', 'Đã thêm chỉ số KPI!');
    }

    public function criteriaUpdate(Request $request, int $id)
    {
        $this->guard('kpi.manage');
        $criterion = KpiCriterion::findOrFail($id);
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'weight' => 'required|numeric|min:0|max:100',
            'target' => 'nullable|string|max:255',
            'unit' => 'nullable|string|max:50',
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);
        $validated['is_active'] = $request->boolean('is_active');
        $criterion->update($validated);

        return back()->with('success', 'Đã cập nhật chỉ số KPI!');
    }

    public function criteriaDestroy(int $id)
    {
        $this->guard('kpi.manage');
        KpiCriterion::findOrFail($id)->delete();

        return back()->with('success', 'Đã xoá chỉ số KPI!');
    }

    // ───────────────────── ĐÁNH GIÁ KPI THÁNG ─────────────────────
    public function monthly(Request $request)
    {
        $this->guard();
        $month = (int) $request->input('month', now()->month);
        $year = (int) $request->input('year', now()->year);

        $evaluations = KpiEvaluation::with('user')
            ->where('month', $month)->where('year', $year)
            ->get()
            ->keyBy('user_id');

        $staff = User::whereHas('roles', fn ($q) => $q->whereIn('name', self::STAFF_ROLES))
            ->orderBy('name')->get();

        return view('kpi.monthly', compact('evaluations', 'staff', 'month', 'year'));
    }

    public function evaluate(Request $request, int $userId)
    {
        $this->guard();
        $staff = User::findOrFail($userId);
        $month = (int) $request->input('month', now()->month);
        $year = (int) $request->input('year', now()->year);

        $criteria = KpiCriterion::active()->orderBy('id')->get();
        $evaluation = KpiEvaluation::with('items')
            ->where('user_id', $userId)->where('month', $month)->where('year', $year)->first();
        $scores = $evaluation ? $evaluation->items->keyBy('kpi_criterion_id') : collect();

        return view('kpi.evaluate', compact('staff', 'criteria', 'evaluation', 'scores', 'month', 'year'));
    }

    public function evaluateStore(Request $request, int $userId)
    {
        $this->guard('kpi.confirm');
        User::findOrFail($userId);
        $validated = $request->validate([
            'month' => 'required|integer|min:1|max:12',
            'year' => 'required|integer|min:2020|max:2100',
            'comment' => 'nullable|string',
            'score' => 'required|array',
            'score.*' => 'nullable|numeric|min:0|max:100',
            'note' => 'nullable|array',
        ]);

        $criteria = KpiCriterion::active()->get()->keyBy('id');

        // Tính điểm tổng theo trọng số: sum(score*weight)/sum(weight)
        $weightedSum = 0;
        $weightTotal = 0;
        foreach ($validated['score'] as $criterionId => $score) {
            if (! isset($criteria[$criterionId]) || $score === null || $score === '') {
                continue;
            }
            $w = (float) $criteria[$criterionId]->weight;
            $weightedSum += (float) $score * $w;
            $weightTotal += $w;
        }
        $total = $weightTotal > 0 ? round($weightedSum / $weightTotal, 2) : 0;

        $evaluation = KpiEvaluation::updateOrCreate(
            ['user_id' => $userId, 'month' => $validated['month'], 'year' => $validated['year']],
            [
                'evaluator_id' => Auth::id(),
                'total_score' => $total,
                'comment' => $validated['comment'] ?? null,
                'status' => 'confirmed',
            ]
        );

        foreach ($validated['score'] as $criterionId => $score) {
            if (! isset($criteria[$criterionId]) || $score === null || $score === '') {
                continue;
            }
            KpiEvaluationItem::updateOrCreate(
                ['kpi_evaluation_id' => $evaluation->id, 'kpi_criterion_id' => $criterionId],
                ['score' => $score, 'note' => $validated['note'][$criterionId] ?? null]
            );
        }

        return redirect()->route('kpi.monthly', ['month' => $validated['month'], 'year' => $validated['year']])
            ->with('success', "Đã lưu đánh giá KPI (Tổng điểm: {$total}%).");
    }

    // ───────────────────── RÀ SOÁT ĐIỂM DANH (Admin học vụ) ─────────────────────
    public function attendanceReview(Request $request)
    {
        $this->guard();
        $date = $request->input('date', now()->toDateString());
        $classId = $request->input('class_id');

        $query = StudentAttendance::with(['student', 'classModel', 'teacher'])
            ->whereDate('session_date', $date);
        if ($classId) {
            $query->where('class_id', $classId);
        }
        $records = $query->latest('id')->get();

        $summary = [
            'present' => $records->where('status', 'present')->count(),
            'late' => $records->where('status', 'late')->count(),
            'absent' => $records->where('status', 'absent')->count(),
            'excused' => $records->where('status', 'excused')->count(),
        ];

        $classes = ClassModel::orderBy('name')->get();

        return view('kpi.attendance-review', compact('records', 'summary', 'classes', 'date', 'classId'));
    }

    public function reviewAttendance(Request $request, int $id)
    {
        $this->guard('kpi.confirm');
        $validated = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'review_note' => ['nullable', 'string', 'max:1000'],
        ]);
        $attendance = StudentAttendance::findOrFail($id);
        $attendance->update([
            'review_status' => $validated['decision'],
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
            'review_note' => $validated['review_note'] ?? null,
        ]);

        $student = Student::find($attendance->student_id);
        if ($student) {
            $approved = StudentAttendance::where('student_id', $student->id)->where('review_status', 'approved');
            $student->update([
                'attended_lessons' => (clone $approved)->whereIn('status', ['present', 'late'])->count(),
                'total_lessons' => max($student->total_lessons, (clone $approved)->count()),
            ]);
        }

        return redirect()->back()->with('success', $validated['decision'] === 'approved' ? 'Đã duyệt điểm danh.' : 'Đã trả lại điểm danh để chỉnh sửa.');
    }
}
