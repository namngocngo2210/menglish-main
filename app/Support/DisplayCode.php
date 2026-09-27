<?php

namespace App\Support;

/**
 * Hiển thị gọn mã dạng ULID (vd. "HV-01M3GK1FGA6108BFT34HYF1NWC", "PT-2026-01M3GK1EDBFCPZ016X4TNE").
 * CHỈ đổi cách hiển thị — mã lưu trong DB, tìm kiếm, xuất file giữ nguyên. Giữ tiền tố + 6 ký tự cuối (phần ngẫu
 * nhiên của ULID, đủ phân biệt bằng mắt); mã đầy đủ đặt ở tooltip (<x-ui.code>). Mã ngắn sẵn (HV-00104) giữ nguyên.
 */
final class DisplayCode
{
    /** Tiền tố (chữ, tuỳ chọn "-năm") + ULID 26 ký tự Crockford base32. */
    private const CODE = '((?:[A-Z]{1,6}-)+(?:\d{4}-)?)([0-9A-HJKMNP-TV-Z]{26})';

    private const TAIL = 6;

    public static function short(?string $code): string
    {
        $code = (string) $code;

        return preg_match('/^'.self::CODE.'$/', $code) === 1 ? self::shortenIn($code) : $code;
    }

    /** Rút gọn mọi mã ULID trong một đoạn chữ (vd. tiêu đề việc "Nhắc thu học phí: A (HV-01M3…)"). */
    public static function shortenIn(?string $text): string
    {
        return (string) preg_replace_callback(
            '/\b'.self::CODE.'\b/',
            fn (array $m) => $m[1].'…'.substr($m[2], -self::TAIL),
            (string) $text,
        );
    }
}
