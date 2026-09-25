<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperatingExpense extends Model
{
    use HasFactory;

    protected $table = 'operating_expenses';

    protected $fillable = [
        'expense_date',
        'title',
        'amount',
        'payment_method',
        'branch_id',
        'category',
        'notes',
        'creator_id',
    ];

    protected $casts = [
        'expense_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function getFormattedAmountAttribute(): string
    {
        return number_format((float)$this->amount, 0, ',', '.') . ' đ';
    }

    public function getPaymentMethodLabelAttribute(): string
    {
        return match ($this->payment_method) {
            'chuyen_khoan' => 'Chuyển khoản',
            'tien_mat' => 'Tiền mặt',
            default => 'Khác',
        };
    }

    public function getCategoryLabelAttribute(): string
    {
        return match ($this->category) {
            'mat_bang_tien_ich' => 'Mặt bằng & Tiện ích',
            'giao_trinh_van_hanh' => 'In ấn & Vận hành lớp',
            default => 'Chi phí khác',
        };
    }
}
