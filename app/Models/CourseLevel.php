<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourseLevel extends Model
{
    use HasFactory;

    protected $table = 'course_levels';

    protected $fillable = [
        'code',
        'name',
        'level_group',
        'target',
        'duration',
        'lessons_count',
        'syllabus_curriculum_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'lessons_count' => 'integer',
    ];

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class, 'course_level_id');
    }

    /** Giáo trình gắn kèm trình độ (tuỳ chọn). */
    public function syllabus(): BelongsTo
    {
        return $this->belongsTo(SyllabusCurriculum::class, 'syllabus_curriculum_id');
    }

    /** Lớp học dùng trình độ này (classes.level lưu mã trình độ). */
    public function classes(): HasMany
    {
        return $this->hasMany(ClassModel::class, 'level', 'code');
    }

    /**
     * Thêm cột classes_count: số lớp (chưa xóa) đang dùng mã trình độ.
     */
    public function scopeWithClassesCount(Builder $query): Builder
    {
        return $query->withCount('classes');
    }
}
