<?php

namespace App\Services\Syllabus;

use App\Models\AdminNotification;
use App\Models\ClassModel;
use App\Models\SyllabusAssignment;
use App\Models\SyllabusStage;
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

        $overseers = NotificationService::userIdsWithPermission('syllabus.assign')
            ->merge(BranchStaff::withRoles(Roles::MANAGER, $class?->branch_id)->pluck('id'))
            ->diff($this->teacherIds($assignment));
        $this->send($overseers, $title, 'GV chưa xác nhận sau 24 giờ kể từ khi được giao.',
            ['label' => 'NHẮC LẠI', 'url' => route('syllabus.assignments.remind', $assignment->id), 'method' => 'post']);
    }

    /** Học thuật sửa syllabus riêng của lớp → GV của lớp, nút ĐÃ XEM. */
    public function classSyllabusUpdated(ClassModel $class, Collection $lessonNumbers): void
    {
        $assignment = SyllabusAssignment::open()->where('class_id', $class->id)->first();
        $ids = $assignment ? $this->teacherIds($assignment) : collect([$class->teacher_id, $class->foreign_teacher_id]);
        $numbers = $lessonNumbers->sort()->unique()->implode(', ');
        $this->send($ids, "Syllabus lớp {$class->name} đã được cập nhật (buổi {$numbers}).", 'Học thuật đã chỉnh sửa syllabus riêng của lớp.', $this->readAction());
    }

    /** Học thuật sửa syllabus gốc của chặng → GV các lớp đang học chặng đó, nút ĐÃ XEM. */
    public function stageContentUpdated(SyllabusStage $stage): void
    {
        $stage->loadMissing('curriculum');
        $title = "Syllabus {$stage->label} (giáo trình {$stage->curriculum?->title}) đã được Học thuật cập nhật.";
        SyllabusAssignment::open()->with('classModel')->where('stage_id', $stage->id)->get()
            ->each(fn (SyllabusAssignment $assignment) => $this->send($this->teacherIds($assignment), $title,
                "Lớp {$assignment->classModel?->name}: xem lại nội dung các buổi sắp dạy.", $this->readAction()));
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
