<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'class_report_student_support_id',
        'class_id',
        'student_id',
        'teacher_id',
        'scheduled_by',
        'class_session_id',
        'session_date',
        'start_time',
        'end_time',
        'room',
        'status',
        'reason',
        'completion_note',
        'completed_at',
    ];

    protected $casts = [
        'session_date' => 'date',
        'completed_at' => 'datetime',
    ];

    public function classModel(): BelongsTo
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function scheduler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scheduled_by');
    }

    /** Dòng trong danh sách bổ trợ mà buổi này xử lý (nếu xếp từ danh sách). */
    public function supportItem(): BelongsTo
    {
        return $this->belongsTo(ClassReportStudentSupport::class, 'class_report_student_support_id');
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'scheduled' => 'Đã xếp lịch',
            'completed' => 'Hoàn thành',
            'cancelled' => 'Đã hủy',
            default => (string) $this->status,
        };
    }

    public function classSession(): BelongsTo
    {
        return $this->belongsTo(ClassSession::class);
    }
}
