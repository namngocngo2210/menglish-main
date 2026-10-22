<?php

namespace Tests\Feature;

use App\Models\SyllabusCurriculum;
use App\Models\SyllabusDocument;
use App\Models\User;
use App\Services\Syllabus\Import\SyllabusImportStore;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BuildsSyllabusPackages;
use Tests\TestCase;

/** Trang import syllabus từ file: tải mẫu, tải file lên, xem trước (không ghi DB). */
class SyllabusImportPageTest extends TestCase
{
    use BuildsSyllabusPackages;
    use RefreshDatabase;

    private User $academic;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(SyllabusDocument::DISK);
        $this->academic = User::factory()->create(['is_active' => true]);
        $this->academic->assignRole(Roles::ACADEMIC_LEAD);
        $this->teacher = User::factory()->create(['is_active' => true]);
        $this->teacher->assignRole(Roles::TEACHER_FULLTIME);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(SyllabusImportStore::root());
        parent::tearDown();
    }

    private function upload(string $path): UploadedFile
    {
        return new UploadedFile($path, basename($path), null, null, true);
    }

    public function test_teacher_cannot_open_import(): void
    {
        $this->actingAs($this->teacher)->get(route('syllabus.import'))->assertForbidden();
        $this->actingAs($this->teacher)->get(route('syllabus.import.template'))->assertForbidden();
        $this->actingAs($this->teacher)->post(route('syllabus.import.preview'), [
            'file' => $this->upload($this->makeXlsx($this->validSheets())),
        ])->assertForbidden();
    }

    public function test_template_download(): void
    {
        $response = $this->actingAs($this->academic)->get(route('syllabus.import.template'));

        $response->assertOk();
        $this->assertStringContainsString('mau-import-syllabus.zip', $response->headers->get('content-disposition'));
    }

    public function test_preview_shows_tree_without_writing(): void
    {
        $response = $this->actingAs($this->academic)->post(route('syllabus.import.preview'), [
            'file' => $this->upload($this->makeXlsx($this->validSheets())),
            'visible_to_teachers' => '1',
        ]);
        $response->assertRedirect();
        parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertNotEmpty($query['token']);

        $this->actingAs($this->academic)->get(route('syllabus.import', ['token' => $query['token']]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Syllabus/Import')
                ->where('preview.summary.lessons', 4)
                ->where('preview.errors', [])
                ->where('preview.curriculum.code', 'KIDS-Q4')
                ->missing('preview.stages.0.units.0.lessons.0.objectives'))
            ->assertSee('Buổi 1');

        $this->assertSame(0, SyllabusCurriculum::count());
    }

    public function test_preview_lists_errors(): void
    {
        $sheets = $this->validSheets();
        $sheets['Buổi'][1][1] = null; // thiếu số unit
        $sheets['Buổi'][2][3] = 1;    // trùng số buổi

        $response = $this->actingAs($this->academic)->post(route('syllabus.import.preview'), [
            'file' => $this->upload($this->makeXlsx($sheets)),
        ]);
        $response->assertRedirect();

        $page = $this->actingAs($this->academic)->get($response->headers->get('Location'));
        $page->assertInertia(fn (Assert $p) => $p->component('Syllabus/Import')->has('preview.errors'));
        $errors = $page->viewData('page')['props']['preview']['errors'];
        $this->assertNotEmpty($errors);
        $page->assertSee(e($errors[0]), false);
    }

    public function test_fatal_package_error_returns_to_upload(): void
    {
        $zip = $this->makeZip(['syllabus.xlsx' => 'x', '../evil.pdf' => $this->pdfBytes()]);

        $this->actingAs($this->academic)->post(route('syllabus.import.preview'), ['file' => $this->upload($zip)])
            ->assertSessionHasErrors('file');

        $this->assertSame([], File::glob(SyllabusImportStore::root().'/*'));
    }

    public function test_builder_shows_import_button_for_managers(): void
    {
        $this->actingAs($this->academic)->get(route('syllabus.builder'))->assertSee('Import từ file');
    }
}
