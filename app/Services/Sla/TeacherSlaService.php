<?php

namespace App\Services\Sla;

use App\Http\Controllers\TeacherPortalController;
use App\Models\AcademicRecord;
use App\Models\ClassSession;
use App\Models\SlaEvent;
use App\Models\SyllabusAssignment;
use App\Services\Syllabus\ConfirmDeadline;
use App\Services\Syllabus\SyllabusNotifier;
use Illuminate\Support\Carbon;

/**
 * SLA vận hành của GV (Quy chế GV): nhận xét sau buổi học trong 12h kể từ khi lớp kết thúc; XÁC NHẬN syllabus chặng chậm nhất
 * 1 giờ trước buổi đầu tiên của chặng (docs/luong-giao-vien.md). Quá hạn → biên bản cho GV theo bậc phạt tái phạm
 * (SlaBreachService), chỉ tính mốc phát sinh sau thời điểm bật.
 */
class TeacherSlaService
{
    private const SINCE_KEY = 'sla_teacher_live_since';

    public function __construct(private SlaBreachService $breaches, private ConfirmDeadline $confirmDeadline, private SyllabusNotifier $notifier) {}

    /** @return array<string, int> */
    public function run(?Carbon $now = null): array
    {
        $now ??= now();
        $since = $this->liveSince($now);

        return [
            'gv.remarks_late' => Sla::enabled('gv.remarks_late') ? $this->remarksLate($since, $now) : 0,
            'gv.syllabus_confirm' => Sla::enabled('gv.syllabus_confirm') ? $this->syllabusConfirmLate($since, $now) : 0,
        ];
    }

    private function liveSince(Carbon $now): Carbon
    {
        $value = \App\Models\SystemSetting::get(self::SINCE_KEY);
        if (! $value) {
            \App\Models\SystemSetting::set(self::SINCE_KEY, $now->toIso8601String(), 'Thời điểm bắt đầu áp dụng SLA GV tự động (buổi trước đó không bị phạt hồi tố)');

            return $now->copy();
        }

        return Carbon::parse($value);
    }

    private function remarksLate(Carbon $since, Carbon $now): int
    {
        $hours = Sla::value('gv.remarks_late');
        $done = SlaEvent::query()->where('rule_key', 'gv.remarks_late')->where('subject_type', 'class_session')
            ->where(fn ($q) => $q->whereNotNull('breached_at')->orWhereNotNull('resolved_at'))->select('subject_id');
        $count = 0;

        ClassSession::with(['teacher', 'classModel'])
            ->where('type', ClassSession::TYPE_REGULAR)->whereNotIn('status', ['cancelled'])->whereNotNull('teacher_id')
            ->whereDate('date', '>=', $since->toDateString())->whereDate('date', '<=', $now->toDateString())
            ->whereNotIn('id', $done)->orderBy('id')
            ->each(function (ClassSession $session) use ($hours, $since, $now, &$count) {
                $ends = $session->end_time ? $session->date->copy()->setTimeFrom($session->end_time) : $session->date->copy()->endOfDay();
                $due = $ends->copy()->addHours($hours);
                if ($ends->lt($since) || $due->gt($now)) {
                    return;
                }
                $event = $this->breaches->open('gv.remarks_late', 'class_session', $session->id, $session->teacher, $ends, $due);
                $record = AcademicRecord::where('module', 'teacher_remarks')->where('status', 'completed')
                    ->where('record_code', TeacherPortalController::remarkRecordCode($session))->first();
                if ($record && $record->updated_at->lte($due)) {
                    $this->breaches->resolve($event, $record->updated_at);

                    return;
                }
                $label = ($session->classModel?->name ?? 'Lớp').' — buổi '.$session->date->format('d/m/Y').' '.$session->start_time?->format('H:i');
                $this->breaches->breach($event, $label, [
                    'Lớp / buổi học' => $label,
                    'Giáo viên' => (string) $session->teacher?->name,
                    'Buổi học kết thúc lúc' => $ends->format('H:i d/m/Y'),
                    'Nhận xét đã gửi' => $record ? $record->updated_at->format('H:i d/m/Y').' (trễ hạn)' : 'Chưa gửi',
                ], route('teacher.remarks', ['classId' => $session->class_id, 'session' => $session->id]), $now);
                if ($record) {
                    $this->breaches->resolve($event, $record->updated_at);
                }
                $count++;
            });

        return $count;
    }

    /** Chặng đang mở đã qua hạn xác nhận: xác nhận đúng hạn → đóng mốc; chưa / xác nhận muộn → biên bản cho GV được giao chặng. */
    private function syllabusConfirmLate(Carbon $since, Carbon $now): int
    {
        $done = SlaEvent::query()->where('rule_key', 'gv.syllabus_confirm')->where('subject_type', 'syllabus_assignment')
            ->where(fn ($q) => $q->whereNotNull('breached_at')->orWhereNotNull('resolved_at'))->select('subject_id');
        $count = 0;

        SyllabusAssignment::open()->with(['classModel.teacher', 'teacher'])->whereNotNull('class_id')->whereNotIn('id', $done)
            ->lazyById()
            ->each(function (SyllabusAssignment $assignment) use ($since, $now, &$count) {
                $due = $this->confirmDeadline->dueAt($assignment);
                $owner = $assignment->teacher ?? $assignment->classModel?->teacher;
                if (! $due || $due->gt($now) || $due->lt($since) || ! $owner) {
                    return;
                }
                $assignedAt = $assignment->assigned_at ?? $assignment->opened_at ?? $assignment->created_at;
                $event = $this->breaches->open('gv.syllabus_confirm', 'syllabus_assignment', $assignment->id, $owner, Carbon::instance($assignedAt), Carbon::instance($due));
                if ($assignment->confirmed_at && $assignment->confirmed_at->lte($due)) {
                    $this->breaches->resolve($event, Carbon::instance($assignment->confirmed_at));

                    return;
                }
                $label = ($assignment->classModel?->name ?? 'Lớp').' — '.$assignment->stage_name;
                $this->breaches->breach($event, $label, [
                    'Lớp / chặng' => $label,
                    'Giáo viên' => $owner->name,
                    'Giao chặng lúc' => $assignedAt->format('H:i d/m/Y'),
                    'Hạn xác nhận' => $due->format('H:i d/m/Y').' (1 buổi học đầu tiên của chặng − '.Sla::value('gv.syllabus_confirm').' giờ)',
                    'Đã xác nhận' => $assignment->confirmed_at ? $assignment->confirmed_at->format('H:i d/m/Y').' (trễ hạn)' : 'Chưa xác nhận',
                ], route('syllabus.teacher-view', ['class' => $assignment->class_id]), $now);
                $this->notifier->confirmLate($assignment);
                if ($assignment->confirmed_at) {
                    $this->breaches->resolve($event, Carbon::instance($assignment->confirmed_at));
                }
                $count++;
            });

        return $count;
    }
}
