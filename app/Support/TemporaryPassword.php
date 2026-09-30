<?php

namespace App\Support;

/**
 * Mật khẩu tạm cấp cho tài khoản học viên (lúc chốt Lead, cấp lại ở màn Xác nhận chính thức): 10 ký tự, luôn có
 * chữ thường, chữ hoa, chữ số và ký tự đặc biệt. Bỏ các ký tự dễ nhầm khi đọc cho phụ huynh (0/O/o, 1/l/I).
 * Sinh bằng random_int (CSPRNG).
 */
final class TemporaryPassword
{
    public const LENGTH = 10;

    private const LOWER = 'abcdefghijkmnpqrstuvwxyz';

    private const UPPER = 'ABCDEFGHJKLMNPQRSTUVWXYZ';

    private const DIGITS = '23456789';

    private const SPECIAL = '!@#$%&*?';

    public static function generate(): string
    {
        $sets = [self::LOWER, self::UPPER, self::DIGITS, self::SPECIAL];
        $all = implode('', $sets);

        $chars = array_map(fn (string $set) => self::pick($set), $sets);
        while (count($chars) < self::LENGTH) {
            $chars[] = self::pick($all);
        }

        // Xáo trộn Fisher–Yates bằng random_int để vị trí từng nhóm ký tự không đoán được.
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }

    private static function pick(string $set): string
    {
        return $set[random_int(0, strlen($set) - 1)];
    }
}
