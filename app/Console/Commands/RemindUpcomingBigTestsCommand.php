<?php

namespace App\Console\Commands;

use App\Models\AdminNotification;
use App\Models\BigTest;
use Illuminate\Console\Command;

/**
 * Nhắc lịch Big Test trước 7 ngày: báo cho giáo viên (GV chính, GVNN, trợ giảng) của lớp
 * có đợt thi trong 7 ngày tới; đợt thi chưa duyệt đề thì báo thêm cho Học thuật.
 * Idempotent: mỗi đợt thi chỉ nhắc một lần (đánh dấu big_tests.teacher_reminded_at).
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

        $this->info("Đã nhắc {$tests->count()} đợt Big Test ({$notified} thông báo giáo viên).");

        return self::SUCCESS;
    }
}
