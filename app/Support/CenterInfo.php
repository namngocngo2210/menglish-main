<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\SystemSetting;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Thông tin trung tâm dùng trên phiếu thu, biên lai, bảng điểm... thay cho
 * số điện thoại / địa chỉ viết cứng trong view.
 * Ưu tiên system_settings, sau đó config('app.center_*'); địa chỉ lấy từ chi nhánh.
 */
final class CenterInfo
{
    public static function name(): string
    {
        return (string) (self::setting('center_name') ?: config('app.center_name', config('app.name')));
    }

    public static function phone(): ?string
    {
        return self::setting('center_phone') ?: config('app.center_phone');
    }

    public static function taxCode(): ?string
    {
        return self::setting('center_tax_code') ?: config('app.center_tax_code');
    }

    public static function website(): ?string
    {
        return self::setting('center_website') ?: config('app.center_website');
    }

    /**
     * Chi nhánh đang hoạt động có địa chỉ (để in "CS1: ..., CS2: ...").
     *
     * @return Collection<int, Branch>
     */
    public static function branches(): Collection
    {
        try {
            return Branch::where('is_active', true)
                ->whereNotNull('address')
                ->where('address', '!=', '')
                ->orderBy('id')
                ->get(['id', 'name', 'address', 'phone']);
        } catch (Throwable) {
            return collect();
        }
    }

    private static function setting(string $key): ?string
    {
        try {
            $value = SystemSetting::get($key);
        } catch (Throwable) {
            return null;
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
