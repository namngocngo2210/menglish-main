<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
        'branch_id',
        'course_id',
        'starts_at',
        'ends_at',
        'usage_limit',
        'used_count',
        'is_active',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'max_discount_amount' => 'decimal:2',
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'usage_limit' => 'integer',
        'used_count' => 'integer',
    ];

    public function calculateDiscount(float $baseAmount): float
    {
        if ($this->type === 'percent') {
            $discount = ($baseAmount * $this->value) / 100;
            if ($this->max_discount_amount && $discount > $this->max_discount_amount) {
                $discount = $this->max_discount_amount;
            }

            return $discount;
        }

        return min($baseAmount, (float) $this->value);
    }

    public function isApplicable(?int $branchId, ?int $courseId): bool
    {
        return $this->is_active
            && (! $this->starts_at || $this->starts_at->lte(now()))
            && (! $this->ends_at || $this->ends_at->gte(now()))
            && (! $this->usage_limit || $this->used_count < $this->usage_limit)
            && (! $this->branch_id || $this->branch_id === $branchId)
            && (! $this->course_id || $this->course_id === $courseId);
    }
}
