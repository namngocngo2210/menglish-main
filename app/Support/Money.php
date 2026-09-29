<?php

namespace App\Support;

/**
 * Định dạng số tiền hiển thị thống nhất toàn hệ thống: "9.500.000 đ" (dấu chấm hàng nghìn, không lẻ, đơn vị "đ").
 * Trong view dùng <x-ui.money> cho ô / con số tiền; dùng Money::format() khi tiền nằm giữa câu chữ, thông báo, hint.
 * Không dùng cho file xuất Excel (giữ số thô) hay chứng từ in (tuition-bill) có mẫu riêng.
 */
final class Money
{
    public const UNIT = 'đ';

    /** null / không phải số → "—". $sign = true thêm "+" cho số dương. $unit = '' để bỏ đơn vị. */
    public static function format(mixed $value, string $unit = self::UNIT, bool $sign = false): string
    {
        if (! is_numeric($value)) {
            return '—';
        }

        $number = (float) $value;
        $prefix = $number < 0 ? '-' : ($sign && $number > 0 ? '+' : '');

        return $prefix.number_format(abs($number), 0, ',', '.').($unit !== '' ? ' '.$unit : '');
    }
}
