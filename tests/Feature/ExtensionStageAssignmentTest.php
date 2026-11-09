<?php

namespace Tests\Feature;

use App\Models\SyllabusAdjustmentRequest;
use App\Models\SyllabusAssignment;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSyllabusWorkflow;
use Tests\TestCase;

/** TK-2026-0054: form xin giãn chặng chỉ liệt kê / nhận chặng đang mở được giao cho chính GV (bản ghi phân công). */
class ExtensionStageAssignmentTest extends TestCase
{
    use BuildsSyllabusWorkflow, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSyllabusWorkflow();
    }

    public function test_teacher_does_not_see_stage_assigned_to_another_teacher(): void
    {
        $an = $this->userWithRole(Roles::TEACHER_PARTTIME, 'GV An');
        $this->openStage($this->stage1, ['user_id' => $an->id]);

        // $this->teacher là GV chính của lớp nhưng chặng đang mở giao cho An.
        $this->actingAs($this->teacher)->get(route('syllabus.teacher-adjust'))->assertOk()
            ->assertInertia(fn ($page) => $page->has('classes', 0));
    }

    public function test_teacher_sees_only_own_open_stage_with_nothing_preselected(): void
    {
        $this->openStage($this->stage1, ['user_id' => $this->teacher->id]);

        $this->actingAs($this->teacher)->get(route('syllabus.teacher-adjust'))->assertOk()
            ->assertInertia(fn ($page) => $page->has('classes', 1)->where('classes.0.value', $this->class->id))
            ->assertSee('Chọn lớp/chặng cần xin giãn');
    }

    public function test_closed_stage_is_not_listed(): void
    {
        $this->openStage($this->stage1, ['user_id' => $this->teacher->id, 'status' => SyllabusAssignment::STATUS_CLOSED]);

        $this->actingAs($this->teacher)->get(route('syllabus.teacher-adjust'))->assertOk()
            ->assertInertia(fn ($page) => $page->has('classes', 0));
    }

    public function test_server_rejects_request_for_stage_assigned_to_another_teacher(): void
    {
        $an = $this->userWithRole(Roles::TEACHER_PARTTIME, 'GV An');
        $this->openStage($this->stage1, ['user_id' => $an->id]);

        $this->actingAs($this->teacher)->post(route('syllabus.adjustment-requests.store'), [
            'class_id' => $this->class->id, 'extra_sessions' => 1, 'reason' => 'Xin giãn chặng của GV khác',
        ])->assertForbidden();
        $this->assertSame(0, SyllabusAdjustmentRequest::count());
    }

    public function test_server_accepts_request_for_own_assigned_stage(): void
    {
        $this->openStage($this->stage1, ['user_id' => $this->teacher->id]);

        $this->actingAs($this->teacher)->post(route('syllabus.adjustment-requests.store'), [
            'class_id' => $this->class->id, 'extra_sessions' => 1, 'reason' => 'Xin giãn chặng của mình',
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, SyllabusAdjustmentRequest::count());
    }
}
