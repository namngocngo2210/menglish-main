<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Order đề test của giáo viên (Cổng GV → màn Duyệt & phân phối đề).
 * Học thuật duyệt kèm link đề, hoặc từ chối kèm lý do.
 */
class BigTestOrder extends Model
{
    public const STATUS_LABELS = [
        'pending' => 'Chờ duyệt',
        'approved' => 'Đã duyệt & phân phối',
        'rejected' => 'Đã từ chối',
    ];

    public const TYPE_LABELS = [
        'mini' => 'Mini Test',
        'big' => 'Big Test',
    ];

    /** Đề phải phân phối trước ngày thi tối thiểu N ngày (SLA theo mockup). */
    public const LEAD_DAYS = 3;

    protected $fillable = [
        'code',
        'class_id',
        'teacher_id',
        'stage_name',
        'test_type',
        'exam_date',
        'due_date',
        'note',
        'status',
        'test_link',
        'big_test_id',
        'reviewed_by',
        'reviewed_at',
        'rejection_reason',
    ];

    protected $casts = [
        'exam_date' => 'date',
        'due_date' => 'date',
        'reviewed_at' => 'datetime',
    ];

    public function classModel(): BelongsTo
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function bigTest(): BelongsTo
    {
        return $this->belongsTo(BigTest::class, 'big_test_id');
    }

    /** Học thuật xem mọi order; giáo viên chỉ xem order của lớp mình phụ trách hoặc do mình gửi. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->hasAnyRole(BigTest::ACADEMIC_ROLES)) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q->where('teacher_id', $user->id)
            ->orWhereHas('classModel', fn (Builder $class) => $class->where(
                fn (Builder $c) => $c->where('teacher_id', $user->id)
                    ->orWhere('foreign_teacher_id', $user->id)
                    ->orWhere('assistant_id', $user->id)
            )));
    }

    public function isOverdue(): bool
    {
        return $this->status === 'pending' && $this->due_date !== null && $this->due_date->lt(today());
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? (string) $this->status;
    }

    public function getStatusColorAttribute(): string
    {
        return ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'error'][$this->status] ?? 'neutral';
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPE_LABELS[$this->test_type] ?? (string) $this->test_type;
    }
}
