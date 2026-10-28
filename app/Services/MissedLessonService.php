<?php

namespace App\Services;

use App\Models\ClassReport;
use App\Models\ClassReportStudentSupport;
use App\Models\ClassSession;
use App\Models\Homework;
use App\Models\StudentAttendance;
use Illuminate\Support\Collection;

/**
 * Thông tin buổi học sinh vắng để Học vụ chuyển cho người bổ trợ (chủ dự án 18/10/2026): buổi thứ mấy, bài / từ vựng /
 * ngữ pháp theo giáo trình, lớp đã học gì và nhật ký dạy (báo cáo trực lớp), bài tập về nhà giao ở buổi đó.
 * Chỉ dòng bổ trợ nguồn "Vắng học" có buổi gốc (source_id = điểm danh).
 */
class MissedLessonService
{
    public function __construct(private readonly SessionLessonService $lessons) {}

    /**
     * @param  Collection<int, ClassReportStudentSupport>  $items
     * @return array<int, array{session_id: int, lesson_id: ?int, label: string, content: ?string}> id dòng bổ trợ => buổi vắng
     */
    public function forSupports(Collection $items): array
    {
        $attendanceIds = $items->where('source', SupportListService::SOURCE_ATTENDANCE)->pluck('source_id')->filter()->all();
        if ($attendanceIds === []) {
            return [];
        }

        $sessionByAttendance = StudentAttendance::whereIn('id', $attendanceIds)->whereNotNull('class_session_id')
            ->pluck('class_session_id', 'id');
        $sessions = ClassSession::whereIn('id', $sessionByAttendance->values()->unique())->get()->keyBy('id');
        $details = $this->forSessions($sessions);

        $result = [];
        foreach ($items as $item) {
            $sessionId = $item->source === SupportListService::SOURCE_ATTENDANCE ? ($sessionByAttendance[$item->source_id] ?? null) : null;
            if ($sessionId && isset($details[$sessionId])) {
                $result[$item->id] = $details[$sessionId];
            }
        }

        return $result;
    }

    /**
     * @param  Collection<int, ClassSession>  $sessions
     * @return array<int, array{session_id: int, lesson_id: ?int, label: string, content: ?string}>
     */
    public function forSessions(Collection $sessions): array
    {
        if ($sessions->isEmpty()) {
            return [];
        }

        $ids = $sessions->pluck('id')->all();
        $lessons = $this->lessons->lessonsFor($sessions);
        // Báo cáo trực lớp bị trả lại không dùng; mỗi buổi lấy báo cáo mới nhất.
        $reports = ClassReport::whereIn('class_session_id', $ids)
            ->where('status', '!=', ClassReport::STATUS_REJECTED)
            ->latest('id')->get(['id', 'class_session_id', 'topics_learned', 'teaching_log'])
            ->unique('class_session_id')->keyBy('class_session_id');
        $homeworks = Homework::whereIn('class_session_id', $ids)->orderBy('id')->get(['id', 'class_session_id', 'title'])
            ->groupBy('class_session_id');

        $result = [];
        foreach ($sessions as $session) {
            $lesson = $lessons[$session->id] ?? null;
            $report = $reports->get($session->id);
            $homework = $homeworks->get($session->id)?->pluck('title')->filter()->implode('; ');
            $content = collect([
                $lesson['summary'] ?? null,
                filled($report?->topics_learned) ? 'Lớp đã học: '.trim($report->topics_learned) : null,
                filled($report?->teaching_log) ? 'Nhật ký dạy: '.trim($report->teaching_log) : null,
                filled($homework) ? 'BTVN: '.$homework : null,
            ])->filter()->implode("\n");

            $result[$session->id] = [
                'session_id' => (int) $session->id,
                'lesson_id' => $lesson['lesson_id'] ?? null,
                'label' => trim(($lesson ? 'Buổi '.$lesson['no'].' · ' : '').$session->date->format('d/m/Y')
                    .($session->start_time ? ' '.$session->start_time->format('H:i') : '')),
                'content' => $content !== '' ? $content : null,
            ];
        }

        return $result;
    }
}
