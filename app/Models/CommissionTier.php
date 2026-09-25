<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
    ];

    protected $casts = [
        'min_revenue' => 'decimal:2',
        'max_revenue' => 'decimal:2',
        'new_sale_percent' => 'decimal:2',
        'renew_percent' => 'decimal:2',
        'bonus_amount' => 'decimal:2',
    ];

    /**
     * Bậc hoa hồng áp dụng cho một mức doanh thu: min_revenue <= revenue <= max_revenue,
     * ưu tiên bậc có min_revenue cao nhất. Dùng chung cho tính lương (PayrollPeriod) và
     * báo cáo CRM để hai nơi luôn chọn cùng một bậc.
     */
    public static function matchForRevenue(float $revenue): ?self
    {
        return static::query()
            ->where('min_revenue', '<=', $revenue)
            ->where(function ($query) use ($revenue) {
                $query->whereNull('max_revenue')->orWhere('max_revenue', '>=', $revenue);
            })
            ->orderByDesc('min_revenue')
            ->first();
    }
}
