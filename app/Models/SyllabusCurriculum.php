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

    /**
     * Mỗi giáo trình luôn có ít nhất 1 chặng: tạo giáo trình là tạo sẵn "Chặng 1"
     * (tên lấy từ ô Chặng học khi tạo, nếu có).
     */
    protected static function booted(): void
    {
        static::created(function (self $curriculum) {
            $curriculum->stages()->create([
                'position' => 1,
                'name' => trim((string) $curriculum->stage_name) !== '' ? $curriculum->stage_name : 'Chặng 1',
                'overview_link' => $curriculum->overview_link,
            ]);
        });
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    public function units(): HasMany
    {
        return $this->hasMany(SyllabusUnit::class, 'curriculum_id')->orderBy('unit_number');
    }

    /** Chặng theo thứ tự học. */
    public function stages(): HasMany
    {
        return $this->hasMany(SyllabusStage::class, 'curriculum_id')->orderBy('position')->orderBy('id');
    }

    /** Toàn bộ buổi của giáo trình theo số buổi. */
    public function lessons(): HasMany
    {
        return $this->hasMany(SyllabusLesson::class, 'curriculum_id')->orderBy('session_no');
    }

    /** Trình độ dùng giáo trình này (course_levels.syllabus_curriculum_id). */
    public function levels(): HasMany
    {
        return $this->hasMany(CourseLevel::class, 'syllabus_curriculum_id');
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
