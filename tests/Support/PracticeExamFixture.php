<?php

namespace Tests\Support;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use ZipArchive;

/**
 * File đề cố định giả lập theo đúng mẫu file thật (Đề GK Cấp 2): Excel mỗi sheet một mã đề + Word của đề có gạch chân.
 * Đề 6 câu: 2 Phát âm, 1 ngữ pháp, 2 câu điền từ chung một đoạn văn, 1 biển báo (dạng tách dòng "Câu 6: …" như khối 8).
 */
final class PracticeExamFixture
{
    /** @var list<string> */
    private static array $files = [];

    /** @param  array<string, array{grade?: int, term?: string, minutes?: int, keyOverride?: array<int, string>}>  $sheets  mã đề => tuỳ chọn */
    public static function xlsx(array $sheets = ['601' => []]): string
    {
        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);
        foreach ($sheets as $code => $o) {
            $grade = $o['grade'] ?? 6;
            $term = $o['term'] ?? 'GIỮA HỌC KÌ I';
            $sheet = $book->createSheet();
            $sheet->setTitle("Đề {$code}");
            $key = array_replace([1 => 'B', 2 => 'D', 3 => 'B', 4 => 'D', 5 => 'B', 6 => 'A'], $o['keyOverride'] ?? []);
            $rows = [
                ["ĐỀ KIỂM TRA {$term} – NĂM HỌC 2026–2027 – MÔN TIẾNG ANH {$grade} – MÃ ĐỀ {$code}"],
                ['Thời gian làm bài: '.($o['minutes'] ?? 60).' phút · 6 câu trắc nghiệm'],
                ['Câu', 'Dạng bài', 'Câu hỏi / Ngữ liệu', 'A', 'B', 'C', 'D', 'Đáp án'],
                ['Mark the letter A, B, C or D to indicate the word whose underlined part differs from the other three in pronunciation.'],
                ['Câu 1', 'Phát âm', '', 'A. father', 'B. brother', 'C. card', 'D. class', 'B'],
                ['Câu 2', 'Phát âm', '', 'A. pens', 'B. rulers', 'C. bags', 'D. books', 'D'],
                ['Mark the letter A, B, C or D to indicate the correct answer to each of the following questions.'],
                ['Câu 3', 'Ngữ pháp – Từ vựng – Giao tiếp', 'My sister _____ her homework every evening.', 'A. do', 'B. does', 'C. is doing', 'D. doing', 'B'],
                ['Read the following passage and mark the letter A, B, C or D.'],
                ["My name is Phong. I live and study (4) _____.\nI (5) _____ many friends."],
                ['Câu 4', 'Đọc – Điền từ', 'Chỗ trống (4)', 'A. here', 'B. it', 'C. them', 'D. there', 'D'],
                ['Câu 5', 'Đọc – Điền từ', 'Chỗ trống (5)', 'A. do', 'B. have', 'C. make', 'D. play', 'B'],
                ['Read the following sign and mark the letter A, B, C or D.'],
                ['Câu 6: [Biển báo] QUIET, PLEASE!'],
                ['Câu 6', 'Đọc – Biển báo', 'What does the sign say?', 'A. Do not make noise.', 'B. Talk loudly.', 'C. Play games.', 'D. Sing here.', 'A'],
                [],
                ["ĐÁP ÁN – MÃ ĐỀ {$code}"],
                array_map(fn (int $no) => "{$no}. {$key[$no]}", range(1, 6)),
            ];
            foreach ($rows as $r => $cells) {
                foreach ($cells as $c => $value) {
                    $sheet->setCellValue([$c + 1, $r + 1], $value);
                }
            }
        }

        $path = self::tempPath('xlsx');
        (new Xlsx($book))->save($path);

        return $path;
    }

    /** Word của đề: câu 1–2 có gạch chân. */
    public static function docx(string $code = '601'): string
    {
        $u = fn (string $t) => '<w:r><w:rPr><w:u w:val="single"/></w:rPr><w:t xml:space="preserve">'.$t.'</w:t></w:r>';
        $t = fn (string $t) => '<w:r><w:t xml:space="preserve">'.$t.'</w:t></w:r>';
        $tab = '<w:r><w:tab/></w:r>';
        $paragraphs = [
            $t('ĐỀ KIỂM TRA GIỮA HỌC KÌ I'),
            $t('Mã đề: '.$code),
            $t('Mark the letter A, B, C or D to indicate the word whose underlined part differs.'),
            $t('Question 1. A. f').$u('a').$t('ther').$tab.$t('B. br').$u('o').$t('ther').$tab.$t('C. c').$u('a').$t('rd').$tab.$t('D. cl').$u('a').$t('ss'),
            $t('Question 2. A. pen').$u('s').$tab.$t('B. ruler').$u('s').$tab.$t('C. bag').$u('s').$tab.$t('D. book').$u('s'),
            $t('Mark the letter A, B, C or D to indicate the correct answer.'),
            $t('Question 3. My sister _____ her homework every evening.'),
            $t('A. do').$tab.$t('B. does').$tab.$t('C. is doing').$tab.$t('D. doing'),
        ];
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
            .implode('', array_map(fn ($p) => "<w:p>{$p}</w:p>", $paragraphs))
            .'</w:body></w:document>';

        $path = self::tempPath('docx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $zip->addFromString('word/document.xml', $xml);
        $zip->close();

        return $path;
    }

    /** @param  array<string, string>  $entries  tên trong ZIP => đường dẫn file */
    public static function zip(array $entries): string
    {
        $path = self::tempPath('zip');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($entries as $name => $file) {
            $zip->addFile($file, $name);
        }
        $zip->close();

        return $path;
    }

    public static function cleanup(): void
    {
        foreach (self::$files as $file) {
            @unlink($file);
        }
        self::$files = [];
    }

    private static function tempPath(string $ext): string
    {
        $path = sys_get_temp_dir().'/practice-exam-fixture-'.bin2hex(random_bytes(6)).'.'.$ext;
        self::$files[] = $path;

        return $path;
    }
}
