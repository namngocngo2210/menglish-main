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

        $switchable = $default['switchable'] ?? true;
        // penalty null = SLA không có biên bản (chỉ nhắc / chặn thao tác): giữ null dù có dòng ghi đè.
        $penalty = $default['penalty'] === null ? null : (bool) ($override?->penalty ?? $default['penalty']);

        return [
            ...$default,
            'key' => $key,
            'switchable' => $switchable,
            'enabled' => $switchable ? ($override?->enabled ?? true) : true,
            'value' => $override?->value ?? $default['value'],
            'penalty' => $penalty,
            'amount' => (float) ($override?->amount ?? $default['amount']),
            'ladder' => $override?->ladder ?: ($default['ladder'] ?? null),
            'customized' => $override !== null,
        ];
    }

    public static function enabled(string $key): bool
    {
        return self::rule($key)['enabled'];
    }

    /** Ngưỡng (giờ, ngày, số lần, phút trong ngày hoặc ngày trong tháng tuỳ đơn vị của SLA). */
    public static function value(string $key): int
    {
        return (int) self::rule($key)['value'];
    }

    /** SLA đơn vị "time": giờ trong ngày dạng H:i (giá trị lưu là số phút từ 00:00). */
    public static function time(string $key): string
    {
        return self::minutesToTime(self::value($key));
    }

    /** Có tự lập biên bản khi quá hạn không (SLA không có biên bản → false). */
    public static function penalizes(string $key): bool
    {
        return (bool) self::rule($key)['penalty'];
    }

    public static function minutesToTime(int $minutes): string
    {
        $minutes = max(0, min(1439, $minutes));

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /** Hiển thị giá trị kèm đơn vị: "24 giờ", "3 ngày", "3 lần", "19:00", "ngày 5". */
    public static function formatValue(int $value, string $unit): string
    {
        return match ($unit) {
            'hours' => "{$value} giờ",
            'days' => "{$value} ngày",
            'time' => self::minutesToTime($value),
            'day_of_month' => "ngày {$value}",
            default => "{$value} lần",
        };
    }

    /** Giới hạn hợp lệ [min, max] của giá trị theo đơn vị (trang Cấu hình SLA). */
    public static function bounds(string $unit): array
    {
        return match ($unit) {
            'hours' => [1, 720],
            'days' => [1, 90],
            'time' => [0, 1439],
            'day_of_month' => [1, 28],
            default => [1, 20],
        };
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
