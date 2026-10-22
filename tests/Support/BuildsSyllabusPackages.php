<?php

namespace Tests\Support;

use App\Services\Syllabus\Import\SyllabusPackageReader as Reader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use ZipArchive;

/** Dựng file xlsx / zip gói import syllabus trong test; file tạo ra được xoá ở tearDown. */
trait BuildsSyllabusPackages
{
    private function packagesDir(): string
    {
        $dir = storage_path('framework/testing/syllabus-packages');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir;
    }

    protected function tearDown(): void
    {
        $dir = storage_path('framework/testing/syllabus-packages');
        if (is_dir($dir)) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($items as $item) {
                $item->isDir() && ! $item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($dir);
        }

        parent::tearDown();
    }

    /** Một thư mục con mới (rỗng) để giải nén / chứa file. */
    protected function newPackageDir(): string
    {
        $dir = $this->packagesDir().'/'.uniqid('d', true);
        mkdir($dir, 0775, true);

        return $dir;
    }

    /** Gói hợp lệ: 1 giáo trình, 2 chặng, 2 unit, 4 buổi. Mỗi sheet là list dòng (dòng đầu là tiêu đề). */
    protected function validSheets(): array
    {
        return [
            'Giáo trình' => [
                array_values(Reader::CURRICULUM_COLUMNS),
                ['KIDS-Q4', 'Kids Quý 4', 'v1', 'Giáo trình thiếu nhi'],
            ],
            'Chặng' => [
                array_values(Reader::STAGE_COLUMNS),
                [1, 'Chặng 1', 'Làm quen', 'Big Test 1', 'Ôn tập', 'https://example.com/muc-luc-1'],
                [2, 'Chặng 2', null, null, null, null],
            ],
            'Buổi' => [
                array_values(Reader::LESSON_COLUMNS),
                ['Chặng 1', 1, 'Hello', 1, 'Chào hỏi', 'Biết chào', 'Hát', 'hello', 'to be', 'Làm bài 1'],
                ['Chặng 1', 1, 'Hello', 2, 'Giới thiệu', null, null, null, null, null],
                ['Chặng 2', 2, 'Animals', 3, 'Con vật', null, null, 'cat, dog', null, null],
                ['Chặng 2', 2, 'Animals', 4, 'Ôn tập', null, null, null, null, null],
            ],
        ];
    }

    /** @param array<string, list<list<scalar|null>>> $sheets tên sheet => dòng (gồm dòng tiêu đề) */
    protected function makeXlsx(array $sheets): string
    {
        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);
        foreach ($sheets as $title => $rows) {
            $sheet = $book->createSheet();
            $sheet->setTitle($title);
            $sheet->fromArray($rows, null, 'A1', true);
        }

        $path = $this->packagesDir().'/'.uniqid('x', true).'.xlsx';
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        return $path;
    }

    /** @param array<string, string> $entries tên entry => nội dung (tên kết thúc bằng "/" là thư mục) */
    protected function makeZip(array $entries): string
    {
        $path = $this->packagesDir().'/'.uniqid('z', true).'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($entries as $name => $content) {
            if (str_ends_with($name, '/')) {
                $zip->addEmptyDir($name);
            } else {
                $zip->addFromString($name, $content, ZipArchive::FL_ENC_RAW);
            }
        }
        $zip->close();

        return $path;
    }

    /** PDF tối thiểu hợp lệ. */
    protected function pdfBytes(): string
    {
        return "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
            ."3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\n"
            ."trailer<</Root 1 0 R/Size 4>>\n%%EOF\n";
    }
}
