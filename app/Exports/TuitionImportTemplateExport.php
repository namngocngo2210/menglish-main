<?php

namespace App\Exports;

use App\Services\TuitionImportService;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

/** File mẫu nhập học phí từ Excel (1 dòng ví dụ). */
class TuitionImportTemplateExport implements FromArray, ShouldAutoSize, WithHeadings
{
    public function headings(): array
    {
        return array_values(TuitionImportService::TEMPLATE_HEADINGS);
    }

    public function array(): array
    {
        return [[
            'HV-000001',
            'IELTS-A1-01',
            12500000,
            500000,
            850000,
            now()->addDays(14)->format('d/m/Y'),
            5000000,
            'chuyen_khoan',
            'FT26100012345',
            now()->format('d/m/Y'),
            'Đóng đợt 1',
        ]];
    }
}
