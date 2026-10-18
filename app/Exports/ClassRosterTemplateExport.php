<?php

namespace App\Exports;

use App\Services\ClassRosterImportService;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;

/** File mẫu nhập danh sách lớp: sheet "Lớp" + sheet "Học viên", mỗi sheet một dòng ví dụ. */
class ClassRosterTemplateExport implements WithMultipleSheets
{
    public function sheets(): array
    {
        $sheet = fn (string $title, array $headings, array $row) => new class($title, $headings, $row) implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
        {
            public function __construct(private string $title, private array $headings, private array $row) {}

            public function array(): array
            {
                return [$this->row];
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
            $sheet('Lớp', array_values(ClassRosterImportService::CLASS_HEADINGS), [
                'Đội Cấn', 'CAM37', 'CAM37', 'Thanh Mai', 'Thứ 3', '19:30', '21:00', 'P201', 'Thứ 6', '19:30', '21:00', 'P401', '', '', '', '',
            ]),
            $sheet('Học viên', array_values(ClassRosterImportService::STUDENT_HEADINGS), [
                'Đội Cấn', 'CAM37', 'HS001521', 'Trịnh Đức Quang', '0385294492', '06/10/2018', 'Nam', '', '', '001192008455', 'TH Ba Đình',
                'Nguyễn Kim Ngân', '0385294492', '', '', '', '', 'Hoàng Hoa Thám, Ngọc Hà, Hà Nội', '19/06/2026', 43, 33, '',
            ]),
        ];
    }
}
