<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Một dòng nhật ký xuất nhập kho của chi nhánh. quantity_change: + nhập, - xuất. */
class MerchandiseStockMovement extends Model
{
    public const TYPE_IMPORT = 'import';

    public const TYPE_COUNT = 'count';

    public const TYPE_SALE = 'sale';

    public const TYPE_RETURN = 'return';

    public const TYPE_OPENING = 'opening';

    public const TYPE_LABELS = [
        self::TYPE_IMPORT => 'Nhập kho',
        self::TYPE_COUNT => 'Kiểm kê điều chỉnh',
        self::TYPE_SALE => 'Xuất theo phiếu thu',
        self::TYPE_RETURN => 'Hoàn kho (hủy hóa đơn)',
        self::TYPE_OPENING => 'Tồn đầu kỳ',
    ];

    /** Sách / hàng hóa trong hợp đồng học phí (Chốt & Xếp lớp) — xuất 1 lần cho mỗi hợp đồng. */
    public const SOURCE_CONTRACT = 'contract';

    /** Hàng hóa chọn ở phần Phụ thu của phiếu thu. */
    public const SOURCE_SURCHARGE = 'surcharge';

    protected $fillable = [
        'merchandise_item_id',
        'branch_id',
        'type',
        'source',
        'quantity_change',
        'balance_after',
        'tuition_receipt_id',
        'student_tuition_id',
        'user_id',
        'note',
    ];

    protected $casts = [
        'quantity_change' => 'integer',
        'balance_after' => 'integer',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(MerchandiseItem::class, 'merchandise_item_id')->withTrashed();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(TuitionReceipt::class, 'tuition_receipt_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPE_LABELS[$this->type] ?? $this->type;
    }
}
