<?php

namespace App\Models;

use App\Services\MissedLessonService;
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
        'missed_class_session_id',
        'session_date',
        'start_time',
        'end_time',
        'room',
        'status',
        'reason',
        'lesson_content',
        'completion_note',
        'completed_at',
        'cancel_reason',
        'cancelled_at',
    ];

    protected $casts = [
        'session_date' => 'date',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
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

    /** Buổi học sinh vắng mà buổi bổ trợ này dạy lại. */
    public function missedSession(): BelongsTo
    {
        return $this->belongsTo(ClassSession::class, 'missed_class_session_id');
    }

    /**
     * Buổi giáo trình (syllabus_lessons.id) của các buổi học sinh vắng mà $user được giao bổ trợ (buổi chưa hủy) — mở quyền
     * xem tài liệu buổi đó cho người bổ trợ (SyllabusDocument::grantedBySupport).
     *
     * @return array<int, int>
     */
    public static function missedLessonIdsFor(User $user): array
    {
        $sessions = ClassSession::whereIn('id', self::where('teacher_id', $user->id)->where('status', '!=', 'cancelled')
            ->whereNotNull('missed_class_session_id')->select('missed_class_session_id'))->get();

        return collect(app(MissedLessonService::class)->forSessions($sessions))
            ->pluck('lesson_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
    }
}
