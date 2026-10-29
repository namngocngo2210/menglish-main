<?php

namespace App\Services\Syllabus;

use App\Models\BigTestOrder;
use App\Models\SyllabusAssignment;
use App\Models\User;
use App\Models\WorkTask;

/**
 * Order đề mini test / Big Test theo mốc syllabus (docs/kho-syllabus.md §9.3) → 1 việc "Chuẩn bị đề" trong Danh sách đầu việc của
 * Học thuật nhận chặng lớp (cùng người nhận với việc cuối chặng), hạn = hạn xử lý của order, thông báo NHẬN VIỆC. Việc tự đóng
 * khi order được duyệt (Hoàn thành) / từ chối (Hủy). Không phụ thuộc vai trò người gửi (GV full-time / part-time / GVNN).
 */
class TestOrderTasks
{
    public function __construct(private readonly AcademicAssignee $assignees, private readonly SyllabusNotifier $notifier) {}

    public function open(BigTestOrder $order, SyllabusAssignment $assignment, User $teacher): ?WorkTask
    {
        if (WorkTask::withTrashed()->where('big_test_order_id', $order->id)->exists()) {
            return null;
        }
        $assignee = $this->assignees->for($assignment);
        $class = $assignment->classModel;
        if (! $assignee || ! $class) {
            return null;
        }
        $stage = $assignment->stage?->label ?? $assignment->stage_name;

        $task = WorkTask::create([
            'title' => "Chuẩn bị đề {$order->type_label} {$stage} cho lớp {$class->name} (order {$order->code})",
            'description' => "Giáo viên {$teacher->name} order đề {$order->type_label} cho {$stage} của lớp {$class->name}"
                .($order->exam_date ? ', ngày thi dự kiến '.$order->exam_date->format('d/m/Y') : '')
                .'. Chuẩn bị đề rồi gửi Admin duyệt & phân phối ở màn Phân phối đề; việc tự đóng khi order được duyệt / từ chối.'
                .($order->note ? "\nGhi chú của GV: {$order->note}" : ''),
            'creator_id' => $teacher->id,
            'assignee_id' => $assignee->id,
            'branch_id' => $class->branch_id,
            'class_id' => $class->id,
            'kind' => WorkTask::KIND_TEST_ORDER,
            'big_test_order_id' => $order->id,
            'syllabus_assignment_id' => $assignment->id,
            'task_type' => 'one_time',
            'due_date' => $order->due_date?->toDateString(),
            'status' => 'new',
        ]);
        $this->notifier->testOrdered($task);

        return $task;
    }

    /** Order đã duyệt → Hoàn thành; đã từ chối → Hủy (kèm lý do). Việc đã đóng tay giữ nguyên. */
    public function close(BigTestOrder $order): int
    {
        $approved = $order->status === 'approved';
        if (! $approved && $order->status !== 'rejected') {
            return 0;
        }

        return WorkTask::query()->where('big_test_order_id', $order->id)
            ->whereNotIn('status', ['completed', 'canceled'])
            ->get()
            ->each(fn (WorkTask $task) => $task->update($approved
                ? ['status' => 'completed', 'completed_at' => now(), 'completion_note' => "Order {$order->code} đã được duyệt & phân phối đề (hệ thống tự đóng)."]
                : ['status' => 'canceled', 'rejection_reason' => "Order {$order->code} bị từ chối: {$order->rejection_reason}"]))
            ->count();
    }
}
