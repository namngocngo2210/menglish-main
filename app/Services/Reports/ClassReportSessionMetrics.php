<?php

namespace App\Services\Reports;

use App\Http\Controllers\TeacherPortalController;
use App\Models\AcademicRecord;
use App\Models\ClassSession;
use App\Models\Homework;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Services\Sla\Sla;
use App\Support\Dashboard\TeachingQuality;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Số liệu tự động của một buổi trong báo cáo trực lớp (chủ dự án 17/10/2026, khối "Sĩ số & nề nếp"): sĩ số và đi muộn theo điểm
 * danh của GV, BTVN đã nộp của bài giao gần nhất trước buổi, nhận xét buổi của GV (đã gửi / hạn gửi). Cùng khung mục với các báo cáo
 * khác (hiển thị bằng DailyMetric).
 */
class ClassReportSessionMetrics
{
    /** Bài tập không đặt hạn: hạn mặc định sau khi giao (như TeachingQuality). */
    private const HOMEWORK_DEFAULT_DAYS = 7;

    /**
     * @param  Collection<int, ClassSession>  $sessions  buổi của cùng một lớp
     * @param  Collection<int, Student>  $roster  học viên của lớp
     * @return array<int, list<array<string, mixed>>> id buổi => các mục số liệu
     */
    public static function forSessions(Collection $sessions, Collection $roster): array
    {
        if ($sessions->isEmpty()) {
            return [];
        }
        $attendance = StudentAttendance::with('student:id,name')->whereIn('class_session_id', $sessions->pluck('id'))->get()->groupBy('class_session_id');
        $remarks = AcademicRecord::where('module', 'teacher_remarks')->where('status', 'completed')
            ->whereIn('record_code', $sessions->flatMap(fn (ClassSession $s) => [TeacherPortalController::remarkRecordCode($s), self::legacyCode($s)])->all())
            ->get(['record_code', 'updated_at'])->keyBy('record_code');
        $homeworks = Homework::where('class_id', $sessions->first()->class_id)
            ->where('created_at', '<', $sessions->max(fn (ClassSession $s) => self::startsAt($s)))
            ->orderByDesc('created_at')->get(['id', 'title', 'created_at', 'due_date', 'due_at']);
        $studentIds = $roster->pluck('id')->map(fn ($id) => (string) $id)->values();
        $submissions = $homeworks->isEmpty() || $studentIds->isEmpty() ? collect() : AcademicRecord::where('screen_key', TeachingQuality::SUBMISSION_SCREEN)
            ->whereIn('data->student_id', $studentIds->all())->where('created_at', '>=', $homeworks->min('created_at'))
            ->get(['data', 'created_at'])->groupBy(fn (AcademicRecord $r) => (string) data_get($r->data, 'student_id'));
        $hours = Sla::value('gv.remarks_late');

        return $sessions->mapWithKeys(function (ClassSession $s) use ($attendance, $remarks, $homeworks, $submissions, $studentIds, $hours) {
            $rows = $attendance->get($s->id, collect());
            $present = $rows->whereIn('status', ['present', 'late'])->count();
            $absent = $rows->whereIn('status', ['absent', 'excused']);
            $late = $rows->where('status', 'late');
            $homework = $homeworks->first(fn (Homework $h) => $h->created_at->lt(self::startsAt($s)));
            $remark = $remarks->get(TeacherPortalController::remarkRecordCode($s)) ?? $remarks->get(self::legacyCode($s));
            $due = self::endsAt($s)->addHours($hours);

            return [(int) $s->id => [
                self::item('attendance', 'Sĩ số', $rows->isEmpty() ? '—' : "{$present} / {$rows->count()}", 'Học viên có mặt / đi muộn trên tổng lượt điểm danh GV đã nhập cho buổi này.',
                    $rows->isEmpty()
                        ? [['text' => 'GV chưa điểm danh buổi này']]
                        : $absent->map(fn (StudentAttendance $a) => ['text' => $a->student?->name ?? 'Học viên', 'tag' => $a->status === 'excused' ? 'Có phép' : 'Vắng', 'tone' => 'warning'])->values()->all()),
                self::item('late', 'Đi muộn', $rows->isEmpty() ? '—' : (string) $late->count(), 'Học viên GV điểm danh "Đi muộn" ở buổi này.',
                    $late->map(fn (StudentAttendance $a) => ['text' => $a->student?->name ?? 'Học viên'])->values()->all()),
                self::item('homework', 'BTVN đã nộp', $homework ? self::submitted($homework, $submissions, $studentIds).' / '.$studentIds->count() : '—',
                    'Học viên nộp bài tập giao gần nhất trước buổi này, trong hạn nộp của bài (không đặt hạn: 7 ngày sau khi giao).',
                    $homework ? [['text' => 'Bài: '.$homework->title.' · giao '.$homework->created_at->format('d/m')]] : [['text' => 'Không có BTVN giao trước buổi này']]),
                self::item('remarks', 'Nhận xét GV', $remark ? 'Đã gửi' : 'Chưa gửi', 'Nhận xét học viên của GV cho buổi này (bản đã gửi). Hạn gửi theo SLA "Nhận xét sau buổi học".',
                    $remark
                        ? [['text' => 'Gửi lúc '.$remark->updated_at->format('H:i d/m'), ...($remark->updated_at->gt($due) ? ['tag' => 'Trễ hạn', 'tone' => 'error'] : [])]]
                        : [['text' => 'Hạn '.$due->format('H:i d/m'), ...($due->isPast() ? ['tag' => 'Quá hạn', 'tone' => 'error'] : [])]]),
            ]];
        })->all();
    }

    private static function submitted(Homework $homework, Collection $submissions, Collection $studentIds): int
    {
        $deadline = $homework->due_at ?? ($homework->due_date ? Carbon::parse($homework->due_date)->endOfDay() : $homework->created_at->copy()->addDays(self::HOMEWORK_DEFAULT_DAYS));

        return $studentIds->filter(fn (string $id) => $submissions->get($id, collect())
            ->contains(fn (AcademicRecord $r) => $r->created_at->between($homework->created_at, $deadline)))->count();
    }

    private static function startsAt(ClassSession $s): Carbon
    {
        return $s->start_time ? $s->date->copy()->setTimeFrom($s->start_time) : $s->date->copy()->startOfDay();
    }

    private static function endsAt(ClassSession $s): Carbon
    {
        return $s->end_time ? $s->date->copy()->setTimeFrom($s->end_time) : $s->date->copy()->endOfDay();
    }

    private static function legacyCode(ClassSession $s): string
    {
        return $s->class_id.'-'.$s->date->toDateString();
    }

    private static function item(string $key, string $label, string $value, string $info, array $details = []): array
    {
        return ['key' => $key, 'label' => $label, 'value' => $value, 'info' => $info, 'details' => $details];
    }
}
