<?php

namespace Tests\Feature;

use App\Models\SyllabusChangeProposal;
use App\Models\SyllabusCurriculum;
use App\Models\SyllabusDocument;
use App\Models\SyllabusDocumentView;
use App\Models\SyllabusLesson;
use App\Models\User;
use App\Services\Syllabus\StageImport\StageImportPlanner;
use App\Services\Syllabus\StageImport\StageSheetReader;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSyllabusLibrarySeeder;
use Database\Seeders\Support\SyllabusSampleFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Dữ liệu demo Kho syllabus: buổi đủ cột Sheet 1, tài liệu theo đối tượng, đề xuất sửa, giáo trình import zip; chạy lại không nhân bản. */
class DemoSyllabusLibrarySeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seed_builds_library_data_and_is_idempotent(): void
    {
        Http::fake();
        $this->seed(DatabaseSeeder::class);

        // Buổi theo form Sheet 1.
        $starters = SyllabusCurriculum::where('code', 'DEMO-SYL-STARTERS')->firstOrFail();
        $this->assertSame(18, $starters->lessons()->whereNotNull('ppt_url')->whereNotNull('quizizz_url')->count());
        $this->assertSame([15, 3], [$starters->lessons()->whereNotNull('ppt_foreign_url')->count(), $starters->lessons()->whereNotNull('test_url')->count()]);
        // "Ôn tập Unit…" cuối chặng 1, 2 → Ôn tập; buổi cuối chặng cuối → Big test.
        $this->assertSame(['big_test' => 1, 'lesson' => 15, 'review' => 2],
            $starters->lessons()->reorder()->selectRaw('lesson_type, count(*) as total')->groupBy('lesson_type')->orderBy('lesson_type')
                ->pluck('total', 'lesson_type')->map(fn ($total) => (int) $total)->all());
        foreach (array_keys(SyllabusLesson::MILESTONES) as $milestone) {
            $this->assertTrue($starters->lessons()->where('notice_milestone', $milestone)->exists(), "Thiếu mốc {$milestone}.");
        }
        $this->assertSame(3, $starters->stages()->where('position', 1)->value('notice_before_sessions'));

        // Tài liệu: GV chỉ thấy tài liệu của chặng lớp mình đang học; Học vụ thấy cả tài liệu nội bộ.
        $documents = SyllabusDocument::where('curriculum_id', $starters->id)->get();
        $this->assertCount(8, $documents);
        $this->assertTrue($documents->contains(fn ($d) => $d->syllabus_lesson_id !== null && $d->stage_id !== null), 'Tài liệu buổi gắn chặng của buổi.');
        $teacher = User::where('email', 'nguyenvanan@menglish.edu.vn')->firstOrFail();
        $visible = SyllabusDocument::visibleTo($teacher)->where('curriculum_id', $starters->id)->pluck('title');
        $this->assertContains('Hướng dẫn sử dụng giáo trình Starters', $visible);
        $this->assertNotContains('Đáp án Workbook Starters (nội bộ — Học vụ order in)', $visible);
        $this->assertNotContains('Đề Mock test Starters', $visible, 'Chặng 3 chưa lớp nào học tới.');
        $staff = User::where('email', 'nva@menglish.edu.vn')->firstOrFail();
        $this->assertCount(8, SyllabusDocument::visibleTo($staff)->where('curriculum_id', $starters->id)->get());
        $this->assertTrue(SyllabusDocumentView::where('user_id', $teacher->id)->exists());

        // Đề xuất sửa đủ 3 trạng thái.
        $this->assertEqualsCanonicalizing(['pending', 'approved', 'rejected'],
            SyllabusChangeProposal::where('curriculum_id', $starters->id)->pluck('status')->all());

        // Giáo trình import từ zip: đủ cột Sheet 1 + tài liệu.
        $flyers = SyllabusCurriculum::where('code', 'DEMO-SYL-FLYERS')->firstOrFail();
        $this->assertSame([2, 8, 4], [$flyers->stages()->count(), $flyers->lessons()->count(), SyllabusDocument::where('curriculum_id', $flyers->id)->count()]);
        $this->assertSame(2, $flyers->lessons()->where('lesson_type', 'big_test')->count());

        // File thử import (docs/mau-test-kho-syllabus) khớp dữ liệu demo như README mô tả.
        $dir = sys_get_temp_dir().'/demo-syllabus-test-'.bin2hex(random_bytes(4));
        (new SyllabusSampleFiles)->writeAll($dir);
        $plan = fn (string $file, $stage) => app(StageImportPlanner::class)->plan(app(StageSheetReader::class)->read($dir.'/'.$file), $starters, $stage);
        $new = $plan('chang-moi-starters.xlsx', null);
        $this->assertTrue($new->ok(), json_encode($new->errors, JSON_UNESCAPED_UNICODE));
        $update = $plan('cap-nhat-starters-chang-1.xlsx', $starters->stages()->where('position', 1)->firstOrFail());
        $this->assertSame(['create' => 0, 'update' => 5, 'delete' => 1, 'units' => 0], $update->summary());
        $warnings = implode(' ', $update->warnings);
        $this->assertStringContainsString('lớp đang học chặng này', $warnings);
        $this->assertStringContainsString('buổi có tài liệu buổi', $warnings);
        File::deleteDirectory($dir);

        // Chạy lại không nhân bản.
        $counts = [SyllabusDocument::count(), SyllabusChangeProposal::count(), SyllabusCurriculum::count(), SyllabusDocumentView::count()];
        $this->seed(DemoSyllabusLibrarySeeder::class);
        $this->assertSame($counts, [SyllabusDocument::count(), SyllabusChangeProposal::count(), SyllabusCurriculum::count(), SyllabusDocumentView::count()]);
    }
}
