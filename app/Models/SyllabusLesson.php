<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Buổi học trong giáo trình (nội dung từng buổi) — cấp thấp nhất của Giáo trình → Chặng → Unit → Buổi.
 *
 * Đánh số duy nhất: `session_no` là số buổi trong TOÀN giáo trình (UNIQUE curriculum_id + session_no),
 * nên "buổi thứ N của lớp" ↔ buổi có session_no = N. Unit chỉ nhóm các buổi (Unit có `unit_number` riêng).
 * Màn soạn syllabus, màn GV xem nội dung buổi và mọi nội dung theo buổi đều dùng cặp (unit_number, session_no) này.
 */
class SyllabusLesson extends Model
{
    protected $table = 'syllabus_lessons';

    protected $fillable = [
        'curriculum_id',
        'unit_id',
        'session_no',
        'title',
        'objectives',
        'content',
        'vocabulary_focus',
        'grammar_focus',
        'homework_guide',
    ];

    protected $casts = [
        'session_no' => 'integer',
    ];

    protected static function booted(): void
    {
        // curriculum_id luôn khớp với Unit để ràng buộc UNIQUE (curriculum_id, session_no) có hiệu lực.
        static::saving(function (self $lesson) {
            if ($lesson->unit_id && ($lesson->isDirty('unit_id') || ! $lesson->curriculum_id)) {
                $lesson->curriculum_id = SyllabusUnit::whereKey($lesson->unit_id)->value('curriculum_id');
            }
        });
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(SyllabusUnit::class, 'unit_id');
    }

    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(SyllabusCurriculum::class, 'curriculum_id');
    }
}
