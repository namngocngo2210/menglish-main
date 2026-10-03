<?php

namespace App\Services\Sla;

use App\Models\SlaSetting;
use App\Support\RequestMemo;

/**
 * Đọc cấu hình SLA: mặc định ở config/sla.php, Admin ghi đè ở bảng sla_settings (trang "Cấu hình SLA").
 * Mọi code SLA lấy ngưỡng qua đây thay vì hằng số để đổi được không cần deploy.
 */
class Sla
{
    /** @return array<string, array<string, mixed>> */
    public static function defaults(): array
    {
        return config('sla.rules', []);
    }

    /** @return array<string, mixed> cấu hình hiệu lực của một SLA (mặc định + ghi đè). */
    public static function rule(string $key): array
    {
        $default = self::defaults()[$key] ?? throw new \InvalidArgumentException("SLA không tồn tại: {$key}");
        $override = RequestMemo::remember('sla_settings', fn () => SlaSetting::query()->get()->keyBy('rule_key'))->get($key);

        return [
            ...$default,
            'key' => $key,
            'enabled' => $override?->enabled ?? true,
            'value' => $override?->value ?? $default['value'],
            'penalty' => $override?->penalty ?? $default['penalty'],
            'amount' => (float) ($override?->amount ?? $default['amount']),
            'customized' => $override !== null,
        ];
    }

    public static function enabled(string $key): bool
    {
        return self::rule($key)['enabled'];
    }

    /** Ngưỡng (giờ hoặc số lần tuỳ SLA). */
    public static function value(string $key): int
    {
        return (int) self::rule($key)['value'];
    }

    public static function forget(): void
    {
        RequestMemo::forget('sla_settings');
    }
}
