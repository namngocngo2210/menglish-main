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
        'title',
        'content',
        'severity',
        'report_date',
        'status',
    ];

    protected $casts = [
        'report_date' => 'date',
    ];

    public const TYPE_LABELS = [
        'journal' => 'Nhật ký sự vụ',
        'daily' => 'Báo cáo ngày',
        'weekly' => 'Báo cáo tuần',
        'monthly' => 'Báo cáo tháng',
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
