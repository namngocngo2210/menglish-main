<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Penalty extends Model
{
    use HasFactory;

    protected $table = 'penalties';

    protected $fillable = [
        'code',
        'user_id',
        'class_id',
        'violation_type',
        'violation_date',
        'amount',
        'reporter_id',
        'status',
        'payroll_record_id',
        'notes',
    ];

    protected $casts = [
        'violation_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function classModel(): BelongsTo
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }

    /** Bản ghi lương đã trừ biên bản này ở lần tính gần nhất. */
    public function payrollRecord(): BelongsTo
    {
        return $this->belongsTo(PayrollRecord::class, 'payroll_record_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function getStatusBadgeAttribute(): string
    {
        return match ($this->status) {
            'pending' => 'bg-amber-50 text-amber-700 border-amber-200',
            'confirmed' => 'bg-rose-50 text-rose-700 border-rose-200',
            'fined' => 'bg-orange-50 text-orange-700 border-orange-200',
            'deducted' => 'bg-purple-50 text-purple-700 border-purple-200',
            'paid' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            'resolved' => 'bg-sky-50 text-sky-700 border-sky-200',
            'cancelled' => 'bg-gray-50 text-gray-700 border-gray-200',
            default => 'bg-gray-50 text-gray-700 border-gray-200',
        };
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'pending' => 'Chờ xác nhận',
            'confirmed' => 'Đã xác nhận lỗi',
            'fined' => 'Đã quyết phạt (chờ thu/trừ lương)',
            'deducted' => 'Đã trừ vào bảng lương',
            'paid' => 'Đã nộp phạt trực tiếp',
            'resolved' => 'Đã xử lý (không phạt tiền)',
            'cancelled' => 'Đã hủy biên bản',
            default => $this->status,
        };
    }

    /**
     * Trạng thái mà bảng lương sẽ thu nộp khi tính kỳ lương.
     */
    public static function payableStatuses(): array
    {
        return ['fined'];
    }

    public static function generateCode(): string
    {
        $year = date('Y');
        $count = static::whereYear('created_at', $year)->count() + 1;

        return 'BB-'.$year.'-'.str_pad($count, 3, '0', STR_PAD_LEFT);
    }
}
