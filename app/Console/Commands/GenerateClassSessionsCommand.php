<?php

namespace App\Console\Commands;

use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Services\ScheduleExtensionService;
use App\Services\SessionScheduleService;
use Illuminate\Console\Command;

/**
 * Keep every active class stocked with regular sessions up to a rolling horizon, following its weekly slots.
 * Reuses SessionScheduleService (holidays, conflicts) and ScheduleExtensionService (slots). Never touches existing
 * sessions (any status) and never moves the class end_date. Idempotent on class + date + start time.
 */
class GenerateClassSessionsCommand extends Command
{
    protected $signature = 'classes:generate-sessions {--dry-run : Chỉ báo cáo, không ghi dữ liệu} {--days= : Số ngày phía trước (mặc định theo cấu hình)}';

    protected $description = 'Sinh buổi học chính khóa cho lớp đang học theo TKB lặp tuần (bỏ ngày nghỉ lễ, không trùng)';

    public function handle(SessionScheduleService $schedule, ScheduleExtensionService $extension): int
    {
        $dry = (bool) $this->option('dry-run');
        $horizon = (int) ($this->option('days') ?: config('schedule_jobs.session_horizon_days', 28));
        $today = today();
        $to = $today->copy()->addDays($horizon);
        $created = [];
        $skipped = [];

        ClassModel::query()
            ->whereNotIn('status', ClassModel::CLOSED_STATUSES)
            ->with(['scheduleConfig', 'course'])
            ->orderBy('id')
            ->each(function (ClassModel $class) use ($schedule, $extension, $dry, $today, $to, &$created, &$skipped) {
                if ($class->status !== 'active' || ($class->start_date && $class->start_date->gt($today))) {
                    $skipped[$class->id] = 'chưa khai giảng';

                    return;
                }
                if ($class->end_date && $class->end_date->lt($today)) {
                    $skipped[$class->id] = 'đã quá ngày kết thúc';

                    return;
                }
                $slots = $class->scheduleConfig ? $extension->slotsFor($class) : [];
                if ($slots === []) {
                    $skipped[$class->id] = 'chưa có TKB lặp tuần';

                    return;
                }

                $until = $class->end_date ? $class->end_date->copy()->min($to) : $to;
                $existing = ClassSession::where('class_id', $class->id)
                    ->whereDate('date', '>=', $today->toDateString())
                    ->get()
                    ->mapWithKeys(fn (ClassSession $s) => [$s->date->toDateString().'|'.substr((string) $s->getRawOriginal('start_time'), 0, 5) => true]);

                $sessions = array_values(array_filter(
                    $schedule->generate($slots, $today, $until, $class->branch_id),
                    fn (array $s) => ! isset($existing["{$s['date']}|{$s['start']}"]),
                ));
                $remaining = $schedule->remainingLessons($class);
                if ($remaining !== null) {
                    $sessions = array_slice($sessions, 0, $remaining);
                }

                // Drop sessions clashing with another class's room/staff; they need manual scheduling.
                $staff = [$class->teacher_id, $class->assistant_id, $class->foreign_teacher_id];
                $conflicts = 0;
                while ($sessions && ($conflict = $schedule->findConflict(
                    array_map(fn (array $s) => $s + ['room' => $class->room], $sessions), $class->branch_id, $staff, $class->room, $class->id,
                ))) {
                    $key = $conflict[0]['date'].'|'.$conflict[0]['start'];
                    $sessions = array_values(array_filter($sessions, fn (array $s) => "{$s['date']}|{$s['start']}" !== $key));
                    $conflicts++;
                }

                if (! $sessions) {
                    $skipped[$class->id] = 'đủ buổi'.($conflicts ? " ({$conflicts} buổi trùng phòng/nhân sự bị bỏ)" : '');

                    return;
                }

                foreach ($sessions as $s) {
                    $created[$class->id][] = $s['date'];
                    if (! $dry) {
                        ClassSession::create([
                            'class_id' => $class->id,
                            'branch_id' => $class->branch_id,
                            'date' => $s['date'],
                            'shift_name' => $s['name'] ?? null,
                            'type' => ClassSession::TYPE_REGULAR,
                            'start_time' => $s['start'],
                            'end_time' => $s['end'],
                            'room' => $class->room,
                            'teacher_id' => $class->teacher_id ?? $class->foreign_teacher_id,
                            'foreign_teacher_id' => $class->foreign_teacher_id,
                            'assistant_id' => $class->assistant_id,
                            'status' => 'scheduled',
                            'notes' => 'Tự động sinh theo TKB',
                        ]);
                    }
                }
                if ($conflicts) {
                    $this->warn("Lớp #{$class->id}: bỏ {$conflicts} buổi trùng phòng/nhân sự, cần xếp tay.");
                }
            });

        $total = array_sum(array_map('count', $created));
        $this->info(($dry ? '[DRY-RUN] Sẽ tạo ' : 'Đã tạo ')."{$total} buổi cho ".count($created)." lớp (tới {$to->toDateString()}).");
        foreach ($created as $id => $dates) {
            $this->line("  + Lớp #{$id}: ".count($dates).' buổi ('.implode(', ', $dates).')');
        }
        foreach ($skipped as $id => $reason) {
            $this->line("  - Lớp #{$id}: bỏ qua — {$reason}");
        }

        return self::SUCCESS;
    }
}
