<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Báo cáo họp giáo viên theo tuần (Học thuật ghi): nội dung chuyên môn của một giáo viên / lớp + đề xuất và tình trạng xử lý. */
class TeacherMeetingReport extends Model
{
    use SoftDeletes;

    public const STATUSES = [
        'handled' => 'Đã xử lý',
        'in_progress' => 'Đang xử lý',
        'sketchy' => 'GV báo cáo sơ sài',
        'not_reported' => 'GV chưa báo cáo',
        'duplicate' => 'Báo cáo trùng lặp',
    ];

    public const STATUS_COLORS = [
        'handled' => 'success',
        'in_progress' => 'primary',
        'sketchy' => 'warning',
        'not_reported' => 'error',
        'duplicate' => 'secondary',
    ];

    protected $fillable = [
        'week_start', 'teacher_id', 'class_id', 'author_id',
        'syllabus_note', 'scores_note', 'class_note', 'academic_order', 'recommendation', 'status',
    ];

    protected $casts = [
        'week_start' => 'date',
    ];

    /** Báo cáo không gắn lớp, hoặc gắn lớp nằm trong phạm vi Lớp học của người xem. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull('class_id')
            ->orWhereHas('classModel', fn (Builder $c) => $c->visibleTo($user)));
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function classModel(): BelongsTo
    {
        return $this->belongsTo(ClassModel::class, 'class_id')->withTrashed();
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
