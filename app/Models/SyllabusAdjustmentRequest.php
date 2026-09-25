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
        'syllabus_assignment_id',
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

    /** Hạn xử lý (SLA) của Học thuật: 24 giờ kể từ khi GV gửi (theo thông báo SLA trên màn GV). */
    public const SLA_HOURS = 24;

    protected $casts = [
        'extra_sessions' => 'integer',
        'reviewed_at' => 'datetime',
    ];

    public function getSlaDueAtAttribute(): ?\Illuminate\Support\Carbon
    {
        return $this->created_at?->copy()->addHours(self::SLA_HOURS);
    }

    /** Còn chờ duyệt và đã quá hạn SLA. */
    public function isSlaOverdue(): bool
    {
        return $this->status === 'pending' && $this->sla_due_at !== null && $this->sla_due_at->isPast();
    }

    /** "Lớp A - Chặng 2: …" (chặng đang mở khi gửi yêu cầu). */
    public function getClassStageLabelAttribute(): string
    {
        return trim(($this->classModel?->name ?? 'Lớp #'.$this->class_id).($this->assignment ? ' - '.$this->assignment->stage_name : ''));
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? (string) $this->status;
    }

    public function classModel(): BelongsTo
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }

    /** Chặng đang mở của lớp tại thời điểm gửi yêu cầu giãn tiến độ. */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(SyllabusAssignment::class, 'syllabus_assignment_id');
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
