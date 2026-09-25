<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentTuition extends Model
{
    use HasFactory;

    protected $table = 'student_tuitions';

    protected $fillable = [
        'student_id',
        'class_id',
        'branch_id',
        'bank_account_id',
        'promotion_id',
        'total_amount',
        'discount_amount',
        'other_fees',
        'fee_items',
        'prepaid_amount',
        'final_amount',
        'paid_amount',
        'debt_amount',
        'due_date',
        'status',
        'debt_status',
        'notes',
        'transfer_memo',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'other_fees' => 'decimal:2',
        'fee_items' => 'array',
        'prepaid_amount' => 'decimal:2',
        'final_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'debt_amount' => 'decimal:2',
        'due_date' => 'date',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function classModel(): BelongsTo
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(TuitionReceipt::class, 'student_tuition_id');
    }

    /**
     * Tính lại công nợ từ các phiếu đã duyệt (nguồn sự thật duy nhất):
     * - paid_amount = Σ phần học phí của phiếu approved (amount − surcharge_amount; phụ thu không cấn nợ,
     *   phiếu hoàn/chuyển nhượng âm tự trừ vào).
     * - debt_amount = max(0, final_amount − Σ discount_amount của phiếu approved − paid_amount).
     * Phiếu pending/draft/rejected/cancelled không được tính.
     */
    public function recalculateDebt(): void
    {
        $totals = $this->receipts()
            ->where('status', TuitionReceipt::STATUS_APPROVED)
            ->selectRaw('COALESCE(SUM(amount - COALESCE(surcharge_amount, 0)), 0) AS paid_total')
            ->selectRaw('COALESCE(SUM(COALESCE(discount_amount, 0)), 0) AS discount_total')
            ->toBase()
            ->first();

        $this->paid_amount = round((float) $totals->paid_total, 2);
        $this->debt_amount = max(0, round((float) $this->final_amount - (float) $totals->discount_total - (float) $this->paid_amount, 2));

        if ($this->debt_amount <= 0) {
            $this->status = 'paid';
        } elseif ($this->paid_amount > 0) {
            $this->status = 'partial';
        } else {
            $this->status = ($this->due_date && $this->due_date < now()) ? 'overdue' : 'unpaid';
        }

        $this->save();
    }

    public function getStatusBadgeAttribute(): string
    {
        return match ($this->status) {
            'paid' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            'partial' => 'bg-amber-50 text-amber-700 border-amber-200',
            'overdue' => 'bg-rose-50 text-rose-700 border-rose-200',
            default => 'bg-gray-50 text-gray-700 border-gray-200',
        };
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'paid' => 'Đã hoàn thành',
            'partial' => 'Đang nợ (Đã cọc)',
            'overdue' => 'Quá hạn',
            default => 'Chưa nộp',
        };
    }
}
