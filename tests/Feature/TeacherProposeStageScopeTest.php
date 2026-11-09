<?php

namespace Tests\Feature;

use App\Models\SyllabusChangeProposal;
use App\Models\SyllabusCurriculum;
use App\Models\SyllabusLesson;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSyllabusWorkflow;
use Tests\TestCase;

/** TK-2026-0060 (GV-DX-01): GV chỉ đề xuất sửa giáo trình/buổi thuộc chặng đang mở được giao cho mình. */
class TeacherProposeStageScopeTest extends TestCase
{
    use BuildsSyllabusWorkflow, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSyllabusWorkflow();
    }

    private function lessonOf($stage): SyllabusLesson
    {
        return SyllabusLesson::whereHas('unit', fn ($q) => $q->where('stage_id', $stage->id))->firstOrFail();
    }

    public function test_page_lists_only_lessons_of_assigned_stage(): void
    {
        $this->openStage($this->stage1, ['user_id' => $this->teacher->id]);
        $other = SyllabusCurriculum::create(['code' => 'QT-OTHER', 'title' => 'Giáo trình khác', 'version' => 'v1']);

        $this->actingAs($this->teacher)->get(route('syllabus.teacher-propose'))->assertOk()
            ->assertInertia(fn ($page) => $page->has('curriculums', 1)
                ->where('curriculums.0.id', $this->curriculum->id)
                ->has('curriculums.0.lessons', 6)); // chặng 1 có 6 buổi, chặng 2 (4 buổi) bị ẩn
        $this->assertNotSame($other->id, $this->curriculum->id);
    }

    public function test_teacher_without_assigned_stage_sees_nothing(): void
    {
        $this->openStage($this->stage1, ['user_id' => $this->foreignTeacher->id]);

        $this->actingAs($this->teacher)->get(route('syllabus.teacher-propose'))->assertOk()
            ->assertInertia(fn ($page) => $page->has('curriculums', 0));
    }

    public function test_server_rejects_lesson_of_other_stage_and_unassigned_curriculum(): void
    {
        $this->openStage($this->stage1, ['user_id' => $this->teacher->id]);
        $other = SyllabusCurriculum::create(['code' => 'QT-OTHER', 'title' => 'Giáo trình khác', 'version' => 'v1']);

        $this->actingAs($this->teacher)->post(route('syllabus.proposals.store'), [
            'curriculum_id' => $this->curriculum->id, 'lesson_id' => $this->lessonOf($this->stage2)->id, 'new_content' => 'Sửa buổi chặng khác',
        ])->assertForbidden();
        $this->actingAs($this->teacher)->post(route('syllabus.proposals.store'), [
            'curriculum_id' => $other->id, 'new_content' => 'Sửa giáo trình không được giao',
        ])->assertForbidden();
        $this->assertSame(0, SyllabusChangeProposal::count());
    }

    public function test_server_accepts_lesson_of_assigned_stage(): void
    {
        $this->openStage($this->stage1, ['user_id' => $this->teacher->id]);

        $this->actingAs($this->teacher)->post(route('syllabus.proposals.store'), [
            'curriculum_id' => $this->curriculum->id, 'lesson_id' => $this->lessonOf($this->stage1)->id, 'new_content' => 'Sửa buổi chặng mình',
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, SyllabusChangeProposal::count());
    }

    public function test_stage_assigned_to_another_teacher_is_rejected(): void
    {
        $an = $this->userWithRole(Roles::TEACHER_PARTTIME, 'GV An');
        $this->openStage($this->stage1, ['user_id' => $an->id]);

        $this->actingAs($this->teacher)->post(route('syllabus.proposals.store'), [
            'curriculum_id' => $this->curriculum->id, 'lesson_id' => $this->lessonOf($this->stage1)->id, 'new_content' => 'Chặng của GV khác',
        ])->assertForbidden();
    }
}
