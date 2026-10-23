<?php

namespace Tests\Feature;

use App\Services\Syllabus\StageImport\StageImportException;
use App\Services\Syllabus\StageImport\StageImportTemplate;
use App\Services\Syllabus\StageImport\StageSheetReader;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/** Đọc file syllabus theo form Sheet 1 (docs/kho-syllabus.md §3.3, docs/Syllabus_Quy_trinh_xu_ly.xlsx). */
class SyllabusStageImportReaderTest extends TestCase
{
    public function test_reads_header_and_rows_from_filled_template(): void
    {
        $path = self::filledTemplate([
            ['B' => 1, 'C' => 'Học', 'D' => 'Unit 1: My New School', 'E' => 'Obj U1', 'F' => "Getting started\nchi tiết", 'M' => 'https://ppt/1', 'N' => 'link Quizizz'],
            ['B' => 2, 'C' => 'Mini test', 'F' => 'MINI TEST UNIT 1', 'P' => 'Order in ấn mini test'],
            ['B' => 3],
        ], curriculum: 'Global Success 6', stage: 'Chặng 1 – Unit 1 → Unit 3', version: 'v1.0');

        $sheet = app(StageSheetReader::class)->read($path);

        $this->assertSame('Global Success 6', $sheet->curriculumLabel);
        $this->assertSame('Chặng 1 – Unit 1 → Unit 3', $sheet->stageName);
        $this->assertSame('v1.0', $sheet->version);
        $this->assertCount(2, $sheet->rows);
        $this->assertSame(11, $sheet->rows[0]->line);
        $this->assertSame('Unit 1: My New School', $sheet->rows[0]->unitCell);
        $this->assertSame("Getting started\nchi tiết", $sheet->rows[0]->content);
        $this->assertSame('link Quizizz', $sheet->rows[0]->quizizzUrl);
        $this->assertSame('Mini test', $sheet->rows[1]->type);
        $this->assertSame('Order in ấn mini test', $sheet->rows[1]->milestone);
        $this->assertSame(0, $sheet->skippedExamples);
    }

    public function test_sample_workbook_example_rows_are_skipped(): void
    {
        $sheet = app(StageSheetReader::class)->read(base_path('docs/Syllabus_Quy_trinh_xu_ly.xlsx'));

        // Dòng 11–15 nền xám (ví dụ) bị bỏ; dòng 16 tô màu "Buổi kiểm tra" nên được đọc như dòng thật.
        $this->assertSame(5, $sheet->skippedExamples);
        $this->assertCount(1, $sheet->rows);
        $this->assertSame(16, $sheet->rows[0]->line);
        $this->assertSame('Chặng 1 – Unit 1 → Unit 3', $sheet->stageName);
    }

    public function test_finds_sheet1_even_when_it_is_not_the_first_sheet(): void
    {
        $book = IOFactory::load(self::filledTemplate([['B' => 1, 'C' => 'Học', 'D' => 'Unit 1: A', 'F' => 'Bài 1']]));
        $cover = $book->createSheet(0);
        $cover->setTitle('Bìa');
        $cover->setCellValue('A1', 'Trang bìa');
        $path = self::save($book);

        $this->assertCount(1, app(StageSheetReader::class)->read($path)->rows);
    }

    public function test_non_sheet1_file_is_rejected(): void
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->setCellValue('A1', 'Hello');

        $this->expectException(StageImportException::class);
        app(StageSheetReader::class)->read(self::save($book));
    }

    public function test_hyperlink_cell_uses_link_url(): void
    {
        $book = IOFactory::load(self::filledTemplate([['B' => 1, 'D' => 'Unit 1: A', 'F' => 'Bài 1', 'M' => 'Link PPT']]));
        $book->getSheet(0)->getCell('M11')->getHyperlink()->setUrl('https://docs.google.com/presentation/d/abc');

        $row = app(StageSheetReader::class)->read(self::save($book))->rows[0];

        $this->assertSame('https://docs.google.com/presentation/d/abc', $row->pptUrl);
    }

    /**
     * File mẫu (StageImportTemplate) điền sẵn ô đầu sheet và các dòng từ dòng 11.
     *
     * @param  list<array<string, mixed>>  $rows  cột => giá trị
     */
    public static function filledTemplate(array $rows, string $curriculum = 'Giáo trình test', string $stage = 'Chặng 1', string $version = 'v1'): string
    {
        $book = IOFactory::load(app(StageImportTemplate::class)->build());
        $sheet = $book->getSheet(0);
        $sheet->setCellValue('D3', $curriculum);
        $sheet->setCellValue('D4', $stage);
        $sheet->setCellValue('D6', $version);
        foreach ($rows as $i => $cells) {
            foreach ($cells as $column => $value) {
                $sheet->setCellValue($column.(11 + $i), $value);
            }
        }

        return self::save($book);
    }

    private static function save(Spreadsheet $book): string
    {
        $path = tempnam(sys_get_temp_dir(), 'stage').'.xlsx';
        IOFactory::createWriter($book, 'Xlsx')->save($path);

        return $path;
    }
}
