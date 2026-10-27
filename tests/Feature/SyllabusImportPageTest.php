<?php

namespace Tests\Feature;

use App\Models\AdminNotification;
use App\Models\BackgroundTask;
use App\Models\SyllabusCurriculum;
use App\Models\SyllabusDocument;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Services\Syllabus\Import\SyllabusImportStore;
use App\Services\Syllabus\Import\SyllabusPackage;
use App\Services\Syllabus\Import\SyllabusPackageReader;
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
    use BuildsSyllabusPackages {
        tearDown as cleanUpSyllabusPackages;
    }
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
        $this->cleanUpSyllabusPackages();
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

    public function test_unexpected_reader_error_returns_generic_message_and_cleans_up(): void
    {
        $this->app->bind(SyllabusPackageReader::class, fn () => new class extends SyllabusPackageReader
        {
            public function read(string $path, string $workDir): SyllabusPackage
            {
                throw new \RuntimeException('boom');
            }
        });

        $this->actingAs($this->academic)->post(route('syllabus.import.preview'), ['file' => $this->upload($this->makeXlsx($this->validSheets()))])
            ->assertSessionHasErrors(['file' => 'Không đọc được file. Vui lòng dùng file mẫu (.zip/.xlsx).']);

        $this->assertSame([], File::glob(SyllabusImportStore::root().'/*'));
    }

    public function test_unexpected_reader_error_on_reload_redirects_and_forgets_token(): void
    {
        $token = $this->previewToken($this->academic);
        $this->app->bind(SyllabusPackageReader::class, fn () => new class extends SyllabusPackageReader
        {
            public function read(string $path, string $workDir): SyllabusPackage
            {
                throw new \RuntimeException('boom');
            }
        });

        $this->actingAs($this->academic)->get(route('syllabus.import', ['token' => $token]))
            ->assertRedirect(route('syllabus.import'))
            ->assertSessionHasErrors(['file' => 'Không đọc được file. Vui lòng dùng file mẫu (.zip/.xlsx).']);

        $this->assertSame([], File::glob(SyllabusImportStore::root()."/{$token}*"));
    }

    public function test_array_token_is_treated_as_expired(): void
    {
        $this->actingAs($this->academic)->post(route('syllabus.import.confirm'), ['token' => ['x']])
            ->assertRedirect(route('syllabus.import'))
            ->assertSessionHasErrors('file');
        $this->actingAs($this->academic)->get(route('syllabus.import', ['token' => ['x']]))
            ->assertRedirect(route('syllabus.import'));
    }

    public function test_builder_shows_import_button_for_managers(): void
    {
        $this->actingAs($this->academic)->get(route('syllabus.curriculums.index'))->assertSee('Import giáo trình mới');
    }

    /** Tải gói lên + mở trang xem trước, trả về token. */
    private function previewToken(User $user, ?string $path = null): string
    {
        $response = $this->actingAs($user)->post(route('syllabus.import.preview'), [
            'file' => $this->upload($path ?? $this->makeXlsx($this->validSheets())),
        ]);
        parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

        return $query['token'];
    }

    public function test_confirm_creates_curriculum_and_redirects_to_builder(): void
    {
        $token = $this->previewToken($this->academic);
        $this->actingAs($this->academic)->get(route('syllabus.import', ['token' => $token]))->assertSee('Xác nhận import');
        $this->assertNotEmpty(File::glob(SyllabusImportStore::root()."/{$token}*"));

        $response = $this->actingAs($this->academic)->post(route('syllabus.import.confirm'), ['token' => $token]);

        // Ghi giáo trình chạy nền (queue sync trong test: đã xong khi request trả về).
        $response->assertRedirect(route('syllabus.import'));
        $this->assertStringContainsString('Đang import giáo trình KIDS-Q4 ở nền', session('status'));
        $curriculum = SyllabusCurriculum::where('code', 'KIDS-Q4')->firstOrFail();
        $task = BackgroundTask::query()->where('type', BackgroundTask::SYLLABUS_IMPORT)->firstOrFail();
        $this->assertSame(BackgroundTask::DONE, $task->status);
        $this->assertStringContainsString('Đã import giáo trình KIDS-Q4: 2 chặng, 4 buổi, 0 tài liệu.', $task->message);
        $this->assertSame(route('syllabus.curriculums.show', ['id' => $curriculum->id]),
            AdminNotification::query()->where('user_id', $this->academic->id)->where('type', 'background_task')->firstOrFail()->data['link']);
        $this->assertSame([], File::glob(SyllabusImportStore::root()."/{$token}*"));
    }

    public function test_confirm_twice_creates_one_curriculum(): void
    {
        $token = $this->previewToken($this->academic);
        $this->actingAs($this->academic)->post(route('syllabus.import.confirm'), ['token' => $token]);

        $this->actingAs($this->academic)->post(route('syllabus.import.confirm'), ['token' => $token])
            ->assertRedirect(route('syllabus.import'))
            ->assertSessionHasErrors(['file' => 'Phiên kiểm tra đã hết hạn, vui lòng tải file lên lại.']);

        $this->assertSame(1, SyllabusCurriculum::count());
    }

    public function test_confirm_with_other_users_token_is_rejected(): void
    {
        $token = $this->previewToken($this->academic);
        $other = User::factory()->create(['is_active' => true]);
        $other->assignRole(Roles::ACADEMIC_LEAD);

        $this->actingAs($other)->post(route('syllabus.import.confirm'), ['token' => $token])
            ->assertSessionHasErrors('file');

        $this->assertSame(0, SyllabusCurriculum::count());
    }

    public function test_confirm_after_expiry_is_rejected(): void
    {
        $token = $this->previewToken($this->academic);
        $this->travel(121)->minutes();

        $this->actingAs($this->academic)->post(route('syllabus.import.confirm'), ['token' => $token])
            ->assertRedirect(route('syllabus.import'))
            ->assertSessionHasErrors('file');

        $this->assertSame(0, SyllabusCurriculum::count());
    }

    public function test_confirm_rechecks_duplicate_code(): void
    {
        $token = $this->previewToken($this->academic);
        SyllabusCurriculum::create(['code' => 'KIDS-Q4', 'title' => 'Có sẵn', 'version' => 'v1']);

        $this->actingAs($this->academic)->post(route('syllabus.import.confirm'), ['token' => $token])
            ->assertRedirect(route('syllabus.import', ['token' => $token]))
            ->assertSessionHasErrors('file');

        $this->assertSame(1, SyllabusCurriculum::count());
    }

    public function test_import_requires_library_permission(): void
    {
        // Bị gỡ quyền Kho syllabus (syllabus.library) → không xem trước / xác nhận import được.
        UserPermissionOverride::create([
            'user_id' => $this->academic->id,
            'module' => 'syllabus',
            'action' => 'library',
            'scope_type' => UserPermissionOverride::SCOPE_ALL,
            'allow' => false,
        ]);

        $this->actingAs($this->academic)->post(route('syllabus.import.preview'), ['file' => $this->upload($this->makeXlsx($this->validSheets()))])
            ->assertForbidden();
        $this->actingAs($this->academic)->post(route('syllabus.import.confirm'), ['token' => 'x'])->assertForbidden();

        $this->assertSame(0, SyllabusCurriculum::count());
    }

    public function test_documents_page_has_no_sample_pdf(): void
    {
        $this->actingAs($this->academic)->get(route('syllabus.curriculums.index'))
            ->assertOk()
            ->assertDontSee('Tải file mẫu (.pdf)')
            ->assertDontSee('mau-tai-lieu-giao-trinh.pdf');
    }
}
