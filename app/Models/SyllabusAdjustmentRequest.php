<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyllabusAdjustmentRequest extends Model
{
    use HasFactory;

    protected $table = 'syllabus_adjustment_requests';

    protected $fillable = [
        'class_id',
        'user_id',
        'request_type',
        'reason',
        'extra_sessions',
        'approver_id',
        'status',
        'rejection_reason',
        'reviewed_at',
        'applied_note',
    ];

    public const STATUS_LABELS = [
        'pending' => 'Chờ duyệt',
        'approved' => 'Đã duyệt',
        'rejected' => 'Đã từ chối',
    ];

    /** Số buổi tối đa được giãn trong một yêu cầu. */
    public const MAX_EXTRA_SESSIONS = 10;

    protected $casts = [
        'extra_sessions' => 'integer',
        'reviewed_at' => 'datetime',
    ];

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? (string) $this->status;
    }

    public function classModel(): BelongsTo
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}
