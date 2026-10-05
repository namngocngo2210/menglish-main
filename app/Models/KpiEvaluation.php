<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

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
     * Bộ tiêu chí phiếu này đã được chấm theo. Thường là bộ đang áp dụng ($active); phiếu chấm theo bộ cũ (tiêu chí đã
     * xóa mềm / ngừng dùng, vd sau khi đổi sang 15 tiêu chí mới) → các tiêu chí của chính phiếu, để phiếu đã chốt
     * không bị tính lại thành 0.
     *
     * @param  Collection<int, KpiCriterion>  $active
     * @return array{criteria: Collection<int, KpiCriterion>, legacy: bool}
     */
    public function scoredCriteria(Collection $active): array
    {
        $itemCriterionIds = $this->items->pluck('kpi_criterion_id')->map(fn ($id) => (int) $id);
        if ($itemCriterionIds->isEmpty() || $itemCriterionIds->diff($active->modelKeys())->isEmpty()) {
            return ['criteria' => $active, 'legacy' => false];
        }

        return ['criteria' => KpiCriterion::withTrashed()->whereIn('id', $itemCriterionIds)->ordered()->get(), 'legacy' => true];
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
