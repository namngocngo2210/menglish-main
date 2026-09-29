<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Nội dung chuyển khoản học phí dùng chung cho mọi luồng (chốt khách, lập phiếu thu, bill, import, SePay):
 * TÊN HỌC SINH + MÃ HỌC SINH + LỚP — không dấu, viết hoa, bỏ ký tự đặc biệt; chưa xếp lớp thì bỏ phần lớp.
 * Ví dụ: "NGUYENVANAN HV00012 IELTS1".
 */
final class TransferMemo
{
    public static function build(?string $studentCode, ?string $studentName, ?string $className = null): string
    {
        return collect([$studentName, $studentCode, $className])
            ->map(fn (?string $part) => self::clean($part))
            ->filter()
            ->implode(' ');
    }

    /** Một phần của nội dung: bỏ dấu, chỉ giữ chữ + số, viết hoa (ngân hàng thường bỏ dấu / ký tự đặc biệt). */
    public static function clean(?string $value): string
    {
        return strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', Str::ascii((string) $value)));
    }
}
