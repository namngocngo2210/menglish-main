<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TuitionRefundRequest extends Model
{
    use HasFactory;

    protected $table = 'tuition_refund_requests';

    protected $fillable = [
        'student_id',
        'type',
        'total_paid',
        'attended_lessons',
        'admin_fee',
        'refund_amount',
        'target_student_id',
        'reason',
        'requester_id',
        'approver_id',
        'status',
        'clawback_commission',
        'clawback_amount',
        'clawback_user_id',
        'approved_at',
    ];

    protected $casts = [
        'total_paid' => 'decimal:2',
        'admin_fee' => 'decimal:2',
        'refund_amount' => 'decimal:2',
        'attended_lessons' => 'integer',
        'clawback_commission' => 'boolean',
        'clawback_amount' => 'decimal:2',
        'approved_at' => 'datetime',
    ];

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

    /** Sale bị thu hồi hoa hồng (nếu người duyệt chọn thu hồi). */
    public function clawbackUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'clawback_user_id');
    }
}
