<?php

namespace Tests\Support;

use Illuminate\Support\Facades\File;
use RuntimeException;
use ZipArchive;

/**
 * Dựng file ZIP import ngân hàng câu hỏi trong test (data/*.json + hinh/*), đúng định dạng file tách câu.
 * File tạo ra nằm trong một thư mục tạm riêng; gọi cleanup() ở tearDown.
 */
final class QuestionBankZip
{
    /** Ảnh PNG 1×1 hợp lệ (SafeUploadService kiểm tra theo nội dung). */
    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    public static function png(): string
    {
        return base64_decode(self::PNG_BASE64);
    }

    /**
     * Một file dữ liệu (nội dung JSON) với source mặc định: khối 9, "Giữa kì I".
     *
     * @param  list<array<string, mixed>>  $groups
     * @param  array<string, mixed>  $source  ghi đè các trường source
     */
    public static function dataFile(int $fileNo, array $groups, array $source = []): string
    {
        return json_encode([
            'source' => array_merge([
                'file_no' => $fileNo,
                'path' => sprintf('/2026-2027/GIỮA KÌ I/Khối 9/De %03d', $fileNo),
                'nam_hoc' => '2026-2027',
                'ky' => 'Giữa kì I',
                'khoi' => 9,
                'truong' => 'THCS Thử Nghiệm',
            ], $source),
            'groups' => $groups,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * Một nhóm câu hỏi; $passage khác null → nhóm có ngữ liệu chung.
     *
     * @param  list<array<string, mixed>>  $questions
     * @return array<string, mixed>
     */
    public static function group(string $skill, string $type, array $questions, ?string $passage = null, string $instruction = 'Choose A, B, C or D.'): array
    {
        $group = ['skill' => $skill, 'type' => $type, 'instruction' => $instruction, 'questions' => $questions];
        if ($passage !== null) {
            $group['passage'] = $passage;
        }

        return $group;
    }

    /**
     * Một câu trắc nghiệm A–D hợp lệ; $overrides ghi đè / thêm trường (anh, thieu, kien_thuc…).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function question(string $no, array $overrides = []): array
    {
        return array_merge([
            'no' => $no,
            'stem' => "Stem {$no} _______.",
            'options' => ['A' => "a {$no}", 'B' => "b {$no}", 'C' => "c {$no}", 'D' => "d {$no}"],
            'answer' => 'B',
        ], $overrides);
    }

    /**
     * Ghi file ZIP với các entry (tên trong zip => nội dung) và trả về đường dẫn.
     *
     * @param  array<string, string>  $entries
     */
    public static function write(array $entries): string
    {
        $dir = self::dir();
        $path = $dir.'/'.uniqid('qb', true).'.zip';

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create test zip '.$path);
        }
        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        return $path;
    }

    public static function cleanup(): void
    {
        File::deleteDirectory(self::dir());
    }

    private static function dir(): string
    {
        $dir = storage_path('framework/testing/question-bank-zips');
        File::ensureDirectoryExists($dir);

        return $dir;
    }
}
