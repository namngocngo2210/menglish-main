<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourseLevel extends Model
{
    use HasFactory;

    protected $table = 'course_levels';

    protected $fillable = [
        'code',
        'name',
        'target',
        'duration',
        'lessons_count',
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
}
