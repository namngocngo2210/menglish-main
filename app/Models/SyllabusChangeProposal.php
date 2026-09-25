<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Đề xuất sửa giáo trình: giáo viên gửi, Học thuật duyệt/từ chối (kèm lý do).
 */
class SyllabusChangeProposal extends Model
{
    public const STATUS_LABELS = [
        'pending' => 'Chờ duyệt',
        'approved' => 'Đã duyệt',
        'rejected' => 'Đã từ chối',
    ];

    public const ATTACHMENT_DISK = 'local';

    public const ATTACHMENT_DIRECTORY = 'syllabus_proposals';

    protected $fillable = [
        'curriculum_id',
        'unit_id',
        'lesson_id',
        'user_id',
        'proposal_type',
        'old_content',
        'new_content',
        'reason',
        'attachment_path',
        'attachment_name',
        'status',
        'reviewer_id',
        'reviewed_at',
        'review_note',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(SyllabusCurriculum::class, 'curriculum_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(SyllabusUnit::class, 'unit_id');
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(SyllabusLesson::class, 'lesson_id');
    }

    /** "Buổi 3: … (Unit 2)", "Unit 2: …" hoặc "Chung toàn giáo trình". */
    public function getTargetLabelAttribute(): string
    {
        if ($this->lesson) {
            return 'Buổi '.$this->lesson->session_no.': '.$this->lesson->title.($this->unit ? ' (Unit '.$this->unit->unit_number.')' : '');
        }

        return $this->unit ? 'Unit '.$this->unit->unit_number.': '.$this->unit->title : 'Chung toàn giáo trình';
    }

    public function proposer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /** Người duyệt xem mọi đề xuất; người khác chỉ xem đề xuất của mình. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->can('syllabus.approve_adjustment') ? $query : $query->where('user_id', $user->id);
    }

    public function isVisibleTo(User $user): bool
    {
        return $user->can('syllabus.approve_adjustment') || (int) $this->user_id === (int) $user->id;
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? (string) $this->status;
    }

    public function getStatusColorAttribute(): string
    {
        return ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'error'][$this->status] ?? 'neutral';
    }
}
