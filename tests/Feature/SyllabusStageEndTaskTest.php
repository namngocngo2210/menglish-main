<?php

namespace Tests\Feature;

use App\Models\AdminNotification;
use App\Models\ClassLessonProgress;
use App\Models\ClassSession;
use App\Models\SyllabusAssignment;
use App\Models\SyllabusLesson;
use App\Models\WorkTask;
use App\Services\Reports\WeeklyReportMetrics;
use App\Services\Syllabus\LessonSheetCells;
use App\Support\ReportPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSyllabusWorkflow;
use Tests\TestCase;

/** Sắp hết chặng → việc cho Học thuật: NHẬN VIỆC → HOÀN THÀNH → báo cáo tuần; quá hạn nhắc hằng ngày (docs/kho-syllabus.md §9.2). */
class SyllabusStageEndTaskTest extends TestCase
{
    use BuildsSyllabusWorkflow, RefreshDatabase;

    private SyllabusAssignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSyllabusWorkflow();
        $this->stage1->update(['notice_before_sessions' => 2]);
        $this->assignment = $this->openStage($this->stage1, ['start_date' => today()->subDays(10), 'opened_at' => today()->subDays(10), 'confirmed_at' => now()]);
        foreach ([1, 3] as $d) {
            $this->addSession($d);
        }
    }

    public function test_task_created_when_remaining_lessons_reach_notice(): void
    {
        $this->markLessons(3);
        $this->artisan('syllabus:stage-end-alerts')->assertSuccessful();
        $this->assertSame(0, WorkTask::count(), 'Còn 3 buổi > báo trước 2 buổi: chưa tạo việc.');

        $this->markLessons(4);
        $this->artisan('syllabus:stage-end-alerts')->assertSuccessful();
        $this->artisan('syllabus:stage-end-alerts')->assertSuccessful();

        $task = WorkTask::sole();
        $this->assertSame('Chuẩn bị test Chặng 1: Làm quen – giáo trình Family and Friends và syllabus mới cho lớp Lớp QT 01', $task->title);
        $this->assertSame([$this->lead->id, 'new', WorkTask::KIND_SYLLABUS_STAGE_END, $this->assignment->id, $this->class->id],
            [$task->assignee_id, $task->status, $task->kind, $task->syllabus_assignment_id, $task->class_id]);
        $this->assertSame(today()->addDays(3)->toDateString(), $task->due_date->toDateString(), 'Hạn = ngày dự kiến buổi cuối chặng.');
        $notification = AdminNotification::where('user_id', $this->lead->id)->sole();
        $this->assertSame(['label' => 'NHẬN VIỆC', 'url' => route('tasks.accept', $task->id), 'method' => 'post'], $notification->action);
    }

    public function test_task_created_when_notify_milestone_reached_and_only_once(): void
    {
        SyllabusLesson::where('curriculum_id', $this->curriculum->id)->where('session_no', 2)->update(['notice_milestone' => 'notify_academic']);
        $this->markLessons(1);
        $this->artisan('syllabus:stage-end-alerts')->assertSuccessful();

        $this->assertSame(1, WorkTask::count(), 'Buổi hiện tại là buổi có mốc "Báo Học thuật chuẩn bị".');
        $this->markLessons(5);
        $this->artisan('syllabus:stage-end-alerts')->assertSuccessful();
        $this->assertSame(1, WorkTask::count());
    }

    public function test_accept_moves_new_to_in_progress_only_for_assignee(): void
    {
        $task = $this->createTask();

        $this->actingAs($this->manager)->post(route('tasks.accept', $task->id))->assertForbidden();
        $this->actingAs($this->lead)->post(route('tasks.accept', $task->id))->assertRedirect();

        $this->assertSame('in_progress', $task->fresh()->status);
        $this->actingAs($this->lead)->post(route('tasks.accept', $task->id))->assertSessionHasErrors('status');
    }

    public function test_complete_without_proof_marks_completed_and_appears_in_weekly_report(): void
    {
        $task = $this->createTask();
        $this->actingAs($this->lead)->post(route('tasks.accept', $task->id));

        $this->actingAs($this->lead)->post(route('tasks.complete', $task->id), ['note' => 'Đã chuẩn bị đề và syllabus chặng 2'])->assertRedirect();

        $task->refresh();
        $this->assertSame('completed', $task->status);
        $this->assertNotNull($task->completed_at);
        $done = WeeklyReportMetrics::academic($this->lead, ReportPeriod::weekKey(now()))['suggestions']['done'];
        $this->assertContains("task:{$task->id}", array_column($done, 'source'));
    }

    public function test_overdue_task_reminds_assignee_and_branch_manager_daily_once(): void
    {
        $task = $this->createTask(['due_date' => today()->subDay(), 'status' => 'overdue']);

        $this->artisan('syllabus:remind-overdue-tasks')->assertSuccessful();
        $this->artisan('syllabus:remind-overdue-tasks')->assertSuccessful();

        $title = "Việc “{$task->title}” của lớp Lớp QT 01 đã quá hạn.";
        foreach ([$this->lead, $this->manager] as $user) {
            $notification = AdminNotification::where('user_id', $user->id)->where('title', $title)->sole();
            $this->assertSame(['label' => 'XỬ LÝ', 'url' => route('tasks.show', $task->id), 'method' => 'get'], $notification->action);
        }

        $this->travel(1)->days();
        $this->artisan('syllabus:remind-overdue-tasks')->assertSuccessful();
        $this->assertSame(2, AdminNotification::where('user_id', $this->lead->id)->where('title', $title)->count(), 'Nhắc lại mỗi ngày.');
    }

    public function test_milestone_text_parsing_notify_academic(): void
    {
        $this->assertSame('notify_academic', LessonSheetCells::milestone("Báo chuẩn bị Big test\nBáo chuẩn bị syllabus chặng 4"));
        $this->assertSame('notify_academic', LessonSheetCells::milestone('Báo Học thuật chuẩn bị'));
        $this->assertSame('order_big_test', LessonSheetCells::milestone('Order in ấn big test'));
        $this->assertSame('Báo Học thuật chuẩn bị', SyllabusLesson::MILESTONES['notify_academic']);
    }

    private function createTask(array $attributes = []): WorkTask
    {
        $this->markLessons(4);
        $this->artisan('syllabus:stage-end-alerts');
        $task = WorkTask::sole();
        $task->update($attributes);

        return $task->fresh();
    }

    private function markLessons(int $count): void
    {
        $lessons = SyllabusLesson::where('curriculum_id', $this->curriculum->id)->orderBy('session_no')->take($count)->get();
        foreach ($lessons as $i => $lesson) {
            ClassLessonProgress::firstOrCreate(['syllabus_assignment_id' => $this->assignment->id, 'syllabus_lesson_id' => $lesson->id],
                ['class_id' => $this->class->id, 'kind' => 'lesson', 'taught_by' => $this->teacher->id, 'taught_at' => today()->subDays(9 - $i)]);
        }
    }

    private function addSession(int $days): void
    {
        ClassSession::create(['class_id' => $this->class->id, 'branch_id' => $this->branch->id, 'date' => today()->addDays($days)->toDateString(),
            'start_time' => '18:00', 'end_time' => '19:30', 'type' => 'regular', 'status' => 'scheduled', 'teacher_id' => $this->teacher->id]);
    }
}
