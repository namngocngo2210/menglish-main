<?php

namespace App\Models;

use App\Services\SupportListService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentAttendance extends Model
{
    use HasFactory;

    protected $table = 'student_attendances';

    protected $fillable = [
        'class_id',
        'class_session_id',
        'student_id',
        'user_id',
        'recorded_by',
        'session_date',
        'status',
        'review_status',
        'reviewed_by',
        'reviewed_at',
        'review_note',
        'note',
    ];

    protected $casts = [
        'session_date' => 'date',
        'reviewed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Vắng học → tự vào danh sách bổ trợ; sửa lại thành có mặt thì gỡ (nếu chưa xếp buổi).
        static::saved(fn (StudentAttendance $attendance) => app(SupportListService::class)->syncAttendance($attendance));
        static::deleted(fn (StudentAttendance $attendance) => app(SupportListService::class)->forget(SupportListService::SOURCE_ATTENDANCE, $attendance->id));
    }

    /** Người thực sự lưu điểm danh (Học vụ/Quản lý khi điểm danh thay giáo viên). */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function classModel(): BelongsTo
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function classSession(): BelongsTo
    {
        return $this->belongsTo(ClassSession::class, 'class_session_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'present' => 'Có mặt',
            'absent' => 'Vắng',
            'late' => 'Đi muộn',
            'excused' => 'Vắng có phép',
            default => $this->status,
        };
    }
}
