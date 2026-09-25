<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TuitionRefundRequest extends Model
{
    use HasFactory;

    public const TYPE_REFUND = 'refund';

    public const TYPE_TRANSFER = 'transfer';

    /** Khất nợ: duyệt xong dời hạn đóng và tạm dừng nhắc nợ tới hạn mới. */
    public const TYPE_EXTENSION = 'extension';

    /** Bảo lưu: duyệt xong học viên sang "Bảo lưu", đóng băng số buổi còn lại & công nợ trong thời gian bảo lưu. */
    public const TYPE_DEFERRAL = 'deferral';

    public const TYPES = [
        self::TYPE_REFUND => 'Hoàn phí',
        self::TYPE_TRANSFER => 'Chuyển nhượng',
        self::TYPE_EXTENSION => 'Khất nợ',
        self::TYPE_DEFERRAL => 'Bảo lưu',
    ];

    protected $table = 'tuition_refund_requests';

    protected $fillable = [
        'student_id',
        'type',
        'total_paid',
        'attended_lessons',
        'admin_fee',
        'refund_amount',
        'extended_due_date',
        'defer_from',
        'defer_to',
        'target_student_id',
        'reason',
        'requester_id',
        'approver_id',
        'status',
    ];

    protected $casts = [
        'total_paid' => 'decimal:2',
        'admin_fee' => 'decimal:2',
        'refund_amount' => 'decimal:2',
        'attended_lessons' => 'integer',
        'extended_due_date' => 'date',
        'defer_from' => 'date',
        'defer_to' => 'date',
    ];

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? (string) $this->type;
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function targetStudent(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'target_student_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}
