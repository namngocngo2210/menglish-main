<?php

namespace App\Models;

use App\Services\SupportListService;
use Illuminate\Database\Eloquent\Builder;
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
        // Buổi có mặt / đi muộn đầu tiên = ngày bắt đầu vào học của học viên (hồ sơ, thâm niên).
        static::saved(function (StudentAttendance $attendance) {
            if ($attendance->student_id && $attendance->session_date && in_array($attendance->status, ['present', 'late'], true)) {
                Student::recordStudyStart((int) $attendance->student_id, $attendance->session_date);
            }
        });
        static::deleted(fn (StudentAttendance $attendance) => app(SupportListService::class)->forget(SupportListService::SOURCE_ATTENDANCE, $attendance->id));
    }

    /**
     * Điểm danh được tính vào chuyên cần (cổng học viên / phụ huynh, số buổi đã học của hồ sơ): mọi dòng trừ dòng Học vụ
     * trả lại để sửa. Trước đây chỉ tính dòng đã duyệt nên Học vụ chưa rà soát thì phụ huynh thấy chuyên cần 0.
     */
    public function scopeCounted(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull('review_status')->orWhere('review_status', '!=', 'rejected'));
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
