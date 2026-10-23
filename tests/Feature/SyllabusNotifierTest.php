<?php

namespace Tests\Feature;

use App\Models\AdminNotification;
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
        $this->assertSame('Syllabus Chặng 1: Làm quen (giáo trình Family and Friends) đã được Học thuật cập nhật.', $notification->title);
        $this->assertSame('read', $notification->data['action']['method']);
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
