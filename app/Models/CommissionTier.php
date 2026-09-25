<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Bậc hoa hồng tuyển sinh (khách mới). Mỗi dòng là một PHIÊN BẢN có hiệu lực
 * [effective_from, effective_to]; sửa bậc = tạo phiên bản mới để kỳ lương cũ
 * luôn tính lại được theo đúng mốc đã áp dụng. effective_from NULL = từ đầu.
 *
 * renew_percent: A6 chốt KHÔNG tính hoa hồng tái tục — cột giữ lại cho dữ liệu cũ,
 * không dùng trong tính lương (chờ BA chốt Q3 về thưởng tái tục).
 */
class CommissionTier extends Model
{
    use HasFactory;

    protected $table = 'commission_tiers';

    protected $fillable = [
        'tier_name',
        'min_revenue',
        'max_revenue',
        'new_sale_percent',
        'renew_percent',
        'bonus_amount',
        'effective_from',
        'effective_to',
        'replaces_id',
        'created_by',
    ];

    protected $casts = [
        'min_revenue' => 'decimal:2',
        'max_revenue' => 'decimal:2',
        'new_sale_percent' => 'decimal:2',
        'renew_percent' => 'decimal:2',
        'bonus_amount' => 'decimal:2',
        'effective_from' => 'date',
        'effective_to' => 'date',
    ];

    public function replaces(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Các phiên bản đang hiệu lực tại một ngày (mặc định hôm nay). */
    public function scopeEffectiveAt(Builder $query, CarbonInterface|string|null $date = null): Builder
    {
        $day = Carbon::parse($date ?? today())->toDateString();

        return $query
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $day))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $day));
    }

    public function isEffectiveAt(CarbonInterface|string|null $date = null): bool
    {
        $day = Carbon::parse($date ?? today())->startOfDay();

        return ($this->effective_from === null || $this->effective_from->lte($day))
            && ($this->effective_to === null || $this->effective_to->gte($day));
    }

    /**
     * Bậc hoa hồng áp dụng cho một mức doanh thu tại một thời điểm (mặc định hôm nay):
     * min_revenue <= revenue <= max_revenue, ưu tiên bậc có min_revenue cao nhất, chỉ xét
     * phiên bản hiệu lực tại ngày đó. Dùng chung cho tính lương, BXH KPI và báo cáo CRM.
     */
    public static function matchForRevenue(float $revenue, CarbonInterface|string|null $asOf = null): ?self
    {
        return static::query()
            ->effectiveAt($asOf)
            ->where('min_revenue', '<=', $revenue)
            ->where(function ($query) use ($revenue) {
                $query->whereNull('max_revenue')->orWhere('max_revenue', '>=', $revenue);
            })
            ->orderByDesc('min_revenue')
            ->first();
    }
}
