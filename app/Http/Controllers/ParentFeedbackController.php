<?php

namespace App\Http\Controllers;

use App\Models\AcademicRecord;
use App\Models\ClassModel;
use App\Models\Student;
use App\Models\SyllabusAssignment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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
        $stage = $stages->firstWhere('id', $request->integer('stage_id')) ?? SyllabusAssignment::feedbackStageForClass($class?->id);

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
                    'source' => $record ? (($record->data['source'] ?? 'portal') === 'cm' ? 'CM nhập tay' : 'Cổng phụ huynh') : null,
                    'record_id' => $record?->id,
                    'handled' => (bool) ($record?->data['handled'] ?? false),
                    'rating' => isset($record?->data['muc_do_hai_long']) ? (int) $record->data['muc_do_hai_long'] : null,
                    'content' => $record?->data['noi_dung_feedback'] ?? null,
                    'categories' => $record?->data['categories'] ?? [],
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
            'canManage' => $request->user()->can('class_quality.parent_feedback'),
        ]);
    }

    public function storeManual(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'stage_id' => ['required', 'integer', 'exists:syllabus_assignments,id'],
            'muc_do_hai_long' => ['nullable', 'integer', 'min:0', 'max:5'],
            'categories' => ['nullable', 'array'],
            'categories.*' => [Rule::in(['Học thuật', 'Giáo viên', 'Khác'])],
            'noi_dung_feedback' => ['nullable', 'string', 'max:5000'],
        ]);
        $stage = SyllabusAssignment::query()
            ->whereIn('class_id', ClassModel::query()->visibleTo($request->user())->select('id'))
            ->findOrFail($data['stage_id']);
        $class = $stage->classModel;
        $student = $class->roster()->findOrFail($data['student_id']);

        if ((int) ($data['muc_do_hai_long'] ?? 0) === 0 && empty($data['categories']) && trim($data['noi_dung_feedback'] ?? '') === '') {
            return back()->withErrors(['validation' => 'Vui lòng điền ít nhất 1 mục (Nội dung, Lĩnh vực góp ý hoặc Chọn mức hài lòng) để gửi feedback.']);
        }

        $key = [
            'screen_key' => self::SCREEN_KEY,
            'data->student_id' => (string) $student->id,
            'data->stage_name' => $stage->stage_name,
        ];
        $existing = AcademicRecord::query()->where($key)->first();
        $categories = $data['categories'] ?? [];
        AcademicRecord::updateOrCreate($key, [
            'module' => 'student_portal',
            'record_code' => $existing?->record_code ?? 'FB-'.strtoupper(Str::random(6)),
            'title' => 'Feedback chặng: '.$stage->stage_name.' - '.$student->name,
            'status' => $existing?->status ?? 'submitted',
            'data' => array_merge($existing?->data ?? [], [
                'student_id' => (string) $student->id,
                'student_name' => $student->name,
                'stage_name' => $stage->stage_name,
                'class_name' => $class->name,
                'muc_do_hai_long' => isset($data['muc_do_hai_long']) ? (int) $data['muc_do_hai_long'] : null,
                'categories' => $categories,
                'fb_hoc_thuat' => in_array('Học thuật', $categories, true),
                'fb_giao_vien' => in_array('Giáo viên', $categories, true),
                'fb_khac' => in_array('Khác', $categories, true),
                'noi_dung_feedback' => $data['noi_dung_feedback'] ?? '',
                'source' => $existing ? ($existing->data['source'] ?? 'portal') : 'cm',
                'handled' => $existing?->data['handled'] ?? false,
                'handled_at' => $existing?->data['handled_at'] ?? null,
                'handled_by' => $existing?->data['handled_by'] ?? null,
            ]),
            'user_id' => $request->user()->id,
        ]);

        return back()->with('success', 'Đã ghi nhận feedback thành công!');
    }

    public function toggleHandled(Request $request, AcademicRecord $record): RedirectResponse
    {
        abort_unless($record->screen_key === self::SCREEN_KEY, 404);
        $student = Student::query()->find($record->data['student_id'] ?? null);
        abort_unless($student && ClassModel::query()->visibleTo($request->user())->whereKey($student->current_class_id)->exists(), 404);
        $data = $request->validate(['handled' => ['required', 'boolean']]);
        $record->update(['data' => array_merge($record->data, [
            'handled' => (bool) $data['handled'],
            'handled_at' => now()->toIso8601String(),
            'handled_by' => $request->user()->id,
        ])]);

        return back()->with('success', 'Đã cập nhật trạng thái xử lý feedback.');
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
