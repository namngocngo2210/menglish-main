<?php

namespace App\Models\Concerns;

use App\Models\Scopes\VisibleToViewer;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Model tự lọc theo phạm vi dữ liệu của người đang đăng nhập (docs/rbac.md, mục "Lọc tự động").
 *
 * Trong request web, mọi truy vấn (danh sách, đếm, findOrFail, route model binding, quan hệ) chỉ thấy bản ghi trong
 * phạm vi: quên lọc vẫn an toàn, mở bản ghi ngoài phạm vi theo id trả 404. Model khai báo luật bằng
 * constrainToViewer(). Bỏ lọc có chủ đích: ->withoutViewerScope() cho một truy vấn, DataScopeGuard::bypass() cho
 * một đoạn tính toán nội bộ.
 */
trait ScopedToViewer
{
    public static function bootScopedToViewer(): void
    {
        static::addGlobalScope(new VisibleToViewer);
    }

    /** Thêm điều kiện "bản ghi người này được thấy" vào truy vấn. */
    abstract public static function constrainToViewer(Builder $query, User $user): void;

    /** Bản ghi một người cụ thể được thấy (thay cho lọc tự động theo người đang đăng nhập). */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        $query->withoutGlobalScope(VisibleToViewer::class);
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }
        static::constrainToViewer($query, $user);

        return $query;
    }

    /** Bỏ lọc tự động cho truy vấn này (tính toán nội bộ, không trả thẳng bản ghi ra cho người xem). */
    public function scopeWithoutViewerScope(Builder $query): Builder
    {
        return $query->withoutGlobalScope(VisibleToViewer::class);
    }
}
