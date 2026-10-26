<?php

namespace App\Models;

use App\Models\Concerns\AuditsChanges;
use App\Models\Concerns\LogsCurriculumId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Buổi học trong giáo trình (nội dung từng buổi) — cấp thấp nhất của Giáo trình → Chặng → Unit → Buổi.
 *
 * Đánh số duy nhất: `session_no` là số buổi trong TOÀN giáo trình (UNIQUE curriculum_id + session_no),
 * nên "buổi thứ N của lớp" ↔ buổi có session_no = N. Unit chỉ nhóm các buổi (Unit có `unit_number` riêng).
 * Màn soạn syllabus, màn GV xem nội dung buổi và mọi nội dung theo buổi đều dùng cặp (unit_number, session_no) này.
 */
class SyllabusLesson extends Model
{
    use AuditsChanges, LogsCurriculumId, SoftDeletes;

    protected $table = 'syllabus_lessons';

    /** Loại buổi theo form syllabus mẫu (Sheet 1 "Loại buổi"). */
    public const TYPES = ['lesson' => 'Học', 'review' => 'Ôn tập', 'grammar' => 'Grammar Basic', 'mini_test' => 'Mini test', 'big_test' => 'Big test'];

    /** Mốc thông báo của buổi (Sheet 1 "Mốc thông báo") — giai đoạn 4 dùng để nhắc order. */
    public const MILESTONES = ['order_print' => 'Order in ấn', 'order_mini_test' => 'Order mini test', 'order_big_test' => 'Order Big Test', 'notify_academic' => 'Báo Học thuật chuẩn bị'];

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
        'lesson_type',
        'procedure',
        'activity_1',
        'activity_2',
        'homework_quizizz',
        'homework_video',
        'ppt_url',
        'ppt_foreign_url',
        'quizizz_url',
        'test_url',
        'output_product',
        'notice_milestone',
        'note',
    ];

    protected $attributes = [
        'lesson_type' => 'lesson',
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

        // Buổi đã xóa mềm nhả số buổi (session_no = -id) để UNIQUE (curriculum_id, session_no) không chặn buổi mới cùng số.
        static::softDeleted(function (self $lesson) {
            static::withTrashed()->whereKey($lesson->getKey())->update(['session_no' => -$lesson->getKey()]);
        });
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(SyllabusUnit::class, 'unit_id');
    }

    /** Tài liệu gắn riêng buổi này (Kho syllabus). */
    public function documents(): HasMany
    {
        return $this->hasMany(SyllabusDocument::class, 'syllabus_lesson_id');
    }

    /** Bài giảng PowerPoint tải lên của buổi (PPT GV / PPT GVNN), trình chiếu bằng iframe. */
    public function slides(): HasMany
    {
        return $this->hasMany(SyllabusLessonSlide::class, 'syllabus_lesson_id');
    }

    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(SyllabusCurriculum::class, 'curriculum_id');
    }
}
