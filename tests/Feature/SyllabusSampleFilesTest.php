<?php

namespace Tests\Feature;

use App\Models\SyllabusCurriculum;
use App\Models\SyllabusLesson;
use App\Models\SyllabusStage;
use App\Models\SyllabusUnit;
use App\Services\Syllabus\Import\SyllabusImportPlan;
use App\Services\Syllabus\Import\SyllabusPackageReader;
use App\Services\Syllabus\Import\SyllabusPackageValidator;
use App\Services\Syllabus\StageImport\StageImportPlan;
use App\Services\Syllabus\StageImport\StageImportPlanner;
use App\Services\Syllabus\StageImport\StageSheetReader;
use Database\Seeders\Support\SyllabusSampleFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsSyllabusPackages;
use Tests\TestCase;

/** Bộ file test thủ công Kho syllabus (docs/mau-test-kho-syllabus) cho đúng kết quả README mô tả. */
class SyllabusSampleFilesTest extends TestCase
{
    use BuildsSyllabusPackages, RefreshDatabase;

    private string $dir;

    private SyllabusCurriculum $starters;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = $this->newPackageDir();
        (new SyllabusSampleFiles)->writeAll($this->dir);

        // Như Chặng 1 của Cambridge Starters (demo): Unit 1–2, 6 buổi.
        $this->starters = SyllabusCurriculum::create(['code' => 'DEMO-SYL-STARTERS', 'title' => 'Cambridge Starters (demo)', 'version' => 'v1']);
        $stage = $this->starters->stages()->firstOrFail();
        foreach ([1 => 'Hello & Classroom', 2 => 'My Family'] as $number => $title) {
            $unit = SyllabusUnit::create(['curriculum_id' => $this->starters->id, 'stage_id' => $stage->id, 'unit_number' => $number, 'title' => $title]);
            foreach ([1, 2, 3] as $i) {
                SyllabusLesson::create(['unit_id' => $unit->id, 'session_no' => ($number - 1) * 3 + $i, 'title' => "U{$number} B{$i}"]);
            }
        }
    }

    public function test_docs_folder_has_every_sample_file(): void
    {
        foreach (SyllabusSampleFiles::FILES as $name) {
            $this->assertFileExists($this->dir.'/'.$name);
            $this->assertFileExists(base_path(SyllabusSampleFiles::DIRECTORY.'/'.$name), "Chạy lại lệnh tạo file trong README để cập nhật {$name}.");
        }
    }

    public function test_new_stage_file_follows_new_sheet1_form(): void
    {
        $plan = $this->stagePlan('chang-moi-starters.xlsx', null);

        $this->assertTrue($plan->ok(), json_encode($plan->errors, JSON_UNESCAPED_UNICODE));
        $this->assertSame(['create' => 8, 'update' => 0, 'delete' => 0, 'units' => 2], $plan->summary());
        $this->assertSame('Ôn thi Starters', $plan->stageName);
        $this->assertStringContainsString('bỏ qua 1 dòng ví dụ', implode(' ', $plan->warnings));

        $lessons = array_column($plan->lessons, 'attributes');
        $this->assertSame(['lesson', 'lesson', 'review', 'grammar', 'lesson', 'lesson', 'review', 'big_test'], array_column($lessons, 'lesson_type'));
        $this->assertSame([7, 7, 7, 7, 8, 8, 8, 8], array_column($plan->lessons, 'unit_number'), 'Dòng GRAMMAR / ÔN TẬP CHẶNG / BIG TEST thuộc unit đang mở.');
        $this->assertSame(['Đồ chơi', 'GRAMMAR BASIC 1 – TOPIC 1: NOUNS', 'ÔN TẬP CHẶNG · Review Unit 7, 8', 'BIG TEST Unit 7 - 8'],
            [$lessons[0]['title'], $lessons[3]['title'], $lessons[6]['title'], $lessons[7]['title']]);
        $this->assertSame(['https://example.com/starters/ppt-unit-7-1', 'https://example.com/starters/ppt-gvnn-unit-7-1'],
            [$lessons[0]['ppt_url'], $lessons[0]['ppt_foreign_url']], 'Ô hyperlink lấy URL, không lấy tên file hiển thị.');
        $this->assertSame('https://example.com/starters/kiem-tra-tu-unit-7', $lessons[1]['test_url']);
        $this->assertNull($lessons[2]['ppt_url'], 'Chữ giữ chỗ "link PPT" coi như trống.');
        $this->assertSame(['order_print', 'order_mini_test', null, null, 'order_big_test', null, null, null], array_column($lessons, 'notice_milestone'));
    }

    public function test_update_stage_file_updates_five_and_deletes_one(): void
    {
        $plan = $this->stagePlan('cap-nhat-starters-chang-1.xlsx', $this->starters->stages()->firstOrFail());

        $this->assertTrue($plan->ok(), json_encode($plan->errors, JSON_UNESCAPED_UNICODE));
        $this->assertSame(['create' => 0, 'update' => 5, 'delete' => 1, 'units' => 0], $plan->summary());
    }

    public function test_broken_stage_file_reports_each_row(): void
    {
        $plan = $this->stagePlan('chang-co-loi.xlsx', null);

        $this->assertFalse($plan->ok());
        $messages = implode(' | ', array_column($plan->errors, 'message'));
        foreach (['Chưa có UNIT', 'Loại buổi “Thi cuối kỳ”', 'Link PPT không phải đường dẫn hợp lệ', 'Link PPT GVNN không phải đường dẫn hợp lệ',
            'Mốc thông báo “Gửi phụ huynh”'] as $expected) {
            $this->assertStringContainsString($expected, $messages);
        }
    }

    public function test_flyers_package_is_valid_with_sheet1_columns_and_documents(): void
    {
        $plan = $this->packagePlan('giao-trinh-flyers.zip');

        $this->assertTrue($plan->ok(), implode(' | ', $plan->errors));
        $this->assertSame(['stages' => 2, 'units' => 3, 'lessons' => 8, 'documents' => 4], $plan->summary());
        $this->assertSame(3, $plan->stages[0]['notice_before_sessions']);
        $last = $plan->stages[1]['units'][0]['lessons'][2];
        $this->assertSame(['big_test', 'https://example.com/flyers/ppt-buoi-8', 'https://example.com/flyers/ppt-gvnn-buoi-8', 'https://example.com/flyers/de-buoi-8'],
            [$last['lesson_type'], $last['ppt_url'], $last['ppt_foreign_url'], $last['test_url']]);
        $this->assertSame(['review', 'grammar'], [$plan->stages[0]['units'][1]['lessons'][1]['lesson_type'], $plan->stages[1]['units'][0]['lessons'][1]['lesson_type']]);
    }

    public function test_old_format_file_is_still_valid(): void
    {
        $plan = $this->packagePlan('giao-trinh-mau-cu.xlsx');

        $this->assertTrue($plan->ok(), implode(' | ', $plan->errors));
        $this->assertSame(['stages' => 2, 'units' => 2, 'lessons' => 3, 'documents' => 0], $plan->summary());
        $this->assertSame('Hội thoại chào hỏi', $plan->stages[0]['units'][0]['lessons'][0]['content'], 'Cột "Hoạt động" đọc như cột nội dung.');
    }

    public function test_broken_package_reports_each_error(): void
    {
        $plan = $this->packagePlan('giao-trinh-co-loi.zip');

        $this->assertFalse($plan->ok());
        $messages = implode(' | ', $plan->errors);
        foreach (['Báo trước (buổi) phải từ 1 đến 50', 'Loại buổi “Thi cuối kỳ”', 'Mốc thông báo “Gửi phụ huynh”', 'Link PPT không phải đường dẫn hợp lệ',
            'buổi số 1 bị trùng', 'tai-lieu/chang-9/: không có Chặng 9'] as $expected) {
            $this->assertStringContainsString($expected, $messages);
        }
    }

    private function stagePlan(string $file, ?SyllabusStage $stage): StageImportPlan
    {
        return app(StageImportPlanner::class)->plan(app(StageSheetReader::class)->read($this->dir.'/'.$file), $this->starters, $stage);
    }

    private function packagePlan(string $file): SyllabusImportPlan
    {
        return (new SyllabusPackageValidator)->validate((new SyllabusPackageReader)->read($this->dir.'/'.$file, $this->newPackageDir()), true);
    }
}
