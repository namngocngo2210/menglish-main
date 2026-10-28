<?php

namespace App\Services;

use App\Models\BigTestResult;
use App\Models\ClassReportStudentSupport;
use App\Models\ClassSession;
use App\Models\MiniTestScore;
use App\Models\StudentAttendance;
use App\Models\TeacherSessionReport;
use Illuminate\Support\Collection;

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

    /** "Học sinh cần chú ý" trong Báo cáo buổi học của GV (source_id = teacher_session_reports.id). */
    public const SOURCE_TEACHER_REPORT = 'teacher_report';

    public const SOURCE_LABELS = [
        self::SOURCE_CLASS_REPORT => 'Báo cáo trực lớp',
        self::SOURCE_TEACHER_REPORT => 'Báo cáo buổi GV',
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
        $session = $attendance->classSession;
        // Vắng buổi phụ đạo không tạo thêm dòng bổ trợ: buổi bổ trợ bị hủy, dòng gốc quay lại danh sách chờ (SupportSessionService).
        // Điểm danh Học vụ trả lại để sửa (review_status = rejected) chưa đáng tin → chưa tạo dòng, GV sửa lại thì tạo.
        if (! in_array($attendance->status, self::ABSENT_STATUSES, true) || $session?->type === ClassSession::TYPE_SUPPORT
            || $attendance->review_status === 'rejected') {
            $this->forget(self::SOURCE_ATTENDANCE, $attendance->id);

            return;
        }
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
        // Kết quả nháp (GV chưa gửi duyệt) chưa tính vào danh sách bổ trợ.
        if (! $classId || $result->status === 'draft' || $result->is_absent || $overall === null || (float) $overall >= self::SCORE_THRESHOLD) {
            $this->forget(self::SOURCE_BIG_TEST, $result->id);

            return;
        }

        $this->put(self::SOURCE_BIG_TEST, (int) $result->id, (int) $result->student_id, $classId, [
            'absence_session' => null,
            'reason' => 'Big Test "'.($result->bigTest?->title ?? '#'.$result->big_test_id).'": '.$overall.'/10',
            'score' => (float) $overall,
        ]);
    }

    /**
     * "Học sinh cần chú ý" của một buổi — một danh sách chung cho GV (báo cáo buổi) và TA (báo cáo trực lớp), chủ dự án
     * 27/10/2026: dòng do vắng buổi này, dòng TA báo trong báo cáo trực lớp của buổi, dòng GV báo trong báo cáo buổi.
     *
     * @return Collection<int, ClassReportStudentSupport>
     */
    public function sessionRows(ClassSession $session): Collection
    {
        $attendanceIds = StudentAttendance::where('class_session_id', $session->id)->pluck('id');
        $reportId = TeacherSessionReport::where('class_session_id', $session->id)->value('id');

        return ClassReportStudentSupport::with('student:id,name')
            ->where('class_id', $session->class_id)
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('source', self::SOURCE_ATTENDANCE)->whereIn('source_id', $attendanceIds))
                ->orWhere(fn ($q) => $q->where('source', self::SOURCE_CLASS_REPORT)->whereHas('classReport', fn ($r) => $r->where('class_session_id', $session->id)))
                ->orWhere(fn ($q) => $q->where('source', self::SOURCE_TEACHER_REPORT)->where('source_id', $reportId ?? 0)))
            ->orderBy('id')
            ->get();
    }

    /**
     * Ghi "Học sinh cần chú ý" của báo cáo buổi GV vào danh sách chung. Học sinh đã có dòng của buổi (vắng / TA báo) thì
     * không tạo dòng mới — GV ghi hướng xử lý vào dòng đó nếu dòng chưa có. Học sinh GV bỏ khỏi báo cáo thì gỡ dòng
     * (nếu chưa xếp buổi bổ trợ).
     */
    public function syncTeacherReport(TeacherSessionReport $report, ClassSession $session): void
    {
        $others = $this->sessionRows($session)->where('source', '!=', self::SOURCE_TEACHER_REPORT)->groupBy('student_id');
        $keep = [];
        foreach ((array) ($report->attention ?? []) as $row) {
            $studentId = (int) ($row['student_id'] ?? 0);
            if (! $studentId) {
                continue;
            }
            $action = filled($row['action'] ?? null) ? $row['action'] : null;
            if ($existing = $others->get($studentId)?->first()) {
                if ($action && blank($existing->action_plan)) {
                    $existing->update(['action_plan' => $action]);
                }

                continue;
            }
            $keep[] = $studentId;
            $this->put(self::SOURCE_TEACHER_REPORT, (int) $report->id, $studentId, (int) $report->class_id, [
                'absence_session' => null,
                'reason' => filled($row['reason'] ?? null) ? $row['reason'] : 'GV ghi nhận cần chú ý buổi '.$session->date->format('d/m'),
                'action_plan' => $action,
                'score' => null,
            ]);
        }

        ClassReportStudentSupport::where('source', self::SOURCE_TEACHER_REPORT)->where('source_id', $report->id)
            ->whereNotIn('student_id', $keep ?: [0])
            ->whereDoesntHave('supportSessions')
            ->delete();
    }

    /** Gỡ dòng bổ trợ của bản ghi nguồn nếu chưa xếp buổi (đã xếp thì giữ lịch sử). */
    public function forget(string $source, ?int $sourceId): void
    {
        if (! $sourceId) {
            return;
        }

        ClassReportStudentSupport::where('source', $source)
            ->where('source_id', $sourceId)
            ->whereDoesntHave('supportSessions')
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
