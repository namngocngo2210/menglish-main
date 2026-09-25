<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BigTestResult extends Model
{
    use HasFactory;

    public const STATUS_LABELS = [
        'draft' => 'Nháp',
        'pending_review' => 'Chờ duyệt',
        'approved' => 'Đã duyệt',
        'sent' => 'Đã gửi phụ huynh',
    ];

    /** Kết quả đã duyệt/đã gửi phụ huynh thì không được chấm lại. */
    public const LOCKED_STATUSES = ['approved', 'sent'];

    protected $table = 'big_test_results';

    protected $fillable = [
        'big_test_id',
        'student_id',
        'listening_score',
        'reading_score',
        'writing_score',
        'speaking_score',
        'overall_score',
        'is_absent',
        'progress_note',
        'video_url',
        'status',
        'graded_by',
        'approved_by',
        'approved_at',
        'parent_notified',
        'notified_at',
    ];

    protected $casts = [
        'listening_score' => 'decimal:1',
        'reading_score' => 'decimal:1',
        'writing_score' => 'decimal:1',
        'speaking_score' => 'decimal:1',
        'overall_score' => 'decimal:1',
        'is_absent' => 'boolean',
        'parent_notified' => 'boolean',
        'notified_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public function test(): BelongsTo
    {
        return $this->belongsTo(BigTest::class, 'big_test_id');
    }

    public function bigTest(): BelongsTo
    {
        return $this->belongsTo(BigTest::class, 'big_test_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function grader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'graded_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isLocked(): bool
    {
        return in_array($this->status, self::LOCKED_STATUSES, true);
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? (string) $this->status;
    }
}
