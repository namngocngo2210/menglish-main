<?php

namespace Tests\Feature;

use App\Models\SyllabusCurriculum;
use App\Services\Syllabus\Import\SyllabusImportPlan;
use App\Services\Syllabus\Import\SyllabusPackageReader;
use App\Services\Syllabus\Import\SyllabusPackageValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsSyllabusPackages;
use Tests\TestCase;

class SyllabusPackageValidatorTest extends TestCase
{
    use BuildsSyllabusPackages, RefreshDatabase;

    /** @param array<string, list<list<scalar|null>>> $sheets */
    private function validate(array $sheets, array $zipEntries = [], bool $canUpload = true): SyllabusImportPlan
    {
        $xlsx = $this->makeXlsx($sheets);
        $path = $xlsx;
        if ($zipEntries !== []) {
            $path = $this->makeZip(['syllabus.xlsx' => file_get_contents($xlsx)] + $zipEntries);
        }
        $package = (new SyllabusPackageReader)->read($path, $this->newPackageDir());

        return (new SyllabusPackageValidator)->validate($package, $canUpload);
    }

    private function lessonRow(string|int $stage, string|int $unit, string $unitTitle, string|int $session, ?string $title): array
    {
        return [$stage, $unit, $unitTitle, $session, $title, null, null, null, null, null];
    }

    public function test_valid_package_builds_tree(): void
    {
        $sheets = $this->validSheets();
        $sheets['Giáo trình'][1][2] = null;

        $plan = $this->validate($sheets);

        $this->assertTrue($plan->ok(), implode(' | ', $plan->errors));
        $this->assertSame(['stages' => 2, 'units' => 2, 'lessons' => 4, 'documents' => 0], $plan->summary());
        $this->assertSame('v1', $plan->curriculum['version']);
        $this->assertSame('KIDS-Q4', $plan->curriculum['code']);
        $this->assertSame('Chặng 1', $plan->stages[0]['name']);
        $this->assertSame('https://example.com/muc-luc-1', $plan->stages[0]['overview_link']);
        $this->assertSame('Hello', $plan->stages[0]['units'][0]['title']);
        $this->assertSame([1, 2], array_column($plan->stages[0]['units'][0]['lessons'], 'session_no'));
        $this->assertSame('cat, dog', $plan->stages[1]['units'][0]['lessons'][0]['vocabulary_focus']);
    }

    public function test_curriculum_sheet_rules(): void
    {
        $sheets = $this->validSheets();
        $sheets['Giáo trình'][] = ['B', null, null, null];
        $plan = $this->validate($sheets);

        $this->assertContains('Sheet Giáo trình: chỉ điền 1 dòng.', $plan->errors);
        $this->assertContains('Sheet Giáo trình, dòng 3: thiếu Tên.', $plan->errors);

        $sheets = $this->validSheets();
        $sheets['Giáo trình'][1][0] = str_repeat('A', 51);
        $this->assertContains('Sheet Giáo trình, dòng 2: Mã tối đa 50 ký tự.', $this->validate($sheets)->errors);

        $sheets = $this->validSheets();
        $sheets['Giáo trình'][1][1] = null;
        $this->assertContains('Sheet Giáo trình, dòng 2: thiếu Tên.', $this->validate($sheets)->errors);

        $sheets = $this->validSheets();
        $sheets['Giáo trình'] = [$sheets['Giáo trình'][0]];
        $this->assertContains('Sheet Giáo trình: chỉ điền 1 dòng.', $this->validate($sheets)->errors);
    }

    public function test_stage_sheet_rules(): void
    {
        $sheets = $this->validSheets();
        $sheets['Chặng'][2][0] = 3;
        $sheets['Buổi'][3][0] = 3;
        $sheets['Buổi'][4][0] = 3;
        $this->assertContains('Sheet Chặng: thứ tự phải liên tục từ 1 (thiếu 2).', $this->validate($sheets)->errors);

        $sheets = $this->validSheets();
        $sheets['Chặng'][2][5] = 'không phải url';
        $sheets['Buổi'] = array_slice($sheets['Buổi'], 0, 3);
        $errors = $this->validate($sheets)->errors;
        $this->assertContains('Sheet Chặng, dòng 3: Link mục lục không phải URL hợp lệ.', $errors);
        $this->assertContains('Chặng 2 chưa có buổi nào.', $errors);
    }

    public function test_reports_every_rule_violation(): void
    {
        $sheets = $this->validSheets();
        $sheets['Buổi'] = [
            $sheets['Buổi'][0],
            $this->lessonRow(1, 1, 'Hello', 1, 'A'),
            $this->lessonRow(1, 1, 'Hello', 2, 'B'),
            $this->lessonRow(1, 1, 'Hello', 3, 'C'),
            $this->lessonRow(1, 1, 'Hello', 5, null),
            $this->lessonRow(2, 2, 'Animals', 3, 'D'),
            $this->lessonRow(1, 2, 'Zoo', 6, 'E'),
            $this->lessonRow(4, 3, 'Other', 8, 'F'),
            $this->lessonRow(1, 1, 'Hello', 4, 'G'),
        ];

        $errors = $this->validate($sheets)->errors;

        foreach ([
            'Sheet Buổi, dòng 5: thiếu Tên buổi.',
            'Sheet Buổi: buổi số 3 bị trùng (dòng 4, 6).',
            'Sheet Buổi: thiếu buổi số 7.',
            'Sheet Buổi, dòng 8: Chặng 4 không có trong sheet Chặng.',
            'Sheet Buổi: Unit 2 nằm ở nhiều chặng (1, 2).',
            'Sheet Buổi: Unit 2 có nhiều tên khác nhau.',
        ] as $message) {
            $this->assertContains($message, $errors);
        }
    }

