<?php

namespace App\Models;

use App\Services\DocumentCodeGenerator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Course extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'courses';

    protected $fillable = [
        'code',
        'name',
        'course_level_id',
        'tuition_fee',
        'total_lessons',
        'description',
        'is_active',
    ];

    protected $casts = [
        'tuition_fee' => 'decimal:2',
        'total_lessons' => 'integer',
        'is_active' => 'boolean',
    ];

    /** Mã khóa học do hệ thống sinh (KHOA-0001) khi tạo mà không truyền mã. */
    protected static function booted(): void
    {
        static::creating(function (Course $course) {
            if (blank($course->code)) {
                $course->code = app(DocumentCodeGenerator::class)->courseCode();
            }
        });
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(CourseLevel::class, 'course_level_id');
    }

    public function classes(): HasMany
    {
        return $this->hasMany(ClassModel::class, 'course_id');
    }

    public function curriculums(): HasMany
    {
        return $this->hasMany(SyllabusCurriculum::class, 'course_id');
    }
}
