<?php

namespace Tests\Feature;

use App\Models\AdminNotification;
use App\Models\BigTestOrder;
use App\Models\ClassLessonOverride;
use App\Models\ClassLessonProgress;
use App\Models\ClassModel;
use App\Models\Homework;
use App\Models\HomeworkSubmission;
use App\Models\MaterialOrder;
use App\Models\StaffReport;
use App\Models\StaffReportNote;
use App\Models\SyllabusAdjustmentRequest;
use App\Models\SyllabusAssignment;
use App\Models\SyllabusChangeProposal;
use App\Models\SyllabusCurriculum;
use App\Models\SyllabusDocument;
use App\Models\SyllabusDocumentView;
use App\Models\SyllabusLesson;
use App\Models\User;
use App\Models\WorkTask;
use App\Services\Syllabus\ClassSyllabus;
use App\Services\Syllabus\ClassSyllabusTab;
use App\Services\Syllabus\StageImport\StageImportPlanner;
use App\Services\Syllabus\StageImport\StageSheetReader;
use App\Services\Syllabus\SyllabusOrders;
use App\Support\ReportPeriod;
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
        // "Ôn tập Unit…" cuối chặng 1 → Mini test (có làm mini test), cuối chặng 2 → Ôn tập; buổi cuối chặng cuối → Big test.
        $this->assertSame(['big_test' => 1, 'lesson' => 15, 'mini_test' => 1, 'review' => 1],
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

        // Syllabus riêng của lớp: 1 buổi chỉnh sửa thủ công có lý do (lớp Starters CG FAM 0).
        $override = ClassLessonOverride::where('class_id', ClassModel::where('code', 'DEMO-CG-FAM0')->value('id'))->sole();
        $this->assertStringContainsString('nghe yếu', $override->reason);
        $this->assertArrayHasKey('content', $override->data);

        // Tiến độ theo "Đã dạy": CG FAM 1 có buổi đã dạy (1 ghi chú tiến độ) và đúng 1 buổi đã qua chưa đánh dấu; BD FAM 1 có
        // yêu cầu Customize syllabus "Dời tiến độ" chờ duyệt.
        $cg1 = SyllabusAssignment::open()->where('class_id', ClassModel::where('code', 'DEMO-CG-FAM1')->value('id'))->sole();
        $timeline = app(ClassSyllabus::class)->timeline($cg1);
        $this->assertGreaterThan(0, $timeline['taught']);
        $this->assertCount(1, $timeline['unmarked_sessions']);
        $this->assertTrue(ClassLessonProgress::where('syllabus_assignment_id', $cg1->id)->whereNotNull('pace_note')->exists());
        $this->assertTrue(SyllabusAdjustmentRequest::where('class_id', ClassModel::where('code', 'DEMO-BD-FAM1')->value('id'))->where('kind', 'postpone')->where('status', 'pending')->exists());

        // Giai đoạn 4: việc cuối chặng của CG FAM 1 Học thuật đã NHẬN VIỆC (đã qua hạn → Quá hạn); GV CG có mốc ORDER đến hạn.
        $this->assertTrue(WorkTask::where('kind', WorkTask::KIND_SYLLABUS_STAGE_END)->where('status', 'overdue')
            ->where('class_id', ClassModel::where('code', 'DEMO-CG-FAM1')->value('id'))->exists());
        $this->assertGreaterThan(0, app(SyllabusOrders::class)->dueCount($teacher));

        // Giai đoạn 5: CG FAM 1 có bài tập theo syllabus, học viên nộp theo giỏ, GV đã xem 1 bài.
        $cg = ClassModel::where('code', 'DEMO-CG-FAM1')->firstOrFail();
        $syllabusHomework = Homework::where('class_id', $cg->id)->where('title', 'like', 'Bài tập buổi%')->pluck('id');
        $this->assertGreaterThanOrEqual(1, $syllabusHomework->count());
        // Chỉ xét bài nộp của bài tập theo syllabus (DemoPhase2 / DemoKpiSources cũng seed bài nộp + chấm cho lớp của chúng).
        $submissions = HomeworkSubmission::whereIn('homework_id', $syllabusHomework);
        $this->assertEqualsCanonicalizing(['paper', 'quizizz', 'video'], (clone $submissions)->distinct()->pluck('basket')->all());
        $this->assertSame(1, (clone $submissions)->where('status', 'reviewed')->count());

        // Quy trình đủ bước (docs/kho-syllabus.md §7–9): nhắc 24h chưa xác nhận, Customize "Giãn" đã duyệt, order theo mốc,
        // việc cuối chặng quá hạn / hoàn thành, báo cáo tuần Học thuật có ghi chú của Quản lý; buổi test đã dạy chưa nhập điểm.
        $classId = fn (string $code) => ClassModel::where('code', $code)->value('id');
        $actions = AdminNotification::where('type', 'syllabus_workflow')->get()->pluck('data.action.label')->unique();
        foreach (['ĐỌC & XÁC NHẬN', 'NHẮC LẠI', 'ĐÃ XEM', 'GIAO CHẶNG', 'NHẬN VIỆC', 'XỬ LÝ', 'ORDER'] as $label) {
            $this->assertContains($label, $actions, "Thiếu thông báo có nút {$label}.");
        }
        $this->assertNotNull(SyllabusAssignment::open()->where('class_id', $classId('DEMO-BD-FAM1'))->value('confirm_reminded_at'));
        $this->assertTrue(SyllabusAdjustmentRequest::where('class_id', $classId('DEMO-CG-FAM0'))->where('kind', 'extend')->where('status', 'approved')->exists());
        $this->assertGreaterThan(0, SyllabusAssignment::open()->where('class_id', $classId('DEMO-CG-FAM0'))->value('extra_sessions'));
        $this->assertTrue(MaterialOrder::where('class_id', $classId('DEMO-CG-FAM0'))->whereNotNull('syllabus_lesson_id')->where('category', MaterialOrder::CATEGORY_PRINTING)->exists());
        $this->assertTrue(BigTestOrder::where('class_id', $classId('DEMO-BD-FAM0'))->whereNotNull('syllabus_lesson_id')->where('status', 'pending')->exists());
        $this->assertSame('overdue', WorkTask::where('kind', WorkTask::KIND_SYLLABUS_STAGE_END)->where('class_id', $classId('DEMO-BD-FAM1'))->value('status'));
        $this->assertSame('completed', WorkTask::where('kind', WorkTask::KIND_SYLLABUS_STAGE_END)->where('class_id', $classId('DEMO-CG-FAM0'))->value('status'));
        $lead = User::where('email', 'academiclead@menglish.edu.vn')->firstOrFail();
        $weekly = StaffReport::where('user_id', $lead->id)->where('kind', 'academic_weekly')->where('period_key', ReportPeriod::weekKey(now()))->sole();
        $this->assertSame(2, $weekly->notes()->count(), 'Quản lý + chính Học thuật ghi chú.');
        $this->assertStringContainsString('Chuẩn bị test', $weekly->content);
        $bd1 = ClassModel::findOrFail($classId('DEMO-BD-FAM1'));
        $this->assertTrue(collect(app(ClassSyllabusTab::class)->data($bd1, $lead)['lessons'] ?? [])->contains('missing_score', true), 'Buổi mini test đã dạy chưa nhập điểm.');

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
        $counts = fn () => [SyllabusDocument::count(), SyllabusChangeProposal::count(), SyllabusCurriculum::count(), SyllabusDocumentView::count(), Homework::count(),
            HomeworkSubmission::count(), SyllabusAdjustmentRequest::count(), MaterialOrder::count(), BigTestOrder::count(), StaffReport::count(), StaffReportNote::count(),
            AdminNotification::where('type', 'syllabus_workflow')->count()];
        $before = $counts();
        $this->seed(DemoSyllabusLibrarySeeder::class);
        $this->assertSame($before, $counts());
    }
}
