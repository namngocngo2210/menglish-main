<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Student extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'students';

    protected $fillable = [
        'code',
        'user_id',
        'name',
        'phone',
        'email',
        'dob',
        'gender',
        'address',
        'branch_id',
        'current_class_id',
        'target',
        'entrance_score',
        'midterm_score',
        'final_score',
        'attended_lessons',
        'total_lessons',
        'homework_rate',
        'status',
        'notes',
    ];

    protected $casts = [
        'dob' => 'date',
        'attended_lessons' => 'integer',
        'total_lessons' => 'integer',
        'homework_rate' => 'decimal:2',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function currentClass(): BelongsTo
    {
        return $this->belongsTo(ClassModel::class, 'current_class_id');
    }

    public function tuition(): HasOne
    {
        return $this->hasOne(StudentTuition::class, 'student_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(ClassEnrollment::class, 'student_id');
    }

    public function getAttendanceRateAttribute(): string
    {
        if ($this->total_lessons == 0) {
            return '100%';
        }
        $pct = round(($this->attended_lessons / $this->total_lessons) * 100, 1);

        return "{$this->attended_lessons}/{$this->total_lessons} ({$pct}%)";
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'studying' => 'Đang học',
            'graduated' => 'Đã tốt nghiệp',
            'deferred' => 'Bảo lưu',
            'dropped' => 'Rút hồ sơ',
            default => $this->status,
        };
    }

    public function getStatusBadgeAttribute(): string
    {
        return match ($this->status) {
            'studying' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            'graduated' => 'bg-blue-50 text-blue-700 border-blue-200',
            'deferred' => 'bg-amber-50 text-amber-700 border-amber-200',
            'dropped' => 'bg-rose-50 text-rose-700 border-rose-200',
            default => 'bg-gray-50 text-gray-700 border-gray-200',
        };
    }
}
