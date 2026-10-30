<?php

namespace App\Console\Commands;

use App\Models\BigTestOrder;
use App\Models\SyllabusAssignment;
use App\Models\WorkTask;
use App\Services\Syllabus\AcademicAssignee;
use App\Services\Syllabus\TestOrderTasks;
use Illuminate\Console\Command;

/**
 * TK-2026-0003: order đề GV gửi trước bản sửa (PR #259) chưa có việc "Chuẩn bị đề" ở Danh sách đầu việc của Học thuật.
 * Lệnh chạy một lần sau deploy: tạo việc cho mọi order đang chờ duyệt chưa có việc, qua đúng TestOrderTasks::open(). Idempotent.
 */
class BackfillAcademicOrderTasksCommand extends Command
{
    protected $signature = 'academic-orders:backfill-tasks {--dry-run}';

    protected $description = 'Bổ sung việc Học thuật cho order đề mini test / Big Test đang chờ duyệt chưa có việc';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $created = $skipped = 0;

        foreach (BigTestOrder::where('status', 'pending')->with(['teacher', 'classModel'])->lazyById() as $order) {
            if (WorkTask::withTrashed()->where('big_test_order_id', $order->id)->exists()) {
                continue;
            }

            $teacher = $order->teacher;
            $assignment = $this->assignmentFor($order);
            $className = $assignment?->classModel?->name ?? $order->classModel?->name ?? 'Không có lớp';
            $reason = null;
            $result = null;

            if (! $teacher) {
                $reason = 'không tìm thấy giáo viên';
            } elseif (! $assignment) {
                $reason = 'không tìm thấy chặng được giao';
            } elseif ($dryRun) {
                $assignee = app(AcademicAssignee::class)->for($assignment);
                if ($assignee && $assignment->classModel) {
                    $result = "sẽ tạo — {$assignee->name}";
                } else {
                    $reason = 'không có người nhận Học thuật hoặc lớp học';
                }
            } else {
                $task = app(TestOrderTasks::class)->open($order, $assignment, $teacher);
                if ($task) {
                    $result = "đã tạo việc #{$task->id} — {$task->assignee?->name}";
                } else {
                    $reason = 'không có người nhận Học thuật hoặc lớp học, hoặc đã có việc';
                }
            }

            if ($reason !== null) {
                $skipped++;
                $result = "bỏ qua: {$reason}";
            } else {
                $created++;
            }
            $this->line("#{$order->id} / {$order->code} | {$className} | {$result}");
        }

        $this->info($dryRun
            ? "Chạy thử: sẽ tạo {$created} việc Học thuật, bỏ qua {$skipped} order."
            : "Đã tạo {$created} việc Học thuật, bỏ qua {$skipped} order.");

        return self::SUCCESS;
    }

    private function assignmentFor(BigTestOrder $order): ?SyllabusAssignment
    {
        if ($order->syllabus_assignment_id) {
            $assignment = SyllabusAssignment::with(['classModel', 'stage'])->find($order->syllabus_assignment_id);
            if ($assignment) {
                return $assignment;
            }
        }

        $candidates = SyllabusAssignment::with(['classModel', 'stage'])->where('class_id', $order->class_id);
        if ($order->syllabus_stage_id !== null) {
            $candidates->where('stage_id', $order->syllabus_stage_id);
        } else {
            $candidates->where('stage_name', $order->stage_name);
        }

        return (clone $candidates)->open()->latest('id')->first() ?? $candidates->latest('id')->first();
    }
}
