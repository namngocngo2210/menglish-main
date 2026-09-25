<?php

namespace App\Console\Commands;

use App\Models\AdminNotification;
use App\Models\BigTest;
use App\Models\SyllabusAssignment;
use Illuminate\Console\Command;

/**
 * Nhắc lịch Big Test trước 7 ngày: báo cho giáo viên (GV chính, GVNN, trợ giảng) của lớp
 * có đợt thi trong 7 ngày tới; đợt thi chưa duyệt đề thì báo thêm cho Học thuật.
 * Idempotent: mỗi đợt thi chỉ nhắc một lần (đánh dấu big_tests.teacher_reminded_at).
 *
 * Thêm: chặng đang mở có ngày dự kiến Big Test (GV chính đặt) trong N ngày tới mà CHƯA có đợt Big Test của chặng
 * → nhắc GV chính / GVNN / trợ giảng order đề và báo Học thuật. Mỗi chặng nhắc 1 lần cho mỗi ngày dự kiến
 * (syllabus_assignments.big_test_reminded_for); GV đổi ngày dự kiến thì được nhắc lại theo ngày mới.
 */
class RemindUpcomingBigTestsCommand extends Command
{
    protected $signature = 'bigtests:remind-upcoming {--days=7 : Nhắc các đợt thi diễn ra trong số ngày tới}';

    protected $description = 'Nhắc giáo viên các đợt Big Test sắp diễn ra (mặc định trước 7 ngày)';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $tests = BigTest::with('classModel')
            ->whereNull('teacher_reminded_at')
            ->whereNotNull('class_id')
            ->whereBetween('scheduled_at', [now(), now()->addDays($days)->endOfDay()])
            ->get();

        $notified = 0;
        foreach ($tests as $test) {
            $class = $test->classModel;
            if (! $class) {
                continue;
            }

            $when = $test->scheduled_at->format('H:i d/m/Y');
            $daysLeft = (int) now()->startOfDay()->diffInDays($test->scheduled_at->copy()->startOfDay());
            $examStatus = $test->is_distributed ? 'Đề đã duyệt & phân phối.' : 'Đề CHƯA được duyệt — liên hệ Học thuật.';
            $link = route('syllabus.big-tests.schedules');

            $teacherIds = collect([$class->teacher_id, $class->foreign_teacher_id, $class->assistant_id])->filter()->unique();
            foreach ($teacherIds as $teacherId) {
                AdminNotification::create([
                    'user_id' => $teacherId,
                    'type' => 'big_test_upcoming',
                    'title' => "Nhắc lịch Big Test: {$test->title}",
                    'message' => "Lớp {$class->name} thi \"{$test->title}\" lúc {$when} tại {$test->room} (còn {$daysLeft} ngày). {$examStatus}",
                    'data' => ['big_test_id' => $test->id, 'link' => $link],
                    'is_read' => false,
                ]);
                $notified++;
            }

            if (! $test->is_distributed) {
                AdminNotification::create([
                    'type' => 'big_test_upcoming',
                    'title' => "Big Test chưa duyệt đề: {$test->code}",
                    'message' => "Lớp {$class->name} thi \"{$test->title}\" lúc {$when} (còn {$daysLeft} ngày) nhưng đề chưa được duyệt & phân phối.",
                    'data' => ['big_test_id' => $test->id, 'link' => route('syllabus.big-tests.distribution')],
                    'is_read' => false,
                ]);
            }

            $test->forceFill(['teacher_reminded_at' => now()])->save();
        }

        [$stages, $stageNotified] = $this->remindExpectedStageDates($days);

        $this->info("Đã nhắc {$tests->count()} đợt Big Test ({$notified} thông báo giáo viên); {$stages} chặng theo ngày dự kiến chưa có đợt thi ({$stageNotified} thông báo giáo viên).");

        return self::SUCCESS;
    }

    /** @return array{0: int, 1: int} [số chặng đã nhắc, số thông báo giáo viên] */
    private function remindExpectedStageDates(int $days): array
    {
        $assignments = SyllabusAssignment::open()
            ->with(['classModel', 'stage'])
            ->whereNotNull('class_id')
            ->whereNotNull('expected_big_test_date')
            ->whereBetween('expected_big_test_date', [today()->toDateString(), today()->addDays($days)->toDateString()])
            ->get();

        $stages = 0;
        $notified = 0;
        foreach ($assignments as $assignment) {
            $class = $assignment->classModel;
            $expected = $assignment->expected_big_test_date;
            if (! $class || ($assignment->big_test_reminded_for && $assignment->big_test_reminded_for->isSameDay($expected))) {
                continue;
            }
            $hasBigTest = BigTest::where('class_id', $class->id)
                ->when($assignment->stage_id, fn ($q) => $q->where('syllabus_stage_id', $assignment->stage_id))
                ->when(! $assignment->stage_id, fn ($q) => $q->where('created_at', '>=', $assignment->opened_at ?? $assignment->created_at))
                ->exists();
            if ($hasBigTest) {
                continue;
            }

            $stageLabel = $assignment->stage?->label ?? $assignment->stage_name;
            $daysLeft = (int) today()->diffInDays($expected->copy()->startOfDay());
            $message = "Lớp {$class->name} dự kiến thi Big Test {$stageLabel} ngày ".$expected->format('d/m/Y')
                ." (còn {$daysLeft} ngày) nhưng chưa có đợt thi — GV chính order đề, Học thuật duyệt & tạo đợt thi.";

            foreach (collect([$class->teacher_id, $class->foreign_teacher_id, $class->assistant_id])->filter()->unique() as $teacherId) {
                AdminNotification::create([
                    'user_id' => $teacherId,
                    'type' => 'big_test_upcoming',
                    'title' => "Nhắc lịch Big Test dự kiến: {$stageLabel}",
                    'message' => $message,
                    'data' => ['syllabus_assignment_id' => $assignment->id, 'link' => route('syllabus.teaching-stages')],
                    'is_read' => false,
                ]);
                $notified++;
            }
            AdminNotification::create([
                'type' => 'big_test_upcoming',
                'title' => "Chặng sắp thi chưa có đợt Big Test: {$class->name}",
                'message' => $message,
                'data' => ['syllabus_assignment_id' => $assignment->id, 'link' => route('syllabus.big-tests.schedules')],
                'is_read' => false,
            ]);

            $assignment->forceFill(['big_test_reminded_for' => $expected->toDateString()])->save();
            $stages++;
        }

        return [$stages, $notified];
    }
}
