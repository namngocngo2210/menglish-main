<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SyllabusCurriculum extends Model
{
    use HasFactory;

    protected $table = 'syllabus_curriculums';

    protected $fillable = [
        'code',
        'title',
        'stage_name',
        'unlock_policy',
        'overview_link',
        'course_id',
        'version',
        'file_type',
        'file_size',
        'file_url',
        'description',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    public function units(): HasMany
    {
        return $this->hasMany(SyllabusUnit::class, 'curriculum_id')->orderBy('unit_number');
    }

    public const UNLOCK_POLICIES = [
        'weekly' => 'Mở khóa theo tuần',
        'after_big_test' => 'Hoàn thành Big Test mới được mở',
        'manual' => 'Mở khóa thủ công bởi Admin',
    ];

    public function documents(): HasMany
    {
        return $this->hasMany(SyllabusDocument::class, 'curriculum_id')->latest();
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(SyllabusChangeProposal::class, 'curriculum_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(SyllabusAssignment::class, 'curriculum_id');
    }
}
