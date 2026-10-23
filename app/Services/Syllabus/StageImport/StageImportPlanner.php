<?php

namespace App\Services\Syllabus\StageImport;

use App\Models\SyllabusAssignment;
use App\Models\SyllabusCurriculum;
use App\Models\SyllabusLesson;
use App\Models\SyllabusStage;
use App\Models\SyllabusUnit;
use Illuminate\Support\Str;

/**
 * Kiểm tra file chặng (Sheet 1) và so với chặng hiện có (docs/kho-syllabus.md §3.3):
 * - Loại buổi Học / Mini test / Big test; Mốc thông báo Order in ấn / mini test / Big Test; link phải là URL (ô giữ chỗ
 *   "link PPT"... coi như trống); ô UNIT "Unit 1: Tên" mở unit mới, ô trống = unit của dòng trên.
 * - Cập nhật chặng đã có: buổi thứ i của file ghi đè buổi thứ i của chặng (giữ id), thừa → tạo, thiếu → xóa mềm.
 */
class StageImportPlanner
{
    private const TYPES = ['học' => 'lesson', 'mini test' => 'mini_test', 'big test' => 'big_test'];

    private const PLACEHOLDERS = ['link ppt', 'link quizizz', 'link đề', 'link quizizz (nếu test online)'];

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

            $type = $row->type === null ? 'lesson' : (self::TYPES[$this->normalize($row->type)] ?? null);
            if ($type === null) {
                $error("Loại buổi “{$row->type}” không hợp lệ (Học / Mini test / Big test).");
            }
            $milestone = $this->milestone($row->milestone);
            if ($milestone === false) {
                $error("Mốc thông báo “{$row->milestone}” không hợp lệ (Order in ấn / Order mini test / Order Big Test).");
            }
            $ppt = $this->link($row->pptUrl);
            $quizizz = $this->link($row->quizizzUrl);
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
                    'title' => $this->title($row->content, $type ?? 'lesson', $index + 1),
                    'lesson_type' => $type ?? 'lesson',
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
        $openClasses = $stage ? SyllabusAssignment::open()->where('stage_id', $stage->id)->count() : 0;
        if ($openClasses > 0) {
            $warnings[] = "{$openClasses} lớp đang học chặng này — nội dung mới áp dụng ngay cho các lớp.";
        }

        return new StageImportPlan(
            stageId: $stage?->id,
            stageName: $sheet->stageName !== '' ? $sheet->stageName : ($stage?->name ?? 'Chặng mới'),
            lessons: $lessons,
            deleteLessonIds: $deleteIds,
            units: array_values($units),
            errors: $errors,
            warnings: $warnings,
            openClasses: $openClasses,
        );
    }

    private function normalize(string $text): string
    {
        return Str::lower(trim(preg_replace('/\s+/u', ' ', $text)));
    }

    /** @return string|null|false mã mốc; null = trống; false = không hợp lệ */
    private function milestone(?string $text): string|null|false
    {
        if ($text === null) {
            return null;
        }
        $text = $this->normalize($text);

        return match (true) {
            str_contains($text, 'big test') => 'order_big_test',
            str_contains($text, 'mini test') => 'order_mini_test',
            str_contains($text, 'in ấn') => 'order_print',
            default => false,
        };
    }

    /** @return string|null|false URL; null = trống / ô giữ chỗ; false = không phải URL http(s) */
    private function link(?string $text): string|null|false
    {
        if ($text === null || in_array($this->normalize($text), self::PLACEHOLDERS, true)) {
            return null;
        }

        return filter_var($text, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $text) && mb_strlen($text) <= 500 ? $text : false;
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
