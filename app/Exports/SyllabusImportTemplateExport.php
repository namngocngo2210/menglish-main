<?php

namespace App\Exports;

use App\Services\Syllabus\Import\SyllabusPackageReader as Reader;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;

/** File mẫu import syllabus: sheet "Hướng dẫn" + 3 sheet dữ liệu (Giáo trình, Chặng, Buổi) có dòng ví dụ. */
class SyllabusImportTemplateExport implements WithMultipleSheets
{
    public function sheets(): array
    {
        $sheet = fn (string $title, array $headings, array $rows) => new class($title, $headings, $rows) implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
        {
            public function __construct(private string $title, private array $headings, private array $rows) {}

            public function array(): array
            {
                return $this->rows;
            }

            public function headings(): array
            {
                return $this->headings;
            }

            public function title(): string
            {
                return $this->title;
            }
        };

        return [
            $sheet('Hướng dẫn', ['Hướng dẫn điền file'], array_map(fn (string $line) => [$line], self::guideLines())),
            $sheet(Reader::SHEETS['curriculum'], array_values(Reader::CURRICULUM_COLUMNS), [
                ['MAU-01', 'Giáo trình mẫu', 'v1', 'Giáo trình ví dụ để tham khảo cách điền'],
            ]),
            $sheet(Reader::SHEETS['stages'], array_values(Reader::STAGE_COLUMNS), [
                [1, 'Chặng 1', 'Làm quen', 'Big Test 1', 'Ôn Unit 1', 'https://example.com/muc-luc-chang-1', 6],
                [2, 'Chặng 2', 'Nâng cao', 'Big Test 2', 'Ôn Unit 2', 'https://example.com/muc-luc-chang-2', null],
            ]),
            // Thứ tự cột theo Reader::LESSON_COLUMNS.
            $sheet(Reader::SHEETS['lessons'], array_values(Reader::LESSON_COLUMNS), [
                [1, 1, 'Greetings', 1, 'Hello', 'Chào hỏi cơ bản', 'Getting started: hội thoại chào hỏi', 'hello, goodbye', 'to be', 'Làm bài tập trang 5',
                    'Học', 'Warm-up → Presentation → Practice → Production', 'Role-play chào hỏi', 'Hát bài Hello', 'Quiz chào hỏi', 'Video giới thiệu bản thân',
                    'https://example.com/ppt-buoi-1', 'https://quizizz.com/example', 'Đoạn hội thoại chào hỏi', 'Order in ấn', 'In tài liệu cho chặng 1'],
                [1, 1, 'Greetings', 2, 'My name is', 'Giới thiệu bản thân', 'Hỏi đáp theo cặp', 'name, age', 'I am / You are', 'Viết 5 câu giới thiệu',
                    'Mini test', null, null, null, null, null, null, null, 'Bài mini test Unit 1', null, null],
                [2, 2, 'Family', 3, 'My family', 'Nói về gia đình', 'Vẽ cây gia đình', 'mother, father', 'has / have', 'Vẽ và mô tả gia đình',
                    'Học', null, null, null, null, null, null, null, null, 'Order Big Test', null],
                [2, 2, 'Family', 4, 'Family members', 'Mô tả người thân', 'Trò chơi đoán người', 'brother, sister', 'is / are', 'Làm bài tập trang 12',
                    'Big test', null, null, null, null, null, null, null, null, null, null],
            ]),
        ];
    }

    /** @return list<string> */
    public static function guideLines(): array
    {
        return [
            'Điền 3 sheet "Giáo trình", "Chặng", "Buổi" theo đúng tiêu đề cột ở dòng 1; sheet này không được import.',
            'Giáo trình: chỉ 1 dòng. Mã và Tên bắt buộc; mã tối đa 50 ký tự và chưa có giáo trình nào đang dùng. Phiên bản tối đa 20 ký tự, để trống sẽ là v1.',
            'Chặng: cột Thứ tự điền liên tục 1, 2, 3... không bỏ số; Tên chặng bắt buộc. Link mục lục (nếu có) phải bắt đầu bằng http:// hoặc https://.',
            'Buổi: cột Chặng điền số thứ tự của chặng (1, 2...), không điền chữ "Chặng 1". Mỗi chặng có ít nhất 1 buổi.',
            'Buổi số đánh liên tục 1...N trong cả giáo trình (không đặt lại từ 1 ở mỗi chặng), không trùng nhau.',
            'Một unit chỉ thuộc một chặng; các dòng cùng Unit số phải cùng Tên unit. Unit số từ 1 đến 500, tối đa 1000 buổi.',
            'Cột theo form syllabus mẫu (không bắt buộc): Loại buổi (Học / Mini test / Big test, để trống là Học), Quy trình tiết học, Hoạt động gợi ý 1–2, Bài tập Quizizz / Video, Link PPT / Quizizz (phải bắt đầu bằng http:// hoặc https://), Sản phẩm đầu ra, Mốc thông báo (Order in ấn / Order mini test / Order Big Test), Ghi chú.',
            'Chặng: Báo trước (buổi) là số buổi trước buổi cuối chặng hệ thống báo Học thuật (1–50, để trống là 6).',
            'File mẫu có sẵn tài liệu ví dụ (PDF, ảnh, audio, video) trong tai-lieu/chang-1 và chang-2: thay bằng tài liệu thật hoặc xoá đi trước khi import.',
            'Tài liệu (không bắt buộc): đặt vào thư mục tai-lieu/chang-<thứ tự>/ (ví dụ tai-lieu/chang-1/). Thư mục phải khớp một chặng trong sheet "Chặng".',
            'File để thẳng trong tai-lieu/ là tài liệu chung của giáo trình, không gắn chặng. Không tạo thư mục con sâu hơn một cấp.',
            'Tên tài liệu lấy từ tên file bỏ đuôi. Chỉ nhận các định dạng tài liệu được hệ thống cho phép (kiểm tra theo nội dung file).',
            'Dung lượng file upload tối đa 100 MB. Video nặng hãy upload riêng ở trang Tài liệu.',
            'Không có tài liệu thì có thể upload riêng file syllabus.xlsx, không cần nén zip.',
        ];
    }
}