    public function test_integer_cells_accept_excel_number_forms(): void
    {
        $sheets = $this->validSheets();
        $sheets['Buổi'] = [
            $sheets['Buổi'][0],
            $this->lessonRow('1.0', 1, 'Hello', '1.0', 'A'),
            $this->lessonRow(1, ' 1 ', 'Hello', ' 2 ', 'B'),
            $this->lessonRow(1, '01', 'Hello', '03', 'C'),
        ];
        $sheets['Chặng'] = array_slice($sheets['Chặng'], 0, 2);
        $plan = $this->validate($sheets);
        $this->assertTrue($plan->ok(), implode(' | ', $plan->errors));
        $this->assertSame(3, $plan->summary()['lessons']);

        foreach (['1.5', 'abc'] as $bad) {
            $sheets['Buổi'][3] = $this->lessonRow(1, 1, 'Hello', $bad, 'C');
            $this->assertContains('Sheet Buổi, dòng 4: Buổi số phải là số nguyên.', $this->validate($sheets)->errors);
        }
    }

    public function test_existing_code_is_rejected_including_trashed(): void
    {
        $existing = SyllabusCurriculum::create(['code' => 'KIDS-Q4', 'title' => 'Cũ', 'version' => 'v1']);
        $existing->delete();

        $plan = $this->validate($this->validSheets());

        $this->assertContains('Mã KIDS-Q4 đã tồn tại — đổi mã khác.', $plan->errors);
    }

    public function test_more_than_1000_lessons_is_rejected(): void
    {
        $sheets = $this->validSheets();
        $rows = [$sheets['Buổi'][0]];
        for ($i = 1; $i <= 1001; $i++) {
            $rows[] = $this->lessonRow(1, 1, 'Hello', $i, 'Buổi '.$i);
        }
        $sheets['Buổi'] = $rows;

        $this->assertContains('Sheet Buổi: tối đa 1000 buổi.', $this->validate($sheets)->errors);
    }

    public function test_documents_are_attached_by_stage_folder(): void
    {
        $plan = $this->validate($this->validSheets(), [
            'tai-lieu/chang-1/Slide Unit 1-3.pdf' => $this->pdfBytes(),
            'tai-lieu/Chung.pdf' => $this->pdfBytes(),
        ]);

        $this->assertTrue($plan->ok(), implode(' | ', $plan->errors));
        $doc = $plan->stages[0]['documents'][0];
        $this->assertSame('Slide Unit 1-3', $doc['title']);
        $this->assertSame('Slide Unit 1-3.pdf', $doc['original_name']);
        $this->assertSame('pdf', $doc['extension']);
        $this->assertFileExists($doc['local_path']);
        $this->assertSame('Chung', $plan->sharedDocuments[0]['title']);
        $this->assertSame([], $plan->stages[1]['documents']);
        $this->assertContains('Chặng 2 chưa có tài liệu.', $plan->warnings);
        $this->assertNotContains('Chặng 1 chưa có tài liệu.', $plan->warnings);
        $this->assertSame(2, $plan->summary()['documents']);
    }

    public function test_document_errors(): void
    {
        $plan = $this->validate($this->validSheets(), [
            'tai-lieu/chang-4/x.pdf' => $this->pdfBytes(),
            'tai-lieu/chang-1/abc/x.pdf' => $this->pdfBytes(),
            'tai-lieu/chang-1/virus.pdf' => 'MZ'.str_repeat("\x00", 64),
            'tai-lieu/khac/x.pdf' => $this->pdfBytes(),
        ]);

        $this->assertFalse($plan->ok());
        foreach ([
            'tai-lieu/chang-4/: không có Chặng 4.',
            'tai-lieu/chang-1/abc/x.pdf: chỉ đặt file trực tiếp trong thư mục chặng.',
            'tai-lieu/chang-1/virus.pdf: định dạng không được phép.',
            'tai-lieu/khac/: tên thư mục phải là chang-<thứ tự>.',
        ] as $message) {
            $this->assertContains($message, $plan->errors);
        }
    }

    public function test_documents_need_upload_permission(): void
    {
        $plan = $this->validate($this->validSheets(), ['tai-lieu/Chung.pdf' => $this->pdfBytes()], canUpload: false);

        $this->assertContains('Bạn không có quyền upload tài liệu — bỏ thư mục tai-lieu hoặc nhờ người có quyền.', $plan->errors);

        $plan = $this->validate($this->validSheets(), [], canUpload: false);
        $this->assertTrue($plan->ok());
    }

    public function test_ignored_and_renamed_files_are_warnings(): void
    {
        $plan = $this->validate($this->validSheets(), [
            '.DS_Store' => 'x',
            '__MACOSX/._a' => 'x',
            "tai-lieu/chang-1/B\xE0i.pdf" => $this->pdfBytes(),
        ]);

        $this->assertTrue($plan->ok(), implode(' | ', $plan->errors));
        $this->assertContains('Đã bỏ qua 2 file hệ thống / ngoài thư mục tai-lieu.', $plan->warnings);
        $renamed = array_values(array_filter($plan->warnings, fn ($w) => str_ends_with($w, ': tên file không đọc được — đổi tên ở trang Tài liệu sau khi import.')));
        $this->assertCount(1, $renamed);
        $this->assertStringStartsWith('tai-lieu/chang-1/B', $renamed[0]);
    }

    public function test_missing_sheets_return_only_those_errors(): void
    {
        $sheets = $this->validSheets();
        unset($sheets['Chặng']);

        $plan = $this->validate($sheets);

        $this->assertNull($plan->curriculum);
        $this->assertSame(['Thiếu sheet "Chặng".'], $plan->errors);
        $this->assertFalse($plan->ok());
    }
}
