<?php

namespace App\Models;

use App\Models\Concerns\AuditsChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bản đè syllabus của 1 lớp cho 1 buổi (docs/kho-syllabus.md §7.1): `data` chỉ chứa các trường bị sửa riêng (FIELDS), kèm
 * lý do. Buổi hiệu lực của lớp = buổi gốc + data (App\Services\Syllabus\ClassSyllabus).
 */
class ClassLessonOverride extends Model
{
    use AuditsChanges;

    /** Trường nội dung Sheet 1 được sửa riêng cho lớp (không gồm số buổi / unit). */
    public const FIELDS = [
        'title', 'lesson_type', 'objectives', 'content', 'procedure', 'activity_1', 'activity_2', 'homework_guide',
        'homework_quizizz', 'homework_video', 'ppt_url', 'ppt_foreign_url', 'quizizz_url', 'test_url', 'output_product',
        'notice_milestone', 'note',
    ];

    protected $fillable = ['class_id', 'syllabus_lesson_id', 'data', 'reason', 'edited_by'];

    protected $casts = ['data' => 'array'];

    public function classModel(): BelongsTo
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(SyllabusLesson::class, 'syllabus_lesson_id');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }
}
