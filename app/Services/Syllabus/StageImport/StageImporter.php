<?php

namespace App\Services\Syllabus\StageImport;

use App\Models\SyllabusCurriculum;
use App\Models\SyllabusLesson;
use App\Models\SyllabusStage;
use App\Models\SyllabusUnit;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Áp dụng StageImportPlan trong 1 transaction: tạo chặng mới (cuối giáo trình) hoặc cập nhật chặng, upsert unit, sửa /
 * tạo / xóa mềm buổi, rồi đánh lại session_no liên tục cả giáo trình. Ghi 1 dòng lịch sử tổng hợp thay vì từng buổi.
 */
class StageImporter
{
    /** Giá trị tạm khi đánh lại số buổi (UNIQUE curriculum_id + session_no; buổi xóa mềm giữ -id nên không dùng số âm). */
    private const TEMP_OFFSET = 300000;

    public function import(StageImportPlan $plan, SyllabusCurriculum $curriculum, User $user): SyllabusStage
    {
        $stage = DB::transaction(fn () => activity()->withoutLogs(fn () => $this->apply($plan, $curriculum)));

        $s = $plan->summary();
        activity(Audit::logNameFor(SyllabusCurriculum::class))
            ->performedOn($stage)
            ->causedBy($user)
            ->event('imported')
            ->withProperties(['curriculum_id' => $curriculum->id] + $s)
            ->log("Import chặng từ file: {$stage->label} — {$s['create']} thêm, {$s['update']} sửa, {$s['delete']} xóa");

        return $stage;
    }

    /** Đánh lại session_no 1..N theo thứ tự chặng, rồi thứ tự hiện có trong chặng (2 lượt để không vướng UNIQUE). */
    public function renumberSessions(int $curriculumId): void
    {
        // Buổi xóa mềm trước bản sửa MariaDB (8c782125) có thể còn giữ số dương → nhả số (-id) để không chặn UNIQUE.
        SyllabusLesson::onlyTrashed()->where('curriculum_id', $curriculumId)->where('session_no', '>', 0)
            ->update(['session_no' => DB::raw('-id')]);

        $ids = SyllabusLesson::query()
            ->join('syllabus_units', 'syllabus_units.id', '=', 'syllabus_lessons.unit_id')
            ->join('syllabus_stages', 'syllabus_stages.id', '=', 'syllabus_units.stage_id')
            ->where('syllabus_lessons.curriculum_id', $curriculumId)
            ->orderBy('syllabus_stages.position')
            ->orderBy('syllabus_lessons.session_no')
            ->pluck('syllabus_lessons.id')
            ->all();

        foreach ($ids as $rank => $id) {
            SyllabusLesson::whereKey($id)->update(['session_no' => self::TEMP_OFFSET + $rank + 1]);
        }
        foreach ($ids as $rank => $id) {
            SyllabusLesson::whereKey($id)->update(['session_no' => $rank + 1]);
        }
    }

    private function apply(StageImportPlan $plan, SyllabusCurriculum $curriculum): SyllabusStage
    {
        $stage = $plan->stageId
            ? tap(SyllabusStage::findOrFail($plan->stageId))->update(['name' => $plan->stageName])
            : SyllabusStage::create([
                'curriculum_id' => $curriculum->id,
                'position' => (int) SyllabusStage::where('curriculum_id', $curriculum->id)->max('position') + 1,
                'name' => $plan->stageName,
            ]);

        $unitIds = [];
        foreach ($plan->units as $unit) {
            $attributes = ['title' => $unit['title'], 'stage_id' => $stage->id] + array_filter(['objectives' => $unit['objectives']]);
            $unitIds[$unit['unit_number']] = $unit['unit_id']
                ? tap(SyllabusUnit::findOrFail($unit['unit_id']))->update($attributes)->id
                : SyllabusUnit::create($attributes + ['curriculum_id' => $curriculum->id, 'unit_number' => $unit['unit_number']])->id;
        }

        SyllabusLesson::whereKey($plan->deleteLessonIds)->get()->each->delete();

        foreach ($plan->lessons as $i => $lesson) {
            $attributes = $lesson['attributes'] + ['unit_id' => $unitIds[$lesson['unit_number']]];
            if ($lesson['lesson_id']) {
                SyllabusLesson::findOrFail($lesson['lesson_id'])->update($attributes);
            } else {
                // Số tạm sau mọi buổi hiện có; renumberSessions() đặt số thật.
                SyllabusLesson::create($attributes + ['session_no' => self::TEMP_OFFSET * 2 + $i + 1]);
            }
        }

        $this->renumberSessions($curriculum->id);

        return $stage->fresh();
    }
}
