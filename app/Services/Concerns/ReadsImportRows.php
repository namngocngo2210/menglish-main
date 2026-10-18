<?php

namespace App\Services\Concerns;

use App\Models\Branch;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Đọc file Excel nhập dữ liệu từ hệ thống cũ: tìm dòng tiêu đề theo bảng bí danh, đọc ngày dd/mm/yyyy, tách SĐT,
 * khớp cơ sở theo tên / mã. Dùng chung cho nhập danh sách lớp và nhập nhân sự.
 */
trait ReadsImportRows
{
    /**
     * Tìm dòng tiêu đề (trong 5 dòng đầu, bỏ dòng tên bảng) và đổi các dòng sau thành mảng key => giá trị.
     *
     * @return array{columns: array<int, string>, rows: array<int, array<string, mixed>>}|null rows theo số dòng Excel
     */
    private function locate(array $rows, array $aliases, array $required): ?array
    {
        $rows = array_values($rows);
        foreach (array_slice($rows, 0, 5, true) as $headerIndex => $header) {
            $columns = [];
            foreach ($header as $index => $heading) {
                $key = $aliases[Str::slug(Str::ascii(preg_replace('/^\xEF\xBB\xBF/', '', (string) $heading)), '_')] ?? null;
                if ($key && ! in_array($key, $columns, true)) {
                    $columns[$index] = $key;
                }
            }
            if (array_diff($required, $columns) !== [] || count($columns) < 2) {
                continue;
            }

            $data = [];
            foreach (array_slice($rows, $headerIndex + 1, null, true) as $offset => $cells) {
                $row = [];
                foreach ($columns as $index => $key) {
                    $value = $cells[$index] ?? null;
                    $row[$key] = is_string($value) ? trim($value) : $value;
                }
                if (collect($row)->filter(fn ($v) => trim((string) $v) !== '')->isNotEmpty()) {
                    $data[$offset + 1] = $row;
                }
            }

            return ['columns' => $columns, 'rows' => $data];
        }

        return null;
    }

    private function findBranch(Collection $branches, string $value): ?Branch
    {
        $needle = preg_replace('/^(me|co so|chi nhanh|cn)\s+/', '', self::fold($value));

        return $branches->first(fn (Branch $b) => self::fold((string) $b->code) === $needle || self::fold($b->name) === $needle)
            ?? (($found = $branches->filter(fn (Branch $b) => str_contains(self::fold($b->name), $needle)))->count() === 1 ? $found->first() : null);
    }

    private function date(mixed $value, string $label, array &$warnings): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        try {
            if (is_numeric($value)) {
                return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->toDateString();
            }
            foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'd/m/y', 'Y-m-d H:i:s'] as $format) {
                $parsed = \DateTime::createFromFormat('!'.$format, trim((string) $value));
                $problems = \DateTime::getLastErrors();
                if ($parsed && (! $problems || ($problems['warning_count'] === 0 && $problems['error_count'] === 0))) {
                    return Carbon::instance($parsed)->toDateString();
                }
            }
        } catch (\Throwable) {
            // rơi xuống cảnh báo
        }
        $warnings[] = "{$label} \"{$value}\" không đúng dạng dd/mm/yyyy: bỏ trống.";

        return null;
    }

    /** Tách "0934671389 / 0978990288 (bố)" thành các số; số 9 chữ số bị mất số 0 đầu (Excel) được thêm lại. */
    private static function phones(mixed $value): array
    {
        $phones = [];
        foreach (preg_split('/[\/;,]|\s-\s/u', preg_replace('/\.0$/', '', trim((string) $value))) as $part) {
            $digits = preg_replace('/\D/', '', $part);
            if (strlen($digits) === 9 && $digits[0] !== '0') {
                $digits = '0'.$digits;
            }
            if (strlen($digits) >= 8 && strlen($digits) <= 15) {
                $phones[] = $digits;
            }
        }

        return array_values(array_unique($phones));
    }

    private static function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? mb_substr($value, 0, 255) : null;
    }

    private static function fold(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', Str::lower(Str::ascii($value))));
    }
}
