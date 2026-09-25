<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClassModel extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'classes';

    protected $fillable = [
        'code',
        'name',
        'course_id',
        'program',
        'level',
        'branch_id',
        'teacher_id',
        'assistant_id',
        'foreign_teacher_id',
        'room',
        'schedule_text',
        'start_date',
        'end_date',
        'max_capacity',
        'tuition_fee',
        'notes',
        'status',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'max_capacity' => 'integer',
    ];

    /**
     * Lớp mà người dùng được xem: nhân sự quản lý lớp (class.create/update) thấy
     * tất cả; giáo viên / GVNN / trợ giảng chỉ thấy lớp mình phụ trách hoặc có
     * buổi dạy; vai trò khác (học viên...) không thấy lớp nào.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if (self::userManagesAll($user)) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($user) {
            $query->where('teacher_id', $user->id)
                ->orWhere('assistant_id', $user->id)
                ->orWhere('foreign_teacher_id', $user->id)
                ->orWhereHas('sessions', function (Builder $sessions) use ($user) {
                    $sessions->forStaff($user->id);
                });
        });
    }

    /** Trạng thái học viên còn giữ chỗ trong lớp (tính vào sĩ số). */
    public const SEAT_HOLDING_STUDENT_STATUSES = ['waiting_start', 'studying', 'summer_break'];

    /** Trạng thái bàn giao xếp lớp còn hiệu lực (khớp CRM: pending/completed). */
    public const ACTIVE_ENROLLMENT_STATUSES = ['pending', 'completed'];

    /**
     * Số học viên đang giữ chỗ: hợp của bàn giao xếp lớp còn hiệu lực và học viên có
     * current_class_id là lớp này (hai nguồn có thể lệch nhau — xem audit A4 #7), chỉ tính
     * học viên chưa thôi học/hoàn thành/bảo lưu.
     */
    public function occupiedSeats(): int
    {
        $viaEnrollments = $this->enrollments()
            ->whereIn('status', self::ACTIVE_ENROLLMENT_STATUSES)
            ->whereHas('student', fn (Builder $q) => $q->whereIn('status', self::SEAT_HOLDING_STUDENT_STATUSES))
            ->pluck('student_id');
        $viaCurrentClass = $this->students()
            ->whereIn('status', self::SEAT_HOLDING_STUDENT_STATUSES)
            ->pluck('id');

        return $viaEnrollments->merge($viaCurrentClass)->map(fn ($id) => (int) $id)->unique()->count();
    }

    /**
     * Số chỗ còn trống; null khi lớp không giới hạn sĩ số (max_capacity trống hoặc 0).
     */
    public function seatsLeft(): ?int
    {
        if ((int) $this->max_capacity <= 0) {
            return null;
        }

        return max(0, (int) $this->max_capacity - $this->occupiedSeats());
    }

    public function isFull(): bool
    {
        return $this->seatsLeft() === 0;
    }

    /** Còn đủ chỗ cho $count học viên nữa không (dùng khi xếp lớp thủ công). */
    public function hasSeatsFor(int $count = 1): bool
    {
        $left = $this->seatsLeft();

        return $left === null || $left >= $count;
    }

    public static function userManagesAll(User $user): bool
    {
        return $user->can('class.create') || $user->can('class.update');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function assistant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assistant_id');
    }

    public function foreignTeacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'foreign_teacher_id');
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class, 'current_class_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(ClassEnrollment::class, 'class_id');
    }

    public function timesheets(): HasMany
    {
        return $this->hasMany(TeacherTimesheet::class, 'class_id');
    }

    public function scheduleConfig()
    {
        return $this->hasOne(ClassScheduleConfig::class, 'class_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(ClassSession::class, 'class_id');
    }

    public function supportSessions(): HasMany
    {
        return $this->hasMany(SupportSession::class, 'class_id');
    }

    public function syllabusAssignments(): HasMany
    {
        return $this->hasMany(SyllabusAssignment::class, 'class_id');
    }
}
