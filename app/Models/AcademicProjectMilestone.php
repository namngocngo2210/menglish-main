<?php

namespace App\Models;

use App\Services\Sla\Sla;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Mốc tiến độ của dự án học thuật: hạn (23:59 ngày due_date), khối lượng cần làm (vd 12 unit, 80 trang) và người nhận.
 * Khối lượng đã xong lấy từ lần cập nhật tiến độ gần nhất (tổng đến nay, không cộng dồn để sửa được khi nhập nhầm).
 */
class AcademicProjectMilestone extends Model
{
    use SoftDeletes;

    public const SLA_RULE = 'academic.milestone_late';

    public const SLA_SUBJECT = 'academic_project_milestone';

    public const STATUSES = [
        'todo' => 'Chưa bắt đầu',
        'in_progress' => 'Đang làm',
        'done' => 'Hoàn thành',
    ];

    protected $fillable = [
        'academic_project_id', 'title', 'description', 'assignee_id', 'start_date', 'due_date',
        'target_quantity', 'unit', 'done_quantity', 'status', 'completed_at', 'reminded_at', 'sort_order',
    ];

    protected $casts = [
        'start_date' => 'date',
        'due_date' => 'date',
        'target_quantity' => 'float',
        'done_quantity' => 'float',
        'completed_at' => 'datetime',
        'reminded_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(AcademicProject::class, 'academic_project_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /** Hạn chót: hết ngày due_date. */
    public function dueAt(): Carbon
    {
        return $this->due_date->copy()->endOfDay();
    }

    /** Thời điểm bị tính trễ: hạn chót + số giờ ân hạn của SLA (trang Cấu hình SLA). */
    public function breachAt(): Carbon
    {
        return $this->dueAt()->addHours(Sla::value(self::SLA_RULE));
    }

    public function isDone(): bool
    {
        return $this->status === 'done';
    }

    public function isOverdue(?Carbon $now = null): bool
    {
        $now ??= now();

        return $this->isDone() ? $this->completed_at?->gt($this->dueAt()) ?? false : $this->dueAt()->lt($now);
    }

    public function progressPercent(): int
    {
        if ($this->isDone()) {
            return 100;
        }
        if ($this->target_quantity <= 0) {
            return 0;
        }

        return (int) min(100, floor($this->done_quantity * 100 / $this->target_quantity));
    }

    /** "Trễ hạn" | "Sắp đến hạn" (≤ 3 ngày) | null. */
    public function deadlineState(?Carbon $now = null): ?string
    {
        $now ??= now();
        if ($this->isOverdue($now)) {
            return 'overdue';
        }

        return ! $this->isDone() && $this->dueAt()->lte($now->copy()->addDays(3)) ? 'soon' : null;
    }

    /** 12 / 12,5 → chuỗi ngắn gọn kiểu Việt Nam. */
    public static function formatQuantity(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',');
    }
}
