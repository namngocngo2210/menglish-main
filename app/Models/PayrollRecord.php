<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollRecord extends Model
{
    use HasFactory;

    protected $table = 'payroll_records';

    protected $fillable = [
        'payroll_period_id',
        'user_id',
        'department',
        'base_salary',
        'standard_hours',
        'actual_hours',
        'overtime_hours',
        'teaching_salary',
        'kpi_bonus',
        'renew_bonus',
        'commission_bonus',
        'commission_base',
        'commission_clawback',
        'allowance',
        'allowance_override',
        'other_bonus',
        'other_deduction',
        'insurance_deduction',
        'tax_deduction',
        'penalty_deduction',
        'foreign_teacher_sessions_count',
        'foreign_teacher_deduction_rate',
        'foreign_teacher_deduction',
        'net_salary',
        'status',
        'notes',
        'adjustment_notes',
    ];

    protected $casts = [
        'base_salary' => 'decimal:2',
        'standard_hours' => 'decimal:2',
        'actual_hours' => 'decimal:2',
        'overtime_hours' => 'decimal:2',
        'teaching_salary' => 'decimal:2',
        'kpi_bonus' => 'decimal:2',
        'renew_bonus' => 'decimal:2',
        'commission_bonus' => 'decimal:2',
        'commission_base' => 'decimal:2',
        'commission_clawback' => 'decimal:2',
        'allowance' => 'decimal:2',
        'allowance_override' => 'decimal:2',
        'other_bonus' => 'decimal:2',
        'other_deduction' => 'decimal:2',
        'insurance_deduction' => 'decimal:2',
        'tax_deduction' => 'decimal:2',
        'penalty_deduction' => 'decimal:2',
        'foreign_teacher_sessions_count' => 'integer',
        'foreign_teacher_deduction_rate' => 'decimal:2',
        'foreign_teacher_deduction' => 'decimal:2',
        'net_salary' => 'decimal:2',
    ];

    public function period(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Tổng thu nhập (gross): mọi khoản cộng của bản ghi lương.
     */
    public function getGrossIncomeAttribute(): float
    {
        return (float) $this->base_salary + (float) $this->teaching_salary + (float) $this->kpi_bonus
            + (float) $this->renew_bonus + (float) $this->commission_bonus + (float) $this->allowance
            + (float) $this->other_bonus;
    }

    /**
     * Tổng khấu trừ: BHXH + thuế TNCN + phạt + giảm trừ GVNN + thu hồi hoa hồng + khấu trừ khác.
     */
    public function getTotalDeductionsAttribute(): float
    {
        return (float) $this->insurance_deduction + (float) $this->tax_deduction
            + (float) $this->penalty_deduction + (float) $this->foreign_teacher_deduction
            + (float) $this->commission_clawback + (float) $this->other_deduction;
    }

    /** Kỳ lương của bản ghi đã duyệt/đã chi trả → không được sửa. */
    public function isLocked(): bool
    {
        return in_array($this->period?->status, PayrollPeriod::LOCKED_STATUSES, true);
    }

    public function calculateNetSalary(): void
    {
        $settings = PayrollPeriod::payrollSettings();
        $this->foreign_teacher_deduction = $this->foreign_teacher_deduction > 0
            ? $this->foreign_teacher_deduction
            : ($this->foreign_teacher_sessions_count * ($this->foreign_teacher_deduction_rate ?: $settings['foreign_teacher_deduction_rate']));

        $this->net_salary = max(0, $this->gross_income - $this->total_deductions);
    }
}
