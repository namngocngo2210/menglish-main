<?php

namespace Tests\Feature;

use App\Models\SyllabusLesson;
use App\Models\SyllabusLessonSlide;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsSyllabusWorkflow;
use Tests\TestCase;
use ZipArchive;

/** Bài giảng PowerPoint của buổi tải lên app, trình chiếu bằng iframe Office Online (docs/kho-syllabus.md §7.4). */
class SyllabusLessonSlidesTest extends TestCase
{
    use BuildsSyllabusWorkflow, RefreshDatabase;

    private SyllabusLesson $lesson;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(SyllabusLessonSlide::DISK);
        config(['app.url' => 'https://portal.example.com']);
        $this->buildSyllabusWorkflow();
        $this->openStage($this->stage1);
        $this->lesson = SyllabusLesson::where('session_no', 1)->firstOrFail();
    }

    public function test_academic_uploads_and_replaces_lesson_ppt(): void
    {
        $this->upload('ppt', 'Unit 1 - Lesson 1.pptx')->assertSessionHasNoErrors();
        $slide = SyllabusLessonSlide::firstOrFail();
        $this->assertSame([$this->lesson->id, 'ppt', 'Unit 1 - Lesson 1.pptx'], [$slide->syllabus_lesson_id, $slide->kind, $slide->original_name]);
        Storage::disk(SyllabusLessonSlide::DISK)->assertExists($slide->file_path);
        $oldPath = $slide->file_path;

        // Tải lại cùng loại = thay file (1 file / buổi / loại), file cũ bị xóa.
        $this->travel(1)->seconds();
        $this->upload('ppt', 'Unit 1 - Lesson 1 v2.pptx')->assertSessionHasNoErrors();
        $this->assertSame(1, SyllabusLessonSlide::count());
        $slide->refresh();
        $this->assertSame('Unit 1 - Lesson 1 v2.pptx', $slide->original_name);
        Storage::disk(SyllabusLessonSlide::DISK)->assertMissing($oldPath);

        $this->upload('ppt_foreign', 'GVNN.pptx')->assertSessionHasNoErrors();
        $this->assertSame(2, SyllabusLessonSlide::count());

        $this->actingAs($this->lead)->get(route('syllabus.curriculums.show', $this->curriculum->id))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('stages.0.units.0.lessons.0.slides.ppt.name', 'Unit 1 - Lesson 1 v2.pptx')
                ->where('stages.0.units.0.lessons.0.slides.ppt_foreign.url', route('syllabus.slides.show', SyllabusLessonSlide::where('kind', 'ppt_foreign')->value('id'))));
    }

    public function test_only_powerpoint_files_and_only_library_editors_can_upload(): void
    {
        $this->actingAs($this->lead)->post(route('syllabus.slides.store', $this->lesson->id), [
            'kind' => 'ppt', 'file' => UploadedFile::fake()->create('de.pdf', 10, 'application/pdf'),
        ])->assertSessionHasErrors('file');
        $this->actingAs($this->teacher)->post(route('syllabus.slides.store', $this->lesson->id), [
            'kind' => 'ppt', 'file' => $this->pptx('a.pptx'),
        ])->assertForbidden();
        $this->assertSame(0, SyllabusLessonSlide::count());
    }

    public function test_viewer_page_embeds_office_online_for_teachers_of_open_stage_only(): void
    {
        $this->upload('ppt', 'b1.pptx');
        $slide = SyllabusLessonSlide::firstOrFail();
        $stage2Lesson = SyllabusLesson::where('session_no', 7)->firstOrFail();
        $this->actingAs($this->lead)->post(route('syllabus.slides.store', $stage2Lesson->id), ['kind' => 'ppt', 'file' => $this->pptx('b7.pptx')]);
        $closedStageSlide = SyllabusLessonSlide::where('syllabus_lesson_id', $stage2Lesson->id)->firstOrFail();

        foreach ([$this->lead, $this->staff, $this->teacher, $this->foreignTeacher] as $user) {
            $this->actingAs($user)->get(route('syllabus.slides.show', $slide->id))->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page->component('Syllabus/SlideViewer')
                    ->where('embedUrl', fn (string $url) => str_starts_with($url, SyllabusLessonSlide::EMBED_URL.rawurlencode('https://portal.example.com/slides/'.$slide->id.'/')))
                    ->where('publicHost', true));
        }
        // GV chỉ xem bài giảng của chặng lớp đang học; GV lớp khác không xem được.
        $this->actingAs($this->teacher)->get(route('syllabus.slides.show', $closedStageSlide->id))->assertNotFound();
        $this->actingAs($this->userWithRole(Roles::TEACHER_FULLTIME, 'GV khác'))->get(route('syllabus.slides.show', $slide->id))->assertNotFound();
        $this->actingAs($this->lead)->get(route('syllabus.slides.show', $closedStageSlide->id))->assertOk();
    }

    public function test_public_file_needs_valid_unexpired_signature(): void
    {
        $this->upload('ppt', 'b1.pptx');
        $slide = SyllabusLessonSlide::firstOrFail();
        $url = $slide->publicUrl();
        $path = parse_url($url, PHP_URL_PATH);

        // Trình xem Office tải file không đăng nhập.
        $response = $this->get($path)->assertOk();
        $this->assertStringContainsString('presentationml', $response->headers->get('Content-Type'));
        $this->assertSame(Storage::disk(SyllabusLessonSlide::DISK)->get($slide->file_path), $response->streamedContent());

        $this->get(substr($path, 0, -strlen('/slide.pptx')).'0/slide.pptx')->assertNotFound();
        $this->get(preg_replace('#/[a-f0-9]{64}/#', '/'.str_repeat('a', 64).'/', $path))->assertForbidden();

        $this->travel(SyllabusLessonSlide::PUBLIC_MINUTES + 1)->minutes();
        $this->get($path)->assertForbidden();
    }

    public function test_replacing_file_invalidates_old_public_links_and_delete_removes_file(): void
    {
        $this->upload('ppt', 'b1.pptx');
        $path = parse_url(SyllabusLessonSlide::firstOrFail()->publicUrl(), PHP_URL_PATH);
        $this->travel(1)->seconds();
        $this->upload('ppt', 'b1-v2.pptx');
        $this->get($path)->assertForbidden();

        $slide = SyllabusLessonSlide::firstOrFail();
        $this->actingAs($this->teacher)->delete(route('syllabus.slides.destroy', $slide->id))->assertForbidden();
        $this->actingAs($this->lead)->delete(route('syllabus.slides.destroy', $slide->id))->assertRedirect();
        $this->assertSame(0, SyllabusLessonSlide::count());
        Storage::disk(SyllabusLessonSlide::DISK)->assertMissing($slide->file_path);
    }

    public function test_viewer_warns_when_app_url_is_not_public(): void
    {
        config(['app.url' => 'http://localhost:8000']);
        $this->upload('ppt', 'b1.pptx');
        $this->actingAs($this->lead)->get(route('syllabus.slides.show', SyllabusLessonSlide::firstOrFail()->id))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('publicHost', false));
    }

    private function upload(string $kind, string $name)
    {
        return $this->actingAs($this->lead)->post(route('syllabus.slides.store', $this->lesson->id), ['kind' => $kind, 'file' => $this->pptx($name)]);
    }

    /** Gói PowerPoint tối giản (zip có [Content_Types].xml đầu tiên + ppt/presentation.xml). */
    private function pptx(string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'pptx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/></Types>');
        $zip->addFromString('ppt/presentation.xml', '<p:presentation xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"/>');
        $zip->addFromString('ppt/slides/slide1.xml', '<p:sld xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"/>');
        $zip->close();

        return new UploadedFile($path, $name, null, null, true);
    }
}
