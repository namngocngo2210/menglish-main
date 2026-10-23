<?php

namespace Database\Seeders\Support;

use App\Services\Syllabus\Import\SyllabusPackageReader as Reader;
use App\Services\Syllabus\StageImport\StageImportTemplate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use RuntimeException;
use ZipArchive;

/**
 * Bộ file test thủ công Kho syllabus (docs/mau-test-kho-syllabus): file import 1 chặng theo form Sheet 1 và gói import cả
 * giáo trình (.zip / .xlsx), gồm file đúng và file cố tình sai. DemoSyllabusLibrarySeeder dùng gói Flyers để import thử
 * một giáo trình; SyllabusSampleFilesTest giữ các file luôn cho đúng kết quả mô tả trong README.
 */
class SyllabusSampleFiles
{
    public const DIRECTORY = 'docs/mau-test-kho-syllabus';

    /** Các file writeAll() tạo ra — cũng là các file phải có trong thư mục docs. */
    public const FILES = [
        'chang-moi-starters.xlsx',
        'cap-nhat-starters-chang-1.xlsx',
        'chang-co-loi.xlsx',
        'giao-trinh-flyers.zip',
        'giao-trinh-mau-cu.xlsx',
        'giao-trinh-co-loi.zip',
    ];

    private const SAMPLE_DOCS = 'syllabus-import-samples';

    /** Ghi toàn bộ file mẫu vào $dir (tạo thư mục nếu chưa có). */
    public function writeAll(string $dir): void
    {
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException("Không tạo được thư mục {$dir}.");
        }

