<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Một khoản hoa hồng tuyển sinh = một phiếu thu (khách mới) × % bậc của kỳ phát sinh.
 * Trạng thái: deferred (chưa đạt gate kép, chờ kỳ sau) → payable (đã gắn vào phiếu lương kỳ chưa duyệt)
 * → paid (kỳ trả đã duyệt, settled_at). void = phiếu bị hủy / hoàn phí có thu hồi trước khi trả.
 */
class CommissionItem extends Model
{
    public const STATUS_DEFERRED = 'deferred';

    public const STATUS_PAYABLE = 'payable';

    public const STATUS_PAID = 'paid';

    public const STATUS_VOID = 'void';

    public const STATUS_LABELS = [
        self::STATUS_DEFERRED => 'Hoãn — chờ đủ điều kiện',
        self::STATUS_PAYABLE => 'Trả trong kỳ',
        self::STATUS_PAID => 'Đã trả',
        self::STATUS_VOID => 'Hủy',
    ];

    protected $table = 'commission_items';

    protected $fillable = [
        'user_id',
        'tuition_receipt_id',
        'student_id',
        'crm_customer_id',
        'base_amount',
        'percent',
        'amount',
        'closed_count',
        'earned_period_start',
        'earned_period_end',
        'closed_at',
        'status',
        'deferred_reason',
        'payroll_record_id',
        'settled_at',
    ];

    protected $casts = [
        'base_amount' => 'decimal:2',
        'percent' => 'decimal:2',
        'amount' => 'decimal:2',
        'closed_count' => 'integer',
        'earned_period_start' => 'date',
        'earned_period_end' => 'date',
        'closed_at' => 'datetime',
        'settled_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(TuitionReceipt::class, 'tuition_receipt_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(CrmCustomer::class, 'crm_customer_id');
    }

    public function payrollRecord(): BelongsTo
    {
        return $this->belongsTo(PayrollRecord::class, 'payroll_record_id');
    }

    /** Chưa trả (còn có thể hoãn / trả / hủy). */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('settled_at')->where('status', '!=', self::STATUS_VOID);
    }

    /**
     * Đã chi cho sale: kỳ trả đã duyệt (settled_at) hoặc phiếu lương chứa khoản thuộc kỳ đã khóa.
     */
    public function isPaidOut(): bool
    {
        if ($this->settled_at !== null) {
            return true;
        }

        return $this->payrollRecord?->period?->isLocked() ?? false;
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }
}
