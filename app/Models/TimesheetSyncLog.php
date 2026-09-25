<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Một đợt đồng bộ chấm công từ thiết bị / AppSheet. Chưa có tích hợp nào ghi bảng này (xem Phần D Phase 3):
 * khi có, đợt đồng bộ ghi records_count (tổng dòng), matched_count (thành công), failed_count (lỗi),
 * skipped_count (bỏ qua vì kỳ lương đã chốt), lỗi hệ thống (error_code / error_message) và error_rows.
 */
class TimesheetSyncLog extends Model
{
    use HasFactory;

    public const STATUS_LABELS = [
        'success' => 'Thành công',
        'partial' => 'Lỗi một phần',
        'failed' => 'Lỗi toàn bộ',
    ];

    protected $table = 'timesheet_sync_logs';

    protected $fillable = [
        'branch_id',
        'device_name',
        'device_ip',
        'source',
        'records_count',
        'matched_count',
        'failed_count',
        'skipped_count',
        'status',
        'error_code',
        'error_message',
        'error_rows',
    ];

    protected $casts = [
        'error_rows' => 'array',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** Trạng thái chuẩn hóa: 'error' cũ = lỗi toàn bộ. */
    public function getNormalizedStatusAttribute(): string
    {
        return $this->status === 'error' ? 'failed' : (string) $this->status;
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->normalized_status] ?? (string) $this->status;
    }

    public function hasErrorDetails(): bool
    {
        return filled($this->error_message) || ! empty($this->error_rows);
    }
}