        $this->stageSheet($dir.'/chang-moi-starters.xlsx', 'Cambridge Starters (demo)', 'Chặng 4: Ôn thi Starters', $this->newStageRows(), withExample: true);
        $this->stageSheet($dir.'/cap-nhat-starters-chang-1.xlsx', 'Cambridge Starters (demo)', 'Chặng 1: Làm quen', $this->updateStageRows());
        $this->stageSheet($dir.'/chang-co-loi.xlsx', 'Cambridge Starters (demo)', 'Chặng 5: File lỗi', $this->brokenStageRows());
        $this->flyersPackage($dir.'/giao-trinh-flyers.zip', 'MAU-FLYERS', 'Cambridge Flyers (file mẫu)');
        $this->oldFormatPackage($dir.'/giao-trinh-mau-cu.xlsx');
        $this->brokenPackage($dir.'/giao-trinh-co-loi.zip');
    }

    /**
     * Gói zip giáo trình Flyers: 2 chặng, 8 buổi đủ cột Sheet 1, tài liệu chung + tài liệu chặng 1, 2.
     */
    public function flyersPackage(string $path, string $code, string $title): void
    {
        $stages = [
            [1, 'Nền tảng Flyers', 'Củng cố ngữ pháp, mở rộng từ vựng chủ đề.', 'Big Test chặng 1 — Flyers', 'Ôn Unit 1–2', 'https://example.com/flyers/muc-luc-chang-1', 3],
            [2, 'Luyện đề Flyers', 'Làm quen dạng đề Flyers 4 kỹ năng.', 'Big Test cuối khóa — Flyers', 'Đề tổng hợp', 'https://example.com/flyers/muc-luc-chang-2', null],
        ];
        $lessons = [
            $this->lesson(1, 1, 'Weather & Seasons', 1, 'Thời tiết và các mùa', 'Học', 'Order in ấn', 'In workbook chặng 1 trước buổi 1.'),
            $this->lesson(1, 1, 'Weather & Seasons', 2, 'Will / won\'t dự đoán thời tiết', 'Học'),
            $this->lesson(1, 2, 'Jobs', 3, 'Nghề nghiệp', 'Học', 'Order mini test'),
            $this->lesson(1, 2, 'Jobs', 4, 'Mini test Unit 1–2', 'Mini test', 'Order Big Test'),
            $this->lesson(1, 2, 'Jobs', 5, 'Big Test chặng 1', 'Big test'),
            $this->lesson(2, 3, 'Flyers practice', 6, 'Listening & Speaking Flyers', 'Học', 'Order Big Test'),
            $this->lesson(2, 3, 'Flyers practice', 7, 'Reading & Writing Flyers', 'Học'),
            $this->lesson(2, 3, 'Flyers practice', 8, 'Big Test cuối khóa', 'Big test'),
        ];

        $this->zip($path, $this->packageWorkbook([$code, $title, 'v1', 'Giáo trình mẫu import từ file zip (đủ cột form Sheet 1).'], $stages, Reader::LESSON_COLUMNS, $lessons), [
            'tai-lieu/huong-dan-giao-trinh.png' => 'hinh-minh-hoa.png',
            'tai-lieu/chang-1/slide-buoi-1.pdf' => 'slide-buoi-1.pdf',
            'tai-lieu/chang-1/audio-nghe-unit-1.mp3' => 'audio-nghe.mp3',
            'tai-lieu/chang-2/video-speaking-mau.mp4' => 'video-mau.mp4',
        ]);
    }

    // ── File 1 chặng (form Sheet 1) ───────────────────────────────────────────────

    /**
     * File 1 chặng dựng từ file mẫu tải về trên hệ thống; mỗi dòng: [cột => giá trị], cột M/N có thể là
     * ['Link PPT', url] để thử ô hyperlink (chữ hiển thị khác URL).
     *
     * @param  list<array<string, string|array{0: string, 1: string}>>  $rows
     */
    private function stageSheet(string $path, string $curriculum, string $stage, array $rows, bool $withExample = false): void
    {
        $template = app(StageImportTemplate::class)->build();
        $book = IOFactory::load($template);
        unlink($template);
        $sheet = $book->getActiveSheet();
        $sheet->setCellValue('D3', $curriculum);
        $sheet->setCellValue('D4', $stage);
        $sheet->setCellValue('D5', 'Học thuật (file test)');
        $sheet->setCellValue('D6', 'v2 – 23/10/2026');

        $line = 11;
        if ($withExample) {
            // Dòng ví dụ nền xám: hệ thống bỏ qua và cảnh báo.
            $sheet->setCellValue("C{$line}", 'Học');
            $sheet->setCellValue("D{$line}", 'Unit 99: Ví dụ');
            $sheet->setCellValue("F{$line}", 'Dòng ví dụ — phải bị bỏ qua');
            $sheet->getStyle("A{$line}:R{$line}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(StageImportTemplate::EXAMPLE_FILL);
            $line++;
        }
        foreach ($rows as $row) {
            foreach ($row as $column => $value) {
                if (is_array($value)) {
                    [$text, $url] = $value;
                    $sheet->setCellValue($column.$line, $text);
                    $sheet->getCell($column.$line)->getHyperlink()->setUrl($url);
                } else {
                    $sheet->setCellValue($column.$line, $value);
                }
            }
            $line++;
        }

        IOFactory::createWriter($book, 'Xlsx')->save($path);
        $book->disconnectWorksheets();
    }

    /** Chặng 4 mới cho Starters: Unit 7–8, 6 buổi gồm Mini test, Big test, link (cả ô hyperlink) và mốc thông báo. */
    private function newStageRows(): array
    {
        return [
            $this->stageRow('Học', 'Unit 7: Toys', "Đồ chơi\nTừ vựng: ball, doll, kite, robot", 'In flashcard đồ chơi', ['Link PPT', 'https://example.com/starters/ppt-unit-7-1'], 'Order in ấn', objectives: 'Gọi tên đồ chơi, hỏi đáp What\'s this?'),
            $this->stageRow('Học', null, 'Have got / has got', 'Phiếu bài tập have got', 'https://example.com/starters/ppt-unit-7-2', null),
            $this->stageRow('Mini test', null, 'Mini test Unit 7', 'Đề mini test 15 phút', 'link PPT', 'Order mini test', quizizz: 'https://quizizz.com/admin/quiz/demo-unit-7'),
            $this->stageRow('Học', 'Unit 8: My house', 'Các phòng trong nhà', 'Vẽ ngôi nhà của em', 'https://example.com/starters/ppt-unit-8-1', 'Order Big Test', objectives: 'Gọi tên phòng, đồ vật trong nhà; giới từ chỉ vị trí.'),
            $this->stageRow('Học', null, 'In / on / under', 'Bài nói mô tả phòng ngủ', 'https://example.com/starters/ppt-unit-8-2', null),
            $this->stageRow('Big test', null, 'Big Test chặng 4', 'Bài thi 4 kỹ năng', null, null, note: 'Thi trong giờ học, GV chấm và nhập điểm trong 3 ngày.'),
        ];
    }

    /** Cập nhật Chặng 1 của Starters (đang có 6 buổi): 5 buổi → 5 buổi sửa, 1 buổi xóa. */
    private function updateStageRows(): array
    {
        return [
            $this->stageRow('Học', 'Unit 1: Hello & Classroom', 'Chào hỏi, tên, tuổi (bản sửa)', 'Thẻ tên', 'https://example.com/starters/ppt-unit-1-1', 'Order in ấn'),
            $this->stageRow('Học', null, 'Đồ vật lớp học (bản sửa)', 'Trò chơi tìm đồ vật', 'https://example.com/starters/ppt-unit-1-2', null),
            $this->stageRow('Học', null, 'Màu sắc & số 1–10 (bản sửa)', 'Tô màu theo số', 'https://example.com/starters/ppt-unit-1-3', 'Order mini test'),
            $this->stageRow('Học', 'Unit 2: My Family', 'Thành viên gia đình (bản sửa)', 'Ảnh gia đình', 'https://example.com/starters/ppt-unit-2-1', null),
            $this->stageRow('Mini test', null, 'Mini test Unit 1–2', 'Đề mini test', null, null),
        ];
    }

    /** File chặng có lỗi theo dòng: thiếu UNIT, sai dạng UNIT, loại buổi / link / mốc thông báo sai. */
    private function brokenStageRows(): array
    {
        return [
            $this->stageRow('Học', null, 'Buổi chưa có UNIT', null, null, null),
            $this->stageRow('Học', 'Bài 1 - Không đúng dạng', 'Ô UNIT sai dạng', null, null, null),
            $this->stageRow('Thi cuối kỳ', 'Unit 20: Lỗi', 'Loại buổi không hợp lệ', null, null, null),
            $this->stageRow('Học', null, 'Link PPT không phải URL', null, 'slide buoi 4', null),
            $this->stageRow('Học', null, 'Mốc thông báo sai', null, null, 'Gửi phụ huynh'),
        ];
    }

    /** @return array<string, string|array{0: string, 1: string}> */
    private function stageRow(string $type, ?string $unit, string $content, ?string $output, string|array|null $ppt, ?string $milestone,
        ?string $objectives = null, ?string $quizizz = null, ?string $note = null): array
    {
        return array_filter([
            'C' => $type,
            'D' => $unit,
            'E' => $objectives,
            'F' => $content,
            'G' => 'Warm-up → Presentation → Practice → Production',
            'H' => 'Trò chơi khởi động',
            'I' => 'Làm việc nhóm đôi',
            'J' => 'Workbook trang tương ứng',
            'K' => $quizizz ? 'Quizizz ôn tập' : null,
            'L' => 'Quay video đọc từ vựng',
            'M' => $ppt,
            'N' => $quizizz,
            'O' => $output,
            'P' => $milestone,
            'R' => $note,
        ], fn ($value) => $value !== null);
    }

    // ── Gói import cả giáo trình ─────────────────────────────────────────────────

    /** File .xlsx theo mẫu cũ: chỉ cột cơ bản, cột nội dung còn tên cũ "Hoạt động" — vẫn import được. */
    private function oldFormatPackage(string $path): void
    {
        $columns = array_slice(Reader::LESSON_COLUMNS, 0, 10, true);
        $columns['content'] = 'Hoạt động';
        $lessons = [
            [1, 1, 'Greetings', 1, 'Hello', 'Chào hỏi cơ bản', 'Hội thoại chào hỏi', 'hello, goodbye', 'to be', 'Workbook trang 5'],
            [1, 1, 'Greetings', 2, 'My name is', 'Giới thiệu bản thân', 'Hỏi đáp theo cặp', 'name, age', 'I am', 'Viết 5 câu giới thiệu'],
            [2, 2, 'Family', 3, 'My family', 'Nói về gia đình', 'Vẽ cây gia đình', 'mother, father', 'has / have', 'Vẽ gia đình'],
        ];
        $stages = [[1, 'Làm quen', null, 'Big Test 1', null, null], [2, 'Gia đình', null, 'Big Test 2', null, null]];

        $book = $this->packageWorkbook(['MAU-CU-01', 'Giáo trình mẫu cũ (file test)', 'v1', 'File theo mẫu trước khi thêm cột Sheet 1.'],
            $stages, $columns, $lessons, array_slice(Reader::STAGE_COLUMNS, 0, 6, true));
        IOFactory::createWriter($book, 'Xlsx')->save($path);
        $book->disconnectWorksheets();
    }

    /** Gói zip cố tình sai: báo trước ngoài 1–50, loại buổi / link / mốc sai, trùng buổi số, thư mục chặng không tồn tại. */
    private function brokenPackage(string $path): void
    {
        $stages = [[1, 'Chặng lỗi', null, null, null, null, 99]];
        $lessons = [
            $this->lesson(1, 1, 'Unit lỗi', 1, 'Loại buổi sai', 'Thi cuối kỳ'),
            $this->lesson(1, 1, 'Unit lỗi', 1, 'Trùng buổi số 1', 'Học', 'Gửi phụ huynh'),
            array_replace($this->lesson(1, 1, 'Unit lỗi', 2, 'Link PPT sai', 'Học'), [16 => 'slide-buoi-2.pptx']),
        ];

        $this->zip($path, $this->packageWorkbook(['MAU-LOI-01', 'Giáo trình lỗi (file test)', 'v1', null], $stages, Reader::LESSON_COLUMNS, $lessons), [
            'tai-lieu/chang-9/khong-co-chang.png' => 'hinh-minh-hoa.png',
        ]);
    }

    /** Một dòng sheet Buổi theo thứ tự Reader::LESSON_COLUMNS. */
    private function lesson(int $stage, int $unit, string $unitTitle, int $session, string $title, string $type, ?string $milestone = null, ?string $note = null): array
    {
        $slug = 'buoi-'.$session;

        return [
            $stage, $unit, $unitTitle, $session, $title, "HV nắm được: {$title}.", "{$title}\nLuyện tập theo cặp, trò chơi củng cố.",
            'Từ vựng chủ đề '.$unitTitle, 'Cấu trúc trọng tâm của buổi', 'Workbook trang tương ứng',
            $type, 'Warm-up → Presentation → Practice → Production', 'Trò chơi khởi động', 'Thảo luận nhóm',
            'Quizizz ôn tập buổi '.$session, 'Video nói 1 phút', "https://example.com/flyers/ppt-{$slug}",
            "https://quizizz.com/admin/quiz/flyers-{$slug}", 'Phiếu bài làm của HV', $milestone, $note,
        ];
    }

    /**
     * @param  list<?string>  $curriculum  [mã, tên, phiên bản, mô tả]
     * @param  array<string, string>  $lessonColumns
     * @param  array<string, string>|null  $stageColumns
     */
    private function packageWorkbook(array $curriculum, array $stages, array $lessonColumns, array $lessons, ?array $stageColumns = null): Spreadsheet
    {
        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);
        foreach ([
            Reader::SHEETS['curriculum'] => [array_values(Reader::CURRICULUM_COLUMNS), [$curriculum]],
            Reader::SHEETS['stages'] => [array_values($stageColumns ?? Reader::STAGE_COLUMNS), $stages],
            Reader::SHEETS['lessons'] => [array_values($lessonColumns), $lessons],
        ] as $title => [$headings, $rows]) {
            $sheet = $book->createSheet();
            $sheet->setTitle($title);
            $sheet->fromArray([$headings, ...$rows], null, 'A1', true);
        }

        return $book;
    }

    /** @param  array<string, string>  $documents  đường dẫn trong zip => file trong resources/syllabus-import-samples */
    private function zip(string $path, Spreadsheet $book, array $documents): void
    {
        $xlsx = sys_get_temp_dir().'/syllabus-'.bin2hex(random_bytes(6)).'.xlsx';
        IOFactory::createWriter($book, 'Xlsx')->save($xlsx);
        $book->disconnectWorksheets();

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Không tạo được file {$path}.");
        }
        $zip->addFile($xlsx, 'syllabus.xlsx');
        foreach ($documents as $entry => $file) {
            $zip->addFile(resource_path(self::SAMPLE_DOCS.'/'.$file), $entry);
        }
        $zip->close();
        unlink($xlsx);
    }
}
