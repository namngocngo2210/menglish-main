<?php

namespace Tests\Feature;

use App\Services\Syllabus\Import\SyllabusPackageException;
use App\Services\Syllabus\Import\SyllabusPackageReader;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsSyllabusPackages;
use Tests\TestCase;
use ZipArchive;

class SyllabusPackageReaderTest extends TestCase
{
    use BuildsSyllabusPackages;

    private function reader(): SyllabusPackageReader
    {
        return new SyllabusPackageReader;
    }

    private function zipWithDocs(string $prefix = ''): string
    {
        $xlsx = file_get_contents($this->makeXlsx($this->validSheets()));

        return $this->makeZip([
            $prefix.'syllabus.xlsx' => $xlsx,
            $prefix.'tai-lieu/chang-1/Slide.pdf' => $this->pdfBytes(),
            $prefix.'tai-lieu/Chung.pdf' => $this->pdfBytes(),
            $prefix.'__MACOSX/._Slide.pdf' => 'x',
            $prefix.'tai-lieu/.DS_Store' => 'x',
            $prefix.'tai-lieu/chang-1/' => '',
        ]);
    }

    public function test_reads_xlsx_rows_by_header(): void
    {
        $package = $this->reader()->read($this->makeXlsx($this->validSheets()), $this->newPackageDir());

        $this->assertSame('KIDS-Q4', $package->curriculumRows[2]['code']);
        $this->assertCount(4, $package->lessonRows);
        $this->assertSame('1', $package->lessonRows[2]['session_no']);
        $this->assertNull($package->lessonRows[3]['objectives']);
        $this->assertSame([], $package->files);
        $this->assertFalse($package->isZip);
        $this->assertSame([], $package->missing);
    }

    public function test_headers_match_case_and_space_insensitively(): void
    {
        $sheets = $this->validSheets();
        $sheets['Buổi'][0][array_search('Buổi số', $sheets['Buổi'][0], true)] = ' buổi SỐ ';

        $package = $this->reader()->read($this->makeXlsx($sheets), $this->newPackageDir());

        $this->assertArrayHasKey('session_no', $package->lessonRows[2]);
        $this->assertSame([], $package->missing);
    }

    public function test_missing_sheet_or_required_column_is_reported(): void
    {
        $sheets = $this->validSheets();
        unset($sheets['Chặng']);
        $col = array_search('Tên buổi', $sheets['Buổi'][0], true);
        foreach ($sheets['Buổi'] as &$row) {
            unset($row[$col]);
        }
        unset($row);
        $sheets['Buổi'] = array_map('array_values', $sheets['Buổi']);

        $package = $this->reader()->read($this->makeXlsx($sheets), $this->newPackageDir());

        $this->assertContains('Thiếu sheet "Chặng".', $package->missing);
        $this->assertContains('Sheet Buổi: thiếu cột "Tên buổi".', $package->missing);
    }

    public function test_reads_zip_with_documents_and_ignores_junk(): void
    {
        $package = $this->reader()->read($this->zipWithDocs(), $this->newPackageDir());

        $paths = array_map(fn ($f) => $f->path, $package->files);
        sort($paths);
        $this->assertSame(['tai-lieu/Chung.pdf', 'tai-lieu/chang-1/Slide.pdf'], $paths);
        $this->assertCount(2, $package->ignored);
        $this->assertTrue($package->isZip);
        foreach ($package->files as $file) {
            $this->assertFileExists($file->localPath);
            $this->assertSame(strlen($this->pdfBytes()), $file->size);
            $this->assertFalse($file->renamed);
        }
        $this->assertSame('KIDS-Q4', $package->curriculumRows[2]['code']);
    }

    public function test_rereading_with_populated_work_dir_gives_same_result(): void
    {
        $zip = $this->zipWithDocs();
        $dir = $this->newPackageDir();
        $first = $this->reader()->read($zip, $dir);
        $second = $this->reader()->read($zip, $dir);

        $this->assertCount(2, $second->files);
        $this->assertSame(array_map(fn ($f) => $f->size, $first->files), array_map(fn ($f) => $f->size, $second->files));
    }

    public function test_single_wrapping_folder_is_stripped(): void
    {
        $package = $this->reader()->read($this->zipWithDocs('mau-import-syllabus/'), $this->newPackageDir());

        $paths = array_map(fn ($f) => $f->path, $package->files);
        sort($paths);
        $this->assertSame(['tai-lieu/Chung.pdf', 'tai-lieu/chang-1/Slide.pdf'], $paths);
        $this->assertSame('KIDS-Q4', $package->curriculumRows[2]['code']);
    }

    public function test_non_utf8_entry_names_are_scrubbed(): void
    {
        $zip = $this->makeZip([
            'syllabus.xlsx' => file_get_contents($this->makeXlsx($this->validSheets())),
            "tai-lieu/chang-1/B\xE0i.pdf" => $this->pdfBytes(),
        ]);

        $package = $this->reader()->read($zip, $this->newPackageDir());

        $this->assertCount(1, $package->files);
        $this->assertTrue(mb_check_encoding($package->files[0]->path, 'UTF-8'));
        $this->assertTrue($package->files[0]->renamed);
        $this->assertFileExists($package->files[0]->localPath);
    }

