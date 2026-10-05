<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KpiEvaluation extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'evaluator_id',
        'month',
        'year',
        'total_score',
        'comment',
        'strengths',
        'improvements',
        'next_actions',
        'status',
        'reject_reason',
        'decided_at',
    ];

    /** Trạng thái phiếu: draft = chờ duyệt (phiếu tự tạo đầu tháng), confirmed = đã duyệt (vào bảng lương), rejected = không duyệt. */
    public const STATUS_PENDING = 'draft';

    public const STATUS_APPROVED = 'confirmed';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_LABELS = [
        self::STATUS_PENDING => 'Chờ duyệt',
        self::STATUS_APPROVED => 'Đã duyệt',
        self::STATUS_REJECTED => 'Không duyệt',
    ];

    public const STATUS_COLORS = [
        self::STATUS_PENDING => 'warning',
        self::STATUS_APPROVED => 'success',
        self::STATUS_REJECTED => 'error',
    ];

    protected $casts = [
        'total_score' => 'decimal:2',
        'decided_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function evaluator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluator_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(KpiEvaluationItem::class);
    }

    /**
     * Xếp loại tháng theo mockup "Tổng hợp KPI & Đánh giá tháng" (tiêu chuẩn Khá 85–94%).
     * Các mốc A ≥ 95%, C 70–84%, D < 70% do dev đặt — chờ BA xác nhận.
     *
     * @return array{0: string, 1: string, 2: string} [hạng, nhãn, khoảng]
     */
    public static function gradeFor(float $score): array
    {
        return match (true) {
            $score >= 95 => ['A', 'Xuất sắc', '95–100%'],
            $score >= 85 => ['B', 'Khá', '85–94%'],
            $score >= 70 => ['C', 'Đạt', '70–84%'],
            default => ['D', 'Cần cải thiện', 'dưới 70%'],
        };
    }
}
