<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Bậc hoa hồng tuyển sinh (khách mới). Mỗi dòng là một PHIÊN BẢN có hiệu lực
 * [effective_from, effective_to]; sửa bậc = tạo phiên bản mới để kỳ lương cũ
 * luôn tính lại được theo đúng mốc đã áp dụng. effective_from NULL = từ đầu.
 *
 * A6 (bản sửa 25/09/2026): bậc chọn theo SỐ HS CHỐT trong kỳ [min_students, max_students];
 * hoa hồng = new_sale_percent × doanh thu tuyển sinh thật. Bậc cũ theo doanh thu (min_students NULL),
 * renew_percent và bonus_amount chỉ còn là dữ liệu lịch sử, không dùng trong tính lương.
 */
class CommissionTier extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'commission_tiers';

    protected $fillable = [
        'tier_name',
        'min_revenue',
        'max_revenue',
        'min_students',
        'max_students',
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
        'min_students' => 'integer',
        'max_students' => 'integer',
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

    /** Bậc theo số HS chốt (A6 bản sửa) — bậc cũ theo doanh thu có min_students NULL. */
    public function scopeByStudents(Builder $query): Builder
    {
        return $query->whereNotNull('min_students');
    }

    /**
     * Bậc áp dụng cho số HS chốt trong kỳ tại một ngày (mặc định hôm nay): min_students <= n <= max_students
     * (max NULL = không giới hạn), ưu tiên bậc có min_students cao nhất, chỉ xét phiên bản hiệu lực.
     */
    public static function matchForStudents(int $closedStudents, CarbonInterface|string|null $asOf = null): ?self
    {
        return static::query()
            ->byStudents()
            ->effectiveAt($asOf)
            ->where('min_students', '<=', $closedStudents)
            ->where(fn ($query) => $query->whereNull('max_students')->orWhere('max_students', '>=', $closedStudents))
            ->orderByDesc('min_students')
            ->first();
    }

    public function getStudentRangeLabelAttribute(): string
    {
        if ($this->min_students === null) {
            return 'Theo doanh thu (bậc cũ)';
        }

        return $this->max_students === null
            ? 'Từ '.$this->min_students.' HS'
            : $this->min_students.'–'.$this->max_students.' HS';
    }

    /**
     * (Bậc cũ) Bậc hoa hồng áp dụng cho một mức doanh thu tại một thời điểm (mặc định hôm nay):
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