    #[DataProvider('unsafeZips')]
    public function test_rejects_unsafe_or_oversized_zip(string $case, string $message): void
    {
        $xlsx = file_get_contents($this->makeXlsx($this->validSheets()));
        $pdf = $this->pdfBytes();

        $zip = match ($case) {
            'dotdot' => $this->makeZip(['syllabus.xlsx' => $xlsx, '../evil.pdf' => $pdf]),
            'absolute' => $this->makeZip(['syllabus.xlsx' => $xlsx, '/etc/x.pdf' => $pdf]),
            'too_many' => $this->makeZip(['syllabus.xlsx' => $xlsx] + array_fill_keys(array_map(fn ($i) => "tai-lieu/f$i.pdf", range(1, 500)), $pdf)),
            'no_xlsx' => $this->makeZip(['tai-lieu/Chung.pdf' => $pdf]),
            'two_xlsx' => $this->makeZip(['a.xlsx' => $xlsx, 'b.xlsx' => $xlsx]),
            'encrypted' => $this->encryptedZip($xlsx),
            'not_zip' => $this->notAZip(),
        };

        $this->expectException(SyllabusPackageException::class);
        $this->expectExceptionMessage($message);
        $this->reader()->read($zip, $this->newPackageDir());
    }

    public static function unsafeZips(): array
    {
        return [
            'dotdot' => ['dotdot', 'đường dẫn không hợp lệ'],
            'absolute' => ['absolute', 'đường dẫn không hợp lệ'],
            'too many entries' => ['too_many', 'quá 500 file'],
            'no xlsx' => ['no_xlsx', 'Không tìm thấy file syllabus.xlsx'],
            'two xlsx' => ['two_xlsx', 'chỉ được có 1 file .xlsx'],
            'encrypted' => ['encrypted', 'có mật khẩu'],
            'not a zip' => ['not_zip', 'Không đọc được file'],
        ];
    }

    private function encryptedZip(string $xlsx): string
    {
        $path = $this->newPackageDir().'/enc.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('syllabus.xlsx', $xlsx);
        $zip->setEncryptionName('syllabus.xlsx', ZipArchive::EM_AES_256, 'secret');
        $zip->close();

        return $path;
    }

    private function notAZip(): string
    {
        $path = $this->newPackageDir().'/bad.zip';
        file_put_contents($path, 'this is not a zip file');

        return $path;
    }

    public function test_rejects_zip_bomb_by_uncompressed_size(): void
    {
        $zip = $this->makeZip(['tai-lieu/big.txt' => str_repeat('0', 210 * 1024 * 1024)]);
        $this->assertLessThan(5 * 1024 * 1024, filesize($zip));

        $this->expectException(SyllabusPackageException::class);
        $this->expectExceptionMessage('quá 200 MB sau giải nén');
        $this->reader()->read($zip, $this->newPackageDir());
    }

    public function test_rejects_entries_that_collide_after_normalising(): void
    {
        $zip = $this->makeZip([
            'syllabus.xlsx' => file_get_contents($this->makeXlsx($this->validSheets())),
            'tai-lieu//a.pdf' => $this->pdfBytes(),
            'tai-lieu/./a.pdf' => $this->pdfBytes().'different',
        ]);

        $this->expectException(SyllabusPackageException::class);
        $this->expectExceptionMessage('hai file trùng đường dẫn');
        $this->reader()->read($zip, $this->newPackageDir());
    }

    public function test_rejects_when_real_bytes_exceed_limit_despite_lying_headers(): void
    {
        // Giả lập header khai gian: declaredSize() luôn 0 nên chỉ phép đếm byte thực mới chặn được.
        $reader = new class extends SyllabusPackageReader
        {
            protected function maxUncompressedBytes(): int
            {
                return 1024 * 1024;
            }

            protected function declaredSize(array $stat): int
            {
                return 0;
            }
        };
        $zip = $this->makeZip([
            'syllabus.xlsx' => file_get_contents($this->makeXlsx($this->validSheets())),
            'tai-lieu/a.txt' => str_repeat('a', 600 * 1024),
            'tai-lieu/b.txt' => str_repeat('b', 600 * 1024),
        ]);
        $dir = $this->newPackageDir();

        try {
            $reader->read($zip, $dir);
            $this->fail('Expected SyllabusPackageException');
        } catch (SyllabusPackageException $e) {
            $this->assertStringContainsString('quá 1 MB sau giải nén', $e->getMessage());
        }
        $this->assertFileDoesNotExist($dir.'/tai-lieu/a.txt');
        $this->assertFileDoesNotExist($dir.'/tai-lieu/b.txt');
    }
}
