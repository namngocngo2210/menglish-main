<?php

namespace App\Http\Controllers;

use App\Models\ClassLessonOverride;
use App\Models\ClassModel;
use App\Models\SyllabusAssignment;
use App\Models\SyllabusLesson;
use App\Services\Syllabus\DriveAccessSync;
use App\Services\Syllabus\SyllabusNotifier;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Syllabus của lớp (docs/kho-syllabus.md §7.3 bước 5): Học thuật chỉnh sửa thủ công từng buổi cho 1 lớp (bản đè + lý do),
 * GV của lớp được báo (ĐÃ XEM). Dữ liệu tab: App\Services\Syllabus\ClassSyllabusTab.
 */
class SyllabusClassController extends Controller
{
    public function __construct(private readonly SyllabusNotifier $notifier) {}

    public function override(Request $request, int $class, int $lesson)
    {
        [$classModel, $lessonModel] = $this->resolve($request, $class, $lesson);
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'lesson_type' => ['nullable', Rule::in(array_keys(SyllabusLesson::TYPES))],
            'objectives' => ['nullable', 'string', 'max:10000'],
            'content' => ['nullable', 'string', 'max:10000'],
            'procedure' => ['nullable', 'string', 'max:10000'],
            'activity_1' => ['nullable', 'string', 'max:5000'],
            'activity_2' => ['nullable', 'string', 'max:5000'],
            'homework_guide' => ['nullable', 'string', 'max:5000'],
            'homework_quizizz' => ['nullable', 'string', 'max:5000'],
            'homework_video' => ['nullable', 'string', 'max:5000'],
            'ppt_url' => ['nullable', 'url', 'max:500'],
            'ppt_foreign_url' => ['nullable', 'url', 'max:500'],
            'quizizz_url' => ['nullable', 'url', 'max:500'],
            'test_url' => ['nullable', 'url', 'max:500'],
            'output_product' => ['nullable', 'string', 'max:5000'],
            'notice_milestone' => ['nullable', Rule::in(array_keys(SyllabusLesson::MILESTONES))],
            'note' => ['nullable', 'string', 'max:5000'],
            'reason' => ['required', 'string', 'max:2000'],
        ], ['reason.required' => 'Vui lòng nhập lý do chỉnh sửa riêng cho lớp.']);

        // Chỉ lưu trường khác bản gốc: trường giữ nguyên vẫn nhận thay đổi của bản gốc sau này (Sheet 4 câu 3).
        $data = [];
        foreach (ClassLessonOverride::FIELDS as $field) {
            if (! array_key_exists($field, $validated)) {
                continue;
            }
            $value = $validated[$field] === '' ? null : $validated[$field];
            if ($value !== $lessonModel->{$field}) {
                $data[$field] = $value;
            }
        }
        if ($data === []) {
            ClassLessonOverride::where('class_id', $classModel->id)->where('syllabus_lesson_id', $lessonModel->id)->first()?->delete();

            return redirect()->back()->with('status', "Buổi {$lessonModel->session_no} giống bản gốc — không lưu chỉnh sửa riêng.");
        }

        ClassLessonOverride::updateOrCreate(
            ['class_id' => $classModel->id, 'syllabus_lesson_id' => $lessonModel->id],
            ['data' => $data, 'reason' => $validated['reason'], 'edited_by' => $request->user()->id],
        );
        $this->notifier->classSyllabusUpdated($classModel, collect([$lessonModel->session_no]));

        return redirect()->back()->with('status', "Đã lưu chỉnh sửa riêng Buổi {$lessonModel->session_no} cho lớp {$classModel->name}.");
    }

    public function destroyOverride(Request $request, int $class, int $lesson)
    {
        [$classModel, $lessonModel] = $this->resolve($request, $class, $lesson);
        $override = ClassLessonOverride::where('class_id', $classModel->id)->where('syllabus_lesson_id', $lessonModel->id)->firstOrFail();
        $override->delete();
        $this->notifier->classSyllabusUpdated($classModel, collect([$lessonModel->session_no]));

        return redirect()->back()->with('status', "Buổi {$lessonModel->session_no} của lớp {$classModel->name} đã quay về bản gốc.");
    }

    /** Học thuật "Đồng bộ lại" quyền xem Google Drive của chặng lớp đang học (thử lại ngay, kể cả quyền đã lỗi). */
    public function syncDrive(Request $request, int $class, DriveAccessSync $sync)
    {
        $classModel = ClassModel::visibleTo($request->user())->findOrFail($class);
        $assignment = SyllabusAssignment::with(['classModel', 'stage'])->open()->where('class_id', $classModel->id)->firstOrFail();
        if (! $sync->configured()) {
            return redirect()->back()->with('error', 'Chưa kết nối Google Drive (thiếu khóa service account trên server).');
        }
        $result = $sync->syncAssignment($assignment);

        return redirect()->back()->with($result['failed'] ? 'warning' : 'status',
            "Đồng bộ Google Drive: mở {$result['granted']} quyền".($result['failed'] ? ", lỗi {$result['failed']} — xem cột Lỗi." : '.'));
    }

    /** @return array{0: ClassModel, 1: SyllabusLesson} lớp trong phạm vi + buổi thuộc chặng lớp đang học */
    private function resolve(Request $request, int $class, int $lesson): array
    {
        $classModel = ClassModel::visibleTo($request->user())->findOrFail($class);
        $stageId = SyllabusAssignment::open()->where('class_id', $classModel->id)->value('stage_id');
        $lessonModel = $stageId
            ? SyllabusLesson::whereKey($lesson)->whereHas('unit', fn ($q) => $q->where('stage_id', $stageId))->first()
            : null;
        if (! $lessonModel) {
            throw ValidationException::withMessages(['lesson' => 'Buổi này không thuộc chặng lớp đang học.']);
        }

        return [$classModel, $lessonModel];
    }
}
