<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use App\Models\SyllabusAdjustmentRequest;
use App\Models\SyllabusAssignment;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSyllabusWorkflow;
use Tests\TestCase;

/** Customize syllabus: GV xin Giãn (thêm buổi vào TKB) hoặc Dời tiến độ, Học thuật duyệt (docs/kho-syllabus.md §8.4, Sheet 4 câu 2). */
class CustomizeSyllabusTest extends TestCase
{
    use BuildsSyllabusWorkflow, RefreshDatabase;

    private SyllabusAssignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSyllabusWorkflow();
        $this->assignment = $this->openStage($this->stage1, ['start_date' => today()->subDays(3), 'opened_at' => today()->subDays(3), 'confirmed_at' => now()]);
    }

    public function test_postpone_adds_extra_sessions_without_new_schedule_sessions(): void
    {
        $today = ClassSession::create(['class_id' => $this->class->id, 'branch_id' => $this->branch->id, 'date' => today()->toDateString(),
            'start_time' => '18:00', 'end_time' => '19:30', 'type' => ClassSession::TYPE_REGULAR, 'status' => 'scheduled', 'teacher_id' => $this->teacher->id]);
        $this->actingAs($this->teacher)->post(route('teacher.lesson-today.taught', $this->class->id), ['class_session_id' => $today->id, 'use_extra' => '1'])
            ->assertSessionHasErrors('use_extra');

        $this->actingAs($this->teacher)->post(route('syllabus.adjustment-requests.store'), [
            'class_id' => $this->class->id, 'kind' => 'postpone', 'extra_sessions' => 2, 'reason' => 'Lớp cần thêm thời gian ôn Unit 1',
        ])->assertSessionHasNoErrors();
        $request = SyllabusAdjustmentRequest::sole();
        $this->assertSame('postpone', $request->kind);

        $admin = $this->userWithRole(Roles::ADMIN, 'Admin');
        $this->actingAs($admin)->post(route('syllabus.adjustment-requests.approve', $request->id))->assertSessionHasNoErrors();

        $this->assertSame(2, $this->assignment->fresh()->extra_sessions);
        $this->assertSame(1, ClassSession::where('class_id', $this->class->id)->count(), 'Dời tiến độ không thêm buổi vào TKB.');
        $this->assertStringContainsString('Dời tiến độ', $request->fresh()->applied_note);
        $this->actingAs($this->teacher)->post(route('teacher.lesson-today.taught', $this->class->id), ['class_session_id' => $today->id, 'use_extra' => '1'])
            ->assertSessionHasNoErrors();
    }

    public function test_kind_defaults_to_extend_and_is_validated(): void
    {
        $this->actingAs($this->teacher)->post(route('syllabus.adjustment-requests.store'), [
            'class_id' => $this->class->id, 'kind' => 'skip', 'extra_sessions' => 1, 'reason' => 'x',
        ])->assertSessionHasErrors('kind');
        $this->actingAs($this->teacher)->post(route('syllabus.adjustment-requests.store'), [
            'class_id' => $this->class->id, 'extra_sessions' => 1, 'reason' => 'x',
        ])->assertSessionHasNoErrors();

        $this->assertSame('extend', SyllabusAdjustmentRequest::sole()->kind);
    }

    public function test_screen_titled_customize_syllabus(): void
    {
        $this->actingAs($this->teacher)->get(route('syllabus.teacher-adjust'))
            ->assertOk()
            ->assertSee('Customize syllabus')
            ->assertSee('Giãn thêm buổi')
            ->assertSee('Dời tiến độ');
    }
}
