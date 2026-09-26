<?php

namespace App\Models;

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
