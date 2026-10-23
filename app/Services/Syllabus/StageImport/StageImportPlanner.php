<?php

namespace App\Services\Syllabus\StageImport;

use App\Models\SyllabusAssignment;
use App\Models\SyllabusChangeProposal;
use App\Models\SyllabusCurriculum;
use App\Models\SyllabusDocument;
use App\Models\SyllabusLesson;
use App\Models\SyllabusStage;
use App\Models\SyllabusUnit;
use App\Services\Syllabus\LessonSheetCells;
use Illuminate\Support\Str;

/**
 * Kiểm tra file chặng (Sheet 1) và so với chặng hiện có (docs/kho-syllabus.md §3.3):
 * - Loại buổi Học / Mini test / Big test; Mốc thông báo Order in ấn / mini test / Big Test; link phải là URL (ô giữ chỗ
 *   "link PPT"... coi như trống); ô UNIT "Unit 1: Tên" mở unit mới, ô trống = unit của dòng trên.
 * - Cập nhật chặng đã có: buổi thứ i của file ghi đè buổi thứ i của chặng (giữ id), thừa → tạo, thiếu → xóa mềm.
 */
class StageImportPlanner
{
    private const TITLE_MAX = 120;

    public function plan(StageSheet $sheet, SyllabusCurriculum $curriculum, ?SyllabusStage $stage): StageImportPlan
    {
        $errors = [];
        $lessons = [];
        $units = [];
        $currentUnit = null;

        if ($sheet->rows === []) {
            $errors[] = ['line' => 0, 'message' => 'File chưa có buổi nào (dòng ví dụ nền xám đã được bỏ qua).'];
        }

        $existingUnits = SyllabusUnit::where('curriculum_id', $curriculum->id)->get()->keyBy('unit_number');
        foreach ($sheet->rows as $index => $row) {
            $error = function (string $message) use (&$errors, $row): void {
                $errors[] = ['line' => $row->line, 'message' => $message];
            };

            if ($row->unitCell !== null) {
                if (preg_match('/^unit\s*(\d+)\s*[:\-–]\s*(.+)$/iu', $row->unitCell, $m)) {
                    $currentUnit = (int) $m[1];
                    $existing = $existingUnits->get($currentUnit);
                    if ($existing && $existing->stage_id !== $stage?->id) {
                        $error("Unit {$currentUnit} đã thuộc chặng khác của giáo trình — mỗi unit chỉ thuộc 1 chặng.");
                    }
                    $units[$currentUnit] ??= ['unit_number' => $currentUnit, 'title' => trim($m[2]), 'objectives' => null, 'unit_id' => $existing?->stage_id === $stage?->id ? $existing?->id : null];
                    $units[$currentUnit]['objectives'] = $row->unitObjectives ?? $units[$currentUnit]['objectives'];
                } else {
                    $error('Ô UNIT phải có dạng “Unit 1: Tên unit”.');
                }
            } elseif ($currentUnit === null) {
                $error('Chưa có UNIT — ghi “Unit 1: Tên unit” ở buổi đầu tiên của unit.');
            }

            $type = LessonSheetCells::type($row->type) ?? 'lesson';
            if ($type === false) {
                $error("Loại buổi “{$row->type}” ".LessonSheetCells::TYPE_ERROR);
            }
            $milestone = LessonSheetCells::milestone($row->milestone);
            if ($milestone === false) {
                $error("Mốc thông báo “{$row->milestone}” ".LessonSheetCells::MILESTONE_ERROR);
            }
            $ppt = LessonSheetCells::link($row->pptUrl);
            $quizizz = LessonSheetCells::link($row->quizizzUrl);
            if ($ppt === false) {
                $error('Link PPT không phải đường dẫn hợp lệ.');
            }
            if ($quizizz === false) {
                $error('Link Quizizz không phải đường dẫn hợp lệ.');
            }

            $lessons[] = [
                'line' => $row->line,
                'unit_number' => (int) $currentUnit,
                'lesson_id' => null,
                'attributes' => [
                    'title' => $this->title($row->content, $type ?: 'lesson', $index + 1),
                    'lesson_type' => $type ?: 'lesson',
                    'content' => $row->content,
                    'procedure' => $row->procedure,
                    'activity_1' => $row->activity1,
                    'activity_2' => $row->activity2,
                    'homework_guide' => $row->homeworkPaper,
                    'homework_quizizz' => $row->homeworkQuizizz,
                    'homework_video' => $row->homeworkVideo,
                    'ppt_url' => $ppt ?: null,
                    'quizizz_url' => $quizizz ?: null,
                    'output_product' => $row->outputProduct,
                    'notice_milestone' => $milestone ?: null,
                    'note' => $row->note,
                ],
            ];
        }

        // Cập nhật chặng đã có: khớp buổi theo thứ tự trong chặng.
        $deleteIds = [];
        if ($stage) {
            $existingIds = SyllabusLesson::whereHas('unit', fn ($q) => $q->where('stage_id', $stage->id))->orderBy('session_no')->pluck('id')->all();
            foreach ($lessons as $i => $lesson) {
                $lessons[$i]['lesson_id'] = $existingIds[$i] ?? null;
            }
            $deleteIds = array_values(array_slice($existingIds, count($lessons)));
        }

        $warnings = [];
        $label = trim($sheet->curriculumLabel);
        if ($label !== '' && ! Str::contains(Str::lower($label), [Str::lower($curriculum->title), Str::lower($curriculum->code)])) {
            $warnings[] = "Ô Giáo trình trong file là “{$label}”, khác giáo trình đang mở ({$curriculum->title}).";
        }
        if ($sheet->skippedExamples > 0) {
            $warnings[] = "Đã bỏ qua {$sheet->skippedExamples} dòng ví dụ (nền xám) — nếu đó là buổi thật, hãy bỏ màu nền rồi tải lên lại.";
        }
        $updatedIds = array_values(array_filter(array_column($lessons, 'lesson_id')));
        $withDocuments = $updatedIds ? SyllabusDocument::whereIn('syllabus_lesson_id', $updatedIds)->distinct()->count('syllabus_lesson_id') : 0;
        $withProposals = $updatedIds ? SyllabusChangeProposal::whereIn('lesson_id', $updatedIds)->where('status', 'pending')->distinct()->count('lesson_id') : 0;
        if ($withDocuments > 0 || $withProposals > 0) {
            $warnings[] = "Buổi được ghi đè theo thứ tự: {$withDocuments} buổi có tài liệu buổi, {$withProposals} buổi có đề xuất sửa đang chờ — "
                .'tài liệu / đề xuất giữ theo vị trí buổi, kiểm tra lại nếu file chèn hoặc bớt buổi ở giữa chặng.';
        }
        $openClasses = $stage ? SyllabusAssignment::open()->where('stage_id', $stage->id)->count() : 0;
        if ($openClasses > 0) {
            $warnings[] = "{$openClasses} lớp đang học chặng này — nội dung mới áp dụng ngay cho các lớp.";
        }

        return new StageImportPlan(
            stageId: $stage?->id,
            stageName: $this->stageName($sheet->stageName) ?? ($stage?->name ?? 'Chặng mới'),
            lessons: $lessons,
            deleteLessonIds: $deleteIds,
            units: array_values($units),
            errors: $errors,
            warnings: $warnings,
            openClasses: $openClasses,
        );
    }

    /** Tên chặng trong ô D4 bỏ tiền tố "Chặng N" (nhãn tự thêm "Chặng {vị trí}: "); null khi trống. */
    private function stageName(string $text): ?string
    {
        $name = trim(preg_replace('/^chặng\s*\d+\s*[:\-–—]?\s*/iu', '', trim($text)));

        return $name !== '' ? $name : (trim($text) !== '' ? trim($text) : null);
    }

    private function title(?string $content, string $type, int $number): string
    {
        $first = $content === null ? '' : trim(strtok($content, "\n"));
        if ($first !== '') {
            return Str::limit($first, self::TITLE_MAX, '…');
        }

        return match ($type) {
            'mini_test' => 'Mini test',
            'big_test' => 'Big test',
            default => "Buổi {$number}",
        };
    }
}
