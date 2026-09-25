<?php

namespace App\Models;

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
