<?php

namespace App\Http\Controllers;

use App\Models\AcademicRecord;
use App\Models\ClassModel;
use App\Models\SyllabusAssignment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * TK-2026-0058 — "Feedback phụ huynh" theo lớp / chặng cho Học vụ / Admin (class_quality.view): chọn lớp → chặng → bảng học viên
 * (Nguồn / Cập nhật lúc / Trạng thái) và công tắc mở thu thập feedback theo chặng (class_quality.parent_feedback).
 * Feedback do phụ huynh gửi từ cổng học viên (AcademicRecord SCREEN_KEY) được gắn theo tên chặng; cổng chỉ nhận khi chặng đang mở.
 */
class ParentFeedbackController extends Controller
{
    public const SCREEN_KEY = '04_Cong_Phu_Huynh_Hoc_Sinh/07_phu_huynh_gui_feedback';

    public function index(Request $request): Response
    {
        $classes = ClassModel::query()->visibleTo($request->user())->orderBy('name')->get(['id', 'name', 'code']);
        $class = $classes->firstWhere('id', $request->integer('class_id')) ?? $classes->first();

        $stages = $class
            ? SyllabusAssignment::query()->where('class_id', $class->id)->orderByDesc('id')
                ->get(['id', 'stage_name', 'status', 'feedback_open'])
            : collect();
        $stage = $stages->firstWhere('id', $request->integer('stage_id')) ?? $stages->firstWhere('status', SyllabusAssignment::STATUS_OPEN) ?? $stages->first();

        $rows = [];
        if ($class && $stage) {
            $records = AcademicRecord::query()->where('screen_key', self::SCREEN_KEY)
                ->where('data->stage_name', $stage->stage_name)
                ->get()->keyBy(fn (AcademicRecord $r) => (string) ($r->data['student_id'] ?? ''));
            $rows = $class->rosterStudents()->map(function ($s) use ($records) {
                $record = $records->get((string) $s->id);

                return [
                    'id' => $s->id,
                    'name' => $s->name,
                    'source' => $record ? 'Cổng phụ huynh' : null,
                    'updated_at' => $record?->updated_at?->toIso8601String(),
                    'status' => $record ? 'submitted' : 'pending',
                ];
            })->values()->all();
        }

        return Inertia::render('ClassQuality/ParentFeedback', [
            'classes' => $classes->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'code' => $c->code])->values()->all(),
            'classId' => $class?->id,
            'stages' => $stages->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->stage_name,
                'current' => $s->status === SyllabusAssignment::STATUS_OPEN,
                'open' => (bool) $s->feedback_open,
            ])->values()->all(),
            'stageId' => $stage?->id,
            'rows' => $rows,
            'canToggle' => $request->user()->can('class_quality.parent_feedback'),
        ]);
    }

    public function toggle(Request $request, int $assignment): RedirectResponse
    {
        $data = $request->validate(['open' => ['required', 'boolean']]);
        // Lớp ngoài phạm vi người xem → 404 (không lộ chặng của lớp khác).
        $stage = SyllabusAssignment::query()
            ->whereIn('class_id', ClassModel::query()->visibleTo($request->user())->select('id'))
            ->findOrFail($assignment);
        $stage->update(['feedback_open' => (bool) $data['open']]);

        return back()->with('success', $stage->feedback_open ? 'Đã mở thu thập feedback cho chặng.' : 'Đã đóng thu thập feedback cho chặng.');
    }
}
