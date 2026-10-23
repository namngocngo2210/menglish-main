<?php

namespace Tests\Feature;

use App\Models\SyllabusCurriculum;
use App\Models\SyllabusDocument;
use App\Models\SyllabusLesson;
use App\Models\SyllabusStage;
use App\Models\SyllabusUnit;
use App\Models\User;
use App\Services\Syllabus\Import\SyllabusImporter;
use App\Services\Syllabus\Import\SyllabusImportException;
use App\Services\Syllabus\Import\SyllabusImportPlan;
use App\Services\Syllabus\Import\SyllabusPackageReader;
use App\Services\Syllabus\Import\SyllabusPackageValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsSyllabusPackages;
use Tests\TestCase;

class SyllabusImporterTest extends TestCase
{
    use BuildsSyllabusPackages, RefreshDatabase;

    private const VISIBILITY = ['visible_to_teachers' => true, 'visible_to_assistants' => false, 'downloadable' => true];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(SyllabusDocument::DISK);
    }

    private function plan(array $zipEntries = [], ?array $sheets = null): SyllabusImportPlan
    {
        $xlsx = $this->makeXlsx($sheets ?? $this->validSheets());
        $path = $zipEntries === [] ? $xlsx : $this->makeZip(['syllabus.xlsx' => file_get_contents($xlsx)] + $zipEntries);
        $package = (new SyllabusPackageReader)->read($path, $this->newPackageDir());
        $plan = (new SyllabusPackageValidator)->validate($package, true);
        $this->assertTrue($plan->ok(), implode(' | ', $plan->errors));

        return $plan;
    }

    public function test_imports_full_tree(): void
    {
        $curriculum = (new SyllabusImporter)->import($this->plan(), User::factory()->create(), self::VISIBILITY);

        $this->assertSame('KIDS-Q4', $curriculum->code);
        $this->assertSame('Kids Quý 4', $curriculum->title);
        $this->assertSame('Giáo trình thiếu nhi', $curriculum->description);

        $stages = SyllabusStage::where('curriculum_id', $curriculum->id)->orderBy('position')->get();
        $this->assertSame([1, 2], $stages->pluck('position')->all());
        $this->assertSame('Big Test 1', $stages[0]->big_test_title);
        $this->assertSame('Ôn tập', $stages[0]->big_test_note);
        $this->assertSame('Làm quen', $stages[0]->description);
        $this->assertSame('https://example.com/muc-luc-1', $stages[0]->overview_link);

        $units = SyllabusUnit::where('curriculum_id', $curriculum->id)->orderBy('unit_number')->get();
        $this->assertSame([$stages[0]->id, $stages[1]->id], $units->pluck('stage_id')->all());
        $this->assertSame(['Hello', 'Animals'], $units->pluck('title')->all());

        $lessons = SyllabusLesson::where('curriculum_id', $curriculum->id)->orderBy('session_no')->get();
        $this->assertSame([1, 2, 3, 4], $lessons->pluck('session_no')->all());
        $this->assertSame([$units[0]->id, $units[0]->id, $units[1]->id, $units[1]->id], $lessons->pluck('unit_id')->all());
        $this->assertSame('Chào hỏi', $lessons[0]->title);
        $this->assertSame('Biết chào', $lessons[0]->objectives);
        $this->assertSame('Hát', $lessons[0]->content);
        $this->assertSame('hello', $lessons[0]->vocabulary_focus);
        $this->assertSame('to be', $lessons[0]->grammar_focus);
        $this->assertSame('Làm bài 1', $lessons[0]->homework_guide);
    }

    public function test_imports_sheet1_columns(): void
    {
        $sheets = $this->validSheets();
        $sheets['Chặng'][0][] = 'Báo trước (buổi)';
        $sheets['Chặng'][1][6] = 3;
        $columns = array_keys(SyllabusPackageReader::LESSON_COLUMNS);
        $sheets['Buổi'][4] = array_map(fn (string $key) => [
            'stage' => 2, 'unit_number' => 2, 'unit_title' => 'Animals', 'session_no' => 4, 'title' => 'Ôn tập',
            'lesson_type' => 'Big test', 'ppt_url' => 'https://example.com/ppt', 'notice_milestone' => 'Order Big Test', 'note' => 'In 30 đề',
        ][$key] ?? null, $columns);

        $curriculum = (new SyllabusImporter)->import($this->plan([], $sheets), User::factory()->create(), self::VISIBILITY);

        $stages = SyllabusStage::where('curriculum_id', $curriculum->id)->orderBy('position')->get();
        $this->assertSame([3, 6], $stages->pluck('notice_before_sessions')->all());
        $lesson = SyllabusLesson::where('curriculum_id', $curriculum->id)->where('session_no', 4)->firstOrFail();
        $this->assertSame(['big_test', 'https://example.com/ppt', 'order_big_test', 'In 30 đề'],
            [$lesson->lesson_type, $lesson->ppt_url, $lesson->notice_milestone, $lesson->note]);
        $this->assertSame('lesson', SyllabusLesson::where('curriculum_id', $curriculum->id)->where('session_no', 1)->value('lesson_type'));
    }

    public function test_imports_documents_with_visibility(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan([
            'tai-lieu/chang-1/Slide.pdf' => $this->pdfBytes(),
            'tai-lieu/Chung.pdf' => $this->pdfBytes(),
        ]);

        $curriculum = (new SyllabusImporter)->import($plan, $user, self::VISIBILITY);

        $stage1 = SyllabusStage::where('curriculum_id', $curriculum->id)->where('position', 1)->first();
        $docs = SyllabusDocument::where('curriculum_id', $curriculum->id)->get();
        $this->assertCount(2, $docs);

        $staged = $docs->firstWhere('stage_id', $stage1->id);
        $this->assertNotNull($staged);
        $this->assertSame($stage1->label, $staged->stage_name);
        $shared = $docs->firstWhere('stage_id', null);
        $this->assertNotNull($shared);
        $this->assertNull($shared->stage_name);

        foreach ($docs as $doc) {
            $this->assertTrue($doc->visible_to_teachers);
            $this->assertFalse($doc->visible_to_assistants);
            $this->assertTrue($doc->downloadable);
            $this->assertSame($user->id, $doc->uploaded_by);
            $this->assertSame('pdf', $doc->extension);
            $this->assertGreaterThan(0, $doc->size_bytes);
            Storage::disk(SyllabusDocument::DISK)->assertExists($doc->file_path);
        }
    }

    public function test_duplicate_code_throws(): void
    {
        $plan = $this->plan();
        SyllabusCurriculum::create(['code' => 'KIDS-Q4', 'title' => 'Cũ', 'version' => 'v1']);
        $before = SyllabusCurriculum::withTrashed()->count();

        try {
            (new SyllabusImporter)->import($plan, User::factory()->create(), self::VISIBILITY);
            $this->fail('Expected SyllabusImportException');
        } catch (SyllabusImportException $e) {
            $this->assertSame('Mã KIDS-Q4 đã tồn tại — đổi mã khác.', $e->getMessage());
        }

        $this->assertSame($before, SyllabusCurriculum::withTrashed()->count());
    }

    public function test_failure_rolls_back_and_removes_stored_files(): void
    {
        $good = $this->newPackageDir().'/a.pdf';
        file_put_contents($good, $this->pdfBytes());
        $doc = fn (string $path) => ['title' => 'A', 'original_name' => 'a.pdf', 'extension' => 'pdf', 'size' => 10, 'local_path' => $path];
        $plan = new SyllabusImportPlan(
            ['code' => 'X1', 'title' => 'X', 'version' => 'v1', 'description' => null],
            [['position' => 1, 'name' => 'Chặng 1', 'description' => null, 'big_test_title' => null, 'big_test_note' => null, 'overview_link' => null,
                'units' => [], 'documents' => [$doc($good), $doc('/nonexistent/b.pdf')]]],
            [],
            [],
            [],
        );

        try {
            (new SyllabusImporter)->import($plan, User::factory()->create(), self::VISIBILITY);
            $this->fail('Expected exception');
        } catch (\LogicException $e) {
            throw $e;
        } catch (\Throwable) {
            // expected
        }

        $this->assertSame(0, SyllabusCurriculum::withTrashed()->count());
        $this->assertSame([], Storage::disk(SyllabusDocument::DISK)->allFiles(SyllabusDocument::DIRECTORY));
        $this->assertFileExists($good);
    }

    public function test_plan_with_errors_is_a_logic_error(): void
    {
        $this->expectException(\LogicException::class);
        (new SyllabusImporter)->import(new SyllabusImportPlan(null, [], [], ['lỗi'], []), User::factory()->create(), self::VISIBILITY);
    }
}
