<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Một lần GV bấm "Đã dạy" (docs/kho-syllabus.md §8.1): dạy xong 1 buổi syllabus, hoặc dùng 1 buổi giãn đã duyệt. */
class ClassLessonProgress extends Model
{
    public const KIND_LESSON = 'lesson';

    public const KIND_EXTRA = 'extra';

    protected $table = 'class_lesson_progress';

    protected $fillable = [
        'syllabus_assignment_id', 'class_id', 'syllabus_lesson_id', 'class_session_id', 'kind', 'pace_note', 'taught_by', 'taught_at',
    ];

    protected $casts = ['taught_at' => 'datetime'];

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(SyllabusAssignment::class, 'syllabus_assignment_id');
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(SyllabusLesson::class, 'syllabus_lesson_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ClassSession::class, 'class_session_id');
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'taught_by');
    }
}
