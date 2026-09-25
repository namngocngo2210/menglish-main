<?php

namespace App\Services;

use App\Models\BigTestResult;
use App\Models\ClassReportStudentSupport;
use App\Models\MiniTestScore;
use App\Models\StudentAttendance;

/**
 * Danh sách bổ trợ (BPMN bước 11–12): học viên vắng học, điểm mini test hoặc Big Test dưới 7
 * được tự đưa vào danh sách để Học vụ xếp buổi bổ trợ. Mỗi (học viên, lớp, nguồn, bản ghi nguồn)
 * chỉ có 1 dòng — lưu lại/chấm lại không tạo trùng. Khi dữ liệu nguồn được sửa (vắng → có mặt,
 * điểm nâng lên ≥ 7) thì dòng tự gỡ nếu chưa xếp buổi bổ trợ.
 */
class SupportListService
{
    public const SOURCE_CLASS_REPORT = 'class_report';

    public const SOURCE_ATTENDANCE = 'attendance';

    public const SOURCE_MINI_TEST = 'mini_test';

    public const SOURCE_BIG_TEST = 'big_test';

    public const SOURCE_LABELS = [
        self::SOURCE_CLASS_REPORT => 'Báo cáo trực lớp',
        self::SOURCE_ATTENDANCE => 'Vắng học',
        self::SOURCE_MINI_TEST => 'Mini test < 7',
        self::SOURCE_BIG_TEST => 'Big Test < 7',
    ];

    /** Ngưỡng điểm (thang 10): dưới ngưỡng thì cần bổ trợ. */
    public const SCORE_THRESHOLD = 7.0;

    /** Trạng thái điểm danh coi là vắng buổi (cần học bổ trợ phần đã lỡ). */
    public const ABSENT_STATUSES = ['absent', 'excused'];

    public function syncAttendance(StudentAttendance $attendance): void
    {
        if (! in_array($attendance->status, self::ABSENT_STATUSES, true)) {
            $this->forget(self::SOURCE_ATTENDANCE, $attendance->id);

            return;
        }

        $session = $attendance->classSession;
        $date = ($session?->date ?? $attendance->session_date)?->format('d/m/Y');
        $label = trim('Buổi '.$date.' '.($session?->shift_name ?? ''));

        $this->put(self::SOURCE_ATTENDANCE, (int) $attendance->id, (int) $attendance->student_id, (int) $attendance->class_id, [
            'absence_session' => $label,
            'reason' => ($attendance->status === 'excused' ? 'Vắng có phép' : 'Vắng học').' '.$label
                .($attendance->note ? ' — '.$attendance->note : ''),
            'score' => null,
        ]);
    }

    public function syncMiniTest(MiniTestScore $score): void
    {
        $max = (float) $score->max_score;
        $scaled = $max > 0 ? round((float) $score->score / $max * 10, 2) : null;
        if ($scaled === null || $scaled >= self::SCORE_THRESHOLD) {
            $this->forget(self::SOURCE_MINI_TEST, $score->id);

            return;
        }

        $this->put(self::SOURCE_MINI_TEST, (int) $score->id, (int) $score->student_id, (int) $score->class_id, [
            'absence_session' => null,
            'reason' => "Điểm {$score->name} ngày ".$score->test_date?->format('d/m/Y').': '
                .rtrim(rtrim(number_format((float) $score->score, 2, '.', ''), '0'), '.').'/'
                .rtrim(rtrim(number_format($max, 2, '.', ''), '0'), '.')." (quy đổi {$scaled}/10)",
            'score' => $scaled,
        ]);
    }

    public function syncBigTest(BigTestResult $result): void
    {
        $classId = (int) ($result->bigTest?->class_id ?? 0);
        $overall = $result->overall_score;
        if (! $classId || $result->is_absent || $overall === null || (float) $overall >= self::SCORE_THRESHOLD) {
            $this->forget(self::SOURCE_BIG_TEST, $result->id);

            return;
        }

        $this->put(self::SOURCE_BIG_TEST, (int) $result->id, (int) $result->student_id, $classId, [
            'absence_session' => null,
            'reason' => 'Big Test "'.($result->bigTest?->title ?? '#'.$result->big_test_id).'": '.$overall.'/10',
            'score' => (float) $overall,
        ]);
    }

    /** Gỡ dòng bổ trợ của bản ghi nguồn nếu chưa xếp buổi (đã xếp thì giữ lịch sử). */
    public function forget(string $source, ?int $sourceId): void
    {
        if (! $sourceId) {
            return;
        }

        ClassReportStudentSupport::where('source', $source)
            ->where('source_id', $sourceId)
            ->whereDoesntHave('supportSession')
            ->delete();
    }

    private function put(string $source, int $sourceId, int $studentId, int $classId, array $values): void
    {
        ClassReportStudentSupport::updateOrCreate(
            ['student_id' => $studentId, 'class_id' => $classId, 'source' => $source, 'source_id' => $sourceId],
            $values
        );
    }
}
