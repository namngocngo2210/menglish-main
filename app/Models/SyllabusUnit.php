<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Unit — nhóm buổi học trong một chặng (Giáo trình → Chặng → Unit → Buổi).
 *
 * Câu hỏi còn mở (Q4 — cấu trúc bảng, chờ BA xác nhận): Unit có cần bảng riêng hay chỉ là số `so_unit` trên buổi.
 * Lựa chọn hiện tại: Unit là bảng riêng nhưng "nhẹ" — chỉ tên, số unit và phần mô tả tổng quan; nội dung dạy
 * nằm ở từng Buổi (SyllabusLesson). Nếu BA chốt Unit chỉ là số thứ tự, có thể gộp bảng này thành cột
 * `unit_number` trên syllabus_lessons mà không đổi cách đánh số (unit_number, session_no).
 *
 * `unit_number` duy nhất trong giáo trình. Các cột objectives/vocabulary_focus/grammar_focus/homework_guide
 * là dữ liệu cũ (trước Q4 mỗi dòng ở đây là một "buổi"); khi chuyển mô hình, nội dung đã được chép sang
 * Buổi cùng số và giữ lại ở đây làm mô tả tổng quan của Unit.
 */
class SyllabusUnit extends Model
{
    use HasFactory;

    protected $table = 'syllabus_units';

    protected $fillable = [
        'curriculum_id',
        'stage_id',
        'unit_number',
        'title',
        'objectives',
        'vocabulary_focus',
        'grammar_focus',
        'homework_guide',
    ];

    protected $casts = [
        'unit_number' => 'integer',
    ];

    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(SyllabusCurriculum::class, 'curriculum_id');
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(SyllabusStage::class, 'stage_id');
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(SyllabusLesson::class, 'unit_id')->orderBy('session_no');
    }
}
