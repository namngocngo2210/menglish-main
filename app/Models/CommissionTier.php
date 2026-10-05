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
 * Mốc tăng tiến (03/10/2026): [min_students, max_students] là THỨ TỰ chốt của học viên trong tháng chốt của
 * người phụ trách; học viên thứ n mang new_sale_percent của mốc chứa n (SalesCommissionService). Bậc cũ theo doanh thu (min_students NULL),
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

    /** Bậc theo số HS chốt (A6 bản sửa) — bậc cũ theo doanh thu có min_students NULL. */
    public function scopeByStudents(Builder $query): Builder
    {
        return $query->whereNotNull('min_students');
    }

    /**
     * Mốc chứa học viên thứ n (thứ tự chốt trong tháng) tại một ngày (mặc định hôm nay): min_students <= n <= max_students
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

        // Mốc theo thứ tự chốt: HS đầu tiên là HS thứ 1 (ngưỡng 0 cũ hiển thị như 1).
        $from = max(1, (int) $this->min_students);

        return $this->max_students === null
            ? 'Từ HS thứ '.$from
            : 'HS thứ '.$from.'–'.$this->max_students;
    }

}
