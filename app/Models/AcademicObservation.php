<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Đánh giá dự giờ học thuật của một lớp trong một tháng (`month` = YYYY-MM, mỗi lớp tối đa 1 bản ghi / tháng).
 * Lớp chưa dự giờ trong tháng vẫn lưu được % chuyên cần / % đạt yêu cầu (`observed` = false, các tiêu chí để trống).
 */
class AcademicObservation extends Model
{
    /** 6 tiêu chí đánh giá theo thứ tự mockup. */
    public const CRITERIA = [
        'teaching_quality' => 'Chất lượng giảng dạy',
        'lesson_content' => 'Nội dung bài học',
        'interaction' => 'Kỹ năng tương tác',
        'attitude' => 'Thái độ',
        'effectiveness' => 'Hiệu quả lớp học',
        'overall' => 'Kết quả tổng kết',
    ];

    protected $fillable = [
        'class_id', 'month', 'attendance_rate', 'pass_rate', 'observed', 'observed_on', 'observer_id',
        'teaching_quality', 'lesson_content', 'interaction', 'attitude', 'effectiveness', 'overall', 'action_notes',
        'updated_by',
    ];

    protected $casts = [
        'observed' => 'boolean',
        'observed_on' => 'date',
        'attendance_rate' => 'float',
        'pass_rate' => 'float',
    ];

    public function classModel(): BelongsTo
    {
        return $this->belongsTo(ClassModel::class, 'class_id')->withTrashed();
    }

    public function observer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'observer_id');
    }
}
