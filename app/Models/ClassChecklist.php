<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Checklist học phí & feedback Big Test của một lớp trong một tháng (`month` = YYYY-MM). Mỗi mục là Có / Không / N-A
 * (null = chưa đánh dấu); dùng làm căn cứ chấm các mục KPI Học vụ "Nhắc học phí", "Thu học phí", "Feedback Big Test".
 */
class ClassChecklist extends Model
{
    public const YES = 'yes';

    public const NO = 'no';

    public const NA = 'na';

    public const ANSWERS = [self::YES => 'Có', self::NO => 'Không', self::NA => 'N-A'];

    /** Các cột Có / Không / N-A theo thứ tự mockup, chia 2 nhóm Học phí / Big Test. */
    public const ITEMS = [
        'tuition_due' => 'Đến hạn nhắc học phí?',
        'tuition_reminded' => 'Đã nhắc đúng quy trình?',
        'tuition_collected' => 'Thu đúng học phí?',
        'big_test_due' => 'Đến mốc Big Test?',
        'feedback_on_time' => 'Feedback đúng hạn?',
        'negative_feedback_handled' => 'Feedback tiêu cực đã xử lý?',
    ];

    /** Mục "đến hạn" → các mục phải làm khi đến hạn (đến hạn = Có mà mục sau = Không → lớp bị đánh dấu thiếu sót). */
    public const FOLLOW_UPS = [
        'tuition_due' => ['tuition_reminded', 'tuition_collected'],
        'big_test_due' => ['feedback_on_time', 'negative_feedback_handled'],
    ];

    protected $fillable = [
        'class_id', 'month', 'tuition_due', 'tuition_reminded', 'tuition_collected', 'tuition_note',
        'big_test_due', 'feedback_on_time', 'negative_feedback_handled', 'updated_by',
    ];

    public function classModel(): BelongsTo
    {
        return $this->belongsTo(ClassModel::class, 'class_id')->withTrashed();
    }

    /** Các mục bị thiếu: đến hạn (Có) nhưng mục cần làm đánh "Không". */
    public function gaps(): array
    {
        $gaps = [];
        foreach (self::FOLLOW_UPS as $due => $items) {
            if ($this->{$due} !== self::YES) {
                continue;
            }
            foreach ($items as $item) {
                if ($this->{$item} === self::NO) {
                    $gaps[] = $item;
                }
            }
        }

        return $gaps;
    }
}
