<?php

namespace Tests\Feature;

use App\Models\AdminNotification;
use App\Models\BigTest;
use App\Models\BigTestResult;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\SyllabusAssignment;
use App\Services\SyllabusProgressionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSyllabusWorkflow;
use Tests\TestCase;

/** Giao chặng (ngày bắt đầu + số buổi), GV xác nhận, nhắc 24h, không tự mở chặng kế (docs/kho-syllabus.md §7.3). */
class SyllabusAssignmentConfirmTest extends TestCase
{
    use BuildsSyllabusWorkflow, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSyllabusWorkflow();
        foreach (range(0, 7) as $i) {
            ClassSession::create(['class_id' => $this->class->id, 'branch_id' => $this->branch->id, 'date' => today()->addDays(2 * $i)->toDateString(),
                'start_time' => '18:00', 'end_time' => '19:30', 'type' => ClassSession::TYPE_REGULAR, 'status' => 'scheduled']);
        }
    }

    public function test_assign_requires_start_date_and_sets_deadline_from_planned_sessions(): void
    {
        $this->actingAs($this->lead)->post(route('syllabus.assignments.store'), ['class_id' => $this->class->id, 'stage_id' => $this->stage1->id])
            ->assertSessionHasErrors('start_date');

        $this->actingAs($this->lead)->post(route('syllabus.assignments.store'), [
            'class_id' => $this->class->id, 'stage_id' => $this->stage1->id, 'start_date' => today()->toDateString(), 'planned_sessions' => 3,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $assignment = SyllabusAssignment::open()->sole();
        $this->assertSame(today()->toDateString(), $assignment->start_date->toDateString());
        $this->assertSame(3, $assignment->planned_sessions);
        $this->assertSame(today()->addDays(4)->toDateString(), $assignment->deadline->toDateString());
        $this->assertFalse($assignment->isConfirmed());

        $notification = AdminNotification::where('user_id', $this->teacher->id)->sole();
        $this->assertStringStartsWith('Bạn đã nhận syllabus mới: Lớp Lớp QT 01 – Giáo trình Family and Friends – Chặng 1: Làm quen, bắt đầu ', $notification->title);
        $this->assertSame(['label' => 'XÁC NHẬN', 'url' => route('syllabus.assignments.confirm', $assignment->id), 'method' => 'post'], $notification->action);
        $this->assertSame(1, AdminNotification::where('user_id', $this->foreignTeacher->id)->count());
    }

    public function test_planned_sessions_defaults_to_stage_lesson_count(): void
    {
        $this->actingAs($this->lead)->post(route('syllabus.assignments.store'), [
            'class_id' => $this->class->id, 'stage_id' => $this->stage1->id, 'start_date' => today()->toDateString(),
        ])->assertSessionHasNoErrors()->assertRedirect();

        $assignment = SyllabusAssignment::open()->sole();
        $this->assertSame(6, $assignment->planned_sessions);
        $this->assertSame(today()->addDays(10)->toDateString(), $assignment->deadline->toDateString());
    }

    public function test_teacher_confirms_and_others_cannot(): void
    {
        $assignment = $this->openStage($this->stage1, ['start_date' => today()]);

        $this->actingAs($this->staff)->post(route('syllabus.assignments.confirm', $assignment->id))->assertForbidden();
        $this->assertFalse($assignment->fresh()->isConfirmed());

        $this->actingAs($this->foreignTeacher)->post(route('syllabus.assignments.confirm', $assignment->id))->assertRedirect();
        $assignment->refresh();
        $this->assertTrue($assignment->isConfirmed());
        $this->assertSame($this->foreignTeacher->id, $assignment->confirmed_by);
    }

    public function test_reminder_after_24h_once_to_teacher_lead_and_manager(): void
    {
        $fresh = $this->openStage($this->stage1, ['opened_at' => now()->subHours(23)]);
        $this->artisan('syllabus:remind-unconfirmed')->assertSuccessful();
        $this->assertSame(0, AdminNotification::count());

        $fresh->update(['opened_at' => now()->subHours(25)]);
        $this->artisan('syllabus:remind-unconfirmed')->assertSuccessful();
        $this->artisan('syllabus:remind-unconfirmed')->assertSuccessful();

        $title = 'Lớp Lớp QT 01 chưa xác nhận syllabus Chặng 1: Làm quen.';
        foreach ([$this->teacher, $this->foreignTeacher, $this->lead, $this->manager] as $user) {
            $this->assertSame(1, AdminNotification::where('user_id', $user->id)->where('title', $title)->count(), $user->name);
        }
        $this->assertSame('NHẮC LẠI', AdminNotification::where('user_id', $this->lead->id)->sole()->action['label']);
        $this->assertSame('XÁC NHẬN', AdminNotification::where('user_id', $this->teacher->id)->sole()->action['label']);
        $this->assertSame(0, AdminNotification::where('user_id', $this->staff->id)->count());
    }

    public function test_confirmed_or_closed_stage_is_not_reminded(): void
    {
        $this->openStage($this->stage1, ['opened_at' => now()->subDays(2), 'confirmed_at' => now(), 'confirmed_by' => $this->teacher->id]);

        $this->artisan('syllabus:remind-unconfirmed')->assertSuccessful();

        $this->assertSame(0, AdminNotification::count());
    }

    public function test_lead_resends_reminder_to_teacher(): void
    {
        $assignment = $this->openStage($this->stage1);

        $this->actingAs($this->lead)->post(route('syllabus.assignments.remind', $assignment->id))->assertRedirect();
        $this->actingAs($this->staff)->post(route('syllabus.assignments.remind', $assignment->id))->assertForbidden();

        $this->assertSame('XÁC NHẬN', AdminNotification::where('user_id', $this->teacher->id)->sole()->action['label']);
    }

    public function test_big_test_completion_closes_stage_without_opening_next_and_notifies_lead(): void
    {
        $assignment = $this->openStage($this->stage1);
        $student = Student::create(['code' => 'HV-QT-1', 'name' => 'Học viên QT', 'phone' => '0390000001', 'branch_id' => $this->branch->id, 'class_id' => $this->class->id, 'status' => 'studying']);
        $test = BigTest::create(['code' => 'BT-QT-1', 'title' => 'BT chặng 1', 'class_id' => $this->class->id, 'syllabus_stage_id' => $this->stage1->id,
            'test_type' => 'midterm', 'scheduled_at' => now()->subDay(), 'room' => 'Lab', 'is_distributed' => true, 'status' => 'distributed']);
        BigTestResult::create(['big_test_id' => $test->id, 'student_id' => $student->id, 'status' => 'sent', 'overall_score' => 8]);

        $outcome = app(SyllabusProgressionService::class)->syncBigTest($test);

        $this->assertNotNull($outcome);
        $this->assertNull($outcome['next']);
        $this->assertFalse($assignment->fresh()->isOpen());
        $this->assertSame(0, SyllabusAssignment::open()->count());
        $this->assertSame('Lớp Lớp QT 01 đã xong Chặng 1: Làm quen — giao chặng tiếp theo.', AdminNotification::where('user_id', $this->lead->id)->sole()->title);
    }

    public function test_manual_close_never_opens_next_stage(): void
    {
        $assignment = $this->openStage($this->stage1);

        $this->actingAs($this->lead)->post(route('syllabus.assignments.close', $assignment->id), ['reason' => 'Thi ở lớp khác', 'open_next' => '1'])->assertRedirect();

        $this->assertSame(0, SyllabusAssignment::open()->count());
    }
}
