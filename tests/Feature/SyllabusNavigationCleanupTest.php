<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\SyllabusCurriculum;
use App\Models\SyllabusLesson;
use App\Models\SyllabusUnit;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Dọn giao diện mục Giáo trình (docs/giao-trinh-don-giao-dien.md): /syllabus vào màn theo quyền,
 * đầu trang chỉ có thao tác của trang, nút quay lại chỉ ở trang con, ô soạn Builder mở trong modal.
 */
class SyllabusNavigationCleanupTest extends TestCase
{
    use RefreshDatabase;

    private User $academic;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $branch = Branch::create(['name' => 'Cơ sở Dọn GT', 'code' => 'DG', 'is_active' => true]);
        $this->academic = User::factory()->create(['is_active' => true, 'branch_id' => $branch->id]);
        $this->academic->assignRole(Roles::ACADEMIC_LEAD);
        $this->teacher = User::factory()->create(['is_active' => true, 'branch_id' => $branch->id]);
        $this->teacher->assignRole(Roles::TEACHER_FULLTIME);
    }

    public function test_syllabus_index_sends_managers_to_documents(): void
    {
        $this->actingAs($this->academic)->get('/syllabus')->assertRedirect(route('syllabus.curriculums.index'));
    }

    public function test_syllabus_index_sends_teachers_to_teaching_stages(): void
    {
        $this->actingAs($this->teacher)->get('/syllabus')->assertRedirect(route('syllabus.teaching-stages'));
    }

    public function test_manager_pages_have_no_cross_links_or_back_button(): void
    {
        $this->actingAs($this->academic)->get(route('syllabus.documents'))->assertOk()
            ->assertDontSee('Xem như giáo viên')->assertDontSee('data-back-link', false);
        $this->actingAs($this->academic)->get(route('syllabus.curriculums.index'))->assertOk()
            ->assertDontSee('Tiếp tục: Chặng của lớp')->assertDontSee('data-back-link', false);
        $this->actingAs($this->academic)->get(route('syllabus.assignments'))->assertOk()
            ->assertDontSee('Màn GV xem giáo trình');
        $this->actingAs($this->academic)->get(route('syllabus.versions'))->assertOk()
            ->assertDontSee('data-back-link', false);
        $this->actingAs($this->academic)->get(route('syllabus.adjustment-requests'))->assertOk()
            ->assertDontSee('Gửi yêu cầu mới');
        $this->actingAs($this->academic)->get(route('syllabus.big-tests.schedules'))->assertOk()
            ->assertDontSee('data-back-link', false);
    }

    public function test_teacher_pages_have_no_cross_links_or_back_button(): void
    {
        $this->actingAs($this->teacher)->get(route('syllabus.teacher-view'))->assertOk()
            ->assertDontSee('data-back-link', false);
        $this->actingAs($this->teacher)->get(route('syllabus.teacher-propose'))->assertOk()
            ->assertDontSee('Xem trạng thái đề xuất')->assertDontSee('data-back-link', false);
        $this->actingAs($this->teacher)->get(route('syllabus.teacher-adjust'))->assertOk()
            ->assertDontSee('Duyệt yêu cầu tiến độ')->assertDontSee('data-back-link', false);
    }

    public function test_import_is_a_child_page_with_back_to_builder(): void
    {
        $this->actingAs($this->academic)->get(route('syllabus.import'))->assertOk()
            ->assertSee('data-back-link href="'.route('syllabus.curriculums.index', [], false).'"', false);
    }

    public function test_builder_editor_opens_in_modal_from_query(): void
    {
        [$curriculum, $lesson] = $this->curriculumWithLesson('CUR-DG1');

        $this->actingAs($this->academic)
            ->get(route('syllabus.curriculums.show', ['id' => $curriculum->id, 'edit_lesson' => $lesson->id]))
            ->assertOk()
            ->assertSee('form="syllabus-editor-form"', false)
            ->assertSee('Sửa Buổi '.$lesson->session_no.': '.$lesson->title)
            ->assertDontSee('#editor', false);
    }

    public function test_builder_ignores_editor_id_from_other_curriculum(): void
    {
        [$curriculum] = $this->curriculumWithLesson('CUR-DG1');
        [, $otherLesson] = $this->curriculumWithLesson('CUR-DG2');

        $this->actingAs($this->academic)
            ->get(route('syllabus.curriculums.show', ['id' => $curriculum->id, 'edit_lesson' => $otherLesson->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('editor', null));
    }

    public function test_builder_editor_stays_open_after_validation_error(): void
    {
        [$curriculum] = $this->curriculumWithLesson('CUR-DG1');
        $stage = $curriculum->stages()->firstOrFail();
        $editUrl = route('syllabus.curriculums.show', ['id' => $curriculum->id, 'edit_stage' => $stage->id]);

        $this->actingAs($this->academic)->from($editUrl)
            ->put(route('syllabus.stages.update', $stage->id), ['name' => ''])
            ->assertRedirect($editUrl)
            ->assertSessionHasErrors('name');
        $this->actingAs($this->academic)->get($editUrl)->assertOk()
            ->assertSee('form="syllabus-editor-form"', false)
            ->assertSee('Lưu chặng học');
    }

    /** @return array{0: SyllabusCurriculum, 1: SyllabusLesson} */
    private function curriculumWithLesson(string $code): array
    {
        $curriculum = SyllabusCurriculum::create(['code' => $code, 'title' => 'Giáo trình '.$code, 'version' => 'v1']);
        $unit = SyllabusUnit::create(['curriculum_id' => $curriculum->id, 'stage_id' => $curriculum->stages()->firstOrFail()->id, 'unit_number' => 1, 'title' => 'Unit '.$code]);
        $lesson = SyllabusLesson::create(['unit_id' => $unit->id, 'session_no' => 1, 'title' => 'Buổi '.$code]);

        return [$curriculum, $lesson];
    }
}
