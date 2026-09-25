<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Buổi học thử của khách (lead) trong một buổi học thật của lớp.
 * Học thử là hoạt động trong giai đoạn tư vấn — KHÔNG phải stage pipeline,
 * phản hồi của giáo viên được lưu gắn với lead (customer_id), không phải học viên.
 */
class CrmTrialBooking extends Model
{
    public const MAX_ACTIVE_PER_LEAD = 2;

    public const STATUSES = [
        'scheduled' => 'Đã hẹn',
        'attended' => 'Đã học thử',
        'no_show' => 'Vắng mặt',
        'cancelled' => 'Đã hủy',
    ];

    protected $fillable = [
        'customer_id',
        'class_id',
        'class_session_id',
        'booked_by',
        'status',
        'notes',
        'rating',
        'feedback',
        'feedback_by',
        'feedback_at',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'feedback_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(CrmCustomer::class, 'customer_id');
    }

    public function classModel(): BelongsTo
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ClassSession::class, 'class_session_id');
    }

    public function bookedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'booked_by');
    }

    public function feedbackBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'feedback_by');
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
