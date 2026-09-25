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

    /**
     * Tải bảng về dạng .xlsx (mặc định) hoặc .csv (UTF-8 BOM).
     *
     * @param  list<string>  $headings
     * @param  list<array<int, mixed>>  $rows
     */
    public static function download(string $name, array $headings, array $rows, ?string $format = 'xlsx'): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $format = $format === 'csv' ? 'csv' : 'xlsx';

        return \Maatwebsite\Excel\Facades\Excel::download(
            new self($headings, $rows),
            $name.'-'.now()->format('Ymd-His').'.'.$format,
            $format === 'csv' ? \Maatwebsite\Excel\Excel::CSV : \Maatwebsite\Excel\Excel::XLSX
        );
    }

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
