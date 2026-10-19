<?php

namespace App\Support;

use Closure;

/**
 * Bật / tắt lọc dữ liệu tự động theo người đang đăng nhập (docs/rbac.md, mục "Lọc tự động").
 *
 * Model dùng App\Models\Concerns\ScopedToViewer tự lọc mọi truy vấn theo phạm vi của người xem, nhưng chỉ khi đang
 * xử lý một request web (middleware EnforceDataScope bật). Lệnh console, job hàng đợi và đoạn code bọc trong
 * DataScopeGuard::bypass() (tính lương, sinh mã, chống trùng...) chạy trên toàn bộ dữ liệu.
 */
final class DataScopeGuard
{
    private bool $enforcing = false;

    private int $bypassDepth = 0;

    public static function current(): self
    {
        return app(self::class);
    }

    public function enforce(bool $on = true): void
    {
        $this->enforcing = $on;
    }

    public function active(): bool
    {
        return $this->enforcing && $this->bypassDepth === 0;
    }

    public function suspend(): void
    {
        $this->bypassDepth++;
    }

    public function resume(): void
    {
        $this->bypassDepth = max(0, $this->bypassDepth - 1);
    }

    /**
     * Chạy đoạn code trên toàn bộ dữ liệu, bỏ lọc tự động. Chỉ dùng cho tính toán nội bộ cần đủ dữ liệu (không trả
     * thẳng bản ghi ra cho người xem).
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function bypass(Closure $callback): mixed
    {
        $guard = self::current();
        $guard->suspend();
        try {
            return $callback();
        } finally {
            $guard->resume();
        }
    }
}
