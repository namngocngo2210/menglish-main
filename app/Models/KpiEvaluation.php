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
    ];

    protected $casts = [
        'total_score' => 'decimal:2',
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
