<?php

namespace App\Services\Syllabus;

use App\Models\AdminNotification;
use App\Models\ClassModel;
use App\Models\SyllabusAssignment;
use App\Models\SyllabusStage;
use App\Models\WorkTask;
use App\Services\BranchStaff;
use App\Services\NotificationService;
use App\Support\Roles;
use Illuminate\Support\Collection;

/**
 * Thông báo của quy trình syllabus — nội dung mẫu và nút theo Sheet 3 "Quy tắc thông báo" (docs/kho-syllabus.md §6.1).
 * Nút: XÁC NHẬN / NHẮC LẠI (post), GIAO CHẶNG / XỬ LÝ (get), ĐÃ XEM (read = chỉ đánh dấu đã đọc).
 */
class SyllabusNotifier
{
    public const TYPE = 'syllabus_workflow';

    /** Giao syllabus mới → GV của lớp, nút XÁC NHẬN. */
    public function stageAssigned(SyllabusAssignment $assignment): void
    {
        $assignment->loadMissing(['classModel', 'curriculum']);
        $start = ($assignment->start_date ?? $assignment->opened_at)?->format('d/m/Y');
        $this->send($this->teacherIds($assignment),
            "Bạn đã nhận syllabus mới: Lớp {$assignment->classModel?->name} – Giáo trình {$assignment->curriculum?->title} – {$assignment->stage_name}, bắt đầu {$start}.",
            'Bấm XÁC NHẬN để xác nhận đã nhận syllabus.',
            $this->confirmAction($assignment));
    }

    /** GV chưa xác nhận sau 24h → nhắc GV (XÁC NHẬN) + báo Học thuật và Quản lý chi nhánh (NHẮC LẠI). */
    public function unconfirmedReminder(SyllabusAssignment $assignment): void
    {
        $assignment->loadMissing('classModel');
        $class = $assignment->classModel;
        $title = "Lớp {$class?->name} chưa xác nhận syllabus {$assignment->stage_name}.";
        $this->send($this->teacherIds($assignment), $title, 'Vui lòng bấm XÁC NHẬN để nhận syllabus của lớp.', $this->confirmAction($assignment));

        $leads = NotificationService::userIdsWithPermission('syllabus.assign')->diff($this->teacherIds($assignment));
        $this->send($leads, $title, 'GV chưa xác nhận sau 24 giờ kể từ khi được giao.',
            ['label' => 'NHẮC LẠI', 'url' => route('syllabus.assignments.remind', $assignment->id), 'method' => 'post']);
        // Quản lý chi nhánh chỉ theo dõi (không có quyền nhắc lại): nút ĐÃ XEM.
        $managers = BranchStaff::withRoles(Roles::MANAGER, $class?->branch_id)->pluck('id')->diff($leads)->diff($this->teacherIds($assignment));
        $this->send($managers, $title, 'GV chưa xác nhận sau 24 giờ kể từ khi được giao — Học thuật đã được báo để nhắc lại.', $this->readAction());
    }

    /** Học thuật sửa syllabus riêng của lớp → GV của lớp, nút ĐÃ XEM. */
    public function classSyllabusUpdated(ClassModel $class, Collection $lessonNumbers): void
    {
        $assignment = SyllabusAssignment::open()->where('class_id', $class->id)->first();
        $ids = $assignment ? $this->teacherIds($assignment) : collect([$class->teacher_id, $class->foreign_teacher_id]);
        $numbers = $lessonNumbers->sort()->unique()->implode(', ');
        $this->send($ids, "Syllabus lớp {$class->name} đã được cập nhật (buổi {$numbers}).", 'Học thuật đã chỉnh sửa syllabus riêng của lớp.', $this->readAction());
    }

    /**
     * Học thuật sửa syllabus gốc của chặng → GV các lớp đang học chặng đó (1 thông báo / lớp), nút ĐÃ XEM. Sửa nhiều buổi
     * liên tiếp → gộp: GV còn thông báo cùng lớp chưa đọc thì không gửi thêm.
     */
    public function stageContentUpdated(SyllabusStage $stage): void
    {
        $stage->loadMissing('curriculum');
        SyllabusAssignment::open()->with('classModel')->where('stage_id', $stage->id)->get()
            ->each(function (SyllabusAssignment $assignment) use ($stage) {
                $title = "Syllabus lớp {$assignment->classModel?->name} – {$stage->label} (giáo trình {$stage->curriculum?->title}) đã được Học thuật cập nhật.";
                $unread = AdminNotification::where('type', self::TYPE)->where('title', $title)->where('is_read', false)->pluck('user_id');
                $this->send($this->teacherIds($assignment)->diff($unread), $title,
                    "Lớp {$assignment->classModel?->name}: xem lại nội dung các buổi sắp dạy.", $this->readAction());
            });
    }

