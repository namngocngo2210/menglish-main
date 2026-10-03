<?php

namespace App\Services\Sla;

use App\Models\SlaSetting;
use App\Models\SystemSetting;
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
            'ladder' => $override?->ladder ?: ($default['ladder'] ?? null),
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

    /** Số tháng cộng dồn lần tái phạm cho bậc phạt (Admin chỉnh ở trang Cấu hình SLA). */
    public static function ladderResetMonths(): int
    {
        return max(1, (int) SystemSetting::get('sla.ladder_reset_months', config('sla.ladder_reset_months', 12)));
    }

    /** Mức phạt của lần vi phạm thứ $occurrence theo bậc phạt; không có bậc thì dùng mức gợi ý chung. */
    public static function amountForOccurrence(array $rule, int $occurrence): float
    {
        $ladder = array_values($rule['ladder'] ?? []);
        if ($ladder === []) {
            return (float) $rule['amount'];
        }

        return (float) $ladder[min($occurrence, count($ladder)) - 1];
    }

    public static function forget(): void
    {
        RequestMemo::forget('sla_settings');
    }
}
