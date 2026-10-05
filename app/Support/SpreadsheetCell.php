<?php

namespace App\Support;

/**
 * Chống "CSV / formula injection": ô bắt đầu bằng = + - @ (hoặc tab / xuống dòng) bị Excel, LibreOffice, Google Sheets
 * coi là công thức. Dữ liệu người dùng nhập (tên khoản chi, ghi chú, họ tên ứng viên, tiêu đề công việc…) đi vào file xuất
 * phải được vô hiệu hóa bằng cách đặt dấu nháy đơn đứng trước, theo khuyến nghị của OWASP.
 * Số âm / số kèm đơn vị do hệ thống tự định dạng ("-1.500.000 đ") giữ nguyên.
 */
final class SpreadsheetCell
{
    public static function safe(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        $first = $value[0];
        if ($first === '=' || $first === '@' || $first === "\t" || $first === "\r") {
            return "'".$value;
        }
        if (($first === '+' || $first === '-') && ! preg_match('/^[+-][\d.,]+(\s?[\p{L}%]{1,5})?$/u', $value)) {
            return "'".$value;
        }

        return $value;
    }

    /**
     * @param  array<int|string, mixed>  $row
     * @return array<int|string, mixed>
     */
    public static function safeRow(array $row): array
    {
        return array_map(self::safe(...), $row);
    }

    /**
     * @param  list<array<int|string, mixed>>  $rows
     * @return list<array<int|string, mixed>>
     */
    public static function safeRows(array $rows): array
    {
        return array_map(self::safeRow(...), $rows);
    }
}
