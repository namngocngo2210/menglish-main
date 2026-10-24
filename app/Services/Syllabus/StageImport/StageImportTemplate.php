<?php

namespace App\Services\Syllabus\StageImport;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

/**
 * File mẫu import 1 chặng — bố cục Sheet 1 "Syllabus mẫu" của Học thuật (docs/Syllabus_Quy_trinh_xu_ly.xlsx, thêm cột
 * Link PPT GVNN và Link Mini test / Kiểm tra từ như syllabus FAM 2): ô đầu sheet (giáo trình, chặng, người soạn, phiên
 * bản, người duyệt), chú thích màu, tiêu đề 2 dòng và 40 dòng buổi. StageSheetReader nhận cột theo tiêu đề nên file
 * theo form cũ (thiếu 2 cột link) vẫn đọc được.
 */
class StageImportTemplate
{
    public const ROWS = 40;

    public const INPUT_FILL = 'FFF2CC';

    public const EXAMPLE_FILL = 'F2F2F2';

    public const TEST_FILL = 'FCE4D6';

    public const MILESTONE_FILL = 'E2EFDA';

    private const HEADER_FILL = '1F3864';

    private const LABELS = [
        3 => 'Chương trình / Giáo trình:',
        4 => 'Chặng:',
        5 => 'Người soạn (Học thuật):',
        6 => 'Phiên bản / Ngày cập nhật:',
        7 => 'Người duyệt (Quản lý):',
    ];

    private const LEGEND = [
        4 => [self::INPUT_FILL, 'Ô cần học thuật điền'],
        5 => [self::EXAMPLE_FILL, 'Dòng ví dụ – xoá khi dùng thật'],
        6 => [self::TEST_FILL, 'Buổi kiểm tra (Mini test / Big test)'],
        7 => [self::MILESTONE_FILL, 'Dòng có đánh dấu mốc thông báo'],
    ];

    private const HEADERS = [
        'A' => 'Chặng', 'B' => 'Buổi / Lesson', 'C' => 'Loại buổi', 'D' => 'UNIT', 'E' => 'Objectives của UNIT',
        'F' => 'Content (nội dung từng tiết)', 'G' => 'Quy trình tiết học chung', 'H' => 'HOẠT ĐỘNG GỢI Ý (tối đa 2 HĐ/lesson)',
        'J' => 'HOMEWORK (HS nộp đúng giỏ theo loại để thống kê)', 'M' => 'Link PPT', 'N' => 'Link PPT GVNN', 'O' => 'Link Quizizz',
        'P' => 'Link Mini test / Kiểm tra từ', 'Q' => 'Sản phẩm đầu ra', 'R' => 'Mốc thông báo', 'S' => 'Chỉnh sửa thủ công', 'T' => 'Ghi chú',
    ];

    private const SUB_HEADERS = [
        'H' => 'Hoạt động gợi ý 1', 'I' => 'Hoạt động gợi ý 2', 'J' => 'Bài tập giấy', 'K' => 'Bài tập Quizizz', 'L' => 'Bài tập Video',
    ];

    /** @return string đường dẫn file .xlsx tạm; người gọi tự xoá sau khi gửi */
    public function build(): string
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Syllabus mẫu');

        $sheet->setCellValue('A1', 'SYLLABUS MẪU – FORM CHUẨN CỦA HỌC THUẬT');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->setCellValue('A2', 'Mỗi file = 1 chặng của 1 giáo trình. Ô nền vàng là ô cần điền. Loại buổi: Học / Ôn tập / Grammar Basic / '
            .'Mini test / Big test. Mốc thông báo: Order in ấn / Order mini test / Order Big Test / Báo Học thuật chuẩn bị (tạo việc chuẩn bị test cho Học thuật). Ô UNIT ghi "Unit 1: Tên unit" ở buổi '
            .'đầu của unit; ghi chữ khác (vd. "GRAMMAR BASIC 1 – TOPIC 1", "ÔN TẬP CHẶNG", "BIG TEST") là tiêu đề của riêng buổi đó, '
            .'buổi vẫn thuộc unit đang học.');

        foreach (self::LABELS as $row => $label) {
            $sheet->setCellValue('A'.$row, $label);
            $sheet->mergeCells("D{$row}:F{$row}");
            $this->fill($sheet, "D{$row}:F{$row}", self::INPUT_FILL);
        }
        $sheet->setCellValue('H3', 'CHÚ THÍCH');
        foreach (self::LEGEND as $row => [$color, $text]) {
            $this->fill($sheet, 'H'.$row, $color);
            $sheet->setCellValue('I'.$row, $text);
        }

        foreach (self::HEADERS as $column => $title) {
            $sheet->setCellValue($column.'9', $title);
        }
        foreach (self::SUB_HEADERS as $column => $title) {
            $sheet->setCellValue($column.'10', $title);
        }
        $sheet->mergeCells('H9:I9');
        $sheet->mergeCells('J9:L9');
        $header = $sheet->getStyle('A9:T10');
        $header->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $header->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
        $this->fill($sheet, 'A9:T10', self::HEADER_FILL);

        $last = 10 + self::ROWS;
        for ($i = 1; $i <= self::ROWS; $i++) {
            $sheet->setCellValue('B'.(10 + $i), $i);
        }
        $this->fill($sheet, "C11:T{$last}", self::INPUT_FILL);
        $sheet->getStyle("A11:T{$last}")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);

        foreach (['A' => 10, 'B' => 9, 'C' => 11, 'D' => 24, 'E' => 32, 'F' => 36, 'G' => 26, 'H' => 24, 'I' => 24, 'J' => 20,
            'K' => 20, 'L' => 20, 'M' => 16, 'N' => 16, 'O' => 16, 'P' => 18, 'Q' => 24, 'R' => 18, 'S' => 12, 'T' => 24] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        $sheet->freezePane('C11');

        return $this->save($book);
    }

    private function fill(Worksheet $sheet, string $range, string $rgb): void
    {
        $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($rgb);
    }

    private function save(Spreadsheet $book): string
    {
        $dir = storage_path('app/private/syllabus-imports');
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException('Không tạo được thư mục tạm.');
        }
        $path = $dir.'/mau-chang-'.bin2hex(random_bytes(8)).'.xlsx';
        IOFactory::createWriter($book, 'Xlsx')->save($path);

        return $path;
    }
}
