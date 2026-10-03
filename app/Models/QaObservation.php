<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Một lượt dự giờ của đội vận hành (QA / Học vụ): quan sát một buổi dạy của giáo viên ở một lớp và xếp loại. */
class QaObservation extends Model
{
    use SoftDeletes;

    public const RATINGS = [
        'excellent' => 'Xuất sắc',
        'good' => 'Tốt',
        'pass' => 'Đạt',
        'needs_improvement' => 'Cần cải thiện',
    ];

    /** Màu badge theo xếp loại. */
    public const RATING_COLORS = [
        'excellent' => 'success',
        'good' => 'primary',
        'pass' => 'secondary',
        'needs_improvement' => 'warning',
    ];

    /** Các mục nội dung quan sát (tùy chọn) theo thứ tự mockup. */
    public const NOTE_FIELDS = [
        'lesson_content' => 'Nội dung bài học',
        'attitude' => 'Tác phong, thái độ',
        'preparation' => 'Chuẩn bị bài giảng',
        'technique' => 'Kỹ thuật giảng dạy (tương tác)',
        'suggestions' => 'Góp ý / Cải thiện',
    ];

    protected $fillable = [
        'class_id', 'teacher_id', 'observer_id', 'observed_on',
        'lesson_content', 'attitude', 'preparation', 'technique', 'suggestions', 'rating',
    ];

    protected $casts = [
        'observed_on' => 'date',
    ];

    public function classModel(): BelongsTo
    {
        return $this->belongsTo(ClassModel::class, 'class_id')->withTrashed();
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function observer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'observer_id');
    }
}
