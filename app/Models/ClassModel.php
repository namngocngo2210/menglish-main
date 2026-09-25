<?php

namespace App\Models;

use App\Models\Concerns\AuditsChanges;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClassModel extends Model
{
    use AuditsChanges, HasFactory, SoftDeletes;

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
                    $sessions->where(fn (Builder $q) => $q->where('teacher_id', $user->id)->orWhere('assistant_id', $user->id));
                });
        });
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