    /** Chặng của lớp đã xong (Big Test duyệt & gửi PH) → Học thuật giao chặng tiếp theo (Bước 11). */
    public function stageFinished(SyllabusAssignment $assignment): void
    {
        $assignment->loadMissing('classModel');
        $this->send(NotificationService::userIdsWithPermission('syllabus.assign'),
            "Lớp {$assignment->classModel?->name} đã xong {$assignment->stage_name} — giao chặng tiếp theo.",
            'Hệ thống không tự mở chặng kế tiếp; Học thuật giao chặng tiếp theo cho lớp.',
            ['label' => 'GIAO CHẶNG', 'url' => route('syllabus.assignments', ['class_id' => $assignment->class_id]), 'method' => 'get']);
    }

    /**
     * Lớp đổi GV chính (sửa lớp): chặng đang mở giao cho GV cũ chuyển sang GV mới, GV mới phải XÁC NHẬN lại; GV cũ không
     * còn xác nhận được, quyền Drive của GV cũ được thu hồi ở lượt đồng bộ kế (docs/kho-syllabus.md §7.3–7.4).
     */
    public function classTeacherChanged(ClassModel $class, ?int $previousTeacherId): void
    {
        $assignment = SyllabusAssignment::open()->where('class_id', $class->id)->first();
        if (! $assignment || ! $class->teacher_id || ($previousTeacherId && (int) $assignment->user_id !== $previousTeacherId)) {
            return;
        }
        $assignment->update(['user_id' => $class->teacher_id, 'assigned_at' => now(), 'confirmed_at' => null, 'confirmed_by' => null, 'confirm_reminded_at' => null]);
        $assignment->setRelation('classModel', $class);
        $this->stageAssigned($assignment);
    }

    /** Sắp hết chặng → Học thuật được giao việc, nút NHẬN VIỆC (Bước 8). */
    public function stageEnding(WorkTask $task): void
    {
        $this->send([$task->assignee_id], $task->title,
            'Hạn: '.$task->due_date?->format('d/m/Y').' (buổi cuối chặng dự kiến). Bấm NHẬN VIỆC để chuyển vào Công việc cần làm.',
            ['label' => 'NHẬN VIỆC', 'url' => route('tasks.accept', $task->id), 'method' => 'post']);
    }

    /** Việc quá hạn → người làm + Quản lý chi nhánh, nút XỬ LÝ (Sheet 3 "Việc quá hạn"). */
    public function taskOverdue(WorkTask $task): void
    {
        $task->loadMissing('classModel');
        $managers = BranchStaff::withRoles(Roles::MANAGER, $task->branch_id)->pluck('id');
        $this->send(collect([$task->assignee_id])->merge($managers), "Việc “{$task->title}” của lớp {$task->classModel?->name} đã quá hạn.",
            'Hạn chót '.$task->due_date?->format('d/m/Y').' đã qua — xử lý và bấm HOÀN THÀNH.',
            ['label' => 'XỬ LÝ', 'url' => route('tasks.show', $task->id), 'method' => 'get']);
    }

    /** GV nhận syllabus của lớp: GV được giao chặng + GV chính + GVNN (bỏ trùng). */
    public function teacherIds(SyllabusAssignment $assignment): Collection
    {
        $assignment->loadMissing('classModel');

        return collect([$assignment->user_id, $assignment->classModel?->teacher_id, $assignment->classModel?->foreign_teacher_id])
            ->filter()->map(fn ($id) => (int) $id)->unique()->values();
    }

    private function confirmAction(SyllabusAssignment $assignment): array
    {
        return ['label' => 'XÁC NHẬN', 'url' => route('syllabus.assignments.confirm', $assignment->id), 'method' => 'post'];
    }

    private function readAction(): array
    {
        return ['label' => 'ĐÃ XEM', 'url' => null, 'method' => 'read'];
    }

    /** @param  iterable<int|null>  $userIds */
    private function send(iterable $userIds, string $title, string $message, array $action): void
    {
        AdminNotification::notifyUsers($userIds, self::TYPE, $title, $message, null, ['action' => $action]);
    }
}
