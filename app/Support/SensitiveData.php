<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Che các giá trị nhạy cảm (mật khẩu, khóa bí mật, token, CCCD...) trước khi
 * ghi vào nhật ký thao tác. Dò theo tên khóa, đệ quy qua mảng lồng nhau.
 */
class SensitiveData
{
    public const MASK = '***';

    /** Khóa bị bỏ hẳn khỏi nhật ký (không có giá trị tra cứu). */
    private const DROPPED_KEYS = ['_token', '_method'];

    /** Tên khóa (không phân biệt hoa thường) chứa một trong các chuỗi này sẽ bị che. */
    private const SENSITIVE_FRAGMENTS = [
        'password', 'passwd', 'secret', 'token', 'api_key', 'apikey', 'private_key',
        'access_key', 'signature', 'authorization', 'credential', 'id_card', 'cccd',
    ];

    public static function isSensitiveKey(string|int $key): bool
    {
        if (is_int($key)) {
            return false;
        }

        $key = Str::lower($key);
        if ($key === 'key') {
            return true;
        }

        foreach (self::SENSITIVE_FRAGMENTS as $fragment) {
            if (str_contains($key, $fragment)) {
                return true;
            }
        }

        return false;
    }

    public static function mask(array $data, int $maxLength = 500): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (in_array($key, self::DROPPED_KEYS, true)) {
                continue;
            }

            if (self::isSensitiveKey($key)) {
                $result[$key] = ($value === null || $value === '') ? $value : self::MASK;

                continue;
            }

            $result[$key] = match (true) {
                is_array($value) => self::mask($value, $maxLength),
                $value instanceof UploadedFile => '[file] '.Str::limit($value->getClientOriginalName(), 120),
                is_string($value) && strlen($value) > $maxLength => Str::limit($value, $maxLength),
                default => $value,
            };
        }

        return $result;
    }

    /**
     * Che tham số nhạy cảm trong query string của URL.
     */
    public static function maskUrl(string $url): string
    {
        $parts = parse_url($url);
        if (empty($parts['query'])) {
            return $url;
        }

        parse_str($parts['query'], $query);
        $masked = http_build_query(self::mask($query));

        return Str::before($url, '?').($masked !== '' ? '?'.$masked : '');
    }
}
