<?php

namespace Tests\Feature;

use App\Models\AdminNotification;
use App\Models\ClassModel;
use App\Models\SyllabusAssignment;
use App\Services\Syllabus\SyllabusNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSyllabusWorkflow;
use Tests\TestCase;

/** Thông báo của quy trình syllabus theo mẫu Sheet 3 "Quy tắc thông báo" (docs/kho-syllabus.md §6.1). */
class SyllabusNotifierTest extends TestCase
{
    use BuildsSyllabusWorkflow, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSyllabusWorkflow();
    }

    public function test_class_update_lists_lesson_numbers_with_read_action(): void
    {
        $this->openStage($this->stage1);

        app(SyllabusNotifier::class)->classSyllabusUpdated($this->class, collect([5, 3]));

        foreach ([$this->teacher, $this->foreignTeacher] as $user) {
            $notification = AdminNotification::where('user_id', $user->id)->sole();
            $this->assertSame('Syllabus lớp Lớp QT 01 đã được cập nhật (buổi 3, 5).', $notification->title);
            $this->assertSame(['label' => 'ĐÃ XEM', 'url' => null, 'method' => 'read'], $notification->data['action']);
        }
        $this->assertSame(0, AdminNotification::where('user_id', $this->lead->id)->count());
    }

    public function test_stage_content_update_reaches_teachers_of_classes_running_that_stage(): void
    {
        $this->openStage($this->stage1);

        app(SyllabusNotifier::class)->stageContentUpdated($this->stage1);
        app(SyllabusNotifier::class)->stageContentUpdated($this->stage2);

        $notification = AdminNotification::where('user_id', $this->teacher->id)->sole();
        $this->assertSame('Syllabus lớp Lớp QT 01 – Chặng 1: Làm quen (giáo trình Family and Friends) đã được Học thuật cập nhật.', $notification->title);
        $this->assertSame('read', $notification->data['action']['method']);
    }

    public function test_repeated_stage_content_updates_are_merged_while_unread(): void
    {
        $this->openStage($this->stage1);
        $notifier = app(SyllabusNotifier::class);

        $notifier->stageContentUpdated($this->stage1);
        $notifier->stageContentUpdated($this->stage1);
        $this->assertSame(1, AdminNotification::where('user_id', $this->teacher->id)->count());

        AdminNotification::where('user_id', $this->teacher->id)->update(['is_read' => true]);
        $notifier->stageContentUpdated($this->stage1);
        $this->assertSame(2, AdminNotification::where('user_id', $this->teacher->id)->count());
    }

    public function test_stage_content_update_notices_each_class_separately(): void
    {
        $this->openStage($this->stage1);
        $other = ClassModel::create(['code' => 'QT-02', 'name' => 'Lớp QT 02', 'branch_id' => $this->branch->id, 'status' => 'active', 'teacher_id' => $this->teacher->id]);
        SyllabusAssignment::create(['class_id' => $other->id, 'curriculum_id' => $this->curriculum->id, 'stage_id' => $this->stage1->id, 'stage_name' => $this->stage1->label,
            'assigned_chapters' => $this->stage1->label, 'user_id' => $this->teacher->id, 'status' => 'in_progress', 'opened_at' => now()]);

        app(SyllabusNotifier::class)->stageContentUpdated($this->stage1);

        $this->assertSame(2, AdminNotification::where('user_id', $this->teacher->id)->count());
    }

    public function test_stage_finished_goes_to_academic_leads_with_assign_link(): void
    {
        $assignment = $this->openStage($this->stage1);

        app(SyllabusNotifier::class)->stageFinished($assignment);

        $notification = AdminNotification::where('user_id', $this->lead->id)->sole();
        $this->assertSame('Lớp Lớp QT 01 đã xong Chặng 1: Làm quen — giao chặng tiếp theo.', $notification->title);
        $this->assertSame(['label' => 'GIAO CHẶNG', 'url' => route('syllabus.assignments', ['class_id' => $this->class->id]), 'method' => 'get'], $notification->data['action']);
        $this->assertSame(0, AdminNotification::whereIn('user_id', [$this->staff->id, $this->manager->id, $this->teacher->id])->count());
    }
}
