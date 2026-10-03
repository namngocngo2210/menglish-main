<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * Ưu đãi / voucher học phí.
 * - Ưu đãi trong danh mục (is_special = false) được chọn lại khi chốt khách và khi lập phiếu thu.
 * - is_default: tự chọn sẵn khi chốt khách đúng phạm vi cơ sở / khóa (người lập vẫn đổi / bỏ được).
 * - is_special: ưu đãi riêng tạo cho một khách ở ca đặc biệt, bắt buộc lý do, chỉ dùng 1 lần, không hiện trong danh sách chọn.
 * Công thức giảm trừ chỉ nằm ở calculateDiscount() (phía trình duyệt: resources/js/lib/promotion.js) để sau này đổi
 * logic tính học phí chỉ cần sửa một chỗ mỗi phía.
 */
class Promotion extends Model
{
    use HasFactory;

    protected $table = 'promotions';

    protected $fillable = [
        'code',
        'name',
        'type',
        'value',
        'max_discount_amount',
        'description',
        'reason',
        'branch_id',
        'course_id',
        'starts_at',
        'ends_at',
        'usage_limit',
        'used_count',
        'is_active',
        'is_default',
        'is_special',
        'created_by',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'max_discount_amount' => 'decimal:2',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'is_special' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'usage_limit' => 'integer',
        'used_count' => 'integer',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Đang hiệu lực: bật, trong thời gian áp dụng, còn lượt dùng. */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->where(fn (Builder $q) => $q->whereNull('usage_limit')->orWhereColumn('used_count', '<', 'usage_limit'));
    }

    /** Ưu đãi dùng lại được (không gồm ưu đãi riêng của từng khách). */
    public function scopeCatalog(Builder $query): Builder
    {
        return $query->where('is_special', false);
    }

    public function calculateDiscount(float $baseAmount): float
    {
        if ($this->type === 'percent') {
            $discount = ($baseAmount * $this->value) / 100;
            if ($this->max_discount_amount && $discount > $this->max_discount_amount) {
                $discount = $this->max_discount_amount;
            }

            return round(min($baseAmount, (float) $discount));
        }

        return min($baseAmount, (float) $this->value);
    }

    public function isApplicable(?int $branchId, ?int $courseId): bool
    {
        return $this->is_active
            && (! $this->starts_at || $this->starts_at->lte(now()))
            && (! $this->ends_at || $this->ends_at->gte(now()))
            && (! $this->usage_limit || $this->used_count < $this->usage_limit)
            && (! $this->branch_id || (int) $this->branch_id === (int) $branchId)
            && (! $this->course_id || (int) $this->course_id === (int) $courseId);
    }

    /**
     * Ưu đãi mặc định cho cơ sở / khóa: ưu đãi mặc định cụ thể nhất (đúng cả cơ sở + khóa > đúng khóa > đúng cơ sở > chung).
     *
     * @param  Collection<int, Promotion>|null  $candidates  danh sách đã tải sẵn (tránh truy vấn lại)
     */
    public static function defaultFor(?int $branchId, ?int $courseId, ?Collection $candidates = null): ?self
    {
        $candidates ??= self::query()->available()->catalog()->where('is_default', true)->get();

        return $candidates
            ->filter(fn (self $p) => $p->is_default && ! $p->is_special && $p->isApplicable($branchId, $courseId))
            ->sortByDesc(fn (self $p) => ($p->course_id ? 2 : 0) + ($p->branch_id ? 1 : 0))
            ->first();
    }

    public function getValueLabelAttribute(): string
    {
        return $this->type === 'percent'
            ? rtrim(rtrim(number_format((float) $this->value, 2, ',', '.'), '0'), ',').'%'
            : Money::format((float) $this->value);
    }
}
