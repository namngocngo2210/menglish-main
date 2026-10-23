<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\SyllabusAssignment;
use App\Models\SyllabusCurriculum;
use App\Models\SyllabusDocument;
use App\Models\SyllabusLesson;
use App\Models\SyllabusStage;
use App\Models\SyllabusUnit;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Kho syllabus — tài liệu theo Buổi / Chặng / Chung trên trang chi tiết; GV / TA chỉ thấy tài liệu chặng đang mở của
 * lớp mình (docs/kho-syllabus.md §3.1, §3.4).
 */
class SyllabusLibraryDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $lead;

    private SyllabusCurriculum $curriculum;

    private SyllabusStage $stage1;

    private SyllabusStage $stage2;

    private SyllabusLesson $lesson;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(SyllabusDocument::DISK);

        $this->branch = Branch::create(['name' => 'Cơ sở Tài liệu', 'code' => 'TL', 'is_active' => true]);
        $this->lead = $this->userWithRole(Roles::ACADEMIC_LEAD);
        [$this->curriculum, $this->stage1, $this->stage2, $this->lesson] = $this->curriculumWithTwoStages('CUR-TL');
    }

    public function test_upload_to_lesson_sets_lesson_and_its_stage(): void
    {
        $this->actingAs($this->lead)->post(route('syllabus.documents.store'), [
            'curriculum_id' => $this->curriculum->id, 'syllabus_lesson_id' => $this->lesson->id, 'title' => 'PPT buổi 1',
            'visible_to_teachers' => 1, 'file' => UploadedFile::fake()->create('b1.pdf', 10, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        $doc = SyllabusDocument::firstOrFail();
        $this->assertSame($this->lesson->id, $doc->syllabus_lesson_id);
        $this->assertSame($this->stage1->id, $doc->stage_id);
    }

    public function test_upload_rejects_lesson_of_other_curriculum(): void
    {
        [, , , $otherLesson] = $this->curriculumWithTwoStages('CUR-KHAC');

        $this->actingAs($this->lead)->post(route('syllabus.documents.store'), [
            'curriculum_id' => $this->curriculum->id, 'syllabus_lesson_id' => $otherLesson->id, 'title' => 'Sai giáo trình',
            'file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'),
        ])->assertSessionHasErrors('syllabus_lesson_id');

        $this->assertDatabaseCount('syllabus_documents', 0);
    }

    public function test_detail_page_groups_documents_by_scope(): void
    {
        $shared = $this->document('Chung');
        $stageDoc = $this->document('Chặng 2', stage: $this->stage2);
        $lessonDoc = $this->document('Buổi 1', lesson: $this->lesson);

        $this->actingAs($this->lead)->get(route('syllabus.curriculums.show', ['id' => $this->curriculum->id, 'tab' => 'documents']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Syllabus/CurriculumShow')
                ->where('tab', 'documents')
                ->where('documents.shared.0.id', $shared->id)
                ->where('documents.stages.'.$this->stage2->id.'.0.id', $stageDoc->id)
                ->where('documents.lessons.'.$this->lesson->id.'.0.id', $lessonDoc->id)
                ->missing('documents.stages.'.$this->stage1->id));
    }

    public function test_soft_deleted_lesson_documents_stay_on_stage_for_library(): void
    {
        $lessonDoc = $this->document('Buổi 1', lesson: $this->lesson);
        $this->lesson->delete();

        $this->actingAs($this->lead)->get(route('syllabus.curriculums.show', $this->curriculum->id))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('documents.stages.'.$this->stage1->id.'.0.id', $lessonDoc->id));
    }

    public function test_documents_url_redirects_to_library(): void
    {
        $this->actingAs($this->lead)->get(route('syllabus.documents'))->assertRedirect(route('syllabus.curriculums.index'));
    }

    public function test_academic_staff_sees_and_downloads_every_document_but_cannot_upload(): void
    {
        $staff = $this->userWithRole(Roles::ACADEMIC_STAFF);
        $stage2Doc = $this->document('Chặng 2', stage: $this->stage2); // chặng chưa lớp nào học
        $stage2Doc->update(['downloadable' => false, 'visible_to_teachers' => false]);

        $this->assertContains($stage2Doc->id, SyllabusDocument::visibleTo($staff)->pluck('id')->all());
        $this->assertTrue($stage2Doc->canDownload($staff));
        Storage::disk(SyllabusDocument::DISK)->put($stage2Doc->file_path, '%PDF-1.4');
        $this->actingAs($staff)->get(route('syllabus.documents.file', ['id' => $stage2Doc->id, 'download' => 1]))->assertOk();
        $this->actingAs($staff)->post(route('syllabus.documents.store'), [
            'curriculum_id' => $this->curriculum->id, 'title' => 'X', 'file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'),
        ])->assertForbidden();
        $this->actingAs($staff)->delete(route('syllabus.documents.destroy', $stage2Doc->id))->assertForbidden();
    }

    // ---- Phạm vi tài liệu của giáo viên (Task 2) ----

    public function test_teacher_sees_only_documents_of_open_stage_of_own_class(): void
    {
        $teacher = $this->userWithRole(Roles::TEACHER_FULLTIME);
        $this->openStage($this->stage1, $teacher);
        $shared = $this->document('Chung');
        $stage1Doc = $this->document('Chặng 1', stage: $this->stage1);
        $stage2Doc = $this->document('Chặng 2', stage: $this->stage2);
        $lessonDoc = $this->document('Buổi 1', lesson: $this->lesson);

        $this->assertEqualsCanonicalizing([$shared->id, $stage1Doc->id, $lessonDoc->id], SyllabusDocument::visibleTo($teacher)->pluck('id')->all());
        $this->actingAs($teacher)->get(route('syllabus.documents.file', $stage2Doc->id))->assertNotFound();
    }

    public function test_teacher_without_open_stage_sees_nothing(): void
    {
        $this->document('Chung');

        $this->assertSame(0, SyllabusDocument::visibleTo($this->userWithRole(Roles::TEACHER_FULLTIME))->count());
    }

    public function test_teacher_of_two_classes_sees_both_open_stages(): void
    {
        $teacher = $this->userWithRole(Roles::TEACHER_FULLTIME);
        [$other, $otherStage1] = $this->curriculumWithTwoStages('CUR-B');
        $this->openStage($this->stage1, $teacher);
        $this->openStage($otherStage1, $teacher);
        $a = $this->document('A', stage: $this->stage1);
        $b = $this->document('B', stage: $otherStage1, curriculum: $other);

        $this->assertEqualsCanonicalizing([$a->id, $b->id], SyllabusDocument::visibleTo($teacher)->pluck('id')->all());
    }

    public function test_lesson_moved_to_another_stage_follows_new_stage(): void
    {
        $teacher = $this->userWithRole(Roles::TEACHER_FULLTIME);
        $this->openStage($this->stage1, $teacher);
        $lessonDoc = $this->document('Buổi 1', lesson: $this->lesson);
        $unit2 = SyllabusUnit::create(['curriculum_id' => $this->curriculum->id, 'stage_id' => $this->stage2->id, 'unit_number' => 2, 'title' => 'Unit 2']);

        $this->actingAs($this->lead)->put(route('syllabus.lessons.update', $this->lesson->id), ['session_no' => 1, 'title' => 'Buổi 1', 'unit_id' => $unit2->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($this->stage2->id, $lessonDoc->fresh()->stage_id);
        $this->assertFalse($lessonDoc->fresh()->isVisibleTo($teacher));
    }

    public function test_unit_moved_to_another_stage_moves_its_lesson_documents(): void
    {
        $lessonDoc = $this->document('Buổi 1', lesson: $this->lesson);

        $this->actingAs($this->lead)->put(route('syllabus.units.update', $this->lesson->unit_id), ['unit_number' => 1, 'title' => 'Unit 1', 'stage_id' => $this->stage2->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($this->stage2->id, $lessonDoc->fresh()->stage_id);
    }

    public function test_substitute_teacher_of_a_session_sees_open_stage_documents(): void
    {
        $assignment = $this->openStage($this->stage1, $this->userWithRole(Roles::TEACHER_FULLTIME));
        $substitute = $this->userWithRole(Roles::TEACHER_FULLTIME);
        ClassSession::create(['class_id' => $assignment->class_id, 'branch_id' => $this->branch->id, 'date' => now()->toDateString(),
            'start_time' => '18:00', 'end_time' => '19:30', 'teacher_id' => $substitute->id, 'status' => 'scheduled']);
        $doc = $this->document('Chặng 1', stage: $this->stage1);

        $this->assertTrue($doc->isVisibleTo($substitute));
    }

    /** @return array{0: SyllabusCurriculum, 1: SyllabusStage, 2: SyllabusStage, 3: SyllabusLesson} */
    private function curriculumWithTwoStages(string $code): array
    {
        $curriculum = SyllabusCurriculum::create(['code' => $code, 'title' => 'Giáo trình '.$code, 'version' => 'v1']);
        $stage1 = $curriculum->stages()->firstOrFail();
        $stage2 = SyllabusStage::create(['curriculum_id' => $curriculum->id, 'position' => 2, 'name' => 'Chặng 2']);
        $unit = SyllabusUnit::create(['curriculum_id' => $curriculum->id, 'stage_id' => $stage1->id, 'unit_number' => 1, 'title' => 'Unit 1']);
        $lesson = SyllabusLesson::create(['unit_id' => $unit->id, 'session_no' => 1, 'title' => 'Buổi 1']);

        return [$curriculum, $stage1, $stage2, $lesson];
    }

    private function document(string $title, ?SyllabusStage $stage = null, ?SyllabusLesson $lesson = null, ?SyllabusCurriculum $curriculum = null): SyllabusDocument
    {
        return SyllabusDocument::create([
            'curriculum_id' => ($curriculum ?? $this->curriculum)->id,
            'stage_id' => $lesson ? $lesson->unit->stage_id : $stage?->id,
            'syllabus_lesson_id' => $lesson?->id,
            'title' => $title,
            'file_path' => 'syllabus-documents/'.$title.'.pdf',
            'original_name' => $title.'.pdf',
            'extension' => 'pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 100,
            'visible_to_teachers' => true,
            'visible_to_assistants' => true,
            'downloadable' => true,
            'uploaded_by' => $this->lead->id,
        ]);
    }

    private function openStage(SyllabusStage $stage, User $teacher): SyllabusAssignment
    {
        $class = ClassModel::create(['code' => 'L-'.$stage->id.'-'.$teacher->id, 'name' => 'Lớp '.$stage->id, 'branch_id' => $this->branch->id, 'teacher_id' => $teacher->id, 'status' => 'active']);

        return SyllabusAssignment::create([
            'class_id' => $class->id, 'curriculum_id' => $stage->curriculum_id, 'stage_id' => $stage->id, 'stage_name' => $stage->label,
            'user_id' => $teacher->id, 'assigned_chapters' => $stage->label, 'status' => SyllabusAssignment::STATUS_OPEN, 'open_class_id' => $class->id, 'opened_at' => now(),
        ]);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['is_active' => true, 'branch_id' => $this->branch->id]);
        $user->assignRole($role);

        return $user;
    }
}
