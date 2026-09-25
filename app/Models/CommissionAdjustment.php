<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Điều chỉnh hoa hồng của sale (âm = thu hồi khi hoàn phí). Chưa tất toán
 * (settled_at NULL) thì được trừ ở lần tính lương kế tiếp; khi kỳ lương chứa nó
 * được duyệt thì đóng dấu settled_at để không trừ lại.
 */
class CommissionAdjustment extends Model
{
    protected $table = 'commission_adjustments';

    protected $fillable = [
        'user_id',
        'tuition_refund_request_id',
        'student_id',
        'amount',
        'reason',
        'created_by',
        'payroll_record_id',
        'settled_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'settled_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function refundRequest(): BelongsTo
    {
        return $this->belongsTo(TuitionRefundRequest::class, 'tuition_refund_request_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function payrollRecord(): BelongsTo
    {
        return $this->belongsTo(PayrollRecord::class, 'payroll_record_id');
    }
}
