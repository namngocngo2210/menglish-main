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
            $this->lesson(1, 2, 'Jobs', 4, 'Ôn tập + Mini test Unit 1–2', 'Ôn tập', 'Order Big Test'),
            $this->lesson(1, 2, 'Jobs', 5, 'Big Test chặng 1', 'Big test'),
            $this->lesson(2, 3, 'Flyers practice', 6, 'Listening & Speaking Flyers', 'Học', 'Order Big Test'),
            $this->lesson(2, 3, 'Flyers practice', 7, 'Grammar Basic: Past simple', 'Grammar Basic'),
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

    /**
     * Chặng 4 mới cho Starters theo form Sheet 1 mới (như syllabus FAM 2): Unit 7–8, 8 buổi gồm Ôn tập, Grammar Basic,
     * Big test; ô UNIT "GRAMMAR BASIC…", "ÔN TẬP CHẶNG", "BIG TEST" là tiêu đề riêng của buổi; link PPT / PPT GVNN / Quizizz /
     * đề kiểm tra (cả ô hyperlink hiện tên file) và mốc thông báo.
     */
    private function newStageRows(): array
    {
        $url = fn (string $slug) => "https://example.com/starters/{$slug}";

        return [
            $this->stageRow('Học', 'Unit 7: Toys', "Đồ chơi\nTừ vựng: ball, doll, kite, robot", 'In flashcard đồ chơi', 'Order in ấn',
                objectives: 'Gọi tên đồ chơi, hỏi đáp What\'s this?', ppt: ['ST-Unit 7-Lesson 1.pptx', $url('ppt-unit-7-1')],
                pptForeign: ['ST-Unit 7-Lesson 1 - FOREIGN TEACHER.pptx', $url('ppt-gvnn-unit-7-1')]),
            $this->stageRow('Học', null, 'Have got / has got', 'Phiếu bài tập have got', 'Order in ấn mini test',
                ppt: $url('ppt-unit-7-2'), quizizz: 'https://quizizz.com/admin/quiz/demo-unit-7', test: ['KIỂM TRA TỪ Unit 7.docx', $url('kiem-tra-tu-unit-7')]),
            $this->stageRow('Ôn tập', null, 'Review Unit 7 + Mini test', 'Điểm mini test Unit 7', null, ppt: 'link PPT', test: $url('mini-test-unit-7')),
            $this->stageRow('Grammar Basic', "GRAMMAR BASIC 1\nTOPIC 1: NOUNS", "Nouns\nSingular", 'Hoàn thành BT sách Basic 1', null,
                ppt: $url('grammar-topic-1'), quizizz: 'https://quizizz.com/admin/quiz/demo-grammar-1'),
            $this->stageRow('Học', 'Unit 8: My house', 'Các phòng trong nhà', 'Vẽ ngôi nhà của em', 'Order Big Test',
                objectives: 'Gọi tên phòng, đồ vật trong nhà; giới từ chỉ vị trí.', ppt: $url('ppt-unit-8-1'), pptForeign: $url('ppt-gvnn-unit-8-1')),
            $this->stageRow('Học', null, 'In / on / under', 'Bài nói mô tả phòng ngủ', null, ppt: $url('ppt-unit-8-2')),
            $this->stageRow('Ôn tập', 'ÔN TẬP CHẶNG', 'Review Unit 7, 8', 'Video speaking ôn tập', null, quizizz: 'https://quizizz.com/admin/quiz/demo-review-4'),
            $this->stageRow('Big test', 'BIG TEST', 'BIG TEST Unit 7 - 8', 'Điểm Big test chặng 4', null, test: $url('big-test-chang-4'),
                note: 'Thi trong giờ học, GV chấm và nhập điểm trong 3 ngày.'),
        ];
    }

    /** Cập nhật Chặng 1 của Starters (đang có 6 buổi): 5 buổi → 5 buổi sửa, 1 buổi xóa. */
    private function updateStageRows(): array
    {
        return [
            $this->stageRow('Học', 'Unit 1: Hello & Classroom', 'Chào hỏi, tên, tuổi (bản sửa)', 'Thẻ tên', 'Order in ấn', ppt: 'https://example.com/starters/ppt-unit-1-1'),
            $this->stageRow('Học', null, 'Đồ vật lớp học (bản sửa)', 'Trò chơi tìm đồ vật', null, ppt: 'https://example.com/starters/ppt-unit-1-2'),
            $this->stageRow('Học', null, 'Màu sắc & số 1–10 (bản sửa)', 'Tô màu theo số', 'Order mini test', ppt: 'https://example.com/starters/ppt-unit-1-3'),
            $this->stageRow('Học', 'Unit 2: My Family', 'Thành viên gia đình (bản sửa)', 'Ảnh gia đình', null, ppt: 'https://example.com/starters/ppt-unit-2-1'),
            $this->stageRow('Ôn tập', null, 'Ôn tập + Mini test Unit 1–2', 'Đề mini test', null),
        ];
    }

    /** File chặng có lỗi theo dòng: thiếu UNIT ở buổi đầu, loại buổi / link / mốc thông báo sai. */
    private function brokenStageRows(): array
    {
        return [
            $this->stageRow('Học', null, 'Buổi chưa có UNIT', null, null),
            $this->stageRow('Học', 'Unit 20: Lỗi', 'Loại buổi không hợp lệ', null, null),
            $this->stageRow('Thi cuối kỳ', null, 'Loại buổi không hợp lệ', null, null),
            $this->stageRow('Học', null, 'Link PPT / PPT GVNN không phải URL', null, null, ppt: 'slide buoi 4', pptForeign: 'slide GVNN buoi 4.pptx'),
            $this->stageRow('Học', null, 'Mốc thông báo sai', null, 'Gửi phụ huynh'),
        ];
    }

    /**
     * Một dòng file chặng theo cột của StageImportTemplate; link là URL hoặc [chữ hiển thị, URL hyperlink].
     *
     * @return array<string, string|array{0: string, 1: string}>
     */
    private function stageRow(string $type, ?string $unit, string $content, ?string $output, ?string $milestone, ?string $objectives = null,
        string|array|null $ppt = null, string|array|null $pptForeign = null, ?string $quizizz = null, string|array|null $test = null, ?string $note = null): array
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
            'K' => $quizizz ? 'Quizizz ôn tập (y/c đạt 90%)' : null,
            'L' => 'Quay video đọc từ vựng',
            'M' => $ppt,
            'N' => $pptForeign,
            'O' => $quizizz,
            'P' => $test,
            'Q' => $output,
            'R' => $milestone,
            'S' => 'Không',
            'T' => $note,
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
            "https://example.com/flyers/ppt-gvnn-{$slug}", in_array($type, ['Mini test', 'Big test', 'Ôn tập'], true) ? "https://example.com/flyers/de-{$slug}" : null,
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
