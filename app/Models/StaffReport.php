<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StaffReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'class_id',
        'type',
        'kind',
        'title',
        'content',
        'data',
        'severity',
        'kpi_code',
        'serious_confirmed_by',
        'serious_confirmed_at',
        'reviewed_by',
        'reviewed_at',
        'review_note',
        'report_date',
        'period_key',
        'status',
    ];

    protected $casts = [
        'report_date' => 'date',
        'data' => 'array',
        'serious_confirmed_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public const TYPE_LABELS = [
        'journal' => 'Nhật ký sự vụ',
        'daily' => 'Báo cáo ngày',
        'weekly' => 'Báo cáo tuần',
        'monthly' => 'Báo cáo tháng',
        'quarterly' => 'Báo cáo quý',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Lớp mà sự vụ thuộc về (null = sự vụ chung của cơ sở). */
    public function classModel(): BelongsTo
    {
        return $this->belongsTo(ClassModel::class, 'class_id')->withTrashed();
    }

    /** Admin đã xác nhận sự việc trong nhật ký là "Lỗi nghiêm trọng" (null = chưa xác nhận). */
    public function seriousConfirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'serious_confirmed_by');
    }

    /** Admin đã kiểm tra báo cáo (null = chờ kiểm tra). */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function followups(): HasMany
    {
        return $this->hasMany(StaffReportFollowup::class)->latest();
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPE_LABELS[$this->type] ?? $this->type;
    }

    public function getSeverityLabelAttribute(): string
    {
        return match ($this->severity) {
            'urgent' => 'Khẩn cấp',
            'important' => 'Quan trọng',
            'normal' => 'Bình thường',
            default => '—',
        };
    }
}
