<?php

namespace App\Exports;

use App\Services\StaffImportService;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/** File mẫu nhập nhân sự: 1 sheet "Nhân sự", 2 dòng ví dụ (giáo viên part-time, trợ giảng). */
class StaffImportTemplateExport implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
{
    public function array(): array
    {
        return [
            ['Nguyễn Văn An', 'Giáo viên Part-time', 'Đội Cấn', 'msan.menglish@gmail.com', 'vanan@gmail.com', '0912345678', '17/09/2001', '001201000001', '', 'Hà Nội',
                'Cầu Giấy, Hà Nội', 'Bố', '0987654321', 'ĐH Ngoại Ngữ - ĐHQGHN', 'Ngôn ngữ Anh', 'Mẫu giáo / Tiểu học', 'IELTS 7.5', 'Bán thời gian', '15/09/2023', '', '', 'Điện thoại', ''],
            ['Trần Thị Bình', 'Trợ giảng', 'Hoàng Hoa Thám', 'tranbinh@gmail.com', '', '0934567890', '28/09/2007', '001307000002', '22/12/2021', 'Hải Phòng',
                'Đống Đa, Hà Nội', 'Mẹ', '0976543210', 'ĐH Bách Khoa Hà Nội', 'Kỹ thuật Môi trường', '', '', 'Cộng tác viên', '01/12/2025', '', '0934567890 - MB Bank', 'Điện thoại', ''],
        ];
    }

    public function headings(): array
    {
        return array_values(StaffImportService::HEADINGS);
    }

    public function title(): string
    {
        return 'Nhân sự';
    }
}
