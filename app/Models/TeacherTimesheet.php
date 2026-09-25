<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeacherTimesheet extends Model
{
    use HasFactory;

    protected $table = 'teacher_timesheets';

    protected $fillable = [
        'user_id',
        'class_id',
        'class_session_id',
        'teaching_date',
        'scheduled_time',
        'checkin_time',
        'hours',
        'hourly_rate',
        'type',
        'status',
        'reviewed_by',
        'reviewed_at',
        'rejection_reason',
        'notes',
    ];

    protected $casts = [
        'teaching_date' => 'date',
        'hours' => 'decimal:2',
        'hourly_rate' => 'decimal:2',
        'reviewed_at' => 'datetime',
    ];

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function classModel(): BelongsTo
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }

    public function classSession(): BelongsTo
    {
        return $this->belongsTo(ClassSession::class, 'class_session_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** Đơn giá mặc định khi cả ca dạy lẫn nhân sự đều chưa cấu hình. */
    public const DEFAULT_HOURLY_RATE = 250000;

    /**
     * Đơn giá áp dụng khi tính lương: timesheet.hourly_rate → user.hourly_rate → mặc định.
     */
    public function effectiveHourlyRate(?User $user = null): float
    {
        if ((float) $this->hourly_rate > 0) {
            return (float) $this->hourly_rate;
        }

        $user ??= $this->teacher;

        return (float) ($user?->hourly_rate) > 0 ? (float) $user->hourly_rate : self::DEFAULT_HOURLY_RATE;
    }

    public function getTypeLabelAttribute(): string
    {
        return match ($this->type) {
            'regular' => 'Ca dạy chính khóa',
            'sub' => 'Dạy thay (Sub)',
            '1on1' => 'Kèm phụ đạo 1-1',
            'grading' => 'Chấm bài thi Test',
            'workshop' => 'Workshop / Sự kiện',
            default => $this->type,
        };
    }
}
