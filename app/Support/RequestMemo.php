<?php

namespace App\Support;

use Closure;

/**
 * Bộ nhớ tạm theo request cho dữ liệu cấu hình nhỏ, đọc lặp lại nhiều lần trong cùng một trang (cấu hình hệ thống,
 * đơn giá riêng của GV, kỳ lương đã khoá…): mỗi khoá chỉ truy vấn 1 lần.
 *
 * Làm mới: đầu mỗi request (AppServiceProvider, sự kiện Routing), sau mỗi job hàng đợi (binding scoped), và khi model
 * nguồn được lưu / xoá (model tự gọi forget()).
 */
final class RequestMemo
{
    /** @var array<string, mixed> */
    private array $items = [];

    public static function remember(string $key, Closure $resolver): mixed
    {
        $memo = app(self::class);
        if (! array_key_exists($key, $memo->items)) {
            $memo->items[$key] = $resolver();
        }

        return $memo->items[$key];
    }

    /** Xoá các khoá bắt đầu bằng $prefix. */
    public static function forget(string $prefix): void
    {
        $memo = app(self::class);
        foreach (array_keys($memo->items) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($memo->items[$key]);
            }
        }
    }

    public static function flush(): void
    {
        app(self::class)->items = [];
    }
}
