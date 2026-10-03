<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Số tồn của một mặt hàng tại một chi nhánh (có thể âm khi đã giao hàng mà chưa nhập kho bổ sung). */
class MerchandiseStock extends Model
{
    /** Còn từ ngưỡng này trở xuống (và > 0) thì cảnh báo "Sắp hết". */
    public const LOW_THRESHOLD = 5;

    protected $fillable = ['merchandise_item_id', 'branch_id', 'quantity'];

    protected $casts = ['quantity' => 'integer'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(MerchandiseItem::class, 'merchandise_item_id')->withTrashed();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** Trạng thái cảnh báo theo số tồn: negative | out | low | ok. */
    public static function level(int $quantity): string
    {
        return match (true) {
            $quantity < 0 => 'negative',
            $quantity === 0 => 'out',
            $quantity <= self::LOW_THRESHOLD => 'low',
            default => 'ok',
        };
    }
}
