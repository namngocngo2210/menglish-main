<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

/**
 * Xuất bảng dữ liệu đơn giản (tiêu đề + các dòng) ra Excel / CSV.
 * CSV có BOM UTF-8 để Excel đọc đúng tiếng Việt.
 */
class ArrayExport implements FromArray, ShouldAutoSize, WithCustomCsvSettings, WithHeadings, WithStrictNullComparison
{
    /**
     * @param  list<string>  $headings
     * @param  list<array<int, mixed>>  $rows
     */
    public function __construct(private readonly array $headings, private readonly array $rows) {}

    public function array(): array
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return $this->headings;
    }

    public function getCsvSettings(): array
    {
        return ['use_bom' => true];
    }
}
