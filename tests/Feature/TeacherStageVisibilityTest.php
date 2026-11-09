<?php

namespace Tests\Feature;

use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\SyllabusAssignment;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSyllabusWorkflow;
use Tests\TestCase;

/**
 * TK-2026-0047: "Lớp của tôi" / "Chặng đang dạy" theo bản ghi phân công chặng; dạy thay chỉ theo buổi.
 */
class TeacherStageVisibilityTest extends TestCase
{
    use BuildsSyllabusWorkflow;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSyllabusWorkflow();
    }

    private function assignedTeacher(): User
    {
        return $this->userWithRole(Roles::TEACHER_FULLTIME, 'GV Được Giao');
    }

    public function test_assigned_non_main_teacher_sees_class_and_pending_stage(): void
    {
        $an = $this->assignedTeacher();
        // Chặng chờ GV xác nhận (confirmed_at null) vẫn là chặng đang mở.
        $this->openStage($this->stage1, ['user_id' => $an->id]);

        $this->actingAs($an)->get(route('classes.index', ['mine' => 1]))->assertOk()
            ->assertInertia(fn ($page) => $page->where('classes.data', fn ($rows) => collect($rows)->pluck('my_role', 'name')->all() === ['Lớp QT 01' => 'GV được giao chặng']));

        $this->actingAs($an)->get(route('syllabus.teaching-stages'))->assertOk()
            ->assertInertia(fn ($page) => $page->has('assignments', 1)->where('assignments.0.class_label', 'Lớp QT 01 - QT-01'));
    }

    public function test_closed_assignment_is_not_listed_for_assigned_teacher(): void
    {
        $an = $this->assignedTeacher();
        $this->openStage($this->stage1, ['user_id' => $an->id, 'status' => SyllabusAssignment::STATUS_CLOSED]);

        $this->actingAs($an)->get(route('classes.index', ['mine' => 1]))->assertOk()
            ->assertInertia(fn ($page) => $page->has('classes.data', 0));
        $this->actingAs($an)->get(route('syllabus.teaching-stages'))->assertOk()
            ->assertInertia(fn ($page) => $page->has('assignments', 0));
    }

    public function test_substitute_teacher_is_not_listed_but_keeps_session_access(): void
    {
        $an = $this->assignedTeacher();
        $this->openStage($this->stage1);
        ClassSession::create([
            'class_id' => $this->class->id, 'branch_id' => $this->branch->id, 'date' => today()->addDay(), 'start_time' => '18:00',
            'end_time' => '19:30', 'status' => 'scheduled', 'teacher_id' => $an->id,
        ]);

        $this->actingAs($an)->get(route('classes.index', ['mine' => 1]))->assertOk()
            ->assertInertia(fn ($page) => $page->has('classes.data', 0));
        $this->actingAs($an)->get(route('syllabus.teaching-stages'))->assertOk()
            ->assertInertia(fn ($page) => $page->has('assignments', 0));

        // Quyền theo buổi vẫn còn: mở được trang lớp của buổi mình dạy thay.
        $this->assertTrue(ClassModel::visibleTo($an)->whereKey($this->class->id)->exists());
        $this->assertFalse(ClassModel::visibleTo($an, false)->whereKey($this->class->id)->exists());
    }

    public function test_unrelated_teacher_sees_nothing(): void
    {
        $other = $this->assignedTeacher();
        $this->openStage($this->stage1);

        $this->actingAs($other)->get(route('classes.index', ['mine' => 1]))->assertOk()
            ->assertInertia(fn ($page) => $page->has('classes.data', 0));
        $this->actingAs($other)->get(route('syllabus.teaching-stages'))->assertOk()
            ->assertInertia(fn ($page) => $page->has('assignments', 0));
    }
}
