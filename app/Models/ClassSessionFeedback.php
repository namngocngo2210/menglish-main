<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Góp ý cho một buổi học (chủ dự án 17/10/2026). Hai nguồn:
 * - `parent`: phụ huynh / học viên chấm 1–5 sao trên cổng học viên, mỗi học sinh một phiếu / buổi, sửa được trong 48 giờ.
 * - `staff`: Học vụ góp ý ngay trong báo cáo ngày (Tốt / Cần lưu ý / Có vấn đề + nội dung), không cần dự giờ; mỗi người một
 *   phiếu / buổi. Bấm "Báo Admin gấp" tạo sự việc mức "Khẩn" trong Nhật ký (`escalation_id`).
 * Chỉ Học vụ, Học thuật và Admin xem được; giáo viên không thấy.
 */
class ClassSessionFeedback extends Model
{
    protected $table = 'class_session_feedbacks';

    public const SOURCE_PARENT = 'parent';

    public const SOURCE_STAFF = 'staff';

    /** Phụ huynh sửa được phiếu trong khoảng này kể từ lúc gửi lần đầu. */
    public const PARENT_EDIT_HOURS = 48;

    /** Chấm từ mức này trở xuống là "thấp", hiện ở mục cần chú ý của báo cáo ngày. */
    public const LOW_RATING = 2;

    public const VERDICTS = [
        'good' => 'Tốt',
        'attention' => 'Cần lưu ý',
        'problem' => 'Có vấn đề',
    ];

    public const PARENT_CATEGORIES = [
        'lesson' => 'Nội dung bài',
        'teacher' => 'Giáo viên',
        'assistant' => 'Trợ giảng',
        'timing' => 'Giờ giấc',
        'facilities' => 'Cơ sở vật chất',
        'other' => 'Khác',
    ];

    protected $fillable = [
        'class_session_id',
        'class_id',
        'source',
        'student_id',
        'author_id',
        'rating',
        'verdict',
        'categories',
        'content',
        'staff_report_id',
        'escalation_id',
    ];

    protected $casts = [
        'rating' => 'integer',
        'categories' => 'array',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(ClassSession::class, 'class_session_id');
    }

    public function classModel(): BelongsTo
    {
        return $this->belongsTo(ClassModel::class, 'class_id')->withTrashed();
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function escalation(): BelongsTo
    {
        return $this->belongsTo(StaffReport::class, 'escalation_id');
    }

    public function parentEditable(): bool
    {
        return $this->created_at === null || $this->created_at->copy()->addHours(self::PARENT_EDIT_HOURS)->isFuture();
    }

    /** @return list<string> */
    public function categoryLabels(): array
    {
        return collect($this->categories ?? [])->map(fn ($c) => self::PARENT_CATEGORIES[$c] ?? null)->filter()->values()->all();
    }
}
